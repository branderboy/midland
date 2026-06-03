<?php
/**
 * Plugin Name: Midland Floors Video Brief
 * Description: Daily SEO keyword, offer, and viral video brief with real TikTok + YouTube examples — delivered to your client via Resend.
 * Version: 1.8.1
 * Author: Midland Floor Care
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: content-traffic-maker
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CTM_VERSION', '1.8.1' );
define( 'CTM_FILE', __FILE__ );
define( 'CTM_PATH', plugin_dir_path( __FILE__ ) );
define( 'CTM_URL', plugin_dir_url( __FILE__ ) );

require_once CTM_PATH . 'includes/class-db.php';
require_once CTM_PATH . 'includes/class-generator.php';
require_once CTM_PATH . 'includes/class-emailer.php';
require_once CTM_PATH . 'includes/class-cron.php';
require_once CTM_PATH . 'includes/class-admin.php';

/**
 * Main plugin bootstrap — wires the modules together and owns activation /
 * deactivation.
 */
final class Content_Traffic_Maker {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( CTM_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( CTM_FILE, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function activate() {
        CTM_DB::create_table();
        // Make sure the recurring weekly schedule exists before cron tries to use it.
        CTM_Cron::register_schedules( array() );
        CTM_Cron::reschedule();
    }

    public function deactivate() {
        CTM_Cron::clear();
    }

    public function init() {
        load_plugin_textdomain( 'content-traffic-maker', false, dirname( plugin_basename( CTM_FILE ) ) . '/languages' );

        add_filter( 'cron_schedules', array( 'CTM_Cron', 'register_schedules' ) );

        CTM_DB::maybe_upgrade();
        CTM_Cron::get_instance();
        CTM_Admin::get_instance();
    }
}

Content_Traffic_Maker::get_instance();
