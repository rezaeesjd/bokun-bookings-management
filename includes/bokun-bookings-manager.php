<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Force publish future-dated 'bokun_booking' posts
function bokun_force_publish_future_posts($data, $postarr) {
    if ($data['post_type'] == 'bokun_booking') {
        // If the post status is 'future', change it to 'publish'
        if ($data['post_status'] == 'future') {
            $data['post_status'] = 'publish';
        }
    }
    return $data;
}
add_filter('wp_insert_post_data', 'bokun_force_publish_future_posts', 10, 2);

// Function to format the date
function bokun_format_date() {
    return gmdate('Y-m-d H:i:s');
}

// Function to generate Bokun HMAC signature
function bokun_generate_signature($date, $apiKey, $method, $endpoint, $secretKey) {
    $stringToSign = $date . $apiKey . $method . $endpoint;
    $signature = hash_hmac('sha1', $stringToSign, $secretKey, true);
    return base64_encode($signature);
}

// Fetch bookings from Bokun API
function bokun_fetch_bookings($upgrade = '') {
    if ($upgrade) {        
        $api_key = get_option('bokun_api_key_upgrade', '');
        $secret_key = get_option('bokun_secret_key_upgrade', '');
    } else {
        $api_key = get_option('bokun_api_key', '');
        $secret_key = get_option('bokun_secret_key', '');
    }
    
    $url = BOKUN_API_BASE_URL . BOKUN_API_BOOKING_API;
    $method = 'POST';
    $date = bokun_format_date();
    $endpoint = '/booking.json/booking-search';

    // Prepare payload (adjust date range and other parameters as needed)
    $today = new DateTime('today', new DateTimeZone('GMT')); // Midnight today in GMT
    $yesterday = (clone $today)->modify('-1 day');
    $oneMonthLater = (clone $today)->modify('+1 month');

    $all_bookings = [];
    $page = 1;

    $items_per_page = apply_filters('bokun_booking_items_per_page', 50);
    if (!is_numeric($items_per_page) || (int)$items_per_page <= 0) {
        $items_per_page = 50;
    } else {
        $items_per_page = (int)$items_per_page;
    }

    $request_timeout = apply_filters('bokun_booking_request_timeout', 300);
    if (!is_numeric($request_timeout) || (float)$request_timeout < 0) {
        $request_timeout = 300;
    } else {
        $request_timeout = (float)$request_timeout;
    }

    // Generate the signature
    $signature = bokun_generate_signature($date, $api_key, $method, $endpoint, $secret_key);

    // Set headers
    $headers = [
        'X-Bokun-AccessKey' => $api_key,
        'X-Bokun-Date' => $date,
        'X-Bokun-Signature' => $signature,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    do {
        $payload_data = [
            'page' => $page,
            'itemsPerPage' => $items_per_page,
            'startDateRange' => [
                'from' => $today->format('Y-m-d\T00:00:00\Z'),
                'includeLower' => true,
                'includeUpper' => true,
                'to' => $oneMonthLater->format('Y-m-d\TH:i:s\Z')
            ]
        ];

        $payload = wp_json_encode($payload_data);

        // Request options
        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $payload,
            'timeout' => $request_timeout,
        ];

        // Send the request
        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return $page === 1 ? 'Error: ' . $response->get_error_message() : $all_bookings;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return $page === 1 ? 'Error: Received unexpected response code ' . $response_code . '. Response: ' . $body : $all_bookings;
        }

        $data = json_decode($body, true);

        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (!empty($item['confirmationCode'])) {
                    error_log('Found booking ' . $item['confirmationCode']);
                }
            }
            $all_bookings = array_merge($all_bookings, $data['items']);
        }

        $total_pages = null;
        if (isset($data['totalPages'])) {
            $total_pages = (int) $data['totalPages'];
        } elseif (isset($data['paging']['totalPages'])) {
            $total_pages = (int) $data['paging']['totalPages'];
        } elseif (isset($data['paging']['totalPageCount'])) {
            $total_pages = (int) $data['paging']['totalPageCount'];
        }

        $next_page = null;
        if (isset($data['nextPage'])) {
            $next_page = (int) $data['nextPage'];
        } elseif (isset($data['paging']['nextPage'])) {
            $next_page = (int) $data['paging']['nextPage'];
        }

        if (!is_null($next_page) && $next_page <= $page) {
            $next_page = null;
        }

        $has_more = false;
        if (!is_null($total_pages) && $page < $total_pages) {
            $has_more = true;
        }

        if (isset($data['hasMore']) && $data['hasMore']) {
            $has_more = true;
        }

        if (isset($data['paging']['hasMore']) && $data['paging']['hasMore']) {
            $has_more = true;
        }

        if (!empty($next_page)) {
            $has_more = true;
        }

        if (!$has_more && isset($data['items']) && is_array($data['items']) && count($data['items']) === $items_per_page) {
            $has_more = true;
            $next_page = $page + 1;
        }

        if (!$has_more || empty($data['items'])) {
            break;
        }

        $page = !empty($next_page) ? $next_page : $page + 1;
    } while (true);

    if (!empty($all_bookings)) {
        return $all_bookings;
    }

    return 'No bookings available to process.';
}

function bokun_normalize_import_context($context) {
    $context = is_string($context) ? strtolower($context) : '';
    $context = sanitize_key($context);

    if (empty($context)) {
        $context = 'default';
    }

    return $context;
}

function bokun_get_import_progress_key($context) {
    $context = bokun_normalize_import_context($context);

    return 'bokun_import_progress_' . $context;
}

function bokun_get_import_progress_label($context) {
    $context = bokun_normalize_import_context($context);

    switch ($context) {
        case 'upgrade':
            return __('API 2 import', 'bokun-bookings-manager');
        case 'fetch':
            return __('API 1 import', 'bokun-bookings-manager');
        default:
            return __('Import', 'bokun-bookings-manager');
    }
}

function bokun_get_import_progress_message($context, $status) {
    $context = bokun_normalize_import_context($context);
    $status = is_string($status) ? strtolower($status) : 'idle';
    $label = bokun_get_import_progress_label($context);

    switch ($status) {
        case 'running':
            /* translators: %s: API label. */
            return sprintf(__('%s — importing product {current}/{total}', 'bokun-bookings-manager'), $label);
        case 'completed':
            /* translators: %s: API label. */
            return sprintf(__('%s — import complete ({total}/{total})', 'bokun-bookings-manager'), $label);
        case 'error':
            /* translators: %s: API label. */
            return sprintf(__('%s — import interrupted', 'bokun-bookings-manager'), $label);
        default:
            /* translators: %s: API label. */
            return sprintf(__('%s — preparing import…', 'bokun-bookings-manager'), $label);
    }
}

function bokun_get_import_progress_state($context) {
    $context = bokun_normalize_import_context($context);
    $key = bokun_get_import_progress_key($context);
    $state = get_transient($key);

    if (!is_array($state)) {
        $state = array(
            'status'    => 'idle',
            'context'   => $context,
            'total'     => 0,
            'processed' => 0,
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'label'     => bokun_get_import_progress_label($context),
            'message'   => bokun_get_import_progress_message($context, 'idle'),
            'updated_at'=> time(),
            'current'   => 0,
            'remaining' => 0,
            'total_items' => 0,
            'percentage'=> 0,
        );
    } else {
        $state['context'] = $context;

        if (!isset($state['label']) || '' === $state['label']) {
            $state['label'] = bokun_get_import_progress_label($context);
        }

        if (empty($state['message'])) {
            $status = isset($state['status']) ? $state['status'] : 'idle';
            $state['message'] = bokun_get_import_progress_message($context, $status);
        }

        $state['updated_at'] = isset($state['updated_at']) ? (int) $state['updated_at'] : time();
    }

    $state = bokun_enrich_import_progress_state($context, $state);

    return $state;
}

