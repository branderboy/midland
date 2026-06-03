<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap rsseo-wrap">
    <h1><?php esc_html_e( 'Site Audit', 'real-smart-seo' ); ?></h1>

    <p><?php esc_html_e( 'Scans every published post and page directly inside WordPress — no uploads needed. Finds missing titles, thin content, broken alt text, duplicate meta, orphaned pages, and more.', 'real-smart-seo' ); ?></p>

    <div id="rsseo-audit-msg" class="rsseo-notice" style="display:none;"></div>

    <p>
        <button type="button" id="rsseo-run-audit" class="button button-primary button-hero">
            <?php esc_html_e( 'Run Site Audit', 'real-smart-seo' ); ?>
        </button>
        <span id="rsseo-audit-spinner" class="spinner" style="float:none;vertical-align:middle;display:none;"></span>
    </p>

    <?php if ( $audit ) : ?>
    <div class="rsseo-audit-meta">
        <?php
        $total = (int) $audit->issues_critical + (int) $audit->issues_high + (int) $audit->issues_medium + (int) $audit->issues_low;
        printf(
            /* translators: 1: posts checked, 2: total issues, 3: date */
            esc_html__( 'Last audit: %1$d posts checked · %2$d issues · %3$s', 'real-smart-seo' ),
            (int) $audit->posts_checked,
            (int) $total,
            esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $audit->created_at ) ) )
        );
        ?>
    </div>
    <?php endif; ?>

    <div id="rsseo-audit-results">
        <?php if ( ! empty( $issues ) ) : ?>
        <?php echo wp_kses_post( self_render_audit_issues( $issues ) ); // phpcs:ignore -- function defined below ?>
        <?php elseif ( $audit ) : ?>
        <p><?php esc_html_e( 'No issues found — your site is looking great!', 'real-smart-seo' ); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php
/**
 * Renders the issues table. Used both on page load and injected by AJAX.
 *
 * @param array $issues  Array of issue objects from DB.
 * @return string        HTML string.
 */
