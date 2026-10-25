<?php
if ('cli' !== PHP_SAPI) {
    exit;
}

define('ABSPATH', __DIR__ . '/');
define('DB_NAME', 'isolated_webhook_tests');

class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}

class Webhook_Test_Request {
    private $method;
    private $query;
    private $headers;
    private $body;
    public function __construct($method = 'POST', $query = array(), $headers = array(), $body = array('id' => 123)) { $this->method = $method; $this->query = $query; $this->headers = $headers; $this->body = $body; }
    public function get_method() { return $this->method; }
    public function get_query_params() { return $this->query; }
    public function get_header($name) { return $this->headers[$name] ?? ''; }
    public function get_json_params() { return $this->body; }
}

class Webhook_Test_DB {
    public $prefix = 'isolated_';
    public $options = 'isolated_options';
    public $connection = 1;
    public $locks = array();
    public $supported = true;
    public function prepare($sql, ...$args) { return array($sql, $args); }
    public function get_var($query) {
        [$sql, $args] = $query;
        if (!$this->supported) { return null; }
        $name = $args[0];
        if (str_contains($sql, 'GET_LOCK')) {
            if (isset($this->locks[$name]) && $this->locks[$name] !== $this->connection) { return '0'; }
            $this->locks[$name] = $this->connection;
            return '1';
        }
        if (str_contains($sql, 'RELEASE_LOCK') && ($this->locks[$name] ?? null) === $this->connection) {
            unset($this->locks[$name]);
            return '1';
        }
        return '0';
    }
    public function query($query) {
        [$sql, $args] = $query;
        if (!str_starts_with($sql, 'DELETE FROM')) { throw new RuntimeException('Unexpected test SQL.'); }
        if ($GLOBALS['fail_clear']) { return false; }
        if ((string) ($GLOBALS['test_options'][$args[0]] ?? '') !== (string) $args[1]) { return 0; }
        unset($GLOBALS['test_options'][$args[0]]);
        return 1;
    }
    public function disconnect($connection) { $this->locks = array_filter($this->locks, static function ($owner) use ($connection) { return $owner !== $connection; }); }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function current_user_can($capability) { return $GLOBALS['test_admin']; }
function get_option($name, $default = false) { return $GLOBALS['test_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) {
    if (in_array($name, $GLOBALS['fail_options'], true)) { return false; }
    if (isset($GLOBALS['test_options'][$name]) && $GLOBALS['test_options'][$name] === $value) { return false; }
    $GLOBALS['test_options'][$name] = $value;
    return true;
}
function wp_generate_password($length, $special, $extra) { return substr(hash('sha512', (string) ++$GLOBALS['unique_counter']), 0, $length); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-' . str_pad((string) ++$GLOBALS['unique_counter'], 12, '0', STR_PAD_LEFT); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function add_query_arg($name, $value, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($name) . '=' . rawurlencode($value); }
function rest_url($path) { return 'https://internal.example.test/wp-json/' . $path; }
function wp_cache_delete($name, $group) { return true; }
function add_action($name, $callback, $priority = 10) { $GLOBALS['test_actions'][$name][] = $callback; }
function add_filter($name, $callback, $priority = 10) { $GLOBALS['test_filters'][$name][$priority][] = $callback; }
function remove_filter($name, $callback, $priority = 10) {
    foreach ($GLOBALS['test_filters'][$name][$priority] ?? array() as $index => $registered) {
        if ($registered === $callback) { unset($GLOBALS['test_filters'][$name][$priority][$index]); }
    }
}
function apply_filters($name, $value) {
    $priorities = $GLOBALS['test_filters'][$name] ?? array();
    ksort($priorities);
    foreach ($priorities as $callbacks) { foreach ($callbacks as $callback) { $value = $callback($value); } }
    return $value;
}
function wp_next_scheduled($hook) { return $GLOBALS['test_cron'][$hook] ?? false; }
function wp_schedule_single_event($when, $hook, $args = array(), $error = false) {
    if ($GLOBALS['fail_schedule']) { return new WP_Error('schedule_failed', 'Synthetic scheduler failure.'); }
    $GLOBALS['test_cron'][$hook] = $when;
    return true;
}
function wp_schedule_event($when, $recurrence, $hook, $args = array(), $error = false) { return wp_schedule_single_event($when, $hook, $args, $error); }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['test_cron'][$hook]); return 1; }
function register_rest_route($namespace, $path, $route) { $GLOBALS['test_route'] = $route; }
function wp_die($message, $title = '', $args = array()) { throw new RuntimeException($message); }
function check_admin_referer($action) {
    $GLOBALS['test_nonce_action'] = $action;
    if (!$GLOBALS['test_nonce_valid']) { throw new RuntimeException('Synthetic invalid nonce.'); }
}
function bokun_import_webhook_booking($reference, $deadline) {
    $lock = bokun_webhook_acquire_import_lock();
    if (is_wp_error($lock)) { return $lock; }
    try {
        $GLOBALS['import_count']++;
        $GLOBALS['import_trigger'] = 'webhook';
        $GLOBALS['import_references'][] = $reference;
        $GLOBALS['import_windows'][] = array(apply_filters('bokun_booking_start_date_lookback_days', 1), apply_filters('bokun_booking_start_date_lookahead_days', 30));
        return ($GLOBALS['test_import'])();
    } finally {
        bokun_webhook_release_import_lock($lock);
    }
}

$test_options = $test_cron = $test_actions = $test_filters = $fail_options = $import_windows = array();
$test_admin = $fail_clear = $fail_schedule = $test_nonce_valid = false;
$unique_counter = $import_count = $checks = 0;
$import_references = array();
$test_import = static function () { return true; };
$wpdb = new Webhook_Test_DB();

require dirname(__DIR__) . '/includes/bokun-webhook.php';

function verify_webhook($condition, $description) {
    $GLOBALS['checks']++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $description); }
    echo 'PASS: ' . $description . "\n";
}

