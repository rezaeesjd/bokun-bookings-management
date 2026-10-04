<?php
/**
 * Admin screen for the analytics source table.
 *
 * Lets an administrator (re)build the flat source dataset that powers the
 * analytics dashboard and preview the most recent records.
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'BOKUN_text_domain' ) );
}

global $wpdb;

$table_name   = bokun_analytics_get_table_name();
$like_name    = $wpdb->esc_like( $table_name );
$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like_name ) ) === $table_name );

$window_months = bokun_analytics_get_window_months();
$row_count     = $table_exists ? bokun_analytics_get_row_count() : 0;
$last_built    = get_option( BOKUN_ANALYTICS_LAST_BUILT_OPTION, '' );
$rebuild_nonce = wp_create_nonce( 'bokun_analytics_rebuild' );

$last_built_display = '';
if ( ! empty( $last_built ) ) {
    $last_built_ts = strtotime( $last_built . ' UTC' );
    if ( $last_built_ts ) {
        $last_built_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_built_ts );
    }
}

// Columns to preview (subset of the full field set, ordered for readability).
$preview_columns = array(
    'confirmation_code'   => __( 'Confirmation', 'BOKUN_text_domain' ),
    'created_datetime'    => __( 'Created (GMT)', 'BOKUN_text_domain' ),
    'travel_datetime'     => __( 'Travel (GMT)', 'BOKUN_text_domain' ),
    'product_title'       => __( 'Product', 'BOKUN_text_domain' ),
    'product_option'      => __( 'Option', 'BOKUN_text_domain' ),
    'result'              => __( 'Result', 'BOKUN_text_domain' ),
    'payment_method'      => __( 'Payment', 'BOKUN_text_domain' ),
    'adult_participants'  => __( 'Ad', 'BOKUN_text_domain' ),
    'child_participants'  => __( 'Ch', 'BOKUN_text_domain' ),
    'infant_participants' => __( 'In', 'BOKUN_text_domain' ),
    'language'            => __( 'Lang', 'BOKUN_text_domain' ),
    'currency'            => __( 'Cur', 'BOKUN_text_domain' ),
    'channel_title'       => __( 'Channel', 'BOKUN_text_domain' ),
    'seller_title'        => __( 'Seller', 'BOKUN_text_domain' ),
    'vendor_title'        => __( 'Vendor', 'BOKUN_text_domain' ),
    'pb_status'           => __( 'Status', 'BOKUN_text_domain' ),
    'price_amount'        => __( 'Amount', 'BOKUN_text_domain' ),
    'price_note'          => __( 'Price note', 'BOKUN_text_domain' ),
);

$preview_rows = array();
if ( $table_exists && $row_count > 0 ) {
    $preview_rows = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY created_datetime DESC LIMIT 25", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ARRAY_A
    );
}
?>
<div class="wrap bokun-analytics-source">
    <h1><?php esc_html_e( 'Analytics Data Source', 'BOKUN_text_domain' ); ?></h1>

    <p class="description">
        <?php
        printf(
            /* translators: %d: number of months. */
            esc_html__( 'A flat, query-ready table of booking records from the last %d months (by booking creation date). It is rebuilt automatically on each import and powers the analytics dashboard.', 'BOKUN_text_domain' ),
            (int) $window_months
        );
        ?>
    </p>

    <?php if ( ! $table_exists ) : ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'The analytics source table does not exist yet. Click “Rebuild now” to create and populate it.', 'BOKUN_text_domain' ); ?></p>
        </div>
    <?php endif; ?>

    <div class="bokun-analytics-source__cards" style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">
        <div class="card" style="padding:16px;min-width:180px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Records', 'BOKUN_text_domain' ); ?></h2>
            <p style="font-size:28px;font-weight:600;margin:0;" data-analytics-count><?php echo esc_html( number_format_i18n( $row_count ) ); ?></p>
        </div>
        <div class="card" style="padding:16px;min-width:180px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Window', 'BOKUN_text_domain' ); ?></h2>
            <p style="font-size:28px;font-weight:600;margin:0;"><?php echo esc_html( sprintf( _n( '%d month', '%d months', $window_months, 'BOKUN_text_domain' ), $window_months ) ); ?></p>
        </div>
        <div class="card" style="padding:16px;min-width:220px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Last rebuilt', 'BOKUN_text_domain' ); ?></h2>
            <p style="font-size:16px;margin:8px 0 0;" data-analytics-last-built>
                <?php echo $last_built_display ? esc_html( $last_built_display ) : esc_html__( 'Never', 'BOKUN_text_domain' ); ?>
            </p>
        </div>
    </div>

    <p>
        <button type="button" class="button button-primary" id="bokun-analytics-rebuild">
            <?php esc_html_e( 'Rebuild now', 'BOKUN_text_domain' ); ?>
        </button>
        <span class="spinner" id="bokun-analytics-spinner" style="float:none;margin-top:0;"></span>
        <span id="bokun-analytics-message" style="margin-left:8px;"></span>
    </p>

    <h2><?php esc_html_e( 'Preview (latest 25 records)', 'BOKUN_text_domain' ); ?></h2>

    <?php if ( empty( $preview_rows ) ) : ?>
        <p><?php esc_html_e( 'No records yet. Import bookings or rebuild the source to populate this table.', 'BOKUN_text_domain' ); ?></p>
    <?php else : ?>
        <table class="widefat striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <?php foreach ( $preview_columns as $label ) : ?>
                        <th><?php echo esc_html( $label ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $preview_rows as $row ) : ?>
                    <tr>
                        <?php foreach ( $preview_columns as $key => $label ) : ?>
                            <td><?php echo esc_html( isset( $row[ $key ] ) && null !== $row[ $key ] ? $row[ $key ] : '' ); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script type="text/javascript">
