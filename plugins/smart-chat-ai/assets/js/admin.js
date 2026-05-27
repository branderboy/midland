jQuery(document).ready(function($) {
    'use strict';

    $('#smart_chat_logo_upload').on('click', function(e) {
        e.preventDefault();
        if (typeof wp === 'undefined' || !wp.media) {
            return;
        }
        var frame = wp.media({
            title: 'Select chat logo',
            button: { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#smart_chat_chat_logo').val(attachment.url);
        });
        frame.open();
    });
});
