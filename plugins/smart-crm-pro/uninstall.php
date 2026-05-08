<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'scrm_campaign_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'scrm_campaigns' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'scrm\_pro\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

wp_clear_scheduled_hook( 'scrm_pro_daily_scan' );
wp_clear_scheduled_hook( 'scrm_pro_send_campaign_email' );
