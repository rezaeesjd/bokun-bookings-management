<?php
/**
 * Live sync from Bokun via webhook.
 *
 * Exposes an HTTPS endpoint that Bokun calls on every booking change
 * (created / updated / cancelled). Each authenticated call triggers the SAME
 * full windowed import the daily cron and the manual "Fetch" button run —
 * the webhook is only a trigger, so nothing in the request body is trusted or
 * stored; we always re-fetch from Bokun's API, which stays the source of truth.
 *
 * Design notes:
 *  - The endpoint answers 200 fast and runs the import on a background wp-cron
 *    event (Bokun allows ~5s per delivery, our import can take much longer).
 *  - A short cooldown collapses a burst of changes into a single sync — a full
 *    windowed fetch already covers every change in the window, so nothing is
 *    lost by debouncing.
 *  - Authenticity is accepted on EITHER a shared secret token (in the URL Bokun
 *    calls) OR a valid Bokun HMAC over the X-Bokun-* headers. The token is the
 *    primary gate and does not depend on Bokun's HMAC encoding.
 *  - The existing daily cron is kept as a backstop for any missed delivery.
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BOKUN_WEBHOOK_SYNC_HOOK' ) ) {
	define( 'BOKUN_WEBHOOK_SYNC_HOOK', 'bokun_webhook_import' );
}
if ( ! defined( 'BOKUN_WEBHOOK_SECRET_OPTION' ) ) {
	define( 'BOKUN_WEBHOOK_SECRET_OPTION', 'bokun_webhook_secret' );
}
if ( ! defined( 'BOKUN_WEBHOOK_LAST_OPTION' ) ) {
	define( 'BOKUN_WEBHOOK_LAST_OPTION', 'bokun_webhook_last_received' );
}
if ( ! defined( 'BOKUN_WEBHOOK_PENDING_OPTION' ) ) {
	// Set while at least one received change is not yet covered by a completed
	// sync. Ensures no event is dropped: bursts coalesce into the next run, and a
	// change arriving mid-import queues a guaranteed trailing run.
	define( 'BOKUN_WEBHOOK_PENDING_OPTION', 'bokun_webhook_pending' );
}
if ( ! defined( 'BOKUN_WEBHOOK_LAST_RUN_OPTION' ) ) {
	// Timestamp a sync last started — used to throttle runs to the cooldown.
	define( 'BOKUN_WEBHOOK_LAST_RUN_OPTION', 'bokun_webhook_last_run_start' );
}

/**
 * The shared secret token, generated and stored on first use.
 *
 * @return string
 */
function bokun_webhook_get_secret() {
	$secret = get_option( BOKUN_WEBHOOK_SECRET_OPTION, '' );

	if ( ! is_string( $secret ) || strlen( $secret ) < 16 ) {
		$secret = bokun_webhook_regenerate_secret();
	}

	return $secret;
}

/**
 * Generate a new secret token (invalidating the old URL).
 *
 * @return string
 */
function bokun_webhook_regenerate_secret() {
	$secret = wp_generate_password( 40, false, false ); // 40 alphanumeric characters.
	update_option( BOKUN_WEBHOOK_SECRET_OPTION, $secret, false );

	return $secret;
}

/**
 * The full URL to paste into Bokun (REST route + secret token).
 *
 * @return string
 */
function bokun_webhook_url() {
	return add_query_arg( 'token', bokun_webhook_get_secret(), rest_url( 'bokun/v1/sync' ) );
}

