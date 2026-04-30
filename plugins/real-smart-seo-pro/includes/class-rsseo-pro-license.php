<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Pro_License {

    const OPTION_KEY    = 'rsseo_pro_license_key';
    const OPTION_STATUS = 'rsseo_pro_license_status';
    const OPTION_EXPIRY = 'rsseo_pro_license_expiry';

    public static function get_key() {
        return get_option( self::OPTION_KEY, '' );
    }

    public static function is_active() {
        return 'active' === get_option( self::OPTION_STATUS, '' );
    }

    public static function activate( $key ) {
        $key = sanitize_text_field( $key );

        $response = wp_remote_post( RSSEO_PRO_LICENSE_SERVER . '/wp-json/lmfwc/v2/licenses/activate/' . rawurlencode( $key ), array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $data['data']['licenseKey']['status'] ) && 'active' === strtolower( $data['data']['licenseKey']['status'] ) ) {
            update_option( self::OPTION_KEY,    $key );
            update_option( self::OPTION_STATUS, 'active' );
            update_option( self::OPTION_EXPIRY, $data['data']['licenseKey']['expiresAt'] ?? '' );
            return array( 'success' => true );
        }

        return array( 'success' => false, 'error' => $data['message'] ?? __( 'Invalid license key.', 'real-smart-seo-pro' ) );
    }

    public static function deactivate() {
        $key = self::get_key();
        if ( $key ) {
            wp_remote_post( RSSEO_PRO_LICENSE_SERVER . '/wp-json/lmfwc/v2/licenses/deactivate/' . rawurlencode( $key ), array( 'timeout' => 10 ) );
        }
        update_option( self::OPTION_STATUS, 'inactive' );
    }

    public static function get_expiry() {
        return get_option( self::OPTION_EXPIRY, '' );
    }
}
