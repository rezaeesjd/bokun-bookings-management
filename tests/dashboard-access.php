<?php
if ('cli' !== PHP_SAPI) {
    exit;
}

// The whole dashboard is now private (predefined-key login). This suite loads
// the real access module and asserts the role-gated model end to end, plus the
// unchanged de-index / no-cache privacy behavior.
define('BOKUN_ACCESS_TEST', true);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('BOKUN_CSS_URL', '');
define('BOKUN_IMAGES_URL', '');
$bokun_version = 'test';

$checks = array();
$access = array('logged_in' => false, 'capabilities' => array(), 'singular' => 'page', 'page_id' => 100, 'dashboard_page_id' => 0, 'content' => '', 'queries' => 0, 'cache_headers' => 0, 'message_renders' => 0);
$hooks = array();
$GLOBALS['opt'] = array();

function verify_dashboard_case($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $checks[] = $label;
}

function add_shortcode($name, $callback) {}
function add_filter($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function add_action($name, $callback = null, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name] = $callback; }
function apply_filters($name, $value) { return $value; }
function is_user_logged_in() { return $GLOBALS['access']['logged_in']; }
function current_user_can($capability) { return in_array($capability, $GLOBALS['access']['capabilities'], true); }
function nocache_headers() { $GLOBALS['access']['cache_headers']++; }
function absint($value) { return abs((int) $value); }
function is_singular($type = null) { return $type ? $GLOBALS['access']['singular'] === $type : in_array($GLOBALS['access']['singular'], array('page', 'bokun_booking'), true); }
function get_option($name, $default = false) {
    if ('bokun_dashboard_page_id' === $name) {
        return $GLOBALS['access']['dashboard_page_id'];
    }
    return array_key_exists($name, $GLOBALS['opt']) ? $GLOBALS['opt'][$name] : $default;
}
function update_option($name, $value, $autoload = null) { $GLOBALS['opt'][$name] = $value; return true; }
function get_queried_object_id() { return $GLOBALS['access']['page_id']; }
function get_post() { return new WP_Post($GLOBALS['access']['content']); }
function has_shortcode($content, $shortcode) { return 1 === preg_match('/\[' . preg_quote($shortcode, '/') . '(?:\s|\])/', $content); }
function post_type_exists($type) { return 'bokun_booking' === $type; }
function shortcode_atts($defaults, $values, $shortcode = '') { return array_merge($defaults, $values); }
function wp_get_current_user() { return new WP_User(); }
function get_current_user_id() { return $GLOBALS['access']['logged_in'] ? 1 : 0; }
function current_time($format, $gmt = false) { return 'timestamp' === $format ? time() : gmdate('Y-m-d H:i:s'); }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function esc_attr_e($text, $domain = '') { echo esc_html($text); }
function esc_js($text) { return addslashes((string) $text); }
function wp_rand($minimum, $maximum) { return $minimum; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value)); }
function sanitize_title($value) { return sanitize_key(str_replace(' ', '-', $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_unslash($value) { return $value; }
function wp_salt($scheme = 'auth') { return 'dashboard-test-salt-' . $scheme; }
function is_ssl() { return false; }
function wp_enqueue_style($handle = null, $src = null, $deps = array(), $ver = null) {}
function home_url($path = '') { return 'http://example.test' . $path; }
function add_query_arg($args = array(), $b = null, $c = null) { return '/dashboard'; }
function remove_query_arg($keys, $url = '') { return $url; }
function bokun_render_fetch_status_note() { return 'STAFF_IMPORT_STATUS'; }

class WP_Post {
    public $post_content;
    public function __construct($content) { $this->post_content = $content; }
}

class WP_User {
    public $display_name = 'Test Admin';
    public $user_login = 'test-admin';
    public function exists() { return is_user_logged_in(); }
}

class WP_Query {
    public $posts = array();
    public function __construct($arguments) { $GLOBALS['access']['queries']++; }
    public function have_posts() { return false; }
}

class BOKUN_Messages {
    public static function can_manage() {
        return function_exists('bokun_access_can')
            ? bokun_access_can('manage_messages')
            : (is_user_logged_in() && current_user_can('manage_options'));
    }
    public static function render() { $GLOBALS['access']['message_renders']++; return 'CANONICAL_MESSAGES'; }
}

require dirname(__DIR__) . '/includes/bokun-access.php';
require dirname(__DIR__) . '/includes/bokun_shortcode.class.php';
$shortcodes = $GLOBALS['bokun_shortcode'];
verify_dashboard_case(isset($hooks['wp_robots'], $hooks['template_redirect']), 'booking privacy hooks are registered');

/** Establish the current actor: a key user id (or null) and optional WP caps. */
function set_actor($id, $caps = array()) {
    unset($_COOKIE[BOKUN_ACCESS_COOKIE]);
    $GLOBALS['access']['logged_in'] = !empty($caps);
    $GLOBALS['access']['capabilities'] = $caps;
    if ($id) {
        bokun_access_set_cookie($id);
    }
    bokun_access_flush();
}

// --- The public: the whole dashboard is private now. ----------------------
set_actor(null);
$public = $shortcodes->render_booking_dashboard();
verify_dashboard_case(str_contains($public, 'Dashboard sign in') && !str_contains($public, 'No upcoming bookings'), 'the public gets the key login screen, not the bookings view');
verify_dashboard_case('' === $shortcodes->render_analytics_panel() && '' === $shortcodes->render_business_panel(), 'the public cannot render analytics or business results');
verify_dashboard_case('' === $shortcodes->function_bokun_fetch_button(), 'the public cannot render import controls');
verify_dashboard_case(str_contains($shortcodes->render_booking_history_table(array('capability' => '')), 'authorized staff account'), 'the public cannot view booking history');

// --- Generic key (SJD): read-only bookings, nothing else. -----------------
set_actor('sjd');
$generic = $shortcodes->render_booking_dashboard();
verify_dashboard_case(str_contains($generic, 'No upcoming bookings') && str_contains($generic, 'Signed in as'), 'the generic key sees the bookings view with an account bar');
verify_dashboard_case(str_contains($generic, 'SJD'), 'the account identity shows the mapped key user name, not Guest');
verify_dashboard_case(!str_contains($generic, 'STAFF_IMPORT_STATUS') && !str_contains($generic, 'data-tab-target="analytics"') && !str_contains($generic, 'data-tab-target="messages"'), 'the generic key hides import, analytics and messages');
verify_dashboard_case('' === $shortcodes->function_bokun_fetch_button() && '' === $shortcodes->render_analytics_panel(), 'the generic key cannot import or view analytics');
verify_dashboard_case(str_contains($generic, 'Sign out'), 'the generic key gets a sign-out control');

// --- Booking key (Johnna): All Booking data and actions (no Analytics). ----
set_actor('johnna');
$before_messages = $access['message_renders'];
$booking = $shortcodes->render_booking_dashboard();
verify_dashboard_case(str_contains($booking, 'STAFF_IMPORT_STATUS'), 'the booking key sees staff import status');
verify_dashboard_case(str_contains($booking, 'data-tab-target="messages"') && str_contains($booking, 'CANONICAL_MESSAGES'), 'the booking key sees the Messages tab');
verify_dashboard_case(!str_contains($booking, 'data-tab-target="analytics"'), 'the booking key cannot see the Analytics tab');
verify_dashboard_case('' === $shortcodes->render_analytics_panel() && '' === $shortcodes->render_business_panel(), 'the booking key cannot render analytics or business results directly');
verify_dashboard_case('' !== $shortcodes->function_bokun_fetch_button(), 'the booking key can render import controls');
verify_dashboard_case($access['message_renders'] > $before_messages, 'the booking key renders the Messages surface');

// --- Admin key (Bardi): everything. ---------------------------------------
set_actor('bardi');
$admin = $shortcodes->render_booking_dashboard();
verify_dashboard_case(str_contains($admin, 'data-tab-target="analytics"') && str_contains($admin, 'data-tab-target="messages"') && str_contains($admin, 'CANONICAL_MESSAGES'), 'the admin key sees analytics and messages tabs');
verify_dashboard_case('' !== $shortcodes->render_analytics_panel(), 'the admin key can render the analytics panel');

// --- WordPress administrator failsafe: full access without a key. ---------
set_actor(null, array('read', 'manage_options'));
$wpadmin = $shortcodes->render_booking_dashboard();
verify_dashboard_case(str_contains($wpadmin, 'data-tab-target="analytics"') && str_contains($wpadmin, 'CANONICAL_MESSAGES'), 'a WordPress administrator keeps full access as a failsafe');
verify_dashboard_case(str_contains($wpadmin, 'WordPress session') && !str_contains($wpadmin, 'Sign out'), 'the WordPress failsafe shows a session note, not a key sign-out');

// --- A WordPress editor without a key is key-driven: locked out. -----------
set_actor(null, array('read', 'edit_others_posts'));
$editor = $shortcodes->render_booking_dashboard();
verify_dashboard_case(str_contains($editor, 'Dashboard sign in'), 'a WordPress editor without a key gets the login screen');

// --- Tab chrome assembly via the actor role. ------------------------------
$wrap = new ReflectionMethod(BOKUN_Shortcode::class, 'wrap_dashboard_tabs');
set_actor('johnna');
$messages_only = $wrap->invoke($shortcodes, 'BOOKINGS', '');
verify_dashboard_case(str_contains($messages_only, 'CANONICAL_MESSAGES') && !str_contains($messages_only, 'data-tab-target="analytics"'), 'booking role: empty analytics does not hide the Messages tab');
set_actor('sjd');
verify_dashboard_case('BOOKINGS' === $wrap->invoke($shortcodes, 'BOOKINGS', ''), 'generic role with no secondary panel needs no tab chrome');

// --- De-index / no-cache privacy (unchanged, actor-independent). -----------
$robots = array('index' => true, 'follow' => true, 'max-image-preview' => 'large');
foreach (array('bokun_booking_dashboard', 'bokun_booking_history', 'bokun_messages', 'bokun_fetch_button') as $shortcode) {
    $access['content'] = '[' . $shortcode . ']';
    $result = $shortcodes->filter_booking_robots($robots);
    verify_dashboard_case(!isset($result['index'], $result['follow']) && !empty($result['noindex']) && !empty($result['nofollow']), $shortcode . ' pages are excluded from indexing and following');
    verify_dashboard_case('large' === $result['max-image-preview'], $shortcode . ' privacy preserves unrelated robots directives');
}
$access['content'] = '';
$access['dashboard_page_id'] = 100;
verify_dashboard_case(!empty($shortcodes->filter_booking_robots($robots)['noindex']), 'configured auto-appended dashboard is noindex even without an explicit shortcode');
$access['dashboard_page_id'] = 0;
$access['singular'] = 'bokun_booking';
verify_dashboard_case(!empty($shortcodes->filter_booking_robots($robots)['noindex']), 'single booking operational links are noindex without changing their access rules');
$access['singular'] = 'page';
verify_dashboard_case($robots === $shortcodes->filter_booking_robots($robots), 'unrelated public pages retain their robots directives');
$access['content'] = '[bokun_booking_dashboard]';
$before_headers = $access['cache_headers'];
$shortcodes->protect_booking_page_cache();
verify_dashboard_case(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE && $access['cache_headers'] > $before_headers, 'sensitive page cache protection runs before rendering');

foreach ($checks as $label) {
    echo 'PASS ' . $label . "\n";
}
echo 'Completed ' . count($checks) . " dashboard access and privacy checks.\n";
