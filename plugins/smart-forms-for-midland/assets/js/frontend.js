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

                    // Fire ad-platform conversion pixels. Each pixel is gated by
                    // (a) the operator having configured it in Settings → Tracking,
                    // and (b) the pixel's tag script being present on the page
                    // (gtag / fbq / ttq). Missing scripts are silently skipped.
                    try {
                        var tracking = (sfcoData && sfcoData.tracking) || {};

                        // Google Ads: send_to is the conversion ID + label,
                        // e.g. AW-12345/abcDEFghi
                        if (tracking.google_ads_send_to && typeof gtag === 'function') {
                            gtag('event', 'conversion', {
                                send_to: tracking.google_ads_send_to,
                                value: tracking.google_ads_value || 0,
                                currency: tracking.google_ads_currency || 'USD'
                            });
                        }

                        // Facebook / Meta pixel
                        if (tracking.facebook_pixel_id && typeof fbq === 'function') {
                            fbq('track', tracking.facebook_event || 'Lead');
                        }

                        // TikTok pixel
                        if (tracking.tiktok_pixel_id && typeof ttq === 'object' && typeof ttq.track === 'function') {
                            ttq.track(tracking.tiktok_event || 'SubmitForm');
                        }
                    } catch (e) {
                        if (window.console && console.warn) { console.warn('Smart Forms pixel firing failed:', e); }
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
