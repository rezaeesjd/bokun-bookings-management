<?php
/*
Plugin Name: Bokun Bookings Management
Plugin URI: #
Description:  Manage Bokun bookings and notifications.
Version: 1.0.0
Author: Hitesh (HWT)
Author URI: #
Domain Path: /languages
Text Domain: bokun-bookings-management
*/

$autoloader = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloader)) {
    require_once $autoloader;
} elseif (function_exists('spl_autoload_register')) {
    spl_autoload_register(static function ($class) {
        $prefix = 'Bokun\\Bookings\\';
        $prefixLength = strlen($prefix);

        if (strncmp($prefix, $class, $prefixLength) !== 0) {
            return;
        }

        $relativeClass = substr($class, $prefixLength);
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

use Bokun\Bookings\Admin\Assets\AdminAssets;
use Bokun\Bookings\Admin\Localization\LocalizationLoader;
use Bokun\Bookings\Admin\Menu\AdminMenu;
use Bokun\Bookings\Registration\PostTypeRegistrar;
use Bokun\Bookings\Registration\TaxonomyRegistrar;

class BokunBookingManagement {
    public const VERSION = '1.0.0';
    /**
     * @var string
     */
    private $settingsSlug = 'bokun_settings';

    /**
     * @var string
     */
    private $bookingHistorySlug = 'bokun_booking_history';

    /** @var AdminMenu */
    private $adminMenu;

    /** @var AdminAssets */
    private $assets;

    /** @var PostTypeRegistrar */
    private $postTypeRegistrar;

    /** @var TaxonomyRegistrar */
    private $taxonomyRegistrar;

    /** @var LocalizationLoader */
    private $localizationLoader;

    public function __construct(
        ?AdminMenu $adminMenu = null,
        ?AdminAssets $assets = null,
        ?PostTypeRegistrar $postTypeRegistrar = null,
        ?TaxonomyRegistrar $taxonomyRegistrar = null,
        ?LocalizationLoader $localizationLoader = null
    ) {
        $this->adminMenu         = $adminMenu ?: new AdminMenu($this->settingsSlug, $this->bookingHistorySlug);
        $this->assets            = $assets ?: new AdminAssets($this->adminMenu, self::VERSION);
        $this->postTypeRegistrar = $postTypeRegistrar ?: new PostTypeRegistrar();
        $this->taxonomyRegistrar = $taxonomyRegistrar ?: new TaxonomyRegistrar();
        $this->localizationLoader = $localizationLoader ?: new LocalizationLoader();

        $this->adminMenu->register();
        $this->assets->register();
        $this->postTypeRegistrar->register();
        $this->taxonomyRegistrar->register();
        $this->localizationLoader->register();
    }

    public static function bokun_install()
    {
        self::activate();
    }

    public static function bokun_deactivation()
    {
        self::deactivate();
    }

    public static function activate()
    {
        $container = \Bokun\Bookings\Plugin::getContainerInstance();

        if ($container && $container->has('bokun.activator')) {
            $container->get('bokun.activator')->activate();
        }
    }

    public static function deactivate()
    {
        $container = \Bokun\Bookings\Plugin::getContainerInstance();

        if ($container && $container->has('bokun.deactivator')) {
            $container->get('bokun.deactivator')->deactivate();
        }
    }

    public function getSubmenuItems()
    {
        return $this->adminMenu->getSubmenuItems();
    }

    public function registerMenu()
    {
        $this->adminMenu->registerMenu();
    }

    public function registerBookingPostType()
    {
        if ($this->postTypeRegistrar instanceof PostTypeRegistrar) {
            $this->postTypeRegistrar->registerPostType();
        }
    }

    public function isActivated()
    {
        return (bool) get_option('bokun_plugin');
    }

    public function getAdminSlugs()
    {
        return $this->adminMenu->getPageSlugs();
    }

    public function isPluginPage()
    {
        return $this->adminMenu->isPluginPage();
    }

    public function getAdminMessage($key)
    {
        return $this->adminMenu->getAdminMessage($key);
    }

    public function enqueueAdminAssets()
    {
        $this->assets->enqueueAdminAssets();
    }

    public function enqueueFrontAssets()
    {
        $this->assets->enqueueFrontAssets();
    }

    public function registerBookingScripts()
    {
        $this->assets->registerBookingScripts();
    }

    public function renderPage()
    {
        $this->adminMenu->renderPage();
    }

    public function writeLog($content = '', $fileName = 'bokun_log.txt')
    {
        $file        = __DIR__ . '/log/' . $fileName;
        $fileContent = '=============== Write At => ' . date('y-m-d H:i:s') . " =============== \r\n";
        $fileContent .= $content . "\r\n\r\n";

        file_put_contents($file, $fileContent, FILE_APPEND | LOCK_EX);
    }

    public function bokun_get_sub_menu()
    {
        return $this->getSubmenuItems();
    }

    public function bokun_add_menu()
    {
        $this->registerMenu();
    }

    public function bokun_register_custom_post_type()
    {
        $this->registerBookingPostType();
    }

    public function bokun_is_activate()
    {
        return $this->isActivated();
    }

    public function bokun_admin_slugs()
    {
        return $this->getAdminSlugs();
    }

    public function bokun_is_page()
    {
        return $this->isPluginPage();
    }

    public function bokun_admin_msg($key)
    {
        return $this->getAdminMessage($key);
    }

    public function bokun_enqueue_scripts()
    {
        $this->enqueueAdminAssets();
    }

    public function bokun_front_enqueue_scripts()
    {
        $this->enqueueFrontAssets();
    }

    public function bokun_register_all_scripts()
    {
        $this->registerBookingScripts();
    }

    public function bokun_route()
    {
        $this->renderPage();
    }

    public function bokun_write_log($content = '', $fileName = 'bokun_log.txt')
    {
        $this->writeLog($content, $fileName);
    }
}
$bokunContainer = new \Bokun\Bookings\Infrastructure\Container();
$plugin = new \Bokun\Bookings\Plugin(__FILE__, $bokunContainer);
$plugin->boot();
