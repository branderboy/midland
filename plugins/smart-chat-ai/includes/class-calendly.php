<?php
/**
 * Chat-owned Calendly connection.
 *
 * The chat is the form: it captures a name + email free-hand in conversation,
 * then hands the visitor a Calendly link. This class owns that Calendly
 * integration end to end, with NO dependency on the Smart Forms plugin:
 *
 *   - Its own API key + one-click Connect, which creates a Calendly webhook
 *     subscription pointed at THIS plugin's REST endpoint.
 *   - Its own signing key + signature verification.
 *   - Its own webhook handler: invitee.created marks the captured chat lead
 *     Booked and fires scai_lead_booked; invitee.canceled reverses it and fires
 *     scai_lead_canceled. Smart CRM listens to those chat events to tag the
 *     contact in ActiveCampaign — so a chat booking is tagged in the CRM
 *     without Smart Forms being in the loop at all.
 *
 * Options (all chat-namespaced):
 *   smart_chat_booking_url           Calendly scheduling URL (Settings field).
 *   smart_chat_calendly_api_key      Calendly Personal Access Token.
 *   smart_chat_calendly_signing_key  HMAC key, written by Connect.
 *   smart_chat_calendly_webhook_uri  Calendly subscription URI, written by Connect.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCAI_Calendly {

    const REST_NS    = 'scai/v1';
    const REST_ROUTE = '/calendly/webhook';

    public function __construct() {
        add_action( 'admin_init',    array( $this, 'handle_connect' ) );
        add_action( 'rest_api_init', array( $this, 'register_webhook' ) );
    }

    /**
     * The chat's Calendly booking URL (the Settings field). '' when unset.
     */
    public static function get_booking_url() {
        return (string) get_option( 'smart_chat_booking_url', '' );
    }

    /**
     * True once Connect has stored a signing key + webhook subscription, i.e.
     * bookings will actually reach the chat (and therefore the CRM).
     */
    public static function is_connected() {
        return '' !== (string) get_option( 'smart_chat_calendly_signing_key', '' )
            && '' !== (string) get_option( 'smart_chat_calendly_webhook_uri', '' );
    }

    /**
     * Stamp a Calendly scheduling URL with the CHAT lead id (and prefill) so a
     * booking made from it maps back to the exact captured chat lead.
     *
     * utm_content=LEAD_<chat_lead_id> is echoed back in the invitee.created
     * payload's tracking object, giving match_lead() a 1:1 link that survives
     * the visitor typing a different email into Calendly. Only Calendly URLs are
     * decorated; anything else is returned untouched.
     */
    public static function decorate_booking_url( $url, $chat_lead_id, $name = '', $email = '' ) {
        $url          = (string) $url;
        $chat_lead_id = (int) $chat_lead_id;
        if ( '' === $url || $chat_lead_id <= 0 ) {
            return $url;
        }
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( false === stripos( (string) $host, 'calendly.com' ) ) {
            return $url;
        }
        $args = array( 'utm_source' => 'midland-chat', 'utm_content' => 'LEAD_' . $chat_lead_id );
        if ( '' !== $name ) {
            $args['name'] = $name;
        }
        if ( '' !== $email ) {
            $args['email'] = $email;
        }
        return add_query_arg( $args, $url );
    }

    /**
     * One-click Connect: use the saved token to resolve the org and create a
     * webhook subscription pointed at this plugin's endpoint, storing the
     * signing key so verify_webhook_signature() works. Mirrors the proven
     * Smart Forms flow but writes only chat-namespaced options.
     */
    public function handle_connect() {
        if ( ! isset( $_POST['scai_connect_calendly'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scai_cal_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scai_cal_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scai_connect_calendly' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-chat-ai' ) );
        }

        // Persist whatever was typed into the key field first.
        if ( isset( $_POST['smart_chat_calendly_api_key'] ) ) {
            update_option( 'smart_chat_calendly_api_key', sanitize_text_field( wp_unslash( $_POST['smart_chat_calendly_api_key'] ) ) );
        }
        if ( isset( $_POST['smart_chat_booking_url'] ) ) {
            update_option( 'smart_chat_booking_url', esc_url_raw( wp_unslash( $_POST['smart_chat_booking_url'] ) ) );
        }

        $token = (string) get_option( 'smart_chat_calendly_api_key', '' );
        if ( '' === $token ) {
            $this->connect_redirect( 'fail', __( 'Add your Calendly API key first.', 'smart-chat-ai' ) );
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
            /* translators: %d: HTTP status code */
            $msg = $me_body['message'] ?? sprintf( __( 'Calendly rejected the API key (HTTP %d).', 'smart-chat-ai' ), $me_code );
            $this->connect_redirect( 'fail', $msg );
        }
        $org_uri = (string) $me_body['resource']['current_organization'];

        // 2. Create an org-scoped webhook subscription with a generated signing key.
        $signing_key = wp_generate_password( 40, false );
        $payload = array(
            'url'          => rest_url( self::REST_NS . self::REST_ROUTE ),
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

        if ( 201 === $code && ! empty( $body['resource']['uri'] ) ) {
            update_option( 'smart_chat_calendly_signing_key', $signing_key );
            update_option( 'smart_chat_calendly_webhook_uri', (string) $body['resource']['uri'] );
            $this->connect_redirect( 'ok', '' );
        }

        if ( 409 === $code ) {
            $this->connect_redirect( 'exists', __( 'A webhook for this site already exists in Calendly. If bookings are not arriving, delete it in Calendly and reconnect so a fresh signing key can be stored.', 'smart-chat-ai' ) );
        }

        $msg = '';
        if ( is_array( $body ) ) {
            $msg = (string) ( $body['message'] ?? '' );
            if ( '' === $msg && ! empty( $body['details'][0]['message'] ) ) {
                $msg = (string) $body['details'][0]['message'];
            }
        }
        /* translators: %d: HTTP status code */
        $this->connect_redirect( 'fail', $msg ?: sprintf( __( 'Calendly returned HTTP %d.', 'smart-chat-ai' ), $code ) );
    }

    private function connect_redirect( $status, $message ) {
        $args = array( 'page' => 'smart-chat-settings', 'scai_connect' => $status );
        if ( '' !== $message ) {
            $args['scai_connect_msg'] = rawurlencode( $message );
        }
        wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $args ) ) );
        exit;
    }

    public function register_webhook() {
        register_rest_route( self::REST_NS, self::REST_ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => array( $this, 'verify_webhook_signature' ),
        ) );
    }

    /**
     * Verify Calendly's HMAC-SHA256 signature.
     * Header: Calendly-Webhook-Signature: t=<timestamp>,v1=<hmac>
     * Signed payload: "{timestamp}.{raw body}". 5-minute replay tolerance.
     */
    public function verify_webhook_signature( $request ) {
        $signing_key = (string) get_option( 'smart_chat_calendly_signing_key', '' );
        if ( '' === $signing_key ) {
            return new WP_Error( 'scai_calendly_no_key', __( 'Calendly signing key is not configured.', 'smart-chat-ai' ), array( 'status' => 401 ) );
        }

        $header = (string) $request->get_header( 'calendly_webhook_signature' );
        if ( '' === $header ) {
            return new WP_Error( 'scai_calendly_missing_sig', __( 'Missing Calendly signature header.', 'smart-chat-ai' ), array( 'status' => 401 ) );
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
            return new WP_Error( 'scai_calendly_bad_sig', __( 'Malformed Calendly signature.', 'smart-chat-ai' ), array( 'status' => 401 ) );
        }
        if ( abs( time() - $timestamp ) > 300 ) {
            return new WP_Error( 'scai_calendly_stale', __( 'Calendly webhook timestamp out of tolerance.', 'smart-chat-ai' ), array( 'status' => 401 ) );
        }

        $expected = hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $signing_key );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'scai_calendly_invalid_sig', __( 'Calendly signature mismatch.', 'smart-chat-ai' ), array( 'status' => 401 ) );
        }
        return true;
    }

    public function handle_webhook( $request ) {
        $body  = $request->get_json_params();
        $event = $body['event'] ?? '';
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
                'note'     => 'No chat lead matched the booking (utm_content / email).',
            ), 200 );
        }

        if ( 'invitee.canceled' === $event ) {
            $reason = sanitize_text_field( (string) ( $payload['cancellation']['reason'] ?? '' ) );
            $result = $this->mark_canceled( $lead, $name, $email, $reason );
        } else {
            $result = $this->mark_booked( $lead, $time, $name, $email );
        }

        return new WP_REST_Response( array(
            'received' => true,
            'action'   => $result,
            'lead_id'  => (int) $lead->id,
        ), 200 );
    }

    /**
     * Resolve the chat lead a booking belongs to: primary 1:1 match on the
     * utm_content=LEAD_<chat_lead_id> we stamp on the decorated link, falling
     * back to the most recent chat lead with the booking email.
     */
    private function match_lead( $payload, $email ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_leads';

        $tracking    = ( isset( $payload['tracking'] ) && is_array( $payload['tracking'] ) ) ? $payload['tracking'] : array();
        $utm_content = (string) ( $tracking['utm_content'] ?? '' );
        if ( '' === $utm_content ) {
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
                "SELECT * FROM {$table} WHERE email = %s ORDER BY id DESC LIMIT 1",
                $email
            ) );
        }
        return null;
    }

    /**
     * Flip a chat lead to booked and fire scai_lead_booked so Smart CRM tags
     * the contact (booked tag + deal stage). Guarded so a re-delivered webhook
     * can't double-fire.
     */
    private function mark_booked( $lead, $time = '', $name = '', $email = '' ) {
        $current = strtolower( (string) ( $lead->status ?? '' ) );
        if ( 'booked' === $current ) {
            return 'already_booked';
        }

        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'smart_chat_leads',
            array( 'status' => 'booked' ),
            array( 'id' => (int) $lead->id ),
            array( '%s' ),
            array( '%d' )
        );
        $lead->status = 'booked';

        wp_mail(
            get_option( 'admin_email' ),
            /* translators: %s: invitee name or email */
            sprintf( __( 'Booking: %s scheduled via the chat', 'smart-chat-ai' ), $name ?: $email ),
            sprintf(
                /* translators: 1: name, 2: time, 3: email */
                __( "%1\$s booked an appointment from the chat.\n\nTime: %2\$s\nEmail: %3\$s\n\nThe chat lead is marked Booked and tagged in the CRM.", 'smart-chat-ai' ),
                $name,
                $time,
                $email
            )
        );

        /**
         * Chat booking conversion. Smart CRM tags the contact booked in
         * ActiveCampaign from this. Passes the chat lead id + a normalized
         * payload (so the CRM never has to read the chat schema directly).
         */
        do_action( 'scai_lead_booked', (int) $lead->id, $this->lead_payload( $lead ) );

        return 'marked_booked';
    }

    /**
     * Reverse a booked chat lead on cancellation and fire scai_lead_canceled.
     */
    private function mark_canceled( $lead, $name = '', $email = '', $reason = '' ) {
        $current = strtolower( (string) ( $lead->status ?? '' ) );
        if ( 'booked' !== $current ) {
            return 'not_booked';
        }

        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'smart_chat_leads',
            array( 'status' => 'canceled' ),
            array( 'id' => (int) $lead->id ),
            array( '%s' ),
            array( '%d' )
        );
        $lead->status = 'canceled';

        do_action( 'scai_lead_canceled', (int) $lead->id, $this->lead_payload( $lead ), $reason );

        return 'marked_canceled';
    }

    /**
     * Normalize a smart_chat_leads row into the generic lead payload Smart CRM
     * expects (same keys the capture event uses), so listeners never touch the
     * chat table schema directly.
     */
    private function lead_payload( $lead ) {
        return array(
            'name'         => (string) ( $lead->name ?? '' ),
            'email'        => (string) ( $lead->email ?? '' ),
            'phone'        => (string) ( $lead->phone ?? '' ),
            'service_type' => (string) ( $lead->service_type ?? '' ),
            'message'      => (string) ( $lead->message ?? '' ),
            'source'       => 'chat',
            'lead_source'  => 'chat',
        );
    }
}
