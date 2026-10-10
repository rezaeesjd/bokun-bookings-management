<?php
if( !class_exists ( 'BOKUN_Shortcode' ) ) {

    class BOKUN_Shortcode {

        function __construct(){

            add_shortcode('bokun_fetch_button', array($this, "function_bokun_fetch_button" ) );
            add_shortcode('bokun_booking_history', array($this, 'render_booking_history_table'));
            add_shortcode('bokun_booking_dashboard', array($this, 'render_booking_dashboard'));

        }

        
        function function_bokun_fetch_button() {
            $should_render_progress = !defined('BOKUN_PROGRESS_RENDERED');

            if ($should_render_progress) {
                define('BOKUN_PROGRESS_RENDERED', true);
            }

            ob_start();
            ?>
            <div class="bokun-fetch-wrapper">
                <a href="#" class="button button-primary bokun_fetch_booking_data_front" role="button">Fetch</a>
                <?php if ($should_render_progress) : ?>
                    <div id="bokun_progress" class="bokun-progress" style="display:none;" role="status" aria-live="polite">
                        <div class="bokun-progress__header">
                            <span id="bokun_progress_message" class="bokun-progress__message">Import progress</span>
                            <span class="bokun-progress__status">
                                <span id="bokun_progress_value" class="bokun-progress__value">0%</span>
                                <img id="bokun_progress_spinner" class="bokun-progress__spinner" src="<?= BOKUN_IMAGES_URL.'ajax-loading.gif'; ?>" alt="Loading" width="18" height="18">
                            </span>
                        </div>
                        <div class="bokun-progress__track" aria-hidden="true">
                            <div id="bokun_progress_bar" class="bokun-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        }


        private function enqueue_booking_history_assets($export_title) {
            $script_version = '1.0.0';
            $script_path = BOKUN_JS_DIR . 'bokun-booking-history.js';

            if (file_exists($script_path)) {
                $script_version = (string) filemtime($script_path);
            }

            wp_enqueue_style(
                'bokun-datatables',
                'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css',
                [],
                '1.13.8'
            );

            wp_enqueue_style(
                'bokun-datatables-buttons',
                'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css',
                ['bokun-datatables'],
                '2.4.2'
            );

            wp_enqueue_script(
                'bokun-datatables',
                'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
                ['jquery'],
                '1.13.8',
                true
            );

            wp_enqueue_script(
                'bokun-jszip',
                'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
                [],
                '3.10.1',
                true
            );

            wp_enqueue_script(
                'bokun-datatables-buttons',
                'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js',
                ['bokun-datatables'],
                '2.4.2',
                true
            );

            wp_enqueue_script(
                'bokun-datatables-buttons-html5',
                'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js',
                ['bokun-datatables-buttons', 'bokun-jszip'],
                '2.4.2',
                true
            );

            wp_enqueue_script(
                'bokun-booking-history',
                BOKUN_JS_URL . 'bokun-booking-history.js',
                ['bokun-datatables-buttons-html5'],
                $script_version,
                true
            );

            wp_localize_script(
                'bokun-booking-history',
                'bokunBookingHistory',
                [
                    'texts'    => [
                        'downloadCsv' => __('Download CSV', 'BOKUN_txt_domain'),
                        'noPermission' => __('You do not have permission to view the booking history.', 'BOKUN_txt_domain'),
                        'noResults' => __('No booking activity has been recorded yet.', 'BOKUN_txt_domain'),
                    ],
                    'language' => [],
                    'exportTitle' => sanitize_title($export_title),
                ]
            );
        }

        /**
         * Group product tags with an existing partner page ID for a missing tag.
         *
         * Exact title matches are shown first, followed by up to five closest
         * titles, and then the remaining tags alphabetically.
         *
         * @param string $missing_name Product tag title that needs an ID.
         * @param array  $source_tags  Product tags that already have an ID.
         * @return array
         */
        private function get_partner_tag_suggestions($missing_name, $source_tags) {
            $normalize = static function ($value) {
                $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $value = preg_replace('/\s+/', ' ', trim($value));

                if (function_exists('mb_strtolower')) {
                    return mb_strtolower($value, 'UTF-8');
                }

                return strtolower($value);
            };

            $missing_normalized = $normalize($missing_name);
            $exact = [];
            $ranked = [];

            foreach ($source_tags as $source_tag) {
                $source_normalized = $normalize($source_tag['name']);

                if ($source_normalized === $missing_normalized) {
                    $exact[] = $source_tag;
                    continue;
                }

                $percentage = 0.0;
                similar_text($missing_normalized, $source_normalized, $percentage);
                $source_tag['similarity'] = $percentage;
                $ranked[] = $source_tag;
            }

            usort($exact, static function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
            usort($ranked, static function ($a, $b) {
                if ($a['similarity'] === $b['similarity']) {
                    return strcasecmp($a['name'], $b['name']);
                }

                return ($a['similarity'] > $b['similarity']) ? -1 : 1;
            });

            $similar = array_slice($ranked, 0, 5);
            $other = array_slice($ranked, 5);
            usort($other, static function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });

            return [
                'exact'   => $exact,
                'similar' => $similar,
                'other'   => $other,
            ];
        }


        function render_booking_history_table($atts = []) {
            global $wpdb;

            $atts = shortcode_atts(
                [
                    'limit'      => 100,
                    'capability' => 'manage_options',
                    'export'     => 'booking-history',
                ],
                $atts,
                'bokun_booking_history'
            );

            $limit = is_numeric($atts['limit']) ? max(1, (int) $atts['limit']) : 100;
            $capability = sanitize_key($atts['capability']);
            $export_title = sanitize_title($atts['export']);
            if (empty($export_title)) {
                $export_title = 'booking-history';
            }

            if (!empty($capability) && !current_user_can($capability)) {
                return sprintf(
                    '<div class="bokun-booking-history-notice" role="alert">%s</div>',
                    esc_html__('You do not have permission to view the booking history.', 'BOKUN_txt_domain')
                );
            }

            $table_name   = $wpdb->prefix . 'bokun_booking_history';
            $like_name    = $wpdb->esc_like($table_name);
            $table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like_name)) === $table_name);

            if (!$table_exists) {
                return sprintf(
                    '<div class="bokun-booking-history-notice" role="alert">%s</div>',
                    esc_html__('The booking history table does not exist. Please contact an administrator.', 'BOKUN_txt_domain')
                );
            }

            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, post_id, booking_id, action_type, is_checked, user_id, user_name, actor_source, created_at
                     FROM {$table_name}
                     ORDER BY created_at DESC
                     LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );

            if (empty($logs)) {
                return sprintf(
                    '<div class="bokun-booking-history-notice" role="status">%s</div>',
                    esc_html__('No booking activity has been recorded yet.', 'BOKUN_txt_domain')
                );
            }

            $filter_options = [
                'action' => [],
                'status' => [],
                'actor'  => [],
                'source' => [],
            ];

            $processed_logs = [];

            foreach ($logs as $log) {
                $timestamp = strtotime($log['created_at']);
                $formatted_date = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : $log['created_at'];
                $sortable_date  = $timestamp ? $timestamp : 0;
                $action_label = ucwords(str_replace('-', ' ', $log['action_type']));
                // Sending a message is a one-off action, not a checkbox transition,
                // so report it as "Sent" instead of the generic Checked/Unchecked.
                if ('message-sent' === $log['action_type']) {
                    $status_label = __('Sent', 'BOKUN_txt_domain');
                } else {
                    $status_label = !empty($log['is_checked']) ? __('Checked', 'BOKUN_txt_domain') : __('Unchecked', 'BOKUN_txt_domain');
                }
                $actor_label  = $log['user_name'];

                if (empty($actor_label) && !empty($log['user_id'])) {
                    $user = get_user_by('id', (int) $log['user_id']);
                    if ($user) {
                        $actor_label = $user->display_name ? $user->display_name : $user->user_login;
                    }
                }

                if (empty($actor_label)) {
                    $actor_label = __('Unknown', 'BOKUN_txt_domain');
                }

                switch ($log['actor_source']) {
                    case 'wp_user':
                        $source_label = __('WordPress User', 'BOKUN_txt_domain');
                        break;
                    case 'team_member':
                        $source_label = __('Team Member', 'BOKUN_txt_domain');
                        break;
                    default:
                        $source_label = __('Guest', 'BOKUN_txt_domain');
                        break;
                }

                $booking_display = esc_html($log['booking_id']);

                if (!empty($log['post_id'])) {
                    $edit_link = get_permalink((int) $log['post_id']);
                    if ($edit_link) {
                        $booking_display = sprintf(
                            '<a href="%s">%s</a>',
                            esc_url($edit_link),
                            esc_html($log['booking_id'])
                        );
                    }
                }

                $action_value = sanitize_title($action_label);
                $status_value = sanitize_title($status_label);
                $actor_value  = sanitize_title($actor_label);
                $source_value = sanitize_title($source_label);

                if (!isset($filter_options['action'][$action_value])) {
                    $filter_options['action'][$action_value] = $action_label;
                }

                if (!isset($filter_options['status'][$status_value])) {
                    $filter_options['status'][$status_value] = $status_label;
                }

                if (!isset($filter_options['actor'][$actor_value])) {
                    $filter_options['actor'][$actor_value] = $actor_label;
                }

                if (!isset($filter_options['source'][$source_value])) {
                    $filter_options['source'][$source_value] = $source_label;
                }

                $processed_logs[] = [
                    'date'           => $formatted_date,
                    'booking_display'=> $booking_display,
                    'action_label'   => $action_label,
                    'action_value'   => $action_value,
                    'status_label'   => $status_label,
                    'status_value'   => $status_value,
                    'actor_label'    => $actor_label,
                    'actor_value'    => $actor_value,
                    'source_label'   => $source_label,
                    'source_value'   => $source_value,
                    'sort_date'      => $sortable_date,
                ];
            }

            foreach ($filter_options as $key => $options) {
                if (!empty($options)) {
                    natcasesort($options);
                    $filter_options[$key] = $options;
                }
            }

            $this->enqueue_booking_history_assets($export_title);

            $table_id = sanitize_html_class('bokun-booking-history-table-' . uniqid());

            ob_start();
            ?>
            <div class="bokun-booking-history" data-export-title="<?php echo esc_attr($export_title); ?>">
                <div class="bokun-history-filters" data-target-table="<?php echo esc_attr($table_id); ?>" aria-label="<?php esc_attr_e('Booking history filters', 'BOKUN_txt_domain'); ?>">
                    <?php
                    $filter_labels = [
                        'action' => __('Action', 'BOKUN_txt_domain'),
                        'status' => __('Status', 'BOKUN_txt_domain'),
                        'actor'  => __('Actor', 'BOKUN_txt_domain'),
                        'source' => __('Source', 'BOKUN_txt_domain'),
                    ];

                    $filter_columns = [
                        'action' => 2,
                        'status' => 3,
                        'actor'  => 4,
                        'source' => 5,
                    ];

                    $filter_index = 0;
                    foreach ($filter_options as $filter_key => $options) :
                        if (empty($options)) {
                            continue;
                        }

                        $filter_index++;
                        $search_id = sanitize_html_class('bokun-history-filter-' . $filter_key . '-search-' . $filter_index . '-' . uniqid());
                        $search_label = sprintf(__('Search %s', 'BOKUN_txt_domain'), $filter_labels[$filter_key]);
                    ?>
                        <div class="bokun-history-filter" data-filter-key="<?php echo esc_attr($filter_key); ?>" data-filter-column="<?php echo isset($filter_columns[$filter_key]) ? (int) $filter_columns[$filter_key] : 0; ?>">
                            <details>
                                <summary><?php echo esc_html($filter_labels[$filter_key]); ?></summary>
                                <div class="bokun-history-filter-search">
                                    <label for="<?php echo esc_attr($search_id); ?>"><?php echo esc_html($search_label); ?></label>
                                    <div class="bokun-history-filter-search-input">
                                        <input type="text" id="<?php echo esc_attr($search_id); ?>" class="bokun-history-filter-text" data-filter-text placeholder="<?php echo esc_attr($search_label); ?>" />
                                        <button type="button" class="bokun-history-filter-clear-text" data-filter-clear-text aria-label="<?php esc_attr_e('Clear search', 'BOKUN_txt_domain'); ?>">
                                            &times;
                                        </button>
                                        <button type="button" class="bokun-history-filter-paste-text" data-filter-paste-text aria-label="<?php esc_attr_e('Paste from clipboard', 'BOKUN_txt_domain'); ?>" title="<?php esc_attr_e('Paste from clipboard', 'BOKUN_txt_domain'); ?>">
                                            <span aria-hidden="true">&#128203;</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="bokun-history-filter-options">
                                    <ul>
                                        <li class="bokun-history-filter-option-all">
                                            <label>
                                                <input type="checkbox" data-filter-all checked />
                                                <span><?php esc_html_e('All', 'BOKUN_txt_domain'); ?></span>
                                            </label>
                                        </li>
                                        <?php foreach ($options as $option_value => $option_label) :
                                            $option_label_text = wp_strip_all_tags($option_label);
                                            $option_match = $option_label_text;
                                            if (function_exists('mb_strtolower')) {
                                                $option_match = mb_strtolower($option_match);
                                            } else {
                                                $option_match = strtolower($option_match);
                                            }
                                            $option_match = trim($option_match);
                                        ?>
                                            <li>
                                                <label>
                                                    <input type="checkbox" value="<?php echo esc_attr($option_value); ?>" data-filter-option data-filter-match="<?php echo esc_attr($option_match); ?>" checked />
                                                    <span><?php echo esc_html($option_label); ?></span>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
                <style>
                    .bokun-history-filters {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 12px;
                        margin: 0 0 16px;
                    }

                    .bokun-history-filter {
                        position: relative;
                        min-width: 220px;
                    }

                    .bokun-history-filter details {
                        border: 1px solid #dcdcde;
                        border-radius: 4px;
                        background: #fff;
                        transition: box-shadow 0.15s ease-in-out;
                    }

                    .bokun-history-filter details[open] {
                        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
                    }

                    .bokun-history-filter summary {
                        padding: 8px 12px;
                        cursor: pointer;
                        font-weight: 600;
                        list-style: none;
                        position: relative;
                    }

                    .bokun-history-filter summary::-webkit-details-marker {
                        display: none;
                    }

                    .bokun-history-filter summary:after {
                        content: '\25BC';
                        position: absolute;
                        right: 12px;
                        top: 50%;
                        transform: translateY(-50%);
                        font-size: 10px;
                    }

                    .bokun-history-filter details[open] summary {
                        border-bottom: 1px solid #dcdcde;
                    }

                    .bokun-history-filter details[open] summary:after {
                        content: '\25B2';
                    }

                    .bokun-history-filter-search {
                        padding: 12px 12px 0;
                    }

                    .bokun-history-filter-search label {
                        display: block;
                        font-size: 12px;
                        font-weight: 600;
                        margin: 0 0 4px;
                    }

                    .bokun-history-filter-search-input {
                        position: relative;
                    }

                    .bokun-history-filter-search input[type="text"] {
                        width: 100%;
                        border: 1px solid #dcdcde;
                        border-radius: 3px;
                        padding: 6px 52px 6px 8px;
                        font-size: 13px;
                    }

                    .bokun-history-filter-search input[type="text"]:focus {
                        border-color: #2271b1;
                        box-shadow: 0 0 0 1px rgba(34, 113, 177, 0.2);
                        outline: none;
                    }

                    .bokun-history-filter-clear-text {
                        position: absolute;
                        top: 50%;
                        right: 28px;
                        transform: translateY(-50%);
                        border: none;
                        background: transparent;
                        color: #50575e;
                        cursor: pointer;
                        padding: 0;
                        font-size: 12px;
                        line-height: 1;
                    }

                    .bokun-history-filter-clear-text:hover,
                    .bokun-history-filter-clear-text:focus {
                        color: #d63638;
                    }

                    .bokun-history-filter-paste-text {
                        position: absolute;
                        top: 50%;
                        right: 6px;
                        transform: translateY(-50%);
                        border: none;
                        background: transparent;
                        color: #50575e;
                        cursor: pointer;
                        padding: 0;
                        font-size: 13px;
                        line-height: 1;
                    }

                    .bokun-history-filter-paste-text:hover,
                    .bokun-history-filter-paste-text:focus {
                        color: #2271b1;
                    }

                    .bokun-history-search-paste {
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        margin-left: 6px;
                        padding: 4px 8px;
                        border: 1px solid #dcdcde;
                        border-radius: 3px;
                        background: #f6f7f7;
                        color: #50575e;
                        cursor: pointer;
                        font-size: 14px;
                        line-height: 1;
                        vertical-align: middle;
                    }

                    .bokun-history-search-paste:hover,
                    .bokun-history-search-paste:focus {
                        border-color: #2271b1;
                        color: #2271b1;
                        outline: none;
                    }

                    .bokun-history-filter-options {
                        max-height: 220px;
                        overflow: auto;
                        padding: 8px 12px 12px;
                    }

                    .bokun-history-filter-options ul {
                        margin: 8px 0 0;
                    }

                    .bokun-history-filter-options li {
                        list-style: none;
                        margin-bottom: 6px;
                    }

                    .bokun-history-filter-options label {
                        display: flex;
                        gap: 6px;
                        font-size: 13px;
                        cursor: pointer;
                        align-items: center;
                    }

                    .bokun-history-filter-options .bokun-history-filter-option-all {
                        border-bottom: 1px solid #f0f0f1;
                        margin-bottom: 10px;
                        padding-bottom: 6px;
                    }
                </style>
                <table class="bokun-booking-history-table display" id="<?php echo esc_attr($table_id); ?>" aria-describedby="bokun-booking-history-caption">
                    <caption id="bokun-booking-history-caption" class="screen-reader-text"><?php esc_html_e('Booking history activities', 'BOKUN_txt_domain'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Date', 'BOKUN_txt_domain'); ?></th>
                            <th scope="col"><?php esc_html_e('Booking ID', 'BOKUN_txt_domain'); ?></th>
                            <th scope="col"><?php esc_html_e('Action', 'BOKUN_txt_domain'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'BOKUN_txt_domain'); ?></th>
                            <th scope="col"><?php esc_html_e('Actor', 'BOKUN_txt_domain'); ?></th>
                            <th scope="col"><?php esc_html_e('Source', 'BOKUN_txt_domain'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processed_logs as $log) : ?>
                            <tr data-action="<?php echo esc_attr($log['action_value']); ?>" data-status="<?php echo esc_attr($log['status_value']); ?>" data-actor="<?php echo esc_attr($log['actor_value']); ?>" data-source="<?php echo esc_attr($log['source_value']); ?>">
                                <td data-title="<?php esc_attr_e('Date', 'BOKUN_txt_domain'); ?>" data-order="<?php echo esc_attr($log['sort_date']); ?>"><?php echo esc_html($log['date']); ?></td>
                                <td data-title="<?php esc_attr_e('Booking ID', 'BOKUN_txt_domain'); ?>"><?php echo wp_kses_post($log['booking_display']); ?></td>
                                <td data-title="<?php esc_attr_e('Action', 'BOKUN_txt_domain'); ?>"><?php echo esc_html($log['action_label']); ?></td>
                                <td data-title="<?php esc_attr_e('Status', 'BOKUN_txt_domain'); ?>"><?php echo esc_html($log['status_label']); ?></td>
                                <td data-title="<?php esc_attr_e('Actor', 'BOKUN_txt_domain'); ?>"><?php echo esc_html($log['actor_label']); ?></td>
                                <td data-title="<?php esc_attr_e('Source', 'BOKUN_txt_domain'); ?>"><?php echo esc_html($log['source_label']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            return ob_get_clean();
        }


        public function render_booking_dashboard($atts = []) {
            if (!post_type_exists('bokun_booking')) {
                return '';
            }

            $atts = shortcode_atts(
                [
                    'days'    => 30,
                    'columns' => 3,
                ],
                $atts,
                'bokun_booking_dashboard'
            );

            $days    = max(1, absint($atts['days']));
            $columns = max(1, min(6, absint($atts['columns'])));

            $current_user      = wp_get_current_user();
            $user_display_name = '';

            if ($current_user instanceof WP_User && $current_user->exists()) {
                $user_display_name = $current_user->display_name ?: $current_user->user_login;
            }

            if ('' === $user_display_name) {
                $user_display_name = __('Guest user', 'BOKUN_txt_domain');
            }

            $now_timestamp = current_time('timestamp');
            $range_end     = strtotime('+' . $days . ' days', $now_timestamp);

            if (false === $range_end) {
                $range_end = $now_timestamp;
            }

            $query = new WP_Query(
                [
                    'post_type'           => 'bokun_booking',
                    'post_status'         => 'publish',
                    'posts_per_page'      => -1,
                    'ignore_sticky_posts' => true,
                    'no_found_rows'       => true,
                    'orderby'             => [
                        'date' => 'ASC',
                    ],
                    'date_query'          => [
                        [
                            'column'    => 'post_date_gmt',
                            'after'     => current_time('mysql', true),
                            'before'    => gmdate('Y-m-d H:i:s', $range_end),
                            'inclusive' => true,
                        ],
                    ],
                ]
            );

            if (!$query->have_posts()) {
                $empty_message = sprintf(
                    '<div class="bokun-booking-dashboard__empty">%s</div>',
                    esc_html(
                        sprintf(
                            /* translators: %d: number of days in the dashboard range. */
                            __('No upcoming bookings were found for the next %d days.', 'BOKUN_txt_domain'),
                            $days
                        )
                    )
                );

                // Still surface the per-API fetch status so the note stays
                // visible even when there are no bookings to display.
                if (function_exists('bokun_render_fetch_status_note')) {
                    $empty_message .= bokun_render_fetch_status_note();
                }

                return $empty_message;
            }

            $color_priority_map = [
                'alarm'     => 0,
                'attention' => 1,
            ];

            if (!empty($query->posts) && is_array($query->posts)) {
                $default_priority = count($color_priority_map);

                usort(
                    $query->posts,
                    static function ($a, $b) use ($color_priority_map, $default_priority) {
                        $a_id = ($a instanceof WP_Post) ? $a->ID : (int) $a;
                        $b_id = ($b instanceof WP_Post) ? $b->ID : (int) $b;

                        $a_alarm_meta = get_post_meta($a_id, 'alarmstatus', true);
                        $b_alarm_meta = get_post_meta($b_id, 'alarmstatus', true);

                        $a_alarm = is_scalar($a_alarm_meta) ? strtolower((string) $a_alarm_meta) : '';
                        $b_alarm = is_scalar($b_alarm_meta) ? strtolower((string) $b_alarm_meta) : '';

                        $a_priority = $color_priority_map[$a_alarm] ?? $default_priority;
                        $b_priority = $color_priority_map[$b_alarm] ?? $default_priority;

                        if ($a_priority !== $b_priority) {
                            return $a_priority <=> $b_priority;
                        }

                        $a_timestamp = (int) get_post_time('U', true, $a);
                        $b_timestamp = (int) get_post_time('U', true, $b);

                        if ($a_timestamp === $b_timestamp) {
                            return 0;
                        }

                        return ($a_timestamp < $b_timestamp) ? -1 : 1;
                    }
                );

                $query->rewind_posts();
            }

            $dashboard_id = 'bokun-booking-dashboard-' . uniqid();
            $columns_style = sprintf('style="--bokun-booking-dashboard-columns: %d"', $columns);

            $tabs = [
                'closest'   => [
                    'label' => __('Closest bookings', 'BOKUN_txt_domain'),
                    'items' => [],
                ],
                'other'     => [
                    'label' => __('Other bookings', 'BOKUN_txt_domain'),
                    'items' => [],
                ],
                'cancelled' => [
                    'label' => __('Cancelled bookings', 'BOKUN_txt_domain'),
                    'items' => [],
                ],
                'all'       => [
                    'label' => __('All bookings', 'BOKUN_txt_domain'),
                    'items' => [],
                ],
            ];

            $filter_options = [
                'status' => [],
                'team'   => [],
            ];

            $status_guidance_map = [
                'booking-made' => [
                    'title'       => __('Booking made', 'BOKUN_txt_domain'),
                    'description' => __('Booking received from partner and awaiting next steps.', 'BOKUN_txt_domain'),
                ],
                'cancelled' => [
                    'title'       => __('Cancelled', 'BOKUN_txt_domain'),
                    'description' => '',
                ],
            ];

            $booking_made_cancelled_cards = [];

            $product_tags_without_partner = [];
            $processed_product_tag_ids    = [];

            // Use the same authorization predicate as the AJAX handler. This also
            // allows guests to use the composer on the public booking dashboard,
            // while hiding it from logged-in users without a staff capability.
            $user_can_send_message = function_exists('bokun_can_send_booking_message')
                ? bokun_can_send_booking_message()
                : (!is_user_logged_in() || current_user_can('edit_others_posts') || current_user_can('manage_options'));

            while ($query->have_posts()) {
                $query->the_post();

                $post_id       = get_the_ID();
                $permalink     = get_permalink($post_id);
                $post_classes  = get_post_class('bokun-booking-dashboard__card', $post_id);
                $booking_code  = get_post_meta($post_id, '_confirmation_code', true);
                $product_title = get_post_meta($post_id, '_product_title', true);
                $meeting_point = get_post_meta($post_id, 'bk_meetingpointtitle', true);
                $external_ref  = get_post_meta($post_id, '_external_booking_reference', true);
                $parent_booking_id_meta = get_post_meta($post_id, 'productBookings_0_parentBookingId', true);
                $customer_first = get_post_meta($post_id, '_first_name', true);
                $customer_last  = get_post_meta($post_id, '_last_name', true);
                $customer_email = get_post_meta($post_id, '_email', true);
                $phone_prefix   = get_post_meta($post_id, '_phone_prefix', true);
                $phone_number   = get_post_meta($post_id, '_phone_number', true);
                $created_at     = get_post_meta($post_id, 'bookingcreationdate', true);
                $inclusions     = get_post_meta($post_id, 'inclusions_clean', true);
                $alarm_status   = get_post_meta($post_id, 'alarmstatus', true);
                $vendor_title_meta = get_post_meta($post_id, 'productBookings_0_vendor_title', true);
                $rate_title_meta   = get_post_meta($post_id, 'productBookings_0_fields_rateTitle', true);
                $vendor_title      = is_scalar($vendor_title_meta) ? (string) $vendor_title_meta : '';
                $rate_title        = is_scalar($rate_title_meta) ? (string) $rate_title_meta : '';

                if ('Taste of Florence Tours' === trim($vendor_title)) {
                    $vendor_title = 'Via Florence';
                }

                $external_ref  = is_scalar($external_ref) ? (string) $external_ref : '';
                $parent_booking_id = is_scalar($parent_booking_id_meta) ? (string) $parent_booking_id_meta : '';

                $viator_url = '';
                if ($external_ref !== '') {
                    $viator_url = sprintf(
                        'https://supplier.viator.com/messaging/conversation/booking/%s',
                        rawurlencode($external_ref)
                    );
                }

                $bokun_url = '';
                if ($parent_booking_id !== '') {
                    $bokun_url = sprintf(
                        'https://florenceadventuressrl.bokun.io/sales/%s',
                        rawurlencode($parent_booking_id)
                    );
                }

                $customer_email = is_scalar($customer_email) ? trim((string) $customer_email) : '';
                $recipient_email = ('' !== $customer_email && is_email($customer_email)) ? $customer_email : '';
                $show_message_button = $user_can_send_message && ('' !== $recipient_email || '' !== $viator_url);

                $participants = [];
                foreach (range(1, 5) as $index) {
                    $value = get_post_meta($post_id, 'pricecategory' . $index, true);

                    if (!empty($value)) {
                        $participants[] = $value;
                    }
                }

                $customer_name = trim(sprintf('%s %s', $customer_first, $customer_last));
                if ('' === $customer_name) {
                    $customer_name = $customer_first ?: $customer_last;
                }

                $phone_display = trim(sprintf('%s %s', $phone_prefix, $phone_number));
                $phone_copy_value = $phone_display;
                if ('' !== $phone_copy_value) {
                    $first_space_position = strpos($phone_copy_value, ' ');
                    if (false !== $first_space_position) {
                        $phone_copy_value = trim(substr($phone_copy_value, $first_space_position + 1));
                    }
                }

                $start_timestamp = get_post_time('U', true, $post_id);

                $start_date_display = $start_timestamp ? wp_date(get_option('date_format'), $start_timestamp) : '';
                $start_time_display = $start_timestamp ? wp_date(get_option('time_format'), $start_timestamp) : '';

                $status_terms = get_the_terms($post_id, 'booking_status');
                $status_labels = [];
                $status_values = [];
                $is_cancelled = false;

                if ($status_terms && !is_wp_error($status_terms)) {
                    foreach ($status_terms as $term) {
                        $status_labels[] = $term->name;

                        $sanitized_status = sanitize_title($term->name);
                        if ('' !== $sanitized_status) {
                            $status_values[] = $sanitized_status;

                            if (!isset($filter_options['status'][$sanitized_status])) {
                                $filter_options['status'][$sanitized_status] = $term->name;
                            }
                        }

                        $term_slug = strtolower($term->slug);
                        $term_name = strtolower($term->name);
                        if (false !== strpos($term_slug, 'cancel') || false !== strpos($term_name, 'cancel')) {
                            $is_cancelled = true;
                        }
                    }
                }

                $team_terms = get_the_terms($post_id, 'team_member');
                $team_labels = [];
                $team_values = [];

                if ($team_terms && !is_wp_error($team_terms)) {
                    foreach ($team_terms as $term) {
                        $team_labels[] = $term->name;

                        $sanitized_team = sanitize_title($term->name);
                        if ('' !== $sanitized_team) {
                            $team_values[] = $sanitized_team;

                            if (!isset($filter_options['team'][$sanitized_team])) {
                                $filter_options['team'][$sanitized_team] = $term->name;
                            }
                        }
                    }
                }

                $checkbox_states = [
                    'full'           => has_term('full', 'booking_status', $post_id),
                    'partial'        => has_term('partial', 'booking_status', $post_id),
                    'not-available'  => has_term('not-available', 'booking_status', $post_id),
                    'refund-partner' => has_term('refund-requested-from-partner', 'booking_status', $post_id),
                    'refunded-partner' => has_term('refunded-by-partner', 'booking_status', $post_id),
                    'amex'           => has_term('amex', 'booking_status', $post_id),
                    'paypal'         => has_term('paypal', 'booking_status', $post_id),
                    'other'          => has_term('other-payment', 'booking_status', $post_id),
                ];

                $normalized_alarm = strtolower($alarm_status);
                $is_closest = in_array($normalized_alarm, ['alarm', 'attention'], true);

                $date_classes = ['bokun-booking-dashboard__date'];
                if ('alarm' === $normalized_alarm) {
                    $date_classes[] = 'bokun-booking-dashboard__date--alarm';
                } elseif ('attention' === $normalized_alarm) {
                    $date_classes[] = 'bokun-booking-dashboard__date--attention';
                }

                $search_fragments = array_filter(
                    [
                        get_the_title($post_id),
                        $booking_code,
                        $product_title,
                        $meeting_point,
                        $customer_name,
                        $phone_display,
                        $external_ref,
                        $created_at,
                        implode(' ', $status_labels),
                        implode(' ', $team_labels),
                        implode(' ', $participants),
                    ],
                    static function ($value) {
                        return !empty($value);
                    }
                );

                $search_text = wp_strip_all_tags(implode(' ', $search_fragments));
                if (function_exists('mb_strtolower')) {
                    $search_text = mb_strtolower($search_text);
                } else {
                    $search_text = strtolower($search_text);
                }
                $search_text = preg_replace('/\s+/', ' ', $search_text);
                if (null === $search_text) {
                    $search_text = '';
                }

                $status_attribute = implode(' ', array_unique($status_values));
                $has_booking_made_status = in_array('booking-made', $status_values, true);
                $has_cancelled_status    = in_array('cancelled', $status_values, true);
                $requires_refund_followup = ($has_booking_made_status && $has_cancelled_status);

                $status_guidance_entries = [];
                if (!empty($status_values)) {
                    foreach ($status_guidance_map as $status_key => $guidance) {
                        if (in_array($status_key, $status_values, true)) {
                            $status_guidance_entries[] = $guidance;
                        }
                    }
                }

                $team_attribute   = implode(' ', array_unique($team_values));

                $card_class_attribute = esc_attr(implode(' ', $post_classes));

                $title_copy_text  = trim(get_the_title($post_id));
                $title_copy_value = $title_copy_text;
                $title_copy_html  = '';

                $partner_page_id  = '';
                $tag_editor_link  = '';
                $partner_terms    = wp_get_post_terms($post_id, 'product_tags');
                if (!empty($partner_terms) && !is_wp_error($partner_terms)) {
                    foreach ($partner_terms as $term) {
                        $term_partner_meta = get_term_meta($term->term_id, 'partnerpageid', true);
                        $term_partner_id   = is_scalar($term_partner_meta) ? (string) $term_partner_meta : '';
                        $term_edit_link    = get_edit_term_link($term, 'product_tags');

                        if ('' === $tag_editor_link && !is_wp_error($term_edit_link) && !empty($term_edit_link)) {
                            $tag_editor_link = $term_edit_link;
                        }

                        if (!isset($processed_product_tag_ids[$term->term_id])) {
                            $processed_product_tag_ids[$term->term_id] = true;

                            if ($term_partner_id === '') {
                                $product_tags_without_partner[$term->term_id] = [
                                    'term_id'  => $term->term_id,
                                    'name'      => $term->name,
                                    'edit_link' => (!is_wp_error($term_edit_link) && !empty($term_edit_link)) ? $term_edit_link : '',
                                ];
                            }
                        }

                        if ($term_partner_id !== '') {
                            $partner_page_id = $term_partner_id;
                            break;
                        }
                    }
                }

                $partner_page_url = '';
                if (!empty($partner_page_id)) {
                    $partner_page_url = sprintf(
                        'https://extranet.ciaoflorence.it/en/tourDetails/%s',
                        rawurlencode($partner_page_id)
                    );
                }

                if (!empty($permalink)) {
                    if ('' !== $title_copy_text) {
                        $title_copy_value = sprintf('%s - %s', $title_copy_text, $permalink);
                        $title_copy_html  = sprintf(
                            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                            esc_url($permalink),
                            esc_html($title_copy_text)
                        );
                    } else {
                        $title_copy_value = $permalink;
                        $title_copy_html  = sprintf(
                            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                            esc_url($permalink),
                            esc_html($permalink)
                        );
                    }
                }

                if (null === $title_copy_value) {
                    $title_copy_value = '';
                }

                $show_refund_toggle = $requires_refund_followup;
                $vendor_class = !empty($vendor_title) && stripos($vendor_title, 'Florence Adventures') !== false
                    ? 'bokun-booking-dashboard__vendor--highlight'
                    : 'bokun-booking-dashboard__vendor--accent';
                $reserve_link_class = $vendor_class === 'bokun-booking-dashboard__vendor--highlight'
                    ? 'bokun-booking-dashboard__reserve-link bokun-booking-dashboard__reserve-link--highlight'
                    : 'bokun-booking-dashboard__reserve-link bokun-booking-dashboard__reserve-link--accent';

                ob_start();
                ?>
                <article
                    class="<?php echo $card_class_attribute; ?>"
                    data-booking-id="<?php echo esc_attr($booking_code); ?>"
                    data-search="<?php echo esc_attr($search_text); ?>"
                    data-statuses="<?php echo esc_attr($status_attribute); ?>"
                    data-teams="<?php echo esc_attr($team_attribute); ?>"
                >
                    <header class="bokun-booking-dashboard__header">
                        <div class="bokun-booking-dashboard__title-row">
                            <div class="bokun-booking-dashboard__title-main">
                                <h3 class="bokun-booking-dashboard__title">
                                    <?php if (!empty($permalink)) : ?>
                                        <a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo esc_html(get_the_title($post_id)); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html(get_the_title($post_id)); ?>
                                    <?php endif; ?>
                                </h3>
                                <?php if (!empty($title_copy_value)) : ?>
                                    <a
                                        href="#"
                                        class="bokun-booking-dashboard__copy-button"
                                        role="button"
                                        data-copy-value="<?php echo esc_attr($title_copy_value); ?>"
                                        <?php if (!empty($title_copy_html)) : ?>data-copy-html="<?php echo esc_attr($title_copy_html); ?>"<?php endif; ?>
                                        data-copy-label="<?php esc_attr_e('Copy title & link', 'BOKUN_txt_domain'); ?>"
                                        data-copy-done="<?php esc_attr_e('Copied!', 'BOKUN_txt_domain'); ?>"
                                        data-copy-error="<?php esc_attr_e('Copy failed', 'BOKUN_txt_domain'); ?>"
                                        data-copy-state="default"
                                    >
                                        <?php esc_html_e('Copy title & link', 'BOKUN_txt_domain'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($partner_page_url)) : ?>
                                <a
                                    class="<?php echo esc_attr($reserve_link_class); ?>"
                                    href="<?php echo esc_url($partner_page_url); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <?php esc_html_e('Reserve link', 'BOKUN_txt_domain'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($vendor_title)) : ?>
                            <p class="bokun-booking-dashboard__vendor <?php echo esc_attr($vendor_class); ?>">
                                <?php echo esc_html($vendor_title); ?>
                            </p>
                        <?php endif; ?>
                    </header>

                    <p class="bokun-booking-dashboard__pre-toggle-note">
                        <strong><?php esc_html_e('Double check - logged in with correct account on partner website?', 'BOKUN_txt_domain'); ?></strong>
                    </p>

                    <?php
                    $result_panel_id      = 'bokun-result-panel-' . uniqid();
                    $has_result_selection = ! empty( $checkbox_states['full'] ) || ! empty( $checkbox_states['partial'] ) || ! empty( $checkbox_states['not-available'] );
                    ?>
                    <div class="bokun-booking-dashboard__result" data-result>
                        <button type="button" class="bokun-booking-dashboard__result-toggle" data-result-toggle aria-expanded="false" aria-controls="<?php echo esc_attr($result_panel_id); ?>">
                            <span class="bokun-booking-dashboard__result-title"><?php esc_html_e('Result', 'BOKUN_txt_domain'); ?></span>
                            <span class="bokun-booking-dashboard__result-summary" data-result-summary></span>
                            <svg class="bokun-booking-dashboard__result-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="16" height="16">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                        <div class="bokun-booking-dashboard__result-panel" id="<?php echo esc_attr($result_panel_id); ?>" data-result-panel hidden>
                            <div class="bokun-booking-dashboard__toggles" role="group" aria-label="<?php esc_attr_e('Booking result', 'BOKUN_txt_domain'); ?>">
                                <div class="bokun-booking-dashboard__toggle">
                                    <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_code); ?>" data-type="full" aria-label="<?php esc_attr_e('Full', 'BOKUN_txt_domain'); ?>" <?php echo checked($checkbox_states['full'], true, false); ?> />
                                    <span><?php esc_html_e('Full', 'BOKUN_txt_domain'); ?></span>
                                </div>
                                <div class="bokun-booking-dashboard__toggle">
                                    <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_code); ?>" data-type="partial" aria-label="<?php esc_attr_e('Partial', 'BOKUN_txt_domain'); ?>" <?php echo checked($checkbox_states['partial'], true, false); ?> />
                                    <span><?php esc_html_e('Partial', 'BOKUN_txt_domain'); ?></span>
                                </div>
                                <div class="bokun-booking-dashboard__toggle">
                                    <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_code); ?>" data-type="not-available" aria-label="<?php esc_attr_e('Not available', 'BOKUN_txt_domain'); ?>" <?php echo checked($checkbox_states['not-available'], true, false); ?> />
                                    <span><?php esc_html_e('Not available', 'BOKUN_txt_domain'); ?></span>
                                </div>
                                <?php if ($show_refund_toggle) : ?>
                                    <div class="bokun-booking-dashboard__toggle">
                                        <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_code); ?>" data-type="refund-partner" aria-label="<?php esc_attr_e('Refund requested', 'BOKUN_txt_domain'); ?>" <?php echo checked($checkbox_states['refund-partner'], true, false); ?> />
                                        <span><?php esc_html_e('Refund requested', 'BOKUN_txt_domain'); ?></span>
                                    </div>
                                    <div class="bokun-booking-dashboard__toggle">
                                        <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_code); ?>" data-type="refunded-partner" aria-label="<?php esc_attr_e('Cancelled and refunded by Partner', 'BOKUN_txt_domain'); ?>" <?php echo checked($checkbox_states['refunded-partner'], true, false); ?> />
                                        <span><?php esc_html_e('Cancelled and refunded by Partner', 'BOKUN_txt_domain'); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="bokun-booking-dashboard__payment" data-payment role="group" aria-label="<?php esc_attr_e('Payment method', 'BOKUN_txt_domain'); ?>" <?php echo $has_result_selection ? '' : 'hidden'; ?>>
                                <span class="bokun-booking-dashboard__payment-label"><?php esc_html_e('Payment method:', 'BOKUN_txt_domain'); ?></span>
                                <div class="bokun-booking-dashboard__toggle">
                                    <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_code); ?>" data-type="amex" aria-label="<?php esc_attr_e('Amex', 'BOKUN_txt_domain'); ?>" <?php echo checked($checkbox_states['amex'], true, false); ?> />
                                    <span><?php esc_html_e('Amex', 'BOKUN_txt_domain'); ?></span>
                                </div>
                                <div class="bokun-booking-dashboard__toggle">
                                    <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_code); ?>" data-type="paypal" aria-label="<?php esc_attr_e('PayPal', 'BOKUN_txt_domain'); ?>" <?php echo checked($checkbox_states['paypal'], true, false); ?> />
                                    <span><?php esc_html_e('PayPal', 'BOKUN_txt_domain'); ?></span>
                                </div>
                                <div class="bokun-booking-dashboard__toggle">
                                    <input type="checkbox" class="booking-checkbox" data-booking-id="<?php echo esc_attr($booking_code); ?>" data-type="other" aria-label="<?php esc_attr_e('Other', 'BOKUN_txt_domain'); ?>" <?php echo checked($checkbox_states['other'], true, false); ?> />
                                    <span><?php esc_html_e('Other', 'BOKUN_txt_domain'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bokun-booking-dashboard__body">
                        <?php if (!empty($product_title) || !empty($rate_title) || !empty($meeting_point) || !empty($start_date_display) || !empty($start_time_display) || !empty($participants) || !empty($inclusions)) : ?>
                            <div class="bokun-booking-dashboard__column bokun-booking-dashboard__column--primary">
                                <?php if (!empty($product_title) || !empty($rate_title) || !empty($meeting_point) || !empty($start_date_display) || !empty($start_time_display)) : ?>
                                    <dl class="bokun-booking-dashboard__details">
                                        <?php if (!empty($product_title)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Product', 'BOKUN_txt_domain'); ?></dt>
                                                <dd><?php echo esc_html($product_title); ?></dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($rate_title)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Which option is booked?', 'BOKUN_txt_domain'); ?></dt>
                                                <dd><?php echo esc_html($rate_title); ?></dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($start_date_display) || !empty($start_time_display)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Start', 'BOKUN_txt_domain'); ?></dt>
                                                <dd>
                                                    <?php if (!empty($start_date_display)) : ?>
                                                        <time class="<?php echo esc_attr(implode(' ', $date_classes)); ?>" datetime="<?php echo esc_attr(wp_date('c', $start_timestamp)); ?>"><?php echo esc_html($start_date_display); ?></time>
                                                    <?php endif; ?>
                                                    <?php if (!empty($start_time_display)) : ?>
                                                        <span class="bokun-booking-dashboard__time"><?php echo esc_html($start_time_display); ?></span>
                                                    <?php endif; ?>
                                                </dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($meeting_point)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Meeting point', 'BOKUN_txt_domain'); ?></dt>
                                                <dd><?php echo esc_html($meeting_point); ?></dd>
                                            </div>
                                        <?php endif; ?>
                                    </dl>
                                <?php endif; ?>

                                <?php if (!empty($participants)) : ?>
                                    <div class="bokun-booking-dashboard__section bokun-booking-dashboard__section--participants">
                                        <div class="bokun-booking-dashboard__participants" role="group" aria-label="<?php esc_attr_e('Participants', 'BOKUN_txt_domain'); ?>">
                                            <span class="bokun-booking-dashboard__participants-label"><?php esc_html_e('Participants', 'BOKUN_txt_domain'); ?>:</span>
                                            <span class="bokun-booking-dashboard__participants-value"><?php echo esc_html(implode(' • ', $participants)); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($inclusions)) : ?>
                                    <div class="bokun-booking-dashboard__inclusions">
                                        <h4 class="bokun-booking-dashboard__section-title"><?php esc_html_e('Inclusions & notes', 'BOKUN_txt_domain'); ?></h4>
                                        <p><?php echo wp_kses_post(nl2br(esc_html($inclusions))); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($customer_name) || !empty($customer_first) || !empty($customer_last) || !empty($phone_display) || !empty($external_ref) || !empty($created_at) || !empty($team_labels) || !empty($viator_url) || !empty($bokun_url)) : ?>
                            <div class="bokun-booking-dashboard__column bokun-booking-dashboard__column--secondary">
                                <?php if (!empty($customer_name) || !empty($customer_first) || !empty($customer_last) || !empty($phone_display) || !empty($external_ref) || !empty($created_at) || !empty($team_labels)) : ?>
                                    <dl class="bokun-booking-dashboard__details">
                                        <?php if (!empty($customer_name)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Lead traveller', 'BOKUN_txt_domain'); ?></dt>
                                                <dd class="bokun-booking-dashboard__detail-copy">
                                                    <span class="bokun-booking-dashboard__detail-value"><?php echo esc_html($customer_name); ?></span>
                                                    <a
                                                        href="#"
                                                        class="bokun-booking-dashboard__copy-button"
                                                        role="button"
                                                        data-copy-value="<?php echo esc_attr($customer_name); ?>"
                                                        data-copy-label="<?php esc_attr_e('Copy', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-done="<?php esc_attr_e('Copied!', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-error="<?php esc_attr_e('Copy failed', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-state="default"
                                                    >
                                                        <?php esc_html_e('Copy', 'BOKUN_txt_domain'); ?>
                                                    </a>
                                                </dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($customer_first)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('First name', 'BOKUN_txt_domain'); ?></dt>
                                                <dd class="bokun-booking-dashboard__detail-copy">
                                                    <span class="bokun-booking-dashboard__detail-value"><?php echo esc_html($customer_first); ?></span>
                                                    <a
                                                        href="#"
                                                        class="bokun-booking-dashboard__copy-button"
                                                        role="button"
                                                        data-copy-value="<?php echo esc_attr($customer_first); ?>"
                                                        data-copy-label="<?php esc_attr_e('Copy', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-done="<?php esc_attr_e('Copied!', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-error="<?php esc_attr_e('Copy failed', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-state="default"
                                                    >
                                                        <?php esc_html_e('Copy', 'BOKUN_txt_domain'); ?>
                                                    </a>
                                                </dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($customer_last)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Last name', 'BOKUN_txt_domain'); ?></dt>
                                                <dd class="bokun-booking-dashboard__detail-copy">
                                                    <span class="bokun-booking-dashboard__detail-value"><?php echo esc_html($customer_last); ?></span>
                                                    <a
                                                        href="#"
                                                        class="bokun-booking-dashboard__copy-button"
                                                        role="button"
                                                        data-copy-value="<?php echo esc_attr($customer_last); ?>"
                                                        data-copy-label="<?php esc_attr_e('Copy', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-done="<?php esc_attr_e('Copied!', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-error="<?php esc_attr_e('Copy failed', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-state="default"
                                                    >
                                                        <?php esc_html_e('Copy', 'BOKUN_txt_domain'); ?>
                                                    </a>
                                                </dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($phone_display)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Phone', 'BOKUN_txt_domain'); ?></dt>
                                                <dd class="bokun-booking-dashboard__detail-copy">
                                                    <span class="bokun-booking-dashboard__detail-value"><?php echo esc_html($phone_display); ?></span>
                                                    <a
                                                        href="#"
                                                        class="bokun-booking-dashboard__copy-button"
                                                        role="button"
                                                        data-copy-value="<?php echo esc_attr($phone_copy_value); ?>"
                                                        data-copy-label="<?php esc_attr_e('Copy', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-done="<?php esc_attr_e('Copied!', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-error="<?php esc_attr_e('Copy failed', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-state="default"
                                                    >
                                                        <?php esc_html_e('Copy', 'BOKUN_txt_domain'); ?>
                                                    </a>
                                                </dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($external_ref)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Reference for booking:', 'BOKUN_txt_domain'); ?></dt>
                                                <dd class="bokun-booking-dashboard__detail-copy">
                                                    <span class="bokun-booking-dashboard__detail-value"><?php echo esc_html($external_ref); ?></span>
                                                    <a
                                                        href="#"
                                                        class="bokun-booking-dashboard__copy-button"
                                                        role="button"
                                                        data-copy-value="<?php echo esc_attr($external_ref); ?>"
                                                        data-copy-label="<?php esc_attr_e('Copy', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-done="<?php esc_attr_e('Copied!', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-error="<?php esc_attr_e('Copy failed', 'BOKUN_txt_domain'); ?>"
                                                        data-copy-state="default"
                                                    >
                                                        <?php esc_html_e('Copy', 'BOKUN_txt_domain'); ?>
                                                    </a>
                                                </dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($created_at)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Created', 'BOKUN_txt_domain'); ?></dt>
                                                <dd><?php echo esc_html($created_at); ?></dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($team_labels)) : ?>
                                            <div class="bokun-booking-dashboard__detail">
                                                <dt><?php esc_html_e('Team members', 'BOKUN_txt_domain'); ?></dt>
                                                <dd><?php echo esc_html(implode(', ', $team_labels)); ?></dd>
                                            </div>
                                        <?php endif; ?>
                                    </dl>
                                <?php endif; ?>

                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($status_labels) || !empty($tag_editor_link) || !empty($viator_url) || !empty($bokun_url) || $show_message_button) : ?>
                        <div class="bokun-booking-dashboard__meta-line">
                            <?php if ($show_message_button) : ?>
                                <button
                                    type="button"
                                    class="bokun-booking-dashboard__message-button"
                                    data-dashboard-message-button
                                    data-message-booking="<?php echo esc_attr($booking_code); ?>"
                                    data-message-email="<?php echo esc_attr($recipient_email); ?>"
                                    data-message-name="<?php echo esc_attr($customer_first); ?>"
                                    data-message-date="<?php echo esc_attr($start_date_display); ?>"
                                    data-message-product="<?php echo esc_attr($product_title); ?>"
                                    data-message-reference="<?php echo esc_attr($external_ref); ?>"
                                    data-message-viator="<?php echo esc_url($viator_url); ?>"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
                                    </svg>
                                    <span><?php esc_html_e('Message client', 'BOKUN_txt_domain'); ?></span>
                                </button>
                            <?php endif; ?>

                            <?php if (!empty($status_labels)) : ?>
                                <ul class="bokun-booking-dashboard__status-list">
                                    <?php foreach ($status_labels as $status_label) : ?>
                                        <li class="bokun-booking-dashboard__status-item"><?php echo esc_html($status_label); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if (!empty($tag_editor_link)) : ?>
                                <a href="<?php echo esc_url($tag_editor_link); ?>" target="_blank" rel="noopener noreferrer" class="bokun-booking-dashboard__reference-link bokun-booking-dashboard__tag-editor-link">
                                    <?php esc_html_e('Tag Editor', 'BOKUN_txt_domain'); ?>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($viator_url) || !empty($bokun_url)) : ?>
                                <div class="bokun-booking-dashboard__reference-links">
                                    <?php if (!empty($viator_url)) : ?>
                                        <a href="<?php echo esc_url($viator_url); ?>" target="_blank" rel="noopener noreferrer" class="bokun-booking-dashboard__reference-link">
                                            <?php esc_html_e('Viator link', 'BOKUN_txt_domain'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($bokun_url)) : ?>
                                        <a href="<?php echo esc_url($bokun_url); ?>" target="_blank" rel="noopener noreferrer" class="bokun-booking-dashboard__reference-link">
                                            <?php esc_html_e('Bokun link', 'BOKUN_txt_domain'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </article>
                <?php
                $card_html = ob_get_clean();

                $tabs['all']['items'][] = $card_html;

                if ($is_cancelled) {
                    $tabs['cancelled']['items'][] = $card_html;
                }

                if ($is_closest && !$is_cancelled) {
                    $tabs['closest']['items'][] = $card_html;
                }

                if (!$is_closest && !$is_cancelled) {
                    $tabs['other']['items'][] = $card_html;
                }

                // Once the partner has refunded a cancelled booking it is fully
                // resolved, so drop it from the follow-up list (it still appears
                // in the Cancelled tab, where the refund can be un-ticked).
                if ($requires_refund_followup && empty($checkbox_states['refunded-partner'])) {
                    $booking_made_cancelled_cards[] = $card_html;
                }
            }

            if (!empty($product_tags_without_partner)) {
                uasort(
                    $product_tags_without_partner,
                    static function ($a, $b) {
                        return strcasecmp($a['name'], $b['name']);
                    }
                );

                $partner_tag_sources = [];
                $all_product_tags = get_terms(
                    [
                        'taxonomy'   => 'product_tags',
                        'hide_empty' => false,
                    ]
                );

                if (!is_wp_error($all_product_tags)) {
                    foreach ($all_product_tags as $source_term) {
                        $source_partner_id = get_term_meta($source_term->term_id, 'partnerpageid', true);
                        $source_partner_id = is_scalar($source_partner_id) ? trim((string) $source_partner_id) : '';

                        if ('' === $source_partner_id) {
                            continue;
                        }

                        $partner_tag_sources[] = [
                            'term_id'         => (int) $source_term->term_id,
                            'name'            => $source_term->name,
                            'partner_page_id' => $source_partner_id,
                        ];
                    }
                }

                foreach ($product_tags_without_partner as &$missing_term_data) {
                    $missing_term_data['suggestions'] = $this->get_partner_tag_suggestions(
                        $missing_term_data['name'],
                        $partner_tag_sources
                    );
                }
                unset($missing_term_data);
            }

            wp_reset_postdata();

            foreach ($filter_options as $key => $options) {
                if (!empty($options)) {
                    natcasesort($options);
                    $filter_options[$key] = $options;
                }
            }

            $active_tab = 'closest';
            foreach ($tabs as $key => $tab) {
                if (!empty($tab['items'])) {
                    $active_tab = $key;
                    break;
                }
            }

            $should_render_progress = !defined('BOKUN_PROGRESS_RENDERED');

            if ($should_render_progress) {
                define('BOKUN_PROGRESS_RENDERED', true);
            }

            $dual_status_count   = count($booking_made_cancelled_cards);
            $history_markup      = $this->render_booking_history_table([
                'limit'      => 150,
                'capability' => '',
                'export'     => 'dashboard-history',
            ]);
            $dual_status_section_id = $dashboard_id . '-dual-status';
            $dual_status_panel_id   = $dashboard_id . '-dual-status-panel';
            $dual_status_toggle_id  = $dashboard_id . '-dual-status-toggle';
            $history_overlay_id     = $dashboard_id . '-history-overlay';
            $history_dialog_id      = $dashboard_id . '-history-dialog';
            $history_title_id       = $dashboard_id . '-history-title';

            ob_start();
            ?>
            <div id="<?php echo esc_attr($dashboard_id); ?>" class="bokun-booking-dashboard" <?php echo $columns_style; ?>>
                <div class="bokun-booking-dashboard__toolbar">
                    <div class="bokun-booking-dashboard__toolbar-group">
                        <h2 class="bokun-booking-dashboard__toolbar-title">
                            <?php esc_html_e('Bookings Dashboard', 'BOKUN_txt_domain'); ?>
                        </h2>
                    </div>
                    <div class="bokun-booking-dashboard__toolbar-group bokun-booking-dashboard__toolbar-group--right">
                        <a href="#" class="bokun-booking-dashboard__toolbar-link bokun_fetch_booking_data_front" role="button">
                            <?php esc_html_e('Fetch bookings', 'BOKUN_txt_domain'); ?>
                        </a>
                    </div>
                </div>
                <?php if ($should_render_progress) : ?>
                    <div id="bokun_progress" class="bokun-progress" style="display:none;" role="status" aria-live="polite">
                        <div class="bokun-progress__header">
                            <span id="bokun_progress_message" class="bokun-progress__message">Import progress</span>
                            <span class="bokun-progress__status">
                                <span id="bokun_progress_value" class="bokun-progress__value">0%</span>
                                <img id="bokun_progress_spinner" class="bokun-progress__spinner" src="<?= BOKUN_IMAGES_URL.'ajax-loading.gif'; ?>" alt="Loading" width="18" height="18">
                            </span>
                        </div>
                        <div class="bokun-progress__track" aria-hidden="true">
                            <div id="bokun_progress_bar" class="bokun-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="bokun-booking-dashboard__controls" data-dashboard-controls>
                    <div class="bokun-booking-dashboard__search">
                        <?php
                        $search_input_id = $dashboard_id . '-search';
                        $search_label    = __('Search bookings', 'BOKUN_txt_domain');
                        ?>
                        <label for="<?php echo esc_attr($search_input_id); ?>"><?php echo esc_html($search_label); ?></label>
                        <div class="bokun-booking-dashboard__search-field">
                            <span class="bokun-booking-dashboard__search-control">
                                <input
                                    type="search"
                                    id="<?php echo esc_attr($search_input_id); ?>"
                                    class="bokun-booking-dashboard__search-input"
                                    placeholder="<?php echo esc_attr($search_label); ?>"
                                    data-dashboard-search
                                />
                                <a
                                    href="#"
                                    class="bokun-booking-dashboard__search-clear"
                                    data-dashboard-search-clear
                                    role="button"
                                    aria-label="<?php esc_attr_e('Clear search', 'BOKUN_txt_domain'); ?>"
                                    hidden
                                >
                                    &times;
                                </a>
                            </span>
                            <button
                                type="button"
                                class="bokun-booking-dashboard__search-paste"
                                data-dashboard-search-paste
                                aria-label="<?php esc_attr_e('Paste from clipboard', 'BOKUN_txt_domain'); ?>"
                                title="<?php esc_attr_e('Paste from clipboard', 'BOKUN_txt_domain'); ?>"
                            >
                                <span aria-hidden="true">&#128203;</span>
                            </button>
                        </div>
                    </div>

                    <?php if (!empty($filter_options['status'])) : ?>
                        <div class="bokun-booking-dashboard__filter" data-filter-group="status">
                            <details class="bokun-booking-dashboard__filter-dropdown">
                                <summary><?php esc_html_e('Filter by status', 'BOKUN_txt_domain'); ?></summary>
                                <div class="bokun-booking-dashboard__filter-menu" role="group" aria-label="<?php esc_attr_e('Filter by status', 'BOKUN_txt_domain'); ?>">
                                    <div class="bokun-booking-dashboard__filter-options">
                                        <label class="bokun-booking-dashboard__filter-option bokun-booking-dashboard__filter-option--all">
                                            <input type="checkbox" data-filter-status-all checked />
                                            <span><?php esc_html_e('All', 'BOKUN_txt_domain'); ?></span>
                                        </label>
                                        <?php
                                        $status_index = 0;
                                        foreach ($filter_options['status'] as $value => $label) :
                                            $status_index++;
                                            $input_id = $dashboard_id . '-status-' . $status_index;
                                            ?>
                                            <label class="bokun-booking-dashboard__filter-option" for="<?php echo esc_attr($input_id); ?>">
                                                <input
                                                    type="checkbox"
                                                    id="<?php echo esc_attr($input_id); ?>"
                                                    value="<?php echo esc_attr($value); ?>"
                                                    checked
                                                    data-filter-status
                                                />
                                                <span><?php echo esc_html($label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($filter_options['team'])) : ?>
                        <div class="bokun-booking-dashboard__filter" data-filter-group="team">
                            <details class="bokun-booking-dashboard__filter-dropdown">
                                <summary><?php esc_html_e('Filter by team member', 'BOKUN_txt_domain'); ?></summary>
                                <div class="bokun-booking-dashboard__filter-menu" role="group" aria-label="<?php esc_attr_e('Filter by team member', 'BOKUN_txt_domain'); ?>">
                                    <div class="bokun-booking-dashboard__filter-options">
                                        <label class="bokun-booking-dashboard__filter-option bokun-booking-dashboard__filter-option--all">
                                            <input type="checkbox" data-filter-team-all checked />
                                            <span><?php esc_html_e('All', 'BOKUN_txt_domain'); ?></span>
                                        </label>
                                        <?php
                                        $team_index = 0;
                                        foreach ($filter_options['team'] as $value => $label) :
                                            $team_index++;
                                            $input_id = $dashboard_id . '-team-' . $team_index;
                                            ?>
                                            <label class="bokun-booking-dashboard__filter-option" for="<?php echo esc_attr($input_id); ?>">
                                                <input
                                                    type="checkbox"
                                                    id="<?php echo esc_attr($input_id); ?>"
                                                    value="<?php echo esc_attr($value); ?>"
                                                    checked
                                                    data-filter-team
                                                />
                                                <span><?php echo esc_html($label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bokun-booking-dashboard__tabs" role="tablist" aria-label="<?php esc_attr_e('Booking groups', 'BOKUN_txt_domain'); ?>">
                    <?php foreach ($tabs as $key => $tab) :
                        $tab_id    = $dashboard_id . '-tab-' . $key;
                        $panel_id  = $dashboard_id . '-panel-' . $key;
                        $is_active = ($key === $active_tab);
                        $count     = count($tab['items']);
                        ?>
                        <a
                            href="#"
                            class="bokun-booking-dashboard__tab"
                            id="<?php echo esc_attr($tab_id); ?>"
                            role="tab"
                            data-target="<?php echo esc_attr($panel_id); ?>"
                            aria-controls="<?php echo esc_attr($panel_id); ?>"
                            aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                            <?php echo $is_active ? '' : 'tabindex="-1"'; ?>
                        >
                            <span class="bokun-booking-dashboard__tab-label"><?php echo esc_html($tab['label']); ?></span>
                            <span class="bokun-booking-dashboard__tab-count"><?php echo esc_html($count); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="bokun-booking-dashboard__panels">
                    <?php foreach ($tabs as $key => $tab) :
                        $panel_id  = $dashboard_id . '-panel-' . $key;
                        $tab_id    = $dashboard_id . '-tab-' . $key;
                        $is_active = ($key === $active_tab);
                        ?>
                        <div
                            id="<?php echo esc_attr($panel_id); ?>"
                            class="bokun-booking-dashboard__panel"
                            role="tabpanel"
                            aria-labelledby="<?php echo esc_attr($tab_id); ?>"
                            <?php echo $is_active ? '' : 'hidden'; ?>
                        >
                            <?php if (!empty($tab['items'])) : ?>
                                <div class="bokun-booking-dashboard__grid">
                                    <?php echo implode('', $tab['items']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </div>
                            <?php else : ?>
                                <p class="bokun-booking-dashboard__empty" role="status"><?php esc_html_e('No bookings available in this group.', 'BOKUN_txt_domain'); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($dual_status_count > 0) :
                    $dual_status_show_label = __('Show bookings', 'BOKUN_txt_domain');
                    $dual_status_hide_label = __('Hide bookings', 'BOKUN_txt_domain');
                    ?>
                    <section
                        id="<?php echo esc_attr($dual_status_section_id); ?>"
                        class="bokun-booking-dashboard__dual-status"
                        aria-labelledby="<?php echo esc_attr($dual_status_section_id); ?>-title"
                        data-dashboard-dual-status
                    >
                        <div class="bokun-booking-dashboard__dual-status-header">
                            <div class="bokun-booking-dashboard__dual-status-heading">
                                <h3 id="<?php echo esc_attr($dual_status_section_id); ?>-title" class="bokun-booking-dashboard__dual-status-title">
                                    <?php esc_html_e('Bookings tagged “Booking made” and “Cancelled”', 'BOKUN_txt_domain'); ?>
                                </h3>
                                <span class="bokun-booking-dashboard__dual-status-count" aria-label="<?php esc_attr_e('Total bookings with both statuses', 'BOKUN_txt_domain'); ?>">
                                    <?php echo esc_html(number_format_i18n($dual_status_count)); ?>
                                </span>
                            </div>
                            <a
                                href="#"
                                class="bokun-booking-dashboard__dual-status-toggle"
                                id="<?php echo esc_attr($dual_status_toggle_id); ?>"
                                data-dashboard-dual-status-toggle
                                data-show-label="<?php echo esc_attr($dual_status_show_label); ?>"
                                data-hide-label="<?php echo esc_attr($dual_status_hide_label); ?>"
                                role="button"
                                aria-expanded="false"
                                aria-controls="<?php echo esc_attr($dual_status_panel_id); ?>"
                            >
                                <?php echo esc_html($dual_status_show_label); ?>
                            </a>
                        </div>
                        <p class="bokun-booking-dashboard__dual-status-description">
                            <?php esc_html_e('Use this list to confirm partner cancellations and refunds are fully resolved.', 'BOKUN_txt_domain'); ?>
                        </p>
                        <div
                            id="<?php echo esc_attr($dual_status_panel_id); ?>"
                            class="bokun-booking-dashboard__dual-status-grid"
                            data-dashboard-dual-status-panel
                            hidden
                        >
                            <?php foreach ($booking_made_cancelled_cards as $card_html) : ?>
                                <?php echo $card_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <?php
                $meeting_point_map_url = 'https://maps.app.goo.gl/P6R5T7zeYNbSYR9YA';
                ?>
                <?php
                $message_greeting = __('Hello, we hope this message finds you well.', 'BOKUN_txt_domain');
                $message_signoff  = __('Best regards', 'BOKUN_txt_domain');

                $message_templates = [
                    'meeting-point' => [
                        'label'   => __('Meeting point', 'BOKUN_txt_domain'),
                        'subject' => __('Meeting point for your tour', 'BOKUN_txt_domain'),
                        'body'    => implode("\n", [
                            $message_greeting,
                            '',
                            __('Here is the exact meeting point for your tour:', 'BOKUN_txt_domain'),
                            '',
                            __('Piazzale Montelungo', 'BOKUN_txt_domain'),
                            $meeting_point_map_url,
                            '',
                            __('You will find us in fuchsia shirts.', 'BOKUN_txt_domain'),
                            __('Please make sure to be there at least 15 minutes before the tour starts.', 'BOKUN_txt_domain'),
                            '',
                            $message_signoff,
                        ]),
                    ],
                    'alternative-date' => [
                        'label'   => __('Alternative date', 'BOKUN_txt_domain'),
                        'subject' => __('Alternative date for your booking', 'BOKUN_txt_domain'),
                        'body'    => implode("\n", [
                            $message_greeting,
                            '',
                            __('Unfortunately this tour is not available for Day and Month. We apologize for any inconvenience caused by this. The first available date will be Day and Month.', 'BOKUN_txt_domain'),
                            '',
                            __('Let us know if that works for you. If not, we have to cancel the reservation with a full refund.', 'BOKUN_txt_domain'),
                            '',
                            __('Thank you for understanding and patience.', 'BOKUN_txt_domain'),
                            '',
                            $message_signoff,
                        ]),
                    ],
                    'tour-not-available' => [
                        'label'   => __('Tour not available', 'BOKUN_txt_domain'),
                        'subject' => __('Update about your booking', 'BOKUN_txt_domain'),
                        'body'    => implode("\n", [
                            $message_greeting,
                            '',
                            __('Unfortunately this tour is not available for Day and Month. We apologize for the inconvenience but we have to cancel the reservation with a full refund.', 'BOKUN_txt_domain'),
                            '',
                            __('Thank you for understanding and patience.', 'BOKUN_txt_domain'),
                            '',
                            $message_signoff,
                        ]),
                    ],
                    'custom' => [
                        'label'   => __('Custom message', 'BOKUN_txt_domain'),
                        'subject' => '',
                        'body'    => '',
                    ],
                ];

                $message_dialog_id  = $dashboard_id . '-message-dialog';
                $message_title_id   = $dashboard_id . '-message-title';
                $message_type_id    = $dashboard_id . '-message-type';
                $message_subject_id = $dashboard_id . '-message-subject';
                $message_body_id    = $dashboard_id . '-message-body';
                ?>
                <div
                    class="bokun-booking-dashboard__message-overlay"
                    data-dashboard-message-overlay
                    hidden
                    aria-hidden="true"
                ></div>
                <div
                    class="bokun-booking-dashboard__message"
                    id="<?php echo esc_attr($message_dialog_id); ?>"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="<?php echo esc_attr($message_title_id); ?>"
                    data-dashboard-message
                    data-message-templates="<?php echo esc_attr(wp_json_encode($message_templates)); ?>"
                    data-text-placeholder-date="<?php esc_attr_e('Day and Month', 'BOKUN_txt_domain'); ?>"
                    data-text-sending="<?php esc_attr_e('Sending…', 'BOKUN_txt_domain'); ?>"
                    data-text-sent="<?php esc_attr_e('Message sent.', 'BOKUN_txt_domain'); ?>"
                    data-text-error="<?php esc_attr_e('The message could not be sent. Please try again.', 'BOKUN_txt_domain'); ?>"
                    data-text-empty="<?php esc_attr_e('Please write a message before sending.', 'BOKUN_txt_domain'); ?>"
                    data-text-no-email="<?php esc_attr_e('No contact email is stored for this booking. Use the Viator conversation link instead.', 'BOKUN_txt_domain'); ?>"
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <div class="bokun-booking-dashboard__message-header">
                        <h3 id="<?php echo esc_attr($message_title_id); ?>"><?php esc_html_e('Message client', 'BOKUN_txt_domain'); ?></h3>
                        <button type="button" class="bokun-booking-dashboard__message-close" data-dashboard-message-close aria-label="<?php esc_attr_e('Close message composer', 'BOKUN_txt_domain'); ?>">
                            &times;
                        </button>
                    </div>
                    <div class="bokun-booking-dashboard__message-body">
                        <p class="bokun-booking-dashboard__message-recipient">
                            <span class="bokun-booking-dashboard__message-recipient-label"><?php esc_html_e('To:', 'BOKUN_txt_domain'); ?></span>
                            <span class="bokun-booking-dashboard__message-recipient-value" data-dashboard-message-recipient></span>
                        </p>
                        <p class="bokun-booking-dashboard__message-no-email" data-dashboard-message-no-email hidden role="alert"></p>
                        <div class="bokun-booking-dashboard__message-field">
                            <label for="<?php echo esc_attr($message_type_id); ?>"><?php esc_html_e('Type of message', 'BOKUN_txt_domain'); ?></label>
                            <select id="<?php echo esc_attr($message_type_id); ?>" class="bokun-booking-dashboard__message-select" data-dashboard-message-type>
                                <?php foreach ($message_templates as $template_key => $template) : ?>
                                    <option value="<?php echo esc_attr($template_key); ?>"><?php echo esc_html($template['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="bokun-booking-dashboard__message-field">
                            <label for="<?php echo esc_attr($message_subject_id); ?>"><?php esc_html_e('Subject', 'BOKUN_txt_domain'); ?></label>
                            <input type="text" id="<?php echo esc_attr($message_subject_id); ?>" class="bokun-booking-dashboard__message-subject" data-dashboard-message-subject />
                        </div>
                        <div class="bokun-booking-dashboard__message-field">
                            <label for="<?php echo esc_attr($message_body_id); ?>"><?php esc_html_e('Draft of message', 'BOKUN_txt_domain'); ?></label>
                            <textarea id="<?php echo esc_attr($message_body_id); ?>" class="bokun-booking-dashboard__message-textarea" rows="12" data-dashboard-message-textarea></textarea>
                        </div>
                    </div>
                    <div class="bokun-booking-dashboard__message-actions">
                        <span class="bokun-booking-dashboard__message-status" data-dashboard-message-status role="status" aria-live="polite"></span>
                        <a href="#" class="bokun-booking-dashboard__reference-link bokun-booking-dashboard__message-viator" data-dashboard-message-viator target="_blank" rel="noopener noreferrer" hidden>
                            <?php esc_html_e('Open Viator conversation', 'BOKUN_txt_domain'); ?>
                        </a>
                        <button type="button" class="bokun-booking-dashboard__message-send" data-dashboard-message-send>
                            <?php esc_html_e('Send message', 'BOKUN_txt_domain'); ?>
                        </button>
                    </div>
                </div>

                <div
                    class="bokun-booking-dashboard__history-overlay"
                    id="<?php echo esc_attr($history_overlay_id); ?>"
                    data-dashboard-history-overlay
                    hidden
                    aria-hidden="true"
                ></div>
                <div
                    class="bokun-booking-dashboard__history"
                    id="<?php echo esc_attr($history_dialog_id); ?>"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="<?php echo esc_attr($history_title_id); ?>"
                    data-dashboard-history
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <div class="bokun-booking-dashboard__history-header">
                        <h3 id="<?php echo esc_attr($history_title_id); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" width="20" height="20">
                                <path d="M3 3v5h5"></path>
                                <path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"></path>
                                <path d="M12 7v5l3 2"></path>
                            </svg>
                            <?php esc_html_e('Booking history', 'BOKUN_txt_domain'); ?>
                        </h3>
                        <button type="button" class="bokun-booking-dashboard__history-close" data-dashboard-history-close aria-label="<?php esc_attr_e('Close booking history', 'BOKUN_txt_domain'); ?>">
                            &times;
                        </button>
                    </div>
                    <div class="bokun-booking-dashboard__history-body">
                        <?php if (!empty($history_markup)) : ?>
                            <?php echo $history_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php else : ?>
                            <p class="bokun-booking-dashboard__history-empty" role="status">
                                <?php esc_html_e('No booking history is available yet.', 'BOKUN_txt_domain'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bokun-booking-dashboard__footer">
                    <div class="bokun-booking-dashboard__footer-item" data-dashboard-user-indicator>
                        <span class="bokun-booking-dashboard__footer-label"><?php esc_html_e('Current user', 'BOKUN_txt_domain'); ?>:</span>
                        <span class="bokun-booking-dashboard__footer-value"><?php echo esc_html($user_display_name); ?></span>
                    </div>
                    <div class="bokun-booking-dashboard__footer-item bokun-booking-dashboard__footer-item--history">
                        <a
                            href="#"
                            data-dashboard-history-open
                            role="button"
                            aria-haspopup="dialog"
                            aria-expanded="false"
                            aria-controls="<?php echo esc_attr($history_dialog_id); ?>"
                        >
                            <?php esc_html_e('Booking history', 'BOKUN_txt_domain'); ?>
                        </a>
                    </div>
                </div>

                <?php
                if (function_exists('bokun_render_fetch_status_note')) {
                    echo bokun_render_fetch_status_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is pre-escaped in the helper.
                }
                ?>

                <?php if (!empty($product_tags_without_partner)) : ?>
                    <div class="bokun-booking-dashboard__missing-tags">
                        <h3><?php esc_html_e('Product tags without link to partner website:', 'BOKUN_txt_domain'); ?></h3>
                        <ul class="bokun-booking-dashboard__missing-tags-list">
                            <?php foreach ($product_tags_without_partner as $term_data) :
                                $term_id = isset($term_data['term_id']) ? (int) $term_data['term_id'] : 0;
                                $input_id = $term_id > 0 ? sprintf('partner-page-id-%d', $term_id) : uniqid('partner-page-id-');
                            ?>
                                <li class="bokun-booking-dashboard__missing-tags-item" data-partner-tag-item>
                                    <span class="bokun-booking-dashboard__missing-tag-name"><?php echo esc_html($term_data['name']); ?></span>
                                    <div class="bokun-booking-dashboard__missing-tag-actions">
                                        <?php if (!empty($term_data['edit_link'])) : ?>
                                            <a href="<?php echo esc_url($term_data['edit_link']); ?>" target="_blank" rel="noopener noreferrer" class="bokun-booking-dashboard__missing-tag-link">
                                                <?php esc_html_e('Edit tag', 'BOKUN_txt_domain'); ?>
                                            </a>
                                        <?php endif; ?>
                                        <form class="bokun-booking-dashboard__missing-tag-form" data-partner-tag-form data-term-id="<?php echo esc_attr($term_id); ?>" data-dashboard-days="<?php echo esc_attr($days); ?>">
                                            <?php
                                            $suggestion_groups = isset($term_data['suggestions']) && is_array($term_data['suggestions'])
                                                ? $term_data['suggestions']
                                                : [];
                                            $has_suggestions = !empty($suggestion_groups['exact']) || !empty($suggestion_groups['similar']) || !empty($suggestion_groups['other']);
                                            ?>
                                            <?php if ($has_suggestions) : ?>
                                                <label class="screen-reader-text" for="<?php echo esc_attr($input_id . '-source'); ?>"><?php esc_html_e('Copy Partner Page ID from an existing product tag', 'BOKUN_txt_domain'); ?></label>
                                                <select
                                                    id="<?php echo esc_attr($input_id . '-source'); ?>"
                                                    class="bokun-booking-dashboard__missing-tag-source"
                                                    data-partner-tag-source
                                                >
                                                    <option value=""><?php esc_html_e('Choose an existing tag…', 'BOKUN_txt_domain'); ?></option>
                                                    <?php
                                                    $group_labels = [
                                                        'exact'   => __('Exact title matches', 'BOKUN_txt_domain'),
                                                        'similar' => __('Most similar titles', 'BOKUN_txt_domain'),
                                                        'other'   => __('All other tags', 'BOKUN_txt_domain'),
                                                    ];
                                                    foreach ($group_labels as $group_key => $group_label) :
                                                        if (empty($suggestion_groups[$group_key])) {
                                                            continue;
                                                        }
                                                    ?>
                                                        <optgroup label="<?php echo esc_attr($group_label); ?>">
                                                            <?php foreach ($suggestion_groups[$group_key] as $suggestion) : ?>
                                                                <option
                                                                    value="<?php echo esc_attr($suggestion['partner_page_id']); ?>"
                                                                    data-source-term-id="<?php echo esc_attr($suggestion['term_id']); ?>"
                                                                ><?php echo esc_html(sprintf('%1$s — ID %2$s', $suggestion['name'], $suggestion['partner_page_id'])); ?></option>
                                                            <?php endforeach; ?>
                                                        </optgroup>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif; ?>
                                            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php esc_html_e('Partner Page ID', 'BOKUN_txt_domain'); ?></label>
                                            <input
                                                type="text"
                                                id="<?php echo esc_attr($input_id); ?>"
                                                name="partner_page_id"
                                                class="bokun-booking-dashboard__missing-tag-input"
                                                placeholder="<?php esc_attr_e('Enter Partner Page ID', 'BOKUN_txt_domain'); ?>"
                                                data-partner-page-input
                                            >
                                            <button type="submit" class="bokun-booking-dashboard__missing-tag-save" data-partner-page-submit>
                                                <?php esc_html_e('Save', 'BOKUN_txt_domain'); ?>
                                            </button>
                                            <span
                                                class="bokun-booking-dashboard__missing-tag-feedback"
                                                data-partner-page-feedback
                                                role="status"
                                                aria-live="polite"
                                                aria-hidden="true"
                                                hidden
                                            ></span>
                                            <?php if ($has_suggestions) : ?>
                                                <span class="bokun-booking-dashboard__missing-tag-hint"><?php esc_html_e('Selecting a tag copies its Partner Page ID here. Review it, then save.', 'BOKUN_txt_domain'); ?></span>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                (function () {
                    var dashboard = document.getElementById('<?php echo esc_js($dashboard_id); ?>');
                    if (!dashboard) {
                        return;
                    }

                    if (dashboard.getAttribute('data-tabs-initialized') === 'true') {
                        return;
                    }
                    dashboard.setAttribute('data-tabs-initialized', 'true');

                    var tabs = dashboard.querySelectorAll('[role="tab"]');
                    var panels = dashboard.querySelectorAll('[role="tabpanel"]');
                    var cards = Array.prototype.slice.call(dashboard.querySelectorAll('.bokun-booking-dashboard__card'));
                    var statusCheckboxes = Array.prototype.slice.call(dashboard.querySelectorAll('[data-filter-status]'));
                    var teamCheckboxes = Array.prototype.slice.call(dashboard.querySelectorAll('[data-filter-team]'));
                    var searchInput = dashboard.querySelector('[data-dashboard-search]');
                    var searchClearButton = dashboard.querySelector('[data-dashboard-search-clear]');
                    var statusAllCheckbox = dashboard.querySelector('[data-filter-status-all]');
                    var teamAllCheckbox = dashboard.querySelector('[data-filter-team-all]');
                    var copyButtons = Array.prototype.slice.call(dashboard.querySelectorAll('[data-copy-value]'));
                    var conversationToggle = dashboard.querySelector('[data-dashboard-conversations-toggle]');
                    var conversationPanel = dashboard.querySelector('[data-dashboard-conversations]');
                    var conversationOverlay = dashboard.querySelector('[data-dashboard-conversations-overlay]');
                    var conversationClose = dashboard.querySelector('[data-dashboard-conversations-close]');
                    var noResultsMessage = '<?php echo esc_js(__('No bookings match your search or filters.', 'BOKUN_txt_domain')); ?>';

                    var tabCountLookup = {};
                    tabs.forEach(function (tab) {
                        var targetId = tab.getAttribute('data-target');
                        var countElement = tab.querySelector('.bokun-booking-dashboard__tab-count');
                        if (targetId && countElement) {
                            tabCountLookup[targetId] = countElement;
                        }
                    });

                    var cardsByPanel = {};
                    cards.forEach(function (card) {
                        var panel = card.closest('[role="tabpanel"]');
                        if (!panel) {
                            return;
                        }

                        var panelId = panel.id || '';
                        if (!panelId) {
                            return;
                        }

                        if (!cardsByPanel[panelId]) {
                            cardsByPanel[panelId] = [];
                        }

                        cardsByPanel[panelId].push(card);
                    });

                    function getCheckedValues(checkboxes) {
                        return checkboxes
                            .filter(function (checkbox) {
                                return checkbox.checked;
                            })
                            .map(function (checkbox) {
                                return checkbox.value;
                            });
                    }

                    function syncFilterGroup(checkboxes, allCheckbox) {
                        if (!allCheckbox) {
                            return;
                        }

                        var total = checkboxes.length;
                        if (!total) {
                            allCheckbox.checked = true;
                            allCheckbox.indeterminate = false;
                            return;
                        }

                        var selected = checkboxes.filter(function (checkbox) {
                            return checkbox.checked;
                        }).length;

                        if (selected === 0) {
                            allCheckbox.checked = false;
                            allCheckbox.indeterminate = false;
                        } else if (selected === total) {
                            allCheckbox.checked = true;
                            allCheckbox.indeterminate = false;
                        } else {
                            allCheckbox.checked = false;
                            allCheckbox.indeterminate = true;
                        }
                    }

                    function ensureFilteredMessage(panel) {
                        var message = panel.querySelector('.bokun-booking-dashboard__empty--filtered');
                        if (!message) {
                            message = document.createElement('p');
                            message.className = 'bokun-booking-dashboard__empty bokun-booking-dashboard__empty--filtered';
                            message.setAttribute('role', 'status');
                            message.textContent = noResultsMessage;
                            panel.appendChild(message);
                        }

                        return message;
                    }

                    function openConversations() {
                        if (!conversationPanel) {
                            return;
                        }

                        conversationPanel.removeAttribute('hidden');
                        conversationPanel.setAttribute('aria-hidden', 'false');

                        if (conversationOverlay) {
                            conversationOverlay.removeAttribute('hidden');
                            conversationOverlay.setAttribute('aria-hidden', 'false');
                        }

                        if (conversationToggle) {
                            conversationToggle.setAttribute('aria-expanded', 'true');
                        }
                    }

                    function closeConversations() {
                        if (!conversationPanel) {
                            return;
                        }

                        conversationPanel.setAttribute('hidden', '');
                        conversationPanel.setAttribute('aria-hidden', 'true');

                        if (conversationOverlay) {
                            conversationOverlay.setAttribute('hidden', '');
                            conversationOverlay.setAttribute('aria-hidden', 'true');
                        }

                        if (conversationToggle) {
                            conversationToggle.setAttribute('aria-expanded', 'false');
                        }
                    }

                    function applyFilters() {
                        var searchValue = searchInput ? searchInput.value.trim().toLowerCase() : '';

                        if (searchClearButton) {
                            if (searchValue) {
                                searchClearButton.removeAttribute('hidden');
                            } else {
                                searchClearButton.setAttribute('hidden', '');
                            }
                        }

                        syncFilterGroup(statusCheckboxes, statusAllCheckbox);
                        syncFilterGroup(teamCheckboxes, teamAllCheckbox);

                        var activeStatuses = statusCheckboxes.length ? getCheckedValues(statusCheckboxes) : [];
                        var activeTeams = teamCheckboxes.length ? getCheckedValues(teamCheckboxes) : [];
                        var statusFilterActive = statusCheckboxes.length && activeStatuses.length !== statusCheckboxes.length;
                        var teamFilterActive = teamCheckboxes.length && activeTeams.length !== teamCheckboxes.length;

                        cards.forEach(function (card) {
                            var visible = true;

                            if (searchValue) {
                                var haystack = card.getAttribute('data-search') || '';
                                if (haystack.indexOf(searchValue) === -1) {
                                    visible = false;
                                }
                            }

                            if (visible && statusCheckboxes.length) {
                                if (statusFilterActive) {
                                    if (!activeStatuses.length) {
                                        visible = false;
                                    } else {
                                        var cardStatuses = (card.getAttribute('data-statuses') || '').split(' ');
                                        var hasStatus = cardStatuses.some(function (value) {
                                            return value && activeStatuses.indexOf(value) !== -1;
                                        });
                                        if (!hasStatus) {
                                            visible = false;
                                        }
                                    }
                                }
                            }

                            if (visible && teamCheckboxes.length) {
                                if (teamFilterActive) {
                                    if (!activeTeams.length) {
                                        visible = false;
                                    } else {
                                        var cardTeams = (card.getAttribute('data-teams') || '').split(' ');
                                        var hasTeam = cardTeams.some(function (value) {
                                            return value && activeTeams.indexOf(value) !== -1;
                                        });
                                        if (!hasTeam) {
                                            visible = false;
                                        }
                                    }
                                }
                            }

                            card.style.display = visible ? '' : 'none';
                        });

                        panels.forEach(function (panel) {
                            var panelCards = cardsByPanel[panel.id] || [];
                            var visibleCount = 0;

                            panelCards.forEach(function (card) {
                                if (card.style.display !== 'none') {
                                    visibleCount++;
                                }
                            });

                            if (panelCards.length && visibleCount === 0) {
                                var emptyMessage = ensureFilteredMessage(panel);
                                emptyMessage.removeAttribute('hidden');
                            } else {
                                var existingMessage = panel.querySelector('.bokun-booking-dashboard__empty--filtered');
                                if (existingMessage) {
                                    existingMessage.setAttribute('hidden', '');
                                }
                            }

                            var countElement = tabCountLookup[panel.id];
                            if (countElement) {
                                countElement.textContent = String(visibleCount);
                            }
                        });
                    }

                    function setCopyButtonState(button, state) {
                        var defaultLabel = button.getAttribute('data-copy-label') || button.textContent;
                        var successLabel = button.getAttribute('data-copy-done') || defaultLabel;
                        var errorLabel = button.getAttribute('data-copy-error') || defaultLabel;

                        if (button.copyTimeoutId) {
                            window.clearTimeout(button.copyTimeoutId);
                            button.copyTimeoutId = null;
                        }

                        var displayLabel = defaultLabel;
                        if (state === 'copied') {
                            displayLabel = successLabel;
                        } else if (state === 'error') {
                            displayLabel = errorLabel;
                        }

                        button.textContent = displayLabel;
                        button.setAttribute('data-copy-state', state);

                        if (state === 'copied' || state === 'error') {
                            button.copyTimeoutId = window.setTimeout(function () {
                                button.textContent = defaultLabel;
                                button.setAttribute('data-copy-state', 'default');
                                button.copyTimeoutId = null;
                            }, 2000);
                        }
                    }

                    function fallbackCopy(value, onSuccess, onError, htmlValue) {
                        var selection = document.getSelection ? document.getSelection() : null;
                        var previousRange = selection && selection.rangeCount ? selection.getRangeAt(0) : null;
                        var temporaryElement;
                        var successful = false;

                        try {
                            if (htmlValue) {
                                temporaryElement = document.createElement('div');
                                temporaryElement.innerHTML = htmlValue;
                                temporaryElement.setAttribute('contenteditable', 'true');
                            } else {
                                temporaryElement = document.createElement('textarea');
                                temporaryElement.value = value;
                                temporaryElement.setAttribute('readonly', '');
                                temporaryElement.style.opacity = '0';
                            }

                            temporaryElement.style.position = 'absolute';
                            temporaryElement.style.left = '-9999px';
                            temporaryElement.style.top = '0';
                            document.body.appendChild(temporaryElement);

                            if (htmlValue) {
                                if (typeof temporaryElement.focus === 'function') {
                                    try {
                                        temporaryElement.focus({ preventScroll: true });
                                    } catch (focusError) {
                                        temporaryElement.focus();
                                    }
                                }
                                var range = document.createRange();
                                range.selectNodeContents(temporaryElement);
                                if (selection && selection.removeAllRanges) {
                                    selection.removeAllRanges();
                                    selection.addRange(range);
                                }
                            } else if (typeof temporaryElement.select === 'function') {
                                temporaryElement.select();
                            }

                            successful = document.execCommand('copy');
                        } catch (error) {
                            successful = false;
                        }

                        if (selection && selection.removeAllRanges) {
                            selection.removeAllRanges();
                            if (previousRange) {
                                selection.addRange(previousRange);
                            }
                        }

                        if (temporaryElement && temporaryElement.parentNode) {
                            temporaryElement.parentNode.removeChild(temporaryElement);
                        }

                        if (successful) {
                            onSuccess();
                        } else {
                            onError();
                        }
                    }

                    function copyValue(button) {
                        var value = button.getAttribute('data-copy-value') || '';
                        var htmlValue = button.getAttribute('data-copy-html') || '';
                        if (!value && !htmlValue) {
                            setCopyButtonState(button, 'error');
                            return;
                        }

                        var plainValue = value || htmlValue;

                        var handleSuccess = function () {
                            setCopyButtonState(button, 'copied');
                        };

                        var handleError = function () {
                            setCopyButtonState(button, 'error');
                        };

                        var clipboard = navigator.clipboard;
                        var clipboardItemConstructor = (typeof window !== 'undefined' && typeof window.ClipboardItem === 'function') ? window.ClipboardItem : null;
                        var canWriteHtml = !!(htmlValue && clipboard && typeof clipboard.write === 'function' && clipboardItemConstructor);

                        if (canWriteHtml) {
                            var clipboardItems = {};
                            clipboardItems['text/html'] = new Blob([htmlValue], { type: 'text/html' });
                            clipboardItems['text/plain'] = new Blob([plainValue], { type: 'text/plain' });

                            clipboard.write([new clipboardItemConstructor(clipboardItems)]).then(handleSuccess).catch(function () {
                                if (clipboard && typeof clipboard.writeText === 'function') {
                                    clipboard.writeText(plainValue).then(handleSuccess).catch(function () {
                                        fallbackCopy(plainValue, handleSuccess, handleError, htmlValue);
                                    });
                                } else {
                                    fallbackCopy(plainValue, handleSuccess, handleError, htmlValue);
                                }
                            });
                        } else if (clipboard && typeof clipboard.writeText === 'function') {
                            clipboard.writeText(plainValue).then(handleSuccess).catch(function () {
                                fallbackCopy(plainValue, handleSuccess, handleError, htmlValue);
                            });
                        } else {
                            fallbackCopy(plainValue, handleSuccess, handleError, htmlValue);
                        }
                    }

                    if (searchInput) {
                        searchInput.addEventListener('input', applyFilters);
                    }

                    if (searchClearButton && searchInput) {
                        searchClearButton.addEventListener('click', function (event) {
                            event.preventDefault();
                            if (searchInput.value) {
                                searchInput.value = '';
                                applyFilters();
                            }
                            searchInput.focus();
                        });
                    }

                    var searchPasteButton = dashboard.querySelector('[data-dashboard-search-paste]');
                    if (searchPasteButton && searchInput) {
                        searchPasteButton.addEventListener('click', function (event) {
                            event.preventDefault();

                            var applyPastedValue = function (text) {
                                searchInput.value = (text || '').replace(/[\r\n]+/g, ' ').trim();
                                applyFilters();
                                searchInput.focus();
                            };

                            if (navigator.clipboard && typeof navigator.clipboard.readText === 'function') {
                                navigator.clipboard.readText().then(applyPastedValue).catch(function () {
                                    // Clipboard read blocked (permissions/insecure context);
                                    // focus the field so the user can paste manually.
                                    searchInput.focus();
                                });
                            } else {
                                searchInput.focus();
                            }
                        });
                    }

                    statusCheckboxes.forEach(function (checkbox) {
                        checkbox.addEventListener('change', applyFilters);
                    });

                    if (statusAllCheckbox) {
                        statusAllCheckbox.addEventListener('change', function (event) {
                            event.preventDefault();
                            var shouldCheck = statusAllCheckbox.checked;
                            statusAllCheckbox.indeterminate = false;
                            statusCheckboxes.forEach(function (checkbox) {
                                checkbox.checked = shouldCheck;
                            });
                            applyFilters();
                        });
                    }

                    teamCheckboxes.forEach(function (checkbox) {
                        checkbox.addEventListener('change', applyFilters);
                    });

                    if (teamAllCheckbox) {
                        teamAllCheckbox.addEventListener('change', function (event) {
                            event.preventDefault();
                            var shouldCheck = teamAllCheckbox.checked;
                            teamAllCheckbox.indeterminate = false;
                            teamCheckboxes.forEach(function (checkbox) {
                                checkbox.checked = shouldCheck;
                            });
                            applyFilters();
                        });
                    }

                    copyButtons.forEach(function (button) {
                        button.addEventListener('click', function (event) {
                            event.preventDefault();
                            copyValue(button);
                        });

                        button.addEventListener('keydown', function (event) {
                            if (event.key === ' ' || event.key === 'Spacebar') {
                                event.preventDefault();
                                copyValue(button);
                            }
                        });
                    });

                    function activateTab(newTab) {
                        if (!newTab) {
                            return;
                        }

                        tabs.forEach(function (tab) {
                            var isSelected = tab === newTab;
                            tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                            if (isSelected) {
                                tab.removeAttribute('tabindex');
                                tab.focus();
                            } else {
                                tab.setAttribute('tabindex', '-1');
                            }
                        });

                        var targetId = newTab.getAttribute('data-target');
                        panels.forEach(function (panel) {
                            if (panel.id === targetId) {
                                panel.removeAttribute('hidden');
                            } else {
                                panel.setAttribute('hidden', '');
                            }
                        });
                    }

                    function handleKeydown(event) {
                        var currentIndex = Array.prototype.indexOf.call(tabs, event.currentTarget);
                        if (currentIndex === -1) {
                            return;
                        }

                        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                            event.preventDefault();
                            activateTab(event.currentTarget);
                            return;
                        }

                        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                            event.preventDefault();
                            var delta = event.key === 'ArrowRight' ? 1 : -1;
                            var newIndex = (currentIndex + delta + tabs.length) % tabs.length;
                            activateTab(tabs[newIndex]);
                        }
                    }

                    tabs.forEach(function (tab) {
                        tab.addEventListener('click', function (event) {
                            event.preventDefault();
                            activateTab(tab);
                        });

                        tab.addEventListener('keydown', handleKeydown);
                    });

                    if (conversationToggle && conversationPanel) {
                        conversationToggle.addEventListener('click', function (event) {
                            event.preventDefault();

                            if (conversationPanel.hasAttribute('hidden')) {
                                openConversations();
                                conversationPanel.focus();
                            } else {
                                closeConversations();
                            }
                        });
                    }

                    if (conversationClose) {
                        conversationClose.addEventListener('click', function (event) {
                            event.preventDefault();
                            closeConversations();

                            if (conversationToggle) {
                                conversationToggle.focus();
                            }
                        });
                    }

                    if (conversationOverlay) {
                        conversationOverlay.addEventListener('click', function (event) {
                            event.preventDefault();
                            closeConversations();

                            if (conversationToggle) {
                                conversationToggle.focus();
                            }
                        });
                    }

                    if (conversationPanel) {
                        conversationPanel.addEventListener('keydown', function (event) {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                closeConversations();

                                if (conversationToggle) {
                                    conversationToggle.focus();
                                }
                            }
                        });
                    }

                    // Per-booking "Message client" composer modal.
                    var messageDialog = dashboard.querySelector('[data-dashboard-message]');
                    var messageOverlay = dashboard.querySelector('[data-dashboard-message-overlay]');
                    var messageClose = dashboard.querySelector('[data-dashboard-message-close]');
                    var messageButtons = Array.prototype.slice.call(dashboard.querySelectorAll('[data-dashboard-message-button]'));
                    var messageTypeSelect = messageDialog ? messageDialog.querySelector('[data-dashboard-message-type]') : null;
                    var messageSubjectInput = messageDialog ? messageDialog.querySelector('[data-dashboard-message-subject]') : null;
                    var messageTextarea = messageDialog ? messageDialog.querySelector('[data-dashboard-message-textarea]') : null;
                    var messageRecipient = messageDialog ? messageDialog.querySelector('[data-dashboard-message-recipient]') : null;
                    var messageNoEmail = messageDialog ? messageDialog.querySelector('[data-dashboard-message-no-email]') : null;
                    var messageStatus = messageDialog ? messageDialog.querySelector('[data-dashboard-message-status]') : null;
                    var messageSendButton = messageDialog ? messageDialog.querySelector('[data-dashboard-message-send]') : null;
                    var messageViatorLink = messageDialog ? messageDialog.querySelector('[data-dashboard-message-viator]') : null;

                    var messageTemplates = {};
                    if (messageDialog) {
                        try {
                            messageTemplates = JSON.parse(messageDialog.getAttribute('data-message-templates') || '{}');
                        } catch (error) {
                            messageTemplates = {};
                        }
                    }

                    var messageTexts = messageDialog ? {
                        date: messageDialog.getAttribute('data-text-placeholder-date') || 'Day and Month',
                        sending: messageDialog.getAttribute('data-text-sending') || 'Sending…',
                        sent: messageDialog.getAttribute('data-text-sent') || 'Message sent.',
                        error: messageDialog.getAttribute('data-text-error') || 'The message could not be sent.',
                        empty: messageDialog.getAttribute('data-text-empty') || 'Please write a message before sending.',
                        noEmail: messageDialog.getAttribute('data-text-no-email') || 'No contact email is stored for this booking.'
                    } : {};

                    var messageLastFocus = null;
                    var messageCurrent = {};
                    // Guards against a close/reopen race while a send is in flight:
                    // messageSendPending keeps Send disabled across reopen, and
                    // messageSendSession lets a resolving request ignore a composer
                    // that has since been reopened for a different booking.
                    var messageSendPending = false;
                    var messageSendSession = 0;

                    function setMessageStatus(text, state) {
                        if (!messageStatus) {
                            return;
                        }
                        messageStatus.textContent = text || '';
                        messageStatus.setAttribute('data-state', state || 'default');
                    }

                    function applyMessageTemplate(typeKey) {
                        var template = messageTemplates[typeKey];
                        if (!template) {
                            return;
                        }

                        var subject = template.subject || '';
                        var body = template.body || '';
                        var dateValue = messageCurrent.date || '';

                        if (dateValue && messageTexts.date && body.indexOf(messageTexts.date) !== -1) {
                            body = body.replace(messageTexts.date, dateValue);
                        }

                        if (messageSubjectInput) {
                            messageSubjectInput.value = subject;
                        }
                        if (messageTextarea) {
                            messageTextarea.value = body;
                        }
                    }

                    function openMessageModal(button) {
                        if (!messageDialog) {
                            return;
                        }

                        messageCurrent = {
                            booking: button.getAttribute('data-message-booking') || '',
                            email: button.getAttribute('data-message-email') || '',
                            name: button.getAttribute('data-message-name') || '',
                            date: button.getAttribute('data-message-date') || '',
                            product: button.getAttribute('data-message-product') || '',
                            reference: button.getAttribute('data-message-reference') || '',
                            viator: button.getAttribute('data-message-viator') || ''
                        };

                        messageLastFocus = button;
                        // A new open supersedes any in-flight send's UI effects.
                        messageSendSession++;
                        setMessageStatus('', 'default');

                        var hasEmail = !!messageCurrent.email;

                        if (messageRecipient) {
                            messageRecipient.textContent = hasEmail ? messageCurrent.email : '';
                        }

                        if (messageNoEmail) {
                            if (hasEmail) {
                                messageNoEmail.setAttribute('hidden', '');
                                messageNoEmail.textContent = '';
                            } else {
                                messageNoEmail.textContent = messageTexts.noEmail;
                                messageNoEmail.removeAttribute('hidden');
                            }
                        }

                        if (messageSendButton) {
                            // Stay disabled while a previous send is still in flight.
                            messageSendButton.disabled = !hasEmail || messageSendPending;
                        }

                        if (messageViatorLink) {
                            if (messageCurrent.viator) {
                                messageViatorLink.setAttribute('href', messageCurrent.viator);
                                messageViatorLink.removeAttribute('hidden');
                            } else {
                                messageViatorLink.setAttribute('hidden', '');
                            }
                        }

                        if (messageTypeSelect && !messageTypeSelect.value) {
                            messageTypeSelect.selectedIndex = 0;
                        }
                        applyMessageTemplate(messageTypeSelect ? messageTypeSelect.value : '');

                        messageDialog.removeAttribute('hidden');
                        messageDialog.setAttribute('aria-hidden', 'false');

                        if (messageOverlay) {
                            messageOverlay.removeAttribute('hidden');
                            messageOverlay.setAttribute('aria-hidden', 'false');
                        }

                        messageDialog.focus();
                    }

                    function closeMessageModal() {
                        if (!messageDialog) {
                            return;
                        }

                        messageDialog.setAttribute('hidden', '');
                        messageDialog.setAttribute('aria-hidden', 'true');

                        if (messageOverlay) {
                            messageOverlay.setAttribute('hidden', '');
                            messageOverlay.setAttribute('aria-hidden', 'true');
                        }

                        if (messageLastFocus && typeof messageLastFocus.focus === 'function') {
                            messageLastFocus.focus();
                        }
                    }

                    function sendMessage() {
                        if (!messageDialog || !messageSendButton) {
                            return;
                        }

                        // A send is already in flight (possibly for a composer that was
                        // since closed); never start a second one.
                        if (messageSendPending) {
                            return;
                        }

                        var ajax = window.bbm_ajax || {};
                        if (!ajax.ajax_url || !ajax.send_message_nonce) {
                            setMessageStatus(messageTexts.error, 'error');
                            return;
                        }

                        if (!messageCurrent.email) {
                            setMessageStatus(messageTexts.noEmail, 'error');
                            return;
                        }

                        var body = messageTextarea ? messageTextarea.value.replace(/^\s+|\s+$/g, '') : '';
                        if (!body) {
                            setMessageStatus(messageTexts.empty, 'error');
                            if (messageTextarea) {
                                messageTextarea.focus();
                            }
                            return;
                        }

                        var subject = messageSubjectInput ? messageSubjectInput.value : '';
                        var type = messageTypeSelect ? messageTypeSelect.value : '';

                        // Capture the session so a resolving request only touches the
                        // composer if it has not been reopened in the meantime.
                        var session = messageSendSession;
                        messageSendPending = true;
                        messageSendButton.disabled = true;
                        setMessageStatus(messageTexts.sending, 'pending');

                        // Re-enable Send for whatever composer is open now that the
                        // request has settled — the current one if still open on this
                        // session, otherwise a composer reopened for another booking.
                        var releasePending = function () {
                            messageSendPending = false;
                            if (session === messageSendSession) {
                                return;
                            }
                            if (messageDialog && !messageDialog.hasAttribute('hidden') && messageSendButton) {
                                messageSendButton.disabled = !messageCurrent.email;
                            }
                        };

                        var params = [
                            'action=bokun_send_booking_message',
                            'security=' + encodeURIComponent(ajax.send_message_nonce),
                            'booking_id=' + encodeURIComponent(messageCurrent.booking),
                            'message_type=' + encodeURIComponent(type),
                            'subject=' + encodeURIComponent(subject),
                            'message=' + encodeURIComponent(messageTextarea ? messageTextarea.value : '')
                        ].join('&');

                        fetch(ajax.ajax_url, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: params
                        }).then(function (response) {
                            return response.json();
                        }).then(function (result) {
                            releasePending();
                            if (session !== messageSendSession) {
                                return;
                            }
                            if (result && result.success) {
                                var sentText = (result.data && result.data.message) ? result.data.message : messageTexts.sent;
                                setMessageStatus(sentText, 'success');
                                window.setTimeout(function () {
                                    if (session === messageSendSession) {
                                        closeMessageModal();
                                    }
                                }, 1200);
                            } else {
                                var errText = (result && result.data && result.data.message) ? result.data.message : messageTexts.error;
                                setMessageStatus(errText, 'error');
                                messageSendButton.disabled = false;
                            }
                        }).catch(function () {
                            releasePending();
                            if (session !== messageSendSession) {
                                return;
                            }
                            setMessageStatus(messageTexts.error, 'error');
                            messageSendButton.disabled = false;
                        });
                    }

                    messageButtons.forEach(function (button) {
                        button.addEventListener('click', function (event) {
                            event.preventDefault();
                            openMessageModal(button);
                        });
                    });

                    if (messageTypeSelect) {
                        messageTypeSelect.addEventListener('change', function () {
                            applyMessageTemplate(messageTypeSelect.value);
                            setMessageStatus('', 'default');
                        });
                    }

                    if (messageClose) {
                        messageClose.addEventListener('click', function (event) {
                            event.preventDefault();
                            closeMessageModal();
                        });
                    }

                    if (messageOverlay) {
                        messageOverlay.addEventListener('click', function (event) {
                            event.preventDefault();
                            closeMessageModal();
                        });
                    }

                    if (messageSendButton) {
                        messageSendButton.addEventListener('click', function (event) {
                            event.preventDefault();
                            sendMessage();
                        });
                    }

                    if (messageDialog) {
                        messageDialog.addEventListener('keydown', function (event) {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                closeMessageModal();
                            }
                        });
                    }

                    applyFilters();
                })();
            </script>
            <?php
            $dashboard_html = ob_get_clean();
            // The Business Results view is embedded inside the Analytics panel
            // (a toolbar toggle next to Export CSV), not a separate top-level tab.
            $analytics_html = $this->render_analytics_panel();

            return $this->wrap_dashboard_tabs( $dashboard_html, $analytics_html );
        }

        /**
         * Wrap the bookings dashboard and the analytics dashboard in a two-tab
         * layout so the existing dashboard becomes the first tab and analytics
         * the second.
         *
         * @param string $bookings_html  Rendered bookings dashboard markup.
         * @param string $analytics_html Rendered analytics dashboard markup.
         * @return string
         */
        private function wrap_dashboard_tabs( $bookings_html, $analytics_html ) {
            $tabs_id = 'bokun-dash-tabs-' . wp_rand( 1000, 9999 );

            ob_start();
            ?>
            <div class="bokun-dash-tabs" id="<?php echo esc_attr( $tabs_id ); ?>" data-bokun-tabs>
                <div class="bokun-dash-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Dashboard views', 'BOKUN_txt_domain' ); ?>">
                    <button type="button" class="bokun-dash-tabs__tab is-active" role="tab" aria-selected="true" data-tab-target="bookings">
                        <?php esc_html_e( 'Bookings', 'BOKUN_txt_domain' ); ?>
                    </button>
                    <button type="button" class="bokun-dash-tabs__tab" role="tab" aria-selected="false" data-tab-target="analytics">
                        <?php esc_html_e( 'Analytics', 'BOKUN_txt_domain' ); ?>
                    </button>
                </div>
                <div class="bokun-dash-tabs__panel is-active" data-tab-panel="bookings">
                    <?php echo $bookings_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <div class="bokun-dash-tabs__panel" data-tab-panel="analytics" hidden>
                    <?php echo $analytics_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>
            <style>
                .bokun-dash-tabs__nav { display:flex; gap:4px; border-bottom:2px solid #e2e4e7; margin-bottom:16px; flex-wrap:wrap; }
                .bokun-dash-tabs__tab { appearance:none; background:transparent; border:0; border-bottom:3px solid transparent; margin-bottom:-2px; padding:10px 18px; font-size:15px; font-weight:600; color:#50575e; cursor:pointer; }
                .bokun-dash-tabs__tab:hover { color:#1d2327; }
                .bokun-dash-tabs__tab.is-active { color:#2271b1; border-bottom-color:#2271b1; }
                .bokun-dash-tabs__panel[hidden] { display:none; }
                @media (max-width:600px){
                    .bokun-dash-tabs__nav { gap:0; flex-wrap:nowrap; overflow-x:auto; -webkit-overflow-scrolling:touch; }
                    .bokun-dash-tabs__tab { padding:10px 14px; font-size:14px; white-space:nowrap; flex:0 0 auto; }
                }
            </style>
            <script>
                ( function () {
                    var root = document.getElementById( '<?php echo esc_js( $tabs_id ); ?>' );
                    if ( ! root ) { return; }
                    var tabs = root.querySelectorAll( '.bokun-dash-tabs__tab' );
                    var panels = root.querySelectorAll( '.bokun-dash-tabs__panel' );

                    tabs.forEach( function ( tab ) {
                        tab.addEventListener( 'click', function () {
                            var target = tab.getAttribute( 'data-tab-target' );
                            tabs.forEach( function ( t ) {
                                var active = t === tab;
                                t.classList.toggle( 'is-active', active );
                                t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
                            } );
                            panels.forEach( function ( panel ) {
                                var match = panel.getAttribute( 'data-tab-panel' ) === target;
                                panel.classList.toggle( 'is-active', match );
                                panel.hidden = ! match;
                            } );
                            if ( 'analytics' === target && root.bokunAnalyticsInit ) {
                                root.bokunAnalyticsInit();
                            }
                        } );
                    } );
                } )();
            </script>
            <?php
            return ob_get_clean();
        }

        /**
         * Render the analytics dashboard panel: KPI tiles (counts and totals),
         * filters for every column, a group-by breakdown, and a filtered detail
         * table with CSV export. Filtering and aggregation run client-side over
         * the source rows for snappy interactivity.
         *
         * @return string
         */
        /**
         * Render the analytics dashboard panel: a business-performance view of
         * the wp_bokun_analytics_source rows — KPI tiles, auto insights, a trend
         * chart, product/channel performance, result & payment mix, filters for
         * every column, a group-by breakdown, and a filtered detail table with
         * CSV export. Filtering, aggregation and chart drawing run client-side
         * for instant interactivity; the tab renders lazily on first open.
         *
         * @return string
         */
        /**
         * Render the analytics dashboard panel: a business-performance view of
         * the wp_bokun_analytics_source rows, joined to the partners-products
         * catalog (net price, commission, departure city) so it can show net
         * revenue (gross amount minus net price times participants) per line and
         * in aggregate. KPI tiles, auto insights, a trend chart, product/channel
         * performance, result & payment mix, a left sidebar of clickable item
         * filters, a group-by breakdown, and a filtered detail table with CSV
         * export. Filtering, joining and aggregation run client-side.
         *
         * @return string
         */
        public function render_analytics_panel() {
            if ( ! function_exists( 'bokun_analytics_get_rows' ) ) {
                return '<div class="bokun-andash__empty">' . esc_html__( 'Analytics data layer is unavailable.', 'BOKUN_txt_domain' ) . '</div>';
            }

            $rows     = bokun_analytics_get_rows();
            $partners = function_exists( 'bokun_partners_products_get_map' ) ? bokun_partners_products_get_map() : array();
            $months   = function_exists( 'bokun_analytics_get_window_months' ) ? (int) bokun_analytics_get_window_months() : 3;
            $uid      = 'bokun-andash-' . wp_rand( 1000, 9999 );

            // Important dimensions shown as clickable item lists in the sidebar.
            // Status sits right under Channel in the sidebar: it is used a lot,
            // so it is a clickable item list rather than a "More filters" dropdown.
            $facets = array(
                'product_title'   => __( 'Product', 'BOKUN_txt_domain' ),
                'channel_title'   => __( 'Channel', 'BOKUN_txt_domain' ),
                'pb_status'       => __( 'Status', 'BOKUN_txt_domain' ),
                'partner_page_id' => __( 'Partner page', 'BOKUN_txt_domain' ),
                'result'          => __( 'Result', 'BOKUN_txt_domain' ),
                'payment_method'  => __( 'Payment', 'BOKUN_txt_domain' ),
                'currency'        => __( 'Currency', 'BOKUN_txt_domain' ),
                'departure_city'  => __( 'Departure city', 'BOKUN_txt_domain' ),
            );

            // Secondary dimensions kept as compact dropdowns under "More filters".
            $more = array(
                'product_option'       => __( 'Option', 'BOKUN_txt_domain' ),
                'channel_channel_type' => __( 'Channel type', 'BOKUN_txt_domain' ),
                'seller_title'         => __( 'Seller', 'BOKUN_txt_domain' ),
                'vendor_title'         => __( 'Vendor', 'BOKUN_txt_domain' ),
                'pb_seller_title'      => __( 'Product-booking seller', 'BOKUN_txt_domain' ),
                'language'             => __( 'Language', 'BOKUN_txt_domain' ),
            );

            $group_dims = array_merge( $facets, $more );

            $l10n = array(
                'bookings'     => __( 'Bookings', 'BOKUN_txt_domain' ),
                'participants' => __( 'Participants', 'BOKUN_txt_domain' ),
                'revenue'      => __( 'Revenue', 'BOKUN_txt_domain' ),
                'netrev'       => __( 'Net revenue', 'BOKUN_txt_domain' ),
                'amount'       => __( 'Gross', 'BOKUN_txt_domain' ),
                'netprice'     => __( 'Net price', 'BOKUN_txt_domain' ),
                'avgValue'     => __( 'Avg booking value', 'BOKUN_txt_domain' ),
                'avgLead'      => __( 'Avg lead time', 'BOKUN_txt_domain' ),
                'days'         => __( 'days', 'BOKUN_txt_domain' ),
                'products'     => __( 'Products', 'BOKUN_txt_domain' ),
                'channels'     => __( 'Channels', 'BOKUN_txt_domain' ),
                'topProduct'   => __( 'Top product', 'BOKUN_txt_domain' ),
                'topChannel'   => __( 'Top channel', 'BOKUN_txt_domain' ),
                'fullRate'     => __( 'Full-result rate', 'BOKUN_txt_domain' ),
                'margin'       => __( 'Margin', 'BOKUN_txt_domain' ),
                'perMonth'     => __( 'per month', 'BOKUN_txt_domain' ),
                'noResult'     => __( 'No result', 'BOKUN_txt_domain' ),
                'totals'       => __( 'Totals', 'BOKUN_txt_domain' ),
                'ofTotal'      => __( 'of total', 'BOKUN_txt_domain' ),
                'trend'        => __( 'Trend over time', 'BOKUN_txt_domain' ),
                'topProducts'  => __( 'Product performance', 'BOKUN_txt_domain' ),
                'topChannels'  => __( 'Channel performance', 'BOKUN_txt_domain' ),
                'resultMix'    => __( 'Result mix', 'BOKUN_txt_domain' ),
                'paymentMix'   => __( 'Payment mix', 'BOKUN_txt_domain' ),
                'group'        => __( 'Group', 'BOKUN_txt_domain' ),
                'share'        => __( 'Share', 'BOKUN_txt_domain' ),
                'other'        => __( 'Other', 'BOKUN_txt_domain' ),
                'noData'       => __( 'No data for the current filters.', 'BOKUN_txt_domain' ),
                'week'         => __( 'Week of', 'BOKUN_txt_domain' ),
                'mixedCur'     => __( 'mixed currencies', 'BOKUN_txt_domain' ),
                'all'          => __( 'All', 'BOKUN_txt_domain' ),
                'bizResults'   => __( 'Business Results', 'BOKUN_txt_domain' ),
                'backToAna'    => __( '← Back to analytics', 'BOKUN_txt_domain' ),
            );

            ob_start();
            ?>
            <div class="bokun-andash" id="<?php echo esc_attr( $uid ); ?>" data-rows="<?php echo (int) count( $rows ); ?>">
                <div class="bokun-andash__head">
                    <h2 class="bokun-andash__title"><?php esc_html_e( 'Analytics', 'BOKUN_txt_domain' ); ?></h2>
                    <p class="bokun-andash__sub">
                        <?php
                        printf(
                            /* translators: %d: number of months. */
                            esc_html__( 'Performance of bookings from the last %d months (by creation date), joined to the partners-products catalog for net revenue.', 'BOKUN_txt_domain' ),
                            (int) $months
                        );
                        ?>
                    </p>
                </div>

                <?php if ( empty( $rows ) ) : ?>
                    <div class="bokun-andash__empty">
                        <?php esc_html_e( 'No analytics records yet. Open Bokun Bookings Management → Analytics Data and click “Rebuild now”, or run an import.', 'BOKUN_txt_domain' ); ?>
                    </div>
                <?php else : ?>

                    <div class="bokun-andash__layout">
                        <aside class="bokun-andash__side" data-filters>
                            <div class="bokun-andash__side-row">
                                <input type="search" class="bokun-andash__search" data-f-search placeholder="<?php esc_attr_e( 'Search…', 'BOKUN_txt_domain' ); ?>" />
                            </div>
                            <div class="bokun-andash__side-row bokun-andash__quick">
                                <button type="button" class="bokun-andash__quickbtn" data-quick="viator-confirmed-resulted" aria-pressed="false" title="<?php esc_attr_e( 'Toggle: confirmed Viator.com bookings that already have a result selected', 'BOKUN_txt_domain' ); ?>"><?php esc_html_e( 'Viator · Confirmed · with result', 'BOKUN_txt_domain' ); ?></button>
                            </div>
                            <div class="bokun-andash__side-row bokun-andash__presets" role="group" aria-label="<?php esc_attr_e( 'Date range', 'BOKUN_txt_domain' ); ?>">
                                <button type="button" class="bokun-andash__chip" data-preset="7"><?php esc_html_e( '7d', 'BOKUN_txt_domain' ); ?></button>
                                <button type="button" class="bokun-andash__chip" data-preset="30"><?php esc_html_e( '30d', 'BOKUN_txt_domain' ); ?></button>
                                <button type="button" class="bokun-andash__chip" data-preset="90"><?php esc_html_e( '90d', 'BOKUN_txt_domain' ); ?></button>
                                <button type="button" class="bokun-andash__chip is-active" data-preset="0"><?php esc_html_e( 'All', 'BOKUN_txt_domain' ); ?></button>
                            </div>
                            <div class="bokun-andash__side-row bokun-andash__dates">
                                <div class="bokun-andash__daterow">
                                    <span class="bokun-andash__daterow-label"><?php esc_html_e( 'Created', 'BOKUN_txt_domain' ); ?></span>
                                    <input type="date" data-f-date="created_from" aria-label="<?php esc_attr_e( 'Created from', 'BOKUN_txt_domain' ); ?>" />
                                    <input type="date" data-f-date="created_to" aria-label="<?php esc_attr_e( 'Created to', 'BOKUN_txt_domain' ); ?>" />
                                </div>
                                <div class="bokun-andash__daterow">
                                    <span class="bokun-andash__daterow-label"><?php esc_html_e( 'Travel', 'BOKUN_txt_domain' ); ?></span>
                                    <input type="date" data-f-date="travel_from" aria-label="<?php esc_attr_e( 'Travel from', 'BOKUN_txt_domain' ); ?>" />
                                    <input type="date" data-f-date="travel_to" aria-label="<?php esc_attr_e( 'Travel to', 'BOKUN_txt_domain' ); ?>" />
                                </div>
                            </div>
                            <?php foreach ( $facets as $key => $label ) : ?>
                                <div class="bokun-andash__facet" data-facet="<?php echo esc_attr( $key ); ?>">
                                    <h4><?php echo esc_html( $label ); ?></h4>
                                    <div class="bokun-andash__facet-items" data-facet-items></div>
                                </div>
                            <?php endforeach; ?>
                            <details class="bokun-andash__more">
                                <summary><?php esc_html_e( 'More filters', 'BOKUN_txt_domain' ); ?></summary>
                                <?php foreach ( $more as $key => $label ) : ?>
                                    <label class="bokun-andash__more-field">
                                        <span><?php echo esc_html( $label ); ?></span>
                                        <select data-f-dim="<?php echo esc_attr( $key ); ?>">
                                            <option value=""><?php esc_html_e( 'All', 'BOKUN_txt_domain' ); ?></option>
                                        </select>
                                    </label>
                                <?php endforeach; ?>
                            </details>
                            <div class="bokun-andash__side-row">
                                <button type="button" class="button" data-reset><?php esc_html_e( 'Reset filters', 'BOKUN_txt_domain' ); ?></button>
                            </div>
                        </aside>

                        <div class="bokun-andash__main">
                            <div class="bokun-andash__toolbar">
                                <div class="bokun-andash__metric" role="group" data-metric-group aria-label="<?php esc_attr_e( 'Metric', 'BOKUN_txt_domain' ); ?>">
                                    <button type="button" class="bokun-andash__seg is-active" data-metric="bookings"><?php esc_html_e( 'Bookings', 'BOKUN_txt_domain' ); ?></button>
                                    <button type="button" class="bokun-andash__seg" data-metric="participants"><?php esc_html_e( 'Participants', 'BOKUN_txt_domain' ); ?></button>
                                    <button type="button" class="bokun-andash__seg" data-metric="revenue"><?php esc_html_e( 'Revenue', 'BOKUN_txt_domain' ); ?></button>
                                    <button type="button" class="bokun-andash__seg" data-metric="netrev"><?php esc_html_e( 'Net revenue', 'BOKUN_txt_domain' ); ?></button>
                                </div>
                                <div class="bokun-andash__toolbar-actions">
                                    <button type="button" class="button" data-export><?php esc_html_e( 'Export CSV', 'BOKUN_txt_domain' ); ?></button>
                                    <button type="button" class="button button-primary" data-biz-toggle aria-pressed="false"><?php esc_html_e( 'Business Results', 'BOKUN_txt_domain' ); ?></button>
                                </div>
                            </div>

                            <div data-analytics-view>
                            <div class="bokun-andash__insights" data-insights></div>
                            <div class="bokun-andash__kpis" data-kpis></div>

                            <div class="bokun-andash__charts">
                                <div class="bokun-andash__card bokun-andash__card--wide">
                                    <h3 data-trend-title><?php esc_html_e( 'Trend over time', 'BOKUN_txt_domain' ); ?></h3>
                                    <div class="bokun-andash__trend" data-trend></div>
                                </div>
                                <div class="bokun-andash__card">
                                    <h3 data-topproducts-title><?php esc_html_e( 'Product performance', 'BOKUN_txt_domain' ); ?></h3>
                                    <div data-topproducts></div>
                                </div>
                                <div class="bokun-andash__card">
                                    <h3 data-topchannels-title><?php esc_html_e( 'Channel performance', 'BOKUN_txt_domain' ); ?></h3>
                                    <div data-topchannels></div>
                                </div>
                                <div class="bokun-andash__card">
                                    <h3><?php esc_html_e( 'Result mix', 'BOKUN_txt_domain' ); ?></h3>
                                    <div data-resultmix></div>
                                </div>
                                <div class="bokun-andash__card">
                                    <h3><?php esc_html_e( 'Payment mix', 'BOKUN_txt_domain' ); ?></h3>
                                    <div data-paymentmix></div>
                                </div>
                            </div>

                            <div class="bokun-andash__card">
                                <div class="bokun-andash__breakdown-head">
                                    <h3><?php esc_html_e( 'Breakdown', 'BOKUN_txt_domain' ); ?></h3>
                                    <label>
                                        <?php esc_html_e( 'Group by', 'BOKUN_txt_domain' ); ?>
                                        <select data-groupby>
                                            <?php foreach ( $group_dims as $key => $label ) : ?>
                                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__created_month"><?php esc_html_e( 'Created month', 'BOKUN_txt_domain' ); ?></option>
                                            <option value="__travel_month"><?php esc_html_e( 'Travel month', 'BOKUN_txt_domain' ); ?></option>
                                        </select>
                                    </label>
                                </div>
                                <div data-breakdown></div>
                            </div>

                            <div class="bokun-andash__card">
                                <details open>
                                    <summary><?php esc_html_e( 'Records', 'BOKUN_txt_domain' ); ?> <span data-detail-count></span></summary>
                                    <div class="bokun-andash__table-wrap" data-detail></div>
                                </details>
                            </div>
                            </div><!-- /[data-analytics-view] -->

                            <div data-biz-view hidden>
                                <?php echo $this->render_business_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        </div>
                    </div>

                    <script type="application/json" data-andash-rows><?php echo wp_json_encode( $rows ); ?></script>
                    <script type="application/json" data-andash-partners><?php echo wp_json_encode( $partners ); ?></script>
                    <script type="application/json" data-andash-facets><?php echo wp_json_encode( $facets ); ?></script>
                    <script type="application/json" data-andash-more><?php echo wp_json_encode( $more ); ?></script>
                    <script type="application/json" data-andash-groupdims><?php echo wp_json_encode( $group_dims ); ?></script>
                    <script type="application/json" data-andash-l10n><?php echo wp_json_encode( $l10n ); ?></script>
                <?php endif; ?>
            </div>

            <style>
                .bokun-andash { --an-surface:#fff; --an-ink:#1d2327; --an-ink-2:#646970; --an-line:#e2e4e7; --an-blue:#2a78d6; --an-blue-soft:rgba(42,120,214,.14); --an-good:#008300; --an-warn:#eda100; --an-crit:#e34948; --an-c1:#2a78d6; --an-c2:#eb6834; --an-c3:#1baf7a; --an-c4:#eda100; --an-c5:#e87ba4; --an-c6:#4a3aa7; color:var(--an-ink); }
                @media (prefers-color-scheme: dark) {
                    .bokun-andash { --an-surface:#1f1f1e; --an-ink:#f2f2f0; --an-ink-2:#b5b5ad; --an-line:#3a3a38; --an-blue:#3987e5; --an-blue-soft:rgba(57,135,229,.20); --an-good:#2faa4a; --an-warn:#c98500; --an-crit:#e66767; --an-c1:#3987e5; --an-c2:#d95926; --an-c3:#199e70; --an-c4:#c98500; --an-c5:#d55181; --an-c6:#9085e9; }
                }
                .bokun-andash__head { margin-bottom:12px; }
                .bokun-andash__title { margin:0; font-size:20px; }
                .bokun-andash__sub { margin:4px 0 0; color:var(--an-ink-2); }
                .bokun-andash__empty { padding:24px; background:var(--an-surface); border:1px dashed var(--an-line); border-radius:8px; color:var(--an-ink-2); }
                .bokun-andash__layout { display:grid; grid-template-columns:260px minmax(0,1fr); gap:18px; align-items:start; }
                .bokun-andash__side { background:var(--an-surface); border:1px solid var(--an-line); border-radius:10px; padding:12px; position:sticky; top:32px; max-height:calc(100vh - 60px); overflow:auto; }
                .bokun-andash__side-row { margin-bottom:12px; }
                .bokun-andash__search { width:100%; }
                .bokun-andash__presets { display:flex; gap:4px; }
                .bokun-andash__chip, .bokun-andash__seg { appearance:none; background:transparent; border:1px solid var(--an-line); border-radius:999px; padding:6px 12px; font-size:13px; font-weight:600; color:var(--an-ink-2); cursor:pointer; }
                .bokun-andash__chip.is-active, .bokun-andash__seg.is-active { background:var(--an-blue); color:#fff; border-color:var(--an-blue); }
                .bokun-andash__quickbtn { width:100%; appearance:none; text-align:left; background:var(--an-blue-soft); border:1px solid var(--an-blue); border-radius:6px; padding:7px 10px; font-size:13px; font-weight:600; color:var(--an-blue); cursor:pointer; }
                .bokun-andash__quickbtn:hover { background:var(--an-blue); color:#fff; }
                .bokun-andash__quickbtn.is-active { background:var(--an-blue); color:#fff; }
                .bokun-andash__quickbtn.is-active::after { content:"✕"; float:right; font-weight:700; opacity:.9; }
                .bokun-andash__dates { display:flex; flex-direction:column; gap:10px; }
                .bokun-andash__daterow { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
                .bokun-andash__daterow-label { grid-column:1 / -1; font-size:12px; font-weight:600; color:var(--an-ink-2); }
                .bokun-andash__daterow input { width:100%; min-width:0; }
                .bokun-andash__facet { border-top:1px solid var(--an-line); padding-top:10px; margin-top:10px; }
                .bokun-andash__facet[hidden] { display:none; }
                .bokun-andash__facet h4 { margin:0 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--an-ink-2); }
                .bokun-andash__facet-items { display:flex; flex-direction:column; gap:3px; max-height:180px; overflow:auto; }
                .bokun-andash__item { display:flex; justify-content:space-between; gap:8px; align-items:center; padding:4px 8px; border-radius:6px; font-size:13px; cursor:pointer; border:1px solid transparent; }
                .bokun-andash__item:hover { background:var(--an-blue-soft); }
                .bokun-andash__item.is-on { background:var(--an-blue); color:#fff; }
                .bokun-andash__item .lab { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
                .bokun-andash__item .cnt { font-variant-numeric:tabular-nums; opacity:.7; font-size:12px; }
                .bokun-andash__more { margin-top:12px; border-top:1px solid var(--an-line); padding-top:8px; }
                .bokun-andash__more summary { cursor:pointer; font-size:13px; font-weight:600; color:var(--an-ink-2); }
                .bokun-andash__more-field { display:block; margin:8px 0; }
                .bokun-andash__more-field span { display:block; font-size:12px; font-weight:600; color:var(--an-ink-2); margin-bottom:2px; }
                .bokun-andash__more-field select { width:100%; }
                .bokun-andash__toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-bottom:14px; }
                .bokun-andash__metric { display:inline-flex; gap:4px; flex-wrap:wrap; }
                .bokun-andash__toolbar-actions { display:inline-flex; gap:8px; flex-wrap:wrap; align-items:center; }
                .bokun-andash__toolbar-actions [data-biz-toggle].is-active { background:var(--an-blue); border-color:var(--an-blue); color:#fff; }
                .bokun-andash.is-biz .bokun-andash__side { display:none; }
                .bokun-andash.is-biz .bokun-andash__layout { grid-template-columns:1fr; }
                .bokun-andash.is-biz [data-metric-group], .bokun-andash.is-biz [data-export] { display:none; }
                .bokun-andash__insights { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:14px; }
                .bokun-andash__insight { background:var(--an-surface); border:1px solid var(--an-line); border-left:3px solid var(--an-blue); border-radius:10px; padding:12px 14px; }
                .bokun-andash__insight-label { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--an-ink-2); }
                .bokun-andash__insight-value { font-size:17px; font-weight:700; margin-top:3px; overflow-wrap:anywhere; }
                .bokun-andash__insight-sub { font-size:12px; color:var(--an-ink-2); margin-top:2px; }
                .bokun-andash__kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:16px; }
                .bokun-andash__kpi { background:var(--an-surface); border:1px solid var(--an-line); border-radius:10px; padding:14px 16px; }
                .bokun-andash__kpi-label { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--an-ink-2); }
                .bokun-andash__kpi-value { font-size:22px; font-weight:700; margin-top:4px; }
                .bokun-andash__kpi-sub { font-size:12px; color:var(--an-ink-2); margin-top:2px; }
                .bokun-andash__charts { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-bottom:16px; }
                .bokun-andash__card { background:var(--an-surface); border:1px solid var(--an-line); border-radius:10px; padding:14px; margin-bottom:16px; }
                .bokun-andash__charts .bokun-andash__card { margin-bottom:0; }
                .bokun-andash__card--wide { grid-column:1 / -1; }
                .bokun-andash__card h3 { margin:0 0 10px; font-size:15px; }
                .bokun-andash__breakdown-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:8px; }
                .bokun-andash__breakdown-head h3 { margin:0; }
                .bokun-andash__barlist { display:flex; flex-direction:column; gap:8px; }
                .bokun-andash__barrow { display:grid; grid-template-columns:1fr auto; gap:4px 10px; align-items:center; font-size:13px; }
                .bokun-andash__barrow .lab { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
                .bokun-andash__barrow .val { font-variant-numeric:tabular-nums; color:var(--an-ink-2); }
                .bokun-andash__track { grid-column:1 / -1; height:8px; background:var(--an-line); border-radius:4px; overflow:hidden; }
                .bokun-andash__fill { height:100%; border-radius:4px; background:var(--an-blue); }
                .bokun-andash__seg-bar { display:flex; height:16px; border-radius:4px; overflow:hidden; background:var(--an-line); }
                .bokun-andash__legend { display:flex; flex-wrap:wrap; gap:10px 16px; margin-top:10px; font-size:12px; color:var(--an-ink-2); }
                .bokun-andash__legend i { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:5px; vertical-align:middle; }
                .bokun-andash table { width:100%; border-collapse:collapse; font-size:13px; }
                .bokun-andash th, .bokun-andash td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--an-line); white-space:nowrap; }
                .bokun-andash th { color:var(--an-ink-2); font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
                .bokun-andash__num { text-align:right; font-variant-numeric:tabular-nums; }
                .bokun-andash__neg { color:var(--an-crit); }
                .bokun-andash tfoot td { font-weight:700; border-top:2px solid var(--an-line); border-bottom:none; background:var(--an-surface); position:sticky; bottom:0; }
                .bokun-andash th.bokun-andash__sortable { cursor:pointer; user-select:none; white-space:nowrap; }
                .bokun-andash th.bokun-andash__sortable:hover { color:var(--an-blue); }
                .bokun-andash th.bokun-andash__sortable.is-sorted { color:var(--an-blue); }
                .bokun-andash__table-wrap { overflow:auto; max-height:520px; margin-top:10px; }
                .bokun-andash__trend svg { width:100%; height:auto; display:block; }
                .bokun-andash__tip { position:absolute; pointer-events:none; background:var(--an-ink); color:var(--an-surface); font-size:12px; padding:5px 8px; border-radius:6px; white-space:nowrap; transform:translate(-50%,-120%); opacity:0; transition:opacity .08s; z-index:5; }
                @media (max-width:900px){ .bokun-andash__layout { grid-template-columns:1fr; } .bokun-andash__side { position:static; max-height:none; } .bokun-andash__charts { grid-template-columns:1fr; } }
                @media (max-width:600px){
                    .bokun-andash__title { font-size:18px; }
                    .bokun-andash__sub { font-size:12px; }
                    /* Toolbar: stack the metric picker and the action buttons, each full-width and easy to tap. */
                    .bokun-andash__toolbar { flex-direction:column; align-items:stretch; gap:8px; }
                    .bokun-andash__metric { width:100%; }
                    .bokun-andash__metric .bokun-andash__seg { flex:1 1 auto; text-align:center; }
                    .bokun-andash__toolbar-actions { width:100%; }
                    .bokun-andash__toolbar-actions .button { flex:1 1 auto; }
                    .bokun-andash__seg, .bokun-andash__chip { padding:8px 12px; }
                    /* Denser tiles so two fit per row instead of one huge column. */
                    .bokun-andash__kpis { grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:8px; }
                    .bokun-andash__kpi { padding:10px 12px; }
                    .bokun-andash__kpi-value { font-size:18px; }
                    .bokun-andash__insights { grid-template-columns:1fr; }
                    .bokun-andash__card { padding:12px; }
                    /* Tables scroll horizontally with momentum; tighter cells. */
                    .bokun-andash__table-wrap { -webkit-overflow-scrolling:touch; }
                    .bokun-andash table { font-size:12px; }
                    .bokun-andash th, .bokun-andash td { padding:6px; }
                    .bokun-andash__breakdown-head { flex-direction:column; align-items:stretch; gap:8px; }
                    .bokun-andash__breakdown-head select, .bokun-andash__more-field select { width:100%; }
                    /* Business Results view on small screens. */
                    .bokun-bizdash__cardhead { align-items:stretch; }
                    .bokun-bizdash__modes { width:100%; }
                    .bokun-bizdash__modes .bokun-andash__seg { flex:1 1 auto; text-align:center; }
                    .bokun-bizdash__table th, .bokun-bizdash__table td { padding:6px 8px; font-size:12px; }
                }
            </style>
            <script>
            ( function () {
                var root = document.getElementById( '<?php echo esc_js( $uid ); ?>' );
                if ( ! root ) { return; }
                var rowsEl = root.querySelector( '[data-andash-rows]' );
                if ( ! rowsEl ) { return; }

                function readJson( sel, fb ) { var el = root.querySelector( sel ); if ( ! el ) { return fb; } try { return JSON.parse( el.textContent || '' ); } catch ( e ) { return fb; } }
                var ROWS = readJson( '[data-andash-rows]', [] );
                var PARTNERS = readJson( '[data-andash-partners]', {} );
                var FACETS = readJson( '[data-andash-facets]', {} );
                var MORE = readJson( '[data-andash-more]', {} );
                var GROUP_DIMS = readJson( '[data-andash-groupdims]', {} );
                var L = readJson( '[data-andash-l10n]', {} );
                var FACET_KEYS = Object.keys( FACETS );
                var MORE_KEYS = Object.keys( MORE );

                var metric = 'bookings';
                var started = false;
                var revCur = '';
                var revMulti = false;
                // Net revenue is scoped to its own primary currency, chosen only
                // from rows that actually have a net-revenue value. Partner-refunded
                // rows are null and must not steer this choice, or a set of refunded
                // rows in one currency could hide a real net-revenue result in another.
                var netRevCur = '';
                var netRevMulti = false;

                function num( v ) { var n = parseFloat( v ); return isNaN( n ) ? 0 : n; }
                function parts( r ) { return num( r.adult_participants ) + num( r.child_participants ) + num( r.infant_participants ); }
                function esc( v ) { return ( v === null || v === undefined ) ? '' : String( v ); }
                function escHtml( v ) { return esc( v ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' ); }
                function fmtInt( n ) { return Number( Math.round( n ) ).toLocaleString(); }
                function fmtMoney( n ) { return Number( n ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ); }
                function dayStr( v ) { return esc( v ).slice( 0, 10 ); }

                // Join each booking to the partners catalog and precompute net cost
                // and net revenue (gross amount minus net price times participants).
                function hasNum( v ) { return v !== null && v !== undefined && v !== '' && ! isNaN( parseFloat( v ) ); }
                function isEmpty( v ) { return v === null || v === undefined || v === ''; }
                // The partners catalog is keyed by the Bokun partner page id
                // (the "ID" column of the partners spreadsheet). A booking's
                // partner_page_id comes from its product tag's partnerpageid
                // term meta and shares that numbering, so it is the only
                // reliable join key: product ids and titles differ between the
                // booking channel (e.g. Viator) and the catalog.
                function partnerFor( r ) {
                    return ( ! isEmpty( r.partner_page_id ) ? PARTNERS[ String( r.partner_page_id ) ] : null ) || {};
                }
                ROWS.forEach( function ( r ) {
                    var p = partnerFor( r );
                    r.net_price = ( p.net_price === undefined ) ? null : p.net_price;
                    r.commission = ( p.commission === undefined ) ? null : p.commission;
                    // Pull departure city from the catalog (a catalog-only
                    // field) so a partners-products rebuild is reflected
                    // without a full analytics rebuild; the source row wins
                    // when it already carries a value.
                    if ( isEmpty( r.departure_city ) && p.departure_city ) { r.departure_city = p.departure_city; }
                    var pax = parts( r );
                    var partnerRefunded = ( r.partner_refunded === 1 || r.partner_refunded === '1' || r.partner_refunded === true );
                    var isCancelled = ( r.is_cancelled === 1 || r.is_cancelled === '1' || r.is_cancelled === true );
                    // "Booking made" means we reserved the tour and paid the
                    // partner, i.e. a Full or Partial result.
                    var wasMade = ( esc( r.result ) === 'full' || esc( r.result ) === 'partial' );
                    var netCostFull = ( r.net_price !== null ) ? ( r.net_price * pax ) : null;
                    // _grossEarned is the gross this line actually earned, used as
                    // the net-revenue margin denominator. A cancelled or refunded
                    // booking earned nothing, so it contributes 0 there even though
                    // the Gross column still shows the booking's face value.
                    r._refunded = partnerRefunded;
                    if ( partnerRefunded ) {
                        // Client cancelled a booking we paid the partner for, but
                        // the partner refunded that cost — no profit and no loss.
                        // Leave net revenue null (neutral) and the cost recovered.
                        r._net_cost = 0;
                        r._net_revenue = null;
                        r._grossEarned = 0;
                    } else if ( isCancelled ) {
                        // Client cancelled and the partner has NOT refunded us. No
                        // revenue was earned, so if the booking was made (we paid
                        // the partner) the whole partner cost is a loss; otherwise
                        // there is nothing to report.
                        r._grossEarned = 0;
                        if ( wasMade && netCostFull !== null ) {
                            r._net_cost = netCostFull;
                            r._net_revenue = 0 - netCostFull;
                        } else {
                            r._net_cost = null;
                            r._net_revenue = null;
                        }
                    } else {
                        // Live booking: net revenue is the gross minus the partner
                        // cost. A numeric zero gross (complimentary/fully-discounted)
                        // is a real value, so test presence, not truthiness.
                        r._net_cost = netCostFull;
                        r._net_revenue = ( hasNum( r.price_amount ) && r.net_price !== null ) ? ( num( r.price_amount ) - netCostFull ) : null;
                        r._grossEarned = hasNum( r.price_amount ) ? num( r.price_amount ) : 0;
                    }
                } );

                function primaryCurrency( data ) {
                    var c = {}, best = '', bestN = -1;
                    data.forEach( function ( r ) { var cur = esc( r.currency ); if ( cur ) { c[ cur ] = ( c[ cur ] || 0 ) + 1; if ( c[ cur ] > bestN ) { bestN = c[ cur ]; best = cur; } } } );
                    return { cur: best, multi: Object.keys( c ).length > 1 };
                }

                function metricVal( r ) {
                    if ( metric === 'participants' ) { return parts( r ); }
                    // Revenue is gross *earned*: a cancelled or partner-refunded
                    // booking earned nothing, so it uses _grossEarned (0 for those)
                    // rather than the raw price, keeping it out of the revenue totals.
                    if ( metric === 'revenue' ) { return ( esc( r.currency ) === revCur ) ? ( r._grossEarned || 0 ) : 0; }
                    if ( metric === 'netrev' ) { return ( esc( r.currency ) === netRevCur && r._net_revenue !== null ) ? r._net_revenue : 0; }
                    return 1;
                }
                function metricLabel() { return metric === 'participants' ? L.participants : ( metric === 'revenue' ? L.revenue : ( metric === 'netrev' ? L.netrev : L.bookings ) ); }
                function isMoney() { return metric === 'revenue' || metric === 'netrev'; }
                function fmtMetric( n ) { return isMoney() ? fmtMoney( n ) : fmtInt( n ); }
                function metricLabelFull() { var lbl = metricLabel(); if ( isMoney() ) { var cur = ( metric === 'netrev' ) ? netRevCur : revCur; var multi = ( metric === 'netrev' ) ? netRevMulti : revMulti; if ( cur ) { lbl += ' (' + cur + ( multi ? ', ' + L.mixedCur : '' ) + ')'; } } return lbl; }

                // ---- facet filter state (multi-select) ----
                var facetSel = {}; FACET_KEYS.forEach( function ( k ) { facetSel[ k ] = {}; } );

                function uniqueCounts( key ) {
                    var m = {};
                    ROWS.forEach( function ( r ) { var v = r[ key ]; if ( v !== null && v !== undefined && v !== '' ) { v = String( v ); m[ v ] = ( m[ v ] || 0 ) + 1; } } );
                    return Object.keys( m ).map( function ( v ) { return { v: v, n: m[ v ] }; } ).sort( function ( a, b ) { return b.n - a.n || a.v.localeCompare( b.v ); } );
                }

                // Build sidebar facet item lists. Facets with no values (e.g.
                // partner page / city before the catalog join resolves) are
                // hidden so the sidebar stays tidy instead of showing empty
                // headers.
                FACET_KEYS.forEach( function ( key ) {
                    var facetEl = root.querySelector( '.bokun-andash__facet[data-facet="' + key + '"]' );
                    var wrap = facetEl ? facetEl.querySelector( '[data-facet-items]' ) : null;
                    if ( ! wrap ) { return; }
                    var values = uniqueCounts( key );
                    // "No result" is a selectable item on the Result facet for the
                    // bookings that have no result chosen yet (empty result). Its
                    // data-val is the empty string, which matches() already treats
                    // as the value of an empty cell, so it needs no special case
                    // in the filter — only this synthetic list item.
                    if ( key === 'result' ) {
                        var emptyN = 0;
                        ROWS.forEach( function ( r ) { if ( isEmpty( r.result ) ) { emptyN++; } } );
                        if ( emptyN ) { values = values.concat( [ { v: '', n: emptyN, label: L.noResult } ] ); }
                    }
                    if ( ! values.length ) { facetEl.hidden = true; return; }
                    values.forEach( function ( o ) {
                        var disp = o.label || o.v;
                        var el = document.createElement( 'div' );
                        el.className = 'bokun-andash__item';
                        el.setAttribute( 'data-val', o.v );
                        el.innerHTML = '<span class="lab" title="' + escHtml( disp ) + '">' + escHtml( disp ) + '</span><span class="cnt">' + fmtInt( o.n ) + '</span>';
                        el.addEventListener( 'click', function () {
                            if ( facetSel[ key ][ o.v ] ) { delete facetSel[ key ][ o.v ]; el.classList.remove( 'is-on' ); }
                            else { facetSel[ key ][ o.v ] = 1; el.classList.add( 'is-on' ); }
                            recompute();
                        } );
                        wrap.appendChild( el );
                    } );
                } );

                // Build "more" dropdown options.
                MORE_KEYS.forEach( function ( key ) {
                    var sel = root.querySelector( '[data-f-dim="' + key + '"]' );
                    if ( ! sel ) { return; }
                    uniqueCounts( key ).forEach( function ( o ) { var opt = document.createElement( 'option' ); opt.value = o.v; opt.textContent = o.v + ' (' + o.n + ')'; sel.appendChild( opt ); } );
                } );

                // Reflect the current facetSel state onto the sidebar item-list
                // highlights, so a programmatic selection (quick filter) shows up.
                function syncFacetItemClasses() {
                    root.querySelectorAll( '.bokun-andash__facet' ).forEach( function ( facetEl ) {
                        var key = facetEl.getAttribute( 'data-facet' );
                        facetEl.querySelectorAll( '.bokun-andash__item' ).forEach( function ( el ) {
                            var v = el.getAttribute( 'data-val' );
                            el.classList.toggle( 'is-on', !! ( facetSel[ key ] && facetSel[ key ][ v ] ) );
                        } );
                    } );
                }
                // Distinct non-empty values of a column whose text matches a pattern.
                function distinctMatch( key, re ) {
                    var out = {};
                    ROWS.forEach( function ( r ) { var v = esc( r[ key ] ); if ( v && re.test( v ) ) { out[ v ] = 1; } } );
                    return Object.keys( out );
                }
                // A value no booking column can hold, used so a required quick-filter
                // dimension with no matching data excludes every row rather than
                // silently dropping the constraint.
                var QUICK_NONE = '\u0000__no_match__';
                // Set a quick-filter facet to the matching values, or — when none
                // exist in the data — to a sentinel that matches nothing, so the
                // constraint still applies (an empty selection would be read by
                // currentFilters() as "no filter on this dimension").
                function setQuickDim( key, vals ) {
                    if ( ! facetSel[ key ] ) { return; }
                    if ( vals.length ) { vals.forEach( function ( v ) { facetSel[ key ][ v ] = 1; } ); }
                    else { facetSel[ key ][ QUICK_NONE ] = 1; }
                }
                // Predefined quick filter: confirmed status, Viator.com channel, and
                // a result already selected (any non-empty result). Values are read
                // from the data so the exact casing of "CONFIRMED"/"Viator.com" does
                // not have to be hardcoded. It is a toggle: turning it on sets these
                // facet selections (clearing the rest); turning it off clears them
                // again. The date range and search box are left as the user had them.
                var quickOn = false;
                function setQuickActive( on ) {
                    quickOn = on;
                    var btn = root.querySelector( '[data-quick]' );
                    if ( btn ) { btn.classList.toggle( 'is-active', on ); btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' ); }
                }
                function applyQuick() {
                    FACET_KEYS.forEach( function ( k ) { facetSel[ k ] = {}; } );
                    setQuickDim( 'pb_status', distinctMatch( 'pb_status', /confirm/i ) );
                    setQuickDim( 'channel_title', distinctMatch( 'channel_title', /viator/i ) );
                    setQuickDim( 'result', distinctMatch( 'result', /\S/ ) );
                    setQuickActive( true );
                    syncFacetItemClasses();
                    recompute();
                }
                function clearQuick() {
                    FACET_KEYS.forEach( function ( k ) { facetSel[ k ] = {}; } );
                    setQuickActive( false );
                    syncFacetItemClasses();
                    recompute();
                }
                function toggleQuick() { if ( quickOn ) { clearQuick(); } else { applyQuick(); } }

                function currentFilters() {
                    var f = { search: '', dims: {}, dates: {} };
                    var s = root.querySelector( '[data-f-search]' );
                    f.search = s ? s.value.trim().toLowerCase() : '';
                    FACET_KEYS.forEach( function ( key ) { var vals = Object.keys( facetSel[ key ] ); if ( vals.length ) { f.dims[ key ] = vals; } } );
                    MORE_KEYS.forEach( function ( key ) { var sel = root.querySelector( '[data-f-dim="' + key + '"]' ); if ( sel && sel.value ) { f.dims[ key ] = [ sel.value ]; } } );
                    root.querySelectorAll( '[data-f-date]' ).forEach( function ( el ) { if ( el.value ) { f.dates[ el.getAttribute( 'data-f-date' ) ] = el.value; } } );
                    return f;
                }
                function matches( r, f ) {
                    for ( var key in f.dims ) { if ( f.dims[ key ].indexOf( String( r[ key ] === null || r[ key ] === undefined ? '' : r[ key ] ) ) === -1 ) { return false; } }
                    var cd = dayStr( r.created_datetime ), td = dayStr( r.travel_datetime );
                    if ( f.dates.created_from && cd && cd < f.dates.created_from ) { return false; }
                    if ( f.dates.created_to && cd && cd > f.dates.created_to ) { return false; }
                    if ( f.dates.travel_from && td && td < f.dates.travel_from ) { return false; }
                    if ( f.dates.travel_to && td && td > f.dates.travel_to ) { return false; }
                    if ( f.search ) { var hay = ''; for ( var k in r ) { if ( k.charAt( 0 ) !== '_' ) { hay += ' ' + esc( r[ k ] ).toLowerCase(); } } if ( hay.indexOf( f.search ) === -1 ) { return false; } }
                    return true;
                }

                function groupSum( data, keyFn ) {
                    var g = {};
                    data.forEach( function ( r ) {
                        var k = keyFn( r ); if ( k === '' || k === null || k === undefined ) { k = '—'; }
                        if ( ! g[ k ] ) { g[ k ] = { label: String( k ), count: 0, parts: 0, amount: 0, netrev: 0, netrevN: 0, metric: 0 }; }
                        g[ k ].count++; g[ k ].parts += parts( r ); g[ k ].amount += num( r.price_amount );
                        if ( r._net_revenue !== null && esc( r.currency ) === netRevCur ) { g[ k ].netrev += r._net_revenue; g[ k ].netrevN++; }
                        g[ k ].metric += metricVal( r );
                    } );
                    return Object.keys( g ).map( function ( k ) { return g[ k ]; } );
                }
                function avgLead( data ) {
                    var tot = 0, n = 0;
                    data.forEach( function ( r ) { var c = dayStr( r.created_datetime ), t = dayStr( r.travel_datetime ); if ( c && t ) { var d = ( Date.parse( t ) - Date.parse( c ) ) / 86400000; if ( ! isNaN( d ) && d >= 0 ) { tot += d; n++; } } } );
                    return n ? ( tot / n ) : null;
                }

                // `max` is the scaling denominator; callers pass the largest
                // ABSOLUTE metric so negative values (e.g. loss-making net
                // revenue) size by magnitude and are coloured red rather than
                // clamped to a tiny positive bar.
                function barList( items, max, colorFn, valFn ) {
                    if ( ! items.length ) { return '<p class="bokun-andash__insight-sub">' + escHtml( L.noData ) + '</p>'; }
                    return '<div class="bokun-andash__barlist">' + items.map( function ( g ) {
                        var w = max ? Math.max( 2, Math.round( ( Math.abs( g.metric ) / max ) * 100 ) ) : 2;
                        var color = colorFn ? colorFn( g ) : ( g.metric < 0 ? 'var(--an-crit)' : 'var(--an-blue)' );
                        return '<div class="bokun-andash__barrow"><span class="lab" title="' + escHtml( g.label ) + '">' + escHtml( g.label ) +
                            '</span><span class="val">' + ( valFn ? valFn( g ) : fmtMetric( g.metric ) ) + '</span>' +
                            '<span class="bokun-andash__track"><span class="bokun-andash__fill" style="width:' + w + '%;background:' + color + '"></span></span></div>';
                    } ).join( '' ) + '</div>';
                }
                function topDimension( data, key, el ) {
                    var list = groupSum( data, function ( r ) { return esc( r[ key ] ); } ).filter( function ( g ) { return g.label !== '—'; } );
                    list.sort( function ( a, b ) { return b.metric - a.metric; } );
                    var top = list.slice( 0, 8 );
                    if ( list.length > 8 ) { top.push( { label: L.other, metric: list.slice( 8 ).reduce( function ( s, g ) { return s + g.metric; }, 0 ) } ); }
                    var max = top.reduce( function ( m, g ) { return Math.max( m, Math.abs( g.metric ) ); }, 0 );
                    root.querySelector( el ).innerHTML = barList( top, max, null, null );
                }

                function sumNetRev( data ) { var s = 0, any = false; data.forEach( function ( r ) { if ( r._net_revenue !== null && esc( r.currency ) === netRevCur ) { s += r._net_revenue; any = true; } } ); return any ? s : null; }

                function renderInsights( data ) {
                    var pc = primaryCurrency( data );
                    var prods = groupSum( data, function ( r ) { return esc( r.product_title ); } ).filter( function ( g ) { return g.label !== '—'; } );
                    prods.sort( function ( a, b ) { return b.metric - a.metric; } );
                    var chans = groupSum( data, function ( r ) { return esc( r.channel_title ); } ).filter( function ( g ) { return g.label !== '—'; } );
                    chans.sort( function ( a, b ) { return b.metric - a.metric; } );
                    var totalMetric = data.reduce( function ( s, r ) { return s + metricVal( r ); }, 0 );
                    var full = 0, resulted = 0;
                    data.forEach( function ( r ) { var res = esc( r.result ); if ( res ) { resulted++; if ( res === 'full' ) { full++; } } } );
                    var cards = [];
                    if ( prods.length ) { var share = totalMetric ? Math.round( ( prods[ 0 ].metric / totalMetric ) * 100 ) : 0; cards.push( { label: L.topProduct, value: prods[ 0 ].label, sub: fmtMetric( prods[ 0 ].metric ) + ' ' + metricLabel().toLowerCase() + ' · ' + share + '% ' + L.ofTotal } ); }
                    if ( chans.length ) { cards.push( { label: L.topChannel, value: chans[ 0 ].label, sub: fmtMetric( chans[ 0 ].metric ) + ' ' + metricLabel().toLowerCase() } ); }
                    var netrev = sumNetRev( data );
                    if ( netrev !== null ) {
                        // Margin numerator and denominator must share one currency:
                        // net revenue is summed in netRevCur, so the gross here is
                        // the gross of non-refunded rows in that same currency.
                        // Refunded rows are excluded (their net revenue is null, so
                        // counting their gross would understate the margin).
                        var grossNet = data.reduce( function ( s, r ) { return ( esc( r.currency ) === netRevCur ) ? s + ( r._grossEarned || 0 ) : s; }, 0 );
                        var margin = grossNet ? Math.round( ( netrev / grossNet ) * 100 ) : 0;
                        // Average monthly net revenue: the total spread over the
                        // number of distinct calendar months (by creation date)
                        // that actually contributed net revenue, so a filter
                        // narrowing to one month shows that month, not a /3 of it.
                        var nrMonths = {};
                        data.forEach( function ( r ) { if ( r._net_revenue !== null && esc( r.currency ) === netRevCur ) { var mk = dayStr( r.created_datetime ).slice( 0, 7 ); if ( mk ) { nrMonths[ mk ] = 1; } } } );
                        var monthsN = Object.keys( nrMonths ).length;
                        var avgMonthly = monthsN ? ( netrev / monthsN ) : netrev;
                        var curSuffix = netRevCur ? ' ' + netRevCur : '';
                        var sub = L.margin + ' ' + margin + '%' +
                            ' · ' + fmtMoney( avgMonthly ) + curSuffix + ' ' + L.perMonth +
                            ( netRevMulti ? ' · ' + L.mixedCur : '' );
                        cards.push( { label: L.netrev, value: fmtMoney( netrev ) + curSuffix, sub: sub } );
                    }
                    // Avg booking value is over gross *earned*, so cancelled and
                    // partner-refunded bookings (which earned nothing) neither count
                    // toward the total nor the booking tally.
                    var revRows = data.filter( function ( r ) { return esc( r.currency ) === pc.cur && ( r._grossEarned || 0 ); } );
                    var totalRev = revRows.reduce( function ( s, r ) { return s + ( r._grossEarned || 0 ); }, 0 );
                    if ( totalRev > 0 ) { cards.push( { label: L.avgValue, value: fmtMoney( revRows.length ? totalRev / revRows.length : 0 ) + ( pc.cur ? ' ' + pc.cur : '' ), sub: pc.multi ? L.mixedCur : '' } ); }
                    if ( resulted ) { cards.push( { label: L.fullRate, value: Math.round( ( full / resulted ) * 100 ) + '%', sub: fmtInt( full ) + ' / ' + fmtInt( resulted ) } ); }
                    root.querySelector( '[data-insights]' ).innerHTML = cards.map( function ( c ) {
                        return '<div class="bokun-andash__insight"><div class="bokun-andash__insight-label">' + escHtml( c.label ) + '</div><div class="bokun-andash__insight-value">' + escHtml( c.value ) + '</div>' + ( c.sub ? '<div class="bokun-andash__insight-sub">' + escHtml( c.sub ) + '</div>' : '' ) + '</div>';
                    } ).join( '' );
                }

                function renderKpis( data ) {
                    var count = data.length, a = 0, c = 0, i = 0, byCur = {};
                    // Revenue tile sums gross *earned* per currency, so a cancelled
                    // or partner-refunded booking (earned nothing) is left out.
                    data.forEach( function ( r ) { a += num( r.adult_participants ); c += num( r.child_participants ); i += num( r.infant_participants ); var amt = r._grossEarned || 0; if ( amt ) { var cur = esc( r.currency ) || '—'; byCur[ cur ] = ( byCur[ cur ] || 0 ) + amt; } } );
                    var money = Object.keys( byCur ).sort().map( function ( cur ) { return fmtMoney( byCur[ cur ] ) + ' ' + cur; } );
                    var netrev = sumNetRev( data );
                    var tiles = [
                        { label: L.bookings, value: fmtInt( count ) },
                        { label: L.participants, value: fmtInt( a + c + i ), sub: 'A ' + fmtInt( a ) + ' · C ' + fmtInt( c ) + ' · I ' + fmtInt( i ) },
                        { label: L.revenue, value: money.length ? money[ 0 ] : '—', sub: money.length > 1 ? money.slice( 1 ).join( ' · ' ) : '' },
                        { label: L.netrev, value: netrev !== null ? ( fmtMoney( netrev ) + ( netRevCur ? ' ' + netRevCur : '' ) ) : '—' },
                        { label: L.avgLead, value: avgLead( data ) !== null ? ( fmtInt( avgLead( data ) ) + ' ' + L.days ) : '—' }
                    ];
                    root.querySelector( '[data-kpis]' ).innerHTML = tiles.map( function ( t ) { return '<div class="bokun-andash__kpi"><div class="bokun-andash__kpi-label">' + escHtml( t.label ) + '</div><div class="bokun-andash__kpi-value">' + escHtml( t.value ) + '</div>' + ( t.sub ? '<div class="bokun-andash__kpi-sub">' + escHtml( t.sub ) + '</div>' : '' ) + '</div>'; } ).join( '' );
                }

                function trendColors() { var cs = getComputedStyle( root ); function c( n, fb ) { var v = cs.getPropertyValue( n ).trim(); return v || fb; } return { blue: c( '--an-blue', '#2a78d6' ), blueSoft: c( '--an-blue-soft', 'rgba(42,120,214,.14)' ), line: c( '--an-line', '#e2e4e7' ), ink2: c( '--an-ink-2', '#646970' ) }; }
                function weekKey( d ) { var dt = new Date( d + 'T00:00:00' ); if ( isNaN( dt ) ) { return ''; } var day = ( dt.getDay() + 6 ) % 7; dt.setDate( dt.getDate() - day ); return dt.toISOString().slice( 0, 10 ); }
                function renderTrend( data ) {
                    var el = root.querySelector( '[data-trend]' ); var COL = trendColors(); var buckets = {};
                    data.forEach( function ( r ) { var cday = dayStr( r.created_datetime ); if ( ! cday ) { return; } var wk = weekKey( cday ); if ( ! wk ) { return; } buckets[ wk ] = ( buckets[ wk ] || 0 ) + metricVal( r ); } );
                    var keys = Object.keys( buckets ).sort();
                    if ( keys.length === 0 ) { el.innerHTML = '<p class="bokun-andash__insight-sub">' + escHtml( L.noData ) + '</p>'; return; }
                    var vals = keys.map( function ( k ) { return buckets[ k ]; } );
                    var W = 800, H = 240, padL = 48, padB = 26, padT = 12, padR = 12;
                    var innerW = W - padL - padR, innerH = H - padT - padB;
                    // Signed scale with a zero baseline so net-revenue losses
                    // (negative) render below zero instead of off-canvas.
                    var top = Math.max.apply( null, vals.concat( [ 0 ] ) );
                    var bot = Math.min.apply( null, vals.concat( [ 0 ] ) );
                    if ( top === bot ) { top = bot + 1; }
                    var span = top - bot;
                    var x = function ( idx ) { return padL + ( keys.length === 1 ? innerW / 2 : ( idx / ( keys.length - 1 ) ) * innerW ); };
                    var y = function ( v ) { return padT + innerH - ( ( v - bot ) / span ) * innerH; };
                    var y0 = y( 0 );
                    var pts = keys.map( function ( k, idx ) { return [ x( idx ), y( buckets[ k ] ) ]; } );
                    var line = pts.map( function ( p, idx ) { return ( idx ? 'L' : 'M' ) + p[ 0 ].toFixed( 1 ) + ' ' + p[ 1 ].toFixed( 1 ); } ).join( ' ' );
                    var area = line + ' L' + x( keys.length - 1 ).toFixed( 1 ) + ' ' + y0.toFixed( 1 ) + ' L' + x( 0 ).toFixed( 1 ) + ' ' + y0.toFixed( 1 ) + ' Z';
                    var grid = '', ticks = 4;
                    for ( var g = 0; g <= ticks; g++ ) { var gv = bot + ( span / ticks ) * g, gy = y( gv ); grid += '<line x1="' + padL + '" y1="' + gy.toFixed( 1 ) + '" x2="' + ( W - padR ) + '" y2="' + gy.toFixed( 1 ) + '" stroke="' + COL.line + '" stroke-width="1"/>'; grid += '<text x="' + ( padL - 6 ) + '" y="' + ( gy + 3 ).toFixed( 1 ) + '" text-anchor="end" font-size="10" fill="' + COL.ink2 + '">' + fmtInt( gv ) + '</text>'; }
                    var zeroLine = ( bot < 0 ) ? '<line x1="' + padL + '" y1="' + y0.toFixed( 1 ) + '" x2="' + ( W - padR ) + '" y2="' + y0.toFixed( 1 ) + '" stroke="' + COL.ink2 + '" stroke-width="1"/>' : '';
                    var xlabels = '', step = Math.ceil( keys.length / 6 );
                    keys.forEach( function ( k, idx ) { if ( idx % step === 0 || idx === keys.length - 1 ) { xlabels += '<text x="' + x( idx ).toFixed( 1 ) + '" y="' + ( H - 8 ) + '" text-anchor="middle" font-size="10" fill="' + COL.ink2 + '">' + k.slice( 5 ) + '</text>'; } } );
                    var dots = pts.map( function ( p, idx ) { return '<circle cx="' + p[ 0 ].toFixed( 1 ) + '" cy="' + p[ 1 ].toFixed( 1 ) + '" r="3" fill="' + COL.blue + '" data-i="' + idx + '"/>'; } ).join( '' );
                    var hit = pts.map( function ( p, idx ) { var hw = innerW / keys.length; return '<rect x="' + ( p[ 0 ] - hw / 2 ).toFixed( 1 ) + '" y="' + padT + '" width="' + hw.toFixed( 1 ) + '" height="' + innerH + '" fill="transparent" data-hit="' + idx + '"/>'; } ).join( '' );
                    el.style.position = 'relative';
                    el.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + escHtml( L.trend ) + '">' + grid + zeroLine + '<path d="' + area + '" fill="' + COL.blueSoft + '" stroke="none"/><path d="' + line + '" fill="none" stroke="' + COL.blue + '" stroke-width="2"/><line class="an-cross" x1="0" y1="' + padT + '" x2="0" y2="' + ( padT + innerH ) + '" stroke="' + COL.blue + '" stroke-width="1" opacity="0"/>' + dots + xlabels + hit + '</svg><div class="bokun-andash__tip" data-tip></div>';
                    var svg = el.querySelector( 'svg' ), tip = el.querySelector( '[data-tip]' ), cross = el.querySelector( '.an-cross' );
                    svg.querySelectorAll( '[data-hit]' ).forEach( function ( rect ) {
                        rect.addEventListener( 'mousemove', function () { var idx = +rect.getAttribute( 'data-hit' ), px = x( idx ), py = y( buckets[ keys[ idx ] ] ); cross.setAttribute( 'x1', px ); cross.setAttribute( 'x2', px ); cross.setAttribute( 'opacity', '1' ); tip.style.left = ( ( px / W ) * el.clientWidth ) + 'px'; tip.style.top = ( ( py / H ) * el.clientHeight ) + 'px'; tip.style.opacity = '1'; tip.textContent = L.week + ' ' + keys[ idx ] + ': ' + fmtMetric( buckets[ keys[ idx ] ] ); } );
                        rect.addEventListener( 'mouseleave', function () { tip.style.opacity = '0'; cross.setAttribute( 'opacity', '0' ); } );
                    } );
                }

                function renderResultMix( data ) {
                    var map = [ [ 'full', 'Full', 'var(--an-good)' ], [ 'partial', 'Partial', 'var(--an-warn)' ], [ 'not-available', 'Not available', 'var(--an-crit)' ] ];
                    var counts = { full: 0, partial: 0, 'not-available': 0 }; var total = 0;
                    data.forEach( function ( r ) { var res = esc( r.result ); if ( counts[ res ] !== undefined ) { counts[ res ]++; total++; } } );
                    total = total || 1;
                    var seg = map.map( function ( m ) { var w = ( counts[ m[ 0 ] ] / total ) * 100; return w > 0 ? '<span style="width:' + w + '%;background:' + m[ 2 ] + '" title="' + m[ 1 ] + ': ' + counts[ m[ 0 ] ] + '"></span>' : ''; } ).join( '' );
                    var legend = map.map( function ( m ) { return '<span><i style="background:' + m[ 2 ] + '"></i>' + m[ 1 ] + ' ' + fmtInt( counts[ m[ 0 ] ] ) + ' (' + Math.round( ( counts[ m[ 0 ] ] / total ) * 100 ) + '%)</span>'; } ).join( '' );
                    root.querySelector( '[data-resultmix]' ).innerHTML = '<div class="bokun-andash__seg-bar">' + seg + '</div><div class="bokun-andash__legend">' + legend + '</div>';
                }
                function renderPaymentMix( data ) {
                    var list = groupSum( data, function ( r ) { return esc( r.payment_method ); } ).filter( function ( g ) { return g.label !== '—'; } );
                    list.sort( function ( a, b ) { return b.count - a.count; } );
                    var cols = [ 'var(--an-c1)', 'var(--an-c2)', 'var(--an-c3)', 'var(--an-c4)', 'var(--an-c5)', 'var(--an-c6)' ];
                    var max = list.reduce( function ( m, g ) { return Math.max( m, g.count ); }, 0 );
                    var items = list.map( function ( g, idx ) { g._c = cols[ idx % cols.length ]; g.metric = g.count; return g; } );
                    root.querySelector( '[data-paymentmix]' ).innerHTML = barList( items, max, function ( g ) { return g._c; }, function ( g ) { return fmtInt( g.count ); } );
                }

                var DETAIL_COLS = [
                    [ 'created_datetime', 'Created' ], [ 'travel_datetime', 'Travel' ], [ 'confirmation_code', 'Confirmation' ],
                    [ 'external_booking_reference', 'Ext. ref' ],
                    [ 'product_title', 'Product' ], [ 'departure_city', 'City' ], [ 'partner_page_id', 'Partner' ],
                    [ '__parts', 'Pax' ], [ 'price_amount', 'Gross' ], [ 'net_price', 'Net price' ], [ '__net_revenue', 'Net revenue' ],
                    [ 'currency', 'Cur' ], [ 'result', 'Result' ], [ 'payment_method', 'Payment' ], [ 'channel_title', 'Channel' ], [ 'pb_status', 'Status' ]
                ];
                var DETAIL_CAP = 300;
                function cell( r, key ) {
                    if ( key === '__parts' ) { return fmtInt( parts( r ) ); }
                    if ( key === 'price_amount' ) { var a = num( r.price_amount ); return a ? fmtMoney( a ) : ''; }
                    if ( key === 'net_price' ) { return r.net_price !== null && r.net_price !== undefined ? fmtMoney( r.net_price ) : ''; }
                    if ( key === '__net_revenue' ) { return r._net_revenue !== null && r._net_revenue !== undefined ? fmtMoney( r._net_revenue ) : ''; }
                    return esc( r[ key ] );
                }
                function detailNumeric( key ) { return ( key === 'price_amount' || key === '__parts' || key === 'net_price' || key === '__net_revenue' ); }
                // Totals row under the records table, computed over the FULL filtered
                // set (not just the capped rows shown). Amount/count columns get a
                // sum; text columns get a count of populated cells. Money sums stay
                // within the primary currency (revCur / netRevCur) — gross and net
                // revenue are never added across currencies — and the currency is
                // shown on the total so it reads honestly.
                function renderDetailFoot( data ) {
                    if ( ! data.length ) { return ''; }
                    var sumParts = 0, sumGross = 0, sumNet = 0, grossAny = false, netAny = false, counts = {};
                    data.forEach( function ( r ) {
                        sumParts += parts( r );
                        if ( esc( r.currency ) === revCur ) { sumGross += num( r.price_amount ); grossAny = true; }
                        if ( r._net_revenue !== null && esc( r.currency ) === netRevCur ) { sumNet += r._net_revenue; netAny = true; }
                        DETAIL_COLS.forEach( function ( c ) { if ( ! detailNumeric( c[ 0 ] ) && ! isEmpty( r[ c[ 0 ] ] ) ) { counts[ c[ 0 ] ] = ( counts[ c[ 0 ] ] || 0 ) + 1; } } );
                    } );
                    var curGross = revCur ? ' ' + revCur : '';
                    var curNet = netRevCur ? ' ' + netRevCur : '';
                    var cells = DETAIL_COLS.map( function ( c, idx ) {
                        var key = c[ 0 ];
                        if ( idx === 0 ) { return '<td>' + escHtml( L.totals ) + '</td>'; }
                        if ( key === '__parts' ) { return '<td class="bokun-andash__num">' + fmtInt( sumParts ) + '</td>'; }
                        if ( key === 'price_amount' ) { return '<td class="bokun-andash__num">' + ( grossAny ? fmtMoney( sumGross ) + curGross : '' ) + '</td>'; }
                        if ( key === '__net_revenue' ) { return '<td class="bokun-andash__num' + ( netAny && sumNet < 0 ? ' bokun-andash__neg' : '' ) + '">' + ( netAny ? fmtMoney( sumNet ) + curNet : '' ) + '</td>'; }
                        // net_price is a per-person rate, not an additive amount, so it has no total.
                        if ( key === 'net_price' ) { return '<td class="bokun-andash__num"></td>'; }
                        return '<td>' + fmtInt( counts[ key ] || 0 ) + '</td>';
                    } ).join( '' );
                    return '<tfoot><tr>' + cells + '</tr></tfoot>';
                }
                // Click-to-sort state for the records table. A null key keeps the
                // natural (filtered) order; clicking a header sorts by that column and
                // clicking it again flips direction.
                var detailSort = { key: null, dir: 1 };
                function detailSortVal( r, key ) {
                    if ( key === '__parts' ) { return parts( r ); }
                    if ( key === 'price_amount' ) { return hasNum( r.price_amount ) ? num( r.price_amount ) : null; }
                    if ( key === 'net_price' ) { return ( r.net_price === null || r.net_price === undefined ) ? null : num( r.net_price ); }
                    if ( key === '__net_revenue' ) { return ( r._net_revenue === null || r._net_revenue === undefined ) ? null : r._net_revenue; }
                    if ( key === 'partner_page_id' ) { return hasNum( r.partner_page_id ) ? num( r.partner_page_id ) : null; }
                    if ( key === 'created_datetime' || key === 'travel_datetime' ) { var t = Date.parse( esc( r[ key ] ) ); return isNaN( t ) ? null : t; }
                    return esc( r[ key ] ).toLowerCase();
                }
                function detailCmp( a, b, key, dir ) {
                    var va = detailSortVal( a, key ), vb = detailSortVal( b, key );
                    var ea = ( va === null || va === '' ), eb = ( vb === null || vb === '' );
                    // Empty / missing values always sort to the bottom, both directions.
                    if ( ea && eb ) { return 0; }
                    if ( ea ) { return 1; }
                    if ( eb ) { return -1; }
                    if ( typeof va === 'number' && typeof vb === 'number' ) { return ( va - vb ) * dir; }
                    return String( va ).localeCompare( String( vb ) ) * dir;
                }
                function detailSortDefaultDir( key ) { return ( detailNumeric( key ) || key === 'partner_page_id' || key === 'created_datetime' || key === 'travel_datetime' ) ? -1 : 1; }
                function sortDetailBy( key ) {
                    if ( detailSort.key === key ) { detailSort.dir = -detailSort.dir; }
                    else { detailSort.key = key; detailSort.dir = detailSortDefaultDir( key ); }
                    renderDetail( lastFiltered );
                }
                function renderDetail( data ) {
                    var rows = data;
                    if ( detailSort.key ) { rows = data.slice().sort( function ( a, b ) { return detailCmp( a, b, detailSort.key, detailSort.dir ); } ); }
                    var head = '<thead><tr>' + DETAIL_COLS.map( function ( c ) {
                        var active = ( detailSort.key === c[ 0 ] );
                        var caret = active ? ( detailSort.dir > 0 ? ' ▲' : ' ▼' ) : '';
                        var cls = ( detailNumeric( c[ 0 ] ) ? 'bokun-andash__num ' : '' ) + 'bokun-andash__sortable' + ( active ? ' is-sorted' : '' );
                        return '<th class="' + cls + '" data-sort-key="' + escHtml( c[ 0 ] ) + '" role="button" tabindex="0" aria-sort="' + ( active ? ( detailSort.dir > 0 ? 'ascending' : 'descending' ) : 'none' ) + '">' + escHtml( c[ 1 ] ) + caret + '</th>';
                    } ).join( '' ) + '</tr></thead>';
                    var body = rows.slice( 0, DETAIL_CAP ).map( function ( r ) {
                        return '<tr>' + DETAIL_COLS.map( function ( c ) {
                            var cls = detailNumeric( c[ 0 ] ) ? 'bokun-andash__num' : '';
                            if ( c[ 0 ] === '__net_revenue' && r._net_revenue !== null && r._net_revenue < 0 ) { cls += ' bokun-andash__neg'; }
                            return '<td' + ( cls ? ' class="' + cls.trim() + '"' : '' ) + '>' + escHtml( cell( r, c[ 0 ] ) ) + '</td>';
                        } ).join( '' ) + '</tr>';
                    } ).join( '' );
                    var wrap = root.querySelector( '[data-detail]' );
                    wrap.innerHTML = '<table>' + head + '<tbody>' + ( body || '<tr><td>—</td></tr>' ) + '</tbody>' + renderDetailFoot( data ) + '</table>';
                    root.querySelector( '[data-detail-count]' ).textContent = data.length > DETAIL_CAP ? ( '(' + fmtInt( DETAIL_CAP ) + ' / ' + fmtInt( data.length ) + ')' ) : ( '(' + fmtInt( data.length ) + ')' );
                    wrap.querySelectorAll( 'thead th[data-sort-key]' ).forEach( function ( th ) {
                        th.addEventListener( 'click', function () { sortDetailBy( th.getAttribute( 'data-sort-key' ) ); } );
                        th.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); sortDetailBy( th.getAttribute( 'data-sort-key' ) ); } } );
                    } );
                }

                // Click-to-sort state for the breakdown table; defaults to most
                // bookings first, matching the prior fixed ordering.
                var bdSort = { key: 'count', dir: -1 };
                function bdSortVal( g, key ) {
                    if ( key === 'label' ) { return String( g.label ).toLowerCase(); }
                    if ( key === 'parts' ) { return g.parts; }
                    if ( key === 'amount' ) { return g.amount; }
                    if ( key === 'netrev' ) { return g.netrevN ? g.netrev : null; }
                    return g.count; // count and share share the same ordering
                }
                function bdCmp( a, b, key, dir ) {
                    var va = bdSortVal( a, key ), vb = bdSortVal( b, key );
                    var ea = ( va === null || va === '' ), eb = ( vb === null || vb === '' );
                    if ( ea && eb ) { return 0; }
                    if ( ea ) { return 1; }
                    if ( eb ) { return -1; }
                    if ( typeof va === 'number' && typeof vb === 'number' ) { return ( va - vb ) * dir; }
                    return String( va ).localeCompare( String( vb ) ) * dir;
                }
                function sortBreakdownBy( key ) {
                    if ( bdSort.key === key ) { bdSort.dir = -bdSort.dir; }
                    else { bdSort.key = key; bdSort.dir = ( key === 'label' ) ? 1 : -1; }
                    renderBreakdown( lastFiltered );
                }
                function renderBreakdown( data ) {
                    var key = root.querySelector( '[data-groupby]' ).value;
                    var list = groupSum( data, function ( r ) { if ( key === '__created_month' ) { return dayStr( r.created_datetime ).slice( 0, 7 ); } if ( key === '__travel_month' ) { return dayStr( r.travel_datetime ).slice( 0, 7 ); } return esc( r[ key ] ); } );
                    list.sort( function ( a, b ) { return bdCmp( a, b, bdSort.key, bdSort.dir ); } );
                    var totalCount = data.length || 1;
                    var rowsHtml = list.map( function ( g ) {
                        var share = Math.round( ( g.count / totalCount ) * 100 );
                        // Distinguish "no computable net revenue" (no gross or no
                        // catalog match) from a genuine 0.00, so missing data is
                        // not shown as breakeven.
                        var nrCell = g.netrevN ? fmtMoney( g.netrev ) : '—';
                        var nrCls = ( g.netrevN && g.netrev < 0 ) ? ' bokun-andash__neg' : '';
                        return '<tr><td>' + escHtml( g.label ) + '</td><td class="bokun-andash__num">' + fmtInt( g.count ) + '</td><td class="bokun-andash__num">' + share + '%</td><td class="bokun-andash__num">' + fmtInt( g.parts ) + '</td><td class="bokun-andash__num">' + fmtMoney( g.amount ) + '</td><td class="bokun-andash__num' + nrCls + '">' + nrCell + '</td></tr>';
                    } ).join( '' );
                    var GL = Object.assign( {}, GROUP_DIMS, { __created_month: 'Created month', __travel_month: 'Travel month' } );
                    var bdCols = [ [ 'label', GL[ key ] || L.group, false ], [ 'count', L.bookings, true ], [ 'share', L.share, true ], [ 'parts', L.participants, true ], [ 'amount', L.amount, true ], [ 'netrev', L.netrev, true ] ];
                    var headHtml = bdCols.map( function ( col ) {
                        var active = ( bdSort.key === col[ 0 ] );
                        var caret = active ? ( bdSort.dir > 0 ? ' ▲' : ' ▼' ) : '';
                        var cls = ( col[ 2 ] ? 'bokun-andash__num ' : '' ) + 'bokun-andash__sortable' + ( active ? ' is-sorted' : '' );
                        return '<th class="' + cls + '" data-bd-key="' + escHtml( col[ 0 ] ) + '" role="button" tabindex="0" aria-sort="' + ( active ? ( bdSort.dir > 0 ? 'ascending' : 'descending' ) : 'none' ) + '">' + escHtml( col[ 1 ] ) + caret + '</th>';
                    } ).join( '' );
                    var wrap = root.querySelector( '[data-breakdown]' );
                    wrap.innerHTML = '<div class="bokun-andash__table-wrap"><table><thead><tr>' + headHtml + '</tr></thead><tbody>' + ( rowsHtml || '<tr><td colspan="6">—</td></tr>' ) + '</tbody></table></div>';
                    wrap.querySelectorAll( 'thead th[data-bd-key]' ).forEach( function ( th ) {
                        th.addEventListener( 'click', function () { sortBreakdownBy( th.getAttribute( 'data-bd-key' ) ); } );
                        th.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); sortBreakdownBy( th.getAttribute( 'data-bd-key' ) ); } } );
                    } );
                }

                var lastFiltered = ROWS;
                function renderMetricTitles() {
                    root.querySelector( '[data-trend-title]' ).textContent = L.trend + ' — ' + metricLabelFull();
                    root.querySelector( '[data-topproducts-title]' ).textContent = L.topProducts + ' — ' + metricLabelFull();
                    root.querySelector( '[data-topchannels-title]' ).textContent = L.topChannels + ' — ' + metricLabelFull();
                }
                function recompute() {
                    var f = currentFilters();
                    lastFiltered = ROWS.filter( function ( r ) { return matches( r, f ); } );
                    var pc = primaryCurrency( lastFiltered ); revCur = pc.cur; revMulti = pc.multi;
                    var npc = primaryCurrency( lastFiltered.filter( function ( r ) { return r._net_revenue !== null; } ) ); netRevCur = npc.cur; netRevMulti = npc.multi;
                    renderMetricTitles();
                    renderInsights( lastFiltered );
                    renderKpis( lastFiltered );
                    renderTrend( lastFiltered );
                    topDimension( lastFiltered, 'product_title', '[data-topproducts]' );
                    topDimension( lastFiltered, 'channel_title', '[data-topchannels]' );
                    renderResultMix( lastFiltered );
                    renderPaymentMix( lastFiltered );
                    renderBreakdown( lastFiltered );
                    renderDetail( lastFiltered );
                }

                root.querySelector( '[data-f-search]' ).addEventListener( 'input', recompute );
                root.querySelectorAll( '[data-f-dim], [data-f-date]' ).forEach( function ( el ) { el.addEventListener( 'change', recompute ); } );
                root.querySelector( '[data-groupby]' ).addEventListener( 'change', function () { renderBreakdown( lastFiltered ); } );
                root.querySelectorAll( '[data-metric]' ).forEach( function ( btn ) { btn.addEventListener( 'click', function () { metric = btn.getAttribute( 'data-metric' ); root.querySelectorAll( '[data-metric]' ).forEach( function ( b ) { b.classList.toggle( 'is-active', b === btn ); } ); recompute(); } ); } );
                root.querySelectorAll( '[data-preset]' ).forEach( function ( btn ) { btn.addEventListener( 'click', function () { root.querySelectorAll( '[data-preset]' ).forEach( function ( b ) { b.classList.toggle( 'is-active', b === btn ); } ); var days = +btn.getAttribute( 'data-preset' ); var from = root.querySelector( '[data-f-date="created_from"]' ), to = root.querySelector( '[data-f-date="created_to"]' ); if ( ! days ) { from.value = ''; to.value = ''; } else { var d = new Date(); var f2 = new Date(); f2.setDate( d.getDate() - days ); from.value = f2.toISOString().slice( 0, 10 ); to.value = ''; } recompute(); } ); } );
                var quickBtn = root.querySelector( '[data-quick]' );
                if ( quickBtn ) { quickBtn.addEventListener( 'click', toggleQuick ); }
                root.querySelector( '[data-reset]' ).addEventListener( 'click', function () {
                    FACET_KEYS.forEach( function ( k ) { facetSel[ k ] = {}; } );
                    setQuickActive( false );
                    root.querySelectorAll( '.bokun-andash__item.is-on' ).forEach( function ( el ) { el.classList.remove( 'is-on' ); } );
                    root.querySelectorAll( '[data-f-dim]' ).forEach( function ( el ) { el.value = ''; } );
                    root.querySelectorAll( '[data-f-date]' ).forEach( function ( el ) { el.value = ''; } );
                    root.querySelector( '[data-f-search]' ).value = '';
                    root.querySelectorAll( '[data-preset]' ).forEach( function ( b ) { b.classList.toggle( 'is-active', b.getAttribute( 'data-preset' ) === '0' ); } );
                    recompute();
                } );
                root.querySelector( '[data-export]' ).addEventListener( 'click', function () {
                    if ( ! lastFiltered.length ) { return; }
                    var cols = Object.keys( lastFiltered[ 0 ] ).filter( function ( k ) { return k !== '_net_cost'; } );
                    var lines = [ cols.join( ',' ) ];
                    lastFiltered.forEach( function ( r ) { lines.push( cols.map( function ( k ) { var v = ( r[ k ] === null || r[ k ] === undefined ) ? '' : String( r[ k ] ); return '"' + v.replace( /"/g, '""' ) + '"'; } ).join( ',' ) ); } );
                    var blob = new Blob( [ lines.join( '\n' ) ], { type: 'text/csv;charset=utf-8;' } );
                    var a = document.createElement( 'a' ); a.href = URL.createObjectURL( blob ); a.download = 'bokun-analytics.csv'; document.body.appendChild( a ); a.click(); document.body.removeChild( a );
                } );

                // Business Results toggle: swap the analytics view for the
                // embedded business panel (and hide the booking-only sidebar +
                // metric buttons, which don't apply to the overhead P&L). The
                // business panel inits lazily the first time it is shown.
                ( function () {
                    var bizToggle = root.querySelector( '[data-biz-toggle]' );
                    var bizView = root.querySelector( '[data-biz-view]' );
                    var anaView = root.querySelector( '[data-analytics-view]' );
                    if ( ! bizToggle || ! bizView || ! anaView ) { return; }
                    bizToggle.addEventListener( 'click', function () {
                        var showBiz = bizView.hidden;
                        bizView.hidden = ! showBiz;
                        anaView.hidden = showBiz;
                        root.classList.toggle( 'is-biz', showBiz );
                        bizToggle.classList.toggle( 'is-active', showBiz );
                        bizToggle.setAttribute( 'aria-pressed', showBiz ? 'true' : 'false' );
                        bizToggle.textContent = showBiz ? ( L.backToAna || '← Back to analytics' ) : ( L.bizResults || 'Business Results' );
                        if ( showBiz ) { var br = bizView.querySelector( '.bokun-bizdash' ); if ( br && br.bokunBusinessInit ) { br.bokunBusinessInit(); } }
                    } );
                } )();

                function init() { if ( started ) { return; } started = true; recompute(); }
                root.closest( '[data-bokun-tabs]' ) && ( root.closest( '[data-bokun-tabs]' ).bokunAnalyticsInit = init );
                var panel = root.closest( '[data-tab-panel]' );
                if ( ! panel || ! panel.hidden ) { init(); }
            } )();
            </script>
            <?php
            return ob_get_clean();
        }

        /**
         * Render the Business Results panel: operating profit built from the
         * booking net-revenue (margin) already computed by the analytics layer,
         * minus the operator's overhead expenses (from the business config). It
         * shows run-rate KPI tiles, a layered P&L (gross → partner cost → net
         * revenue → overhead → operating profit) as a waterfall and a statement,
         * a per-category expense breakdown, and a monthly trend of margin vs
         * overhead vs profit. All amounts are in the reporting currency (EUR by
         * default); revenue is scoped to that currency so money is never mixed.
         * Everything is computed client-side from the same rows + partners map
         * the analytics tab uses, so net revenue matches exactly. Lazy-rendered
         * on first open of the tab.
         *
         * @return string
         */
        public function render_business_panel() {
            if ( ! function_exists( 'bokun_analytics_get_rows' ) || ! function_exists( 'bokun_business_get_expenses' ) ) {
                return '';
            }

            $rows     = bokun_analytics_get_rows();
            $partners = function_exists( 'bokun_partners_products_get_map' ) ? bokun_partners_products_get_map() : array();
            $expenses = bokun_business_get_expenses();
            $currency = function_exists( 'bokun_business_reporting_currency' ) ? bokun_business_reporting_currency() : 'EUR';
            $months   = function_exists( 'bokun_analytics_get_window_months' ) ? (int) bokun_analytics_get_window_months() : 3;
            $uid      = 'bokun-bizdash-' . wp_rand( 1000, 9999 );

            // The exact calendar months of the analytics window (last N months,
            // by creation date), so the client builds the reporting period from
            // the window itself — not only from months that happen to have
            // bookings — and a month with no bookings still counts (and is
            // charged its overhead) instead of inflating the monthly average.
            $window_months = array();
            try {
                $now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
                $anchor = ( clone $now )->modify( 'first day of this month' );
                for ( $i = $months - 1; $i >= 0; $i-- ) {
                    $window_months[] = ( clone $anchor )->modify( '-' . (int) $i . ' months' )->format( 'Y-m' );
                }
                $current_month = $now->format( 'Y-m' );
            } catch ( Exception $e ) {
                $current_month = gmdate( 'Y-m' );
            }

            $cfg = array(
                'currency'      => $currency,
                'window_months' => $window_months,
                'current_month' => $current_month,
            );

            $l10n = array(
                'title'          => __( 'Business Results', 'BOKUN_txt_domain' ),
                'monthlyOh'      => __( 'Monthly overhead', 'BOKUN_txt_domain' ),
                'annualOh'       => __( 'Annual overhead', 'BOKUN_txt_domain' ),
                'avgMargin'      => __( 'Avg monthly margin', 'BOKUN_txt_domain' ),
                'monthlyProfit'  => __( 'Monthly operating profit', 'BOKUN_txt_domain' ),
                'breakEven'      => __( 'Break-even', 'BOKUN_txt_domain' ),
                'coverage'       => __( 'Catalog coverage', 'BOKUN_txt_domain' ),
                'overMonths'     => __( 'over %d month(s)', 'BOKUN_txt_domain' ),
                'perMonthBk'     => __( '%s bookings / month', 'BOKUN_txt_domain' ),
                'perBooking'     => __( '%s / booking margin', 'BOKUN_txt_domain' ),
                'unmatchedGross' => __( '%s gross not in catalog', 'BOKUN_txt_domain' ),
                'pnl'            => __( 'Profit & loss', 'BOKUN_txt_domain' ),
                'grossMatched'   => __( 'Gross revenue (catalog-matched)', 'BOKUN_txt_domain' ),
                'partnerCost'    => __( 'Partner cost', 'BOKUN_txt_domain' ),
                'netMargin'      => __( 'Net revenue (margin)', 'BOKUN_txt_domain' ),
                'overhead'       => __( 'Overhead expenses', 'BOKUN_txt_domain' ),
                'opProfit'       => __( 'Operating profit', 'BOKUN_txt_domain' ),
                'perMonth'       => __( 'Per month', 'BOKUN_txt_domain' ),
                'period'         => __( 'This period', 'BOKUN_txt_domain' ),
                'annual'         => __( 'Annualized', 'BOKUN_txt_domain' ),
                'expenses'       => __( 'Expense breakdown', 'BOKUN_txt_domain' ),
                'byCategory'     => __( 'By category (monthly)', 'BOKUN_txt_domain' ),
                'item'           => __( 'Item', 'BOKUN_txt_domain' ),
                'category'       => __( 'Category', 'BOKUN_txt_domain' ),
                'cost'           => __( 'Cost', 'BOKUN_txt_domain' ),
                'monthlyCol'     => __( 'Monthly', 'BOKUN_txt_domain' ),
                'annualCol'      => __( 'Annual', 'BOKUN_txt_domain' ),
                'share'          => __( 'Share', 'BOKUN_txt_domain' ),
                'notes'          => __( 'Notes', 'BOKUN_txt_domain' ),
                'trend'          => __( 'Monthly margin vs overhead', 'BOKUN_txt_domain' ),
                'marginLeg'      => __( 'Net margin', 'BOKUN_txt_domain' ),
                'overheadLeg'    => __( 'Overhead', 'BOKUN_txt_domain' ),
                'profitLeg'      => __( 'Operating profit', 'BOKUN_txt_domain' ),
                'oneTime'        => __( 'one-off', 'BOKUN_txt_domain' ),
                'inactive'       => __( 'inactive', 'BOKUN_txt_domain' ),
                'excludedCur'    => __( '%1$d expense(s) in another currency are not shown (only %2$s is combined with revenue).', 'BOKUN_txt_domain' ),
                'yearly'         => __( '/ year', 'BOKUN_txt_domain' ),
                'monthlyFreq'    => __( '/ month', 'BOKUN_txt_domain' ),
                'noRev'          => __( 'No bookings in %s in this period, so revenue cannot be combined with expenses yet.', 'BOKUN_txt_domain' ),
                'noExp'          => __( 'No expenses are configured. Edit includes/data/business-expenses.php to add them.', 'BOKUN_txt_domain' ),
                'noData'         => __( 'No data for this period.', 'BOKUN_txt_domain' ),
                'profitLine'     => __( 'After %1$s overhead you clear %2$s per month.', 'BOKUN_txt_domain' ),
                'lossLine'       => __( 'Overhead (%1$s) is above your margin — a %2$s monthly loss.', 'BOKUN_txt_domain' ),
                'partialNote'    => __( 'The first and last months in the window may be partial, so monthly figures are averages over the bookings on hand.', 'BOKUN_txt_domain' ),
                'subRange'       => __( ' Covering %1$s–%2$s.', 'BOKUN_txt_domain' ),
                'capMonth'       => __( 'Average per month across %1$s–%2$s (%3$d months).', 'BOKUN_txt_domain' ),
                'capPeriod'      => __( 'Total for %1$s–%2$s (%3$d months).', 'BOKUN_txt_domain' ),
                'capAnnual'      => __( 'Projected to a full year, based on %1$s–%2$s (%3$d-month run-rate).', 'BOKUN_txt_domain' ),
                'overRange'      => __( 'across %1$s–%2$s', 'BOKUN_txt_domain' ),
                'beNeedAvg'      => __( 'need %1$s/mo · averaging %2$s/mo', 'BOKUN_txt_domain' ),
                'profitPerBk'    => __( 'Profit per booking', 'BOKUN_txt_domain' ),
                'aboveBe'        => __( '%s/month above break-even — healthy.', 'BOKUN_txt_domain' ),
                'belowBe'        => __( '%s more booking(s)/month would reach break-even.', 'BOKUN_txt_domain' ),
                'atBe'           => __( 'Right around break-even.', 'BOKUN_txt_domain' ),
            );

            ob_start();
            ?>
            <div class="bokun-andash bokun-bizdash" id="<?php echo esc_attr( $uid ); ?>" data-rows="<?php echo (int) count( $rows ); ?>">
                <div class="bokun-andash__head">
                    <h2 class="bokun-andash__title"><?php esc_html_e( 'Business Results', 'BOKUN_txt_domain' ); ?></h2>
                    <p class="bokun-andash__sub">
                        <?php
                        printf(
                            /* translators: 1: number of months, 2: currency code. */
                            esc_html__( 'Operating profit over the last %1$d months (by booking creation date): your booking margin minus your overhead expenses, in %2$s.', 'BOKUN_txt_domain' ),
                            (int) $months,
                            esc_html( $currency )
                        );
                        ?>
                        <span data-biz-subrange></span>
                    </p>
                </div>

                <?php if ( empty( $rows ) ) : ?>
                    <div class="bokun-andash__empty">
                        <?php esc_html_e( 'No analytics records yet. Open Bokun Bookings Management → Analytics Data and click “Rebuild now”, or run an import.', 'BOKUN_txt_domain' ); ?>
                    </div>
                <?php else : ?>
                    <div class="bokun-bizdash__notice" data-biz-notice hidden></div>
                    <div class="bokun-andash__insights" data-biz-insights></div>
                    <div class="bokun-andash__kpis" data-biz-kpis></div>

                    <div class="bokun-andash__card">
                        <div class="bokun-bizdash__cardhead">
                            <h3><?php esc_html_e( 'Profit &amp; loss', 'BOKUN_txt_domain' ); ?></h3>
                            <div class="bokun-andash__metric bokun-bizdash__modes" role="group" aria-label="<?php esc_attr_e( 'P&L period', 'BOKUN_txt_domain' ); ?>">
                                <button type="button" class="bokun-andash__seg is-active" data-biz-mode="month"><?php esc_html_e( 'Per month', 'BOKUN_txt_domain' ); ?></button>
                                <button type="button" class="bokun-andash__seg" data-biz-mode="period"><?php esc_html_e( 'This period', 'BOKUN_txt_domain' ); ?></button>
                                <button type="button" class="bokun-andash__seg" data-biz-mode="annual"><?php esc_html_e( 'Annualized', 'BOKUN_txt_domain' ); ?></button>
                            </div>
                        </div>
                        <p class="bokun-bizdash__periodcap" data-biz-period></p>
                        <div class="bokun-bizdash__pnl">
                            <div class="bokun-bizdash__waterfall" data-biz-waterfall></div>
                            <div class="bokun-bizdash__statement" data-biz-statement></div>
                        </div>
                    </div>

                    <div class="bokun-bizdash__grid">
                        <div class="bokun-andash__card">
                            <h3><?php esc_html_e( 'Expense breakdown (monthly)', 'BOKUN_txt_domain' ); ?></h3>
                            <div data-biz-catbars></div>
                        </div>
                        <div class="bokun-andash__card bokun-andash__card--wide">
                            <h3><?php esc_html_e( 'Monthly margin vs overhead', 'BOKUN_txt_domain' ); ?></h3>
                            <div class="bokun-andash__trend" data-biz-trend></div>
                        </div>
                    </div>

                    <div class="bokun-andash__card">
                        <h3><?php esc_html_e( 'Expenses', 'BOKUN_txt_domain' ); ?></h3>
                        <div class="bokun-bizdash__table-wrap" data-biz-items></div>
                    </div>

                    <script type="application/json" data-biz-rows><?php echo wp_json_encode( $rows ); ?></script>
                    <script type="application/json" data-biz-partners><?php echo wp_json_encode( $partners ); ?></script>
                    <script type="application/json" data-biz-expenses><?php echo wp_json_encode( $expenses ); ?></script>
                    <script type="application/json" data-biz-config><?php echo wp_json_encode( $cfg ); ?></script>
                    <script type="application/json" data-biz-l10n><?php echo wp_json_encode( $l10n ); ?></script>
                <?php endif; ?>
            </div>

            <style>
                .bokun-bizdash { --an-surface:#fff; --an-ink:#1d2327; --an-ink-2:#646970; --an-line:#e2e4e7; --an-blue:#2a78d6; --an-blue-soft:rgba(42,120,214,.14); --an-good:#008300; --an-warn:#eda100; --an-crit:#e34948; --an-c1:#2a78d6; --an-c2:#eb6834; --an-c3:#1baf7a; --an-c4:#eda100; --an-c5:#e87ba4; --an-c6:#4a3aa7; color:var(--an-ink); }
                @media (prefers-color-scheme: dark) {
                    .bokun-bizdash { --an-surface:#1f1f1e; --an-ink:#f2f2f0; --an-ink-2:#b5b5ad; --an-line:#3a3a38; --an-blue:#3987e5; --an-blue-soft:rgba(57,135,229,.20); --an-good:#2faa4a; --an-warn:#c98500; --an-crit:#e66767; --an-c1:#3987e5; --an-c2:#d95926; --an-c3:#199e70; --an-c4:#c98500; --an-c5:#d55181; --an-c6:#9085e9; }
                }
                .bokun-bizdash__notice { background:var(--an-blue-soft); border:1px solid var(--an-line); border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:13px; }
                .bokun-bizdash__cardhead { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px; }
                .bokun-bizdash__cardhead h3 { margin:0; font-size:15px; }
                .bokun-bizdash__periodcap { margin:0 0 12px; font-size:12px; color:var(--an-ink-2); }
                .bokun-bizdash__pnl { display:grid; grid-template-columns:minmax(0,1.2fr) minmax(0,1fr); gap:18px; align-items:start; }
                @media (max-width:720px) { .bokun-bizdash__pnl { grid-template-columns:1fr; } }
                .bokun-bizdash__statement { display:flex; flex-direction:column; gap:2px; font-size:14px; }
                .bokun-bizdash__line { display:flex; justify-content:space-between; gap:12px; padding:7px 2px; border-bottom:1px solid var(--an-line); }
                .bokun-bizdash__line .amt { font-variant-numeric:tabular-nums; white-space:nowrap; }
                .bokun-bizdash__line--sub { font-weight:600; border-bottom:2px solid var(--an-line); }
                .bokun-bizdash__line--total { font-weight:700; font-size:16px; border-bottom:0; margin-top:4px; }
                .bokun-bizdash__pos { color:var(--an-good); }
                .bokun-bizdash__neg { color:var(--an-crit); }
                .bokun-bizdash__grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.4fr); gap:16px; }
                @media (max-width:860px) { .bokun-bizdash__grid { grid-template-columns:1fr; } }
                .bokun-bizdash__grid .bokun-andash__card { margin-bottom:0; }
                .bokun-bizdash__table-wrap { overflow-x:auto; }
                .bokun-bizdash__table { width:100%; border-collapse:collapse; font-size:13px; }
                .bokun-bizdash__table th, .bokun-bizdash__table td { text-align:left; padding:7px 10px; border-bottom:1px solid var(--an-line); white-space:nowrap; }
                .bokun-bizdash__table th { color:var(--an-ink-2); font-weight:600; text-transform:uppercase; font-size:11px; letter-spacing:.03em; }
                .bokun-bizdash__table td.num, .bokun-bizdash__table th.num { text-align:right; font-variant-numeric:tabular-nums; }
                .bokun-bizdash__pill { display:inline-block; padding:1px 8px; border-radius:999px; background:var(--an-blue-soft); font-size:11px; }
                .bokun-bizdash__tip { position:absolute; transform:translate(-50%,-115%); background:var(--an-ink); color:var(--an-surface); padding:4px 8px; border-radius:6px; font-size:12px; white-space:nowrap; pointer-events:none; opacity:0; transition:opacity .1s; }
            </style>

            <script>
            ( function () {
                var root = document.getElementById( '<?php echo esc_js( $uid ); ?>' );
                if ( ! root ) { return; }
                var rowsEl = root.querySelector( '[data-biz-rows]' );
                if ( ! rowsEl ) { return; }

                function readJson( sel, fb ) { var el = root.querySelector( sel ); if ( ! el ) { return fb; } try { return JSON.parse( el.textContent || '' ); } catch ( e ) { return fb; } }
                var ROWS = readJson( '[data-biz-rows]', [] );
                var PARTNERS = readJson( '[data-biz-partners]', {} );
                var ALL_EXPENSES = readJson( '[data-biz-expenses]', [] );
                var CFG = readJson( '[data-biz-config]', {} );
                var L = readJson( '[data-biz-l10n]', {} );
                var CUR = ( CFG.currency || 'EUR' );
                var WINDOW_MONTHS = ( CFG.window_months && CFG.window_months.length ) ? CFG.window_months.slice() : [];
                var CURMONTH = CFG.current_month || '';
                // Only expenses in the reporting currency are combined with
                // revenue; a different-currency item is never summed as if it
                // were the same money. Any excluded items are surfaced in the
                // notice so a currency typo in the config is visible.
                var EXPENSES = ALL_EXPENSES.filter( function ( e ) { return ( e.currency || CUR ) === CUR; } );
                var EXCLUDED_CUR = ALL_EXPENSES.length - EXPENSES.length;

                var started = false;
                var mode = 'month';

                function num( v ) { var n = parseFloat( v ); return isNaN( n ) ? 0 : n; }
                function parts( r ) { return num( r.adult_participants ) + num( r.child_participants ) + num( r.infant_participants ); }
                function esc( v ) { return ( v === null || v === undefined ) ? '' : String( v ); }
                function escHtml( v ) { return esc( v ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' ); }
                function fmtInt( n ) { return Number( Math.round( n ) ).toLocaleString(); }
                function fmtMoney( n ) { return Number( n ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ); }
                function money( n ) { return fmtMoney( n ) + ' ' + CUR; }
                function dayStr( v ) { return esc( v ).slice( 0, 10 ); }
                function hasNum( v ) { return v !== null && v !== undefined && v !== '' && ! isNaN( parseFloat( v ) ); }
                function isEmpty( v ) { return v === null || v === undefined || v === ''; }
                // Honors positional %N$ specifiers (and falls back to sequential),
                // so a translated string may reorder its placeholders.
                function fillTpl( s, a ) { var i = 0; return esc( s ).replace( /%(?:(\d+)\$)?[ds]/g, function ( _m, n ) { return a[ n ? ( n - 1 ) : ( i++ ) ]; } ); }

                // --- Join to partners catalog + precompute net revenue (identical to
                // the Analytics tab, so the margin shown here matches it exactly). ---
                function partnerFor( r ) {
                    return ( ! isEmpty( r.partner_page_id ) ? PARTNERS[ String( r.partner_page_id ) ] : null ) || {};
                }
                ROWS.forEach( function ( r ) {
                    var p = partnerFor( r );
                    r.net_price = ( p.net_price === undefined ) ? null : p.net_price;
                    var pax = parts( r );
                    var partnerRefunded = ( r.partner_refunded === 1 || r.partner_refunded === '1' || r.partner_refunded === true );
                    var isCancelled = ( r.is_cancelled === 1 || r.is_cancelled === '1' || r.is_cancelled === true );
                    var wasMade = ( esc( r.result ) === 'full' || esc( r.result ) === 'partial' );
                    var netCostFull = ( r.net_price !== null ) ? ( r.net_price * pax ) : null;
                    if ( partnerRefunded ) {
                        r._net_cost = 0; r._net_revenue = null; r._grossEarned = 0;
                    } else if ( isCancelled ) {
                        r._grossEarned = 0;
                        if ( wasMade && netCostFull !== null ) { r._net_cost = netCostFull; r._net_revenue = 0 - netCostFull; }
                        else { r._net_cost = null; r._net_revenue = null; }
                    } else {
                        r._net_cost = netCostFull;
                        r._net_revenue = ( hasNum( r.price_amount ) && r.net_price !== null ) ? ( num( r.price_amount ) - netCostFull ) : null;
                        r._grossEarned = hasNum( r.price_amount ) ? num( r.price_amount ) : 0;
                    }
                } );

                // --- Revenue aggregates (scoped to the reporting currency). ---
                var rev = ROWS.filter( function ( r ) { return esc( r.currency ) === CUR; } );
                var G_total = 0, G_matched = 0, G_unmatched = 0, netMargin = 0, bookingsMatched = 0;
                rev.forEach( function ( r ) {
                    var ge = r._grossEarned || 0; G_total += ge;
                    if ( r._net_revenue !== null ) { G_matched += ge; netMargin += r._net_revenue; bookingsMatched++; }
                    else { G_unmatched += ge; }
                } );
                var partnerCost = G_matched - netMargin;

                function monthKey( r ) { return dayStr( r.created_datetime ).slice( 0, 7 ); }
                // The reporting period is the analytics window (every calendar
                // month in it, booked or not), unioned with any month a row
                // falls in, so an empty month still counts and carries its
                // overhead instead of being dropped and inflating the average.
                var monthsSet = {};
                WINDOW_MONTHS.forEach( function ( m ) { if ( m ) { monthsSet[ m ] = 1; } } );
                rev.forEach( function ( r ) { var m = monthKey( r ); if ( m ) { monthsSet[ m ] = 1; } } );
                var months = Object.keys( monthsSet ).sort();
                var nMonths = months.length || 1;

                // --- Expense helpers + aggregates. ---
                function expenseActive( e, ym ) {
                    if ( ! ym ) { return true; }
                    if ( e.start && e.start.slice( 0, 7 ) > ym ) { return false; }
                    if ( e.end && e.end.slice( 0, 7 ) < ym ) { return false; }
                    return true;
                }
                // A recurring expense counts toward the current run-rate only
                // while it is active this month (a future start or past end —
                // e.g. a cancelled subscription — drops out), matching how the
                // monthly series charges it.
                function isRunRate( e ) { return e.frequency !== 'one_time' && expenseActive( e, CURMONTH ); }
                var monthlyOverhead = 0, annualOverhead = 0, orphanOneTime = 0;
                EXPENSES.forEach( function ( e ) {
                    if ( isRunRate( e ) ) { monthlyOverhead += num( e.monthly ); annualOverhead += num( e.annual ); }
                    if ( e.frequency === 'one_time' && ! e.start ) { orphanOneTime += num( e.total ); }
                } );
                function overheadForMonth( ym ) {
                    var s = 0;
                    EXPENSES.forEach( function ( e ) {
                        if ( e.frequency === 'one_time' ) { if ( e.start && e.start.slice( 0, 7 ) === ym ) { s += num( e.total ); } return; }
                        if ( ! expenseActive( e, ym ) ) { return; }
                        if ( e.frequency === 'monthly' ) { s += num( e.total ); }
                        else if ( e.frequency === 'yearly' ) { s += num( e.total ) / 12; }
                    } );
                    return s;
                }

                var series = months.map( function ( m ) {
                    var mnet = 0, mg = 0, hasM = false, bk = 0;
                    rev.forEach( function ( r ) {
                        if ( monthKey( r ) !== m ) { return; }
                        bk++; mg += r._grossEarned || 0;
                        if ( r._net_revenue !== null ) { mnet += r._net_revenue; hasM = true; }
                    } );
                    var ov = overheadForMonth( m );
                    return { ym: m, margin: mnet, gross: mg, hasMargin: hasM, overhead: ov, profit: mnet - ov, bookings: bk };
                } );
                if ( series.length && orphanOneTime ) { series[ 0 ].overhead += orphanOneTime; series[ 0 ].profit = series[ 0 ].margin - series[ 0 ].overhead; }

                var periodOverhead = series.reduce( function ( s, m ) { return s + m.overhead; }, 0 );
                var periodProfit = netMargin - periodOverhead;
                var avgMonthlyMargin = netMargin / nMonths;
                var avgMarginPerBooking = bookingsMatched ? ( netMargin / bookingsMatched ) : null;
                var breakEvenBookings = ( avgMarginPerBooking && avgMarginPerBooking > 0 ) ? ( monthlyOverhead / avgMarginPerBooking ) : null;
                var coverage = G_total > 0 ? ( G_matched / G_total * 100 ) : null;
                var avgBookingsPerMonth = bookingsMatched / nMonths;
                var profitPerBooking = bookingsMatched ? ( ( netMargin - periodOverhead ) / bookingsMatched ) : null;

                // Human-readable month + the covered date range, so the period
                // controls name the actual dates, not just "per month" / "period".
                function fmtMonth( ym ) {
                    var p = esc( ym ).split( '-' ); if ( p.length < 2 ) { return esc( ym ); }
                    var d = new Date( Date.UTC( +p[ 0 ], ( +p[ 1 ] || 1 ) - 1, 1 ) );
                    return isNaN( d ) ? esc( ym ) : d.toLocaleString( undefined, { month: 'short', year: 'numeric', timeZone: 'UTC' } );
                }
                var rangeFrom = months.length ? fmtMonth( months[ 0 ] ) : '';
                var rangeTo = months.length ? fmtMonth( months[ months.length - 1 ] ) : '';

                // mode-scaled P&L figures for the waterfall + statement.
                function scaled() {
                    if ( mode === 'period' ) {
                        return { gross: G_matched, partner: partnerCost, margin: netMargin, overhead: periodOverhead, profit: periodProfit };
                    }
                    if ( mode === 'annual' ) {
                        var f = 12 / nMonths;
                        return { gross: G_matched * f, partner: partnerCost * f, margin: netMargin * f, overhead: annualOverhead, profit: ( avgMonthlyMargin * 12 ) - annualOverhead };
                    }
                    return { gross: G_matched / nMonths, partner: partnerCost / nMonths, margin: avgMonthlyMargin, overhead: monthlyOverhead, profit: avgMonthlyMargin - monthlyOverhead };
                }

                function colVar( name, fb ) { var v = getComputedStyle( root ).getPropertyValue( name ).trim(); return v || fb; }

                // ---------- renderers ----------
                function renderKpis() {
                    var monthlyProfit = avgMonthlyMargin - monthlyOverhead;
                    function cls( n ) { return n < 0 ? 'bokun-bizdash__neg' : 'bokun-bizdash__pos'; }
                    var tiles = [
                        { label: L.monthlyOh, value: money( monthlyOverhead ) },
                        { label: L.avgMargin, value: money( avgMonthlyMargin ), sub: rangeFrom ? fillTpl( L.overRange, [ rangeFrom, rangeTo ] ) : fillTpl( L.overMonths, [ nMonths ] ) },
                        { label: L.monthlyProfit, value: money( monthlyProfit ), vcls: cls( monthlyProfit ) },
                        { label: L.profitPerBk, value: profitPerBooking !== null ? money( profitPerBooking ) : '—', vcls: profitPerBooking !== null ? cls( profitPerBooking ) : '', sub: avgMarginPerBooking !== null ? fillTpl( L.perBooking, [ money( avgMarginPerBooking ) ] ) : '' },
                        { label: L.breakEven, value: breakEvenBookings !== null ? fillTpl( L.perMonthBk, [ fmtInt( Math.ceil( breakEvenBookings ) ) ] ) : '—', sub: breakEvenBookings !== null ? fillTpl( L.beNeedAvg, [ fmtInt( Math.ceil( breakEvenBookings ) ), fmtInt( Math.round( avgBookingsPerMonth ) ) ] ) : '' },
                        { label: L.coverage, value: coverage !== null ? ( Math.round( coverage ) + '%' ) : '—', sub: G_unmatched > 0 ? fillTpl( L.unmatchedGross, [ money( G_unmatched ) ] ) : '' },
                        { label: L.annualOh, value: money( annualOverhead ) }
                    ];
                    root.querySelector( '[data-biz-kpis]' ).innerHTML = tiles.map( function ( t ) {
                        return '<div class="bokun-andash__kpi"><div class="bokun-andash__kpi-label">' + escHtml( t.label ) + '</div><div class="bokun-andash__kpi-value ' + ( t.vcls || '' ) + '">' + escHtml( t.value ) + '</div>' + ( t.sub ? '<div class="bokun-andash__kpi-sub">' + escHtml( t.sub ) + '</div>' : '' ) + '</div>';
                    } ).join( '' );
                }

                function renderSubRange() {
                    var el = root.querySelector( '[data-biz-subrange]' );
                    if ( el ) { el.textContent = rangeFrom ? fillTpl( L.subRange, [ rangeFrom, rangeTo ] ) : ''; }
                }

                function renderPeriodCaption() {
                    var el = root.querySelector( '[data-biz-period]' );
                    if ( ! el ) { return; }
                    var key = mode === 'period' ? L.capPeriod : ( mode === 'annual' ? L.capAnnual : L.capMonth );
                    el.textContent = rangeFrom ? fillTpl( key, [ rangeFrom, rangeTo, nMonths ] ) : '';
                }

                function renderInsights() {
                    var monthlyProfit = avgMonthlyMargin - monthlyOverhead;
                    var cards = [];
                    // Headline: profit/loss per month, with the covered dates.
                    cards.push( monthlyProfit >= 0
                        ? { label: L.monthlyProfit, value: money( monthlyProfit ), sub: fillTpl( L.profitLine, [ money( monthlyOverhead ), money( monthlyProfit ) ] ) }
                        : { label: L.monthlyProfit, value: money( monthlyProfit ), sub: fillTpl( L.lossLine, [ money( monthlyOverhead ), money( Math.abs( monthlyProfit ) ) ] ) } );
                    // Break-even gap: how many bookings/month vs what is needed.
                    if ( breakEvenBookings !== null ) {
                        var need = Math.ceil( breakEvenBookings ), have = avgBookingsPerMonth, gap = have - need;
                        var sub = ( gap >= 1 ) ? fillTpl( L.aboveBe, [ fmtInt( Math.round( gap ) ) ] )
                            : ( gap <= -1 ) ? fillTpl( L.belowBe, [ fmtInt( Math.ceil( -gap ) ) ] )
                            : L.atBe;
                        cards.push( { label: L.breakEven, value: fillTpl( L.perMonthBk, [ fmtInt( need ) ] ), sub: sub } );
                    }
                    root.querySelector( '[data-biz-insights]' ).innerHTML = cards.map( function ( c ) {
                        return '<div class="bokun-andash__insight"><div class="bokun-andash__insight-label">' + escHtml( c.label ) + '</div><div class="bokun-andash__insight-value">' + escHtml( c.value ) + '</div><div class="bokun-andash__insight-sub">' + escHtml( c.sub ) + '</div></div>';
                    } ).join( '' );
                }

                function renderStatement( s ) {
                    function amt( v, sign ) {
                        var cls = v < 0 ? 'bokun-bizdash__neg' : '';
                        var pre = ( sign && v > 0 ) ? '+' : ( sign && v < 0 ? '−' : '' );
                        var disp = sign ? ( pre + money( Math.abs( v ) ) ) : money( v );
                        return '<span class="amt ' + cls + '">' + escHtml( disp ) + '</span>';
                    }
                    var rows = [
                        [ L.grossMatched, amt( s.gross, false ), '' ],
                        [ L.partnerCost, amt( -s.partner, true ), '' ],
                        [ L.netMargin, amt( s.margin, false ), 'sub' ],
                        [ L.overhead, amt( -s.overhead, true ), '' ],
                        [ L.opProfit, '<span class="amt ' + ( s.profit < 0 ? 'bokun-bizdash__neg' : 'bokun-bizdash__pos' ) + '">' + escHtml( money( s.profit ) ) + '</span>', 'total' ]
                    ];
                    root.querySelector( '[data-biz-statement]' ).innerHTML = rows.map( function ( r ) {
                        var m = r[ 2 ] ? ( ' bokun-bizdash__line--' + r[ 2 ] ) : '';
                        return '<div class="bokun-bizdash__line' + m + '"><span>' + escHtml( r[ 0 ] ) + '</span>' + r[ 1 ] + '</div>';
                    } ).join( '' );
                }

                function renderWaterfall( s ) {
                    var el = root.querySelector( '[data-biz-waterfall]' );
                    var cBlue = colVar( '--an-blue', '#2a78d6' ), cCrit = colVar( '--an-crit', '#e34948' ), cGood = colVar( '--an-good', '#008300' ), cTeal = colVar( '--an-c3', '#1baf7a' ), cLine = colVar( '--an-line', '#e2e4e7' ), cInk2 = colVar( '--an-ink-2', '#646970' );
                    // steps: base gross, -partner, =margin(subtotal), -overhead, =profit(total)
                    var steps = [
                        { label: L.grossMatched, delta: s.gross, kind: 'base' },
                        { label: L.partnerCost, delta: -s.partner, kind: 'flow' },
                        { label: L.netMargin, kind: 'sub', total: s.margin },
                        { label: L.overhead, delta: -s.overhead, kind: 'flow' },
                        { label: L.opProfit, kind: 'total', total: s.profit }
                    ];
                    // compute running + bar extents
                    var run = 0, bars = [];
                    steps.forEach( function ( st ) {
                        if ( st.kind === 'base' ) { bars.push( { lo: 0, hi: st.delta, v: st.delta, c: cBlue, label: st.label } ); run = st.delta; }
                        else if ( st.kind === 'flow' ) { var lo = run + st.delta, hi = run; bars.push( { lo: Math.min( lo, hi ), hi: Math.max( lo, hi ), v: st.delta, c: cCrit, label: st.label } ); run += st.delta; }
                        else if ( st.kind === 'sub' ) { bars.push( { lo: Math.min( 0, st.total ), hi: Math.max( 0, st.total ), v: st.total, c: cTeal, label: st.label } ); run = st.total; }
                        else { bars.push( { lo: Math.min( 0, st.total ), hi: Math.max( 0, st.total ), v: st.total, c: st.total < 0 ? cCrit : cGood, label: st.label } ); run = st.total; }
                    } );
                    var lo = 0, hi = 0;
                    bars.forEach( function ( b ) { lo = Math.min( lo, b.lo ); hi = Math.max( hi, b.hi ); } );
                    if ( hi === lo ) { hi = lo + 1; }
                    var W = 420, H = 230, padT = 10, padB = 46, padL = 8, padR = 8;
                    var innerH = H - padT - padB, innerW = W - padL - padR;
                    var n = bars.length, gap = 10, bw = ( innerW - gap * ( n - 1 ) ) / n;
                    var span = hi - lo;
                    function y( v ) { return padT + innerH - ( ( v - lo ) / span ) * innerH; }
                    var y0 = y( 0 );
                    var svg = '';
                    svg += '<line x1="' + padL + '" y1="' + y0.toFixed( 1 ) + '" x2="' + ( W - padR ) + '" y2="' + y0.toFixed( 1 ) + '" stroke="' + cInk2 + '" stroke-width="1"/>';
                    bars.forEach( function ( b, i ) {
                        var x = padL + i * ( bw + gap );
                        var yt = y( b.hi ), yb = y( b.lo ), h = Math.max( 1, yb - yt );
                        svg += '<rect x="' + x.toFixed( 1 ) + '" y="' + yt.toFixed( 1 ) + '" width="' + bw.toFixed( 1 ) + '" height="' + h.toFixed( 1 ) + '" rx="3" fill="' + b.c + '" data-bi="' + i + '"/>';
                        var vlabel = ( steps[ i ].kind === 'flow' ) ? ( ( b.v < 0 ? '−' : '+' ) + fmtInt( Math.abs( b.v ) ) ) : fmtInt( b.v );
                        svg += '<text x="' + ( x + bw / 2 ).toFixed( 1 ) + '" y="' + ( yt - 4 ).toFixed( 1 ) + '" text-anchor="middle" font-size="10" fill="' + cInk2 + '">' + escHtml( vlabel ) + '</text>';
                        // wrapped short label under axis
                        var short = b.label.length > 16 ? b.label.slice( 0, 15 ) + '…' : b.label;
                        svg += '<text x="' + ( x + bw / 2 ).toFixed( 1 ) + '" y="' + ( H - padB + 16 ).toFixed( 1 ) + '" text-anchor="middle" font-size="9.5" fill="' + cInk2 + '">' + escHtml( short ) + '</text>';
                    } );
                    el.style.position = 'relative';
                    el.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + escHtml( L.pnl ) + '" style="width:100%;height:auto">' + svg + '</svg><div class="bokun-bizdash__tip" data-biz-wtip></div>';
                    var tip = el.querySelector( '[data-biz-wtip]' );
                    el.querySelectorAll( '[data-bi]' ).forEach( function ( rc ) {
                        rc.addEventListener( 'mousemove', function ( ev ) { var b = bars[ +rc.getAttribute( 'data-bi' ) ]; var rect = el.getBoundingClientRect(); tip.style.left = ( ev.clientX - rect.left ) + 'px'; tip.style.top = ( ev.clientY - rect.top ) + 'px'; tip.style.opacity = '1'; tip.textContent = b.label + ': ' + money( b.v ); } );
                        rc.addEventListener( 'mouseleave', function () { tip.style.opacity = '0'; } );
                    } );
                }

                function renderCatBars() {
                    var el = root.querySelector( '[data-biz-catbars]' );
                    if ( ! EXPENSES.length ) { el.innerHTML = '<p class="bokun-andash__insight-sub">' + escHtml( L.noExp ) + '</p>'; return; }
                    var cats = {};
                    EXPENSES.forEach( function ( e ) { if ( ! isRunRate( e ) ) { return; } var k = e.category || L.other || 'Other'; cats[ k ] = ( cats[ k ] || 0 ) + num( e.monthly ); } );
                    var list = Object.keys( cats ).map( function ( k ) { return { label: k, metric: cats[ k ] }; } ).filter( function ( g ) { return g.metric > 0; } );
                    list.sort( function ( a, b ) { return b.metric - a.metric; } );
                    var cols = [ '--an-c1', '--an-c2', '--an-c3', '--an-c4', '--an-c5', '--an-c6' ];
                    var max = list.reduce( function ( m, g ) { return Math.max( m, g.metric ); }, 0 );
                    if ( ! list.length ) { el.innerHTML = '<p class="bokun-andash__insight-sub">' + escHtml( L.noData ) + '</p>'; return; }
                    el.innerHTML = '<div class="bokun-andash__barlist">' + list.map( function ( g, i ) {
                        var w = max ? Math.max( 2, Math.round( ( g.metric / max ) * 100 ) ) : 2;
                        var c = colVar( cols[ i % cols.length ], '#2a78d6' );
                        return '<div class="bokun-andash__barrow"><span class="lab" title="' + escHtml( g.label ) + '">' + escHtml( g.label ) + '</span><span class="val">' + escHtml( money( g.metric ) ) + '</span><span class="bokun-andash__track"><span class="bokun-andash__fill" style="width:' + w + '%;background:' + c + '"></span></span></div>';
                    } ).join( '' ) + '</div>';
                }

                function renderItems() {
                    var el = root.querySelector( '[data-biz-items]' );
                    if ( ! EXPENSES.length ) { el.innerHTML = '<p class="bokun-andash__insight-sub">' + escHtml( L.noExp ) + '</p>'; return; }
                    var totalMonthly = monthlyOverhead || 1;
                    var items = EXPENSES.slice().sort( function ( a, b ) { return num( b.monthly ) - num( a.monthly ); } );
                    function freqLabel( e ) {
                        if ( e.frequency === 'one_time' ) { return L.oneTime; }
                        return ( e.frequency === 'yearly' ) ? L.yearly : L.monthlyFreq;
                    }
                    var body = items.map( function ( e ) {
                        // A recurring expense that is not active this month (future
                        // start or past end) is not in the current run-rate, so its
                        // monthly/annual/share read "—" and the total it feeds is 0.
                        var inactive = ( e.frequency !== 'one_time' ) && ! isRunRate( e );
                        var costTxt = money( num( e.total ) ) + ' ' + freqLabel( e ) + ( e.quantity > 1 ? ' ×' + e.quantity : '' ) + ( inactive ? ' · ' + ( L.inactive || 'inactive' ) : '' );
                        var noRate = ( e.frequency === 'one_time' ) || inactive;
                        var share = noRate ? '—' : ( Math.round( num( e.monthly ) / totalMonthly * 100 ) + '%' );
                        var mo = noRate ? '—' : money( num( e.monthly ) );
                        var yr = noRate ? '—' : money( num( e.annual ) );
                        return '<tr><td>' + escHtml( e.name ) + '</td><td><span class="bokun-bizdash__pill">' + escHtml( e.category ) + '</span></td><td>' + escHtml( costTxt ) + '</td><td class="num">' + escHtml( mo ) + '</td><td class="num">' + escHtml( yr ) + '</td><td class="num">' + escHtml( share ) + '</td><td>' + escHtml( e.notes || '' ) + '</td></tr>';
                    } ).join( '' );
                    var foot = '<tr><td><strong>' + escHtml( L.monthlyOh ) + '</strong></td><td></td><td></td><td class="num"><strong>' + escHtml( money( monthlyOverhead ) ) + '</strong></td><td class="num"><strong>' + escHtml( money( annualOverhead ) ) + '</strong></td><td class="num">100%</td><td></td></tr>';
                    el.innerHTML = '<table class="bokun-bizdash__table"><thead><tr><th>' + escHtml( L.item ) + '</th><th>' + escHtml( L.category ) + '</th><th>' + escHtml( L.cost ) + '</th><th class="num">' + escHtml( L.monthlyCol ) + '</th><th class="num">' + escHtml( L.annualCol ) + '</th><th class="num">' + escHtml( L.share ) + '</th><th>' + escHtml( L.notes ) + '</th></tr></thead><tbody>' + body + '</tbody><tfoot>' + foot + '</tfoot></table>';
                }

                function renderTrend() {
                    var el = root.querySelector( '[data-biz-trend]' );
                    if ( ! series.length ) { el.innerHTML = '<p class="bokun-andash__insight-sub">' + escHtml( L.noData ) + '</p>'; return; }
                    var cBlue = colVar( '--an-blue', '#2a78d6' ), cWarn = colVar( '--an-warn', '#eda100' ), cGood = colVar( '--an-good', '#008300' ), cCrit = colVar( '--an-crit', '#e34948' ), cLine = colVar( '--an-line', '#e2e4e7' ), cInk2 = colVar( '--an-ink-2', '#646970' );
                    var W = 820, H = 260, padL = 50, padB = 40, padT = 12, padR = 12;
                    var innerW = W - padL - padR, innerH = H - padT - padB;
                    var vals = [];
                    series.forEach( function ( m ) { vals.push( m.margin, m.overhead, m.profit ); } );
                    var hi = Math.max.apply( null, vals.concat( [ 0 ] ) );
                    var lo = Math.min.apply( null, vals.concat( [ 0 ] ) );
                    if ( hi === lo ) { hi = lo + 1; }
                    var span = hi - lo;
                    function y( v ) { return padT + innerH - ( ( v - lo ) / span ) * innerH; }
                    var y0 = y( 0 );
                    var n = series.length, slot = innerW / n, bw = Math.min( 26, slot / 3.2 );
                    var svg = '';
                    for ( var g = 0; g <= 4; g++ ) { var gv = lo + ( span / 4 ) * g, gy = y( gv ); svg += '<line x1="' + padL + '" y1="' + gy.toFixed( 1 ) + '" x2="' + ( W - padR ) + '" y2="' + gy.toFixed( 1 ) + '" stroke="' + cLine + '"/>'; svg += '<text x="' + ( padL - 6 ) + '" y="' + ( gy + 3 ).toFixed( 1 ) + '" text-anchor="end" font-size="10" fill="' + cInk2 + '">' + fmtInt( gv ) + '</text>'; }
                    svg += '<line x1="' + padL + '" y1="' + y0.toFixed( 1 ) + '" x2="' + ( W - padR ) + '" y2="' + y0.toFixed( 1 ) + '" stroke="' + cInk2 + '"/>';
                    var profPts = [];
                    series.forEach( function ( m, i ) {
                        var cx = padL + slot * i + slot / 2;
                        var xM = cx - bw - 1, xO = cx + 1;
                        function bar( x, v, c ) { var yt = y( Math.max( 0, v ) ), yb = y( Math.min( 0, v ) ); return '<rect x="' + x.toFixed( 1 ) + '" y="' + yt.toFixed( 1 ) + '" width="' + bw.toFixed( 1 ) + '" height="' + Math.max( 1, yb - yt ).toFixed( 1 ) + '" rx="2" fill="' + c + '"/>'; }
                        svg += bar( xM, m.margin, cBlue );
                        svg += bar( xO, m.overhead, cWarn );
                        profPts.push( [ cx, y( m.profit ) ] );
                        svg += '<text x="' + cx.toFixed( 1 ) + '" y="' + ( H - padB + 15 ).toFixed( 1 ) + '" text-anchor="middle" font-size="10" fill="' + cInk2 + '">' + escHtml( m.ym.slice( 2 ) ) + '</text>';
                    } );
                    var pline = profPts.map( function ( p, i ) { return ( i ? 'L' : 'M' ) + p[ 0 ].toFixed( 1 ) + ' ' + p[ 1 ].toFixed( 1 ); } ).join( ' ' );
                    svg += '<path d="' + pline + '" fill="none" stroke="' + cGood + '" stroke-width="2"/>';
                    profPts.forEach( function ( p, i ) { svg += '<circle cx="' + p[ 0 ].toFixed( 1 ) + '" cy="' + p[ 1 ].toFixed( 1 ) + '" r="3" fill="' + ( series[ i ].profit < 0 ? cCrit : cGood ) + '"/>'; } );
                    var legend = '<div class="bokun-andash__legend"><span><i style="background:' + cBlue + '"></i>' + escHtml( L.marginLeg ) + '</span><span><i style="background:' + cWarn + '"></i>' + escHtml( L.overheadLeg ) + '</span><span><i style="background:' + cGood + '"></i>' + escHtml( L.profitLeg ) + '</span></div>';
                    el.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + escHtml( L.trend ) + '" style="width:100%;height:auto">' + svg + '</svg>' + legend;
                }

                function renderNotice() {
                    var el = root.querySelector( '[data-biz-notice]' );
                    var msgs = [];
                    if ( ! rev.length ) { msgs.push( fillTpl( L.noRev, [ CUR ] ) ); }
                    if ( ! EXPENSES.length ) { msgs.push( L.noExp ); }
                    if ( EXCLUDED_CUR > 0 ) { msgs.push( fillTpl( L.excludedCur, [ EXCLUDED_CUR, CUR ] ) ); }
                    if ( rev.length ) { msgs.push( L.partialNote ); }
                    if ( msgs.length ) { el.hidden = false; el.innerHTML = msgs.map( escHtml ).join( '<br>' ); }
                    else { el.hidden = true; }
                }

                function renderPnl() { var s = scaled(); renderWaterfall( s ); renderStatement( s ); renderPeriodCaption(); }

                function recompute() {
                    renderSubRange(); renderNotice(); renderInsights(); renderKpis(); renderPnl(); renderCatBars(); renderItems(); renderTrend();
                }

                root.querySelectorAll( '[data-biz-mode]' ).forEach( function ( btn ) {
                    btn.addEventListener( 'click', function () {
                        mode = btn.getAttribute( 'data-biz-mode' );
                        root.querySelectorAll( '[data-biz-mode]' ).forEach( function ( b ) { b.classList.toggle( 'is-active', b === btn ); } );
                        renderPnl();
                    } );
                } );

                function init() { if ( started ) { return; } started = true; recompute(); }
                // The panel is embedded inside the Analytics view, revealed by a
                // toolbar toggle that calls this. Expose it on the root; auto-init
                // only if already visible (defensive, e.g. standalone use).
                root.bokunBusinessInit = init;
                if ( root.offsetParent !== null ) { init(); }
            } )();
            </script>
            <?php
            return ob_get_clean();
        }

        function bokun_display_settings( ) {
            if( file_exists( BOKUN_INCLUDES_DIR . "bokun_shortcode.view.php" ) ) {
                include_once( BOKUN_INCLUDES_DIR . "bokun_shortcode.view.php" );
            }
        }

    }


    global $bokun_shortcode;
    $bokun_shortcode = new BOKUN_Shortcode();
}


    
?>
