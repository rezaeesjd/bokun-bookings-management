<?php
if ('cli' !== PHP_SAPI) {
    exit;
}
define('DOING_AJAX', true);
$GLOBALS['wp_filter']['option_active_plugins'][1][] = array('function' => static function () { return array(); }, 'accepted_args' => 0);
require __DIR__ . '/bootstrap.php';

define('BOKUN_API_BASE_URL', 'https://api.bokun.io');
define('BOKUN_API_BOOKING_API', '/booking.json/booking-search');
define('BOKUN_DAILY_IMPORT_HOOK', 'bokun_daily_import');
define('BOKUN_INCLUDES_DIR', dirname(__DIR__, 2) . '/includes/');
require BOKUN_INCLUDES_DIR . 'bokun-webhook.php';
require BOKUN_INCLUDES_DIR . 'bokun-bookings-manager.php';
require BOKUN_INCLUDES_DIR . 'bokun_settings.class.php';

$import_test = array('checks' => array(), 'failures' => array(), 'responses' => array(), 'requests' => array(), 'lock' => '1', 'worker_lock' => '1', 'lock_acquires' => 0, 'lock_releases' => 0, 'last_status' => null);

class Import_Integration_DB {
    private $database;
    public function __construct($database) { $this->database = $database; }
    public function __get($property) { return $this->database->$property; }
    public function __isset($property) { return isset($this->database->$property); }
    public function __set($property, $value) { $this->database->$property = $value; }
    public function __call($method, $arguments) { return $this->database->$method(...$arguments); }
    public function get_var($query = null, $column = 0, $row = 0) {
        global $import_test;
        if (is_string($query) && str_contains($query, 'GET_LOCK(')) {
            $import_test['lock_acquires']++;
            return str_contains($query, 'webhook_worker') ? $import_test['worker_lock'] : $import_test['lock'];
        }
        if (is_string($query) && str_contains($query, 'RELEASE_LOCK(')) {
            $import_test['lock_releases']++;
            return '1';
        }
        return $this->database->get_var($query, $column, $row);
    }
}

class Import_Integration_Ajax_End extends RuntimeException {}

function verify_import_case($condition, $label) {
    global $import_test;
    $import_test['checks'][] = $label;
    if (!$condition) {
        $import_test['failures'][] = $label;
    }
}

function import_http_response($data, $code = 200) {
    return array('headers' => array(), 'body' => is_string($data) ? $data : wp_json_encode($data), 'response' => array('code' => $code, 'message' => 'Synthetic fixture'), 'cookies' => array());
}

function import_fixture($responses) {
    global $import_test;
    $import_test['responses'] = $responses;
    $import_test['requests'] = array();
}

function import_error_result($result) {
    return is_wp_error($result) || (is_string($result) && str_starts_with(strtolower($result), 'error'));
}

function import_ajax($callback, $parameters = array()) {
    global $import_test;
    $_POST = $parameters;
    $_REQUEST = $parameters;
    $import_test['last_status'] = null;
    ob_start();
    try {
        $callback();
    } catch (Import_Integration_Ajax_End $error) {
    } finally {
        $output = ob_get_clean();
    }
    return array('data' => json_decode($output, true), 'status' => $import_test['last_status'], 'raw' => $output);
}

function import_snapshot() {
    global $wpdb;
    return array(
        'bookings' => $wpdb->get_results("SELECT ID,post_status,post_modified FROM {$wpdb->posts} WHERE post_type='bokun_booking' ORDER BY ID", ARRAY_A),
        'terms' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy}"),
        'progress' => get_option('bokun_import_progress_api_1', null),
    );
}

$wpdb = new Import_Integration_DB($wpdb);
add_filter('pre_wp_mail', '__return_false');
add_filter('pre_http_request', static function ($previous, $arguments, $url) {
    global $import_test;
    $import_test['requests'][] = array('url' => $url, 'payload' => json_decode($arguments['body'] ?? '{}', true));
    if ($url !== BOKUN_API_BASE_URL . BOKUN_API_BOOKING_API) {
        return new WP_Error('network_disabled', 'All non-fixture requests are disabled in this isolated test.');
    }
    if (!$import_test['responses']) {
        return new WP_Error('fixture_exhausted', 'No synthetic page is configured.');
    }
    return array_shift($import_test['responses']);
}, 10, 3);
add_filter('wp_die_ajax_handler', static function () {
    return static function () { throw new Import_Integration_Ajax_End(); };
});
add_filter('status_header', static function ($header, $code) {
    $GLOBALS['import_test']['last_status'] = $code;
    return $header;
}, 10, 2);

