<?php
/**
 * Uninstall: drop the plugin's tables and options. Vectors stored in Neon are
 * the user's own database and are intentionally left untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array( 'chunks', 'scores', 'recommendations', 'logs', 'settings' );
foreach ( $tables as $t ) {
	$table = $wpdb->prefix . 'gsb_' . $t;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$options = array(
	'gsb_db_version', 'gsb_admin_notice',
	'gsb_openai_api_key', 'gsb_chat_model', 'gsb_neon_enabled', 'gsb_neon_dsn',
	'gsb_post_types', 'gsb_chunk_max_chars', 'gsb_embed_batch', 'gsb_retrieval_k',
	'gsb_weekly_reindex', 'gsb_business_name', 'gsb_business_locations', 'gsb_core_services',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

wp_clear_scheduled_hook( 'gsb_weekly_reindex' );
wp_clear_scheduled_hook( 'gsb_reindex_continue' );
