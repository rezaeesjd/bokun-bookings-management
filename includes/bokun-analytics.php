<?php
/**
 * Bokun analytics source data layer.
 *
 * Prepares a flat, query-friendly table of booking records that powers the
 * analytics dashboard. Each booking (the `bokun_booking` custom post type) is
 * denormalized into a single row holding the fields the dashboard reports on,
 * sourced from the flattened post meta written by
 * {@see bokun_save_all_fields_as_meta()}, the `booking_status` taxonomy, and
 * the aggregated price-category meta.
 *
 * Only bookings created within the trailing window (three months by default)
 * are kept, so the table stays small and fast to scan for charts.
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Schema version for the analytics source table. Bump when columns change so
 * installed sites re-run dbDelta on the next admin request.
 */
define( 'BOKUN_ANALYTICS_DB_VERSION', '1.5.0' );

/** Option key tracking the installed analytics schema version. */
define( 'BOKUN_ANALYTICS_DB_VERSION_OPTION', 'bokun_analytics_db_version' );

/** Option key tracking the last successful full rebuild timestamp (GMT). */
define( 'BOKUN_ANALYTICS_LAST_BUILT_OPTION', 'bokun_analytics_last_built' );

/**
 * Fully-qualified name of the analytics source table.
 *
 * @return string
 */
function bokun_analytics_get_table_name() {
    global $wpdb;

    return $wpdb->prefix . 'bokun_analytics_source';
}

/**
 * Number of trailing months of bookings (by creation date) to retain.
 *
 * @return int
 */
function bokun_analytics_get_window_months() {
    $months = (int) apply_filters( 'bokun_analytics_window_months', 3 );

    return $months > 0 ? $months : 3;
}

/**
 * Start of the retention window as a `Y-m-d H:i:s` string in site time.
 *
 * Bookings created on or after this moment are included in the source table.
 *
 * @return string
 */
function bokun_analytics_get_window_start() {
    $months = bokun_analytics_get_window_months();

    $start = new DateTime( 'now', wp_timezone() );
    $start->modify( '-' . $months . ' months' );
    $start->setTime( 0, 0, 0 );

    return $start->format( 'Y-m-d H:i:s' );
}

/**
 * Create or upgrade the analytics source table via dbDelta.
 *
 * Safe to call repeatedly; dbDelta only applies the diff.
 *
 * @return void
 */
function bokun_analytics_install_table() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name      = bokun_analytics_get_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    // Detect whether this call UPGRADES an already-populated table (vs. a first
    // install), so the migration flag is set no matter which path reached here
    // — the admin ensure-schema path or the activation hook, which both call
    // this function directly.
    $previous_version = get_option( BOKUN_ANALYTICS_DB_VERSION_OPTION );
    $table_existed    = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name );

    $sql = "CREATE TABLE $table_name (
        post_id BIGINT(20) UNSIGNED NOT NULL,
        confirmation_code VARCHAR(191) NULL,
        external_booking_reference VARCHAR(191) NULL,
        channel_title VARCHAR(191) NULL,
        channel_id VARCHAR(191) NULL,
        channel_channel_type VARCHAR(100) NULL,
        seller_title VARCHAR(191) NULL,
        seller_id VARCHAR(191) NULL,
        vendor_title VARCHAR(191) NULL,
        vendor_id VARCHAR(191) NULL,
        pb_seller_id VARCHAR(191) NULL,
        pb_seller_title VARCHAR(191) NULL,
        product_external_id VARCHAR(191) NULL,
        product_id VARCHAR(191) NULL,
        partner_page_id VARCHAR(191) NULL,
        result VARCHAR(50) NULL,
        partner_refunded TINYINT(1) NOT NULL DEFAULT 0,
        is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
        payment_method VARCHAR(100) NULL,
        product_title VARCHAR(255) NULL,
        product_option VARCHAR(255) NULL,
        travel_datetime DATETIME NULL,
        created_datetime DATETIME NULL,
        adult_participants INT NOT NULL DEFAULT 0,
        child_participants INT NOT NULL DEFAULT 0,
        infant_participants INT NOT NULL DEFAULT 0,
        language VARCHAR(50) NULL,
        pb_status VARCHAR(100) NULL,
        price_note TEXT NULL,
        price_amount DECIMAL(14,2) NULL,
        product_confirmation_code VARCHAR(191) NULL,
        pb_channel_id VARCHAR(191) NULL,
        currency VARCHAR(10) NULL,
        phone_prefix VARCHAR(20) NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY  (post_id),
        KEY confirmation_code (confirmation_code),
        KEY created_datetime (created_datetime),
        KEY travel_datetime (travel_datetime),
        KEY channel_id (channel_id),
        KEY product_id (product_id),
        KEY partner_page_id (partner_page_id)
    ) $charset_collate;";

    dbDelta( $sql );

    update_option( BOKUN_ANALYTICS_DB_VERSION_OPTION, BOKUN_ANALYTICS_DB_VERSION );

    // An existing populated table whose version actually changed needs its rows
    // repopulated so new/changed columns are filled. Flag a one-time rebuild and
    // reset any in-progress migration cursor; the admin upgrade path turns the
    // flag into a scheduled batched rebuild. A first install has nothing to
    // migrate, so it is skipped.
    if ( $table_existed && $previous_version && $previous_version !== BOKUN_ANALYTICS_DB_VERSION ) {
        update_option( 'bokun_analytics_pending_rebuild', 1, false );
        delete_option( 'bokun_analytics_rebuild_cursor' );
        delete_option( 'bokun_analytics_rebuild_cutoff' );
    }
}

