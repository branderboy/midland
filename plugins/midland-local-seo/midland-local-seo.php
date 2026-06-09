<?php
/**
 * Plugin Name: Midland Local SEO
 * Plugin URI: https://midlandfloors.com/local-seo
 * Description: Local SEO toolkit for Midland Floors — citation audit, LocalBusiness sameAs identity schema, Local Falcon geo-grid rank tracking, local backlink tracking, Google Business Profile mirror, GBP optimizer, and GMB competitor audit. Powered by DataForSEO (bring your own key).
 * Version: 1.2.1
 * Author: Midland Floor Care
 * Author URI: https://midlandfloors.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: midland-local-seo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Update URI: false
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MLS_VERSION', '1.2.1' );
define( 'MLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLS_URL', plugin_dir_url( __FILE__ ) );
define( 'MLS_FILE', __FILE__ );

/**
 * Core bootstrap: loads modules, registers the top-level menu, and manages the
 * DB schema version. Lifecycle hooks (activation/deactivation) are registered at
 * FILE SCOPE below — not in this constructor — so they always bind.
 */
class MLS_Plugin {

	/** Top-level admin menu slug shared by every module's submenu. */
	const MENU_SLUG = 'midland-local-seo';

	/**
	 * Singleton instance.
	 *
	 * @var MLS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook into runtime (not lifecycle). Lifecycle hooks are bound at file scope.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
	}

	/**
	 * Register a 'weekly' cron interval used by the Geo-Grid module.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'midland-local-seo' ),
			);
		}
		return $schedules;
	}

	/**
	 * Activation: create geo-grid tables, seed default options, record db version.
	 */
	public static function activate() {
		require_once MLS_PATH . 'includes/class-mls-geogrid.php';
		MLS_Geogrid::create_tables();

		// Seed default options so the plugin is useful out of the box. add_option
		// is a no-op when the option already exists, so reactivation is safe.
		if ( false === get_option( 'mls_identity', false ) ) {
			require_once MLS_PATH . 'includes/class-mls-sameas.php';
			add_option( 'mls_identity', MLS_SameAs::defaults() );
		}
		add_option( 'mls_citations', array() );
		add_option( 'mls_geogrid_settings', array() );

		// Insights defaults + schedule the digest cron on the default cadence.
		require_once MLS_PATH . 'includes/class-mls-insights.php';
		MLS_Insights::set_defaults();
		if ( 'off' !== MLS_Insights::cadence() && ! wp_next_scheduled( MLS_Insights::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, MLS_Insights::cadence(), MLS_Insights::CRON_HOOK );
		}

		// Seed the curated local-backlink prospect list from the bundled baseline
		// on first activation (guarded so operator edits are never clobbered).
		if ( ! get_option( 'mls_backlinks_seeded' ) ) {
			require_once MLS_PATH . 'includes/class-mls-backlinks.php';
			$existing = get_option( 'mls_backlink_targets' );
			if ( ! is_array( $existing ) || empty( $existing ) ) {
				MLS_Backlinks::seed_from_baseline();
			}
			update_option( 'mls_backlinks_seeded', 1 );
		}

		update_option( 'mls_db_version', MLS_VERSION );
	}

	/**
	 * Deactivation: clear EVERY scheduled cron event, including arg-bearing
	 * single events. wp_unschedule_hook() removes all instances regardless of
	 * the args they were scheduled with (covers the per-cell geo-grid events).
	 */
	public static function deactivate() {
		$hooks = array(
			'mls_geogrid_weekly_scan',  // MLS_Geogrid::CRON_HOOK.
			'mls_geogrid_process_cell', // MLS_Geogrid::TICK_HOOK (arg-bearing).
			'mls_insights_digest',      // MLS_Insights::CRON_HOOK.
		);
		foreach ( $hooks as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Runtime init on plugins_loaded.
	 */
	public function init() {
		load_plugin_textdomain( 'midland-local-seo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		$this->includes();
		$this->init_classes();
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
	}

	/**
	 * Load every module file.
	 */
	private function includes() {
		require_once MLS_PATH . 'includes/class-mls-dataforseo.php';
		require_once MLS_PATH . 'includes/class-mls-dashboard.php';
		require_once MLS_PATH . 'includes/class-mls-sameas.php';
		require_once MLS_PATH . 'includes/class-mls-citations.php';
		require_once MLS_PATH . 'includes/class-mls-geogrid.php';
		require_once MLS_PATH . 'includes/class-mls-gmb-mirror.php';
		require_once MLS_PATH . 'includes/class-mls-gmb-optimizer.php';
		require_once MLS_PATH . 'includes/class-mls-backlinks.php';
		require_once MLS_PATH . 'includes/class-mls-gmb-competitors.php';
		require_once MLS_PATH . 'includes/class-mls-insights.php';
	}

	/**
	 * Initialize every module singleton.
	 */
	private function init_classes() {
		MLS_Dashboard::get_instance();
		MLS_SameAs::get_instance();
		MLS_Citations::get_instance();
		MLS_Geogrid::get_instance();
		MLS_GMB_Mirror::get_instance();
		MLS_GMB_Optimizer::get_instance();
		MLS_Backlinks::get_instance();
		MLS_GMB_Competitors::get_instance();
		MLS_Insights::get_instance();
	}

	/**
	 * Register one top-level "Local SEO" menu. The dashboard is the default
	 * landing page; each module attaches its own submenu via this same parent
	 * (priority 9 so the parent exists before the modules at 10+ run).
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Local SEO', 'midland-local-seo' ),
			__( 'Local SEO', 'midland-local-seo' ),
			'manage_options',
			self::MENU_SLUG,
			array( MLS_Dashboard::get_instance(), 'render_page' ),
			'dashicons-location',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'midland-local-seo' ),
			__( 'Dashboard', 'midland-local-seo' ),
			'manage_options',
			self::MENU_SLUG,
			array( MLS_Dashboard::get_instance(), 'render_page' )
		);
	}

	/**
	 * Idempotent table create/upgrade on in-place updates (no reactivation).
	 */
	public function maybe_upgrade_db() {
		if ( get_option( 'mls_db_version' ) === MLS_VERSION ) {
			return;
		}
		if ( class_exists( 'MLS_Geogrid' ) ) {
			MLS_Geogrid::create_tables();
		}
		// One-time on this update: overlay the canonical Midland identity over any
		// stale saved profile (fixes the old name/coords and blank listing URLs),
		// while preserving any extra keys the operator added. Guarded so it runs
		// only once and never clobbers later edits.
		if ( class_exists( 'MLS_SameAs' ) && ! get_option( 'mls_identity_refreshed_119' ) ) {
			$current = get_option( 'mls_identity', array() );
			$current = is_array( $current ) ? $current : array();
			$merged  = array_merge( $current, MLS_SameAs::defaults() );
			update_option( 'mls_identity', $merged );
			if ( method_exists( 'MLS_SameAs', 'bridge_to_rsseo' ) ) {
				MLS_SameAs::bridge_to_rsseo( $merged );
			}
			update_option( 'mls_identity_refreshed_119', 1 );
		}
		update_option( 'mls_db_version', MLS_VERSION );
	}
}

// Lifecycle hooks at FILE SCOPE — these must bind during the initial include.
register_activation_hook( __FILE__, array( 'MLS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MLS_Plugin', 'deactivate' ) );

MLS_Plugin::get_instance();
