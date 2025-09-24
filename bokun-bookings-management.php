<?php
/*
Plugin Name: Bokun Bookings Management
Plugin URI: #
Description:  Manage Bokun bookings and notifications.
Version: 1.0.0
Author: Hitesh (HWT)
Author URI: #
Domain Path: /languages
Text Domain: BOKUN_text_domain
*/


// plugin definitions
define( 'BOKUN_PLUGIN', '/bokun-bookings-management/');

$api_key = get_option('bokun_api_key', '');
$secret_key = get_option('bokun_secret_key', '');
$api_key_upgrade = get_option('bokun_api_key_upgrade', '');
$secret_key_upgrade = get_option('bokun_secret_key_upgrade', '');
// Define Bokun API constants
define('BOKUN_API_BASE_URL', 'https://api.bokun.io');
define('BOKUN_API_KEY', $api_key); 
define('BOKUN_SECRET_KEY', $secret_key); 
define('BOKUN_API_KEY_UPGRADE', $api_key_upgrade); 
define('BOKUN_SECRET_KEY_UPGRADE', $secret_key_upgrade); 
define('BOKUN_API_BOOKING_API', '/booking.json/booking-search');

// directory define
define( 'BOKUN_PLUGIN_DIR', WP_PLUGIN_DIR.BOKUN_PLUGIN);
define( 'BOKUN_INCLUDES_DIR', BOKUN_PLUGIN_DIR.'includes/' );
define( 'BOKUN_UPLOAD_URL', BOKUN_PLUGIN_DIR.'upload/');
$upload = wp_upload_dir();

define( 'BOKUN_ASSETS_DIR', BOKUN_PLUGIN_DIR.'assets/' );
define( 'BOKUN_CSS_DIR', BOKUN_ASSETS_DIR.'css/' );
define( 'BOKUN_JS_DIR', BOKUN_ASSETS_DIR.'js/' );
define( 'BOKUN_IMAGES_DIR', BOKUN_ASSETS_DIR.'images/' );

// URL define
define( 'BOKUN_PLUGIN_URL', WP_PLUGIN_URL.BOKUN_PLUGIN);

define( 'BOKUN_ASSETS_URL', BOKUN_PLUGIN_URL.'assets/');
define( 'BOKUN_IMAGES_URL', BOKUN_ASSETS_URL.'images/');
define( 'BOKUN_CSS_URL', BOKUN_ASSETS_URL.'css/');
define( 'BOKUN_JS_URL', BOKUN_ASSETS_URL.'js/');
define( 'BOKUN_AUTH_URL', '');

// define text domain
define( 'BOKUN_txt_domain', 'BOKUN_text_domain' );

global $bokun_version;
$bokun_version = '1.0.0';

class BokunBookingManagement {
    var $bokun_settings = '';

	function __construct() {
        global $wpdb;
        
        $this->bokun_settings = 'bokun_settings';
        
		register_activation_hook( __FILE__,  array( &$this, 'bokun_install' ) );

        register_deactivation_hook( __FILE__, array( &$this, 'bokun_deactivation' ) );

		add_action( 'admin_menu', array( $this, 'bokun_add_menu' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'bokun_enqueue_scripts' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'bokun_front_enqueue_scripts' ) );

        add_action( 'wp_loaded', array( $this,'bokun_register_all_scripts' ));

        add_action( 'plugins_loaded', array( $this, 'bokun_load_textdomain' ) );

       // add_action('wp_enqueue_scripts', array( $this,'enqueue_bokun_script')) ;

        add_action('init', array( $this,'bokun_register_custom_post_type'));

        add_action('init', array( $this,'bokun_register_product_taxonomy'));
        
        add_action('init', array( $this,'bokun_register_booking_status_taxonomy'));
        
	}
    

    // Register custom taxonomy for Booking Status    
    function bokun_register_booking_status_taxonomy() {
        register_taxonomy(
            'booking_status',
            'bokun_booking',
            [
                'label' => __('Booking Status'),
                'rewrite' => ['slug' => 'booking-status'],
                'hierarchical' => false,
                'show_in_rest' => true,
            ]
        );
    }
    // Register custom taxonomy for Product Tags
    function bokun_register_product_taxonomy() {
        register_taxonomy(
            'product_tags',
            'bokun_booking',
            [
                'label' => __('Product Tags'),
                'rewrite' => [  'slug' => 'product-tags',
    'with_front' => true,],
    'public' => true,
                'hierarchical' => false,
              'show_ui' => true,
            'show_in_nav_menus' => true,
                'show_in_rest' => true,
            ]
        );
    }
    


