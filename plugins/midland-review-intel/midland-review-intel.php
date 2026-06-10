<?php
/**
 * Plugin Name: Midland Review Intel
 * Plugin URI: https://midlandfloors.com/review-intel
 * Description: Competitor review intelligence — pulls Google reviews for MD/DC flooring & carpet competitors via DataForSEO (reuses the Midland Local SEO key), mines the market's own language and discontent, and feeds keywords + page opportunities into Midland Smart SEO's clusters and programmatic page generator.
 * Version: 1.0.1
 * Author: Midland Floor Care
 * Author URI: https://midlandfloors.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: midland-review-intel
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: midland-local-seo
 * Tested up to: 6.9
 * Update URI: false
 *
 * @package Midland_Review_Intel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MRI_VERSION', '1.0.1' );
define( 'MRI_PATH', plugin_dir_path( __FILE__ ) );
define( 'MRI_URL', plugin_dir_url( __FILE__ ) );
define( 'MRI_FILE', __FILE__ );

/**
 * Plugin bootstrap.
 */
class MRI_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var MRI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return MRI_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bind lifecycle hooks.
	 */
	private function __construct() {
		register_activation_hook( MRI_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( MRI_FILE, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Activation: create the reviews table.
	 */
	public function activate() {
		require_once MRI_PATH . 'includes/class-mri-db.php';
		MRI_DB::create_tables();
	}

	/**
	 * Deactivation: clear scheduled polling.
	 */
	public function deactivate() {
		wp_unschedule_hook( 'mri_poll_tasks' );
	}

	/**
	 * Load modules. Requires Midland Local SEO for the DataForSEO key.
	 */
	public function init() {
		if ( ! class_exists( 'MLS_DataForSEO' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Midland Review Intel needs the Midland Local SEO plugin active (it provides the DataForSEO connection).', 'midland-review-intel' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once MRI_PATH . 'includes/class-mri-db.php';
		require_once MRI_PATH . 'includes/class-mri-fetcher.php';
		require_once MRI_PATH . 'includes/class-mri-analyzer.php';

		MRI_Fetcher::get_instance();

		if ( is_admin() ) {
			require_once MRI_PATH . 'includes/class-mri-admin.php';
			MRI_Admin::get_instance();
		}
	}
}

MRI_Plugin::get_instance();
