<?php
if( !class_exists ( 'BOKUN_Shortcode' ) ) {

    class BOKUN_Shortcode {

        function __construct(){

            add_shortcode('bokun_fetch_button', array($this, "function_bokun_fetch_button" ) );
            add_shortcode('bokun_booking_history', array($this, 'render_booking_history_table'));

        }

        
        function function_bokun_fetch_button() {
            ob_start();
            ?>
            <div class="bokun-fetch-wrapper">
                <button class="button button-primary bokun_fetch_booking_data_front">Fetch</button>
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
                                    <input type="text" id="<?php echo esc_attr($search_id); ?>" class="bokun-history-filter-text" data-filter-text placeholder="<?php echo esc_attr($search_label); ?>" />
                                </div>
                                <div class="bokun-history-filter-actions">
                                    <button type="button" class="button" data-filter-select-all><?php esc_html_e('Select All', 'BOKUN_txt_domain'); ?></button>
                                    <button type="button" class="button" data-filter-clear><?php esc_html_e('Clear', 'BOKUN_txt_domain'); ?></button>
                                </div>
                                <div class="bokun-history-filter-options">
                                    <ul>
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
                                                    <input type="checkbox" value="<?php echo esc_attr($option_value); ?>" data-filter-match="<?php echo esc_attr($option_match); ?>" checked />
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
                        min-width: 180px;
                    }

                    .bokun-history-filter details {
                        border: 1px solid #dcdcde;
                        border-radius: 4px;
                        background: #fff;
                    }

                    .bokun-history-filter summary {
                        padding: 8px 12px;
                        cursor: pointer;
                        font-weight: 600;
                        list-style: none;
                    }

                    .bokun-history-filter summary::-webkit-details-marker {
                        display: none;
                    }

                    .bokun-history-filter summary:after {
                        content: '\25BC';
                        float: right;
                        font-size: 10px;
                        margin-top: 4px;
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

                    .bokun-history-filter-search input[type="text"] {
                        width: 100%;
                        border: 1px solid #dcdcde;
                        border-radius: 3px;
                        padding: 6px 8px;
                        font-size: 13px;
                    }

                    .bokun-history-filter-search input[type="text"]:focus {
                        border-color: #2271b1;
                        box-shadow: 0 0 0 1px rgba(34, 113, 177, 0.2);
                        outline: none;
                    }

                    .bokun-history-filter-actions {
                        display: flex;
                        gap: 8px;
                        padding: 8px 12px 0;
                    }

                    .bokun-history-filter-actions button {
                        background: #f6f7f7;
                        border: 1px solid #dcdcde;
                        border-radius: 3px;
                        cursor: pointer;
                        font-size: 12px;
                        padding: 4px 8px;
                    }

                    .bokun-history-filter-actions button:hover {
                        background: #fff;
                    }

                    .bokun-history-filter-options {
                        max-height: 200px;
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
                                <td data-title="<?php esc_attr_e('Date', 'BOKUN_txt_domain'); ?>"><?php echo esc_html($log['date']); ?></td>
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