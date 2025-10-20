jQuery(document).ready(function($) {
    function writeTeamMemberCookie(key, value) {
        if (!key) {
            return;
        }

        var expires = new Date();
        expires.setFullYear(expires.getFullYear() + 1);
        document.cookie = encodeURIComponent(key) + '=' + encodeURIComponent(value) + '; expires=' + expires.toUTCString() + '; path=/';
    }

    function clearTeamMemberStorage(key) {
        if (!key) {
            return;
        }

        try {
            window.localStorage.removeItem(key);
        } catch (error) {}

        try {
            window.sessionStorage.removeItem(key);
        } catch (error) {}

        document.cookie = encodeURIComponent(key) + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
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
        $checkbox.after('<span class="loading-message" style="color: blue; margin-left: 10px;">Loading...</span>');

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
                if (response.success) {
                    $checkbox.after('<span class="save-message" style="color: green; margin-left: 10px;">Saved</span>');
                } else {
                    $checkbox.after('<span class="save-message" style="color: red; margin-left: 10px;">Error</span>');
                }
            },
            error: function() {
                $checkbox.siblings('.loading-message').remove();
                $checkbox.after('<span class="save-message" style="color: red; margin-left: 10px;">Error</span>');
            }
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
                $message.text(response.data && response.data.message ? response.data.message : 'Saved');
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
                var errorMessage = response && response.data && response.data.message ? response.data.message : 'Unable to save team member.';
                $message.text(errorMessage);
            }
        }).fail(function() {
            $message.text('Unable to save team member.');
        }).always(function() {
            $form.removeClass('is-loading');
        });
    });
});
