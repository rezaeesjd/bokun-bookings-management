<?php

if (!defined('ABSPATH')) {
    exit;
}

define('BOKUN_WEBHOOK_SYNC_HOOK', 'bokun_webhook_import');
define('BOKUN_WEBHOOK_BACKSTOP_HOOK', 'bokun_webhook_retry_pending');
define('BOKUN_WEBHOOK_SECRET_OPTION', 'bokun_webhook_secret');
define('BOKUN_WEBHOOK_ENABLED_OPTION', 'bokun_webhook_enabled');
define('BOKUN_WEBHOOK_LAST_OPTION', 'bokun_webhook_last_received');
define('BOKUN_WEBHOOK_PENDING_OPTION', 'bokun_webhook_pending');
define('BOKUN_WEBHOOK_LAST_RUN_OPTION', 'bokun_webhook_last_run_start');
define('BOKUN_WEBHOOK_RESULT_OPTION', 'bokun_webhook_last_result');
define('BOKUN_WEBHOOK_JOBS_OPTION', 'bokun_webhook_booking_jobs');

// Notification data is a lookup hint only. Booking facts always come from the API.
function bokun_webhook_booking_reference($value) {
    if (!is_string($value) && !is_int($value)) {
        return '';
    }
    $value = trim((string) $value);
    if (strlen($value) > 128) {
        return '';
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if (is_string($decoded) && preg_match('/^Booking:([1-9][0-9]{0,18})$/D', $decoded, $match)) {
        return $match[1];
    }
    return preg_match('/^(?:[1-9][0-9]{0,18}|[A-Za-z][A-Za-z0-9_]{0,31}-[A-Za-z0-9_-]{1,64})$/D', $value) ? $value : '';
}

function bokun_webhook_request_reference($request) {
    $reference = bokun_webhook_booking_reference($request->get_header('x-bokun-booking-id'));
    if ('' !== $reference) {
        return $reference;
    }
    $body = $request->get_json_params();
    if (!is_array($body)) {
        return '';
    }
    foreach (array('bookingId', 'id', 'confirmationCode') as $field) {
        $reference = bokun_webhook_booking_reference($body[$field] ?? null);
        if ('' !== $reference) {
            return $reference;
        }
    }
    return '';
}

function bokun_webhook_jobs() {
    // Refresh after taking the queue lock: another request may have changed it.
    wp_cache_delete(BOKUN_WEBHOOK_JOBS_OPTION, 'options');
    $jobs = get_option(BOKUN_WEBHOOK_JOBS_OPTION, array());
    return is_array($jobs) ? $jobs : array();
}

function bokun_webhook_enqueue_booking($reference, $marker, $code = '') {
    $lock = bokun_webhook_acquire_lock('webhook_queue');
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        $jobs = bokun_webhook_jobs();
        $key = hash('sha256', $reference);
        if (!isset($jobs[$key]) && count($jobs) >= 1000) {
            return new WP_Error('webhook_queue_full', 'The booking queue is full.');
        }
        $code = bokun_webhook_booking_reference($code);
        $jobs[$key] = array('reference' => $reference, 'marker' => $marker, 'confirmation_code' => $code ?: ($jobs[$key]['confirmation_code'] ?? ''));
        return update_option(BOKUN_WEBHOOK_JOBS_OPTION, $jobs, false) ? true : new WP_Error('webhook_storage', 'Could not queue the booking.');
    } finally {
        bokun_webhook_release_lock($lock, 'webhook_queue');
    }
}

function bokun_webhook_retry_booking($key, $job) {
    $lock = bokun_webhook_acquire_lock('webhook_queue');
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        $jobs = bokun_webhook_jobs();
        if (!isset($jobs[$key]) || $jobs[$key] !== $job) {
            return true;
        }
        $job['attempt'] = min(7, 1 + (int) ($job['attempt'] ?? 0));
        $job['retry_at'] = time() + min(3600, 60 * (2 ** ($job['attempt'] - 1)));
        unset($jobs[$key]);
        $jobs[$key] = $job;
        return update_option(BOKUN_WEBHOOK_JOBS_OPTION, $jobs, false) ? true : new WP_Error('webhook_storage', 'Could not retain the retry.');
    } finally {
        bokun_webhook_release_lock($lock, 'webhook_queue');
    }
}

