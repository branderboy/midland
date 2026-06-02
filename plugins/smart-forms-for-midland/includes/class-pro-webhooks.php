<?php
/**
 * Per-form outbound webhooks.
 *
 * Each form can configure a webhook URL + method (POST / PUT / PATCH /
 * GET / DELETE) on its Settings tab. When the form is submitted, the
 * full lead payload is POSTed to that URL. This is the bridge for any
 * "send to Zapier / Make / n8n / custom backend" workflow without
 * writing code.
 *
 * Config lives inside the form's settings_json under the 'webhook'
 * key so it ships with the form (no separate options table).
 *
 * Modeled on Gravity Forms' webhook add-on, simplified to one hook
 * per form (the GF flow supports multiple feeds per form; we don't
 * need that complexity for the Midland use case).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Webhooks {

    public function __construct() {
        // Priority 25 so it fires after CRM (20) but before the basic
        // notification email (30). Ordering lets the operator see in
        // the integration log which step ran when.
        add_action( 'sfco_lead_submitted', array( $this, 'on_lead_submitted' ), 25, 3 );
    }

    public function on_lead_submitted( $lead_id, $row, $form ): void {
        if ( ! $form ) {
            return;
        }
        $settings = is_string( $form->settings_json ?? null ) ? json_decode( $form->settings_json, true ) : array();
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $hook = $settings['webhook'] ?? array();
        $url  = isset( $hook['url'] ) ? trim( (string) $hook['url'] ) : '';
        if ( '' === $url ) {
            return;
        }

        // SSRF guard: only allow http(s) and reject URLs that resolve to
        // loopback/private/reserved ranges (e.g. http://169.254.169.254/ cloud
        // metadata, http://localhost). wp_http_validate_url() performs the IP
        // checks WordPress uses for its own safe-redirect / HTTP API.
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! wp_http_validate_url( $url ) ) {
            SFCO_Pro_Log::record( 'webhook', 'error', 'Blocked unsafe webhook URL: ' . $url, (int) ( $form->id ?? 0 ), (int) $lead_id );
            return;
        }

        $method = strtoupper( (string) ( $hook['method'] ?? 'POST' ) );
        if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'GET', 'DELETE' ), true ) ) {
            $method = 'POST';
        }
        $format = ( $hook['format'] ?? 'json' ) === 'form' ? 'form' : 'json';

        // Build a clean payload — lead row plus form / lead identity.
        $row = is_array( $row ) ? $row : (array) $row;
        $payload = array_merge( $row, array(
            'lead_id'    => (int) $lead_id,
            'form_id'    => (int) ( $form->id ?? 0 ),
            'form_title' => (string) ( $form->title ?? '' ),
            'site_url'   => home_url(),
            'submitted_at' => current_time( 'mysql', 1 ),
        ) );

        $args = array(
            'method'             => $method,
            'timeout'            => 15,
            'reject_unsafe_urls' => true,
            'headers'            => array(),
        );

        if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            if ( 'json' === $format ) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body'] = wp_json_encode( $payload );
            } else {
                $args['body'] = $payload;
            }
            $target_url = $url;
        } else {
            // GET / DELETE — fold the payload into the query string.
            $target_url = add_query_arg( array_map( static function ( $v ) {
                return is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
            }, $payload ), $url );
        }

        $response = wp_remote_request( $target_url, $args );

        if ( is_wp_error( $response ) ) {
            SFCO_Pro_Log::record( 'webhook', 'error', 'Transport error: ' . $response->get_error_message(), (int) ( $form->id ?? 0 ), (int) $lead_id, $payload );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $status = ( $code >= 200 && $code < 300 ) ? 'ok' : 'error';
        SFCO_Pro_Log::record( 'webhook', $status, sprintf( '%s %s → HTTP %d', $method, $url, $code ), (int) ( $form->id ?? 0 ), (int) $lead_id, $payload, $body );
    }
}

new SFCO_Pro_Webhooks();
