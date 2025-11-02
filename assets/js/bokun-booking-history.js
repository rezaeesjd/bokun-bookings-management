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
                    var tableNode = $table.get(0);
                    var tableId = $table.attr('id') || '';
                    var checkboxFilters = {};
                    var textFilters = {};
                    var filterColumns = {};

                    var applyFilters = function () {
                        api.draw();
                    };

                    var filterFn = function (settings, data) {
                        if (settings.nTable !== tableNode) {
                            return true;
                        }

                        var visible = true;

                        $.each(checkboxFilters, function (key, values) {
                            if (!values || !values.length || !visible) {
                                return;
                            }

                            var columnIndex = filterColumns[key];
                            if (typeof columnIndex !== 'number') {
                                return;
                            }

                            var cellText = (data[columnIndex] || '').toString().toLowerCase().trim();
                            var matches = false;

                            for (var i = 0; i < values.length; i += 1) {
                                if (cellText === values[i]) {
                                    matches = true;
                                    break;
                                }
                            }

                            if (!matches) {
                                visible = false;
                            }
                        });

                        if (!visible) {
                            return false;
                        }

                        $.each(textFilters, function (key, value) {
                            if (!value || !visible) {
                                return;
                            }

                            var columnIndex = filterColumns[key];
                            if (typeof columnIndex !== 'number') {
                                return;
                            }

                            var cellText = (data[columnIndex] || '').toString().toLowerCase();
                            if (cellText.indexOf(value) === -1) {
                                visible = false;
                            }
                        });

                        return visible;
                    };

                    $.fn.dataTable.ext.search.push(filterFn);

                    $table.on('destroy.dt', function () {
                        var index = $.fn.dataTable.ext.search.indexOf(filterFn);
                        if (index !== -1) {
                            $.fn.dataTable.ext.search.splice(index, 1);
                        }
                    });

                    var initializeFilterGroup = function ($filterGroup) {
                        if (!$filterGroup || !$filterGroup.length) {
                            return;
                        }

                        var targetTable = $filterGroup.data('targetTable');
                        if (targetTable && tableId && targetTable !== tableId) {
                            return;
                        }

                        if ($filterGroup.data('filtersInitialized')) {
                            return;
                        }

                        $filterGroup.data('filtersInitialized', true);

                        $filterGroup.find('.bokun-history-filter').each(function () {
                            var $filter = $(this);
                            var key = $filter.data('filterKey');
                            var columnIndex = parseInt($filter.data('filterColumn'), 10);

                            if (!key || isNaN(columnIndex)) {
                                return;
                            }

                            filterColumns[key] = columnIndex;

                            if (!checkboxFilters[key]) {
                                checkboxFilters[key] = [];
                            }

                            if (!textFilters[key]) {
                                textFilters[key] = '';
                            }

                            var $optionCheckboxes = $filter.find('input[type="checkbox"][data-filter-option]');
                            var $allCheckbox = $filter.find('input[type="checkbox"][data-filter-all]');
                            var $textInput = $filter.find('[data-filter-text]');
                            var $clearText = $filter.find('[data-filter-clear-text]');

                            var syncAllCheckbox = function () {
                                if (!$allCheckbox.length) {
                                    return;
                                }

                                var totalOptions = $optionCheckboxes.length;
                                var selectedCount = $optionCheckboxes.filter(':checked').length;

                                if (selectedCount === 0) {
                                    $allCheckbox.prop('checked', false).prop('indeterminate', false);
                                } else if (selectedCount === totalOptions) {
                                    $allCheckbox.prop('checked', true).prop('indeterminate', false);
                                } else {
                                    $allCheckbox.prop('checked', false).prop('indeterminate', true);
                                }
                            };

                            var updateCheckboxFilter = function () {
                                var values = [];

                                $optionCheckboxes.each(function () {
                                    if (!this.checked) {
                                        return;
                                    }

                                    var matchValue = $(this).data('filterMatch');
                                    if (typeof matchValue === 'undefined') {
                                        matchValue = $(this).closest('label').find('span').text();
                                    }

                                    if (typeof matchValue === 'string') {
                                        values.push(matchValue.toString().toLowerCase().trim());
                                    }
                                });

                                checkboxFilters[key] = values;
                                syncAllCheckbox();
                                applyFilters();
                            };

                            var updateTextFilter = function () {
                                if (!$textInput.length) {
                                    textFilters[key] = '';
                                    applyFilters();
                                    return;
                                }

                                var value = ($textInput.val() || '').toString().toLowerCase().trim();
                                textFilters[key] = value;
                                applyFilters();
                            };

                            $optionCheckboxes.on('change', updateCheckboxFilter);

                            if ($clearText.length && $textInput.length) {
                                $clearText.on('click', function (event) {
                                    event.preventDefault();
                                    $textInput.val('');
                                    updateTextFilter();
                                    $textInput.trigger('focus');
                                });
                            }

                            if ($allCheckbox.length) {
                                $allCheckbox.on('change', function (event) {
                                    event.preventDefault();

                                    var shouldCheck = $allCheckbox.is(':checked');
                                    $optionCheckboxes.prop('checked', shouldCheck);

                                    syncAllCheckbox();
                                    updateCheckboxFilter();
                                });
                            }

                            if ($textInput.length) {
                                $textInput.on('input change', updateTextFilter);
                            }

                            updateCheckboxFilter();
                            updateTextFilter();
                            syncAllCheckbox();
                        });
                    };

                    var $filterGroups = $wrapper.find('.bokun-history-filters');
                    if ($filterGroups.length) {
                        $filterGroups.each(function () {
                            initializeFilterGroup($(this));
                        });
                    }
                }
            });
        });
    }

    $(function () {
        initBookingHistoryTable(document);
    });
})(jQuery);
