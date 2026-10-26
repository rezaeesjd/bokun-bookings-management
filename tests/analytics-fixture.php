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
function admin_url($path = '') { return 'https://analytics.example.test/wp-admin/' . ltrim((string) $path, '/'); }
function wp_create_nonce($action = '') { return 'test-nonce'; }
function get_permalink($id) { return 'https://analytics.example.test/bokun-booking/' . (int) $id . '/'; }
function get_edit_post_link($id, $context = 'display') { return 'https://analytics.example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function get_post_meta($id, $key = '', $single = false) { return ''; }
function bokun_analytics_get_rows() { return $GLOBALS['fixture']['rows']; }
function bokun_partners_products_get_map() { return $GLOBALS['fixture']['partners'] ?? array(); }
// 0 (the default) means "no default date filter", so interaction checks see
// every row; a test can pass default_view_months to exercise the default view.
function bokun_analytics_get_default_view_months() { return (int) ($GLOBALS['fixture']['default_view_months'] ?? 0); }
function bokun_analytics_get_window_months() { return (int) ($GLOBALS['fixture']['window_months'] ?? 12); }
function _prime_post_caches($ids, $term_meta = true, $meta = true) {}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
// Minimal $wpdb double. Counts queries so a test can assert the records
// enrichment uses one bulk meta query, never a per-row lookup (the per-row
// path loaded every booking's meta and blanked the dashboard on large imports).
class Bokun_Test_WPDB {
    public $postmeta = 'wp_postmeta';
    public $query_count = 0;
    public $meta = array();
    public function get_results($sql, $output = null) { $this->query_count++; return $this->meta; }
}
$GLOBALS['wpdb'] = new Bokun_Test_WPDB();
$GLOBALS['wpdb']->meta = isset($GLOBALS['fixture']['wpdb_meta']) ? $GLOBALS['fixture']['wpdb_meta'] : array();
require dirname(__DIR__) . '/includes/bokun_shortcode.class.php';
echo $GLOBALS['bokun_shortcode']->render_analytics_panel();
echo "\n<!--wpdb-queries:" . (int) $GLOBALS['wpdb']->query_count . "-->";
