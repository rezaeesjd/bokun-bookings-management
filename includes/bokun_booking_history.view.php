<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$table_name   = $wpdb->prefix . 'bokun_booking_history';
$like_name    = $wpdb->esc_like($table_name);
$table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like_name)) === $table_name);
$logs         = [];
$processed_logs = [];
$filter_options = [
    'action' => [],
    'status' => [],
    'actor'  => [],
    'source' => [],
];
$filter_columns = [
    'action' => 2,
    'status' => 3,
    'actor'  => 4,
    'source' => 5,
];

if ($table_exists) {
    $limit = apply_filters('bokun_booking_history_page_limit', 100);
    $limit = is_numeric($limit) ? max(1, (int) $limit) : 100;

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
}

if (!empty($logs)) {
    foreach ($logs as $log) {
        $timestamp      = strtotime($log['created_at']);
        $formatted_date = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : $log['created_at'];
        $action_label   = ucwords(str_replace('-', ' ', $log['action_type']));
        $status_label   = $log['is_checked'] ? __('Checked', 'BOKUN_txt_domain') : __('Unchecked', 'BOKUN_txt_domain');
        $actor_label    = $log['user_name'];

        if (empty($actor_label) && !empty($log['user_id'])) {
            $user = get_user_by('id', (int) $log['user_id']);
            if ($user) {
                $actor_label = $user->display_name ? $user->display_name : $user->user_login;
            }
        }

        if (empty($actor_label)) {
            $actor_label = __('Unknown', 'BOKUN_txt_domain');
        }

        $source_label = '';

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

        $booking_link = '';

        if (!empty($log['post_id'])) {
            $booking_link = get_edit_post_link((int) $log['post_id']);
        }

        $processed_logs[] = [
            'date'         => $formatted_date,
            'booking_id'   => $log['booking_id'],
            'booking_link' => $booking_link,
            'action_label' => $action_label,
            'action_value' => $action_value,
            'status_label' => $status_label,
            'status_value' => $status_value,
            'actor_label'  => $actor_label,
            'actor_value'  => $actor_value,
            'source_label' => $source_label,
            'source_value' => $source_value,
        ];
    }

    foreach ($filter_options as $key => $options) {
        if (!empty($options)) {
            natcasesort($options);
            $filter_options[$key] = $options;
        }
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e('Booking History', 'BOKUN_txt_domain'); ?></h1>

    <?php if (!$table_exists) : ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('The booking history table does not exist. Please reactivate the plugin to recreate it.', 'BOKUN_txt_domain'); ?></p>
        </div>
    <?php elseif (empty($processed_logs)) : ?>
        <p><?php esc_html_e('No booking activity has been recorded yet.', 'BOKUN_txt_domain'); ?></p>
    <?php else : ?>
        <p><?php printf(esc_html__('Showing the latest %d booking history entries.', 'BOKUN_txt_domain'), absint(count($processed_logs))); ?></p>

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

        <?php
        $filter_labels = [
            'action' => __('Action', 'BOKUN_txt_domain'),
            'status' => __('Status', 'BOKUN_txt_domain'),
            'actor'  => __('Actor', 'BOKUN_txt_domain'),
            'source' => __('Source', 'BOKUN_txt_domain'),
        ];
        ?>

        <?php $filter_index = 0; ?>
        <div class="bokun-history-filters" data-target-table="bokun-booking-history-table" aria-label="<?php esc_attr_e('Booking history filters', 'BOKUN_txt_domain'); ?>">
            <?php foreach ($filter_options as $filter_key => $options) :
                if (empty($options)) {
                    continue;
                }
                $filter_index++;
                $search_id = sanitize_html_class('bokun-history-filter-' . $filter_key . '-search-' . $filter_index);
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

        <table class="widefat fixed striped" id="bokun-booking-history-table">
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
                    <tr class="bokun-booking-history-row"
                        data-action="<?php echo esc_attr($log['action_value']); ?>"
                        data-status="<?php echo esc_attr($log['status_value']); ?>"
                        data-actor="<?php echo esc_attr($log['actor_value']); ?>"
                        data-source="<?php echo esc_attr($log['source_value']); ?>">
                        <td><?php echo esc_html($log['date']); ?></td>
                        <td>
                            <?php if (!empty($log['booking_link'])) : ?>
                                <a href="<?php echo esc_url($log['booking_link']); ?>"><?php echo esc_html($log['booking_id']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($log['booking_id']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($log['action_label']); ?></td>
                        <td><?php echo esc_html($log['status_label']); ?></td>
                        <td><?php echo esc_html($log['actor_label']); ?></td>
                        <td><?php echo esc_html($log['source_label']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const filters = document.querySelectorAll('.bokun-history-filter');
                const table = document.getElementById('bokun-booking-history-table');
                const rows = table ? Array.from(table.querySelectorAll('tbody tr.bokun-booking-history-row')) : [];
                const activeFilters = new Map();
                const textFilters = new Map();
                const filterColumns = new Map();

                const applyFilters = function () {
                    rows.forEach(function (row) {
                        let visible = true;

                        activeFilters.forEach(function (values, key) {
                            if (!values.length || !visible) {
                                return;
                            }

                            const rowValue = row.dataset[key] || '';
                            if (!values.includes(rowValue)) {
                                visible = false;
                            }
                        });

                        if (visible) {
                            textFilters.forEach(function (value, key) {
                                if (!value || !visible) {
                                    return;
                                }

                                const columnIndexAttr = filterColumns.get(key);
                                if (typeof columnIndexAttr !== 'number') {
                                    return;
                                }

                                const cells = row.getElementsByTagName('td');
                                const cell = cells && cells.length > columnIndexAttr ? cells[columnIndexAttr] : null;
                                const cellText = cell ? cell.textContent.toLowerCase() : '';

                                if (cellText.indexOf(value) === -1) {
                                    visible = false;
                                }
                            });
                        }

                        row.style.display = visible ? '' : 'none';
                    });
                };

                filters.forEach(function (filter) {
                    const key = filter.getAttribute('data-filter-key');
                    if (!key) {
                        return;
                    }

                    const columnIndexAttr = filter.getAttribute('data-filter-column');
                    const columnIndex = columnIndexAttr !== null ? parseInt(columnIndexAttr, 10) : NaN;
                    if (!isNaN(columnIndex)) {
                        filterColumns.set(key, columnIndex);
                    }

                    const optionCheckboxes = Array.from(filter.querySelectorAll('input[type="checkbox"][data-filter-option]'));
                    const allCheckbox = filter.querySelector('input[type="checkbox"][data-filter-all]');
                    const textInput = filter.querySelector('[data-filter-text]');
                    const clearTextButton = filter.querySelector('[data-filter-clear-text]');

                    const syncAllCheckbox = function () {
                        if (!allCheckbox) {
                            return;
                        }

                        const totalOptions = optionCheckboxes.length;
                        const selectedCount = optionCheckboxes.filter(function (checkbox) {
                            return checkbox.checked;
                        }).length;

                        if (selectedCount === 0) {
                            allCheckbox.checked = false;
                            allCheckbox.indeterminate = false;
                        } else if (selectedCount === totalOptions) {
                            allCheckbox.checked = true;
                            allCheckbox.indeterminate = false;
                        } else {
                            allCheckbox.checked = false;
                            allCheckbox.indeterminate = true;
                        }
                    };

                    const updateFilterState = function () {
                        const selectedValues = optionCheckboxes
                            .filter(function (checkbox) {
                                return checkbox.checked;
                            })
                            .map(function (checkbox) {
                                return checkbox.value;
                            });

                        activeFilters.set(key, selectedValues);
                        syncAllCheckbox();
                        applyFilters();
                    };

                    const updateTextFilter = function () {
                        if (!textInput) {
                            return;
                        }

                        const value = textInput.value ? textInput.value.trim().toLowerCase() : '';
                        textFilters.set(key, value);
                        applyFilters();
                    };

                    optionCheckboxes.forEach(function (checkbox) {
                        checkbox.addEventListener('change', updateFilterState);
                    });

                    if (textInput) {
                        textInput.addEventListener('input', updateTextFilter);
                        textInput.addEventListener('change', updateTextFilter);
                    }

                    if (clearTextButton && textInput) {
                        clearTextButton.addEventListener('click', function (event) {
                            event.preventDefault();
                            textInput.value = '';
                            updateTextFilter();
                            textInput.focus();
                        });
                    }

                    if (allCheckbox) {
                        allCheckbox.addEventListener('change', function (event) {
                            event.preventDefault();
                            const shouldCheck = allCheckbox.checked;

                            optionCheckboxes.forEach(function (checkbox) {
                                checkbox.checked = shouldCheck;
                            });

                            syncAllCheckbox();
                            updateFilterState();
                        });
                    }

                    updateFilterState();
                    updateTextFilter();
                    syncAllCheckbox();
                });
            });
        </script>
    <?php endif; ?>
</div>
