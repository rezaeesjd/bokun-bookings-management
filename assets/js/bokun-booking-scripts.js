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
});