<?php
/**
 * Partners products reference table.
 *
 * Stores one row per Bokun product: the supplied catalog data (title, net
 * price, commission, departure city) joined with the product's
 * `partnerpageid` term meta. This is the per-product dimension table that the
 * analytics layer joins against for partner, net-price and commission
 * reporting.
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Schema version for the partners-products table. */
define( 'BOKUN_PARTNERS_PRODUCTS_DB_VERSION', '1.0.0' );

/** Option key tracking the installed partners-products schema version. */
define( 'BOKUN_PARTNERS_PRODUCTS_DB_VERSION_OPTION', 'bokun_partners_products_db_version' );

/** Option key tracking the last successful catalog import (GMT). */
define( 'BOKUN_PARTNERS_PRODUCTS_LAST_SYNC_OPTION', 'bokun_partners_products_last_sync' );

/**
 * Fully-qualified name of the partners-products table.
 *
 * @return string
 */
function bokun_partners_products_get_table_name() {
    global $wpdb;

    return $wpdb->prefix . 'bokun_partners_products';
}

/**
 * Create or upgrade the partners-products table via dbDelta.
 *
 * @return void
 */
function bokun_partners_products_install_table() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name      = bokun_partners_products_get_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        product_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(255) NULL,
        net_price DECIMAL(14,2) NULL,
        commission DECIMAL(8,4) NULL,
        departure_city VARCHAR(191) NULL,
        partner_page_id VARCHAR(191) NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY  (product_id),
        KEY partner_page_id (partner_page_id),
        KEY departure_city (departure_city)
    ) $charset_collate;";

    dbDelta( $sql );

    update_option( BOKUN_PARTNERS_PRODUCTS_DB_VERSION_OPTION, BOKUN_PARTNERS_PRODUCTS_DB_VERSION );
}

/**
 * Ensure the table exists / matches the current schema on admin requests.
 *
 * @return void
 */
function bokun_partners_products_maybe_upgrade() {
    if ( ! is_admin() ) {
        return;
    }

    if ( get_option( BOKUN_PARTNERS_PRODUCTS_DB_VERSION_OPTION ) === BOKUN_PARTNERS_PRODUCTS_DB_VERSION ) {
        return;
    }

    bokun_partners_products_install_table();

    // Seed the catalog on this first post-upgrade admin request. `init` has
    // already registered the product_tags taxonomy by now, so partner page
    // ids resolve correctly (unlike during the activation hook).
    bokun_partners_products_seed();
}
add_action( 'admin_init', 'bokun_partners_products_maybe_upgrade', 20 );

/**
 * Resolve the `partnerpageid` term meta for a Bokun product id.
 *
 * Looks up the `product_tags` term carrying the matching `bokun_product_id`
 * term meta and returns its `partnerpageid`. Results are memoized per request
 * so repeated lookups during an import stay cheap.
 *
 * @param int|string $product_id Bokun product id.
 * @return string Partner page id, or '' when none is set.
 */
function bokun_partners_products_resolve_partner_page_id( $product_id ) {
    static $cache = array();

    $product_id = (string) ( is_numeric( $product_id ) ? (int) $product_id : $product_id );

    if ( '' === $product_id || '0' === $product_id ) {
        return '';
    }

    if ( isset( $cache[ $product_id ] ) ) {
        return $cache[ $product_id ];
    }

    $term_ids = get_terms(
        array(
            'taxonomy'   => 'product_tags',
            'hide_empty' => false,
            'number'     => 1,
            'fields'     => 'ids',
            'meta_key'   => 'bokun_product_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $product_id,        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        )
    );

    $partner = '';

    if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
        $value = get_term_meta( (int) $term_ids[0], 'partnerpageid', true );

        if ( is_array( $value ) ) {
            $value = reset( $value );
        }

        $partner = ( null === $value ) ? '' : trim( (string) $value );
    }

    $cache[ $product_id ] = $partner;

    return $partner;
}

/**
 * Load the bundled product catalog seed data.
 *
 * @return array[] List of product rows from the data file.
 */
function bokun_partners_products_get_seed_data() {
    $file = BOKUN_INCLUDES_DIR . 'data/partners-products.php';

    if ( ! file_exists( $file ) ) {
        return array();
    }

    $data = include $file;

    return is_array( $data ) ? $data : array();
}

/**
 * Import the catalog into the partners-products table and (re)resolve each
 * product's partner page id from term meta.
 *
 * @return array{imported:int,partners:int,total:int} Import statistics.
 */
function bokun_partners_products_seed() {
    global $wpdb;

    $table_name = bokun_partners_products_get_table_name();
    $data       = bokun_partners_products_get_seed_data();
    $now        = current_time( 'mysql', true );

    $stats = array(
        'imported' => 0,
        'partners' => 0,
        'total'    => count( $data ),
    );

    foreach ( $data as $row ) {
        $product_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;

        if ( $product_id <= 0 ) {
            continue;
        }

        $partner = bokun_partners_products_resolve_partner_page_id( $product_id );

        if ( '' !== $partner ) {
            $stats['partners']++;
        }

        $wpdb->replace(
            $table_name,
            array(
                'product_id'      => $product_id,
                'title'           => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : null,
                'net_price'       => isset( $row['net_price'] ) && null !== $row['net_price'] ? (float) $row['net_price'] : null,
                'commission'      => isset( $row['commission'] ) && null !== $row['commission'] ? (float) $row['commission'] : null,
                'departure_city'  => isset( $row['departure_city'] ) ? sanitize_text_field( $row['departure_city'] ) : null,
                'partner_page_id' => ( '' !== $partner ) ? $partner : null,
                'synced_at'       => $now,
            )
        );

        $stats['imported']++;
    }

    update_option( BOKUN_PARTNERS_PRODUCTS_LAST_SYNC_OPTION, $now );

    return $stats;
}

/**
 * Number of rows currently in the partners-products table.
 *
 * @return int
 */
function bokun_partners_products_get_row_count() {
    global $wpdb;

    $table_name = bokun_partners_products_get_table_name();

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * AJAX handler: rebuild (re-import) the partners-products table.
 *
 * @return void
 */
function bokun_partners_products_ajax_rebuild() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'BOKUN_text_domain' ) ), 403 );
    }

    check_ajax_referer( 'bokun_partners_products_rebuild', 'nonce' );

    bokun_partners_products_install_table();
    $stats = bokun_partners_products_seed();

    wp_send_json_success(
        array(
            'stats'     => $stats,
            'row_count' => bokun_partners_products_get_row_count(),
            'last_sync' => get_option( BOKUN_PARTNERS_PRODUCTS_LAST_SYNC_OPTION, '' ),
            /* translators: 1: products imported, 2: partner ids resolved. */
            'message'   => sprintf(
                __( 'Partners products rebuilt: %1$d products imported, %2$d partner page ids resolved.', 'BOKUN_text_domain' ),
                (int) $stats['imported'],
                (int) $stats['partners']
            ),
        )
    );
}
add_action( 'wp_ajax_bokun_rebuild_partners_products', 'bokun_partners_products_ajax_rebuild' );
