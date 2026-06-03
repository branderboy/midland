<?php
/**
 * Uninstall Midland Smart Reviews.
 *
 * By default, runtime state is removed but survey data and settings are
 * preserved so a reinstall doesn't lose collected reviews.
 *
 * To do a full hard reset — dropping the srp_surveys table and all srp_
 * options — set the option `srp_purge_on_uninstall` to '1' before
 * deleting the plugin, or define the constant SRP_PURGE_ON_UNINSTALL.
 *
 * Example (run in wp-cli or a one-off snippet before uninstalling):
 *   update_option( 'srp_purge_on_uninstall', '1' );
 *
 * @package MidlandSmartReviews
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$purge = get_option( 'srp_purge_on_uninstall', '0' ) === '1'
    || ( defined( 'SRP_PURGE_ON_UNINSTALL' ) && SRP_PURGE_ON_UNINSTALL );

/* ------------------------------------------------------------------ *
 * Always-clean: scheduled cron events.                               *
 * ------------------------------------------------------------------ */
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'srp_cron_reminders' );  // hourly survey reminders
    wp_clear_scheduled_hook( 'srp_crm_poll' );        // hourly CRM poll
    wp_clear_scheduled_hook( 'srp_review_reminder' ); // 48h GMB review nudges
}

/* ------------------------------------------------------------------ *
 * Opt-in hard reset: all srp_ options + the surveys table.          *
 * ------------------------------------------------------------------ */
if ( $purge ) {

    // All plugin-owned options (srp_ prefix).
    $options = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'srp\_%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

    foreach ( (array) $options as $option ) {
        delete_option( $option );
    }

    // Custom table created by SRP_DB::create_tables().
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}srp_surveys`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
}
