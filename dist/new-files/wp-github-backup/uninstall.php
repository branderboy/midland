<?php
/**
 * Uninstall WP GitHub Backup.
 *
 * By default, preserves GitHub credentials and deploy settings so a
 * reinstall doesn't force reconfiguration. Set
 * wgb_purge_on_uninstall = '1' in the options table, or define the
 * constant WGB_PURGE_ON_UNINSTALL, before deleting the plugin to wipe
 * everything.
 *
 * Runtime state (transients, step progress, cron hook) is always
 * removed — it has no value across reinstalls.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$purge = get_option( 'wgb_purge_on_uninstall', '0' ) === '1'
	|| ( defined( 'WGB_PURGE_ON_UNINSTALL' ) && WGB_PURGE_ON_UNINSTALL );

/* ------------------------------------------------------------------ *
 * Always-clean: runtime / lock / schedule state.                     *
 * ------------------------------------------------------------------ */
delete_transient( 'wgb_backup_running' );

$step_options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wgb\_step\_%'"
);

foreach ( (array) $step_options as $option ) {
	delete_option( $option );
}

// Cron event — remove unconditionally. Activation adds it; deactivation
// removes it, but uninstall must handle the "user deleted the plugin
// without deactivating first" path too.
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'wp_github_backup_cron' );
}

/* ------------------------------------------------------------------ *
 * Opt-in hard reset: every wgb_ option, both log tables, and every    *
 * post-meta key the plugin wrote.                                    *
 * ------------------------------------------------------------------ */
if ( $purge ) {

	// Every plugin-owned option. The 'wgb_' prefix is enforced site-wide
	// by WGB_Settings::get()/save().
	$options = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wgb\_%'"
	);

	foreach ( (array) $options as $option ) {
		delete_option( $option );
	}

	// Plugin-owned post meta.
	$meta_keys = array(
		'_wgb_last_deploy_sha',
		'_wgb_schema_json_ld',
	);

	foreach ( $meta_keys as $meta_key ) {
		$wpdb->delete(
			$wpdb->postmeta,
			array( 'meta_key' => $meta_key ),
			array( '%s' )
		);
	}

	// Custom log tables.
	$tables = array(
		$wpdb->prefix . 'github_backup_log',
		$wpdb->prefix . 'github_deploy_log',
	);

	foreach ( $tables as $table ) {
		// Identifier, not user input — safe to interpolate after prefix.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	// User meta — none currently written, but belt-and-suspenders for
	// future additions. No-op if the table has no matching rows.
	$wpdb->delete(
		$wpdb->usermeta,
		array( 'meta_key' => '_wgb_dismissed_notices' ),
		array( '%s' )
	);
}
