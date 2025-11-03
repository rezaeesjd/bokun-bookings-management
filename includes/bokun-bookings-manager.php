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

/**
 * Format raw booking timestamps into a human readable datetime string.
 *
 * @param string|int|float|null $value Raw value returned by the API.
 *
 * @return string Formatted datetime in site timezone or the original value if it cannot be parsed.
 */
function bokun_format_booking_datetime($value) {
    if (null === $value || '' === $value) {
        return '';
    }

    $value = (string) $value;

    if (is_numeric($value)) {
        $numeric_value = (float) $value;

        // Values from the API are sometimes milliseconds since epoch; convert to seconds.
        if ($numeric_value > 9999999999) {
            $numeric_value /= 1000;
        }

        if ($numeric_value > 0) {
            return wp_date('Y-m-d H:i:s', (int) $numeric_value);
        }
    }

    $timestamp = strtotime($value);

    if (false !== $timestamp) {
        return wp_date('Y-m-d H:i:s', $timestamp);
    }

    return $value;
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

/**
 * Ensure the Bokun booking custom post type is registered before attempting to
 * create or update posts.
 *
 * Running imports before WordPress executes the `init` action means the custom
 * post type registration that normally occurs on that hook has not happened
 * yet. Creating posts in that state triggers capability checks against an
 * unknown post type, which raises the "map_meta_cap was called incorrectly"
 * notice logged in production. This helper makes a best effort to register the
 * post type on demand (using the plugin's bootstrap class when available) so
 * the importer can run without emitting warnings.
 *
 * @return bool Whether the custom post type is available for use.
 */
function bokun_ensure_booking_post_type_registered() {
    if (post_type_exists('bokun_booking')) {
        return true;
    }

    if (!function_exists('register_post_type')) {
        return false;
    }

    global $rb;

    if (isset($rb) && is_object($rb) && method_exists($rb, 'bokun_register_custom_post_type')) {
        $rb->bokun_register_custom_post_type();

        if (post_type_exists('bokun_booking')) {
            remove_action('init', array($rb, 'bokun_register_custom_post_type'));
            return true;
        }
    }

    return post_type_exists('bokun_booking');
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

    if (!bokun_ensure_booking_post_type_registered()) {
        $stats['skipped'] = $stats['total'];

        $error_message = __('The Bokun Booking post type is not registered. Import aborted to avoid capability errors.', 'bokun-bookings-manager');

        bokun_set_import_progress_state($context, array(
            'status'    => 'error',
            'total'     => $stats['total'],
            'processed' => 0,
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => $stats['skipped'],
            'message'   => $error_message,
        ));

        error_log($error_message);

        return $stats;
    }

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

        bokun_save_specific_fields($post_id, $booking, $context);
        bokun_save_all_fields_as_meta($post_id, $booking);
        bokun_save_meeting_point_meta($post_id, $booking, $context);
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
function bokun_save_specific_fields($post_id, $booking, $context = 'default') {
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

    bokun_sync_product_tag_metadata_from_booking($productBooking, $context);

    // Handle timestamps properly for date fields
    $booking_creation_date = $booking['creationDate'] ?? '';

    if ('' !== $booking_creation_date) {
        $sanitized_creation_date = sanitize_text_field($booking_creation_date);

        $original_creation_date = get_post_meta($post_id, '_original_creation_date', true);
        if ($original_creation_date !== $sanitized_creation_date) {
            update_post_meta($post_id, '_original_creation_date', $sanitized_creation_date);
        }

        $formatted_creation_date = bokun_format_booking_datetime($sanitized_creation_date);

        if ('' !== $formatted_creation_date) {
            update_post_meta($post_id, 'bookingcreationdate', sanitize_text_field($formatted_creation_date));
        } else {
            delete_post_meta($post_id, 'bookingcreationdate');
        }
    } else {
        delete_post_meta($post_id, 'bookingcreationdate');
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
        bokun_sync_product_tag_metadata_from_booking($productBooking, $context);
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

/**
 * Store metadata about the Bokun product on the associated taxonomy term.
 *
 * @param array  $product_booking Product booking payload from the API.
 * @param string $context         Import context used when fetching the booking.
 */
function bokun_sync_product_tag_metadata_from_booking($product_booking, $context = 'default') {
    if (!is_array($product_booking)) {
        return;
    }

    $product      = $product_booking['product'] ?? [];
    $product_id   = isset($product['id']) ? (int) $product['id'] : 0;
    $product_name = isset($product['title']) ? sanitize_text_field($product['title']) : '';

    if ($product_id <= 0 || '' === $product_name) {
        return;
    }

    $term = get_term_by('name', $product_name, 'product_tags');

    if (!$term || is_wp_error($term)) {
        return;
    }

    $existing_id = (int) get_term_meta($term->term_id, 'bokun_product_id', true);

    if ($existing_id !== $product_id) {
        update_term_meta($term->term_id, 'bokun_product_id', $product_id);
    }

    $existing_viator_id = (int) get_term_meta($term->term_id, 'viatorProductID', true);

    if ($existing_viator_id !== $product_id) {
        update_term_meta($term->term_id, 'viatorProductID', $product_id);
    }

    $context = bokun_normalize_import_context($context);

    if (!empty($context)) {
        $stored_context = get_term_meta($term->term_id, 'bokun_product_import_context', true);

        if ($stored_context !== $context) {
            update_term_meta($term->term_id, 'bokun_product_import_context', $context);
        }
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

/**
 * Store meeting-point metadata using the legacy meta key format that other
 * extensions expect (bk_meetingpoint*).
 *
 * @param int    $post_id Booking post ID.
 * @param array  $booking Raw booking payload from the Bókun API.
 * @param string $context Import context determining which API credentials to use.
 */
function bokun_save_meeting_point_meta($post_id, $booking, $context = 'default') {
    bokun_log_meeting_point_step('Saving meeting-point metadata for booking.', [
        'post_id' => (int) $post_id,
        'context' => bokun_normalize_import_context($context),
    ]);

    $start_points = bokun_extract_meeting_points($booking, $context);

    bokun_log_meeting_point_step('Meeting-point payload extracted.', [
        'type'  => is_array($start_points) ? 'array' : gettype($start_points),
        'count' => is_array($start_points) ? count($start_points) : null,
    ]);

    bokun_store_meeting_point_meta_on_post($post_id, $start_points);

    $product_ids = bokun_get_booking_product_ids($booking);

    bokun_log_meeting_point_step('Collected product identifiers from booking.', [
        'product_ids' => $product_ids,
    ]);

    if (!empty($product_ids)) {
        bokun_store_meeting_point_meta_on_products($product_ids, $start_points, $context);
    }
}

/**
 * Write a structured log entry for meeting-point processing steps.
 *
 * @param string $message Human-readable description of the current step.
 * @param array  $context Optional contextual data to include in the log entry.
 */
function bokun_log_meeting_point_step($message, $context = []) {
    $disabled = apply_filters('bokun_disable_meeting_point_logging', false, $message, $context);

    if ($disabled) {
        return;
    }

    $log_entry = '[Bokun Meeting Points] ' . $message;

    if (!empty($context)) {
        $encoded_context = wp_json_encode($context);

        if (false !== $encoded_context) {
            $log_entry .= ' :: ' . $encoded_context;
        }
    }

    error_log($log_entry);
}

/**
 * Store meeting-point data on a post using the legacy meta structure.
 *
 * @param int   $post_id      Post identifier that will receive the metadata.
 * @param mixed $start_points Meeting-point payload from the API.
 */
function bokun_store_meeting_point_meta_on_post($post_id, $start_points) {
    $post_id = (int) $post_id;

    if ($post_id <= 0) {
        return;
    }

    bokun_delete_meeting_point_meta($post_id);

    if (empty($start_points)) {
        bokun_log_meeting_point_step('No meeting-point data available for post.', [
            'post_id' => $post_id,
        ]);
        return;
    }

    if (!is_array($start_points)) {
        update_post_meta($post_id, 'bk_meetingpointtitle', sanitize_text_field((string) $start_points));
        bokun_log_meeting_point_step('Saved scalar meeting-point title.', [
            'post_id' => $post_id,
            'title'   => sanitize_text_field((string) $start_points),
        ]);
        return;
    }

    $start_points = array_values((array) $start_points);

    $primary_title = null;

    foreach ($start_points as $index => $start_point) {
        if (is_array($start_point)) {
            if (isset($start_point['title']) && '' !== $start_point['title']) {
                $sanitized_title = sanitize_text_field($start_point['title']);

                update_post_meta(
                    $post_id,
                    'bk_meetingpointtitle_' . $index,
                    $sanitized_title
                );

                if (null === $primary_title) {
                    $primary_title = $sanitized_title;
                }

                bokun_log_meeting_point_step('Saved meeting-point title for index.', [
                    'post_id' => $post_id,
                    'index'   => $index,
                    'title'   => $sanitized_title,
                ]);
            }
        } else {
            $sanitized_value = sanitize_text_field(is_scalar($start_point) ? (string) $start_point : wp_json_encode($start_point));

            update_post_meta(
                $post_id,
                'bk_meetingpointtitle_' . $index,
                $sanitized_value
            );

            if (null === $primary_title) {
                $primary_title = $sanitized_value;
            }

            bokun_log_meeting_point_step('Saved non-array meeting-point entry as title.', [
                'post_id' => $post_id,
                'index'   => $index,
                'title'   => $sanitized_value,
            ]);
        }
    }

    if (null !== $primary_title) {
        update_post_meta($post_id, 'bk_meetingpointtitle', $primary_title);
        bokun_log_meeting_point_step('Saved primary meeting-point title.', [
            'post_id' => $post_id,
            'title'   => $primary_title,
        ]);
    } else {
        bokun_log_meeting_point_step('Meeting-point payload did not contain a title.', [
            'post_id' => $post_id,
        ]);
    }
}

/**
 * Store meeting-point metadata on related product posts so companion plugins can
 * read the legacy meta keys from the expected location.
 *
 * @param int[] $product_ids  Bokun product identifiers.
 * @param mixed $start_points Meeting-point payload from the API.
 * @param string $context     Import context determining which API credentials to use.
 */
function bokun_store_meeting_point_meta_on_products($product_ids, $start_points, $context = 'default') {
    $product_ids = bokun_normalize_identifier_list($product_ids);

    if (empty($product_ids)) {
        return;
    }

    $context = bokun_normalize_import_context($context);
    $product_post_ids = bokun_find_product_post_ids_for_bokun_ids($product_ids, $context);

    bokun_log_meeting_point_step('Resolved WordPress products for Bokun IDs.', [
        'bokun_ids'   => $product_ids,
        'product_ids' => $product_post_ids,
        'context'     => $context,
    ]);

    if (empty($product_post_ids)) {
        return;
    }

    foreach ($product_post_ids as $product_post_id) {
        bokun_store_meeting_point_meta_on_post($product_post_id, $start_points);
    }
}

/**
 * Locate WordPress product posts that represent the provided Bokun products.
 *
 * @param int[]  $product_ids Bokun product identifiers.
 * @param string $context     Import context determining which API credentials to use.
 *
 * @return int[] WordPress product post IDs.
 */
function bokun_find_product_post_ids_for_bokun_ids($product_ids, $context = 'default') {
    $product_ids = bokun_normalize_identifier_list($product_ids);

    if (empty($product_ids)) {
        return [];
    }

    $context = bokun_normalize_import_context($context);

    $meta_keys = apply_filters(
        'bokun_meeting_point_product_meta_keys',
        [
            '_bokun_product_id',
            'bokun_product_id',
            '_bokun_activity_id',
            'bokun_activity_id',
        ],
        $product_ids,
        $context
    );

    $post_types = apply_filters(
        'bokun_meeting_point_product_post_types',
        ['product'],
        $product_ids,
        $context
    );

    if (empty($post_types) || empty($meta_keys)) {
        return [];
    }

    $found_posts = [];

    static $lookup_cache = [];

    foreach ($product_ids as $product_id) {
        foreach ((array) $meta_keys as $meta_key) {
            if (!is_string($meta_key) || '' === trim($meta_key)) {
                continue;
            }

            $cache_key = implode(':', [$context, $meta_key, $product_id]);

            if (!array_key_exists($cache_key, $lookup_cache)) {
                $query_args = [
                    'post_type'              => (array) $post_types,
                    'post_status'            => 'any',
                    'numberposts'            => -1,
                    'fields'                 => 'ids',
                    'suppress_filters'       => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'meta_query'             => [
                        [
                            'key'   => $meta_key,
                            'value' => $product_id,
                        ],
                    ],
                ];

                $posts = get_posts($query_args);

                if (!is_array($posts)) {
                    $posts = [];
                }

                $lookup_cache[$cache_key] = array_values(array_unique(array_map('intval', $posts)));
            }

            if (!empty($lookup_cache[$cache_key])) {
                $found_posts = array_merge($found_posts, $lookup_cache[$cache_key]);
            }
        }
    }

    $found_posts = array_values(array_unique(array_filter(array_map('intval', $found_posts))));

    return apply_filters('bokun_meeting_point_product_post_ids', $found_posts, $product_ids, $context);
}

/**
 * Remove previously stored meeting-point metadata to avoid stale values.
 *
 * @param int $post_id Booking post ID.
 */
function bokun_delete_meeting_point_meta($post_id) {
    $all_meta = get_post_meta($post_id);

    if (!is_array($all_meta)) {
        return;
    }

    foreach ($all_meta as $meta_key => $values) {
        if (0 === strpos($meta_key, 'bk_meetingpointtitle') || 0 === strpos($meta_key, 'bk_meetingpoint_')) {
            delete_post_meta($post_id, $meta_key);
        }
    }
}

/**
 * Extract the start points array from a booking payload.
 *
 * @param array  $booking Booking payload from the API.
 * @param string $context Import context determining which API credentials to use.
 *
 * @return mixed Meeting-point data (array|string|null).
 */
function bokun_extract_meeting_points($booking, $context = 'default') {
    if (!is_array($booking)) {
        return null;
    }

    $product_bookings = $booking['productBookings'] ?? [];

    if (is_array($product_bookings)) {
        foreach ($product_bookings as $product_booking) {
            if (!is_array($product_booking)) {
                continue;
            }

            if (!empty($product_booking['product']['startPoints'])) {
                bokun_log_meeting_point_step('Found meeting points within product booking payload.', [
                    'source' => 'product.startPoints',
                ]);
                return $product_booking['product']['startPoints'];
            }

            if (!empty($product_booking['startPoints'])) {
                bokun_log_meeting_point_step('Found meeting points within product booking startPoints.', [
                    'source' => 'productBooking.startPoints',
                ]);
                return $product_booking['startPoints'];
            }

            if (!empty($product_booking['product']['startPoint'])) {
                bokun_log_meeting_point_step('Found single meeting point within product booking payload.', [
                    'source' => 'product.startPoint',
                ]);
                return $product_booking['product']['startPoint'];
            }
        }
    }

    if (!empty($booking['startPoints'])) {
        bokun_log_meeting_point_step('Found meeting points at booking root.', [
            'source' => 'booking.startPoints',
        ]);
        return $booking['startPoints'];
    }

    $product_ids = bokun_get_booking_product_ids($booking);

    if (!empty($product_ids)) {
        $normalized_context = bokun_normalize_import_context($context);

        bokun_log_meeting_point_step('Falling back to product-list lookup for meeting points.', [
            'product_ids' => $product_ids,
            'context'     => $normalized_context,
        ]);
        $external_start_points = bokun_get_meeting_points_from_product_lists_for_ids($product_ids, $context);

        if (!empty($external_start_points)) {
            return $external_start_points;
        }

        bokun_log_meeting_point_step('Product-list lookup returned no meeting points. Attempting direct activity lookup.', [
            'product_ids' => $product_ids,
            'context'     => $normalized_context,
        ]);

        foreach ($product_ids as $product_id) {
            $activity_start_points = bokun_get_meeting_points_from_activity($product_id, $context);

            if (!empty($activity_start_points)) {
                return $activity_start_points;
            }
        }
    }

    bokun_log_meeting_point_step('Meeting-point lookup completed without results.', []);

    return null;
}

/**
 * Attempt to locate meeting-point data for a product by querying configured product lists.
 *
 * @param int    $product_id Product (activity) identifier.
 * @param string $context    Import context determining which API credentials to use.
 *
 * @return mixed Meeting-point data or null when unavailable.
 */
function bokun_get_meeting_points_from_product_lists($product_id, $context = 'default') {
    $product_id = (int) $product_id;

    if ($product_id <= 0) {
        return null;
    }

    return bokun_get_meeting_points_from_product_lists_for_ids([$product_id], $context);
}

/**
 * Attempt to locate meeting-point data for any of the provided product identifiers
 * by querying configured product lists.
 *
 * @param int[] $product_ids Product (activity) identifiers.
 * @param string $context    Import context determining which API credentials to use.
 *
 * @return mixed Meeting-point data or null when unavailable.
 */
function bokun_get_meeting_points_from_product_lists_for_ids($product_ids, $context = 'default') {
    $product_ids = bokun_normalize_identifier_list((array) $product_ids);

    if (empty($product_ids)) {
        return null;
    }

    $context = bokun_normalize_import_context($context);
    $product_list_ids = bokun_get_product_list_ids($context);

    bokun_log_meeting_point_step('Attempting product-list lookup for meeting points.', [
        'bokun_ids'     => $product_ids,
        'context'       => $context,
        'product_lists' => $product_list_ids,
    ]);

    if (empty($product_list_ids)) {
        bokun_log_meeting_point_step('No product lists configured for meeting-point lookup.', [
            'context' => $context,
        ]);
        return null;
    }

    foreach ($product_list_ids as $list_id) {
        $activities = bokun_fetch_product_list_activities($list_id, $context);

        bokun_log_meeting_point_step('Fetched product-list activities for lookup.', [
            'list_id'        => $list_id,
            'activity_count' => is_array($activities) ? count($activities) : null,
        ]);

        if (empty($activities)) {
            continue;
        }

        foreach ($activities as $activity) {
            if (!is_array($activity)) {
                continue;
            }

            $activity_ids = bokun_collect_numeric_identifiers($activity, ['id', 'productId', 'activityId', 'experienceId']);

            if (empty($activity_ids) || empty(array_intersect($product_ids, $activity_ids))) {
                continue;
            }

            if (!empty($activity['startPoints'])) {
                bokun_log_meeting_point_step('Matched activity startPoints from product list.', [
                    'list_id'      => $list_id,
                    'activity_ids' => $activity_ids,
                ]);

                return $activity['startPoints'];
            }

            if (!empty($activity['startPoint'])) {
                bokun_log_meeting_point_step('Matched activity startPoint from product list.', [
                    'list_id'      => $list_id,
                    'activity_ids' => $activity_ids,
                ]);

                return $activity['startPoint'];
            }
        }
    }

    bokun_log_meeting_point_step('No meeting points found in configured product lists.', [
        'bokun_ids' => $product_ids,
        'context'   => $context,
    ]);

    return null;
}

/**
 * Attempt to locate meeting-point data by querying the activity endpoint directly.
 *
 * @param int    $product_id Product (activity) identifier.
 * @param string $context    Import context determining which API credentials to use.
 *
 * @return mixed Meeting-point data or null when unavailable.
 */
/**
 * Retrieve the full Bokun activity payload for a product.
 *
 * @param int    $product_id Bokun activity identifier.
 * @param string $context    Import context used to determine API credentials.
 *
 * @return array|null Activity payload or null when unavailable.
 */
function bokun_fetch_activity_payload($product_id, $context = 'default') {
    $product_id = (int) $product_id;

    if ($product_id <= 0) {
        return null;
    }

    $context   = bokun_normalize_import_context($context);
    $cache_key = sprintf('bokun_activity_payload_%s_%d', $context, $product_id);
    $cached    = get_transient($cache_key);

    if (false !== $cached) {
        return is_array($cached) ? $cached : null;
    }

    list($api_key, $secret_key) = bokun_get_api_credentials_for_context($context);

    if (empty($api_key) || empty($secret_key)) {
        return null;
    }

    $endpoint  = sprintf('/activity.json/%d', $product_id);
    $url       = BOKUN_API_BASE_URL . $endpoint;
    $date      = bokun_format_date();
    $method    = 'GET';
    $signature = bokun_generate_signature($date, $api_key, $method, $endpoint, $secret_key);

    $args = [
        'headers' => [
            'X-Bokun-AccessKey' => $api_key,
            'X-Bokun-Date'      => $date,
            'X-Bokun-Signature' => $signature,
            'Accept'            => 'application/json',
        ],
        'timeout' => apply_filters('bokun_activity_request_timeout', 30, $product_id, $context),
    ];

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        return null;
    }

    $code = wp_remote_retrieve_response_code($response);

    if (200 !== $code) {
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data)) {
        return null;
    }

    $activity = null;

    if (isset($data['activity']) && is_array($data['activity'])) {
        $activity = $data['activity'];
    } elseif (isset($data['item']) && is_array($data['item'])) {
        $activity = $data['item'];
    } elseif (isset($data['result']) && is_array($data['result'])) {
        $activity = $data['result'];
    } else {
        $activity = $data;
    }

    if (!is_array($activity)) {
        return null;
    }

    $ttl = apply_filters('bokun_activity_payload_cache_ttl', HOUR_IN_SECONDS, $product_id, $context, $activity);

    if ((int) $ttl > 0) {
        set_transient($cache_key, $activity, (int) $ttl);
    }

    return $activity;
}

function bokun_get_meeting_points_from_activity($product_id, $context = 'default') {
    $product_id = (int) $product_id;

    if ($product_id <= 0) {
        return null;
    }

    $context   = bokun_normalize_import_context($context);
    $cache_key = sprintf('bokun_activity_start_points_%s_%d', $context, $product_id);
    $cached    = get_transient($cache_key);

    if (false !== $cached) {
        bokun_log_meeting_point_step('Using cached activity meeting-point payload.', [
            'bokun_id' => $product_id,
            'context'  => $context,
            'type'     => is_array($cached) ? 'array' : (is_null($cached) ? 'NULL' : gettype($cached)),
            'count'    => is_array($cached) ? count($cached) : null,
        ]);

        return $cached;
    }

    bokun_log_meeting_point_step('Fetching activity for meeting-point lookup.', [
        'bokun_id' => $product_id,
        'context'  => $context,
    ]);

    $activity = bokun_fetch_activity_payload($product_id, $context);

    if (!is_array($activity)) {
        bokun_log_meeting_point_step('Unable to determine activity payload from response.', [
            'bokun_id' => $product_id,
            'context'  => $context,
        ]);

        return null;
    }

    if (!empty($activity['startPoints'])) {
        $start_points = $activity['startPoints'];

        bokun_log_meeting_point_step('Found meeting points on activity response.', [
            'bokun_id' => $product_id,
            'context'  => $context,
            'type'     => 'startPoints',
            'count'    => is_array($start_points) ? count($start_points) : null,
        ]);
    } elseif (!empty($activity['startPoint'])) {
        $start_points = $activity['startPoint'];

        bokun_log_meeting_point_step('Found single meeting point on activity response.', [
            'bokun_id' => $product_id,
            'context'  => $context,
            'type'     => 'startPoint',
        ]);
    } else {
        $start_points = [];

        bokun_log_meeting_point_step('No meeting points present on activity response.', [
            'bokun_id' => $product_id,
            'context'  => $context,
        ]);
    }

    $cache_ttl = apply_filters('bokun_activity_cache_ttl', HOUR_IN_SECONDS, $product_id, $context, $start_points);

    if ((int) $cache_ttl > 0) {
        set_transient($cache_key, $start_points, (int) $cache_ttl);
    }

    return $start_points;
}

/**
 * Retrieve the stored Bokun/Viator product identifier for a product-tag term.
 *
 * @param int $term_id Term identifier.
 *
 * @return int Normalized product identifier or 0 if unavailable.
 */
function bokun_get_product_tag_product_id($term_id) {
    $term_id = (int) $term_id;

    if ($term_id <= 0) {
        return 0;
    }

    $viator_product_id = get_term_meta($term_id, 'viatorProductID', true);

    if (is_array($viator_product_id)) {
        $viator_product_id = reset($viator_product_id);
    }

    $viator_product_id = is_string($viator_product_id) ? trim($viator_product_id) : $viator_product_id;

    if ('' !== $viator_product_id && null !== $viator_product_id) {
        $normalized = absint($viator_product_id);

        if ($normalized > 0) {
            return $normalized;
        }
    }

    $bokun_product_id = get_term_meta($term_id, 'bokun_product_id', true);

    if (is_array($bokun_product_id)) {
        $bokun_product_id = reset($bokun_product_id);
    }

    $bokun_product_id = is_string($bokun_product_id) ? trim($bokun_product_id) : $bokun_product_id;

    if ('' !== $bokun_product_id && null !== $bokun_product_id) {
        $normalized = absint($bokun_product_id);

        if ($normalized > 0) {
            return $normalized;
        }
    }

    return 0;
}

/**
 * Import Bokun activity images for all product tags.
 *
 * @param array $args Optional arguments.
 *
 * @return array Summary of the import operation.
 */
function bokun_import_images_for_all_product_tags($args = []) {
    $args = wp_parse_args(
        $args,
        [
            'context'  => 'default',
            'term_ids' => [],
        ]
    );

    $context       = bokun_normalize_import_context($args['context']);
    $requested_ids = array_filter(array_map('intval', (array) $args['term_ids']));

    $term_query = [
        'taxonomy'   => 'product_tags',
        'hide_empty' => false,
    ];

    if (!empty($requested_ids)) {
        $term_query['include'] = $requested_ids;
    }

    $terms = get_terms($term_query);

    if (is_wp_error($terms)) {
        return [
            'total_terms'     => 0,
            'processed_terms' => 0,
            'updated_terms'   => 0,
            'unchanged_terms' => 0,
            'skipped_terms'   => 0,
            'errors'          => 1,
            'messages'        => [$terms->get_error_message()],
            'context'         => $context,
            'query_error'     => true,
        ];
    }

    $summary = [
        'total_terms'     => 0,
        'processed_terms' => 0,
        'updated_terms'   => 0,
        'unchanged_terms' => 0,
        'skipped_terms'   => 0,
        'errors'          => 0,
        'messages'        => [],
        'context'         => $context,
        'query_error'     => false,
    ];

    foreach ($terms as $term) {
        $product_id = bokun_get_product_tag_product_id($term->term_id);

        if ($product_id <= 0) {
            $term_name = wp_strip_all_tags($term->name);
            $summary['skipped_terms']++;
            $summary['messages'][] = sprintf(
                /* translators: %s: Product tag name. */
                __('Skipped product tag “%s” because no Viator product ID is stored.', 'bokun-bookings-manager'),
                $term_name
            );
            continue;
        }

        $summary['total_terms']++;

        $result = bokun_import_product_tag_images_for_term($term, $context);

        $summary['processed_terms']++;

        if ('error' === $result['status']) {
            $summary['errors']++;
            if (!empty($result['message'])) {
                $summary['messages'][] = $result['message'];
            }
        } elseif ('updated' === $result['status']) {
            $summary['updated_terms']++;
            if (!empty($result['message'])) {
                $summary['messages'][] = $result['message'];
            }
        } elseif ('unchanged' === $result['status']) {
            $summary['unchanged_terms']++;
            if (!empty($result['message'])) {
                $summary['messages'][] = $result['message'];
            }
        } elseif ('skipped' === $result['status']) {
            $summary['skipped_terms']++;
            if (!empty($result['message'])) {
                $summary['messages'][] = $result['message'];
            }
        } else {
            $summary['messages'][] = $result['message'];
        }

        if (!empty($result['errors']) && is_array($result['errors'])) {
            foreach ($result['errors'] as $error_message) {
                $summary['messages'][] = $error_message;
            }
        }
    }

    return $summary;
}

/**
 * Import Bokun activity images for a single product tag term.
 *
 * @param WP_Term|int $term    Term object or ID.
 * @param string      $context Preferred import context.
 *
 * @return array Result payload containing status details.
 */
function bokun_import_product_tag_images_for_term($term, $context = 'default') {
    if (is_numeric($term)) {
        $term = get_term((int) $term, 'product_tags');
    }

    if (!$term || is_wp_error($term)) {
        return [
            'status'   => 'error',
            'message'  => __('Invalid product tag supplied for image import.', 'bokun-bookings-manager'),
            'errors'   => [__('Invalid product tag supplied for image import.', 'bokun-bookings-manager')],
            'term_id'  => is_object($term) ? $term->term_id : 0,
            'term_name'=> is_object($term) ? $term->name : '',
        ];
    }

    $term_name = wp_strip_all_tags($term->name);

    $product_id = bokun_get_product_tag_product_id($term->term_id);

    if ($product_id <= 0) {
        return [
            'status'    => 'skipped',
            'message'   => sprintf(
                /* translators: %s: Product tag name. */
                __('No Viator product ID stored for product tag “%s”.', 'bokun-bookings-manager'),
                $term_name
            ),
            'term_id'   => $term->term_id,
            'term_name' => $term_name,
            'errors'    => [],
        ];
    }

    $stored_context = get_term_meta($term->term_id, 'bokun_product_import_context', true);

    if (!empty($stored_context)) {
        $context = $stored_context;
    }

    $context  = bokun_normalize_import_context($context);
    $activity = bokun_fetch_activity_payload($product_id, $context);

    if (!is_array($activity)) {
        $fallback = ('upgrade' === $context) ? 'default' : 'upgrade';
        if ($fallback !== $context) {
            $fallback_activity = bokun_fetch_activity_payload($product_id, $fallback);
            if (is_array($fallback_activity)) {
                $activity = $fallback_activity;
                $context  = $fallback;
            }
        }
    }

    if (!is_array($activity)) {
        $message = sprintf(
            /* translators: %s: Product tag name. */
            __('Unable to fetch activity data for product tag “%s”.', 'bokun-bookings-manager'),
            $term_name
        );

        return [
            'status'    => 'error',
            'message'   => $message,
            'term_id'   => $term->term_id,
            'term_name' => $term_name,
            'errors'    => [$message],
        ];
    }

    $photos = bokun_extract_activity_photos($activity);

    if (empty($photos)) {
        update_term_meta($term->term_id, 'bokun_product_image_ids', []);
        update_term_meta($term->term_id, 'bokun_product_image_map', []);
        delete_term_meta($term->term_id, 'bokun_product_key_photo_attachment');
        delete_term_meta($term->term_id, 'bokun_product_key_photo_remote_id');
        update_term_meta($term->term_id, 'bokun_product_last_image_import', current_time('mysql'));

        return [
            'status'    => 'skipped',
            'message'   => sprintf(
                /* translators: %s: Product tag name. */
                __('No photos available for product tag “%s”.', 'bokun-bookings-manager'),
                $term_name
            ),
            'term_id'   => $term->term_id,
            'term_name' => $term_name,
            'errors'    => [],
        ];
    }

    $existing_map = get_term_meta($term->term_id, 'bokun_product_image_map', true);

    if (!is_array($existing_map)) {
        $existing_map = [];
    }

    $existing_map = array_filter(
        $existing_map,
        function ($attachment_id) {
            return $attachment_id && get_post((int) $attachment_id);
        }
    );

    $new_map       = [];
    $errors        = [];
    $downloaded    = 0;
    $refreshed     = 0;

    foreach ($photos as $photo) {
        $remote_id     = $photo['remote_id'];
        $attachment_id = isset($existing_map[$remote_id]) ? (int) $existing_map[$remote_id] : 0;

        if ($attachment_id > 0 && get_post($attachment_id)) {
            bokun_update_attachment_metadata($attachment_id, $photo, $context);
            $new_map[$remote_id] = $attachment_id;
            $refreshed++;
            continue;
        }

        $attachment_id = bokun_download_product_tag_photo($term, $photo, $context);

        if (is_wp_error($attachment_id)) {
            $errors[] = sanitize_text_field($attachment_id->get_error_message());
            continue;
        }

        if ($attachment_id > 0) {
            $new_map[$remote_id] = $attachment_id;
            $downloaded++;
        }
    }

    $removed = [];

    foreach ($existing_map as $remote_id => $attachment_id) {
        if (!isset($new_map[$remote_id])) {
            $removed[] = (int) $attachment_id;
        }
    }

    if (!empty($removed)) {
        bokun_ensure_media_dependencies_loaded();

        foreach ($removed as $attachment_id) {
            if ($attachment_id > 0 && get_post($attachment_id)) {
                wp_delete_attachment($attachment_id, true);
            }
        }
    }

    $attachment_ids = array_map('intval', array_values($new_map));

    update_term_meta($term->term_id, 'bokun_product_image_map', $new_map);
    update_term_meta($term->term_id, 'bokun_product_image_ids', $attachment_ids);
    update_term_meta($term->term_id, 'bokun_product_last_image_import', current_time('mysql'));
    update_term_meta($term->term_id, 'bokun_product_image_import_context', $context);

    $key_remote_id = null;
    foreach ($photos as $photo) {
        if (!empty($photo['is_key_photo'])) {
            $key_remote_id = $photo['remote_id'];
            break;
        }
    }

    if ($key_remote_id && isset($new_map[$key_remote_id])) {
        update_term_meta($term->term_id, 'bokun_product_key_photo_attachment', (int) $new_map[$key_remote_id]);
        update_term_meta($term->term_id, 'bokun_product_key_photo_remote_id', $key_remote_id);
    } else {
        delete_term_meta($term->term_id, 'bokun_product_key_photo_attachment');
        delete_term_meta($term->term_id, 'bokun_product_key_photo_remote_id');
    }

    if (!empty($errors)) {
        return [
            'status'    => 'error',
            'message'   => sprintf(
                /* translators: %s: Product tag name. */
                __('Encountered errors while importing images for product tag “%s”.', 'bokun-bookings-manager'),
                $term_name
            ),
            'term_id'   => $term->term_id,
            'term_name' => $term_name,
            'downloaded'=> $downloaded,
            'refreshed' => $refreshed,
            'removed'   => count($removed),
            'errors'    => array_map('sanitize_text_field', $errors),
        ];
    }

    $status = ($downloaded > 0 || !empty($removed)) ? 'updated' : 'unchanged';

    return [
        'status'     => $status,
        'message'    => sprintf(
            /* translators: 1: Product tag name, 2: number of downloaded images. */
            __('Imported %2$d image(s) for product tag “%1$s”.', 'bokun-bookings-manager'),
            $term_name,
            $downloaded
        ),
        'term_id'    => $term->term_id,
        'term_name'  => $term_name,
        'downloaded' => $downloaded,
        'refreshed'  => $refreshed,
        'removed'    => count($removed),
        'errors'     => [],
    ];
}

/**
 * Normalize photo entries from a Bokun activity payload.
 *
 * @param array $activity Activity payload.
 *
 * @return array Normalized photo information.
 */
function bokun_extract_activity_photos($activity) {
    if (!is_array($activity)) {
        return [];
    }

    $photo_entries = [];

    if (!empty($activity['keyPhoto']) && is_array($activity['keyPhoto'])) {
        $key_photo               = $activity['keyPhoto'];
        $key_photo['is_key_photo'] = true;
        $photo_entries[]         = $key_photo;
    }

    if (!empty($activity['photos']) && is_array($activity['photos'])) {
        foreach ($activity['photos'] as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $photo_entries[] = $photo;
        }
    }

    $normalized = [];

    foreach ($photo_entries as $photo) {
        $remote_id = '';

        if (isset($photo['id']) && '' !== $photo['id']) {
            $remote_id = (string) $photo['id'];
        } elseif (!empty($photo['fileName'])) {
            $remote_id = wp_basename($photo['fileName']);
        } elseif (!empty($photo['originalUrl'])) {
            $remote_id = md5($photo['originalUrl']);
        }

        $original_url = isset($photo['originalUrl']) ? esc_url_raw($photo['originalUrl']) : '';

        if ('' === $remote_id || '' === $original_url) {
            continue;
        }

        if (!isset($normalized[$remote_id])) {
            $normalized[$remote_id] = [
                'remote_id'   => $remote_id,
                'original_url'=> $original_url,
                'description' => isset($photo['description']) ? wp_strip_all_tags((string) $photo['description']) : '',
                'alt_text'    => isset($photo['alternateText']) ? wp_strip_all_tags((string) $photo['alternateText']) : '',
                'file_name'   => isset($photo['fileName']) ? wp_basename((string) $photo['fileName']) : '',
                'is_key_photo'=> !empty($photo['is_key_photo']),
            ];
        } elseif (!empty($photo['is_key_photo'])) {
            $normalized[$remote_id]['is_key_photo'] = true;
        }
    }

    return array_values($normalized);
}

/**
 * Ensure media helper functions are available.
 */
function bokun_ensure_media_dependencies_loaded() {
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    if (!function_exists('wp_delete_attachment')) {
        require_once ABSPATH . 'wp-admin/includes/post.php';
    }
}

/**
 * Download a Bokun activity photo and create a WordPress attachment.
 *
 * @param WP_Term $term   Product tag term.
 * @param array   $photo  Normalized photo data.
 * @param string  $context Import context.
 *
 * @return int|WP_Error Attachment ID on success or WP_Error on failure.
 */
function bokun_download_product_tag_photo($term, $photo, $context = 'default') {
    bokun_ensure_media_dependencies_loaded();

    $term_name  = is_object($term) ? wp_strip_all_tags($term->name) : '';
    $description = sprintf(
        /* translators: %s: Product tag name. */
        __('Bokun image for product tag “%s”.', 'bokun-bookings-manager'),
        $term_name
    );

    $attachment_id = media_sideload_image($photo['original_url'], 0, $description, 'id');

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    bokun_update_attachment_metadata($attachment_id, $photo, $context);

    return (int) $attachment_id;
}

/**
 * Update attachment metadata with Bokun details.
 *
 * @param int    $attachment_id Attachment ID.
 * @param array  $photo         Normalized photo data.
 * @param string $context       Import context.
 */
function bokun_update_attachment_metadata($attachment_id, $photo, $context = 'default') {
    $attachment_id = (int) $attachment_id;

    if ($attachment_id <= 0) {
        return;
    }

    $context = bokun_normalize_import_context($context);

    if (!empty($photo['alt_text'])) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($photo['alt_text']));
    }

    $update = [
        'ID'           => $attachment_id,
        'post_excerpt' => sanitize_text_field($photo['description']),
    ];

    wp_update_post($update);

    update_post_meta($attachment_id, '_bokun_remote_id', sanitize_text_field($photo['remote_id']));
    update_post_meta($attachment_id, '_bokun_remote_url', esc_url_raw($photo['original_url']));
    update_post_meta($attachment_id, '_bokun_remote_context', $context);
}

/**
 * Determine the product ID represented in the booking payload.
 *
 * @param array $booking Booking payload.
 *
 * @return int Product identifier or 0 when unavailable.
 */
function bokun_get_booking_product_id($booking) {
    $product_ids = bokun_get_booking_product_ids($booking);

    if (empty($product_ids)) {
        return 0;
    }

    return (int) $product_ids[0];
}

/**
 * Determine the product IDs represented in the booking payload.
 *
 * @param array $booking Booking payload.
 *
 * @return int[] Product identifiers.
 */
function bokun_get_booking_product_ids($booking) {
    if (!is_array($booking)) {
        return [];
    }

    $identifiers = [];

    $product_bookings = $booking['productBookings'] ?? [];

    if (is_array($product_bookings)) {
        foreach ($product_bookings as $product_booking) {
            if (!is_array($product_booking)) {
                continue;
            }

            if (isset($product_booking['product']) && is_array($product_booking['product'])) {
                $identifiers = array_merge(
                    $identifiers,
                    bokun_collect_numeric_identifiers($product_booking['product'], ['id', 'productId', 'activityId', 'experienceId'])
                );
            }

            $identifiers = array_merge(
                $identifiers,
                bokun_collect_numeric_identifiers($product_booking, ['productId', 'activityId', 'experienceId'])
            );
        }
    }

    if (isset($booking['product']) && is_array($booking['product'])) {
        $identifiers = array_merge(
            $identifiers,
            bokun_collect_numeric_identifiers($booking['product'], ['id', 'productId', 'activityId', 'experienceId'])
        );
    }

    $identifiers = array_merge(
        $identifiers,
        bokun_collect_numeric_identifiers($booking, ['productId', 'activityId', 'experienceId'])
    );

    return bokun_normalize_identifier_list($identifiers);
}

/**
 * Collect numeric identifier values from the provided payload.
 *
 * @param array $payload Source payload.
 * @param array $keys    Keys that may contain identifier values.
 *
 * @return int[] Ordered list of identifiers found in the payload.
 */
function bokun_collect_numeric_identifiers($payload, $keys) {
    if (!is_array($payload) || empty($keys)) {
        return [];
    }

    $identifiers = [];
    $queue = [$payload];

    while (!empty($queue)) {
        $current = array_shift($queue);

        if (!is_array($current)) {
            continue;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $current)) {
                continue;
            }

            $value = $current[$key];

            if (is_array($value) || is_object($value)) {
                continue;
            }

            if (!is_scalar($value) && !is_null($value)) {
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $identifier = (int) $value;

            if ($identifier > 0 && !in_array($identifier, $identifiers, true)) {
                $identifiers[] = $identifier;
            }
        }

        foreach ($current as $value) {
            if (is_array($value)) {
                $queue[] = $value;
            }
        }
    }

    return $identifiers;
}

/**
 * Normalise a list of identifiers by removing invalid and duplicate entries.
 *
 * @param array $identifiers Raw identifiers.
 *
 * @return int[] Sanitized identifiers.
 */
function bokun_normalize_identifier_list($identifiers) {
    $normalized = [];

    foreach ((array) $identifiers as $identifier) {
        if (is_string($identifier) && !is_numeric($identifier)) {
            continue;
        }

        $identifier = (int) $identifier;

        if ($identifier <= 0) {
            continue;
        }

        if (!in_array($identifier, $normalized, true)) {
            $normalized[] = $identifier;
        }
    }

    return $normalized;
}

/**
 * Retrieve configured product list identifiers for meeting-point lookups.
 *
 * @param string $context Import context determining which API credentials to use.
 *
 * @return int[] Array of list identifiers.
 */
function bokun_get_product_list_ids($context = 'default') {
    $context = bokun_normalize_import_context($context);

    $option_key = 'upgrade' === $context ? 'bokun_product_list_ids_upgrade' : 'bokun_product_list_ids';
    $stored_value = get_option($option_key, '');

    if (is_string($stored_value)) {
        $list_ids = array_filter(array_map('trim', explode(',', $stored_value)));
    } elseif (is_array($stored_value)) {
        $list_ids = $stored_value;
    } else {
        $list_ids = [];
    }

    $list_ids = array_map('intval', (array) $list_ids);
    $list_ids = array_filter($list_ids, function ($value) {
        return $value > 0;
    });

    $list_ids = apply_filters('bokun_meeting_point_product_lists', array_values($list_ids), $context);

    $list_ids = array_map('intval', (array) $list_ids);
    $list_ids = array_values(array_unique(array_filter($list_ids, function ($value) {
        return $value > 0;
    })));

    return $list_ids;
}

/**
 * Fetch activities contained in a product list from the Bókun API.
 *
 * @param int    $list_id Product list identifier.
 * @param string $context Import context determining which API credentials to use.
 *
 * @return array Normalised array of activities in the product list.
 */
function bokun_fetch_product_list_activities($list_id, $context = 'default') {
    $list_id = (int) $list_id;

    if ($list_id <= 0) {
        return [];
    }

    $context = bokun_normalize_import_context($context);
    $cache_key = sprintf('bokun_product_list_%s_%d', $context, $list_id);
    $cached = get_transient($cache_key);

    if (false !== $cached) {
        bokun_log_meeting_point_step('Using cached product-list activities.', [
            'list_id'      => $list_id,
            'context'      => $context,
            'cached_count' => is_array($cached) ? count($cached) : null,
        ]);
        return is_array($cached) ? $cached : [];
    }

    list($api_key, $secret_key) = bokun_get_api_credentials_for_context($context);

    bokun_log_meeting_point_step('Fetching product-list activities from API.', [
        'list_id'     => $list_id,
        'context'     => $context,
        'has_api_key' => !empty($api_key),
        'has_secret'  => !empty($secret_key),
    ]);

    if (empty($api_key) || empty($secret_key)) {
        bokun_log_meeting_point_step('Missing API credentials for product-list lookup.', [
            'list_id' => $list_id,
            'context' => $context,
        ]);
        return [];
    }

    $endpoint = sprintf('/product-list.json/%d', $list_id);
    $url      = BOKUN_API_BASE_URL . $endpoint;
    $date     = bokun_format_date();
    $method   = 'GET';
    $signature = bokun_generate_signature($date, $api_key, $method, $endpoint, $secret_key);

    $args = [
        'headers' => [
            'X-Bokun-AccessKey' => $api_key,
            'X-Bokun-Date'      => $date,
            'X-Bokun-Signature' => $signature,
            'Accept'            => 'application/json',
        ],
        'timeout' => apply_filters('bokun_product_list_request_timeout', 30, $list_id, $context),
    ];

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        bokun_log_meeting_point_step('Product-list request failed.', [
            'list_id' => $list_id,
            'context' => $context,
            'error'   => $response->get_error_message(),
        ]);
        return [];
    }

    $code = wp_remote_retrieve_response_code($response);

    if (200 !== $code) {
        bokun_log_meeting_point_step('Unexpected status code from product-list request.', [
            'list_id' => $list_id,
            'context' => $context,
            'status'  => $code,
        ]);
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data)) {
        bokun_log_meeting_point_step('Invalid product-list response payload.', [
            'list_id' => $list_id,
            'context' => $context,
        ]);
        return [];
    }

    $activities = bokun_normalize_product_list_payload($data);

    bokun_log_meeting_point_step('Product-list response normalised.', [
        'list_id'        => $list_id,
        'context'        => $context,
        'activity_count' => count($activities),
    ]);

    $cache_ttl = apply_filters('bokun_product_list_cache_ttl', HOUR_IN_SECONDS, $list_id, $context, $activities);

    if ((int) $cache_ttl > 0) {
        set_transient($cache_key, $activities, (int) $cache_ttl);
    }

    return $activities;
}

