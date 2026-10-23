<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="bokun-messages" data-bokun-messages data-api="<?php echo esc_url(rest_url('bokun/v1/messages/')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" aria-label="Booking messages">
    <header class="bokun-messages__header">
        <div><h2>Messages</h2><p>Review the conversation, verify the booking, then edit and approve the reply.</p></div>
        <a href="<?php echo esc_url(admin_url('admin.php?page=bokun-messages-settings')); ?>">Connections &amp; settings</a>
    </header>
    <p data-msg-status role="status" aria-live="polite"></p>
    <div class="bokun-messages__toolbar">
        <label>Business <select data-msg-account><option value="">All businesses</option><?php foreach (self::accounts() as $key => $account) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($account['name']); ?></option><?php endforeach; ?></select></label>
        <label>Status <select data-msg-filter><option value="attention">Needs attention</option><option value="all">All messages</option><option value="needs_reply">Needs reply</option><option value="needs_review">Needs review</option><option value="answered">Answered</option><option value="closed">Handled</option></select></label>
        <label>Sort <select data-msg-sort><option value="recent" selected>Last message (newest)</option><option value="oldest">Last message (oldest)</option><option value="priority">Priority (needs reply first)</option></select></label>
        <label>Search inbox <input type="search" data-msg-search placeholder="Booking, customer or message"></label>
        <button type="button" data-msg-sync>Sync both mailboxes</button>
        <button type="button" data-msg-reload>Refresh inbox</button>
    </div>
    <p data-msg-counts class="bokun-messages__counts"></p>
    <div data-msg-connections class="bokun-messages__connections"></div>
    <div class="bokun-messages__layout">
        <aside aria-label="Conversations"><div data-msg-list></div><div class="bokun-messages__pagination"><button type="button" data-msg-prev>Previous</button><span data-msg-page></span><button type="button" data-msg-next>Next</button></div></aside>
        <div data-msg-detail class="bokun-messages__detail"><p>Select a conversation. If the inbox is empty, connect Gmail and synchronize.</p></div>
    </div>
</section>
