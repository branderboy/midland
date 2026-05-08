<?php
/**
 * Facebook Graph API — posts directly to a Facebook Page from WordPress.
 *
 * Setup required (one-time):
 *  1. Create a Facebook App at developers.facebook.com
 *  2. Add the Pages API product
 *  3. Generate a Page Access Token
 *  4. Paste the token + Page ID in Job Listings → Settings
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Facebook {

    public static function register(): void {
        add_action( 'wp_ajax_dpjp_post_facebook', [ __CLASS__, 'handle_ajax' ] );
    }

    public static function handle_ajax(): void {
        check_ajax_referer( 'dpjp_post_action', 'nonce' );

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( 'Permission denied.' );
        $result  = self::post( $post_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [ 'message' => 'Posted to Facebook!', 'post_id' => $result ] );
    }

    public static function post( int $post_id ): string|WP_Error {
        $page_id    = get_option( 'dpjp_fb_page_id', '' );
        $token      = get_option( 'dpjp_fb_page_token', '' );

        if ( ! $page_id || ! $token ) {
            return new WP_Error( 'no_config', 'Facebook Page ID and Access Token are required. Go to Job Listings → Settings.' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) return new WP_Error( 'no_post', 'Job post not found.' );

        $meta    = DPJP_Meta_Fields::get( $post_id );
        $message = DPJP_Content::for_facebook( $post, $meta );
        $job_url = get_permalink( $post );

        $response = wp_remote_post(
            "https://graph.facebook.com/v19.0/{$page_id}/feed",
            [
                'timeout' => 30,
                'body'    => [
                    'message'      => $message,
                    'link'         => $job_url,
                    'access_token' => $token,
                ],
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['error'] ) ) {
            return new WP_Error( 'fb_api_error', $body['error']['message'] ?? 'Facebook API error.' );
        }

        $fb_post_id = $body['id'] ?? '';
        update_post_meta( $post_id, 'dpjp_fb_post_id', $fb_post_id );
        update_post_meta( $post_id, 'dpjp_fb_posted_at', current_time( 'mysql' ) );

        return $fb_post_id;
    }
}
