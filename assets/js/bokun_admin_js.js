jQuery(document).ready(function ($) {
	
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
				call_from_secound_api();
				if (res.success) {
					var msg_all = decodeHTMLEntities(res.data.msg);
					jQuery('.msg_success p').html(`<strong>Success:</strong> ${msg_all}`);
					jQuery('.msg_success').show();
				} else {
					jQuery('.msg_error p').html(`<strong>Error:</strong> ${res.data.msg}`);
					jQuery('.msg_error').show();
				}
			},
			error: function (xhr, status, error) {
				jQuery('#bokun_loader').hide();
            
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
					var msg_all = decodeHTMLEntities(res.data.msg);
					jQuery('.msg_success_upgrade p').html(`<strong>Success:</strong> ${msg_all}`);
					jQuery('.msg_success_upgrade').show();
				} else {
					jQuery('.msg_error_upgrade p').html(`<strong>Error:</strong> ${res.data.msg}`);
					jQuery('.msg_error_upgrade').show();
				}
			},
			error: function (xhr, status, error) {
				jQuery('#bokun_loader_upgrade').hide();
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
	
});