    // Register custom post type for Bokun Bookings
    function bokun_register_custom_post_type() {
        register_post_type('bokun_booking', [
            'labels' => [
                'name' => __('Bokun Bookings'),
                'singular_name' => __('Bokun Booking'),
                'add_new' => __('Add New'),
                'add_new_item' => __('Add New Bokun Booking'),
                'edit_item' => __('Edit Bokun Booking'),
                'new_item' => __('New Bokun Booking'),
                'view_item' => __('View Bokun Booking'),
                'search_items' => __('Search Bokun Bookings'),
                'not_found' => __('No Bokun Bookings found'),
                'not_found_in_trash' => __('No Bokun Bookings found in Trash'),
                'all_items' => __('All Bokun Bookings'),
                'archives' => __('Bokun Booking Archives'),
                'insert_into_item' => __('Insert into Bokun Booking'),
                'uploaded_to_this_item' => __('Uploaded to this Bokun Booking'),
                'featured_image' => __('Featured Image'),
                'set_featured_image' => __('Set featured image'),
                'remove_featured_image' => __('Remove featured image'),
                'use_featured_image' => __('Use as featured image'),
                'menu_name' => __('Bokun Bookings Management'),
                'filter_items_list' => __('Filter Bokun Bookings list'),
                'items_list_navigation' => __('Bokun Bookings list navigation'),
                'items_list' => __('Bokun Bookings list'),
            ],
  'public' => true,
        'has_archive' => true,
        'show_in_menu' => true,
        'show_in_rest' => true, // Enable REST API support for Elementor
        'supports' => ['title', 'editor', 'custom-fields', 'author', 'comments', 'revisions', 'thumbnail', 'excerpt', 'page-attributes'],
        'rewrite' => ['slug' => 'bokun-booking'],
        'taxonomies' => ['alarm_status'], // Link custom taxonomy here
        'capability_type' => 'post',
        'map_meta_cap' => true,
		'show_ui' => true,
        'show_in_nav_menus' => true,
        'menu_icon' => 'dashicons-calendar',
        ]);
    }


    function bokun_load_textdomain() {
        load_plugin_textdomain( BOKUN_txt_domain, false, basename(dirname(__FILE__)) . '/languages' ); //Loads plugin text domain for the translation
        do_action('bokun_txt_domain');
    }

	static function bokun_install() {

		global $wpdb, $rb, $bokun_version;

        $charset_collate = $wpdb->get_charset_collate();
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        update_option( "bokun_plugin", true );
        update_option( "bokun_version", $bokun_version );

        $wpdb->prefix;
        
	}

    static function bokun_deactivation() {
        // deactivation process here
    }

	function bokun_get_sub_menu() {
        $bokun_admin_menu = array(
            array(
                'name' => __('Settings', 'BOKUN_txt_domain'), // Name of the submenu
                'cap' => 'manage_options', // Capability required to access this submenu
                'slug' => $this->bokun_settings, // Slug of the submenu
            ),
        );
		return $bokun_admin_menu;
	}

	function bokun_add_menu() {

		$bokun_main_page_name = __('Bokun Bookings Management ', BOKUN_txt_domain);
		$bokun_main_page_capa = 'manage_options';
		$bokun_main_page_slug = 'edit.php?post_type=bokun_booking'; //$this->bokun_settings; 

		$bokun_get_sub_menu   = $this->bokun_get_sub_menu();
		/* set capablity here.... Right now manage_options capability given to all page and sub pages. <span class="dashicons dashicons-money"></span>*/	 
		//($bokun_main_page_name, $bokun_main_page_name, $bokun_main_page_capa, $bokun_main_page_slug, array( &$this, 'bokun_route' ), 'dashicons-database-import', 50 );
		
		foreach ($bokun_get_sub_menu as $bokun_menu_key => $bokun_menu_value) {
			add_submenu_page(
				$bokun_main_page_slug, 
				$bokun_menu_value['name'], 
				$bokun_menu_value['name'], 
				$bokun_menu_value['cap'], 
				$bokun_menu_value['slug'], 
				array( $this, 'bokun_route') 
			);	
		}
	}

	function bokun_is_activate(){
		if(get_option("bokun_plugin")) {
			return true;
		} else {
			return false;
		}
	}

	function bokun_admin_slugs() {
		$bokun_pages_slug = array(
			$this->bokun_settings,
		);
		return $bokun_pages_slug;
	}

	function bokun_is_page() {
		if( isset( $_REQUEST['page'] ) && in_array( $_REQUEST['page'], $this->bokun_admin_slugs() ) ) {
			return true;
		} else {
			return false;
		}
	} 

