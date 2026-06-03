<?php
/**
 * Restore info page class for WP GitHub Backup.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Restore {

	/**
	 * Get available backups from the GitHub repository.
	 *
	 * @return array Array of backup entries grouped by directory.
	 */
	public static function get_available_backups() {
		$token    = WGB_Settings::get_token();
		$username = WGB_Settings::get( 'github_username' );
		$repo     = WGB_Settings::get( 'repo_name' );

		if ( empty( $token ) || empty( $username ) || empty( $repo ) ) {
			return array();
		}

		$github      = new WGB_GitHub_API( $token, $username, $repo );
		$directories = array( 'database', 'themes', 'plugins', 'uploads' );
		$backups     = array();

		foreach ( $directories as $dir ) {
			$files = $github->list_files( $dir );

			if ( is_wp_error( $files ) || empty( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				if ( ! isset( $file['name'] ) ) {
					continue;
				}

				$date = '';
				if ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $file['name'], $matches ) ) {
					$date = $matches[1];
				}

				$backups[] = array(
					'directory'    => $dir,
					'name'         => $file['name'],
					'path'         => $file['path'] ?? $dir . '/' . $file['name'],
					'size'         => $file['size'] ?? 0,
					'date'         => $date,
					'download_url' => $file['download_url'] ?? '',
					'sha'          => $file['sha'] ?? '',
				);
			}
		}

		// Sort by date descending.
		usort( $backups, function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		} );

		return $backups;
	}

	/**
	 * Render the restore instructions HTML.
	 *
	 * @return string HTML content.
	 */
	public static function get_restore_instructions() {
		ob_start();
		?>
		<div class="wgb-restore-instructions">
			<h3>How to Restore from a GitHub Backup</h3>
			<ol>
				<li>
					<strong>Download backup files from GitHub</strong>
					<p>Click the "Download" links above for the backup files you want to restore.</p>
				</li>
				<li>
					<strong>Restore the Database</strong>
					<p>Decompress the <code>.sql.gz</code> file using <code>gunzip</code> or a tool like 7-Zip.
					Import the resulting <code>.sql</code> file using phpMyAdmin or the MySQL command line:
					<code>mysql -u username -p database_name &lt; backup.sql</code></p>
				</li>
				<li>
					<strong>Restore Themes &amp; Plugins</strong>
					<p>Unzip the theme and plugin archives into your <code>wp-content/</code> directory,
					overwriting the existing folders as needed.</p>
				</li>
				<li>
					<strong>Restore Uploads</strong>
					<p>If you backed up uploads, unzip the uploads archive into <code>wp-content/uploads/</code>.</p>
				</li>
				<li>
					<strong>Verify Your Site</strong>
					<p>Visit your site and WordPress admin to confirm everything is working correctly.
					You may need to re-save permalinks under Settings &rarr; Permalinks.</p>
				</li>
			</ol>
			<p><em>Automated one-click restore is planned for a future update.</em></p>
		</div>
		<?php
		return ob_get_clean();
	}
}
