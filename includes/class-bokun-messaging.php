<?php
/**
 * Bokun Messaging — AI-assisted customer reply engine (Slice 1).
 *
 * This module ingests inbound customer messages (currently the Viator /
 * TripAdvisor "expmessaging" email channel), matches each one to the Bokun
 * booking it belongs to, asks Claude to draft a reply using the booking's full
 * context, and surfaces the draft in an "AI Inbox" admin screen for a human to
 * Approve / Edit / Reject. Nothing is ever sent without an explicit click
 * (draft-and-confirm only), and the whole feature is inert until it is enabled
 * in settings AND credentials are provided.
 *
 * Design notes:
 *  - Self-contained: own options (bokun_msg_*), own DB table, own admin page,
 *    own AJAX actions, own cron event. It does not touch the live front-end
 *    dashboard. Removing this one include disables the feature cleanly.
 *  - Channel-pluggable: the Viator-email specifics (how a message is matched to
 *    a booking and how a reply is routed back) live behind a tiny "channel"
 *    seam so other channels can be added later without reworking the pipeline.
 *  - Claude is called over raw HTTPS via wp_remote_post() — the same transport
 *    the plugin already uses for the Bokun API — rather than a Composer SDK.
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Bokun_Messaging' ) ) {

	class Bokun_Messaging {

		/** Schema version for the messages table (bump to trigger dbDelta). */
		const DB_VERSION = '1.0.0';

		/** Option that stores the installed DB version. */
		const DB_VERSION_OPTION = 'bokun_msg_db_version';

		/** Cron hook that polls the mailbox. */
		const CRON_HOOK = 'bokun_msg_poll_mailbox';

		/** Custom cron schedule name (near-real-time polling). */
		const CRON_SCHEDULE = 'bokun_msg_every_two_minutes';

		/** Anthropic Messages API endpoint. */
		const CLAUDE_ENDPOINT = 'https://api.anthropic.com/v1/messages';

		/** Anthropic API version header value. */
		const CLAUDE_API_VERSION = '2023-06-01';

		/** Default model (configurable in settings). */
		const DEFAULT_MODEL = 'claude-opus-5-5';

		/**
		 * Booking post meta that caches the per-thread reply address once a
		 * booking has been matched to one, so later messages match instantly.
		 */
		const META_THREAD_ADDRESS = '_viator_messaging_address';

		/** Message lifecycle statuses. */
		const STATUS_RECEIVED  = 'received';       // Inbound stored, no draft yet.
		const STATUS_NEEDS_MATCH = 'needs_match';  // Inbound stored but no booking matched.
		const STATUS_DRAFT     = 'draft_pending';  // AI draft ready for review.
		const STATUS_APPROVED  = 'draft_approved'; // Approved, send in progress.
		const STATUS_SENT      = 'sent';           // Reply delivered.
		const STATUS_IGNORED   = 'ignored';        // Dismissed by a human.
		const STATUS_ERROR     = 'error';          // Draft/send failed.

		/** @var string Fully-qualified messages table name. */
		private $table;

		public function __construct() {
			global $wpdb;
			$this->table = $wpdb->prefix . 'bokun_messages';

			// Make sure the table exists / is current on load (covers the case
			// where the plugin was updated without re-activation).
			add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );

			// Cron wiring.
			add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
			add_action( self::CRON_HOOK, array( $this, 'poll_mailbox' ) );
			add_action( 'init', array( $this, 'maybe_schedule_cron' ) );

			// Admin UI.
			add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 20 );

			// AJAX actions (all admin-only; capability + nonce checked inside).
			add_action( 'wp_ajax_bokun_msg_save_settings', array( $this, 'ajax_save_settings' ) );
			add_action( 'wp_ajax_bokun_msg_fetch_now', array( $this, 'ajax_fetch_now' ) );
			add_action( 'wp_ajax_bokun_msg_generate_draft', array( $this, 'ajax_generate_draft' ) );
			add_action( 'wp_ajax_bokun_msg_approve_send', array( $this, 'ajax_approve_send' ) );
			add_action( 'wp_ajax_bokun_msg_ignore', array( $this, 'ajax_ignore' ) );
			add_action( 'wp_ajax_bokun_msg_assign_booking', array( $this, 'ajax_assign_booking' ) );
		}

		/* ------------------------------------------------------------------ */
		/* Activation / schema                                                */
		/* ------------------------------------------------------------------ */

		/**
		 * Create or migrate the messages table. Safe to call repeatedly; only
		 * runs dbDelta when the stored version differs from DB_VERSION.
		 */
		public function maybe_upgrade_db() {
			if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
				return;
			}
			$this->install_table();
		}

		/**
		 * Run on plugin activation (wired from the main plugin file).
		 */
		public function activate() {
			$this->install_table();
			$this->maybe_schedule_cron();
		}

		/**
		 * Clear the cron event on deactivation.
		 */
		public function deactivate() {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
		}

		private function install_table() {
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();
			$table           = $this->table;

			$sql = "CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				channel VARCHAR(32) NOT NULL DEFAULT 'viator_email',
				direction VARCHAR(16) NOT NULL DEFAULT 'inbound',
				thread_key VARCHAR(191) NOT NULL DEFAULT '',
				from_email VARCHAR(191) NOT NULL DEFAULT '',
				to_email VARCHAR(191) NOT NULL DEFAULT '',
				subject TEXT NULL,
				body LONGTEXT NULL,
				message_uid VARCHAR(191) NOT NULL DEFAULT '',
				status VARCHAR(32) NOT NULL DEFAULT 'received',
				draft_body LONGTEXT NULL,
				ai_model VARCHAR(64) NOT NULL DEFAULT '',
				error_text TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				KEY booking_post_id (booking_post_id),
				KEY thread_key (thread_key),
				KEY status (status),
				KEY message_uid (message_uid)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}

		/* ------------------------------------------------------------------ */
		/* Settings                                                           */
		/* ------------------------------------------------------------------ */

		/**
		 * Settings accessor with defaults. All settings live under the single
		 * option 'bokun_msg_settings' to keep the options table tidy.
		 *
		 * @return array
		 */
		public function get_settings() {
			$defaults = array(
				'enabled'       => 0,
				'claude_api_key'=> '',
				'model'         => self::DEFAULT_MODEL,
				'max_tokens'    => 1024,
				'auto_draft'    => 1,
				'system_prompt' => $this->default_system_prompt(),
				'reply_from'    => get_option( 'admin_email' ),
				'sender_filter' => 'expmessaging.tripadvisor.com',
				'imap_host'     => '',
				'imap_port'     => 993,
				'imap_ssl'      => 1,
				'imap_user'     => '',
				'imap_pass'     => '',
				'imap_mailbox'  => 'INBOX',
			);

			$stored = get_option( 'bokun_msg_settings', array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			return array_merge( $defaults, $stored );
		}

		private function default_system_prompt() {
			return "You are a helpful, warm, and professional customer-service assistant for a tour and activity operator. "
				. "You are drafting a reply to a customer who has messaged about their booking. "
				. "Use the booking context provided (traveler name, product/tour, date, confirmation code, status) to answer accurately and personally. "
				. "Be concise and friendly. Do not invent details you were not given — if information is missing, say you will check and follow up. "
				. "Never promise refunds, date changes, or cancellations as confirmed; say you will arrange or confirm them. "
				. "Sign off politely. Write only the body of the reply — no subject line, no email headers.";
		}

		public function is_enabled() {
			$s = $this->get_settings();
			return ! empty( $s['enabled'] ) && ! empty( $s['claude_api_key'] );
		}

		/* ------------------------------------------------------------------ */
		/* Cron                                                                */
		/* ------------------------------------------------------------------ */

		public function register_cron_schedule( $schedules ) {
			if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
				$schedules[ self::CRON_SCHEDULE ] = array(
					'interval' => 120,
					'display'  => __( 'Every 2 minutes (Bokun AI Inbox)', 'BOKUN_txt_domain' ),
				);
			}
			return $schedules;
		}

		public function maybe_schedule_cron() {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
			}
		}

		/* ------------------------------------------------------------------ */
		/* Ingestion (IMAP)                                                    */
		/* ------------------------------------------------------------------ */

		/**
		 * Cron entry point: poll the mailbox for new customer messages.
		 */
		public function poll_mailbox() {
			if ( ! $this->is_enabled() ) {
				return;
			}
			$this->ingest_via_imap();
		}

		/**
		 * Connect to the configured IMAP mailbox, pull unseen messages, match
		 * and store each, and (optionally) generate a draft.
		 *
		 * @return array{fetched:int, matched:int, errors:string[]}
		 */
		public function ingest_via_imap() {
			$result = array( 'fetched' => 0, 'matched' => 0, 'errors' => array() );

			if ( ! function_exists( 'imap_open' ) ) {
				$result['errors'][] = __( 'The PHP IMAP extension is not installed on this server. Ask your host to enable ext-imap, or use the webhook ingestion route.', 'BOKUN_txt_domain' );
				return $result;
			}

			$s = $this->get_settings();
			if ( empty( $s['imap_host'] ) || empty( $s['imap_user'] ) || empty( $s['imap_pass'] ) ) {
				$result['errors'][] = __( 'IMAP mailbox credentials are not fully configured.', 'BOKUN_txt_domain' );
				return $result;
			}

			$flags   = '/imap' . ( ! empty( $s['imap_ssl'] ) ? '/ssl' : '' );
			$mailbox = '{' . $s['imap_host'] . ':' . (int) $s['imap_port'] . $flags . '}' . $s['imap_mailbox'];

			// imap_open emits warnings on failure; suppress and read the error.
			$inbox = @imap_open( $mailbox, $s['imap_user'], $s['imap_pass'], 0, 1 );
			if ( ! $inbox ) {
				$err = function_exists( 'imap_last_error' ) ? imap_last_error() : '';
				$result['errors'][] = sprintf(
					/* translators: %s: IMAP error detail. */
					__( 'Could not connect to the mailbox: %s', 'BOKUN_txt_domain' ),
					$err ? $err : __( 'unknown error', 'BOKUN_txt_domain' )
				);
				return $result;
			}

			$unseen = imap_search( $inbox, 'UNSEEN' );
			if ( is_array( $unseen ) ) {
				// Oldest first so a thread's messages store in order.
				sort( $unseen );
				foreach ( $unseen as $msg_number ) {
					$stored = $this->ingest_one_imap_message( $inbox, $msg_number, $s );
					if ( $stored['stored'] ) {
						$result['fetched']++;
						if ( $stored['matched'] ) {
							$result['matched']++;
						}
					}
				}
			}

			imap_close( $inbox );
			return $result;
		}

		/**
		 * Parse and store a single IMAP message. Marks it Seen so it is not
		 * re-ingested.
		 *
		 * @return array{stored:bool, matched:bool, message_id:int}
		 */
		private function ingest_one_imap_message( $inbox, $msg_number, $settings ) {
			$out = array( 'stored' => false, 'matched' => false, 'message_id' => 0 );

			$overview = imap_fetch_overview( $inbox, (string) $msg_number, 0 );
			$overview = is_array( $overview ) && ! empty( $overview ) ? $overview[0] : null;
			if ( ! $overview ) {
				return $out;
			}

			$from_email = isset( $overview->from ) ? $this->extract_email( $overview->from ) : '';
			$to_email   = isset( $overview->to ) ? $this->extract_email( $overview->to ) : '';
			$subject    = isset( $overview->subject ) ? $this->decode_mime( $overview->subject ) : '';
			$uid        = isset( $overview->message_id ) ? $overview->message_id : ( 'imap-' . $msg_number );

			// Optional sender filter (e.g. only ingest Viator/TripAdvisor relay).
			if ( ! empty( $settings['sender_filter'] ) ) {
				if ( stripos( $from_email, $settings['sender_filter'] ) === false
					&& stripos( (string) $to_email, $settings['sender_filter'] ) === false
					&& stripos( (string) ( $overview->from ?? '' ), $settings['sender_filter'] ) === false ) {
					// Not a channel message; leave it unread and skip.
					return $out;
				}
			}

			// Dedupe on Message-ID.
			if ( $this->message_uid_exists( $uid ) ) {
				imap_setflag_full( $inbox, (string) $msg_number, '\\Seen' );
				return $out;
			}

			$body = $this->get_imap_plain_body( $inbox, $msg_number );

			// The per-thread reply address is the anchor. On the Viator channel
			// the customer's relay address (S-xxxx@expmessaging.tripadvisor.com)
			// is what we reply to, so capture it as the thread key.
			$thread_key = $this->pick_thread_key( $from_email, $to_email, $settings );

			$booking_id = $this->match_booking( $thread_key, $subject, $body );

			$now        = current_time( 'mysql' );
			$message_id = $this->insert_message( array(
				'booking_post_id' => $booking_id,
				'channel'         => 'viator_email',
				'direction'       => 'inbound',
				'thread_key'      => $thread_key,
				'from_email'      => $from_email,
				'to_email'        => $to_email,
				'subject'         => $subject,
				'body'            => $body,
				'message_uid'     => $uid,
				'status'          => $booking_id ? self::STATUS_RECEIVED : self::STATUS_NEEDS_MATCH,
				'created_at'      => $now,
				'updated_at'      => $now,
			) );

			imap_setflag_full( $inbox, (string) $msg_number, '\\Seen' );

			if ( ! $message_id ) {
				return $out;
			}

			$out['stored']     = true;
			$out['matched']    = (bool) $booking_id;
			$out['message_id'] = $message_id;

			// If matched and auto-draft is on, generate a draft right away.
			if ( $booking_id && ! empty( $settings['auto_draft'] ) ) {
				$this->generate_draft_for_message( $message_id );
			}

			return $out;
		}

		/**
		 * Pick the thread/reply key for an inbound message. For the Viator
		 * channel this is whichever side of the message carries the relay
		 * address — that is the address we reply to.
		 */
		private function pick_thread_key( $from_email, $to_email, $settings ) {
			$filter = ! empty( $settings['sender_filter'] ) ? $settings['sender_filter'] : '';
			if ( $filter && stripos( (string) $from_email, $filter ) !== false ) {
				return $from_email;
			}
			if ( $filter && stripos( (string) $to_email, $filter ) !== false ) {
				return $to_email;
			}
			// No filter configured: default to the sender.
			return $from_email;
		}

		private function get_imap_plain_body( $inbox, $msg_number ) {
			$structure = imap_fetchstructure( $inbox, $msg_number );
			$body      = '';

			if ( ! $structure ) {
				$body = imap_body( $inbox, $msg_number );
				return $this->clean_body( $body );
			}

			if ( empty( $structure->parts ) ) {
				// Single-part message.
				$raw  = imap_body( $inbox, $msg_number );
				$body = $this->decode_part( $raw, isset( $structure->encoding ) ? $structure->encoding : 0 );
			} else {
				// Multipart: prefer text/plain, fall back to text/html.
				$plain = '';
				$html  = '';
				foreach ( $structure->parts as $idx => $part ) {
					$part_number = (string) ( $idx + 1 );
					$raw         = imap_fetchbody( $inbox, $msg_number, $part_number );
					$decoded     = $this->decode_part( $raw, isset( $part->encoding ) ? $part->encoding : 0 );
					$subtype     = isset( $part->subtype ) ? strtoupper( $part->subtype ) : '';
					if ( 'PLAIN' === $subtype && '' === $plain ) {
						$plain = $decoded;
					} elseif ( 'HTML' === $subtype && '' === $html ) {
						$html = $decoded;
					}
				}
				$body = $plain !== '' ? $plain : wp_strip_all_tags( $html );
			}

			return $this->clean_body( $body );
		}

		private function decode_part( $raw, $encoding ) {
			switch ( (int) $encoding ) {
				case 3: // base64
					return base64_decode( $raw );
				case 4: // quoted-printable
					return quoted_printable_decode( $raw );
				default:
					return $raw;
			}
		}

		private function clean_body( $body ) {
			$body = (string) $body;
			// Normalise newlines and trim trailing whitespace per line.
			$body = str_replace( "\r\n", "\n", $body );
			return trim( $body );
		}

		private function decode_mime( $text ) {
			if ( ! function_exists( 'imap_mime_header_decode' ) ) {
				return $text;
			}
			$decoded = '';
			$parts   = imap_mime_header_decode( $text );
			foreach ( (array) $parts as $part ) {
				$decoded .= $part->text;
			}
			return $decoded !== '' ? $decoded : $text;
		}

		private function extract_email( $raw ) {
			if ( preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', (string) $raw, $m ) ) {
				return strtolower( $m[0] );
			}
			return '';
		}

		/* ------------------------------------------------------------------ */
		/* Matching                                                            */
		/* ------------------------------------------------------------------ */

		/**
		 * Match an inbound message to a Bokun booking post.
		 *
		 * Strategy, in order of confidence:
		 *   1. A booking already tagged with this thread address (fast path for
		 *      every message after the first in a thread).
		 *   2. A previous message in our table on the same thread key that is
		 *      already matched.
		 *   3. External booking reference or confirmation code found in the
		 *      subject/body.
		 *
		 * Returns 0 when no confident match is found (the message then lands in
		 * the "Needs match" queue for a human to assign).
		 *
		 * @return int Booking post ID, or 0.
		 */
		public function match_booking( $thread_key, $subject, $body ) {
			// 1. Booking already tagged with this thread address.
			if ( $thread_key ) {
				$by_meta = $this->find_booking_by_meta( self::META_THREAD_ADDRESS, $thread_key );
				if ( $by_meta ) {
					return $by_meta;
				}

				// 2. Earlier matched message on the same thread.
				global $wpdb;
				$prior = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT booking_post_id FROM {$this->table} WHERE thread_key = %s AND booking_post_id > 0 ORDER BY id DESC LIMIT 1",
						$thread_key
					)
				);
				if ( $prior ) {
					return $prior;
				}
			}

			// 3. Reference / confirmation code in the text.
			$haystack = $subject . "\n" . $body;

			// Bokun confirmation codes and Viator references vary, so try a few
			// common shapes. Longest / most specific first.
			$candidates = array();
			if ( preg_match_all( '/\b([A-Z]{2,4}-?\d{4,})\b/', strtoupper( $haystack ), $m ) ) {
				$candidates = array_merge( $candidates, $m[1] );
			}
			if ( preg_match_all( '/\b(\d{8,})\b/', $haystack, $m ) ) {
				$candidates = array_merge( $candidates, $m[1] );
			}

			foreach ( array_unique( $candidates ) as $code ) {
				foreach ( array( '_confirmation_code', '_external_booking_reference' ) as $meta_key ) {
					$hit = $this->find_booking_by_meta( $meta_key, $code );
					if ( $hit ) {
						// Cache the thread address so future messages match fast.
						if ( $thread_key ) {
							update_post_meta( $hit, self::META_THREAD_ADDRESS, $thread_key );
						}
						return $hit;
					}
				}
			}

			return 0;
		}

		private function find_booking_by_meta( $meta_key, $meta_value ) {
			$query = new WP_Query( array(
				'post_type'      => 'bokun_booking',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'value'   => $meta_value,
						'compare' => '=',
					),
				),
			) );
			$ids = $query->posts;
			return ! empty( $ids ) ? (int) $ids[0] : 0;
		}

		/* ------------------------------------------------------------------ */
		/* Claude draft generation                                             */
		/* ------------------------------------------------------------------ */

		/**
		 * Generate (or regenerate) an AI draft reply for an inbound message.
		 *
		 * @param int $message_id Row id of the inbound message.
		 * @return array{ok:bool, error:string}
		 */
		public function generate_draft_for_message( $message_id ) {
			$message = $this->get_message( $message_id );
			if ( ! $message ) {
				return array( 'ok' => false, 'error' => __( 'Message not found.', 'BOKUN_txt_domain' ) );
			}
			if ( empty( $message['booking_post_id'] ) ) {
				return array( 'ok' => false, 'error' => __( 'Message is not matched to a booking yet.', 'BOKUN_txt_domain' ) );
			}

			$s = $this->get_settings();
			if ( empty( $s['claude_api_key'] ) ) {
				$this->update_message( $message_id, array(
					'status'     => self::STATUS_ERROR,
					'error_text' => __( 'No Claude API key configured.', 'BOKUN_txt_domain' ),
				) );
				return array( 'ok' => false, 'error' => __( 'No Claude API key configured.', 'BOKUN_txt_domain' ) );
			}

			$context_block = $this->build_booking_context( (int) $message['booking_post_id'] );
			$thread_block  = $this->build_thread_context( $message );

			$user_prompt = $context_block . "\n\n" . $thread_block
				. "\n\nDraft the operator's reply to the customer's latest message above.";

			$draft = $this->call_claude( $s, $user_prompt );
			if ( is_wp_error( $draft ) ) {
				$this->update_message( $message_id, array(
					'status'     => self::STATUS_ERROR,
					'error_text' => $draft->get_error_message(),
				) );
				return array( 'ok' => false, 'error' => $draft->get_error_message() );
			}

			$this->update_message( $message_id, array(
				'status'     => self::STATUS_DRAFT,
				'draft_body' => $draft,
				'ai_model'   => $s['model'],
				'error_text' => '',
			) );

			return array( 'ok' => true, 'error' => '' );
		}

		/**
		 * Assemble the booking context block from the booking post meta.
		 */
		private function build_booking_context( $booking_id ) {
			$get = function ( $key ) use ( $booking_id ) {
				$v = get_post_meta( $booking_id, $key, true );
				return $v !== '' ? $v : 'N/A';
			};

			$lines   = array();
			$lines[] = 'BOOKING CONTEXT';
			$lines[] = 'Customer: ' . trim( $get( '_first_name' ) . ' ' . $get( '_last_name' ) );
			$lines[] = 'Product/Tour: ' . $get( '_product_title' );
			$lines[] = 'Confirmation code: ' . $get( '_confirmation_code' );
			$lines[] = 'External reference: ' . $get( '_external_booking_reference' );
			$lines[] = 'Start date: ' . $get( '_original_start_datetime' );
			$lines[] = 'Booking status: ' . $get( '_productBookings_0_status' );

			$inclusions = get_post_meta( $booking_id, 'inclusions_clean', true );
			if ( ! empty( $inclusions ) ) {
				$lines[] = 'Inclusions / notes: ' . ( is_string( $inclusions ) ? $inclusions : wp_json_encode( $inclusions ) );
			}

			return implode( "\n", $lines );
		}

		/**
		 * Assemble the prior conversation for this thread, oldest first, ending
		 * with the customer's latest message.
		 */
		private function build_thread_context( $latest_message ) {
			global $wpdb;

			$rows = array();
			if ( ! empty( $latest_message['thread_key'] ) ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT direction, body, draft_body, status, created_at FROM {$this->table} WHERE thread_key = %s AND id <= %d ORDER BY id ASC",
						$latest_message['thread_key'],
						(int) $latest_message['id']
					),
					ARRAY_A
				);
			}

			if ( empty( $rows ) ) {
				$rows = array( array(
					'direction' => 'inbound',
					'body'      => $latest_message['body'],
					'status'    => $latest_message['status'],
				) );
			}

			$lines   = array();
			$lines[] = 'CONVERSATION (oldest first)';
			foreach ( $rows as $row ) {
				$who  = ( 'outbound' === $row['direction'] ) ? 'Operator' : 'Customer';
				$text = ( 'outbound' === $row['direction'] && ! empty( $row['draft_body'] ) && empty( $row['body'] ) )
					? $row['draft_body']
					: $row['body'];
				$lines[] = $who . ': ' . trim( (string) $text );
			}

			return implode( "\n\n", $lines );
		}

		/**
		 * Call the Anthropic Messages API and return the draft text, or a
		 * WP_Error on failure.
		 *
		 * @return string|WP_Error
		 */
		private function call_claude( $settings, $user_prompt ) {
			$model      = ! empty( $settings['model'] ) ? $settings['model'] : self::DEFAULT_MODEL;
			$max_tokens = ! empty( $settings['max_tokens'] ) ? (int) $settings['max_tokens'] : 1024;

			$payload = array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'system'     => $settings['system_prompt'],
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $user_prompt,
					),
				),
			);

			$response = wp_remote_post( self::CLAUDE_ENDPOINT, array(
				'timeout' => 45,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $settings['claude_api_key'],
					'anthropic-version' => self::CLAUDE_API_VERSION,
				),
				'body'    => wp_json_encode( $payload ),
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( $code < 200 || $code >= 300 ) {
				$detail = isset( $data['error']['message'] ) ? $data['error']['message'] : $body;
				return new WP_Error( 'bokun_msg_claude_http', sprintf(
					/* translators: 1: HTTP status, 2: error detail. */
					__( 'Claude API error (HTTP %1$d): %2$s', 'BOKUN_txt_domain' ),
					(int) $code,
					$detail
				) );
			}

			// Safety classifiers may decline with stop_reason "refusal".
			if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
				return new WP_Error( 'bokun_msg_claude_refusal', __( 'Claude declined to draft a reply to this message. Please handle it manually.', 'BOKUN_txt_domain' ) );
			}

			// content is an array of blocks; concatenate the text blocks.
			$text = '';
			if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
				foreach ( $data['content'] as $block ) {
					if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
						$text .= $block['text'];
					}
				}
			}

			$text = trim( $text );
			if ( '' === $text ) {
				return new WP_Error( 'bokun_msg_claude_empty', __( 'Claude returned an empty draft.', 'BOKUN_txt_domain' ) );
			}

			return $text;
		}

		/* ------------------------------------------------------------------ */
		/* Sending                                                             */
		/* ------------------------------------------------------------------ */

		/**
		 * Send an approved reply back out on its channel and record it.
		 *
		 * @param int    $message_id Inbound message being replied to.
		 * @param string $reply_body Final (possibly human-edited) reply text.
		 * @return array{ok:bool, error:string}
		 */
		public function approve_and_send( $message_id, $reply_body ) {
			$message = $this->get_message( $message_id );
			if ( ! $message ) {
				return array( 'ok' => false, 'error' => __( 'Message not found.', 'BOKUN_txt_domain' ) );
			}

			$reply_body = trim( (string) $reply_body );
			if ( '' === $reply_body ) {
				return array( 'ok' => false, 'error' => __( 'The reply is empty.', 'BOKUN_txt_domain' ) );
			}

			$to = $message['thread_key'] ? $message['thread_key'] : $message['from_email'];
			if ( ! is_email( $to ) ) {
				return array( 'ok' => false, 'error' => __( 'No valid reply address for this thread.', 'BOKUN_txt_domain' ) );
			}

			$s       = $this->get_settings();
			$subject = $message['subject'] ? $message['subject'] : __( 'Your booking', 'BOKUN_txt_domain' );
			if ( stripos( $subject, 'Re:' ) !== 0 ) {
				$subject = 'Re: ' . $subject;
			}

			$headers = array();
			if ( ! empty( $s['reply_from'] ) && is_email( $s['reply_from'] ) ) {
				$headers[] = 'From: ' . $s['reply_from'];
				$headers[] = 'Reply-To: ' . $s['reply_from'];
			}

			$sent = wp_mail( $to, $subject, $reply_body, $headers );
			$now  = current_time( 'mysql' );

			if ( ! $sent ) {
				$this->update_message( $message_id, array(
					'status'     => self::STATUS_ERROR,
					'error_text' => __( 'wp_mail() failed to send the reply. Check the site mail configuration.', 'BOKUN_txt_domain' ),
				) );
				return array( 'ok' => false, 'error' => __( 'Sending failed (wp_mail). Check the site mail configuration.', 'BOKUN_txt_domain' ) );
			}

			// Mark the inbound message handled and record the outbound reply.
			$this->update_message( $message_id, array(
				'status'     => self::STATUS_SENT,
				'draft_body' => $reply_body,
				'error_text' => '',
			) );

			$this->insert_message( array(
				'booking_post_id' => (int) $message['booking_post_id'],
				'channel'         => $message['channel'],
				'direction'       => 'outbound',
				'thread_key'      => $message['thread_key'],
				'from_email'      => ! empty( $s['reply_from'] ) ? $s['reply_from'] : '',
				'to_email'        => $to,
				'subject'         => $subject,
				'body'            => $reply_body,
				'message_uid'     => 'sent-' . $message_id . '-' . time(),
				'status'          => self::STATUS_SENT,
				'created_at'      => $now,
				'updated_at'      => $now,
			) );

			return array( 'ok' => true, 'error' => '' );
		}

		/* ------------------------------------------------------------------ */
		/* Data access                                                         */
		/* ------------------------------------------------------------------ */

		private function insert_message( $data ) {
			global $wpdb;
			$wpdb->insert( $this->table, $data );
			return (int) $wpdb->insert_id;
		}

		public function get_message( $id ) {
			global $wpdb;
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", (int) $id ),
				ARRAY_A
			);
			return $row ? $row : null;
		}

		private function update_message( $id, $fields ) {
			global $wpdb;
			$fields['updated_at'] = current_time( 'mysql' );
			return $wpdb->update( $this->table, $fields, array( 'id' => (int) $id ) );
		}

		private function message_uid_exists( $uid ) {
			global $wpdb;
			return (bool) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$this->table} WHERE message_uid = %s LIMIT 1", $uid )
			);
		}

		/**
		 * Inbound messages that still need a human (draft to review, needs
		 * match, or errored), newest first.
		 */
		public function get_open_inbound( $limit = 100 ) {
			global $wpdb;
			$statuses = array( self::STATUS_DRAFT, self::STATUS_NEEDS_MATCH, self::STATUS_RECEIVED, self::STATUS_ERROR );
			$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$args     = $statuses;
			$args[]   = (int) $limit;
			$sql      = "SELECT * FROM {$this->table} WHERE direction = 'inbound' AND status IN ($in) ORDER BY id DESC LIMIT %d";
			return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		}

		/* ------------------------------------------------------------------ */
		/* Admin UI                                                            */
		/* ------------------------------------------------------------------ */

		public function register_admin_menu() {
			add_menu_page(
				__( 'AI Inbox', 'BOKUN_txt_domain' ),
				__( 'AI Inbox', 'BOKUN_txt_domain' ),
				'manage_options',
				'bokun-ai-inbox',
				array( $this, 'render_inbox_page' ),
				'dashicons-email-alt',
				58
			);
			add_submenu_page(
				'bokun-ai-inbox',
				__( 'AI Inbox Settings', 'BOKUN_txt_domain' ),
				__( 'Settings', 'BOKUN_txt_domain' ),
				'manage_options',
				'bokun-ai-inbox-settings',
				array( $this, 'render_settings_page' )
			);
		}

		private function nonce_field() {
			wp_nonce_field( 'bokun_msg_admin', 'bokun_msg_nonce' );
		}

		public function render_inbox_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$enabled = $this->is_enabled();
			$open    = $this->get_open_inbound();
			include __DIR__ . '/bokun_messaging_inbox.view.php';
		}

		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$s        = $this->get_settings();
			$imap_ok  = function_exists( 'imap_open' );
			include __DIR__ . '/bokun_messaging_settings.view.php';
		}

		/* ------------------------------------------------------------------ */
		/* AJAX                                                                */
		/* ------------------------------------------------------------------ */

		private function verify_admin_ajax() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'msg' => __( 'Permission denied.', 'BOKUN_txt_domain' ) ) );
			}
			if ( ! check_ajax_referer( 'bokun_msg_admin', 'nonce', false ) ) {
				wp_send_json_error( array( 'msg' => __( 'Security check failed.', 'BOKUN_txt_domain' ) ) );
			}
		}

		public function ajax_save_settings() {
			$this->verify_admin_ajax();

			$current = $this->get_settings();
			$in      = wp_unslash( $_POST );

			$new = array(
				'enabled'        => ! empty( $in['enabled'] ) ? 1 : 0,
				'claude_api_key' => isset( $in['claude_api_key'] ) ? sanitize_text_field( $in['claude_api_key'] ) : $current['claude_api_key'],
				'model'          => isset( $in['model'] ) ? sanitize_text_field( $in['model'] ) : $current['model'],
				'max_tokens'     => isset( $in['max_tokens'] ) ? max( 128, (int) $in['max_tokens'] ) : $current['max_tokens'],
				'auto_draft'     => ! empty( $in['auto_draft'] ) ? 1 : 0,
				'system_prompt'  => isset( $in['system_prompt'] ) ? sanitize_textarea_field( $in['system_prompt'] ) : $current['system_prompt'],
				'reply_from'     => isset( $in['reply_from'] ) ? sanitize_email( $in['reply_from'] ) : $current['reply_from'],
				'sender_filter'  => isset( $in['sender_filter'] ) ? sanitize_text_field( $in['sender_filter'] ) : $current['sender_filter'],
				'imap_host'      => isset( $in['imap_host'] ) ? sanitize_text_field( $in['imap_host'] ) : $current['imap_host'],
				'imap_port'      => isset( $in['imap_port'] ) ? (int) $in['imap_port'] : $current['imap_port'],
				'imap_ssl'       => ! empty( $in['imap_ssl'] ) ? 1 : 0,
				'imap_user'      => isset( $in['imap_user'] ) ? sanitize_text_field( $in['imap_user'] ) : $current['imap_user'],
				'imap_mailbox'   => isset( $in['imap_mailbox'] ) ? sanitize_text_field( $in['imap_mailbox'] ) : $current['imap_mailbox'],
			);

			// Only overwrite the password when a new one is supplied.
			if ( isset( $in['imap_pass'] ) && '' !== $in['imap_pass'] ) {
				$new['imap_pass'] = $in['imap_pass'];
			} else {
				$new['imap_pass'] = $current['imap_pass'];
			}

			update_option( 'bokun_msg_settings', array_merge( $current, $new ) );
			wp_send_json_success( array( 'msg' => __( 'Settings saved.', 'BOKUN_txt_domain' ) ) );
		}

		public function ajax_fetch_now() {
			$this->verify_admin_ajax();
			if ( ! $this->is_enabled() ) {
				wp_send_json_error( array( 'msg' => __( 'Enable the feature and add a Claude API key first.', 'BOKUN_txt_domain' ) ) );
			}
			$result = $this->ingest_via_imap();
			if ( ! empty( $result['errors'] ) ) {
				wp_send_json_error( array( 'msg' => implode( ' ', $result['errors'] ) ) );
			}
			wp_send_json_success( array(
				'msg' => sprintf(
					/* translators: 1: fetched count, 2: matched count. */
					__( 'Fetched %1$d new message(s); %2$d matched to a booking.', 'BOKUN_txt_domain' ),
					(int) $result['fetched'],
					(int) $result['matched']
				),
			) );
		}

		public function ajax_generate_draft() {
			$this->verify_admin_ajax();
			$id     = isset( $_POST['message_id'] ) ? (int) $_POST['message_id'] : 0;
			$result = $this->generate_draft_for_message( $id );
			if ( empty( $result['ok'] ) ) {
				wp_send_json_error( array( 'msg' => $result['error'] ) );
			}
			$message = $this->get_message( $id );
			wp_send_json_success( array( 'draft' => $message ? $message['draft_body'] : '' ) );
		}

		public function ajax_approve_send() {
			$this->verify_admin_ajax();
			$id    = isset( $_POST['message_id'] ) ? (int) $_POST['message_id'] : 0;
			$reply = isset( $_POST['reply_body'] ) ? wp_unslash( $_POST['reply_body'] ) : '';
			$reply = sanitize_textarea_field( $reply );
			$result = $this->approve_and_send( $id, $reply );
			if ( empty( $result['ok'] ) ) {
				wp_send_json_error( array( 'msg' => $result['error'] ) );
			}
			wp_send_json_success( array( 'msg' => __( 'Reply sent.', 'BOKUN_txt_domain' ) ) );
		}

		public function ajax_ignore() {
			$this->verify_admin_ajax();
			$id = isset( $_POST['message_id'] ) ? (int) $_POST['message_id'] : 0;
			$this->update_message( $id, array( 'status' => self::STATUS_IGNORED ) );
			wp_send_json_success( array( 'msg' => __( 'Message dismissed.', 'BOKUN_txt_domain' ) ) );
		}

		public function ajax_assign_booking() {
			$this->verify_admin_ajax();
			$id         = isset( $_POST['message_id'] ) ? (int) $_POST['message_id'] : 0;
			$booking_id = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;

			if ( ! $id || ! $booking_id || 'bokun_booking' !== get_post_type( $booking_id ) ) {
				wp_send_json_error( array( 'msg' => __( 'Invalid booking ID.', 'BOKUN_txt_domain' ) ) );
			}

			$message = $this->get_message( $id );
			$this->update_message( $id, array(
				'booking_post_id' => $booking_id,
				'status'          => self::STATUS_RECEIVED,
			) );

			// Remember the thread address on the booking for next time.
			if ( $message && ! empty( $message['thread_key'] ) ) {
				update_post_meta( $booking_id, self::META_THREAD_ADDRESS, $message['thread_key'] );
			}

			// Draft immediately so the operator can act in one step.
			$this->generate_draft_for_message( $id );
			$updated = $this->get_message( $id );

			wp_send_json_success( array(
				'msg'    => __( 'Booking assigned and draft generated.', 'BOKUN_txt_domain' ),
				'draft'  => $updated ? $updated['draft_body'] : '',
				'status' => $updated ? $updated['status'] : '',
			) );
		}
	}
}
