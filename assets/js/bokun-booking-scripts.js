jQuery(document).ready(function($) {
    var DEFAULT_TEAM_MEMBER_STORAGE_KEY = (typeof bbm_ajax !== 'undefined' && bbm_ajax.team_member_storage_key) ? bbm_ajax.team_member_storage_key : 'bbm_team_member_authorized';
    var TEAM_MEMBER_STATUS_TEMPLATE = (typeof bbm_ajax !== 'undefined' && bbm_ajax.team_member_status_template) ? bbm_ajax.team_member_status_template : 'Authorized as %s';
    var TEAM_MEMBER_STORAGE_WARNING = (typeof bbm_ajax !== 'undefined' && bbm_ajax.team_member_storage_warning) ? bbm_ajax.team_member_storage_warning : '';
    var activeTeamMemberLocks = 0;

    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function formatStatusText(name) {
        var template = TEAM_MEMBER_STATUS_TEMPLATE || 'Authorized as %s';
        if (typeof name !== 'string') {
            return template.replace(/%1\$s|%s/, '');
        }

        return template.replace(/%1\$s|%s/, name);
    }

    function refreshBodyLockState() {
        if (activeTeamMemberLocks > 0) {
            $('body').addClass('bbm-team-member-locked');
        } else {
            $('body').removeClass('bbm-team-member-locked');
        }
    }

    function getStorageKey($context) {
        if ($context && $context.length) {
            var dataKey = $context.attr('data-storage-key') || $context.data('storage-key');
            if (typeof dataKey === 'string' && dataKey.length) {
                return dataKey;
            }
        }

        return DEFAULT_TEAM_MEMBER_STORAGE_KEY;
    }

    function readStoredTeamMember(key) {
        var storageKey = typeof key === 'string' && key.length ? key : DEFAULT_TEAM_MEMBER_STORAGE_KEY;
        var stored = '';

        try {
            stored = window.localStorage.getItem(storageKey) || '';
        } catch (err) {
            stored = '';
        }

        if (stored && stored.length) {
            return stored;
        }

        if (document.cookie && document.cookie.length) {
            try {
                var pattern = new RegExp('(?:^|; )' + escapeRegExp(storageKey) + '=([^;]*)');
                var match = document.cookie.match(pattern);
                if (match && match[1]) {
                    return decodeURIComponent(match[1]);
                }
            } catch (cookieError) {
                return '';
            }
        }

        return '';
    }

    function storeTeamMember(key, value) {
        var storageKey = typeof key === 'string' && key.length ? key : DEFAULT_TEAM_MEMBER_STORAGE_KEY;
        var stored = false;

        if (typeof value !== 'string' || !value.length) {
            return stored;
        }

        try {
            window.localStorage.setItem(storageKey, value);
            stored = true;
        } catch (storageError) {
            stored = false;
        }

        if (!stored) {
            try {
                var cookie = storageKey + '=' + encodeURIComponent(value) + '; path=/; max-age=' + (60 * 60 * 24 * 365);
                document.cookie = cookie;
                stored = true;
            } catch (cookieError) {
                stored = false;
            }
        }

        return stored;
    }

    function focusTeamMemberInput($gate) {
        if (!$gate || !$gate.length) {
            return;
        }

        var $input = $gate.find('input[name="team_member_name"]').first();
        if ($input.length) {
            setTimeout(function() {
                $input.trigger('focus');
            }, 50);
        }
    }

    function updateStatus($gate, memberName, options) {
        var $status = $gate && $gate.length ? $gate.find('.bbm-team-member-lock__status') : $();
        if (!$status.length) {
            return;
        }

        var lines = [];

        if (memberName) {
            lines.push(formatStatusText(memberName));
        }

        if (options && options.message) {
            lines.push(options.message);
        }

        if (options && options.warning) {
            lines.push(options.warning);
        }

        if (lines.length) {
            $status.text(lines.join(' ')).show();
        } else {
            $status.text('').hide();
        }
    }

    function activateTeamMemberLock($gate) {
        if (!$gate || !$gate.length) {
            return;
        }

        if ($gate.data('bbmLockActive')) {
            focusTeamMemberInput($gate);
            return;
        }

        $gate.data('bbmLockActive', true);
        activeTeamMemberLocks += 1;
        refreshBodyLockState();

        $gate.removeClass('is-authorized').addClass('is-lock-active');
        updateStatus($gate);
        focusTeamMemberInput($gate);
    }

    function deactivateTeamMemberLock($gate, memberName, options) {
        if (!$gate || !$gate.length) {
            return;
        }

        if ($gate.data('bbmLockActive')) {
            activeTeamMemberLocks = Math.max(0, activeTeamMemberLocks - 1);
            $gate.data('bbmLockActive', false);
        }

        refreshBodyLockState();

        $gate.removeClass('is-lock-active').addClass('is-authorized');
        updateStatus($gate, memberName, options);
    }

    function initialiseTeamMemberGate($gate) {
        if (!$gate || !$gate.length) {
            return;
        }

        var storageKey = getStorageKey($gate);
        var storedName = $gate.attr('data-authorized-name') || readStoredTeamMember(storageKey);

        if ($gate.attr('data-authorized-name')) {
            $gate.removeAttr('data-authorized-name');
        }

        if (storedName) {
            $gate.removeClass('is-lock-active').addClass('is-authorized');
            $gate.data('bbmLockActive', false);
            updateStatus($gate, storedName);
            refreshBodyLockState();
            return;
        }

        activateTeamMemberLock($gate);
    }

    $('.bbm-team-member-lock').each(function() {
        initialiseTeamMemberGate($(this));
    });

    refreshBodyLockState();

    // Handle checkbox change event for Full, Partial, and Not Available checkboxes
    $(document).on('change', '.booking-checkbox', function() {
        var $checkbox = $(this);
        var bookingId = $checkbox.data('booking-id');
        var type = $checkbox.data('type');
        var isChecked = $checkbox.is(':checked');

        $checkbox.siblings('.save-message, .loading-message').remove();
        $checkbox.after('<span class="loading-message" style="color: blue; margin-left: 10px;">Loading...</span>');

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
            }
        }).done(function(response) {
            $checkbox.siblings('.loading-message').remove();
            if (response && response.success) {
                $checkbox.after('<span class="save-message" style="color: green; margin-left: 10px;">Saved</span>');
            } else {
                $checkbox.after('<span class="save-message" style="color: red; margin-left: 10px;">Error</span>');
            }
        }).fail(function() {
            $checkbox.siblings('.loading-message').remove();
            $checkbox.after('<span class="save-message" style="color: red; margin-left: 10px;">Error</span>');
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
        var $gate = $form.closest('.bbm-team-member-lock');
        var teamMemberName = $.trim($input.val());
        var storageKey = getStorageKey($gate);

        $message.text('');

        if (!teamMemberName.length) {
            $message.text('Please enter a team member name.');
            $input.trigger('focus');
            return;
        }

        $form.addClass('is-loading').attr('aria-busy', 'true');
        $form.find('button[type="submit"]').prop('disabled', true);
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
            var responseData = response && response.data ? response.data : {};

            if (response && response.success) {
                var responseMessage = responseData.message || 'Saved';
                var memberName = responseData.team_member_name || teamMemberName;
                var persisted = storeTeamMember(storageKey, memberName);
                var statusOptions = { message: responseMessage };

                if (!persisted && TEAM_MEMBER_STORAGE_WARNING) {
                    statusOptions.warning = TEAM_MEMBER_STORAGE_WARNING;
                }

                deactivateTeamMemberLock($gate, memberName, statusOptions);
                $form.trigger('reset');
                $message.text('');
            } else {
                var errorMessage = responseData.message || 'Unable to save team member.';
                $message.text(errorMessage);
                activateTeamMemberLock($gate);
            }
        }).fail(function() {
            $message.text('Unable to save team member.');
            activateTeamMemberLock($gate);
        }).always(function() {
            $form.removeClass('is-loading').removeAttr('aria-busy');
            $form.find('button[type="submit"]').prop('disabled', false);
        });
    });
});
