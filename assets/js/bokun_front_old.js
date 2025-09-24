
    jQuery(document).on('click', '.bokun_fetch_booking_data_front', function (e) {
		e.preventDefault();        
        jQuery('.bokun_fetch_booking_data_front').text('processing...');
        
		jQuery.ajax({
			type: 'POST',
			url: ajaxurl,
			data: {
				action: 'bokun_bookings_manager_page',
				security : bokun_api_auth_vars.nonce,
				mode: 'fetch'
			},
			dataType: 'json',
			success: function (res) {
				
                console.log(res);
				jQuery('.bokun_fetch_booking_data_front').text('Fetch');
				call_from_secound_api_front();
				if (res.success) {
					var msg_all = decodeHTMLEntities(res.data.msg);
				}
			},
			error: function (xhr, status, error) {
				jQuery('#bokun_loader').hide();
            
				// Parse the response text and format the message
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

	function call_from_secound_api_front() {
		
		jQuery('.bokun_fetch_booking_data_front').text('processing again...');
        
		jQuery.ajax({
			type: 'POST',
			url: ajaxurl,
			data: {
				action: 'bokun_bookings_manager_page',
				security : bokun_api_auth_vars.nonce,
				mode: 'upgrade'
			},
			dataType: 'json',
			success: function (res) {
				
                console.log(res);
				jQuery('.bokun_fetch_booking_data_front').text('Fetch');
				
				if (res.success) {
					var msg_all = decodeHTMLEntities(res.data.msg);
					alert(msg_all);
				} else {
					alert(res.data.msg);
				}
			},
			error: function (xhr, status, error) {
				jQuery('#bokun_loader').hide();
            
				// Parse the response text and format the message
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