function bokun_set_import_progress_state($context, $overrides = array()) {
    $context = bokun_normalize_import_context($context);
    $key = bokun_get_import_progress_key($context);
    $current_state = bokun_get_import_progress_state($context);

    $overrides = is_array($overrides) ? array_filter($overrides, function ($value) {
        return null !== $value;
    }) : array();

    $state = array_merge($current_state, $overrides);

    if (!isset($state['status']) || '' === $state['status']) {
        $state['status'] = 'idle';
    }

    $state['status'] = strtolower($state['status']);

    foreach (array('total', 'processed', 'created', 'updated', 'skipped') as $numeric_key) {
        if (isset($state[$numeric_key])) {
            $state[$numeric_key] = max(0, (int) $state[$numeric_key]);
        }
    }

    if (!isset($state['label']) || '' === $state['label']) {
        $state['label'] = bokun_get_import_progress_label($context);
    }

    if (empty($state['message'])) {
        $state['message'] = bokun_get_import_progress_message($context, $state['status']);
    }

    $state = bokun_enrich_import_progress_state($context, $state);

    $state['context'] = $context;
    $state['updated_at'] = time();

    set_transient($key, $state, 15 * MINUTE_IN_SECONDS);

    return $state;
}

function bokun_enrich_import_progress_state($context, $state) {
    $status = isset($state['status']) ? strtolower($state['status']) : 'idle';
    $total = isset($state['total']) ? max(0, (int) $state['total']) : 0;
    $processed = isset($state['processed']) ? max(0, (int) $state['processed']) : 0;

    if ($total <= 0 && $processed > 0) {
        $total = $processed;
    }

    $current = $total > 0 ? min($processed, $total) : $processed;
    $remaining = max($total - $current, 0);
    $percentage = $total > 0 ? min(100, max(0, (int) round(($current / $total) * 100))) : ('completed' === $status ? 100 : 0);

    $state['current'] = $current;
    $state['remaining'] = $remaining;
    $state['total_items'] = $total;
    $state['percentage'] = $percentage;

    $state['display_message'] = '';

    if (!empty($state['message'])) {
        $state['display_message'] = bokun_format_import_progress_message($context, $state['message'], $current, $total, $remaining);
    }

    return $state;
}

function bokun_format_import_progress_message($context, $message, $current, $total, $remaining) {
    $message = (string) $message;
    $label = bokun_get_import_progress_label($context);

    $replacements = array(
        '{label}'     => $label,
        '{current}'   => (string) $current,
        '{total}'     => (string) $total,
        '{remaining}' => (string) $remaining,
    );

    return strtr($message, $replacements);
}

function bokun_reset_import_progress_state($context) {
    $context = bokun_normalize_import_context($context);
    $key = bokun_get_import_progress_key($context);

    delete_transient($key);
}

// Save Bokun bookings as WordPress posts
function bokun_save_bookings_as_posts($bookings, $context = 'default') {
    $stats = array(
        'total'     => is_array($bookings) ? count($bookings) : 0,
        'processed' => 0,
        'created'   => 0,
        'updated'   => 0,
        'skipped'   => 0,
    );

    $context = bokun_normalize_import_context($context);

    bokun_set_import_progress_state($context, array(
        'status'    => $stats['total'] > 0 ? 'running' : 'completed',
        'total'     => $stats['total'],
        'processed' => 0,
        'created'   => 0,
        'updated'   => 0,
        'skipped'   => 0,
        'message'   => $stats['total'] > 0 ? bokun_get_import_progress_message($context, 'running') : bokun_get_import_progress_message($context, 'completed'),
    ));

    if (!is_array($bookings) || empty($bookings)) {
        bokun_set_import_progress_state($context, array(
            'status'    => 'completed',
            'total'     => $stats['total'],
            'processed' => 0,
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => $stats['skipped'],
            'message'   => bokun_get_import_progress_message($context, 'completed'),
        ));
        return $stats;
    }

    // Step 1: Collect all confirmation codes from the imported bookings
    $imported_confirmation_codes = [];
    foreach ($bookings as $booking) {
        if (isset($booking['confirmationCode'])) {
            $imported_confirmation_codes[] = $booking['confirmationCode'];
        }
    }

    // Step 2: Set all existing `bokun_booking` posts before today to draft if not in the import list
    $today = new DateTime('today', new DateTimeZone('GMT')); // Midnight today
    $args = [
        'post_type'      => 'bokun_booking',
        'post_status'    => 'publish',
        'date_query'     => [
            'before' => $today->format('Y-m-d H:i:s'),
        ],
        'fields'         => 'ids', // Only get post IDs to improve performance
        'posts_per_page' => -1,
    ];

    $query = new WP_Query($args);
    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {
            $confirmation_code = get_post_meta($post_id, '_confirmation_code', true);
            if (!in_array($confirmation_code, $imported_confirmation_codes)) {
                wp_update_post([
                    'ID'          => $post_id,
                    'post_status' => 'draft',
                ]);
            }
        }
    }
    wp_reset_postdata();

    // Step 3: Process imported bookings and save or update as usual
    foreach ($bookings as $booking) {
        if (empty($booking['confirmationCode'])) {
            $stats['skipped']++;
            bokun_set_import_progress_state($context, array(
                'status'    => 'running',
                'total'     => $stats['total'],
                'processed' => $stats['created'] + $stats['updated'] + $stats['skipped'],
                'created'   => $stats['created'],
                'updated'   => $stats['updated'],
                'skipped'   => $stats['skipped'],
                'message'   => bokun_get_import_progress_message($context, 'running'),
            ));
            continue;
        }

        $confirmationCode = $booking['confirmationCode'];
        $post_title = $confirmationCode;

        $startDateTime = !empty($booking['productBookings'][0]['startDateTime'])
                            ? $booking['productBookings'][0]['startDateTime']
                            : (!empty($booking['productBookings'][0]['startDate'])
                                ? $booking['productBookings'][0]['startDate']
                                : '');

        if (empty($startDateTime)) {
            $stats['skipped']++;
            continue;
        }

        if ($startDateTime > 1000000000000) {
            $startDateTime = $startDateTime / 1000;
        }

        $startDateTimeObject = new DateTime("@$startDateTime", new DateTimeZone('UTC'));
        $post_date = $startDateTimeObject->format('Y-m-d H:i:s');

        $post_data = [
            'post_title'     => $post_title,
            'post_name'      => sanitize_title($confirmationCode),
            'post_status'    => 'publish',
            'post_type'      => 'bokun_booking',
            'post_date'      => $post_date,
            'post_date_gmt'  => get_gmt_from_date($post_date)
        ];

        $existing_post = get_posts([
            'post_type'  => 'bokun_booking',
            'meta_query' => [
                [
                    'key'     => '_confirmation_code',
                    'value'   => $confirmationCode,
                    'compare' => '='
                ]
            ],
            'fields'     => 'ids'
        ]);

        if (!empty($existing_post)) {
            $post_id = $existing_post[0];
            $has_changes = bokun_check_for_changes($post_id, $booking);
            if ($has_changes) {
                $update_result = wp_update_post(array_merge(['ID' => $post_id], $post_data));
                if (is_wp_error($update_result) || 0 === $update_result) {
                    $stats['skipped']++;
                } else {
                    $stats['updated']++;
                }
            } else {
                $stats['skipped']++;
            }
        } else {
            $post_id = wp_insert_post($post_data);
            if (is_wp_error($post_id) || 0 === $post_id) {
                $stats['skipped']++;
                continue;
            }
            $stats['created']++;
        }

        bokun_save_specific_fields($post_id, $booking);
        bokun_save_all_fields_as_meta($post_id, $booking);
        process_price_categories_and_save($post_id, $booking);
        bokun_calculate_booking_status($post_id, $booking['productBookings'][0]['product']['title'] ?? '', $startDateTime);

        $inclusions_text = $booking['productBookings'][0]['notes'][0]['body'] ?? '';
        $inclusions_clean = bokun_get_inclusions_clean($inclusions_text);

        if (!empty($inclusions_clean)) {
            update_post_meta($post_id, 'inclusions_clean', $inclusions_clean);
        }

        bokun_set_import_progress_state($context, array(
            'status'    => 'running',
            'total'     => $stats['total'],
            'processed' => $stats['created'] + $stats['updated'] + $stats['skipped'],
            'created'   => $stats['created'],
            'updated'   => $stats['updated'],
            'skipped'   => $stats['skipped'],
            'message'   => bokun_get_import_progress_message($context, 'running'),
        ));
    }

    $stats['processed'] = $stats['created'] + $stats['updated'] + $stats['skipped'];

    bokun_set_import_progress_state($context, array(
        'status'    => 'completed',
        'total'     => $stats['total'],
        'processed' => $stats['processed'],
        'created'   => $stats['created'],
        'updated'   => $stats['updated'],
        'skipped'   => $stats['skipped'],
        'message'   => bokun_get_import_progress_message($context, 'completed'),
    ));

    return $stats;
}