function self_render_audit_issues( $issues ) { // phpcs:ignore NamingConventions
    if ( empty( $issues ) ) {
        return '';
    }

    $severity_labels = array(
        'critical' => array( 'label' => __( 'Critical', 'real-smart-seo' ), 'class' => 'rsseo-sev--critical' ),
        'high'     => array( 'label' => __( 'High',     'real-smart-seo' ), 'class' => 'rsseo-sev--high' ),
        'medium'   => array( 'label' => __( 'Medium',   'real-smart-seo' ), 'class' => 'rsseo-sev--medium' ),
        'low'      => array( 'label' => __( 'Low',      'real-smart-seo' ), 'class' => 'rsseo-sev--low' ),
    );

    // Group by severity.
    $grouped = array( 'critical' => array(), 'high' => array(), 'medium' => array(), 'low' => array() );
    foreach ( $issues as $issue ) {
        $sev = isset( $grouped[ $issue->severity ] ) ? $issue->severity : 'low';
        $grouped[ $sev ][] = $issue;
    }

    $html  = '';
    foreach ( $grouped as $sev => $sev_issues ) {
        if ( empty( $sev_issues ) ) {
            continue;
        }
        $info  = $severity_labels[ $sev ];
        $html .= '<h3 class="rsseo-sev-heading ' . esc_attr( $info['class'] ) . '">';
        $html .= esc_html( $info['label'] ) . ' <span class="rsseo-sev-count">(' . count( $sev_issues ) . ')</span>';
        $html .= '</h3>';

        $html .= '<table class="widefat rsseo-audit-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__( 'Post', 'real-smart-seo' ) . '</th>';
        $html .= '<th>' . esc_html__( 'Issue', 'real-smart-seo' ) . '</th>';
        $html .= '<th>' . esc_html__( 'Description', 'real-smart-seo' ) . '</th>';
        $html .= '<th>' . esc_html__( 'Suggestion', 'real-smart-seo' ) . '</th>';
        $html .= '<th>' . esc_html__( 'Action', 'real-smart-seo' ) . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ( $sev_issues as $issue ) {
            $post_link = '';
            if ( $issue->post_id ) {
                $post_title = get_the_title( $issue->post_id );
                $edit_link  = get_edit_post_link( $issue->post_id );
                $post_link  = '<a href="' . esc_url( $edit_link ) . '" target="_blank">' . esc_html( $post_title ?: '#' . $issue->post_id ) . '</a>';
            } else {
                $post_link = esc_html__( 'Site-wide', 'real-smart-seo' );
            }

            $row_class = $issue->fixed ? ' rsseo-issue--fixed' : '';
            $html .= '<tr id="rsseo-issue-' . (int) $issue->id . '" class="rsseo-issue-row' . esc_attr( $row_class ) . '">';
            $html .= '<td>' . $post_link . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- post_link is already escaped above
            $html .= '<td><code>' . esc_html( $issue->issue_type ) . '</code></td>';
            $html .= '<td>' . esc_html( $issue->description ) . '</td>';
            $html .= '<td>' . esc_html( $issue->suggestion ) . '</td>';
            $html .= '<td>';

            if ( $issue->fixed ) {
                $html .= '<span class="rsseo-status rsseo-status--complete">' . esc_html__( 'Fixed', 'real-smart-seo' ) . '</span>';
            } elseif ( $issue->auto_fixable ) {
                $html .= '<button type="button" class="button rsseo-apply-audit-fix" data-issue-id="' . (int) $issue->id . '">'
                       . esc_html__( 'Auto Fix', 'real-smart-seo' )
                       . '</button>';
            } else {
                $html .= '<span class="rsseo-status rsseo-status--manual">' . esc_html__( 'Manual', 'real-smart-seo' ) . '</span>';
            }

            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
    }

    return $html;
}
?>

<script>
(function($) {
    'use strict';
    var ajaxUrl = rsseoData.ajax_url;
    var nonce   = rsseoData.nonce;
    var str     = rsseoData.strings || {};

    // Run audit
    $('#rsseo-run-audit').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text(str.auditing || 'Running audit...');
        $('#rsseo-audit-spinner').show();
        $('#rsseo-audit-msg').hide();

        $.post(ajaxUrl, {
            action: 'rsseo_run_audit',
            nonce:  nonce
        }, function(res) {
            $('#rsseo-audit-spinner').hide();
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Run Site Audit', 'real-smart-seo' ) ); ?>');

            if (res.success) {
                $('#rsseo-audit-msg')
                    .removeClass('rsseo-notice--error').addClass('rsseo-notice rsseo-notice--success')
                    .text(res.data.message).show();
                // Reload to show fresh results with server-rendered HTML
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                $('#rsseo-audit-msg')
                    .removeClass('rsseo-notice--success').addClass('rsseo-notice rsseo-notice--error')
                    .text(res.data || str.error || 'Error.').show();
            }
        });
    });

    // Auto fix
    $(document).on('click', '.rsseo-apply-audit-fix', function() {
        var $btn     = $(this);
        var issueId  = $btn.data('issue-id');

        if (!confirm(str.confirm_fix || 'Apply this fix to your site?')) return;

        $btn.prop('disabled', true).text(str.applying || 'Applying...');

        $.post(ajaxUrl, {
            action:   'rsseo_apply_audit_fix',
            nonce:    nonce,
            issue_id: issueId
        }, function(res) {
            if (res.success) {
                $btn.replaceWith('<span class="rsseo-status rsseo-status--complete"><?php echo esc_js( __( 'Fixed', 'real-smart-seo' ) ); ?></span>');
                $('#rsseo-issue-' + issueId).addClass('rsseo-issue--fixed');
            } else {
                alert(res.data || str.error || 'Error.');
                $btn.prop('disabled', false).text('Auto Fix');
            }
        });
    });
})(jQuery);
</script>
