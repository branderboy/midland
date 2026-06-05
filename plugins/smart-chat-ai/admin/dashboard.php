<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$analytics = new SCAI_Analytics();
$stats     = $analytics->get_stats( 30 );

// Connection statuses surfaced from the three subsystems that power the chat.
$perplexity_key = (string) get_option( 'smart_chat_perplexity_api_key', '' );
if ( '' === $perplexity_key ) {
    $perplexity_key = (string) get_option( 'rsseo_pro_ai_perplexity_key', '' );
}
$openai_key       = (string) get_option( 'smart_chat_openai_api_key', '' );
$provider         = get_option( 'smart_chat_ai_provider', 'perplexity' );
$ai_connected     = ( 'openai' === $provider ) ? ( '' !== $openai_key ) : ( '' !== $perplexity_key );

$ctx_enabled = (int) get_option( SCAI_Content_Context::OPT_ENABLED, 0 );
$ctx_last    = get_option( SCAI_Content_Context::OPT_LAST_REFRESH, array() );
$ctx_count   = isset( $ctx_last['count'] ) ? (int) $ctx_last['count'] : 0;
$ctx_ready   = $ctx_enabled && $ctx_count > 0;

$whatsapp_number = (string) get_option( 'smart_chat_whatsapp_number', '' );
$whatsapp_ready  = '' !== $whatsapp_number;

// Chat-owned Calendly + the CRM it feeds. The chat captures the lead free-hand,
// sends the visitor to Calendly, and the booking is tagged in Smart CRM.
$booking_url        = (string) get_option( 'smart_chat_booking_url', '' );
$calendly_connected = class_exists( 'SCAI_Calendly' ) && SCAI_Calendly::is_connected() && '' !== $booking_url;
$crm_active         = defined( 'SCRM_PRO_VERSION' );

