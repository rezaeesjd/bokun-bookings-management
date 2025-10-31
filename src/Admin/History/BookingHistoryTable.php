<?php

namespace Bokun\Bookings\Admin\History;

use Bokun\Bookings\Infrastructure\Validation\DataSanitizer;
use DateTimeImmutable;
use DateTimeZone;
use WP_List_Table;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BookingHistoryTable extends WP_List_Table
{
    /**
     * @var DataSanitizer
     */
    private $sanitizer;

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private $filterOptions = [];

    /**
     * @var array<string, string>
     */
    private $filters = [];

    /**
     * @var string
     */
    private $pageSlug = '';

    /**
     * @param DataSanitizer $sanitizer
     */
    public function __construct(DataSanitizer $sanitizer)
    {
        parent::__construct([
            'singular' => 'booking-history-entry',
            'plural'   => 'booking-history-entries',
            'ajax'     => false,
        ]);

        $this->sanitizer = $sanitizer;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $options
     */
    public function setFilterOptions(array $options)
    {
        $this->filterOptions = $options;
    }

    /**
     * @param array<string, string> $filters
     */
    public function setActiveFilters(array $filters)
    {
        $this->filters = $filters;
    }

    /**
     * @param string $slug
     */
    public function setPageSlug($slug)
    {
        $this->pageSlug = (string) $slug;
    }

    /**
     * {@inheritDoc}
     */
    public function prepare_items()
    {
        $context = $this->buildQueryContext();

        if (! $context['exists']) {
            $this->items = [];
            $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
            return;
        }

        $perPage = $this->get_items_per_page('bokun_booking_history_per_page', 20);
        $currentPage = max(1, (int) $this->get_pagenum());
        $offset = ($currentPage - 1) * $perPage;

        list($orderBy, $order) = $this->resolveRequestedOrder();

        $totalItems = $this->countItems($context['table'], $context['where'], $context['params']);
        $rawItems = $this->fetchRows($context['table'], $context['where'], $context['params'], $orderBy, $order, $perPage, $offset);

        $items = [];
        foreach ((array) $rawItems as $row) {
            $items[] = $this->transformRow($row);
        }

        $this->items = $items;

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $this->set_pagination_args([
            'total_items' => (int) $totalItems,
            'per_page'    => (int) $perPage,
            'total_pages' => $perPage > 0 ? (int) ceil($totalItems / $perPage) : 0,
        ]);
    }

    public function exportToCsv($filename, $batchSize = 500)
    {
        $context = $this->buildQueryContext();

        if (! $context['exists']) {
            wp_die(esc_html__('The booking history table is not available for export.', BOKUN_TEXT_DOMAIN));
        }

        if (! is_numeric($batchSize) || $batchSize <= 0) {
            $batchSize = 500;
        }

        $batchSize = (int) apply_filters('bokun_booking_history_export_batch_size', $batchSize);

        if ($batchSize <= 0) {
            $batchSize = 500;
        }

        list($orderBy, $order) = $this->resolveRequestedOrder();

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');

        $output = fopen('php://output', 'w');
        if (! $output) {
            wp_die(esc_html__('Unable to open output stream for export.', BOKUN_TEXT_DOMAIN));
        }

        // Output UTF-8 BOM for compatibility with Excel.
        fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $columns = $this->get_columns();
        fputcsv($output, array_values($columns));

        $offset = 0;

        do {
            $rows = $this->fetchRows($context['table'], $context['where'], $context['params'], $orderBy, $order, (int) $batchSize, $offset);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $item = $this->transformRow($row);
                $line = [];
                foreach (array_keys($columns) as $columnKey) {
                    $value = isset($item[$columnKey]) ? $item[$columnKey] : '';

                    if ('booking_id' === $columnKey && isset($row['booking_id'])) {
                        $value = $row['booking_id'];
                    }

                    $line[] = is_scalar($value) ? $value : '';
                }
                fputcsv($output, $line);
            }

            $offset += count($rows);
        } while (count($rows) === (int) $batchSize);

        fclose($output);
        exit;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function transformRow(array $row)
    {
        $timestamp = isset($row['created_at']) ? strtotime($row['created_at']) : false;
        $formattedDate = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '';

        $actionLabel = ucwords(str_replace('-', ' ', (string) $row['action_type']));
        $statusLabel = ! empty($row['is_checked']) ? __('Checked', BOKUN_TEXT_DOMAIN) : __('Unchecked', BOKUN_TEXT_DOMAIN);

        $actorLabel = $this->prepareActorLabel($row);
        $sourceLabel = $this->prepareSourceLabel($row['actor_source']);

        $bookingLink = '';
        if (! empty($row['post_id'])) {
            $bookingLink = get_edit_post_link((int) $row['post_id']);
        }

        return [
            'id'           => isset($row['id']) ? (int) $row['id'] : 0,
            'date'         => $formattedDate,
            'booking_id'   => isset($row['booking_id']) ? $row['booking_id'] : '',
            'booking_link' => $bookingLink,
            'action'       => $actionLabel,
            'status'       => $statusLabel,
            'actor'        => $actorLabel,
            'source'       => $sourceLabel,
        ];
    }

    private function prepareSourceLabel($source)
    {
        switch ($source) {
            case 'wp_user':
                return __('WordPress User', BOKUN_TEXT_DOMAIN);
            case 'team_member':
                return __('Team Member', BOKUN_TEXT_DOMAIN);
            case 'guest':
            default:
                return __('Guest', BOKUN_TEXT_DOMAIN);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function prepareActorLabel(array $row)
    {
        $label = isset($row['user_name']) ? trim((string) $row['user_name']) : '';

        if ('' !== $label) {
            return $label;
        }

        if (! empty($row['user_id'])) {
            $user = get_user_by('id', (int) $row['user_id']);
            if ($user) {
                return $user->display_name ? $user->display_name : $user->user_login;
            }
        }

        return __('Unknown', BOKUN_TEXT_DOMAIN);
    }

    /**
     * {@inheritDoc}
     */
    public function get_columns()
    {
        return [
            'date'       => __('Date', BOKUN_TEXT_DOMAIN),
            'booking_id' => __('Booking ID', BOKUN_TEXT_DOMAIN),
            'action'     => __('Action', BOKUN_TEXT_DOMAIN),
            'status'     => __('Status', BOKUN_TEXT_DOMAIN),
            'actor'      => __('Actor', BOKUN_TEXT_DOMAIN),
            'source'     => __('Source', BOKUN_TEXT_DOMAIN),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function get_sortable_columns()
    {
        return [
            'date'       => ['created_at', true],
            'booking_id' => ['booking_id', false],
            'action'     => ['action_type', false],
            'status'     => ['is_checked', false],
            'actor'      => ['user_name', false],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'booking_id':
                if (! empty($item['booking_link'])) {
                    return sprintf(
                        '<a href="%s">%s</a>',
                        esc_url($item['booking_link']),
                        esc_html($item['booking_id'])
                    );
                }

                return esc_html($item['booking_id']);
            case 'date':
            case 'action':
            case 'status':
            case 'actor':
            case 'source':
                return esc_html($item[$column_name]);
            default:
                return isset($item[$column_name]) ? esc_html((string) $item[$column_name]) : '';
        }
    }

    /**
     * {@inheritDoc}
     */
    public function no_items()
    {
        esc_html_e('No booking activity has been recorded yet.', BOKUN_TEXT_DOMAIN);
    }

    /**
     * {@inheritDoc}
     */
    protected function extra_tablenav($which)
    {
        if ('top' !== $which) {
            return;
        }

        echo '<div class="alignleft actions">';

        foreach ($this->filterOptions as $filterKey => $options) {
            $selectName = sprintf('bokun_history_%s', $filterKey);
            $selected   = isset($this->filters[$filterKey]) ? $this->filters[$filterKey] : '';

            echo '<label class="screen-reader-text" for="' . esc_attr($selectName) . '">';
            printf(esc_html__('Filter by %s', BOKUN_TEXT_DOMAIN), esc_html(ucfirst($filterKey)));
            echo '</label>';

            echo '<select name="' . esc_attr($selectName) . '" id="' . esc_attr($selectName) . '" class="postform">';
            echo '<option value="">' . esc_html__('All', BOKUN_TEXT_DOMAIN) . '</option>';

            foreach ($options as $value => $definition) {
                $label = isset($definition['label']) ? $definition['label'] : $value;
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($value),
                    selected($selected, $value, false),
                    esc_html($label)
                );
            }

            echo '</select>';
        }

        echo '</div>';

        $dateStart = isset($this->filters['date_start']) ? $this->filters['date_start'] : '';
        $dateEnd   = isset($this->filters['date_end']) ? $this->filters['date_end'] : '';

        echo '<div class="alignleft actions">';
        echo '<label for="bokun_history_date_start" class="screen-reader-text">' . esc_html__('Filter from date', BOKUN_TEXT_DOMAIN) . '</label>';
        echo '<input type="date" id="bokun_history_date_start" name="bokun_history_date_start" value="' . esc_attr($dateStart) . '" />';
        echo '</div>';

        echo '<div class="alignleft actions">';
        echo '<label for="bokun_history_date_end" class="screen-reader-text">' . esc_html__('Filter to date', BOKUN_TEXT_DOMAIN) . '</label>';
        echo '<input type="date" id="bokun_history_date_end" name="bokun_history_date_end" value="' . esc_attr($dateEnd) . '" />';
        echo '</div>';

        echo '<div class="alignleft actions">';
        submit_button(__('Filter'), '', 'filter_action', false);
        echo ' <button type="submit" class="button action" name="bokun_history_export" value="csv">' . esc_html__('Export CSV', BOKUN_TEXT_DOMAIN) . '</button>';

        $resetUrl = $this->pageSlug ? remove_query_arg(
            ['bokun_history_action', 'bokun_history_status', 'bokun_history_actor', 'bokun_history_source', 'bokun_history_date_start', 'bokun_history_date_end', 's', 'orderby', 'order', 'paged'],
            add_query_arg('page', $this->pageSlug, admin_url('admin.php'))
        ) : '';

        if ($resetUrl) {
            echo ' <a class="button" href="' . esc_url($resetUrl) . '">' . esc_html__('Reset', BOKUN_TEXT_DOMAIN) . '</a>';
        }

        echo '</div>';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQueryContext()
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'bokun_booking_history';
        $likeName  = $wpdb->esc_like($tableName);
        $tableExists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $likeName)) === $tableName);

        $context = [
            'table'  => $tableName,
            'exists' => $tableExists,
            'where'  => '',
            'params' => [],
        ];

        if (! $tableExists) {
            return $context;
        }

        $where   = [];
        $params  = [];

        $filters = $this->filters;

        if (! empty($filters['action']) && isset($this->filterOptions['action'][$filters['action']])) {
            $action = $this->filterOptions['action'][$filters['action']];
            $where[] = 'action_type = %s';
            $params[] = $action['query']['action_type'];
        }

        if (! empty($filters['status']) && isset($this->filterOptions['status'][$filters['status']])) {
            $status = $this->filterOptions['status'][$filters['status']];
            $where[] = 'is_checked = %d';
            $params[] = (int) $status['query']['is_checked'];
        }

        if (! empty($filters['source']) && isset($this->filterOptions['source'][$filters['source']])) {
            $source = $this->filterOptions['source'][$filters['source']];
            $where[] = 'actor_source = %s';
            $params[] = $source['query']['actor_source'];
        }

        if (! empty($filters['actor']) && isset($this->filterOptions['actor'][$filters['actor']])) {
            $actor = $this->filterOptions['actor'][$filters['actor']];
            if (! empty($actor['query']['user_name'])) {
                $where[] = 'user_name = %s';
                $params[] = $actor['query']['user_name'];
            } elseif (! empty($actor['query']['user_id'])) {
                $where[] = 'user_id = %d';
                $params[] = (int) $actor['query']['user_id'];
            } else {
                $where[] = '(user_name IS NULL OR user_name = \'\')';
            }
        }

        if (! empty($filters['date_start'])) {
            $date = $this->normalizeDateBoundary($filters['date_start'], false);
            if ($date) {
                $where[] = 'created_at >= %s';
                $params[] = $date;
            }
        }

        if (! empty($filters['date_end'])) {
            $date = $this->normalizeDateBoundary($filters['date_end'], true);
            if ($date) {
                $where[] = 'created_at <= %s';
                $params[] = $date;
            }
        }

        $search = isset($_REQUEST['s']) ? $this->sanitizer->text(wp_unslash($_REQUEST['s']), '') : '';
        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(booking_id LIKE %s OR user_name LIKE %s OR action_type LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (! empty($where)) {
            $context['where'] = 'WHERE ' . implode(' AND ', $where);
        }

        $context['params'] = $params;

        return $context;
    }

    private function countItems($tableName, $whereSql, array $params)
    {
        global $wpdb;

        $query = "SELECT COUNT(*) FROM {$tableName} {$whereSql}";

        if (! empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        return (int) $wpdb->get_var($query);
    }

    private function fetchRows($tableName, $whereSql, array $params, $orderBy, $order, $limit, $offset)
    {
        global $wpdb;

        $orderBy = $this->normalizeOrderBy($orderBy);
        $order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

        $query = "SELECT id, post_id, booking_id, action_type, is_checked, user_id, user_name, actor_source, created_at
            FROM {$tableName}
            {$whereSql}
            ORDER BY {$orderBy} {$order}
            LIMIT %d OFFSET %d";

        $queryParams = $params;
        $queryParams[] = (int) $limit;
        $queryParams[] = (int) $offset;

        return (array) $wpdb->get_results($wpdb->prepare($query, $queryParams), ARRAY_A);
    }

    private function normalizeOrderBy($orderBy)
    {
        $allowedOrderBys = [
            'created_at' => 'created_at',
            'booking_id' => 'booking_id',
            'action_type' => 'action_type',
            'is_checked' => 'is_checked',
            'user_name' => 'user_name',
        ];

        return isset($allowedOrderBys[$orderBy]) ? $allowedOrderBys[$orderBy] : $allowedOrderBys['created_at'];
    }

    private function resolveRequestedOrder()
    {
        $orderByRequest = isset($_REQUEST['orderby']) ? $this->sanitizer->key(wp_unslash($_REQUEST['orderby']), 'created_at') : 'created_at';
        $orderRequest = isset($_REQUEST['order']) ? strtoupper($this->sanitizer->key(wp_unslash($_REQUEST['order']), 'DESC')) : 'DESC';

        if (! in_array($orderRequest, ['ASC', 'DESC'], true)) {
            $orderRequest = 'DESC';
        }

        $orderBy = $this->normalizeOrderBy($orderByRequest);

        return [$orderBy, $orderRequest];
    }

    private function normalizeDateBoundary($value, $isUpper)
    {
        $value = $this->sanitizer->text($value, '');

        if ('' === $value) {
            return '';
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, $timezone);

        if (! $date) {
            return '';
        }

        if ($isUpper) {
            $date = $date->setTime(23, 59, 59);
        } else {
            $date = $date->setTime(0, 0, 0);
        }

        $utcDate = $date->setTimezone(new DateTimeZone('UTC'));

        return $utcDate->format('Y-m-d H:i:s');
    }
}
