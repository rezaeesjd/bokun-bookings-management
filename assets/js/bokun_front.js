jQuery(function ($) {

        var ajaxUrl = (typeof bokun_api_auth_vars !== 'undefined' && bokun_api_auth_vars.ajax_url) ? bokun_api_auth_vars.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
        var importProgressPollers = {};
        var progressPollInterval = 1000;
        var apiContexts = Array.isArray(bokun_api_auth_vars && bokun_api_auth_vars.apiContexts) ? bokun_api_auth_vars.apiContexts : [];
        var apiContextMap = {};
        var importNotificationMessages = [];

        apiContexts = apiContexts.filter(function (context) {
                return context && (typeof context === 'object');
        }).map(function (context, index) {
                var slug = context.slug ? String(context.slug) : 'api_' + (index + 1);
                var normalizedIndex = typeof context.index === 'number' && !isNaN(context.index) ? context.index : index + 1;
                var label = context.label ? String(context.label) : 'API #' + normalizedIndex;

                var normalizedContext = {
                        slug: slug,
                        label: label,
                        index: normalizedIndex
                };

                apiContextMap[slug] = normalizedContext;

                return normalizedContext;
        });

        var CONTEXT_DISPLAY_ORDER = apiContexts.length ? apiContexts.map(function (context) {
                return context.slug;
        }) : [];

        function getContextDefinition(slug, index) {
                if (slug && apiContextMap[slug]) {
                        return apiContextMap[slug];
                }

                if (typeof index === 'number' && index >= 0 && index < apiContexts.length) {
                        return apiContexts[index];
                }

                if (slug && /^api_(\d+)$/.test(slug)) {
                        var parsedIndex = parseInt(slug.replace('api_', ''), 10);

                        if (!isNaN(parsedIndex) && parsedIndex > 0 && parsedIndex - 1 < apiContexts.length) {
                                return apiContexts[parsedIndex - 1];
                        }
                }

                return null;
        }

        function getContextLabel(slug, index) {
                var definition = getContextDefinition(slug, index);

                if (definition && definition.label) {
                        return definition.label;
                }

                var fallbackIndex = index;

                if (typeof fallbackIndex !== 'number' || fallbackIndex < 0) {
                        if (slug && /^api_(\d+)$/.test(slug)) {
                                fallbackIndex = parseInt(slug.replace('api_', ''), 10) - 1;
                        } else if (slug) {
                                fallbackIndex = CONTEXT_DISPLAY_ORDER.indexOf(slug);
                        }
                }

                if (typeof fallbackIndex !== 'number' || fallbackIndex < 0) {
                        fallbackIndex = 0;
                }

                var humanIndex = fallbackIndex + 1;
                return 'API #' + humanIndex;
        }

        function getContextSlugByIndex(index) {
                if (apiContexts[index]) {
                        return apiContexts[index].slug;
                }

                return CONTEXT_DISPLAY_ORDER[index] || ('api_' + (index + 1));
        }

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
                        window.bokunImportProgress.fallbackTotal = Math.max(apiContexts.length, 2);
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
                state.fallbackTotal = Math.max(apiContexts.length, 2);
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

        function runContextImportSequence(startIndex) {
                var $button = $('.bokun_fetch_booking_data_front');

                if (!Array.isArray(apiContexts) || !apiContexts.length) {
                        $button.text('Fetch').prop('disabled', false);
                        setImportProgress('error', { message: 'No API credentials configured.' });
                        alert('No API credentials configured.');
                        return;
                }

                var index = typeof startIndex === 'number' ? startIndex : 0;

                if (index >= apiContexts.length) {
                        setImportProgress('allComplete', { totalContexts: apiContexts.length });
                        $button.text('Fetch').prop('disabled', false);
                        showFinalImportNotification();
                        return;
                }

                var context = apiContexts[index];
                var contextSlug = context.slug;
                var contextLabel = context.label;

                setImportProgress('contextStart', {
                        context: contextSlug,
                        label: contextLabel,
                        index: index,
                        totalContexts: apiContexts.length
                });

                startImportProgressPolling(contextSlug);

                $.ajax({
                        type: 'POST',
                        url: ajaxUrl,
                        data: {
                                action: 'bokun_bookings_manager_page',
                                security: bokun_api_auth_vars.nonce,
                                mode: contextSlug
                        },
                        dataType: 'json'
                }).done(function (res) {
                        stopImportProgressPolling(contextSlug);

                        if (res && res.success) {
                                if (res.data && res.data.import_summary) {
                                        updateImportProgressFromSummary(res.data.import_summary, {
                                                label: contextLabel,
                                                isFinal: true,
                                                context: contextSlug
                                        });
                                } else if (res.data && res.data.progress_message) {
                                        var decodedMessage = decodeHTMLEntities(res.data.progress_message);
                                        setImportProgress('summaryUpdate', {
                                                summary: { total: 0, processed: 0, created: 0, updated: 0, skipped: 0 },
                                                message: decodedMessage,
                                                isFinal: true,
                                                useAbsolute: true,
                                                context: contextSlug,
                                                totalItems: 0,
                                                current: 0,
                                                value: 100
                                        });
                                } else {
                                        setImportProgress('contextComplete', {
                                                context: contextSlug,
                                                label: contextLabel,
                                                index: index,
                                                totalContexts: apiContexts.length
                                        });
                                }

                                if (res.data && res.data.msg) {
                                        recordImportNotification(contextLabel, res.data.msg);
                                }

                                runContextImportSequence(index + 1);
                        } else {
                                setImportProgress('error', {
                                        context: contextSlug,
                                        message: res && res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : ''
                                });

                                var errorMessage = res && res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : 'An unexpected error occurred.';
                                alert(errorMessage);
                                $button.text('Fetch').prop('disabled', false);
                        }
                }).fail(function (xhr, status, error) {
                        stopImportProgressPolling(contextSlug);
                        setImportProgress('error', { context: contextSlug });

                        var responseText = xhr && xhr.responseText ? xhr.responseText : '';
                        var formattedMessage;

                        try {
                                var parsedResponse = responseText ? JSON.parse(responseText) : null;
                                formattedMessage = parsedResponse && parsedResponse.message ? 'Error: ' + parsedResponse.message : 'Error: ' + responseText;
                        } catch (e) {
                                formattedMessage = 'Error: Received unexpected response code ' + xhr.status + '. Response: ' + responseText;
                        }

                        alert(formattedMessage);
                        console.error('Error:', error);
                        $button.text('Fetch').prop('disabled', false);
                });
        }

        $(document).on('click', '.bokun_fetch_booking_data_front', function (e) {
                e.preventDefault();

                if (!ajaxUrl) {
                        alert('AJAX endpoint is not available.');
                        return;
                }

                if (!apiContexts.length) {
                        alert('No API credentials configured.');
                        return;
                }

                var $button = $('.bokun_fetch_booking_data_front');

                $button.text('Processing…').prop('disabled', true);
                $('.msg_sec, .msg_success, .msg_error').hide();
                stopAllImportProgressPolling();
                resetImportProgressState();
                setImportProgress('reset');
                resetImportNotifications();

                runContextImportSequence(0);
        });

        function decodeHTMLEntities(text) {
                var tempElement = document.createElement('textarea');
                tempElement.innerHTML = text;
                return tempElement.value;
        }

        function resetImportNotifications() {
                importNotificationMessages = [];
        }

        function recordImportNotification(contextLabel, message) {
                if (!message) {
                        return;
                }

                var normalized = decodeHTMLEntities(message);
                var labeledMessage = contextLabel ? (contextLabel + ': ' + normalized) : normalized;

                importNotificationMessages.push(labeledMessage);
        }

        function showFinalImportNotification() {
                var message;

                if (importNotificationMessages.length) {
                        message = importNotificationMessages.join('\n');
                } else {
                        message = 'Import complete.';
                }

                alert(message);
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
                        progressState.fallbackTotal = Math.max(apiContexts.length, 2);
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
                        var errorText = options && options.message ? options.message : 'Import interrupted';
                        $progress.addClass('is-error').show();
                        $message.text(errorText);
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
                        var shouldShowSpinner = !isFinal;
                        setSpinnerVisible(shouldShowSpinner);
                        return;
                }

                if (step === 'contextStart' || step === 'contextComplete') {
                        var stage = step === 'contextStart' ? 'start' : 'complete';
                        var contextSlug = typeof options.context === 'string' ? options.context : null;
                        var fallbackTotal = progressState.fallbackTotal || Math.max(apiContexts.length, 2);
                        var fallbackCurrent = typeof options.index === 'number' ? options.index + 1 : null;
                        var current = toNumber(options.current);

                        if (current === null) {
                                if (typeof fallbackCurrent === 'number') {
                                        current = fallbackCurrent;
                                } else if (totalImportItems > 0) {
                                        current = progressState.completedItems;
                                } else {
                                        current = stage === 'start' ? 1 : 0;
                                }
                        }

                        if (totalImportItems > 0 && current > totalImportItems && stage !== 'start') {
                                current = totalImportItems;
                        }

                        var label = options.label || getContextLabel(contextSlug, typeof options.index === 'number' ? options.index : null);
                        var template = options.message;

                        if (!template) {
                                template = stage === 'start' ? label + ' — fetching items…' : label + ' — finished';
                        }

                        var totalForMessage = totalImportItems > 0 ? totalImportItems : fallbackTotal;
                        var message = applyMessageTemplate(template, current, totalForMessage, stage === 'complete');
                        var progressValue = computeProgressValue(current, totalImportItems, stage, options.value, fallbackTotal, fallbackCurrent);
                        var aggregatedMessage = buildAggregatedMessage(contextSlug, message, stage === 'complete');

                        renderProgress(aggregatedMessage, progressValue, stage === 'complete');
                        setSpinnerVisible(stage !== 'complete');
                        return;
                }

                if (step === 'allComplete') {
                        var completionMessage = options && options.message ? options.message : 'Import complete';
                        var finalMessage = buildAggregatedMessage(null, completionMessage, true);
                        renderProgress(finalMessage, 100, true);
                        setSpinnerVisible(false);
                }
        }

        function handlePartnerTagFormSubmission($form) {
                if (!$form || !$form.length) {
                        return;
                }

                if (!ajaxUrl) {
                        alert('AJAX endpoint is not available.');
                        return;
                }

                var termId = parseInt($form.attr('data-term-id'), 10);

                if (!termId) {
                        alert('Invalid product tag.');
                        return;
                }

                var $input = $form.find('[data-partner-page-input]');
                var $submit = $form.find('[data-partner-page-submit]');
                var $feedback = $form.find('[data-partner-page-feedback]');
                var partnerPageId = $input.length ? String($input.val()).trim() : '';

                function showFeedback(type, message) {
                        if (!$feedback.length) {
                                if (message) {
                                        alert(message);
                                }

                                return;
                        }

                        $feedback.removeClass('is-success is-error');

                        if (!message) {
                                $feedback
                                        .text('')
                                        .attr('hidden', 'hidden')
                                        .attr('aria-hidden', 'true')
                                        .attr('role', 'status')
                                        .attr('aria-live', 'polite');
                                return;
                        }

                        if (type === 'error') {
                                $feedback
                                        .addClass('is-error')
                                        .attr('role', 'alert')
                                        .attr('aria-live', 'assertive');
                        } else {
                                $feedback
                                        .addClass('is-success')
                                        .attr('role', 'status')
                                        .attr('aria-live', 'polite');
                        }

                        $feedback
                                .text(message)
                                .removeAttr('hidden')
                                .attr('aria-hidden', 'false');
                }

                showFeedback();
                $submit.prop('disabled', true);
                $input.prop('disabled', true);

                $.ajax({
                        type: 'POST',
                        url: ajaxUrl,
                        data: {
                                action: 'bokun_update_partner_page_id',
                                security: bokun_api_auth_vars.nonce,
                                term_id: termId,
                                partner_page_id: partnerPageId
                        },
                        dataType: 'json'
                }).done(function (res) {
                        if (res && res.success) {
                                var successMessage = res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : 'Partner Page ID saved.';
                                showFeedback('success', successMessage);

                                var $item = $form.closest('[data-partner-tag-item]');

                                if ($item.length) {
                                        setTimeout(function () {
                                                $item.fadeOut(200, function () {
                                                        var $parentList = $item.parent();
                                                        $item.remove();

                                                        if ($parentList && !$parentList.children().length) {
                                                                var $container = $parentList.closest('.bokun-booking-dashboard__missing-tags');

                                                                if ($container.length) {
                                                                        $container.fadeOut(200, function () {
                                                                                $container.remove();
                                                                        });
                                                                }
                                                        }
                                                });
                                        }, 400);
                                }
                        } else {
                                var errorMessage = res && res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : 'Unable to save Partner Page ID.';
                                showFeedback('error', errorMessage);
                        }
                }).fail(function (xhr) {
                        var errorMessage = 'An unexpected error occurred.';

                        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg) {
                                errorMessage = decodeHTMLEntities(xhr.responseJSON.data.msg);
                        }

                        showFeedback('error', errorMessage);
                }).always(function () {
                        $submit.prop('disabled', false);
                        $input.prop('disabled', false);
                });
        }

        $(document).on('submit', '[data-partner-tag-form]', function (e) {
                e.preventDefault();
                handlePartnerTagFormSubmission($(this));
        });

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
