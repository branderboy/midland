<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Clear the daily content-context refresh cron.
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'scai_ctx_refresh' );
}

// Drop tables.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'smart_chat_leads' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'smart_chat_conversations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'smart\_chat\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

