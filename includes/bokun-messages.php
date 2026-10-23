<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-bokun-message-parser.php';
require_once __DIR__ . '/class-bokun-message-mail.php';
require_once __DIR__ . '/class-bokun-message-ai.php';

class BOKUN_Messages {
    public static function boot() {
        add_action('init', array(__CLASS__, 'install'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_post_bokun_messages_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_bokun_messages_connect', array('BOKUN_Message_Mail', 'connect'));
        add_action('admin_post_bokun_messages_oauth_callback', array('BOKUN_Message_Mail', 'callback'));
        add_filter('cron_schedules', array(__CLASS__, 'schedules'));
        add_action('bokun_messages_poll', array(__CLASS__, 'poll'));
        add_shortcode('bokun_messages', array(__CLASS__, 'render'));
    }

    public static function settings() {
        return wp_parse_args(get_option('bokun_messages_settings', array()), array(
            'google_client_id' => '', 'model' => 'gpt-4.1-mini', 'auto_draft' => 0,
            'florence-adventures_context' => '', 'via-florence_context' => '',
        ));
    }

    public static function accounts() {
        $config = self::settings();
        return array(
            'florence-adventures' => array('name' => 'Florence Adventures', 'email' => 'amerigovespuccitours@gmail.com', 'context' => $config['florence-adventures_context'], 'vendors' => array('Florence Adventures', 'My Florence Tour Srl')),
            'via-florence' => array('name' => 'Via Florence', 'email' => 'fiorifirenzesrl@gmail.com', 'context' => $config['via-florence_context'], 'vendors' => array('Via Florence', 'Taste of Florence Tours')),
        );
    }

    public static function secret($name) {
        if ('openai_key' === $name) {
            if (defined('BOKUN_MESSAGES_OPENAI_API_KEY')) {
                return BOKUN_MESSAGES_OPENAI_API_KEY;
            }
            if (getenv('OPENAI_API_KEY')) {
                return getenv('OPENAI_API_KEY');
            }
        }
        $encrypted = get_option('bokun_messages_secret_' . $name, '');
        if (!$encrypted || !function_exists('openssl_decrypt')) {
            return '';
        }
        $packed = base64_decode($encrypted, true);
        if (false === $packed || strlen($packed) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($packed, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($packed, 0, 12), substr($packed, 12, 16));
        return false === $plain ? '' : $plain;
    }

    public static function set_secret($name, $value) {
        if (!function_exists('openssl_encrypt')) {
            return new WP_Error('encryption', 'PHP OpenSSL is required to protect mailbox credentials.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $nonce, $tag);
        if (false === $encrypted) {
            return new WP_Error('encryption', 'Credential encryption failed.');
        }
        update_option('bokun_messages_secret_' . $name, base64_encode($nonce . $tag . $encrypted), false);
        return true;
    }

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'bokun_msg_' . $name;
    }

    public static function lock($name) {
        $previous = (int) get_option($name, 0);
        if ($previous && $previous < time() - 600) {
            global $wpdb;
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, (string) $previous));
            wp_cache_delete($name, 'options');
        }
        return add_option($name, time(), '', false);
    }

    public static function install() {
        if ('2' !== get_option('bokun_messages_db_version')) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $collate = $wpdb->get_charset_collate();
            dbDelta('CREATE TABLE ' . self::table('conversations') . " (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                conversation_key char(64) NOT NULL,
                account varchar(64) NOT NULL,
                reference varchar(32) NOT NULL DEFAULT '',
                subject text NOT NULL,
                state varchar(32) NOT NULL DEFAULT 'needs_review',
                version bigint unsigned NOT NULL DEFAULT 1,
                latest_at datetime NOT NULL,
                notes longtext NOT NULL,
                booking_snapshot longtext NOT NULL,
                booking_checked_at bigint NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY conversation_key (conversation_key),
                KEY account_latest (account,latest_at)
            ) $collate;");
            dbDelta('CREATE TABLE ' . self::table('messages') . " (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                conversation_id bigint unsigned NOT NULL,
                dedupe_key char(64) NOT NULL,
                provider_id varchar(128) NOT NULL,
                thread_id varchar(128) NOT NULL,
                rfc_id text NOT NULL,
                kind varchar(24) NOT NULL,
                trusted tinyint NOT NULL DEFAULT 0,
                subject text NOT NULL,
                body longtext NOT NULL,
                reply_to varchar(254) NOT NULL,
                refs text NOT NULL,
                sent_at datetime NOT NULL,
                attachments longtext NOT NULL,
                email_context longtext NOT NULL,
                review_reason text NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dedupe_key (dedupe_key),
                KEY conversation_order (conversation_id,sent_at)
            ) $collate;");
            dbDelta('CREATE TABLE ' . self::table('drafts') . " (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                conversation_id bigint unsigned NOT NULL,
                context_hash char(64) NOT NULL,
                conversation_version bigint unsigned NOT NULL DEFAULT 0,
                body longtext NOT NULL,
                analysis longtext NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'draft',
                actor bigint unsigned NOT NULL DEFAULT 0,
                approved_by bigint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                approved_at datetime DEFAULT NULL,
                send_id varchar(128) NOT NULL DEFAULT '',
                error text NOT NULL,
                PRIMARY KEY  (id),
                KEY conversation_draft (conversation_id,id)
            ) $collate;");
            update_option('bokun_messages_db_version', '2', false);
        }
        if (!wp_next_scheduled('bokun_messages_poll')) {
            wp_schedule_event(time() + 60, 'bokun_messages_five_minutes', 'bokun_messages_poll');
        }
    }

    public static function schedules($schedules) {
        $schedules['bokun_messages_five_minutes'] = array('interval' => 300, 'display' => 'Every five minutes');
        return $schedules;
    }

    public static function can_manage() {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    public static function routes() {
        foreach (array('inbox' => 'GET', 'conversation' => 'GET', 'sync' => 'POST', 'refresh' => 'POST', 'notes' => 'POST', 'draft' => 'POST', 'revise' => 'POST', 'save' => 'POST', 'send' => 'POST', 'close' => 'POST', 'reopen' => 'POST', 'reconcile' => 'POST') as $action => $method) {
            register_rest_route('bokun/v1', '/messages/' . $action, array(
                'methods' => $method,
                'permission_callback' => array(__CLASS__, 'permission'),
                'callback' => function ($request) use ($action) { return BOKUN_Messages::dispatch($action, $request); },
            ));
        }
    }

    public static function permission($request) {
        if (!self::can_manage() || !wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_Error('messages_forbidden', 'Sign in as an administrator to access booking messages.', array('status' => 403));
        }
        return true;
    }

    public static function conversation($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('conversations') . ' WHERE id=%d', $id), ARRAY_A);
    }

