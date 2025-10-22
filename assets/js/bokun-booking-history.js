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
                pageLength: 40,
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

                    var api = this.api();
                    var $thead = $table.find('thead');
                    var $headerCells = $thead.find('tr').first().find('th');
                    var $existingFilterRow = $thead.find('.bokun-booking-history-filters');

                    if ($existingFilterRow.length) {
                        $existingFilterRow.remove();
                    }

                    var $filterRow = $('<tr class="bokun-booking-history-filters" role="row"></tr>');

                    api.columns().every(function () {
                        var column = this;
                        var columnIndex = column.index();
                        var $cell = $('<th scope="col"></th>');

                        if (columnIndex === 1) {
                            $cell.addClass('bokun-booking-history-filter-disabled');
                            $filterRow.append($cell);
                            return;
                        }

                        var columnTitle = $headerCells.eq(columnIndex).text();
                        var placeholderBase = (texts && texts.filterPlaceholder) || (texts && texts.filterLabel) || '';
                        var placeholder = placeholderBase ? placeholderBase.replace('%s', columnTitle) : 'Filter ' + columnTitle;
                        var $input = $('<input>', {
                            type: 'text',
                            class: 'bokun-booking-history-filter',
                            'aria-label': 'Filter ' + columnTitle,
                            placeholder: placeholder
                        });

                        $input.on('input change', function () {
                            var value = this.value;
                            column.search(value, false, true).draw();
                        });

                        $cell.append($input);
                        $filterRow.append($cell);
                    });

                    $thead.append($filterRow);
                }
            });
        });
    }

    $(function () {
        initBookingHistoryTable(document);
    });
})(jQuery);
