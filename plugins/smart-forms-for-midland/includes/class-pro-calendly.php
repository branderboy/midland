<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Calendly {

    /**
     * Legacy one-time cron hook. Calendly no longer auto-resolves visits (see
     * the constructor note); this constant is kept only so unschedule_completion()
     * can clear any events older installs left scheduled.
     */
    const CRON_COMPLETE = 'sfco_pro_calendly_complete';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 32 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_init', array( $this, 'handle_connect' ) );
        add_action( 'rest_api_init', array( $this, 'register_webhook' ) );
        // Calendly's only job is to record that the service was SCHEDULED:
        // invitee.created marks the lead Booked, invitee.canceled reverses it.
        // It deliberately does NOT resolve or complete the visit — a calendar
        // slot passing is not proof the paid service was rendered. ServiceM8 is
        // the sole source of completion (scrm_pro_job_completed → review survey
        // + floor-care plan + AC completed flow), so no auto-completion cron is
        // registered here.
    }

    public function add_menu() {
        // Parent under the Smart Forms top-level menu so the API Key / Connect /
        // signing-key fields are actually reachable. Previously this used a null
        // parent, leaving the only Calendly-credentials screen invisible — the
        // page existed but had no menu entry, so operators could not find where
        // to paste their token.
        add_submenu_page(
            'smart-forms',
            esc_html__( 'Calendly', 'smart-forms-for-midland' ),
            esc_html__( 'Calendly', 'smart-forms-for-midland' ),
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
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-for-midland' ) );
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
     * One-click "Connect Calendly" — uses the saved Personal Access Token to:
     *   1. Resolve the current user + their organization URI (/users/me).
     *   2. Create an organization-scoped webhook subscription pointed at our
     *      REST endpoint, subscribed to invitee.created (+ invitee.canceled
     *      for future use), with a generated signing key.
     *   3. Persist that signing key so verify_webhook_signature() works.
     *
     * Calendly has no dashboard UI for webhook creation — it's API-only — so
     * this is the only way to wire the inbound side without curl gymnastics.
     */
    public function handle_connect() {
        if ( ! isset( $_POST['sfco_connect_calendly'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_sfco_cal_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_cal_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_calendly' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-for-midland' ) );
        }

        // Persist whatever the operator typed into the API key field first so
        // they don't have to "Save" before "Connect".
        if ( isset( $_POST['calendly_api_key'] ) ) {
            update_option( 'sfco_pro_calendly_api_key', sanitize_text_field( wp_unslash( $_POST['calendly_api_key'] ) ) );
        }

        $token = (string) get_option( 'sfco_pro_calendly_api_key', '' );
        if ( '' === $token ) {
            $this->connect_redirect( 'fail', __( 'Add your Calendly API key first.', 'smart-forms-for-midland' ) );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        );

        // 1. Who am I → organization URI.
        $me = wp_remote_get( 'https://api.calendly.com/users/me', array( 'headers' => $headers, 'timeout' => 15 ) );
        if ( is_wp_error( $me ) ) {
            $this->connect_redirect( 'fail', $me->get_error_message() );
        }
        $me_code = (int) wp_remote_retrieve_response_code( $me );
        $me_body = json_decode( (string) wp_remote_retrieve_body( $me ), true );
        if ( 200 !== $me_code || empty( $me_body['resource']['current_organization'] ) ) {
            $msg = $me_body['message'] ?? sprintf( __( 'Calendly rejected the API key (HTTP %d).', 'smart-forms-for-midland' ), $me_code );
            $this->connect_redirect( 'fail', $msg );
        }
        $org_uri = (string) $me_body['resource']['current_organization'];

        // 2. Create an org-scoped webhook subscription with a generated
        //    signing key (used to HMAC-verify every inbound event).
        $signing_key = wp_generate_password( 40, false );
        $payload = array(
            'url'          => rest_url( 'sfco-pro/v1/calendly/webhook' ),
            'events'       => array( 'invitee.created', 'invitee.canceled' ),
            'organization' => $org_uri,
            'scope'        => 'organization',
            'signing_key'  => $signing_key,
        );

        $create = wp_remote_post( 'https://api.calendly.com/webhook_subscriptions', array(
            'headers' => $headers,
            'timeout' => 20,
            'body'    => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $create ) ) {
            $this->connect_redirect( 'fail', $create->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $create );
        $body = json_decode( (string) wp_remote_retrieve_body( $create ), true );

        // 201 = created. 409 = a subscription for this URL already exists —
        // treat as success but we can't recover the original signing key, so
        // only overwrite ours when we actually created a new one.
        if ( 201 === $code && ! empty( $body['resource']['uri'] ) ) {
            update_option( 'sfco_pro_calendly_signing_key', $signing_key );
            update_option( 'sfco_pro_calendly_webhook_uri', (string) $body['resource']['uri'] );
            update_option( 'sfco_pro_calendly_enabled', 1 );
            $this->connect_redirect( 'ok', '' );
        }

        if ( 409 === $code ) {
            $this->connect_redirect( 'exists', __( 'A webhook for this site already exists in Calendly. If bookings are not arriving, delete it in Calendly (API) and reconnect so a fresh signing key can be stored.', 'smart-forms-for-midland' ) );
        }

        $msg = '';
        if ( is_array( $body ) ) {
            $msg = (string) ( $body['message'] ?? '' );
            if ( '' === $msg && ! empty( $body['details'][0]['message'] ) ) {
                $msg = (string) $body['details'][0]['message'];
            }
        }
        $this->connect_redirect( 'fail', $msg ?: sprintf( __( 'Calendly returned HTTP %d.', 'smart-forms-for-midland' ), $code ) );
    }

    private function connect_redirect( $status, $message ) {
        $args = array( 'page' => 'sfco-calendar', 'connect' => $status );
        if ( '' !== $message ) {
            $args['connect_msg'] = rawurlencode( $message );
        }
        wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $args ) ) );
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
            return new WP_Error( 'sfco_calendly_no_key', __( 'Calendly signing key is not configured.', 'smart-forms-for-midland' ), array( 'status' => 401 ) );
        }

        $header = (string) $request->get_header( 'calendly_webhook_signature' );
        if ( '' === $header ) {
            return new WP_Error( 'sfco_calendly_missing_sig', __( 'Missing Calendly signature header.', 'smart-forms-for-midland' ), array( 'status' => 401 ) );
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
            return new WP_Error( 'sfco_calendly_bad_sig', __( 'Malformed Calendly signature.', 'smart-forms-for-midland' ), array( 'status' => 401 ) );
        }

        // Reject anything older than 5 minutes (replay protection).
        if ( abs( time() - $timestamp ) > 300 ) {
            return new WP_Error( 'sfco_calendly_stale', __( 'Calendly webhook timestamp out of tolerance.', 'smart-forms-for-midland' ), array( 'status' => 401 ) );
        }

        $payload = $request->get_body();
        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $signing_key );

        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'sfco_calendly_invalid_sig', __( 'Calendly signature mismatch.', 'smart-forms-for-midland' ), array( 'status' => 401 ) );
        }

        return true;
    }

    public function handle_webhook( $request ) {
        $body  = $request->get_json_params();
        $event = $body['event'] ?? '';

        // Act on a confirmed booking or a cancellation; ignore everything else.
        if ( 'invitee.created' !== $event && 'invitee.canceled' !== $event ) {
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

        if ( 'invitee.canceled' === $event ) {
            $reason = sanitize_text_field( (string) ( $payload['cancellation']['reason'] ?? '' ) );
            $result = $this->mark_lead_canceled( $lead, $name, $email, $reason );
        } else {
            $result = $this->mark_lead_booked( $lead, $time, $name, $email );
        }

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

        // A canceled lead that books again is a re-book: it falls through here
        // and re-fires the booked conversion below. We flag it so AC can clear
        // the stale midland-job-canceled tag rather than leave it stuck on the
        // contact alongside the fresh booked tag.
        $is_rebook = ( 'canceled' === $current );

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
            sprintf( __( 'Booking: %s scheduled via Calendly', 'smart-forms-for-midland' ), $name ?: $email ),
            sprintf(
                /* translators: 1: name, 2: time, 3: email */
                __( "%1\$s booked an appointment.\n\nTime: %2\$s\nEmail: %3\$s\n\nLead has been marked as Booked, pushed to ServiceM8, and tagged in ActiveCampaign.", 'smart-forms-for-midland' ),
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
         * @param object $lead      wp_sfco_leads row, status already = booked.
         * @param bool   $is_rebook True when a previously-canceled lead booked
         *                          again — listeners should clear stale
         *                          cancellation state.
         */
        do_action( 'sfco_lead_booked', $lead, $is_rebook );

        // Calendly's responsibility ends here: the lead is Booked, i.e. the
        // service is scheduled. We do NOT schedule any auto-completion — whether
        // the job was actually done (and paid) is reported by ServiceM8, which
        // owns the completion signal.
        return $is_rebook ? 'marked_rebooked' : 'marked_booked';
    }

    /**
     * Flip a booked lead back to "canceled" when the invitee cancels in
     * Calendly, and fan out sfco_lead_canceled so Smart CRM can remove the
     * booked tag / reverse the AC deal stage.
     *
     * Only a currently-booked lead is reversed — we never overwrite a
     * completed job (the work was already done) or re-cancel an already
     * canceled lead. This keeps the booked-conversion count honest: a
     * cancellation undoes the conversion rather than leaving a phantom.
     *
     * @param object $lead   wp_sfco_leads row.
     * @param string $name   Invitee name for the admin email.
     * @param string $email  Invitee email for the admin email.
     * @param string $reason Cancellation reason, if Calendly supplied one.
     * @return string 'marked_canceled' | 'not_booked' | 'already_completed'
     */
    private function mark_lead_canceled( $lead, $name = '', $email = '', $reason = '' ) {
        $current = strtolower( (string) ( $lead->status ?? '' ) );
        if ( 'completed' === $current ) {
            return 'already_completed';
        }
        if ( 'booked' !== $current ) {
            // Nothing to reverse — the lead was never marked booked.
            return 'not_booked';
        }

        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'sfco_leads',
            array( 'status' => 'canceled' ),
            array( 'id' => (int) $lead->id ),
            array( '%s' ),
            array( '%d' )
        );
        $lead->status = 'canceled';

        // Drop the pending auto-completion — the visit is no longer happening.
        $this->unschedule_completion( (int) $lead->id );

        // Notify the operator.
        $admin_email = get_option( 'admin_email' );
        wp_mail(
            $admin_email,
            /* translators: %s: invitee name */
            sprintf( __( 'Canceled: %s canceled their Calendly booking', 'smart-forms-for-midland' ), $name ?: $email ),
            sprintf(
                /* translators: 1: name, 2: email, 3: reason */
                __( "%1\$s canceled their appointment.\n\nEmail: %2\$s\nReason: %3\$s\n\nLead has been marked as Canceled and the booked tag/deal stage has been reversed in ActiveCampaign.", 'smart-forms-for-midland' ),
                $name,
                $email,
                $reason ?: __( '(none given)', 'smart-forms-for-midland' )
            )
        );

        /**
         * Cancellation signal — Smart CRM Pro hooks this to apply the
         * canceled tag in ActiveCampaign and move the deal off the Booked
         * stage. Lead row passed by handle with status already = canceled.
         *
         * @param object $lead   wp_sfco_leads row, status already = canceled.
         * @param string $reason Cancellation reason (may be empty).
         */
        do_action( 'sfco_lead_canceled', $lead, $reason );

        return 'marked_canceled';
    }

    /**
     * Clear any pending auto-completion event for a lead. Calendly no longer
     * schedules these, but older versions did — so on cancellation (and as a
     * general safety) we still sweep out any single-event an earlier build left
     * queued, otherwise it could fire and flip a lead's status unexpectedly.
     */
    private function unschedule_completion( $lead_id ) {
        $args = array( (int) $lead_id );
        $ts   = wp_next_scheduled( self::CRON_COMPLETE, $args );
        while ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_COMPLETE, $args );
            $ts = wp_next_scheduled( self::CRON_COMPLETE, $args );
        }
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
            <h1><?php esc_html_e( 'Calendly Integration', 'smart-forms-for-midland' ); ?></h1>

            <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Calendly settings saved.', 'smart-forms-for-midland' ); ?></p></div>
            <?php endif; ?>
            <?php
            $connect     = isset( $_GET['connect'] ) ? sanitize_key( $_GET['connect'] ) : '';
            $connect_msg = isset( $_GET['connect_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['connect_msg'] ) ) : '';
            ?>
            <?php if ( 'ok' === $connect ) : ?>
                <div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Connected to Calendly.', 'smart-forms-for-midland' ); ?></strong> <?php esc_html_e( 'The webhook subscription was created and the signing key was stored automatically. Bookings will now mark leads as Booked, push to ServiceM8, and tag in ActiveCampaign.', 'smart-forms-for-midland' ); ?></p></div>
            <?php elseif ( 'exists' === $connect ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php echo esc_html( $connect_msg ); ?></p></div>
            <?php elseif ( 'fail' === $connect ) : ?>
                <div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Calendly connection failed.', 'smart-forms-for-midland' ); ?></strong> <?php echo esc_html( $connect_msg ); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

            <?php
            // Cross-plugin heads-up: Midland Chat owns a SEPARATE Calendly
            // connection (its own API key, signing key, and webhook). If Smart
            // Forms is connected here but the chat is active and still
            // unconnected, flag it so chat bookings don't silently miss the CRM.
            if (
                '' !== (string) $signing_key
                && class_exists( 'SCAI_Calendly' )
                && '' === (string) get_option( 'smart_chat_calendly_signing_key', '' )
            ) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e( 'Heads up: Midland Chat is active but its Calendly connection is separate from this one and is not connected yet. Connect Calendly in the chat settings so chat bookings also reach the CRM.', 'smart-forms-for-midland' ); ?></p></div>
            <?php endif; ?>

            <?php $is_connected = '' !== (string) get_option( 'sfco_pro_calendly_signing_key', '' ); ?>
            <p>
                <?php if ( $is_connected ) : ?>
                    <span style="display:inline-block;padding:4px 10px;background:#dcfce7;color:#166534;border-radius:3px;font-weight:600;">✓ <?php esc_html_e( 'Webhook connected', 'smart-forms-for-midland' ); ?></span>
                <?php else : ?>
                    <span style="display:inline-block;padding:4px 10px;background:#fef3c7;color:#92400e;border-radius:3px;font-weight:600;"><?php esc_html_e( 'Webhook not connected — add your API key and click Connect Calendly', 'smart-forms-for-midland' ); ?></span>
                <?php endif; ?>
            </p>

            <?php
            $crm_on     = defined( 'SCRM_PRO_VERSION' );
            $reviews_on = defined( 'SRP_VERSION' );
            $badge      = function ( $on ) {
                return $on
                    ? '<span style="display:inline-block;min-width:18px;color:#166534;font-weight:700;">&#10003;</span>'
                    : '<span style="display:inline-block;min-width:18px;color:#b32d2e;font-weight:700;">&#10005;</span>';
            };
            ?>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px 18px;margin:0 0 20px;max-width:760px;">
                <strong><?php esc_html_e( 'Booking → completion flow', 'smart-forms-for-midland' ); ?></strong>
                <ul style="margin:8px 0 0;">
                    <li><?php echo $badge( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Calendly records that the service was scheduled: a booking marks the lead Booked (pushed to ServiceM8 + tagged in ActiveCampaign); a cancellation reverses it.', 'smart-forms-for-midland' ); ?></li>
                    <li><?php echo $badge( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Calendly does NOT complete the job — a calendar slot passing is not proof the work was done. The lead stays Booked until ServiceM8 reports it.', 'smart-forms-for-midland' ); ?></li>
                    <li><?php echo $badge( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'ServiceM8 closing the job is the sole completion signal → Completed → review survey + floor-care plan + AC completed flow.', 'smart-forms-for-midland' ); ?></li>
                    <li><?php echo $badge( $crm_on ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <strong>Smart CRM for Midland</strong> — <?php echo $crm_on ? esc_html__( 'connected: drives the ServiceM8 sync and the segment/urgency tags.', 'smart-forms-for-midland' ) : esc_html__( 'not active — only the Booked/Canceled status is recorded.', 'smart-forms-for-midland' ); ?></li>
                </ul>
            </div>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_calendly', '_sfco_cal_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable', 'smart-forms-for-midland' ); ?></th>
                        <td><label><input type="checkbox" name="calendly_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Enable Calendly booking after form submission', 'smart-forms-for-midland' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="calendly_url"><?php esc_html_e( 'Calendly URL', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <input type="url" name="calendly_url" id="calendly_url" class="regular-text" value="<?php echo esc_attr( $booking_url ); ?>" placeholder="https://calendly.com/your-company/30min">
                            <p class="description"><?php esc_html_e( 'Your Calendly scheduling page URL.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="calendly_api_key"><?php esc_html_e( 'API Key', 'smart-forms-for-midland' ); ?> <span style="color:#b32d2e;">*</span></label></th>
                        <td>
                            <input type="password" name="calendly_api_key" id="calendly_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'Your Calendly Personal Access Token (Calendly → Integrations → API & Webhooks). Used to create the webhook subscription automatically — click "Connect Calendly" below after pasting it.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="calendly_show_after"><?php esc_html_e( 'Show Booking', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <select name="calendly_show_after" id="calendly_show_after">
                                <option value="submission" <?php selected( $show_after, 'submission' ); ?>><?php esc_html_e( 'After form submission (in success message)', 'smart-forms-for-midland' ); ?></option>
                                <option value="email" <?php selected( $show_after, 'email' ); ?>><?php esc_html_e( 'In follow-up email only', 'smart-forms-for-midland' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Webhook URL', 'smart-forms-for-midland' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $webhook_url ); ?></code>
                            <p class="description"><?php esc_html_e( 'Add this URL in your Calendly webhook settings to auto-update lead status when appointments are booked.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="calendly_signing_key"><?php esc_html_e( 'Webhook Signing Key', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <input type="password" name="calendly_signing_key" id="calendly_signing_key" class="regular-text" value="<?php echo esc_attr( $signing_key ); ?>">
                            <p class="description"><?php esc_html_e( 'Filled in automatically when you click "Connect Calendly". Without it, the webhook endpoint rejects every request. Only edit this if you created the webhook subscription manually.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="sfco_connect_calendly" value="1" class="button button-primary"><?php esc_html_e( 'Connect Calendly', 'smart-forms-for-midland' ); ?></button>
                    <button type="submit" name="sfco_save_calendly" value="1" class="button" style="margin-left:8px;"><?php esc_html_e( 'Save Calendly Settings', 'smart-forms-for-midland' ); ?></button>
                </p>
                <p class="description" style="max-width:640px;"><?php esc_html_e( '"Connect Calendly" uses your API key to create the booking webhook and store its signing key for you (Calendly has no dashboard UI for this). "Save Calendly Settings" just stores the fields above without touching Calendly.', 'smart-forms-for-midland' ); ?></p>
            </form>
        </div>
        <?php
    }
}

new SFCO_Pro_Calendly();
