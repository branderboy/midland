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
        // Route every wp_mail through Resend's HTTPS API when enabled.
        // pre_wp_mail (WP 5.7+) short-circuits the SMTP path entirely
        // when we return non-null, so we never touch PHPMailer. Avoids
        // the port 465/587 outbound-blocked-by-cPanel firewall problem
        // and gets Resend's full delivery telemetry instead of the
        // opaque "SMTP said 250 OK" you get from phpmailer_init.
        add_filter( 'pre_wp_mail',        array( $this, 'maybe_send_via_api' ), 10, 2 );
    }

    public function add_menu() {
        add_submenu_page(
            null,
            esc_html__( 'Email Transport', 'smart-forms-for-midland' ),
            esc_html__( 'Email Transport', 'smart-forms-for-midland' ),
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
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-for-midland' ) );
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
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-for-midland' ) );
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
     * Short-circuit wp_mail and send via Resend's HTTPS API.
     *
     * @param null|bool $short_circuit Filter contract — return non-null
     *                                 to skip wp_mail's PHPMailer path.
     * @param array     $atts          to, subject, message, headers, attachments.
     * @return null|bool null = continue with default wp_mail; bool = result.
     */
    public function maybe_send_via_api( $short_circuit, $atts ) {
        if ( ! get_option( 'sfco_resend_enabled' ) ) {
            return $short_circuit;
        }
        $api_key = (string) get_option( 'sfco_resend_api_key', '' );
        if ( '' === $api_key ) {
            return $short_circuit;
        }

        $from_name  = (string) get_option( 'sfco_resend_from_name', get_bloginfo( 'name' ) );
        $from_email = (string) get_option( 'sfco_resend_from_email', get_option( 'admin_email' ) );

        // Parse the wp_mail atts.
        $to          = (array) ( is_array( $atts['to'] ?? null ) ? $atts['to'] : array_filter( array_map( 'trim', explode( ',', (string) ( $atts['to'] ?? '' ) ) ) ) );
        $subject     = (string) ( $atts['subject'] ?? '' );
        $message     = (string) ( $atts['message'] ?? '' );
        $headers     = (array) ( $atts['headers'] ?? array() );
        $attachments = (array) ( $atts['attachments'] ?? array() );

        // Detect HTML vs plain by inspecting Content-Type header.
        $is_html = false;
        $reply_to = '';
        $cc = array();
        $bcc = array();
        $headers = is_array( $headers ) ? $headers : array_map( 'trim', explode( "\n", (string) $headers ) );
        foreach ( $headers as $h ) {
            if ( ! is_string( $h ) ) continue;
            if ( stripos( $h, 'content-type:' ) === 0 && stripos( $h, 'text/html' ) !== false ) {
                $is_html = true;
            }
            if ( stripos( $h, 'reply-to:' ) === 0 ) {
                $reply_to = trim( substr( $h, 9 ) );
            }
            if ( stripos( $h, 'cc:' ) === 0 ) {
                $cc = array_filter( array_map( 'trim', explode( ',', substr( $h, 3 ) ) ) );
            }
            if ( stripos( $h, 'bcc:' ) === 0 ) {
                $bcc = array_filter( array_map( 'trim', explode( ',', substr( $h, 4 ) ) ) );
            }
            if ( stripos( $h, 'from:' ) === 0 ) {
                // Allow a per-message From header to override the default.
                $raw = trim( substr( $h, 5 ) );
                if ( preg_match( '/^(.*?)<([^>]+)>$/', $raw, $m ) ) {
                    $from_name  = trim( $m[1] );
                    $from_email = trim( $m[2] );
                } elseif ( is_email( $raw ) ) {
                    $from_email = $raw;
                }
            }
        }

        $payload = array(
            'from'    => ( '' !== $from_name ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email ),
            'to'      => array_values( $to ),
            'subject' => $subject,
            ( $is_html ? 'html' : 'text' ) => $message,
        );
        if ( $reply_to ) {
            $payload['reply_to'] = $reply_to;
        }
        if ( $cc ) {
            $payload['cc'] = array_values( $cc );
        }
        if ( $bcc ) {
            $payload['bcc'] = array_values( $bcc );
        }
        if ( $attachments ) {
            $payload['attachments'] = array();
            foreach ( $attachments as $path ) {
                if ( is_readable( $path ) ) {
                    $payload['attachments'][] = array(
                        'filename' => basename( $path ),
                        'content'  => base64_encode( file_get_contents( $path ) ),
                    );
                }
            }
        }

        $response = wp_remote_post(
            'https://api.resend.com/emails',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( $payload ),
            )
        );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'SFCO_Pro_Log' ) ) {
                SFCO_Pro_Log::record( 'resend', 'error', 'Transport: ' . $response->get_error_message(), null, null, $payload );
            }
            return false;
        }
        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $body     = json_decode( $body_raw, true );
        $ok       = ( $code >= 200 && $code < 300 );
        if ( class_exists( 'SFCO_Pro_Log' ) ) {
            SFCO_Pro_Log::record( 'resend', $ok ? 'ok' : 'error', sprintf( 'HTTP %d → %s', $code, $ok ? ( $body['id'] ?? 'sent' ) : ( $body['message'] ?? $body_raw ) ), null, null, $payload, $body ?: $body_raw );
        }
        return $ok;
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
            <h1><?php esc_html_e( 'Email Transport — Resend', 'smart-forms-for-midland' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Route all WordPress emails through Resend for instant, reliable delivery. No more leads lost to spam.', 'smart-forms-for-midland' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-forms-for-midland' ); ?></p></div>
            <?php endif; ?>
            <?php if ( 'ok' === $test ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test email sent successfully via Resend.', 'smart-forms-for-midland' ); ?></p></div>
            <?php elseif ( 'fail' === $test ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Test email failed. Check your API key.', 'smart-forms-for-midland' ); ?></p></div>
            <?php elseif ( 'invalid' === $test ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Invalid test email address.', 'smart-forms-for-midland' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_resend', '_sfco_resend_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable Resend Transport', 'smart-forms-for-midland' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="resend_enabled" value="1" <?php checked( $enabled ); ?>>
                                <?php esc_html_e( 'Route all wp_mail through Resend SMTP', 'smart-forms-for-midland' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resend_api_key"><?php esc_html_e( 'Resend API Key', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <input type="password" id="resend_api_key" name="resend_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>">
                            <p class="description"><?php esc_html_e( 'Get your key at resend.com → API Keys. Starts with "re_".', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resend_from_name"><?php esc_html_e( 'From Name', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="resend_from_name" name="resend_from_name" class="regular-text" value="<?php echo esc_attr( $from_name ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="resend_from_email"><?php esc_html_e( 'From Email', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <input type="email" id="resend_from_email" name="resend_from_email" class="regular-text" value="<?php echo esc_attr( $from_email ); ?>">
                            <p class="description"><?php esc_html_e( 'Must be from a domain verified in your Resend account.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="sfco_save_resend" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'smart-forms-for-midland' ); ?></button>
                </p>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Send Test Email', 'smart-forms-for-midland' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'sfco_save_resend', '_sfco_resend_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="test_email_to"><?php esc_html_e( 'Send Test To', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="email" id="test_email_to" name="test_email_to" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="sfco_test_email" value="1" class="button"><?php esc_html_e( 'Send Test Email', 'smart-forms-for-midland' ); ?></button>
                </p>
            </form>

            <hr>
            <h3><?php esc_html_e( 'SMTP Details (for reference)', 'smart-forms-for-midland' ); ?></h3>
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
