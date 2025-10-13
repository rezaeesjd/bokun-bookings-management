jQuery(document).ready(function ($) {


        var importProgressPollers = {};
        var progressPollInterval = 1000;

        function startImportProgressPolling(mode, options) {
                options = options || {};

                if (!mode || importProgressPollers[mode]) {
                        return;
                }

                if (typeof ajaxurl === 'undefined' || !ajaxurl) {
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
                                url: ajaxurl,
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
                                                useAbsolute: true
                                        };

                                        if (data.message) {
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

		return window.bokunImportProgress;
	}

	function resetImportProgressState(total) {
		var state = getImportProgressState();
		state.totalItems = typeof total === 'number' && total > 0 ? total : 0;
		state.completedItems = 0;
		state.fallbackTotal = 2;
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
                        useAbsolute: true
                });
        }

	jQuery(document).on('click', '.bokun_api_auth_save', function () {
		var form = jQuery('#bokun_api_auth_form')[0];
		var formData = new FormData(form);
		formData.append('action', 'bokun_save_api_auth');
		formData.append('security', bokun_api_auth_vars.nonce);

		jQuery.ajax({
			type: 'POST',
			url: ajaxurl,
			data: formData,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function (res) {
				
				jQuery('.msg_success_apis, .msg_error_apis').hide();
				
				if (res.success) {
					var msg_all = decodeHTMLEntities(res.data.msg);
					jQuery('.msg_success_apis p').html(`<strong>Success:</strong> ${msg_all}`);
					jQuery('.msg_success_apis').show();
				} else {
					jQuery('.msg_error_apis p').html(`<strong>Error:</strong> ${res.data.msg}`);
					jQuery('.msg_error_apis').show(); // Show error notice
				}
			},
			error: function (xhr, status, error) {
				console.error('Error:', error);
				alert('An error occurred. Please try again.');
			}
		});
	});
	
	jQuery(document).on('click', '.bokun_api_auth_save_upgrade', function () {
		var form = jQuery('#bokun_api_auth_form_upgrade')[0];
		var formData = new FormData(form);
		formData.append('action', 'bokun_save_api_auth_upgrade');
		formData.append('security', bokun_api_auth_vars.nonce);

		jQuery.ajax({
			type: 'POST',
			url: ajaxurl,
			data: formData,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function (res) {
				
				jQuery('.msg_success_apis_upgrade, .msg_error_apis_upgrade').hide();
				
				if (res.success) {
					var msg_all = decodeHTMLEntities(res.data.msg);
					jQuery('.msg_success_apis_upgrade p').html(`<strong>Success:</strong> ${msg_all}`);
					jQuery('.msg_success_apis_upgrade').show(); // Show success notice
				} else {
					jQuery('.msg_error_apis_upgrade p').html(`<strong>Error:</strong> ${res.data.msg}`);
					jQuery('.msg_error_apis_upgrade').show(); // Show error notice
				}
			},
			error: function (xhr, status, error) {
				console.error('Error:', error);
				alert('An error occurred. Please try again.');
			}
		});
	});

        jQuery(document).on('click', '.bokun_fetch_booking_data', function (e) {
                e.preventDefault();
                jQuery('.msg_sec').hide();
                jQuery('#bokun_loader').show();
                stopAllImportProgressPolling();
                resetImportProgressState();
                setImportProgress('reset');
                setImportProgress('startApi1', {
                        current: 0
                });
                startImportProgressPolling('fetch');
                jQuery.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                                action: 'bokun_bookings_manager_page',
				security: bokun_api_auth_vars.nonce,
				mode: 'fetch'
			},
			dataType: 'json',
			success: function (res) {

                                jQuery('#bokun_loader').hide();
                                jQuery('.msg_success, .msg_error').hide();
                                if (res.success) {
                                        stopImportProgressPolling('fetch');
                                        if (res.data && res.data.import_summary) {
                                                updateImportProgressFromSummary(res.data.import_summary, {
                                                        label: 'Imported items from API 1'
                                                });
                                        } else {
                                                setImportProgress('api1Complete');
                                        }
                                        call_from_secound_api();
                                        var msg_all = decodeHTMLEntities(res.data.msg);
                                        jQuery('.msg_success p').html(`<strong>Success:</strong> ${msg_all}`);
                                        jQuery('.msg_success').show();
                                } else {
                                        stopImportProgressPolling('fetch');
                                        setImportProgress('error');
                                        jQuery('.msg_error p').html(`<strong>Error:</strong> ${res.data.msg}`);
                                        jQuery('.msg_error').show();
                                }
                        },
                        error: function (xhr, status, error) {
                                jQuery('#bokun_loader').hide();
                                stopImportProgressPolling('fetch');
                                setImportProgress('error');

                                var responseText = xhr.responseText;
                                try {
                                        var parsedResponse = JSON.parse(responseText);
					var formattedMessage = `Error: ${parsedResponse.message}`;
				} catch (e) {
					// If parsing fails, use the raw response text
					var formattedMessage = `Error: Received unexpected response code ${xhr.status}. Response: ${responseText}`;
				}

				alert(formattedMessage);
				console.error('Error:', error);
			}
		});
	});


        function call_from_secound_api() {

                jQuery('#bokun_loader').hide();
                jQuery('#bokun_loader_upgrade').show();
                var progressState = getImportProgressState();
                setImportProgress('startApi2', {
                        current: progressState.completedItems,
                        totalItems: progressState.totalItems
                });
                startImportProgressPolling('upgrade');
                jQuery.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                                action: 'bokun_bookings_manager_page',
                                security: bokun_api_auth_vars.nonce,
                                mode: 'upgrade'
                        },
                        dataType: 'json',
                        success: function (res) {

                                jQuery('#bokun_loader_upgrade').hide();
                                jQuery('.msg_success_upgrade, .msg_error_upgrade').hide();

                                if (res.success) {
                                        stopImportProgressPolling('upgrade');
                                        if (res.data && res.data.import_summary) {
                                                updateImportProgressFromSummary(res.data.import_summary, {
                                                        label: 'Imported items from API 2',
                                                        isFinal: true
                                                });
                                        } else {
                                                setImportProgress('api2Complete');
                                        }
                                        var msg_all = decodeHTMLEntities(res.data.msg);
                                        jQuery('.msg_success_upgrade p').html(`<strong>Success:</strong> ${msg_all}`);
                                        jQuery('.msg_success_upgrade').show();
                                } else {
                                        stopImportProgressPolling('upgrade');
                                        setImportProgress('error');
                                        jQuery('.msg_error_upgrade p').html(`<strong>Error:</strong> ${res.data.msg}`);
                                        jQuery('.msg_error_upgrade').show();
                                }
                        },
                        error: function (xhr, status, error) {
                                jQuery('#bokun_loader_upgrade').hide();
                                stopImportProgressPolling('upgrade');
                                setImportProgress('error');
                                var responseText = xhr.responseText;
                                try {
                                        var parsedResponse = JSON.parse(responseText);
                                        var formattedMessage = `Error: ${parsedResponse.message}`;
                                } catch (e) {
                                        // If parsing fails, use the raw response text
                                        var formattedMessage = `Error: Received unexpected response code ${xhr.status}. Response: ${responseText}`;
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
                var $progress = jQuery('#bokun_progress');
                var $message = jQuery('#bokun_progress_message');
                var $value = jQuery('#bokun_progress_value');
                var $bar = jQuery('#bokun_progress_bar');

                if (!$progress.length) {
                        return;
                }

                options = options || {};

                var progressState = getImportProgressState();
                var totalImportItems = typeof progressState.totalItems === 'number' && !isNaN(progressState.totalItems) ? progressState.totalItems : 0;

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

                        // Keep the progress visible after a successful fetch so the
                        // user can review the final status rather than having it fade
                        // away automatically.
                }

                $progress.stop(true, true);

                if (step === 'reset') {
                        var resetTotal = toNumber(options.totalItems);
                        progressState.totalItems = resetTotal !== null && resetTotal > 0 ? resetTotal : 0;
                        progressState.completedItems = 0;
                        progressState.fallbackTotal = 2;

                        var resetMessage = progressState.totalItems > 0 ? 'Import progress (0/' + progressState.totalItems + ')' : 'Import progress';
                        $progress.removeClass('is-error').hide();
                        $message.text(resetMessage);
                        $value.text('0%');

                        if ($bar.length) {
                                $bar.css('width', '0%').attr('aria-valuenow', 0).data('progress-value', 0);
                        }

                        return;
                }

                if (step === 'error') {
                        $progress.addClass('is-error').show();
                        $message.text('Import interrupted');
                        $value.text('Check the error message for details.');

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

                        var progressValue = totalImportItems > 0 ? computeProgressValue(currentValue, totalImportItems, 'complete') : (isFinal ? 100 : 0);

                        renderProgress(message, progressValue, isFinal);
                        return;
                }

                var stepMap = {
                        startApi1: { stage: 'start', fallbackTotal: progressState.fallbackTotal || 2, fallbackCurrent: 1, message: 'Fetching items from API 1…' },
                        api1Complete: { stage: 'complete', fallbackTotal: progressState.fallbackTotal || 2, fallbackCurrent: 1, isFinal: true, message: 'Finished API 1' },
                        startApi2: { stage: 'start', fallbackTotal: progressState.fallbackTotal || 2, fallbackCurrent: 2, message: 'Fetching items from API 2… ({current} processed so far)' },
                        api2Complete: { stage: 'complete', fallbackTotal: progressState.fallbackTotal || 2, fallbackCurrent: 2, isFinal: true, message: 'Finished API 2' }
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

                        renderProgress(message, progressValue, !!state.isFinal);
                }
        }


});