function bokun_webhook_next_retry() {
    $jobs = bokun_webhook_jobs();
    if ($jobs) {
        return min(array_map(static function ($job) { return (int) ($job['retry_at'] ?? 0); }, $jobs));
    }
    $result = get_option(BOKUN_WEBHOOK_RESULT_OPTION, array());
    return is_array($result) ? (int) ($result['next_retry_at'] ?? 0) : 0;
}

function bokun_webhook_acknowledge_booking($key, $job) {
    $lock = bokun_webhook_acquire_lock('webhook_queue');
    if (is_wp_error($lock)) {
        return $lock;
    }
    try {
        $jobs = bokun_webhook_jobs();
        if (!isset($jobs[$key]) || $jobs[$key] !== $job) {
            return true; // A newer event for this booking must survive this run.
        }
        unset($jobs[$key]);
        return update_option(BOKUN_WEBHOOK_JOBS_OPTION, $jobs, false) ? true : new WP_Error('webhook_storage', 'Could not acknowledge the booking.');
    } finally {
        bokun_webhook_release_lock($lock, 'webhook_queue');
    }
}

function bokun_webhook_get_secret() {
    $secret = get_option(BOKUN_WEBHOOK_SECRET_OPTION, '');
    return is_string($secret) && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $secret) ? $secret : '';
}

function bokun_webhook_is_configured() {
    return '1' === (string) get_option(BOKUN_WEBHOOK_ENABLED_OPTION, '') && '' !== bokun_webhook_get_secret();
}

function bokun_webhook_enable() {
    if (!current_user_can('manage_options')) {
        return new WP_Error('webhook_forbidden', 'Administrator access is required.');
    }
    if ('' === bokun_webhook_get_secret()) {
        $secret = bokun_webhook_regenerate_secret();
        return is_wp_error($secret) ? $secret : true;
    }
    if (!update_option(BOKUN_WEBHOOK_ENABLED_OPTION, 1, false) && !bokun_webhook_is_configured()) {
        return new WP_Error('webhook_storage', 'Could not enable the booking webhook.');
    }
    bokun_webhook_maybe_schedule();
    return true;
}

function bokun_webhook_regenerate_secret() {
    if (!current_user_can('manage_options')) {
        return new WP_Error('webhook_forbidden', 'Administrator access is required.');
    }
    $secret = wp_generate_password(48, false, false);
    if (!update_option(BOKUN_WEBHOOK_SECRET_OPTION, $secret, false)) {
        return new WP_Error('webhook_storage', 'Could not save a booking webhook secret.');
    }
    if (!update_option(BOKUN_WEBHOOK_ENABLED_OPTION, 1, false) && !bokun_webhook_is_configured()) {
        return new WP_Error('webhook_storage', 'Could not enable the booking webhook.');
    }
    bokun_webhook_maybe_schedule();
    return $secret;
}

function bokun_webhook_disable() {
    if (!current_user_can('manage_options')) {
        return new WP_Error('webhook_forbidden', 'Administrator access is required.');
    }
    if (!update_option(BOKUN_WEBHOOK_ENABLED_OPTION, 0, false) && '0' !== (string) get_option(BOKUN_WEBHOOK_ENABLED_OPTION, '')) {
        return new WP_Error('webhook_storage', 'Could not disable the booking webhook.');
    }
    bokun_webhook_deactivate();
    return true;
}

function bokun_webhook_deactivate() {
    wp_clear_scheduled_hook(BOKUN_WEBHOOK_SYNC_HOOK);
    wp_clear_scheduled_hook(BOKUN_WEBHOOK_BACKSTOP_HOOK);
}

