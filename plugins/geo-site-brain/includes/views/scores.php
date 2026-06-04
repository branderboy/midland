<?php
/**
 * Scores: per-page GEO score with the ten sub-scores and a drill-down.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows    = GSB_Database::get_scores( 'score', 'ASC', 300 );
$labels  = GSB_Scorer::labels();
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'GEO Scores', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub"><?php esc_html_e( 'Each page is scored 1–100 from ten weighted dimensions. Click a row to see the breakdown.', 'geo-site-brain' ); ?></p>

	<?php if ( empty( $rows ) ) : ?>
		<div class="notice notice-info inline"><p><?php printf( wp_kses_post( __( 'No scores yet. <a href="%s">Run a scan</a> to score your pages.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-scan' ) ) ); ?></p></div>
	<?php else : ?>
		<table class="widefat striped gsb-scores">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Page', 'geo-site-brain' ); ?></th>
					<th><?php esc_html_e( 'Type', 'geo-site-brain' ); ?></th>
					<th><?php esc_html_e( 'GEO score', 'geo-site-brain' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) :
				$title = get_the_title( $row->post_id ) ?: $row->url;
				$sub   = json_decode( (string) $row->subscores, true ) ?: array();
				$det   = json_decode( (string) $row->details, true ) ?: array();
				?>
				<tr class="gsb-score-row" data-row="<?php echo (int) $row->post_id; ?>">
					<td><strong><?php echo esc_html( $title ); ?></strong><br><a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener" class="gsb-muted"><?php echo esc_html( $row->url ); ?></a></td>
					<td><?php echo esc_html( get_post_type( $row->post_id ) ); ?></td>
					<td><span class="gsb-pill gsb-pill-<?php echo esc_attr( GSB_View_Helpers::band( (int) $row->score ) ); ?>"><?php echo (int) $row->score; ?></span></td>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'geo-site-brain' ); ?></a>
						<button class="button button-small gsb-reindex-one" data-post="<?php echo (int) $row->post_id; ?>"><?php esc_html_e( 'Reindex', 'geo-site-brain' ); ?></button>
						<button class="button button-small gsb-toggle-detail"><?php esc_html_e( 'Breakdown', 'geo-site-brain' ); ?></button>
					</td>
				</tr>
				<tr class="gsb-detail-row" id="gsb-detail-<?php echo (int) $row->post_id; ?>" style="display:none;">
					<td colspan="4">
						<div class="gsb-subscores">
							<?php foreach ( $labels as $key => $label ) :
								$val   = isset( $sub[ $key ] ) ? (int) $sub[ $key ] : 0;
								$notes = isset( $det[ $key ] ) ? (array) $det[ $key ] : array(); ?>
								<div class="gsb-subscore">
									<div class="gsb-subscore-head">
										<span><?php echo esc_html( $label ); ?></span>
										<span class="gsb-pill gsb-pill-<?php echo esc_attr( GSB_View_Helpers::band( $val ) ); ?>"><?php echo (int) $val; ?></span>
									</div>
									<div class="gsb-bar small"><div class="gsb-bar-fill" style="width:<?php echo (int) $val; ?>%"></div></div>
									<?php if ( $notes ) : ?>
										<div class="gsb-muted gsb-notes"><?php echo esc_html( implode( ' · ', array_map( 'strval', $notes ) ) ); ?></div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
