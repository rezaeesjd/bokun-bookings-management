<?php

require_once __DIR__ . '/wp-constants.php';
require_once __DIR__ . '/../vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';

if (! defined('BOKUN_PLUGIN')) {
    define('BOKUN_PLUGIN', '/bokun-bookings-management/');
}

if (! defined('BOKUN_TEXT_DOMAIN')) {
    define('BOKUN_TEXT_DOMAIN', 'bokun-bookings-management');
}

if (! defined('BOKUN_PLUGIN_VERSION')) {
    define('BOKUN_PLUGIN_VERSION', '0.0.0');
}

if (! defined('BOKUN_PLUGIN_DIR')) {
    define('BOKUN_PLUGIN_DIR', __DIR__);
}

if (! defined('BOKUN_INCLUDES_DIR')) {
    define('BOKUN_INCLUDES_DIR', __DIR__ . '/../includes/');
}

if (! defined('BOKUN_ASSETS_DIR')) {
    define('BOKUN_ASSETS_DIR', __DIR__);
}

if (! defined('BOKUN_JS_DIR')) {
    define('BOKUN_JS_DIR', __DIR__);
}

if (! defined('BOKUN_CSS_DIR')) {
    define('BOKUN_CSS_DIR', __DIR__);
}

if (! defined('BOKUN_IMAGES_DIR')) {
    define('BOKUN_IMAGES_DIR', __DIR__);
}

if (! defined('BOKUN_PLUGIN_URL')) {
    define('BOKUN_PLUGIN_URL', 'http://example.com/wp-content/plugins/bokun-bookings-management/');
}

if (! defined('BOKUN_ASSETS_URL')) {
    define('BOKUN_ASSETS_URL', 'http://example.com/wp-content/plugins/bokun-bookings-management/assets/');
}

if (! defined('BOKUN_JS_URL')) {
    define('BOKUN_JS_URL', BOKUN_ASSETS_URL . 'js/');
}

if (! defined('BOKUN_CSS_URL')) {
    define('BOKUN_CSS_URL', BOKUN_ASSETS_URL . 'css/');
}

if (! defined('BOKUN_IMAGES_URL')) {
    define('BOKUN_IMAGES_URL', BOKUN_ASSETS_URL . 'images/');
}

if (! class_exists('BokunBookingManagement')) {
    class BokunBookingManagement
    {
        public const VERSION = '1.0.0';

        public function __construct(...$args)
        {
        }

        public function isActivated(): bool
        {
            return true;
        }
    }
}
