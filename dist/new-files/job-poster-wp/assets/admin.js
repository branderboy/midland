jQuery(function ($) {

    // ── Post to Facebook ──────────────────────────────────────────────────────
    $('#dpjp-post-fb').on('click', function () {
        const btn = $(this);
        const result = $('#dpjp-fb-result');
        btn.prop('disabled', true).text('Posting…');
        result.html('');

        $.post(dpjp.ajax, {
            action:  'dpjp_post_facebook',
            nonce:   dpjp.nonce,
            post_id: dpjp.post_id,
        })
        .done(function (res) {
            if (res.success) {
                result.html('<div style="background:#d1e7dd;color:#0a3622;padding:6px 10px;border-radius:4px;">✓ ' + res.data.message + '</div>');
                btn.text('Post to Facebook Page');
            } else {
                result.text('✗ ' + (res.data || 'Unknown error'));
                result.attr('style', 'color:#58151c;font-size:12px;padding:4px 0;');
                btn.prop('disabled', false).text('Post to Facebook Page');
            }
        })
        .fail(function () {
            result.text('✗ Request failed. Try again.');
            result.attr('style', 'color:#58151c;font-size:12px;padding:4px 0;');
            btn.prop('disabled', false).text('Post to Facebook Page');
        });
    });

    // ── Post to Indeed ────────────────────────────────────────────────────────
    $('#dpjp-post-indeed').on('click', function () {
        const btn = $(this);
        const result = $('#dpjp-in-result');
        btn.prop('disabled', true).text('Posting…');
        result.html('');

        $.post(dpjp.ajax, {
            action:  'dpjp_post_indeed',
            nonce:   dpjp.nonce,
            post_id: dpjp.post_id,
        })
        .done(function (res) {
            if (res.success) {
                result.html('<div style="background:#d1e7dd;color:#0a3622;padding:6px 10px;border-radius:4px;">✓ ' + res.data.message + '</div>');
                btn.text('Post to Indeed');
            } else {
                result.text('✗ ' + (res.data || 'Unknown error'));
                result.attr('style', 'color:#58151c;font-size:12px;padding:4px 0;');
                btn.prop('disabled', false).text('Post to Indeed');
            }
        })
        .fail(function () {
            result.text('✗ Request failed. Try again.');
            result.attr('style', 'color:#58151c;font-size:12px;padding:4px 0;');
            btn.prop('disabled', false).text('Post to Indeed');
        });
    });

    // ── Copy to clipboard ─────────────────────────────────────────────────────
    $('[data-copy]').on('click', function () {
        const btn     = $(this);
        const targetId = btn.data('copy');
        const text    = $('#' + targetId).val();
        navigator.clipboard.writeText(text).then(function () {
            const orig = btn.text();
            btn.text('✓ Copied!');
            setTimeout(function () { btn.text(orig); }, 2000);
        });
    });

});