/**
 * Normalise the product list payload into an array of activities.
 *
 * @param array $payload Raw product list payload.
 *
 * @return array Normalised activities.
 */
function bokun_normalize_product_list_payload($payload) {
    $activities = [];

    if (isset($payload['items']) && is_array($payload['items'])) {
        foreach ($payload['items'] as $item) {
            if (isset($item['activity']) && is_array($item['activity'])) {
                $activities[] = $item['activity'];
                continue;
            }

            if (isset($item['product']) && is_array($item['product'])) {
                $activities[] = $item['product'];
                continue;
            }

            if (is_array($item)) {
                $activities[] = $item;
            }
        }
    }

    if (isset($payload['activities']) && is_array($payload['activities'])) {
        foreach ($payload['activities'] as $activity) {
            if (is_array($activity)) {
                $activities[] = $activity;
            }
        }
    }

    if (isset($payload['activity']) && is_array($payload['activity'])) {
        $activities[] = $payload['activity'];
    }

    if (isset($payload['products']) && is_array($payload['products'])) {
        foreach ($payload['products'] as $product) {
            if (is_array($product)) {
                $activities[] = $product;
            }
        }
    }

    $activities = apply_filters('bokun_product_list_activities', $activities, $payload);

    return array_values($activities);
}

/**
 * Retrieve API credentials for the given import context.
 *
 * @param string $context Import context determining which API credentials to use.
 *
 * @return array Array containing the access key and secret key.
 */
