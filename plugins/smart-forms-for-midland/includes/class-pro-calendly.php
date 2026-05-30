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
            null,
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

        // We only act on a confirmed booking. invitee.canceled could later
        // flip the lead back, but that's a separate flow.
        if ( 'invitee.created' !== $event ) {
            return new WP_REST_Response( array( 'received' => true, 'action' => 'ignored' ), 200 );
        }

        $payload = $body['payload'] ?? array();
        $email   = sanitize_email( (string) ( $payload['email'] ?? '' ) );
        $name    = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
        $time    = (string) ( $payload['scheduled_event']['start_time'] ?? '' );

        $lead = $this->match_lead( $payload, $email );
        if ( ! $lead ) {
            return new WP_REST_Response( array(
                'received' => true,
                'action'   => 'no_match',
                'note'     => 'No wp_sfco_leads row matched the booking (utm_content / email).',
            ), 200 );
        }

        $result = $this->mark_lead_booked( $lead, $time, $name, $email );

        return new WP_REST_Response( array(
            'received' => true,
            'action'   => $result,
            'lead_id'  => (int) $lead->id,
        ), 200 );
    }

    /**
     * Resolve the Smart Forms lead a Calendly booking belongs to.
     *
     * Primary match: the utm_content tracking param we stamp on every
     * decorated booking link (LEAD_<id>) — an exact 1:1 link back to the
     * originating lead, immune to the "visitor typed a different email into
     * Calendly" problem. Falls back to the most recent lead with the booking
     * email when no tracking is present (e.g. an org-wide Calendly link that
     * wasn't decorated).
     *
     * @param array  $payload Calendly invitee.created payload.
     * @param string $email   Sanitized invitee email.
     * @return object|null wp_sfco_leads row or null.
     */
    private function match_lead( $payload, $email ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';

        $tracking    = ( isset( $payload['tracking'] ) && is_array( $payload['tracking'] ) ) ? $payload['tracking'] : array();
        $utm_content = (string) ( $tracking['utm_content'] ?? '' );
        if ( '' === $utm_content ) {
            // Some setups surface the param on the invitee object instead.
            $utm_content = (string) ( $payload['utm_content'] ?? '' );
        }

        if ( preg_match( '/LEAD[_-](\d+)/i', $utm_content, $m ) ) {
            $lead = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE id = %d",
                (int) $m[1]
            ) );
            if ( $lead ) {
                return $lead;
            }
        }

        if ( '' !== $email ) {
            return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE customer_email = %s ORDER BY id DESC LIMIT 1",
                $email
            ) );
        }

        return null;
    }

    /**
     * Flip a lead to "booked", notify the operator, and fan out the
     * sfco_lead_booked action so the rest of the stack reacts:
     *   - Smart CRM → ActiveCampaign tags it midland-job-booked + advances
     *     the AC deal to the Booked stage (this is the booked CONVERSION).
     *   - Smart CRM → ServiceM8 creates the job from the lead.
     *
     * Deduped/guarded so a re-delivered webhook (or a completed job) can't
     * regress the status. A completed lead is left as completed.
     *
     * @param object $lead  wp_sfco_leads row.
     * @param string $time  Scheduled start_time (RFC3339) for the admin email.
     * @param string $name  Invitee name for the admin email.
     * @param string $email Invitee email for the admin email.
     * @return string 'marked_booked' | 'already_booked' | 'already_completed'
     */
    private function mark_lead_booked( $lead, $time = '', $name = '', $email = '' ) {
        $current = strtolower( (string) ( $lead->status ?? '' ) );
        if ( 'completed' === $current ) {
            return 'already_completed';
        }
        if ( 'booked' === $current ) {
            return 'already_booked';
        }

        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'sfco_leads',
            array( 'status' => 'booked' ),
            array( 'id' => (int) $lead->id ),
            array( '%s' ),
            array( '%d' )
        );
        $lead->status = 'booked';

        // Notify the operator.
        $admin_email = get_option( 'admin_email' );
        wp_mail(
            $admin_email,
            /* translators: %s: invitee name */
            sprintf( __( 'Booking: %s scheduled via Calendly', 'smart-forms-pro' ), $name ?: $email ),
            sprintf(
                /* translators: 1: name, 2: time, 3: email */
                __( "%1\$s booked an appointment.\n\nTime: %2\$s\nEmail: %3\$s\n\nLead has been marked as Booked, pushed to ServiceM8, and tagged in ActiveCampaign.", 'smart-forms-pro' ),
                $name,
                $time,
                $email
            )
        );

        /**
         * The booked-conversion signal. Smart CRM Pro hooks this to push the
         * lead into ServiceM8 (create the job) and to ActiveCampaign (booked
         * tag + deal-stage advance). Passing the lead row by handle means
         * listeners on the same tick see status=booked.
         *
         * @param object $lead wp_sfco_leads row, status already = booked.
         */
        do_action( 'sfco_lead_booked', $lead );

        return 'marked_booked';
    }

    /**
     * Get the global Calendly booking URL (when enabled). Returns '' when the
     * integration is off so callers can fall back to a per-form link.
     */
    public static function get_booking_url() {
        $enabled = get_option( 'sfco_pro_calendly_enabled', 0 );
        if ( ! $enabled ) {
            return '';
        }
        return (string) get_option( 'sfco_pro_calendly_url', '' );
    }

    /**
     * Append lead identity + tracking to a Calendly scheduling URL so a
     * booking made from this link maps back to the exact lead.
     *
     * - name / email prefill Calendly's form (less typing for the customer).
     * - utm_content=LEAD_<id> is echoed back in the invitee.created webhook
     *   payload's tracking object, giving match_lead() an exact 1:1 link
     *   that survives the customer entering a different email in Calendly.
     *
     * Only decorates Calendly URLs; anything else is returned untouched so a
     * non-Calendly redirect target isn't polluted with params.
     *
     * @param string $url     Booking URL.
     * @param int    $lead_id Smart Forms lead row ID.
     * @param string $name    Optional customer name to prefill.
     * @param string $email   Optional customer email to prefill.
     * @return string Decorated URL (or original when not a Calendly link / no lead).
     */
    public static function decorate_booking_url( $url, $lead_id, $name = '', $email = '' ) {
        $url     = (string) $url;
        $lead_id = (int) $lead_id;
        if ( '' === $url || $lead_id <= 0 ) {
            return $url;
        }
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( false === stripos( $host, 'calendly.com' ) ) {
            return $url;
        }

        // add_query_arg() URL-encodes the values itself — pass them raw.
        $args = array( 'utm_source' => 'midland', 'utm_content' => 'LEAD_' . $lead_id );
        if ( '' !== $name ) {
            $args['name'] = $name;
        }
        if ( '' !== $email ) {
            $args['email'] = $email;
        }
        return add_query_arg( $args, $url );
    }

    public function render_page() {
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
