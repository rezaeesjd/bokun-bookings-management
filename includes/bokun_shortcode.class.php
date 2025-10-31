<?php
if (! defined('ABSPATH')) {
    exit;
}

use Bokun\Bookings\Presentation\Shortcode\BookingShortcode;
use Bokun\Bookings\Plugin;

if (! class_exists('BOKUN_Shortcode')) {
    class BOKUN_Shortcode
    {
        /**
         * @var BookingShortcode|null
         */
        private $shortcode;

        public function __construct()
        {
            $this->shortcode = $this->resolveShortcode();
        }

        public function function_bokun_fetch_button()
        {
            if ($this->shortcode) {
                return $this->shortcode->renderFetchButton();
            }

            return '';
        }

        public function render_booking_history_table($atts = [])
        {
            if ($this->shortcode) {
                return $this->shortcode->renderBookingHistoryTable($atts);
            }

            return '';
        }

        public function render_booking_overview($atts = [])
        {
            if ($this->shortcode) {
                return $this->shortcode->renderBookingOverview($atts);
            }

            return '';
        }

        private function resolveShortcode()
        {
            $container = Plugin::getContainerInstance();

            if ($container) {
                try {
                    return $container->get('bokun.shortcode');
                } catch (\Throwable $exception) {
                    // Fall back to a direct instance below.
                }
            }

            if (class_exists(BookingShortcode::class)) {
                return new BookingShortcode();
            }

            return null;
        }
    }

    $GLOBALS['bokun_shortcode'] = new BOKUN_Shortcode();
}
