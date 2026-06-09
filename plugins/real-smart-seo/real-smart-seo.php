<?php
/**
 * Plugin Name: Midland Smart SEO
 * Plugin URI: https://midlandfloors.com/smart-seo
 * Description: Midland's organic SEO suite — audit, AI-powered analysis, one-click fixes with rollback, programmatic city × service pages, internal link suggestions, keyword clustering, content briefs, schema, GSC cleanup, IndexNow, page speed, and rank tracking.
 * Version: 2.1.0
 * Author: Midland Floor Care
 * Author URI: https://midlandfloors.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: real-smart-seo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RSSEO_VERSION',  '2.1.0' );
define( 'RSSEO_PATH',     plugin_dir_path( __FILE__ ) );
define( 'RSSEO_URL',      plugin_dir_url( __FILE__ ) );
define( 'RSSEO_FILE',     __FILE__ );

// Constants mirrored so bundled modules keep working unchanged.
define( 'RSSEO_PRO_VERSION', RSSEO_VERSION );
define( 'RSSEO_PRO_PATH',    RSSEO_PATH );
define( 'RSSEO_PRO_URL',     RSSEO_URL );
define( 'RSSEO_PRO_FILE',    RSSEO_FILE );

class RSSEO_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
    }

    /** 'weekly' interval used by the Geo-Grid and AI-Rank modules. */
    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'real-smart-seo' ),
            );
        }
        return $schedules;
    }

    public function activate() {
        require_once RSSEO_PATH . 'includes/class-rsseo-database.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-database.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-ai-rank.php';
        RSSEO_Database::create_tables();
        RSSEO_Pro_Database::create_tables();
        RSSEO_Pro_AI_Rank::create_tables();
        flush_rewrite_rules();
        // Record the schema version so maybe_upgrade_db() (admin_init) doesn't
        // re-run a full dbDelta + flush_rewrite_rules pass on the next admin load.
        update_option( 'rsseo_db_version', RSSEO_VERSION );
    }

    /**
     * Clear every scheduled cron event this plugin registers, so nothing keeps
     * firing after deactivation. wp_unschedule_hook() removes all instances of
     * a hook regardless of the args they were scheduled with (covers the
     * per-URL / per-scan single events too).
     */
    public function deactivate() {
        $hooks = array(
            'rsseo_ai_rank_weekly_scan',   // RSSEO_Pro_AI_Rank::CRON_HOOK
            'rsseo_ai_rank_process_one',   // RSSEO_Pro_AI_Rank::TICK_HOOK
            'rsseo_growth_digest_send',    // RSSEO_Pro_Growth_Digest::CRON_HOOK
            'rsseo_analyze_scan',          // RSSEO_Jobs::HOOK (background analysis)
            'rsseo_indexnow_ping',         // RSSEO_Pro_IndexNow single + batch
            'rsseo_indexnow_batch_ping',
        );
        foreach ( $hooks as $hook ) {
            wp_unschedule_hook( $hook );
        }
        flush_rewrite_rules();
    }

    public function init() {
        load_plugin_textdomain( 'real-smart-seo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        $this->includes();
        $this->init_classes();
        add_action( 'admin_init',        array( $this, 'maybe_upgrade_db' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_brand_layout' ) );
    }

    private function includes() {
        // Core engine.
        require_once RSSEO_PATH . 'includes/class-rsseo-status.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-profile.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-database.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-settings.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-ai-client.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-importer.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-analyzer.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-jobs.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-opportunities.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-fixer.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-crawler.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-admin.php';

        // Bundled modules (formerly the Pro add-on).
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-license.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-database.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-dataforseo.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-schema.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-analyzer.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-fixer.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-crawler.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-admin.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-gsc-cleanup.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-programmatic.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-internal-links.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-clusters.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-content-brief.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-indexnow.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-speed.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-ai-rank.php';
        require_once RSSEO_PATH . 'includes/class-rsseo-pro-growth-digest.php';
    }

    private function init_classes() {
        RSSEO_Admin::get_instance();
        // Bundled modules self-initialize via get_instance() at file scope; the
        // admin shell + crawler need an explicit nudge (as in the old Pro boot).
        if ( class_exists( 'RSSEO_Pro_Admin' ) ) {
            RSSEO_Pro_Admin::get_instance();
        }
        if ( class_exists( 'RSSEO_Pro_Crawler' ) && method_exists( 'RSSEO_Pro_Crawler', 'register' ) ) {
            RSSEO_Pro_Crawler::register();
        }
    }

    /** Idempotent table create/upgrade on in-place updates (no reactivation). */
    public function maybe_upgrade_db() {
        if ( get_option( 'rsseo_db_version' ) === RSSEO_VERSION ) {
            return;
        }
        if ( class_exists( 'RSSEO_Database' ) )     { RSSEO_Database::create_tables(); }
        if ( class_exists( 'RSSEO_Pro_Database' ) ) { RSSEO_Pro_Database::create_tables(); }
        if ( class_exists( 'RSSEO_Pro_AI_Rank' ) )  { RSSEO_Pro_AI_Rank::create_tables(); }
        flush_rewrite_rules();
        update_option( 'rsseo_db_version', RSSEO_VERSION );
    }

    /** Brand layout CSS for hand-built + programmatic pages. */
    public function enqueue_brand_layout() {
        if ( file_exists( RSSEO_PATH . 'assets/css/brand-layout.css' ) ) {
            wp_enqueue_style( 'midland-brand-layout', RSSEO_URL . 'assets/css/brand-layout.css', array(), RSSEO_VERSION );
        }
    }
}

RSSEO_Plugin::get_instance();

// Lifecycle hooks registered at file scope (not in the constructor) so they
// always bind during the activation/deactivation request.
register_activation_hook( __FILE__, array( RSSEO_Plugin::get_instance(), 'activate' ) );
register_deactivation_hook( __FILE__, array( RSSEO_Plugin::get_instance(), 'deactivate' ) );
