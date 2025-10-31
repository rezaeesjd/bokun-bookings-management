<?php
namespace Bokun\Bookings\Admin\Settings;

use Bokun\Bookings\Infrastructure\Config\SettingsRepository;

if (! defined('ABSPATH')) {
    exit;
}

class SettingsController
{
    /**
     * @var SettingsRepository
     */
    private $settings;

    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
        add_action('wp_ajax_bokun_save_api_auth', [$this, 'bokun_save_api_auth'], 10, 2);
        add_action('wp_ajax_no_priv_bokun_save_api_auth', [$this, 'bokun_save_api_auth'], 10, 2);

        add_action('wp_ajax_bokun_save_api_auth_upgrade', [$this, 'bokun_save_api_auth_upgrade'], 10, 2);
        add_action('wp_ajax_no_priv_bokun_save_api_auth_upgrade', [$this, 'bokun_save_api_auth_upgrade'], 10, 2);

        add_action('wp_ajax_bokun_bookings_manager_page', [$this, 'bokun_bookings_manager_page'], 10);
        add_action('wp_ajax_nopriv_bokun_bookings_manager_page', [$this, 'bokun_bookings_manager_page'], 10);

        add_action('wp_ajax_bokun_get_import_progress', [$this, 'bokun_get_import_progress'], 10);
        add_action('wp_ajax_nopriv_bokun_get_import_progress', [$this, 'bokun_get_import_progress'], 10);
    }

    public function bokun_bookings_manager_page()
    {
        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['msg' => 'Nonce verification failed.']);
            wp_die();
        }

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        $progress_context = ($mode === 'upgrade') ? 'upgrade' : 'fetch';

        $bookings = ($mode === 'upgrade') ? bokun_fetch_bookings('upgrade') : bokun_fetch_bookings();

        if (is_string($bookings)) {
            $normalized_message = trim($bookings);
            $is_error_message = stripos($normalized_message, 'error') === 0;

            bokun_set_import_progress_state($progress_context, [
                'status'    => $is_error_message ? 'error' : 'completed',
                'total'     => 0,
                'processed' => 0,
                'created'   => 0,
                'updated'   => 0,
                'skipped'   => 0,
                'message'   => $is_error_message ? bokun_get_import_progress_message($progress_context, 'error') : bokun_get_import_progress_message($progress_context, 'completed'),
            ]);

            wp_send_json_success(['msg' => esc_html($bookings), 'status' => false]);
        } else {
            $import_summary = bokun_save_bookings_as_posts($bookings, $progress_context);

            if (! is_array($import_summary)) {
                $import_summary = [];
            }

            $normalized_summary = [
                'total'     => isset($import_summary['total']) ? (int) $import_summary['total'] : 0,
                'processed' => isset($import_summary['processed']) ? (int) $import_summary['processed'] : 0,
                'created'   => isset($import_summary['created']) ? (int) $import_summary['created'] : 0,
                'updated'   => isset($import_summary['updated']) ? (int) $import_summary['updated'] : 0,
                'skipped'   => isset($import_summary['skipped']) ? (int) $import_summary['skipped'] : 0,
            ];

            wp_send_json_success([
                'msg'            => 'Bookings have been successfully imported as custom posts.',
                'status'         => true,
                'import_summary' => $normalized_summary,
            ]);
        }

        wp_die();
    }

    public function bokun_get_import_progress()
    {
        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['msg' => 'Nonce verification failed.']);
            wp_die();
        }

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        $progress = bokun_get_import_progress_state($mode);

        if (! is_array($progress)) {
            $progress = [];
        }

        wp_send_json_success($progress);
        wp_die();
    }

    public function bokun_save_api_auth()
    {
        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['msg' => 'Invalid nonce.']);
            wp_die();
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        $secret_key = isset($_POST['secret_key']) ? sanitize_text_field(wp_unslash($_POST['secret_key'])) : '';

        $this->settings->savePrimaryCredentials($api_key, $secret_key);

        wp_send_json_success(['msg' => 'API keys saved successfully.', 'status' => false]);
        wp_die();
    }

    public function bokun_save_api_auth_upgrade()
    {
        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['msg' => 'Invalid nonce.']);
            wp_die();
        }

        $api_key = isset($_POST['api_key_upgrade']) ? sanitize_text_field(wp_unslash($_POST['api_key_upgrade'])) : '';
        $secret_key = isset($_POST['secret_key_upgrade']) ? sanitize_text_field(wp_unslash($_POST['secret_key_upgrade'])) : '';

        $this->settings->saveUpgradeCredentials($api_key, $secret_key);

        wp_send_json_success(['msg' => 'API keys saved successfully.', 'status' => false]);
        wp_die();
    }

    public function bokun_display_settings()
    {
        if (defined('BOKUN_INCLUDES_DIR')) {
            $view = rtrim(BOKUN_INCLUDES_DIR, '/\\') . '/bokun_settings.view.php';

            if (file_exists($view)) {
                $primaryCredentials = $this->settings->getPrimaryCredentials();
                $upgradeCredentials = $this->settings->getUpgradeCredentials();

                $api_key = $primaryCredentials['api_key'];
                $secret_key = $primaryCredentials['secret_key'];
                $api_key_upgrade = $upgradeCredentials['api_key'];
                $secret_key_upgrade = $upgradeCredentials['secret_key'];

                include $view;
            }
        }
    }
}

if (! class_exists('BOKUN_Settings', false)) {
    class_alias(SettingsController::class, 'BOKUN_Settings');
}
