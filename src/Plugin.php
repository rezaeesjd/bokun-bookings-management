<?php
namespace Bokun\Bookings;

use Bokun\Bookings\Infrastructure\Config\SettingsRepository;
use Bokun\Bookings\Infrastructure\Container;
use Bokun\Bookings\Infrastructure\ServiceProvider\LegacyServiceProvider;

class Plugin
{
    /**
     * @var Plugin|null
     */
    private static $instance;

    /**
     * @var string
     */
    private $pluginFile;

    /**
     * @var Container
     */
    private $container;

    /**
     * @param string $pluginFile
     */
    public function __construct($pluginFile, ?Container $container = null)
    {
        $this->pluginFile = $pluginFile;
        $this->container = $container ?: new Container();
        self::$instance = $this;
    }

    /**
     * Bootstrap the plugin.
     */
    public function boot()
    {
        $this->registerServices();
        $this->defineConstants();
        $this->loadPlugin();
    }

    public static function getInstance()
    {
        return self::$instance;
    }

    public static function getContainerInstance()
    {
        if (self::$instance instanceof self) {
            return self::$instance->container;
        }

        return null;
    }

    public function getContainer()
    {
        return $this->container;
    }

    private function registerServices()
    {
        $this->container->register(new LegacyServiceProvider());
    }

    private function defineConstants()
    {
        $this->define('BOKUN_PLUGIN', '/bokun-bookings-management/');
        $this->define('BOKUN_PLUGIN_VERSION', \BokunBookingManagement::VERSION);

        /** @var SettingsRepository $settings */
        $settings = $this->container->get('bokun.settings_repository');
        $primaryCredentials = $settings->getPrimaryCredentials();
        $upgradeCredentials = $settings->getUpgradeCredentials();

        $this->define('BOKUN_API_BASE_URL', 'https://api.bokun.io');
        $this->define('BOKUN_API_KEY', $primaryCredentials['api_key']);
        $this->define('BOKUN_SECRET_KEY', $primaryCredentials['secret_key']);
        $this->define('BOKUN_API_KEY_UPGRADE', $upgradeCredentials['api_key']);
        $this->define('BOKUN_SECRET_KEY_UPGRADE', $upgradeCredentials['secret_key']);
        $this->define('BOKUN_API_BOOKING_API', '/booking.json/booking-search');

        $pluginDir = WP_PLUGIN_DIR . BOKUN_PLUGIN;
        $this->define('BOKUN_PLUGIN_DIR', $pluginDir);
        $this->define('BOKUN_INCLUDES_DIR', $pluginDir . 'includes/');
        $this->define('BOKUN_UPLOAD_URL', $pluginDir . 'upload/');

        // Ensure WordPress initializes upload directories as before.
        wp_upload_dir();

        $assetsDir = $pluginDir . 'assets/';
        $this->define('BOKUN_ASSETS_DIR', $assetsDir);
        $this->define('BOKUN_CSS_DIR', $assetsDir . 'css/');
        $this->define('BOKUN_JS_DIR', $assetsDir . 'js/');
        $this->define('BOKUN_IMAGES_DIR', $assetsDir . 'images/');

        $pluginUrl = WP_PLUGIN_URL . BOKUN_PLUGIN;
        $this->define('BOKUN_PLUGIN_URL', $pluginUrl);
        $this->define('BOKUN_ASSETS_URL', $pluginUrl . 'assets/');
        $this->define('BOKUN_IMAGES_URL', $pluginUrl . 'images/');
        $this->define('BOKUN_CSS_URL', $pluginUrl . 'css/');
        $this->define('BOKUN_JS_URL', $pluginUrl . 'js/');
        $this->define('BOKUN_AUTH_URL', '');

        $this->define('BOKUN_TEXT_DOMAIN', 'bokun-bookings-management');
        $this->define('BOKUN_txt_domain', BOKUN_TEXT_DOMAIN);
    }

    private function loadPlugin()
    {
        if (! class_exists('\BokunBookingManagement')) {
            return;
        }

        $manager = $this->container->get('bokun.manager');

        if (! $manager->isActivated()) {
            return;
        }

        $this->container->get('bokun.settings');
        $this->includeIfExists(BOKUN_INCLUDES_DIR . 'bokun-bookings-manager.php');
        $this->container->get('bokun.shortcode');
        $this->container->get('bokun.booking_list_enhancer');
    }

    private function includeIfExists($path)
    {
        if (file_exists($path)) {
            include_once $path;
        }
    }

    private function define($name, $value)
    {
        if (! defined($name)) {
            define($name, $value);
        }
    }
}
