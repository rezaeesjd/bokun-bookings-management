<?php

namespace Bokun\Bookings\Registration\Database;

class BookingHistoryTableCreator
{
    public function create(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $tableName = $wpdb->prefix . 'bokun_booking_history';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$tableName} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NULL,
            booking_id VARCHAR(191) NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            is_checked TINYINT(1) NOT NULL DEFAULT 0,
            user_id BIGINT(20) UNSIGNED NULL,
            user_name VARCHAR(191) NULL,
            actor_source VARCHAR(50) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY booking_id (booking_id),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
}
