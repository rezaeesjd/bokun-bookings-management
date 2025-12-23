jQuery(document).ready(function ($) {


        var importProgressPollers = {};
        var progressPollInterval = 1000;
        var apiContexts = Array.isArray(bokun_api_auth_vars && bokun_api_auth_vars.apiContexts) ? bokun_api_auth_vars.apiContexts : [];
        var apiContextMap = {};
        var importNotificationMessages = [];

        apiContexts = apiContexts.filter(function (context) {
                return context && typeof context === 'object';
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

        function getApiCredentialsList() {
                return jQuery('[data-api-credentials-list]');
        }

        function getNextCredentialIndex($list) {
                var nextIndex = parseInt($list.attr('data-next-index'), 10);

                if (isNaN(nextIndex) || nextIndex < 0) {
                        nextIndex = $list.children('[data-api-credentials-item]').length;
                }

                return nextIndex;
        }

        function setNextCredentialIndex($list, value) {
                $list.attr('data-next-index', value);
        }

        function toggleCredentialRemoveButtons($list) {
                var $items = $list.children('[data-api-credentials-item]');
                var shouldDisable = $items.length <= 1;

                $items.find('[data-api-credentials-remove]').prop('disabled', shouldDisable);
        }

        function renumberApiCredentialItems($list) {
                var $items = $list.children('[data-api-credentials-item]');

                $items.each(function (index) {
                        var $item = jQuery(this);
                        $item.attr('data-index', index);

                        $item.find('.bokun_api_credentials__item-title').each(function () {
                                jQuery(this).text('API #' + (index + 1));
                        });

                        $item.find('input[name]').each(function () {
                                var $input = jQuery(this);
                                var name = $input.attr('name');

                                if (!name) {
                                        return;
                                }

                                var updatedName = name.replace(/api_credentials\[[^\]]*\]/, 'api_credentials[' + index + ']');
                                $input.attr('name', updatedName);
                        });
                });

                setNextCredentialIndex($list, $items.length);
                toggleCredentialRemoveButtons($list);
                refreshApiContextsFromForm();
        }

        function buildCredentialItem(index) {
                var template = document.getElementById('bokun-api-credential-template');

                if (!template) {
                        return null;
                }

                var html = template.innerHTML.replace(/__index__/g, index).replace(/__number__/g, index + 1);

                return jQuery(html);
        }

        function refreshApiContextsFromForm() {
                var $list = getApiCredentialsList();
                var $items = $list.children('[data-api-credentials-item]');

                apiContexts = [];
                apiContextMap = {};

                $items.each(function (index) {
                        var $item = jQuery(this);
                        var apiKeyValue = $item.find('input[name$="[api_key]"]').val();
                        var secretValue = $item.find('input[name$="[secret_key]"]').val();

                        apiKeyValue = typeof apiKeyValue === 'string' ? apiKeyValue.trim() : '';
                        secretValue = typeof secretValue === 'string' ? secretValue.trim() : '';

                        if (!apiKeyValue || !secretValue) {
                                return;
                        }

                        var slug = 'api_' + (apiContexts.length + 1);
                        var label = 'API #' + (apiContexts.length + 1);
                        var definition = {
                                slug: slug,
                                label: label,
                                index: apiContexts.length + 1
                        };

                        apiContexts.push(definition);
                        apiContextMap[slug] = definition;
                });

                CONTEXT_DISPLAY_ORDER = apiContexts.length ? apiContexts.map(function (context) {
                        return context.slug;
                }) : [];
        }

        jQuery(document).on('click', '[data-api-credentials-add]', function (event) {
                event.preventDefault();

                var $list = getApiCredentialsList();
                var nextIndex = getNextCredentialIndex($list);
                var $item = buildCredentialItem(nextIndex);

                if (!$item || !$item.length) {
                        alert('Unable to add another API field.');
                        return;
                }

                $list.append($item);
                renumberApiCredentialItems($list);
        });

        jQuery(document).on('click', '[data-api-credentials-remove]', function (event) {
                event.preventDefault();

                var $button = jQuery(this);
                var $list = getApiCredentialsList();
                var $item = $button.closest('[data-api-credentials-item]');

                if (!$item.length) {
                        return;
                }

                $item.remove();

                if (!$list.children('[data-api-credentials-item]').length) {
                        var $newItem = buildCredentialItem(0);

                        if ($newItem && $newItem.length) {
                                $list.append($newItem);
                        }
                }

                renumberApiCredentialItems($list);
        });

        jQuery(document).on('submit', '#bokun_api_credentials_form', function (event) {
                event.preventDefault();

                var form = this;
                var formData = new FormData(form);
                formData.append('action', 'bokun_save_api_credentials');
                formData.append('security', bokun_api_auth_vars.nonce);

                var $successNotice = jQuery('.msg_success_apis');
                var $errorNotice = jQuery('.msg_error_apis');

                $successNotice.hide();
                $errorNotice.hide();

                jQuery.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json'
                }).done(function (res) {
                        if (res && res.success) {
                                var message = res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : 'API keys saved successfully.';
                                $successNotice.find('p').html('<strong>Success:</strong> ' + message);
                                $successNotice.show();
                                refreshApiContextsFromForm();
                        } else {
                                var errorMessage = res && res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : 'An unexpected error occurred.';
                                $errorNotice.find('p').html('<strong>Error:</strong> ' + errorMessage);
                                $errorNotice.show();
                        }
                }).fail(function () {
                        $errorNotice.find('p').html('<strong>Error:</strong> An unexpected error occurred.');
                        $errorNotice.show();
                });
        });

        renumberApiCredentialItems(getApiCredentialsList());

        jQuery(document).on('click', '.bokun_dashboard_settings_save', function () {
                var form = jQuery('#bokun_dashboard_settings_form')[0];

                if (!form) {
                        return;
                }

                var ajaxUrl = (typeof ajaxurl !== 'undefined' && ajaxurl) ? ajaxurl : (bokun_api_auth_vars && bokun_api_auth_vars.ajax_url ? bokun_api_auth_vars.ajax_url : '');

                if (!ajaxUrl) {
                        return;
                }

                var formData = new FormData(form);
                formData.append('action', 'bokun_save_dashboard_settings');
                formData.append('security', bokun_api_auth_vars.nonce);

                jQuery.ajax({
                        type: 'POST',
                        url: ajaxUrl,
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function (res) {
                                jQuery('.msg_dashboard_success, .msg_dashboard_error').hide();

                                if (res && res.success) {
                                        var message = res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : '';
                                        jQuery('.msg_dashboard_success p').html('<strong>Success:</strong> ' + message);
                                        jQuery('.msg_dashboard_success').show();
                                } else {
                                        var errorMessage = res && res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : 'An unexpected error occurred.';
                                        jQuery('.msg_dashboard_error p').html('<strong>Error:</strong> ' + errorMessage);
                                        jQuery('.msg_dashboard_error').show();
                                }
                        },
                        error: function (xhr, status, error) {
                                jQuery('.msg_dashboard_success').hide();
                                var message = 'An error occurred. Please try again.';

                                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg) {
                                        message = decodeHTMLEntities(xhr.responseJSON.data.msg);
                                }

                                jQuery('.msg_dashboard_error p').html('<strong>Error:</strong> ' + message);
                                jQuery('.msg_dashboard_error').show();
                                console.error('Error:', error);
                        }
                });
        });

        function runAdminImportSequence(startIndex) {
                var $button = jQuery('.bokun_fetch_booking_data');

                if (!Array.isArray(apiContexts) || !apiContexts.length) {
                        $button.prop('disabled', false).val('Fetch');
                        setImportProgress('error', { message: 'No API credentials configured.' });
                        alert('No API credentials configured.');
                        return;
                }

                var index = typeof startIndex === 'number' ? startIndex : 0;

                if (index >= apiContexts.length) {
                        setImportProgress('allComplete', { totalContexts: apiContexts.length });
                        $button.prop('disabled', false).val('Fetch');
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

                jQuery.ajax({
                        type: 'POST',
                        url: ajaxurl,
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
                                        var decoded = decodeHTMLEntities(res.data.progress_message);
                                        setImportProgress('summaryUpdate', {
                                                summary: { total: 0, processed: 0, created: 0, updated: 0, skipped: 0 },
                                                message: decoded,
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

                                runAdminImportSequence(index + 1);
                        } else {
                                setImportProgress('error', {
                                        context: contextSlug,
                                        message: res && res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : ''
                                });

                                var errorMessage = res && res.data && res.data.msg ? decodeHTMLEntities(res.data.msg) : 'An unexpected error occurred.';
                                alert(errorMessage);
                                $button.prop('disabled', false).val('Fetch');
                        }
                }).fail(function (xhr) {
                        stopImportProgressPolling(contextSlug);
                        setImportProgress('error', { context: contextSlug });

                        var responseText = xhr && xhr.responseText ? xhr.responseText : '';
                        var formattedMessage;

                        try {
                                var parsed = responseText ? JSON.parse(responseText) : null;
                                formattedMessage = parsed && parsed.message ? 'Error: ' + parsed.message : 'Error: ' + responseText;
                        } catch (e) {
                                formattedMessage = 'Error: Received unexpected response code ' + (xhr ? xhr.status : '') + '. Response: ' + responseText;
                        }

                        alert(formattedMessage);
                        $button.prop('disabled', false).val('Fetch');
                });
        }

        jQuery(document).on('click', '.bokun_fetch_booking_data', function (e) {
                e.preventDefault();

                if (typeof ajaxurl === 'undefined' || !ajaxurl) {
                        alert('AJAX endpoint is not available.');
                        return;
                }

                if (!apiContexts.length) {
                        alert('No API credentials configured.');
                        return;
                }

                var $button = jQuery('.bokun_fetch_booking_data');

                $button.prop('disabled', true).val('Processing…');
                jQuery('.msg_sec, .msg_success, .msg_error').hide();
                stopAllImportProgressPolling();
                resetImportProgressState();
                setImportProgress('reset');
                resetImportNotifications();

                runAdminImportSequence(0);
        });

        function appendMessageList($container, messages) {
                if (!$container || !$container.length || !messages || !messages.length) {
                        return;
                }

                var list = jQuery('<ul class="bokun-admin-message-list"></ul>');

                messages.forEach(function (message) {
                        if (!message) {
                                return;
                        }

                        var normalized = decodeHTMLEntities(message);
                        var item = jQuery('<li></li>').text(normalized);
                        list.append(item);
                });

                if (list.children().length) {
                        $container.append(list);
                }
        }

        jQuery(document).on('click', '.bokun_import_product_tag_images', function (e) {
                e.preventDefault();

                var $button = jQuery(this);

                if ($button.prop('disabled')) {
                        return;
                }

                var originalLabel = $button.val();
                var loadingLabel = $button.data('loadingText') || $button.data('loading-text') || 'Importing…';

                $button.prop('disabled', true).val(loadingLabel);

                var $successNotice = jQuery('.msg_product_images_success');
                var $errorNotice = jQuery('.msg_product_images_error');

                $successNotice.hide().find('.bokun-admin-message-list').remove();
                $errorNotice.hide().find('.bokun-admin-message-list').remove();

                jQuery.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                                action: 'bokun_import_product_tag_images',
                                security: bokun_api_auth_vars.nonce
                        },
                        dataType: 'json'
                }).done(function (res) {
                        if (res && res.success && res.data) {
                                var responseData = res.data;
                                var message = responseData.message ? decodeHTMLEntities(responseData.message) : '';
                                var messages = Array.isArray(responseData.messages) ? responseData.messages : [];

                                if (responseData.has_errors) {
                                        $errorNotice.find('p').html('<strong>Error:</strong> ' + message);
                                        appendMessageList($errorNotice, messages);
                                        $errorNotice.show();
                                } else {
                                        $successNotice.find('p').html('<strong>Success:</strong> ' + message);
                                        appendMessageList($successNotice, messages);
                                        $successNotice.show();
                                }
                        } else {
                                var errorMessage = 'An unexpected error occurred. Please try again.';

                                if (res && res.data && res.data.msg) {
                                        errorMessage = decodeHTMLEntities(res.data.msg);
                                }

                                $errorNotice.find('p').html('<strong>Error:</strong> ' + errorMessage);
                                $errorNotice.show();
                        }
                }).fail(function (xhr) {
                        var errorText = 'An unexpected error occurred. Please try again.';

                        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.msg) {
                                errorText = decodeHTMLEntities(xhr.responseJSON.data.msg);
                        }

                        $errorNotice.find('p').html('<strong>Error:</strong> ' + errorText);
                        $errorNotice.show();
                }).always(function () {
                        $button.prop('disabled', false).val(originalLabel);
                });
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
                var $progress = jQuery('#bokun_progress');
                var $message = jQuery('#bokun_progress_message');
                var $value = jQuery('#bokun_progress_value');
                var $bar = jQuery('#bokun_progress_bar');
                var $spinner = jQuery('#bokun_progress_spinner');

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

                        // Keep the progress visible after a successful fetch so the
                        // user can review the final status rather than having it fade
                        // away automatically.
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


});
