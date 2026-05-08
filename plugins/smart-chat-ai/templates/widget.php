<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$position = get_option( 'smart_chat_chat_position', 'bottom-right' );
$color    = esc_attr( get_option( 'smart_chat_chat_color', '#2563EB' ) );
$title    = esc_html( get_option( 'smart_chat_chat_title', __( 'Chat with us!', 'smart-chat-ai' ) ) );
$subtitle = esc_html( get_option( 'smart_chat_chat_subtitle', __( 'We typically reply in a few minutes', 'smart-chat-ai' ) ) );
?>
<div id="smart-chat-widget" class="smart-chat-<?php echo esc_attr( $position ); ?>" style="display:none;">
    <div id="smart-chat-bubble" style="background:<?php echo $color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized above ?>;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
    </div>
    <div id="smart-chat-window" style="display:none;">
        <div id="smart-chat-header" style="background:<?php echo $color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;">
            <div>
                <strong><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?></strong>
                <small><?php echo $subtitle; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?></small>
            </div>
            <button id="smart-chat-close">&times;</button>
        </div>
        <div id="smart-chat-messages"></div>
        <div id="smart-chat-input-area">
            <input type="text" id="smart-chat-input" placeholder="<?php esc_attr_e( 'Type a message...', 'smart-chat-ai' ); ?>" autocomplete="off">
            <button id="smart-chat-send" style="background:<?php echo $color; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;"><?php esc_html_e( 'Send', 'smart-chat-ai' ); ?></button>
        </div>
    </div>
</div>
