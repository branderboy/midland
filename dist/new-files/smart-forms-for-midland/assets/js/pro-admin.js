jQuery(document).ready(function ($) {
    'use strict';

    /* CRM Test Connection */
    $('#sfco-test-crm').on('click', function () {
        var $btn = $(this);
        var $result = $('#sfco-crm-test-result');
        $btn.prop('disabled', true).text('Testing...');
        $result.text('');

        $.post(sfcoProAdmin.ajaxurl, {
            action: 'sfco_pro_test_crm',
            nonce: sfcoProAdmin.nonce
        }, function (res) {
            if (res.success) {
                $result.html('<span style="color:#00a32a;font-weight:600;">' + res.data.message + '</span>');
            } else {
                $result.html('<span style="color:#d63638;font-weight:600;">' + res.data.message + '</span>');
            }
        }).fail(function () {
            $result.html('<span style="color:#d63638;">Connection failed.</span>');
        }).always(function () {
            $btn.prop('disabled', false).text('Test Connection');
        });
    });

    /* Remove team member */
    $('.sfco-remove-member').on('click', function () {
        if (!confirm('Remove this team member?')) return;
        var $btn = $(this);
        var id = $btn.data('id');

        $.post(sfcoProAdmin.ajaxurl, {
            action: 'sfco_pro_remove_member',
            nonce: sfcoProAdmin.nonce,
            member_id: id
        }, function (res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
            }
        });
    });

    /* Add automation step */
    $('#sfco-add-step').on('click', function () {
        var html = '<div class="sfco-auto-step sfco-card" style="margin-bottom:12px;">' +
            '<div style="display:flex;gap:12px;align-items:center;margin-bottom:10px;">' +
            '<select name="step_type[]">' +
            '<option value="email">Send Email</option>' +
            '<option value="wait">Wait</option>' +
            '</select>' +
            '<label>Delay (hours): <input type="number" name="step_delay[]" value="0" min="0" style="width:70px;"></label>' +
            '<button type="button" class="button sfco-remove-step">&times;</button>' +
            '</div>' +
            '<div>' +
            '<input type="text" name="step_subject[]" class="large-text" placeholder="Email subject..." style="margin-bottom:6px;">' +
            '<textarea name="step_body[]" rows="4" class="large-text" placeholder="Email body (HTML supported)..."></textarea>' +
            '</div></div>';
        $('#sfco-auto-steps').append(html);
    });

    $(document).on('click', '.sfco-remove-step', function () {
        $(this).closest('.sfco-auto-step').remove();
    });

    /* Logo upload via WP media */
    $('#sfco-upload-logo').on('click', function (e) {
        e.preventDefault();
        var frame = wp.media({
            title: 'Select Logo',
            button: { text: 'Use this image' },
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#brand_logo').val(attachment.url);
        });
        frame.open();
    });

    /* CRM sync lead button (on lead detail page) */
    $(document).on('click', '.sfco-sync-crm-btn', function () {
        var $btn = $(this);
        var leadId = $btn.data('lead-id');
        $btn.prop('disabled', true).text('Syncing...');

        $.post(sfcoProAdmin.ajaxurl, {
            action: 'sfco_pro_sync_lead',
            nonce: sfcoProAdmin.nonce,
            lead_id: leadId
        }, function (res) {
            if (res.success) {
                $btn.text('Synced!');
            } else {
                $btn.text('Failed');
            }
            setTimeout(function () {
                $btn.prop('disabled', false).text('Sync to CRM');
            }, 2000);
        });
    });
});
