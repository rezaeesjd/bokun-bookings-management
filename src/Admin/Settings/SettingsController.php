<?php

namespace Bokun\Bookings\Admin\Settings;

use Bokun\Bookings\Application\Synchronization\BookingSyncService;
use Bokun\Bookings\Infrastructure\Config\SettingsRepository;
use Bokun\Bookings\Infrastructure\Validation\RequestSanitizer;

class SettingsController
{
    /**
     * @var SettingsRepository
     */
    private $settings;

    /**
     * @var RequestSanitizer
     */
    private $request;

    /**
     * @var BookingSyncService
     */
    private $syncService;

    public function __construct(SettingsRepository $settings, RequestSanitizer $request, BookingSyncService $syncService)
    {
        $this->settings = $settings;
        $this->request = $request;
        $this->syncService = $syncService;
        add_action('wp_ajax_bokun_save_api_auth', [$this, 'savePrimaryCredentials'], 10);
        add_action('wp_ajax_nopriv_bokun_save_api_auth', [$this, 'savePrimaryCredentials'], 10);

        add_action('wp_ajax_bokun_save_api_auth_upgrade', [$this, 'saveUpgradeCredentials'], 10);
        add_action('wp_ajax_nopriv_bokun_save_api_auth_upgrade', [$this, 'saveUpgradeCredentials'], 10);

        add_action('wp_ajax_bokun_bookings_manager_page', [$this, 'handleBookingsManagerPage'], 10);
        add_action('wp_ajax_nopriv_bokun_bookings_manager_page', [$this, 'handleBookingsManagerPage'], 10);

        add_action('wp_ajax_bokun_get_import_progress', [$this, 'getImportProgress'], 10);
        add_action('wp_ajax_nopriv_bokun_get_import_progress', [$this, 'getImportProgress'], 10);

        add_action('wp_ajax_bokun_validate_credentials', [$this, 'validateCredentials'], 10);
        add_action('wp_ajax_bokun_run_background_sync', [$this, 'runBackgroundSync'], 10);
    }

