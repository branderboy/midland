<?php
/**
 * Competitors — how AI-legible your competitors are versus you, and which of
 * your services they target that you haven't covered.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cmp        = GSB_Competitors::compare();
$you        = $cmp['you'];
$competitors = $cmp['competitors'];
$rows       = $cmp['service_rows'];
$configured = GSB_Settings::competitor_urls();
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Competitive GEO', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub">
		<?php esc_html_e( 'How understandable your competitors are to AI compared with you — and where they are ahead.', 'geo-site-brain' ); ?>
		<?php if ( ! empty( $configured ) ) : ?>
			<button id="gsb-run-competitors" class="button button-primary"><?php esc_html_e( 'Analyse competitors', 'geo-site-brain' ); ?></button>
			<span id="gsb-comp-status" class="gsb-probe-status"></span>
		<?php endif; ?>
	</p>

	<?php if ( empty( $configured ) ) : ?>
		<div class="notice notice-info inline"><p><?php printf( wp_kses_post( __( 'Add competitor website URLs in <a href="%s">Settings → Competitors</a>, then analyse them here.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-settings' ) ) ); ?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<div class="gsb-panel">
		<h2><?php esc_html_e( 'AI legibility: you vs competitors', 'geo-site-brain' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Business', 'geo-site-brain' ); ?></th>
					<th><?php esc_html_e( 'AI score', 'geo-site-brain' ); ?></th>
					<th><?php esc_html_e( 'Services', 'geo-site-brain' ); ?></th>
					<th><?php esc_html_e( 'Locations', 'geo-site-brain' ); ?></th>
					<th><?php esc_html_e( 'FAQs', 'geo-site-brain' ); ?></th>
					<th><?php esc_html_e( 'Schema', 'geo-site-brain' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="gsb-you-row">
					<td><strong><?php echo esc_html( $you['name'] ); ?> <?php esc_html_e( '(you)', 'geo-site-brain' ); ?></strong></td>
					<td><span class="gsb-pill gsb-pill-<?php echo esc_attr( GSB_View_Helpers::band( (int) $you['ai_score'] ) ); ?>"><?php echo null === $you['ai_score'] ? '—' : (int) $you['ai_score']; ?></span></td>
					<td><?php echo (int) $you['services']; ?></td>
					<td><?php echo (int) $you['locations']; ?></td>
					<td><?php echo (int) $you['faqs']; ?></td>
					<td>—</td>
				</tr>
				<?php if ( empty( $competitors ) ) : ?>
					<tr><td colspan="6" class="gsb-muted"><?php esc_html_e( 'No competitor data yet. Click "Analyse competitors".', 'geo-site-brain' ); ?></td></tr>
				<?php else : foreach ( $competitors as $c ) :
					$snap = json_decode( (string) $c->snapshot, true ) ?: array(); ?>
					<tr>
						<td><?php echo esc_html( $c->name ); ?><br><a class="gsb-muted" href="<?php echo esc_url( $c->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $c->url ); ?></a></td>
						<td><span class="gsb-pill gsb-pill-<?php echo esc_attr( GSB_View_Helpers::band( (int) $c->ai_score ) ); ?>"><?php echo (int) $c->ai_score; ?></span></td>
						<td><?php echo (int) count( (array) ( $snap['services'] ?? array() ) ); ?></td>
						<td><?php echo (int) count( (array) ( $snap['locations'] ?? array() ) ); ?></td>
						<td><?php echo (int) ( $snap['faq_count'] ?? 0 ); ?></td>
						<td><?php echo ! empty( $snap['schema_types'] ) ? '✓' : '—'; ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ( ! empty( $competitors ) && ! empty( $rows ) ) : ?>
		<div class="gsb-panel">
			<h2><?php esc_html_e( 'Service coverage: who targets what', 'geo-site-brain' ); ?></h2>
			<p class="gsb-muted"><?php esc_html_e( 'Compared against your configured services. "Gap" = a competitor targets it and you don\'t have a clear page yet.', 'geo-site-brain' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Service', 'geo-site-brain' ); ?></th><th><?php esc_html_e( 'You', 'geo-site-brain' ); ?></th><th><?php esc_html_e( 'Competitor', 'geo-site-brain' ); ?></th><th><?php esc_html_e( 'Verdict', 'geo-site-brain' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['name'] ); ?></td>
							<td><?php echo $r['you'] ? '<span class="gsb-ok">✓</span>' : '<span class="gsb-bad">✗</span>'; ?></td>
							<td><?php echo $r['comp'] ? '✓' : '—'; ?></td>
							<td><span class="gsb-verdict gsb-verdict-<?php echo esc_attr( $r['verdict'] ); ?>"><?php echo esc_html( $r['verdict'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
