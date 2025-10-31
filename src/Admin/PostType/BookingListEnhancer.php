<?php
namespace Bokun\Bookings\Admin\PostType;

use Bokun\Bookings\Infrastructure\Validation\DataSanitizer;

if (! defined('ABSPATH')) {
    exit;
}

class BookingListEnhancer
{
    /**
     * @var DataSanitizer
     */
    private $sanitizer;

    public function __construct(DataSanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    public function register()
    {
        add_filter('manage_edit-bokun_booking_columns', [$this, 'registerColumns']);
        add_action('manage_bokun_booking_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('manage_edit-bokun_booking_sortable_columns', [$this, 'registerSortableColumns']);
        add_action('pre_get_posts', [$this, 'applySorting']);
        add_action('restrict_manage_posts', [$this, 'renderFilters']);
        add_action('pre_get_posts', [$this, 'applyFilters']);
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function registerColumns($columns)
    {
        $insertion = [
            'confirmation_code' => __('Confirmation Code', BOKUN_TEXT_DOMAIN),
            'customer_name'     => __('Customer', BOKUN_TEXT_DOMAIN),
            'booking_status'    => __('Booking Status', BOKUN_TEXT_DOMAIN),
            'start_date'        => __('Start Date', BOKUN_TEXT_DOMAIN),
        ];

        $ordered = [];
        foreach ($columns as $key => $label) {
            $ordered[$key] = $label;
            if ('title' === $key) {
                $ordered = array_merge($ordered, $insertion);
                $insertion = [];
            }
        }

        if (! empty($insertion)) {
            $ordered = array_merge($ordered, $insertion);
        }

        return $ordered;
    }

    /**
     * @param string $column
     * @param int    $postId
     */
    public function renderColumn($column, $postId)
    {
        switch ($column) {
            case 'confirmation_code':
                $confirmation = get_post_meta($postId, '_confirmation_code', true);
                echo esc_html($confirmation);
                break;
            case 'customer_name':
                $firstName = get_post_meta($postId, '_first_name', true);
                $lastName  = get_post_meta($postId, '_last_name', true);
                $fullName  = trim($firstName . ' ' . $lastName);
                echo esc_html($fullName ? $fullName : __('Unknown', BOKUN_TEXT_DOMAIN));
                break;
            case 'booking_status':
                $status = get_post_meta($postId, '_booking_status_origin', true);
                if ($status) {
                    echo esc_html($status);
                } else {
                    $terms = wp_get_post_terms($postId, 'booking_status', ['fields' => 'names']);
                    echo esc_html(! empty($terms) ? implode(', ', $terms) : __('Not set', BOKUN_TEXT_DOMAIN));
                }
                break;
            case 'start_date':
                $startDate = get_post_meta($postId, '_original_start_date', true);
                if (! $startDate) {
                    $startDate = get_post_meta($postId, 'startDate', true);
                }
                echo esc_html($startDate ? $startDate : __('Not available', BOKUN_TEXT_DOMAIN));
                break;
        }
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function registerSortableColumns($columns)
    {
        $columns['confirmation_code'] = 'confirmation_code';
        $columns['start_date']        = 'start_date';
        $columns['booking_status']    = 'booking_status';

        return $columns;
    }

    public function applySorting($query)
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        if ('bokun_booking' !== $query->get('post_type')) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'confirmation_code':
                $query->set('meta_key', '_confirmation_code');
                $query->set('orderby', 'meta_value');
                break;
            case 'start_date':
                $query->set('meta_key', '_original_start_date');
                $query->set('orderby', 'meta_value');
                break;
            case 'booking_status':
                $query->set('meta_key', '_booking_status_origin');
                $query->set('orderby', 'meta_value');
                break;
        }
    }

    public function renderFilters($postType)
    {
        if ('bokun_booking' !== $postType) {
            return;
        }

        $statusValue = isset($_GET['bokun_booking_status']) ? $this->sanitizer->text(wp_unslash($_GET['bokun_booking_status']), '') : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $startValue  = isset($_GET['bokun_booking_month']) ? $this->sanitizer->key(wp_unslash($_GET['bokun_booking_month']), '') : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $statuses = $this->getDistinctStatuses();

        echo '<select name="bokun_booking_status" class="postform">';
        echo '<option value="">' . esc_html__('All booking statuses', BOKUN_TEXT_DOMAIN) . '</option>';
        foreach ($statuses as $status) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($status),
                selected($statusValue, $status, false),
                esc_html($status)
            );
        }
        echo '</select>';

        $months = $this->getAvailableMonths();
        echo '<select name="bokun_booking_month" class="postform">';
        echo '<option value="">' . esc_html__('All start months', BOKUN_TEXT_DOMAIN) . '</option>';
        foreach ($months as $monthKey => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($monthKey),
                selected($startValue, $monthKey, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    public function applyFilters($query)
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        if ('bokun_booking' !== $query->get('post_type')) {
            return;
        }

        $metaQuery = (array) $query->get('meta_query');

        if (isset($_GET['bokun_booking_status'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $status = $this->sanitizer->text(wp_unslash($_GET['bokun_booking_status']), ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ('' !== $status) {
                $metaQuery[] = [
                    'key'   => '_booking_status_origin',
                    'value' => $status,
                ];
            }
        }

        if (isset($_GET['bokun_booking_month'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $month = $this->sanitizer->key(wp_unslash($_GET['bokun_booking_month']), ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ('' !== $month) {
                $metaQuery[] = [
                    'key'     => '_original_start_date',
                    'value'   => $month,
                    'compare' => 'LIKE',
                ];
            }
        }

        if (! empty($metaQuery)) {
            $query->set('meta_query', $metaQuery);
        }
    }

    /**
     * @return array<int, string>
     */
    private function getDistinctStatuses()
    {
        global $wpdb;

        $results = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_booking_status_origin' AND meta_value <> '' ORDER BY meta_value ASC"
        );

        return array_filter(array_map('trim', (array) $results));
    }

    /**
     * @return array<string, string>
     */
    private function getAvailableMonths()
    {
        global $wpdb;

        $dates = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_original_start_date' AND meta_value <> '' ORDER BY meta_value DESC"
        );

        $months = [];

        foreach ((array) $dates as $date) {
            $timestamp = strtotime($date);
            if (! $timestamp) {
                continue;
            }

            $key = gmdate('Y-m', $timestamp);
            $label = date_i18n('F Y', $timestamp);
            $months[$key] = $label;
        }

        return $months;
    }
}
