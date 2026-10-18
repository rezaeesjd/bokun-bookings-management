<?php
/**
 * AI Inbox — settings screen.
 *
 * Rendered by Bokun_Messaging::render_settings_page(). Expects:
 *   $s       array   current settings (from get_settings()).
 *   $imap_ok bool    whether PHP ext-imap is available.
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ajax_url = admin_url( 'admin-ajax.php' );
$nonce    = wp_create_nonce( 'bokun_msg_admin' );
?>
<div class="wrap bokun-msg-settings">
	<h1><?php esc_html_e( 'AI Inbox Settings', 'BOKUN_txt_domain' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Ingest customer messages, draft replies with Claude using each booking\'s full context, and send after you approve them. Nothing is sent automatically.', 'BOKUN_txt_domain' ); ?>
	</p>

	<?php if ( ! $imap_ok ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The PHP IMAP extension (ext-imap) is not enabled on this server, so mailbox polling will not work until your host enables it. The rest of the settings can still be saved.', 'BOKUN_txt_domain' ); ?></p>
		</div>
	<?php endif; ?>

	<div id="bokun-msg-settings-notice" class="notice" style="display:none;"><p></p></div>

	<form id="bokun-msg-settings-form">
		<h2 class="title"><?php esc_html_e( 'General', 'BOKUN_txt_domain' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable AI Inbox', 'BOKUN_txt_domain' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> />
						<?php esc_html_e( 'Poll the mailbox and generate drafts', 'BOKUN_txt_domain' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-draft on arrival', 'BOKUN_txt_domain' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="auto_draft" value="1" <?php checked( ! empty( $s['auto_draft'] ) ); ?> />
						<?php esc_html_e( 'Generate a draft automatically when a matched message arrives', 'BOKUN_txt_domain' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Claude (Anthropic API)', 'BOKUN_txt_domain' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bokun-msg-api-key"><?php esc_html_e( 'API key', 'BOKUN_txt_domain' ); ?></label></th>
				<td>
					<input type="password" id="bokun-msg-api-key" name="claude_api_key" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['claude_api_key'] ); ?>" />
					<p class="description"><?php esc_html_e( 'A paid Anthropic API key (sk-ant-...). Billed per token, separate from any Claude subscription.', 'BOKUN_txt_domain' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bokun-msg-model"><?php esc_html_e( 'Model', 'BOKUN_txt_domain' ); ?></label></th>
				<td>
					<select id="bokun-msg-model" name="model">
						<?php
						$models = array(
							'claude-opus-5-5'   => 'Claude Opus 5.5 — most capable (default)',
							'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — balanced, cheaper',
							'claude-haiku-5-5'  => 'Claude Haiku 5.5 — fastest, cheapest',
						);
						foreach ( $models as $id => $label ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $id ),
								selected( $s['model'], $id, false ),
								esc_html( $label )
							);
						}
						?>
					</select>
					<p class="description"><?php esc_html_e( 'Sonnet or Haiku cut cost sharply for routine replies; Opus gives the best judgment on tricky ones.', 'BOKUN_txt_domain' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bokun-msg-max-tokens"><?php esc_html_e( 'Max reply length (tokens)', 'BOKUN_txt_domain' ); ?></label></th>
				<td>
					<input type="number" id="bokun-msg-max-tokens" name="max_tokens" min="128" max="4096" value="<?php echo esc_attr( (int) $s['max_tokens'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bokun-msg-system-prompt"><?php esc_html_e( 'Tone & policy instructions', 'BOKUN_txt_domain' ); ?></label></th>
				<td>
					<textarea id="bokun-msg-system-prompt" name="system_prompt" rows="8" class="large-text"><?php echo esc_textarea( $s['system_prompt'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'How the assistant should write — voice, do/don\'t rules, what it may promise. This is the system prompt for every draft.', 'BOKUN_txt_domain' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Sending', 'BOKUN_txt_domain' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bokun-msg-reply-from"><?php esc_html_e( 'Reply "From" address', 'BOKUN_txt_domain' ); ?></label></th>
				<td>
					<input type="email" id="bokun-msg-reply-from" name="reply_from" class="regular-text" value="<?php echo esc_attr( $s['reply_from'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Approved replies are emailed to the thread\'s relay address. Some channels only accept replies from your registered operator email — set that here.', 'BOKUN_txt_domain' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Mailbox (IMAP)', 'BOKUN_txt_domain' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bokun-msg-imap-host"><?php esc_html_e( 'IMAP host', 'BOKUN_txt_domain' ); ?></label></th>
				<td><input type="text" id="bokun-msg-imap-host" name="imap_host" class="regular-text" placeholder="imap.gmail.com" value="<?php echo esc_attr( $s['imap_host'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Port / SSL', 'BOKUN_txt_domain' ); ?></th>
				<td>
					<input type="number" name="imap_port" value="<?php echo esc_attr( (int) $s['imap_port'] ); ?>" style="width:90px" />
					<label style="margin-left:12px">
						<input type="checkbox" name="imap_ssl" value="1" <?php checked( ! empty( $s['imap_ssl'] ) ); ?> />
						<?php esc_html_e( 'Use SSL', 'BOKUN_txt_domain' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bokun-msg-imap-user"><?php esc_html_e( 'Username', 'BOKUN_txt_domain' ); ?></label></th>
				<td><input type="text" id="bokun-msg-imap-user" name="imap_user" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['imap_user'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="bokun-msg-imap-pass"><?php esc_html_e( 'Password', 'BOKUN_txt_domain' ); ?></label></th>
				<td>
					<input type="password" id="bokun-msg-imap-pass" name="imap_pass" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['imap_pass'] ? esc_attr__( '••••••• (unchanged)', 'BOKUN_txt_domain' ) : ''; ?>" value="" />
					<p class="description"><?php esc_html_e( 'For Gmail, use an App Password (not your account password). Leave blank to keep the saved one.', 'BOKUN_txt_domain' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bokun-msg-imap-mailbox"><?php esc_html_e( 'Mailbox / folder', 'BOKUN_txt_domain' ); ?></label></th>
				<td><input type="text" id="bokun-msg-imap-mailbox" name="imap_mailbox" class="regular-text" value="<?php echo esc_attr( $s['imap_mailbox'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="bokun-msg-sender-filter"><?php esc_html_e( 'Only ingest messages involving', 'BOKUN_txt_domain' ); ?></label></th>
				<td>
					<input type="text" id="bokun-msg-sender-filter" name="sender_filter" class="regular-text" value="<?php echo esc_attr( $s['sender_filter'] ); ?>" />
					<p class="description"><?php esc_html_e( 'A substring the relay address contains, e.g. expmessaging.tripadvisor.com. Leave blank to ingest every unread message.', 'BOKUN_txt_domain' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'BOKUN_txt_domain' ); ?></button>
		</p>
	</form>
</div>

<script>
( function () {
	var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
	var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
	var form    = document.getElementById( 'bokun-msg-settings-form' );
	var noticeEl = document.getElementById( 'bokun-msg-settings-notice' );

	function notify( msg, ok ) {
		noticeEl.className = 'notice ' + ( ok ? 'notice-success' : 'notice-error' );
		noticeEl.querySelector( 'p' ).textContent = msg;
		noticeEl.style.display = 'block';
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var data = new FormData( form );
		data.append( 'action', 'bokun_msg_save_settings' );
		data.append( 'nonce', nonce );

		fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				notify( ( res.data && res.data.msg ) || 'Done.', !!res.success );
			} )
			.catch( function () { notify( 'Request failed.', false ); } );
	} );
}() );
</script>