/**
 * Ensure the analytics table exists and matches the current schema version.
 *
 * Runs on admin requests so installs upgraded from an older build pick up the
 * table without re-activating the plugin.
 *
 * @return void
 */
function bokun_analytics_maybe_upgrade() {
    if ( ! is_admin() ) {
        return;
    }

    bokun_analytics_ensure_schema();
    bokun_analytics_maybe_schedule_rebuild();
}
add_action( 'admin_init', 'bokun_analytics_maybe_upgrade', 5 );

/**
 * Schedule the one-time post-upgrade rebuild on wp-cron when one is pending, so
 * rows already in the table pick up new/changed columns automatically. Called
 * from the admin-only upgrade path, so it never fires from a front-end or import
 * request, and the heavy rebuild runs in the background rather than blocking a
 * page load. The manual "Rebuild now" button remains as a fallback.
 *
 * @return void
 */
function bokun_analytics_maybe_schedule_rebuild() {
    if ( ! get_option( 'bokun_analytics_pending_rebuild' ) ) {
        return;
    }

    if ( ! wp_next_scheduled( 'bokun_analytics_run_pending_rebuild' ) ) {
        wp_schedule_single_event( time() + 30, 'bokun_analytics_run_pending_rebuild' );
    }
}

/**
 * Re-sync one page of in-window bookings in place (no truncate) so existing rows
 * pick up new/changed columns after a schema upgrade, without emptying the table
 * or having to finish in a single request. Ordering is fixed by post ID and
 * sync does not change the booking's ID or creation date, so paging is
 * deterministic across batches.
 *
 * @param int $page       1-based page.
 * @param int $batch_size Bookings per page.
 * @return array{page:int,max_pages:int,done:bool}
 */
function bokun_analytics_migrate_batch( $after_id, $cutoff, $batch_size ) {
    global $wpdb;

    $after_id   = max( 0, (int) $after_id );
    $batch_size = (int) $batch_size > 0 ? (int) $batch_size : bokun_analytics_backfill_batch_size();
    $cutoff     = (string) $cutoff;

    $statuses             = array( 'publish', 'draft', 'pending', 'private' );
    $status_placeholders  = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

    // Keyset pagination: always continue from the last processed post ID with a
    // fixed creation-date cutoff, so a shifting result set (window advancing
    // across midnight, deletions, concurrent edits) can never make a numbered
    // page skip unprocessed rows. `bookingcreationdate` is stored as
    // 'Y-m-d H:i:s', which compares correctly as a string.
    $sql = "SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'bookingcreationdate'
        WHERE p.post_type = 'bokun_booking'
          AND p.post_status IN ( {$status_placeholders} )
          AND p.ID > %d
          AND m.meta_value >= %s
        ORDER BY p.ID ASC
        LIMIT %d";

    $params = array_merge( $statuses, array( $after_id, $cutoff, $batch_size ) );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

    $processed = 0;
    $last_id   = $after_id;

    if ( is_array( $ids ) ) {
        foreach ( $ids as $post_id ) {
            $post_id = (int) $post_id;
            bokun_analytics_sync_booking( $post_id );
            $last_id = $post_id;
            $processed++;
        }
    }

    return array(
        'processed' => $processed,
        'last_id'   => $last_id,
        // A short page (fewer than requested) means no rows remain after it.
        'done'      => ( $processed < $batch_size ),
    );
}

/**
 * wp-cron callback: process one batch of the pending post-upgrade rebuild and
 * reschedule the next, so a large backlog never has to repopulate inside a
 * single cron run (a timeout would otherwise leave the table partial and
 * restart from scratch). A keyset cursor (last processed post ID) plus a fixed
 * window cutoff make paging stable; when the last row is done it prunes
 * aged-out rows and clears the flag.
 *
 * @return void
 */
