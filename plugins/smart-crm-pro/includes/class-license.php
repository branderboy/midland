<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_License {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 99 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            __( 'CRM PRO License', 'smart-crm-pro' ),
            __( 'CRM PRO License', 'smart-crm-pro' ),
            'manage_options',
            'scrm-license',
            array( $this, 'render_page' )
        );
    }

    public static function is_valid() {
        // Midland in-house build — no license required.
        return true;
    }

    public function handle_save() {
        if ( ! isset( $_POST['scrm_save_license'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scrm_license_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_license_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scrm_save_license' ) ) {
            return;
        }

        $key = isset( $_POST['scrm_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['scrm_license_key'] ) ) : '';
        if ( empty( $key ) ) {
            delete_option( 'scrm_pro_license_key' );
            delete_option( 'scrm_pro_license_status' );
            wp_safe_redirect( admin_url( 'admin.php?page=scrm-license&deactivated=1' ) );
            exit;
        }

        update_option( 'scrm_pro_license_key', $key );

        $response = wp_remote_post( 'https://livableforms.com/api/license/validate', array(
            'timeout' => 15,
            'body'    => array( 'license_key' => $key, 'site_url' => home_url(), 'product' => 'smart-crm-pro' ),
        ) );

        $valid = true; // Accept if can't reach server.
        if ( ! is_wp_error( $response ) ) {
            $body  = json_decode( wp_remote_retrieve_body( $response ), true );
            $valid = ! empty( $body['valid'] );
        }

        update_option( 'scrm_pro_license_status', $valid ? 'valid' : 'invalid' );
        wp_safe_redirect( admin_url( 'admin.php?page=scrm-license&saved=1' ) );
        exit;
    }

    public function render_page() {
        $key    = get_option( 'scrm_pro_license_key', '' );
        $status = get_option( 'scrm_pro_license_status', '' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart CRM PRO License', 'smart-crm-pro' ); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) && 'valid' === $status ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License activated!', 'smart-crm-pro' ); ?></p></div>
            <?php elseif ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Invalid license key.', 'smart-crm-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'scrm_save_license', '_scrm_license_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="scrm_license_key"><?php esc_html_e( 'License Key', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="text" name="scrm_license_key" id="scrm_license_key" class="regular-text" value="<?php echo esc_attr( $key ); ?>" placeholder="XXXX-XXXX-XXXX-XXXX">
                            <?php if ( 'valid' === $status ) : ?>
                                <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Active', 'smart-crm-pro' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="scrm_save_license" value="1" class="button button-primary"><?php esc_html_e( 'Save License', 'smart-crm-pro' ); ?></button>
                </p>
            </form>
            <p><?php esc_html_e( "Don't have a license?", 'smart-crm-pro' ); ?> <a href="https://livableforms.com/smart-crm-pro" target="_blank" rel="noopener noreferrer"><strong><?php esc_html_e( 'Get Smart CRM PRO', 'smart-crm-pro' ); ?></strong></a></p>
        </div>
        <?php
    }
}
