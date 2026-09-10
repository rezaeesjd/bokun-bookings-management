jQuery(document).ready(function($) {
    function writeTeamMemberCookie(key, value) {
        if (!key || typeof document === 'undefined') {
            return;
        }

        var expires = new Date();
        expires.setFullYear(expires.getFullYear() + 1);
        document.cookie = [
            encodeURIComponent(key) + '=' + encodeURIComponent(value || ''),
            'expires=' + expires.toUTCString(),
            'path=/',
            'SameSite=Lax'
        ].join('; ');
    }

    function clearTeamMemberStorage(key) {
        if (!key || typeof document === 'undefined') {
            return;
        }

        try {
            window.localStorage.removeItem(key);
        } catch (error) {}

        try {
            window.sessionStorage.removeItem(key);
        } catch (error) {}

        document.cookie = [
            encodeURIComponent(key) + '=;',
            'expires=Thu, 01 Jan 1970 00:00:00 GMT',
            'path=/',
            'SameSite=Lax'
        ].join('; ');
    }

    function storeTeamMemberValue(key, value, legacyKey) {
        if (!key) {
            return;
        }

        var stored = false;

        try {
            window.localStorage.setItem(key, value);
            stored = true;
        } catch (error) {
            stored = false;
        }

        if (!stored) {
            try {
                window.sessionStorage.setItem(key, value);
                stored = true;
            } catch (error) {
                stored = false;
            }
        }

        writeTeamMemberCookie(key, value);

        if (legacyKey && legacyKey !== key) {
            clearTeamMemberStorage(legacyKey);
        }
    }

    // Handle checkbox change event for Full, Partial, Refund Requested from Partner, and Not Available checkboxes
    $(document).on('change', '.booking-checkbox', function() {
        var $checkbox = $(this);
        var bookingId = $checkbox.data('booking-id');
        var type = $checkbox.data('type'); // "full", "partial", "refund-partner", or "not-available"
        var isChecked = $checkbox.is(':checked');

        if (typeof bbm_ajax === 'undefined' || !bbm_ajax.nonce || !bbm_ajax.ajax_url) {
            return;
        }

        $checkbox.siblings('.save-message, .loading-message').remove();

        var loadingMessage = $('<span/>', {
            class: 'loading-message',
            text: 'Loading...'
        }).css({
            color: 'blue',
            marginLeft: '10px'
        });

        $checkbox.after(loadingMessage);

        // Send AJAX request to update booking status
        $.ajax({
            url: bbm_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'update_booking_status',
                security: bbm_ajax.nonce,
                booking_id: bookingId,
                checked: isChecked,
                type: type
            },
            success: function(response) {
                $checkbox.siblings('.loading-message').remove();

                var messageOptions = {
                    class: 'save-message',
                    text: response && response.success ? 'Saved' : 'Error'
                };

                var messageStyles = {
                    color: response && response.success ? 'green' : 'red',
                    marginLeft: '10px'
                };

                $('<span/>', messageOptions).css(messageStyles).insertAfter($checkbox);
            },
            error: function() {
                $checkbox.siblings('.loading-message').remove();

                $('<span/>', {
                    class: 'save-message',
                    text: 'Error'
                }).css({
                    color: 'red',
                    marginLeft: '10px'
                }).insertAfter($checkbox);
            }
        });
    });

    // Result disclosure: reveal the Full/Partial/Not available options on click.
    $(document).on('click', '[data-result-toggle]', function() {
        var $toggle = $(this);
        var $panel = $toggle.siblings('[data-result-panel]').first();

        if (!$panel.length) {
            return;
        }

        var isHidden = $panel.prop('hidden');
        $panel.prop('hidden', !isHidden);
        $toggle.attr('aria-expanded', isHidden ? 'true' : 'false');
    });

    // Show the Payment method sub-question only once a result is selected, and
    // keep the collapsed "Result" summary in sync.
    function updateResultState($result) {
        if (!$result || !$result.length) {
            return;
        }

        var resultTypes = ['full', 'partial', 'not-available'];
        var selected = [];

        $result.find('.booking-checkbox').each(function() {
            var type = String($(this).data('type'));

            if (resultTypes.indexOf(type) !== -1 && $(this).is(':checked')) {
                selected.push($(this).closest('.bokun-booking-dashboard__toggle').find('span').first().text());
            }
        });

        var hasSelection = selected.length > 0;
        var $payment = $result.find('[data-payment]').first();

        if ($payment.length) {
            $payment.prop('hidden', !hasSelection);
        }

        var $summary = $result.find('[data-result-summary]').first();

        if ($summary.length) {
            $summary.text(hasSelection ? selected.join(', ') : '');
        }
    }

    $(document).on('change', '.booking-checkbox', function() {
        updateResultState($(this).closest('[data-result]'));
    });

    $(function() {
        $('[data-result]').each(function() {
            updateResultState($(this));
        });
    });

    // Handle Team Member form submission
    $(document).on('submit', '.bokun-team-member-form', function(event) {
        event.preventDefault();

        if (typeof bbm_ajax === 'undefined' || !bbm_ajax.team_member_nonce) {
            return;
        }

        var $form = $(this);
        var $input = $form.find('input[name="team_member_name"]');
        var $message = $form.find('.bokun-team-member-message');
        var teamMemberName = $.trim($input.val());

        $message.text('');

        if (!teamMemberName.length) {
            $message.text('Please enter a team member name.');
            return;
        }

        $form.addClass('is-loading');
        $message.text('Saving...');

        $.ajax({
            url: bbm_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'add_team_member',
                security: bbm_ajax.team_member_nonce,
                team_member_name: teamMemberName
            }
        }).done(function(response) {
            if (response && response.success) {
                var successMessage = 'Saved';

                if (response.data && response.data.message) {
                    successMessage = response.data.message;
                }

                $message.text(successMessage);
                if (response.data && response.data.created) {
                    $input.val('');
                }

                var overlayId = $form.data('overlay-id');
                var storageKey = $form.data('storageKey') || $form.data('storage-key');
                var legacyStorageKey = $form.data('legacyStorageKey') || $form.data('legacy-storage-key');
                var accessRegistry = window.bokunTeamMemberAccess || {};
                var accessEntry = overlayId ? accessRegistry[overlayId] : null;

                if (accessEntry && typeof accessEntry.save === 'function') {
                    accessEntry.save(teamMemberName);
                } else if (storageKey) {
                    storeTeamMemberValue(storageKey, teamMemberName, legacyStorageKey);
                }

                if (accessEntry && typeof accessEntry.unlock === 'function') {
                    accessEntry.unlock();
                } else if (overlayId) {
                    var overlay = document.getElementById(overlayId);

                    if (overlay) {
                        overlay.classList.remove('is-visible');
                        overlay.setAttribute('aria-hidden', 'true');
                    }

                    $('body').removeClass('bokun-team-member-overlay-active');
                } else {
                    $('body').removeClass('bokun-team-member-overlay-active');
                }
            } else {
                var errorMessage = 'Unable to save team member.';

                if (response && response.data && response.data.message) {
                    errorMessage = response.data.message;
                }

                $message.text(errorMessage);
            }
        }).fail(function() {
            $message.text('Unable to save team member.');
        }).always(function() {
            $form.removeClass('is-loading');
        });
    });
});
