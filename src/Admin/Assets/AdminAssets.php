<?php

namespace Bokun\Bookings\Admin\Assets;

use Bokun\Bookings\Admin\Menu\AdminMenu;

class AdminAssets
{
    /**
     * @var AdminMenu
     */
    private $menu;

    /**
     * @var string
     */
    private $version;

    /**
     * @param AdminMenu $menu
     * @param string    $version
     */
    public function __construct(AdminMenu $menu, $version)
    {
        $this->menu = $menu;
        $this->version = $this->normalizeVersion($version);
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
        $version = $this->version;

        wp_register_script(
            'bokun_admin_js',
            BOKUN_JS_URL . 'bokun_admin_js.js?rand=' . rand(1, 999),
            ['jquery'],
            $version,
            true
        );

        wp_enqueue_script('bokun_admin_js');

        wp_localize_script(
            'bokun_admin_js',
            'bokun_api_auth_vars',
            [
                'nonce'    => wp_create_nonce('bokun_api_auth_nonce'),
                'ajax_url' => admin_url('admin-ajax.php'),
                'messages' => [
                    'validating'     => __('Validating credentials…', BOKUN_TEXT_DOMAIN),
                    'validationError'=> __('Unable to validate credentials. Please try again.', BOKUN_TEXT_DOMAIN),
                    'syncing'        => __('Running background sync…', BOKUN_TEXT_DOMAIN),
                    'syncError'      => __('The background sync could not be completed. Please check the logs for details.', BOKUN_TEXT_DOMAIN),
                    'syncLocked'     => __('A sync is already running. Please wait and try again.', BOKUN_TEXT_DOMAIN),
                    'ago'            => __('ago', BOKUN_TEXT_DOMAIN),
                    'never'          => __('Never', BOKUN_TEXT_DOMAIN),
                    'notScheduled'   => __('Not scheduled', BOKUN_TEXT_DOMAIN),
                    'summaryCreated' => __('%d new', BOKUN_TEXT_DOMAIN),
                    'summaryUpdated' => __('%d updated', BOKUN_TEXT_DOMAIN),
                    'summarySkipped' => __('%d skipped', BOKUN_TEXT_DOMAIN),
                    'importComplete' => __('Import complete', BOKUN_TEXT_DOMAIN),
                    'importProgress' => __('Import progress', BOKUN_TEXT_DOMAIN),
                    'importProgressWithTotals' => __('Import progress ({current}/{total})', BOKUN_TEXT_DOMAIN),
                    'importingItem'  => __('Importing item {current}/{total}', BOKUN_TEXT_DOMAIN),
                    'importedCount'  => __('Imported {current}/{total}', BOKUN_TEXT_DOMAIN),
                    'startingImport' => __('Starting import…', BOKUN_TEXT_DOMAIN),
                    'processing'     => __('Processing…', BOKUN_TEXT_DOMAIN),
                    'progressApi1Start'    => __('Fetching items from API 1…', BOKUN_TEXT_DOMAIN),
                    'progressApi1Complete' => __('Finished API 1', BOKUN_TEXT_DOMAIN),
                    'progressApi2Start'    => __('Fetching items from API 2… ({current} processed so far)', BOKUN_TEXT_DOMAIN),
                    'progressApi2Complete' => __('Finished API 2', BOKUN_TEXT_DOMAIN),
                    'summaryApi1Label'     => __('Imported items from API 1', BOKUN_TEXT_DOMAIN),
                    'summaryApi2Label'     => __('Imported items from API 2', BOKUN_TEXT_DOMAIN),
                    'importInterrupted'    => __('Import interrupted', BOKUN_TEXT_DOMAIN),
                    'importErrorDetails'   => __('Check the error message for details.', BOKUN_TEXT_DOMAIN),
                    'genericError'         => __('An error occurred. Please try again.', BOKUN_TEXT_DOMAIN),
                    'errorPrefix'          => __('Error:', BOKUN_TEXT_DOMAIN),
                    'successPrefix'        => __('Success:', BOKUN_TEXT_DOMAIN),
                    'alertErrorMessage'    => __('Error: {message}', BOKUN_TEXT_DOMAIN),
                    'alertUnexpectedResponse' => __('Error: Received unexpected response code {status}. Response: {response}', BOKUN_TEXT_DOMAIN),
                ],
            ]
        );

        wp_register_style(
            'bokun_admin_css',
            BOKUN_CSS_URL . 'bokun_admin_style.css?rand=' . rand(1, 999),
            [],
            $version
        );

        wp_enqueue_style('bokun_admin_css');
    }

    /**
     * Enqueue the front-end assets needed for the shortcode widgets.
     */
    public function enqueueFrontAssets()
    {
        $version = $this->version;

        wp_register_style(
            'bokun_front_css',
            BOKUN_CSS_URL . 'bokun_front.css?rand=' . rand(1, 999),
            [],
            $version
        );

        wp_enqueue_style('bokun_front_css');

        wp_register_script(
            'bokun_front_js',
            BOKUN_JS_URL . 'bokun_front.js?rand=' . rand(1, 999),
            ['jquery'],
            $version,
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
        $version = $this->version;

        wp_register_script(
            'bokun_bokun_booking_scripts',
            BOKUN_JS_URL . 'bokun-booking-scripts.js?rand=' . rand(1, 999),
            ['jquery'],
            $version,
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

    private function normalizeVersion($version)
    {
        if (is_string($version) && $version !== '') {
            return $version;
        }

        if (defined('BOKUN_PLUGIN_VERSION')) {
            return (string) BOKUN_PLUGIN_VERSION;
        }

        return '1.0.0';
    }
}
