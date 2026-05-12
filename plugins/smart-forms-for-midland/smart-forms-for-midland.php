<?php
/**
 * Plugin Name: Midland Smart Forms
 * Description: Multi-form lead capture for Midland Floor Care — floor-care templates, per-form shortcodes, file uploads, automation, Smart CRM Pro sync, Resend email, Google Calendar, branding, analytics, team management. (Formerly Smart Forms Basic + Smart Forms PRO, combined into one.)
 * Version: 2.0.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-forms-for-midland
 * Domain Path: /languages
 * Requires at least: 5.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants. Internal SFCO_/SFCO_PRO_ prefixes preserved so all
// existing class code keeps working with zero changes when we merged the
// Pro plugin into this folder.
define( 'SFCO_VERSION', '2.0.0' );
define( 'SFCO_PLUGIN_FILE', __FILE__ );
define( 'SFCO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFCO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Pro constants point at the same dir now — Pro classes were merged in.
define( 'SFCO_PRO_VERSION', SFCO_VERSION );
define( 'SFCO_PRO_FILE',    __FILE__ );
define( 'SFCO_PRO_DIR',     SFCO_PLUGIN_DIR );
define( 'SFCO_PRO_URL',     SFCO_PLUGIN_URL );

/**
 * Main plugin class
 */
class SFCO_Plugin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    private function load_dependencies() {
        // Core (formerly the free Basic plugin)
        require_once SFCO_PLUGIN_DIR . 'includes/class-database.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-form-handler.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-admin.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-shortcode.php';

        // Pro modules (formerly the separate Smart Forms PRO plugin, merged
        // into this folder so it's one install). class-pro-license.php is
        // a no-op shim that hard-returns true so every Pro feature is
        // unlocked without any license enforcement.
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-license.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-db.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-admin.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-automations.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-crm.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-calendly.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-analytics.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-branding.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-team.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-resend.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-gcal.php';
    }
    
    private function init_hooks() {
        register_activation_hook( __FILE__, array( 'SFCO_Plugin', 'activate' ) );
        register_uninstall_hook(  __FILE__, array( 'SFCO_Plugin', 'uninstall' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
    }

    /**
     * Activation: create both the core forms/leads tables and the Pro side
     * tables (automations log, analytics events, etc.) plus seed the
     * Midland template library.
     */
    public static function activate() {
        SFCO_Database::create_tables();
        if ( class_exists( 'SFCO_Pro_DB' ) && method_exists( 'SFCO_Pro_DB', 'create_tables' ) ) {
            SFCO_Pro_DB::create_tables();
        }
    }
    
    public function enqueue_frontend_assets() {
        wp_enqueue_style( 'sfco-frontend', SFCO_PLUGIN_URL . 'assets/css/frontend.css', array(), SFCO_VERSION );
        wp_enqueue_script( 'sfco-frontend', SFCO_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), SFCO_VERSION, true );
        
        wp_localize_script( 'sfco-frontend', 'sfcoData', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'sfco_submit' ),
        ) );
    }
    
    public static function uninstall() {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }
        
        global $wpdb;
        
        $table = $wpdb->prefix . 'sfco_leads';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name built from $wpdb->prefix.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        
        delete_option( 'sfco_version' );
        delete_option( 'sfco_settings' );
    }
}

// Initialize plugin
SFCO_Plugin::get_instance();