// Function to check if fields have changed, excluding 'bookingmade'
function bokun_check_for_changes($post_id, $booking) {
    // Extract relevant fields to compare
    $customer = $booking['customer'] ?? [];
    $productBooking = $booking['productBookings'][0] ?? [];

    // Fields to compare
    $fields_to_compare = [
        '_confirmation_code' => $booking['confirmationCode'] ?? 'N/A',
        '_first_name' => $customer['firstName'] ?? 'N/A',
        '_last_name' => $customer['lastName'] ?? 'N/A',
        '_email' => $customer['email'] ?? 'N/A',
        '_phone_prefix' => parse_phone_number($customer['phoneNumber'] ?? '')[0] ?? 'N/A',
        '_phone_number' => parse_phone_number($customer['phoneNumber'] ?? '')[1] ?? 'N/A',
        '_external_booking_reference' => $booking['externalBookingReference'] ?? 'N/A',
        '_product_title' => $productBooking['product']['title'] ?? 'N/A',
        '_productBookings_0_status' => $productBooking['status'] ?? 'N/A',
        '_original_creation_date' => $booking['creationDate'] ?? 'N/A',
        '_original_start_date' => $productBooking['startDate'] ?? 'N/A',
    ];

    // Loop through each field to check if any have changed
    foreach ($fields_to_compare as $meta_key => $new_value) {
        $existing_value = get_post_meta($post_id, $meta_key, true);
        if ($existing_value != $new_value) {
            return true; // Return true if any field has changed
        }
    }
    return false; // Return false if nothing has changed
}

// Function to save specific fields of the booking
function bokun_save_specific_fields($post_id, $booking) {
    // Extract nested values
    $customer = $booking['customer'] ?? [];
    $productBooking = $booking['productBookings'][0] ?? [];

    $phoneParsed = parse_phone_number($customer['phoneNumber'] ?? '');

    // Save necessary fields, with proper sanitization for text and numeric values
    update_post_meta($post_id, '_confirmation_code', sanitize_text_field($booking['confirmationCode'] ?? 'N/A'));
    update_post_meta($post_id, '_first_name', sanitize_text_field($customer['firstName'] ?? 'N/A'));
    update_post_meta($post_id, '_last_name', sanitize_text_field($customer['lastName'] ?? 'N/A'));
    update_post_meta($post_id, '_email', sanitize_email($customer['email'] ?? 'N/A'));
    update_post_meta($post_id, '_phone_prefix', sanitize_text_field($phoneParsed[0] ?? 'N/A'));
    update_post_meta($post_id, '_phone_number', sanitize_text_field($phoneParsed[1] ?? 'N/A'));
    update_post_meta($post_id, '_external_booking_reference', sanitize_text_field($booking['externalBookingReference'] ?? 'N/A'));
    update_post_meta($post_id, '_product_title', sanitize_text_field($productBooking['product']['title'] ?? 'N/A'));
    update_post_meta($post_id, '_product_id', intval($productBooking['product']['id'] ?? 0));
    update_post_meta($post_id, '_booking_status_origin', sanitize_text_field($productBooking['status'] ?? 'N/A'));

    // Handle timestamps properly for date fields
    $original_creation_date = get_post_meta($post_id, '_original_creation_date', true);
    if ($original_creation_date !== $booking['creationDate']) {
        update_post_meta($post_id, '_original_creation_date', sanitize_text_field($booking['creationDate']));
    }

    $original_start_date = get_post_meta($post_id, '_original_start_date', true);
    if ($original_start_date !== $productBooking['startDate']) {
        update_post_meta($post_id, '_original_start_date', sanitize_text_field($productBooking['startDate']));
    }

    // Handle product tags (product_id and product_title)
    $product_title = sanitize_text_field($productBooking['product']['title'] ?? '');

    // Assign product title tag to the post
    if (!empty($product_title)) {
        bokun_assign_tag_to_post($post_id, $product_title, 'product_tags');
    }

    // Handle booking status
    $booking_status = sanitize_text_field($productBooking['status'] ?? '');

    if (!empty($booking_status)) {
        bokun_assign_tag_to_post($post_id, $booking_status, 'booking_status');
    } else {
        // If no booking status is set, default to 'Booking Not Made'
        bokun_assign_tag_to_post($post_id, 'Booking Not Made', 'booking_status');
    }

    // Call the function after processing other tags
    bokun_assign_not_made_if_not_made_exists($post_id);

    // Calculate and save custom status fields (Status OK, Attention, Alarm)
    if (!empty($productBooking['startDate'])) {
        bokun_calculate_booking_status($post_id, $product_title, $productBooking['startDate']);
    }
}

// Function to assign 'Booking Not Made' if it doesn't exist
function bokun_assign_not_made_if_not_made_exists($post_id) {
    $taxonomy = 'booking_status';

    // Get assigned terms by name
    $assigned_terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
    
    if (!in_array('Booking Made', $assigned_terms)) {
        // 'Booking Made' is not assigned, check if 'Booking Not Made' is assigned
        if (!in_array('Booking Not Made', $assigned_terms)) {
            // Assign 'Booking Not Made' to the post
            bokun_assign_tag_to_post($post_id, 'Booking Not Made', $taxonomy);
        }
    }
}



// Function to parse phone number (example implementation)
function parse_phone_number($phoneNumber) {
    $phoneRegex = '/^(\+\d+|\w+\+\d+)?\s*(.*)$/';
    preg_match($phoneRegex, $phoneNumber, $matches);
    return [($matches[1] ?? ''), ($matches[2] ?? '')];
}

// Function to assign product tags to the post
function bokun_assign_tag_to_post($post_id, $term_name, $taxonomy) {
    if (empty($term_name)) {
        return;
    }

    // Check if term exists by name
    $term = get_term_by('name', $term_name, $taxonomy);
    if (!$term) {
        // If not, create it
        $term = wp_insert_term($term_name, $taxonomy);
        if (is_wp_error($term)) {
            return;
        }
        $term_id = $term['term_id'];
    } else {
        $term_id = $term->term_id;
    }

    // Assign the term to the post using wp_set_object_terms
    wp_set_object_terms($post_id, intval($term_id), $taxonomy, true);
}

// Function to remove a tag from a post
function bokun_remove_tag_from_post($post_id, $term_name, $taxonomy) {
    // Get the term by name
    $term = get_term_by('name', $term_name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        wp_remove_object_terms($post_id, intval($term->term_id), $taxonomy);
    }
}

// Function to calculate and save booking status as 'Ok', 'Attention', or 'Alarm'
function bokun_calculate_booking_status($post_id, $product_title, $startDateTime) {
    // Check if the timestamp is in milliseconds and convert it to seconds if necessary
    if ($startDateTime > 1000000000000) { // If timestamp is in milliseconds, divide by 1000
        $startDateTime = $startDateTime / 1000;
    }

    if (empty($startDateTime)) {
        return;
    }

    // Create the DateTime object using the corrected timestamp
    $booking_date = new DateTime("@$startDateTime");

    // Fetch the WordPress timezone setting
    $timezone_string = get_option('timezone_string');

    // Use the default UTC timezone if no valid timezone is set
    if (empty($timezone_string)) {
        $timezone_string = 'UTC';
    }

    // Wrap the DateTimeZone constructor in a try-catch to handle invalid timezones
    try {
        $timezone = new DateTimeZone($timezone_string);
        $current_date = new DateTime('now', $timezone);
    } catch (Exception $e) {
        $timezone = new DateTimeZone('UTC');
        $current_date = new DateTime('now', $timezone);
    }

    // Continue with the rest of the logic
    $product_tags = wp_get_post_terms($post_id, 'product_tags');
    if (!empty($product_tags) && !is_wp_error($product_tags)) {
        foreach ($product_tags as $tag) {
            if (html_entity_decode($tag->name, ENT_QUOTES | ENT_HTML5) === $product_title) {
                $product_title_tag = $tag;
                break;
            }
        }

        if (!empty($product_title_tag)) {
            // Get custom fields from the product title tag for status thresholds
            $statusok = get_term_meta($product_title_tag->term_id, 'statusok', true);
            $statusattention = get_term_meta($product_title_tag->term_id, 'statusattention', true);
            $statusalarm = get_term_meta($product_title_tag->term_id, 'statusalarm', true);

            // Set default values if the custom fields are not set or not numeric
            $statusok = is_numeric($statusok) ? intval($statusok) : 29;
            $statusattention = is_numeric($statusattention) ? intval($statusattention) : 5;
            $statusalarm = is_numeric($statusalarm) ? intval($statusalarm) : 3;

            // Calculate the number of days until the booking date
            $interval = $current_date->diff($booking_date);
            $days_until_booking = (int)$interval->format('%r%a'); // Include sign to handle past dates

            // Initialize the alarm status value
            $alarm_status = 'Ok';

            // Determine the alarm status based on thresholds
            if ($days_until_booking < $statusalarm) {
                $alarm_status = 'Alarm';
            } elseif ($days_until_booking < $statusattention) {
                $alarm_status = 'Attention';
            }
            
            // Save the alarmstatus field with the appropriate value
            update_post_meta($post_id, 'alarmstatus', $alarm_status);

            // Sync the corresponding taxonomy term in 'alarm_status'
            bokun_assign_alarm_status_taxonomy($post_id, $alarm_status);
        }
    }
}