function bokun_get_api_credentials_for_context($context = 'default') {
    $context = bokun_normalize_import_context($context);

    if ('upgrade' === $context) {
        $api_key    = get_option('bokun_api_key_upgrade', '');
        $secret_key = get_option('bokun_secret_key_upgrade', '');
    } else {
        $api_key    = get_option('bokun_api_key', '');
        $secret_key = get_option('bokun_secret_key', '');
    }

    return [$api_key, $secret_key];
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

/**
 * Retrieve booking status data used by booking related shortcodes.
 *
 * @return array{
 *     booking_id:string,
 *     checked:array<string,string>
 * }
 */
function bokun_get_booking_checkbox_data() {
    global $post;

    $booking_id = get_post_meta($post->ID, '_confirmation_code', true);

    return array(
        'booking_id' => $booking_id,
        'checked'    => array(
            'full'              => has_term('full', 'booking_status', $post->ID) ? 'checked' : '',
            'partial'           => has_term('partial', 'booking_status', $post->ID) ? 'checked' : '',
            'refund-partner'    => has_term('refund-requested-from-partner', 'booking_status', $post->ID) ? 'checked' : '',
            'not-available'     => has_term('not-available', 'booking_status', $post->ID) ? 'checked' : '',
        ),
    );
}

// Display booking status checkboxes next to each booking
function booking_checkbox_shortcode($atts) {
    $data = bokun_get_booking_checkbox_data();
    $booking_id = $data['booking_id'];
    $checked    = $data['checked'];

    ob_start();
    ?>
    <div class="elementor-widget-container">
        <label>
            <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="full" <?php echo $checked['full']; ?>>
            Full
        </label>
        <label>
            <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="partial" <?php echo $checked['partial']; ?>>
            Partial
        </label>
        <label>
            <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="not-available" <?php echo $checked['not-available']; ?>>
            Not Available
        </label>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('booking_checkbox', 'booking_checkbox_shortcode');

// Display only the refund requested from partner checkbox
function refund_checkbox_shortcode($atts) {
    $data = bokun_get_booking_checkbox_data();
    $booking_id = $data['booking_id'];
    $checked    = $data['checked'];

    ob_start();
    ?>
    <div class="elementor-widget-container">
        <label>
            <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="refund-partner" <?php echo $checked['refund-partner']; ?>>
            Refund Requested from Partner
        </label>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('refund_checkbox', 'refund_checkbox_shortcode');

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
    <div class="form-field">
        <label for="term_meta[viatorProductID]"><?php _e('Viator Product ID', 'bokun-bookings-manager'); ?></label>
        <input type="text" name="term_meta[viatorProductID]" id="term_meta[viatorProductID]" value="">
        <p class="description"><?php _e('Store the associated Bokun/Viator product ID for this tag.', 'bokun-bookings-manager'); ?></p>
    </div>
    <?php
}

// Add custom fields to the 'Edit' term page
add_action('product_tags_edit_form_fields', 'edit_product_tag_custom_fields', 10, 2);
function edit_product_tag_custom_fields($term, $taxonomy) {
    $statusok = get_term_meta($term->term_id, 'statusok', true);
    $statusattention = get_term_meta($term->term_id, 'statusattention', true);
    $statusalarm = get_term_meta($term->term_id, 'statusalarm', true);
    $viator_product_id = get_term_meta($term->term_id, 'viatorProductID', true);
    $image_ids = get_term_meta($term->term_id, 'bokun_product_image_ids', true);

    if (!is_array($image_ids)) {
        $image_ids = [];
    }

    $image_ids = array_filter(array_map('intval', $image_ids));
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
    <tr class="form-field">
        <th scope="row" valign="top"><label for="term_meta[viatorProductID]"><?php _e('Viator Product ID', 'bokun-bookings-manager'); ?></label></th>
        <td>
            <input type="text" name="term_meta[viatorProductID]" id="term_meta[viatorProductID]" value="<?php echo esc_attr($viator_product_id) ? esc_attr($viator_product_id) : ''; ?>">
            <p class="description"><?php _e('Store the associated Bokun/Viator product ID for this tag.', 'bokun-bookings-manager'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row" valign="top"><?php _e('Imported images', 'bokun-bookings-manager'); ?></th>
        <td>
            <?php if (!empty($image_ids)) : ?>
                <ul class="bokun-product-tag-images" style="display:flex;flex-wrap:wrap;gap:8px;list-style:none;margin:0;padding:0;">
                    <?php foreach ($image_ids as $attachment_id) : ?>
                        <li>
                            <?php echo wp_get_attachment_image($attachment_id, [80, 80], false, ['style' => 'max-width:80px;height:auto;']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="description"><?php _e('No images have been imported for this product tag yet.', 'bokun-bookings-manager'); ?></p>
            <?php endif; ?>
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
            if ('viatorProductID' === $key || 'bokun_product_id' === $key) {
                $value = absint($value);
            } else {
                $value = sanitize_text_field($value);
            }

            update_term_meta($term_id, $key, $value);
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