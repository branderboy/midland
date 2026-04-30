/* Real Smart SEO Pro — Admin JS */
(function($) {
    'use strict';

    var data    = window.rsseoProData || {};
    var ajaxUrl = data.ajax_url || '';
    var nonce   = data.nonce   || '';
    var str     = data.strings || {};

    // Apply single schema
    $(document).on('click', '.rsseo-pro-apply-schema', function() {
        var $btn      = $(this);
        var schemaId  = $btn.data('schema-id');
        var $row      = $('#rsseo-schema-' + schemaId);

        if (!confirm(str.confirm || 'Apply this schema to your site?')) return;

        $btn.prop('disabled', true).text(str.applying || 'Applying...');

        $.post(ajaxUrl, {
            action:    'rsseo_pro_apply_schema',
            nonce:     nonce,
            schema_id: schemaId
        }, function(res) {
            if (res.success) {
                $btn.replaceWith('<span class="rsseo-status rsseo-status--complete">' + (str.applied || 'Applied!') + '</span>');
                $row.addClass('rsseo-fix--applied');
                $row.find('.rsseo-status--pending').replaceWith('<span class="rsseo-status rsseo-status--complete">Applied</span>');
            } else {
                alert(res.data || str.error || 'Error. Try again.');
                $btn.prop('disabled', false).text('Apply');
            }
        });
    });

    // Apply all schemas
    $(document).on('click', '.rsseo-pro-apply-all-schemas', function() {
        var $btn     = $(this);
        var reportId = $btn.data('report-id');

        if (!confirm(str.confirm_all || 'Apply all schema blocks to your site?')) return;

        $btn.prop('disabled', true).text(str.applying || 'Applying...');

        $.post(ajaxUrl, {
            action:    'rsseo_pro_apply_all_schemas',
            nonce:     nonce,
            report_id: reportId
        }, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || str.error || 'Error.');
                $btn.prop('disabled', false).text('Apply All Schema');
            }
        });
    });

    // Backlink status change
    $(document).on('change', '.rsseo-bl-status', function() {
        var $sel       = $(this);
        var backlinkId = $sel.data('backlink-id');
        var status     = $sel.val();

        $.post(ajaxUrl, {
            action:      'rsseo_pro_update_backlink',
            nonce:       nonce,
            backlink_id: backlinkId,
            status:      status
        });
    });

    // License activate / deactivate
    $(document).on('click', '.rsseo-pro-license-btn', function() {
        var $btn   = $(this);
        var action = $btn.data('action');
        var key    = action === 'activate' ? $('#rsseo-pro-license-key').val().trim() : '';

        $btn.prop('disabled', true).text(action === 'activate' ? 'Activating...' : 'Deactivating...');

        $.post(ajaxUrl, {
            action:         'rsseo_pro_save_license',
            nonce:          nonce,
            license_action: action,
            license_key:    key
        }, function(res) {
            showMsg(res.success ? 'success' : 'error', res.data ? (res.data.message || res.data) : 'Error');
            $btn.prop('disabled', false);
            if (res.success) {
                setTimeout(function() { location.reload(); }, 1200);
            }
        });
    });

    // Save DataForSEO credentials
    $('#rsseo-save-dfs').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Saving...');
        $.post(ajaxUrl, {
            action:       'rsseo_pro_save_settings',
            nonce:        nonce,
            dfs_login:    $('#rsseo-dfs-login').val().trim(),
            dfs_password: $('#rsseo-dfs-password').val().trim()
        }, function(res) {
            showMsg(res.success ? 'success' : 'error', res.data ? (res.data.message || res.data) : 'Error');
            $btn.prop('disabled', false).text('Save Credentials');
            if (res.success) setTimeout(function() { location.reload(); }, 1200);
        });
    });

    // Test DataForSEO
    $('#rsseo-test-dfs').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Testing...');
        $.post(ajaxUrl, { action: 'rsseo_pro_test_dfs', nonce: nonce }, function(res) {
            showMsg(res.success ? 'success' : 'error', res.data ? (res.data.message || res.data) : 'Error');
            $btn.prop('disabled', false).text('Test Connection');
        });
    });

    function showMsg(type, msg) {
        var $el = $('#rsseo-pro-license-msg');
        $el.removeClass('rsseo-notice--success rsseo-notice--error')
           .addClass('rsseo-notice rsseo-notice--' + type)
           .text(msg)
           .show();
        setTimeout(function() { $el.fadeOut(); }, 4000);
    }

})(jQuery);
