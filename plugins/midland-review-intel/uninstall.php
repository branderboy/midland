<?php
/**
 * Uninstall: drop the reviews table and all plugin options.
 *
 * @package Midland_Review_Intel
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mri_reviews' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( 'mri_competitors' );
delete_option( 'mri_pending_tasks' );
delete_option( 'mri_last_errors' );
delete_option( 'mri_depth' );