// Function to assign the corresponding term in 'alarm_status' taxonomy by name
function bokun_assign_alarm_status_taxonomy($post_id, $alarm_status) {
    $taxonomy = 'alarm_status';

    // Check if the term already exists by its name
    $term = term_exists($alarm_status, $taxonomy);

    // If the term doesn't exist, create it
    if (!$term) {
        $term = wp_insert_term($alarm_status, $taxonomy);
        if (is_wp_error($term)) {
            return;
        }
        // Extract the term name (in case wp_insert_term returns a term array)
        $term_name = $alarm_status;
    } else {
        // If the term exists, get the term name
        $term_data = get_term($term['term_id'], $taxonomy);
        $term_name = $term_data->name;
    }

    // Assign the term by its name to the post
    wp_set_post_terms($post_id, [$term_name], $taxonomy, false);
}

// Function to save all fields of the booking as post meta
function bokun_save_all_fields_as_meta($post_id, $data, $prefix = '') {
    foreach ($data as $key => $value) {
        // Create a meta key with a prefix to avoid conflicts
        $meta_key = $prefix . $key;

        // Recursively save nested arrays and objects
        if (is_array($value) || is_object($value)) {
            bokun_save_all_fields_as_meta($post_id, (array)$value, $meta_key . '_');
        } else {
            // If the value is a JSON string, decode it
            if (is_string($value) && is_json($value)) {
                $decoded_value = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded_value;
                }
            }

            // Use the appropriate function to save text or numeric data
            if (is_numeric($value)) {
                update_post_meta($post_id, $meta_key, intval($value));
            } else {
                update_post_meta($post_id, $meta_key, sanitize_text_field($value));
            }
        }
    }
}

// Utility function to check if a string is a valid JSON
function is_json($string) {
    if (!is_string($string)) {
        return false;
    }
    json_decode($string);
    return (json_last_error() === JSON_ERROR_NONE);
}

// Register Alarm Status taxonomy and create default terms
function bokun_register_alarm_status_taxonomy() {
    register_taxonomy('alarm_status', 'bokun_booking', [
        'labels' => [
            'name' => __('Alarm Status'),
            'singular_name' => __('Alarm Status'),
        ],
        'public' => true,
        'rewrite' => ['slug' => 'alarm-status'],
        'hierarchical' => false,
        'show_in_nav_menus' => true,
        'show_in_menu' => true,
        'show_in_rest' => true, // Important for Elementor
        'show_ui' => true,
        'show_admin_column' => true, // This adds the taxonomy in post lists
    ]);

    // Check if the terms 'Ok', 'Attention', and 'Alarm' exist, if not, create them
    $terms = ['Ok', 'Attention', 'Alarm'];
    foreach ($terms as $term) {
        if (!term_exists($term, 'alarm_status')) {
            wp_insert_term($term, 'alarm_status');
        }
    }
}
add_action('init', 'bokun_register_alarm_status_taxonomy');

function bokun_booking_history_table_exists() {
    static $table_exists = null;

    if (null !== $table_exists) {
        return $table_exists;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'bokun_booking_history';
    $like_name  = $wpdb->esc_like($table_name);
    $table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like_name)) === $table_name);

    return $table_exists;
}

function bokun_get_team_member_name_from_cookies() {
    foreach ($_COOKIE as $cookie_name => $value) {
        if (0 === strpos($cookie_name, 'bokunTeamMemberAuthorized_')) {
            $team_member_name = sanitize_text_field(wp_unslash($value));

            if (!empty($team_member_name)) {
                return $team_member_name;
            }
        }
    }

    return '';
}

function bokun_get_booking_history_actor_details() {
    $user_id = get_current_user_id();

    if ($user_id) {
        $user = get_user_by('id', $user_id);
        $user_name = '';

        if ($user) {
            $user_name = $user->display_name ? $user->display_name : $user->user_login;
        }

        return [
            'user_id'      => $user_id,
            'user_name'    => sanitize_text_field($user_name),
            'actor_source' => 'wp_user',
        ];
    }

    $team_member_name = bokun_get_team_member_name_from_cookies();

    if (!empty($team_member_name)) {
        return [
            'user_id'      => 0,
            'user_name'    => $team_member_name,
            'actor_source' => 'team_member',
        ];
    }

    return [
        'user_id'      => 0,
        'user_name'    => '',
        'actor_source' => 'guest',
    ];
}

function bokun_record_booking_history($post_id, $booking_id, $action_type, $checked) {
    if (!bokun_booking_history_table_exists()) {
        return;
    }

    global $wpdb;

    $details = bokun_get_booking_history_actor_details();

    $table_name = $wpdb->prefix . 'bokun_booking_history';

    $wpdb->insert(
        $table_name,
        [
            'post_id'      => $post_id ? (int) $post_id : null,
            'booking_id'   => $booking_id,
            'action_type'  => $action_type,
            'is_checked'   => $checked ? 1 : 0,
            'user_id'      => $details['user_id'] ?: null,
            'user_name'    => $details['user_name'],
            'actor_source' => $details['actor_source'],
            'created_at'   => current_time('mysql'),
        ],
        [
            '%d',
            '%s',
            '%s',
            '%d',
            '%d',
            '%s',
            '%s',
            '%s',
        ]
    );
}

// Handle AJAX request to update booking status and track click logs
function update_booking_status() {
    check_ajax_referer('update_booking_nonce', 'security');

    $booking_id = isset($_POST['booking_id']) ? sanitize_text_field(wp_unslash($_POST['booking_id'])) : '';
    $checked    = isset($_POST['checked']) ? filter_var(wp_unslash($_POST['checked']), FILTER_VALIDATE_BOOLEAN) : false;
    $type       = isset($_POST['type']) ? strtolower(sanitize_text_field(wp_unslash($_POST['type']))) : '';

    if (empty($booking_id)) {
        wp_send_json_error(['message' => 'Invalid booking ID provided.']);
        wp_die();
    }

    $allowed_types = ['full', 'partial', 'not-available', 'refund-partner'];

    if (!in_array($type, $allowed_types, true)) {
        wp_send_json_error(['message' => 'Invalid booking status type provided.']);
        wp_die();
    }

    $args = [
        'post_type'      => 'bokun_booking',
        'posts_per_page' => 1,
        'meta_query'     => [
            [
                'key'     => '_confirmation_code',
                'value'   => $booking_id,
                'compare' => '=',
            ],
        ],
    ];

    $query   = new WP_Query($args);
    $updated = false;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id  = get_the_ID();
            $taxonomy = 'booking_status';

            switch ($type) {
                case 'not-available':
                    if ($checked) {
                        bokun_assign_tag_to_post($post_id, 'Not Available', $taxonomy);
                    } else {
                        bokun_remove_tag_from_post($post_id, 'Not Available', $taxonomy);
                    }
                    break;
                case 'refund-partner':
                    if ($checked) {
                        bokun_assign_tag_to_post($post_id, 'Refund Requested from Partner', $taxonomy);
                    } else {
                        bokun_remove_tag_from_post($post_id, 'Refund Requested from Partner', $taxonomy);
                    }
                    break;
                default:
                    $specific_term = ($type === 'full') ? 'Full' : 'Partial';

                    if ($checked) {
                        bokun_assign_tag_to_post($post_id, 'Booking Made', $taxonomy);
                        bokun_assign_tag_to_post($post_id, $specific_term, $taxonomy);
                        bokun_remove_tag_from_post($post_id, 'Booking Not Made', $taxonomy);
                    } else {
                        bokun_remove_tag_from_post($post_id, $specific_term, $taxonomy);
                        $remaining_terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
                        if (!in_array('Full', $remaining_terms) && !in_array('Partial', $remaining_terms)) {
                            bokun_assign_tag_to_post($post_id, 'Booking Not Made', $taxonomy);
                            bokun_remove_tag_from_post($post_id, 'Booking Made', $taxonomy);
                        }
                    }
                    break;
            }

            bokun_record_booking_history($post_id, $booking_id, $type, $checked);
            $updated = true;
        }
        wp_reset_postdata();
    }

    if ($updated) {
        wp_send_json_success(['message' => 'Booking status updated']);
    } else {
        wp_send_json_error(['message' => 'Booking ID not found.']);
    }

    wp_die();
}

