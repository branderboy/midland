<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Team {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 34 );
        add_action( 'admin_init', array( $this, 'handle_invite' ) );
        add_action( 'wp_ajax_sfco_pro_remove_member', array( $this, 'ajax_remove' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'Team', 'smart-forms-pro' ),
            esc_html__( 'Team', 'smart-forms-pro' ),
            'manage_options',
            'sfco-team',
            array( $this, 'render_page' )
        );
    }

    public function handle_invite() {
        if ( ! isset( $_POST['sfco_invite_member'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_team_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_team_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_invite_member' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-pro' ) );
        }

        $email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
        $name  = isset( $_POST['member_name'] ) ? sanitize_text_field( wp_unslash( $_POST['member_name'] ) ) : '';
        $role  = isset( $_POST['member_role'] ) ? sanitize_key( $_POST['member_role'] ) : 'sales';

        if ( empty( $email ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfco-team&error=email' ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfco_team_members';

        // Check duplicate.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s", $email // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( $existing ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfco-team&error=duplicate' ) );
            exit;
        }

        $token   = wp_generate_password( 32, false );
        $expires = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) );

        // Check if this email has a WP user account.
        $wp_user = get_user_by( 'email', $email );

        $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'user_id'        => $wp_user ? $wp_user->ID : null,
            'email'          => $email,
            'name'           => $name,
            'role'           => $role,
            'invite_token'   => $token,
            'invite_expires' => $expires,
            'created_at'     => current_time( 'mysql' ),
        ) );

        // Send invite email.
        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf( __( "You've been invited to %s - Smart Forms", 'smart-forms-pro' ), $site_name );
        $message   = sprintf(
            __( "Hi %s,\n\nYou've been invited to join the Smart Forms team on %s as a %s.\n\nLog in to WordPress to access the leads dashboard.\n\nSite: %s", 'smart-forms-pro' ),
            $name,
            $site_name,
            ucfirst( $role ),
            wp_login_url()
        );

        wp_mail( $email, $subject, $message );

        wp_safe_redirect( admin_url( 'admin.php?page=sfco-team&invited=1' ) );
        exit;
    }

    public function ajax_remove() {
        check_ajax_referer( 'sfco_pro_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
        if ( ! $member_id ) {
            wp_send_json_error();
        }

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'sfco_team_members', array( 'id' => $member_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        wp_send_json_success();
    }

    public function render_page() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>' . esc_html__( 'Please activate your PRO license.', 'smart-forms-pro' ) . '</p></div></div>';
            return;
        }

        global $wpdb;
        $members = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sfco_team_members ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $roles = array(
            'admin'   => __( 'Admin', 'smart-forms-pro' ),
            'manager' => __( 'Manager', 'smart-forms-pro' ),
            'sales'   => __( 'Sales', 'smart-forms-pro' ),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Team Members', 'smart-forms-pro' ); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['invited'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Invitation sent!', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['error'] ) && 'duplicate' === $_GET['error'] ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'This email is already on the team.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>

            <!-- Invite Form -->
            <div class="sfco-card">
                <h2><?php esc_html_e( 'Invite Team Member', 'smart-forms-pro' ); ?></h2>
                <form method="post" class="sfco-invite-form">
                    <?php wp_nonce_field( 'sfco_invite_member', '_sfco_team_nonce' ); ?>
                    <div class="sfco-invite-fields">
                        <input type="text" name="member_name" placeholder="<?php esc_attr_e( 'Name', 'smart-forms-pro' ); ?>" required>
                        <input type="email" name="member_email" placeholder="<?php esc_attr_e( 'Email', 'smart-forms-pro' ); ?>" required>
                        <select name="member_role">
                            <?php foreach ( $roles as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="sfco_invite_member" value="1" class="button button-primary"><?php esc_html_e( 'Send Invite', 'smart-forms-pro' ); ?></button>
                    </div>
                </form>
            </div>

            <!-- Members List -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'smart-forms-pro' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'smart-forms-pro' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'smart-forms-pro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'smart-forms-pro' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'smart-forms-pro' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $members ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No team members yet.', 'smart-forms-pro' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $members as $member ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $member->name ?: '—' ); ?></strong></td>
                                <td><?php echo esc_html( $member->email ); ?></td>
                                <td><?php echo esc_html( ucfirst( $member->role ) ); ?></td>
                                <td>
                                    <?php if ( $member->accepted_at ) : ?>
                                        <span class="sfco-status-badge sfco-status-active"><?php esc_html_e( 'Active', 'smart-forms-pro' ); ?></span>
                                    <?php else : ?>
                                        <span class="sfco-status-badge sfco-status-contacted"><?php esc_html_e( 'Pending', 'smart-forms-pro' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button sfco-remove-member" data-id="<?php echo esc_attr( $member->id ); ?>"><?php esc_html_e( 'Remove', 'smart-forms-pro' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

new SFCO_Pro_Team();
