<?php
/**
 * Fix Queue — every gap as an action: problem, why it matters, impact,
 * difficulty, and a one-click fix where possible.
 * Statuses: open / in_progress / applied / manual / dismissed / failed.
 * Old 'done' rows display under applied for backward compatibility.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$open_recs    = GSB_Database::get_recommendations( 'open' );   // includes in_progress
$applied_recs = GSB_Database::get_recommendations( 'applied' ); // includes old 'done'
$failed_recs  = GSB_Database::get_recommendations( 'failed' );

$can_apply = array( 'create_service_page', 'create_location_page', 'generate_meta', 'generate_faq_schema', 'generate_localbusiness_schema' );
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Fix Queue', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub">
		<?php esc_html_e( 'Prioritised actions to make your business clearer to AI. Apply the easy ones in one click.', 'geo-site-brain' ); ?>
		<button id="gsb-rebuild-recs" class="button button-secondary"><?php esc_html_e( 'Rebuild', 'geo-site-brain' ); ?></button>
	</p>

	<?php if ( empty( $open_recs ) && empty( $failed_recs ) ) : ?>
		<div class="notice notice-info inline"><p><?php
			if ( ! empty( $applied_recs ) ) {
				printf(
					wp_kses_post( __( 'All fixes are applied. <a href="%s">Scan again</a> to check for new gaps.', 'geo-site-brain' ) ),
					esc_url( admin_url( 'admin.php?page=gsb-scan' ) )
				);
			} else {
				printf(
					wp_kses_post( __( 'Nothing queued. <a href="%s">Scan your website</a> to find gaps.', 'geo-site-brain' ) ),
					esc_url( admin_url( 'admin.php?page=gsb-scan' ) )
				);
			}
		?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $failed_recs ) ) : ?>
		<h2 style="color:#b32d2e;"><?php
			printf(
				esc_html( _n( '%d fix failed', '%d fixes failed', count( $failed_recs ), 'geo-site-brain' ) ),
				count( $failed_recs )
			);
		?></h2>
		<ul class="gsb-recs gsb-fixqueue gsb-fixqueue-failed" data-failed-list="1">
			<?php foreach ( $failed_recs as $rec ) : ?>
				<li class="gsb-rec gsb-failed" data-id="<?php echo (int) $rec->id; ?>">
					<div class="gsb-rec-badges">
						<span class="gsb-prio gsb-prio-<?php echo esc_attr( $rec->impact ); ?>"><?php echo esc_html( $rec->impact ); ?></span>
						<span class="gsb-status-badge gsb-status-failed"><?php esc_html_e( 'Failed', 'geo-site-brain' ); ?></span>
					</div>
					<div class="gsb-rec-body">
						<div class="gsb-rec-title"><?php echo esc_html( $rec->title ); ?></div>
						<?php if ( $rec->detail ) : ?><div class="gsb-rec-detail"><strong><?php esc_html_e( 'Problem:', 'geo-site-brain' ); ?></strong> <?php echo esc_html( $rec->detail ); ?></div><?php endif; ?>
						<div class="gsb-fix-result"></div>
					</div>
					<div class="gsb-rec-actions">
						<button class="button button-primary gsb-apply-fix"><?php esc_html_e( 'Retry Fix', 'geo-site-brain' ); ?></button>
						<button class="button button-small gsb-rec-act" data-status="dismissed"><?php esc_html_e( 'Dismiss', 'geo-site-brain' ); ?></button>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $open_recs ) ) : ?>
		<ul class="gsb-recs gsb-fixqueue">
			<?php foreach ( $open_recs as $rec ) :
				$applyable  = in_array( $rec->fix_action, $can_apply, true );
				$in_progress = ( 'in_progress' === $rec->status );
			?>
				<li class="gsb-rec<?php echo $in_progress ? ' gsb-in-progress' : ''; ?>" data-id="<?php echo (int) $rec->id; ?>">
					<div class="gsb-rec-badges">
						<span class="gsb-prio gsb-prio-<?php echo esc_attr( $rec->impact ); ?>" title="<?php esc_attr_e( 'Impact', 'geo-site-brain' ); ?>"><?php echo esc_html( $rec->impact ); ?></span>
						<span class="gsb-diff" title="<?php esc_attr_e( 'Difficulty', 'geo-site-brain' ); ?>"><?php echo esc_html( $rec->difficulty ); ?></span>
						<?php if ( $in_progress ) : ?>
							<span class="gsb-status-badge gsb-status-in-progress"><?php esc_html_e( 'In Progress', 'geo-site-brain' ); ?></span>
						<?php endif; ?>
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
						<?php if ( ! $in_progress ) : ?>
							<button class="button button-small gsb-rec-act" data-status="in_progress"><?php esc_html_e( 'In Progress', 'geo-site-brain' ); ?></button>
						<?php endif; ?>
						<button class="button button-small gsb-rec-act" data-status="manual"><?php esc_html_e( 'Mark Done', 'geo-site-brain' ); ?></button>
						<button class="button button-small gsb-rec-act" data-status="dismissed"><?php esc_html_e( 'Dismiss', 'geo-site-brain' ); ?></button>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $applied_recs ) ) : ?>
		<h2><?php
			printf(
				esc_html( _n( '%d fix applied', '%d fixes applied', count( $applied_recs ), 'geo-site-brain' ) ),
				count( $applied_recs )
			);
		?></h2>
		<ul class="gsb-recs gsb-applied-list">
			<?php foreach ( $applied_recs as $rec ) : ?>
				<li class="gsb-rec gsb-applied" data-id="<?php echo (int) $rec->id; ?>">
					<div class="gsb-rec-badges">
						<span class="gsb-status-badge gsb-status-applied">
							<?php echo ( 'done' === $rec->status ) ? esc_html__( 'Done', 'geo-site-brain' ) : esc_html__( 'Applied', 'geo-site-brain' ); ?>
						</span>
					</div>
					<div class="gsb-rec-body">
						<div class="gsb-rec-title"><?php echo esc_html( $rec->title ); ?></div>
						<?php if ( $rec->applied_at ) : ?>
							<div class="gsb-muted" style="font-size:12px;"><?php echo esc_html( mysql2date( 'M j, Y', $rec->applied_at ) ); ?></div>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
