<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$analytics = new SCAI_Analytics();
$stats     = $analytics->get_stats( 30 );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Midland Chat Dashboard', 'smart-chat-ai' ); ?></h1>

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
