<?php
/**
 * Recommendations: grouped by type, prioritised, with done/dismiss actions.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$recs = GSB_Database::get_recommendations( 'open' );

// Group by type, preserving the priority ordering from the query.
$grouped = array();
foreach ( $recs as $rec ) {
	$grouped[ $rec->rec_type ][] = $rec;
}
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Fix Queue', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub">
		<?php esc_html_e( 'Prioritised GEO / AEO / SEO actions derived from your scores and content gaps.', 'geo-site-brain' ); ?>
		<button id="gsb-rebuild-recs" class="button button-secondary"><?php esc_html_e( 'Rebuild', 'geo-site-brain' ); ?></button>
	</p>

	<?php if ( empty( $recs ) ) : ?>
		<div class="notice notice-info inline"><p><?php printf( wp_kses_post( __( 'No open recommendations. <a href="%s">Run a scan</a>, then rebuild.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-scan' ) ) ); ?></p></div>
	<?php else : ?>
		<?php foreach ( $grouped as $type => $items ) : ?>
			<div class="gsb-panel">
				<h2><?php echo esc_html( GSB_View_Helpers::rec_type_label( $type ) ); ?> <span class="gsb-count"><?php echo count( $items ); ?></span></h2>
				<ul class="gsb-recs">
					<?php foreach ( $items as $rec ) : ?>
						<li class="gsb-rec" data-id="<?php echo (int) $rec->id; ?>">
							<span class="gsb-prio gsb-prio-<?php echo esc_attr( $rec->priority ); ?>"><?php echo esc_html( $rec->priority ); ?></span>
							<div class="gsb-rec-body">
								<div class="gsb-rec-title">
									<?php echo esc_html( $rec->title ); ?>
									<?php if ( $rec->post_id ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $rec->post_id ) ); ?>" class="gsb-muted">↗ <?php esc_html_e( 'edit', 'geo-site-brain' ); ?></a>
									<?php endif; ?>
								</div>
								<div class="gsb-rec-detail"><?php echo esc_html( $rec->detail ); ?></div>
							</div>
							<div class="gsb-rec-actions">
								<button class="button button-small gsb-rec-act" data-status="done"><?php esc_html_e( 'Done', 'geo-site-brain' ); ?></button>
								<button class="button button-small gsb-rec-act" data-status="dismissed"><?php esc_html_e( 'Dismiss', 'geo-site-brain' ); ?></button>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
