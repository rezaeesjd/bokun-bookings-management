(function ($) {
    'use strict';

    function initBookingHistoryTable(context) {
        var $tables = $('.bokun-booking-history-table', context || document);

        if (!$tables.length || typeof $.fn.DataTable !== 'function') {
            return;
        }

        $tables.each(function () {
            var $table = $(this);

            if ($table.hasClass('bokun-booking-history-initialized')) {
                return;
            }

            $table.addClass('bokun-booking-history-initialized');

            var $wrapper = $table.closest('.bokun-booking-history');
            var localized = typeof window.bokunBookingHistory !== 'undefined' ? window.bokunBookingHistory : null;
            var exportTitle = ($wrapper.data('export-title') || (localized && localized.exportTitle)) || 'booking-history';
            var texts = localized && localized.texts ? localized.texts : {};
            var language = localized && localized.language ? localized.language : {};

            $table.DataTable({
                dom: '<"bokun-history-toolbar"Bfrtip>',
                order: [[0, 'desc']],
                buttons: [
                    {
                        extend: 'csvHtml5',
                        title: exportTitle,
                        className: 'button button-secondary',
                        text: texts.downloadCsv || 'Download CSV',
                        exportOptions: {
                            columns: ':visible'
                        }
                    }
                ],
                responsive: true,
                language: language,
                initComplete: function () {
                    $table.attr('aria-live', 'polite');
                }
            });
        });
    }

    $(function () {
        initBookingHistoryTable(document);
    });
})(jQuery);
