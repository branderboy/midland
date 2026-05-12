<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Calendly {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 32 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'rest_api_init', array( $this, 'register_webhook' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'Calendar', 'smart-forms-pro' ),
            esc_html__( 'Calendar', 'smart-forms-pro' ),
            'manage_options',
            'sfco-calendar',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['sfco_save_calendly'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_cal_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_cal_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_calendly' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-pro' ) );
        }

        $api_key      = isset( $_POST['calendly_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['calendly_api_key'] ) ) : '';
        $signing_key  = isset( $_POST['calendly_signing_key'] ) ? sanitize_text_field( wp_unslash( $_POST['calendly_signing_key'] ) ) : '';
        $booking_url  = isset( $_POST['calendly_url'] ) ? esc_url_raw( wp_unslash( $_POST['calendly_url'] ) ) : '';
        $show_after   = isset( $_POST['calendly_show_after'] ) ? sanitize_key( $_POST['calendly_show_after'] ) : 'submission';
        $enabled      = isset( $_POST['calendly_enabled'] ) ? 1 : 0;

        update_option( 'sfco_pro_calendly_api_key', $api_key );
        update_option( 'sfco_pro_calendly_signing_key', $signing_key );
        update_option( 'sfco_pro_calendly_url', $booking_url );
        update_option( 'sfco_pro_calendly_show_after', $show_after );
        update_option( 'sfco_pro_calendly_enabled', $enabled );

        wp_safe_redirect( admin_url( 'admin.php?page=sfco-calendar&saved=1' ) );
        exit;
    }

    /**
     * Register REST endpoint for Calendly webhooks.
     */
    public function register_webhook() {
        register_rest_route( 'sfco-pro/v1', '/calendly/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => array( $this, 'verify_webhook_signature' ),
        ) );
    }

    /**
     * Verify Calendly's HMAC-SHA256 signature header.
     * Header format: Calendly-Webhook-Signature: t=<timestamp>,v1=<hmac>
     * Signature payload: "{timestamp}.{raw body}"
     * Tolerance: 5 minutes against replay.
     */
    public function verify_webhook_signature( $request ) {
        $signing_key = (string) get_option( 'sfco_pro_calendly_signing_key', '' );

        // No key configured: refuse the request so attackers can't forge events.
        if ( '' === $signing_key ) {
            return new WP_Error( 'sfco_calendly_no_key', __( 'Calendly signing key is not configured.', 'smart-forms-pro' ), array( 'status' => 401 ) );
        }

        $header = (string) $request->get_header( 'calendly_webhook_signature' );
        if ( '' === $header ) {
            return new WP_Error( 'sfco_calendly_missing_sig', __( 'Missing Calendly signature header.', 'smart-forms-pro' ), array( 'status' => 401 ) );
        }

        $parts = array();
        foreach ( explode( ',', $header ) as $piece ) {
            $kv = explode( '=', trim( $piece ), 2 );
            if ( 2 === count( $kv ) ) {
                $parts[ $kv[0] ] = $kv[1];
            }
        }

        $timestamp = isset( $parts['t'] ) ? (int) $parts['t'] : 0;
        $signature = $parts['v1'] ?? '';

        if ( ! $timestamp || ! $signature ) {
            return new WP_Error( 'sfco_calendly_bad_sig', __( 'Malformed Calendly signature.', 'smart-forms-pro' ), array( 'status' => 401 ) );
        }

        // Reject anything older than 5 minutes (replay protection).
        if ( abs( time() - $timestamp ) > 300 ) {
            return new WP_Error( 'sfco_calendly_stale', __( 'Calendly webhook timestamp out of tolerance.', 'smart-forms-pro' ), array( 'status' => 401 ) );
        }

        $payload = $request->get_body();
        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $signing_key );

        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'sfco_calendly_invalid_sig', __( 'Calendly signature mismatch.', 'smart-forms-pro' ), array( 'status' => 401 ) );
        }

        return true;
    }

    public function handle_webhook( $request ) {
        $body  = $request->get_json_params();
        $event = $body['event'] ?? '';

        if ( 'invitee.created' === $event ) {
            $payload = $body['payload'] ?? array();
            $email   = $payload['email'] ?? '';
            $name    = $payload['name'] ?? '';
            $time    = $payload['scheduled_event']['start_time'] ?? '';

            if ( $email ) {
                // Try to match to a lead.
                global $wpdb;
                $lead = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sfco_leads WHERE customer_email = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $email
                ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

                if ( $lead ) {
                    $wpdb->update(
                        $wpdb->prefix . 'sfco_leads',
                        array( 'status' => 'contacted' ),
                        array( 'id' => $lead->id ),
                        array( '%s' ),
                        array( '%d' )
                    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

                    // Notify admin.
                    $admin_email = get_option( 'admin_email' );
                    wp_mail(
                        $admin_email,
                        sprintf( __( 'Booking: %s scheduled via Calendly', 'smart-forms-pro' ), $name ),
                        sprintf( __( "%s booked an appointment.\n\nTime: %s\nEmail: %s\n\nLead has been marked as Contacted.", 'smart-forms-pro' ), $name, $time, $email )
                    );
                }
            }
        }

        return new WP_REST_Response( array( 'received' => true ), 200 );
    }

    /**
     * Get the Calendly booking URL to show after form submission.
     */
    public static function get_booking_url() {
        $enabled = get_option( 'sfco_pro_calendly_enabled', 0 );
        if ( ! $enabled ) {
            return '';
        }
        return get_option( 'sfco_pro_calendly_url', '' );
    }

    public function render_page() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>' . esc_html__( 'Please activate your PRO license.', 'smart-forms-pro' ) . '</p></div></div>';
            return;
        }

        $api_key     = get_option( 'sfco_pro_calendly_api_key', '' );
        $signing_key = get_option( 'sfco_pro_calendly_signing_key', '' );
        $booking_url = get_option( 'sfco_pro_calendly_url', '' );
        $show_after  = get_option( 'sfco_pro_calendly_show_after', 'submission' );
        $enabled     = get_option( 'sfco_pro_calendly_enabled', 0 );
        $webhook_url = rest_url( 'sfco-pro/v1/calendly/webhook' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Calendar / Calendly Integration', 'smart-forms-pro' ); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Calendar settings saved.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_calendly', '_sfco_cal_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable', 'smart-forms-pro' ); ?></th>
                        <td><label><input type="checkbox" name="calendly_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Enable Calendly booking after form submission', 'smart-forms-pro' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="calendly_url"><?php esc_html_e( 'Calendly URL', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="url" name="calendly_url" id="calendly_url" class="regular-text" value="<?php echo esc_attr( $booking_url ); ?>" placeholder="https://calendly.com/your-company/30min">
                            <p class="description"><?php esc_html_e( 'Your Calendly scheduling page URL.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="calendly_api_key"><?php esc_html_e( 'API Key (optional)', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="password" name="calendly_api_key" id="calendly_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>">
                            <p class="description"><?php esc_html_e( 'For webhook integration. Get yours at calendly.com/integrations.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="calendly_show_after"><?php esc_html_e( 'Show Booking', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <select name="calendly_show_after" id="calendly_show_after">
                                <option value="submission" <?php selected( $show_after, 'submission' ); ?>><?php esc_html_e( 'After form submission (in success message)', 'smart-forms-pro' ); ?></option>
                                <option value="email" <?php selected( $show_after, 'email' ); ?>><?php esc_html_e( 'In follow-up email only', 'smart-forms-pro' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Webhook URL', 'smart-forms-pro' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $webhook_url ); ?></code>
                            <p class="description"><?php esc_html_e( 'Add this URL in your Calendly webhook settings to auto-update lead status when appointments are booked.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="calendly_signing_key"><?php esc_html_e( 'Webhook Signing Key', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="password" name="calendly_signing_key" id="calendly_signing_key" class="regular-text" value="<?php echo esc_attr( $signing_key ); ?>">
                            <p class="description"><?php esc_html_e( 'Required. Calendly returns this when you create the webhook subscription. Without it, the webhook endpoint rejects every request.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="sfco_save_calendly" value="1" class="button button-primary"><?php esc_html_e( 'Save Calendar Settings', 'smart-forms-pro' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}

new SFCO_Pro_Calendly();
