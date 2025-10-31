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

$autoloader = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    require_once __DIR__ . '/src/Infrastructure/Exception/ContainerException.php';
    require_once __DIR__ . '/src/Infrastructure/Exception/NotFoundException.php';
    require_once __DIR__ . '/src/Infrastructure/ServiceProviderInterface.php';
    require_once __DIR__ . '/src/Infrastructure/Container.php';
    require_once __DIR__ . '/src/Infrastructure/Config/SettingsRepository.php';
    require_once __DIR__ . '/src/Infrastructure/ServiceProvider/LegacyServiceProvider.php';
    require_once __DIR__ . '/src/Admin/Assets/AdminAssets.php';
    require_once __DIR__ . '/src/Admin/Localization/LocalizationLoader.php';
    require_once __DIR__ . '/src/Admin/Menu/AdminMenu.php';
    require_once __DIR__ . '/src/Admin/Settings/SettingsController.php';
    require_once __DIR__ . '/src/Presentation/Shortcode/BookingShortcode.php';
    require_once __DIR__ . '/src/Registration/PostTypeRegistrar.php';
    require_once __DIR__ . '/src/Registration/TaxonomyRegistrar.php';
    require_once __DIR__ . '/src/Plugin.php';
}

use Bokun\Bookings\Admin\Assets\AdminAssets;
use Bokun\Bookings\Admin\Localization\LocalizationLoader;
use Bokun\Bookings\Admin\Menu\AdminMenu;
use Bokun\Bookings\Registration\PostTypeRegistrar;
use Bokun\Bookings\Registration\TaxonomyRegistrar;
global $bokun_version;
$bokun_version = '1.0.0';

class BokunBookingManagement {
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
        $this->assets            = $assets ?: new AdminAssets($this->adminMenu);
        $this->postTypeRegistrar = $postTypeRegistrar ?: new PostTypeRegistrar();
        $this->taxonomyRegistrar = $taxonomyRegistrar ?: new TaxonomyRegistrar();
        $this->localizationLoader = $localizationLoader ?: new LocalizationLoader();

        register_activation_hook(__FILE__, [self::class, 'activate']);
        register_deactivation_hook(__FILE__, [self::class, 'deactivate']);

        $this->adminMenu->register();
        $this->assets->register();
        $this->postTypeRegistrar->register();
        $this->taxonomyRegistrar->register();
        $this->localizationLoader->register();
    }

    public static function activate()
    {
        global $wpdb, $rb, $bokun_version;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        update_option('bokun_plugin', true);
        update_option('bokun_version', $bokun_version);

        $table_name = $wpdb->prefix . 'bokun_booking_history';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NULL,
            booking_id VARCHAR(191) NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            is_checked TINYINT(1) NOT NULL DEFAULT 0,
            user_id BIGINT(20) UNSIGNED NULL,
            user_name VARCHAR(191) NULL,
            actor_source VARCHAR(50) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY booking_id (booking_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql);
    }

    public static function deactivate()
    {
        // deactivation process here
    }

    public static function bokun_install()
    {
        self::activate();
    }

    public static function bokun_deactivation()
    {
        self::deactivate();
    }

    public function getSubmenuItems()
    {
        return $this->adminMenu->getSubmenuItems();
    }

    public function registerMenu()
    {
        $this->adminMenu->registerMenu();
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
