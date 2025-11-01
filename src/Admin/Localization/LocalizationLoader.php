<?php

namespace Bokun\Bookings\Admin\Localization;

class LocalizationLoader
{
    /**
     * Register the plugin text domain loader.
     */
    public function register()
    {
        add_action('init', [$this, 'loadTextDomain']);
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

        load_plugin_textdomain(BOKUN_TEXT_DOMAIN, false, $relativePath);

        /**
         * @deprecated Backward compatibility hook.
         */
        do_action('bokun_txt_domain');
        do_action('bokun_text_domain_loaded', BOKUN_TEXT_DOMAIN);
    }
}