add_action('wp_ajax_update_booking_status', 'update_booking_status');
add_action('wp_ajax_nopriv_update_booking_status', 'update_booking_status');

// Handle AJAX request to create Team Member taxonomy terms
function bokun_handle_add_team_member() {
    check_ajax_referer('add_team_member_nonce', 'security');

    $team_member_name = isset($_POST['team_member_name']) ? sanitize_text_field(wp_unslash($_POST['team_member_name'])) : '';
    $team_member_name = trim($team_member_name);

    if ('' === $team_member_name) {
        wp_send_json_error([
            'message' => __('Please provide a team member name.', 'bokun-bookings-manager'),
        ]);
    }

    $existing_term = term_exists($team_member_name, 'team_member');

    if ($existing_term) {
        wp_send_json_success([
            'message' => __('This team member already exists.', 'bokun-bookings-manager'),
            'created' => false,
        ]);
    }

    $result = wp_insert_term($team_member_name, 'team_member');

    if (is_wp_error($result)) {
        wp_send_json_error([
            'message' => $result->get_error_message(),
        ]);
    }

    wp_send_json_success([
        'message' => __('Team member added successfully.', 'bokun-bookings-manager'),
        'created' => true,
    ]);
}

add_action('wp_ajax_add_team_member', 'bokun_handle_add_team_member');
add_action('wp_ajax_nopriv_add_team_member', 'bokun_handle_add_team_member');

// Function to process price categories and save to fixed fields
function process_price_categories_and_save($post_id, $booking_data) {
    $category_counts = [];

    // Check if 'productBookings' exists and has at least one entry
    if (isset($booking_data['productBookings'][0]['fields']['priceCategoryBookings'])) {
        $price_category_bookings = $booking_data['productBookings'][0]['fields']['priceCategoryBookings'];

        // Loop through each price category booking
        foreach ($price_category_bookings as $price_category_booking) {
            if (isset($price_category_booking['pricingCategory']['fullTitle']) && isset($price_category_booking['quantity'])) {
                $category_name = $price_category_booking['pricingCategory']['fullTitle'];
                $quantity = intval($price_category_booking['quantity']);

                // Increment the count for each category name
                $category_counts[$category_name] = ($category_counts[$category_name] ?? 0) + $quantity;
            }
        }
    }

    // Sort the categories by highest count first
    arsort($category_counts);

    // Assign the top 5 categories to fixed fields
    $pricecategory_fields = ['pricecategory1', 'pricecategory2', 'pricecategory3', 'pricecategory4', 'pricecategory5'];

    $index = 0;
    foreach ($category_counts as $category_name => $count) {
        if ($index < 5) {
            $field_name = $pricecategory_fields[$index];
            $value_to_save = $count . ' ' . $category_name;
            update_post_meta($post_id, $field_name, sanitize_text_field($value_to_save));
        }
        $index++;
    }

    // Clear remaining fields if fewer than 5 categories
    for (; $index < 5; $index++) {
        $field_name = $pricecategory_fields[$index];
        update_post_meta($post_id, $field_name, '');
    }

    // Clear cache
    wp_cache_delete($post_id, 'post_meta');
}

// Hook into the save_post action for bokun_booking post type
add_action('save_post', 'run_process_price_categories', 10, 3);

// Function to run the processing of price categories after Bokun Booking is saved
function run_process_price_categories($post_id, $post, $update) {
    // Avoid processing autosaves or revisions
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    // Check if this post is being processed during import
    if (!get_post_meta($post_id, '_import_in_progress', true)) {
        return; // Exit if not during import
    }
    // Retrieve the booking data (replace 'your_booking_data_key' with the correct key)
    $booking_data = get_post_meta($post_id, 'your_booking_data_key', true);

    if (!empty($booking_data)) {
        // Process and save price categories
        process_price_categories_and_save($post_id, $booking_data);
    }
}

// Add a custom metabox to display custom fields on the edit page
add_action('add_meta_boxes', 'bokun_add_custom_fields_metabox');
function bokun_add_custom_fields_metabox() {
    add_meta_box(
        'bokun_custom_fields',
        __('Booking Custom Fields'),
        'bokun_display_custom_fields_metabox',
        'bokun_booking',
        'normal',
        'default'
    );
}

function bokun_display_custom_fields_metabox($post) {
    // Retrieve all custom fields associated with this post
    $custom_fields = get_post_meta($post->ID);

    echo '<table class="form-table">';
    foreach ($custom_fields as $key => $value) {
        // Check if the value is serialized or an array and handle it accordingly
        $display_value = maybe_unserialize($value[0]);

        // If it's an array or object, convert it to JSON for readable display
        if (is_array($display_value) || is_object($display_value)) {
            $display_value = json_encode($display_value);
        }

        echo '<tr>';
        echo '<th><label for="' . esc_attr($key) . '">' . esc_html($key) . '</label></th>';
        echo '<td><input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($display_value) . '" readonly></td>';
        echo '</tr>';
    }
    echo '</table>';
}

// Display booking status checkboxes next to each booking
function booking_checkbox_shortcode($atts) {
    global $post;
    $booking_id = get_post_meta($post->ID, '_confirmation_code', true);

    // Check if relevant booking status terms are assigned to the post
    $full_checked           = has_term('full', 'booking_status', $post->ID) ? 'checked' : '';
    $partial_checked        = has_term('partial', 'booking_status', $post->ID) ? 'checked' : '';
    $refund_partner_checked = has_term('refund-requested-from-partner', 'booking_status', $post->ID) ? 'checked' : '';

    ob_start();
    ?>
    <div class="elementor-widget-container">
        <label>
            <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="full" <?php echo $full_checked; ?>>
            Full
        </label>
        <label>
            <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="partial" <?php echo $partial_checked; ?>>
            Partial
        </label>
        <label>
            <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="refund-partner" <?php echo $refund_partner_checked; ?>>
            Refund Requested from Partner
        </label>
        <label>
            <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="not-available" <?php echo has_term('not-available', 'booking_status', $post->ID) ? 'checked' : ''; ?>>
            Not Available
        </label>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('booking_checkbox', 'booking_checkbox_shortcode');

/**
 * Retrieve the identifier used to scope team member authorization.
 *
 * @return string
 */
function bokun_team_member_get_page_identifier() {
    $page_identifier = 0;

    if (function_exists('get_queried_object_id')) {
        $page_identifier = get_queried_object_id();
    }

    if (!$page_identifier && function_exists('get_the_ID')) {
        $page_identifier = get_the_ID();
    }

    if (!$page_identifier) {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $page_identifier = 'url_' . md5(home_url($request_uri));
    }

    return (string) $page_identifier;
}

/**
 * Build the storage key used for client/server coordination.
 *
 * @param null|string $page_identifier
 *
 * @return string
 */
function bokun_team_member_get_storage_key($page_identifier = null) {
    $storage_key = 'bokunTeamMemberAuthorized_sitewide';

    /**
     * Filter the storage key used for client/server coordination.
     *
     * @param string      $storage_key    Calculated storage key.
     * @param string|null $page_identifier Identifier associated with the current request.
     */
    return (string) apply_filters('bokun_team_member_storage_key', $storage_key, $page_identifier);
}

/**
 * Retrieve the legacy per-page storage key used prior to the site-wide session.
 *
 * @param null|string $page_identifier
 *
 * @return string
 */
function bokun_team_member_get_legacy_storage_key($page_identifier = null) {
    if (null === $page_identifier) {
        $page_identifier = bokun_team_member_get_page_identifier();
    }

    if (!$page_identifier) {
        return '';
    }

    return 'bokunTeamMemberAuthorized_' . $page_identifier;
}

/**
 * Determine whether the current request has been authorized by a team member.
 *
 * @param null|string $page_identifier
 *
 * @return bool
 */
function bokun_team_member_is_authorized($page_identifier = null) {
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return true;
    }

    $storage_key = bokun_team_member_get_storage_key($page_identifier);
    $cookie_value = '';

    if (isset($_COOKIE[$storage_key])) {
        $cookie_value = sanitize_text_field(wp_unslash($_COOKIE[$storage_key]));
    } elseif (null !== $page_identifier) {
        $legacy_key = bokun_team_member_get_legacy_storage_key($page_identifier);

        if ($legacy_key && isset($_COOKIE[$legacy_key])) {
            $cookie_value = sanitize_text_field(wp_unslash($_COOKIE[$legacy_key]));

            if ($cookie_value) {
                $expiration = time() + (defined('YEAR_IN_SECONDS') ? YEAR_IN_SECONDS : 31536000);

                if (!headers_sent()) {
                    setcookie($storage_key, $cookie_value, $expiration, '/');
                }

                $_COOKIE[$storage_key] = $cookie_value;
            }
        }
    }

    $authorized = !empty($cookie_value);

    /**
     * Filter whether the visitor is authorized to view the gated content.
     *
     * @param bool   $authorized   Current authorization determination.
     * @param string $page_identifier Identifier used for the gate.
     * @param string $storage_key  Cookie/local storage key inspected.
     * @param string $cookie_value Raw cookie value.
     */
    return (bool) apply_filters(
        'bokun_team_member_is_authorized',
        $authorized,
        $page_identifier,
        $storage_key,
        $cookie_value
    );
}

