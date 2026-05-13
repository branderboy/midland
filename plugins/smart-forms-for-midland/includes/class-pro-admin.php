<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Admin {

    public function __construct() {
        // Remove the free plugin's "Upgrade to PRO" submenu (Pro is bundled here).
        add_action( 'admin_menu', array( $this, 'modify_menu' ), 100 );
    }

    public function modify_menu() {
        remove_submenu_page( 'sfco-forms', 'sfco-upgrade' );
    }
}

new SFCO_Pro_Admin();
