<?php
/**
 * Knowledge Graph — the AI-readable model of the business: entities, the
 * Service × Location coverage matrix, orphan entities and missing links.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$counts  = GSB_Database::entity_counts();
$matrix  = GSB_Knowledge_Graph::matrix();
$orphans = GSB_Knowledge_Graph::orphans();
$missing = GSB_Knowledge_Graph::missing_link_count();

$types = array(
	'business'    => __( 'Business', 'geo-site-brain' ),
	'service'     => __( 'Services', 'geo-site-brain' ),
	'location'    => __( 'Locations', 'geo-site-brain' ),
	'faq'         => __( 'FAQs', 'geo-site-brain' ),
	'testimonial' => __( 'Testimonials', 'geo-site-brain' ),
	'review'      => __( 'Reviews', 'geo-site-brain' ),
	'author'      => __( 'Authors', 'geo-site-brain' ),
	'case_study'  => __( 'Case studies', 'geo-site-brain' ),
);

$has_entities = array_sum( $counts ) > 0;
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Knowledge Graph', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub"><?php esc_html_e( 'This is how AI sees your business: the things you offer, where you offer them, and how they connect.', 'geo-site-brain' ); ?></p>

	<?php if ( ! $has_entities ) : ?>
		<div class="notice notice-info inline"><p><?php printf( wp_kses_post( __( 'No business knowledge yet. <a href="%s">Scan your website</a> to build the graph.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-scan' ) ) ); ?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<div class="gsb-entity-strip">
		<?php foreach ( $types as $type => $label ) : if ( empty( $counts[ $type ] ) ) { continue; } ?>
			<div class="gsb-entity-stat">
				<span class="gsb-entity-num"><?php echo (int) $counts[ $type ]; ?></span>
				<span class="gsb-entity-label"><?php echo esc_html( $label ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="gsb-panel">
		<h2><?php esc_html_e( 'Service × Location coverage', 'geo-site-brain' ); ?></h2>
		<p class="gsb-muted"><?php esc_html_e( 'Each cell shows whether AI can connect a service to a location.', 'geo-site-brain' ); ?>
			<span class="gsb-legend"><span class="gsb-dot found"></span> <?php esc_html_e( 'covered', 'geo-site-brain' ); ?></span>
			<span class="gsb-legend"><span class="gsb-dot weak"></span> <?php esc_html_e( 'weak', 'geo-site-brain' ); ?></span>
			<span class="gsb-legend"><span class="gsb-dot missing"></span> <?php esc_html_e( 'missing', 'geo-site-brain' ); ?></span>
		</p>

		<?php if ( empty( $matrix['services'] ) || empty( $matrix['locations'] ) ) : ?>
			<p class="gsb-muted"><?php esc_html_e( 'Add your services and service locations in Settings to map coverage.', 'geo-site-brain' ); ?></p>
		<?php else : ?>
			<div class="gsb-matrix-scroll">
				<table class="gsb-matrix">
					<thead>
						<tr>
							<th></th>
							<?php foreach ( $matrix['locations'] as $loc ) : ?>
								<th><?php echo esc_html( $loc->name ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $matrix['services'] as $svc ) : ?>
							<tr>
								<th><?php echo esc_html( $svc->name ); ?></th>
								<?php foreach ( $matrix['locations'] as $loc ) :
									$status = $matrix['cells'][ (int) $svc->id ][ (int) $loc->id ] ?? 'missing';
									$sym = ( 'found' === $status ) ? '✓' : ( ( 'weak' === $status ) ? '~' : '+' ); ?>
									<td><span class="gsb-cell gsb-cell-<?php echo esc_attr( $status ); ?>" title="<?php echo esc_attr( $svc->name . ' / ' . $loc->name . ' — ' . $status ); ?>"><?php echo esc_html( $sym ); ?></span></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $missing ) : ?>
				<p><strong><?php echo (int) $missing; ?></strong> <?php esc_html_e( 'missing service-area combinations.', 'geo-site-brain' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-recommendations' ) ); ?>"><?php esc_html_e( 'See them in the Fix Queue →', 'geo-site-brain' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="gsb-grid">
		<div class="gsb-panel">
			<h2><?php esc_html_e( 'Services AI can see', 'geo-site-brain' ); ?></h2>
			<?php
			$svc_entities = GSB_Database::get_entities( 'service' );
			if ( empty( $svc_entities ) ) : ?>
				<p class="gsb-muted"><?php esc_html_e( 'None yet.', 'geo-site-brain' ); ?></p>
			<?php else : ?>
				<ul class="gsb-entity-list">
					<?php foreach ( $svc_entities as $e ) : ?>
						<li>
							<span class="gsb-status gsb-status-<?php echo esc_attr( $e->status ); ?>"><?php echo esc_html( $e->status ); ?></span>
							<?php echo esc_html( $e->name ); ?>
							<?php if ( $e->source_post_id ) : ?>
								<a class="gsb-muted" href="<?php echo esc_url( get_edit_post_link( $e->source_post_id ) ); ?>">↗</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="gsb-panel">
			<h2><?php esc_html_e( 'Loose ends', 'geo-site-brain' ); ?></h2>
			<p class="gsb-muted"><?php esc_html_e( 'Knowledge that exists but isn\'t connected to your services or locations.', 'geo-site-brain' ); ?></p>
			<?php if ( empty( $orphans ) ) : ?>
				<p class="gsb-ok">✓ <?php esc_html_e( 'Everything is connected.', 'geo-site-brain' ); ?></p>
			<?php else : ?>
				<ul class="gsb-entity-list">
					<?php foreach ( array_slice( $orphans, 0, 12 ) as $e ) : ?>
						<li><span class="gsb-status gsb-status-found"><?php echo esc_html( $e->entity_type ); ?></span> <?php echo esc_html( $e->name ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>
