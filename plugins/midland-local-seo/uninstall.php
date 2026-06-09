<?php
/**
 * Uninstall handler for Midland Local SEO.
 *
 * Deletes every option the plugin ever wrote, drops its custom tables, and
 * clears its transients. Guarded so it only runs from the WordPress uninstall
 * lifecycle.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1) Delete every option this plugin ever wrote.
$mls_options = array(
	'mls_dfs_login',
	'mls_dfs_password',
	'mls_identity',
	'mls_identity_migrated',
	'mls_citations',
	'mls_geogrid_settings',
	'mls_backlink_targets',
	'mls_backlinks_seeded',
	'mls_backlink_competitors',
	'mls_linkgap_keys',
	'mls_gmb_categories',
	'mls_identity_refreshed_119',
	'mls_insights_recipient',
	'mls_insights_cadence',
	'mls_insights_last_run',
	'mls_db_version',
);
foreach ( $mls_options as $mls_option ) {
	delete_option( $mls_option );
}

// Any forward-compatible gmb_* options (none persisted in 1.0.0, but the
// uninstall must cover every prefix the plugin could use).
$mls_gmb_options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'mls\\_gmb\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
if ( is_array( $mls_gmb_options ) ) {
	foreach ( $mls_gmb_options as $mls_gmb_option ) {
		delete_option( $mls_gmb_option );
	}
}

// 2) Drop custom tables via prepared identifiers.
$mls_tables = array(
	$wpdb->prefix . 'mls_geogrid_runs',
	$wpdb->prefix . 'mls_geogrid_cells',
);
foreach ( $mls_tables as $mls_table ) {
	// Table names are built from $wpdb->prefix (no user input). Using string
	// interpolation rather than the %i placeholder keeps the declared
	// "Requires at least: 5.8" floor valid (%i needs WP 6.2+).
	$wpdb->query( "DROP TABLE IF EXISTS {$mls_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// 3) Clear plugin transients (and their timeouts).
$mls_transients = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mls\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mls\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
if ( is_array( $mls_transients ) ) {
	foreach ( $mls_transients as $mls_transient_option ) {
		delete_option( $mls_transient_option );
	}
}
