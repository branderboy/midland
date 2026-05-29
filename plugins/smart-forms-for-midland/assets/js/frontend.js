jQuery(document).ready(function($) {
    // Bind by class, not by ID. The plugin renders the same id="smart-forms-quote-form"
    // on three different templates, and duplicate IDs on one page break selectors.
    $('.smart-forms-form').on('submit', function(e) {
        e.preventDefault();

        var form = $(this);
        var submitButton = form.find('.submit-button');
        var originalText = submitButton.text();
        var messageDiv = form.find('.form-message');

        // Resolve ajaxurl and nonce defensively. If sfcoData failed to load
        // (caching plugin, footer reorder, conflict), fall back to the nonce
        // printed into the form by wp_nonce_field and the standard admin-ajax path.
        var data = (typeof sfcoData !== 'undefined') ? sfcoData : {};
        var ajaxurl = data.ajaxurl || (window.ajaxurl) || '/wp-admin/admin-ajax.php';
        var nonce = data.nonce || form.find('input[name="_wpnonce"]').val() || '';

        submitButton.prop('disabled', true).text('Sending...');
        messageDiv.removeClass('success error').hide();

        var formData = new FormData(this);
        formData.append('action', 'sfco_submit');
        // FormData already includes the form's _wpnonce field; only append if missing.
        if (!formData.get('_wpnonce') && nonce) {
            formData.append('_wpnonce', nonce);
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response && response.success) {
                    messageDiv.addClass('success').text(response.data.message).fadeIn();
                    form[0].reset();
                    $(document).trigger('sfco:submitted', [ response.data, form ]);

                    // Redirect after submit (e.g. Calendly) — but NOT when the
                    // form is embedded in the chat widget, which keeps the
                    // visitor in the conversation and shows its own booking button.
                    if (response.data.redirect && form.closest('#smart-chat-form').length === 0) {
                        window.location.href = response.data.redirect;
                        return;
                    }

                    if (response.data.estimate) {
                        var estimateText = 'Estimated cost: $' +
                            Math.round(response.data.estimate.min).toLocaleString() +
                            ' - $' +
                            Math.round(response.data.estimate.max).toLocaleString();
                        messageDiv.append('<br><strong>' + estimateText + '</strong>');
                    }

                    try {
                        var tracking = (data && data.tracking) || {};
                        if (tracking.google_ads_send_to && typeof gtag === 'function') {
                            gtag('event', 'conversion', {
                                send_to: tracking.google_ads_send_to,
                                value: tracking.google_ads_value || 0,
                                currency: tracking.google_ads_currency || 'USD'
                            });
                        }
                        if (tracking.facebook_pixel_id && typeof fbq === 'function') {
                            fbq('track', tracking.facebook_event || 'Lead');
                        }
                        if (tracking.tiktok_pixel_id && typeof ttq === 'object' && typeof ttq.track === 'function') {
                            ttq.track(tracking.tiktok_event || 'SubmitForm');
                        }
                    } catch (err) {
                        if (window.console && console.warn) { console.warn('Smart Forms pixel firing failed:', err); }
                    }
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Submission failed. Please try again.';
                    messageDiv.addClass('error').text(msg).fadeIn();
                }
            },
            error: function(xhr) {
                var msg = 'An error occurred. Please try again.';
                if (window.console) { console.error('Smart Forms submit error:', xhr.status, xhr.responseText); }
                messageDiv.addClass('error').text(msg).fadeIn();
            },
            complete: function() {
                // Restore the button's own label instead of hardcoding "Get Quote".
                submitButton.prop('disabled', false).text(originalText);
            }
        });
    });
});
