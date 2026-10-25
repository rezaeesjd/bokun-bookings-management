<?php
/**
 * Bokun dashboard access control — predefined-key login + role gating.
 *
 * Replaces "the Bookings view is public, staff surfaces need a WordPress login"
 * with a self-contained key login that hides the whole dashboard from the
 * public. Three predefined users each hold a simple key:
 *
 *   - admin   (Bardi)  — everything, including Analytics and settings.
 *   - booking (Johnna) — everything except the Analytics tab; full booking +
 *                        messaging write access.
 *   - generic (SJD)    — read-only Bookings view (what used to be public).
 *
 * Identity is established by a signed, HttpOnly cookie set when a valid key is
 * entered, and resolved on every request. A logged-in WordPress administrator
 * (manage_options / edit_others_posts) always resolves to the admin role as a
 * lockout failsafe, independent of the keys.
 *
 * The keys are deliberately simple — they only say "who is using the dashboard"
 * — but because the dashboard (and the AJAX/REST nonces it emits) now render
 * only for a resolved actor, the existing WordPress nonces keep protecting the
 * write endpoints from CSRF: a public visitor never receives a nonce.
 */

if (!defined('ABSPATH') && !defined('BOKUN_ACCESS_TEST')) {
    // Allow inclusion from the test harness (which defines BOKUN_ACCESS_TEST);
    // otherwise require WordPress.
    exit;
}

/** Option holding the key/role configuration. */
if (!defined('BOKUN_ACCESS_OPTION')) {
    define('BOKUN_ACCESS_OPTION', 'bokun_access_users');
}
/** Session cookie name. */
if (!defined('BOKUN_ACCESS_COOKIE')) {
    define('BOKUN_ACCESS_COOKIE', 'bokun_access');
}
/** Session lifetime in seconds (12 hours). */
if (!defined('BOKUN_ACCESS_LIFETIME')) {
    define('BOKUN_ACCESS_LIFETIME', 12 * HOUR_IN_SECONDS);
}

/**
 * The capabilities granted to each role.
 *
 * @param string $role
 * @return string[]
 */
function bokun_access_role_caps($role) {
    $map = array(
        'generic' => array('view_bookings'),
        'booking' => array('view_bookings', 'manage_bookings', 'view_messages', 'manage_messages'),
        'admin'   => array('view_bookings', 'manage_bookings', 'view_messages', 'manage_messages', 'view_analytics', 'manage_settings'),
    );
    return isset($map[$role]) ? $map[$role] : array();
}

/** Human labels for the roles (admin settings UI + account bar). */
function bokun_access_role_labels() {
    return array(
        'admin'   => __('Admin — full access', 'BOKUN_txt_domain'),
        'booking' => __('Booking manager — everything except Analytics', 'BOKUN_txt_domain'),
        'generic' => __('Generic — read-only Bookings', 'BOKUN_txt_domain'),
    );
}

/**
 * The seeded default users. Generated once; rotate/rename them from the
 * "Dashboard Access" admin screen (they are stored in an option afterwards).
 *
 * @return array<string,array{name:string,role:string,key:string}>
 */
function bokun_access_default_users() {
    return array(
        'bardi'  => array('name' => 'Bardi',  'role' => 'admin',   'key' => 'bardi-ad-4f9c2a7e'),
        'johnna' => array('name' => 'Johnna', 'role' => 'booking', 'key' => 'johnna-bk-8b1d6395'),
        'sjd'    => array('name' => 'SJD',     'role' => 'generic', 'key' => 'sjd-gn-2e7a4c10'),
    );
}

/**
 * The configured users, seeding the defaults on first use.
 *
 * @return array<string,array{name:string,role:string,key:string}>
 */
function bokun_access_users() {
    $stored = get_option(BOKUN_ACCESS_OPTION, null);
    if (!is_array($stored) || empty($stored)) {
        $stored = bokun_access_default_users();
        update_option(BOKUN_ACCESS_OPTION, $stored, false);
    }
    $clean = array();
    foreach ($stored as $id => $user) {
        $id = sanitize_key($id);
        if ('' === $id || !is_array($user)) {
            continue;
        }
        $role = isset($user['role']) ? (string) $user['role'] : 'generic';
        if (!array_key_exists($role, bokun_access_role_labels())) {
            $role = 'generic';
        }
        $clean[$id] = array(
            'name' => isset($user['name']) ? (string) $user['name'] : $id,
            'role' => $role,
            'key'  => isset($user['key']) ? (string) $user['key'] : '',
        );
    }
    return $clean;
}

