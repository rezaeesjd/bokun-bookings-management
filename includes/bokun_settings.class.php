<?php
if( !class_exists ( 'BOKUN_Settings' ) ) {

    class BOKUN_Settings {

        function __construct(){

            add_action('wp_ajax_bokun_save_api_auth',array( $this, "bokun_save_api_auth" ), 10 , 2 );
            add_action('wp_ajax_no_priv_bokun_save_api_auth',array( $this, "bokun_save_api_auth" ), 10 , 2 );

            add_action('wp_ajax_bokun_save_api_auth_upgrade',array( $this, "bokun_save_api_auth_upgrade" ), 10 , 2 );
            add_action('wp_ajax_no_priv_bokun_save_api_auth_upgrade',array( $this, "bokun_save_api_auth_upgrade" ), 10 , 2 );

            add_action('wp_ajax_bokun_bookings_manager_page',array( $this, "bokun_bookings_manager_page" ), 10  );
            add_action('wp_ajax_nopriv_bokun_bookings_manager_page',array( $this, "bokun_bookings_manager_page" ), 10  );

            add_action('wp_ajax_bokun_get_import_progress', array($this, 'bokun_get_import_progress'), 10);
            add_action('wp_ajax_nopriv_bokun_get_import_progress', array($this, 'bokun_get_import_progress'), 10);

        } 

        
        function bokun_bookings_manager_page() {
            
            // Check the nonce first
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => 'Nonce verification failed.'));
                wp_die();
            }

            $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
            $progress_context = ($mode === 'upgrade') ? 'upgrade' : 'fetch';
            // If nonce check passes, proceed with your logic
            if ($mode === 'upgrade') {
                $bookings = bokun_fetch_bookings('upgrade'); // Replace with your actual function
            } else {
                $bookings = bokun_fetch_bookings(); // Replace with your actual function
            }
            // echo 'out';
            // echo '<pre>';
            // print_r($bookings);
            // die;
            if (is_string($bookings)) {
                $normalized_message = trim($bookings);
                $is_error_message = stripos($normalized_message, 'error') === 0;

                bokun_set_import_progress_state($progress_context, array(
                    'status'    => $is_error_message ? 'error' : 'completed',
                    'total'     => 0,
                    'processed' => 0,
                    'created'   => 0,
                    'updated'   => 0,
                    'skipped'   => 0,
                    'message'   => $is_error_message ? bokun_get_import_progress_message($progress_context, 'error') : bokun_get_import_progress_message($progress_context, 'completed'),
                ));
                wp_send_json_success(array('msg' => esc_html($bookings), 'status' => false));
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
            // Verify that the request is coming from an authenticated user
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => 'Invalid nonce.'));
                wp_die();
            }

            // Sanitize the POST data
            $api_key = sanitize_text_field($_POST['api_key']);
            $secret_key = sanitize_text_field($_POST['secret_key']);

            // Save the values in the WordPress options table
            update_option('bokun_api_key', $api_key);
            update_option('bokun_secret_key', $secret_key);

            // Return a success response
            wp_send_json_success(array('msg' => 'API keys saved successfully.', 'status' => false));
            wp_die(); // Terminate the script to prevent WordPress from outputting any further content
        }

        function bokun_save_api_auth_upgrade() {
            // Verify that the request is coming from an authenticated user
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => 'Invalid nonce.'));
                wp_die();
            }

            // Sanitize the POST data
            $api_key = sanitize_text_field($_POST['api_key_upgrade']);
            $secret_key = sanitize_text_field($_POST['secret_key_upgrade']);

            // Save the values in the WordPress options table
            update_option('bokun_api_key_upgrade', $api_key);
            update_option('bokun_secret_key_upgrade', $secret_key);

            // Return a success response
            wp_send_json_success(array('msg' => 'API keys saved successfully.', 'status' => false));
            wp_die(); // Terminate the script to prevent WordPress from outputting any further content
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