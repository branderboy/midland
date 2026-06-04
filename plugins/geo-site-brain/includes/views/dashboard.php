<?php
/**
 * Dashboard — answers "What should I do next?" based on actual plugin state.
 * Next-action priority:
 *   1. Configure settings (no API key)
 *   2. Scan site (never scanned)
 *   3. Review Page Scorecard (scanned but no visibility data)
 *   4. Review Knowledge Graph (entities found)
 *   5. Fix open recommendations
 *   6. Run competitor analysis (competitors configured, none analysed)
 *   7. Open report
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_openai  = GSB_Settings::has_openai();
$progress    = GSB_Indexer::get_instance()->progress();
$engines     = GSB_Database::get_visibility();
$overall     = GSB_Visibility::overall_score();
$counts      = GSB_Database::entity_counts();
$open_recs   = GSB_Database::get_recommendations( 'open' );
$applied_recs = GSB_Database::get_recommendations( 'applied' );
$history     = (array) GSB_Database::get_state( 'visibility_history', array() );

$never_scanned   = ( (int) $progress['posts'] === 0 );
$has_score_data  = ( null !== GSB_Database::site_score() );
$has_vis_data    = ! empty( $engines );
$comp_urls       = GSB_Settings::competitor_urls();
$comp_analysed   = count( GSB_Database::get_competitors() );

// Knowledge completeness averaged across engines.
$knowledge = null;
if ( ! empty( $engines ) ) {
	$k = 0;
	foreach ( $engines as $e ) { $k += (int) $e->knowledge_score; }
	$knowledge = (int) round( $k / count( $engines ) );
}

$svc_found  = isset( $counts['service'] )  ? (int) $counts['service']  : 0;
$loc_found  = isset( $counts['location'] ) ? (int) $counts['location'] : 0;
$svc_exp    = max( count( GSB_Settings::services() ), $svc_found, 1 );
$loc_exp    = max( count( GSB_Settings::locations() ), $loc_found, 1 );
$entity_cov = (int) round( 100 * (
	min( 1, $svc_found / $svc_exp ) + min( 1, $loc_found / $loc_exp )
	+ ( ( $counts['faq'] ?? 0 ) > 0 ? 1 : 0 ) + ( ( $counts['testimonial'] ?? 0 ) > 0 ? 1 : 0 )
) / 4 );

// Determine the single next recommended action.
if ( ! $has_openai ) {
	$next_label = __( 'Configure Settings', 'geo-site-brain' );
	$next_url   = admin_url( 'admin.php?page=gsb-settings' );
	$next_desc  = __( 'Add your OpenAI key and business details so the plugin can index and understand your site.', 'geo-site-brain' );
} elseif ( $never_scanned ) {
	$next_label = __( 'Scan your site', 'geo-site-brain' );
	$next_url   = admin_url( 'admin.php?page=gsb-scan' );
	$next_desc  = __( 'Run the first scan to read your content, score every page, and build your knowledge graph.', 'geo-site-brain' );
} elseif ( $has_score_data && ! $has_vis_data ) {
	$next_label = __( 'Review Page Scorecard', 'geo-site-brain' );
	$next_url   = admin_url( 'admin.php?page=gsb-scores' );
	$next_desc  = __( 'Your pages have been scored. Check which pages are hardest for AI to understand.', 'geo-site-brain' );
} elseif ( $has_score_data && $svc_found > 0 && ! $has_vis_data ) {
	$next_label = __( 'Review Knowledge Graph', 'geo-site-brain' );
	$next_url   = admin_url( 'admin.php?page=gsb-knowledge-graph' );
	$next_desc  = __( 'See what services, locations, FAQs, and trust signals have been identified from your content.', 'geo-site-brain' );
} elseif ( ! empty( $open_recs ) ) {
	$next_label = sprintf(
		_n( 'Fix %d open recommendation', 'Fix %d open recommendations', count( $open_recs ), 'geo-site-brain' ),
		count( $open_recs )
	);
	$next_url   = admin_url( 'admin.php?page=gsb-recommendations' );
	$next_desc  = __( 'Work through the Fix Queue — apply easy one-click fixes first, then tackle the manual ones.', 'geo-site-brain' );
} elseif ( ! empty( $comp_urls ) && 0 === $comp_analysed ) {
	$next_label = __( 'Run competitor analysis', 'geo-site-brain' );
	$next_url   = admin_url( 'admin.php?page=gsb-competitors' );
	$next_desc  = __( 'You have competitor URLs configured. Run the analysis to see how your AI visibility compares.', 'geo-site-brain' );
} else {
	$next_label = __( 'Open full report', 'geo-site-brain' );
	$next_url   = admin_url( 'admin.php?page=gsb-reports' );
	$next_desc  = __( 'Review your AI Visibility report and identify what to improve next.', 'geo-site-brain' );
}

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

	<div class="gsb-next-action" style="background:#fff;border-left:4px solid #2271b1;border-radius:4px;padding:14px 16px;margin:0 0 20px;box-shadow:0 1px 2px rgba(0,0,0,.06);">
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#2271b1;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Next recommended action', 'geo-site-brain' ); ?></div>
		<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
			<div>
				<strong style="font-size:15px;"><?php echo esc_html( $next_label ); ?></strong>
				<div class="gsb-muted" style="font-size:13px;margin-top:2px;"><?php echo esc_html( $next_desc ); ?></div>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( $next_url ); ?>"><?php echo esc_html( $next_label ); ?> →</a>
		</div>
	</div>

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
			<div class="gsb-card-label"><?php esc_html_e( 'Open fixes', 'geo-site-brain' ); ?></div>
		</div>
		<div class="gsb-card">
			<div class="gsb-card-num"><?php echo (int) count( $applied_recs ); ?></div>
			<div class="gsb-card-label"><?php esc_html_e( 'Fixes applied', 'geo-site-brain' ); ?></div>
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
