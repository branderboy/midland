jQuery(document).ready(function($) {
    'use strict';

    // License activation.
    $('#smart-chat-activate-btn').on('click', function(){
        var $btn = $(this), $result = $('#smart-chat-license-result');
        var key = $('#smart-chat-license-key').val();
        if (!key) { $result.html('<span style="color:red;">Enter a license key.</span>'); return; }
        $btn.prop('disabled', true).text('Validating...');
        $.post(scaiAdmin.ajaxurl, {
            action: 'scai_validate_license',
            nonce: scaiAdmin.nonce,
            license_key: key
        }, function(res){
            if (res.success) {
                $result.html('<span style="color:green;">' + res.data.message + '</span>');
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                $result.html('<span style="color:red;">' + (res.data ? res.data.message : 'Failed') + '</span>');
            }
        }).always(function(){
            $btn.prop('disabled', false).text('Activate License');
        });
    });
});
