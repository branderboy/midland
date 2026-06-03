<?php
/**
 * Compatibility shim — the Midland Smart SEO Pro license system has been removed.
 *
 * Historical: this class validated a license key against tagglefish.com / lmfwc
 * and gated every Pro feature behind it. For the Midland in-house build we don't
 * sell access, so the entire enforcement layer is gone. The class is kept as a
 * no-op so other modules that call RSSEO_Pro_License::is_active() keep compiling
 * and just see Pro as always-active.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Pro_License {

    public static function get_key() {
        return '';
    }

    /**
     * Always returns true so every Pro feature is unlocked. No phone-home.
     */
    public static function is_active() {
        return true;
    }

    public static function activate( $key ) {
        return array( 'success' => true );
    }

    public static function deactivate() {
        // no-op
    }

    public static function get_expiry() {
        return '';
    }
}
