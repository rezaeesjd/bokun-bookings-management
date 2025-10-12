jQuery(document).ready(function($) {
    // Handle checkbox change event for Full, Partial, and Not Available checkboxes
    $(document).on('change', '.booking-checkbox', function() {
        var $checkbox = $(this);
        var bookingId = $checkbox.data('booking-id');
        var type = $checkbox.data('type'); // "full", "partial", or "not-available"
        var isChecked = $checkbox.is(':checked');

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
