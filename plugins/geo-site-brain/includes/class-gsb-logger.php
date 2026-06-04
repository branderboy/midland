<?php
/**
 * Structured logging into gsb_logs, plus a one-shot admin notice for indexing
 * errors so failures are visible without digging through logs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Logger {

	const NOTICE_OPTION = 'gsb_admin_notice';

	public static function log( $level, $context, $message ) {
		global $wpdb;
		$level = in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info';
		$wpdb->insert( GSB_Database::table( 'logs' ), array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'level'      => $level,
			'context'    => substr( (string) $context, 0, 50 ),
			'message'    => (string) $message,
			'created_at' => current_time( 'mysql' ),
		) );

		// Surface errors as an admin notice once.
		if ( 'error' === $level ) {
			update_option( self::NOTICE_OPTION, array(
				'level'   => 'error',
				'message' => (string) $message,
				'time'    => time(),
			) );
		}
	}

	public static function info( $context, $message ) {
		self::log( 'info', $context, $message );
	}

	public static function warning( $context, $message ) {
		self::log( 'warning', $context, $message );
	}

	public static function error( $context, $message ) {
		self::log( 'error', $context, $message );
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		$table = GSB_Database::table( 'logs' );
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
			$limit
		) );
	}

	/**
	 * Print and clear the pending admin notice (hooked from GSB_Admin).
	 */
	public static function render_notice() {
		$notice = get_option( self::NOTICE_OPTION );
		if ( empty( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_option( self::NOTICE_OPTION );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>GEO Site Brain:</strong> %2$s</p></div>',
			esc_attr( 'error' === $notice['level'] ? 'error' : 'warning' ),
			esc_html( $notice['message'] )
		);
	}
}
