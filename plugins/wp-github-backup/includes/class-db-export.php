<?php
/**
 * Database export class for WP GitHub Backup.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_DB_Export {

	/**
	 * Keys in wp_options that should have their values redacted.
	 *
	 * @var array
	 */
	private static $sensitive_patterns = array(
		'secret',
		'key',
		'token',
		'password',
		'api_key',
	);

	/**
	 * Export the WordPress database as a compressed SQL string.
	 *
	 * @return array{content: string, filename: string}|WP_Error
	 */
	public static function export() {
		global $wpdb;

		$sql    = '';
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		if ( empty( $tables ) ) {
			return new WP_Error( 'no_tables', __( 'No database tables found.', 'wp-github-backup' ) );
		}

		$sql .= "-- WP GitHub Backup Database Export\n";
		$sql .= '-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$sql .= '-- WordPress: ' . get_bloginfo( 'version' ) . "\n";
		$sql .= "-- -----------------------------------------------\n\n";
		$sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
		$sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

		foreach ( $tables as $table ) {
			$sql .= self::export_table( $table );
		}

		$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

		$compressed = gzencode( $sql, 9 );

		if ( false === $compressed ) {
			return new WP_Error( 'compression_failed', __( 'Failed to compress database export.', 'wp-github-backup' ) );
		}

		$filename = 'database/backup-' . gmdate( 'Y-m-d-His' ) . '.sql.gz';

		return array(
			'content'  => $compressed,
			'filename' => $filename,
		);
	}

	/**
	 * Export a single table (structure and data).
	 *
	 * @param string $table Table name.
	 * @return string SQL statements.
	 */
	private static function export_table( $table ) {
		global $wpdb;

		$sql = "-- Table: {$table}\n";
		$sql .= "DROP TABLE IF EXISTS `{$table}`;\n";

		// Get CREATE TABLE statement.
		// Note: Cannot use $wpdb->prepare() here — it strips backticks from
		// identifiers, which breaks the SHOW CREATE TABLE syntax. The $table
		// value comes directly from SHOW TABLES output so it is safe.
		$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
		if ( ! empty( $create[1] ) ) {
			$sql .= $create[1] . ";\n\n";
		}

		// Get table data in batches.
		$is_users_table  = ( $wpdb->users === $table );
		$is_options_table = ( $wpdb->options === $table );

		$batch_size = 500;
		$offset     = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( $is_users_table ) {
					$row = self::sanitize_user_row( $row );
				}
				if ( $is_options_table ) {
					$row = self::sanitize_option_row( $row );
				}

				$values = array();
				foreach ( $row as $value ) {
					if ( null === $value ) {
						$values[] = 'NULL';
					} else {
						$values[] = "'" . esc_sql( $value ) . "'";
					}
				}

				$columns = '`' . implode( '`, `', array_keys( $row ) ) . '`';
				$sql    .= "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode( ', ', $values ) . ");\n";
			}

			$offset += $batch_size;
		} while ( count( $rows ) === $batch_size );

		$sql .= "\n";
		return $sql;
	}

	/**
	 * Sanitize a wp_users row by replacing the password hash.
	 *
	 * @param array $row Table row.
	 * @return array Sanitized row.
	 */
	private static function sanitize_user_row( $row ) {
		if ( isset( $row['user_pass'] ) ) {
			$row['user_pass'] = '***REDACTED***';
		}
		return $row;
	}

	/**
	 * Sanitize a wp_options row by redacting sensitive values.
	 *
	 * @param array $row Table row.
	 * @return array Sanitized row.
	 */
	private static function sanitize_option_row( $row ) {
		if ( ! isset( $row['option_name'] ) ) {
			return $row;
		}

		$option_name = strtolower( $row['option_name'] );

		foreach ( self::$sensitive_patterns as $pattern ) {
			if ( false !== strpos( $option_name, $pattern ) ) {
				$row['option_value'] = '***REDACTED***';
				break;
			}
		}

		return $row;
	}
}
