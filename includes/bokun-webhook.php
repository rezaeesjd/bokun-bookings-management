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
if ( ! defined( 'BOKUN_WEBHOOK_COOLDOWN_TRANSIENT' ) ) {
	define( 'BOKUN_WEBHOOK_COOLDOWN_TRANSIENT', 'bokun_webhook_cooldown' );
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
	$topic = $request->get_header( 'x-bokun-topic' );
	update_option(
		BOKUN_WEBHOOK_LAST_OPTION,
		array(
			'time'  => time(),
			'topic' => is_string( $topic ) ? sanitize_text_field( $topic ) : '',
		),
		false
	);

	// Debounce bursts: a full windowed sync covers every change in the window,
	// so one run per cooldown window is enough and protects the Bokun API.
	$cooldown = (int) apply_filters( 'bokun_webhook_sync_cooldown', 60 );
	if ( $cooldown > 0 && get_transient( BOKUN_WEBHOOK_COOLDOWN_TRANSIENT ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'queued' => false, 'reason' => 'debounced' ), 200 );
	}
	if ( $cooldown > 0 ) {
		set_transient( BOKUN_WEBHOOK_COOLDOWN_TRANSIENT, 1, $cooldown );
	}

	// Run the import on a background cron event — never inline (Bokun's ~5s limit).
	if ( ! wp_next_scheduled( BOKUN_WEBHOOK_SYNC_HOOK ) ) {
		$delay = (int) apply_filters( 'bokun_webhook_sync_delay', 0 );
		wp_schedule_single_event( time() + max( 0, $delay ), BOKUN_WEBHOOK_SYNC_HOOK );
	}

	// Nudge wp-cron to run the due event promptly, without blocking this reply.
	bokun_webhook_spawn_cron();

	return new WP_REST_Response( array( 'ok' => true, 'queued' => true ), 200 );
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
 * Background job: run the same full windowed import the daily cron runs.
 */
add_action( BOKUN_WEBHOOK_SYNC_HOOK, 'bokun_webhook_run_sync' );
function bokun_webhook_run_sync() {
	if ( function_exists( 'bokun_run_import_for_all_contexts' ) ) {
		bokun_run_import_for_all_contexts( 'webhook' );
	} elseif ( function_exists( 'bokun_run_daily_import' ) ) {
		bokun_run_daily_import();
	}
}

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
