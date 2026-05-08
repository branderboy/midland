<?php
/**
 * Indeed Employer API — posts directly to Indeed from WordPress.
 *
 * Uses the Indeed Employer API (v2).
 * Setup: Get API credentials from Indeed Employer dashboard → Integrations.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Indeed {

    public static function register(): void {
        add_action( 'wp_ajax_dpjp_post_indeed', [ __CLASS__, 'handle_ajax' ] );
    }

    public static function handle_ajax(): void {
        check_ajax_referer( 'dpjp_post_action', 'nonce' );

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( 'Permission denied.' );
        $result  = self::post( $post_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [ 'message' => 'Posted to Indeed!', 'job_key' => $result ] );
    }

    public static function post( int $post_id ): string|WP_Error {
        $client_id     = get_option( 'dpjp_indeed_client_id', '' );
        $client_secret = get_option( 'dpjp_indeed_client_secret', '' );
        $employer_id   = get_option( 'dpjp_indeed_employer_id', '' );

        if ( ! $client_id || ! $client_secret || ! $employer_id ) {
            return new WP_Error( 'no_config', 'Indeed API credentials are required. Go to Job Listings → Settings.' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) return new WP_Error( 'no_post', 'Job post not found.' );

        $meta = DPJP_Meta_Fields::get( $post_id );

        // Get access token
        $token = self::get_access_token( $client_id, $client_secret );
        if ( is_wp_error( $token ) ) return $token;

        // Map employment type
        $type_map = [ 'full-time' => 'FULL_TIME', 'part-time' => 'PART_TIME', 'contract' => 'CONTRACT', 'seasonal' => 'TEMPORARY' ];
        $emp_type = $type_map[ $meta['dpjp_employment_type'] ?? 'full-time' ] ?? 'FULL_TIME';

        // Parse salary
        $salary = self::parse_salary( $meta['dpjp_pay'] ?? '' );

        $body = [
            'externalJobId'    => 'dpjp-' . $post_id,
            'title'            => get_the_title( $post ),
            'description'      => DPJP_Content::for_indeed( $post, $meta ),
            'employerName'     => get_option( 'dpjp_indeed_company_name', get_bloginfo( 'name' ) ),
            'employerId'       => $employer_id,
            'jobLocations'     => [ [ 'locationType' => 'POSTAL', 'address' => [ 'city' => $meta['dpjp_location'] ?? '', 'countryCode' => 'US' ] ] ],
            'jobTypes'         => [ $emp_type ],
            'applyType'        => 'EMAIL',
            'applyEmail'       => $meta['dpjp_contact_email'] ?? '',
        ];

        if ( $salary ) {
            $body['salary'] = $salary;
        }

        $response = wp_remote_post(
            'https://apis.indeed.com/graphql',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Indeed-API-Key' => $client_id,
                ],
                'body' => wp_json_encode( [
                    'query' => 'mutation CreateJob($input: CreateJobInput!) { createJob(input: $input) { jobKey } }',
                    'variables' => [ 'input' => $body ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $data['errors'] ) ) {
            return new WP_Error( 'indeed_api_error', $data['errors'][0]['message'] ?? 'Indeed API error.' );
        }

        $job_key = $data['data']['createJob']['jobKey'] ?? '';
        update_post_meta( $post_id, 'dpjp_indeed_job_key', $job_key );
        update_post_meta( $post_id, 'dpjp_indeed_posted_at', current_time( 'mysql' ) );

        return $job_key;
    }

    private static function get_access_token( string $client_id, string $client_secret ): string|WP_Error {
        $cached = get_transient( 'dpjp_indeed_token' );
        if ( $cached ) return $cached;

        $response = wp_remote_post( 'https://apis.indeed.com/oauth/v2/tokens', [
            'timeout' => 20,
            'body'    => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ],
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $data  = json_decode( wp_remote_retrieve_body( $response ), true );
        $token = $data['access_token'] ?? '';

        if ( ! $token ) {
            return new WP_Error( 'indeed_auth', 'Could not authenticate with Indeed API. Check your credentials.' );
        }

        set_transient( 'dpjp_indeed_token', $token, ( $data['expires_in'] ?? 3600 ) - 60 );
        return $token;
    }

    private static function parse_salary( string $pay ): ?array {
        if ( ! $pay ) return null;
        if ( stripos( $pay, '/yr' ) !== false || stripos( $pay, 'year' ) !== false ) {
            $unit = 'YEARLY';
        } elseif ( stripos( $pay, '/wk' ) !== false || stripos( $pay, 'week' ) !== false ) {
            $unit = 'WEEKLY';
        } elseif ( stripos( $pay, '/day' ) !== false || stripos( $pay, 'daily' ) !== false ) {
            $unit = 'DAILY';
        } elseif ( stripos( $pay, '/mo' ) !== false || stripos( $pay, 'month' ) !== false ) {
            $unit = 'MONTHLY';
        } else {
            $unit = 'HOURLY';
        }
        preg_match_all( '/\d[\d,]*/', $pay, $m );
        if ( empty( $m[0] ) ) return null;
        $nums = array_map( fn( $n ) => (float) str_replace( ',', '', $n ), $m[0] );
        return [ 'min' => $nums[0], 'max' => $nums[1] ?? $nums[0], 'type' => $unit, 'currency' => 'USD' ];
    }
}