function bokun_analytics_do_pending_rebuild() {
    if ( ! get_option( 'bokun_analytics_pending_rebuild' ) ) {
        delete_option( 'bokun_analytics_rebuild_cursor' );
        delete_option( 'bokun_analytics_rebuild_cutoff' );
        return;
    }

    // Freeze the window cutoff for the whole migration so the set does not shift
    // as time passes across batches.
    $cutoff = get_option( 'bokun_analytics_rebuild_cutoff' );
    if ( ! $cutoff ) {
        $cutoff = bokun_analytics_get_window_start();
        update_option( 'bokun_analytics_rebuild_cutoff', $cutoff, false );
    }

    $after_id = (int) get_option( 'bokun_analytics_rebuild_cursor', 0 );
    $result   = bokun_analytics_migrate_batch( $after_id, $cutoff, bokun_analytics_backfill_batch_size() );

    if ( $result['done'] ) {
        bokun_analytics_prune_window();
        update_option( BOKUN_ANALYTICS_LAST_BUILT_OPTION, current_time( 'mysql', true ) );
        delete_option( 'bokun_analytics_rebuild_cursor' );
        delete_option( 'bokun_analytics_rebuild_cutoff' );
        delete_option( 'bokun_analytics_pending_rebuild' );
    } else {
        update_option( 'bokun_analytics_rebuild_cursor', (int) $result['last_id'], false );
        if ( ! wp_next_scheduled( 'bokun_analytics_run_pending_rebuild' ) ) {
            wp_schedule_single_event( time() + 60, 'bokun_analytics_run_pending_rebuild' );
        }
    }
}
add_action( 'bokun_analytics_run_pending_rebuild', 'bokun_analytics_do_pending_rebuild' );

/**
 * Ensure the analytics table matches the current schema version, regardless of
 * context. Gated by the stored version option, so it runs dbDelta only once per
 * version change and then no-ops. Unlike {@see bokun_analytics_maybe_upgrade()}
 * this is not admin-only: any write path (the status-change AJAX handler,
 * scheduled imports) must call it first, because those can run before any
 * admin request has upgraded the table and would otherwise write to a column
 * that does not exist yet.
 *
 * @return void
 */
function bokun_analytics_ensure_schema() {
    if ( get_option( BOKUN_ANALYTICS_DB_VERSION_OPTION ) === BOKUN_ANALYTICS_DB_VERSION ) {
        return;
    }

    // install_table() flags a one-time rebuild when it upgrades an existing
    // populated table (this runs on any write path and on activation).
    bokun_analytics_install_table();
}

/**
 * Normalize a raw meta value to a trimmed string, treating the plugin's "N/A"
 * placeholder and empty values as null.
 *
 * @param mixed $value Raw meta value.
 * @return string|null
 */
function bokun_analytics_clean( $value ) {
    if ( is_array( $value ) || is_object( $value ) ) {
        return null;
    }

    $value = trim( (string) $value );

    if ( '' === $value || 'N/A' === $value ) {
        return null;
    }

    return $value;
}

/**
 * Resolve the first non-empty meta value from a list of candidate keys.
 *
 * @param int   $post_id Booking post ID.
 * @param array $keys    Candidate meta keys, in priority order.
 * @return string|null
 */
function bokun_analytics_meta( $post_id, $keys ) {
    foreach ( (array) $keys as $key ) {
        $value = bokun_analytics_clean( get_post_meta( $post_id, $key, true ) );

        if ( null !== $value ) {
            return $value;
        }
    }

    return null;
}

/**
 * Convert a raw Bokun date value (epoch seconds/milliseconds, or a parseable
 * date string with timezone information) to a GMT `Y-m-d H:i:s` string.
 *
 * @param string|null $value Candidate value.
 * @return string|null
 */
