jQuery(function ($) {

        setImportProgress('reset');

        var ajaxUrl = (typeof bokun_api_auth_vars !== 'undefined' && bokun_api_auth_vars.ajax_url) ? bokun_api_auth_vars.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');

        $(document).on('click', '.bokun_fetch_booking_data_front', function (e) {
                e.preventDefault();

                var $button = $('.bokun_fetch_booking_data_front');

                $button.text('Processing…');
                $('.msg_sec').hide();
                $('#bokun_loader').show();
                setImportProgress('startApi1');

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
                                $('#bokun_loader').hide();
                                $('.msg_success, .msg_error').hide();

                                if (res.success) {
                                        setImportProgress('api1Complete');
                                        call_from_second_api_front();
                                } else {
                                        setImportProgress('error');
                                        alert(res.data.msg);
                                }
                        },
                        error: function (xhr, status, error) {
                                $('#bokun_loader').hide();
                                $button.text('Fetch');
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
                $('#bokun_loader').hide();
                $('#bokun_loader_upgrade').show();
                setImportProgress('startApi2');

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
                                $('#bokun_loader_upgrade').hide();
                                $button.text('Fetch');

                                if (res.success) {
                                        setImportProgress('api2Complete');
                                        var msg_all = decodeHTMLEntities(res.data.msg);
                                        alert(msg_all);
                                } else {
                                        setImportProgress('error');
                                        alert(res.data.msg);
                                }
                        },
                        error: function (xhr, status, error) {
                                $('#bokun_loader_upgrade').hide();
                                $('#bokun_loader').hide();
                                $button.text('Fetch');
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

        function setImportProgress(step) {
                var $progress = $('#bokun_progress');
                var $message = $('#bokun_progress_message');
                var $value = $('#bokun_progress_value');
                var $bar = $('#bokun_progress_bar');
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
