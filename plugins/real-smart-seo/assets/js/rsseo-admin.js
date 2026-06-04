/* Real Smart SEO Admin JS */
(function($) {
    'use strict';

    var data = window.rsseoData || {};
    var ajaxUrl = data.ajax_url || '';
    var nonce   = data.nonce   || '';
    var str     = data.strings || {};

    // Show analyzing message on scan form submit
    $('#rsseo-scan-form').on('submit', function() {
        $(this).find('button[type="submit"]').prop('disabled', true).text(str.analyzing || 'Analyzing…');
        $(this).find('.rsseo-analyzing-msg').show();
    });

    // Apply single fix
    $(document).on('click', '.rsseo-apply-fix', function() {
        var $btn   = $(this);
        var fixId  = $btn.data('fix-id');
        var $row   = $('#rsseo-fix-' + fixId);

        if (!confirm(str.confirm_fix || 'Apply this fix to your site?')) return;

        $btn.prop('disabled', true).text(str.applying || 'Applying…');

        $.post(ajaxUrl, {
            action: 'rsseo_apply_fix',
            nonce:  nonce,
            fix_id: fixId
        }, function(res) {
            if (res.success) {
                $btn.replaceWith('<span class="rsseo-status rsseo-status--complete">' + (str.applied || 'Fixed!') + '</span>');
                $row.addClass('rsseo-fix--applied');
                $row.find('.rsseo-status--pending').replaceWith('<span class="rsseo-status rsseo-status--complete">Applied</span>');
            } else {
                alert(res.data || str.error || 'Error. Try again.');
                $btn.prop('disabled', false).text('Fix');
            }
        }).fail(function() {
            alert(str.error || 'Error. Try again.');
            $btn.prop('disabled', false).text('Fix');
        });
    });

    // Apply all fixes
    $(document).on('click', '.rsseo-apply-all', function() {
        var $btn     = $(this);
        var reportId = $btn.data('report-id');

        if (!confirm(str.confirm_all || 'Apply ALL pending fixes? This will update your site content.')) return;

        $btn.prop('disabled', true).text(str.applying || 'Applying…');

        $.post(ajaxUrl, {
            action:    'rsseo_apply_all',
            nonce:     nonce,
            report_id: reportId
        }, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || str.error || 'Error. Try again.');
                $btn.prop('disabled', false).text('Apply All Fixes');
            }
        });
    });

    // Revert a single applied fix
    $(document).on('click', '.rsseo-restore-fix', function() {
        var $btn  = $(this);
        var fixId = $btn.data('fix-id');

        if (!confirm(str.confirm_revert || 'Revert this fix to the previous value?')) return;

        $btn.prop('disabled', true).text(str.reverting || 'Reverting…');

        $.post(ajaxUrl, {
            action: 'rsseo_restore_fix',
            nonce:  nonce,
            fix_id: fixId
        }, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || str.error || 'Error. Try again.');
                $btn.prop('disabled', false).text('Revert');
            }
        }).fail(function() {
            alert(str.error || 'Error. Try again.');
            $btn.prop('disabled', false).text('Revert');
        });
    });

    // Revert ALL applied fixes for a report
    $(document).on('click', '.rsseo-restore-all', function() {
        var $btn     = $(this);
        var reportId = $btn.data('report-id');

        if (!confirm(str.confirm_revert_all || 'Revert ALL applied fixes back to their previous values?')) return;

        $btn.prop('disabled', true).text(str.reverting || 'Reverting…');

        $.post(ajaxUrl, {
            action:    'rsseo_restore_all',
            nonce:     nonce,
            report_id: reportId
        }, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || str.error || 'Error. Try again.');
                $btn.prop('disabled', false).text('Revert All Applied');
            }
        });
    });

    // Test API connection
    $('#rsseo-test-api').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Testing…');

        $.post(ajaxUrl, {
            action: 'rsseo_test_api',
            nonce:  nonce
        }, function(res) {
            showMsg(res.success ? 'success' : 'error', res.data ? (res.data.message || res.data) : 'Error');
            $btn.prop('disabled', false).text('Test Connection');
        }).fail(function() {
            showMsg('error', 'Request failed. Check your connection.');
            $btn.prop('disabled', false).text('Test Connection');
        });
    });

    // Save settings
    $('#rsseo-settings-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Saving…');

        $.post(ajaxUrl, $.extend({ action: 'rsseo_save_settings', nonce: nonce }, $form.serializeObject()), function(res) {
            showMsg(res.success ? 'success' : 'error', res.data ? (res.data.message || res.data) : 'Error');
            $btn.prop('disabled', false).text('Save Settings');
        });
    });

    // $.fn.serializeObject helper — includes unchecked checkboxes as 0 so
    // boolean settings always send a value instead of being silently omitted
    // (the default serializeArray() behavior skips unchecked boxes entirely,
    // which means the server-side handler can't tell the difference between
    // "field not in this form" and "user unchecked this option").
    $.fn.serializeObject = function() {
        var obj = {};
        // Seed all checkboxes in the form as 0 first.
        $(this).find('input[type="checkbox"]').each(function() {
            obj[this.name] = '0';
        });
        // Let serializeArray() overwrite with '1' (or any other value) when checked.
        $.each(this.serializeArray(), function(_, item) {
            obj[item.name] = item.value;
        });
        return obj;
    };

    function showMsg(type, msg) {
        var $el = $('#rsseo-settings-msg');
        $el.removeClass('rsseo-notice--success rsseo-notice--error')
           .addClass('rsseo-notice--' + type)
           .text(msg)
           .show();
        setTimeout(function() { $el.fadeOut(); }, 4000);
    }

    // Inline rename of the current analysis after Analyze completes.
    $(document).on('click', '#rsseo-save-scan-name', function () {
        var $btn    = $(this);
        var $field  = $('#rsseo-scan-name');
        var $status = $('.rsseo-rename-status');
        var scanId  = parseInt($field.data('scan-id'), 10) || 0;
        var label   = $.trim($field.val());
        if (!scanId) return;
        $btn.prop('disabled', true);
        $status.text('Saving...');
        $.post(data.ajax_url, {
            action:  'rsseo_rename_scan',
            nonce:   data.nonce,
            scan_id: scanId,
            label:   label
        }).done(function (resp) {
            $status.text(resp && resp.success ? (resp.data && resp.data.message || '✓ Saved.') : 'Error.');
            setTimeout(function () { $status.text(''); }, 3000);
        }).fail(function () {
            $status.text('Error.');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

})(jQuery);
