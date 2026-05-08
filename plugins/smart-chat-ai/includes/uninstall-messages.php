<?php
/**
 * Smart Messages Uninstall
 *
 * Removes all plugin data on uninstall.
 *
 * @package Smart_Messages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete all plugin options.
$options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'smsg\_%'"
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// Drop the messages log table.
$table = $wpdb->prefix . 'smsg_messages';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'smsg_send_reminders' );
