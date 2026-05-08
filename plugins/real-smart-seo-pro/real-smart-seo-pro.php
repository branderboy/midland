<?php
/**
 * Plugin Name: Real Smart SEO for Local Pro
 * Plugin URI: https://tagglefish.com/real-smart-seo-pro
 * Description: Full local SEO offense — sameAs entity identity, GSC cleanup, programmatic city × service pages, IndexNow + Rapid URL Indexer, schema, Google Trends, GMB, and AI-powered fixes.
 * Version: 1.1.0
 * Author: TaggleFish
 * Author URI: https://tagglefish.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: real-smart-seo-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: real-smart-seo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RSSEO_PRO_VERSION',        '1.1.0' );
define( 'RSSEO_PRO_PATH',           plugin_dir_path( __FILE__ ) );
define( 'RSSEO_PRO_URL',            plugin_dir_url( __FILE__ ) );
define( 'RSSEO_PRO_FILE',           __FILE__ );
define( 'RSSEO_PRO_LICENSE_SERVER', 'https://tagglefish.com' );

class RSSEO_Pro_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'plugins_loaded', array( $this, 'check_dependencies' ), 20 );
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
    }

    /**
     * Register a 'weekly' cron interval used by Geo-Grid and AI Rank modules.
     * WordPress core ships hourly/twicedaily/daily — weekly must be added explicitly.
     */
    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'real-smart-seo-pro' ),
            );
        }
        return $schedules;
    }

    public function check_dependencies() {
        if ( ! defined( 'RSSEO_VERSION' ) ) {
            add_action( 'admin_notices', array( $this, 'missing_base_notice' ) );
            return;
        }
        $this->init();
    }

    public function missing_base_notice() {
        echo '<div class="notice notice-error"><p>';
        esc_html_e( 'Real Smart SEO for Local Pro requires the free Real Smart SEO plugin to be installed and active.', 'real-smart-seo-pro' );
        echo '</p></div>';
    }

    public function activate() {
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-database.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-geogrid.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-ai-rank.php';
        RSSEO_Pro_Database::create_tables();
        RSSEO_Pro_Geogrid::create_tables();
        RSSEO_Pro_AI_Rank::create_tables();
        flush_rewrite_rules();
    }

    private function init() {
        load_plugin_textdomain( 'real-smart-seo-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        $this->includes();
        $this->init_classes();
    }

    private function includes() {
        // Core (existing).
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-license.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-database.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-dataforseo.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-schema.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-analyzer.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-fixer.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-crawler.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-admin.php';
        // New modules.
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-sameas.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-gsc-cleanup.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-programmatic.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-indexnow.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-speed.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-geogrid.php';
        require_once RSSEO_PRO_PATH . 'includes/class-rsseo-pro-ai-rank.php';
    }

    private function init_classes() {
        RSSEO_Pro_Admin::get_instance();
        RSSEO_Pro_Crawler::register();
        // New modules self-initialize via get_instance() called in their files.
    }
}

RSSEO_Pro_Plugin::get_instance();
