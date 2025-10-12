jQuery(document).ready(function ($) {

        setImportProgress('reset');

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
                setImportProgress('startApi1');
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
                                        setImportProgress('api1Complete');
                                        call_from_secound_api();
                                        var msg_all = decodeHTMLEntities(res.data.msg);
                                        jQuery('.msg_success p').html(`<strong>Success:</strong> ${msg_all}`);
                                        jQuery('.msg_success').show();
                                } else {
                                        setImportProgress('error');
                                        jQuery('.msg_error p').html(`<strong>Error:</strong> ${res.data.msg}`);
                                        jQuery('.msg_error').show();
                                }
                        },
                        error: function (xhr, status, error) {
                                jQuery('#bokun_loader').hide();
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
                setImportProgress('startApi2');
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
                                        setImportProgress('api2Complete');
                                        var msg_all = decodeHTMLEntities(res.data.msg);
                                        jQuery('.msg_success_upgrade p').html(`<strong>Success:</strong> ${msg_all}`);
                                        jQuery('.msg_success_upgrade').show();
                                } else {
                                        setImportProgress('error');
                                        jQuery('.msg_error_upgrade p').html(`<strong>Error:</strong> ${res.data.msg}`);
                                        jQuery('.msg_error_upgrade').show();
                                }
                        },
                        error: function (xhr, status, error) {
                                jQuery('#bokun_loader_upgrade').hide();
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

        function setImportProgress(step) {
                var $progress = jQuery('#bokun_progress');
                var $message = jQuery('#bokun_progress_message');
                var $value = jQuery('#bokun_progress_value');
                var $bar = jQuery('#bokun_progress_bar');
                var totalImportItems = window.bokunImportProgress && window.bokunImportProgress.totalItems ? window.bokunImportProgress.totalItems : 2;

                if (!$progress.length) {
                        return;
                }

                $progress.stop(true, true);

                var stepMap = {
                        startApi1: { stage: 'start', current: 1 },
                        api1Complete: { stage: 'complete', current: 1 },
                        startApi2: { stage: 'start', current: 2 },
                        api2Complete: { stage: 'complete', current: 2, isFinal: true }
                };

                function formatProgressMessage(current, total, stage, isFinal) {
                        if (isFinal) {
                                return 'Import complete (' + total + '/' + total + ')';
                        }

                        if (stage === 'start') {
                                return 'Importing product ' + current + '/' + total;
                        }

                        return 'Completed product ' + current + '/' + total;
                }

                function computeProgressValue(current, total, stage, explicitValue) {
                        if (typeof explicitValue === 'number') {
                                return explicitValue;
                        }

                        var value;
                        if (stage === 'start') {
                                value = Math.round(((current - 1) / total) * 100);
                        } else {
                                value = Math.round((current / total) * 100);
                        }

                        if (value < 0) {
                                value = 0;
                        }

                        if (value > 100) {
                                value = 100;
                        }

                        return value;
                }

                if (step === 'reset') {
                        $progress.removeClass('is-error').hide();
                        $message.text('Import progress (0/' + totalImportItems + ')');
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

                if (stepMap[step]) {
                        var state = stepMap[step];
                        var current = state.current || totalImportItems;
                        var stage = state.stage || 'complete';
                        var isFinal = !!state.isFinal;
                        var message = formatProgressMessage(current, totalImportItems, stage, isFinal);
                        var progressValue = computeProgressValue(current, totalImportItems, stage, state.value);

                        $progress.removeClass('is-error').show();
                        $message.text(message);
                        $value.text(progressValue + '%');
                        if ($bar.length) {
                                $bar.css('width', progressValue + '%').attr('aria-valuenow', progressValue).data('progress-value', progressValue);
                        }

                        if (step === 'api2Complete') {
                                setTimeout(function () {
                                        $progress.fadeOut(300);
                                }, 2000);
                        }
                }
        }

});