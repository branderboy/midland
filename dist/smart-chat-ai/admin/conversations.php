<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

global $wpdb;
$table = $wpdb->prefix . 'smart_chat_conversations';

$sessions = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    "SELECT session_id, MIN(created_at) as started, MAX(created_at) as last_msg, COUNT(*) as msg_count
     FROM {$table} GROUP BY session_id ORDER BY last_msg DESC LIMIT 50" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

$fmt = 'M j, Y g:i a';
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Chat Conversations', 'smart-chat-ai' ); ?></h1>

    <?php if ( empty( $sessions ) ) : ?>
        <p><?php esc_html_e( 'No conversations yet.', 'smart-chat-ai' ); ?></p>
    <?php else : ?>
        <?php foreach ( $sessions as $s ) :
            $messages = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT sender, message, created_at FROM {$table} WHERE session_id = %s ORDER BY created_at ASC",
                $s->session_id
            ) );
            ?>
            <div class="card" style="max-width:860px;margin:0 0 16px;padding:0;">
                <details>
                    <summary style="cursor:pointer;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                        <span>
                            <strong><?php echo esc_html( date_i18n( $fmt, strtotime( $s->started ) ) ); ?></strong>
                            <span style="color:#6b7280;">
                                &middot; <?php echo esc_html( sprintf( _n( '%d message', '%d messages', (int) $s->msg_count, 'smart-chat-ai' ), (int) $s->msg_count ) ); ?>
                                &middot; <?php esc_html_e( 'last:', 'smart-chat-ai' ); ?> <?php echo esc_html( date_i18n( $fmt, strtotime( $s->last_msg ) ) ); ?>
                            </span>
                        </span>
                        <code style="color:#6b7280;"><?php echo esc_html( substr( $s->session_id, 0, 12 ) . '…' ); ?></code>
                    </summary>
                    <div style="border-top:1px solid #e5e7eb;padding:14px 18px;">
                        <?php foreach ( $messages as $m ) :
                            $is_user = ( 'user' === $m->sender );
                            ?>
                            <div style="margin-bottom:12px;">
                                <div style="font-size:11px;color:#6b7280;margin-bottom:2px;">
                                    <strong><?php echo $is_user ? esc_html__( 'Visitor', 'smart-chat-ai' ) : esc_html__( 'Assistant', 'smart-chat-ai' ); ?></strong>
                                    &middot; <?php echo esc_html( date_i18n( $fmt, strtotime( $m->created_at ) ) ); ?>
                                </div>
                                <div style="display:inline-block;max-width:90%;padding:8px 12px;border-radius:10px;<?php echo $is_user ? 'background:#0F1411;color:#fff;' : 'background:#F3FCF4;border:1px solid #d6efd9;color:#0F1411;'; ?>">
                                    <?php echo esc_html( $m->message ); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
