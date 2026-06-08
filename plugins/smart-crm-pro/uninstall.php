<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'scrm_campaign_queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'scrm_campaigns' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove every option this plugin wrote. Covers the scrm_pro_* settings as
// well as the internal dedupe flags stored under the _scrm_* prefix
// (job-opened / floor-care-plan guards).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'scrm\_pro\_%' OR option_name LIKE '\_scrm\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

wp_clear_scheduled_hook( 'scrm_pro_daily_scan' );
wp_clear_scheduled_hook( 'scrm_pro_send_campaign_email' );
// The recurring ServiceM8 status poller.
wp_clear_scheduled_hook( 'scrm_pro_sm8_poll_jobs' );
// Per-lead follow-up reminders are scheduled as single events WITH a lead_id
// argument, so wp_clear_scheduled_hook() (which matches on args) would leave
// every distinct-arg event behind. wp_unschedule_hook() clears them all
// regardless of args, so no orphaned reminder crons survive an uninstall.
if ( function_exists( 'wp_unschedule_hook' ) ) {
    wp_unschedule_hook( 'scrm_pro_follow_up_reminder' );
}
