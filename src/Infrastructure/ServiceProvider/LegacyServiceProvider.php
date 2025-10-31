<?php

namespace Bokun\Bookings\Infrastructure\ServiceProvider;

use Bokun\Bookings\Infrastructure\Container;
use Bokun\Bookings\Infrastructure\ServiceProviderInterface;

class LegacyServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container)
    {
        if (! $container->has('bokun.manager')) {
            $container->singleton('bokun.manager', function () {
                return new \BokunBookingManagement();
            });
        }

        if (! $container->has('bokun.settings')) {
            $container->singleton('bokun.settings', function () {
                $settings = new \Bokun\Bookings\Admin\Settings\SettingsController();
                $this->setGlobal('bokun_settings', $settings);

                return $settings;
            });
        }

        if (! $container->has('bokun.shortcode')) {
            $container->singleton('bokun.shortcode', function () {
                $shortcode = new \Bokun\Bookings\Presentation\Shortcode\BookingShortcode();
                $this->setGlobal('bokun_shortcode', $shortcode);

                return $shortcode;
            });
        }
    }

    private function setGlobal($key, $value)
    {
        $GLOBALS[$key] = $value;
    }
}