function bokun_webhook_url() {
    if (!current_user_can('manage_options') || !bokun_webhook_is_configured()) {
        return '';
    }
    return add_query_arg('token', bokun_webhook_get_secret(), rest_url('bokun/v1/sync'));
}

function bokun_webhook_is_authentic($request) {
    if (!bokun_webhook_is_configured()) {
        return false;
    }
    $token = $request->get_header('x-bokun-webhook-token');
    if ('' === $token || null === $token) {
        $query = $request->get_query_params();
        $token = $query['token'] ?? '';
    }
    return is_string($token) && strlen($token) >= 32 && strlen($token) <= 128 && hash_equals(bokun_webhook_get_secret(), $token);
}

function bokun_webhook_handle_request($request) {
    if (!bokun_webhook_is_authentic($request)) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'unauthorized'), 401);
    }
    $query = $request->get_query_params();
    if ('GET' === $request->get_method() && isset($query['ping']) && is_scalar($query['ping']) && '1' === (string) $query['ping']) {
        return new WP_REST_Response(array('ok' => true, 'pong' => true), 200);
    }
    if ('POST' !== $request->get_method()) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'post_required'), 405);
    }
    $reference = bokun_webhook_request_reference($request);
    if ('' === $reference) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'booking_reference_required'), 400);
    }
    $marker = wp_generate_uuid4();
    $body = $request->get_json_params();
    $code = is_array($body) ? ($body['confirmationCode'] ?? '') : '';
    if (is_wp_error(bokun_webhook_enqueue_booking($reference, $marker, $code))) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'queue_unavailable'), 503);
    }
    if (!update_option(BOKUN_WEBHOOK_PENDING_OPTION, $marker, false)) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'queue_unavailable'), 503);
    }
    update_option(BOKUN_WEBHOOK_LAST_OPTION, array(
        'time' => time(),
        'topic' => substr(sanitize_text_field((string) $request->get_header('x-bokun-topic')), 0, 128),
        'booking_id' => $reference,
    ), false);
    $scheduled = bokun_webhook_schedule_sync();
    if (is_wp_error($scheduled)) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'scheduler_unavailable'), 503);
    }
    return new WP_REST_Response(array('ok' => true, 'queued' => true), 200);
}

function bokun_webhook_lock_name($kind) {
    global $wpdb;
    $database = defined('DB_NAME') ? DB_NAME : '';
    return 'bokun_' . $kind . '_' . md5($database . ':' . $wpdb->prefix);
}

function bokun_webhook_acquire_lock($kind) {
    global $wpdb;
    $name = bokun_webhook_lock_name($kind);
    $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
    if ('1' !== (string) $result) {
        return new WP_Error(null === $result ? 'import_lock_unavailable' : 'import_busy', 'The booking import lock is unavailable or already held.');
    }
    return $name;
}

function bokun_webhook_release_lock($name, $kind) {
    global $wpdb;
    if (!is_string($name) || !hash_equals(bokun_webhook_lock_name($kind), $name)) {
        return false;
    }
    return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
}

function bokun_webhook_acquire_import_lock() {
    return bokun_webhook_acquire_lock('booking_import');
}

function bokun_webhook_release_import_lock($name) {
    return bokun_webhook_release_lock($name, 'booking_import');
}

function bokun_webhook_cooldown() {
    return max(60, min(3600, (int) apply_filters('bokun_webhook_sync_cooldown', 60)));
}

function bokun_webhook_schedule_sync($earliest = 0) {
    if (!bokun_webhook_is_configured() || (!get_option(BOKUN_WEBHOOK_PENDING_OPTION, '') && !bokun_webhook_jobs())) {
        return true;
    }
    $retry_at = bokun_webhook_next_retry();
    $when = max(time(), (int) $earliest, (int) get_option(BOKUN_WEBHOOK_LAST_RUN_OPTION, 0) + bokun_webhook_cooldown(), $retry_at);
    $existing = wp_next_scheduled(BOKUN_WEBHOOK_SYNC_HOOK);
    if ($existing && $existing <= $when) {
        return true;
    }
    if ($existing) {
        wp_clear_scheduled_hook(BOKUN_WEBHOOK_SYNC_HOOK);
    }
    $scheduled = wp_schedule_single_event($when, BOKUN_WEBHOOK_SYNC_HOOK, array(), true);
    return is_wp_error($scheduled) || !$scheduled ? new WP_Error('webhook_schedule', 'Could not schedule the pending booking import.') : true;
}

