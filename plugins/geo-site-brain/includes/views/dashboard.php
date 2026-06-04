<?php
/**
 * Dashboard: site GEO score, index health, weakest pages.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site_score = GSB_Database::site_score();
$progress   = GSB_Indexer::get_instance()->progress();
$weak       = GSB_Database::get_scores( 'score', 'ASC', 10 );
$open_recs  = count( GSB_Database::get_recommendations( 'open' ) );

$has_openai = GSB_Settings::has_openai();
$neon_on    = GSB_Settings::neon_active();
$pgsql      = GSB_Vector_Store::pgsql_available();
$backend    = $neon_on && $pgsql ? __( 'Neon (pgvector)', 'geo-site-brain' ) : __( 'Local MySQL (fallback)', 'geo-site-brain' );
?>
<div class="wrap gsb-wrap">
	<h1><span class="dashicons dashicons-superhero"></span> <?php esc_html_e( 'GEO Site Brain', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub"><?php esc_html_e( 'An AI-readable knowledge base of your site, scored for GEO / AEO / SEO.', 'geo-site-brain' ); ?></p>

	<div class="gsb-cards">
		<div class="gsb-card gsb-score-card">
			<div class="gsb-score-num"><?php echo null === $site_score ? '—' : (int) $site_score; ?></div>
			<div class="gsb-card-label"><?php esc_html_e( 'Average site GEO score', 'geo-site-brain' ); ?></div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><?php echo (int) $progress['posts']; ?></div>
			<div class="gsb-card-label"><?php esc_html_e( 'Pages indexed', 'geo-site-brain' ); ?></div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><?php echo (int) $progress['embedded']; ?> / <?php echo (int) $progress['chunks']; ?></div>
			<div class="gsb-card-label"><?php esc_html_e( 'Chunks embedded', 'geo-site-brain' ); ?></div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><?php echo (int) $open_recs; ?></div>
			<div class="gsb-card-label"><a href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-recommendations' ) ); ?>"><?php esc_html_e( 'Open recommendations', 'geo-site-brain' ); ?></a></div>
		</div>
	</div>

	<div class="gsb-grid">
		<div class="gsb-panel">
			<h2><?php esc_html_e( 'System health', 'geo-site-brain' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'OpenAI embeddings', 'geo-site-brain' ); ?></strong></td>
						<td><?php echo $has_openai
							? '<span class="gsb-ok">' . esc_html__( 'Configured', 'geo-site-brain' ) . '</span>'
							: '<span class="gsb-bad">' . esc_html__( 'Not configured', 'geo-site-brain' ) . '</span>'; ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Vector backend', 'geo-site-brain' ); ?></strong></td>
						<td><?php echo esc_html( $backend ); ?>
							<?php if ( $neon_on && ! $pgsql ) : ?>
								<br><span class="gsb-bad"><?php esc_html_e( 'Neon is enabled but the PDO pgsql driver is missing — using local fallback.', 'geo-site-brain' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Last full reindex', 'geo-site-brain' ); ?></strong></td>
						<td><?php echo $progress['last_reindex'] ? esc_html( $progress['last_reindex'] ) : esc_html__( 'never', 'geo-site-brain' ); ?></td>
					</tr>
				</tbody>
			</table>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-scan' ) ); ?>"><?php esc_html_e( 'Scan / Re-index', 'geo-site-brain' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-chat' ) ); ?>"><?php esc_html_e( 'Ask the agent', 'geo-site-brain' ); ?></a>
			</p>
		</div>

		<div class="gsb-panel">
			<h2><?php esc_html_e( 'Weakest pages for GEO', 'geo-site-brain' ); ?></h2>
			<?php if ( empty( $weak ) ) : ?>
				<p><?php esc_html_e( 'No scores yet. Run a scan to get started.', 'geo-site-brain' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Page', 'geo-site-brain' ); ?></th><th><?php esc_html_e( 'Score', 'geo-site-brain' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $weak as $row ) :
						$title = get_the_title( $row->post_id ) ?: $row->url; ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>"><?php echo esc_html( $title ); ?></a></td>
							<td><span class="gsb-pill gsb-pill-<?php echo esc_attr( GSB_View_Helpers::band( (int) $row->score ) ); ?>"><?php echo (int) $row->score; ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
