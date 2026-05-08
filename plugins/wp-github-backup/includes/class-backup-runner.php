<?php
/**
 * Backup runner class for WP GitHub Backup.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Backup_Runner {

	/**
	 * Lock transient name.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'wgb_backup_running';

	/**
	 * Step timeout in seconds (5 minutes).
	 *
	 * @var int
	 */
	const STEP_TIMEOUT = 300;

	/**
	 * Run a scheduled backup (cron callback).
	 */
	public static function run_scheduled() {
		$runner = new self();
		$runner->run();
	}

	/**
	 * Run the backup process.
	 *
	 * @return array Result with status, message, and details.
	 */
	public function run() {
		// Check for lock.
		if ( get_transient( self::LOCK_KEY ) ) {
			return array(
				'status'  => 'skipped',
				'message' => 'A backup is already in progress.',
			);
		}

		// Set lock (expires in 30 minutes max).
		set_transient( self::LOCK_KEY, time(), 1800 );

		$start_time   = time();
		$settings     = WGB_Settings::get_all();
		$token        = WGB_Settings::get_token();
		$errors       = array();
		$files_pushed = 0;
		$total_size   = 0;

		// Validate configuration.
		if ( empty( $token ) || empty( $settings['github_username'] ) || empty( $settings['repo_name'] ) ) {
			delete_transient( self::LOCK_KEY );
			return array(
				'status'  => 'error',
				'message' => 'GitHub settings are not configured.',
			);
		}

		$github = new WGB_GitHub_API( $token, $settings['github_username'], $settings['repo_name'] );

		// Ensure the GitHub repo exists (auto-create if needed).
		$repo_check = $github->ensure_repo_exists();
		if ( is_wp_error( $repo_check ) ) {
			delete_transient( self::LOCK_KEY );
			return array(
				'status'  => 'error',
				'message' => 'GitHub repo error: ' . $repo_check->get_error_message(),
			);
		}

		// Build commit message parts.
		$parts = array(
			'DB: ' . ( '1' === $settings['include_db'] ? 'yes' : 'no' ),
			'Themes: ' . ( '1' === $settings['include_themes'] ? 'yes' : 'no' ),
			'Plugins: ' . ( '1' === $settings['include_plugins'] ? 'yes' : 'no' ),
			'Uploads: ' . ( '1' === $settings['include_uploads'] ? 'yes' : 'no' ),
			'Posts: ' . ( '1' === $settings['include_posts'] ? 'yes' : 'no' ),
			'Pages: ' . ( '1' === $settings['include_pages'] ? 'yes' : 'no' ),
		);
		$commit_message = 'Backup ' . gmdate( 'Y-m-d H:i' ) . ' — ' . implode( ', ', $parts );

		// Step 1: Database backup.
		if ( '1' === $settings['include_db'] ) {
			$db_result = $this->run_step( 'database', function () use ( $github, $commit_message ) {
				$export = WGB_DB_Export::export();

				if ( is_wp_error( $export ) ) {
					return $export;
				}

				$result = $github->push_file(
					$export['filename'],
					$export['content'],
					$commit_message
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'size'  => strlen( $export['content'] ),
					'count' => 1,
				);
			} );

			if ( is_wp_error( $db_result ) ) {
				$errors[] = 'Database: ' . $db_result->get_error_message();
			} else {
				$files_pushed += $db_result['count'];
				$total_size   += $db_result['size'];
			}
		}

		// Step 2: Themes backup.
		if ( '1' === $settings['include_themes'] ) {
			$result = $this->backup_files( 'themes', $github, $commit_message, $settings );
			if ( is_wp_error( $result ) ) {
				$errors[] = 'Themes: ' . $result->get_error_message();
			} else {
				$files_pushed += $result['count'];
				$total_size   += $result['size'];
			}
		}

		// Step 3: Plugins backup.
		if ( '1' === $settings['include_plugins'] ) {
			$result = $this->backup_files( 'plugins', $github, $commit_message, $settings );
			if ( is_wp_error( $result ) ) {
				$errors[] = 'Plugins: ' . $result->get_error_message();
			} else {
				$files_pushed += $result['count'];
				$total_size   += $result['size'];
			}
		}

		// Step 4: Uploads backup.
		if ( '1' === $settings['include_uploads'] ) {
			$result = $this->backup_files( 'uploads', $github, $commit_message, $settings );
			if ( is_wp_error( $result ) ) {
				$errors[] = 'Uploads: ' . $result->get_error_message();
			} else {
				$files_pushed += $result['count'];
				$total_size   += $result['size'];
			}
		}

		// Step 5: Posts backup.
		if ( '1' === $settings['include_posts'] ) {
			$result = $this->backup_content( 'posts', $github, $commit_message );
			if ( is_wp_error( $result ) ) {
				$errors[] = 'Posts: ' . $result->get_error_message();
			} else {
				$files_pushed += $result['count'];
				$total_size   += $result['size'];
			}
		}

		// Step 6: Pages backup.
		if ( '1' === $settings['include_pages'] ) {
			$result = $this->backup_content( 'pages', $github, $commit_message );
			if ( is_wp_error( $result ) ) {
				$errors[] = 'Pages: ' . $result->get_error_message();
			} else {
				$files_pushed += $result['count'];
				$total_size   += $result['size'];
			}
		}

		// Step 7: Retention cleanup.
		$this->run_retention_cleanup( $github, (int) $settings['retention_days'] );

		// Calculate duration.
		$duration = time() - $start_time;
		$status   = empty( $errors ) ? 'success' : ( $files_pushed > 0 ? 'partial' : 'failed' );

		// Log result.
		$this->log_backup( $status, $files_pushed, $total_size, $errors, $duration );

		// Send notification.
		$this->send_notification( $settings['notification_email'], $status, $files_pushed, $total_size, $errors, $duration );

		// Release lock.
		delete_transient( self::LOCK_KEY );

		return array(
			'status'       => $status,
			'files_pushed' => $files_pushed,
			'total_size'   => $total_size,
			'errors'       => $errors,
			'duration'     => $duration,
		);
	}

	/**
	 * Backup files of a specific type (themes, plugins, uploads).
	 *
	 * @param string       $type           Type: themes, plugins, or uploads.
	 * @param WGB_GitHub_API $github        GitHub API instance.
	 * @param string       $commit_message Commit message.
	 * @param array        $settings       Plugin settings.
	 * @return array|WP_Error Result array or error.
	 */
	private function backup_files( $type, $github, $commit_message, $settings ) {
		return $this->run_step( $type, function () use ( $type, $github, $commit_message, $settings ) {
			$collector = new WGB_File_Collector( WGB_Settings::get_excluded_folders() );

			switch ( $type ) {
				case 'themes':
					$zip_results = $collector->collect_themes();
					break;
				case 'plugins':
					$zip_results = $collector->collect_plugins();
					break;
				case 'uploads':
					$zip_results = $collector->collect_uploads();
					break;
				default:
					return new WP_Error( 'invalid_type', __( 'Invalid backup type.', 'wp-github-backup' ) );
			}

			if ( is_wp_error( $zip_results ) ) {
				return $zip_results;
			}

			// Read every zip into memory once, then push in a single
			// commit via the Git Data API. This replaces the per-file
			// Contents API loop that turned a single backup into N
			// commits and burned 2N API calls (GET sha + PUT).
			$batch      = array();
			$total_size = 0;

			foreach ( $zip_results as $zip ) {
				$content = file_get_contents( $zip['local_path'] );

				if ( false === $content ) {
					continue;
				}

				$batch[] = array(
					'path'    => $zip['repo_path'],
					'content' => $content,
				);
				$total_size += strlen( $content );
			}

			$count = 0;
			if ( ! empty( $batch ) ) {
				$branch = WGB_Settings::get( 'deploy_branch', '' );
				if ( empty( $branch ) ) {
					$default = $github->get_default_branch();
					$branch  = is_wp_error( $default ) ? 'main' : $default;
				}

				$result = $github->commit_batch( $batch, $commit_message, $branch );
				if ( ! is_wp_error( $result ) ) {
					$count = isset( $result['pushed_count'] ) ? (int) $result['pushed_count'] : 0;
				}
			}

			// Cleanup temp files.
			WGB_File_Collector::cleanup( $zip_results );

			return array(
				'count' => $count,
				'size'  => $total_size,
			);
		} );
	}

	/**
	 * Backup content (posts or pages) as individual HTML files.
	 *
	 * @param string         $type           Type: 'posts' or 'pages'.
	 * @param WGB_GitHub_API $github         GitHub API instance.
	 * @param string         $commit_message Commit message.
	 * @return array|WP_Error Result array or error.
	 */
	private function backup_content( $type, $github, $commit_message ) {
		return $this->run_step( $type, function () use ( $type, $github, $commit_message ) {
			if ( 'posts' === $type ) {
				$files = WGB_Content_Export::export_posts();
			} else {
				$files = WGB_Content_Export::export_pages();
			}

			$total_size = 0;
			$batch      = array();

			foreach ( $files as $file ) {
				$batch[]     = array(
					'path'    => $file['repo_path'],
					'content' => $file['content'],
				);
				$total_size += strlen( $file['content'] );
			}

			$count = 0;
			if ( ! empty( $batch ) ) {
				$branch = WGB_Settings::get( 'deploy_branch', '' );
				if ( empty( $branch ) ) {
					$default = $github->get_default_branch();
					$branch  = is_wp_error( $default ) ? 'main' : $default;
				}

				$result = $github->commit_batch( $batch, $commit_message, $branch );
				if ( ! is_wp_error( $result ) ) {
					$count = isset( $result['pushed_count'] ) ? (int) $result['pushed_count'] : 0;
				}
			}

			return array(
				'count' => $count,
				'size'  => $total_size,
			);
		} );
	}

	/**
	 * Run a backup step with timeout protection.
	 *
	 * @param string   $step_name Step name for progress tracking.
	 * @param callable $callback  Step callback.
	 * @return mixed Callback result or WP_Error on timeout.
	 */
	private function run_step( $step_name, $callback ) {
		$progress_key = 'wgb_step_' . $step_name;
		$start        = time();

		update_option( $progress_key, array(
			'started' => $start,
			'status'  => 'running',
		) );

		$result = call_user_func( $callback );

		$elapsed = time() - $start;

		// Only treat as timeout if the step actually failed AND exceeded the
		// time limit. A successful result that took a long time is still valid.
		if ( is_wp_error( $result ) && $elapsed > self::STEP_TIMEOUT ) {
			update_option( $progress_key, array(
				'started' => $start,
				'status'  => 'timeout',
			) );
			return new WP_Error( 'step_timeout', "Step '{$step_name}' exceeded timeout of " . self::STEP_TIMEOUT . ' seconds.' );
		}

		update_option( $progress_key, array(
			'started'   => $start,
			'completed' => time(),
			'status'    => 'completed',
		) );

		return $result;
	}

	/**
	 * Run retention cleanup — delete backup files older than retention period.
	 *
	 * @param WGB_GitHub_API $github         GitHub API instance.
	 * @param int            $retention_days Number of days to keep backups.
	 */
	private function run_retention_cleanup( $github, $retention_days ) {
		if ( $retention_days <= 0 ) {
			return;
		}

		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-' . $retention_days . ' days' ) );
		$directories = array( 'database', 'themes', 'plugins', 'uploads', 'posts', 'pages' );

		foreach ( $directories as $dir ) {
			$files = $github->list_files( $dir );

			if ( is_wp_error( $files ) || empty( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				if ( ! isset( $file['name'], $file['sha'] ) ) {
					continue;
				}

				// Extract date from filename (e.g., backup-2024-01-15-120000.sql.gz).
				if ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $file['name'], $matches ) ) {
					$file_date = $matches[1];
					if ( $file_date < $cutoff_date ) {
						$github->delete_file( $dir . '/' . $file['name'], $file['sha'] );
					}
				}
			}
		}

		// Prune the local log tables on the same retention window. Without this
		// {prefix}github_backup_log and {prefix}github_deploy_log grow forever.
		$this->prune_log_tables( $retention_days );
	}

	/**
	 * Delete rows older than $retention_days from both log tables.
	 *
	 * @param int $retention_days Days to keep.
	 */
	private function prune_log_tables( $retention_days ) {
		if ( $retention_days <= 0 ) {
			return;
		}
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $retention_days . ' days' ) );

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"DELETE FROM {$wpdb->prefix}github_backup_log WHERE backup_date < %s",
			$cutoff
		) );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"DELETE FROM {$wpdb->prefix}github_deploy_log WHERE deploy_date < %s",
			$cutoff
		) );
	}

	/**
	 * Log backup result to the database.
	 *
	 * @param string $status       Backup status.
	 * @param int    $files_pushed Number of files pushed.
	 * @param int    $total_size   Total size in bytes.
	 * @param array  $errors       Array of error messages.
	 * @param int    $duration     Duration in seconds.
	 */
	private function log_backup( $status, $files_pushed, $total_size, $errors, $duration ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'github_backup_log';

		$wpdb->insert(
			$table_name,
			array(
				'backup_date'  => current_time( 'mysql' ),
				'status'       => $status,
				'files_pushed' => $files_pushed,
				'total_size'   => $total_size,
				'errors'       => ! empty( $errors ) ? wp_json_encode( $errors ) : null,
				'duration'     => $duration,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d' )
		);
	}

	/**
	 * Send backup notification email.
	 *
	 * @param string $email        Email address.
	 * @param string $status       Backup status.
	 * @param int    $files_pushed Number of files pushed.
	 * @param int    $total_size   Total size in bytes.
	 * @param array  $errors       Array of error messages.
	 * @param int    $duration     Duration in seconds.
	 */
	private function send_notification( $email, $status, $files_pushed, $total_size, $errors, $duration ) {
		if ( empty( $email ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[%s] GitHub Backup %s', $site_name, ucfirst( $status ) );

		$message  = sprintf( "GitHub Backup Report for %s\n\n", $site_name );
		$message .= sprintf( "Status: %s\n", ucfirst( $status ) );
		$message .= sprintf( "Date: %s\n", current_time( 'Y-m-d H:i:s' ) );
		$message .= sprintf( "Files Pushed: %d\n", $files_pushed );
		$message .= sprintf( "Total Size: %s\n", size_format( $total_size ) );
		$message .= sprintf( "Duration: %d seconds\n", $duration );

		if ( ! empty( $errors ) ) {
			$message .= "\nErrors:\n";
			foreach ( $errors as $error ) {
				$message .= "- {$error}\n";
			}
		}

		wp_mail( $email, $subject, $message );
	}

	/**
	 * Get the last backup log entry.
	 *
	 * @return object|null Last log entry or null.
	 */
	public static function get_last_backup() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'github_backup_log';

		return $wpdb->get_row(
			"SELECT * FROM {$table_name} ORDER BY backup_date DESC LIMIT 1"
		);
	}

	/**
	 * Get backup history.
	 *
	 * @param int $limit  Number of entries.
	 * @param int $offset Offset for pagination.
	 * @return array Array of log entries.
	 */
	public static function get_history( $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'github_backup_log';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} ORDER BY backup_date DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Get total backup count.
	 *
	 * @return int
	 */
	public static function get_total_count() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'github_backup_log';

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}
}