    public function handleBookingsManagerPage()
    {
        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['msg' => 'Nonce verification failed.']);
            wp_die();
        }

        $mode = $this->request->postEnum('mode', ['upgrade'], 'fetch');
        $progress_context = ('upgrade' === $mode) ? 'upgrade' : 'fetch';

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

            $response = [
                'msg'    => esc_html($bookings),
                'status' => false,
            ];

            if ($is_error_message) {
                wp_send_json_error($response);
            }

            wp_send_json_success($response);
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

    public function getImportProgress()
    {
        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['msg' => 'Nonce verification failed.']);
            wp_die();
        }

        $mode = $this->request->postEnum('mode', ['fetch', 'upgrade'], 'fetch');
        $progress = bokun_get_import_progress_state($mode);

        if (! is_array($progress)) {
            $progress = [];
        }

        wp_send_json_success($progress);
        wp_die();
    }

    public function savePrimaryCredentials()
    {
        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['msg' => 'Invalid nonce.']);
            wp_die();
        }

        $credentials = $this->request->postCredentials('api_key', 'secret_key');

        $this->settings->savePrimaryCredentials($credentials['api_key'], $credentials['secret_key']);

        wp_send_json_success(['msg' => 'API keys saved successfully.', 'status' => false]);
        wp_die();
    }

    public function saveUpgradeCredentials()
    {
        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['msg' => 'Invalid nonce.']);
            wp_die();
        }

        $credentials = $this->request->postCredentials('api_key_upgrade', 'secret_key_upgrade');

        $this->settings->saveUpgradeCredentials($credentials['api_key'], $credentials['secret_key']);

        wp_send_json_success(['msg' => 'API keys saved successfully.', 'status' => false]);
        wp_die();
    }

    public function displaySettingsPage()
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

                $sync_status = $this->syncService->getDisplayStatus();
                $credentials_overview = [
                    'primary' => [
                        'configured' => $this->isCredentialPairConfigured($primaryCredentials),
                    ],
                    'upgrade' => [
                        'configured' => $this->isCredentialPairConfigured($upgradeCredentials),
                    ],
                ];

                $onboarding_steps = $this->buildOnboardingSteps($credentials_overview, $sync_status);

                include $view;
            }
        }
    }

    public function bokun_bookings_manager_page()
    {
        $this->handleBookingsManagerPage();
    }

    public function bokun_get_import_progress()
    {
        $this->getImportProgress();
    }

    public function bokun_save_api_auth()
    {
        $this->savePrimaryCredentials();
    }

    public function bokun_save_api_auth_upgrade()
    {
        $this->saveUpgradeCredentials();
    }

    public function bokun_display_settings()
    {
        $this->displaySettingsPage();
    }

    public function limitValidationItems($perPage)
    {
        return 1;
    }

    public function validateCredentials()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', BOKUN_TEXT_DOMAIN)], 403);
        }

        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['message' => __('Invalid request. Please refresh the page and try again.', BOKUN_TEXT_DOMAIN)]);
            wp_die();
        }

        $mode = $this->request->postEnum('mode', ['primary', 'upgrade'], 'primary');
        $context = ('upgrade' === $mode) ? 'upgrade' : '';

        $this->ensureLegacyIncludes();

        add_filter('bokun_booking_items_per_page', [$this, 'limitValidationItems'], 99);
        $bookings = '' !== $context ? bokun_fetch_bookings($context) : bokun_fetch_bookings();
        remove_filter('bokun_booking_items_per_page', [$this, 'limitValidationItems'], 99);

        if (is_string($bookings)) {
            $message = trim($bookings);
            $message = $message !== '' ? $message : __('The credentials could not be validated.', BOKUN_TEXT_DOMAIN);

            wp_send_json_error([
                'status'  => 'error',
                'message' => $message,
                'mode'    => $mode,
            ]);
            wp_die();
        }

        $count = is_array($bookings) ? count($bookings) : 0;

        $message = $count > 0
            ? sprintf(__('Connection successful. Retrieved %d bookings.', BOKUN_TEXT_DOMAIN), $count)
            : __('Connection successful, but no bookings were returned for the selected credentials.', BOKUN_TEXT_DOMAIN);

        wp_send_json_success([
            'status'  => 'success',
            'message' => $message,
            'mode'    => $mode,
            'count'   => $count,
        ]);
        wp_die();
    }

    public function runBackgroundSync()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', BOKUN_TEXT_DOMAIN)], 403);
        }

        if (! check_ajax_referer('bokun_api_auth_nonce', 'security', false)) {
            wp_send_json_error(['message' => __('Invalid request. Please refresh the page and try again.', BOKUN_TEXT_DOMAIN)]);
            wp_die();
        }

        $this->ensureLegacyIncludes();

        $result = $this->syncService->run('manual');
        $status = isset($result['status']) ? $result['status'] : 'error';

        $payload = [
            'status'      => $status,
            'message'     => isset($result['message']) ? $result['message'] : '',
            'summary'     => isset($result['summary']) ? $result['summary'] : [],
            'sync_status' => $this->syncService->getDisplayStatus(),
        ];

        if ('error' === $status || 'locked' === $status) {
            $payload['error'] = isset($result['error']) ? $result['error'] : '';
            wp_send_json_error($payload);
            wp_die();
        }

        wp_send_json_success($payload);
        wp_die();
    }

    private function isCredentialPairConfigured(array $credentials)
    {
        return ! empty($credentials['api_key']) && ! empty($credentials['secret_key']);
    }

    private function buildOnboardingSteps(array $credentialsOverview, array $syncStatus)
    {
        $steps = [];

        $steps[] = [
            'label'     => __('Enter your primary Bokun API keys', BOKUN_TEXT_DOMAIN),
            'completed' => ! empty($credentialsOverview['primary']['configured']),
        ];

        $steps[] = [
            'label'     => __('Add upgrade credentials (optional)', BOKUN_TEXT_DOMAIN),
            'completed' => ! empty($credentialsOverview['upgrade']['configured']),
        ];

        $lastStatus = isset($syncStatus['last_status']) ? $syncStatus['last_status'] : '';
        $steps[] = [
            'label'     => __('Confirm an automatic sync completes successfully', BOKUN_TEXT_DOMAIN),
            'completed' => in_array($lastStatus, ['success', 'empty'], true),
        ];

        return $steps;
    }

    private function ensureLegacyIncludes(): void
    {
        if (defined('BOKUN_INCLUDES_DIR')) {
            $managerFile = rtrim(BOKUN_INCLUDES_DIR, '/\\') . '/bokun-bookings-manager.php';
            if (file_exists($managerFile)) {
                include_once $managerFile;
            }
        }
    }
}

if (! class_exists('BOKUN_Settings', false)) {
    class_alias(SettingsController::class, 'BOKUN_Settings');
}
