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

        } 

        
        function bokun_bookings_manager_page() {
            
            // Check the nonce first
            if (!check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
                wp_send_json_error(array('msg' => 'Nonce verification failed.'));
                wp_die();
            }

            $mode = $_POST['mode'];
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
                wp_send_json_success(array('msg' => esc_html($bookings),'status' => false));
            } else {
                bokun_save_bookings_as_posts($bookings);
                wp_send_json_success(array('msg' => 'Bookings have been successfully imported as custom posts.', 'status' => true));
            }

            wp_die(); // Always end AJAX functions with wp_die()
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