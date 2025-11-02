<?php
/**
 * Plugin Name: Bokun GitHub Updater Add-on
 * Description: Adds GitHub installation and update tooling for the Bokun Bookings Management plugin.
 * Version: 1.0.0
 * Author: Hitesh (HWT)
 * Text Domain: bokun-github-updater
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Bokun_Github_Updater_Addon' ) ) {
    class Bokun_Github_Updater_Addon {
        const OPTION_KEY = 'bokun_github_updater_settings';
        const ADMIN_SLUG = 'bokun-github-updater';
        const NOTICE_KEY = 'bokun_github_updater_notice';

        /**
         * Cached settings.
         *
         * @var array
         */
        private $settings = array();

        public function __construct() {
            $this->settings = $this->get_settings();

            add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_post_bokun_github_update', array( $this, 'handle_update_request' ) );
            add_action( 'admin_bar_menu', array( $this, 'register_admin_bar_items' ), 120 );
        }

        /**
         * Register the settings page under the Tools menu.
         */
        public function register_settings_page() {
            add_management_page(
                __( 'Bokun GitHub Updater', 'bokun-github-updater' ),
                __( 'Bokun GitHub Updater', 'bokun-github-updater' ),
                'manage_options',
                self::ADMIN_SLUG,
                array( $this, 'render_settings_page' )
            );
        }

        /**
         * Register settings for the updater.
         */
        public function register_settings() {
            register_setting(
                'bokun_github_updater',
                self::OPTION_KEY,
                array( $this, 'sanitize_settings' )
            );

            add_settings_section(
                'bokun_github_updater_repo',
                __( 'Repository Settings', 'bokun-github-updater' ),
                array( $this, 'render_repo_section_description' ),
                self::ADMIN_SLUG
            );

            add_settings_field(
                'repository_url',
                __( 'Repository URL', 'bokun-github-updater' ),
                array( $this, 'render_repository_url_field' ),
                self::ADMIN_SLUG,
                'bokun_github_updater_repo'
            );

            add_settings_field(
                'repository_branch',
                __( 'Branch or Tag', 'bokun-github-updater' ),
                array( $this, 'render_repository_branch_field' ),
                self::ADMIN_SLUG,
                'bokun_github_updater_repo'
            );

            add_settings_field(
                'github_token',
                __( 'GitHub Token (optional)', 'bokun-github-updater' ),
                array( $this, 'render_github_token_field' ),
                self::ADMIN_SLUG,
                'bokun_github_updater_repo'
            );

            add_settings_section(
                'bokun_github_updater_account',
                __( 'Repositories From Your GitHub Account', 'bokun-github-updater' ),
                array( $this, 'render_account_section_description' ),
                self::ADMIN_SLUG
            );
        }

        /**
         * Sanitize settings input.
         *
         * @param array $input Raw input from the form.
         *
         * @return array
         */
        public function sanitize_settings( $input ) {
            $sanitized = $this->get_default_settings();

            if ( isset( $input['repository_url'] ) ) {
                $sanitized['repository_url'] = esc_url_raw( trim( $input['repository_url'] ) );
            }

            if ( isset( $input['repository_branch'] ) ) {
                $sanitized['repository_branch'] = sanitize_text_field( $input['repository_branch'] );
            }

            if ( isset( $input['github_token'] ) ) {
                $sanitized['github_token'] = sanitize_text_field( $input['github_token'] );
            }

            $this->settings = $sanitized;

            return $sanitized;
        }

        /**
         * Render repository section description.
         */
        public function render_repo_section_description() {
            echo '<p>' . esc_html__( 'Provide details about the GitHub repository that hosts the Bokun Bookings Management plugin. For public repositories you only need the URL and branch. For private repositories supply a personal access token with the <code>repo</code> scope.', 'bokun-github-updater' ) . '</p>';
        }

        /**
         * Render the repository URL field.
         */
        public function render_repository_url_field() {
            printf(
                '<input type="url" class="regular-text" name="%1$s[repository_url]" id="bokun_repository_url" value="%2$s" placeholder="%3$s" />',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $this->settings['repository_url'] ),
                esc_attr__( 'https://github.com/owner/repository', 'bokun-github-updater' )
            );
        }

        /**
         * Render the repository branch field.
         */
        public function render_repository_branch_field() {
            printf(
                '<input type="text" class="regular-text" name="%1$s[repository_branch]" id="bokun_repository_branch" value="%2$s" placeholder="%3$s" />',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $this->settings['repository_branch'] ),
                esc_attr__( 'main', 'bokun-github-updater' )
            );
        }

        /**
         * Render the GitHub token field.
         */
        public function render_github_token_field() {
            printf(
                '<input type="password" class="regular-text" name="%1$s[github_token]" id="bokun_github_token" value="%2$s" autocomplete="off" />',
                esc_attr( self::OPTION_KEY ),
                esc_attr( $this->settings['github_token'] )
            );
            echo '<p class="description">' . esc_html__( 'Token is optional for public repositories but required for private repositories. Token will be stored in plain text inside the WordPress options table; avoid reusing sensitive tokens.', 'bokun-github-updater' ) . '</p>';
        }

        /**
         * Render the account section description and repository list.
         */
        public function render_account_section_description() {
            echo '<p>' . esc_html__( 'When a GitHub personal access token is provided, repositories from that account can be listed here to quickly select one for updates.', 'bokun-github-updater' ) . '</p>';

            $token = $this->settings['github_token'];

            if ( empty( $token ) ) {
                echo '<p>' . esc_html__( 'Provide a token above and save changes to view available repositories.', 'bokun-github-updater' ) . '</p>';
                return;
            }

            $repositories = $this->get_cached_repositories( $token );

            if ( is_wp_error( $repositories ) ) {
                printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $repositories->get_error_message() ) );
                return;
            }

            if ( empty( $repositories ) ) {
                echo '<p>' . esc_html__( 'No repositories were found for the authenticated user.', 'bokun-github-updater' ) . '</p>';
                return;
            }

            echo '<p>' . esc_html__( 'Click a repository to fill in the repository URL and default branch automatically.', 'bokun-github-updater' ) . '</p>';
            echo '<ul class="bokun-github-repo-list">
