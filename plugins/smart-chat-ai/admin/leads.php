<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$manager = new SCAI_Lead_Manager();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

$leads = $manager->get_leads( array( 'search' => $search, 'status' => $status, 'limit' => 50 ) );
$total = $manager->get_count();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Chat Leads', 'smart-chat-ai' ); ?> <span style="color:#999;font-size:14px;">(<?php echo esc_html( $total ); ?>)</span></h1>

    <div style="margin:16px 0;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-chat-leads' ) ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'smart-chat-ai' ); ?></a> |
            <a href="<?php echo esc_url( add_query_arg( 'status', 'new', admin_url( 'admin.php?page=smart-chat-leads' ) ) ); ?>" class="<?php echo 'new' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'New', 'smart-chat-ai' ); ?></a> |
            <a href="<?php echo esc_url( add_query_arg( 'status', 'contacted', admin_url( 'admin.php?page=smart-chat-leads' ) ) ); ?>" class="<?php echo 'contacted' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Contacted', 'smart-chat-ai' ); ?></a> |
            <a href="<?php echo esc_url( add_query_arg( 'status', 'converted', admin_url( 'admin.php?page=smart-chat-leads' ) ) ); ?>" class="<?php echo 'converted' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Converted', 'smart-chat-ai' ); ?></a>
        </div>
        <form method="get" style="display:flex;gap:6px;">
            <input type="hidden" name="page" value="smart-chat-leads">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'smart-chat-ai' ); ?>">
            <button type="submit" class="button"><?php esc_html_e( 'Search', 'smart-chat-ai' ); ?></button>
        </form>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'smart-chat-ai' ); ?></th>
                <th><?php esc_html_e( 'Email', 'smart-chat-ai' ); ?></th>
                <th><?php esc_html_e( 'Phone', 'smart-chat-ai' ); ?></th>
                <th><?php esc_html_e( 'Message', 'smart-chat-ai' ); ?></th>
                <th><?php esc_html_e( 'Status', 'smart-chat-ai' ); ?></th>
                <th><?php esc_html_e( 'Date', 'smart-chat-ai' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $leads ) ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No leads yet.', 'smart-chat-ai' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $leads as $lead ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $lead->name ); ?></strong></td>
                        <td><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></td>
                        <td><?php echo esc_html( $lead->phone ); ?></td>
                        <td><?php echo esc_html( wp_trim_words( $lead->message, 10 ) ); ?></td>
                        <td><?php echo esc_html( ucfirst( $lead->status ) ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $lead->created_at ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