$ok_badge   = '<span style="color:#38a169;font-weight:600;">&#x2713; ' . esc_html__( 'Connected', 'smart-chat-ai' ) . '</span>';
$warn_badge = '<span style="color:#e53e3e;font-weight:600;">&#x2717; ' . esc_html__( 'Not connected', 'smart-chat-ai' ) . '</span>';
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Midland Chat Dashboard', 'smart-chat-ai' ); ?></h1>

    <?php
    // ── How the system works ────────────────────────────────────────────────
    // Visual flow: the chat is the form. It captures the lead in conversation,
    // sends the visitor to Calendly, and the booking is tagged in Smart CRM.
    $flow_ok   = '<span style="display:inline-block;margin-top:6px;font-size:11px;font-weight:700;color:#15803d;">&#10003; ' . esc_html__( 'Ready', 'smart-chat-ai' ) . '</span>';
    $flow_warn = '<span style="display:inline-block;margin-top:6px;font-size:11px;font-weight:700;color:#b26200;">&#9888; ' . esc_html__( 'Set up', 'smart-chat-ai' ) . '</span>';
    $nodes = array(
        array( 'ic' => '&#128172;', 't' => __( 'Visitor chats', 'smart-chat-ai' ), 's' => __( 'No form, just talk', 'smart-chat-ai' ), 'ok' => $ai_connected ),
        array( 'ic' => '&#9997;',   't' => __( 'Name + email', 'smart-chat-ai' ),  's' => __( 'Captured in chat', 'smart-chat-ai' ), 'ok' => $ai_connected ),
        array( 'ic' => '&#128197;', 't' => __( 'Picks a time', 'smart-chat-ai' ),  's' => __( 'Your Calendly', 'smart-chat-ai' ),    'ok' => $calendly_connected ),
        array( 'ic' => '&#9989;',   't' => __( 'Tagged in CRM', 'smart-chat-ai' ), 's' => __( 'Booked + deal + job', 'smart-chat-ai' ), 'ok' => $crm_active ),
    );
    ?>
    <div class="card" style="padding:22px;margin-top:20px;max-width:900px;border-left:4px solid #43A94B;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'How your chat system works', 'smart-chat-ai' ); ?></h2>
        <p class="description" style="margin:0 0 18px;"><?php esc_html_e( 'There is no form to fill out. The chat captures the lead in conversation, sends the visitor to Calendly to pick a time, and the booking is tagged automatically in your CRM.', 'smart-chat-ai' ); ?></p>
        <div style="display:flex;flex-wrap:wrap;align-items:stretch;gap:8px;">
            <?php foreach ( $nodes as $i => $n ) : ?>
                <?php if ( $i > 0 ) : ?>
                    <div style="display:flex;align-items:center;color:#43A94B;font-size:20px;font-weight:800;">&rarr;</div>
                <?php endif; ?>
                <div style="flex:1 1 150px;min-width:130px;text-align:center;background:#f6f8f6;border:1px solid #e3e9e4;border-radius:12px;padding:14px 10px;">
                    <div style="font-size:22px;line-height:1;"><?php echo wp_kses_post( $n['ic'] ); ?></div>
                    <strong style="display:block;font-size:13px;margin-top:6px;"><?php echo esc_html( $n['t'] ); ?></strong>
                    <span style="display:block;font-size:11px;color:#5b6b60;margin-top:2px;"><?php echo esc_html( $n['s'] ); ?></span>
                    <?php echo $n['ok'] ? $flow_ok : $flow_warn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card" style="padding:20px;margin-top:20px;max-width:900px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Connections', 'smart-chat-ai' ); ?></h2>
        <p class="description" style="margin-bottom:16px;"><?php esc_html_e( 'These three integrations power the chat: an AI brain (Perplexity), a knowledge source (your sitemap), and a live-handoff channel (WhatsApp).', 'smart-chat-ai' ); ?></p>
        <table class="widefat striped" style="max-width:880px;">
            <thead>
                <tr>
                    <th style="width:200px;"><?php esc_html_e( 'Service', 'smart-chat-ai' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'smart-chat-ai' ); ?></th>
                    <th style="width:160px;"><?php esc_html_e( 'Action', 'smart-chat-ai' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e( 'Perplexity AI', 'smart-chat-ai' ); ?></strong><br><small><?php esc_html_e( 'Customer service brain', 'smart-chat-ai' ); ?></small></td>
                    <td>
                        <?php echo $ai_connected ? $ok_badge : $warn_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <br><small>
                            <?php
                            printf(
                                /* translators: 1: provider name, 2: model */
                                esc_html__( 'Provider: %1$s &middot; Model: %2$s', 'smart-chat-ai' ),
                                esc_html( $provider ),
                                esc_html( get_option( 'smart_chat_ai_model', 'sonar' ) )
                            );
                            ?>
                        </small>
                    </td>
                    <td><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-chat-settings' ) ); ?>"><?php esc_html_e( 'Configure', 'smart-chat-ai' ); ?></a></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Sitemap Ingestion', 'smart-chat-ai' ); ?></strong><br><small><?php esc_html_e( 'Feeds site pages into answers', 'smart-chat-ai' ); ?></small></td>
                    <td>
                        <?php echo $ctx_ready ? $ok_badge : $warn_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <br><small>
                            <?php
                            if ( $ctx_count > 0 ) {
                                printf(
                                    /* translators: 1: cached page count, 2: timestamp */
                                    esc_html__( '%1$d pages cached &middot; last refresh %2$s', 'smart-chat-ai' ),
                                    $ctx_count,
                                    ! empty( $ctx_last['at'] ) ? esc_html( wp_date( 'M j, H:i', (int) $ctx_last['at'] ) ) : '—'
                                );
                            } else {
                                esc_html_e( 'No pages cached yet — enable and refresh.', 'smart-chat-ai' );
                            }
                            ?>
                        </small>
                    </td>
                    <td><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=scai-content' ) ); ?>"><?php esc_html_e( 'Configure', 'smart-chat-ai' ); ?></a></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Calendly', 'smart-chat-ai' ); ?></strong><br><small><?php esc_html_e( 'Booking after the chat captures a lead', 'smart-chat-ai' ); ?></small></td>
                    <td>
                        <?php echo $calendly_connected ? $ok_badge : $warn_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <br><small>
                            <?php if ( '' === $booking_url ) {
                                esc_html_e( 'Add your Calendly booking link in Settings.', 'smart-chat-ai' );
                            } elseif ( ! $calendly_connected ) {
                                esc_html_e( 'Booking link set — click Connect Calendly in Settings so bookings reach the CRM.', 'smart-chat-ai' );
                            } else {
                                esc_html_e( 'Connected — a booking marks the lead Booked and tags it in the CRM.', 'smart-chat-ai' );
                            } ?>
                        </small>
                    </td>
                    <td><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-chat-settings' ) ); ?>"><?php esc_html_e( 'Configure', 'smart-chat-ai' ); ?></a></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Smart CRM', 'smart-chat-ai' ); ?></strong><br><small><?php esc_html_e( 'Where chat leads + bookings are tagged', 'smart-chat-ai' ); ?></small></td>
                    <td>
                        <?php echo $crm_active ? $ok_badge : $warn_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <br><small>
                            <?php if ( $crm_active ) {
                                esc_html_e( 'Active — captured leads and bookings flow into ActiveCampaign + ServiceM8.', 'smart-chat-ai' );
                            } else {
                                esc_html_e( 'Smart CRM for Midland not active — activate it to tag chat leads.', 'smart-chat-ai' );
                            } ?>
                        </small>
                    </td>
                    <td><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=scrm-pro-settings' ) ); ?>"><?php esc_html_e( 'Open CRM', 'smart-chat-ai' ); ?></a></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'WhatsApp', 'smart-chat-ai' ); ?></strong><br><small><?php esc_html_e( 'Click-to-chat handoff', 'smart-chat-ai' ); ?></small></td>
                    <td>
                        <?php echo $whatsapp_ready ? $ok_badge : $warn_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <br><small>
                            <?php if ( $whatsapp_ready ) {
                                echo esc_html( $whatsapp_number );
                            } else {
                                esc_html_e( 'Add your WhatsApp number to enable the in-widget button.', 'smart-chat-ai' );
                            } ?>
                        </small>
                    </td>
                    <td><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-chat-settings' ) ); ?>"><?php esc_html_e( 'Configure', 'smart-chat-ai' ); ?></a></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="smart-chat-stats-grid">
        <div class="smart-chat-stat-card">
            <span class="smart-chat-stat-label"><?php esc_html_e( 'Conversations', 'smart-chat-ai' ); ?></span>
            <span class="smart-chat-stat-value"><?php echo esc_html( $stats['total_conversations'] ); ?></span>
        </div>
        <div class="smart-chat-stat-card">
            <span class="smart-chat-stat-label"><?php esc_html_e( 'Messages', 'smart-chat-ai' ); ?></span>
            <span class="smart-chat-stat-value"><?php echo esc_html( $stats['total_messages'] ); ?></span>
        </div>
        <div class="smart-chat-stat-card">
            <span class="smart-chat-stat-label"><?php esc_html_e( 'Tokens Used', 'smart-chat-ai' ); ?></span>
            <span class="smart-chat-stat-value"><?php echo esc_html( number_format( $stats['total_tokens'] ) ); ?></span>
        </div>
    </div>
</div>