function bokun_analytics_raw_to_gmt( $value ) {
    if ( null === $value || '' === $value ) {
        return null;
    }

    $value = (string) $value;

    if ( is_numeric( $value ) ) {
        $numeric = (float) $value;

        // API epoch values are sometimes milliseconds; normalize to seconds.
        if ( $numeric > 9999999999 ) {
            $numeric /= 1000;
        }

        return $numeric > 0 ? gmdate( 'Y-m-d H:i:s', (int) $numeric ) : null;
    }

    $timestamp = strtotime( $value );

    return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Derive the booking creation datetime (GMT) used for the retention window.
 *
 * Prefers the raw API value (which carries timezone information) and falls back
 * to the localized `bookingcreationdate` meta, converting it to GMT.
 *
 * @param int $post_id Booking post ID.
 * @return string|null `Y-m-d H:i:s` (GMT) or null when unknown.
 */
function bokun_analytics_get_created_datetime( $post_id ) {
    $raw = bokun_analytics_meta( $post_id, array( '_original_creation_date', 'creationDate' ) );
    $gmt = bokun_analytics_raw_to_gmt( $raw );

    if ( null !== $gmt ) {
        return $gmt;
    }

    // Fall back to the display meta, stored in site-local time.
    $local = bokun_analytics_meta( $post_id, array( 'bookingcreationdate' ) );

    if ( null === $local ) {
        return null;
    }

    $converted = get_gmt_from_date( $local );

    return $converted ? $converted : null;
}

/**
 * Resolve the "result" (full / partial / not-available) from the booking_status
 * taxonomy.
 *
 * @param int $post_id Booking post ID.
 * @return string|null
 */
function bokun_analytics_get_result( $post_id ) {
    $map = array(
        'full'          => 'full',
        'partial'       => 'partial',
        'not-available' => 'not-available',
    );

    foreach ( $map as $slug => $label ) {
        if ( has_term( $slug, 'booking_status', $post_id ) ) {
            return $label;
        }
    }

    return null;
}

/**
 * Whether the booking is cancelled, matching the dashboard's detection: any
 * booking_status term whose slug or name contains "cancel". A cancelled booking
 * earned no revenue, so net revenue treats its gross as zero.
 *
 * @param int $post_id Booking post ID.
 * @return bool
 */
function bokun_analytics_is_cancelled( $post_id ) {
    $terms = get_the_terms( $post_id, 'booking_status' );

    if ( ! $terms || is_wp_error( $terms ) ) {
        return false;
    }

    foreach ( $terms as $term ) {
        if ( false !== stripos( $term->slug, 'cancel' ) || false !== stripos( $term->name, 'cancel' ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Resolve the payment method(s) from the booking_status taxonomy.
 *
 * Multiple selections are comma-joined.
 *
 * @param int $post_id Booking post ID.
 * @return string|null
 */
function bokun_analytics_get_payment_method( $post_id ) {
    $map = array(
        'amex'          => 'Amex',
        'paypal'        => 'PayPal',
        'other-payment' => 'Other',
    );

    $methods = array();

    foreach ( $map as $slug => $label ) {
        if ( has_term( $slug, 'booking_status', $post_id ) ) {
            $methods[] = $label;
        }
    }

    return empty( $methods ) ? null : implode( ', ', $methods );
}

/**
 * Count participants by category keyword from the aggregated price-category
 * meta (`pricecategory1`..`pricecategory5`), each stored as "{count} {title}".
 *
 * Titles are matched case-insensitively: "infant"/"baby" → infant,
 * "child"/"youth"/"kid" → child, "adult"/"senior" → adult.
 *
 * @param int $post_id Booking post ID.
 * @return array{adult:int,child:int,infant:int}
 */
function bokun_analytics_count_participants( $post_id ) {
    $counts = array(
        'adult'  => 0,
        'child'  => 0,
        'infant' => 0,
    );

    for ( $index = 1; $index <= 5; $index++ ) {
        $value = bokun_analytics_clean( get_post_meta( $post_id, 'pricecategory' . $index, true ) );

        if ( null === $value ) {
            continue;
        }

        if ( ! preg_match( '/^\s*(\d+)\s+(.*)$/', $value, $matches ) ) {
            continue;
        }

        $quantity = (int) $matches[1];
        $title    = strtolower( $matches[2] );

        if ( false !== strpos( $title, 'infant' ) || false !== strpos( $title, 'baby' ) ) {
            $counts['infant'] += $quantity;
        } elseif ( false !== strpos( $title, 'child' ) || false !== strpos( $title, 'youth' ) || false !== strpos( $title, 'kid' ) ) {
            $counts['child'] += $quantity;
        } elseif ( false !== strpos( $title, 'adult' ) || false !== strpos( $title, 'senior' ) ) {
            $counts['adult'] += $quantity;
        }
    }

    return $counts;
}

/**
 * Best-effort extraction of a numeric monetary amount from the free-text price
 * note (`productBookings_0_notes_1_body`).
 *
 * The note has no guaranteed format, so this pulls the last number that looks
 * like a monetary value and normalizes thousands/decimal separators. Returns
 * null when no number is found, so totals simply skip unparseable notes.
 *
 * @param string|null $note Raw note text.
 * @return float|null
 */
function bokun_analytics_parse_amount( $note ) {
    if ( null === $note || '' === $note ) {
        return null;
    }

    // Grab number-like tokens such as 1,234.50 / 1.234,50 / 120 / 99.00.
    if ( ! preg_match_all( '/\d[\d.,\s]*\d|\d/', (string) $note, $matches ) || empty( $matches[0] ) ) {
        return null;
    }

    // Prefer the last token (totals usually appear at the end of the note).
    $raw = trim( end( $matches[0] ) );
    $raw = preg_replace( '/\s+/', '', $raw );

    if ( '' === $raw ) {
        return null;
    }

    $has_dot   = false !== strpos( $raw, '.' );
    $has_comma = false !== strpos( $raw, ',' );

    if ( $has_dot && $has_comma ) {
        // The right-most separator is the decimal separator.
        if ( strrpos( $raw, ',' ) > strrpos( $raw, '.' ) ) {
            $raw = str_replace( '.', '', $raw ); // dots are thousands
            $raw = str_replace( ',', '.', $raw );
        } else {
            $raw = str_replace( ',', '', $raw ); // commas are thousands
        }
    } elseif ( $has_comma ) {
        // Treat a comma with exactly two trailing digits as a decimal point;
        // otherwise it is a thousands separator.
        if ( preg_match( '/,\d{2}$/', $raw ) ) {
            $raw = str_replace( ',', '.', $raw );
        } else {
            $raw = str_replace( ',', '', $raw );
        }
    }

    return is_numeric( $raw ) ? round( (float) $raw, 2 ) : null;
}

/**
 * Build the analytics source row for a booking post.
 *
 * @param int $post_id Booking post ID.
 * @return array|null Column => value map, or null when the post is not a
 *                    booking.
 */
function bokun_analytics_build_row( $post_id ) {
    $post_id = (int) $post_id;

    if ( get_post_type( $post_id ) !== 'bokun_booking' ) {
        return null;
    }

    $participants = bokun_analytics_count_participants( $post_id );
    $price_note   = bokun_analytics_meta( $post_id, array( 'productBookings_0_notes_1_body' ) );
    $product_id   = bokun_analytics_meta( $post_id, array( 'productBookings_0_product_id', '_product_id' ) );

    // Partner page id comes from the product tag's `partnerpageid` term meta.
    $partner_page_id = ( null !== $product_id && function_exists( 'bokun_partners_products_resolve_partner_page_id' ) )
        ? bokun_partners_products_resolve_partner_page_id( $product_id )
        : '';

    // Travel datetime: derive from the raw Bokun start value, which carries an
    // absolute instant. The importer stores `post_date` as the UTC wall-clock
    // of that instant but then derives `post_date_gmt` with get_gmt_from_date(),
    // which re-interprets it as site-local time — double-shifting the value on
    // non-UTC sites. So the raw meta is authoritative; get_post_time() is only a
    // fallback for legacy rows saved before the raw meta existed.
    $travel_raw      = bokun_analytics_meta( $post_id, array( '_original_start_datetime', '_original_start_date' ) );
    $travel_datetime = bokun_analytics_raw_to_gmt( $travel_raw );

    if ( null === $travel_datetime ) {
        $travel_gmt      = get_post_time( 'Y-m-d H:i:s', true, $post_id );
        $travel_datetime = $travel_gmt ? $travel_gmt : null;
    }

    $row = array(
        'post_id'                   => $post_id,
        'confirmation_code'         => bokun_analytics_meta( $post_id, array( 'confirmationCode', '_confirmation_code' ) ),
        'external_booking_reference' => bokun_analytics_meta( $post_id, array( 'externalBookingReference', '_external_booking_reference' ) ),
        'channel_title'             => bokun_analytics_meta( $post_id, array( 'channel_title' ) ),
        'channel_id'                => bokun_analytics_meta( $post_id, array( 'channel_id' ) ),
        'channel_channel_type'      => bokun_analytics_meta( $post_id, array( 'channel_channelType' ) ),
        'seller_title'              => bokun_analytics_meta( $post_id, array( 'seller_title' ) ),
        'seller_id'                 => bokun_analytics_meta( $post_id, array( 'seller_id' ) ),
        'vendor_title'              => bokun_analytics_meta( $post_id, array( 'productBookings_0_vendor_title' ) ),
        'vendor_id'                 => bokun_analytics_meta( $post_id, array( 'productBookings_0_vendor_id' ) ),
        'pb_seller_id'              => bokun_analytics_meta( $post_id, array( 'productBookings_0_seller_id' ) ),
        'pb_seller_title'           => bokun_analytics_meta( $post_id, array( 'productBookings_0_seller_title' ) ),
        'product_external_id'       => bokun_analytics_meta( $post_id, array( 'productBookings_0_productExternalId' ) ),
        'product_id'                => $product_id,
        'partner_page_id'           => ( '' !== $partner_page_id ) ? $partner_page_id : null,
        'result'                    => bokun_analytics_get_result( $post_id ),
        'partner_refunded'          => has_term( 'refunded-by-partner', 'booking_status', $post_id ) ? 1 : 0,
        'is_cancelled'              => bokun_analytics_is_cancelled( $post_id ) ? 1 : 0,
        'payment_method'            => bokun_analytics_get_payment_method( $post_id ),
        'product_title'             => bokun_analytics_meta( $post_id, array( '_product_title', 'productBookings_0_product_title' ) ),
        'product_option'            => bokun_analytics_meta( $post_id, array( 'productBookings_0_fields_rateTitle', 'productBookings_0_rateTitle' ) ),
        'travel_datetime'           => $travel_datetime,
        'created_datetime'          => bokun_analytics_get_created_datetime( $post_id ),
        'adult_participants'        => $participants['adult'],
        'child_participants'        => $participants['child'],
        'infant_participants'       => $participants['infant'],
        'language'                  => bokun_analytics_meta( $post_id, array( 'language' ) ),
        'pb_status'                 => bokun_analytics_meta( $post_id, array( 'productBookings_0_status', '_booking_status_origin' ) ),
        'price_note'                => $price_note,
        'price_amount'              => bokun_analytics_parse_amount( $price_note ),
        'product_confirmation_code' => bokun_analytics_meta( $post_id, array( 'productBookings_0_productConfirmationCode' ) ),
        'pb_channel_id'             => bokun_analytics_meta( $post_id, array( 'productBookings_0_channelId' ) ),
        'currency'                  => bokun_analytics_meta( $post_id, array( 'currency' ) ),
        'phone_prefix'              => bokun_analytics_meta( $post_id, array( '_phone_prefix' ) ),
        'synced_at'                 => current_time( 'mysql', true ),
    );

    return $row;
}

/**
 * Determine whether a created datetime falls within the retention window.
 *
 * @param string|null $created_datetime `Y-m-d H:i:s` (GMT) or null.
 * @return bool
 */
function bokun_analytics_is_within_window( $created_datetime ) {
    if ( null === $created_datetime || '' === $created_datetime ) {
        return false;
    }

    // Window start is in site time; convert to GMT for comparison with the
    // stored (GMT) created datetime.
    $window_start_gmt = get_gmt_from_date( bokun_analytics_get_window_start() );

    return $created_datetime >= $window_start_gmt;
}

/**
 * Insert/update (or remove) a booking's row in the analytics source table.
 *
 * Bookings outside the retention window are deleted from the table so it only
 * ever holds the trailing window.
 *
 * @param int $post_id Booking post ID.
 * @return bool True when a row was written, false when skipped or removed.
 */
function bokun_analytics_sync_booking( $post_id ) {
    global $wpdb;

    // A write can arrive before any admin request upgraded the table (the
    // status-change AJAX handler and scheduled imports both run outside admin),
    // so make sure the schema is current before replacing the row — otherwise
    // the new columns would be silently dropped on an un-upgraded install.
    bokun_analytics_ensure_schema();

    $row = bokun_analytics_build_row( $post_id );

    if ( null === $row ) {
        return false;
    }

    $table_name = bokun_analytics_get_table_name();

    if ( ! bokun_analytics_is_within_window( $row['created_datetime'] ) ) {
        $wpdb->delete( $table_name, array( 'post_id' => (int) $post_id ), array( '%d' ) );

        return false;
    }

    // REPLACE performs an upsert keyed on the post_id primary key. It returns
    // the affected row count, or false on error.
    $result = $wpdb->replace( $table_name, $row );

    return false !== $result;
}

/**
 * Rebuild the entire analytics source table from scratch for the current
 * window.
 *
 * @return array{built:int,skipped:int,total:int} Rebuild statistics.
 */
function bokun_analytics_rebuild() {
    global $wpdb;

    $table_name = bokun_analytics_get_table_name();

    // Start clean so stale/out-of-window rows never linger.
    $wpdb->query( "TRUNCATE TABLE $table_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

    // `bookingcreationdate` meta is stored in site-local time, so the coarse
    // prefilter compares against the local window start. The authoritative,
    // timezone-correct decision is made per row by
    // bokun_analytics_is_within_window().
    $window_start_local = bokun_analytics_get_window_start();

    $stats = array(
        'built'   => 0,
        'skipped' => 0,
        'total'   => 0,
    );

    $paged = 1;

    do {
        $query = new WP_Query(
            array(
                'post_type'        => 'bokun_booking',
                'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page'   => 200,
                'paged'            => $paged,
                'fields'           => 'ids',
                'no_found_rows'    => false,
                'suppress_filters' => true,
                'meta_query'       => array(
                    array(
                        'key'     => 'bookingcreationdate',
                        'value'   => $window_start_local,
                        'compare' => '>=',
                        'type'    => 'DATETIME',
                    ),
                ),
            )
        );

        if ( empty( $query->posts ) ) {
            break;
        }

        foreach ( $query->posts as $post_id ) {
            $stats['total']++;

            if ( bokun_analytics_sync_booking( $post_id ) ) {
                $stats['built']++;
            } else {
                $stats['skipped']++;
            }
        }

        $max_pages = (int) $query->max_num_pages;
        $paged++;
    } while ( $paged <= $max_pages );

    wp_reset_postdata();

    update_option( BOKUN_ANALYTICS_LAST_BUILT_OPTION, current_time( 'mysql', true ) );

    // A full rebuild repopulates every column for every in-window row, so any
    // pending post-upgrade rebuild (manual or scheduled) is satisfied; also
    // cancel any in-progress batched migration cursor.
    delete_option( 'bokun_analytics_pending_rebuild' );
    delete_option( 'bokun_analytics_rebuild_cursor' );
    delete_option( 'bokun_analytics_rebuild_cutoff' );

    return $stats;
}

/**
 * Delete every row whose creation date has aged out of the retention window.
 *
 * Per-booking sync only prunes the booking it is handed, but imports fetch a
 * forward-looking date range, so a past booking is never re-synced once its
 * trip has gone by. Without this global prune, such rows would linger past the
 * window until a manual rebuild. Runs on the import path so the table keeps
 * representing the configured trailing window on its own.
 *
 * @return int|false Rows removed, or false on error.
 */
function bokun_analytics_prune_window() {
    global $wpdb;

    $table_name       = bokun_analytics_get_table_name();
    $window_start_gmt = get_gmt_from_date( bokun_analytics_get_window_start() );

    return $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_datetime IS NULL OR created_datetime < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $window_start_gmt
        )
    );
}
// Prune aged-out rows after every scheduled import, including runs that fetch
// no bookings (which skip the save path entirely).
add_action( BOKUN_DAILY_IMPORT_HOOK, 'bokun_analytics_prune_window', 5 );

/**
 * Fetch every analytics source row, newest booking first.
 *
 * Intended for the analytics dashboard, which filters and aggregates the rows
 * client-side. The dataset is bounded to the retention window so it stays a
 * reasonable size to hand to the browser.
 *
 * @return array[] List of column => value maps.
 */
function bokun_analytics_get_rows() {
    global $wpdb;

    $table_name = bokun_analytics_get_table_name();

    $rows = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY created_datetime DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

/**
 * Number of rows currently in the analytics source table.
 *
 * @return int
 */
function bokun_analytics_get_row_count() {
    global $wpdb;

    $table_name = bokun_analytics_get_table_name();

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * AJAX handler: rebuild the analytics source table on demand.
 *
 * @return void
 */
function bokun_analytics_ajax_rebuild() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'BOKUN_text_domain' ) ), 403 );
    }

    check_ajax_referer( 'bokun_analytics_rebuild', 'nonce' );

    $stats = bokun_analytics_rebuild();

    wp_send_json_success(
        array(
            'stats'      => $stats,
            'row_count'  => bokun_analytics_get_row_count(),
            'last_built' => get_option( BOKUN_ANALYTICS_LAST_BUILT_OPTION, '' ),
            /* translators: 1: rows written, 2: bookings scanned. */
            'message'    => sprintf(
                __( 'Analytics source rebuilt: %1$d records from %2$d bookings in the last %3$d months.', 'BOKUN_text_domain' ),
                (int) $stats['built'],
                (int) $stats['total'],
                bokun_analytics_get_window_months()
            ),
        )
    );
}
add_action( 'wp_ajax_bokun_rebuild_analytics', 'bokun_analytics_ajax_rebuild' );

/**
 * Default number of cancelled bookings processed per backfill request. Kept
 * small so a single admin-AJAX call stays well under PHP/proxy timeouts; the
 * admin screen walks the pages until the backfill reports done.
 */
function bokun_analytics_backfill_batch_size() {
    $size = (int) apply_filters( 'bokun_analytics_backfill_batch_size', 100 );

    return $size > 0 ? $size : 100;
}

/**
 * Backfill one page: mark cancelled bookings that were "made" (Full/Partial, so
 * the partner was paid) as "Refunded by Partner", for the backlog created
 * before that checkbox existed. Each affected booking gets the term, a
 * booking-history entry, and an analytics re-sync so its net revenue flips from
 * the negative cancellation loss to neutral (null).
 *
 * Processes only one bounded batch per call and reports whether more pages
 * remain, so a large backlog never has to complete inside a single request. The
 * cancelled set is stable across pages (the backfill never removes the
 * "cancelled" term it filters on), and ordering is fixed by post ID, so paging
 * is deterministic. Idempotent — bookings already marked are counted, not
 * re-tagged.
 *
 * @param int $page       1-based page to process.
 * @param int $batch_size Bookings per page.
 * @return array{scanned:int,eligible:int,marked:int,already:int,page:int,max_pages:int,done:bool}
 */
function bokun_analytics_mark_cancelled_made_refunded( $page = 1, $batch_size = 0 ) {
    $page       = max( 1, (int) $page );
    $batch_size = (int) $batch_size > 0 ? (int) $batch_size : bokun_analytics_backfill_batch_size();

    $stats = array(
        'scanned'   => 0,
        'eligible'  => 0,
        'marked'    => 0,
        'already'   => 0,
        'page'      => $page,
        'max_pages' => 0,
        'done'      => true,
    );

    // Match the dashboard's cancelled detection: any booking_status term whose
    // slug or name contains "cancel".
    $cancel_term_ids = array();
    $status_terms    = get_terms(
        array(
            'taxonomy'   => 'booking_status',
            'hide_empty' => false,
        )
    );

    if ( is_array( $status_terms ) ) {
        foreach ( $status_terms as $term ) {
            if ( false !== stripos( $term->slug, 'cancel' ) || false !== stripos( $term->name, 'cancel' ) ) {
                $cancel_term_ids[] = (int) $term->term_id;
            }
        }
    }

    if ( empty( $cancel_term_ids ) ) {
        return $stats;
    }

    $query = new WP_Query(
        array(
            'post_type'        => 'bokun_booking',
            'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page'   => $batch_size,
            'paged'            => $page,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'fields'           => 'ids',
            'no_found_rows'    => false,
            'suppress_filters' => true,
            'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                array(
                    'taxonomy' => 'booking_status',
                    'field'    => 'term_id',
                    'terms'    => $cancel_term_ids,
                ),
            ),
        )
    );

    $stats['max_pages'] = (int) $query->max_num_pages;

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post_id ) {
            $post_id = (int) $post_id;
            $stats['scanned']++;

            // Apply the exact eligibility the dashboard refund controls and the
            // status-change handler use: the booking must currently be both
            // "Booking made" and "Cancelled" (sanitize_title of the term name,
            // not just any "cancel" term the broad prefilter matched, e.g.
            // "Cancelled by supplier"). Otherwise the refund term we assign
            // would be hidden and could not be reversed through the dashboard.
            $term_values = array();
            $post_terms  = get_the_terms( $post_id, 'booking_status' );
            if ( $post_terms && ! is_wp_error( $post_terms ) ) {
                foreach ( $post_terms as $post_term ) {
                    $term_values[] = sanitize_title( $post_term->name );
                }
            }
            if ( ! in_array( 'booking-made', $term_values, true ) || ! in_array( 'cancelled', $term_values, true ) ) {
                continue;
            }
            $stats['eligible']++;

            if ( has_term( 'refunded-by-partner', 'booking_status', $post_id ) ) {
                $stats['already']++;
            } elseif ( function_exists( 'bokun_assign_tag_to_post' ) ) {
                bokun_assign_tag_to_post( $post_id, 'Refunded by Partner', 'booking_status' );
                $stats['marked']++;

                if ( function_exists( 'bokun_record_booking_history' ) ) {
                    $booking_id = get_post_meta( $post_id, '_confirmation_code', true );
                    bokun_record_booking_history( $post_id, $booking_id, 'refunded-partner', true );
                }
            }

            // Re-sync so the analytics row reflects the refund immediately.
            bokun_analytics_sync_booking( $post_id );
        }
    }

    wp_reset_postdata();

    $stats['done'] = ( 0 === $stats['max_pages'] ) || ( $page >= $stats['max_pages'] );

    return $stats;
}

/**
 * AJAX: run one page of the cancelled-made -> refunded-by-partner backfill. The
 * admin screen calls this repeatedly, advancing `page` until `done` is true.
 *
 * @return void
 */
function bokun_analytics_ajax_mark_refunded() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'BOKUN_text_domain' ) ), 403 );
    }

    check_ajax_referer( 'bokun_analytics_mark_refunded', 'nonce' );

    $page  = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
    $stats = bokun_analytics_mark_cancelled_made_refunded( $page );

    wp_send_json_success(
        array(
            'stats'     => $stats,
            'page'      => (int) $stats['page'],
            'max_pages' => (int) $stats['max_pages'],
            'done'      => (bool) $stats['done'],
            'next_page' => $stats['done'] ? 0 : ( (int) $stats['page'] + 1 ),
            'row_count' => bokun_analytics_get_row_count(),
        )
    );
}
add_action( 'wp_ajax_bokun_mark_refunded_cancelled', 'bokun_analytics_ajax_mark_refunded' );