update_option('bokun_api_credentials', array(array('api_key' => 'synthetic-access', 'secret_key' => 'synthetic-secret')), false);
delete_option('bokun_webhook_pending');
delete_option('bokun_webhook_last_result');
delete_option('bokun_webhook_last_run_start');
$admin = get_user_by('login', 'messages_admin');
if (!$admin) {
    throw new RuntimeException('Synthetic fixture administrator is missing.');
}
$fixture_suffix = strtolower(wp_generate_password(8, false));
$subscriber_id = wp_insert_user(array('user_login' => 'import_test_subscriber_' . $fixture_suffix, 'user_pass' => wp_generate_password(24), 'role' => 'subscriber', 'user_email' => 'import-subscriber-' . $fixture_suffix . '@example.test'));
$editor_id = wp_insert_user(array('user_login' => 'import_test_editor_' . $fixture_suffix, 'user_pass' => wp_generate_password(24), 'role' => 'editor', 'user_email' => 'import-editor-' . $fixture_suffix . '@example.test'));
if (is_wp_error($subscriber_id) || is_wp_error($editor_id)) {
    throw new RuntimeException('Could not create isolated role fixtures.');
}

$first = array('items' => array(array('confirmationCode' => 'SYNTHETIC-PAGE-ONE')), 'totalPages' => 2);
foreach (array(
    'transport failure' => new WP_Error('synthetic_timeout', 'Synthetic timeout'),
    'HTTP failure' => import_http_response(array('error' => 'Synthetic failure'), 503),
    'malformed JSON' => import_http_response('{invalid'),
    'missing items' => import_http_response(array('totalPages' => 2)),
    'non-array items' => import_http_response(array('items' => 'invalid')),
) as $label => $second) {
    import_fixture(array(import_http_response($first), $second));
    $result = bokun_fetch_bookings('api_1');
    verify_import_case(import_error_result($result) && 2 === count($import_test['requests']), 'page-two ' . $label . ' does not return earlier bookings as success');
}

import_fixture(array(import_http_response($first), import_http_response(array('items' => array(), 'totalPages' => 3, 'hasMore' => true))));
verify_import_case(import_error_result(bokun_fetch_bookings('api_1')), 'an empty intermediate page with more pages cannot return a partial success');
import_fixture(array(import_http_response(array('items' => array('invalid-booking-row'), 'totalPages' => 1))));
verify_import_case(import_error_result(bokun_fetch_bookings('api_1')), 'malformed booking rows cannot be returned as valid import data');
import_fixture(array(import_http_response(array('items' => array(array('confirmationCode' => 'SYNTHETIC-VALID')), 'totalPages' => 'invalid'))));
verify_import_case(import_error_result(bokun_fetch_bookings('api_1')), 'malformed pagination metadata cannot end the search as success');
foreach (array(
    'negative totalPages' => array('totalPages' => -1),
    'fractional totalPages' => array('totalPages' => 1.5),
    'non-progressing nextPage' => array('totalPages' => 2, 'nextPage' => 1, 'hasMore' => true),
    'nextPage beyond totalPages' => array('totalPages' => 2, 'nextPage' => 3),
    'contradictory hasMore' => array('totalPages' => 1, 'hasMore' => true),
    'zero nextPage with remaining totalPages' => array('totalPages' => 2, 'nextPage' => 0),
    'zero nextPage with hasMore' => array('nextPage' => 0, 'hasMore' => true),
    'nonsense hasMore' => array('hasMore' => 'nonsense'),
    'conflicting top-level and nested hasMore' => array('hasMore' => false, 'paging' => array('hasMore' => true)),
    'conflicting normalized hasMore strings' => array('hasMore' => 'false', 'paging' => array('hasMore' => 'true')),
) as $label => $paging) {
    import_fixture(array(import_http_response(array_merge(array('items' => array(array('confirmationCode' => 'SYNTHETIC-PAGING'))), $paging)), import_http_response(array('items' => array(), 'totalPages' => 0))));
    $result = bokun_fetch_bookings('api_1');
    verify_import_case(import_error_result($result) && 1 === count($import_test['requests']), $label . ' is rejected without skipping or guessing pages');
}
import_fixture(array(import_http_response(array('items' => array(array('confirmationCode' => 'SYNTHETIC-BOOLEAN')), 'hasMore' => 'false'))));
$boolean_result = bokun_fetch_bookings('api_1');
verify_import_case(is_array($boolean_result) && 1 === count($boolean_result) && 1 === count($import_test['requests']), 'hasMore string false is normalized as the final page instead of truthy continuation');
import_fixture(array_fill(0, 1001, import_http_response(array('items' => array(array('confirmationCode' => 'SYNTHETIC-BOUNDED')), 'hasMore' => true))));
$bounded_result = bokun_fetch_bookings('api_1');
verify_import_case(import_error_result($bounded_result) && is_string($bounded_result) && str_contains($bounded_result, 'page limit') && 1000 === count($import_test['requests']), 'a nonterminating paginated feed fails at page 1000 without returning a partial import');

