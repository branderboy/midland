<?php
/**
 * Scan Website — manual full scan with a phase-aware progress bar.
 * Phases shown: scanning content → creating chunks → generating embeddings
 *               → building knowledge graph → generating visibility data
 *               → generating fix queue.
 * When OpenAI is not configured, scanning and scoring still run but the
 * embedding phase is skipped and a clear notice explains what is missing.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$progress = GSB_Indexer::get_instance()->progress();
$logs     = GSB_Logger::recent( 25 );
$has_ai   = GSB_Settings::has_openai();
$unembedded = (int) $progress['unembedded'];
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Scan Website', 'geo-site-brain' ); ?></h1>

	<?php if ( ! $has_ai ) : ?>
		<div class="notice notice-warning inline"><p>
			<strong><?php esc_html_e( 'OpenAI not configured', 'geo-site-brain' ); ?></strong> —
			<?php printf(
				wp_kses_post( __( 'Pages will be scanned and scored but <strong>embeddings will not be generated</strong>. Semantic search and the Site Chat agent require an OpenAI key. <a href="%s">Add your key in Settings</a>.', 'geo-site-brain' ) ),
				esc_url( admin_url( 'admin.php?page=gsb-settings' ) )
			); ?>
		</p></div>
	<?php elseif ( $unembedded > 0 ) : ?>
		<div class="notice notice-info inline"><p>
			<?php printf(
				esc_html( _n( '%d chunk is indexed but not yet embedded.', '%d chunks are indexed but not yet embedded.', $unembedded, 'geo-site-brain' ) ),
				$unembedded
			); ?>
			<?php esc_html_e( 'Click "Generate missing embeddings" to complete them without a full rescan.', 'geo-site-brain' ); ?>
		</p></div>
	<?php endif; ?>

	<div class="gsb-panel">
		<h2><?php esc_html_e( 'Full-site scan', 'geo-site-brain' ); ?></h2>
		<p><?php esc_html_e( 'Runs through every published page and post of the indexed types. Safe to run repeatedly — unchanged content is skipped.', 'geo-site-brain' ); ?></p>

		<p class="gsb-scan-phases gsb-muted" style="font-size:13px;margin-bottom:12px;">
			<?php esc_html_e( 'Phases: scan content → create chunks → generate embeddings → build knowledge graph → compute visibility → build fix queue', 'geo-site-brain' ); ?>
		</p>

		<p>
			<button id="gsb-run-scan" class="button button-primary"><?php esc_html_e( 'Start full scan', 'geo-site-brain' ); ?></button>
			<button id="gsb-embed-only" class="button"><?php esc_html_e( 'Generate missing embeddings', 'geo-site-brain' ); ?></button>
			<button id="gsb-rebuild-recs" class="button"><?php esc_html_e( 'Rebuild recommendations', 'geo-site-brain' ); ?></button>
		</p>

		<div id="gsb-progress" class="gsb-progress" style="display:none;">
			<div class="gsb-progress-label" id="gsb-progress-label"></div>
			<div class="gsb-bar"><div class="gsb-bar-fill" id="gsb-bar-fill"></div></div>
			<div id="gsb-progress-phase" class="gsb-muted" style="font-size:12px;margin-top:4px;"></div>
		</div>

		<table class="widefat striped" style="max-width:520px;margin-top:16px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Pages indexed', 'geo-site-brain' ); ?></td>
					<td id="gsb-stat-posts"><?php echo (int) $progress['posts']; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Total chunks', 'geo-site-brain' ); ?></td>
					<td id="gsb-stat-chunks"><?php echo (int) $progress['chunks']; ?></td>
				</tr>
				<tr>
					<td>
						<?php esc_html_e( 'Embedded', 'geo-site-brain' ); ?>
						<?php if ( ! $has_ai ) : ?>
							<span class="gsb-muted" style="font-size:11px;"><?php esc_html_e( '(requires OpenAI key)', 'geo-site-brain' ); ?></span>
						<?php endif; ?>
					</td>
					<td id="gsb-stat-embedded"><?php echo (int) $progress['embedded']; ?></td>
				</tr>
				<tr>
					<td>
						<?php esc_html_e( 'Not yet embedded', 'geo-site-brain' ); ?>
						<?php if ( ! $has_ai && $unembedded > 0 ) : ?>
							<span style="color:#d63638;font-size:11px;"><?php esc_html_e( '— add OpenAI key to generate', 'geo-site-brain' ); ?></span>
						<?php endif; ?>
					</td>
					<td id="gsb-stat-unembedded"><?php echo $unembedded; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Last full reindex', 'geo-site-brain' ); ?></td>
					<td><?php echo $progress['last_reindex'] ? esc_html( $progress['last_reindex'] ) : esc_html__( 'never', 'geo-site-brain' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="gsb-panel">
		<h2><?php esc_html_e( 'Recent activity', 'geo-site-brain' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Time', 'geo-site-brain' ); ?></th>
				<th><?php esc_html_e( 'Level', 'geo-site-brain' ); ?></th>
				<th><?php esc_html_e( 'Context', 'geo-site-brain' ); ?></th>
				<th><?php esc_html_e( 'Message', 'geo-site-brain' ); ?></th>
			</tr></thead>
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
