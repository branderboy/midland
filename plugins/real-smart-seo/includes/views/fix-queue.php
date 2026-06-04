<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Fix Queue (partial). Prioritized action cards instead of a raw table.
 * Vars: $report, $fixes. Reuses the existing apply/rollback AJAX hooks
 * (#rsseo-fix-{id}, .rsseo-apply-fix/.rsseo-restore-fix[data-fix-id],
 * .rsseo-apply-all/.rsseo-restore-all[data-report-id], .rsseo-status--pending)
 * so the working rsseo-admin.js handlers drive the cards unchanged.
 *
 * Per fix_type metadata: why it matters + risk level.
 */
$meta = array(
    'title' => array(
        'label' => __( 'Title tag', 'real-smart-seo' ),
        'why'   => __( 'The title tag is the strongest on-page ranking signal and the headline searchers click in Google.', 'real-smart-seo' ),
        'risk'  => 'low',
    ),
    'meta_description' => array(
        'label' => __( 'Meta description', 'real-smart-seo' ),
        'why'   => __( 'A sharper meta description lifts click-through from the results page even when the ranking position does not change.', 'real-smart-seo' ),
        'risk'  => 'low',
    ),
    'alt_text' => array(
        'label' => __( 'Image alt text', 'real-smart-seo' ),
        'why'   => __( 'Descriptive alt text helps image search and accessibility, and adds keyword context to the page.', 'real-smart-seo' ),
        'risk'  => 'low',
    ),
    'content' => array(
        'label' => __( 'Content', 'real-smart-seo' ),
        'why'   => __( 'Stronger, more relevant copy helps the page rank for more terms and convert more visitors.', 'real-smart-seo' ),
        'risk'  => 'medium',
    ),
);
$risk_meta = array(
    'low'    => array( 'label' => __( 'Low risk', 'real-smart-seo' ),    'color' => '#0a8754' ),
    'medium' => array( 'label' => __( 'Medium risk', 'real-smart-seo' ), 'color' => '#dba617' ),
    'high'   => array( 'label' => __( 'High risk', 'real-smart-seo' ),   'color' => '#d63638' ),
);
$pending = max( 0, (int) $report->fixes_available - (int) $report->fixes_applied );
?>
<div class="rsseo-tabview rsseo-fixqueue">
    <h2><?php esc_html_e( 'Fix Queue', 'real-smart-seo' ); ?>
        <span class="rsseo-report-label" style="font-weight:400;color:#666;">— <?php echo esc_html( $report->label ); ?></span>
    </h2>
    <p class="description">
        <?php
        /* translators: 1: applied count, 2: total */
        printf( esc_html__( '%1$d of %2$d fixes applied. Every applied fix is backed up first — revert any card (or all) to restore the previous value.', 'real-smart-seo' ), (int) $report->fixes_applied, (int) $report->fixes_available );
        ?>
    </p>

    <p class="rsseo-fixqueue__bulk">
        <?php if ( $pending > 0 ) : ?>
            <button class="button button-primary rsseo-apply-all" data-report-id="<?php echo esc_attr( $report->id ); ?>"><?php esc_html_e( 'Apply All Fixes', 'real-smart-seo' ); ?></button>
        <?php endif; ?>
        <?php if ( (int) $report->fixes_applied > 0 ) : ?>
            <button class="button rsseo-restore-all" data-report-id="<?php echo esc_attr( $report->id ); ?>"><?php esc_html_e( 'Revert All Applied', 'real-smart-seo' ); ?></button>
        <?php endif; ?>
    </p>

    <?php if ( empty( $fixes ) ) : ?>
        <p><em><?php esc_html_e( 'No fixes in this analysis.', 'real-smart-seo' ); ?></em></p>
    <?php else : ?>
        <div class="rsseo-fixqueue__cards" style="display:grid;gap:14px;margin-top:8px;">
            <?php foreach ( $fixes as $fix ) :
                $type   = (string) $fix->fix_type;
                $m      = $meta[ $type ] ?? array( 'label' => ucfirst( str_replace( '_', ' ', $type ) ), 'why' => '', 'risk' => 'low' );
                $rk     = $risk_meta[ $m['risk'] ] ?? $risk_meta['low'];
                $applied = ! empty( $fix->applied );
                $status  = $applied ? RSSEO_Status::APPLIED : RSSEO_Status::RECOMMENDED;
                $title   = $fix->post_id ? get_the_title( (int) $fix->post_id ) : '';
                $edit    = $fix->post_id ? get_edit_post_link( (int) $fix->post_id, '' ) : '';
            ?>
            <div class="rsseo-fixcard <?php echo $applied ? 'rsseo-fix--applied' : ''; ?>" id="rsseo-fix-<?php echo esc_attr( $fix->id ); ?>"
                 style="background:#fff;border:1px solid #e2e4e7;border-left:4px solid <?php echo esc_attr( $rk['color'] ); ?>;border-radius:8px;padding:16px 18px;box-shadow:0 1px 2px rgba(0,0,0,.05);">

                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                    <div>
                        <strong style="font-size:14px;"><?php echo esc_html( $m['label'] ); ?><?php if ( $title ) : ?> · <span style="font-weight:600;color:#1d2327;"><?php echo esc_html( $title ); ?></span><?php endif; ?></strong>
                        <div style="margin-top:4px;">
                            <?php if ( $applied ) : ?>
                                <span class="rsseo-status rsseo-status--complete"><?php esc_html_e( 'Applied', 'real-smart-seo' ); ?></span>
                            <?php else : ?>
                                <span class="rsseo-status rsseo-status--pending"><?php esc_html_e( 'Recommended', 'real-smart-seo' ); ?></span>
                            <?php endif; ?>
                            <span style="display:inline-block;margin-left:6px;font-size:11px;font-weight:700;color:<?php echo esc_attr( $rk['color'] ); ?>;"><?php echo esc_html( $rk['label'] ); ?></span>
                        </div>
                    </div>
                    <div class="rsseo-fixcard__actions" style="flex:0 0 auto;">
                        <?php if ( ! $applied ) : ?>
                            <button class="button button-primary button-small rsseo-apply-fix" data-fix-id="<?php echo esc_attr( $fix->id ); ?>"><?php esc_html_e( 'Apply fix', 'real-smart-seo' ); ?></button>
                        <?php else : ?>
                            <button class="button button-small rsseo-restore-fix" data-fix-id="<?php echo esc_attr( $fix->id ); ?>"><?php esc_html_e( 'Revert', 'real-smart-seo' ); ?></button>
                        <?php endif; ?>
                        <?php if ( $edit ) : ?>
                            <a class="button button-small" href="<?php echo esc_url( $edit ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit page', 'real-smart-seo' ); ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $m['why'] ) : ?>
                    <p style="margin:10px 0 8px;color:#50575e;"><strong><?php esc_html_e( 'Why it matters:', 'real-smart-seo' ); ?></strong> <?php echo esc_html( $m['why'] ); ?></p>
                <?php endif; ?>

                <!-- Before / after preview -->
                <div class="rsseo-fixcard__diff" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;">
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#888;margin-bottom:3px;"><?php esc_html_e( 'Before', 'real-smart-seo' ); ?></div>
                        <div style="background:#fff5f5;border:1px solid #f0c6c6;border-radius:4px;padding:8px;font-size:13px;white-space:pre-wrap;max-height:160px;overflow:auto;"><?php echo '' !== trim( (string) $fix->old_value ) ? esc_html( $fix->old_value ) : '<em style="color:#999;">' . esc_html__( '(empty)', 'real-smart-seo' ) . '</em>'; ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#888;margin-bottom:3px;"><?php esc_html_e( 'After (suggested)', 'real-smart-seo' ); ?></div>
                        <div style="background:#f3fcf4;border:1px solid #cdeccf;border-radius:4px;padding:8px;font-size:13px;white-space:pre-wrap;max-height:160px;overflow:auto;"><?php echo esc_html( (string) $fix->new_value ); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    /** Pro add-ons (schema, backlink opportunities) render below the cards. */
    do_action( 'rsseo_after_report_fixes', $report );
    ?>
</div>
