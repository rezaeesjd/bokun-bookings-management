<?php

namespace Bokun\Bookings\Admin\Menu;

class AdminMenu
{
    /**
     * @var array<string, AdminPageInterface>
     */
    private $pages = [];

    /**
     * @var string
     */
    private $mainSlug;

    /**
     * @param array<int, AdminPageInterface> $pages
     * @param string                         $mainSlug
     */
    public function __construct(array $pages = [], $mainSlug = 'edit.php?post_type=bokun_booking')
    {
        $this->mainSlug = $mainSlug;

        foreach ($pages as $page) {
            if ($page instanceof AdminPageInterface) {
                $this->addPage($page);
            }
        }
    }

    public function addPage(AdminPageInterface $page): void
    {
        $this->pages[$page->getSlug()] = $page;
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
        foreach ($this->pages as $page) {
            add_submenu_page(
                $this->mainSlug,
                $page->getTitle(),
                $page->getTitle(),
                $page->getCapability(),
                $page->getSlug(),
                [$page, 'render']
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

        $controller = $this->resolvePage($page);

        if ($controller instanceof AdminPageInterface) {
            $controller->render();
        }
    }

    /**
     * Retrieve the configured submenu definitions.
     *
     * @return array<int, array<string, string>>
     */
    public function getSubmenuItems(): array
    {
        $items = [];

        foreach ($this->pages as $page) {
            $items[] = [
                'name' => $page->getTitle(),
                'cap'  => $page->getCapability(),
                'slug' => $page->getSlug(),
            ];
        }

        return $items;
    }

    /**
     * Retrieve the admin page slugs handled by the menu.
     *
     * @return array<int, string>
     */
    public function getPageSlugs(): array
    {
        return array_keys($this->pages);
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
            'no_tax' => __('No matching tax rates found.', BOKUN_TEXT_DOMAIN),
        ];

        if ('script' === $key) {
            $script  = '<script type="text/javascript">';
            $script .= 'var bokun_msg = ' . wp_json_encode($messages);
            $script .= '</script>';

            return $script;
        }

        return isset($messages[$key]) ? $messages[$key] : false;
    }

    private function resolvePage($slug): ?AdminPageInterface
    {
        if (isset($this->pages[$slug])) {
            return $this->pages[$slug];
        }

        return null;
    }
}
