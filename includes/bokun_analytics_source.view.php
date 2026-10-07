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
$mark_refunded_nonce = wp_create_nonce( 'bokun_analytics_mark_refunded' );
$inspect_nonce       = wp_create_nonce( 'bokun_analytics_inspect' );

// Partners products reference table.
$pp_available   = function_exists( 'bokun_partners_products_get_row_count' );
$pp_count       = $pp_available ? bokun_partners_products_get_row_count() : 0;
$pp_last_sync   = get_option( 'bokun_partners_products_last_sync', '' );
$pp_nonce       = wp_create_nonce( 'bokun_partners_products_rebuild' );
$pp_last_display = '';
if ( ! empty( $pp_last_sync ) ) {
    $pp_last_ts = strtotime( $pp_last_sync . ' UTC' );
    if ( $pp_last_ts ) {
        $pp_last_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pp_last_ts );
    }
}

// Catalog-match coverage: how many distinct booking products join to a
// partners-products row. The catalog is keyed by the partner page id (the
// spreadsheet "ID"), and a booking's partner_page_id comes from its product
// tag's partnerpageid term meta, so that id is the join key. Mirrors the
// dashboard join so an operator can see whether net revenue will populate.
$pp_match = array(
    'total'     => 0,
    'matched'   => 0,
    'unmatched' => array(),
);
if ( $pp_available && $pp_count > 0 && function_exists( 'bokun_partners_products_get_map' ) && function_exists( 'bokun_analytics_get_rows' ) ) {
    $pp_map  = bokun_partners_products_get_map();
    $pp_seen = array();
    foreach ( bokun_analytics_get_rows() as $pp_row ) {
        $pp_pid = (string) ( $pp_row['product_id'] ?? '' );
        if ( '' === $pp_pid || isset( $pp_seen[ $pp_pid ] ) ) {
            continue;
        }
        $pp_seen[ $pp_pid ] = true;
        $pp_match['total']++;
        $pp_ppid = (string) ( $pp_row['partner_page_id'] ?? '' );
        $pp_hit  = ( '' !== $pp_ppid && isset( $pp_map[ $pp_ppid ] ) );
        if ( $pp_hit ) {
            $pp_match['matched']++;
        } elseif ( count( $pp_match['unmatched'] ) < 10 ) {
            $pp_match['unmatched'][] = array( $pp_pid, $pp_ppid, (string) ( $pp_row['product_title'] ?? '' ) );
        }
    }
}

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
    'partner_page_id'     => __( 'Partner', 'BOKUN_text_domain' ),
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

    <h2 style="margin-top:24px;"><?php esc_html_e( 'Backfill partner refunds', 'BOKUN_text_domain' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'One-time cleanup for the backlog created before the "Cancelled and refunded by Partner" checkbox existed. Marks every cancelled booking that was "booking made" (Full/Partial) as refunded by partner, and re-syncs it, so its net revenue becomes neutral (null) instead of the negative cancellation loss. Run this once if you have already requested refunds from the partner for all cancelled bookings. Safe to re-run; already-marked bookings are left as-is.', 'BOKUN_text_domain' ); ?>
    </p>
    <p>
        <button type="button" class="button" id="bokun-mark-refunded">
            <?php esc_html_e( 'Mark cancelled “booking made” as refunded by partner', 'BOKUN_text_domain' ); ?>
        </button>
        <span class="spinner" id="bokun-mark-refunded-spinner" style="float:none;margin-top:0;"></span>
        <span id="bokun-mark-refunded-message" style="margin-left:8px;"></span>
    </p>

    <hr style="margin:24px 0;" />

    <h2><?php esc_html_e( 'Inspect a booking', 'BOKUN_text_domain' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Diagnostic: enter a booking confirmation code (e.g. VIA-104741414) to see what the plugin has stored for it — its status meta, booking-status terms, whether it counts as cancelled, and its analytics row. Tick “also query Bokun” to additionally show what Bokun’s current booking-search returns for it and which field holds the status, so a wrong/refunded status can be traced to its source. Read-only; it changes nothing.', 'BOKUN_text_domain' ); ?>
    </p>
    <p>
        <input type="text" id="bokun-inspect-code" class="regular-text" placeholder="<?php esc_attr_e( 'Confirmation code', 'BOKUN_text_domain' ); ?>" />
        <label style="margin-left:8px;"><input type="checkbox" id="bokun-inspect-live" /> <?php esc_html_e( 'also query Bokun (slower)', 'BOKUN_text_domain' ); ?></label>
    </p>
    <p>
        <button type="button" class="button" id="bokun-inspect-btn"><?php esc_html_e( 'Inspect booking', 'BOKUN_text_domain' ); ?></button>
        <span class="spinner" id="bokun-inspect-spinner" style="float:none;margin-top:0;"></span>
    </p>
    <pre id="bokun-inspect-output" style="display:none;max-width:900px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;border-radius:6px;white-space:pre-wrap;"></pre>

    <hr style="margin:24px 0;" />

    <h2><?php esc_html_e( 'Partners products', 'BOKUN_text_domain' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Per-product reference table (title, net price, commission, departure city) joined with each product\'s partner page id from the product tag term meta. Powers partner, net-revenue and commission reporting.', 'BOKUN_text_domain' ); ?>
    </p>

    <div class="bokun-analytics-source__cards" style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">
        <div class="card" style="padding:16px;min-width:180px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Products', 'BOKUN_text_domain' ); ?></h2>
            <p style="font-size:28px;font-weight:600;margin:0;" data-pp-count><?php echo esc_html( number_format_i18n( $pp_count ) ); ?></p>
        </div>
        <div class="card" style="padding:16px;min-width:220px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Last imported', 'BOKUN_text_domain' ); ?></h2>
            <p style="font-size:16px;margin:8px 0 0;" data-pp-last>
                <?php echo $pp_last_display ? esc_html( $pp_last_display ) : esc_html__( 'Never', 'BOKUN_text_domain' ); ?>
            </p>
        </div>
    </div>

    <p>
        <button type="button" class="button button-primary" id="bokun-pp-rebuild">
            <?php esc_html_e( 'Rebuild partners products', 'BOKUN_text_domain' ); ?>
        </button>
        <span class="spinner" id="bokun-pp-spinner" style="float:none;margin-top:0;"></span>
        <span id="bokun-pp-message" style="margin-left:8px;"></span>
    </p>

    <?php if ( $pp_match['total'] > 0 ) : ?>
        <?php
        $pp_pct = (int) round( ( $pp_match['matched'] / $pp_match['total'] ) * 100 );
        ?>
        <p>
            <strong><?php esc_html_e( 'Catalog match:', 'BOKUN_text_domain' ); ?></strong>
            <?php
            printf(
                /* translators: 1: matched products, 2: total products, 3: percent. */
                esc_html__( '%1$d of %2$d booking products matched a catalog row (%3$d%%) — joined by partner page id. Net revenue only fills for matched products.', 'BOKUN_text_domain' ),
                (int) $pp_match['matched'],
                (int) $pp_match['total'],
                (int) $pp_pct
            );
            ?>
        </p>
        <?php if ( ! empty( $pp_match['unmatched'] ) ) : ?>
            <p class="description"><?php esc_html_e( 'Sample unmatched booking products (no partner page id, or one with no catalog row):', 'BOKUN_text_domain' ); ?></p>
            <table class="widefat striped" style="max-width:900px;margin-bottom:16px;">
                <thead><tr>
                    <th><?php esc_html_e( 'Booking product_id', 'BOKUN_text_domain' ); ?></th>
                    <th><?php esc_html_e( 'Partner page id', 'BOKUN_text_domain' ); ?></th>
                    <th><?php esc_html_e( 'Product title', 'BOKUN_text_domain' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $pp_match['unmatched'] as $pp_u ) : ?>
                        <tr>
                            <td><?php echo esc_html( $pp_u[0] ); ?></td>
                            <td><?php echo esc_html( $pp_u[1] ); ?></td>
                            <td><?php echo esc_html( $pp_u[2] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <script type="text/javascript">
    ( function () {
        var button  = document.getElementById( 'bokun-pp-rebuild' );
        var spinner = document.getElementById( 'bokun-pp-spinner' );
        var message = document.getElementById( 'bokun-pp-message' );
        var count   = document.querySelector( '[data-pp-count]' );
        var last    = document.querySelector( '[data-pp-last]' );
        if ( ! button ) { return; }
        button.addEventListener( 'click', function () {
            button.disabled = true;
            spinner.classList.add( 'is-active' );
            message.textContent = '';
            var body = new URLSearchParams();
            body.append( 'action', 'bokun_rebuild_partners_products' );
            body.append( 'nonce', '<?php echo esc_js( $pp_nonce ); ?>' );
            fetch( '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( json ) {
                button.disabled = false;
                spinner.classList.remove( 'is-active' );
                if ( json && json.success ) {
                    message.style.color = '#1a7f37';
                    message.textContent = json.data.message || '<?php echo esc_js( __( 'Rebuilt.', 'BOKUN_text_domain' ) ); ?>';
                    if ( count && typeof json.data.row_count !== 'undefined' ) { count.textContent = json.data.row_count; }
                    if ( last ) { last.textContent = '<?php echo esc_js( __( 'Just now', 'BOKUN_text_domain' ) ); ?>'; }
                    // Reload so the server-rendered catalog-match diagnostic
                    // reflects the freshly rebuilt catalog.
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

    <hr style="margin:24px 0;" />

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

<script type="text/javascript">
( function () {
    var button  = document.getElementById( 'bokun-mark-refunded' );
    var spinner = document.getElementById( 'bokun-mark-refunded-spinner' );
    var message = document.getElementById( 'bokun-mark-refunded-message' );
    var count   = document.querySelector( '[data-analytics-count]' );

    if ( ! button ) {
        return;
    }

    var ajaxUrl     = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
    var nonce       = '<?php echo esc_js( $mark_refunded_nonce ); ?>';
    var failedText  = '<?php echo esc_js( __( 'Failed.', 'BOKUN_text_domain' ) ); ?>';
    var workingText = '<?php echo esc_js( __( 'Working…', 'BOKUN_text_domain' ) ); ?>';
    var doneText    = '<?php echo esc_js( __( 'Marked', 'BOKUN_text_domain' ) ); ?>';
    var ofText      = '<?php echo esc_js( __( 'of', 'BOKUN_text_domain' ) ); ?>';
    var backlogText = '<?php echo esc_js( __( 'cancelled “booking made” bookings as refunded by partner.', 'BOKUN_text_domain' ) ); ?>';

    function finish( enabled ) {
        button.disabled = ! enabled;
        spinner.classList.remove( 'is-active' );
    }

    button.addEventListener( 'click', function () {
        if ( ! window.confirm( '<?php echo esc_js( __( 'Mark all cancelled “booking made” bookings as refunded by partner? This updates their net revenue to neutral.', 'BOKUN_text_domain' ) ); ?>' ) ) {
            return;
        }

        button.disabled = true;
        spinner.classList.add( 'is-active' );
        message.style.color = '#1a7f37';
        message.textContent = workingText;

        // Totals accumulated across every batch of this run.
        var totals = { scanned: 0, eligible: 0, marked: 0, already: 0 };

        function runBatch( afterId ) {
            var body = new URLSearchParams();
            body.append( 'action', 'bokun_mark_refunded_cancelled' );
            body.append( 'nonce', nonce );
            body.append( 'after_id', afterId );

            fetch( ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            } )
            .then( function ( response ) { return response.json(); } )
            .then( function ( json ) {
                if ( ! json || ! json.success ) {
                    finish( true );
                    message.style.color = '#d63638';
                    message.textContent = ( json && json.data && json.data.message ) ? json.data.message : failedText;
                    return;
                }

                var s = json.data.stats || {};
                totals.scanned  += ( s.scanned  || 0 );
                totals.eligible += ( s.eligible || 0 );
                totals.marked   += ( s.marked   || 0 );
                totals.already  += ( s.already  || 0 );

                if ( count && typeof json.data.row_count !== 'undefined' ) {
                    count.textContent = json.data.row_count;
                }

                if ( ! json.data.done && json.data.next_after ) {
                    message.textContent = workingText + ' (' + totals.marked + '+' + totals.already + ')';
                    runBatch( json.data.next_after );
                } else {
                    finish( true );
                    message.textContent = doneText + ' ' + totals.marked + ' (' + totals.already + ' already) ' + ofText + ' ' + totals.eligible + '/' + totals.scanned + ' ' + backlogText;
                }
            } )
            .catch( function () {
                finish( true );
                message.style.color = '#d63638';
                message.textContent = failedText;
            } );
        }

        runBatch( 0 );
    } );
} )();
</script>

<script type="text/javascript">
( function () {
    var btn     = document.getElementById( 'bokun-inspect-btn' );
    var codeEl  = document.getElementById( 'bokun-inspect-code' );
    var liveEl  = document.getElementById( 'bokun-inspect-live' );
    var spinner = document.getElementById( 'bokun-inspect-spinner' );
    var out     = document.getElementById( 'bokun-inspect-output' );
    if ( ! btn ) { return; }

    var ajaxUrl = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
    var nonce   = '<?php echo esc_js( $inspect_nonce ); ?>';

    btn.addEventListener( 'click', function () {
        var code = ( codeEl.value || '' ).trim();
        if ( ! code ) { codeEl.focus(); return; }
        btn.disabled = true;
        spinner.classList.add( 'is-active' );
        out.style.display = 'block';
        out.textContent = '<?php echo esc_js( __( 'Inspecting…', 'BOKUN_text_domain' ) ); ?>';

        var body = new URLSearchParams();
        body.append( 'action', 'bokun_inspect_booking' );
        body.append( 'nonce', nonce );
        body.append( 'code', code );
        if ( liveEl && liveEl.checked ) { body.append( 'live', '1' ); }

        fetch( ajaxUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        } )
        .then( function ( r ) { return r.json(); } )
        .then( function ( json ) {
            btn.disabled = false;
            spinner.classList.remove( 'is-active' );
            if ( json && json.success && json.data && typeof json.data.report !== 'undefined' ) {
                out.textContent = json.data.report;
            } else {
                out.textContent = ( json && json.data && json.data.message ) ? json.data.message : '<?php echo esc_js( __( 'Inspection failed.', 'BOKUN_text_domain' ) ); ?>';
            }
        } )
        .catch( function () {
            btn.disabled = false;
            spinner.classList.remove( 'is-active' );
            out.textContent = '<?php echo esc_js( __( 'Inspection failed.', 'BOKUN_text_domain' ) ); ?>';
        } );
    } );
} )();
</script>
