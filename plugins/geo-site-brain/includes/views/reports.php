<?php
/**
 * Reports — a scan summary + client-facing AI visibility summary.
 * Shows: latest scan, indexed pages, embedded chunks, avg score,
 * visibility score, open fixes, applied fixes, competitor gaps.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$d = GSB_Reports::data();
$has_openai = GSB_Settings::has_openai();
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Reports', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub">
		<?php esc_html_e( 'A client-ready summary you can print or save as PDF (Print → Save as PDF).', 'geo-site-brain' ); ?>
		<button class="button" onclick="window.print()"><?php esc_html_e( 'Print / Save PDF', 'geo-site-brain' ); ?></button>
	</p>

	<?php if ( null === $d['overall'] && 0 === $d['indexed_pages'] ) : ?>
		<div class="notice notice-info inline"><p><?php
			printf(
				wp_kses_post( __( 'No data yet. <a href="%s">Scan your website</a> to generate a report.', 'geo-site-brain' ) ),
				esc_url( admin_url( 'admin.php?page=gsb-scan' ) )
			);
		?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<?php
	$agency = trim( (string) GSB_Settings::get( 'agency_name' ) );
	$logo   = trim( (string) GSB_Settings::get( 'agency_logo' ) );
	$footer = trim( (string) GSB_Settings::get( 'report_contact' ) );
	?>
	<div class="gsb-report">
		<div class="gsb-report-head">
			<?php if ( $logo ) : ?>
				<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $agency ?: $d['business'] ); ?>" class="gsb-report-logo" />
			<?php endif; ?>
			<h2><?php echo esc_html( $d['business'] ); ?></h2>
			<div class="gsb-muted">
				<?php printf( esc_html__( 'AI Visibility Report · %s', 'geo-site-brain' ), esc_html( $d['generated'] ) ); ?>
				<?php if ( $agency ) : ?> · <?php printf( esc_html__( 'Prepared by %s', 'geo-site-brain' ), esc_html( $agency ) ); ?><?php endif; ?>
			</div>
		</div>

		<!-- Scan summary -->
		<h3><?php esc_html_e( 'Scan Summary', 'geo-site-brain' ); ?></h3>
		<table class="widefat striped" style="max-width:520px;margin-bottom:20px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Latest scan', 'geo-site-brain' ); ?></td>
					<td><?php echo $d['last_scan'] ? esc_html( $d['last_scan'] ) : '<span class="gsb-muted">' . esc_html__( 'Never — scan now', 'geo-site-brain' ) . '</span>'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Indexed pages', 'geo-site-brain' ); ?></td>
					<td><?php echo (int) $d['indexed_pages']; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Embedded chunks', 'geo-site-brain' ); ?></td>
					<td>
						<?php echo (int) $d['embedded_chunks']; ?>
						<?php if ( $d['unembedded_chunks'] > 0 ) : ?>
							&nbsp;<span class="<?php echo $has_openai ? 'gsb-muted' : 'gsb-bad'; ?>">
								(<?php printf(
									esc_html( _n( '%d not embedded', '%d not embedded', $d['unembedded_chunks'], 'geo-site-brain' ) ),
									$d['unembedded_chunks']
								); ?>
								<?php if ( ! $has_openai ) : ?>
									— <a href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-settings' ) ); ?>"><?php esc_html_e( 'add OpenAI key', 'geo-site-brain' ); ?></a>
								<?php endif; ?>)
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Average page score', 'geo-site-brain' ); ?></td>
					<td><?php echo null !== $d['avg_page_score'] ? (int) $d['avg_page_score'] . ' / 100' : '—'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'AI visibility score', 'geo-site-brain' ); ?></td>
					<td><?php echo null !== $d['avg_vis_score'] ? (int) $d['avg_vis_score'] . ' / 100' : '—'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Open fixes', 'geo-site-brain' ); ?></td>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-recommendations' ) ); ?>">
							<?php echo (int) $d['open_fixes']; ?>
						</a>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Applied fixes', 'geo-site-brain' ); ?></td>
					<td><?php echo (int) $d['applied_fixes']; ?></td>
				</tr>
				<?php if ( $d['has_competitors'] ) : ?>
					<tr>
						<td><?php esc_html_e( 'Competitor service gaps', 'geo-site-brain' ); ?></td>
						<td>
							<?php if ( $d['competitor_gaps'] > 0 ) : ?>
								<span class="gsb-bad"><?php echo (int) $d['competitor_gaps']; ?></span>
								&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=gsb-competitors' ) ); ?>" class="gsb-muted"><?php esc_html_e( 'view gaps', 'geo-site-brain' ); ?></a>
							<?php else : ?>
								<span class="gsb-ok">✓ <?php esc_html_e( 'No gaps detected', 'geo-site-brain' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( null !== $d['overall'] ) : ?>

		<!-- AI visibility per engine -->
		<div class="gsb-cards">
			<div class="gsb-card gsb-score-card">
				<div class="gsb-score-num"><?php echo (int) $d['overall']; ?></div>
				<div class="gsb-card-label"><?php esc_html_e( 'Overall AI Visibility', 'geo-site-brain' ); ?></div>
			</div>
			<?php foreach ( $d['engines'] as $e ) : ?>
				<div class="gsb-card">
					<div class="gsb-card-num"><?php echo (int) $e->visibility_score; ?></div>
					<div class="gsb-card-label"><?php echo esc_html( GSB_Visibility::engine_label( $e->engine ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<h3><?php esc_html_e( 'What AI understands about the business', 'geo-site-brain' ); ?></h3>
		<table class="widefat striped">
			<tbody>
				<tr><td><?php esc_html_e( 'Services identified', 'geo-site-brain' ); ?></td><td><?php echo $d['services'] ? esc_html( implode( ', ', $d['services'] ) ) : '—'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Service areas identified', 'geo-site-brain' ); ?></td><td><?php echo $d['locations'] ? esc_html( implode( ', ', $d['locations'] ) ) : '—'; ?></td></tr>
				<tr><td><?php esc_html_e( 'FAQs on file', 'geo-site-brain' ); ?></td><td><?php echo (int) ( $d['counts']['faq'] ?? 0 ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Testimonials on file', 'geo-site-brain' ); ?></td><td><?php echo (int) ( $d['counts']['testimonial'] ?? 0 ); ?></td></tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Biggest opportunities', 'geo-site-brain' ); ?></h3>
		<?php if ( $d['missing_services'] || $d['missing_locations'] || $d['missing_links'] ) : ?>
			<ul class="gsb-report-gaps">
				<?php if ( $d['missing_services'] ) : ?><li><?php printf( esc_html__( 'Add pages for these services: %s', 'geo-site-brain' ), esc_html( implode( ', ', $d['missing_services'] ) ) ); ?></li><?php endif; ?>
				<?php if ( $d['missing_locations'] ) : ?><li><?php printf( esc_html__( 'Add service-area pages for: %s', 'geo-site-brain' ), esc_html( implode( ', ', $d['missing_locations'] ) ) ); ?></li><?php endif; ?>
				<?php if ( $d['missing_links'] ) : ?><li><?php printf( esc_html__( '%d service-in-location combinations are not yet covered.', 'geo-site-brain' ), (int) $d['missing_links'] ); ?></li><?php endif; ?>
			</ul>
		<?php else : ?>
			<p class="gsb-ok">✓ <?php esc_html_e( 'Strong coverage — no major gaps detected.', 'geo-site-brain' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Recommended actions', 'geo-site-brain' ); ?></h3>
		<?php if ( empty( $d['top_fixes'] ) ) : ?>
			<p class="gsb-muted"><?php esc_html_e( 'No open actions.', 'geo-site-brain' ); ?></p>
		<?php else : ?>
			<ol class="gsb-report-actions">
				<?php foreach ( $d['top_fixes'] as $rec ) : ?>
					<li><strong><?php echo esc_html( ucfirst( $rec->impact ) ); ?>:</strong> <?php echo esc_html( $rec->title ); ?></li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<?php endif; // overall ?>

		<?php if ( $footer ) : ?>
			<div class="gsb-report-footer gsb-muted"><?php echo nl2br( esc_html( $footer ) ); ?></div>
		<?php endif; ?>
	</div>
</div>
