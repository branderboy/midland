<?php
/**
 * File collector class for WP GitHub Backup.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_File_Collector {

	/**
	 * Maximum single file size to include (50MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 52428800;

	/**
	 * Maximum zip size before splitting (80MB).
	 *
	 * @var int
	 */
	const MAX_ZIP_SIZE = 83886080;

	/**
	 * Number of files added between flush-and-stat checkpoints.
	 *
	 * Closing the ZipArchive between batches forces ZipArchive to flush
	 * pending entries to disk so filesize() returns the true on-disk
	 * size, and releases the per-entry metadata buffer. Smaller values
	 * give tighter split accuracy at the cost of more open/close churn;
	 * 50 is a balance that keeps memory bounded on shared hosts without
	 * making the zip step noticeably slower.
	 *
	 * @var int
	 */
	const FLUSH_BATCH = 50;

	/**
	 * Number of files inspected for the disk-space pre-flight estimate.
	 * Capped so a 30k-file uploads tree does not turn the safety check
	 * into a measurable share of the request.
	 *
	 * @var int
	 */
	const SIZE_PROBE_LIMIT = 1000;

	/**
	 * Excluded folder names.
	 *
	 * @var array
	 */
	private $excluded_folders;

	/**
	 * Constructor.
	 *
	 * @param array $excluded_folders Folders to exclude.
	 */
	public function __construct( $excluded_folders = array() ) {
		$this->excluded_folders = $excluded_folders;
	}

	/**
	 * Collect and zip the active theme.
	 *
	 * @return array|WP_Error Array of zip file paths and repo paths, or error.
	 */
	public function collect_themes() {
		$theme_dir = get_stylesheet_directory();

		if ( ! is_dir( $theme_dir ) ) {
			return new WP_Error( 'theme_not_found', __( 'Active theme directory not found.', 'wp-github-backup' ) );
		}

		$date    = gmdate( 'Y-m-d' );
		$zip_name = 'themes-' . $date . '.zip';

		return $this->create_zip( $theme_dir, $zip_name, 'themes/' );
	}

	/**
	 * Collect and zip all plugins.
	 *
	 * @return array|WP_Error Array of zip file paths and repo paths, or error.
	 */
	public function collect_plugins() {
		$plugins_dir = WP_PLUGIN_DIR;

		if ( ! is_dir( $plugins_dir ) ) {
			return new WP_Error( 'plugins_not_found', __( 'Plugins directory not found.', 'wp-github-backup' ) );
		}

		$date    = gmdate( 'Y-m-d' );
		$zip_name = 'plugins-' . $date . '.zip';

		return $this->create_zip( $plugins_dir, $zip_name, 'plugins/' );
	}

	/**
	 * Collect and zip the uploads directory.
	 *
	 * @return array|WP_Error Array of zip file paths and repo paths, or error.
	 */
	public function collect_uploads() {
		$uploads_dir = wp_upload_dir();
		$basedir     = $uploads_dir['basedir'];

		if ( ! is_dir( $basedir ) ) {
			return new WP_Error( 'uploads_not_found', __( 'Uploads directory not found.', 'wp-github-backup' ) );
		}

		$date    = gmdate( 'Y-m-d' );
		$zip_name = 'uploads-' . $date . '.zip';

		return $this->create_zip( $basedir, $zip_name, 'uploads/' );
	}

	/**
	 * Create a zip archive of a directory.
	 *
	 * Streams every file from disk via ZipArchive::addFile so PHP memory
	 * stays flat regardless of source size. Closes-and-reopens the zip
	 * every FLUSH_BATCH files to flush pending entries, then uses
	 * filesize() on the actual zip path to decide whether to start a
	 * new .partN.zip. This replaces the pre-3.4.4 implementation that
	 * loaded each file into memory via WP_Filesystem::get_contents and
	 * passed it to addFromString, which OOM-killed backups on hosts
	 * with a 256MB memory limit and a 200MB+ plugins tree.
	 *
	 * @param string $source_dir Directory to zip.
	 * @param string $zip_name   Base zip filename.
	 * @param string $repo_dir   Repository directory prefix.
	 * @return array|WP_Error Array of {local_path, repo_path} items, or error.
	 */
	private function create_zip( $source_dir, $zip_name, $repo_dir ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'ZipArchive extension is not available.', 'wp-github-backup' ) );
		}

		$temp_root = sys_get_temp_dir();

		// Pre-flight: bail before we start writing if the temp partition
		// clearly cannot hold even a rough estimate of the source size.
		// Without this the zip step gets a fseek/fwrite error halfway
		// through and leaves an unreadable partial zip on disk.
		$source_estimate = $this->estimate_source_size( $source_dir );
		$free_space      = @disk_free_space( $temp_root );

		if ( false !== $free_space && $source_estimate > 0 && $free_space < ( $source_estimate * 2 ) ) {
			return new WP_Error(
				'temp_disk_full',
				sprintf(
					/* translators: 1: bytes free in temp dir, 2: estimated bytes the source needs */
					__( 'Not enough free space in %1$s to build the zip. %2$s available, ~%3$s required (estimate). Free up disk or change PHP\'s temp dir before retrying.', 'wp-github-backup' ),
					$temp_root,
					size_format( $free_space ),
					size_format( $source_estimate * 2 )
				)
			);
		}

		$temp_dir = $temp_root . '/wgb_' . uniqid();
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'temp_mkdir_failed', __( 'Could not create temp directory for zip.', 'wp-github-backup' ) );
		}

		$files    = $this->get_directory_files( $source_dir );
		$results  = array();
		$part     = 1;
		$zip_path = $this->build_part_path( $temp_dir, $zip_name, $part );
		$source_dir_trim = rtrim( $source_dir, '/\\' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_create_failed', __( 'Failed to create zip archive.', 'wp-github-backup' ) );
		}

		$batch_count = 0;
		$added_total = 0;

		foreach ( $files as $file ) {
			$relative_path = ltrim( str_replace( $source_dir_trim, '', $file ), '/\\' );
			$relative_path = str_replace( '\\', '/', $relative_path );

			if ( '' === $relative_path ) {
				continue;
			}

			// addFile registers the file with the archive; ZipArchive
			// streams the bytes off disk during close(). Memory cost is
			// the per-entry struct (a few hundred bytes), not the file
			// content, so a 50 MB media file does not move the needle.
			if ( ! $zip->addFile( $file, $relative_path ) ) {
				continue;
			}

			$batch_count++;
			$added_total++;

			// Flush + size-check every FLUSH_BATCH files. close() forces
			// the entries to disk so filesize() is accurate; if we are
			// over MAX_ZIP_SIZE we keep the part as-is and roll to the
			// next .partN.zip. Otherwise we reopen the same path in
			// append mode and keep adding.
			if ( $batch_count >= self::FLUSH_BATCH ) {
				$zip->close();
				$batch_count = 0;

				$current_size = file_exists( $zip_path ) ? filesize( $zip_path ) : 0;

				if ( $current_size >= self::MAX_ZIP_SIZE ) {
					$results[] = array(
						'local_path' => $zip_path,
						'repo_path'  => $repo_dir . basename( $zip_path ),
					);

					$part++;
					$zip_path = $this->build_part_path( $temp_dir, $zip_name, $part );

					$zip = new ZipArchive();
					if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
						return new WP_Error( 'zip_create_failed', __( 'Failed to create zip archive.', 'wp-github-backup' ) );
					}
				} else {
					$zip = new ZipArchive();
					if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
						return new WP_Error( 'zip_reopen_failed', __( 'Failed to reopen zip archive for append.', 'wp-github-backup' ) );
					}
				}
			}
		}

		// Close the last (possibly partial) batch and only keep the
		// final part if it actually has bytes. ZipArchive will refuse
		// to write an empty archive on some PHP builds, so guard with
		// numFiles() as well.
		$last_count = $zip->numFiles;
		$zip->close();

		if ( $last_count > 0 && file_exists( $zip_path ) && filesize( $zip_path ) > 0 ) {
			$results[] = array(
				'local_path' => $zip_path,
				'repo_path'  => $repo_dir . basename( $zip_path ),
			);
		} elseif ( file_exists( $zip_path ) ) {
			// Empty trailing zip from a run that ended exactly on a
			// flush boundary — clean it up so cleanup() does not try
			// to push a 0-byte file.
			wp_delete_file( $zip_path );
		}

		if ( empty( $results ) ) {
			// Nothing was added and the temp dir is now junk.
			if ( is_dir( $temp_dir ) ) {
				$leftovers = glob( $temp_dir . '/*' );
				if ( ! empty( $leftovers ) ) {
					foreach ( $leftovers as $leftover ) {
						wp_delete_file( $leftover );
					}
				}
				@rmdir( $temp_dir );
			}
			return new WP_Error( 'no_files', 'No files found to zip in ' . $source_dir );
		}

		return $results;
	}

	/**
	 * Build the on-disk path for a given part number. Part 1 keeps the
	 * base name; parts 2+ get a .partN.zip suffix so the order is
	 * obvious in the GitHub repo.
	 *
	 * @param string $temp_dir Temp directory.
	 * @param string $zip_name Base zip filename ending in .zip.
	 * @param int    $part     1-indexed part number.
	 * @return string Absolute path to the zip file.
	 */
	private function build_part_path( $temp_dir, $zip_name, $part ) {
		$part_suffix  = ( $part > 1 ) ? '.part' . $part : '';
		$current_name = str_replace( '.zip', $part_suffix . '.zip', $zip_name );
		return $temp_dir . '/' . $current_name;
	}

	/**
	 * Rough size estimate for the source directory. Walks at most
	 * SIZE_PROBE_LIMIT files and sums their on-disk sizes. Used only
	 * for the pre-flight free-space sanity check, so the answer being
	 * an under- or over-estimate is acceptable: the goal is to catch
	 * obviously-doomed runs (e.g. 50 MB free in /tmp), not to predict
	 * compressed zip size.
	 *
	 * @param string $dir Source directory.
	 * @return int Size in bytes.
	 */
	private function estimate_source_size( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$total   = 0;
		$counted = 0;

		try {
			$iterator = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
			$walker   = new RecursiveIteratorIterator( $iterator, RecursiveIteratorIterator::LEAVES_ONLY );
		} catch ( \Exception $e ) {
			return 0;
		}

		foreach ( $walker as $item ) {
			if ( $counted >= self::SIZE_PROBE_LIMIT ) {
				break;
			}

			if ( $item->isFile() ) {
				$size = $item->getSize();
				if ( false !== $size ) {
					$total += (int) $size;
				}
				$counted++;
			}
		}

		return $total;
	}

	/**
	 * Recursively get all files in a directory, respecting exclusions.
	 *
	 * @param string $dir Directory path.
	 * @return array Array of file paths.
	 */
	private function get_directory_files( $dir ) {
		$files     = array();
		$dir_trim  = rtrim( $dir, '/\\' );

		try {
			$iterator = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
			$walker   = new RecursiveIteratorIterator( $iterator, RecursiveIteratorIterator::SELF_FIRST );
		} catch ( \Exception $e ) {
			return $files;
		}

		foreach ( $walker as $item ) {
			// Skip directories.
			if ( $item->isDir() ) {
				continue;
			}

			$path     = $item->getPathname();
			$relative = ltrim( str_replace( $dir_trim, '', $path ), '/\\' );
			$relative = str_replace( '\\', '/', $relative );

			// Skip .git and any nested .git directory contents.
			if ( '.git' === $relative || 0 === strpos( $relative, '.git/' ) || false !== strpos( $relative, '/.git/' ) ) {
				continue;
			}

			// Skip excluded folders. Match only on full path segments
			// so an exclusion of "cache" does NOT swallow a real
			// directory named "cached-files" or a file "cache.php".
			$skip   = false;
			$padded = '/' . $relative . '/';
			foreach ( $this->excluded_folders as $excluded ) {
				$excluded = trim( $excluded, " \t\n\r\0\x0B/\\" );
				if ( '' === $excluded ) {
					continue;
				}
				if ( false !== strpos( $padded, '/' . $excluded . '/' ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			// Skip files larger than 50MB.
			if ( $item->getSize() > self::MAX_FILE_SIZE ) {
				continue;
			}

			$files[] = $path;
		}

		return $files;
	}

	/**
	 * Clean up temporary files.
	 *
	 * @param array $results Array of results from create_zip.
	 */
	public static function cleanup( $results ) {
		if ( ! is_array( $results ) ) {
			return;
		}

		foreach ( $results as $result ) {
			if ( isset( $result['local_path'] ) && file_exists( $result['local_path'] ) ) {
				$dir = dirname( $result['local_path'] );
				wp_delete_file( $result['local_path'] );

				// Remove temp directory if empty.
				if ( is_dir( $dir ) ) {
					$remaining = glob( $dir . '/*' );
					if ( empty( $remaining ) ) {
						rmdir( $dir );
					}
				}
			}
		}
	}
}