import_fixture(array(import_http_response(array('items' => array(), 'totalPages' => 0))));
$empty_result = bokun_fetch_bookings('api_1');
$today = new DateTime('today', new DateTimeZone('GMT'));
verify_import_case('No bookings available to process.' === $empty_result, 'a valid empty API result is distinguished from an API error');
verify_import_case((clone $today)->modify('+1 month')->format('Y-m-d\TH:i:s\Z') === $import_test['requests'][0]['payload']['startDateRange']['to'], 'default import lookahead remains one calendar month');
$lookahead = static function () { return 120; };
add_filter('bokun_booking_start_date_lookahead_days', $lookahead);
import_fixture(array(import_http_response(array('items' => array(), 'totalPages' => 0))));
bokun_fetch_bookings('api_1');
verify_import_case((clone $today)->modify('+120 days')->format('Y-m-d\TH:i:s\Z') === $import_test['requests'][0]['payload']['startDateRange']['to'], 'a filtered forward window reaches the requested later travel dates');
remove_filter('bokun_booking_start_date_lookahead_days', $lookahead);
$excessive_lookahead = static function () { return 999999; };
add_filter('bokun_booking_start_date_lookahead_days', $excessive_lookahead);
import_fixture(array(import_http_response(array('items' => array(), 'totalPages' => 0))));
bokun_fetch_bookings('api_1');
verify_import_case((clone $today)->modify('+3650 days')->format('Y-m-d\TH:i:s\Z') === $import_test['requests'][0]['payload']['startDateRange']['to'], 'the filtered lookahead is bounded to prevent an excessive import');
remove_filter('bokun_booking_start_date_lookahead_days', $excessive_lookahead);

rest_get_server();
foreach (array('guest' => 0, 'subscriber' => $subscriber_id) as $label => $user_id) {
    wp_set_current_user($user_id);
    $snapshot = import_snapshot();
    import_fixture(array());
    $response = rest_do_request(new WP_REST_Request('POST', '/bokun/v1/import-bookings'));
    verify_import_case(in_array($response->get_status(), array(401, 403), true) && !$import_test['requests'], $label . ' cannot invoke the REST import');
    $direct = bokun_import_bookings();
    verify_import_case(is_wp_error($direct) && 'import_forbidden' === $direct->get_error_code(), $label . ' cannot bypass REST permission through the import callback');
    foreach (array('manual import' => array($bokun_settings, 'bokun_bookings_manager_page'), 'import progress' => array($bokun_settings, 'bokun_get_import_progress'), 'booking status' => 'update_booking_status', 'team members' => 'bokun_handle_add_team_member', 'product metadata' => 'bokun_update_partner_page_id') as $operation => $callback) {
        $result = import_ajax($callback, array('security' => 'intentionally-invalid', 'booking_id' => 'SYNTHETIC-NO-BOOKING', 'checked' => '1', 'type' => 'not-available', 'team_member_name' => 'Unauthorized Fixture'));
        verify_import_case(false === ($result['data']['success'] ?? null) && 403 === $result['status'], $label . ' is denied ' . $operation . ' before nonce or mutation work');
    }
    verify_import_case($snapshot === import_snapshot() && !$import_test['requests'], $label . ' denied operations leave booking data and import progress unchanged');
}

