<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$analytics = new SCAI_Analytics();
$stats     = $analytics->get_stats( 30 );
$license   = new SCAI_License_Manager();
$is_active = $license->is_active();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Smart Chat AI Dashboard', 'smart-chat-ai' ); ?></h1>

    <?php if ( ! $is_active ) : ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e( 'License not active.', 'smart-chat-ai' ); ?></strong> <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-chat-license' ) ); ?>"><?php esc_html_e( 'Activate your license', 'smart-chat-ai' ); ?></a> <?php esc_html_e( 'to enable the chat widget on your site.', 'smart-chat-ai' ); ?></p>
        </div>
    <?php endif; ?>

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