( function () {
    var button  = document.getElementById( 'bokun-analytics-rebuild' );
    var spinner = document.getElementById( 'bokun-analytics-spinner' );
    var message = document.getElementById( 'bokun-analytics-message' );
    var count   = document.querySelector( '[data-analytics-count]' );
    var built   = document.querySelector( '[data-analytics-last-built]' );

    if ( ! button ) {
        return;
    }

    button.addEventListener( 'click', function () {
        button.disabled = true;
        spinner.classList.add( 'is-active' );
        message.textContent = '';

        var body = new URLSearchParams();
        body.append( 'action', 'bokun_rebuild_analytics' );
        body.append( 'nonce', '<?php echo esc_js( $rebuild_nonce ); ?>' );

        fetch( '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        } )
        .then( function ( response ) { return response.json(); } )
        .then( function ( json ) {
            button.disabled = false;
            spinner.classList.remove( 'is-active' );

            if ( json && json.success ) {
                message.style.color = '#1a7f37';
                message.textContent = json.data.message || '<?php echo esc_js( __( 'Rebuilt.', 'BOKUN_text_domain' ) ); ?>';
                if ( count && typeof json.data.row_count !== 'undefined' ) {
                    count.textContent = json.data.row_count;
                }
                if ( built ) {
                    built.textContent = '<?php echo esc_js( __( 'Just now', 'BOKUN_text_domain' ) ); ?>';
                }
                // Refresh so the preview reflects the rebuilt data.
                setTimeout( function () { window.location.reload(); }, 1200 );
            } else {
                message.style.color = '#d63638';
                message.textContent = ( json && json.data && json.data.message ) ? json.data.message : '<?php echo esc_js( __( 'Rebuild failed.', 'BOKUN_text_domain' ) ); ?>';
            }
        } )
        .catch( function () {
            button.disabled = false;
            spinner.classList.remove( 'is-active' );
            message.style.color = '#d63638';
            message.textContent = '<?php echo esc_js( __( 'Rebuild failed.', 'BOKUN_text_domain' ) ); ?>';
        } );
    } );
} )();
</script>