    function bokun_admin_msg( $key ) { 
        $admin_msg = array(
            "no_tax" => __("No matching tax rates found.", BOKUN_txt_domain)
        );

        if( $key == 'script' ){
            $script = '<script type="text/javascript">';
            $script.= 'var bokun_msg = '.json_encode($admin_msg);
            $script.= '</script>';
            return $script;
        } else {
            return isset($admin_msg[$key]) ? $admin_msg[$key] : false;
        }
    }

	function bokun_enqueue_scripts() {
        
        if( $this->bokun_is_page() ) {
            global $bokun_version;
            /* must register style and than enqueue */
            wp_register_script( 
                'bokun_admin_js', 
                BOKUN_JS_URL.'bokun_admin_js.js?rand='.rand(1,999), 
                'jQuery', 
                $bokun_version, 
                true 
            );
    
            wp_enqueue_script( 'bokun_admin_js' );
    
            wp_enqueue_script('bokun-script', BOKUN_JS_URL . 'bokun-script.js', array('jquery'), null, true);
    
            // Localize script to pass the nonce
            wp_localize_script('bokun-script', 'bokun_api_auth_vars', array(
                'nonce' => wp_create_nonce('bokun_api_auth_nonce'),
            ));

            wp_register_style( 
                'bokun_admin_css',  
                BOKUN_CSS_URL.'bokun_admin_style.css?rand='.rand(1,999), 
                false, 
                $bokun_version 
            );

            wp_enqueue_style( 'bokun_admin_css' );
		}
    }

    function bokun_front_enqueue_scripts() {
        global $bokun_version;
        // need to check here if its front section than enqueue script
        /*********** register and enqueue styles ***************/

            wp_register_style( 
                'bokun_front_css',  
                BOKUN_CSS_URL.'bokun_front.css?rand='.rand(1,999), 
                false, 
                $bokun_version 
            );

            // wp_enqueue_style( 'bokun_front_css' );


            /*********** register and enqueue scripts ***************/
            echo "<script> var ajaxurl = '".admin_url( 'admin-ajax.php' )."'; </script>";

            wp_register_script( 
                'bokun_front_js', 
                BOKUN_JS_URL.'bokun_front.js?rand='.rand(1,999), 
                'jQuery', 
                $bokun_version, 
                true 
            );

            wp_enqueue_script( 'bokun_front_js' );           

            wp_enqueue_script('bokun-script', BOKUN_JS_URL . 'bokun-script.js', array('jquery'), null, true);
    
            // Localize script to pass the nonce
            wp_localize_script('bokun-script', 'bokun_api_auth_vars', array(
                'nonce' => wp_create_nonce('bokun_api_auth_nonce'),
            ));
        
	}

    function bokun_register_all_scripts() {
        global $bokun_version;

         wp_register_script( 
            'bokun_bokun_booking_scripts', 
            BOKUN_JS_URL.'bokun-booking-scripts.js?rand='.rand(1,999), 
            'jQuery', 
            $bokun_version, 
            true 
        );

        wp_enqueue_script( 'bokun_bokun_booking_scripts' );

        wp_localize_script('bokun_bokun_booking_scripts', 'bbm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('update_booking_nonce'),
        ]);
    }

	function bokun_route() {
		global $rb,$bokun_settings,$bokun_booking,$bokun_timing;
		if( isset($_REQUEST['page']) && $_REQUEST['page'] != '' ){
            switch ( $_REQUEST['page'] ) {
				case $this->bokun_settings:
					$bokun_settings->bokun_display_settings();
					break;
			}
		}
	}

    function bokun_write_log( $content = '', $file_name = 'bokun_log.txt' ) {
        $file = __DIR__ . '/log/' . $file_name;    
        $file_content = "=============== Write At => " . date( "y-m-d H:i:s" ) . " =============== \r\n";
        $file_content .= $content . "\r\n\r\n";
        file_put_contents( $file, $file_content, FILE_APPEND | LOCK_EX );
    }
    
}


// begin!
global $rb;
$rb = new BokunBookingManagement();

if( $rb->bokun_is_activate() && file_exists( BOKUN_INCLUDES_DIR . "bokun_settings.class.php" ) ) {
    include_once( BOKUN_INCLUDES_DIR . "bokun_settings.class.php" );
}
if( $rb->bokun_is_activate() && file_exists( BOKUN_INCLUDES_DIR . "bokun-bookings-manager.php" ) ) {
    include_once( BOKUN_INCLUDES_DIR . "bokun-bookings-manager.php" );
}
if( $rb->bokun_is_activate() && file_exists( BOKUN_INCLUDES_DIR . "bokun_shortcode.class.php" ) ) {
    include_once( BOKUN_INCLUDES_DIR . "bokun_shortcode.class.php" );
}