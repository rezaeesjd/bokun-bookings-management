<?php

namespace Bokun\Bookings\Admin\History;

use Bokun\Bookings\Admin\Menu\AdminPageInterface;
use Bokun\Bookings\Infrastructure\Validation\DataSanitizer;

class BookingHistoryPage implements AdminPageInterface
{
    /**
     * @var DataSanitizer
     */
    private $sanitizer;

    /**
     * @var string
     */
    private $pageSlug;

    /**
     * @var string
     */
    private $title;

    /**
     * @var string
     */
    private $capability;

    public function __construct(DataSanitizer $sanitizer, $pageSlug = 'bokun_booking_history', $title = '', $capability = 'manage_options')
    {
        $this->sanitizer = $sanitizer;
        $this->pageSlug  = (string) $pageSlug;
        $this->title     = $title !== '' ? $title : __('Booking History', BOKUN_TEXT_DOMAIN);
        $this->capability = $capability !== '' ? $capability : 'manage_options';
    }

    public function render()
    {
        if (! current_user_can($this->capability)) {
            wp_die(esc_html__('You do not have permission to access this page.', BOKUN_TEXT_DOMAIN));
        }

        $table = new BookingHistoryTable($this->sanitizer);
        $table->setPageSlug($this->pageSlug);

        $filters = $this->getActiveFilters();
        $table->setActiveFilters($filters);
        $table->setFilterOptions($this->getFilterOptions());

        if ($this->isExportRequest()) {
            $this->maybeExportCsv($table);
            return;
        }

        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->getTitle()) . '</h1>';

        echo '<form method="get">';
        wp_nonce_field('bokun_history_export', 'bokun_history_export_nonce', true, true);
        echo '<input type="hidden" name="page" value="' . esc_attr($this->pageSlug) . '" />';

        $table->search_box(__('Search history', BOKUN_TEXT_DOMAIN), 'bokun-booking-history');
        $table->display();

        echo '</form>';
        echo '</div>';
    }

    public function getSlug(): string
    {
        return $this->pageSlug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    /**
     * @return array<string, string>
     */
    private function getActiveFilters()
    {
        $filters = [
            'action'     => '',
            'status'     => '',
            'actor'      => '',
            'source'     => '',
            'date_start' => '',
            'date_end'   => '',
        ];

        foreach ($filters as $key => $default) {
            $requestKey = sprintf('bokun_history_%s', $key);
            if (isset($_GET[$requestKey])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                if (in_array($key, ['date_start', 'date_end'], true)) {
                    $filters[$key] = $this->sanitizer->text(wp_unslash($_GET[$requestKey]), $default); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                } else {
                    $filters[$key] = $this->sanitizer->key(wp_unslash($_GET[$requestKey]), $default); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                }
            }
        }

        return $filters;
    }

    private function isExportRequest(): bool
    {
        if (! isset($_GET['bokun_history_export'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }

        return 'csv' === $this->sanitizer->key(wp_unslash($_GET['bokun_history_export']), ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    private function maybeExportCsv(BookingHistoryTable $table): void
    {
        if (! isset($_GET['bokun_history_export_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $nonce = $this->sanitizer->text(wp_unslash($_GET['bokun_history_export_nonce']), ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (! wp_verify_nonce($nonce, 'bokun_history_export')) {
            wp_die(esc_html__('Unable to export booking history because the request could not be verified.', BOKUN_TEXT_DOMAIN));
        }

        $filename = sprintf('bokun-booking-history-%s.csv', gmdate('Y-m-d-His'));
        $table->exportToCsv($filename);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function getFilterOptions()
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'bokun_booking_history';
        $likeName  = $wpdb->esc_like($tableName);
        $tableExists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $likeName)) === $tableName);

        if (! $tableExists) {
            return [];
        }

        $options = [
            'action' => [],
            'status' => [
                'checked' => [
                    'label' => __('Checked', BOKUN_TEXT_DOMAIN),
                    'query' => ['is_checked' => 1],
                ],
                'unchecked' => [
                    'label' => __('Unchecked', BOKUN_TEXT_DOMAIN),
                    'query' => ['is_checked' => 0],
                ],
            ],
            'actor'  => [],
            'source' => [],
        ];

        $actions = $wpdb->get_col("SELECT DISTINCT action_type FROM {$tableName} WHERE action_type <> '' ORDER BY action_type ASC");
        foreach ((array) $actions as $action) {
            $label = ucwords(str_replace('-', ' ', $action));
            $value = sanitize_title($label);
            $options['action'][$value] = [
                'label' => $label,
                'query' => [
                    'action_type' => $action,
                ],
            ];
        }

        $sources = $wpdb->get_col("SELECT DISTINCT actor_source FROM {$tableName} WHERE actor_source <> '' ORDER BY actor_source ASC");
        foreach ((array) $sources as $source) {
            switch ($source) {
                case 'wp_user':
                    $label = __('WordPress User', BOKUN_TEXT_DOMAIN);
                    break;
                case 'team_member':
                    $label = __('Team Member', BOKUN_TEXT_DOMAIN);
                    break;
                case 'guest':
                default:
                    $label = __('Guest', BOKUN_TEXT_DOMAIN);
                    break;
            }

            $value = sanitize_title($source);
            $options['source'][$value] = [
                'label' => $label,
                'query' => [
                    'actor_source' => $source,
                ],
            ];
        }

        $actors = $wpdb->get_results(
            "SELECT DISTINCT user_name, user_id FROM {$tableName} ORDER BY user_name ASC, user_id ASC",
            ARRAY_A
        );

        foreach ((array) $actors as $actor) {
            $name = isset($actor['user_name']) ? trim((string) $actor['user_name']) : '';
            $userId = isset($actor['user_id']) ? (int) $actor['user_id'] : 0;

            if ('' === $name && $userId > 0) {
                $user = get_user_by('id', $userId);
                if ($user) {
                    $name = $user->display_name ? $user->display_name : $user->user_login;
                }
            }

            if ('' === $name) {
                $name = __('Unknown', BOKUN_TEXT_DOMAIN);
            }

            $value = sanitize_title($name . '-' . $userId);

            $options['actor'][$value] = [
                'label' => $name,
                'query' => [
                    'user_name' => isset($actor['user_name']) ? $actor['user_name'] : '',
                    'user_id'   => $userId,
                ],
            ];
        }

        return $options;
    }
}
