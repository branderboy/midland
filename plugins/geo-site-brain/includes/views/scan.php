<?php
/**
 * Scan / Re-index: manual full scan + embedding with a live progress bar, plus
 * a recent log tail. Heavy lifting runs in batched AJAX (see assets/js).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$progress = GSB_Indexer::get_instance()->progress();
$logs     = GSB_Logger::recent( 25 );
$has_ai   = GSB_Settings::has_openai();
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Scan Site', 'geo-site-brain' ); ?></h1>

	<?php if ( ! $has_ai ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php printf(
				wp_kses_post( __( 'OpenAI is not configured, so pages will be scanned and scored but <strong>not embedded</strong>. Add a key on the <a href="%s">Settings</a> page to enable semantic search and the agent.', 'geo-site-brain' ) ),
				esc_url( admin_url( 'admin.php?page=gsb-settings' ) )
			); ?>
		</p></div>
	<?php endif; ?>

	<div class="gsb-panel">
		<h2><?php esc_html_e( 'Full-site scan', 'geo-site-brain' ); ?></h2>
		<p><?php esc_html_e( 'Scans every page/post of the indexed types, breaks them into chunks, scores them, and generates embeddings. Safe to run repeatedly — unchanged content is skipped.', 'geo-site-brain' ); ?></p>
		<p>
			<button id="gsb-run-scan" class="button button-primary"><?php esc_html_e( 'Start full scan', 'geo-site-brain' ); ?></button>
			<button id="gsb-embed-only" class="button"><?php esc_html_e( 'Generate missing embeddings', 'geo-site-brain' ); ?></button>
			<button id="gsb-rebuild-recs" class="button"><?php esc_html_e( 'Rebuild recommendations', 'geo-site-brain' ); ?></button>
		</p>

		<div id="gsb-progress" class="gsb-progress" style="display:none;">
			<div class="gsb-progress-label" id="gsb-progress-label"></div>
			<div class="gsb-bar"><div class="gsb-bar-fill" id="gsb-bar-fill"></div></div>
		</div>

		<table class="widefat striped" style="max-width:520px;margin-top:16px;">
			<tbody>
				<tr><td><?php esc_html_e( 'Pages indexed', 'geo-site-brain' ); ?></td><td id="gsb-stat-posts"><?php echo (int) $progress['posts']; ?></td></tr>
				<tr><td><?php esc_html_e( 'Total chunks', 'geo-site-brain' ); ?></td><td id="gsb-stat-chunks"><?php echo (int) $progress['chunks']; ?></td></tr>
				<tr><td><?php esc_html_e( 'Embedded', 'geo-site-brain' ); ?></td><td id="gsb-stat-embedded"><?php echo (int) $progress['embedded']; ?></td></tr>
				<tr><td><?php esc_html_e( 'Awaiting embedding', 'geo-site-brain' ); ?></td><td id="gsb-stat-unembedded"><?php echo (int) $progress['unembedded']; ?></td></tr>
				<tr><td><?php esc_html_e( 'Last full reindex', 'geo-site-brain' ); ?></td><td><?php echo $progress['last_reindex'] ? esc_html( $progress['last_reindex'] ) : esc_html__( 'never', 'geo-site-brain' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="gsb-panel">
		<h2><?php esc_html_e( 'Recent activity', 'geo-site-brain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Time', 'geo-site-brain' ); ?></th><th><?php esc_html_e( 'Level', 'geo-site-brain' ); ?></th><th><?php esc_html_e( 'Context', 'geo-site-brain' ); ?></th><th><?php esc_html_e( 'Message', 'geo-site-brain' ); ?></th></tr></thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No activity yet.', 'geo-site-brain' ); ?></td></tr>
				<?php else : foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log->created_at ); ?></td>
						<td><span class="gsb-level gsb-level-<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( $log->level ); ?></span></td>
						<td><?php echo esc_html( $log->context ); ?></td>
						<td><?php echo esc_html( $log->message ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
</div>