/**
 * Check if the active request should be gated behind the team member form.
 *
 * @return bool
 */
function bokun_team_member_should_gate_request() {
    $should_gate = is_post_type_archive('bokun_booking') || is_singular('bokun_booking');

    /**
     * Filter whether the current request should be intercepted by the gate.
     *
     * @param bool $should_gate Calculated gating flag.
     */
    return (bool) apply_filters('bokun_team_member_should_gate_request', $should_gate);
}

/**
 * Render the standalone gate page that only displays the authorization overlay.
 *
 * @return string
 */
function bokun_team_member_render_gate_page() {
    ob_start();
    status_header(403);
    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php wp_head(); ?>
        <style>
            body.bokun-team-member-gate {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #0f172a;
            }

            body.bokun-team-member-gate .bokun-team-member-overlay {
                position: static;
                inset: auto;
            }
        </style>
    </head>
    <body <?php body_class('bokun-team-member-gate'); ?>>
        <?php
        if (function_exists('wp_body_open')) {
            wp_body_open();
        }
        echo do_shortcode('[team_member_field]');
        ?>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php

    return (string) ob_get_clean();
}

/**
 * Enforce the gate by short-circuiting the request when necessary.
 */
function bokun_team_member_enforce_gate() {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    if (is_feed() || is_embed()) {
        return;
    }

    if (!bokun_team_member_should_gate_request()) {
        return;
    }

    $page_identifier = bokun_team_member_get_page_identifier();

    if (bokun_team_member_is_authorized($page_identifier)) {
        return;
    }

    echo bokun_team_member_render_gate_page();
    exit;
}
add_action('template_redirect', 'bokun_team_member_enforce_gate', 0);

// Display a form to add Team Member taxonomy terms from the front-end
function bokun_team_member_submission_shortcode() {
    $input_id   = 'bokun-team-member-' . wp_rand(1000, 9999);
    $overlay_id = 'bokun-team-member-overlay-' . wp_rand(1000, 9999);

    $page_identifier      = bokun_team_member_get_page_identifier();
    $storage_key          = bokun_team_member_get_storage_key($page_identifier);
    $legacy_storage_key   = bokun_team_member_get_legacy_storage_key($page_identifier);

    ob_start();
    ?>
    <div id="<?php echo esc_attr($overlay_id); ?>" class="bokun-team-member-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="bokun-team-member-overlay__dialog">
            <h2 class="bokun-team-member-overlay__title"><?php esc_html_e('Team Member Verification', 'bokun-bookings-manager'); ?></h2>
            <p class="bokun-team-member-overlay__description"><?php esc_html_e('Enter your name to access this page.', 'bokun-bookings-manager'); ?></p>
            <form class="bokun-team-member-form" data-overlay-id="<?php echo esc_attr($overlay_id); ?>" data-storage-key="<?php echo esc_attr($storage_key); ?>" data-legacy-storage-key="<?php echo esc_attr($legacy_storage_key); ?>" novalidate>
                <label class="bokun-team-member-form__label" for="<?php echo esc_attr($input_id); ?>"><?php esc_html_e('Team Member Name', 'bokun-bookings-manager'); ?></label>
                <input class="bokun-team-member-form__input" type="text" id="<?php echo esc_attr($input_id); ?>" name="team_member_name" autocomplete="off" required>
                <button class="bokun-team-member-form__button" type="submit"><?php esc_html_e('Confirm Access', 'bokun-bookings-manager'); ?></button>
                <span class="bokun-team-member-message" role="status" aria-live="polite"></span>
            </form>
        </div>
    </div>
    <style>
        body.bokun-team-member-overlay-active {
            overflow: hidden;
        }

        .bokun-team-member-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: rgba(15, 23, 42, 0.92);
            z-index: 2147483000;
        }

        .bokun-team-member-overlay.is-visible {
            display: flex;
        }

        .bokun-team-member-overlay__dialog {
            width: 100%;
            max-width: 420px;
            padding: 2rem;
            border-radius: 1rem;
            background: #ffffff;
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.35);
            text-align: left;
        }

        .bokun-team-member-overlay__title {
            margin: 0 0 0.75rem;
            font-size: 1.5rem;
            line-height: 1.2;
            color: #0f172a;
        }

        .bokun-team-member-overlay__description {
            margin: 0 0 1.5rem;
            color: #475569;
            font-size: 0.95rem;
        }

        .bokun-team-member-form {
            display: grid;
            gap: 1rem;
        }

        .bokun-team-member-form__label {
            font-weight: 600;
            color: #0f172a;
        }

        .bokun-team-member-form__input {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid #cbd5f1;
            font-size: 1rem;
            line-height: 1.25rem;
        }

        .bokun-team-member-form__input:focus {
            border-color: #2563eb;
            outline: 2px solid transparent;
            outline-offset: 2px;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25);
        }

        .bokun-team-member-form__button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            border-radius: 999px;
            border: none;
            background: #2563eb;
            color: #ffffff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .bokun-team-member-form__button:hover,
        .bokun-team-member-form__button:focus {
            background: #1d4ed8;
        }

        .bokun-team-member-form.is-loading .bokun-team-member-form__button {
            opacity: 0.6;
            cursor: wait;
        }

        .bokun-team-member-message {
            min-height: 1.5rem;
            font-size: 0.9rem;
            color: #0f172a;
        }
    </style>
    <script>
        (function() {
            var overlayId = <?php echo wp_json_encode($overlay_id); ?>;
            var storageKey = <?php echo wp_json_encode($storage_key); ?>;
            var legacyStorageKey = <?php echo wp_json_encode($legacy_storage_key); ?>;

            function ensureOverlayInBody(element) {
                if (!element) {
                    return;
                }

                if (document.body && element.parentNode !== document.body) {
                    document.body.appendChild(element);
                }
            }

            function getCookieValue(name) {
                var cookies = document.cookie ? document.cookie.split(';') : [];

                for (var i = 0; i < cookies.length; i++) {
                    var cookie = cookies[i].trim();

                    if (cookie.indexOf(name + '=') === 0) {
                        return decodeURIComponent(cookie.substring(name.length + 1));
                    }
                }

                return '';
            }

            function setCookie(name, value) {
                var expires = new Date();
                expires.setFullYear(expires.getFullYear() + 1);
                document.cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value) + '; expires=' + expires.toUTCString() + '; path=/';
            }

            function removeStoredName(key) {
                if (!key) {
                    return;
                }

                try {
                    window.localStorage.removeItem(key);
                } catch (error) {}

                try {
                    window.sessionStorage.removeItem(key);
                } catch (error) {}

                document.cookie = encodeURIComponent(key) + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            }

            function readStoredName(key) {
                if (!key) {
                    return '';
                }

                var value = '';

                try {
                    value = window.localStorage.getItem(key) || '';
                } catch (error) {
                    value = '';
                }

                if (!value) {
                    try {
                        value = window.sessionStorage.getItem(key) || '';
                    } catch (error) {
                        value = '';
                    }
                }

                if (!value) {
                    value = getCookieValue(key);
                }

                return value;
            }

            function getStoredName() {
                var value = readStoredName(storageKey);

                if (!value && legacyStorageKey) {
                    value = readStoredName(legacyStorageKey);

                    if (value) {
                        saveName(value);
                    }
                }

                return value;
            }

            function saveName(value) {
                var stored = false;

                try {
                    window.localStorage.setItem(storageKey, value);
                    stored = true;
                } catch (error) {
                    stored = false;
                }

                if (!stored) {
                    try {
                        window.sessionStorage.setItem(storageKey, value);
                        stored = true;
                    } catch (error) {
                        stored = false;
                    }
                }

                setCookie(storageKey, value);

                if (legacyStorageKey && legacyStorageKey !== storageKey) {
                    removeStoredName(legacyStorageKey);
                }
            }

            function focusInput(element) {
                if (!element) {
                    return;
                }

                var input = element.querySelector('input[name="team_member_name"]');

                if (input) {
                    setTimeout(function() {
                        try {
                            input.focus();
                            input.select();
                        } catch (error) {}
                    }, 50);
                }
            }

            function lock(element) {
                ensureOverlayInBody(element);

                element.classList.add('is-visible');
                element.setAttribute('aria-hidden', 'false');

                if (document.body) {
                    document.body.classList.add('bokun-team-member-overlay-active');
                }

                focusInput(element);
            }

            function unlock(element) {
                element.classList.remove('is-visible');
                element.setAttribute('aria-hidden', 'true');

                if (document.body) {
                    document.body.classList.remove('bokun-team-member-overlay-active');

                    if (document.body.classList.contains('bokun-team-member-gate')) {
                        setTimeout(function() {
                            window.location.reload();
                        }, 50);
                    }
                }
            }

            function initOverlay() {
                var overlay = document.getElementById(overlayId);

                if (!overlay) {
                    return;
                }

                ensureOverlayInBody(overlay);

                if (getStoredName()) {
                    unlock(overlay);
                } else {
                    lock(overlay);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initOverlay);
            } else {
                initOverlay();
            }

            window.bokunTeamMemberAccess = window.bokunTeamMemberAccess || {};
            window.bokunTeamMemberAccess[overlayId] = {
                storageKey: storageKey,
                legacyStorageKey: legacyStorageKey,
                save: saveName,
                unlock: function() {
                    var overlay = document.getElementById(overlayId);

                    if (overlay) {
                        unlock(overlay);
                    }
                },
                lock: function() {
                    var overlay = document.getElementById(overlayId);

                    if (overlay) {
                        lock(overlay);
                    }
                }
            };
        })();
    </script>
    <?php

    return ob_get_clean();
}
add_shortcode('team_member_field', 'bokun_team_member_submission_shortcode');