/**
 * Sign a session for a user. The key's hash is folded into the signature so
 * rotating a key invalidates every existing session for that user.
 */
function bokun_access_sign($id, $expires, $key) {
    return hash_hmac('sha256', $id . '|' . $expires . '|' . hash('sha256', $key), wp_salt('auth'));
}

/**
 * Resolve the current actor, or null for the public (no access).
 *
 * @return array{id:string,name:string,role:string,via:string}|null
 */
function bokun_access_flush() {
    unset($GLOBALS['bokun_access_resolved'], $GLOBALS['bokun_access_actor']);
}

function bokun_access_current() {
    if (!empty($GLOBALS['bokun_access_resolved'])) {
        return isset($GLOBALS['bokun_access_actor']) ? $GLOBALS['bokun_access_actor'] : null;
    }
    $GLOBALS['bokun_access_resolved'] = true;
    $GLOBALS['bokun_access_actor'] = null;

    // Lockout failsafe: a logged-in WordPress administrator is always admin,
    // regardless of any key cookie. (Only manage_options — other WordPress
    // roles are key-driven like everyone else.)
    if (is_user_logged_in() && current_user_can('manage_options')) {
        $wp_user = wp_get_current_user();
        $name = ($wp_user instanceof WP_User && $wp_user->exists())
            ? ($wp_user->display_name ?: $wp_user->user_login)
            : __('WordPress admin', 'BOKUN_txt_domain');
        $GLOBALS['bokun_access_actor'] = array('id' => 'wp:' . get_current_user_id(), 'name' => $name, 'role' => 'admin', 'via' => 'wp');
        return $GLOBALS['bokun_access_actor'];
    }

    $GLOBALS['bokun_access_actor'] = bokun_access_actor_from_cookie();
    return $GLOBALS['bokun_access_actor'];
}

/**
 * Parse and verify the session cookie into an actor.
 *
 * @return array{id:string,name:string,role:string,via:string}|null
 */
function bokun_access_actor_from_cookie() {
    if (empty($_COOKIE[BOKUN_ACCESS_COOKIE])) {
        return null;
    }
    $raw = (string) wp_unslash($_COOKIE[BOKUN_ACCESS_COOKIE]);
    $parts = explode('.', $raw);
    if (count($parts) !== 3) {
        return null;
    }
    list($id, $expires, $sig) = $parts;
    $id = sanitize_key($id);
    $expires = (int) $expires;
    if ('' === $id || $expires < time()) {
        return null;
    }
    $users = bokun_access_users();
    if (!isset($users[$id]) || '' === $users[$id]['key']) {
        return null;
    }
    $expected = bokun_access_sign($id, $expires, $users[$id]['key']);
    if (!hash_equals($expected, (string) $sig)) {
        return null;
    }
    return array('id' => $id, 'name' => $users[$id]['name'], 'role' => $users[$id]['role'], 'via' => 'key');
}

/** The current actor's role, or '' for the public. */
function bokun_access_role() {
    $actor = bokun_access_current();
    return $actor ? $actor['role'] : '';
}

/**
 * Whether the current actor holds a capability.
 *
 * @param string $cap One of view_bookings, manage_bookings, view_messages,
 *                    manage_messages, view_analytics, manage_settings.
 */
function bokun_access_can($cap) {
    $actor = bokun_access_current();
    if (!$actor) {
        return false;
    }
    return in_array($cap, bokun_access_role_caps($actor['role']), true);
}

/** Best-effort client IP for throttling. */
function bokun_access_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return $ip;
}

/**
 * Match a submitted key against the configured users in constant time
 * (every candidate is compared so timing does not leak which id matched).
 *
 * @return string The matched user id, or '' on no match.
 */
function bokun_access_match_key($key) {
    $key = (string) $key;
    $match = '';
    foreach (bokun_access_users() as $id => $user) {
        if ('' === $user['key']) {
            continue;
        }
        if (hash_equals($user['key'], $key)) {
            $match = $id;
        }
    }
    return $match;
}

