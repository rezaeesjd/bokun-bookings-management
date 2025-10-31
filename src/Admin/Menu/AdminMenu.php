<?php
namespace Bokun\Bookings\Admin\Menu;

use Bokun\Bookings\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

class AdminMenu
{
    /**
     * @var string
     */
    private $settingsSlug;

    /**
     * @var string
     */
    private $bookingHistorySlug;

    /**
     * @param string $settingsSlug
     * @param string $bookingHistorySlug
     */
    public function __construct($settingsSlug = 'bokun_settings', $bookingHistorySlug = 'bokun_booking_history')
    {
        $this->settingsSlug = $settingsSlug;
        $this->bookingHistorySlug = $bookingHistorySlug;
    }

    /**
     * Register WordPress hooks for the admin menu.
     */
    public function register()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
    }

    /**
     * Register submenu pages under the Bokun booking post type menu.
     */
    public function registerMenu()
    {
        $mainSlug = 'edit.php?post_type=bokun_booking';

        foreach ($this->getSubmenuItems() as $submenu) {
            add_submenu_page(
                $mainSlug,
                $submenu['name'],
                $submenu['name'],
                $submenu['cap'],
                $submenu['slug'],
                [$this, 'renderPage']
            );
        }
    }

    /**
     * Determine if the current request targets one of the plugin pages.
     *
     * @return bool
     */
    public function isPluginPage()
    {
        if (! isset($_REQUEST['page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        $page = sanitize_key(wp_unslash($_REQUEST['page'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return in_array($page, $this->getPageSlugs(), true);
    }

    /**
     * Render the admin page for the requested submenu.
     */
    public function renderPage()
    {
        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        switch ($page) {
            case $this->settingsSlug:
                $settings = $this->resolveSettingsController();

                if ($settings && method_exists($settings, 'displaySettingsPage')) {
                    $settings->displaySettingsPage();
                } elseif ($settings && method_exists($settings, 'bokun_display_settings')) {
                    $settings->bokun_display_settings();
                }
                break;
            case $this->bookingHistorySlug:
                $view = trailingslashit(BOKUN_INCLUDES_DIR) . 'bokun_booking_history.view.php';

                if (file_exists($view)) {
                    include_once $view;
                }
                break;
        }
    }

    /**
     * Retrieve the configured submenu definitions.
     *
     * @return array<int, array<string, string>>
     */
    public function getSubmenuItems()
    {
        return [
            [
                'name' => __('Settings', 'BOKUN_txt_domain'),
                'cap'  => 'manage_options',
                'slug' => $this->settingsSlug,
            ],
            [
                'name' => __('Booking History', 'BOKUN_txt_domain'),
                'cap'  => 'manage_options',
                'slug' => $this->bookingHistorySlug,
            ],
        ];
    }

    /**
     * Retrieve the admin page slugs handled by the menu.
     *
     * @return array<int, string>
     */
    public function getPageSlugs()
    {
        return [$this->settingsSlug, $this->bookingHistorySlug];
    }

    /**
     * Optional helper for exposing translated admin messages to JavaScript.
     *
     * @param string $key
     *
     * @return string|false
     */
    public function getAdminMessage($key)
    {
        $messages = [
            'no_tax' => __('No matching tax rates found.', 'BOKUN_txt_domain'),
        ];

        if ('script' === $key) {
            $script  = '<script type="text/javascript">';
            $script .= 'var bokun_msg = ' . wp_json_encode($messages);
            $script .= '</script>';

            return $script;
        }

        return isset($messages[$key]) ? $messages[$key] : false;
    }

    private function resolveSettingsController()
    {
        $container = Plugin::getContainerInstance();

        if ($container && $container->has('bokun.settings')) {
            try {
                return $container->get('bokun.settings');
            } catch (\Throwable $exception) {
                return null;
            }
        }

        return null;
    }
}
