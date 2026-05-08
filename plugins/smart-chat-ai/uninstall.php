<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop tables.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'smart_chat_leads' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'smart_chat_conversations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'smart\_chat\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear cron.
wp_clear_scheduled_hook( 'scai_daily_license_check' );
