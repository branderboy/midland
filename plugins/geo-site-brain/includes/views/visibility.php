<?php
/**
 * AI Visibility Gaps — how each major AI system understands the business.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$engines  = GSB_Database::get_visibility();
$labels   = GSB_Visibility::checklist_labels();
$has_ai   = GSB_Settings::has_openai();
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'AI Visibility Gaps', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub"><?php esc_html_e( 'A simulation of how ChatGPT, Claude, Gemini and Perplexity understand your business — and what they still can\'t tell.', 'geo-site-brain' ); ?></p>

	<?php if ( empty( $engines ) ) : ?>
		<div class="notice notice-info inline"><p><?php printf( wp_kses_post( __( 'No visibility data yet. <a href="%s">Scan your website</a> first.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-scan' ) ) ); ?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<div class="gsb-vis-grid">
		<?php foreach ( $engines as $e ) :
			$details = json_decode( (string) $e->details, true ) ?: array();
			$check   = isset( $details['checklist'] ) ? (array) $details['checklist'] : array(); ?>
			<div class="gsb-panel gsb-vis-card">
				<div class="gsb-vis-head">
					<h2><?php echo esc_html( GSB_Visibility::engine_label( $e->engine ) ); ?></h2>
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

				<p class="gsb-vis-summary"><?php echo esc_html( $e->summary ); ?></p>

				<?php if ( $check ) : ?>
					<ul class="gsb-checklist">
						<?php foreach ( $labels as $key => $label ) :
							$ok = ! empty( $check[ $key ] ); ?>
							<li class="<?php echo $ok ? 'yes' : 'no'; ?>"><?php echo $ok ? '✓' : '✗'; ?> <?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<p>
					<button class="button gsb-narrative" data-engine="<?php echo esc_attr( $e->engine ); ?>" <?php disabled( ! $has_ai ); ?>>
						<?php printf( esc_html__( 'How would %s describe us?', 'geo-site-brain' ), esc_html( GSB_Visibility::engine_label( $e->engine ) ) ); ?>
					</button>
				</p>
				<div class="gsb-narrative-out" data-engine="<?php echo esc_attr( $e->engine ); ?>"></div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( ! $has_ai ) : ?>
		<p class="gsb-muted"><?php printf( wp_kses_post( __( 'Scores are available now. Connect AI in <a href="%s">Settings</a> to also generate the written "how AI would describe us" narrative.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-settings' ) ) ); ?></p>
	<?php endif; ?>
</div>
