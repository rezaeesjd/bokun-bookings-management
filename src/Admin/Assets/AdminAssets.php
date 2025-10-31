<?php
namespace Bokun\Bookings\Admin\Assets;

use Bokun\Bookings\Admin\Menu\AdminMenu;

if (! defined('ABSPATH')) {
    exit;
}

class AdminAssets
{
    /**
     * @var AdminMenu
     */
    private $menu;

    /**
     * @param AdminMenu $menu
     */
    public function __construct(AdminMenu $menu)
    {
        $this->menu = $menu;
    }

    /**
     * Register WordPress hooks for asset loading.
     */
    public function register()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontAssets']);
        add_action('wp_loaded', [$this, 'registerBookingScripts']);
    }

    /**
     * Enqueue admin scripts and styles when viewing plugin pages.
     */
    public function enqueueAdminAssets()
    {
        if (! $this->menu->isPluginPage()) {
            return;
        }

        global $bokun_version;

        wp_register_script(
            'bokun_admin_js',
            BOKUN_JS_URL . 'bokun_admin_js.js?rand=' . rand(1, 999),
            ['jquery'],
            $bokun_version,
            true
        );

        wp_enqueue_script('bokun_admin_js');

        wp_localize_script(
            'bokun_admin_js',
            'bokun_api_auth_vars',
            [
                'nonce'    => wp_create_nonce('bokun_api_auth_nonce'),
                'ajax_url' => admin_url('admin-ajax.php'),
            ]
        );

        wp_register_style(
            'bokun_admin_css',
            BOKUN_CSS_URL . 'bokun_admin_style.css?rand=' . rand(1, 999),
            [],
            $bokun_version
        );

        wp_enqueue_style('bokun_admin_css');
    }

    /**
     * Enqueue the front-end assets needed for the shortcode widgets.
     */
    public function enqueueFrontAssets()
    {
        global $bokun_version;

        wp_register_style(
            'bokun_front_css',
            BOKUN_CSS_URL . 'bokun_front.css?rand=' . rand(1, 999),
            [],
            $bokun_version
        );

        wp_enqueue_style('bokun_front_css');

        wp_register_script(
            'bokun_front_js',
            BOKUN_JS_URL . 'bokun_front.js?rand=' . rand(1, 999),
            ['jquery'],
            $bokun_version,
            true
        );

        wp_enqueue_script('bokun_front_js');

        wp_localize_script(
            'bokun_front_js',
            'bokun_api_auth_vars',
            [
                'nonce'    => wp_create_nonce('bokun_api_auth_nonce'),
                'ajax_url' => admin_url('admin-ajax.php'),
            ]
        );
    }

    /**
     * Register the shared booking scripts used across the admin screens.
     */
    public function registerBookingScripts()
    {
        global $bokun_version;

        wp_register_script(
            'bokun_bokun_booking_scripts',
            BOKUN_JS_URL . 'bokun-booking-scripts.js?rand=' . rand(1, 999),
            ['jquery'],
            $bokun_version,
            true
        );

        wp_enqueue_script('bokun_bokun_booking_scripts');

        wp_localize_script(
            'bokun_bokun_booking_scripts',
            'bbm_ajax',
            [
                'ajax_url'          => admin_url('admin-ajax.php'),
                'nonce'             => wp_create_nonce('update_booking_nonce'),
                'team_member_nonce' => wp_create_nonce('add_team_member_nonce'),
            ]
        );
    }
}
