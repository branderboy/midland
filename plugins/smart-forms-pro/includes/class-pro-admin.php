<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Admin {

    public function __construct() {
        // Replace the free "Upgrade to PRO" tab with license activation notice.
        add_action( 'admin_menu', array( $this, 'modify_menu' ), 100 );

        // Add PRO badge to admin bar.
        add_action( 'admin_bar_menu', array( $this, 'admin_bar_badge' ), 999 );

        // Admin notice if license not active.
        add_action( 'admin_notices', array( $this, 'license_notice' ) );
    }

    public function modify_menu() {
        // Remove the free plugin's "Upgrade to PRO" page since they have PRO.
        remove_submenu_page( 'sfco-forms', 'sfco-upgrade' );
    }

    public function admin_bar_badge( $admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( SFCO_Pro_License::is_valid() ) {
            $admin_bar->add_node( array(
                'id'    => 'sfco-pro-badge',
                'title' => '<span style="color:#00a32a;font-weight:700;">Smart Forms PRO</span>',
                'href'  => admin_url( 'admin.php?page=sfco-license' ),
            ) );
        }
    }

    public function license_notice() {
        if ( SFCO_Pro_License::is_valid() ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'sfco-' ) === false ) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Smart Forms PRO is installed but not activated.', 'smart-forms-pro' ); ?></strong>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfco-license' ) ); ?>"><?php esc_html_e( 'Enter your license key to unlock all PRO features.', 'smart-forms-pro' ); ?></a>
            </p>
        </div>
        <?php
    }
}

new SFCO_Pro_Admin();
