<?php
if (! defined('ABSPATH')) {
    exit;
}

use Bokun\Bookings\Admin\Settings\SettingsController;
use Bokun\Bookings\Infrastructure\Config\SettingsRepository;
use Bokun\Bookings\Infrastructure\Validation\DataSanitizer;
use Bokun\Bookings\Infrastructure\Validation\RequestSanitizer;
use Bokun\Bookings\Plugin;

if (! class_exists('BOKUN_Settings')) {
    class BOKUN_Settings
    {
        /**
         * @var SettingsController|null
         */
        private $controller;

        public function __construct()
        {
            $this->controller = $this->resolveController();
        }

        /**
         * Proxy undefined method calls to the modern controller implementation.
         *
         * @param string $method
         * @param array<int, mixed> $arguments
         *
         * @return mixed
         */
        public function __call($method, $arguments)
        {
            if ($this->controller && method_exists($this->controller, $method)) {
                return $this->controller->{$method}(...$arguments);
            }

            return null;
        }

        public function bokun_bookings_manager_page()
        {
            if ($this->controller) {
                return $this->controller->handleBookingsManagerPage();
            }

            return null;
        }

        public function bokun_get_import_progress()
        {
            if ($this->controller) {
                return $this->controller->getImportProgress();
            }

            return null;
        }

        public function bokun_save_api_auth()
        {
            if ($this->controller) {
                return $this->controller->savePrimaryCredentials();
            }

            return null;
        }

        public function bokun_save_api_auth_upgrade()
        {
            if ($this->controller) {
                return $this->controller->saveUpgradeCredentials();
            }

            return null;
        }

        public function bokun_display_settings()
        {
            if ($this->controller) {
                return $this->controller->displaySettingsPage();
            }

            return null;
        }

        private function resolveController()
        {
            $container = Plugin::getContainerInstance();

            if ($container) {
                try {
                    return $container->get('bokun.settings');
                } catch (\Throwable $exception) {
                    // Fall back to a direct instance below.
                }
            }

            if (class_exists(SettingsController::class)) {
                $sanitizer = new DataSanitizer();
                $repository = new SettingsRepository($sanitizer);
                $request = new RequestSanitizer($sanitizer);

                return new SettingsController($repository, $request);
            }

            return null;
        }
    }

    $GLOBALS['bokun_settings'] = new BOKUN_Settings();
}
