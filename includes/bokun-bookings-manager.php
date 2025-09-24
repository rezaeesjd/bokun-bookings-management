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

    $payload = json_encode([
        'page' => 1,
        'itemsPerPage' => 100, // Adjust as needed
        'startDateRange' => [
            'from' => $today->format('Y-m-d\T00:00:00\Z'),
            'includeLower' => true,
            'includeUpper' => true,
            'to' => $oneMonthLater->format('Y-m-d\TH:i:s\Z')
        ]
    ]);

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

    // Request options
    $args = [
        'method' => 'POST',
        'headers' => $headers,
        'body' => $payload,
        'timeout' => 20,
    ];

    // Send the request
    $response = wp_remote_post($url, $args);
    
    if (is_wp_error($response)) {
        error_log('Error fetching bookings: ' . $response->get_error_message());
        return 'Error: ' . $response->get_error_message();
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($response_code === 200) {
        $data = json_decode($body, true);
        // if ($upgrade=='') {        
        //     echo $api_key = get_option('bokun_api_key_upgrade', '');
        //     echo '<br/>';
        //     echo $secret_key = get_option('bokun_secret_key_upgrade', '');
        //     echo '<br/>';
        //     echo '<pre>';
        //     print_r($body);
        //     echo '</pre>';
        //     die;
        // }
        if (isset($data['items']) && !empty($data['items'])) {
            return $data['items'];
        } else {
            return 'No bookings available to process.';
        }
    } else {
        error_log('Unexpected response code: ' . $response_code . ' with body: ' . $body);
        return 'Error: Received unexpected response code ' . $response_code . '. Response: ' . $body;
    }
}

// Save Bokun bookings as WordPress posts
function bokun_save_bookings_as_posts($bookings) {
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
        // Remaining code for processing individual bookings
        if (empty($booking['confirmationCode'])) {
            continue;
        }

        $confirmationCode = $booking['confirmationCode'];
        $post_title = $confirmationCode;

        // Fetch or calculate the startDateTime for the post_date
        $startDateTime = !empty($booking['productBookings'][0]['startDateTime'])
                            ? $booking['productBookings'][0]['startDateTime']
                            : (!empty($booking['productBookings'][0]['startDate'])
                                ? $booking['productBookings'][0]['startDate']
                                : '');

        if (empty($startDateTime)) {
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
                wp_update_post(array_merge(['ID' => $post_id], $post_data));
            }
        } else {
            $post_id = wp_insert_post($post_data);
            if (is_wp_error($post_id)) {
                error_log('Error inserting post for confirmationCode: ' . $confirmationCode . '. Error: ' . $post_id->get_error_message());
                continue;
            }
        }

        // Save fields for both new and updated posts
        bokun_save_specific_fields($post_id, $booking);
        bokun_save_all_fields_as_meta($post_id, $booking);
        process_price_categories_and_save($post_id, $booking);
        bokun_calculate_booking_status($post_id, $booking['productBookings'][0]['product']['title'] ?? '', $startDateTime);

        // Extract, process, and save the inclusions as clean text
// Extract inclusions text from productBookings_0_notes_0_body
$inclusions_text = $booking['productBookings'][0]['notes'][0]['body'] ?? '';

// Process the inclusions to remove content up to the third occurrence of '---'
$inclusions_clean = bokun_get_inclusions_clean($inclusions_text);

if (!empty($inclusions_clean)) {
    update_post_meta($post_id, 'inclusions_clean', $inclusions_clean);
    error_log('Processed Inclusions Clean: ' . $inclusions_clean);
} else {
    error_log('Inclusions Clean is empty.');
}

    }
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
            error_log("Error inserting term '$term_name' into taxonomy '$taxonomy': " . $term->get_error_message());
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
        error_log('Invalid timezone: ' . $timezone_string . ' - Falling back to UTC.');
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
            error_log("Error inserting term '$alarm_status' into taxonomy '$taxonomy': " . $term->get_error_message());
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
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON decode error on $meta_key: " . json_last_error_msg());
                } else {
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

// Handle AJAX request to update booking status and track click logs
function update_booking_status() {
    check_ajax_referer('update_booking_nonce', 'security');

    $booking_id = sanitize_text_field($_POST['booking_id']);
    $checked = filter_var($_POST['checked'], FILTER_VALIDATE_BOOLEAN);
    $type = sanitize_text_field($_POST['type']); // "full", "partial", or "not-available"

    if (empty($booking_id)) {
        wp_send_json_error(['message' => 'Invalid booking ID provided.']);
        wp_die();
    }

    $args = [
        'post_type' => 'bokun_booking',
        'meta_query' => [
            [
                'key' => '_confirmation_code',
                'value' => $booking_id,
                'compare' => '='
            ]
        ]
    ];

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $taxonomy = 'booking_status';

            if ($type === 'not-available') {
                if ($checked) {
                    bokun_assign_tag_to_post($post_id, 'Not Available', $taxonomy);
                } else {
                    bokun_remove_tag_from_post($post_id, 'Not Available', $taxonomy);
                }
            } else {
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
            }

            wp_send_json_success(['message' => 'Booking status updated']);
        }
    } else {
        wp_send_json_error(['message' => 'Booking ID not found.']);
    }

    wp_die();
}

add_action('wp_ajax_update_booking_status', 'update_booking_status');
add_action('wp_ajax_nopriv_update_booking_status', 'update_booking_status');

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

// Display checkboxes for "Full" and "Partial" next to each booking
function booking_checkbox_shortcode($atts) {
    global $post;
    $booking_id = get_post_meta($post->ID, '_confirmation_code', true);

    // Check if 'full' or 'partial' term is assigned to the post
    $full_checked = has_term('full', 'booking_status', $post->ID) ? 'checked' : '';
    $partial_checked = has_term('partial', 'booking_status', $post->ID) ? 'checked' : '';

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
    <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_id); ?>" data-type="not-available" <?php echo has_term('not-available', 'booking_status', $post->ID) ? 'checked' : ''; ?>>
    Not Available
</label>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('booking_checkbox', 'booking_checkbox_shortcode');

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
        bokun_save_bookings_as_posts($bookings);
        error_log('Bookings imported successfully.');
        return new WP_REST_Response('Bookings imported successfully.', 200);
    } else {
        error_log('Error fetching bookings: ' . $bookings);
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
    error_log('Inclusions Parts: ' . print_r($parts, true)); // Log parts for debugging
    
    // Ensure we have at least 4 parts (3 separators before inclusions)
    if (count($parts) >= 4) {
        // Rejoin parts from the fourth element onward
        return trim(implode('---', array_slice($parts, 3)));
    }

    // Return the original text if not enough '---' parts exist
    return $text;
}