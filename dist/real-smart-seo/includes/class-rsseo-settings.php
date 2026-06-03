<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Settings {

    public static function encrypt_key( $key ) {
        if ( empty( $key ) ) {
            return '';
        }
        $salt      = wp_salt( 'auth' );
        $method    = 'aes-256-cbc';
        $iv_len    = openssl_cipher_iv_length( $method );
        $iv        = substr( hash( 'sha256', $salt . 'rsseo_iv' ), 0, $iv_len );
        $secret    = hash( 'sha256', $salt . 'rsseo_key' );
        $encrypted = openssl_encrypt( $key, $method, $secret, 0, $iv );
        return base64_encode( $encrypted );
    }

    public static function decrypt_key( $encrypted_key ) {
        if ( empty( $encrypted_key ) ) {
            return '';
        }
        $salt    = wp_salt( 'auth' );
        $method  = 'aes-256-cbc';
        $iv_len  = openssl_cipher_iv_length( $method );
        $iv      = substr( hash( 'sha256', $salt . 'rsseo_iv' ), 0, $iv_len );
        $secret  = hash( 'sha256', $salt . 'rsseo_key' );
        $decoded = base64_decode( $encrypted_key );
        return openssl_decrypt( $decoded, $method, $secret, 0, $iv );
    }

    public static function save_api_key( $key ) {
        update_option( 'rsseo_api_key', self::encrypt_key( sanitize_text_field( $key ) ) );
    }

    public static function get_api_key() {
        return self::decrypt_key( get_option( 'rsseo_api_key', '' ) );
    }

    public static function has_api_key() {
        return ! empty( self::get_api_key() );
    }

    public static function get_model() {
        // Default model is Perplexity's cheapest Sonar tier. Legacy installs
        // that still have a "claude-*" value stored from the old Anthropic
        // implementation are migrated transparently to "sonar".
        $model = get_option( 'rsseo_model', 'sonar' );
        if ( is_string( $model ) && 0 === strpos( $model, 'claude' ) ) {
            $model = 'sonar';
        }
        return $model;
    }

    public static function get_max_tokens() {
        return (int) get_option( 'rsseo_max_tokens', 8000 );
    }

    public static function detect_seo_plugin() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) || is_plugin_active( 'wordpress-seo-premium/wp-seo-premium.php' ) ) {
            return 'yoast';
        }
        if ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
            return 'rankmath';
        }
        return 'none';
    }
}
