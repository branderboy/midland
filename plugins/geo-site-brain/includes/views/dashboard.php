<?php
/**
 * Executive dashboard — answers "How understandable is my business to AI?"
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_openai = GSB_Settings::has_openai();
$progress   = GSB_Indexer::get_instance()->progress();
$engines    = GSB_Database::get_visibility();
$overall    = GSB_Visibility::overall_score();
$counts     = GSB_Database::entity_counts();
$open_recs  = GSB_Database::get_recommendations( 'open' );
$history    = (array) GSB_Database::get_state( 'visibility_history', array() );

// Knowledge completeness + entity coverage (averaged across engines).
$knowledge = null;
if ( ! empty( $engines ) ) {
	$k = 0;
	foreach ( $engines as $e ) { $k += (int) $e->knowledge_score; }
	$knowledge = (int) round( $k / count( $engines ) );
}

$svc_found = isset( $counts['service'] ) ? (int) $counts['service'] : 0;
$loc_found = isset( $counts['location'] ) ? (int) $counts['location'] : 0;
$svc_exp   = max( count( GSB_Settings::services() ), $svc_found, 1 );
$loc_exp   = max( count( GSB_Settings::locations() ), $loc_found, 1 );
$entity_cov = (int) round( 100 * (
	min( 1, $svc_found / $svc_exp ) + min( 1, $loc_found / $loc_exp )
	+ ( ( $counts['faq'] ?? 0 ) > 0 ? 1 : 0 ) + ( ( $counts['testimonial'] ?? 0 ) > 0 ? 1 : 0 )
) / 4 );

$first_run = ( ! $has_openai || empty( $engines ) );

$recent = array();
foreach ( GSB_Logger::recent( 8 ) as $log ) {
	if ( in_array( $log->context, array( 'entities', 'graph', 'fixes', 'scan', 'cron' ), true ) ) {
		$recent[] = $log;
	}
}
?>
<div class="wrap gsb-wrap">
	<h1><span class="dashicons dashicons-superhero"></span> <?php esc_html_e( 'AI Visibility Command Center', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub"><?php esc_html_e( 'How well ChatGPT, Claude, Gemini and Perplexity understand — and would recommend — your business.', 'geo-site-brain' ); ?></p>

	<?php if ( $first_run ) : ?>
		<div class="gsb-panel gsb-getstarted">
			<h2><?php esc_html_e( 'Start here', 'geo-site-brain' ); ?></h2>
			<ol class="gsb-steps">
				<li class="<?php echo $has_openai ? 'done' : ''; ?>">
					<strong><?php esc_html_e( '1. Connect AI', 'geo-site-brain' ); ?></strong>
					<?php esc_html_e( 'Add your AI key so we can build an AI-readable version of your business.', 'geo-site-brain' ); ?>
					<?php if ( ! $has_openai ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'geo-site-brain' ); ?></a>
					<?php else : ?><span class="gsb-ok">✓ <?php esc_html_e( 'Connected', 'geo-site-brain' ); ?></span><?php endif; ?>
				</li>
				<li><strong><?php esc_html_e( '2. Scan your website', 'geo-site-brain' ); ?></strong>
					<?php esc_html_e( 'We read every page and map your services, locations, FAQs and proof.', 'geo-site-brain' ); ?>
					<a class="button <?php echo $has_openai ? 'button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-scan' ) ); ?>"><?php esc_html_e( 'Scan Website', 'geo-site-brain' ); ?></a>
				</li>
				<li><strong><?php esc_html_e( '3. See & fix the gaps', 'geo-site-brain' ); ?></strong>
					<?php esc_html_e( 'Review AI Visibility, work the Fix Queue, and ask your website questions.', 'geo-site-brain' ); ?>
				</li>
			</ol>
		</div>
	<?php endif; ?>

	<div class="gsb-cards">
		<div class="gsb-card gsb-score-card">
			<div class="gsb-score-num"><?php echo null === $overall ? '—' : (int) $overall; ?></div>
			<div class="gsb-card-label"><?php esc_html_e( 'AI Visibility Score', 'geo-site-brain' ); ?></div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><?php echo null === $knowledge ? '—' : (int) $knowledge . '%'; ?></div>
			<div class="gsb-card-label"><?php esc_html_e( 'Knowledge completeness', 'geo-site-brain' ); ?></div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><?php echo (int) $entity_cov; ?>%</div>
			<div class="gsb-card-label"><?php esc_html_e( 'Entity coverage', 'geo-site-brain' ); ?></div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><a href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-recommendations' ) ); ?>"><?php echo (int) count( $open_recs ); ?></a></div>
			<div class="gsb-card-label"><?php esc_html_e( 'Fixes in the queue', 'geo-site-brain' ); ?></div>
		</div>
	</div>

	<?php if ( ! empty( $engines ) ) : ?>
		<div class="gsb-engine-row">
			<?php foreach ( $engines as $e ) : ?>
				<a class="gsb-engine-chip" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-visibility' ) ); ?>">
					<span class="gsb-engine-name"><?php echo esc_html( GSB_Visibility::engine_label( $e->engine ) ); ?></span>
					<span class="gsb-pill gsb-pill-<?php echo esc_attr( GSB_View_Helpers::band( (int) $e->visibility_score ) ); ?>"><?php echo (int) $e->visibility_score; ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="gsb-entity-strip">
		<?php
		$strip = array(
			'service'     => __( 'Services', 'geo-site-brain' ),
			'location'    => __( 'Locations', 'geo-site-brain' ),
			'faq'         => __( 'FAQs', 'geo-site-brain' ),
			'testimonial' => __( 'Testimonials', 'geo-site-brain' ),
		);
		foreach ( $strip as $type => $label ) : ?>
			<div class="gsb-entity-stat">
				<span class="gsb-entity-num"><?php echo (int) ( $counts[ $type ] ?? 0 ); ?></span>
				<span class="gsb-entity-label"><?php echo esc_html( $label ); ?></span>
			</div>
		<?php endforeach; ?>
		<div class="gsb-entity-stat">
			<span class="gsb-entity-num"><?php echo (int) $progress['posts']; ?></span>
			<span class="gsb-entity-label"><?php esc_html_e( 'Pages read', 'geo-site-brain' ); ?></span>
		</div>
	</div>

	<div class="gsb-grid">
		<div class="gsb-panel">
			<h2><?php esc_html_e( 'Priority fixes', 'geo-site-brain' ); ?></h2>
			<?php if ( empty( $open_recs ) ) : ?>
				<p class="gsb-muted"><?php esc_html_e( 'Scan your website to generate the fix queue.', 'geo-site-brain' ); ?></p>
			<?php else : ?>
				<ul class="gsb-recs">
					<?php foreach ( array_slice( $open_recs, 0, 5 ) as $rec ) : ?>
						<li class="gsb-rec">
							<span class="gsb-prio gsb-prio-<?php echo esc_attr( $rec->impact ); ?>"><?php echo esc_html( $rec->impact ); ?></span>
							<div class="gsb-rec-body"><div class="gsb-rec-title"><?php echo esc_html( $rec->title ); ?></div></div>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-recommendations' ) ); ?>"><?php esc_html_e( 'Open Fix Queue', 'geo-site-brain' ); ?></a></p>
			<?php endif; ?>
		</div>

		<div class="gsb-panel">
			<h2><?php esc_html_e( 'Recent changes', 'geo-site-brain' ); ?></h2>
			<?php if ( empty( $recent ) ) : ?>
				<p class="gsb-muted"><?php esc_html_e( 'No activity yet.', 'geo-site-brain' ); ?></p>
			<?php else : ?>
				<ul class="gsb-activity">
					<?php foreach ( $recent as $log ) : ?>
						<li><span class="gsb-muted"><?php echo esc_html( mysql2date( 'M j, H:i', $log->created_at ) ); ?></span> — <?php echo esc_html( $log->message ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( count( $history ) > 1 ) : ?>
				<h3 style="margin-top:16px;"><?php esc_html_e( 'AI Visibility over time', 'geo-site-brain' ); ?></h3>
				<div class="gsb-spark">
					<?php foreach ( array_slice( $history, -16 ) as $pt ) :
						$h = max( 4, (int) $pt['score'] ); ?>
						<span class="gsb-spark-bar" style="height:<?php echo (int) $h; ?>%" title="<?php echo esc_attr( $pt['date'] . ': ' . $pt['score'] ); ?>"></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
