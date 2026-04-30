<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_License {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_license_page' ), 99 );
        add_action( 'admin_init', array( $this, 'handle_license_save' ) );
    }

    public function add_license_page() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'License', 'smart-forms-pro' ),
            esc_html__( 'License', 'smart-forms-pro' ),
            'manage_options',
            'sfco-license',
            array( $this, 'render_license_page' )
        );
    }

    public static function is_valid() {
        $license = get_option( 'sfco_pro_license_key', '' );
        $status  = get_option( 'sfco_pro_license_status', '' );

        return ! empty( $license ) && 'valid' === $status;
    }

    public function handle_license_save() {
        if ( ! isset( $_POST['sfco_save_license'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_license_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_license_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_license' ) ) {
            return;
        }

        $key = isset( $_POST['sfco_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['sfco_license_key'] ) ) : '';

        if ( empty( $key ) ) {
            delete_option( 'sfco_pro_license_key' );
            delete_option( 'sfco_pro_license_status' );
            wp_safe_redirect( admin_url( 'admin.php?page=sfco-license&deactivated=1' ) );
            exit;
        }

        update_option( 'sfco_pro_license_key', $key );

        // Validate license with remote server.
        $valid = $this->validate_license_remote( $key );

        update_option( 'sfco_pro_license_status', $valid ? 'valid' : 'invalid' );
        wp_safe_redirect( admin_url( 'admin.php?page=sfco-license&saved=1' ) );
        exit;
    }

    private function validate_license_remote( $key ) {
        // Remote license validation endpoint.
        $response = wp_remote_post( 'https://livableforms.com/api/license/validate', array(
            'timeout' => 15,
            'body'    => array(
                'license_key' => $key,
                'site_url'    => home_url(),
                'product'     => 'smart-forms-pro',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            // Can't reach server - accept key for now.
            return true;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return ! empty( $body['valid'] );
    }

    public function render_license_page() {
        $key    = get_option( 'sfco_pro_license_key', '' );
        $status = get_option( 'sfco_pro_license_status', '' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart Forms PRO License', 'smart-forms-pro' ); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <?php if ( 'valid' === $status ) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License activated! All PRO features are now unlocked.', 'smart-forms-pro' ); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Invalid license key. Please check and try again.', 'smart-forms-pro' ); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['deactivated'] ) ) : ?>
                <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'License deactivated.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_license', '_sfco_license_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="sfco_license_key"><?php esc_html_e( 'License Key', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="text" name="sfco_license_key" id="sfco_license_key" class="regular-text" value="<?php echo esc_attr( $key ); ?>" placeholder="XXXX-XXXX-XXXX-XXXX">
                            <?php if ( 'valid' === $status ) : ?>
                                <span style="color:#00a32a;font-weight:600;margin-left:8px;">&#10003; <?php esc_html_e( 'Active', 'smart-forms-pro' ); ?></span>
                            <?php elseif ( 'invalid' === $status ) : ?>
                                <span style="color:#d63638;font-weight:600;margin-left:8px;">&#10005; <?php esc_html_e( 'Invalid', 'smart-forms-pro' ); ?></span>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e( 'Enter your license key from your purchase email. Leave blank and save to deactivate.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="sfco_save_license" value="1" class="button button-primary"><?php esc_html_e( 'Save License', 'smart-forms-pro' ); ?></button>
                </p>
            </form>

            <hr>
            <p><?php esc_html_e( "Don't have a license?", 'smart-forms-pro' ); ?> <a href="https://livableforms.com/smart-forms-pro.html" target="_blank" rel="noopener noreferrer"><strong><?php esc_html_e( 'Get Smart Forms PRO - $399/yr', 'smart-forms-pro' ); ?></strong></a></p>
        </div>
        <?php
    }
}

new SFCO_Pro_License();