    public static function messages($id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('messages') . ' WHERE conversation_id=%d ORDER BY sent_at,id', $id), ARRAY_A);
    }

    public static function latest_draft($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('drafts') . ' WHERE conversation_id=%d ORDER BY id DESC LIMIT 1', $id), ARRAY_A);
    }

    public static function ingest($account, $message) {
        global $wpdb;
        $identity = $message['reference'] ?: 'review:' . $message['thread_id'];
        $key = hash('sha256', $account . ':' . $identity);
        $inserted = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::table('conversations') . " (conversation_key,account,reference,subject,latest_at,notes,booking_snapshot) VALUES (%s,%s,%s,%s,%s,'','')", $key, $account, $message['reference'], $message['subject'], $message['sent_at']));
        if (false === $inserted) {
            return new WP_Error('messages_storage', 'Could not store the conversation.');
        }
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('conversations') . ' WHERE conversation_key=%s', $key));
        $lock = 'bokun_messages_conversation_lock_' . $id;
        if (!self::lock($lock)) {
            return new WP_Error('messages_busy', 'This conversation is being updated. Retry synchronization shortly.');
        }
        try {
            $dedupe = hash('sha256', $account . ':' . ($message['rfc_id'] ?: $message['provider_id']));
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('messages') . ' WHERE dedupe_key=%s', $dedupe))) {
                return 0;
            }
            $result = $wpdb->insert(self::table('messages'), array(
                'conversation_id' => $id, 'dedupe_key' => $dedupe, 'provider_id' => $message['provider_id'], 'thread_id' => $message['thread_id'], 'rfc_id' => $message['rfc_id'],
                'kind' => $message['kind'], 'trusted' => (int) $message['trusted'], 'subject' => $message['subject'], 'body' => $message['body'], 'reply_to' => $message['reply_to'],
                'refs' => $message['references'], 'sent_at' => $message['sent_at'], 'attachments' => wp_json_encode($message['attachments']), 'email_context' => wp_json_encode($message['email_context']), 'review_reason' => $message['review_reason'],
            ));
            if (false === $result) {
                return new WP_Error('messages_storage', 'Could not store the message.');
            }
            $message_id = (int) $wpdb->insert_id;
            $latest_action = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('messages') . " WHERE conversation_id=%d AND kind IN ('customer','outgoing') ORDER BY sent_at DESC,id DESC LIMIT 1", $id));
            $state = !$message['trusted'] || !$message['reference'] || $message['review_reason'] ? 'needs_review' : ('outgoing' === $message['kind'] ? 'answered' : ('customer' === $message['kind'] ? 'needs_reply' : 'needs_review'));
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table('conversations') . ' SET version=version+1, state=CASE WHEN %d=1 THEN %s ELSE state END, latest_at=GREATEST(latest_at,%s) WHERE id=%d', $latest_action === $message_id ? 1 : 0, $state, $message['sent_at'], $id));
            return 1;
        } finally {
            delete_option($lock);
        }
    }

    public static function match_booking($conversation) {
        if (!$conversation['reference']) {
            return array();
        }
        $accounts = self::accounts();
        $account = $accounts[$conversation['account']];
        $posts = get_posts(array('post_type' => 'bokun_booking', 'post_status' => array('publish', 'draft', 'private', 'pending', 'future'), 'numberposts' => -1, 'fields' => 'ids', 'meta_query' => array(array('key' => '_external_booking_reference', 'value' => array($conversation['reference'], 'BR-' . $conversation['reference']), 'compare' => 'IN'))));
        $matches = array();
        foreach ($posts as $post_id) {
            $vendor = get_post_meta($post_id, 'productBookings_0_vendor_title', true);
            $context = get_post_meta($post_id, '_bokun_import_context', true);
            if (($account['context'] && $context === $account['context']) || (!$context && in_array($vendor, $account['vendors'], true))) {
                $matches[] = (int) $post_id;
            }
        }
        return $matches;
    }

    public static function booking_fields($booking) {
        $products = array();
        foreach ($booking['productBookings'] ?? array() as $item) {
            $products[] = array(
                'id' => $item['product']['id'] ?? '', 'title' => $item['product']['title'] ?? '',
                'vendor' => $item['vendor']['title'] ?? '', 'status' => $item['status'] ?? '',
                'date' => $item['startDateTime'] ?? ($item['startDate'] ?? ''),
                'option' => $item['fields']['rateTitle'] ?? '', 'fields' => $item['fields'] ?? array(),
                'notes' => array_map(function ($note) { return BOKUN_Message_Parser::text($note['body'] ?? ''); }, $item['notes'] ?? array()),
            );
        }
        return array('confirmation' => $booking['confirmationCode'] ?? '', 'reference' => $booking['externalBookingReference'] ?? '', 'traveler_name' => trim(($booking['customer']['firstName'] ?? '') . ' ' . ($booking['customer']['lastName'] ?? '')), 'status' => $booking['status'] ?? '', 'products' => $products);
    }

    public static function product_context($product_id, $context) {
        $activity = function_exists('bokun_fetch_activity_payload') ? bokun_fetch_activity_payload($product_id, $context) : null;
        if (!$activity || (int) ($activity['id'] ?? 0) !== (int) $product_id) {
            return array('warning' => 'Product details could not be retrieved from Bókun. Verify itinerary and inclusions manually.');
        }
        $selected = array();
        foreach (array('id', 'externalId', 'title', 'description', 'excerpt', 'lastModified', 'timeZone', 'included', 'excluded', 'requirements', 'attention', 'startPoints', 'agendaItems', 'durationMinutes', 'durationHours', 'durationDays') as $field) {
            if (isset($activity[$field])) {
                $selected[$field] = self::clean_context($activity[$field]);
            }
        }
        $selected['option_descriptions'] = array();
        foreach ($activity['rates'] ?? array() as $rate) {
            $selected['option_descriptions'][] = array('id' => $rate['id'] ?? '', 'title' => $rate['title'] ?? '', 'description' => self::clean_context($rate['description'] ?? ''));
        }
        return $selected;
    }

    public static function clean_context($value) {
        if (is_array($value)) {
            return array_map(array(__CLASS__, 'clean_context'), $value);
        }
        return is_string($value) ? BOKUN_Message_Parser::text($value) : $value;
    }

    public static function refresh_booking($conversation) {
        global $wpdb;
        $accounts = self::accounts();
        $context = $accounts[$conversation['account']]['context'];
        $credentials = function_exists('bokun_get_configured_api_credentials') ? bokun_get_configured_api_credentials() : array();
        if (!$context || !isset($credentials[$context])) {
            return new WP_Error('booking_account', 'Select and verify the Bókun API account for this mailbox in Messages settings.');
        }
        if (!$conversation['reference']) {
            return new WP_Error('booking_reference', 'This conversation needs an unambiguous booking reference.');
        }
        $credential = $credentials[$context];
        $date = gmdate('Y-m-d H:i:s');
        $endpoint = '/booking.json/booking-search';
        $signature = bokun_generate_signature($date, $credential['api_key'], 'POST', $endpoint, $credential['secret_key']);
        $response = wp_remote_post('https://api.bokun.io' . $endpoint, array('timeout' => 30, 'redirection' => 0, 'headers' => array('X-Bokun-AccessKey' => $credential['api_key'], 'X-Bokun-Date' => $date, 'X-Bokun-Signature' => $signature, 'Content-Type' => 'application/json'), 'body' => wp_json_encode(array('externalBookingReference' => $conversation['reference'], 'page' => 1, 'pageSize' => 20))));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return new WP_Error('booking_refresh', 'Bókun could not refresh this booking. No operational changes were made.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $items = $data['items'] ?? array();
        $matches = array_values(array_filter($items, function ($booking) use ($conversation) {
            return preg_replace('/^BR-/i', '', (string) ($booking['externalBookingReference'] ?? '')) === $conversation['reference'];
        }));
        if (count($matches) !== 1 || !empty($data['hasMore']) || (int) ($data['totalCount'] ?? count($items)) > 20) {
            return new WP_Error('booking_match', 'Bókun did not return one unique booking in this business account. Review the reference and mapping.');
        }
        $snapshot = self::booking_fields($matches[0]);
        foreach ($snapshot['products'] as &$product) {
            $product['catalog'] = self::product_context($product['id'], $context);
        }
        unset($product);
        $snapshot_json = wp_json_encode($snapshot);
        $changed = $snapshot_json !== $conversation['booking_snapshot'];
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table('conversations') . ' SET booking_snapshot=%s,booking_checked_at=%d,version=version+%d WHERE id=%d', $snapshot_json, time(), $changed ? 1 : 0, $conversation['id']));
        return true;
    }

    public static function context($conversation) {
        $matches = self::match_booking($conversation);
        $booking = json_decode($conversation['booking_snapshot'] ?: '{}', true) ?: array();
        $warnings = array('Email history may omit replies made in other mailboxes or Viator. Review coverage before approving.');
        if (!$booking) {
            $warnings[] = 'Live Bókun context has not been retrieved. Refresh booking before sending.';
        }
        if (count($matches) > 1) {
            $warnings[] = 'Multiple local booking records match this reference.';
        }
        $local = array();
        if (count($matches) === 1) {
            $post_id = $matches[0];
            foreach (array('_confirmation_code', '_product_title', '_original_start_datetime', 'productBookings_0_fields_rateTitle', 'bk_meetingpointtitle', 'inclusions_clean') as $field) {
                $local[$field] = BOKUN_Message_Parser::text((string) get_post_meta($post_id, $field, true));
            }
            $terms = wp_get_post_terms($post_id, 'booking_status', array('fields' => 'names'));
            $local['operator_labels'] = is_wp_error($terms) ? array() : $terms;
            $local['post_id'] = $post_id;
            $local['url'] = get_permalink($post_id);
        }
        $messages = self::messages($conversation['id']);
        $history = array();
        $parent = null;
        foreach ($messages as $message) {
            if (!$message['trusted'] || $message['review_reason']) {
                $warnings[] = 'A message requires sender or reference review and is excluded from AI input.';
                continue;
            }
            $history[] = array('id' => $message['id'], 'kind' => $message['kind'], 'at' => $message['sent_at'], 'text' => $message['body'], 'attachments' => json_decode($message['attachments'], true), 'email_context' => json_decode($message['email_context'], true));
            if ('customer' === $message['kind'] && $message['reply_to'] && $message['rfc_id']) {
                $parent = $message;
            }
        }
        if (count($history) > 100) {
            $warnings[] = 'History exceeds 100 messages; AI drafting is disabled until the conversation is reviewed.';
        }
        $statuses = array_values(array_unique(array_filter(array_merge(array($booking['status'] ?? ''), array_column($booking['products'] ?? array(), 'status')))));
        $labels = array_map('strtoupper', $local['operator_labels'] ?? array());
        if ((in_array('CANCELLED', $statuses, true) && (in_array('CONFIRMED', $statuses, true) || in_array('CONFIRMED', $labels, true))) || (in_array('CONFIRMED', $statuses, true) && in_array('CANCELLED', $labels, true))) {
            $warnings[] = 'Booking status conflict: live Bókun statuses and stored operator labels disagree. Use the live booked-product status and verify the workflow labels; neither proves payment or refund status.';
        }
        $data = array('drafting_policy' => BOKUN_Message_AI::POLICY_VERSION, 'business' => self::accounts()[$conversation['account']]['name'], 'reference' => $conversation['reference'], 'live_booking' => $booking, 'local_booking' => $local, 'operator_context' => $conversation['notes'], 'history' => $history, 'warnings' => array_values(array_unique($warnings)));
        $data['hash'] = hash('sha256', wp_json_encode(array($conversation['version'], $data, self::settings()[$conversation['account'] . '_context'])));
        $data['booking_checked_at'] = (int) $conversation['booking_checked_at'];
        $data['reply_to'] = $parent ? $parent['reply_to'] : '';
        $data['parent'] = $parent;
        return $data;
    }

    public static function draft_reply($conversation, $revision = array()) {
        if (self::pending_send($conversation['id'])) {
            return new WP_Error('send_pending', 'Reconcile the uncertain send before generating another reply.');
        }
        $result = self::refresh_booking($conversation);
        if (is_wp_error($result)) {
            return $result;
        }
        $fresh = self::conversation($conversation['id']);
        return BOKUN_Message_AI::generate($fresh, self::context($fresh), $revision);
    }

    public static function save_draft($conversation, $body, $analysis, $hash, $allow_analysis_only = false) {
        global $wpdb;
        if (self::pending_send($conversation['id'])) {
            return new WP_Error('send_pending', 'An earlier send is uncertain. Reconcile it before creating another reply.');
        }
        $analysis_only = $allow_analysis_only && '' === trim($body) && false === ($analysis['reply_needed'] ?? null);
        if ((!$body && !$analysis_only) || strlen($body) > 20000) {
            return new WP_Error('draft_body', 'Write a reply between 1 and 20,000 bytes.');
        }
        $result = $wpdb->insert(self::table('drafts'), array('conversation_id' => $conversation['id'], 'conversation_version' => $conversation['version'], 'context_hash' => $hash, 'body' => $body, 'status' => $analysis_only ? 'review' : 'draft', 'analysis' => wp_json_encode($analysis), 'actor' => get_current_user_id(), 'created_at' => gmdate('Y-m-d H:i:s'), 'error' => ''));
        return false === $result ? new WP_Error('draft_storage', 'The draft could not be saved.') : (int) $wpdb->insert_id;
    }

    public static function detail($conversation) {
        global $wpdb;
        $versions = $wpdb->get_results($wpdb->prepare('SELECT id,body,status,created_at,actor FROM ' . self::table('drafts') . ' WHERE conversation_id=%d ORDER BY id DESC LIMIT 10', $conversation['id']), ARRAY_A);
        return array('conversation' => $conversation, 'messages' => self::messages($conversation['id']), 'draft' => self::latest_draft($conversation['id']), 'draft_versions' => $versions, 'context' => self::context($conversation), 'account' => self::accounts()[$conversation['account']]);
    }

    public static function dispatch($action, $request) {
        global $wpdb;
        if ('inbox' === $action) {
            $account = sanitize_key($request['account'] ?? '');
            $page = max(1, (int) ($request['page'] ?? 1));
            $conditions = array();
            if ($account && isset(self::accounts()[$account])) {
                $conditions[] = $wpdb->prepare('account=%s', $account);
            }
            $search = sanitize_text_field($request['search'] ?? '');
            if ($search) {
                $pattern = '%' . $wpdb->esc_like($search) . '%';
                $conditions[] = $wpdb->prepare('(reference LIKE %s OR subject LIKE %s OR booking_snapshot LIKE %s OR EXISTS (SELECT 1 FROM ' . self::table('messages') . ' search_message WHERE search_message.conversation_id=' . self::table('conversations') . '.id AND search_message.body LIKE %s))', $pattern, $pattern, $pattern, $pattern);
            }
            $base_where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
            $counts = array('needs_reply' => 0, 'needs_review' => 0, 'answered' => 0, 'closed' => 0);
            foreach ($wpdb->get_results('SELECT state,COUNT(*) AS total FROM ' . self::table('conversations') . $base_where . ' GROUP BY state', ARRAY_A) as $count) {
                $counts[$count['state']] = (int) $count['total'];
            }
            $state = sanitize_key($request['state'] ?? 'all');
            if ('attention' === $state) {
                $conditions[] = "state IN ('needs_reply','needs_review')";
            } elseif (isset($counts[$state])) {
                $conditions[] = $wpdb->prepare('state=%s', $state);
            }
            $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
            // Sort is chosen from a fixed allowlist (never interpolated from raw
            // input) so the ORDER BY clause is always a trusted constant. The
            // default preserves the historical order: newest last message first.
            $sort = sanitize_key($request['sort'] ?? 'recent');
            $order_map = array(
                'recent'   => 'latest_at DESC,id DESC',
                'oldest'   => 'latest_at ASC,id ASC',
                'priority' => "CASE state WHEN 'needs_reply' THEN 0 WHEN 'needs_review' THEN 1 WHEN 'answered' THEN 2 WHEN 'closed' THEN 3 ELSE 4 END ASC,latest_at DESC,id DESC",
            );
            $order = isset($order_map[$sort]) ? $order_map[$sort] : $order_map['recent'];
            $rows = $wpdb->get_results('SELECT id,account,reference,subject,state,latest_at,version, (SELECT MAX(draft.id) FROM ' . self::table('drafts') . ' draft WHERE draft.conversation_id=' . self::table('conversations') . '.id) AS latest_draft_id, (SELECT latest.body FROM ' . self::table('messages') . ' latest WHERE latest.conversation_id=' . self::table('conversations') . '.id ORDER BY latest.sent_at DESC,latest.id DESC LIMIT 1) AS preview, (SELECT latest.kind FROM ' . self::table('messages') . ' latest WHERE latest.conversation_id=' . self::table('conversations') . '.id ORDER BY latest.sent_at DESC,latest.id DESC LIMIT 1) AS latest_kind FROM ' . self::table('conversations') . $where . ' ORDER BY ' . $order . $wpdb->prepare(' LIMIT 50 OFFSET %d', ($page - 1) * 50), ARRAY_A);
            foreach ($rows as &$row) {
                $row['preview'] = mb_substr(preg_replace('/\s+/u', ' ', $row['preview'] ?? ''), 0, 200);
            }
            unset($row);
            return array('conversations' => $rows, 'accounts' => self::account_status(), 'counts' => $counts, 'page' => $page, 'total' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('conversations') . $where));
        }
        if ('sync' === $action) {
            return BOKUN_Message_Mail::sync(sanitize_key($request['account'] ?? ''));
        }
        $conversation = self::conversation((int) ($request['id'] ?? 0));
        if (!$conversation) {
            return new WP_Error('conversation_missing', 'Conversation not found.', array('status' => 404));
        }
        if ('conversation' === $action) {
            return self::detail($conversation);
        }
        if ('refresh' === $action) {
            $result = self::refresh_booking($conversation);
            return is_wp_error($result) ? $result : self::detail(self::conversation($conversation['id']));
        }
        if ('reconcile' === $action) {
            return self::reconcile($conversation);
        }
        $context = self::context($conversation);
        if (!hash_equals($context['hash'], (string) ($request['context_hash'] ?? ''))) {
            return new WP_Error('context_changed', 'Messages or booking context changed. Reload and review the conversation.', array('status' => 409));
        }
        if ('send' === $action) {
            return self::send($conversation, $request);
        }
        $lock = 'bokun_messages_conversation_lock_' . $conversation['id'];
        if (!self::lock($lock)) {
            return new WP_Error('conversation_busy', 'This conversation is being updated. Reload before continuing.', array('status' => 409));
        }
        try {
            $fresh = self::conversation($conversation['id']);
            if (!hash_equals($context['hash'], self::context($fresh)['hash'])) {
                return new WP_Error('context_changed', 'Conversation changed. Reload before continuing.', array('status' => 409));
            }
            if ('notes' === $action) {
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table('conversations') . ' SET notes=%s,version=version+1 WHERE id=%d', sanitize_textarea_field($request['notes'] ?? ''), $conversation['id']));
            } elseif ('close' === $action) {
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table('conversations') . " SET state='closed',version=version+1 WHERE id=%d", $conversation['id']));
            } elseif ('reopen' === $action) {
                $history = $context['history'];
                $state = 'needs_review';
                foreach ($history as $item) {
                    if ('customer' === $item['kind']) {
                        $state = 'needs_reply';
                    } elseif ('outgoing' === $item['kind']) {
                        $state = 'answered';
                    }
                }
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table('conversations') . ' SET state=%s,version=version+1 WHERE id=%d', $state, $conversation['id']));
            } elseif ('save' === $action) {
                $previous = self::latest_draft($conversation['id']);
                $analysis = $previous ? (json_decode($previous['analysis'], true) ?: array()) : array();
                $analysis['edited_by'] = get_current_user_id();
                $result = self::save_draft($conversation, sanitize_textarea_field($request['body'] ?? ''), $analysis, $context['hash']);
                if (is_wp_error($result)) {
                    return $result;
                }
            } elseif ('draft' === $action) {
                $result = self::draft_reply($fresh);
                if (is_wp_error($result)) {
                    return $result;
                }
            } elseif ('revise' === $action) {
                $previous = self::latest_draft($conversation['id']);
                if (!isset($request['base_draft_id']) || (int) $request['base_draft_id'] !== (int) ($previous['id'] ?? 0)) {
                    return new WP_Error('draft_changed', 'A newer draft is available. Reload it before asking AI to edit.', array('status' => 409));
                }
                $revision = array('instructions' => sanitize_textarea_field($request['instructions'] ?? ''), 'current_reply' => sanitize_textarea_field($request['body'] ?? ''), 'source_draft_id' => (int) ($previous['id'] ?? 0));
                if (!$revision['instructions'] || strlen($revision['instructions']) > 2000 || !$revision['current_reply'] || strlen($revision['current_reply']) > 20000) {
                    return new WP_Error('revision_input', 'Write a reply and editing instructions of up to 2,000 bytes before asking AI to edit.');
                }
                $result = self::draft_reply($fresh, $revision);
                if (is_wp_error($result)) {
                    return $result;
                }
            }
            return self::detail(self::conversation($conversation['id']));
        } finally {
            delete_option($lock);
        }
    }

    public static function send($conversation, $request) {
        global $wpdb;
        if (self::pending_send($conversation['id'])) {
            return new WP_Error('send_pending', 'Reconcile the earlier uncertain send before sending another reply.');
        }
        if (true !== $request['approved']) {
            return new WP_Error('approval_required', 'Explicit administrator approval is required.');
        }
        $draft = self::latest_draft($conversation['id']);
        if (!$draft || (int) $request['draft_id'] !== (int) $draft['id'] || 'draft' !== $draft['status']) {
            return new WP_Error('draft_changed', 'This draft is no longer available to send. Reload the conversation.');
        }
        $context = self::context($conversation);
        if (!$context['parent'] || !hash_equals($draft['context_hash'], $context['hash'])) {
            return new WP_Error('draft_stale', 'Draft context changed or a verified reply address is missing. Review and save a new draft.');
        }
        $booking = self::refresh_booking($conversation);
        if (is_wp_error($booking)) {
            return $booking;
        }
        $conversation = self::conversation($conversation['id']);
        $fresh = self::context($conversation);
        if (!hash_equals($context['hash'], $fresh['hash'])) {
            return new WP_Error('booking_changed', 'Bókun booking context changed. Review it and save a new draft before sending.', array('status' => 409));
        }
        $parent = $fresh['parent'];
        $remote = BOKUN_Message_Mail::api($conversation['account'], 'threads/' . rawurlencode($parent['thread_id']) . '?format=metadata');
        if (is_wp_error($remote)) {
            return $remote;
        }
        $known = array_column(self::messages($conversation['id']), 'provider_id');
        foreach ($remote['messages'] ?? array() as $message) {
            if (!in_array($message['id'], $known, true)) {
                return new WP_Error('mail_changed', 'The Gmail thread has newer messages. Synchronize and review before sending.', array('status' => 409));
            }
        }
        $search = BOKUN_Message_Mail::api($conversation['account'], 'messages?' . http_build_query(array('q' => 'after:' . (time() - 90 * DAY_IN_SECONDS) . ' "' . $conversation['reference'] . '" {from:expmessaging.tripadvisor.com to:expmessaging.tripadvisor.com from:viator.com from:tripadvisor.com}', 'maxResults' => 100)));
        if (is_wp_error($search)) {
            return $search;
        }
        if (!empty($search['nextPageToken'])) {
            return new WP_Error('history_large', 'Additional booking-related messages need synchronization before sending.');
        }
        foreach ($search['messages'] ?? array() as $entry) {
            if (!in_array($entry['id'], $known, true)) {
                $metadata = BOKUN_Message_Mail::api($conversation['account'], 'messages/' . rawurlencode($entry['id']) . '?format=metadata');
                if (is_wp_error($metadata)) {
                    return $metadata;
                }
                if (BOKUN_Message_Parser::candidate($metadata, self::accounts()[$conversation['account']]['email'])) {
                    return new WP_Error('mail_changed', 'Another thread contains booking-related messages that need synchronization and review.', array('status' => 409));
                }
            }
        }
        $lock = 'bokun_messages_conversation_lock_' . $conversation['id'];
        if (!self::lock($lock)) {
            return new WP_Error('conversation_busy', 'This conversation is being updated. Reload before sending.');
        }
        try {
            $current = self::latest_draft($conversation['id']);
            $now = self::context(self::conversation($conversation['id']));
            if (!$current || (int) $current['id'] !== (int) $draft['id'] || 'draft' !== $current['status'] || !hash_equals($draft['context_hash'], $now['hash'])) {
                return new WP_Error('draft_changed', 'The approved draft changed. Reload before sending.');
            }
            $mailbox = self::accounts()[$conversation['account']]['email'];
            $send_id = '<bokun-draft-' . $draft['id'] . '-' . wp_generate_uuid4() . '@' . substr(strrchr($mailbox, '@'), 1) . '>';
            try {
                $raw = BOKUN_Message_Parser::raw_reply($mailbox, $now['reply_to'], $parent['subject'], $draft['body'], $parent['rfc_id'], $parent['refs'], $send_id);
            } catch (InvalidArgumentException $error) {
                return new WP_Error('mail_headers', 'The original email has invalid reply headers. Review it in Gmail before sending.');
            }
            $claimed = $wpdb->query($wpdb->prepare('UPDATE ' . self::table('drafts') . " SET status='sending',approved_by=%d,approved_at=%s,send_id=%s WHERE id=%d AND status='draft'", get_current_user_id(), gmdate('Y-m-d H:i:s'), $send_id, $draft['id']));
            if (1 !== $claimed) {
                return new WP_Error('send_claim', 'This draft is already being sent.');
            }
            $sent = BOKUN_Message_Mail::api($conversation['account'], 'messages/send', 'POST', array('threadId' => $parent['thread_id'], 'raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')));
            if (is_wp_error($sent) || empty($sent['id'])) {
                $wpdb->update(self::table('drafts'), array('status' => 'unknown', 'error' => 'Sending could not be confirmed. Check Sent using Reconcile; do not resend.'), array('id' => $draft['id']));
                return new WP_Error('send_unknown', 'Sending outcome is unknown. Use Reconcile before taking any further action.');
            }
            $wpdb->update(self::table('drafts'), array('status' => 'sent'), array('id' => $draft['id']));
            $wpdb->insert(self::table('messages'), array('conversation_id' => $conversation['id'], 'dedupe_key' => hash('sha256', $conversation['account'] . ':' . $send_id), 'provider_id' => $sent['id'], 'thread_id' => $sent['threadId'] ?? $parent['thread_id'], 'rfc_id' => $send_id, 'kind' => 'outgoing', 'trusted' => 1, 'subject' => 'Re: ' . preg_replace('/^Re:\s*/i', '', $parent['subject']), 'body' => $draft['body'], 'reply_to' => '', 'refs' => $parent['rfc_id'], 'sent_at' => gmdate('Y-m-d H:i:s'), 'attachments' => '[]', 'email_context' => '{}', 'review_reason' => ''));
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table('conversations') . " SET state='answered',latest_at=%s,version=version+1 WHERE id=%d", gmdate('Y-m-d H:i:s'), $conversation['id']));
            return array('sent' => true, 'message' => 'Gmail accepted the approved reply. Customer delivery is not yet confirmed.', 'provider_id' => $sent['id']);
        } finally {
            delete_option($lock);
        }
    }

    public static function reconcile($conversation) {
        global $wpdb;
        $draft = self::pending_send($conversation['id']);
        if (!$draft || !in_array($draft['status'], array('unknown', 'sending'), true) || !$draft['send_id']) {
            return new WP_Error('reconcile_state', 'There is no uncertain send to reconcile.');
        }
        $result = BOKUN_Message_Mail::api($conversation['account'], 'messages?' . http_build_query(array('q' => 'in:sent rfc822msgid:' . $draft['send_id'])));
        if (is_wp_error($result)) {
            return $result;
        }
        if (empty($result['messages'])) {
            return new WP_Error('send_unresolved', 'No Sent copy was found yet. The draft remains blocked to prevent a duplicate. Review Gmail manually.');
        }
        $remote = BOKUN_Message_Mail::api($conversation['account'], 'messages/' . rawurlencode($result['messages'][0]['id']) . '?format=full');
        if (is_wp_error($remote)) {
            return $remote;
        }
        $parsed = BOKUN_Message_Parser::parse($remote, self::accounts()[$conversation['account']]['email']);
        if (!$parsed || 'outgoing' !== $parsed['kind'] || $parsed['rfc_id'] !== $draft['send_id']) {
            return new WP_Error('send_unresolved', 'The matching Sent message could not be verified. The draft remains blocked.');
        }
        $stored = self::ingest($conversation['account'], $parsed);
        if (is_wp_error($stored)) {
            return $stored;
        }
        $wpdb->update(self::table('drafts'), array('status' => 'sent', 'error' => ''), array('id' => $draft['id']));
        return self::detail(self::conversation($conversation['id']));
    }

    public static function pending_send($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('drafts') . " WHERE conversation_id=%d AND status IN ('unknown','sending') ORDER BY id LIMIT 1", $id), ARRAY_A);
    }

    public static function account_status() {
        $status = array();
        foreach (self::accounts() as $key => $account) {
            $account['connected'] = (bool) self::secret('tokens_' . $key);
            $account['sync'] = get_option('bokun_messages_sync_' . $key, array());
            $status[$key] = $account;
        }
        return $status;
    }

    public static function poll() {
        global $wpdb;
        foreach (self::accounts() as $account => $details) {
            if (!self::secret('tokens_' . $account)) {
                continue;
            }
            $result = BOKUN_Message_Mail::sync($account);
            if (is_wp_error($result)) {
                update_option('bokun_messages_sync_' . $account, array('at' => gmdate('c'), 'message' => $result->get_error_message()), false);
                continue;
            }
            if (!self::settings()['auto_draft'] || !self::secret('openai_key') || !empty($result['more'])) {
                continue;
            }
            $drafts_table = self::table('drafts');
            $rows = $wpdb->get_results($wpdb->prepare('SELECT conversations.* FROM ' . self::table('conversations') . " conversations WHERE account=%s AND state='needs_reply' AND NOT EXISTS (SELECT 1 FROM $drafts_table drafts WHERE drafts.conversation_id=conversations.id AND (drafts.conversation_version=conversations.version OR drafts.status IN ('unknown','sending'))) ORDER BY latest_at DESC LIMIT 3", $account), ARRAY_A);
            foreach ($rows as $conversation) {
                $previous = self::latest_draft($conversation['id']);
                $context = self::context($conversation);
                if (self::pending_send($conversation['id']) || ($previous && hash_equals($previous['context_hash'], $context['hash']))) {
                    continue;
                }
                $lock = 'bokun_messages_conversation_lock_' . $conversation['id'];
                if (self::lock($lock)) {
                    try {
                        $fresh = self::conversation($conversation['id']);
                        $latest = self::latest_draft($conversation['id']);
                        if ($latest && hash_equals($latest['context_hash'], self::context($fresh)['hash'])) {
                            continue;
                        }
                        $result = self::draft_reply($fresh);
                        if (is_wp_error($result)) {
                            update_option('bokun_messages_sync_' . $account, array('at' => gmdate('c'), 'message' => $result->get_error_message()), false);
                        }
                    } finally {
                        delete_option($lock);
                    }
                }
            }
        }
    }

    public static function menu() {
        add_menu_page('Booking Messages', 'Booking Messages', 'manage_options', 'bokun-messages', array(__CLASS__, 'admin_page'), 'dashicons-email-alt2', 26);
        add_submenu_page('bokun-messages', 'Message Connections', 'Connections', 'manage_options', 'bokun-messages-settings', array(__CLASS__, 'settings_page'));
    }

    public static function admin_page() {
        echo '<div class="wrap"><h1>Booking Messages</h1>' . self::render() . '</div>';
    }

    public static function render() {
        if (!self::can_manage()) {
            return '<p>Booking messages are available to signed-in administrators. <a href="' . esc_url(wp_login_url()) . '">Sign in</a></p>';
        }
        nocache_headers();
        global $bokun_version;
        wp_enqueue_style('bokun-messages', BOKUN_CSS_URL . 'bokun-messages.css', array(), $bokun_version);
        wp_enqueue_script('bokun-messages', BOKUN_JS_URL . 'bokun-messages.js', array(), $bokun_version, true);
        ob_start();
        include __DIR__ . '/bokun-messages.view.php';
        return ob_get_clean();
    }

    public static function settings_page() {
        if (!self::can_manage()) {
            return;
        }
        include __DIR__ . '/bokun-messages-settings.view.php';
    }

    public static function save_settings() {
        if (!self::can_manage()) {
            wp_die('Administrator access required.', '', array('response' => 403));
        }
        check_admin_referer('bokun_messages_settings');
        $old = self::settings();
        $config = array('google_client_id' => sanitize_text_field(wp_unslash($_POST['google_client_id'] ?? '')), 'model' => sanitize_text_field(wp_unslash($_POST['model'] ?? 'gpt-4.1-mini')), 'auto_draft' => isset($_POST['auto_draft']) ? 1 : 0);
        $contexts = function_exists('bokun_get_configured_api_credentials') ? bokun_get_configured_api_credentials() : array();
        if ($old['google_client_id'] && $old['google_client_id'] !== $config['google_client_id']) {
            foreach (array_keys(self::accounts()) as $account) {
                delete_option('bokun_messages_secret_tokens_' . $account);
            }
        }
        foreach (self::accounts() as $account => $details) {
            $context = sanitize_key($_POST[$account . '_context'] ?? '');
            if ($context && !isset($contexts[$context])) {
                wp_die('Unknown Bókun API account.');
            }
            $config[$account . '_context'] = $context;
            if ($context !== $old[$account . '_context']) {
                global $wpdb;
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table('conversations') . " SET booking_snapshot='',booking_checked_at=0,version=version+1 WHERE account=%s", $account));
            }
        }
        foreach (array('google_client_secret', 'openai_key') as $name) {
            $value = trim(wp_unslash($_POST[$name] ?? ''));
            if ($value) {
                $result = self::set_secret($name, $value);
                if (is_wp_error($result)) {
                    wp_die(esc_html($result->get_error_message()));
                }
            }
        }
        update_option('bokun_messages_settings', $config, false);
        wp_safe_redirect(admin_url('admin.php?page=bokun-messages-settings&saved=1'));
        exit;
    }
}

BOKUN_Messages::boot();
