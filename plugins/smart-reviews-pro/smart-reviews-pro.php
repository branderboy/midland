<?php
/**
 * Plugin Name: Midland Smart Reviews
 * Description: Midland-branded survey-gated review collection. NPS 0-10 survey fires automatically after job completion (driven by sfco_lead_completed + sfco_lead_status_changed actions from Midland Smart Forms / Smart CRM). Score ≥9 sends the GMB review link + 2 follow-up reminders; score <9 captures private feedback only — no public review request.
 * Version: 1.1.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-reviews-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SRP_VERSION', '1.0.0' );
define( 'SRP_PATH',    plugin_dir_path( __FILE__ ) );
define( 'SRP_URL',     plugin_dir_url( __FILE__ ) );
define( 'SRP_FILE',    __FILE__ );

class Smart_Reviews_Pro {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( SRP_FILE, array( $this, 'activate' ) );
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function activate() {
        require_once SRP_PATH . 'includes/class-srp-db.php';
        SRP_DB::create_tables();
    }

    public function init() {
        load_plugin_textdomain( 'smart-reviews-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        $this->includes();
        $this->boot();
    }

    private function includes() {
        require_once SRP_PATH . 'includes/class-srp-db.php';
        require_once SRP_PATH . 'includes/class-srp-survey.php';
        require_once SRP_PATH . 'includes/class-srp-review-router.php';
        require_once SRP_PATH . 'includes/class-srp-admin.php';
        require_once SRP_PATH . 'includes/class-srp-crm-integration.php';
    }

    private function boot() {
        SRP_Survey::get_instance();
        SRP_Review_Router::get_instance();
        SRP_Admin::get_instance();
        SRP_CRM_Integration::get_instance();
    }
}

Smart_Reviews_Pro::get_instance();
