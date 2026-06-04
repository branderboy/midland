<?php
/**
 * Site Brain (landing): leads with the outcome — how complete the site's
 * AI-readable knowledge is, what AI understands, what it can't yet, and what to
 * fix first. Technical detail (chunks, embeddings, Neon) stays in Setup.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_openai = GSB_Settings::has_openai();
$progress   = GSB_Indexer::get_instance()->progress();
$all_ids    = GSB_Scanner::all_post_ids();
$total      = count( $all_ids );
$scores     = GSB_Database::get_scores( 'score', 'DESC', 500 );
$open_recs  = GSB_Database::get_recommendations( 'open' );

// Buckets in plain terms.
$scanned    = count( $scores );
$understood = 0; $needs_work = array(); $strong = array();
foreach ( $scores as $row ) {
	if ( (int) $row->score >= 70 ) {
		$understood++;
		if ( (int) $row->score >= 80 ) { $strong[] = $row; }
	} else {
		$needs_work[] = $row;
	}
}
$unscanned    = max( 0, $total - $scanned );
$completeness = $total ? (int) round( ( $understood / $total ) * 100 ) : 0;

if ( $completeness >= 70 )      { $state = __( 'strong', 'geo-site-brain' ); $band = 'good'; }
elseif ( $completeness >= 40 )  { $state = __( 'taking shape', 'geo-site-brain' ); $band = 'ok'; }
else                            { $state = __( 'incomplete', 'geo-site-brain' ); $band = 'bad'; }

$first_run = ( ! $has_openai || 0 === $scanned );
?>
<div class="wrap gsb-wrap">
	<h1><span class="dashicons dashicons-superhero"></span> <?php esc_html_e( 'Site Brain', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub"><?php esc_html_e( 'How well AI and search engines can understand this website — and what to fix first.', 'geo-site-brain' ); ?></p>

	<?php if ( $first_run ) : ?>
		<div class="gsb-panel gsb-getstarted">
			<h2><?php esc_html_e( 'Start here', 'geo-site-brain' ); ?></h2>
			<ol class="gsb-steps">
				<li class="<?php echo $has_openai ? 'done' : ''; ?>">
					<strong><?php esc_html_e( '1. Connect AI', 'geo-site-brain' ); ?></strong>
					<?php esc_html_e( 'Add your OpenAI key so the site can be turned into AI-readable knowledge.', 'geo-site-brain' ); ?>
					<?php if ( ! $has_openai ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-settings' ) ); ?>"><?php esc_html_e( 'Open Setup', 'geo-site-brain' ); ?></a>
					<?php else : ?>
						<span class="gsb-ok">✓ <?php esc_html_e( 'Connected', 'geo-site-brain' ); ?></span>
					<?php endif; ?>
				</li>
				<li class="<?php echo $scanned ? 'done' : ''; ?>">
					<strong><?php esc_html_e( '2. Scan the site', 'geo-site-brain' ); ?></strong>
					<?php esc_html_e( 'Read every page, score it, and build the knowledge base.', 'geo-site-brain' ); ?>
					<a class="button <?php echo $has_openai ? 'button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-scan' ) ); ?>"><?php esc_html_e( 'Go to Scan', 'geo-site-brain' ); ?></a>
				</li>
				<li>
					<strong><?php esc_html_e( '3. Review & fix', 'geo-site-brain' ); ?></strong>
					<?php esc_html_e( 'See what AI understands, then work the Fix Queue and ask the site questions.', 'geo-site-brain' ); ?>
				</li>
			</ol>
		</div>
	<?php endif; ?>

	<div class="gsb-cards">
		<div class="gsb-card gsb-score-card">
			<div class="gsb-score-num gsb-pill-<?php echo esc_attr( $band ); ?>" style="background:none;"><?php echo (int) $completeness; ?>%</div>
			<div class="gsb-card-label">
				<?php printf( esc_html__( 'Site Brain is %s', 'geo-site-brain' ), esc_html( $state ) ); ?><br>
				<span class="gsb-muted"><?php printf( esc_html__( 'AI clearly understands %1$d of %2$d pages', 'geo-site-brain' ), (int) $understood, (int) $total ); ?></span>
			</div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><?php echo (int) count( $needs_work ) + (int) $unscanned; ?></div>
			<div class="gsb-card-label"><?php esc_html_e( 'Pages AI struggles with', 'geo-site-brain' ); ?></div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><a href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-recommendations' ) ); ?>"><?php echo (int) count( $open_recs ); ?></a></div>
			<div class="gsb-card-label"><?php esc_html_e( 'Fixes in the queue', 'geo-site-brain' ); ?></div>
		</div>
	</div>

	<div class="gsb-grid">
		<div class="gsb-panel">
			<h2><?php esc_html_e( 'Fix these first', 'geo-site-brain' ); ?></h2>
			<?php if ( empty( $open_recs ) ) : ?>
				<p class="gsb-muted"><?php esc_html_e( 'Nothing queued. Scan the site to generate recommendations.', 'geo-site-brain' ); ?></p>
			<?php else : ?>
				<ul class="gsb-recs">
					<?php foreach ( array_slice( $open_recs, 0, 5 ) as $rec ) : ?>
						<li class="gsb-rec">
							<span class="gsb-prio gsb-prio-<?php echo esc_attr( $rec->priority ); ?>"><?php echo esc_html( $rec->priority ); ?></span>
							<div class="gsb-rec-body"><div class="gsb-rec-title"><?php echo esc_html( $rec->title ); ?></div></div>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-recommendations' ) ); ?>"><?php esc_html_e( 'Open Fix Queue', 'geo-site-brain' ); ?></a></p>
			<?php endif; ?>
		</div>

		<div class="gsb-panel">
			<h2><?php esc_html_e( 'What AI can\'t read yet', 'geo-site-brain' ); ?></h2>
			<?php if ( empty( $needs_work ) && 0 === $unscanned ) : ?>
				<p class="gsb-muted"><?php esc_html_e( 'Every scanned page is in good shape. 🎉', 'geo-site-brain' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( array_slice( array_reverse( $needs_work ), 0, 6 ) as $row ) :
						$title = get_the_title( $row->post_id ) ?: $row->url; ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>"><?php echo esc_html( $title ); ?></a></td>
							<td style="width:60px;"><span class="gsb-pill gsb-pill-<?php echo esc_attr( GSB_View_Helpers::band( (int) $row->score ) ); ?>"><?php echo (int) $row->score; ?></span></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( $unscanned ) : ?>
						<tr><td colspan="2" class="gsb-muted"><?php printf( esc_html__( '+ %d page(s) not scanned yet', 'geo-site-brain' ), (int) $unscanned ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-scores' ) ); ?>"><?php esc_html_e( 'See full Scorecard', 'geo-site-brain' ); ?></a></p>
			<?php endif; ?>
		</div>
	</div>

	<div class="gsb-panel gsb-setup-status">
		<h2><?php esc_html_e( 'Status', 'geo-site-brain' ); ?></h2>
		<div class="gsb-status-row">
			<span><?php esc_html_e( 'AI connection', 'geo-site-brain' ); ?></span>
			<?php echo $has_openai
				? '<span class="gsb-ok">✓ ' . esc_html__( 'Connected', 'geo-site-brain' ) . '</span>'
				: '<a class="gsb-bad" href="' . esc_url( admin_url( 'admin.php?page=gsb-settings' ) ) . '">' . esc_html__( 'Not connected — open Setup', 'geo-site-brain' ) . '</a>'; ?>
		</div>
		<div class="gsb-status-row">
			<span><?php esc_html_e( 'Pages read', 'geo-site-brain' ); ?></span>
			<strong><?php echo (int) $scanned; ?> / <?php echo (int) $total; ?></strong>
		</div>
		<div class="gsb-status-row">
			<span><?php esc_html_e( 'Content turned into AI knowledge', 'geo-site-brain' ); ?></span>
			<strong><?php echo $progress['chunks'] ? (int) round( ( $progress['embedded'] / max( 1, $progress['chunks'] ) ) * 100 ) : 0; ?>%</strong>
		</div>
		<div class="gsb-status-row">
			<span><?php esc_html_e( 'Last updated', 'geo-site-brain' ); ?></span>
			<strong><?php echo $progress['last_reindex'] ? esc_html( $progress['last_reindex'] ) : esc_html__( 'never', 'geo-site-brain' ); ?></strong>
		</div>
		<p style="margin-top:12px;">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-scan' ) ); ?>"><?php esc_html_e( 'Scan Site', 'geo-site-brain' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-chat' ) ); ?>"><?php esc_html_e( 'Ask the Site', 'geo-site-brain' ); ?></a>
		</p>
	</div>
</div>