/**
 * Register the REST endpoint. Auth is enforced inside the callback so a bad
 * token returns a clean 401 rather than WordPress's generic permission error.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bokun/v1',
			'/sync',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => 'bokun_webhook_handle_request',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Handle an inbound Bokun webhook (or a health-check ping).
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function bokun_webhook_handle_request( $request ) {
	if ( ! bokun_webhook_is_authentic( $request ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
	}

	// A browser/manual reachability check: GET ...&ping=1 confirms the URL works
	// without triggering a sync.
	if ( 'GET' === $request->get_method() && $request->get_param( 'ping' ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'pong' => true ), 200 );
	}

	// Record what arrived, for the settings screen.
	$topic      = $request->get_header( 'x-bokun-topic' );
	$booking_id = $request->get_header( 'x-bokun-booking-id' );
	update_option(
		BOKUN_WEBHOOK_LAST_OPTION,
		array(
			'time'       => time(),
			'topic'      => is_string( $topic ) ? sanitize_text_field( $topic ) : '',
			'booking_id' => is_string( $booking_id ) ? sanitize_text_field( $booking_id ) : '',
		),
		false
	);

	// Mark work pending and schedule a throttled, coalesced sync. No event is
	// dropped: bursts collapse into the next run, and a change arriving while an
	// import is already running queues a guaranteed trailing run (see
	// bokun_webhook_run_sync). The import runs on a background cron event, never
	// inline, because Bokun allows only ~5s per delivery.
	update_option( BOKUN_WEBHOOK_PENDING_OPTION, 1, false );
	bokun_webhook_schedule_sync();

	return new WP_REST_Response( array( 'ok' => true, 'queued' => true ), 200 );
}

/**
 * Schedule the background sync, throttled to one run per cooldown window and
 * coalesced (never double-booked). Safe to call on every webhook.
 *
 * @return void
 */
function bokun_webhook_schedule_sync() {
	if ( wp_next_scheduled( BOKUN_WEBHOOK_SYNC_HOOK ) ) {
		return; // A run is already queued; it will consume the pending flag.
	}

	$cooldown = (int) apply_filters( 'bokun_webhook_sync_cooldown', 60 );
	if ( $cooldown < 0 ) {
		$cooldown = 0;
	}
	$delay = (int) apply_filters( 'bokun_webhook_sync_delay', 0 );
	if ( $delay < 0 ) {
		$delay = 0;
	}
	$last_start = (int) get_option( BOKUN_WEBHOOK_LAST_RUN_OPTION, 0 );
	$when       = max( time() + $delay, $last_start + $cooldown );

	wp_schedule_single_event( $when, BOKUN_WEBHOOK_SYNC_HOOK );

	// Nudge wp-cron to run the event now only when it is already due — a loopback
	// cannot wake a future event, so for one deferred by the cooldown we leave it
	// to wp-cron on the next request (or a server cron), with the nightly import
	// as the guaranteed backstop. The common "first change after idle" case is
	// due immediately and fires here.
	if ( $when <= time() ) {
		bokun_webhook_spawn_cron();
	}
}

/**
 * Is the request authentic? Accept a matching shared token OR a valid Bokun HMAC.
 *
 * @param WP_REST_Request $request The request.
 * @return bool
 */
function bokun_webhook_is_authentic( $request ) {
	$secret = bokun_webhook_get_secret();

	$token = $request->get_param( 'token' );
	if ( ! is_string( $token ) || '' === $token ) {
		$token = $request->get_header( 'x-bokun-webhook-token' );
	}
	if ( is_string( $token ) && '' !== $token && hash_equals( $secret, $token ) ) {
		return true;
	}

	return bokun_webhook_verify_bokun_hmac( $request );
}

/**
 * Verify Bokun's HMAC over the X-Bokun-* headers using any configured API
 * secret key. Bokun signs: the X-Bokun-* headers (excluding the HMAC itself),
 * header names lowercased and sorted alphabetically, joined as k=v&k=v, with
 * HMAC-SHA256. Both hex and base64 digests are accepted (their docs are
 * ambiguous on the encoding).
 *
 * @param WP_REST_Request $request The request.
 * @return bool
 */
