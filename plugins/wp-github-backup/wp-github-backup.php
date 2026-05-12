<?php
/**
 * Plugin Name:       Midland GitHub Vault & Deploy
 * Description:       Midland-branded GitHub backup + deploy. Backs up WordPress content, DB, themes, plugins, and uploads to a GitHub repository; deploys page/post content from GitHub back into WordPress with automatic cache-purge and live-render verification.
 * Version:           3.5.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-github-backup
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.6
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WGB_VERSION', '3.4.7' );
define( 'WGB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WGB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WGB_PLUGIN_FILE', __FILE__ );

require_once WGB_PLUGIN_DIR . 'includes/class-github-api.php';
require_once WGB_PLUGIN_DIR . 'includes/class-db-export.php';
require_once WGB_PLUGIN_DIR . 'includes/class-file-collector.php';
require_once WGB_PLUGIN_DIR . 'includes/class-content-export.php';
require_once WGB_PLUGIN_DIR . 'includes/class-backup-runner.php';
require_once WGB_PLUGIN_DIR . 'includes/class-restore.php';
require_once WGB_PLUGIN_DIR . 'includes/class-cache-purge.php';
require_once WGB_PLUGIN_DIR . 'includes/class-deployer.php';
require_once WGB_PLUGIN_DIR . 'includes/class-settings.php';
require_once WGB_PLUGIN_DIR . 'includes/class-claude-api.php';
require_once WGB_PLUGIN_DIR . 'includes/class-content-editor.php';
require_once WGB_PLUGIN_DIR . 'includes/class-webhook.php';
require_once WGB_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Plugin activation.
 */
function wgb_activate() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'github_backup_log';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		backup_date DATETIME NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		files_pushed INT(11) NOT NULL DEFAULT 0,
		total_size BIGINT(20) NOT NULL DEFAULT 0,
		errors TEXT,
		duration INT(11) NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		KEY backup_date (backup_date)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Deploy log table.
	$deploy_table = $wpdb->prefix . 'github_deploy_log';

	$deploy_sql = "CREATE TABLE IF NOT EXISTS {$deploy_table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		deploy_date DATETIME NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		files_deployed INT(11) NOT NULL DEFAULT 0,
		errors TEXT,
		duration INT(11) NOT NULL DEFAULT 0,
		target VARCHAR(50) NOT NULL DEFAULT 'full',
		branch VARCHAR(255) NOT NULL DEFAULT 'main',
		PRIMARY KEY (id),
		KEY deploy_date (deploy_date)
	) {$charset_collate};";

	dbDelta( $deploy_sql );

	$defaults = array(
		'wgb_github_token'      => '',
		'wgb_github_username'   => '',
		'wgb_repo_name'         => '',
		'wgb_schedule'          => 'manual',
		'wgb_include_db'        => '1',
		'wgb_include_themes'    => '1',
		'wgb_include_plugins'   => '1',
		'wgb_include_uploads'   => '0',
		'wgb_include_posts'     => '1',
		'wgb_include_pages'     => '1',
		'wgb_exclude_folders'   => 'cache,node_modules,upgrade',
		'wgb_retention_days'    => '30',
		'wgb_notification_email' => get_option( 'admin_email' ),
		'wgb_deploy_repo_owner'  => '',
		'wgb_deploy_repo_name'   => '',
		'wgb_deploy_branch'      => 'main',
		'wgb_deploy_repo_path'   => '',
		'wgb_deploy_target'      => 'full',
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			update_option( $key, $value );
		}
	}

	// Webhook secret — auto-generate on first activation so the GitHub
	// push webhook works out of the box. Uses CSPRNG (random_bytes) when
	// available, falls back to wp_generate_password.
	if ( '' === (string) get_option( 'wgb_webhook_secret', '' ) ) {
		if ( function_exists( 'random_bytes' ) ) {
			$secret = bin2hex( random_bytes( 32 ) );
		} else {
			$secret = wp_generate_password( 64, false, false );
		}
		update_option( 'wgb_webhook_secret', $secret, false );
	}

	$schedule = get_option( 'wgb_schedule', 'manual' );
	if ( 'manual' !== $schedule ) {
		if ( ! wp_next_scheduled( 'wp_github_backup_cron' ) ) {
			wp_schedule_event( time(), $schedule, 'wp_github_backup_cron' );
		}
	}
}
register_activation_hook( __FILE__, 'wgb_activate' );

/**
 * Plugin deactivation.
 */
function wgb_deactivate() {
	$timestamp = wp_next_scheduled( 'wp_github_backup_cron' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wp_github_backup_cron' );
	}
}
register_deactivation_hook( __FILE__, 'wgb_deactivate' );

/**
 * Self-heal a missing webhook secret on admin requests. Without this the
 * REST endpoint returns 403 forever for sites that activated the plugin
 * before 3.4.7 (when auto-generation was added to wgb_activate).
 */
function wgb_ensure_webhook_secret() {
	if ( ! is_admin() ) {
		return;
	}
	if ( '' !== (string) get_option( 'wgb_webhook_secret', '' ) ) {
		return;
	}
	if ( function_exists( 'random_bytes' ) ) {
		$secret = bin2hex( random_bytes( 32 ) );
	} else {
		$secret = wp_generate_password( 64, false, false );
	}
	update_option( 'wgb_webhook_secret', $secret, false );
}
add_action( 'admin_init', 'wgb_ensure_webhook_secret' );

/**
 * Load plugin textdomain for translations.
 *
 * Loads from /languages/wp-github-backup-{locale}.mo. Required for
 * WordPress.org directory submission.
 */
function wgb_load_textdomain() {
	load_plugin_textdomain(
		'wp-github-backup',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);
}
add_action( 'plugins_loaded', 'wgb_load_textdomain' );

// Cron hook.
add_action( 'wp_github_backup_cron', array( 'WGB_Backup_Runner', 'run_scheduled' ) );

// Output structured data (JSON-LD) on the frontend.
add_action( 'wp_head', array( 'WGB_Content_Editor', 'output_schema_json_ld' ) );

// Register webhook REST endpoint.
add_action( 'rest_api_init', array( 'WGB_Webhook', 'register_routes' ) );

// Async deploy cron handler — picks up the work the webhook queued
// after acking GitHub inside the 10s timeout.
add_action( 'wgb_run_async_deploy', array( 'WGB_Webhook', 'run_async_deploy' ) );

// Initialize admin.
if ( is_admin() ) {
	new WGB_Admin();
}