';

            foreach ( $repositories as $repository ) {
                printf(
                    '<li><button type="button" class="button bokun-select-repo" data-url="%1$s" data-branch="%2$s">%3$s</button></li>',
                    esc_attr( $repository['html_url'] ),
                    esc_attr( $repository['default_branch'] ),
                    esc_html( $repository['full_name'] )
                );
            }

            echo '</ul>';

            echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::ADMIN_SLUG, 'refresh_repos' => 1 ), admin_url( 'tools.php' ) ) ) . '">' . esc_html__( 'Refresh repository list', 'bokun-github-updater' ) . '</a></p>';

            $this->enqueue_settings_assets();
        }

        /**
         * Enqueue inline assets for the settings screen.
         */
        private function enqueue_settings_assets() {
            add_action( 'admin_footer', array( $this, 'render_settings_inline_script' ) );
        }

        /**
         * Render inline JavaScript for the settings screen.
         */
        public function render_settings_inline_script() {
            $screen = get_current_screen();

            if ( ! $screen || 'tools_page_' . self::ADMIN_SLUG !== $screen->id ) {
                return;
            }
            ?>
            <script>
                ( function() {
                    const repoButtons = document.querySelectorAll('.bokun-select-repo');
                    const urlInput = document.getElementById('bokun_repository_url');
                    const branchInput = document.getElementById('bokun_repository_branch');

                    if (!repoButtons.length || !urlInput || !branchInput) {
                        return;
                    }

                    repoButtons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            const repoUrl = this.getAttribute('data-url');
                            const branch = this.getAttribute('data-branch');

                            if (repoUrl) {
                                urlInput.value = repoUrl;
                            }

                            if (branch) {
                                branchInput.value = branch;
                            }
                        });
                    });
                } )();
            </script>
            <style>
                .bokun-github-repo-list {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    padding-left: 0;
                    list-style: none;
                }

                .bokun-github-repo-list li {
                    margin: 0;
                }
            </style>
            <?php
        }

        /**
         * Render settings page markup.
         */
        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $notice = $this->consume_notice();
            $main_plugin_active   = $this->is_main_plugin_active();
            $main_plugin_path     = $this->get_main_plugin_path();
            $main_plugin_installed = ! empty( $main_plugin_path ) && file_exists( $main_plugin_path );

            if ( isset( $_GET['refresh_repos'] ) && ! empty( $this->settings['github_token'] ) ) {
                delete_transient( $this->get_repo_cache_key( $this->settings['github_token'] ) );
            }

            $this->settings = $this->get_settings( true );
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Bokun GitHub Updater', 'bokun-github-updater' ); ?></h1>

                <?php if ( $notice ) : ?>
                    <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
                <?php endif; ?>

                <?php if ( ! $main_plugin_installed ) : ?>
                    <div class="notice notice-warning"><p><?php esc_html_e( 'The Bokun Bookings Management plugin is not currently installed. Use the updater below to install it from GitHub.', 'bokun-github-updater' ); ?></p></div>
                <?php elseif ( ! $main_plugin_active ) : ?>
                    <div class="notice notice-info"><p><?php esc_html_e( 'The Bokun Bookings Management plugin is installed but not active. You can still update the files using this tool.', 'bokun-github-updater' ); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The Bokun Bookings Management plugin is active. Use the GitHub updater to fetch new versions or reinstall as needed.', 'bokun-github-updater' ); ?></p></div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'bokun_github_updater' );
                    do_settings_sections( self::ADMIN_SLUG );
                    submit_button( __( 'Save Settings', 'bokun-github-updater' ) );
                    ?>
                </form>

                <hr />

                <h2><?php esc_html_e( 'Install or Update From GitHub', 'bokun-github-updater' ); ?></h2>
                <p><?php esc_html_e( 'Once you have saved the repository settings, use the button below to download the plugin from GitHub, ensure the folder name matches the plugin header, and install/update it.', 'bokun-github-updater' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'bokun_github_update_action' ); ?>
                    <input type="hidden" name="action" value="bokun_github_update" />
                    <?php submit_button( __( 'Run GitHub Update', 'bokun-github-updater' ), 'primary', 'submit', false ); ?>
                </form>
            </div>
            <?php
        }

        /**
         * Handle the GitHub update request.
         */
        public function handle_update_request() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'bokun-github-updater' ) );
            }

            check_admin_referer( 'bokun_github_update_action' );

            $settings = $this->get_settings();
            $result   = $this->update_from_github( $settings );

            if ( is_wp_error( $result ) ) {
                $this->persist_notice( $result->get_error_message(), 'error' );
            } else {
                $this->persist_notice( $result, 'success' );
            }

            wp_safe_redirect( add_query_arg( array( 'page' => self::ADMIN_SLUG ), admin_url( 'tools.php' ) ) );
            exit;
        }

        /**
         * Run update from GitHub repository.
         *
         * @param array $settings Settings.
         *
         * @return string|WP_Error
         */
        private function update_from_github( $settings ) {
            if ( empty( $settings['repository_url'] ) ) {
                return new WP_Error( 'missing_repo', __( 'Please provide a repository URL before running the update.', 'bokun-github-updater' ) );
            }

            $parsed_repo = $this->parse_repository_url( $settings['repository_url'] );

            if ( is_wp_error( $parsed_repo ) ) {
                return $parsed_repo;
            }

            $branch = ! empty( $settings['repository_branch'] ) ? $settings['repository_branch'] : 'main';
            $token  = $settings['github_token'];

            $download_url = sprintf( 'https://api.github.com/repos/%1$s/%2$s/zipball/%3$s', $parsed_repo['owner'], $parsed_repo['repo'], rawurlencode( $branch ) );
            $zip_file     = $this->download_package( $download_url, $token );

            if ( is_wp_error( $zip_file ) ) {
                return $zip_file;
            }

            $result = $this->install_package( $zip_file );

            @unlink( $zip_file );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return __( 'GitHub package downloaded and installed successfully.', 'bokun-github-updater' );
        }

        /**
         * Download GitHub package to a temporary file.
         *
         * @param string $download_url URL for zipball.
         * @param string $token        Optional GitHub token.
         *
         * @return string|WP_Error Path to downloaded file or WP_Error.
         */
        private function download_package( $download_url, $token = '' ) {
            if ( ! function_exists( 'wp_tempnam' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $tmp = wp_tempnam( $download_url );

            if ( ! $tmp ) {
                return new WP_Error( 'temp_file', __( 'Unable to create a temporary file for the download.', 'bokun-github-updater' ) );
            }

            $headers = array(
                'Accept'       => 'application/vnd.github+json',
                'User-Agent'   => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            );

            if ( ! empty( $token ) ) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }

            $response = wp_remote_get(
                $download_url,
                array(
                    'timeout' => 60,
                    'stream'  => true,
                    'filename'=> $tmp,
                    'headers' => $headers,
                )
            );

            if ( is_wp_error( $response ) ) {
                @unlink( $tmp );
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );

            if ( $code >= 400 ) {
                $message = wp_remote_retrieve_body( $response );
                @unlink( $tmp );

                if ( empty( $message ) ) {
                    $message = sprintf( __( 'Unexpected response from GitHub (HTTP %d).', 'bokun-github-updater' ), $code );
                }

                return new WP_Error( 'download_failed', $message );
            }

            return $tmp;
        }

        /**
         * Install package from downloaded zip file.
         *
         * @param string $zip_file Path to zip file.
         *
         * @return true|WP_Error
         */
        private function install_package( $zip_file ) {
            if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            global $wp_filesystem;

            if ( ! $wp_filesystem ) {
                WP_Filesystem();
            }

            if ( ! $wp_filesystem ) {
                return new WP_Error( 'filesystem', __( 'The WordPress filesystem could not be initialized.', 'bokun-github-updater' ) );
            }

            $working_dir = trailingslashit( get_temp_dir() ) . 'bokun-github-' . wp_generate_password( 8, false );

            if ( ! wp_mkdir_p( $working_dir ) ) {
                return new WP_Error( 'temp_dir', __( 'Unable to create a temporary extraction folder.', 'bokun-github-updater' ) );
            }

            $unzipped = unzip_file( $zip_file, $working_dir );

            if ( is_wp_error( $unzipped ) ) {
                $wp_filesystem->delete( $working_dir, true );
                return $unzipped;
            }

            $directories = glob( trailingslashit( $working_dir ) . '*', GLOB_ONLYDIR );

            if ( empty( $directories ) ) {
                $wp_filesystem->delete( $working_dir, true );
                return new WP_Error( 'extraction_failed', __( 'The downloaded archive did not contain any directories.', 'bokun-github-updater' ) );
            }

            $package_root = trailingslashit( $directories[0] );
            $slug_data    = $this->determine_plugin_slug( $package_root );

            if ( is_wp_error( $slug_data ) ) {
                $wp_filesystem->delete( $working_dir, true );
                return $slug_data;
            }

            $destination = trailingslashit( WP_PLUGIN_DIR ) . $slug_data['slug'];

            if ( $wp_filesystem->is_dir( $destination ) ) {
                $wp_filesystem->delete( $destination, true );
            }

            if ( ! wp_mkdir_p( WP_PLUGIN_DIR ) ) {
                $wp_filesystem->delete( $working_dir, true );
                return new WP_Error( 'destination', __( 'Unable to access the plugins directory.', 'bokun-github-updater' ) );
            }

            $source      = rtrim( $package_root, '/' );
            $moved       = $wp_filesystem->move( $source, $destination, true );

            if ( ! $moved ) {
                // Attempt PHP rename as a fallback.
                $php_moved = @rename( $source, $destination );

                if ( ! $php_moved ) {
                    // Fall back to a recursive copy which works across filesystem boundaries.
                    $copied = copy_dir( $source, $destination );

                    if ( is_wp_error( $copied ) ) {
                        $wp_filesystem->delete( $working_dir, true );
                        return $copied;
                    }

                    $moved = true;
                } else {
                    $moved = true;
                }
            }

            if ( ! $moved ) {
                $wp_filesystem->delete( $working_dir, true );
                return new WP_Error( 'move_failed', __( 'Unable to move the extracted plugin into the plugins directory.', 'bokun-github-updater' ) );
            }

            $wp_filesystem->delete( $working_dir, true );

            return true;
        }

        /**
         * Determine plugin slug from extracted package.
         *
         * @param string $package_root Path to extracted package root.
         *
         * @return array|WP_Error
         */
        private function determine_plugin_slug( $package_root ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $package_root, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            $primary_match = null;
            $fallback_match = null;

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
                    continue;
                }

                $path = $file->getPathname();

                // If we directly locate the main plugin file, prefer it immediately.
                if ( 'bokun-bookings-management.php' === $file->getFilename() ) {
                    $primary_match = array(
                        'slug'      => 'bokun-bookings-management',
                        'main_file' => $path,
                    );
                    break;
                }

                $plugin_data = get_plugin_data( $path, false, false );

                if ( empty( $plugin_data['Name'] ) ) {
                    continue;
                }

                $match = array(
                    'slug'      => sanitize_title( $plugin_data['Name'] ),
                    'main_file' => $path,
                );

                if ( ! $fallback_match ) {
                    $fallback_match = $match;
                }

                $text_domain = isset( $plugin_data['TextDomain'] ) ? strtolower( $plugin_data['TextDomain'] ) : '';

                if ( 'bokun-bookings-management' === $match['slug'] || 'bokun_text_domain' === $text_domain ) {
                    $primary_match = array(
                        'slug'      => 'bokun-bookings-management',
                        'main_file' => $path,
                    );
                    break;
                }
            }

            if ( $primary_match ) {
                return $primary_match;
            }

            if ( $fallback_match ) {
                return $fallback_match;
            }

            return new WP_Error( 'plugin_slug', __( 'Unable to determine the plugin slug from the downloaded package.', 'bokun-github-updater' ) );
        }

        /**
         * Parse repository URL into owner/repo.
         *
         * @param string $url GitHub repository URL.
         *
         * @return array|WP_Error
         */
        private function parse_repository_url( $url ) {
            $parts = wp_parse_url( $url );

            if ( empty( $parts['host'] ) || false === stripos( $parts['host'], 'github.com' ) ) {
                return new WP_Error( 'invalid_repo', __( 'Please provide a valid GitHub repository URL.', 'bokun-github-updater' ) );
            }

            $path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

            if ( empty( $path ) ) {
                return new WP_Error( 'invalid_repo', __( 'The repository URL must include the owner and repository name.', 'bokun-github-updater' ) );
            }

            $segments = explode( '/', $path );

            if ( count( $segments ) < 2 ) {
                return new WP_Error( 'invalid_repo', __( 'Unable to detect repository owner and name from the provided URL.', 'bokun-github-updater' ) );
            }

            $owner = $segments[0];
            $repo  = preg_replace( '#\.git$#', '', $segments[1] );

            return array(
                'owner' => $owner,
                'repo'  => $repo,
            );
        }

        /**
         * Add the admin bar menu entry for the updater.
         *
         * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
         */
        public function register_admin_bar_items( $wp_admin_bar ) {
            if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
                return;
            }

            $parent_id = false;

            if ( $this->is_main_plugin_active() ) {
                $existing_parent = $wp_admin_bar->get_node( 'bokun-bookings-management' );

                if ( $existing_parent ) {
                    $parent_id = 'bokun-bookings-management';
                } else {
                    $wp_admin_bar->add_node(
                        array(
                            'id'    => 'bokun-bookings-management',
                            'title' => __( 'Bokun Bookings', 'bokun-github-updater' ),
                            'href'  => admin_url( 'edit.php?post_type=bokun_booking' ),
                        )
                    );
                    $parent_id = 'bokun-bookings-management';
                }
            }

            $wp_admin_bar->add_node(
                array(
                    'id'     => 'bokun-github-updater',
                    'parent' => $parent_id,
                    'title'  => __( 'GitHub Updater', 'bokun-github-updater' ),
                    'href'   => add_query_arg( array( 'page' => self::ADMIN_SLUG ), admin_url( 'tools.php' ) ),
                )
            );
        }

        /**
         * Check if the main Bokun plugin is active.
         *
         * @return bool
         */
        private function is_main_plugin_active() {
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            return is_plugin_active( 'bokun-bookings-management/bokun-bookings-management.php' );
        }

        /**
         * Retrieve settings with defaults.
         *
         * @param bool $force_refresh Whether to bypass the cached property.
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
         * Default settings.
         *
         * @return array
         */
        private function get_default_settings() {
            return array(
                'repository_url'    => '',
                'repository_branch' => 'main',
                'github_token'      => '',
            );
        }

        /**
         * Persist an admin notice for later display.
         *
         * @param string $message Notice message.
         * @param string $type    Notice type.
         */
        private function persist_notice( $message, $type = 'success' ) {
            $key = $this->get_notice_key();
            set_transient( $key, array(
                'message' => $message,
                'type'    => $type,
            ), MINUTE_IN_SECONDS );
        }

        /**
         * Consume persisted notice.
         *
         * @return array|null
         */
        private function consume_notice() {
            $key    = $this->get_notice_key();
            $notice = get_transient( $key );

            if ( $notice ) {
                delete_transient( $key );
            }

            return $notice;
        }

        /**
         * Build notice cache key.
         *
         * @return string
         */
        private function get_notice_key() {
            return self::NOTICE_KEY . '_' . get_current_user_id();
        }

        /**
         * Fetch repositories using GitHub API and cache the results.
         *
         * @param string $token GitHub token.
         *
         * @return array|WP_Error
         */
        private function get_cached_repositories( $token ) {
            $cache_key = $this->get_repo_cache_key( $token );
            $cached    = get_transient( $cache_key );

            if ( false !== $cached && ! isset( $_GET['refresh_repos'] ) ) {
                return $cached;
            }

            $repositories = $this->fetch_repositories( $token );

            if ( is_wp_error( $repositories ) ) {
                return $repositories;
            }

            set_transient( $cache_key, $repositories, HOUR_IN_SECONDS );

            return $repositories;
        }

        /**
         * Build cache key for repository listings.
         *
         * @param string $token GitHub token.
         *
         * @return string
         */
        private function get_repo_cache_key( $token ) {
            return 'bokun_github_repos_' . md5( $token );
        }

        /**
         * Fetch repositories from GitHub API using provided token.
         *
         * @param string $token GitHub token.
         *
         * @return array|WP_Error
         */
        private function fetch_repositories( $token ) {
            $response = wp_remote_get(
                'https://api.github.com/user/repos?per_page=100',
                array(
                    'headers' => array(
                        'Accept'        => 'application/vnd.github+json',
                        'Authorization' => 'Bearer ' . $token,
                        'User-Agent'    => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
                    ),
                    'timeout' => 30,
                )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );

            if ( 200 !== $code ) {
                $message = wp_remote_retrieve_body( $response );

                if ( empty( $message ) ) {
                    $message = sprintf( __( 'Unable to fetch repositories. GitHub returned HTTP %d.', 'bokun-github-updater' ), $code );
                }

                return new WP_Error( 'github_repos', $message );
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( ! is_array( $body ) ) {
                return new WP_Error( 'github_repos', __( 'Invalid response received from GitHub.', 'bokun-github-updater' ) );
            }

            $repositories = array();

            foreach ( $body as $repo ) {
                if ( empty( $repo['full_name'] ) || empty( $repo['html_url'] ) ) {
                    continue;
                }

                $repositories[] = array(
                    'full_name'     => $repo['full_name'],
                    'html_url'      => $repo['html_url'],
                    'default_branch'=> isset( $repo['default_branch'] ) ? $repo['default_branch'] : 'main',
                );
            }

            return $repositories;
        }

        /**
         * Get the absolute path to the main plugin file if it exists.
         *
         * @return string
         */
        private function get_main_plugin_path() {
            return trailingslashit( WP_PLUGIN_DIR ) . 'bokun-bookings-management/bokun-bookings-management.php';
        }
    }
}

new Bokun_Github_Updater_Addon();
