<?php
/**
 * Plugin Name: Real Smart SEO
 * Plugin URI: https://tagglefish.com/real-smart-seo
 * Description: AI-powered SEO analysis, reporting, and auto-fix. Upload your Screaming Frog, GSC, GA, and PageSpeed data — get a full report, a prioritized plan, and one-click fixes.
 * Version: 1.0.0
 * Author: TaggleFish
 * Author URI: https://tagglefish.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: real-smart-seo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RSSEO_VERSION',  '1.0.0' );
define( 'RSSEO_PATH',     plugin_dir_path( __FILE__ ) );
define( 'RSSEO_URL',      plugin_dir_url( __FILE__ ) );
define( 'RSSEO_FILE',     __FILE__ );

class RSSEO_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function activate() {
        require_once RSSEO_PATH . 'includes/class-rsseo-database.php';
        RSSEO_Database::create_tables();
    }

    public function init() {
        load_plugin_textdomain( 'real-smart-seo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        $this->includes();
        $this->init_classes();
    }

    private function includes() {
        require_once RSSEO_PATH . 'includes/class-rsseo-database.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-settings.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-claude-api.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-importer.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-analyzer.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-fixer.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-crawler.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-admin.php';
    }

    private function init_classes() {
        RSSEO_Admin::get_instance();
    }
}

RSSEO_Plugin::get_instance();
