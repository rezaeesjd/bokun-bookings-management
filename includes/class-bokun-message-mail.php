<?php

if (!defined('ABSPATH')) {
    exit;
}

class BOKUN_Message_Mail {
    public static function request($url, $args = array()) {
        $response = wp_remote_request($url, array_merge(array('timeout' => 30, 'redirection' => 0), $args));
        if (is_wp_error($response)) {
            return new WP_Error('mail_network', 'Mail service could not be reached. Check connection status before retrying.');
        }
        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            return new WP_Error('mail_http', 'Mail service returned HTTP ' . (int) $status . '. Reconnect if authorization has expired.');
        }
        return $data;
    }

    public static function token($account) {
        $tokens = BOKUN_Messages::secret('tokens_' . $account);
        $tokens = $tokens ? json_decode($tokens, true) : array();
        if (!is_array($tokens) || empty($tokens['refresh_token'])) {
            return new WP_Error('mail_disconnected', 'Connect this Gmail account in Messages settings first.');
        }
        if (!empty($tokens['access_token']) && ($tokens['expires_at'] ?? 0) > time() + 60) {
            return $tokens['access_token'];
        }
        $config = BOKUN_Messages::settings();
        $response = self::request('https://oauth2.googleapis.com/token', array('method' => 'POST', 'body' => array(
            'client_id' => $config['google_client_id'],
            'client_secret' => BOKUN_Messages::secret('google_client_secret'),
            'refresh_token' => $tokens['refresh_token'],
            'grant_type' => 'refresh_token',
        )));
        if (is_wp_error($response)) {
            return $response;
        }
        if (empty($response['access_token'])) {
            return new WP_Error('mail_token', 'Gmail did not issue an access token. Reconnect this mailbox.');
        }
        $tokens['access_token'] = $response['access_token'];
        $tokens['expires_at'] = time() + (int) ($response['expires_in'] ?? 3600);
        $saved = BOKUN_Messages::set_secret('tokens_' . $account, wp_json_encode($tokens));
        return is_wp_error($saved) ? $saved : $tokens['access_token'];
    }

    public static function api($account, $path, $method = 'GET', $body = null) {
        $token = self::token($account);
        if (is_wp_error($token)) {
            return $token;
        }
        $args = array('method' => $method, 'headers' => array('Authorization' => 'Bearer ' . $token));
        if (null !== $body) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        return self::request('https://gmail.googleapis.com/gmail/v1/users/me/' . $path, $args);
    }

    public static function callback_url() {
        return admin_url('admin-post.php?action=bokun_messages_oauth_callback');
    }

    public static function connect() {
        if (!current_user_can('manage_options')) {
            wp_die('Administrator access required.', '', array('response' => 403));
        }
        check_admin_referer('bokun_messages_connect');
        $account = sanitize_key($_GET['account'] ?? '');
        $accounts = BOKUN_Messages::accounts();
        $config = BOKUN_Messages::settings();
        if (!isset($accounts[$account]) || !$config['google_client_id'] || !BOKUN_Messages::secret('google_client_secret')) {
            wp_die('Save the Google OAuth client ID and secret in Messages settings first.');
        }
        $state = wp_generate_password(48, false, false);
        set_transient('bokun_messages_oauth_' . hash('sha256', $state), array('user' => get_current_user_id(), 'account' => $account), 600);
        $url = add_query_arg(array(
            'client_id' => $config['google_client_id'],
            'redirect_uri' => self::callback_url(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'login_hint' => $accounts[$account]['email'],
            'state' => $state,
        ), 'https://accounts.google.com/o/oauth2/v2/auth');
        wp_redirect($url);
        exit;
    }

    public static function callback() {
        if (!current_user_can('manage_options')) {
            wp_die('Administrator access required.', '', array('response' => 403));
        }
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $name = 'bokun_messages_oauth_' . hash('sha256', $state);
        $pending = get_transient($name);
        delete_transient($name);
        if (!$pending || (int) $pending['user'] !== get_current_user_id() || !empty($_GET['error'])) {
            wp_die('Gmail authorization was declined or expired. Start again from Messages settings.');
        }
        $config = BOKUN_Messages::settings();
        $tokens = self::request('https://oauth2.googleapis.com/token', array('method' => 'POST', 'body' => array(
            'client_id' => $config['google_client_id'],
            'client_secret' => BOKUN_Messages::secret('google_client_secret'),
            'code' => sanitize_text_field(wp_unslash($_GET['code'] ?? '')),
            'redirect_uri' => self::callback_url(),
            'grant_type' => 'authorization_code',
        )));
        if (is_wp_error($tokens) || empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            wp_die('Gmail authorization did not provide offline access. Reconnect and approve the requested scopes.');
        }
        $scopes = explode(' ', $tokens['scope'] ?? '');
        if (!in_array('https://www.googleapis.com/auth/gmail.readonly', $scopes, true) || !in_array('https://www.googleapis.com/auth/gmail.send', $scopes, true)) {
            wp_die('Both reading and sending scopes are required. No mailbox connection was saved.');
        }
        $profile = self::request('https://gmail.googleapis.com/gmail/v1/users/me/profile', array('headers' => array('Authorization' => 'Bearer ' . $tokens['access_token'])));
        $accounts = BOKUN_Messages::accounts();
        $account = $pending['account'];
        if (is_wp_error($profile) || strtolower($profile['emailAddress'] ?? '') !== $accounts[$account]['email']) {
            wp_die('The selected Google account does not match the configured mailbox. No connection was saved.');
        }
        $tokens['expires_at'] = time() + (int) ($tokens['expires_in'] ?? 3600);
        $result = BOKUN_Messages::set_secret('tokens_' . $account, wp_json_encode($tokens));
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        wp_safe_redirect(admin_url('admin.php?page=bokun-messages-settings&connected=1'));
        exit;
    }

    public static function sync($account) {
        $accounts = BOKUN_Messages::accounts();
        if (!isset($accounts[$account])) {
            return new WP_Error('mail_account', 'Unknown business account.');
        }
        $lock = 'bokun_messages_sync_lock_' . $account;
        if (!BOKUN_Messages::lock($lock)) {
            return new WP_Error('mail_busy', 'Mailbox synchronization is already running.');
        }
        try {
            $cursor = get_option('bokun_messages_cursor_' . $account, array());
            if (empty($cursor['query'])) {
                $since = !empty($cursor['completed']) ? max(time() - 90 * DAY_IN_SECONDS, $cursor['completed'] - 300) : time() - 90 * DAY_IN_SECONDS;
                $cursor = array('query' => 'after:' . $since . ' -in:spam -in:trash {from:expmessaging.tripadvisor.com to:expmessaging.tripadvisor.com from:viator.com from:tripadvisor.com}', 'started' => time(), 'page' => '');
            }
            $path = 'messages?' . http_build_query(array('q' => $cursor['query'], 'maxResults' => 15, 'pageToken' => $cursor['page'] ?? ''));
            $listing = self::api($account, $path);
            if (is_wp_error($listing)) {
                return $listing;
            }
            $count = 0;
            $threads = array();
            foreach ($listing['messages'] ?? array() as $entry) {
                $metadata = self::api($account, 'messages/' . rawurlencode($entry['id']) . '?format=metadata');
                if (is_wp_error($metadata)) {
                    return $metadata;
                }
                if (!BOKUN_Message_Parser::candidate($metadata, $accounts[$account]['email'])) {
                    continue;
                }
                $thread_id = $metadata['threadId'];
                if (isset($threads[$thread_id])) {
                    continue;
                }
                $threads[$thread_id] = true;
                $thread = self::api($account, 'threads/' . rawurlencode($thread_id) . '?format=full');
                if (is_wp_error($thread)) {
                    return $thread;
                }
                foreach ($thread['messages'] ?? array() as $message) {
                    $parsed = BOKUN_Message_Parser::parse($message, $accounts[$account]['email']);
                    if ($parsed) {
                        $stored = BOKUN_Messages::ingest($account, $parsed);
                        if (is_wp_error($stored)) {
                            return $stored;
                        }
                        $count += (int) $stored;
                    }
                }
            }
            if (!empty($listing['nextPageToken'])) {
                $cursor['page'] = $listing['nextPageToken'];
            } else {
                $cursor = array('completed' => $cursor['started']);
            }
            update_option('bokun_messages_cursor_' . $account, $cursor, false);
            update_option('bokun_messages_sync_' . $account, array('at' => gmdate('c'), 'message' => 'Imported ' . $count . ' new messages.', 'more' => !empty($cursor['page'])), false);
            return array('imported' => $count, 'more' => !empty($cursor['page']));
        } finally {
            delete_option($lock);
        }
    }
}
