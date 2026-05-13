<?php
/**
 * Compatibility shim — the Smart CRM Pro license system has been removed.
 *
 * Historical: this class enforced a remote-validated license key via
 * livableforms.com and added a "CRM PRO License" submenu. For the Midland
 * in-house build we don't sell access, so the entire enforcement layer is
 * gone. The class is kept as a no-op so other modules that call
 * SCRM_Pro_License::is_valid() keep compiling and just see Pro as
 * always-active.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_License {

    /**
     * Always returns true so every Pro feature is unlocked. No phone-home.
     */
    public static function is_valid() {
        return true;
    }
}

new SCRM_Pro_License();
