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

                if (!$progress.length) {
                        return;
                }

                $progress.stop(true, true);

                var stepMap = {
                        startApi1: { message: 'Import progress', value: 25 },
                        api1Complete: { message: 'Import progress', value: 50 },
                        startApi2: { message: 'Import progress', value: 75 },
                        api2Complete: { message: 'Import complete', value: 100 }
                };

                if (step === 'reset') {
                        $progress.removeClass('is-error').hide();
                        $message.text('Import progress');
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
                        $progress.removeClass('is-error').show();
                        $message.text(state.message);
                        $value.text(state.value + '%');
                        if ($bar.length) {
                                $bar.css('width', state.value + '%').attr('aria-valuenow', state.value).data('progress-value', state.value);
                        }

                        if (step === 'api2Complete') {
                                setTimeout(function () {
                                        $progress.fadeOut(300);
                                }, 2000);
                        }
                }
        }

});
