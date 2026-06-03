<?php
/**
 * Uninstall Midland Smart Forms.
 *
 * By default this only removes runtime/transient data and the db-version
 * flag so that a reinstall works cleanly without manual cleanup. All lead
 * data and settings are preserved.
 *
 * To do a full hard reset — dropping every custom table and deleting every
 * sfco_ option — set the option `sfco_purge_on_uninstall` to '1' before
 * deleting the plugin, or define the constant SFCO_PURGE_ON_UNINSTALL.
 *
 * Example (run in wp-cli or a one-off snippet before uninstalling):
 *   update_option( 'sfco_purge_on_uninstall', '1' );
 *
 * @package SmartFormsForMidland
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$purge = get_option( 'sfco_purge_on_uninstall', '0' ) === '1'
    || ( defined( 'SFCO_PURGE_ON_UNINSTALL' ) && SFCO_PURGE_ON_UNINSTALL );

/* ------------------------------------------------------------------ *
 * Always-clean: db-version flag (forces schema re-check on reinstall) *
 * ------------------------------------------------------------------ */
delete_option( 'sfco_db_version' );
delete_option( 'sfco_version' );

/* ------------------------------------------------------------------ *
 * Opt-in hard reset: all sfco_ options + every custom table.         *
 * ------------------------------------------------------------------ */
if ( $purge ) {

    // All plugin-owned options (sfco_ prefix).
    $options = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sfco\_%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

    foreach ( (array) $options as $option ) {
        delete_option( $option );
    }

    // Custom tables — full set created by SFCO_Database, SFCO_Pro_DB, and SFCO_Pro_Log.
    $tables = array(
        $wpdb->prefix . 'sfco_forms',
        $wpdb->prefix . 'sfco_leads',
        $wpdb->prefix . 'sfco_integration_log',
        $wpdb->prefix . 'sfco_automations',
        $wpdb->prefix . 'sfco_automation_logs',
        $wpdb->prefix . 'sfco_crm_sync',
        $wpdb->prefix . 'sfco_team_members',
    );

    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
    }
}
