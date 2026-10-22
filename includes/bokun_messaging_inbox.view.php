<?php
/**
 * AI Inbox — review & send screen.
 *
 * Rendered by Bokun_Messaging::render_inbox_page(). Expects:
 *   $enabled bool    whether the feature is active.
 *   $open    array[] open inbound message rows (assoc arrays).
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ajax_url      = admin_url( 'admin-ajax.php' );
$nonce         = wp_create_nonce( 'bokun_msg_admin' );
$settings_link = admin_url( 'admin.php?page=bokun-ai-inbox-settings' );

/**
 * Small helper to label a booking for display.
 */
$booking_label = function ( $booking_id ) {
	if ( ! $booking_id ) {
		return '';
	}
	$name    = trim( get_post_meta( $booking_id, '_first_name', true ) . ' ' . get_post_meta( $booking_id, '_last_name', true ) );
	$product = get_post_meta( $booking_id, '_product_title', true );
	$code    = get_post_meta( $booking_id, '_confirmation_code', true );
	$bits    = array_filter( array( $name, $product, $code ) );
	return implode( ' · ', $bits );
};
?>
<div class="wrap bokun-msg-inbox">
	<h1 style="display:flex;align-items:center;gap:12px;">
		<?php esc_html_e( 'AI Inbox', 'BOKUN_txt_domain' ); ?>
		<button type="button" class="button" id="bokun-msg-fetch-now"><?php esc_html_e( 'Fetch now', 'BOKUN_txt_domain' ); ?></button>
		<a href="<?php echo esc_url( $settings_link ); ?>" class="button"><?php esc_html_e( 'Settings', 'BOKUN_txt_domain' ); ?></a>
	</h1>

	<?php if ( ! $enabled ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: settings URL. */
					wp_kses_post( __( 'The AI Inbox is off. Add a Claude API key and enable it in <a href="%s">Settings</a> to start drafting replies.', 'BOKUN_txt_domain' ) ),
					esc_url( $settings_link )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div id="bokun-msg-inbox-notice" class="notice" style="display:none;"><p></p></div>

	<?php if ( empty( $open ) ) : ?>
		<p><?php esc_html_e( 'Nothing waiting. New customer messages will appear here for review.', 'BOKUN_txt_domain' ); ?></p>
	<?php else : ?>
		<div class="bokun-msg-list">
			<?php foreach ( $open as $row ) : ?>
				<?php
				$id          = (int) $row['id'];
				$booking_id  = (int) $row['booking_post_id'];
				$status      = $row['status'];
				$needs_match = ( 'needs_match' === $status || ! $booking_id );
				$label       = $booking_label( $booking_id );
				?>
				<div class="bokun-msg-card" data-id="<?php echo esc_attr( $id ); ?>" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:0 0 16px;max-width:900px;">
					<div class="bokun-msg-card__head" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
						<div>
							<?php if ( $label ) : ?>
								<strong><?php echo esc_html( $label ); ?></strong>
							<?php else : ?>
								<strong style="color:#b32d2e;"><?php esc_html_e( 'Not matched to a booking', 'BOKUN_txt_domain' ); ?></strong>
							<?php endif; ?>
							<div style="color:#646970;font-size:12px;margin-top:2px;">
								<?php echo esc_html( $row['from_email'] ); ?>
								<?php if ( ! empty( $row['created_at'] ) ) : ?>
									· <?php echo esc_html( $row['created_at'] ); ?>
								<?php endif; ?>
							</div>
						</div>
						<span class="bokun-msg-status" style="font-size:12px;color:#646970;"><?php echo esc_html( $status ); ?></span>
					</div>

					<?php if ( ! empty( $row['subject'] ) ) : ?>
						<p style="margin:10px 0 4px;"><em><?php echo esc_html( $row['subject'] ); ?></em></p>
					<?php endif; ?>

					<div class="bokun-msg-inbound" style="background:#f6f7f7;border-radius:6px;padding:10px 12px;white-space:pre-wrap;font-size:13px;margin:6px 0 12px;"><?php echo esc_html( $row['body'] ); ?></div>

					<?php if ( $needs_match ) : ?>
						<div class="bokun-msg-assign" style="margin:8px 0;">
							<label style="font-size:12px;color:#646970;"><?php esc_html_e( 'Enter the matching booking ID to continue:', 'BOKUN_txt_domain' ); ?></label><br/>
							<input type="number" class="bokun-msg-booking-id" min="1" style="width:140px" placeholder="<?php esc_attr_e( 'Booking ID', 'BOKUN_txt_domain' ); ?>" />
							<button type="button" class="button bokun-msg-assign-btn"><?php esc_html_e( 'Assign & draft', 'BOKUN_txt_domain' ); ?></button>
						</div>
					<?php endif; ?>

					<?php if ( self::STATUS_ERROR === $status && ! empty( $row['error_text'] ) ) : ?>
						<div class="notice notice-error inline" style="margin:6px 0;"><p><?php echo esc_html( $row['error_text'] ); ?></p></div>
					<?php endif; ?>

					<div class="bokun-msg-draft-wrap" <?php echo $needs_match ? 'style="display:none;"' : ''; ?>>
						<label style="font-size:12px;color:#646970;"><?php esc_html_e( 'Draft reply (edit before sending):', 'BOKUN_txt_domain' ); ?></label>
						<textarea class="bokun-msg-draft large-text" rows="7" style="width:100%;margin-top:4px;"><?php echo esc_textarea( (string) $row['draft_body'] ); ?></textarea>
						<div class="bokun-msg-actions" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
							<button type="button" class="button button-primary bokun-msg-send"><?php esc_html_e( 'Approve & send', 'BOKUN_txt_domain' ); ?></button>
							<button type="button" class="button bokun-msg-regenerate"><?php esc_html_e( 'Regenerate', 'BOKUN_txt_domain' ); ?></button>
							<button type="button" class="button bokun-msg-ignore"><?php esc_html_e( 'Dismiss', 'BOKUN_txt_domain' ); ?></button>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<script>
( function () {
	var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
	var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
	var noticeEl = document.getElementById( 'bokun-msg-inbox-notice' );

	function notify( msg, ok ) {
		noticeEl.className = 'notice ' + ( ok ? 'notice-success' : 'notice-error' );
		noticeEl.querySelector( 'p' ).textContent = msg;
		noticeEl.style.display = 'block';
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function post( payload ) {
		var data = new FormData();
		data.append( 'nonce', nonce );
		Object.keys( payload ).forEach( function ( k ) { data.append( k, payload[ k ] ); } );
		return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( r ) { return r.json(); } );
	}

	function cardOf( el ) { return el.closest( '.bokun-msg-card' ); }
	function idOf( el ) { return cardOf( el ).getAttribute( 'data-id' ); }

	var fetchBtn = document.getElementById( 'bokun-msg-fetch-now' );
	if ( fetchBtn ) {
		fetchBtn.addEventListener( 'click', function () {
			fetchBtn.disabled = true;
			post( { action: 'bokun_msg_fetch_now' } )
				.then( function ( res ) {
					notify( ( res.data && res.data.msg ) || 'Done.', !!res.success );
					if ( res.success ) { setTimeout( function () { location.reload(); }, 900 ); }
				} )
				.catch( function () { notify( 'Request failed.', false ); } )
				.finally( function () { fetchBtn.disabled = false; } );
		} );
	}

	document.querySelectorAll( '.bokun-msg-send' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var card = cardOf( btn );
			var body = card.querySelector( '.bokun-msg-draft' ).value;
			if ( ! window.confirm( 'Send this reply to the customer?' ) ) { return; }
			btn.disabled = true;
			post( { action: 'bokun_msg_approve_send', message_id: idOf( btn ), reply_body: body } )
				.then( function ( res ) {
					notify( ( res.data && res.data.msg ) || 'Done.', !!res.success );
					if ( res.success ) { card.style.opacity = '0.5'; card.querySelector( '.bokun-msg-actions' ).remove(); }
				} )
				.catch( function () { notify( 'Request failed.', false ); } )
				.finally( function () { btn.disabled = false; } );
		} );
	} );

	document.querySelectorAll( '.bokun-msg-regenerate' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			post( { action: 'bokun_msg_generate_draft', message_id: idOf( btn ) } )
				.then( function ( res ) {
					if ( res.success && res.data ) {
						cardOf( btn ).querySelector( '.bokun-msg-draft' ).value = res.data.draft || '';
						notify( 'Draft regenerated.', true );
					} else {
						notify( ( res.data && res.data.msg ) || 'Failed.', false );
					}
				} )
				.catch( function () { notify( 'Request failed.', false ); } )
				.finally( function () { btn.disabled = false; } );
		} );
	} );

	document.querySelectorAll( '.bokun-msg-ignore' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Dismiss this message?' ) ) { return; }
			post( { action: 'bokun_msg_ignore', message_id: idOf( btn ) } )
				.then( function ( res ) {
					if ( res.success ) { cardOf( btn ).remove(); }
					else { notify( ( res.data && res.data.msg ) || 'Failed.', false ); }
				} );
		} );
	} );

	document.querySelectorAll( '.bokun-msg-assign-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var card = cardOf( btn );
			var bid  = card.querySelector( '.bokun-msg-booking-id' ).value;
			if ( ! bid ) { notify( 'Enter a booking ID.', false ); return; }
			btn.disabled = true;
			post( { action: 'bokun_msg_assign_booking', message_id: idOf( btn ), booking_id: bid } )
				.then( function ( res ) {
					if ( res.success ) {
						notify( ( res.data && res.data.msg ) || 'Assigned.', true );
						setTimeout( function () { location.reload(); }, 800 );
					} else {
						notify( ( res.data && res.data.msg ) || 'Failed.', false );
						btn.disabled = false;
					}
				} )
				.catch( function () { notify( 'Request failed.', false ); btn.disabled = false; } );
		} );
	} );
}() );
</script>