function bokun_webhook_record_failure($error) {
    $previous = get_option(BOKUN_WEBHOOK_RESULT_OPTION, array());
    $attempt = min(7, 1 + (int) (is_array($previous) ? ($previous['attempt'] ?? 0) : 0));
    $retry_at = time() + min(3600, 60 * (2 ** ($attempt - 1)));
    update_option(BOKUN_WEBHOOK_RESULT_OPTION, array(
        'ok' => false,
        'time' => time(),
        'attempt' => $attempt,
        'next_retry_at' => $retry_at,
        'error_code' => sanitize_key($error->get_error_code()),
        'message' => 'The booking import did not complete. Pending changes will be retried.',
        'lookup_trace' => array_slice($GLOBALS['bokun_webhook_lookup_trace'] ?? array(), 0, 12),
    ), false);
    bokun_webhook_schedule_sync($retry_at);
    return $error;
}

function bokun_webhook_clear_pending($marker) {
    global $wpdb;
    $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", BOKUN_WEBHOOK_PENDING_OPTION, $marker));
    wp_cache_delete(BOKUN_WEBHOOK_PENDING_OPTION, 'options');
    wp_cache_delete('notoptions', 'options');
    return $deleted;
}

function bokun_webhook_run_sync() {
    if (!bokun_webhook_is_configured() || (!get_option(BOKUN_WEBHOOK_PENDING_OPTION, '') && !bokun_webhook_jobs())) {
        return true;
    }
    $retry_at = bokun_webhook_next_retry();
    $next_run = max((int) get_option(BOKUN_WEBHOOK_LAST_RUN_OPTION, 0) + bokun_webhook_cooldown(), $retry_at);
    if ($next_run > time()) {
        bokun_webhook_schedule_sync($next_run);
        return new WP_Error('webhook_deferred', 'The pending import is waiting for its scheduled retry.');
    }
    $lock = bokun_webhook_acquire_lock('webhook_worker');
    if (is_wp_error($lock)) {
        bokun_webhook_schedule_sync(time() + 60);
        return $lock;
    }
    try {
        $marker = get_option(BOKUN_WEBHOOK_PENDING_OPTION, '');
        update_option(BOKUN_WEBHOOK_LAST_RUN_OPTION, time(), false);
        if (!function_exists('bokun_import_webhook_booking')) {
            return bokun_webhook_record_failure(new WP_Error('import_unavailable', 'The booking import pipeline is unavailable.'));
        }
        $jobs = bokun_webhook_jobs();
        // Recover the last identifiable notification queued by the previous worker.
        if (!$jobs && $marker) {
            $last = get_option(BOKUN_WEBHOOK_LAST_OPTION, array());
            $reference = bokun_webhook_booking_reference(is_array($last) ? ($last['booking_id'] ?? '') : '');
            if ('' === $reference) {
                return bokun_webhook_record_failure(new WP_Error('booking_reference_missing', 'The pending notification has no booking reference.'));
            }
            $queued = bokun_webhook_enqueue_booking($reference, $marker);
            if (is_wp_error($queued)) {
                return bokun_webhook_record_failure($queued);
            }
            $jobs = bokun_webhook_jobs();
        }
        $deadline = microtime(true) + 40;
        $eligible = array_filter($jobs, static function ($job) { return (int) ($job['retry_at'] ?? 0) <= time(); });
        $failure = null;
        $failure_trace = array();
        foreach (array_slice($eligible, 0, 3, true) as $key => $job) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $imported = bokun_import_webhook_booking($job['reference'], $deadline, $job['confirmation_code'] ?? '');
            if (is_wp_error($imported)) {
                $retained = bokun_webhook_retry_booking($key, $job);
                $failure = is_wp_error($retained) ? $retained : $imported;
                $failure_trace = $GLOBALS['bokun_webhook_lookup_trace'] ?? array();
                continue;
            }
            if (true !== $imported) {
                return bokun_webhook_record_failure(new WP_Error('import_unconfirmed', 'The booking import did not confirm completion.'));
            }
            $acknowledged = bokun_webhook_acknowledge_booking($key, $job);
            if (is_wp_error($acknowledged)) {
                return bokun_webhook_record_failure($acknowledged);
            }
        }
        if (is_wp_error($failure)) {
            $GLOBALS['bokun_webhook_lookup_trace'] = $failure_trace;
            return bokun_webhook_record_failure($failure);
        }
        if (!bokun_webhook_jobs() && false === bokun_webhook_clear_pending($marker)) {
            return bokun_webhook_record_failure(new WP_Error('webhook_storage', 'Could not acknowledge the completed booking import.'));
        }
        update_option(BOKUN_WEBHOOK_RESULT_OPTION, array('ok' => true, 'time' => time(), 'attempt' => 0, 'next_retry_at' => 0), false);
        bokun_webhook_schedule_sync();
        return true;
    } catch (Throwable $error) {
        return bokun_webhook_record_failure(new WP_Error('import_exception', 'The booking import was interrupted. Pending changes will be retried.'));
    } finally {
        bokun_webhook_release_lock($lock, 'webhook_worker');
    }
}