function ready_webhook($callback = null) {
    $GLOBALS['test_options'] = array(BOKUN_WEBHOOK_SECRET_OPTION => str_repeat('a', 48), BOKUN_WEBHOOK_ENABLED_OPTION => 1, BOKUN_WEBHOOK_PENDING_OPTION => wp_generate_uuid4());
    $GLOBALS['test_options'][BOKUN_WEBHOOK_JOBS_OPTION] = array(hash('sha256', '123') => array('reference' => '123', 'marker' => wp_generate_uuid4()));
    $GLOBALS['test_cron'] = $GLOBALS['fail_options'] = array();
    $GLOBALS['fail_schedule'] = $GLOBALS['fail_clear'] = false;
    $GLOBALS['wpdb']->connection = 1;
    $GLOBALS['wpdb']->locks = array();
    $GLOBALS['wpdb']->supported = true;
    $GLOBALS['test_import'] = $callback ?: static function () { return true; };
}

verify_webhook('' === bokun_webhook_get_secret() && '' === bokun_webhook_url() && !$test_options, 'reading unconfigured settings never creates a secret');
bokun_webhook_maybe_schedule();
verify_webhook(!$test_cron, 'unconfigured webhook schedules no work');
$request = new Webhook_Test_Request('POST', array('token' => str_repeat('a', 48)));
verify_webhook(401 === bokun_webhook_handle_request($request)->status && !$test_options, 'anonymous unconfigured requests fail closed without writes');
verify_webhook(is_wp_error(bokun_webhook_regenerate_secret()) && !$test_options, 'anonymous callers cannot configure a secret');
$test_options[BOKUN_WEBHOOK_SECRET_OPTION] = str_repeat('a', 48);
verify_webhook(!bokun_webhook_is_configured() && 401 === bokun_webhook_handle_request($request)->status, 'a legacy secret alone does not activate the webhook');
$test_admin = true;
verify_webhook(true === bokun_webhook_enable() && str_repeat('a', 48) === bokun_webhook_get_secret(), 'explicit admin setup reuses an existing valid secret');
verify_webhook(isset($test_cron[BOKUN_WEBHOOK_BACKSTOP_HOOK]), 'explicit setup enables the recurring retry backstop');
$previous_secret = bokun_webhook_get_secret();
$new_secret = bokun_webhook_regenerate_secret();
verify_webhook(is_string($new_secret) && 48 === strlen($new_secret) && $new_secret !== $previous_secret, 'admin rotation replaces the token with a fresh bounded secret');
verify_webhook(!bokun_webhook_is_authentic($request), 'rotated tokens stop authenticating immediately');
verify_webhook(str_contains(bokun_webhook_url(), 'token=' . $new_secret), 'only configured administrators receive the setup URL');
$test_admin = false;
verify_webhook('' === bokun_webhook_url(), 'public visitors cannot obtain a setup URL');
verify_webhook(!bokun_webhook_is_authentic(new Webhook_Test_Request('POST', array('token' => array($new_secret)))), 'array-valued credentials are rejected');
verify_webhook(!bokun_webhook_is_authentic(new Webhook_Test_Request('POST', array('token' => str_repeat('a', 129)))), 'oversized credentials are rejected');
verify_webhook(bokun_webhook_is_authentic(new Webhook_Test_Request('POST', array(), array('x-bokun-webhook-token' => $new_secret))), 'the token header authenticates without a URL secret');
verify_webhook(!bokun_webhook_is_authentic(new Webhook_Test_Request('POST', array('token' => $new_secret), array('x-bokun-webhook-token' => str_repeat('b', 48)))), 'an invalid supplied token header cannot fall back to a valid query token');
$before_ping = $test_options;
verify_webhook(200 === bokun_webhook_handle_request(new Webhook_Test_Request('GET', array('token' => $new_secret, 'ping' => '1')))->status && $test_options === $before_ping, 'authenticated health checks are strictly read-only');
verify_webhook(405 === bokun_webhook_handle_request(new Webhook_Test_Request('GET', array('token' => $new_secret)))->status && $test_options === $before_ping, 'GET cannot enqueue an import');
$response = bokun_webhook_handle_request(new Webhook_Test_Request('POST', array('token' => $new_secret), array('x-bokun-topic' => str_repeat('x', 1000), 'x-bokun-booking-id' => str_repeat('y', 1000))));
verify_webhook(200 === $response->status && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'an authenticated POST durably queues background work');
verify_webhook(128 === strlen(get_option(BOKUN_WEBHOOK_LAST_OPTION)['topic']) && '123' === get_option(BOKUN_WEBHOOK_LAST_OPTION)['booking_id'], 'metadata is bounded and invalid oversized identifiers are never used');
verify_webhook(isset($test_cron[BOKUN_WEBHOOK_SYNC_HOOK]) && 0 === $import_count, 'webhook requests never import inline');
$queued_at = $test_cron[BOKUN_WEBHOOK_SYNC_HOOK];
$old_marker = get_option(BOKUN_WEBHOOK_PENDING_OPTION);
bokun_webhook_handle_request(new Webhook_Test_Request('POST', array('token' => $new_secret)));
verify_webhook($old_marker !== get_option(BOKUN_WEBHOOK_PENDING_OPTION) && $queued_at === $test_cron[BOKUN_WEBHOOK_SYNC_HOOK], 'bursts update durable work while coalescing the scheduled import');
$fail_options = array(BOKUN_WEBHOOK_PENDING_OPTION);
verify_webhook(503 === bokun_webhook_handle_request(new Webhook_Test_Request('POST', array('token' => $new_secret)))->status, 'a failed durable write is not acknowledged as queued');
ready_webhook();
$fail_schedule = true;
verify_webhook(503 === bokun_webhook_handle_request(new Webhook_Test_Request('POST', array('token' => str_repeat('a', 48))))->status && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'scheduler failures retain pending work and ask the sender to retry');
ready_webhook();
$first_lock = bokun_webhook_acquire_import_lock();
$wpdb->connection = 2;
verify_webhook(is_string($first_lock) && is_wp_error(bokun_webhook_acquire_import_lock()), 'separate database connections cannot import concurrently');
verify_webhook(!bokun_webhook_release_import_lock($first_lock), 'one connection cannot release another worker import lock');
$wpdb->disconnect(1);
verify_webhook(is_string(bokun_webhook_acquire_import_lock()), 'a terminated database connection releases its stale import lock');
$wpdb->connection = 1;
verify_webhook(!bokun_webhook_release_import_lock('unrelated_lock'), 'release refuses an unrelated lock name');
ready_webhook();
$wpdb->supported = false;
$result = bokun_webhook_run_sync();
verify_webhook(is_wp_error($result) && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'unsupported locking fails closed with work preserved');
ready_webhook();
$before_import = $import_count;
verify_webhook(true === bokun_webhook_run_sync() && $before_import + 1 === $import_count && 'webhook' === $import_trigger && !get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'a successful import consumes its exact pending generation');
verify_webhook(array(1, 30) === end($import_windows) && '123' === end($import_references), 'webhooks refresh the notified booking without widening travel-date windows');
verify_webhook(!$wpdb->locks && true === get_option(BOKUN_WEBHOOK_RESULT_OPTION)['ok'], 'successful imports release locks and save success evidence');
$before_import = $import_count;
bokun_webhook_run_sync();
verify_webhook($before_import === $import_count, 'duplicate scheduled jobs without pending work do nothing');
ready_webhook();
$worker_lock = bokun_webhook_acquire_lock('webhook_worker');
$wpdb->connection = 2;
$before_import = $import_count;
verify_webhook(is_wp_error(bokun_webhook_run_sync()) && $before_import === $import_count && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'concurrent webhook workers cannot enter the import pipeline');
$wpdb->connection = 1;
bokun_webhook_release_lock($worker_lock, 'webhook_worker');
ready_webhook();
$window_filter = static function () { return 1000000; };
add_filter('bokun_webhook_sync_window_days', $window_filter);
bokun_webhook_run_sync();
verify_webhook(array(1, 30) === end($import_windows), 'an old date-window override cannot re-enable a full-history webhook import');
remove_filter('bokun_webhook_sync_window_days', $window_filter);
ready_webhook(static function () { update_option(BOKUN_WEBHOOK_PENDING_OPTION, 'event-arrived-during-import', false); return true; });
verify_webhook(true === bokun_webhook_run_sync() && 'event-arrived-during-import' === get_option(BOKUN_WEBHOOK_PENDING_OPTION) && isset($test_cron[BOKUN_WEBHOOK_SYNC_HOOK]), 'a new event during import survives acknowledgement and receives a trailing run');
verify_webhook($test_cron[BOKUN_WEBHOOK_SYNC_HOOK] >= get_option(BOKUN_WEBHOOK_LAST_RUN_OPTION) + 60, 'trailing imports respect the cooldown');
$before_import = $import_count;
verify_webhook(is_wp_error(bokun_webhook_run_sync()) && $import_count === $before_import, 'early duplicate cron invocations cannot bypass the cooldown');
ready_webhook(static function () { return new WP_Error('synthetic_import_failure', 'Synthetic failure with confidential context.'); });
$result = bokun_webhook_run_sync();
$failure = get_option(BOKUN_WEBHOOK_RESULT_OPTION);
verify_webhook(is_wp_error($result) && get_option(BOKUN_WEBHOOK_PENDING_OPTION) && 1 === $failure['attempt'] && $failure['next_retry_at'] >= time() + 59, 'failed imports keep pending events and schedule bounded exponential retries');
verify_webhook(!str_contains(json_encode($failure), 'confidential') && !$wpdb->locks, 'failure receipts exclude raw exception details and release locks');
$before_import = $import_count;
bokun_webhook_run_sync();
verify_webhook($before_import === $import_count, 'backstop invocations honor an existing retry delay');
ready_webhook(static function () { throw new RuntimeException('confidential exception detail'); });
$result = bokun_webhook_run_sync();
verify_webhook(is_wp_error($result) && 'import_exception' === $result->get_error_code() && get_option(BOKUN_WEBHOOK_PENDING_OPTION) && !$wpdb->locks, 'exceptions preserve events and release both import and worker locks');
verify_webhook(1 === apply_filters('bokun_booking_start_date_lookback_days', 1), 'exceptions cannot leak the widened date window into later imports');
ready_webhook(static function () { return null; });
verify_webhook('import_unconfirmed' === bokun_webhook_run_sync()->get_error_code() && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'legacy void return values cannot acknowledge an unconfirmed import');
ready_webhook();
$fail_clear = true;
verify_webhook(is_wp_error(bokun_webhook_run_sync()) && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'acknowledgement write failures retain work for retry');
ready_webhook();
$test_options[BOKUN_WEBHOOK_RESULT_OPTION] = array('attempt' => 100);
bokun_webhook_record_failure(new WP_Error('repeated_failure', 'Synthetic repeated failure.'));
$failure = get_option(BOKUN_WEBHOOK_RESULT_OPTION);
verify_webhook(7 === $failure['attempt'] && $failure['next_retry_at'] <= time() + 3600, 'retry attempts and delay are capped');
$test_admin = true;
verify_webhook(true === bokun_webhook_disable() && !bokun_webhook_is_configured() && !$test_cron && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'explicit disable stops scheduling without losing pending events or rotating the saved secret');
$test_admin = false;
verify_webhook(is_wp_error(bokun_webhook_enable()) && is_wp_error(bokun_webhook_disable()), 'non-admin callers cannot change webhook activation');
ready_webhook();
$test_cron = array(BOKUN_WEBHOOK_SYNC_HOOK => time() + 60, BOKUN_WEBHOOK_BACKSTOP_HOOK => time() + 300);
$before_deactivation = $test_options;
bokun_webhook_deactivate();
verify_webhook(!$test_cron && $before_deactivation === $test_options, 'plugin deactivation clears both schedules without deleting credentials or pending history');
$blocked = false;
try { bokun_webhook_handle_disable(); } catch (RuntimeException $error) { $blocked = true; }
verify_webhook($blocked && bokun_webhook_is_configured(), 'the disable admin-post handler rejects unauthenticated callers before mutation');
$test_admin = true;
$blocked = false;
try { bokun_webhook_handle_disable(); } catch (RuntimeException $error) { $blocked = true; }
verify_webhook($blocked && 'bokun_disable_webhook' === $test_nonce_action && bokun_webhook_is_configured(), 'the disable admin-post handler requires its action-specific nonce before mutation');
verify_webhook(in_array('bokun_webhook_handle_disable', $test_actions['admin_post_bokun_disable_webhook'], true), 'the explicit disable action is registered for administrators');
$schedules = bokun_webhook_schedules(array());
verify_webhook(300 === $schedules['bokun_webhook_five_minutes']['interval'], 'the pending-work recovery backstop runs every five minutes');
foreach ($test_actions['rest_api_init'] as $callback) { $callback(); }
verify_webhook(array('POST', 'GET') === $test_route['methods'] && 'bokun_webhook_handle_request' === $test_route['callback'], 'the route exposes only POST events and authenticated GET health checks');

