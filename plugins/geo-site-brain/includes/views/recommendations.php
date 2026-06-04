<?php
/**
 * Fix Queue — every gap as an action: problem, why it matters, impact,
 * difficulty, and a one-click fix where possible.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$recs = GSB_Database::get_recommendations( 'open' );

$can_apply = array( 'create_service_page', 'create_location_page', 'generate_meta', 'generate_faq_schema', 'generate_localbusiness_schema' );
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Fix Queue', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub">
		<?php esc_html_e( 'Prioritised actions to make your business clearer to AI. Apply the easy ones in one click.', 'geo-site-brain' ); ?>
		<button id="gsb-rebuild-recs" class="button button-secondary"><?php esc_html_e( 'Rebuild', 'geo-site-brain' ); ?></button>
	</p>

	<?php if ( empty( $recs ) ) : ?>
		<div class="notice notice-info inline"><p><?php printf( wp_kses_post( __( 'Nothing queued. <a href="%s">Scan your website</a> to find gaps.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-scan' ) ) ); ?></p></div>
	<?php else : ?>
		<ul class="gsb-recs gsb-fixqueue">
			<?php foreach ( $recs as $rec ) :
				$applyable = in_array( $rec->fix_action, $can_apply, true ); ?>
				<li class="gsb-rec" data-id="<?php echo (int) $rec->id; ?>">
					<div class="gsb-rec-badges">
						<span class="gsb-prio gsb-prio-<?php echo esc_attr( $rec->impact ); ?>" title="<?php esc_attr_e( 'Impact', 'geo-site-brain' ); ?>"><?php echo esc_html( $rec->impact ); ?></span>
						<span class="gsb-diff" title="<?php esc_attr_e( 'Difficulty', 'geo-site-brain' ); ?>"><?php echo esc_html( $rec->difficulty ); ?></span>
					</div>
					<div class="gsb-rec-body">
						<div class="gsb-rec-title">
							<?php echo esc_html( $rec->title ); ?>
							<?php if ( $rec->post_id ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $rec->post_id ) ); ?>" class="gsb-muted">↗ <?php esc_html_e( 'edit', 'geo-site-brain' ); ?></a>
							<?php endif; ?>
						</div>
						<?php if ( $rec->detail ) : ?><div class="gsb-rec-detail"><strong><?php esc_html_e( 'Problem:', 'geo-site-brain' ); ?></strong> <?php echo esc_html( $rec->detail ); ?></div><?php endif; ?>
						<?php if ( $rec->reason ) : ?><div class="gsb-rec-detail gsb-muted"><strong><?php esc_html_e( 'Why it matters:', 'geo-site-brain' ); ?></strong> <?php echo esc_html( $rec->reason ); ?></div><?php endif; ?>
						<div class="gsb-fix-result"></div>
					</div>
					<div class="gsb-rec-actions">
						<?php if ( $applyable ) : ?>
							<button class="button button-primary gsb-apply-fix"><?php esc_html_e( 'Apply Fix', 'geo-site-brain' ); ?></button>
						<?php endif; ?>
						<button class="button button-small gsb-rec-act" data-status="done"><?php esc_html_e( 'Done', 'geo-site-brain' ); ?></button>
						<button class="button button-small gsb-rec-act" data-status="dismissed"><?php esc_html_e( 'Dismiss', 'geo-site-brain' ); ?></button>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
