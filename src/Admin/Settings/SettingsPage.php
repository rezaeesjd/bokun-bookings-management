<?php

namespace Bokun\Bookings\Admin\Settings;

use Bokun\Bookings\Admin\Menu\AdminPageInterface;

class SettingsPage implements AdminPageInterface
{
    /**
     * @var SettingsController
     */
    private $controller;

    /**
     * @var string
     */
    private $slug;

    /**
     * @var string
     */
    private $title;

    /**
     * @var string
     */
    private $capability;

    public function __construct(SettingsController $controller, $slug = 'bokun_settings', $title = '', $capability = 'manage_options')
    {
        $this->controller = $controller;
        $this->slug = (string) $slug;
        $this->title = $title !== '' ? $title : __('Settings', BOKUN_TEXT_DOMAIN);
        $this->capability = $capability !== '' ? $capability : 'manage_options';
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    public function render(): void
    {
        if (! current_user_can($this->capability)) {
            wp_die(esc_html__('You do not have permission to access this page.', BOKUN_TEXT_DOMAIN));
        }

        if (method_exists($this->controller, 'displaySettingsPage')) {
            $this->controller->displaySettingsPage();
            return;
        }

        if (method_exists($this->controller, 'bokun_display_settings')) {
            $this->controller->bokun_display_settings();
        }
    }
}