/** Set the signed session cookie for a user id. */
function bokun_access_set_cookie($id) {
    $users = bokun_access_users();
    if (!isset($users[$id])) {
        return;
    }
    $expires = time() + BOKUN_ACCESS_LIFETIME;
    $value = $id . '.' . $expires . '.' . bokun_access_sign($id, $expires, $users[$id]['key']);
    $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
    if (!headers_sent()) {
        setcookie(BOKUN_ACCESS_COOKIE, $value, array(
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
    $_COOKIE[BOKUN_ACCESS_COOKIE] = $value;
    bokun_access_flush();
}

/** Clear the session cookie. */
function bokun_access_clear_cookie() {
    $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
    if (!headers_sent()) {
        setcookie(BOKUN_ACCESS_COOKIE, '', array(
            'expires'  => time() - 3600,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
    unset($_COOKIE[BOKUN_ACCESS_COOKIE]);
    bokun_access_flush();
}

/** The current front-end URL, used for the post/redirect/get after login. */
function bokun_access_current_url() {
    return home_url(add_query_arg(array()));
}

/**
 * Process a key login or logout submission early in the request, before any
 * output, then redirect (PRG) so the new cookie is read on the next load.
 */
function bokun_access_handle_auth() {
    if (empty($_POST['bokun_access_action'])) {
        return;
    }
    $action = sanitize_key(wp_unslash($_POST['bokun_access_action']));
    $base = remove_query_arg(array('bokun_login'), bokun_access_current_url());

    if ('logout' === $action) {
        bokun_access_clear_cookie();
        wp_safe_redirect($base);
        exit;
    }

    if ('login' === $action) {
        $ip = bokun_access_client_ip();
        $throttle_key = 'bokun_access_fails_' . md5($ip);
        $fails = (int) get_transient($throttle_key);
        if ($fails >= 12) {
            wp_safe_redirect(add_query_arg('bokun_login', 'throttled', $base));
            exit;
        }
        $key = isset($_POST['bokun_access_key']) ? trim((string) wp_unslash($_POST['bokun_access_key'])) : '';
        $id = bokun_access_match_key($key);
        if ('' !== $id) {
            delete_transient($throttle_key);
            bokun_access_set_cookie($id);
            wp_safe_redirect($base);
            exit;
        }
        set_transient($throttle_key, $fails + 1, 15 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('bokun_login', 'failed', $base));
        exit;
    }
}
add_action('init', 'bokun_access_handle_auth', 1);

/**
 * The login screen shown in place of the dashboard for the public.
 *
 * @return string
 */
function bokun_access_login_form() {
    if (function_exists('nocache_headers') && !headers_sent()) {
        nocache_headers();
    }
    global $bokun_version;
    if (defined('BOKUN_CSS_URL') && function_exists('wp_enqueue_style')) {
        wp_enqueue_style('bokun-design-system', BOKUN_CSS_URL . 'bokun-design-system.css', array(), $bokun_version);
    }
    $status = isset($_GET['bokun_login']) ? sanitize_key(wp_unslash($_GET['bokun_login'])) : '';
    $message = '';
    if ('failed' === $status) {
        $message = __('That key was not recognized. Check it and try again.', 'BOKUN_txt_domain');
    } elseif ('throttled' === $status) {
        $message = __('Too many attempts. Please wait a few minutes and try again.', 'BOKUN_txt_domain');
    }
    $action = esc_url(remove_query_arg(array('bokun_login'), bokun_access_current_url()));

    ob_start();
    ?>
    <div class="bokun-access-login">
        <form class="bokun-access-login__card" method="post" action="<?php echo $action; ?>" autocomplete="off">
            <h2 class="bokun-access-login__title"><?php esc_html_e('Dashboard sign in', 'BOKUN_txt_domain'); ?></h2>
            <p class="bokun-access-login__hint"><?php esc_html_e('Enter your access key to continue.', 'BOKUN_txt_domain'); ?></p>
            <?php if ('' !== $message) : ?>
                <p class="bokun-access-login__error" role="alert"><?php echo esc_html($message); ?></p>
            <?php endif; ?>
            <input type="hidden" name="bokun_access_action" value="login">
            <label class="bokun-access-login__label" for="bokun-access-key"><?php esc_html_e('Access key', 'BOKUN_txt_domain'); ?></label>
            <input class="bokun-access-login__input" type="password" id="bokun-access-key" name="bokun_access_key" required autofocus spellcheck="false" autocomplete="off">
            <button class="bokun-access-login__submit" type="submit"><?php esc_html_e('Sign in', 'BOKUN_txt_domain'); ?></button>
        </form>
    </div>
    <style>
        .bokun-access-login{font-family:var(--bk-font,system-ui,sans-serif);display:flex;justify-content:center;padding:48px 16px;color:var(--bk-text,#0f172a);}
        .bokun-access-login__card{width:100%;max-width:380px;background:var(--bk-surface,#fff);border:1px solid var(--bk-border,#e2e8f0);border-radius:var(--bk-radius-lg,.875rem);box-shadow:var(--bk-shadow-md,0 4px 12px rgba(15,23,42,.08));padding:28px;}
        .bokun-access-login__title{margin:0 0 6px;font-size:1.375rem;font-weight:700;}
        .bokun-access-login__hint{margin:0 0 18px;color:var(--bk-text-muted,#64748b);font-size:.9375rem;}
        .bokun-access-login__error{margin:0 0 16px;padding:10px 12px;border-radius:var(--bk-radius-sm,.375rem);background:var(--bk-danger-bg,#fee2e2);color:var(--bk-danger,#b91c1c);font-size:.875rem;}
        .bokun-access-login__label{display:block;margin:0 0 6px;font-size:.8125rem;font-weight:600;color:var(--bk-text-muted,#64748b);}
        .bokun-access-login__input{width:100%;box-sizing:border-box;padding:11px 13px;font-size:1rem;border:1px solid var(--bk-border-strong,#cbd5e1);border-radius:var(--bk-radius-md,.625rem);background:#fff;color:inherit;margin-bottom:18px;}
        .bokun-access-login__input:focus{outline:none;border-color:var(--bk-brand-500,#9a2947);box-shadow:var(--bk-focus-ring,0 0 0 3px #ecc9d2);}
        .bokun-access-login__submit{width:100%;padding:11px 16px;font-size:1rem;font-weight:600;color:var(--bk-text-on-brand,#fff);background:var(--bk-brand-600,#7b1e3a);border:1px solid var(--bk-brand-600,#7b1e3a);border-radius:var(--bk-radius-pill,999px);cursor:pointer;transition:background .18s ease;}
        .bokun-access-login__submit:hover{background:var(--bk-brand-700,#5f152c);}
    </style>
    <?php
    return ob_get_clean();
}

/**
 * The small "signed in as …" bar shown above the dashboard. Key sessions get a
 * Sign out button; WordPress-admin failsafe sessions get a quiet note.
 *
 * @return string
 */
function bokun_access_account_bar() {
    $actor = bokun_access_current();
    if (!$actor) {
        return '';
    }
    $labels = bokun_access_role_labels();
    $role_label = isset($labels[$actor['role']]) ? $labels[$actor['role']] : $actor['role'];
    $action = esc_url(remove_query_arg(array('bokun_login'), bokun_access_current_url()));

    ob_start();
    ?>
    <div class="bokun-access-bar">
        <span class="bokun-access-bar__who">
            <?php
            /* translators: 1: user name, 2: role label. */
            echo esc_html(sprintf(__('Signed in as %1$s · %2$s', 'BOKUN_txt_domain'), $actor['name'], $role_label));
            ?>
        </span>
        <?php if ('key' === $actor['via']) : ?>
            <form class="bokun-access-bar__out" method="post" action="<?php echo $action; ?>">
                <input type="hidden" name="bokun_access_action" value="logout">
                <button type="submit" class="bokun-access-bar__button"><?php esc_html_e('Sign out', 'BOKUN_txt_domain'); ?></button>
            </form>
        <?php else : ?>
            <span class="bokun-access-bar__note"><?php esc_html_e('WordPress session', 'BOKUN_txt_domain'); ?></span>
        <?php endif; ?>
    </div>
    <style>
        .bokun-access-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
            margin:0 0 14px;padding:8px 14px;font-family:var(--bk-font,system-ui,sans-serif);font-size:.8125rem;
            background:var(--bk-surface-sunken,#f8fafc);border:1px solid var(--bk-border,#e2e8f0);border-radius:var(--bk-radius-pill,999px);}
        .bokun-access-bar__who{color:var(--bk-text,#0f172a);font-weight:600;}
        .bokun-access-bar__note{color:var(--bk-text-muted,#64748b);}
        .bokun-access-bar__out{margin:0;}
        .bokun-access-bar__button{appearance:none;cursor:pointer;font:inherit;font-weight:600;
            padding:5px 14px;color:var(--bk-brand-600,#7b1e3a);background:transparent;
            border:1px solid var(--bk-border-strong,#cbd5e1);border-radius:var(--bk-radius-pill,999px);transition:background .18s ease,border-color .18s ease;}
        .bokun-access-bar__button:hover{background:var(--bk-brand-50,#fbf3f5);border-color:var(--bk-brand-500,#9a2947);}
    </style>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Admin settings screen: view/rotate keys and reassign roles.
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'bokun_access_admin_menu');
function bokun_access_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=bokun_booking',
        __('Dashboard Access', 'BOKUN_txt_domain'),
        __('Dashboard Access', 'BOKUN_txt_domain'),
        'manage_options',
        'bokun-access',
        'bokun_access_admin_page'
    );
}

add_action('admin_post_bokun_access_save', 'bokun_access_save');
function bokun_access_save() {
    if (!current_user_can('manage_options')) {
        wp_die('Administrator access required.', '', array('response' => 403));
    }
    check_admin_referer('bokun_access_save');

    $labels = bokun_access_role_labels();
    $ids = isset($_POST['user_id']) && is_array($_POST['user_id']) ? wp_unslash($_POST['user_id']) : array();
    $names = isset($_POST['user_name']) && is_array($_POST['user_name']) ? wp_unslash($_POST['user_name']) : array();
    $roles = isset($_POST['user_role']) && is_array($_POST['user_role']) ? wp_unslash($_POST['user_role']) : array();
    $keys = isset($_POST['user_key']) && is_array($_POST['user_key']) ? wp_unslash($_POST['user_key']) : array();
    $regen = isset($_POST['user_regen']) && is_array($_POST['user_regen']) ? array_map('sanitize_key', wp_unslash($_POST['user_regen'])) : array();

    $existing = bokun_access_users();
    $config = array();
    foreach ($ids as $i => $raw_id) {
        $id = sanitize_key($raw_id);
        if ('' === $id) {
            continue;
        }
        $role = isset($roles[$i]) ? sanitize_key($roles[$i]) : 'generic';
        if (!array_key_exists($role, $labels)) {
            $role = 'generic';
        }
        $key = isset($keys[$i]) ? trim(sanitize_text_field($keys[$i])) : '';
        if (isset($regen[$id]) || '' === $key) {
            // Regenerate: short, human-typable, reasonably unguessable.
            $key = $id . '-' . substr(bin2hex(random_bytes(6)), 0, 10);
        }
        $config[$id] = array(
            'name' => isset($names[$i]) ? sanitize_text_field($names[$i]) : $id,
            'role' => $role,
            'key'  => $key,
        );
    }
    if (!empty($config)) {
        update_option(BOKUN_ACCESS_OPTION, $config, false);
    }
    wp_safe_redirect(add_query_arg(array('post_type' => 'bokun_booking', 'page' => 'bokun-access', 'updated' => '1'), admin_url('edit.php')));
    exit;
}

function bokun_access_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $users = bokun_access_users();
    $labels = bokun_access_role_labels();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Dashboard Access', 'BOKUN_txt_domain'); ?></h1>
        <?php if (isset($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Access keys saved.', 'BOKUN_txt_domain'); ?></p></div>
        <?php endif; ?>
        <p><?php esc_html_e('Each person signs in to the booking dashboard with their key. Keys are shown here so you can share and rotate them. A logged-in WordPress administrator always has full admin access regardless of keys.', 'BOKUN_txt_domain'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="bokun_access_save">
            <?php wp_nonce_field('bokun_access_save'); ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'BOKUN_txt_domain'); ?></th>
                        <th><?php esc_html_e('Name', 'BOKUN_txt_domain'); ?></th>
                        <th><?php esc_html_e('Role', 'BOKUN_txt_domain'); ?></th>
                        <th><?php esc_html_e('Key', 'BOKUN_txt_domain'); ?></th>
                        <th><?php esc_html_e('Regenerate', 'BOKUN_txt_domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $id => $user) : ?>
                        <tr>
                            <td>
                                <code><?php echo esc_html($id); ?></code>
                                <input type="hidden" name="user_id[]" value="<?php echo esc_attr($id); ?>">
                            </td>
                            <td><input type="text" name="user_name[]" value="<?php echo esc_attr($user['name']); ?>" class="regular-text"></td>
                            <td>
                                <select name="user_role[]">
                                    <?php foreach ($labels as $role => $label) : ?>
                                        <option value="<?php echo esc_attr($role); ?>" <?php selected($user['role'], $role); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="user_key[]" value="<?php echo esc_attr($user['key']); ?>" class="regular-text" spellcheck="false"></td>
                            <td style="text-align:center;"><input type="checkbox" name="user_regen[<?php echo esc_attr($id); ?>]" value="1"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="max-width:900px;"><?php esc_html_e('Tick "Regenerate" (or clear a key) to issue a fresh key on save. Rotating a key signs that person out of any active session.', 'BOKUN_txt_domain'); ?></p>
            <?php submit_button(__('Save access keys', 'BOKUN_txt_domain')); ?>
        </form>
    </div>
    <?php
}
