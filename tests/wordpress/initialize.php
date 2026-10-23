<?php
if ('cli' !== PHP_SAPI) {
    exit;
}
define('WP_INSTALLING', true);
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_true');
if (!is_blog_installed()) {
    wp_install('Booking Messages CI', 'messages_admin', 'admin@example.test', false, '', bin2hex(random_bytes(24)));
}
update_option('bokun_plugin', true);
update_option('bokun_github_sync_settings', array('autosync' => 0));
update_option('active_plugins', array('bokun-bookings-management/bokun-bookings-management.php'));
require_once WP_PLUGIN_DIR . '/bokun-bookings-management/bokun-bookings-management.php';
BokunBookingManagement::bokun_install();
BOKUN_Messages::install();
delete_option('bokun_plugin_redirect');
echo "Disposable synthetic WordPress fixture initialized.\n";
