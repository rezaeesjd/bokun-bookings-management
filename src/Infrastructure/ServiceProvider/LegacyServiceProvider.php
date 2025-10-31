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
                $this->includeSettingsFile();

                $settings = new \BOKUN_Settings();
                $this->setGlobal('bokun_settings', $settings);

                return $settings;
            });
        }

        if (! $container->has('bokun.shortcode')) {
            $container->singleton('bokun.shortcode', function () {
                $this->includeShortcodeFile();

                $shortcode = new \BOKUN_Shortcode();
                $this->setGlobal('bokun_shortcode', $shortcode);

                return $shortcode;
            });
        }
    }

    private function includeSettingsFile()
    {
        $this->includeWhenExists('bokun_settings.class.php');
    }

    private function includeShortcodeFile()
    {
        $this->includeWhenExists('bokun_shortcode.class.php');
    }

    private function includeWhenExists($file)
    {
        if (! defined('BOKUN_INCLUDES_DIR')) {
            return;
        }

        $path = rtrim(BOKUN_INCLUDES_DIR, '/\\') . '/' . ltrim($file, '/\\');

        if (file_exists($path)) {
            global $bokun_container_bootstrapping;
            $previous = isset($bokun_container_bootstrapping) ? (bool) $bokun_container_bootstrapping : false;
            $bokun_container_bootstrapping = true;

            include_once $path;

            $bokun_container_bootstrapping = $previous;
        }
    }

    private function setGlobal($key, $value)
    {
        $GLOBALS[$key] = $value;
    }
}
