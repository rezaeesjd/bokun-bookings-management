<?php
/**
 * Access-control tests for the predefined-key dashboard login.
 *
 * Runs under the PHP CLI with lightweight WordPress stubs. Covers the role
 * capability matrix, the public lockout, the signed-cookie round trip
 * (including tamper/expiry/rotation rejection), the WordPress-admin failsafe,
 * and constant-time key matching.
 */

if ('cli' !== PHP_SAPI) {
    exit;
}

define('BOKUN_ACCESS_TEST', true);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

$checks = array();

function verify_access_case($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $checks[] = $label;
}

// --- WordPress stubs -------------------------------------------------------
$GLOBALS['wp'] = array('logged_in' => false, 'caps' => array(), 'options' => array());

function add_action($hook, $cb, $priority = 10, $args = 1) {}
function apply_filters($hook, $value) {
    if ('bokun_access_lifetime' === $hook && isset($GLOBALS['test_lifetime_override'])) {
        return $GLOBALS['test_lifetime_override'];
    }
    return $value;
}
function is_user_logged_in() { return $GLOBALS['wp']['logged_in']; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['wp']['caps'], true); }
function get_current_user_id() { return $GLOBALS['wp']['logged_in'] ? 1 : 0; }
function wp_get_current_user() { return new WP_User(); }
function wp_salt($scheme = 'auth') { return 'unit-test-salt-' . $scheme; }
function wp_unslash($v) { return $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function is_ssl() { return false; }
function __($t, $d = '') { return $t; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['wp']['options']) ? $GLOBALS['wp']['options'][$name] : $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp']['options'][$name] = $value; return true; }

class WP_User {
    public $display_name = 'Bardi WP';
    public $user_login = 'bardi';
    public function exists() { return is_user_logged_in(); }
}

require dirname(__DIR__) . '/includes/bokun-access.php';

function reset_actor() {
    $GLOBALS['wp']['logged_in'] = false;
    $GLOBALS['wp']['caps'] = array();
    unset($_COOKIE[BOKUN_ACCESS_COOKIE]);
    bokun_access_flush();
}

// --- Role capability matrix ------------------------------------------------
$generic = bokun_access_role_caps('generic');
$booking = bokun_access_role_caps('booking');
$admin   = bokun_access_role_caps('admin');

verify_access_case($generic === array('view_bookings'), 'generic role can only view bookings');
verify_access_case(in_array('manage_bookings', $booking, true) && in_array('manage_messages', $booking, true), 'booking role can manage bookings and messages');
verify_access_case(!in_array('view_analytics', $booking, true), 'booking role cannot view analytics');
verify_access_case(in_array('view_analytics', $admin, true) && in_array('manage_settings', $admin, true), 'admin role can view analytics and manage settings');
verify_access_case(bokun_access_role_caps('nonsense') === array(), 'unknown role grants nothing');

// --- Seeded users ----------------------------------------------------------
$users = bokun_access_users();
verify_access_case(isset($users['bardi'], $users['johnna'], $users['sjd']), 'three default users are seeded');
verify_access_case($users['bardi']['role'] === 'admin' && $users['johnna']['role'] === 'booking' && $users['sjd']['role'] === 'generic', 'default users map to the expected roles');
verify_access_case($GLOBALS['wp']['options'][BOKUN_ACCESS_OPTION] === $users || is_array($GLOBALS['wp']['options'][BOKUN_ACCESS_OPTION]), 'seeding persists to the option');

// --- Public lockout --------------------------------------------------------
reset_actor();
verify_access_case(bokun_access_current() === null, 'no cookie and no WP login resolves to no actor');
verify_access_case(!bokun_access_can('view_bookings'), 'the public cannot view bookings');
verify_access_case(bokun_access_role() === '', 'the public has no role');

// --- Key cookie round trip (generic) --------------------------------------
reset_actor();
bokun_access_set_cookie('sjd');
$actor = bokun_access_current();
verify_access_case($actor && $actor['role'] === 'generic' && $actor['via'] === 'key', 'sjd key cookie resolves to the generic role');
verify_access_case(bokun_access_can('view_bookings'), 'generic key can view bookings');
verify_access_case(!bokun_access_can('manage_bookings') && !bokun_access_can('view_analytics'), 'generic key cannot manage bookings or view analytics');

// --- Key cookie round trip (booking) --------------------------------------
reset_actor();
bokun_access_set_cookie('johnna');
verify_access_case(bokun_access_can('manage_bookings') && bokun_access_can('manage_messages'), 'booking key can manage bookings and messages');
verify_access_case(!bokun_access_can('view_analytics'), 'booking key cannot view analytics');

// --- Key cookie round trip (admin) ----------------------------------------
reset_actor();
bokun_access_set_cookie('bardi');
verify_access_case(bokun_access_can('view_analytics') && bokun_access_can('manage_settings'), 'admin key has full access');

// --- Tamper rejection ------------------------------------------------------
reset_actor();
bokun_access_set_cookie('sjd');
$raw = $_COOKIE[BOKUN_ACCESS_COOKIE];
$parts = explode('.', $raw);
// Promote generic -> admin id while keeping the generic signature: must fail.
// Token format is id.expires.remember.sig.
$_COOKIE[BOKUN_ACCESS_COOKIE] = 'bardi.' . $parts[1] . '.' . $parts[2] . '.' . $parts[3];
bokun_access_flush();
verify_access_case(bokun_access_actor_from_cookie() === null, 'swapping the user id invalidates the signature');

reset_actor();
bokun_access_set_cookie('johnna');
$parts = explode('.', $_COOKIE[BOKUN_ACCESS_COOKIE]);
$_COOKIE[BOKUN_ACCESS_COOKIE] = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . substr($parts[3], 0, -1) . 'x';
bokun_access_flush();
verify_access_case(bokun_access_actor_from_cookie() === null, 'tampering the signature is rejected');

// --- Expiry rejection ------------------------------------------------------
reset_actor();
$users = bokun_access_users();
$expired = time() - 10;
$sig = bokun_access_sign('johnna', $expired, $users['johnna']['key']);
$_COOKIE[BOKUN_ACCESS_COOKIE] = 'johnna.' . $expired . '.' . $sig;
bokun_access_flush();
verify_access_case(bokun_access_actor_from_cookie() === null, 'an expired cookie is rejected');

// --- Key rotation invalidates existing sessions ---------------------------
reset_actor();
bokun_access_set_cookie('johnna');
$valid_cookie = $_COOKIE[BOKUN_ACCESS_COOKIE];
$config = bokun_access_users();
$config['johnna']['key'] = 'johnna-bk-rotated99';
update_option(BOKUN_ACCESS_OPTION, $config);
$_COOKIE[BOKUN_ACCESS_COOKIE] = $valid_cookie;
bokun_access_flush();
verify_access_case(bokun_access_actor_from_cookie() === null, 'rotating a key signs out existing sessions');
// restore seeded config
update_option(BOKUN_ACCESS_OPTION, bokun_access_default_users());

// --- WordPress-admin failsafe ---------------------------------------------
reset_actor();
$GLOBALS['wp']['logged_in'] = true;
$GLOBALS['wp']['caps'] = array('manage_options');
$actor = bokun_access_current();
verify_access_case($actor && $actor['role'] === 'admin' && $actor['via'] === 'wp', 'logged-in WordPress admin resolves to the admin role');
verify_access_case(bokun_access_can('view_analytics'), 'WordPress admin failsafe grants full access');

// Failsafe wins even when a lower-privilege key cookie is present.
reset_actor();
bokun_access_set_cookie('sjd');
$GLOBALS['wp']['logged_in'] = true;
$GLOBALS['wp']['caps'] = array('manage_options');
bokun_access_flush();
$actor = bokun_access_current();
verify_access_case($actor && $actor['role'] === 'admin' && $actor['via'] === 'wp', 'WordPress admin failsafe overrides a generic key cookie');

// A logged-in non-staff WP user (no caps) falls through to the key cookie.
reset_actor();
bokun_access_set_cookie('johnna');
$GLOBALS['wp']['logged_in'] = true;
$GLOBALS['wp']['caps'] = array('read');
bokun_access_flush();
$actor = bokun_access_current();
verify_access_case($actor && $actor['role'] === 'booking' && $actor['via'] === 'key', 'a non-staff WP login does not override a booking key');

// --- Constant-time key matching -------------------------------------------
reset_actor();
verify_access_case(bokun_access_match_key('johnna-bk-8b1d6395') === 'johnna', 'a correct key matches its user');
verify_access_case(bokun_access_match_key('totally-wrong') === '', 'an unknown key matches no user');
verify_access_case(bokun_access_match_key('') === '', 'an empty key matches no user');

// --- Remember-this-device session lifetime --------------------------------
reset_actor();
$users = bokun_access_users();
// Remembered device (default): 4-segment token, remember flag 1, long lifetime.
bokun_access_set_cookie('johnna', true);
$parts = explode('.', $_COOKIE[BOKUN_ACCESS_COOKIE]);
verify_access_case(count($parts) === 4 && $parts[2] === '1', 'a remembered device issues a remember=1 token');
$life = (int) $parts[1] - time();
verify_access_case($life > (29 * DAY_IN_SECONDS) && $life <= (30 * DAY_IN_SECONDS) + 5, 'a remembered device uses the ~30-day lifetime');
verify_access_case(bokun_access_actor_from_cookie()['role'] === 'booking', 'a remembered cookie still resolves its actor');

// Session cookie (remember off): remember flag 0, still valid for this session.
reset_actor();
bokun_access_set_cookie('johnna', false);
$parts = explode('.', $_COOKIE[BOKUN_ACCESS_COOKIE]);
verify_access_case($parts[2] === '0', 'an unticked "remember me" issues a remember=0 token');
verify_access_case(bokun_access_actor_from_cookie()['role'] === 'booking', 'a session cookie still resolves its actor');

// Legacy 3-segment cookie (issued before the remember flag) still validates.
reset_actor();
$legacy_exp = time() + 3600;
$legacy_sig = bokun_access_sign('johnna', $legacy_exp, $users['johnna']['key']); // remember=null
$_COOKIE[BOKUN_ACCESS_COOKIE] = 'johnna.' . $legacy_exp . '.' . $legacy_sig;
bokun_access_flush();
$legacy_actor = bokun_access_actor_from_cookie();
verify_access_case($legacy_actor && $legacy_actor['role'] === 'booking' && $legacy_actor['remember'] === 1, 'a legacy 3-segment cookie still validates and is treated as remembered');

// --- Sliding refresh keeps an active device signed in ---------------------
if (!headers_sent()) {
    // A remembered cookie more than a day into its life is re-issued with a
    // fresh (later) expiry when the refresh hook runs.
    reset_actor();
    $soon = time() + (5 * DAY_IN_SECONDS); // 25 days elapsed of a 30-day life
    $_COOKIE[BOKUN_ACCESS_COOKIE] = 'johnna.' . $soon . '.1.' . bokun_access_sign('johnna', $soon, $users['johnna']['key'], 1);
    bokun_access_flush();
    bokun_access_maybe_refresh_cookie();
    $after = (int) explode('.', $_COOKIE[BOKUN_ACCESS_COOKIE])[1];
    verify_access_case($after > $soon + DAY_IN_SECONDS, 'an active remembered device slides its expiry forward');

    // A session cookie (remember 0) is NOT slid forward.
    reset_actor();
    $sess_exp = time() + (5 * DAY_IN_SECONDS);
    $sess_cookie = 'johnna.' . $sess_exp . '.0.' . bokun_access_sign('johnna', $sess_exp, $users['johnna']['key'], 0);
    $_COOKIE[BOKUN_ACCESS_COOKIE] = $sess_cookie;
    bokun_access_flush();
    bokun_access_maybe_refresh_cookie();
    verify_access_case($_COOKIE[BOKUN_ACCESS_COOKIE] === $sess_cookie, 'a session cookie is not slid forward');

    // Short configured lifetimes (<= 1 day) still slide: the refresh interval
    // scales to half the lifetime instead of a fixed day, so an active device
    // on a 12-hour lifetime past the halfway mark is re-issued.
    reset_actor();
    $GLOBALS['test_lifetime_override'] = 12 * HOUR_IN_SECONDS;
    $short_exp = time() + 60; // 60s left of a 12h life => well past halfway
    $_COOKIE[BOKUN_ACCESS_COOKIE] = 'johnna.' . $short_exp . '.1.' . bokun_access_sign('johnna', $short_exp, $users['johnna']['key'], 1);
    bokun_access_flush();
    bokun_access_maybe_refresh_cookie();
    $short_after = (int) explode('.', $_COOKIE[BOKUN_ACCESS_COOKIE])[1];
    verify_access_case($short_after > $short_exp + HOUR_IN_SECONDS, 'a short configured lifetime still slides forward');
    unset($GLOBALS['test_lifetime_override']);
}

// --- Done ------------------------------------------------------------------
foreach ($checks as $label) {
    echo 'PASS ' . $label . "\n";
}
echo 'Completed ' . count($checks) . " access control checks.\n";
