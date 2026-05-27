<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$position  = get_option( 'smart_chat_chat_position', 'bottom-right' );
$color     = esc_attr( get_option( 'smart_chat_chat_color', '#43A94B' ) );
$logo      = esc_url( (string) get_option( 'smart_chat_chat_logo', '' ) );
$title     = esc_html( get_option( 'smart_chat_chat_title', __( 'Chat with us!', 'smart-chat-ai' ) ) );
$subtitle  = esc_html( get_option( 'smart_chat_chat_subtitle', __( 'We typically reply in a few minutes', 'smart-chat-ai' ) ) );
$wa_number = preg_replace( '/[^0-9]/', '', (string) get_option( 'smart_chat_whatsapp_number', '' ) );
$wa_text   = rawurlencode( (string) get_option( 'smart_chat_whatsapp_greeting', __( "Hi! I'd like to ask about your services.", 'smart-chat-ai' ) ) );
$wa_link   = $wa_number ? 'https://wa.me/' . $wa_number . '?text=' . $wa_text : '';
?>
<div id="smart-chat-widget" class="smart-chat-<?php echo esc_attr( $position ); ?>" style="display:none;">
    <div id="smart-chat-bubble" style="background:<?php echo $color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized above ?>;">
        <?php if ( $logo ) : ?>
            <img src="<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>" alt="">
        <?php else : ?>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
        <?php endif; ?>
    </div>
    <div id="smart-chat-window" style="display:none;">
        <div id="smart-chat-header" style="background:<?php echo $color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;">
            <?php if ( $logo ) : ?>
                <img id="smart-chat-header-logo" src="<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>" alt="">
            <?php endif; ?>
            <div class="smart-chat-header-text">
                <strong><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?></strong>
                <small><?php echo $subtitle; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?></small>
            </div>
            <button id="smart-chat-close">&times;</button>
        </div>
        <div id="smart-chat-messages"></div>

        <?php
        $sf_form_id = (int) get_option( 'smart_chat_sf_form_id', 0 );
        $sf_form_html = '';
        if ( $sf_form_id && shortcode_exists( 'sfco_form' ) ) {
            $sf_form_html = do_shortcode( '[sfco_form id="' . $sf_form_id . '"]' );
        }
        ?>
        <div id="smart-chat-form" style="display:none;">
            <div class="smart-chat-form-header">
                <strong><?php esc_html_e( 'Schedule a visit', 'smart-chat-ai' ); ?></strong>
                <button type="button" id="smart-chat-form-close" aria-label="Close">&times;</button>
            </div>
            <?php if ( $sf_form_html ) : ?>
                <div class="smart-chat-form-body">
                    <?php echo $sf_form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of trusted Smart Forms shortcode ?>
                </div>
            <?php else : ?>
                <p style="padding:12px;color:#4B5563;font-size:13px;">
                    <?php esc_html_e( 'Form not configured yet. Set Smart Forms Form ID in Midland Chat → Settings.', 'smart-chat-ai' ); ?>
                </p>
            <?php endif; ?>
        </div>

        <div id="smart-chat-actions">
            <button type="button" id="smart-chat-cta-visit" style="background:<?php echo $color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;">
                <?php esc_html_e( 'Schedule a Visit', 'smart-chat-ai' ); ?>
            </button>
            <?php if ( $wa_link ) : ?>
                <a id="smart-chat-whatsapp" href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener noreferrer">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.82 11.82 0 018.412 3.488 11.82 11.82 0 013.48 8.413c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.149-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.71.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/></svg>
                    <span><?php esc_html_e( 'WhatsApp', 'smart-chat-ai' ); ?></span>
                </a>
            <?php endif; ?>
        </div>

        <div id="smart-chat-input-area">
            <input type="text" id="smart-chat-input" placeholder="<?php esc_attr_e( 'Type a message...', 'smart-chat-ai' ); ?>" autocomplete="off">
            <button id="smart-chat-send" style="background:<?php echo $color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;"><?php esc_html_e( 'Send', 'smart-chat-ai' ); ?></button>
        </div>
    </div>
</div>
