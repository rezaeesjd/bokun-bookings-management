<?php
if ('cli' !== PHP_SAPI) {
    exit;
}
$checks = array();
$access = array('logged_in' => false, 'capabilities' => array(), 'singular' => 'page', 'page_id' => 100, 'dashboard_page_id' => 0, 'content' => '', 'queries' => 0, 'cache_headers' => 0, 'message_renders' => 0);
$hooks = array();

function verify_dashboard_case($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $checks[] = $label;
}

function add_shortcode($name, $callback) {}
function add_filter($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function add_action($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function is_user_logged_in() { return $GLOBALS['access']['logged_in']; }
function current_user_can($capability) { return in_array($capability, $GLOBALS['access']['capabilities'], true); }
function nocache_headers() { $GLOBALS['access']['cache_headers']++; }
function absint($value) { return abs((int) $value); }
function is_singular($type = null) { return $type ? $GLOBALS['access']['singular'] === $type : in_array($GLOBALS['access']['singular'], array('page', 'bokun_booking'), true); }
function get_option($name, $default = false) { return 'bokun_dashboard_page_id' === $name ? $GLOBALS['access']['dashboard_page_id'] : $default; }
function get_queried_object_id() { return $GLOBALS['access']['page_id']; }
function get_post() { return new WP_Post($GLOBALS['access']['content']); }
function has_shortcode($content, $shortcode) { return 1 === preg_match('/\[' . preg_quote($shortcode, '/') . '(?:\s|\])/', $content); }
function post_type_exists($type) { return 'bokun_booking' === $type; }
function shortcode_atts($defaults, $values, $shortcode = '') { return array_merge($defaults, $values); }
function wp_get_current_user() { return new WP_User(); }
function current_time($format, $gmt = false) { return 'timestamp' === $format ? time() : gmdate('Y-m-d H:i:s'); }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function esc_attr_e($text, $domain = '') { echo esc_html($text); }
function esc_js($text) { return addslashes((string) $text); }
function wp_rand($minimum, $maximum) { return $minimum; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value)); }
function sanitize_title($value) { return sanitize_key(str_replace(' ', '-', $value)); }
function bokun_render_fetch_status_note() { return 'STAFF_IMPORT_STATUS'; }

class WP_Post {
    public $post_content;
    public function __construct($content) { $this->post_content = $content; }
}

class WP_User {
    public $display_name = 'Test Staff';
    public $user_login = 'test-staff';
    public function exists() { return is_user_logged_in(); }
}

class WP_Query {
    public $posts = array();
    public function __construct($arguments) { $GLOBALS['access']['queries']++; }
    public function have_posts() { return false; }
}

class BOKUN_Messages {
    public static function can_manage() { return is_user_logged_in() && current_user_can('manage_options'); }
    public static function render() { $GLOBALS['access']['message_renders']++; return 'CANONICAL_MESSAGES'; }
}

require dirname(__DIR__) . '/includes/bokun_shortcode.class.php';
$shortcodes = $GLOBALS['bokun_shortcode'];
verify_dashboard_case(isset($hooks['wp_robots'], $hooks['template_redirect']), 'booking privacy hooks are registered');

foreach (array('anonymous' => array(false, array()), 'subscriber' => array(true, array('read')), 'contributor' => array(true, array('read', 'edit_posts')), 'author' => array(true, array('read', 'edit_posts', 'publish_posts'))) as $role => $permissions) {
    $access['logged_in'] = $permissions[0];
    $access['capabilities'] = $permissions[1];
    $before_queries = $access['queries'];
    $before_messages = $access['message_renders'];
    $dashboard = $shortcodes->render_booking_dashboard();
    verify_dashboard_case(str_contains($dashboard, 'authorized staff account') && !str_contains($dashboard, 'STAFF_IMPORT_STATUS'), $role . ' cannot render booking dashboard data');
    verify_dashboard_case('' === $shortcodes->render_analytics_panel() && '' === $shortcodes->render_business_panel(), $role . ' cannot render analytics or business results directly');
    verify_dashboard_case(str_contains($shortcodes->render_booking_history_table(array('capability' => '')), 'authorized staff account'), $role . ' cannot bypass booking history with an empty capability');
    verify_dashboard_case('' === $shortcodes->function_bokun_fetch_button(), $role . ' cannot render import controls');
    verify_dashboard_case($before_queries === $access['queries'] && $before_messages === $access['message_renders'], $role . ' denial happens before booking queries or message rendering');
}

$access['logged_in'] = true;
$access['capabilities'] = array('read', 'edit_others_posts');
$editor_dashboard = $shortcodes->render_booking_dashboard();
verify_dashboard_case(str_contains($editor_dashboard, 'No upcoming bookings') && str_contains($editor_dashboard, 'data-tab-target="analytics"'), 'Editor retains dashboard and analytics when there are no bookings');
verify_dashboard_case(!str_contains($editor_dashboard, 'data-tab-target="messages"') && !str_contains($editor_dashboard, 'CANONICAL_MESSAGES'), 'Editor cannot render the administrator-only Messages tab');
verify_dashboard_case(str_contains($shortcodes->render_booking_history_table(), 'do not have permission'), 'booking history retains its stricter default administrator capability');

$access['capabilities'] = array('read', 'manage_options');
$administrator_dashboard = $shortcodes->render_booking_dashboard();
verify_dashboard_case(str_contains($administrator_dashboard, 'No upcoming bookings') && str_contains($administrator_dashboard, 'CANONICAL_MESSAGES'), 'Administrator retains canonical Messages with an empty booking inventory');
verify_dashboard_case(str_contains($administrator_dashboard, 'data-tab-target="analytics"') && str_contains($administrator_dashboard, 'data-tab-target="messages"'), 'Administrator receives independently rendered analytics and Messages tabs');
$wrap = new ReflectionMethod(BOKUN_Shortcode::class, 'wrap_dashboard_tabs');
$messages_only = $wrap->invoke($shortcodes, 'BOOKINGS', '');
verify_dashboard_case(str_contains($messages_only, 'CANONICAL_MESSAGES') && !str_contains($messages_only, 'data-tab-target="analytics"'), 'unavailable analytics does not hide the administrator Messages tab');
$access['capabilities'] = array('read', 'edit_others_posts');
verify_dashboard_case('BOOKINGS' === $wrap->invoke($shortcodes, 'BOOKINGS', ''), 'a staff view with no permitted secondary panel needs no empty tab chrome');

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