function bokun_webhook_schedules($schedules) {
    $schedules['bokun_webhook_five_minutes'] = array('interval' => 300, 'display' => 'Every five minutes (pending booking changes)');
    return $schedules;
}

function bokun_webhook_maybe_schedule() {
    if (!bokun_webhook_is_configured()) {
        return;
    }
    if (!wp_next_scheduled(BOKUN_WEBHOOK_BACKSTOP_HOOK)) {
        wp_schedule_event(time() + 300, 'bokun_webhook_five_minutes', BOKUN_WEBHOOK_BACKSTOP_HOOK, array(), true);
    }
    bokun_webhook_schedule_sync();
}

function bokun_webhook_handle_regenerate() {
    if (!current_user_can('manage_options')) {
        wp_die('Administrator access is required.', '', array('response' => 403));
    }
    check_admin_referer('bokun_regenerate_webhook_secret');
    $result = bokun_webhook_regenerate_secret();
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()));
    }
    $referer = wp_get_referer();
    wp_safe_redirect($referer ? add_query_arg('bokun_webhook', 'configured', $referer) : admin_url());
    exit;
}

function bokun_webhook_handle_disable() {
    if (!current_user_can('manage_options')) {
        wp_die('Administrator access is required.', '', array('response' => 403));
    }
    check_admin_referer('bokun_disable_webhook');
    $result = bokun_webhook_disable();
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()));
    }
    $referer = wp_get_referer();
    wp_safe_redirect($referer ? add_query_arg('bokun_webhook', 'disabled', $referer) : admin_url());
    exit;
}

add_action('rest_api_init', static function () {
    register_rest_route('bokun/v1', '/sync', array(
        'methods' => array('POST', 'GET'),
        'callback' => 'bokun_webhook_handle_request',
        'permission_callback' => '__return_true',
    ));
});
add_filter('cron_schedules', 'bokun_webhook_schedules');
add_action('init', 'bokun_webhook_maybe_schedule');
add_action(BOKUN_WEBHOOK_SYNC_HOOK, 'bokun_webhook_run_sync');
add_action(BOKUN_WEBHOOK_BACKSTOP_HOOK, 'bokun_webhook_run_sync');
add_action('admin_post_bokun_regenerate_webhook_secret', 'bokun_webhook_handle_regenerate');
add_action('admin_post_bokun_disable_webhook', 'bokun_webhook_handle_disable');
