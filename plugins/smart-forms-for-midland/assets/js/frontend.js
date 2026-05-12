jQuery(document).ready(function($) {
    $('#smart-forms-quote-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitButton = form.find('.submit-button');
        var messageDiv = form.find('#form-message');
        
        // Disable button
        submitButton.prop('disabled', true).text('Sending...');
        messageDiv.removeClass('success error').hide();
        
        // Prepare form data
        var formData = new FormData(this);
        formData.append('action', 'sfco_submit');
        formData.append('_wpnonce', sfcoData.nonce);
        
        // Submit via AJAX
        $.ajax({
            url: sfcoData.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    messageDiv.addClass('success').text(response.data.message).fadeIn();
                    form[0].reset();
                    
                    // Show estimate if available
                    if (response.data.estimate) {
                        var estimateText = 'Estimated cost: $' + 
                            Math.round(response.data.estimate.min).toLocaleString() + 
                            ' - $' + 
                            Math.round(response.data.estimate.max).toLocaleString();
                        messageDiv.append('<br><strong>' + estimateText + '</strong>');
                    }
                } else {
                    messageDiv.addClass('error').text(response.data.message).fadeIn();
                }
            },
            error: function() {
                messageDiv.addClass('error').text('An error occurred. Please try again.').fadeIn();
            },
            complete: function() {
                submitButton.prop('disabled', false).text('Get Quote');
            }
        });
    });
});
