<?php
/**
 * Agent Chat: retrieval-first Q&A over the indexed site content.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$brand = trim( (string) GSB_Settings::get( 'business_name' ) );
$samples = array(
	__( 'What services does this website clearly offer?', 'geo-site-brain' ),
	__( 'What pages are weak for GEO?', 'geo-site-brain' ),
	__( 'What schema is missing?', 'geo-site-brain' ),
	__( 'What internal links should we add?', 'geo-site-brain' ),
	$brand
		? sprintf( __( 'What FAQs should %s add?', 'geo-site-brain' ), $brand )
		: __( 'What FAQs should we add?', 'geo-site-brain' ),
	__( 'What pages should be expanded?', 'geo-site-brain' ),
	__( 'What pages could rank for commercial floor care in Washington DC?', 'geo-site-brain' ),
);
?>
<div class="wrap gsb-wrap">
	<h1><?php esc_html_e( 'Agent Chat', 'geo-site-brain' ); ?></h1>
	<p class="gsb-sub"><?php esc_html_e( 'Retrieval-first: the agent answers from your indexed content, and clearly separates what was Found on site, Inferred from site, and Recommended additions. It will not invent facts.', 'geo-site-brain' ); ?></p>

	<?php if ( ! GSB_Settings::has_openai() ) : ?>
		<div class="notice notice-warning inline"><p><?php printf( wp_kses_post( __( 'Without an OpenAI key the agent returns the most relevant passages it finds (still grounded, no AI synthesis). Add a key on <a href="%s">Settings</a> for full answers.', 'geo-site-brain' ) ), esc_url( admin_url( 'admin.php?page=gsb-settings' ) ) ); ?></p></div>
	<?php endif; ?>

	<div class="gsb-chat">
		<div class="gsb-chat-samples">
			<?php foreach ( $samples as $q ) : ?>
				<button class="gsb-sample button button-secondary"><?php echo esc_html( $q ); ?></button>
			<?php endforeach; ?>
		</div>

		<div id="gsb-chat-log" class="gsb-chat-log" aria-live="polite"></div>

		<form id="gsb-chat-form" class="gsb-chat-form">
			<textarea id="gsb-chat-input" rows="2" placeholder="<?php esc_attr_e( 'Ask about your site…', 'geo-site-brain' ); ?>"></textarea>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Ask', 'geo-site-brain' ); ?></button>
		</form>
	</div>
</div>
