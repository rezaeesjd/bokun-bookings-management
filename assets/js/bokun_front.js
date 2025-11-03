jQuery(function ($) {

        var ajaxUrl = (typeof bokun_api_auth_vars !== 'undefined' && bokun_api_auth_vars.ajax_url) ? bokun_api_auth_vars.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
        var importProgressPollers = {};
        var progressPollInterval = 1000;
        var CONTEXT_DISPLAY_ORDER = ['fetch', 'upgrade'];

        function startImportProgressPolling(mode, options) {
                options = options || {};

                if (!mode || importProgressPollers[mode]) {
                        return;
                }

                if (!ajaxUrl) {
                        return;
                }

                var interval = Math.max(parseInt(options.interval, 10) || progressPollInterval, 500);
                var requestInFlight = false;

                function performPoll() {
                        if (requestInFlight) {
                                return;
                        }

                        requestInFlight = true;

                        $.ajax({
                                type: 'POST',
                                url: ajaxUrl,
                                data: {
                                        action: 'bokun_get_import_progress',
                                        security: bokun_api_auth_vars.nonce,
                                        mode: mode
                                },
                                dataType: 'json'
                        }).done(function (res) {
                                if (!res) {
                                        return;
                                }

                                if (res.success && res.data) {
                                        var data = res.data;
                                        var summary = {
                                                total: typeof data.total === 'number' ? data.total : null,
                                                processed: typeof data.processed === 'number' ? data.processed : null,
                                                created: typeof data.created === 'number' ? data.created : null,
                                                updated: typeof data.updated === 'number' ? data.updated : null,
                                                skipped: typeof data.skipped === 'number' ? data.skipped : null
                                        };

                                        if (summary.total === null && summary.processed === null && summary.created === null && summary.updated === null && summary.skipped === null) {
                                                summary.total = 0;
                                                summary.processed = 0;
                                        }

                                        var progressOptions = {
                                                summary: summary,
                                                label: data.label || options.label,
                                                isFinal: data.status === 'completed',
                                                useAbsolute: true,
                                                context: mode
                                        };

                                        if (typeof data.total_items === 'number') {
                                                progressOptions.totalItems = data.total_items;
                                        }

                                        if (typeof data.current === 'number') {
                                                progressOptions.current = data.current;
                                        }

                                        if (typeof data.percentage === 'number') {
                                                progressOptions.value = data.percentage;
                                        }

                                        if (data.display_message) {
                                                progressOptions.message = data.display_message;
                                        } else if (data.message) {
                                                progressOptions.message = data.message;
                                        }

                                        setImportProgress('summaryUpdate', progressOptions);

                                        if (data.status === 'completed') {
                                                stopImportProgressPolling(mode);
                                        }

                                        if (data.status === 'error') {
                                                stopImportProgressPolling(mode);
                                                setImportProgress('error');
                                        }
                                } else {
                                        stopImportProgressPolling(mode);
                                }
                        }).always(function () {
                                requestInFlight = false;
                        });
                }

                performPoll();
                importProgressPollers[mode] = setInterval(performPoll, interval);
        }

        function stopImportProgressPolling(mode) {
                if (!mode || !importProgressPollers[mode]) {
                        return;
                }

                clearInterval(importProgressPollers[mode]);
                delete importProgressPollers[mode];
        }

        function stopAllImportProgressPolling() {
                for (var key in importProgressPollers) {
                        if (Object.prototype.hasOwnProperty.call(importProgressPollers, key)) {
                                stopImportProgressPolling(key);
                        }
                }
        }

        resetImportProgressState();
        setImportProgress('reset');

        function getImportProgressState() {
                if (!window.bokunImportProgress || typeof window.bokunImportProgress !== 'object') {
                        window.bokunImportProgress = {};
                }

                if (typeof window.bokunImportProgress.totalItems !== 'number' || isNaN(window.bokunImportProgress.totalItems)) {
                        window.bokunImportProgress.totalItems = 0;
                }

                if (typeof window.bokunImportProgress.completedItems !== 'number' || isNaN(window.bokunImportProgress.completedItems)) {
                        window.bokunImportProgress.completedItems = 0;
                }

                if (typeof window.bokunImportProgress.fallbackTotal !== 'number' || isNaN(window.bokunImportProgress.fallbackTotal)) {
                        window.bokunImportProgress.fallbackTotal = 2;
                }

                if (!window.bokunImportProgress.contextMessages || typeof window.bokunImportProgress.contextMessages !== 'object') {
                        window.bokunImportProgress.contextMessages = {};
                }

                return window.bokunImportProgress;
        }

        function resetImportProgressState(total) {
                var state = getImportProgressState();
                state.totalItems = typeof total === 'number' && total > 0 ? total : 0;
                state.completedItems = 0;
                state.fallbackTotal = 2;
                state.contextMessages = {};
        }

        function updateImportProgressFromSummary(summary, options) {
                if (!summary || typeof summary !== 'object') {
                        return;
                }

                options = options || {};
                var breakdown = [];
                var created = typeof summary.created === 'number' ? summary.created : parseInt(summary.created, 10);
                var updated = typeof summary.updated === 'number' ? summary.updated : parseInt(summary.updated, 10);
                var skipped = typeof summary.skipped === 'number' ? summary.skipped : parseInt(summary.skipped, 10);

                if (!isNaN(created) && created > 0) {
                        breakdown.push(created + ' new');
                }

                if (!isNaN(updated) && updated > 0) {
                        breakdown.push(updated + ' updated');
                }

                if (!isNaN(skipped) && skipped > 0) {
                        breakdown.push(skipped + ' skipped');
                }

                var label = options.label || (options.isFinal ? 'Import complete' : 'Import progress');
                if (breakdown.length) {
                        label += ' — ' + breakdown.join(', ');
                }

                setImportProgress('summaryUpdate', {
                        summary: summary,
                        label: label,
                        isFinal: !!options.isFinal,
                        useAbsolute: true,
                        context: options.context
                });
        }

        $(document).on('click', '.bokun_fetch_booking_data_front', function (e) {
                e.preventDefault();

                var $button = $('.bokun_fetch_booking_data_front');

                $button.text('Processing…');
                $('.msg_sec').hide();
                stopAllImportProgressPolling();
                resetImportProgressState();
                setImportProgress('reset');
                setImportProgress('startApi1', {
                        current: 0
                });
                startImportProgressPolling('fetch');

                $.ajax({
                        type: 'POST',
                        url: ajaxUrl,
                        data: {
                                action: 'bokun_bookings_manager_page',
                                security: bokun_api_auth_vars.nonce,
                                mode: 'fetch'
                        },
                        dataType: 'json',
                        success: function (res) {
                                $button.text('Fetch');
                                $('.msg_success, .msg_error').hide();

                                        if (res.success) {
                                                stopImportProgressPolling('fetch');
                                                if (res.data && res.data.import_summary) {
                                                        updateImportProgressFromSummary(res.data.import_summary, {
                                                                label: 'Imported items from API 1',
                                                                isFinal: true,
                                                                context: 'fetch'
                                                        });
                                                } else if (res.data && res.data.progress_message) {
                                                        var api1Message = decodeHTMLEntities(res.data.progress_message);
                                                        setImportProgress('summaryUpdate', {
                                                                summary: { total: 0, processed: 0, created: 0, updated: 0, skipped: 0 },
                                                                message: api1Message,
                                                                isFinal: true,
                                                                useAbsolute: true,
                                                                context: 'fetch',
                                                                totalItems: 0,
                                                                current: 0,
                                                                value: 100
                                                        });
                                                } else {
                                                        setImportProgress('api1Complete');
                                                }
                                        call_from_second_api_front();
                                } else {
                                        stopImportProgressPolling('fetch');
                                        setImportProgress('error');
                                        alert(res.data.msg);
                                }
                        },
                        error: function (xhr, status, error) {
                                $button.text('Fetch');
                                stopImportProgressPolling('fetch');
                                setImportProgress('error');

                                var responseText = xhr.responseText;
                                var formattedMessage;

                                try {
                                        var parsedResponse = JSON.parse(responseText);
                                        formattedMessage = `Error: ${parsedResponse.message}`;
                                } catch (e) {
                                        formattedMessage = `Error: Received unexpected response code ${xhr.status}. Response: ${responseText}`;
                                }

                                alert(formattedMessage);
                                console.error('Error:', error);
                        }
                });
        });

        function call_from_second_api_front() {
                var $button = $('.bokun_fetch_booking_data_front');

                $button.text('Processing again…');
                var progressState = getImportProgressState();
                setImportProgress('startApi2', {
                        current: progressState.completedItems,
                        totalItems: progressState.totalItems
                });
                startImportProgressPolling('upgrade');

                $.ajax({
                        type: 'POST',
                        url: ajaxUrl,
                        data: {
                                action: 'bokun_bookings_manager_page',
                                security: bokun_api_auth_vars.nonce,
                                mode: 'upgrade'
                        },
                        dataType: 'json',
                        success: function (res) {
                                $button.text('Fetch');
                                stopImportProgressPolling('upgrade');

                                        if (res.success) {
                                                if (res.data && res.data.import_summary) {
                                                        updateImportProgressFromSummary(res.data.import_summary, {
                                                                label: 'Imported items from API 2',
                                                                isFinal: true,
                                                                context: 'upgrade'
                                                        });
                                                } else if (res.data && res.data.progress_message) {
                                                        var api2Message = decodeHTMLEntities(res.data.progress_message);
                                                        setImportProgress('summaryUpdate', {
                                                                summary: { total: 0, processed: 0, created: 0, updated: 0, skipped: 0 },
                                                                message: api2Message,
                                                                isFinal: true,
                                                                useAbsolute: true,
                                                                context: 'upgrade',
                                                                totalItems: 0,
                                                                current: 0,
                                                                value: 100
                                                        });
                                                } else {
                                                        setImportProgress('api2Complete');
                                                }
                                        var msg_all = decodeHTMLEntities(res.data.msg);
                                        alert(msg_all);
                                } else {
                                        setImportProgress('error');
                                        alert(res.data.msg);
                                }
                        },
                        error: function (xhr, status, error) {
                                $button.text('Fetch');
                                stopImportProgressPolling('upgrade');
                                setImportProgress('error');

                                var responseText = xhr.responseText;
                                var formattedMessage;

                                try {
                                        var parsedResponse = JSON.parse(responseText);
                                        formattedMessage = `Error: ${parsedResponse.message}`;
                                } catch (e) {
                                        formattedMessage = `Error: Received unexpected response code ${xhr.status}. Response: ${responseText}`;
                                }

                                alert(formattedMessage);
                                console.error('Error:', error);
                        }
                });
        }

        function decodeHTMLEntities(text) {
                var tempElement = document.createElement('textarea');
                tempElement.innerHTML = text;
                return tempElement.value;
        }

        function setImportProgress(step, options) {
                var $progress = $('#bokun_progress');
                var $message = $('#bokun_progress_message');
                var $value = $('#bokun_progress_value');
                var $bar = $('#bokun_progress_bar');
                var $spinner = $('#bokun_progress_spinner');

                if (!$progress.length) {
                        return;
                }

                options = options || {};

                function setSpinnerVisible(visible) {
                        if (!$spinner.length) {
                                return;
                        }

                        if (visible) {
                                $spinner.show();
                        } else {
                                $spinner.hide();
                        }
                }

                setSpinnerVisible(false);

                var progressState = getImportProgressState();
                var totalImportItems = typeof progressState.totalItems === 'number' && !isNaN(progressState.totalItems) ? progressState.totalItems : 0;

                if (!progressState.contextMessages || typeof progressState.contextMessages !== 'object') {
                        progressState.contextMessages = {};
                }

                function toNumber(value) {
                        var number = typeof value === 'number' ? value : parseInt(value, 10);
                        return isNaN(number) ? null : number;
                }

                function clamp(value) {
                        var number = Math.round(value);

                        if (!isFinite(number)) {
                                return 0;
                        }

                        if (number < 0) {
                                return 0;
                        }

                        if (number > 100) {
                                return 100;
                        }

                        return number;
                }

                function applyMessageTemplate(template, current, total, isFinal) {
                        if (typeof template !== 'string') {
                                return '';
                        }

                        var result = template;
                        result = result.replace(/\{current\}/g, current);
                        result = result.replace(/\{total\}/g, total);
                        result = result.replace(/\{remaining\}/g, Math.max(total - current, 0));
                        result = result.replace(/\{isFinal\}/g, isFinal ? 'true' : 'false');

                        return result;
                }

                function formatProgressMessage(current, total, stage, isFinal, template, fallbackTotal, fallbackCurrent) {
                        if (template) {
                                var effectiveTotal = total > 0 ? total : (fallbackTotal > 0 ? fallbackTotal : 0);
                                var effectiveCurrent = total > 0 ? current : (typeof fallbackCurrent === 'number' ? fallbackCurrent : current);

                                return applyMessageTemplate(template, effectiveCurrent, effectiveTotal, isFinal);
                        }

                        if (total > 0) {
                                if (isFinal) {
                                        return 'Import complete (' + total + '/' + total + ')';
                                }

                                if (stage === 'start') {
                                        return 'Importing item ' + Math.max(current, 1) + '/' + total;
                                }

                                return 'Imported ' + Math.min(current, total) + '/' + total;
                        }

                        if (isFinal) {
                                return 'Import complete';
                        }

                        return stage === 'start' ? 'Starting import…' : 'Processing…';
                }

                function computeProgressValue(current, total, stage, explicitValue, fallbackTotal, fallbackCurrent) {
                        if (typeof explicitValue === 'number' && !isNaN(explicitValue)) {
                                return clamp(explicitValue);
                        }

                        var denominator = total > 0 ? total : (fallbackTotal > 0 ? fallbackTotal : 0);

                        if (denominator <= 0) {
                                if (stage === 'complete') {
                                        return current > 0 ? 100 : 0;
                                }

                                return 0;
                        }

                        var numerator;

                        if (total > 0) {
                                if (stage === 'start') {
                                        numerator = Math.max(current - 1, 0);
                                } else {
                                        numerator = Math.min(current, total);
                                }
                        } else {
                                var fallbackCurrentValue = typeof fallbackCurrent === 'number' ? fallbackCurrent : current;

                                if (stage === 'start') {
                                        numerator = Math.max(fallbackCurrentValue - 1, 0);
                                } else {
                                        numerator = Math.min(fallbackCurrentValue, denominator);
                                }
                        }

                        return clamp((numerator / denominator) * 100);
                }

                function renderProgress(message, progressValue, isFinal) {
                        var safeValue = clamp(progressValue);
                        var safeMessage = message || 'Import progress';

                        $progress.removeClass('is-error').show();
                        $message.text(safeMessage);
                        $value.text(safeValue + '%');

                        if ($bar.length) {
                                $bar.css('width', safeValue + '%').attr('aria-valuenow', safeValue).data('progress-value', safeValue);
                        }

                        // Keep the progress visible after a successful fetch so visitors
                        // can review the final status without it fading out automatically.
                }

                function buildAggregatedMessage(contextKey, message, isFinal) {
                        var contextMessages = progressState.contextMessages;

                        if (!contextMessages || typeof contextMessages !== 'object') {
                                contextMessages = {};
                                progressState.contextMessages = contextMessages;
                        }

                        if (contextKey) {
                                if (isFinal) {
                                        contextMessages[contextKey] = message;
                                } else if (Object.prototype.hasOwnProperty.call(contextMessages, contextKey)) {
                                        delete contextMessages[contextKey];
                                }
                        }

                        var lines = [];

                        CONTEXT_DISPLAY_ORDER.forEach(function (key) {
                                if (contextMessages[key]) {
                                        lines.push(contextMessages[key]);
                                }
                        });

                        if (contextKey && CONTEXT_DISPLAY_ORDER.indexOf(contextKey) === -1 && contextMessages[contextKey]) {
                                lines.push(contextMessages[contextKey]);
                        }

                        if ((!contextKey || !isFinal || !contextMessages[contextKey]) && message) {
                                lines.push(message);
                        }

                        if (!lines.length && message) {
                                lines.push(message);
                        }

                        return lines.join('\n');
                }

                $progress.stop(true, true);

                if (step === 'reset') {
                        var resetTotal = toNumber(options.totalItems);
                        progressState.totalItems = resetTotal !== null && resetTotal > 0 ? resetTotal : 0;
                        progressState.completedItems = 0;
                        progressState.fallbackTotal = 2;
                        progressState.contextMessages = {};

                        var resetMessage = progressState.totalItems > 0 ? 'Import progress (0/' + progressState.totalItems + ')' : 'Import progress';
                        $progress.removeClass('is-error').hide();
                        $message.text(resetMessage);
                        $value.text('0%');
                        setSpinnerVisible(false);

                        if ($bar.length) {
                                $bar.css('width', '0%').attr('aria-valuenow', 0).data('progress-value', 0);
                        }

                        return;
                }

                if (step === 'error') {
                        progressState.contextMessages = {};
                        $progress.addClass('is-error').show();
                        $message.text('Import interrupted');
                        $value.text('Check the error message for details.');
                        setSpinnerVisible(false);

                        if ($bar.length) {
                                var lastValue = $bar.data('progress-value') || 0;
                                $bar.attr('aria-valuenow', lastValue);
                        }

                        return;
                }

                if (step === 'summaryUpdate') {
                        var summary = options.summary || {};
                        var processedValue = summary.processed !== undefined ? toNumber(summary.processed) : null;
                        var totalToAdd = toNumber(summary.total);
                        var createdCount = summary.created !== undefined ? toNumber(summary.created) : null;
                        var updatedCount = summary.updated !== undefined ? toNumber(summary.updated) : null;
                        var skippedCount = summary.skipped !== undefined ? toNumber(summary.skipped) : null;
                        var derivedTotal = 0;
                        var hasDerivedTotal = false;
                        var useAbsolute = !!options.useAbsolute;
                        var explicitCurrent = options.current !== undefined ? toNumber(options.current) : null;
                        var explicitTotal = options.totalItems !== undefined ? toNumber(options.totalItems) : null;
                        var explicitValue = options.value !== undefined ? toNumber(options.value) : null;

                        if (explicitTotal !== null && explicitTotal >= 0) {
                                totalToAdd = explicitTotal;
                        }

                        if (createdCount !== null && createdCount >= 0) {
                                derivedTotal += createdCount;
                                hasDerivedTotal = hasDerivedTotal || createdCount > 0;
                        }

                        if (updatedCount !== null && updatedCount >= 0) {
                                derivedTotal += updatedCount;
                                hasDerivedTotal = hasDerivedTotal || updatedCount > 0;
                        }

                        if (skippedCount !== null && skippedCount >= 0) {
                                derivedTotal += skippedCount;
                                hasDerivedTotal = hasDerivedTotal || skippedCount > 0;
                        }

                        if (totalToAdd === null || totalToAdd < 0) {
                                if (processedValue !== null && processedValue > 0) {
                                        totalToAdd = processedValue;
                                } else if (hasDerivedTotal && derivedTotal > 0) {
                                        totalToAdd = derivedTotal;
                                }
                        }

                        if (totalToAdd !== null) {
                                if (useAbsolute) {
                                        progressState.totalItems = Math.max(totalToAdd, 0);
                                } else if (totalToAdd > 0) {
                                        progressState.totalItems += totalToAdd;
                                }
                        }

                        if (processedValue === null) {
                                if (hasDerivedTotal && (derivedTotal > 0 || totalToAdd === 0)) {
                                        processedValue = derivedTotal;
                                } else if (totalToAdd !== null) {
                                        processedValue = Math.max(totalToAdd, 0);
                                } else {
                                        processedValue = 0;
                                }
                        }

                        if (explicitCurrent !== null) {
                                processedValue = Math.max(explicitCurrent, 0);
                        }

                        if (processedValue < 0) {
                                processedValue = 0;
                        }

                        if (useAbsolute) {
                                if (processedValue !== null) {
                                        progressState.completedItems = processedValue;
                                }
                        } else {
                                progressState.completedItems += processedValue;
                        }

                        if (progressState.totalItems < progressState.completedItems) {
                                progressState.totalItems = progressState.completedItems;
                        }

                        if (progressState.totalItems > 0) {
                                progressState.fallbackTotal = progressState.totalItems;
                        }

                        totalImportItems = progressState.totalItems;
                        var currentValue = progressState.completedItems;
                        var isFinal = !!options.isFinal;
                        var contextKey = typeof options.context === 'string' ? options.context : null;

                        var message;

                        if (options.message) {
                                message = applyMessageTemplate(options.message, currentValue, totalImportItems, isFinal);
                        } else {
                                var label = options.label || (isFinal ? 'Import complete' : 'Import progress');

                                if (totalImportItems > 0) {
                                        message = label + ' (' + Math.min(currentValue, totalImportItems) + '/' + totalImportItems + ')';
                                } else {
                                        message = label;
                                }
                        }

                        var progressValue;

                        if (explicitValue !== null && explicitValue >= 0) {
                                progressValue = clamp(explicitValue);
                        } else if (totalImportItems > 0) {
                                progressValue = computeProgressValue(currentValue, totalImportItems, 'complete');
                        } else {
                                progressValue = isFinal ? 100 : 0;
                        }

                        var aggregatedMessage = buildAggregatedMessage(contextKey, message, isFinal);

                        renderProgress(aggregatedMessage, progressValue, isFinal);
                        var shouldShowSpinner = !isFinal || contextKey === 'fetch';
                        setSpinnerVisible(shouldShowSpinner);
                        return;
                }

                var stepMap = {
                        startApi1: { context: 'fetch', stage: 'start', fallbackTotal: progressState.fallbackTotal || 2, fallbackCurrent: 1, message: 'Fetching items from API 1…' },
                        api1Complete: { context: 'fetch', stage: 'complete', fallbackTotal: progressState.fallbackTotal || 2, fallbackCurrent: 1, isFinal: true, message: 'Finished API 1' },
                        startApi2: { context: 'upgrade', stage: 'start', fallbackTotal: progressState.fallbackTotal || 2, fallbackCurrent: 2, message: 'Fetching items from API 2… ({current} processed so far)' },
                        api2Complete: { context: 'upgrade', stage: 'complete', fallbackTotal: progressState.fallbackTotal || 2, fallbackCurrent: 2, isFinal: true, message: 'Finished API 2' }
                };

                if (stepMap[step]) {
                        var state = stepMap[step];
                        var stage = state.stage || 'complete';
                        var fallbackTotal = state.fallbackTotal || progressState.fallbackTotal || 2;
                        var fallbackCurrent = state.fallbackCurrent;
                        var current = toNumber(options.current);

                        if (current === null) {
                                if (totalImportItems > 0) {
                                        current = progressState.completedItems;
                                } else if (typeof fallbackCurrent === 'number') {
                                        current = fallbackCurrent;
                                } else {
                                        current = 0;
                                }
                        }

                        if (totalImportItems > 0 && current > totalImportItems && stage !== 'start') {
                                current = totalImportItems;
                        }

                        var message = formatProgressMessage(current, totalImportItems, stage, !!state.isFinal, state.message, fallbackTotal, fallbackCurrent);

                        if (options.message) {
                                message = applyMessageTemplate(options.message, current, totalImportItems > 0 ? totalImportItems : fallbackTotal, !!state.isFinal);
                        }

                        var progressValue = computeProgressValue(current, totalImportItems, stage, state.value, fallbackTotal, fallbackCurrent);

                        var aggregatedMessage = buildAggregatedMessage(state.context || null, message, !!state.isFinal);

                        renderProgress(aggregatedMessage, progressValue, !!state.isFinal);
                        var shouldShowSpinnerForStep = !state.isFinal || state.context === 'fetch';
                        setSpinnerVisible(shouldShowSpinnerForStep);
                }
        }

        function toggleDualStatusSection(button) {
                var $button = $(button);
                var $section = $button.closest('[data-dashboard-dual-status]');

                if (!$section.length) {
                        return;
                }

                var $panel = $section.find('[data-dashboard-dual-status-panel]').first();
                var isExpanded = $button.attr('aria-expanded') === 'true';
                var nextState = !isExpanded;
                var showLabel = $button.data('showLabel') || $button.attr('data-show-label') || '';
                var hideLabel = $button.data('hideLabel') || $button.attr('data-hide-label') || '';
                var targetLabel = nextState ? (hideLabel || 'Hide bookings') : (showLabel || 'Show bookings');

                $button.attr('aria-expanded', nextState ? 'true' : 'false');
                $button.toggleClass('is-active', nextState);

                if ($panel.length) {
                        if (nextState) {
                                $panel.removeAttr('hidden');
                        } else {
                                $panel.attr('hidden', 'hidden');
                        }
                }

                $button.text(targetLabel);
        }

        function getHistoryContext($dashboard) {
                if (!$dashboard || !$dashboard.length) {
                        return null;
                }

                var $overlay = $dashboard.find('[data-dashboard-history-overlay]').first();
                var $dialog = $dashboard.find('[data-dashboard-history]').first();

                if (!$overlay.length || !$dialog.length) {
                        return null;
                }

                return {
                        dashboard: $dashboard,
                        overlay: $overlay,
                        dialog: $dialog,
                        trigger: $dashboard.data('historyTrigger')
                };
        }

        function openHistoryDialog(button) {
                var $button = $(button);
                var $dashboard = $button.closest('.bokun-booking-dashboard');

                if (!$dashboard.length) {
                        return;
                }

                var context = getHistoryContext($dashboard);

                if (!context) {
                        return;
                }

                context.overlay.removeAttr('hidden').attr('aria-hidden', 'false');
                context.dialog.removeAttr('hidden').attr('aria-hidden', 'false');
                $dashboard.addClass('bokun-booking-dashboard--history-open');
                $dashboard.data('historyTrigger', $button);
                $button.attr('aria-expanded', 'true');

                var $focusTarget = context.dialog.find('[data-dashboard-history-close]').first();

                if (!$focusTarget.length) {
                        $focusTarget = context.dialog;
                }

                setTimeout(function () {
                        $focusTarget.trigger('focus');
                }, 0);
        }

        function closeHistoryDialog(element) {
                var $dashboard;

                if (element && element.jquery) {
                        $dashboard = element;
                } else {
                        $dashboard = $(element).closest('.bokun-booking-dashboard');
                }

                if (!$dashboard.length) {
                        return;
                }

                var context = getHistoryContext($dashboard);

                if (!context) {
                        return;
                }

                context.overlay.attr('hidden', 'hidden').attr('aria-hidden', 'true');
                context.dialog.attr('hidden', 'hidden').attr('aria-hidden', 'true');

                var $trigger = context.trigger && context.trigger.length ? context.trigger : $dashboard.find('[data-dashboard-history-open]').first();

                if ($trigger && $trigger.length) {
                        $trigger.attr('aria-expanded', 'false');
                }

                $dashboard.removeClass('bokun-booking-dashboard--history-open');
                $dashboard.removeData('historyTrigger');

                if ($trigger && $trigger.length) {
                        setTimeout(function () {
                                $trigger.trigger('focus');
                        }, 0);
                }
        }

        $(document).on('click', '[data-dashboard-dual-status-toggle]', function (event) {
                event.preventDefault();
                toggleDualStatusSection(this);
        });

        $(document).on('click', '[data-dashboard-history-open]', function (event) {
                event.preventDefault();
                openHistoryDialog(this);
        });

        $(document).on('click', '[data-dashboard-history-close]', function (event) {
                event.preventDefault();
                closeHistoryDialog(this);
        });

        $(document).on('click', '[data-dashboard-history-overlay]', function (event) {
                event.preventDefault();
                closeHistoryDialog(this);
        });

        $(document).on('keydown', function (event) {
                if (event.key === 'Escape' || event.key === 'Esc' || event.keyCode === 27) {
                        var $openDashboard = $('.bokun-booking-dashboard--history-open').last();

                        if ($openDashboard.length) {
                                event.preventDefault();
                                closeHistoryDialog($openDashboard);
                        }
                }
        });


});
