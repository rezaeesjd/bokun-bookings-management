<?php
if (!defined('ABSPATH')) {
    exit;
}
$config = self::settings();
$contexts = function_exists('bokun_get_configured_api_credentials') ? bokun_get_configured_api_credentials() : array();
?>
<div class="wrap">
    <h1>Booking Messages — Connections</h1>
    <p>Only signed-in administrators can read messages, generate drafts, edit replies, or send. No AI draft is sent automatically.</p>
    <?php if (isset($_GET['saved']) || isset($_GET['connected'])) : ?><div class="notice notice-success"><p>Settings saved. Check each mailbox connection below.</p></div><?php endif; ?>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=bokun-messages')); ?>">Open message inbox</a></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="bokun_messages_save_settings">
        <?php wp_nonce_field('bokun_messages_settings'); ?>
        <table class="form-table" role="presentation">
            <tr><th><label for="bokun-google-client">Google OAuth client ID</label></th><td><input id="bokun-google-client" class="regular-text" name="google_client_id" value="<?php echo esc_attr($config['google_client_id']); ?>"><p class="description">Use a Web application OAuth client with Gmail API enabled. Reading and sending scopes are requested separately from your normal browser session.</p></td></tr>
            <tr><th><label for="bokun-google-secret">Google OAuth client secret</label></th><td><input type="password" id="bokun-google-secret" class="regular-text" name="google_client_secret" autocomplete="new-password" value=""><p><?php echo self::secret('google_client_secret') ? 'A secret is saved. Leave blank to keep it.' : 'No client secret saved.'; ?></p></td></tr>
            <tr><th>Authorized redirect URI</th><td><code><?php echo esc_html(BOKUN_Message_Mail::callback_url()); ?></code></td></tr>
            <tr><th><label for="bokun-openai-key">OpenAI API key</label></th><td><input type="password" id="bokun-openai-key" class="regular-text" name="openai_key" autocomplete="new-password" value=""><p><?php echo self::secret('openai_key') ? 'An API key is configured. Leave blank to keep it.' : 'No API key configured on this server.'; ?></p><p class="description">Alternatively configure BOKUN_MESSAGES_OPENAI_API_KEY in wp-config.php or OPENAI_API_KEY in the server environment. Keys are never included in browser responses.</p></td></tr>
            <tr><th><label for="bokun-openai-model">Drafting model</label></th><td><input id="bokun-openai-model" name="model" value="<?php echo esc_attr($config['model']); ?>" required></td></tr>
            <?php foreach (self::accounts() as $key => $account) : ?>
                <tr><th><label for="<?php echo esc_attr($key); ?>-context"><?php echo esc_html($account['name']); ?> — Bókun account</label></th><td><select id="<?php echo esc_attr($key); ?>-context" name="<?php echo esc_attr($key); ?>_context"><option value="">Select and verify the business account</option><?php foreach ($contexts as $slug => $context) : ?><option value="<?php echo esc_attr($slug); ?>" <?php selected($account['context'], $slug); ?>><?php echo esc_html($context['label'] ?? $slug); ?></option><?php endforeach; ?></select><p class="description">Verify the API account belongs to this business. Historical lookup uses this account only. Changing it invalidates existing booking snapshots and drafts.</p></td></tr>
            <?php endforeach; ?>
            <tr><th>Automatic drafting</th><td><label><input type="checkbox" name="auto_draft" value="1" <?php checked($config['auto_draft']); ?>> Prepare drafts after mailbox synchronization. The administrator must still review and explicitly approve sending.</label></td></tr>
        </table>
        <?php submit_button('Save message settings'); ?>
    </form>
    <h2>Gmail mailboxes</h2>
    <?php foreach (self::account_status() as $key => $account) : ?>
        <section><h3><?php echo esc_html($account['name']); ?></h3><p><?php echo esc_html($account['email']); ?> — <?php echo $account['connected'] ? 'Connected' : 'Not connected'; ?></p>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'bokun_messages_connect', 'account' => $key), admin_url('admin-post.php')), 'bokun_messages_connect')); ?>"><?php echo $account['connected'] ? 'Reconnect Gmail' : 'Connect Gmail'; ?></a>
        <?php if ($account['sync']) : ?><p><?php echo esc_html(($account['sync']['at'] ?? '') . ' — ' . ($account['sync']['message'] ?? '')); ?></p><?php endif; ?></section>
    <?php endforeach; ?>
    <h2>How synchronization works</h2>
    <p>Initial synchronization checks the last 90 days, including Sent, and imports earlier messages within matching threads. Messages are classified before AI processing; unrelated mail is excluded. Attachments are listed, not opened. Background checks run every five minutes through WP-Cron, with paginated backfill and overlap recovery. Configure a real scheduled call to WP-Cron on low-traffic sites.</p>
    <p>Replies written in another mailbox or directly in Viator might not be captured. These gaps remain visible in the review screen. Verify the portal and its administrator access before enabling production ingestion.</p>
</div>
