<?php
if( !class_exists ( 'BOKUN_Settings' ) ) {

    class BOKUN_Settings {

        function __construct(){

            add_action('wp_ajax_bokun_save_api_auth',array( $this, "bokun_save_api_auth" ), 10 , 2 );
            add_action('wp_ajax_no_priv_bokun_save_api_auth',array( $this, "bokun_save_api_auth" ), 10 , 2 );

            add_action('wp_ajax_bokun_save_api_auth_upgrade',array( $this, "bokun_save_api_auth_upgrade" ), 10 , 2 );
            add_action('wp_ajax_no_priv_bokun_save_api_auth_upgrade',array( $this, "bokun_save_api_auth_upgrade" ), 10 , 2 );

            add_action('wp_ajax_bokun_save_api_credentials', array($this, 'bokun_save_api_credentials'));

            add_action('wp_ajax_bokun_bookings_manager_page',array( $this, "bokun_bookings_manager_page" ), 10  );
            add_action('wp_ajax_nopriv_bokun_bookings_manager_page',array( $this, "bokun_bookings_manager_page" ), 10  );

            add_action('wp_ajax_bokun_get_import_progress', array($this, 'bokun_get_import_progress'), 10);
            add_action('wp_ajax_nopriv_bokun_get_import_progress', array($this, 'bokun_get_import_progress'), 10);

            add_action('wp_ajax_bokun_save_dashboard_settings', array($this, 'bokun_save_dashboard_settings'));

            add_action('wp_ajax_bokun_import_product_tag_images', array($this, 'bokun_import_product_tag_images'));

        } 

        
        function bokun_bookings_manager_page() {
            
            // Check the nonce first
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => 'Nonce verification failed.'));
                wp_die();
            }

            $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';

            $configured_credentials = bokun_get_configured_api_credentials();

            if (empty($configured_credentials)) {
                wp_send_json_error(array('msg' => __('Please configure at least one set of API credentials before running the import.', 'BOKUN_txt_domain')));
                wp_die();
            }

            $available_contexts = array_keys($configured_credentials);

            if (empty($mode)) {
                $mode = isset($available_contexts[0]) ? $available_contexts[0] : 'default';
            }

            if (!isset($configured_credentials[$mode])) {
                if (in_array($mode, array('fetch', 'default'), true) && isset($available_contexts[0])) {
                    $mode = $available_contexts[0];
                } elseif ('upgrade' === $mode && isset($available_contexts[1])) {
                    $mode = $available_contexts[1];
                }
            }

            if (!isset($configured_credentials[$mode]) && isset($available_contexts[0])) {
                $mode = $available_contexts[0];
            }

            $progress_context = bokun_normalize_import_context($mode);

            $bookings = bokun_fetch_bookings($progress_context);
            // echo 'out';
            // echo '<pre>';
            // print_r($bookings);
            // die;
            if (is_string($bookings)) {
                $normalized_message = trim($bookings);
                $is_error_message   = stripos($normalized_message, 'error') === 0;

                if ($is_error_message) {
                    $progress_message = bokun_get_import_progress_message($progress_context, 'error');
                } else {
                    $progress_label    = bokun_get_import_progress_label($progress_context);
                    /* translators: %s: API label. */
                    $progress_message  = sprintf(__('%s — no bookings found; continuing with remaining imports.', 'bokun-bookings-manager'), $progress_label);
                }

                bokun_set_import_progress_state($progress_context, array(
                    'status'    => $is_error_message ? 'error' : 'completed',
                    'total'     => 0,
                    'processed' => 0,
                    'created'   => 0,
                    'updated'   => 0,
                    'skipped'   => 0,
                    'message'   => $progress_message,
                ));

                wp_send_json_success(
                    array(
                        'msg'               => esc_html($bookings),
                        'status'            => false,
                        'progress_message'  => esc_html($progress_message),
                    )
                );
            } else {
                $import_summary = bokun_save_bookings_as_posts($bookings, $progress_context);

                if (!is_array($import_summary)) {
                    $import_summary = array();
                }

                $normalized_summary = array(
                    'total'     => isset($import_summary['total']) ? intval($import_summary['total']) : 0,
                    'processed' => isset($import_summary['processed']) ? intval($import_summary['processed']) : 0,
                    'created'   => isset($import_summary['created']) ? intval($import_summary['created']) : 0,
                    'updated'   => isset($import_summary['updated']) ? intval($import_summary['updated']) : 0,
                    'skipped'   => isset($import_summary['skipped']) ? intval($import_summary['skipped']) : 0,
                );

                wp_send_json_success(
                    array(
                        'msg'             => 'Bookings have been successfully imported as custom posts.',
                        'status'          => true,
                        'import_summary'  => $normalized_summary,
                    )
                );
            }

            wp_die(); // Always end AJAX functions with wp_die()
        }

        function bokun_get_import_progress() {
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => 'Nonce verification failed.'));
                wp_die();
            }

            $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
            $progress = bokun_get_import_progress_state($mode);

            if (!is_array($progress)) {
                $progress = array();
            }

            wp_send_json_success($progress);
            wp_die();
        }
       
        function bokun_save_api_auth() {
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => 'Invalid nonce.'));
                wp_die();
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('msg' => __('You are not allowed to update these settings.', 'BOKUN_txt_domain')));
                wp_die();
            }

            $api_key    = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
            $secret_key = isset($_POST['secret_key']) ? sanitize_text_field(wp_unslash($_POST['secret_key'])) : '';

            $credentials = get_option('bokun_api_credentials', []);
            if (!is_array($credentials)) {
                $credentials = [];
            }

            $credentials[0] = array(
                'api_key'    => $api_key,
                'secret_key' => $secret_key,
            );

            $credentials = $this->filter_valid_api_credentials($credentials);
            update_option('bokun_api_credentials', $credentials);

            delete_option('bokun_api_key');
            delete_option('bokun_secret_key');

            wp_send_json_success(array('msg' => __('API keys saved successfully.', 'BOKUN_txt_domain'), 'status' => true));
            wp_die();
        }

        function bokun_save_api_auth_upgrade() {
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => 'Invalid nonce.'));
                wp_die();
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('msg' => __('You are not allowed to update these settings.', 'BOKUN_txt_domain')));
                wp_die();
            }

            $api_key    = isset($_POST['api_key_upgrade']) ? sanitize_text_field(wp_unslash($_POST['api_key_upgrade'])) : '';
            $secret_key = isset($_POST['secret_key_upgrade']) ? sanitize_text_field(wp_unslash($_POST['secret_key_upgrade'])) : '';

            $credentials = get_option('bokun_api_credentials', []);
            if (!is_array($credentials)) {
                $credentials = [];
            }

            $credentials[1] = array(
                'api_key'    => $api_key,
                'secret_key' => $secret_key,
            );

            $credentials = $this->filter_valid_api_credentials($credentials);
            update_option('bokun_api_credentials', $credentials);

            delete_option('bokun_api_key_upgrade');
            delete_option('bokun_secret_key_upgrade');

            wp_send_json_success(array('msg' => __('API keys saved successfully.', 'BOKUN_txt_domain'), 'status' => true));
            wp_die();
        }

        protected function filter_valid_api_credentials($credentials) {
            if (!is_array($credentials)) {
                return [];
            }

            $filtered = [];

            foreach ($credentials as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $api_key    = isset($entry['api_key']) ? trim((string) $entry['api_key']) : '';
                $secret_key = isset($entry['secret_key']) ? trim((string) $entry['secret_key']) : '';

                if ('' === $api_key || '' === $secret_key) {
                    continue;
                }

                $filtered[] = array(
                    'api_key'    => $api_key,
                    'secret_key' => $secret_key,
                );
            }

            return array_values($filtered);
        }

        function bokun_save_api_credentials() {
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => __('Invalid nonce.', 'BOKUN_txt_domain')));
                wp_die();
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('msg' => __('You are not allowed to update these settings.', 'BOKUN_txt_domain')));
                wp_die();
            }

            $raw_credentials = isset($_POST['api_credentials']) ? wp_unslash($_POST['api_credentials']) : [];
            $credentials = [];

            if (is_array($raw_credentials)) {
                foreach ($raw_credentials as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $api_key    = isset($entry['api_key']) ? sanitize_text_field($entry['api_key']) : '';
                    $secret_key = isset($entry['secret_key']) ? sanitize_text_field($entry['secret_key']) : '';

                    $api_key    = trim($api_key);
                    $secret_key = trim($secret_key);

                    if ('' === $api_key || '' === $secret_key) {
                        continue;
                    }

                    $credentials[] = array(
                        'api_key'    => $api_key,
                        'secret_key' => $secret_key,
                    );
                }
            }

            update_option('bokun_api_credentials', $credentials);

            delete_option('bokun_api_key');
            delete_option('bokun_secret_key');
            delete_option('bokun_api_key_upgrade');
            delete_option('bokun_secret_key_upgrade');

            wp_send_json_success(array('msg' => __('API keys saved successfully.', 'BOKUN_txt_domain')));
            wp_die();
        }

        function bokun_save_dashboard_settings() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('msg' => __('You are not allowed to update these settings.', 'BOKUN_txt_domain')));
                wp_die();
            }

            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => __('Invalid nonce.', 'BOKUN_txt_domain')));
                wp_die();
            }

            $page_id = isset($_POST['dashboard_page_id']) ? absint($_POST['dashboard_page_id']) : 0;

            if ($page_id > 0) {
                $page_post = get_post($page_id);
                if (!$page_post || 'page' !== $page_post->post_type) {
                    wp_send_json_error(array('msg' => __('Please choose a valid page.', 'BOKUN_txt_domain')));
                    wp_die();
                }
            }

            update_option('bokun_dashboard_page_id', $page_id);

            if ($page_id > 0) {
                $message = __('Dashboard page selection saved. The dashboard will be displayed on the chosen page.', 'BOKUN_txt_domain');
            } else {
                $message = __('Dashboard page selection cleared. Use the shortcode to display the dashboard.', 'BOKUN_txt_domain');
            }

            wp_send_json_success(array('msg' => $message));
            wp_die();
        }

        function bokun_import_product_tag_images() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('msg' => __('You are not allowed to import product tag images.', 'BOKUN_txt_domain')));
                wp_die();
            }

            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => __('Invalid nonce.', 'BOKUN_txt_domain')));
                wp_die();
            }

            $context = isset($_POST['context']) ? sanitize_key(wp_unslash($_POST['context'])) : 'default';

            $summary = bokun_import_images_for_all_product_tags(array('context' => $context));

            if (!is_array($summary)) {
                wp_send_json_error(array('msg' => __('Unable to import product tag images.', 'BOKUN_txt_domain')));
                wp_die();
            }

            if (!empty($summary['query_error']) && !empty($summary['messages'])) {
                $message = sanitize_text_field($summary['messages'][0]);
                wp_send_json_error(array('msg' => $message));
                wp_die();
            }

            $processed   = isset($summary['processed_terms']) ? (int) $summary['processed_terms'] : 0;
            $updated     = isset($summary['updated_terms']) ? (int) $summary['updated_terms'] : 0;
            $unchanged   = isset($summary['unchanged_terms']) ? (int) $summary['unchanged_terms'] : 0;
            $skipped     = isset($summary['skipped_terms']) ? (int) $summary['skipped_terms'] : 0;
            $errors      = isset($summary['errors']) ? (int) $summary['errors'] : 0;
            $has_errors  = $errors > 0;
            $total_terms = isset($summary['total_terms']) ? (int) $summary['total_terms'] : $processed;

            $primary_message = sprintf(
                /* translators: 1: Processed term count. 2: Updated count. 3: Unchanged count. 4: Skipped count. */
                __('Processed %1$d product tags (%2$d updated, %3$d unchanged, %4$d skipped).', 'BOKUN_txt_domain'),
                $processed,
                $updated,
                $unchanged,
                $skipped
            );

            if (0 === $total_terms) {
                $primary_message = __('No product tags with Bokun product IDs are available for import.', 'BOKUN_txt_domain');
            }

            $messages = array();

            if (!empty($summary['messages']) && is_array($summary['messages'])) {
                foreach ($summary['messages'] as $message_text) {
                    $messages[] = sanitize_text_field($message_text);
                }
            }

            wp_send_json_success(
                array(
                    'message'    => $primary_message,
                    'messages'   => $messages,
                    'summary'    => $summary,
                    'has_errors' => $has_errors,
                )
            );

            wp_die();
        }

        function bokun_display_settings( ) {
            if( file_exists( BOKUN_INCLUDES_DIR . "bokun_settings.view.php" ) ) {
                include_once( BOKUN_INCLUDES_DIR . "bokun_settings.view.php" );
            }
        }

    }


    global $bokun_settings;
    $bokun_settings = new BOKUN_Settings();
}
    
?>