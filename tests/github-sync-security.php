<?php
if ('cli' !== PHP_SAPI) {
    exit;
}
define('ABSPATH', __DIR__ . '/');
$options = array('bokun_github_sync_settings' => array('github_token' => 'synthetic-updater-token', 'repository_url' => 'https://github.com/rezaeesjd/bokun-bookings-management', 'autosync' => 0));
$checks = 0;
function add_filter(...$arguments) {}
function add_action(...$arguments) {}
function plugin_basename($path) { return 'bokun-bookings-management/bokun-bookings-management.php'; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function delete_transient($key) {}
function wp_parse_args($values, $defaults) { return array_merge($defaults, $values); }
function wp_parse_url($url) { return parse_url($url); }
function get_bloginfo($key) { return 'test'; }
function home_url() { return 'https://example.test'; }
function is_plugin_active($plugin) { return true; }
function activate_plugin(...$arguments) { throw new RuntimeException('No activation is expected in this test.'); }
function __($text, $domain = '') { return $text; }
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function verify_sync($condition, $label) {
    if (!$condition) { throw new RuntimeException('FAILED: ' . $label); }
    $GLOBALS['checks']++;
}
require dirname(__DIR__) . '/includes/class-bokun-github-sync.php';
$sync = new Bokun_Github_Sync(__FILE__);
$repository = 'rezaeesjd/bokun-bookings-management';
foreach (array('https://api.github.com/repos/' . $repository . '/zipball/main', 'https://raw.githubusercontent.com/' . $repository . '/main/readme.md', 'https://codeload.github.com/' . $repository . '/legacy.zip/main') as $url) {
    verify_sync('Bearer synthetic-updater-token' === $sync->authorize_github_download(array(), $url)['headers']['Authorization'], 'authorized repository download receives its credential');
}
foreach (array('https://attacker.test/?host=raw.githubusercontent.com', 'https://raw.githubusercontent.com.attacker.test/' . $repository, 'http://api.github.com/repos/' . $repository, 'https://api.github.com:444/repos/' . $repository, 'https://user@api.github.com/repos/' . $repository, 'https://api.github.com/repos/other/repo', 'https://api.github.com/repos/' . $repository . '-lookalike/zipball/main', 'https://github.com/' . $repository, 'https://codeload.github.com/other/repo/main', 'not a URL') as $url) {
    verify_sync(!isset($sync->authorize_github_download(array(), $url)['headers']['Authorization']), 'untrusted destination never receives a credential');
}
$headers = array('headers' => array('authorization' => 'existing-auth'));
verify_sync($headers === $sync->authorize_github_download($headers, 'https://api.github.com/repos/' . $repository), 'existing case-insensitive authorization is preserved');
verify_sync(array('headers' => 'existing') === $sync->authorize_github_download(array('headers' => 'existing'), 'https://api.github.com/repos/' . $repository), 'non-array headers are not overwritten');
$parse = new ReflectionMethod(Bokun_Github_Sync::class, 'parse_repository_url');
foreach (array('https://github.com.attacker.test/owner/repo', 'http://github.com/owner/repo', 'https://github.com/owner/repo/extra', 'https://user@github.com/owner/repo', 'https://github.com/owner/repo?token=hidden') as $url) {
    verify_sync(is_wp_error($parse->invoke($sync, $url)), 'invalid repository identity is rejected');
}
$plugin = array('plugin' => 'bokun-bookings-management/bokun-bookings-management.php');
$complete = array('action' => 'update', 'type' => 'plugin', 'plugin' => $plugin['plugin']);
$sha = str_repeat('a', 40);
$newer = str_repeat('b', 40);
$package = 'https://api.github.com/repos/' . $repository . '/zipball/' . $sha;
$options[Bokun_Github_Sync::INSTALLED_SHA_KEY] = 'previous';
$options[Bokun_Github_Sync::PENDING_SHA_KEY] = $newer;
$sync->after_update(new stdClass(), $complete);
verify_sync('previous' === $options[Bokun_Github_Sync::INSTALLED_SHA_KEY], 'pending metadata alone is not an installation receipt');
$sync->capture_upgrade_package(false, $package, new stdClass(), $plugin);
$sync->capture_upgrade_result(new WP_Error(), $plugin);
$sync->after_update(new stdClass(), $complete);
verify_sync('previous' === $options[Bokun_Github_Sync::INSTALLED_SHA_KEY], 'failed installation cannot promote a commit');
$sync->capture_upgrade_package(false, $package, new stdClass(), $plugin);
$sync->capture_upgrade_result(array('destination_name' => 'other-plugin'), $plugin);
$sync->after_update(new stdClass(), $complete);
verify_sync('previous' === $options[Bokun_Github_Sync::INSTALLED_SHA_KEY], 'wrong destination cannot promote a commit');
$sync->capture_upgrade_package(false, str_replace($sha, 'main', $package), new stdClass(), $plugin);
$sync->capture_upgrade_result(array('destination_name' => 'bokun-bookings-management'), $plugin);
$sync->after_update(new stdClass(), $complete);
verify_sync('previous' === $options[Bokun_Github_Sync::INSTALLED_SHA_KEY], 'mutable branch archives cannot create immutable receipts');
$sync->capture_upgrade_package(false, $package, new stdClass(), $plugin);
$sync->capture_upgrade_result(array('destination_name' => 'bokun-bookings-management'), $plugin);
$sync->after_update(new stdClass(), $complete);
verify_sync($sha === $options[Bokun_Github_Sync::INSTALLED_SHA_KEY], 'successful package records the captured exact commit');
verify_sync($newer === $options[Bokun_Github_Sync::PENDING_SHA_KEY], 'interleaved newer pending commit is not misreported or erased');
$options[Bokun_Github_Sync::PENDING_SHA_KEY] = $sha;
$sync->capture_upgrade_package(false, $package, new stdClass(), $plugin);
$sync->capture_upgrade_result(array('destination_name' => 'bokun-bookings-management'), $plugin);
$sync->after_update(new stdClass(), array('action' => 'update', 'type' => 'plugin', 'bulk' => true, 'plugins' => array($plugin['plugin'], 'other/plugin.php')));
verify_sync(!isset($options[Bokun_Github_Sync::PENDING_SHA_KEY]), 'bulk completion clears only its successfully installed matching commit');
echo "Completed $checks GitHub updater security and receipt checks.\n";
