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
                $status_label = !empty($log['is_checked']) ? __('Checked', 'BOKUN_txt_domain') : __('Unchecked', 'BOKUN_txt_domain');
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
                        padding: 6px 30px 6px 8px;
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
                        right: 6px;
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
                return sprintf(
                    '<div class="bokun-booking-dashboard__empty">%s</div>',
                    esc_html(
                        sprintf(
                            /* translators: %d: number of days in the dashboard range. */
                            __('No upcoming bookings were found for the next %d days.', 'BOKUN_txt_domain'),
                            $days
                        )
                    )
                );
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
                $phone_prefix   = get_post_meta($post_id, '_phone_prefix', true);
                $phone_number   = get_post_meta($post_id, '_phone_number', true);
                $created_at     = get_post_meta($post_id, 'bookingcreationdate', true);
                $inclusions     = get_post_meta($post_id, 'inclusions_clean', true);
                $alarm_status   = get_post_meta($post_id, 'alarmstatus', true);
                $vendor_title_meta = get_post_meta($post_id, 'productBookings_0_vendor_title', true);
                $rate_title_meta   = get_post_meta($post_id, 'productBookings_0_fields_rateTitle', true);
                $vendor_title      = is_scalar($vendor_title_meta) ? (string) $vendor_title_meta : '';
                $rate_title        = is_scalar($rate_title_meta) ? (string) $rate_title_meta : '';

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

                $partner_page_id = '';
                $partner_terms   = wp_get_post_terms($post_id, 'product_tags');
                if (!empty($partner_terms) && !is_wp_error($partner_terms)) {
                    foreach ($partner_terms as $term) {
                        $term_partner_meta = get_term_meta($term->term_id, 'partnerpageid', true);
                        $term_partner_id   = is_scalar($term_partner_meta) ? (string) $term_partner_meta : '';

                        if (!isset($processed_product_tag_ids[$term->term_id])) {
                            $processed_product_tag_ids[$term->term_id] = true;

                            if ($term_partner_id === '') {
                                $edit_link = get_edit_term_link($term, 'product_tags');
                                $product_tags_without_partner[$term->term_id] = [
                                    'term_id'  => $term->term_id,
                                    'name'      => $term->name,
                                    'edit_link' => (!is_wp_error($edit_link) && !empty($edit_link)) ? $edit_link : '',
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

                    <div class="bokun-booking-dashboard__toggles" role="group" aria-label="<?php esc_attr_e('Booking status toggles', 'BOKUN_txt_domain'); ?>">
                        <span class="bokun-booking-dashboard__toggle-label"><?php esc_html_e('Result:', 'BOKUN_txt_domain'); ?></span>
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
                        <?php endif; ?>
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

                    <?php if (!empty($status_labels) || !empty($viator_url) || !empty($bokun_url)) : ?>
                        <div class="bokun-booking-dashboard__meta-line">
                            <?php if (!empty($status_labels)) : ?>
                                <ul class="bokun-booking-dashboard__status-list">
                                    <?php foreach ($status_labels as $status_label) : ?>
                                        <li class="bokun-booking-dashboard__status-item"><?php echo esc_html($status_label); ?></li>
                                    <?php endforeach; ?>
                                </ul>
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

                if ($requires_refund_followup) {
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
                        <a href="#" class="bokun-booking-dashboard__toolbar-link bokun_fetch_booking_data_front" role="button">
                            <?php esc_html_e('Fetch bookings', 'BOKUN_txt_domain'); ?>
                        </a>
                    </div>
                    <div class="bokun-booking-dashboard__toolbar-group bokun-booking-dashboard__toolbar-group--right">
                        <a
                            href="#"
                            class="bokun-booking-dashboard__toolbar-link"
                            data-dashboard-conversations-toggle
                            aria-haspopup="dialog"
                            aria-expanded="false"
                        >
                            <?php esc_html_e('Common conversations', 'BOKUN_txt_domain'); ?>
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
                <div class="bokun-booking-dashboard__conversations-overlay" data-dashboard-conversations-overlay hidden aria-hidden="true"></div>
                <div class="bokun-booking-dashboard__conversations" data-dashboard-conversations hidden aria-hidden="true" tabindex="-1">
                    <div class="bokun-booking-dashboard__conversations-header">
                        <h3><?php esc_html_e('Common conversations', 'BOKUN_txt_domain'); ?></h3>
                        <a href="#" class="bokun-booking-dashboard__conversations-close" data-dashboard-conversations-close role="button" aria-label="<?php esc_attr_e('Close common conversations', 'BOKUN_txt_domain'); ?>">&times;</a>
                    </div>
                    <div class="bokun-booking-dashboard__conversations-body">
                        <section class="bokun-booking-dashboard__conversation">
                            <h4><?php esc_html_e('Meeting point', 'BOKUN_txt_domain'); ?></h4>
                            <p><?php esc_html_e('Hello, we hope this message finds you well.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Here is the exact meeting point for your tour:', 'BOKUN_txt_domain'); ?></p>
                            <p><strong><?php esc_html_e('Piazzale Montelungo', 'BOKUN_txt_domain'); ?></strong></p>
                            <p>
                                <a href="<?php echo esc_url($meeting_point_map_url); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html($meeting_point_map_url); ?>
                                </a>
                            </p>
                            <p><?php esc_html_e('You will find us in fuchsia shirts.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Please make sure to be there at least 15 minutes before the tour starts.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Best regards', 'BOKUN_txt_domain'); ?></p>
                        </section>
                        <section class="bokun-booking-dashboard__conversation">
                            <h4><?php esc_html_e('Alternative date', 'BOKUN_txt_domain'); ?></h4>
                            <p><?php esc_html_e('Hello, we hope this message finds you well.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Unfortunately this tour is not available for Day and Month. We apologize for any inconvenience caused by this. The first available date will be Day and Month.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Let us know if that works for you. If not, we have to cancel the reservation with a full refund.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Thank you for understanding and patience.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Best regards', 'BOKUN_txt_domain'); ?></p>
                        </section>
                        <section class="bokun-booking-dashboard__conversation">
                            <h4><?php esc_html_e('Tour not available', 'BOKUN_txt_domain'); ?></h4>
                            <p><?php esc_html_e('Hello, we hope this message finds you well.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Unfortunately this tour is not available for Day and Month. We apologize for the inconvenience but we have to cancel the reservation with a full refund.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Thank you for understanding and patience.', 'BOKUN_txt_domain'); ?></p>
                            <p><?php esc_html_e('Best regards', 'BOKUN_txt_domain'); ?></p>
                        </section>
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
                        <h3 id="<?php echo esc_attr($history_title_id); ?>"><?php esc_html_e('Booking history', 'BOKUN_txt_domain'); ?></h3>
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
                                        <form class="bokun-booking-dashboard__missing-tag-form" data-partner-tag-form data-term-id="<?php echo esc_attr($term_id); ?>">
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

                    applyFilters();
                })();
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
