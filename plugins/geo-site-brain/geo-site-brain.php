<?php
/**
 * Plugin Name: GEO Site Brain
 * Description: Turns your WordPress content into an AI-readable knowledge base using OpenAI embeddings (stored in Neon pgvector, with a local fallback). Scores every page for GEO/AEO/SEO, generates recommendations, and answers questions in an admin chat using retrieval first.
 * Version: 1.0.0
 * Author: Midland Floor Care
 * Author URI: https://midlandfloors.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: geo-site-brain
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GSB_VERSION', '1.0.0' );
define( 'GSB_PLUGIN_FILE', __FILE__ );
define( 'GSB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GSB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Embedding model + dimensions. text-embedding-3-small returns 1536 dims and is
// cheap; change both together if you switch models.
define( 'GSB_EMBED_MODEL', 'text-embedding-3-small' );
define( 'GSB_EMBED_DIM', 1536 );

// Cron hooks.
define( 'GSB_CRON_REINDEX', 'gsb_weekly_reindex' );
define( 'GSB_CRON_CONTINUE', 'gsb_reindex_continue' );
define( 'GSB_CRON_POST', 'gsb_index_post' );

/**
 * Main plugin bootstrap. Singleton, mirrors the other Midland plugins.
 */
final class GSB_Plugin {

	/** @var GSB_Plugin|null */
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
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-database.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-logger.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-settings.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-openai.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-vector-store.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-scanner.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-indexer.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-scorer.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-recommendations.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-agent.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-view-helpers.php';
		require_once GSB_PLUGIN_DIR . 'includes/class-gsb-admin.php';
	}

	private function init_hooks() {
		register_activation_hook( GSB_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( GSB_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Self-healing schema check on admin load (cheap option compare).
		add_action( 'admin_init', array( 'GSB_Database', 'maybe_upgrade' ) );

		// Admin UI + AJAX.
		GSB_Admin::get_instance();

		// Content lifecycle + cron indexing hooks.
		GSB_Indexer::get_instance()->register_hooks();
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'geo-site-brain', false, dirname( plugin_basename( GSB_PLUGIN_FILE ) ) . '/languages' );
	}

	public function activate() {
		GSB_Database::install();
		GSB_Settings::set_defaults();

		// Weekly full reindex, opt-in by default.
		if ( ! wp_next_scheduled( GSB_CRON_REINDEX ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', GSB_CRON_REINDEX );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook( GSB_CRON_REINDEX );
		wp_clear_scheduled_hook( GSB_CRON_CONTINUE );
	}
}

/**
 * Register a "weekly" cron schedule if WordPress doesn't already have one.
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'geo-site-brain' ),
		);
	}
	return $schedules;
} );

add_action( 'plugins_loaded', array( 'GSB_Plugin', 'get_instance' ) );
