<?php
/**
 * Compatibility shim — the Pro license system has been removed.
 *
 * Historical: this class enforced a remote-validated license key via
 * livableforms.com and added a Pro → License submenu. For the Midland
 * in-house build we don't sell access, so the entire enforcement layer
 * is gone. The class is kept as a no-op so other modules that call
 * SFCO_Pro_License::is_valid() (e.g. class-pro-team.php) keep compiling
 * and just see Pro as always-active.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_License {

    /**
     * Always returns true so every Pro feature is unlocked. No phone-home.
     */
    public static function is_valid() {
        return true;
    }
}

new SFCO_Pro_License();
