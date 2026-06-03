jQuery(document).ready(function($) {
    'use strict';

    $('.scrm-launch-btn').on('click', function() {
        if (!confirm('Launch this campaign? Emails will start sending immediately.')) return;
        var $btn = $(this), id = $btn.data('id');
        $btn.prop('disabled', true).text('Launching...');
        $.post(scrmProData.ajaxurl, {
            action: 'scrm_launch_campaign',
            nonce: scrmProData.nonce,
            campaign_id: id
        }, function(res) {
            if (res.success) {
                $btn.text(res.data.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                $btn.prop('disabled', false).text('Launch');
                alert(res.data ? res.data.message : 'Failed');
            }
        });
    });

    $('.scrm-delete-btn').on('click', function() {
        if (!confirm('Delete this campaign and all queued emails?')) return;
        var $btn = $(this), id = $btn.data('id');
        $.post(scrmProData.ajaxurl, {
            action: 'scrm_delete_campaign',
            nonce: scrmProData.nonce,
            campaign_id: id
        }, function(res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
            }
        });
    });
});
