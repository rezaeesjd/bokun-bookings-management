<?php
namespace Bokun\Bookings\Admin\Localization;

if (! defined('ABSPATH')) {
    exit;
}

class LocalizationLoader
{
    /**
     * Register the plugin text domain loader.
     */
    public function register()
    {
        add_action('plugins_loaded', [$this, 'loadTextDomain']);
    }

    /**
     * Load the translation files for the plugin.
     */
    public function loadTextDomain()
    {
        $relativePath = trim(BOKUN_PLUGIN, '/');

        if ('' !== $relativePath) {
            $relativePath .= '/languages';
        } else {
            $relativePath = basename(dirname(__FILE__, 4)) . '/languages';
        }

        load_plugin_textdomain(BOKUN_txt_domain, false, $relativePath);
        do_action('bokun_txt_domain');
    }
}
