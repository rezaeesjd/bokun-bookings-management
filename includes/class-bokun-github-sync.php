<?php
/**
 * Bokun Bookings Management – GitHub auto-sync.
 *
 * Merges the functionality of the standalone "Github Plugin Installer and
 * Updater" helper plugin directly into Bokun Bookings Management. It uses the
 * native WordPress plugin-update pipeline to keep the installed plugin in sync
 * with a GitHub branch.
 *
 * Change detection is based on the latest commit SHA of the tracked branch
 * (not just the plugin header version), so ANY change pushed to the branch is
 * reflected on the installed site. Auto-sync (WordPress background
 * auto-updates for this plugin) is enabled by default and can be toggled from
 * the settings screen.
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Bokun_Github_Sync' ) ) {

    /**
     * Handles installing/updating the plugin from GitHub and background sync.
     */
    class Bokun_Github_Sync {

        /**
         * Option key that stores sync settings.
         */
        const OPTION_KEY = 'bokun_github_sync_settings';

        /**
         * Option key that stores the currently installed commit SHA.
         */
        const INSTALLED_SHA_KEY = 'bokun_github_sync_installed_sha';

        /**
         * Option key that stores the commit SHA of an update currently being
         * installed, so it can be promoted to the installed SHA once the
         * upgrade finishes (recording the exact tree that was installed rather
         * than re-querying the branch HEAD afterwards).
         */
        const PENDING_SHA_KEY = 'bokun_github_sync_pending_sha';

        /**
         * Option key that stores the webhook shared secret.
         */
        const WEBHOOK_SECRET_KEY = 'bokun_github_sync_webhook_secret';

        /**
         * Transient key used to throttle the admin-load sync check.
         */
        const ADMIN_THROTTLE_KEY = 'bokun_github_sync_admin_throttle';

        /**
         * REST namespace and route for the GitHub push webhook.
         */
        const REST_NAMESPACE = 'bokun-github-sync/v1';
        const REST_ROUTE     = '/webhook';

        /**
         * Admin page slug (submenu under the Bokun Bookings menu).
         */
        const ADMIN_SLUG = 'bokun-github-sync';

        /**
         * Parent menu the settings page lives under (the Bokun Bookings CPT
         * menu). The main plugin registers the submenu in the correct order;
         * this constant is used to build links back to the page.
         */
        const ADMIN_PARENT = 'edit.php?post_type=bokun_booking';

        /**
         * Transient key holding a short-lived admin notice.
         */
        const NOTICE_KEY = 'bokun_github_sync_notice';

        /**
         * WP-Cron hook that triggers a background sync check.
         */
        const CRON_HOOK = 'bokun_github_sync_check';

        /**
         * Default repository the plugin ships with.
         */
        const DEFAULT_REPO = 'https://github.com/rezaeesjd/bokun-bookings-management';

        /**
         * Absolute path to the main plugin file.
         *
         * @var string
         */
        private $plugin_file;

        /**
         * Plugin basename, e.g. bokun-bookings-management/bokun-bookings-management.php.
         *
         * @var string
         */
        private $plugin_basename;

        /**
         * Plugin folder slug, e.g. bokun-bookings-management.
         *
         * @var string
         */
        private $plugin_slug;

        /**
         * Cached settings.
         *
         * @var array
         */
        private $settings = array();

        /**
         * Constructor.
         *
         * @param string $plugin_file Absolute path to the main plugin file.
         */
        public function __construct( $plugin_file ) {
            $this->plugin_file     = $plugin_file;
            $this->plugin_basename = plugin_basename( $plugin_file );
            $this->plugin_slug     = dirname( $this->plugin_basename );

            if ( '.' === $this->plugin_slug || '' === $this->plugin_slug ) {
                $this->plugin_slug = 'bokun-bookings-management';
            }

            $this->settings = $this->get_settings();

            // Native WordPress update pipeline.
            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
            add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
            add_filter( 'http_request_args', array( $this, 'authorize_github_download' ), 10, 2 );
            add_filter( 'upgrader_source_selection', array( $this, 'rename_source_folder' ), 10, 4 );
            add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );

            // Enable WordPress auto-updates for this plugin when auto-sync is on.
            add_filter( 'auto_update_plugin', array( $this, 'enable_auto_update' ), 10, 2 );

            // Background sync via WP-Cron.
            add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
            add_action( self::CRON_HOOK, array( $this, 'run_scheduled_sync' ) );

            // Self-heal the schedule for sites updated in place (where the
            // activation hook does not fire again).
            add_action( 'admin_init', array( $this, 'maybe_schedule_cron' ) );

            // Event-driven trigger: a GitHub push webhook installs changes the
            // instant they land on the tracked branch.
            add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );

            // Near-real-time fallback: when an admin loads wp-admin, check for a
            // new commit (throttled) so changes appear without waiting for the
            // hourly cron or a webhook.
            add_action( 'admin_init', array( $this, 'maybe_admin_sync' ) );

            // Admin UI. The settings page is registered by the main plugin as a
            // submenu under the Bokun Bookings menu (ordered between Settings and
            // Booking History) and routed to render_settings_page(); we only
            // register the setting/handlers here.
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_post_bokun_github_sync_now', array( $this, 'handle_sync_now' ) );
            add_filter(
                'plugin_action_links_' . $this->plugin_basename,
                array( $this, 'add_action_links' )
            );
        }

        /**
         * Activation routine – schedule cron and enable auto-updates.
         */
        public function activate() {
            $this->maybe_schedule_cron();

            $settings = $this->get_settings();

            if ( ! empty( $settings['autosync'] ) ) {
                $this->set_wp_auto_update( true );
            }

            // Ensure a webhook secret exists so the payload URL can be wired up
            // in GitHub immediately.
            $this->get_webhook_secret();

            // Intentionally do NOT seed the installed SHA from the remote HEAD
            // here. The shipped files do not carry their own commit reference,
            // so adopting the current branch tip would mask any commits that
            // are newer than the files actually on disk (for example when this
            // module arrives via an in-place update whose activation hook never
            // runs). Leaving it unset makes the first check perform an initial
            // sync, after which after_update() records the exact installed SHA.
        }

        /**
         * Deactivation routine – clear scheduled cron.
         */
        public function deactivate() {
            $timestamp = wp_next_scheduled( self::CRON_HOOK );

            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, self::CRON_HOOK );
            }
        }

        /**
         * Ensure the sync cron event is scheduled.
         */
        public function maybe_schedule_cron() {
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time() + MINUTE_IN_SECONDS, 'bokun_github_sync_hourly', self::CRON_HOOK );
            }
        }

        /**
         * Register an hourly-ish cron interval used for sync checks.
         *
         * @param array $schedules Existing schedules.
         *
         * @return array
         */
        public function register_cron_schedule( $schedules ) {
            if ( ! isset( $schedules['bokun_github_sync_hourly'] ) ) {
                $schedules['bokun_github_sync_hourly'] = array(
                    'interval' => HOUR_IN_SECONDS,
                    'display'  => __( 'Every hour (Bokun GitHub sync)', 'BOKUN_text_domain' ),
                );
            }

            return $schedules;
        }

        /**
         * Cron callback: refresh the update transient so WordPress picks up
         * (and, when auto-sync is on, installs) any new commit on the branch.
         */
        public function run_scheduled_sync() {
            $settings = $this->get_settings();

            if ( empty( $settings['autosync'] ) ) {
                return;
            }

            // Bust our cached remote lookup so we compare against fresh data.
            $this->clear_remote_cache();

            if ( ! function_exists( 'wp_update_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/update.php';
            }

            // Re-run the WordPress update check; our inject_update() filter runs
            // as part of this and flags the plugin when the branch has moved.
            wp_update_plugins();

            // Kick the automatic updater so the download happens in the
            // background rather than waiting for the next wp-cron pass.
            if ( ! empty( $settings['autosync'] ) ) {
                $this->run_auto_updater();
            }
        }

        /**
         * Trigger the WordPress automatic updater for this plugin.
         */
        private function run_auto_updater() {
            if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }

            if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
                return;
            }

            $updater = new WP_Automatic_Updater();

            if ( $updater->is_disabled() ) {
                return;
            }

            $transient = get_site_transient( 'update_plugins' );

            if ( ! is_object( $transient ) || empty( $transient->response[ $this->plugin_basename ] ) ) {
                return;
            }

            $item = $transient->response[ $this->plugin_basename ];

            if ( ! $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR ) ) {
                return;
            }

            $updater->update( 'plugin', $item );
        }

        /**
         * Inject an available update into the plugins update transient.
         *
         * @param mixed $transient Update transient (stdClass or false).
         *
         * @return mixed
         */
        public function inject_update( $transient ) {
            if ( ! is_object( $transient ) ) {
                $transient = new stdClass();
            }

            $settings = $this->get_settings();

            if ( empty( $settings['repository_url'] ) ) {
                return $transient;
            }

            $remote = $this->get_remote_info();

            if ( is_wp_error( $remote ) || empty( $remote['sha'] ) ) {
                return $transient;
            }

            $installed_sha = (string) get_option( self::INSTALLED_SHA_KEY, '' );

            $current_version = $this->get_installed_version();

            if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
                $transient->checked = array();
            }

            $transient->checked[ $this->plugin_basename ] = $current_version;

            // An unknown installed SHA means we have never recorded what is on
            // disk, so force an initial sync rather than assuming the remote
            // HEAD is already installed.
            $has_change = ( '' === $installed_sha ) || ( $remote['sha'] !== $installed_sha );

            if ( $has_change ) {
                // Remember the exact SHA whose archive we are about to offer so
                // after_update() can record it verbatim.
                $this->set_pending_sha( $remote['sha'] );

                $update = (object) array(
                    'slug'         => $this->plugin_slug,
                    'plugin'       => $this->plugin_basename,
                    // Present a distinct, monotonically-newer version string so
                    // the WordPress updates UI shows the change even when the
                    // header version did not change between commits.
                    'new_version'  => $this->build_display_version( $remote ),
                    'package'      => $remote['package'],
                    'url'          => $remote['homepage'],
                    'tested'       => $remote['tested'],
                    'requires'     => $remote['requires'],
                    'requires_php' => $remote['requires_php'],
                );

                $transient->response[ $this->plugin_basename ] = $update;

                if ( isset( $transient->no_update[ $this->plugin_basename ] ) ) {
                    unset( $transient->no_update[ $this->plugin_basename ] );
                }
            } else {
                if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
                    unset( $transient->response[ $this->plugin_basename ] );
                }

                if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                    $transient->no_update = array();
                }

                $transient->no_update[ $this->plugin_basename ] = (object) array(
                    'slug'        => $this->plugin_slug,
                    'plugin'      => $this->plugin_basename,
                    'new_version' => $current_version,
                    'package'     => '',
                    'url'         => $remote['homepage'],
                );
            }

            return $transient;
        }

        /**
         * Provide plugin information for the "View details" modal.
         *
         * @param false|object|array $result Existing result.
         * @param string             $action Requested action.
         * @param object             $args   Arguments.
         *
         * @return false|object|array
         */
        public function plugin_information( $result, $action, $args ) {
            if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {
                return $result;
            }

            $settings = $this->get_settings();

            if ( empty( $settings['repository_url'] ) ) {
                return $result;
            }

            $remote = $this->get_remote_info();

            if ( is_wp_error( $remote ) ) {
                return $result;
            }

            $plugin_data = $this->get_installed_plugin_data();

            return (object) array(
                'name'          => ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : 'Bokun Bookings Management',
                'slug'          => $this->plugin_slug,
                'version'       => $this->build_display_version( $remote ),
                'author'        => ! empty( $plugin_data['Author'] ) ? $plugin_data['Author'] : '',
                'homepage'      => $remote['homepage'],
                'download_link' => $remote['package'],
                'requires'      => $remote['requires'],
                'requires_php'  => $remote['requires_php'],
                'tested'        => $remote['tested'],
                'sections'      => array(
                    'description' => wp_kses_post(
                        __( 'Bokun Bookings Management is kept in sync with its GitHub repository. This build tracks the latest commit on the configured branch.', 'BOKUN_text_domain' )
                    ),
                    'changelog'   => wp_kses_post(
                        sprintf(
                            /* translators: %s: short commit SHA. */
                            __( 'Synced to commit %s from GitHub.', 'BOKUN_text_domain' ),
                            substr( $remote['sha'], 0, 7 )
                        )
                    ),
                ),
            );
        }

        /**
         * Add the GitHub Authorization header to GitHub API/download requests.
         *
         * @param array  $args Request arguments.
         * @param string $url  Request URL.
         *
         * @return array
         */
        public function authorize_github_download( $args, $url ) {
            $token = $this->get_token();

            if ( empty( $token ) ) {
                return $args;
            }

            if ( false === stripos( $url, 'api.github.com/repos' ) && false === stripos( $url, 'raw.githubusercontent.com' ) && false === stripos( $url, 'codeload.github.com' ) ) {
                return $args;
            }

            if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
                $args['headers'] = array();
            }

            if ( empty( $args['headers']['Authorization'] ) ) {
                $args['headers']['Authorization'] = 'Bearer ' . $token;
            }

            if ( empty( $args['headers']['User-Agent'] ) ) {
                $args['headers']['User-Agent'] = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();
            }

            return $args;
        }

        /**
         * Rename GitHub's "owner-repo-sha" extraction folder to the plugin slug.
         *
         * GitHub zipballs extract to a directory named after the repo and
         * commit, so without this WordPress would install into the wrong folder.
         *
         * @param string      $source        Path to the extracted source.
         * @param string      $remote_source Path to the download root.
         * @param WP_Upgrader $upgrader      Upgrader instance.
         * @param array       $hook_extra    Extra data about the upgrade.
         *
         * @return string|WP_Error
         */
        public function rename_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
            if ( empty( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
                return $source;
            }

            global $wp_filesystem;

            if ( ! $wp_filesystem ) {
                return $source;
            }

            $desired = trailingslashit( $remote_source ) . $this->plugin_slug;

            $source        = untrailingslashit( $source );
            $desired_plain = untrailingslashit( $desired );

            if ( $source === $desired_plain ) {
                return trailingslashit( $source );
            }

            if ( $wp_filesystem->exists( $desired_plain ) ) {
                $wp_filesystem->delete( $desired_plain, true );
            }

            if ( $wp_filesystem->move( $source, $desired_plain, true ) ) {
                return trailingslashit( $desired_plain );
            }

            return $source;
        }

        /**
         * After a successful update, record the newly-installed commit SHA.
         *
         * @param WP_Upgrader $upgrader Upgrader instance.
         * @param array       $data     Update data.
         */
        public function after_update( $upgrader, $data ) {
            if ( empty( $data['type'] ) || 'plugin' !== $data['type'] ) {
                return;
            }

            $plugins = array();

            if ( ! empty( $data['plugins'] ) && is_array( $data['plugins'] ) ) {
                $plugins = $data['plugins'];
            } elseif ( ! empty( $data['plugin'] ) ) {
                $plugins = array( $data['plugin'] );
            }

            if ( ! in_array( $this->plugin_basename, $plugins, true ) ) {
                return;
            }

            // Promote the SHA whose archive we actually installed. Re-querying
            // the branch HEAD here would risk recording a commit pushed after
            // the archive was resolved, marking it installed even though its
            // files never landed.
            $pending = (string) get_option( self::PENDING_SHA_KEY, '' );

            if ( '' !== $pending ) {
                update_option( self::INSTALLED_SHA_KEY, $pending );
                delete_option( self::PENDING_SHA_KEY );
            }

            $this->clear_remote_cache();
        }

        /**
         * Enable WordPress background auto-updates for this plugin.
         *
         * @param bool|null $update Whether to auto-update.
         * @param object    $item   Update item.
         *
         * @return bool|null
         */
        public function enable_auto_update( $update, $item ) {
            $plugin = '';

            if ( is_object( $item ) && isset( $item->plugin ) ) {
                $plugin = $item->plugin;
            } elseif ( is_array( $item ) && isset( $item['plugin'] ) ) {
                $plugin = $item['plugin'];
            }

            if ( $plugin !== $this->plugin_basename ) {
                return $update;
            }

            $settings = $this->get_settings();

            return ! empty( $settings['autosync'] );
        }

        /* ---------------------------------------------------------------------
         * Event-driven triggers (webhook + admin-load check)
         * ------------------------------------------------------------------ */

        /**
         * Register the REST endpoint GitHub calls on push.
         */
        public function register_webhook_route() {
            register_rest_route(
                self::REST_NAMESPACE,
                self::REST_ROUTE,
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'handle_webhook' ),
                    'permission_callback' => '__return_true',
                )
            );
        }

        /**
         * Handle a GitHub webhook delivery.
         *
         * Verifies the HMAC signature, and on a push to the tracked branch
         * installs the new commit immediately.
         *
         * @param WP_REST_Request $request Incoming request.
         *
         * @return WP_REST_Response
         */
        public function handle_webhook( $request ) {
            $settings = $this->get_settings();

            $secret = $this->get_webhook_secret();

            if ( '' === $secret ) {
                return new WP_REST_Response( array( 'message' => 'Webhook secret is not configured.' ), 403 );
            }

            $payload   = $request->get_body();
            $signature = $request->get_header( 'x_hub_signature_256' );

            if ( ! $this->verify_webhook_signature( $payload, $signature, $secret ) ) {
                return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 401 );
            }

            $event = $request->get_header( 'x_github_event' );

            if ( 'ping' === $event ) {
                return new WP_REST_Response( array( 'message' => 'pong' ), 200 );
            }

            if ( 'push' !== $event ) {
                return new WP_REST_Response( array( 'message' => 'Ignored event: ' . sanitize_text_field( (string) $event ) ), 202 );
            }

            if ( empty( $settings['autosync'] ) ) {
                return new WP_REST_Response( array( 'message' => 'Auto-sync is disabled.' ), 202 );
            }

            $data   = json_decode( $payload, true );
            $branch = ! empty( $settings['repository_branch'] ) ? $settings['repository_branch'] : 'main';
            $ref    = is_array( $data ) && isset( $data['ref'] ) ? $data['ref'] : '';

            if ( 'refs/heads/' . $branch !== $ref ) {
                return new WP_REST_Response( array( 'message' => 'Push does not target the tracked branch.' ), 202 );
            }

            $this->clear_remote_cache();

            $remote = $this->get_remote_info();

            if ( is_wp_error( $remote ) ) {
                return new WP_REST_Response( array( 'message' => $remote->get_error_message() ), 500 );
            }

            $result = $this->install_from_remote( $remote );

            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
            }

            update_option( self::INSTALLED_SHA_KEY, $remote['sha'] );

            return new WP_REST_Response(
                array(
                    'message' => 'Synced to latest commit.',
                    'sha'     => substr( $remote['sha'], 0, 7 ),
                ),
                200
            );
        }

        /**
         * Verify a GitHub webhook signature (X-Hub-Signature-256).
         *
         * @param string $payload   Raw request body.
         * @param string $signature Signature header value.
         * @param string $secret    Shared secret.
         *
         * @return bool
         */
        private function verify_webhook_signature( $payload, $signature, $secret ) {
            if ( empty( $signature ) || 0 !== strpos( $signature, 'sha256=' ) ) {
                return false;
            }

            $expected = 'sha256=' . hash_hmac( 'sha256', (string) $payload, $secret );

            return hash_equals( $expected, $signature );
        }

        /**
         * On an admin request, check for a new commit and install it (throttled).
         *
         * This gives near-real-time updates without depending solely on the
         * hourly WP-Cron event, and downloads only when the branch has actually
         * moved so the common case is a single cheap API call.
         */
        public function maybe_admin_sync() {
            if ( wp_doing_ajax() || wp_doing_cron() ) {
                return;
            }

            if ( ! current_user_can( 'update_plugins' ) ) {
                return;
            }

            $settings = $this->get_settings();

            if ( empty( $settings['autosync'] ) ) {
                return;
            }

            if ( get_transient( self::ADMIN_THROTTLE_KEY ) ) {
                return;
            }

            // Throttle so at most one check runs per couple of minutes per site.
            set_transient( self::ADMIN_THROTTLE_KEY, 1, 2 * MINUTE_IN_SECONDS );

            $remote = $this->get_remote_info();

            if ( is_wp_error( $remote ) || empty( $remote['sha'] ) ) {
                return;
            }

            $installed = (string) get_option( self::INSTALLED_SHA_KEY, '' );

            if ( '' !== $installed && $remote['sha'] === $installed ) {
                return;
            }

            $result = $this->install_from_remote( $remote );

            if ( ! is_wp_error( $result ) ) {
                update_option( self::INSTALLED_SHA_KEY, $remote['sha'] );
            }
        }

        /**
         * Get the webhook payload URL for this site.
         *
         * @return string
         */
        private function get_webhook_url() {
            return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
        }

        /**
         * Resolve the webhook secret, generating and persisting one if needed.
         *
         * A BOKUN_GITHUB_WEBHOOK_SECRET constant, when defined, takes precedence.
         *
         * @return string
         */
        private function get_webhook_secret() {
            if ( defined( 'BOKUN_GITHUB_WEBHOOK_SECRET' ) && BOKUN_GITHUB_WEBHOOK_SECRET ) {
                return (string) BOKUN_GITHUB_WEBHOOK_SECRET;
            }

            $secret = (string) get_option( self::WEBHOOK_SECRET_KEY, '' );

            if ( '' === $secret ) {
                $secret = wp_generate_password( 40, false );
                update_option( self::WEBHOOK_SECRET_KEY, $secret );
            }

            return $secret;
        }

        /* ---------------------------------------------------------------------
         * Remote lookups
         * ------------------------------------------------------------------ */

        /**
         * Fetch remote branch information (latest commit SHA + header metadata).
         *
         * @return array|WP_Error {
         *     @type string $sha          Latest commit SHA on the branch.
         *     @type string $version      Header version parsed from the branch.
         *     @type string $package      Zipball download URL.
         *     @type string $homepage     Repository homepage.
         *     @type string $branch       Resolved branch name.
         *     @type string $requires     Minimum WordPress version.
         *     @type string $requires_php Minimum PHP version.
         *     @type string $tested       Tested-up-to WordPress version.
         * }
         */
        public function get_remote_info() {
            $settings = $this->get_settings();

            $parsed = $this->parse_repository_url( $settings['repository_url'] );

            if ( is_wp_error( $parsed ) ) {
                return $parsed;
            }

            $branch = ! empty( $settings['repository_branch'] ) ? $settings['repository_branch'] : 'main';
            $token  = $this->get_token();

            $cache_key = 'bokun_github_sync_' . md5( $settings['repository_url'] . '|' . $branch );

            if ( ! $this->should_bypass_cache() ) {
                $cached = get_transient( $cache_key );

                if ( is_array( $cached ) ) {
                    return $cached;
                }
            }

            // 1) Resolve the latest commit SHA on the branch.
            $sha = $this->fetch_branch_sha( $parsed, $branch, $token );

            if ( is_wp_error( $sha ) ) {
                return $sha;
            }

            // 2) Parse the plugin header from the branch for version/metadata.
            $header = $this->fetch_plugin_header( $parsed, $branch, $token );

            $version = '';
            $meta    = array(
                'requires'     => '',
                'requires_php' => '',
                'tested'       => '',
            );

            if ( ! is_wp_error( $header ) ) {
                $version = $header['version'];
                $meta    = wp_parse_args( $header['meta'], $meta );
            }

            if ( '' === $version ) {
                $version = $this->get_installed_version();
            }

            $remote = array(
                'sha'          => $sha,
                'version'      => $version,
                'branch'       => $branch,
                // Pin the archive to the exact resolved commit, not the branch
                // name, so the tree we install matches the SHA we compared and
                // record. Otherwise a commit pushed between resolving the SHA
                // and downloading could install a different tree.
                'package'      => sprintf( 'https://api.github.com/repos/%1$s/%2$s/zipball/%3$s', $parsed['owner'], $parsed['repo'], rawurlencode( $sha ) ),
                'homepage'     => sprintf( 'https://github.com/%1$s/%2$s', $parsed['owner'], $parsed['repo'] ),
                'requires'     => $meta['requires'],
                'requires_php' => $meta['requires_php'],
                'tested'       => $meta['tested'],
            );

            set_transient( $cache_key, $remote, 15 * MINUTE_IN_SECONDS );

            return $remote;
        }

        /**
         * Fetch the latest commit SHA on the given branch.
         *
         * @param array  $parsed Parsed repository (owner/repo).
         * @param string $branch Branch name.
         * @param string $token  Optional token.
         *
         * @return string|WP_Error
         */
        private function fetch_branch_sha( $parsed, $branch, $token ) {
            $url = sprintf( 'https://api.github.com/repos/%1$s/%2$s/commits/%3$s', $parsed['owner'], $parsed['repo'], rawurlencode( $branch ) );

            $headers = array(
                // Ask for just the SHA to keep the payload tiny.
                'Accept'     => 'application/vnd.github.sha',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            );

            if ( ! empty( $token ) ) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }

            $response = wp_remote_get(
                $url,
                array(
                    'headers' => $headers,
                    'timeout' => 30,
                )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = trim( wp_remote_retrieve_body( $response ) );

            if ( 200 !== $code ) {
                $message = $body;

                if ( '' === $message ) {
                    $message = sprintf(
                        /* translators: %d: HTTP status code. */
                        __( 'Unable to read the latest commit from GitHub (HTTP %d).', 'BOKUN_text_domain' ),
                        $code
                    );
                }

                return new WP_Error( 'bokun_github_sha', wp_strip_all_tags( $message ) );
            }

            // With the sha media type the body is the raw 40-char SHA. Fall back
            // to JSON parsing if a full commit object came back instead.
            if ( preg_match( '/^[0-9a-f]{40}$/i', $body ) ) {
                return $body;
            }

            $json = json_decode( $body, true );

            if ( is_array( $json ) && ! empty( $json['sha'] ) ) {
                return $json['sha'];
            }

            return new WP_Error( 'bokun_github_sha', __( 'GitHub did not return a commit reference for the branch.', 'BOKUN_text_domain' ) );
        }

        /**
         * Fetch and parse the plugin header from the branch.
         *
         * @param array  $parsed Parsed repository (owner/repo).
         * @param string $branch Branch name.
         * @param string $token  Optional token.
         *
         * @return array|WP_Error { @type string $version; @type array $meta }
         */
        private function fetch_plugin_header( $parsed, $branch, $token ) {
            $url = sprintf(
                'https://raw.githubusercontent.com/%1$s/%2$s/%3$s/%4$s',
                $parsed['owner'],
                $parsed['repo'],
                rawurlencode( $branch ),
                basename( $this->plugin_file )
            );

            $headers = array(
                'Accept'     => 'application/vnd.github.raw',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            );

            if ( ! empty( $token ) ) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }

            $response = wp_remote_get(
                $url,
                array(
                    'headers' => $headers,
                    'timeout' => 30,
                )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                return new WP_Error( 'bokun_github_header', __( 'Unable to read the plugin header from GitHub.', 'BOKUN_text_domain' ) );
            }

            $body = wp_remote_retrieve_body( $response );

            if ( '' === $body ) {
                return new WP_Error( 'bokun_github_header', __( 'The plugin header could not be located on the branch.', 'BOKUN_text_domain' ) );
            }

            $version      = $this->match_header( $body, 'Version' );
            $requires     = $this->match_header( $body, 'Requires at least' );
            $requires_php = $this->match_header( $body, 'Requires PHP' );
            $tested       = $this->match_header( $body, 'Tested up to' );

            return array(
                'version' => $version,
                'meta'    => array(
                    'requires'     => $requires,
                    'requires_php' => $requires_php,
                    'tested'       => $tested,
                ),
            );
        }

        /**
         * Extract a single header field value from raw plugin file contents.
         *
         * @param string $contents Raw file contents.
         * @param string $field    Header field label.
         *
         * @return string
         */
        private function match_header( $contents, $field ) {
            $pattern = '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi';

            if ( preg_match( $pattern, $contents, $matches ) ) {
                return trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $matches[1] ) );
            }

            return '';
        }

        /* ---------------------------------------------------------------------
         * Admin UI
         * ------------------------------------------------------------------ */

        /**
         * Build the admin URL of the settings page under the Bokun menu.
         *
         * @return string
         */
        private function get_admin_page_url() {
            return add_query_arg(
                array(
                    'post_type' => 'bokun_booking',
                    'page'      => self::ADMIN_SLUG,
                ),
                admin_url( 'edit.php' )
            );
        }

        /**
         * Register plugin settings.
         */
        public function register_settings() {
            register_setting(
                'bokun_github_sync',
                self::OPTION_KEY,
                array( $this, 'sanitize_settings' )
            );
        }

        /**
         * Sanitize submitted settings.
         *
         * @param array $input Raw input.
         *
         * @return array
         */
        public function sanitize_settings( $input ) {
            $current   = $this->get_settings();
            $sanitized = $this->get_default_settings();

            if ( isset( $input['repository_url'] ) ) {
                $sanitized['repository_url'] = esc_url_raw( trim( $input['repository_url'] ) );
            }

            if ( isset( $input['repository_branch'] ) ) {
                $sanitized['repository_branch'] = sanitize_text_field( $input['repository_branch'] );
            }

            // Preserve an existing token if the field is left blank on save.
            if ( isset( $input['github_token'] ) && '' !== trim( $input['github_token'] ) ) {
                $sanitized['github_token'] = sanitize_text_field( trim( $input['github_token'] ) );
            } else {
                $sanitized['github_token'] = isset( $current['github_token'] ) ? $current['github_token'] : '';
            }

            $sanitized['autosync'] = ! empty( $input['autosync'] ) ? 1 : 0;

            // Reflect the auto-sync choice into WordPress' auto-update option.
            $this->set_wp_auto_update( (bool) $sanitized['autosync'] );

            $this->settings = $sanitized;

            // Any settings change should invalidate the cached remote lookup.
            $this->clear_remote_cache();

            return $sanitized;
        }

        /**
         * Render the settings page.
         */
        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $this->settings = $this->get_settings( true );
            $settings       = $this->settings;
            $notice         = $this->consume_notice();
            $token_locked   = $this->token_from_constant();

            $remote        = $this->get_remote_info();
            $installed_sha = (string) get_option( self::INSTALLED_SHA_KEY, '' );
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Bokun GitHub Sync', 'BOKUN_text_domain' ); ?></h1>

                <?php if ( $notice ) : ?>
                    <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
                <?php endif; ?>

                <p><?php esc_html_e( 'This plugin keeps itself in sync with its GitHub repository. When auto-sync is enabled, any change pushed to the tracked branch is installed automatically in the background.', 'BOKUN_text_domain' ); ?></p>

                <table class="widefat striped" style="max-width:720px;margin:1em 0;">
                    <tbody>
                        <tr>
                            <th style="width:220px;"><?php esc_html_e( 'Installed commit', 'BOKUN_text_domain' ); ?></th>
                            <td><code><?php echo esc_html( '' !== $installed_sha ? substr( $installed_sha, 0, 7 ) : __( 'unknown', 'BOKUN_text_domain' ) ); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Latest commit on branch', 'BOKUN_text_domain' ); ?></th>
                            <td>
                                <?php if ( is_wp_error( $remote ) ) : ?>
                                    <span style="color:#b32d2e;"><?php echo esc_html( $remote->get_error_message() ); ?></span>
                                <?php else : ?>
                                    <code><?php echo esc_html( substr( $remote['sha'], 0, 7 ) ); ?></code>
                                    <?php if ( '' !== $installed_sha && $remote['sha'] !== $installed_sha ) : ?>
                                        <strong style="color:#996800;">&nbsp;<?php esc_html_e( '— update available', 'BOKUN_text_domain' ); ?></strong>
                                    <?php elseif ( '' !== $installed_sha ) : ?>
                                        <strong style="color:#00844a;">&nbsp;<?php esc_html_e( '— up to date', 'BOKUN_text_domain' ); ?></strong>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <form method="post" action="options.php">
                    <?php settings_fields( 'bokun_github_sync' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="bokun_repository_url"><?php esc_html_e( 'Repository URL', 'BOKUN_text_domain' ); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" id="bokun_repository_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[repository_url]" value="<?php echo esc_attr( $settings['repository_url'] ); ?>" placeholder="https://github.com/owner/repository" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bokun_repository_branch"><?php esc_html_e( 'Branch', 'BOKUN_text_domain' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="bokun_repository_branch" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[repository_branch]" value="<?php echo esc_attr( $settings['repository_branch'] ); ?>" placeholder="main" />
                                <p class="description"><?php esc_html_e( 'The branch to track. Changes to this branch are synced to the installed plugin.', 'BOKUN_text_domain' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bokun_github_token"><?php esc_html_e( 'GitHub token', 'BOKUN_text_domain' ); ?></label></th>
                            <td>
                                <?php if ( $token_locked ) : ?>
                                    <input type="text" class="regular-text" value="<?php esc_attr_e( 'Defined in wp-config.php (BOKUN_GITHUB_TOKEN)', 'BOKUN_text_domain' ); ?>" disabled />
                                    <p class="description"><?php esc_html_e( 'A token constant is defined in wp-config.php and takes precedence over this field.', 'BOKUN_text_domain' ); ?></p>
                                <?php else : ?>
                                    <input type="password" class="regular-text" id="bokun_github_token" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[github_token]" value="" autocomplete="off" placeholder="<?php echo esc_attr( '' !== $settings['github_token'] ? '••••••••••••' : '' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Required for private repositories. Leave blank to keep the saved token. Stored in the WordPress options table — prefer defining BOKUN_GITHUB_TOKEN in wp-config.php.', 'BOKUN_text_domain' ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto-sync', 'BOKUN_text_domain' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[autosync]" value="1" <?php checked( ! empty( $settings['autosync'] ) ); ?> />
                                    <?php esc_html_e( 'Automatically install changes from the tracked branch (recommended).', 'BOKUN_text_domain' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'When enabled, WordPress checks the branch hourly and installs new commits in the background.', 'BOKUN_text_domain' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save settings', 'BOKUN_text_domain' ) ); ?>
                </form>

                <hr />

                <h2><?php esc_html_e( 'Sync now', 'BOKUN_text_domain' ); ?></h2>
                <p><?php esc_html_e( 'Force an immediate check and install the latest commit from the tracked branch.', 'BOKUN_text_domain' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'bokun_github_sync_now_action' ); ?>
                    <input type="hidden" name="action" value="bokun_github_sync_now" />
                    <?php submit_button( __( 'Sync from GitHub now', 'BOKUN_text_domain' ), 'primary', 'submit', false ); ?>
                </form>

                <hr />

                <h2><?php esc_html_e( 'Instant sync on push (GitHub webhook)', 'BOKUN_text_domain' ); ?></h2>
                <p><?php esc_html_e( 'For an event-driven update — installed the moment you push to the branch, instead of waiting for a scheduled check — add a webhook in your GitHub repository using the details below.', 'BOKUN_text_domain' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Payload URL', 'BOKUN_text_domain' ); ?></th>
                        <td>
                            <input type="text" class="large-text code" readonly onfocus="this.select();" value="<?php echo esc_attr( $this->get_webhook_url() ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Secret', 'BOKUN_text_domain' ); ?></th>
                        <td>
                            <input type="text" class="regular-text code" readonly onfocus="this.select();" value="<?php echo esc_attr( $this->get_webhook_secret() ); ?>" />
                            <p class="description"><?php esc_html_e( 'Paste this into the webhook Secret field. It can also be set via the BOKUN_GITHUB_WEBHOOK_SECRET constant in wp-config.php.', 'BOKUN_text_domain' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Content type', 'BOKUN_text_domain' ); ?></th>
                        <td><code>application/json</code></td>
                    </tr>
                </table>
                <p>
                    <?php esc_html_e( 'In GitHub: Repository → Settings → Webhooks → Add webhook. Paste the Payload URL and Secret above, set Content type to application/json, choose "Just the push event", and save. Every push to the tracked branch will then sync this site immediately.', 'BOKUN_text_domain' ); ?>
                </p>
                <p class="description">
                    <?php esc_html_e( 'Even without a webhook, the plugin also checks for changes whenever an administrator opens the dashboard (at most once every couple of minutes) and hourly via WP-Cron.', 'BOKUN_text_domain' ); ?>
                </p>
            </div>
            <?php
        }

        /**
         * Add a settings shortcut on the Plugins screen.
         *
         * @param array $links Existing action links.
         *
         * @return array
         */
        public function add_action_links( $links ) {
            $url = $this->get_admin_page_url();

            $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'GitHub Sync', 'BOKUN_text_domain' ) . '</a>';

            return $links;
        }

        /**
         * Handle the "Sync now" action.
         */
        public function handle_sync_now() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'BOKUN_text_domain' ) );
            }

            check_admin_referer( 'bokun_github_sync_now_action' );

            $this->clear_remote_cache();

            $remote = $this->get_remote_info();

            if ( is_wp_error( $remote ) ) {
                $this->persist_notice( $remote->get_error_message(), 'error' );
                $this->redirect_back();
            }

            $installed_sha = (string) get_option( self::INSTALLED_SHA_KEY, '' );

            if ( '' !== $installed_sha && $remote['sha'] === $installed_sha ) {
                $this->persist_notice( __( 'Already up to date with the latest commit on the branch.', 'BOKUN_text_domain' ), 'success' );
                $this->redirect_back();
            }

            $result = $this->install_from_remote( $remote );

            if ( is_wp_error( $result ) ) {
                $this->persist_notice( $result->get_error_message(), 'error' );
            } else {
                update_option( self::INSTALLED_SHA_KEY, $remote['sha'] );
                $this->persist_notice(
                    sprintf(
                        /* translators: %s: short commit SHA. */
                        __( 'Synced successfully to commit %s.', 'BOKUN_text_domain' ),
                        substr( $remote['sha'], 0, 7 )
                    ),
                    'success'
                );
            }

            $this->redirect_back();
        }

        /**
         * Install the plugin from the resolved remote package using WP_Upgrader.
         *
         * @param array $remote Remote info from get_remote_info().
         *
         * @return true|WP_Error
         */
        private function install_from_remote( $remote ) {
            if ( ! class_exists( 'Plugin_Upgrader' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }

            if ( ! function_exists( 'request_filesystem_credentials' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            // Feed our update details into the transient so the shared source
            // rename + auth filters engage during the upgrade.
            $transient = get_site_transient( 'update_plugins' );

            if ( ! is_object( $transient ) ) {
                $transient = new stdClass();
            }

            $transient->response[ $this->plugin_basename ] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $this->build_display_version( $remote ),
                'package'     => $remote['package'],
                'url'         => $remote['homepage'],
            );

            set_site_transient( 'update_plugins', $transient );

            // Record the SHA being installed so after_update() promotes exactly
            // this commit once the upgrade completes.
            $this->set_pending_sha( $remote['sha'] );

            $skin     = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );

            $result = $upgrader->upgrade( $this->plugin_basename );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            if ( false === $result || null === $result ) {
                $messages = $skin->get_upgrade_messages();
                $message  = ! empty( $messages ) ? implode( ' ', array_map( 'wp_strip_all_tags', (array) $messages ) ) : __( 'The GitHub sync did not complete.', 'BOKUN_text_domain' );

                return new WP_Error( 'bokun_github_install', $message );
            }

            return true;
        }

        /* ---------------------------------------------------------------------
         * Helpers
         * ------------------------------------------------------------------ */

        /**
         * Retrieve settings merged with defaults.
         *
         * @param bool $force_refresh Bypass the cached property.
         *
         * @return array
         */
        private function get_settings( $force_refresh = false ) {
            if ( $force_refresh || empty( $this->settings ) ) {
                $this->settings = wp_parse_args( get_option( self::OPTION_KEY, array() ), $this->get_default_settings() );
            }

            return $this->settings;
        }

        /**
         * Default settings (auto-sync on, pointing at the shipped repository).
         *
         * @return array
         */
        private function get_default_settings() {
            return array(
                'repository_url'    => self::DEFAULT_REPO,
                'repository_branch' => 'main',
                'github_token'      => '',
                'autosync'          => 1,
            );
        }

        /**
         * Resolve the effective GitHub token (constant wins over option).
         *
         * @return string
         */
        private function get_token() {
            $constant = $this->token_from_constant();

            if ( '' !== $constant ) {
                return $constant;
            }

            $settings = $this->get_settings();

            return isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
        }

        /**
         * Token from the BOKUN_GITHUB_TOKEN constant, if defined.
         *
         * @return string
         */
        private function token_from_constant() {
            if ( defined( 'BOKUN_GITHUB_TOKEN' ) && BOKUN_GITHUB_TOKEN ) {
                return (string) BOKUN_GITHUB_TOKEN;
            }

            return '';
        }

        /**
         * Whether the remote cache should be bypassed for this request.
         *
         * @return bool
         */
        private function should_bypass_cache() {
            if ( isset( $_GET['force-check'] ) && '1' === $_GET['force-check'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return true;
            }

            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                return true;
            }

            return false;
        }

        /**
         * Delete cached remote lookups for the configured repository/branch.
         */
        private function clear_remote_cache() {
            $settings = get_option( self::OPTION_KEY, array() );
            $settings = wp_parse_args( is_array( $settings ) ? $settings : array(), $this->get_default_settings() );

            $branch = ! empty( $settings['repository_branch'] ) ? $settings['repository_branch'] : 'main';

            delete_transient( 'bokun_github_sync_' . md5( $settings['repository_url'] . '|' . $branch ) );
        }

        /**
         * Store the commit SHA of an update we are about to install.
         *
         * Written only when it changes to avoid needless option writes on the
         * frequent update-transient checks.
         *
         * @param string $sha Commit SHA.
         */
        private function set_pending_sha( $sha ) {
            if ( '' === (string) $sha ) {
                return;
            }

            if ( (string) get_option( self::PENDING_SHA_KEY, '' ) !== (string) $sha ) {
                update_option( self::PENDING_SHA_KEY, $sha );
            }
        }

        /**
         * Enable or disable WordPress' native auto-update flag for this plugin.
         *
         * @param bool $enabled Whether auto-updates should be on.
         */
        private function set_wp_auto_update( $enabled ) {
            $auto_updates = (array) get_site_option( 'auto_update_plugins', array() );

            if ( $enabled ) {
                if ( ! in_array( $this->plugin_basename, $auto_updates, true ) ) {
                    $auto_updates[] = $this->plugin_basename;
                }
            } else {
                $auto_updates = array_values( array_diff( $auto_updates, array( $this->plugin_basename ) ) );
            }

            update_site_option( 'auto_update_plugins', $auto_updates );
        }

        /**
         * Build a human-readable version string that encodes the commit.
         *
         * @param array $remote Remote info.
         *
         * @return string
         */
        private function build_display_version( $remote ) {
            $base = ! empty( $remote['version'] ) ? $remote['version'] : $this->get_installed_version();
            $sha  = ! empty( $remote['sha'] ) ? substr( $remote['sha'], 0, 7 ) : '';

            if ( '' === $sha ) {
                return $base;
            }

            return $base . '+' . $sha;
        }

        /**
         * Get the installed plugin header data.
         *
         * @return array
         */
        private function get_installed_plugin_data() {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            return get_plugin_data( $this->plugin_file, false, false );
        }

        /**
         * Get the installed plugin version.
         *
         * @return string
         */
        private function get_installed_version() {
            $data = $this->get_installed_plugin_data();

            return ! empty( $data['Version'] ) ? $data['Version'] : '0';
        }

        /**
         * Parse a GitHub repository URL into owner/repo parts.
         *
         * @param string $url Repository URL.
         *
         * @return array|WP_Error
         */
        private function parse_repository_url( $url ) {
            $url   = trim( (string) $url );
            $parts = wp_parse_url( $url );

            if ( empty( $parts['host'] ) || false === stripos( $parts['host'], 'github.com' ) ) {
                return new WP_Error( 'bokun_github_repo', __( 'Please provide a valid GitHub repository URL.', 'BOKUN_text_domain' ) );
            }

            $path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

            if ( '' === $path ) {
                return new WP_Error( 'bokun_github_repo', __( 'The repository URL must include the owner and repository name.', 'BOKUN_text_domain' ) );
            }

            $segments = explode( '/', $path );

            if ( count( $segments ) < 2 ) {
                return new WP_Error( 'bokun_github_repo', __( 'Unable to detect the repository owner and name from the URL.', 'BOKUN_text_domain' ) );
            }

            return array(
                'owner' => $segments[0],
                'repo'  => preg_replace( '#\.git$#', '', $segments[1] ),
            );
        }

        /**
         * Persist a short-lived admin notice.
         *
         * @param string $message Message text.
         * @param string $type    Notice type (success|error|warning|info).
         */
        private function persist_notice( $message, $type = 'success' ) {
            set_transient(
                self::NOTICE_KEY . '_' . get_current_user_id(),
                array(
                    'message' => $message,
                    'type'    => $type,
                ),
                MINUTE_IN_SECONDS
            );
        }

        /**
         * Consume the persisted admin notice.
         *
         * @return array|null
         */
        private function consume_notice() {
            $key    = self::NOTICE_KEY . '_' . get_current_user_id();
            $notice = get_transient( $key );

            if ( $notice ) {
                delete_transient( $key );
            }

            return $notice;
        }

        /**
         * Redirect back to the settings page and stop execution.
         */
        private function redirect_back() {
            wp_safe_redirect( $this->get_admin_page_url() );
            exit;
        }
    }
}
