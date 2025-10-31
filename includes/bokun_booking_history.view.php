<?php
if (! defined('ABSPATH')) {
    exit;
}

use Bokun\Bookings\Admin\History\BookingHistoryPage;
use Bokun\Bookings\Plugin;

$container = Plugin::getContainerInstance();

if ($container && $container->has('bokun.booking_history_page')) {
    try {
        $page = $container->get('bokun.booking_history_page');
        if ($page instanceof BookingHistoryPage) {
            $page->render();
            return;
        }
    } catch (\Throwable $exception) {
        // Fallback below if container resolution fails.
    }
}

if (! function_exists('bokun_get_data_sanitizer')) {
    include_once BOKUN_INCLUDES_DIR . 'bokun-bookings-manager.php';
}

$sanitizer = function_exists('bokun_get_data_sanitizer') ? bokun_get_data_sanitizer() : null;

if ($sanitizer instanceof \Bokun\Bookings\Infrastructure\Validation\DataSanitizer) {
    $page = new BookingHistoryPage($sanitizer);
    $page->render();
    return;
}

echo '<div class="wrap"><h1>' . esc_html__('Booking History', BOKUN_TEXT_DOMAIN) . '</h1>';
echo '<p>' . esc_html__('Booking history could not be loaded.', BOKUN_TEXT_DOMAIN) . '</p></div>';
