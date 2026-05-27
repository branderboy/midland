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

$sf_form_id    = (int) get_option( 'smart_chat_sf_form_id', 0 );
$sf_active     = class_exists( 'SFCO_Database' );
$sf_ready      = $sf_active && $sf_form_id > 0;

$ok_badge   = '<span style="color:#38a169;font-weight:600;">&#x2713; ' . esc_html__( 'Connected', 'smart-chat-ai' ) . '</span>';
$warn_badge = '<span style="color:#e53e3e;font-weight:600;">&#x2717; ' . esc_html__( 'Not connected', 'smart-chat-ai' ) . '</span>';
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Midland Chat Dashboard', 'smart-chat-ai' ); ?></h1>

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
                    <td><strong><?php esc_html_e( 'Smart Forms', 'smart-chat-ai' ); ?></strong><br><small><?php esc_html_e( 'Embedded lead-capture form inside the chat', 'smart-chat-ai' ); ?></small></td>
                    <td>
                        <?php echo $sf_ready ? $ok_badge : $warn_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <br><small>
                            <?php if ( ! $sf_active ) {
                                esc_html_e( 'Smart Forms for Midland not active.', 'smart-chat-ai' );
                            } elseif ( ! $sf_form_id ) {
                                esc_html_e( 'Pick a form in Settings to embed in the chat.', 'smart-chat-ai' );
                            } else {
                                printf( esc_html__( 'Form #%d embedded — submissions bridge to Smart CRM automatically.', 'smart-chat-ai' ), $sf_form_id );
                            } ?>
                        </small>
                    </td>
                    <td><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-chat-settings' ) ); ?>"><?php esc_html_e( 'Configure', 'smart-chat-ai' ); ?></a></td>
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
            <span class="smart-chat-stat-label"><?php esc_html_e( 'Leads (30 days)', 'smart-chat-ai' ); ?></span>
            <span class="smart-chat-stat-value"><?php echo esc_html( $stats['total_leads'] ); ?></span>
        </div>
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

    <?php if ( ! empty( $stats['leads_by_status'] ) ) : ?>
        <div class="card" style="max-width:400px;margin-top:20px;padding:20px;">
            <h2><?php esc_html_e( 'Leads by Status', 'smart-chat-ai' ); ?></h2>
            <table class="widefat">
                <?php foreach ( $stats['leads_by_status'] as $row ) : ?>
                    <tr><td><strong><?php echo esc_html( ucfirst( $row->status ) ); ?></strong></td><td><?php echo esc_html( $row->cnt ); ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</div>