function bokun_webhook_verify_bokun_hmac( $request ) {
	$hmac = $request->get_header( 'x-bokun-hmac' );
	if ( ! is_string( $hmac ) || '' === $hmac ) {
		return false;
	}

	if ( ! function_exists( 'bokun_get_configured_api_credentials' ) ) {
		return false;
	}

	$pairs = array();
	foreach ( (array) $request->get_headers() as $name => $values ) {
		$key = strtolower( str_replace( '_', '-', (string) $name ) );
		if ( 0 !== strpos( $key, 'x-bokun-' ) || 'x-bokun-hmac' === $key ) {
			continue;
		}
		$pairs[ $key ] = is_array( $values ) ? implode( ',', $values ) : (string) $values;
	}
	if ( empty( $pairs ) ) {
		return false;
	}
	ksort( $pairs );
	$parts = array();
	foreach ( $pairs as $k => $v ) {
		$parts[] = $k . '=' . $v;
	}
	$base = implode( '&', $parts );

	foreach ( bokun_get_configured_api_credentials() as $cfg ) {
		$secret_key = isset( $cfg['secret_key'] ) ? (string) $cfg['secret_key'] : '';
		if ( '' === $secret_key ) {
			continue;
		}
		$hex = hash_hmac( 'sha256', $base, $secret_key );
		$b64 = base64_encode( hash_hmac( 'sha256', $base, $secret_key, true ) );
		if ( hash_equals( $hex, $hmac ) || hash_equals( $b64, $hmac ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Non-blocking loopback so wp-cron runs the just-scheduled sync promptly,
 * without the reliability of waiting for the next visitor.
 *
 * @return void
 */
function bokun_webhook_spawn_cron() {
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		return; // A real server cron is driving wp-cron; nothing to nudge.
	}

	$url = site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) );
	wp_remote_post(
		$url,
		array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		)
	);
}

/**
 * Background job: run the import for a webhook trigger.
 *
 * Reuses the same fetch+save pipeline, but widens the start-date search window
 * so bookings the normal window skips — long-lead bookings (tour more than a
 * month out) and older cancellations (tour well in the past) — are still
 * refreshed. If a change arrives while this import is running, a trailing run
 * is queued so no acknowledged event is lost.
 *
 * @return void
 */
function bokun_webhook_run_sync() {
	update_option( BOKUN_WEBHOOK_LAST_RUN_OPTION, time(), false );

	// Snapshot: clear the pending flag now, so any change arriving during the
	// import re-sets it and triggers the trailing run below.
	delete_option( BOKUN_WEBHOOK_PENDING_OPTION );

	// Widen the start-date window for this run (same verified search path).
	$window = (int) apply_filters( 'bokun_webhook_sync_window_days', 730 );
	if ( $window < 0 ) {
		$window = 0;
	}
	$widen = function () use ( $window ) {
		return $window;
	};
	add_filter( 'bokun_booking_start_date_lookback_days', $widen, 99 );
	add_filter( 'bokun_booking_start_date_lookahead_days', $widen, 99 );

	if ( function_exists( 'bokun_run_import_for_all_contexts' ) ) {
		bokun_run_import_for_all_contexts( 'webhook' );
	} elseif ( function_exists( 'bokun_run_daily_import' ) ) {
		bokun_run_daily_import();
	}

	remove_filter( 'bokun_booking_start_date_lookback_days', $widen, 99 );
	remove_filter( 'bokun_booking_start_date_lookahead_days', $widen, 99 );

	// A change arrived while importing → its data may post-date what this run
	// fetched, so queue a guaranteed trailing sync (throttled to the cooldown).
	if ( get_option( BOKUN_WEBHOOK_PENDING_OPTION ) ) {
		bokun_webhook_schedule_sync();
	}
}
add_action( BOKUN_WEBHOOK_SYNC_HOOK, 'bokun_webhook_run_sync' );

/**
 * Admin action: regenerate the secret token (invalidates the old URL).
 */
add_action( 'admin_post_bokun_regenerate_webhook_secret', 'bokun_webhook_handle_regenerate' );
function bokun_webhook_handle_regenerate() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'BOKUN_txt_domain' ) );
	}
	check_admin_referer( 'bokun_regenerate_webhook_secret' );

	bokun_webhook_regenerate_secret();

	$referer = wp_get_referer();
	wp_safe_redirect( $referer ? add_query_arg( 'bokun_webhook', 'regenerated', $referer ) : admin_url() );
	exit;
}