function bokun_team_member_reset_button_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'label' => __('Reset Team Member Session', 'bokun-bookings-manager'),
        ),
        $atts,
        'team_member_reset_button'
    );

    $button_id = 'bokun-team-member-reset-' . wp_rand(1000, 9999);

    $page_identifier = bokun_team_member_get_page_identifier();
    $storage_key     = bokun_team_member_get_storage_key($page_identifier);

    ob_start();
    ?>
    <button id="<?php echo esc_attr($button_id); ?>" class="bokun-team-member-reset-button" type="button">
        <?php echo esc_html($atts['label']); ?>
    </button>
    <script>
        (function() {
            var buttonId = <?php echo wp_json_encode($button_id); ?>;
            var storageKey = <?php echo wp_json_encode($storage_key); ?>;

            var TEAM_MEMBER_STORAGE_PREFIX = 'bokunTeamMemberAuthorized_';

            function removeStorageValue(key) {
                if (!key) {
                    return;
                }

                try {
                    window.localStorage.removeItem(key);
                } catch (error) {}

                try {
                    window.sessionStorage.removeItem(key);
                } catch (error) {}

                document.cookie = encodeURIComponent(key) + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            }

            function clearStorageWithPrefix(storage, prefix) {
                if (!storage || typeof storage.length === 'undefined') {
                    return;
                }

                for (var index = storage.length - 1; index >= 0; index--) {
                    var storageKey = storage.key(index);

                    if (storageKey && storageKey.indexOf(prefix) === 0) {
                        try {
                            storage.removeItem(storageKey);
                        } catch (error) {}
                    }
                }
            }

            function clearCookiesWithPrefix(prefix) {
                if (!document.cookie || !prefix) {
                    return;
                }

                var cookies = document.cookie.split(';');

                for (var i = 0; i < cookies.length; i++) {
                    var cookie = cookies[i].trim();

                    if (!cookie) {
                        continue;
                    }

                    var separatorIndex = cookie.indexOf('=');
                    var name = separatorIndex >= 0 ? cookie.substring(0, separatorIndex) : cookie;

                    if (name && name.indexOf(prefix) === 0) {
                        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
                    }
                }
            }

            function clearAllTeamMemberStorage() {
                clearStorageWithPrefix(window.localStorage, TEAM_MEMBER_STORAGE_PREFIX);
                clearStorageWithPrefix(window.sessionStorage, TEAM_MEMBER_STORAGE_PREFIX);
                clearCookiesWithPrefix(TEAM_MEMBER_STORAGE_PREFIX);
            }

            function lockOverlay(entry, overlayId) {
                if (entry && typeof entry.lock === 'function') {
                    entry.lock();
                    return true;
                }

                if (!overlayId) {
                    return false;
                }

                var overlay = document.getElementById(overlayId);

                if (!overlay) {
                    return false;
                }

                if (overlay.parentNode !== document.body && document.body) {
                    document.body.appendChild(overlay);
                }

                overlay.classList.add('is-visible');
                overlay.setAttribute('aria-hidden', 'false');

                if (document.body) {
                    document.body.classList.add('bokun-team-member-overlay-active');
                }

                var input = overlay.querySelector('input[name="team_member_name"]');

                if (input) {
                    setTimeout(function() {
                        try {
                            input.focus();
                            input.select();
                        } catch (error) {}
                    }, 50);
                }

                return true;
            }

            function resetTeamMemberSession() {
                clearAllTeamMemberStorage();

                if (storageKey) {
                    removeStorageValue(storageKey);
                }

                var registry = window.bokunTeamMemberAccess || {};
                var overlayIds = Object.keys(registry);
                var locked = false;

                for (var i = 0; i < overlayIds.length; i++) {
                    var id = overlayIds[i];
                    var entry = registry[id];

                    if (entry && entry.storageKey) {
                        removeStorageValue(entry.storageKey);
                    }

                    if (!locked) {
                        locked = lockOverlay(entry, id);
                    }
                }

                if (!locked) {
                    lockOverlay(null, null);
                }
            }

            var button = document.getElementById(buttonId);

            if (!button) {
                return;
            }

            button.addEventListener('click', function(event) {
                event.preventDefault();
                resetTeamMemberSession();
            });
        })();
    </script>
    <?php

    return ob_get_clean();
}
add_shortcode('team_member_reset_button', 'bokun_team_member_reset_button_shortcode');

// Force publish future-dated 'bokun_booking' posts after they're saved
function bokun_force_publish_after_save($post_id, $post, $update) {
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    if ($post->post_type === 'bokun_booking' && $post->post_status === 'future') {
        remove_action('save_post', 'bokun_force_publish_after_save', 10);

        wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish',
        ]);

        add_action('save_post', 'bokun_force_publish_after_save', 10, 3);
    }
}
add_action('save_post', 'bokun_force_publish_after_save', 10, 3);

