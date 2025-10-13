<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$table_name   = $wpdb->prefix . 'bokun_booking_history';
$like_name    = $wpdb->esc_like($table_name);
$table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like_name)) === $table_name);
$logs         = [];

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
?>
<div class="wrap">
    <h1><?php esc_html_e('Booking History', 'BOKUN_txt_domain'); ?></h1>

    <?php if (!$table_exists) : ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('The booking history table does not exist. Please reactivate the plugin to recreate it.', 'BOKUN_txt_domain'); ?></p>
        </div>
    <?php elseif (empty($logs)) : ?>
        <p><?php esc_html_e('No booking activity has been recorded yet.', 'BOKUN_txt_domain'); ?></p>
    <?php else : ?>
        <p><?php printf(esc_html__('Showing the latest %d booking history entries.', 'BOKUN_txt_domain'), absint(count($logs))); ?></p>
        <table class="widefat fixed striped">
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
                <?php foreach ($logs as $log) :
                    $timestamp = strtotime($log['created_at']);
                    $formatted_date = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : $log['created_at'];
                    $action_label = ucwords(str_replace('-', ' ', $log['action_type']));
                    $status_label = $log['is_checked'] ? __('Checked', 'BOKUN_txt_domain') : __('Unchecked', 'BOKUN_txt_domain');
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
                ?>
                    <tr>
                        <td><?php echo esc_html($formatted_date); ?></td>
                        <td>
                            <?php if (!empty($log['post_id'])) :
                                $edit_link = get_edit_post_link((int) $log['post_id']);
                                if ($edit_link) : ?>
                                    <a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($log['booking_id']); ?></a>
                                <?php else :
                                    echo esc_html($log['booking_id']);
                                endif;
                            else :
                                echo esc_html($log['booking_id']);
                            endif; ?>
                        </td>
                        <td><?php echo esc_html($action_label); ?></td>
                        <td><?php echo esc_html($status_label); ?></td>
                        <td><?php echo esc_html($actor_label); ?></td>
                        <td><?php echo esc_html($source_label); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
