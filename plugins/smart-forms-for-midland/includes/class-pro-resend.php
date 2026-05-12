<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resend email transport.
 * Replaces wp_mail SMTP with Resend's SMTP relay — zero spam, instant delivery.
 * Settings: Smart Forms PRO > Email Transport
 */
class SFCO_Pro_Resend {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',         array( $this, 'add_menu' ), 35 );
        add_action( 'admin_init',         array( $this, 'handle_save' ) );
        add_action( 'admin_init',         array( $this, 'handle_test_email' ) );
        add_action( 'phpmailer_init',     array( $this, 'configure_phpmailer' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'Email Transport', 'smart-forms-pro' ),
            esc_html__( 'Email Transport', 'smart-forms-pro' ),
            'manage_options',
            'sfco-resend',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['sfco_save_resend'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_resend_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_resend_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_resend' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-pro' ) );
        }

        update_option( 'sfco_resend_enabled',    isset( $_POST['resend_enabled'] ) ? 1 : 0 );
        update_option( 'sfco_resend_api_key',    sanitize_text_field( wp_unslash( $_POST['resend_api_key'] ?? '' ) ) );
        update_option( 'sfco_resend_from_name',  sanitize_text_field( wp_unslash( $_POST['resend_from_name'] ?? '' ) ) );
        update_option( 'sfco_resend_from_email', sanitize_email( wp_unslash( $_POST['resend_from_email'] ?? '' ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=sfco-resend&saved=1' ) );
        exit;
    }

    public function handle_test_email() {
        if ( ! isset( $_POST['sfco_test_email'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_resend_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_resend_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_resend' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-pro' ) );
        }

        $to = sanitize_email( wp_unslash( $_POST['test_email_to'] ?? '' ) );
        if ( ! is_email( $to ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfco-resend&test=invalid' ) );
            exit;
        }

        $sent = wp_mail(
            $to,
            'Resend Transport Test — ' . get_bloginfo( 'name' ),
            '<p>If you\'re reading this, Resend is delivering your emails correctly. No more spam folder.</p>',
            array( 'Content-Type: text/html; charset=UTF-8' )
        );

        wp_safe_redirect( admin_url( 'admin.php?page=sfco-resend&test=' . ( $sent ? 'ok' : 'fail' ) ) );
        exit;
    }

    /**
     * Override PHPMailer to use Resend SMTP.
     * Resend SMTP: smtp.resend.com:465 (SSL), user=resend, pass=API key.
     */
    public function configure_phpmailer( $phpmailer ) {
        if ( ! get_option( 'sfco_resend_enabled' ) ) {
            return;
        }

        $api_key    = get_option( 'sfco_resend_api_key', '' );
        $from_name  = get_option( 'sfco_resend_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'sfco_resend_from_email', get_option( 'admin_email' ) );

        if ( empty( $api_key ) ) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = 'smtp.resend.com';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Port       = 465;
        $phpmailer->SMTPSecure = 'ssl';
        $phpmailer->Username   = 'resend';
        $phpmailer->Password   = $api_key;

        if ( ! empty( $from_email ) ) {
            $phpmailer->setFrom( $from_email, $from_name );
        }
    }

    public function render_page() {
        $enabled    = get_option( 'sfco_resend_enabled', 0 );
        $api_key    = get_option( 'sfco_resend_api_key', '' );
        $from_name  = get_option( 'sfco_resend_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'sfco_resend_from_email', get_option( 'admin_email' ) );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved = isset( $_GET['saved'] );
        $test  = isset( $_GET['test'] ) ? sanitize_key( $_GET['test'] ) : '';
        // phpcs:enable
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Email Transport — Resend', 'smart-forms-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Route all WordPress emails through Resend for instant, reliable delivery. No more leads lost to spam.', 'smart-forms-pro' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>
            <?php if ( 'ok' === $test ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test email sent successfully via Resend.', 'smart-forms-pro' ); ?></p></div>
            <?php elseif ( 'fail' === $test ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Test email failed. Check your API key.', 'smart-forms-pro' ); ?></p></div>
            <?php elseif ( 'invalid' === $test ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Invalid test email address.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_resend', '_sfco_resend_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable Resend Transport', 'smart-forms-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="resend_enabled" value="1" <?php checked( $enabled ); ?>>
                                <?php esc_html_e( 'Route all wp_mail through Resend SMTP', 'smart-forms-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resend_api_key"><?php esc_html_e( 'Resend API Key', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="password" id="resend_api_key" name="resend_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>">
                            <p class="description"><?php esc_html_e( 'Get your key at resend.com → API Keys. Starts with "re_".', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resend_from_name"><?php esc_html_e( 'From Name', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="text" id="resend_from_name" name="resend_from_name" class="regular-text" value="<?php echo esc_attr( $from_name ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="resend_from_email"><?php esc_html_e( 'From Email', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="email" id="resend_from_email" name="resend_from_email" class="regular-text" value="<?php echo esc_attr( $from_email ); ?>">
                            <p class="description"><?php esc_html_e( 'Must be from a domain verified in your Resend account.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="sfco_save_resend" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'smart-forms-pro' ); ?></button>
                </p>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Send Test Email', 'smart-forms-pro' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'sfco_save_resend', '_sfco_resend_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="test_email_to"><?php esc_html_e( 'Send Test To', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="email" id="test_email_to" name="test_email_to" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="sfco_test_email" value="1" class="button"><?php esc_html_e( 'Send Test Email', 'smart-forms-pro' ); ?></button>
                </p>
            </form>

            <hr>
            <h3><?php esc_html_e( 'SMTP Details (for reference)', 'smart-forms-pro' ); ?></h3>
            <table class="widefat" style="max-width:500px">
                <tr><td><strong>Host</strong></td><td>smtp.resend.com</td></tr>
                <tr><td><strong>Port</strong></td><td>465 (SSL)</td></tr>
                <tr><td><strong>Username</strong></td><td>resend</td></tr>
                <tr><td><strong>Password</strong></td><td>Your API Key</td></tr>
            </table>
        </div>
        <?php
    }
}

SFCO_Pro_Resend::get_instance();