foreach (array('Editor' => $editor_id, 'Administrator' => $admin->ID) as $label => $user_id) {
    wp_set_current_user($user_id);
    verify_import_case(bokun_can_send_booking_message(), $label . ' retains the trusted staff capability');
    import_fixture(array(import_http_response(array('items' => array(), 'totalPages' => 0))));
    $response = rest_do_request(new WP_REST_Request('POST', '/bokun/v1/import-bookings'));
    verify_import_case(1 === count($import_test['requests']) && !in_array($response->get_status(), array(401, 403), true), $label . ' may reach the mocked API through the REST import');
    verify_import_case(200 === $response->get_status() && 0 === ($response->get_data()['summary']['total'] ?? null), $label . ' receives a successful no-data REST import rather than a server error');
    import_fixture(array(import_http_response(array('items' => array(), 'totalPages' => 0))));
    $nonce = wp_create_nonce('bokun_api_auth_nonce');
    $result = import_ajax(array($bokun_settings, 'bokun_bookings_manager_page'), array('security' => $nonce, 'mode' => 'api_1'));
    verify_import_case(true === ($result['data']['success'] ?? null) && 1 === count($import_test['requests']), $label . ' may run the manual import with a valid nonce');
    $result = import_ajax(array($bokun_settings, 'bokun_get_import_progress'), array('security' => $nonce, 'mode' => 'api_1'));
    verify_import_case(true === ($result['data']['success'] ?? null), $label . ' may read import progress with a valid nonce');
}

wp_set_current_user($admin->ID);
foreach (array('busy' => '0', 'unavailable' => null) as $label => $lock_result) {
    $import_test['lock'] = $lock_result;
    import_fixture(array());
    $result = bokun_import_bookings();
    verify_import_case(is_wp_error($result) && !$import_test['requests'], 'REST import fails closed when the import lock is ' . $label);
    $result = import_ajax(array($bokun_settings, 'bokun_bookings_manager_page'), array('security' => wp_create_nonce('bokun_api_auth_nonce'), 'mode' => 'api_1'));
    verify_import_case(false === ($result['data']['success'] ?? null) && 409 === $result['status'] && !$import_test['requests'], 'manual import fails closed when the import lock is ' . $label);
    $result = bokun_run_daily_import();
    verify_import_case(is_wp_error($result) && !$import_test['requests'], 'daily import fails closed when the import lock is ' . $label);
    update_option(BOKUN_WEBHOOK_ENABLED_OPTION, 1, false);
    update_option(BOKUN_WEBHOOK_SECRET_OPTION, str_repeat('S', 48), false);
    update_option(BOKUN_WEBHOOK_PENDING_OPTION, 'synthetic-pending-' . $label, false);
    delete_option(BOKUN_WEBHOOK_LAST_RUN_OPTION);
    delete_option(BOKUN_WEBHOOK_RESULT_OPTION);
    $result = bokun_webhook_run_sync();
    verify_import_case(is_wp_error($result) && !$import_test['requests'] && get_option(BOKUN_WEBHOOK_PENDING_OPTION) === 'synthetic-pending-' . $label, 'webhook import retains pending work when the import lock is ' . $label);
}
$import_test['lock'] = '1';
$before_releases = $import_test['lock_releases'];
import_fixture(array(new WP_Error('synthetic_failure', 'Synthetic failure')));
$result = bokun_run_daily_import();
verify_import_case(is_wp_error($result) && 'import_failed' === $result->get_error_code() && $import_test['lock_releases'] > $before_releases, 'daily import reports an API failure and releases the shared import lock');
import_fixture(array(import_http_response(array('items' => array(), 'totalPages' => 0))));
verify_import_case(true === bokun_run_daily_import(), 'daily import confirms a successful empty sync');
verify_import_case('scheduled' === bokun_get_import_last_runs()['api_1']['last_run_trigger'], 'daily import records the scheduled trigger');
verify_import_case(!has_action('wp_ajax_nopriv_bokun_save_api_credentials'), 'anonymous API-credential mutation action remains unregistered');

foreach ($import_test['checks'] as $label) {
    echo (in_array($label, $import_test['failures'], true) ? 'FAIL ' : 'PASS ') . $label . "\n";
}
echo 'Completed ' . count($import_test['checks']) . ' isolated WordPress import checks; failures: ' . count($import_test['failures']) . ".\n";
exit($import_test['failures'] ? 1 : 0);
