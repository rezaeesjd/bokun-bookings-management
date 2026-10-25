<?php
// Render the real Analytics panel using synthetic stdin data and WP doubles.
if ('cli' !== PHP_SAPI) {
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
function add_shortcode(...$args) {}
function add_filter(...$args) {}
function add_action(...$args) {}
function is_user_logged_in() { return true; }
function current_user_can($capability) { return true; }
function nocache_headers() {}
function __($value, $domain = '') { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_attr($value) { return esc_html($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_html($value); }
function esc_js($value) { return addslashes($value); }
function wp_json_encode($value) { return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP); }
function wp_rand($min, $max) { return $min; }
function bokun_analytics_get_rows() { return $GLOBALS['fixture']['rows']; }
function bokun_partners_products_get_map() { return $GLOBALS['fixture']['partners'] ?? array(); }
require dirname(__DIR__) . '/includes/bokun_shortcode.class.php';
echo $GLOBALS['bokun_shortcode']->render_analytics_panel();