verify_webhook('123' === bokun_webhook_booking_reference(rtrim(base64_encode('Booking:123'), '=')), 'GraphQL notification IDs decode to a bounded REST booking ID');
foreach (array('../123', 'https://other.test/123', array(123), 1.5, str_repeat('9', 20), base64_encode('Experience:123')) as $invalid) {
    verify_webhook('' === bokun_webhook_booking_reference($invalid), 'invalid references cannot select an arbitrary API route: ' . gettype($invalid));
}
ready_webhook();
$before = $test_options;
$response = bokun_webhook_handle_request(new Webhook_Test_Request('POST', array('token' => str_repeat('a', 48)), array(), array('status' => 'CANCELLED')));
verify_webhook(400 === $response->status && $before === $test_options, 'notifications without an identifier cannot start an expensive full-history import');
ready_webhook();
foreach (array(456, 789, 987) as $id) {
    bokun_webhook_handle_request(new Webhook_Test_Request('POST', array('token' => str_repeat('a', 48)), array(), array('id' => $id, 'customer' => array('firstName' => 'UNTRUSTED'))));
}
verify_webhook(4 === count(bokun_webhook_jobs()) && !str_contains(json_encode(bokun_webhook_jobs()), 'UNTRUSTED'), 'bursts preserve separate bookings while storing no notification-supplied customer facts');
verify_webhook(true === bokun_webhook_run_sync() && 1 === count(bokun_webhook_jobs()) && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'one worker processes at most three bookings and retains the trailing batch');
unset($test_options[BOKUN_WEBHOOK_LAST_RUN_OPTION]);
verify_webhook(true === bokun_webhook_run_sync() && !bokun_webhook_jobs() && !get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'the trailing batch eventually drains the durable queue');
ready_webhook(static function () {
    bokun_webhook_handle_request(new Webhook_Test_Request('POST', array('token' => str_repeat('a', 48)), array(), array('id' => 123)));
    return true;
});
verify_webhook(true === bokun_webhook_run_sync() && 1 === count(bokun_webhook_jobs()) && get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'a same-booking update arriving during import survives queue acknowledgement');
ready_webhook();
unset($test_options[BOKUN_WEBHOOK_JOBS_OPTION]);
$test_options[BOKUN_WEBHOOK_LAST_OPTION] = array('booking_id' => rtrim(base64_encode('Booking:123'), '='));
verify_webhook(true === bokun_webhook_run_sync() && !get_option(BOKUN_WEBHOOK_PENDING_OPTION), 'a legacy pending notification migrates from its saved booking ID');
ready_webhook();
$fail_options = array(BOKUN_WEBHOOK_PENDING_OPTION);
bokun_webhook_handle_request(new Webhook_Test_Request('POST', array('token' => str_repeat('a', 48)), array(), array('id' => 456)));
unset($test_options[BOKUN_WEBHOOK_PENDING_OPTION]);
$fail_options = array();
verify_webhook(true === bokun_webhook_run_sync() && !bokun_webhook_jobs(), 'queued bookings recover even when the coalescing-marker write failed');

echo "Completed $checks isolated booking webhook checks without network access.\n";
