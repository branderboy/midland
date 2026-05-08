<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

global $wpdb;
$table = $wpdb->prefix . 'smart_chat_conversations';

$sessions = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    "SELECT session_id, MIN(created_at) as started, MAX(created_at) as last_msg, COUNT(*) as msg_count
     FROM {$table} GROUP BY session_id ORDER BY last_msg DESC LIMIT 50" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Chat Conversations', 'smart-chat-ai' ); ?></h1>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Session', 'smart-chat-ai' ); ?></th>
                <th><?php esc_html_e( 'Messages', 'smart-chat-ai' ); ?></th>
                <th><?php esc_html_e( 'Started', 'smart-chat-ai' ); ?></th>
                <th><?php esc_html_e( 'Last Message', 'smart-chat-ai' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $sessions ) ) : ?>
                <tr><td colspan="4"><?php esc_html_e( 'No conversations yet.', 'smart-chat-ai' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $sessions as $s ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( substr( $s->session_id, 0, 12 ) . '...' ); ?></code></td>
                        <td><?php echo esc_html( $s->msg_count ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $s->started ) ) ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $s->last_msg ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