// Add custom fields to the 'Add New' term page
add_action('product_tags_add_form_fields', 'add_product_tag_custom_fields', 10, 2);
function add_product_tag_custom_fields($taxonomy) {
    ?>
    <div class="form-field">
        <label for="term_meta[statusok]"><?php _e('Status OK', 'bokun-bookings-manager'); ?></label>
        <input type="number" name="term_meta[statusok]" id="term_meta[statusok]" value="">
        <p class="description"><?php _e('Enter the number of days for Status OK.', 'bokun-bookings-manager'); ?></p>
    </div>
    <div class="form-field">
        <label for="term_meta[statusattention]"><?php _e('Status Attention', 'bokun-bookings-manager'); ?></label>
        <input type="number" name="term_meta[statusattention]" id="term_meta[statusattention]" value="">
        <p class="description"><?php _e('Enter the number of days for Status Attention.', 'bokun-bookings-manager'); ?></p>
    </div>
    <div class="form-field">
        <label for="term_meta[statusalarm]"><?php _e('Status Alarm', 'bokun-bookings-manager'); ?></label>
        <input type="number" name="term_meta[statusalarm]" id="term_meta[statusalarm]" value="">
        <p class="description"><?php _e('Enter the number of days for Status Alarm.', 'bokun-bookings-manager'); ?></p>
    </div>
    <?php
}

// Add custom fields to the 'Edit' term page
add_action('product_tags_edit_form_fields', 'edit_product_tag_custom_fields', 10, 2);
function edit_product_tag_custom_fields($term, $taxonomy) {
    $statusok = get_term_meta($term->term_id, 'statusok', true);
    $statusattention = get_term_meta($term->term_id, 'statusattention', true);
    $statusalarm = get_term_meta($term->term_id, 'statusalarm', true);
    ?>
    <tr class="form-field">
        <th scope="row" valign="top"><label for="term_meta[statusok]"><?php _e('Status OK', 'bokun-bookings-manager'); ?></label></th>
        <td>
            <input type="number" name="term_meta[statusok]" id="term_meta[statusok]" value="<?php echo esc_attr($statusok) ? esc_attr($statusok) : ''; ?>">
            <p class="description"><?php _e('Enter the number of days for Status OK.', 'bokun-bookings-manager'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row" valign="top"><label for="term_meta[statusattention]"><?php _e('Status Attention', 'bokun-bookings-manager'); ?></label></th>
        <td>
            <input type="number" name="term_meta[statusattention]" id="term_meta[statusattention]" value="<?php echo esc_attr($statusattention) ? esc_attr($statusattention) : ''; ?>">
            <p class="description"><?php _e('Enter the number of days for Status Attention.', 'bokun-bookings-manager'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row" valign="top"><label for="term_meta[statusalarm]"><?php _e('Status Alarm', 'bokun-bookings-manager'); ?></label></th>
        <td>
            <input type="number" name="term_meta[statusalarm]" id="term_meta[statusalarm]" value="<?php echo esc_attr($statusalarm) ? esc_attr($statusalarm) : ''; ?>">
            <p class="description"><?php _e('Enter the number of days for Status Alarm.', 'bokun-bookings-manager'); ?></p>
        </td>
    </tr>
    <?php
}

// Save the custom fields
add_action('created_product_tags', 'save_product_tag_custom_fields', 10, 2);
add_action('edited_product_tags', 'save_product_tag_custom_fields', 10, 2);
function save_product_tag_custom_fields($term_id) {
    if (isset($_POST['term_meta'])) {
        $term_meta = $_POST['term_meta'];

        foreach ($term_meta as $key => $value) {
            update_term_meta($term_id, $key, sanitize_text_field($value));
        }
    }
}

// Register the custom REST API endpoint
add_action('rest_api_init', function () {
    register_rest_route('bokun/v1', '/import-bookings', [
        'methods' => 'POST',
        'callback' => 'bokun_import_bookings',
        'permission_callback' => '__return_true', // Adjust based on security needs
    ]);
});

// Callback function for the endpoint to import bookings
function bokun_import_bookings() {
    // Fetch and process the bookings
    $bookings = bokun_fetch_bookings();
    if (is_array($bookings)) {
        $summary = bokun_save_bookings_as_posts($bookings, 'rest');
        if (!is_array($summary)) {
            $summary = array();
        }

        $normalized_summary = array(
            'total'     => isset($summary['total']) ? intval($summary['total']) : 0,
            'processed' => isset($summary['processed']) ? intval($summary['processed']) : 0,
            'created'   => isset($summary['created']) ? intval($summary['created']) : 0,
            'updated'   => isset($summary['updated']) ? intval($summary['updated']) : 0,
            'skipped'   => isset($summary['skipped']) ? intval($summary['skipped']) : 0,
        );

        return new WP_REST_Response(
            array(
                'message' => 'Bookings imported successfully.',
                'summary' => $normalized_summary,
            ),
            200
        );
    } else {
        return new WP_REST_Response('Error fetching bookings: ' . $bookings, 500);
    }
}

// Add `partnerpageid` field to the 'Add New' term page for product tags
add_action('product_tags_add_form_fields', 'add_partnerpageid_field', 10, 2);
function add_partnerpageid_field($taxonomy) {
    ?>
    <div class="form-field">
        <label for="term_meta[partnerpageid]"><?php _e('Partner Page ID', 'bokun-bookings-manager'); ?></label>
        <input type="text" name="term_meta[partnerpageid]" id="term_meta[partnerpageid]" value="">
        <p class="description"><?php _e('Enter the Partner Page ID.', 'bokun-bookings-manager'); ?></p>
    </div>
    <?php
}

// Add `partnerpageid` field to the 'Edit' term page for product tags
add_action('product_tags_edit_form_fields', 'edit_partnerpageid_field', 10, 2);
function edit_partnerpageid_field($term, $taxonomy) {
    $partnerpageid = get_term_meta($term->term_id, 'partnerpageid', true);
    ?>
    <tr class="form-field">
        <th scope="row" valign="top"><label for="term_meta[partnerpageid]"><?php _e('Partner Page ID', 'bokun-bookings-manager'); ?></label></th>
        <td>
            <input type="text" name="term_meta[partnerpageid]" id="term_meta[partnerpageid]" value="<?php echo esc_attr($partnerpageid) ? esc_attr($partnerpageid) : ''; ?>">
            <p class="description"><?php _e('Enter the Partner Page ID.', 'bokun-bookings-manager'); ?></p>
        </td>
    </tr>
    <?php
}

// Save `partnerpageid` field for product tags
add_action('created_product_tags', 'save_partnerpageid_field', 10, 2);
add_action('edited_product_tags', 'save_partnerpageid_field', 10, 2);
function save_partnerpageid_field($term_id) {
    if (isset($_POST['term_meta']['partnerpageid'])) {
        update_term_meta($term_id, 'partnerpageid', sanitize_text_field($_POST['term_meta']['partnerpageid']));
    }
}

// Shortcode to retrieve the `partnerpageid` value from the current post's product tag
function retrieve_partnerpageid_shortcode($atts) {
    global $post;

    // Ensure we're in a loop with a post ID
    if (empty($post->ID)) {
        return '';
    }

    // Fetch the terms associated with the post in the `product_tags` taxonomy
    $terms = wp_get_post_terms($post->ID, 'product_tags');

    // Check if terms are available and retrieve the `partnerpageid` from the first term
    if (!empty($terms) && !is_wp_error($terms)) {
        $term_id = $terms[0]->term_id; // Use the first associated term
        $partnerpageid = get_term_meta($term_id, 'partnerpageid', true);

        // Return the `partnerpageid` value if it exists, or an empty string if not
        return !empty($partnerpageid) ? esc_html($partnerpageid) : '';
    }

    return ''; // Return empty if no term or partnerpageid value found
}
add_shortcode('partnerpageid', 'retrieve_partnerpageid_shortcode');

// Helper function to extract inclusions after the third '---'
function bokun_get_inclusions_clean($text) {
    // Standardize the separators
    $text = preg_replace('/\s*---\s*/', '---', $text);
    
    // Split the text by '---'
    $parts = explode('---', $text);
    
    // Ensure we have at least 4 parts (3 separators before inclusions)
    if (count($parts) >= 4) {
        // Rejoin parts from the fourth element onward
        return trim(implode('---', array_slice($parts, 3)));
    }

    // Return the original text if not enough '---' parts exist
    return $text;
}