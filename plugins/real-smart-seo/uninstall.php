<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop all plugin tables — must match the full set created by RSSEO_Database::create_tables().
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_fixes" );          // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_reports" );        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_scans" );          // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_api_log" );        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_audit_issues" );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_audits" );         // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

// Bundled-module tables (RSSEO_Pro_Database / AI-Rank).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_scans" );         // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_schema" );        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_ai_rank" );       // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

// Legacy tables from modules that have since moved to the Midland Local SEO
// plugin (backlinks, geo-grid). Kept as DROP IF EXISTS so upgrading-from-old
// installs still clean up; create/CRUD code for these no longer exists here.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_backlinks" );     // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_geogrid_runs" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_geogrid_cells" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

// Delete all plugin options.
$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( 'rsseo_' ) . '%'
) );

// Transients (e.g. the IndexNow ping log) live under the _transient_ prefix, so
// the rsseo_ wildcard above doesn't reach them.
delete_transient( 'rsseo_indexnow_logs' );
$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_rsseo_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_rsseo_' ) . '%'
) );
