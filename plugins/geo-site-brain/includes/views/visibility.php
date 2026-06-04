<?php
/**
 * AI Visibility Gaps — how each major AI system understands the business.
 * Shows deterministic estimates out of the box; runs LIVE probes against the
 * real models when their keys are configured.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$engines    = GSB_Database::get_visibility();
$labels     = GSB_Visibility::checklist_labels();
$live_keys  = GSB_AI_Providers::live_engines();
$any_live   = ! empty( $live_keys );
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'AI Visibility Gaps', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub">
		<?php esc_html_e( 'How ChatGPT, Claude, Gemini and Perplexity understand your business — and what they still can\'t tell.', 'geo-site-brain' ); ?>
		<?php if ( $any_live ) : ?>
			<button id="gsb-probe-all" class="button button-primary"><?php esc_html_e( 'Run live probe on all engines', 'geo-site-brain' ); ?></button>
		<?php endif; ?>
	</p>

	<?php if ( empty( $engines ) ) : ?>
		<div class="notice notice-info inline"><p><?php printf( wp_kses_post( __( 'No visibility data yet. <a href="%s">Scan your website</a> first.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-scan' ) ) ); ?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( ! $any_live ) : ?>
		<div class="notice notice-info inline"><p><?php printf( wp_kses_post( __( 'These are <strong>estimates</strong>. Add a ChatGPT, Claude, Gemini or Perplexity key in <a href="%s">Settings → AI engines</a> to probe the real models and see exactly how each one describes you.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-settings' ) ) ); ?></p></div>
	<?php endif; ?>

	<div class="gsb-vis-grid">
		<?php foreach ( $engines as $e ) :
			$details = json_decode( (string) $e->details, true ) ?: array();
			$check   = isset( $details['checklist'] ) ? (array) $details['checklist'] : array();
			$is_live = ! empty( $details['live'] );
			$has_key = in_array( $e->engine, $live_keys, true ); ?>
			<div class="gsb-panel gsb-vis-card">
				<div class="gsb-vis-head">
					<h2><?php echo esc_html( GSB_Visibility::engine_label( $e->engine ) ); ?>
						<span class="gsb-tag <?php echo $is_live ? 'gsb-tag-live' : 'gsb-tag-est'; ?>"><?php echo $is_live ? esc_html__( 'Live', 'geo-site-brain' ) : esc_html__( 'Estimated', 'geo-site-brain' ); ?></span>
					</h2>
					<span class="gsb-pill gsb-pill-<?php echo esc_attr( GSB_View_Helpers::band( (int) $e->visibility_score ) ); ?>"><?php echo (int) $e->visibility_score; ?></span>
				</div>

				<div class="gsb-vis-scores">
					<?php
					$scoreset = array(
						__( 'Visibility', 'geo-site-brain' )      => (int) $e->visibility_score,
						__( 'Confidence', 'geo-site-brain' )      => (int) $e->confidence_score,
						__( 'Knowledge', 'geo-site-brain' )       => (int) $e->knowledge_score,
						__( 'Would recommend', 'geo-site-brain' ) => (int) $e->recommendation_score,
					);
					foreach ( $scoreset as $lbl => $val ) : ?>
						<div class="gsb-vis-score">
							<div class="gsb-vis-score-num gsb-band-<?php echo esc_attr( GSB_View_Helpers::band( $val ) ); ?>"><?php echo (int) $val; ?></div>
							<div class="gsb-vis-score-lbl"><?php echo esc_html( $lbl ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( $e->summary ) : ?>
					<p class="gsb-vis-summary"><?php echo nl2br( esc_html( $e->summary ) ); ?></p>
				<?php endif; ?>

				<?php if ( $is_live && ! empty( $details['parsed'] ) ) : ?>
					<div class="gsb-vis-cols">
						<div>
							<strong><?php esc_html_e( 'It identified', 'geo-site-brain' ); ?></strong>
							<div class="gsb-muted"><?php echo $details['identified_services'] ? esc_html( implode( ', ', $details['identified_services'] ) ) : esc_html__( 'no services', 'geo-site-brain' ); ?></div>
						</div>
						<?php if ( ! empty( $details['missing_services'] ) ) : ?>
						<div>
							<strong class="gsb-bad"><?php esc_html_e( 'It missed', 'geo-site-brain' ); ?></strong>
							<div class="gsb-muted"><?php echo esc_html( implode( ', ', $details['missing_services'] ) ); ?></div>
						</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $check ) : ?>
					<ul class="gsb-checklist">
						<?php foreach ( $labels as $key => $label ) :
							$ok = ! empty( $check[ $key ] ); ?>
							<li class="<?php echo $ok ? 'yes' : 'no'; ?>"><?php echo $ok ? '✓' : '✗'; ?> <?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<p>
					<?php if ( $has_key ) : ?>
						<button class="button button-primary gsb-probe" data-engine="<?php echo esc_attr( $e->engine ); ?>"><?php esc_html_e( 'Run live probe', 'geo-site-brain' ); ?></button>
					<?php else : ?>
						<span class="gsb-muted"><?php printf( esc_html__( 'Add a %s key in Settings to probe live.', 'geo-site-brain' ), esc_html( GSB_Visibility::engine_label( $e->engine ) ) ); ?></span>
					<?php endif; ?>
					<span class="gsb-probe-status" data-engine="<?php echo esc_attr( $e->engine ); ?>"></span>
				</p>
			</div>
		<?php endforeach; ?>
	</div>
</div>
