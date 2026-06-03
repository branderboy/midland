<?php
/**
 * Uninstall Midland Floors Video Brief (Content Traffic Maker).
 *
 * By default this only clears runtime state (the scheduled cron event, the
 * last-sent flag, and the db-version flag) so a reinstall works cleanly.
 * Stored briefs and settings are preserved.
 *
 * To do a full hard reset — dropping the briefs table and deleting every
 * ctm_ option — set the option `ctm_purge_on_uninstall` to '1' before
 * deleting the plugin, or define the constant CTM_PURGE_ON_UNINSTALL.
 *
 * @package ContentTrafficMaker
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$purge = get_option( 'ctm_purge_on_uninstall', '0' ) === '1'
    || ( defined( 'CTM_PURGE_ON_UNINSTALL' ) && CTM_PURGE_ON_UNINSTALL );

/* ------------------------------------------------------------------ *
 * Always-clean: scheduled cron + runtime flags.                      *
 * ------------------------------------------------------------------ */
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'ctm_send_brief' );
}
delete_option( 'ctm_db_version' );
delete_option( 'ctm_last_sent_date' );

/* ------------------------------------------------------------------ *
 * Opt-in hard reset: all ctm_ options + the briefs table.            *
 * ------------------------------------------------------------------ */
if ( $purge ) {

    $options = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ctm\_%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

    foreach ( (array) $options as $option ) {
        delete_option( $option );
    }

    $table = $wpdb->prefix . 'content_traffic_maker_briefs';
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
}
