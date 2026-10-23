<?php
if ('cli' !== PHP_SAPI) {
    exit;
}
require __DIR__ . '/bootstrap.php';
wp_set_current_user(get_user_by('login', 'messages_admin')->ID);
BOKUN_Messages::render();
global $bokun_version, $wp_scripts, $wp_styles;
foreach (array($wp_scripts, $wp_styles) as $registry) {
    if (!$bokun_version || $bokun_version !== $registry->registered['bokun-messages']->ver) {
        throw new RuntimeException('Messages assets must use the installed plugin version.');
    }
}
echo "Completed 2 message asset cache-version checks.\n";
