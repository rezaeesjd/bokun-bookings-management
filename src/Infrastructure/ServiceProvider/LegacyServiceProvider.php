<?php

namespace Bokun\Bookings\Infrastructure\ServiceProvider;

use Bokun\Bookings\Admin\Assets\AdminAssets;
use Bokun\Bookings\Admin\Localization\LocalizationLoader;
use Bokun\Bookings\Admin\Menu\AdminMenu;
use Bokun\Bookings\Infrastructure\Config\SettingsRepository;
use Bokun\Bookings\Infrastructure\Container;
use Bokun\Bookings\Infrastructure\ServiceProviderInterface;
use Bokun\Bookings\Registration\PostTypeRegistrar;
use Bokun\Bookings\Registration\TaxonomyRegistrar;

class LegacyServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Container $container)
    {
        if (! $container->has('bokun.settings_repository')) {
            $container->singleton('bokun.settings_repository', function () {
                return new SettingsRepository();
            });
        }

        if (! $container->has('bokun.admin_menu')) {
            $container->singleton('bokun.admin_menu', function () {
                return new AdminMenu();
            });
        }

        if (! $container->has('bokun.assets')) {
            $container->singleton('bokun.assets', function (Container $container) {
                return new AdminAssets($container->get('bokun.admin_menu'));
            });
        }

        if (! $container->has('bokun.post_type_registrar')) {
            $container->singleton('bokun.post_type_registrar', function () {
                return new PostTypeRegistrar();
            });
        }

        if (! $container->has('bokun.taxonomy_registrar')) {
            $container->singleton('bokun.taxonomy_registrar', function () {
                return new TaxonomyRegistrar();
            });
        }

        if (! $container->has('bokun.localization_loader')) {
            $container->singleton('bokun.localization_loader', function () {
                return new LocalizationLoader();
            });
        }

        if (! $container->has('bokun.manager')) {
            $container->singleton('bokun.manager', function (Container $container) {
                return new \BokunBookingManagement(
                    $container->get('bokun.admin_menu'),
                    $container->get('bokun.assets'),
                    $container->get('bokun.post_type_registrar'),
                    $container->get('bokun.taxonomy_registrar'),
                    $container->get('bokun.localization_loader')
                );
            });
        }

        if (! $container->has('bokun.settings')) {
            $container->singleton('bokun.settings', function (Container $container) {
                $settings = new \Bokun\Bookings\Admin\Settings\SettingsController(
                    $container->get('bokun.settings_repository')
                );
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
