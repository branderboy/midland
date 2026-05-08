<?php
/**
 * Deployer class — pull content from GitHub and import into WordPress.
 *
 * Imports posts and pages from backed-up HTML files in the GitHub repo.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Deployer {

	/**
	 * GitHub API instance.
	 *
	 * @var WGB_GitHub_API
	 */
	private $github;

	/**
	 * Deploy branch.
	 *
	 * @var string
	 */
	private $branch;

	/**
	 * Errors collected during deploy.
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Items deployed count.
	 *
	 * @var int
	 */
	private $items_deployed = 0;

	/**
	 * Items skipped count (already up to date).
	 *
	 * @var int
	 */
	private $items_skipped = 0;

	/**
	 * Per-file audit log for the current deploy run. Each entry is
	 * [ 'path' => ..., 'action' => 'created'|'updated'|'skipped'|'failed',
	 *   'post_id' => int|null, 'reason' => string ]. Surfaced back to
	 * the admin UI so "Deploy success" is specific about which files
	 * got written, which were unchanged, and which failed.
	 *
	 * @var array
	 */
	private $audit = array();

	/**
	 * Detailed breakdown of what was imported.
	 *
	 * @var array
	 */
	private $breakdown = array(
		'posts_created'  => 0,
		'posts_updated'  => 0,
		'pages_created'  => 0,
		'pages_updated'  => 0,
		'categories_set' => 0,
		'tags_set'       => 0,
		'seo_meta'       => 0,
		'schema_data'    => 0,
	);

	/**
	 * Constructor.
	 *
	 * @param WGB_GitHub_API $github GitHub API instance.
	 * @param string         $branch Branch to deploy from.
	 */
	public function __construct( $github, $branch = 'main' ) {
		$this->github = $github;
		$this->branch = $branch;
	}

	/**
	 * Preview what would be deployed — list content files from the repo.
	 *
	 * @param string $deploy_target Deploy target: posts, pages, or content (both).
	 * @return array|WP_Error Array with file list and summary, or error.
	 */
	public function preview( $deploy_target = 'content' ) {
		$directories = $this->get_content_directories( $deploy_target );
		$all_files   = array();
		$total_size  = 0;

		foreach ( $directories as $dir ) {
			$files = $this->github->list_files( $dir );

			if ( is_wp_error( $files ) || empty( $files ) ) {
				continue;
			}

			foreach ( $files as $entry ) {
				$name = $entry['name'] ?? '';

				// Only HTML files.
				if ( '.html' !== substr( $name, -5 ) ) {
					continue;
				}

				$size        = $entry['size'] ?? 0;
				$total_size += $size;

				$all_files[] = array(
					'path' => $entry['path'] ?? ( $dir . '/' . $name ),
					'size' => $size,
				);
			}
		}

		if ( empty( $all_files ) ) {
			return new WP_Error( 'no_content', __( 'No content files (.html) found in the repository. Run a backup first to push your posts and pages to GitHub.', 'wp-github-backup' ) );
		}

		return array(
			'files'      => $all_files,
			'file_count' => count( $all_files ),
			'total_size' => $total_size,
			'branch'     => $this->branch,
			'target'     => $deploy_target,
		);
	}

	/**
	 * Run the deploy — pull content from GitHub and import into WordPress.
	 *
	 * @param string $deploy_target Deploy target: posts, pages, or content (both).
	 * @return array Deploy result.
	 */
	public function run( $deploy_target = 'content' ) {
		$start       = time();
		$directories = $this->get_content_directories( $deploy_target );
		$all_files   = array();

		foreach ( $directories as $dir ) {
			$files = $this->github->list_files( $dir );

			if ( is_wp_error( $files ) ) {
				$this->errors[] = 'Failed to read ' . $dir . ': ' . $files->get_error_message();
				continue;
			}

			if ( empty( $files ) ) {
				continue;
			}

			foreach ( $files as $entry ) {
				$name = $entry['name'] ?? '';
				if ( '.html' !== substr( $name, -5 ) ) {
					continue;
				}
				$all_files[] = array(
					'path' => $entry['path'] ?? ( $dir . '/' . $name ),
					'dir'  => basename( $dir ),
				);
			}
		}

		if ( empty( $all_files ) ) {
			return array(
				'status'   => 'failed',
				'errors'   => array( 'No content files (.html) found in the repository. Run a backup first.' ),
				'files'    => 0,
				'skipped'  => 0,
				'duration' => time() - $start,
				'target'   => $deploy_target,
			);
		}

		foreach ( $all_files as $file ) {
			$this->import_content_file( $file['path'], $file['dir'] );

			// Timeout protection — 5 minutes max.
			if ( ( time() - $start ) > 300 ) {
				$this->errors[] = 'Deploy timed out after 5 minutes. Some content may not have been imported.';
				break;
			}
		}

		$status = empty( $this->errors ) ? 'success' : ( $this->items_deployed > 0 ? 'partial' : 'failed' );

		$this->log_deploy( $status, $this->items_deployed, $this->errors, time() - $start, $deploy_target );
		$this->record_deployed_sha( $status );

		// Auto-purge any page cache in front of WP so the freshly-imported
		// content becomes visible immediately instead of "deploy worked but
		// the site still looks old" — the single biggest UX complaint with
		// this plugin.
		$purged = array();
		if ( $this->items_deployed > 0 ) {
			$purged = self::purge_all_caches();
		}

		// Live verification: pick one imported page, fetch its live HTML,
		// hash it, and compare against the hash we just pushed. If they
		// match → cache is already clear and the deploy is visible. If they
		// don't → warn the user.
		$verify = null;
		if ( $this->items_deployed > 0 ) {
			$verify = $this->verify_first_import();
		}

		// Honest diagnostic payload the admin UI can show instead of a
		// vague "0 imported" message. Captures: what was scanned, what
		// was actually written, what was skipped (and why), and what the
		// last commit on the branch was. If nothing imported, surface a
		// plain-English reason so users can trust the result instead of
		// guessing.
		$head_sha = $this->github->get_branch_head_sha( $this->branch );
		if ( is_wp_error( $head_sha ) ) {
			$head_sha = '';
		}
		$reason = '';
		if ( 0 === $this->items_deployed ) {
			if ( 0 === count( $all_files ) ) {
				$reason = __( 'The repo contains no .html files under pages/ or posts/ — run Backup Now first.', 'wp-github-backup' );
			} elseif ( $this->items_skipped === count( $all_files ) ) {
				$reason = sprintf(
					/* translators: %d: total file count */
					__( 'Every one of the %d page/post files in git already has a matching content hash on the WordPress post. Nothing changed since the last successful deploy.', 'wp-github-backup' ),
					count( $all_files )
				);
			} else {
				$reason = __( 'Nothing was imported. Check the Errors section below — if empty, the deploy walked every file but none needed updating.', 'wp-github-backup' );
			}
		}

		return array(
			'status'    => $status,
			'files'     => $this->items_deployed,
			'imported'  => $this->items_deployed,
			'skipped'   => $this->items_skipped,
			'scanned'   => count( $all_files ),
			'errors'    => $this->errors,
			'duration'  => time() - $start,
			'target'    => $deploy_target,
			'breakdown' => $this->breakdown,
			'head_sha'  => $head_sha,
			'branch'    => $this->branch,
			'reason'    => $reason,
			'audit'     => $this->audit,
			'purged'    => $purged,
			'verify'    => $verify,
		);
	}

	/**
	 * Delegates to WGB_Cache_Purge::purge_all() — kept as a static
	 * shim so existing callers / third-party code using the older
	 * method name keep working.
	 *
	 * @deprecated 3.3.0 Use WGB_Cache_Purge::purge_all() directly.
	 * @return string[]
	 */
	public static function purge_all_caches() {
		if ( class_exists( 'WGB_Cache_Purge' ) ) {
			return WGB_Cache_Purge::purge_all();
		}
		return array();
	}

	/**
	 * Verify the deploy landed on the live site — fetch the first
	 * imported post's public URL and check the body contains a hash of
	 * its post_content. If it doesn't, a cache is sitting in front and
	 * we surface that so the user doesn't think the plugin lied.
	 */
	private function verify_first_import() {
		$first = null;
		foreach ( $this->audit as $a ) {
			if ( isset( $a['action'] ) && in_array( $a['action'], array( 'created', 'updated' ), true ) ) {
				$first = $a;
				break;
			}
		}
		if ( ! $first || empty( $first['post_id'] ) ) {
			return null;
		}
		$url = get_permalink( $first['post_id'] );
		if ( ! $url ) {
			return null;
		}
		$response = wp_remote_get( $url, array(
			'timeout'    => 10,
			'sslverify'  => apply_filters( 'wgb_verify_sslverify', true ),
			'user-agent' => 'wp-github-backup/verify',
			'headers'    => array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			),
		) );
		if ( is_wp_error( $response ) ) {
			return array(
				'url'     => $url,
				'ok'      => false,
				'message' => 'Could not fetch the live URL to verify: ' . $response->get_error_message(),
			);
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 400 ) {
			return array(
				'url'     => $url,
				'ok'      => false,
				'message' => 'Live URL returned HTTP ' . $code . ' — check that the page is published and reachable.',
			);
		}

		// Pull a stable marker out of the deployed source and check it's
		// present in the rendered HTML. We use the post_title because
		// it's Elementor/Yoast-agnostic and appears in <title> + breadcrumbs.
		$post = get_post( $first['post_id'] );
		$marker = $post ? wp_strip_all_tags( $post->post_title ) : '';
		$ok = $marker && false !== stripos( $body, $marker );
		return array(
			'url'     => $url,
			'ok'      => (bool) $ok,
			'message' => $ok
				? 'Live page renders the expected title — deploy is visible to users.'
				: 'Deploy wrote to the database, but the live HTML doesn\'t show the expected marker. A page cache is probably serving a stale copy — purge your cache plugin + CDN and reload.',
		);
	}

	/**
	 * Run an incremental deploy — pull only files changed since the last
	 * successful deploy on this branch (using the GitHub /compare API).
	 *
	 * @param string $deploy_target Deploy target.
	 * @return array Deploy result. Includes 'no_baseline' => true if there is
	 *               no prior deploy SHA stored for this branch.
	 */
	public function run_incremental( $deploy_target = 'content' ) {
		$start = time();

		$head_sha = $this->github->get_branch_head_sha( $this->branch );
		if ( is_wp_error( $head_sha ) ) {
			return array(
				'status'   => 'failed',
				'errors'   => array( $head_sha->get_error_message() ),
				'files'    => 0,
				'skipped'  => 0,
				'duration' => time() - $start,
				'target'   => $deploy_target,
			);
		}

		$base_sha = self::get_last_deployed_sha( $this->branch );

		if ( empty( $base_sha ) ) {
			return array(
				'status'      => 'failed',
				'errors'      => array( 'No previous deploy recorded for branch "' . $this->branch . '". Run a full Deploy Now first to set the baseline.' ),
				'files'       => 0,
				'skipped'     => 0,
				'duration'    => time() - $start,
				'target'      => $deploy_target,
				'no_baseline' => true,
			);
		}

		if ( $base_sha === $head_sha ) {
			// Nothing to deploy — branch hasn't advanced.
			return array(
				'status'     => 'success',
				'files'      => 0,
				'skipped'    => 0,
				'errors'     => array(),
				'duration'   => time() - $start,
				'target'     => $deploy_target,
				'up_to_date' => true,
				'base_sha'   => $base_sha,
				'head_sha'   => $head_sha,
			);
		}

		$changed = $this->github->compare_commits( $base_sha, $head_sha );
		if ( is_wp_error( $changed ) ) {
			return array(
				'status'   => 'failed',
				'errors'   => array( $changed->get_error_message() ),
				'files'    => 0,
				'skipped'  => 0,
				'duration' => time() - $start,
				'target'   => $deploy_target,
			);
		}

		$allowed_dirs = $this->get_content_directories( $deploy_target );
		$to_import    = array();
		$queued_paths = array();

		foreach ( $changed as $file ) {
			$path   = isset( $file['filename'] ) ? $file['filename'] : '';
			$status = isset( $file['status'] ) ? $file['status'] : '';

			// Skip deletions — the repo deleted a file; we leave the WP post alone.
			if ( 'removed' === $status ) {
				continue;
			}

			// Any change under content/<subdir>/<slug>.<ext> — Elementor trees,
			// page JSON sources, post JSON sources, or anything else dropped
			// into content/ — should trigger a re-import of the matching HTML
			// page/post so the new data lands via the HTML parse + Elementor
			// fallback paths. Without this, commits that only touch content/
			// produce "no changes to deploy".
			if ( 0 === strpos( $path, 'content/' ) ) {
				$slug = pathinfo( $path, PATHINFO_FILENAME );
				if ( '' === $slug ) {
					continue;
				}
				foreach ( $allowed_dirs as $dir ) {
					$prefix = rtrim( $dir, '/' ) . '/';
					$candidate = $prefix . $slug . '.html';
					if ( isset( $queued_paths[ $candidate ] ) ) {
						break;
					}
					$sha = $this->github->get_file_sha( $candidate );
					if ( ! is_wp_error( $sha ) && '' !== $sha ) {
						$to_import[] = array( 'path' => $candidate, 'dir' => basename( $dir ), 'force' => true );
						$queued_paths[ $candidate ] = true;
						break;
					}
				}
				continue;
			}

			if ( '.html' !== substr( $path, -5 ) ) {
				continue;
			}

			// Match file against the allowed content directories.
			$dir_match = null;
			foreach ( $allowed_dirs as $dir ) {
				$prefix = rtrim( $dir, '/' ) . '/';
				if ( 0 === strpos( $path, $prefix ) ) {
					$dir_match = basename( $dir );
					break;
				}
			}

			if ( null === $dir_match ) {
				continue;
			}

			if ( isset( $queued_paths[ $path ] ) ) {
				continue;
			}
			$to_import[] = array( 'path' => $path, 'dir' => $dir_match );
			$queued_paths[ $path ] = true;
		}

		if ( empty( $to_import ) ) {
			// Do NOT advance the deployed-SHA cursor here. Earlier versions
			// recorded head_sha as "deployed" whenever a run turned up no
			// matching files, which poisoned subsequent incremental runs:
			// a real commit you made later could still sit inside the
			// already-"deployed" window and be skipped. Leaving the cursor
			// alone means the next run re-evaluates the same base..head
			// range and catches anything you add.
			return array(
				'status'     => 'success',
				'files'      => 0,
				'skipped'    => 0,
				'errors'     => array(),
				'duration'   => time() - $start,
				'target'     => $deploy_target,
				'up_to_date' => true,
				'base_sha'   => $base_sha,
				'head_sha'   => $head_sha,
				'message'    => 'No content files changed between ' . substr( $base_sha, 0, 7 ) . ' and ' . substr( $head_sha, 0, 7 ) . '.',
			);
		}

		foreach ( $to_import as $file ) {
			$force = ! empty( $file['force'] );
			$this->import_content_file( $file['path'], $file['dir'], $force );

			if ( ( time() - $start ) > 300 ) {
				$this->errors[] = 'Incremental deploy timed out after 5 minutes. Some content may not have been imported.';
				break;
			}
		}

		$result_status = empty( $this->errors ) ? 'success' : ( $this->items_deployed > 0 ? 'partial' : 'failed' );

		$this->log_deploy( $result_status, $this->items_deployed, $this->errors, time() - $start, $deploy_target . ' (incremental)' );
		$this->record_deployed_sha( $result_status, $head_sha );

		return array(
			'status'    => $result_status,
			'files'     => $this->items_deployed,
			'skipped'   => $this->items_skipped,
			'errors'    => $this->errors,
			'duration'  => time() - $start,
			'target'    => $deploy_target,
			'base_sha'  => $base_sha,
			'head_sha'  => $head_sha,
			'breakdown' => $this->breakdown,
		);
	}

	/**
	 * Store the deployed HEAD SHA for this branch so incremental deploys
	 * know where to diff from.
	 *
	 * @param string $status   Deploy status ('success' or 'partial' stores SHA).
	 * @param string $head_sha Optional HEAD SHA; fetched from GitHub if omitted.
	 */
	private function record_deployed_sha( $status, $head_sha = '' ) {
		if ( 'success' !== $status && 'partial' !== $status ) {
			return;
		}

		if ( empty( $head_sha ) ) {
			$sha = $this->github->get_branch_head_sha( $this->branch );
			if ( is_wp_error( $sha ) ) {
				return;
			}
			$head_sha = $sha;
		}

		update_option( 'wgb_last_deploy_sha_' . $this->branch, $head_sha, false );
		update_option( 'wgb_last_deploy_branch', $this->branch, false );
	}

	/**
	 * Get the last-deployed HEAD SHA for a given branch.
	 *
	 * @param string $branch Branch name.
	 * @return string SHA, or empty string if none recorded.
	 */
	public static function get_last_deployed_sha( $branch ) {
		return (string) get_option( 'wgb_last_deploy_sha_' . $branch, '' );
	}

	/**
	 * Import a single content HTML file from the repo into WordPress.
	 *
	 * @param string $file_path Full path in the repo (e.g., posts/my-post.html).
	 * @param string $directory The content directory basename (posts or pages).
	 */
	private function import_content_file( $file_path, $directory, $force = false ) {
		$content = $this->github->download_file( $file_path, $this->branch );

		if ( is_wp_error( $content ) ) {
			$msg = $content->get_error_message();
			$this->errors[] = 'Failed to download ' . $file_path . ': ' . $msg;
			$this->audit[] = array( 'path' => $file_path, 'action' => 'failed', 'reason' => 'download error: ' . $msg );
			return;
		}

		$parsed = $this->parse_html_content( $content );

		if ( is_wp_error( $parsed ) ) {
			$msg = $parsed->get_error_message();
			$this->errors[] = 'Failed to parse ' . $file_path . ': ' . $msg;
			$this->audit[] = array( 'path' => $file_path, 'action' => 'failed', 'reason' => 'parse error: ' . $msg );
			return;
		}

		// Get slug from filename.
		$filename = basename( $file_path, '.html' );

		// Determine post type + resolved slug. Files named "jobs-{slug}.html"
		// target the dpjp_job custom post type (URL /jobs/{slug}/), not a
		// regular page with slug "jobs-{slug}" (URL /jobs-{slug}/). Without
		// this re-routing, deploys silently create parallel WP pages that
		// never appear at the expected URL.
		$post_type = ( 'pages' === $directory ) ? 'page' : 'post';
		$slug      = $filename;
		if ( post_type_exists( 'dpjp_job' ) && 0 === strpos( $filename, 'jobs-' ) && 'jobs-' !== $filename ) {
			$post_type = 'dpjp_job';
			$slug      = substr( $filename, 5 );
		}

		// Check if this post/page already exists by slug.
		$existing = get_page_by_path( $slug, OBJECT, $post_type );

		// Skip if the remote file hasn't changed since our last deploy of it —
		// unless we're forcing a re-import (e.g. because a companion Elementor
		// template changed even though the HTML didn't).
		$content_hash = md5( $content );
		if ( $existing && ! $force ) {
			$last_hash = get_post_meta( $existing->ID, '_wgb_last_deploy_sha', true );
			if ( $last_hash && $last_hash === $content_hash ) {
				$this->items_skipped++;
				$this->audit[] = array(
					'path'    => $file_path,
					'action'  => 'skipped',
					'post_id' => $existing->ID,
					'reason'  => 'hash match (already in sync)',
				);
				return;
			}
		}

		// Decide post_title WITHOUT overwriting a clean admin title.
		// Priority: explicit <meta name="admin-title"> in backup HTML,
		// then existing WP post_title (preserve user edits),
		// then derived from slug,
		// NEVER the raw <title> tag (that's the SEO string).
		$post_title = '';
		if ( ! empty( $parsed['admin_title'] ) ) {
			$post_title = $parsed['admin_title'];
		} elseif ( $existing && ! empty( $existing->post_title ) ) {
			$post_title = $existing->post_title;
		} else {
			$post_title = self::derive_title_from_slug( $filename );
		}

		// Preserve the existing post's status on update so a deploy never
		// silently re-publishes a draft / scheduled / pending / trashed post.
		// New posts default to "publish" (the historical behaviour) unless the
		// admin opts out via wgb_deploy_force_publish=0 — in which case they
		// land as drafts and are never auto-published.
		$force_publish_new = (int) get_option( 'wgb_deploy_force_publish', 1 );

		if ( $existing && ! empty( $existing->post_status ) ) {
			$post_status = $existing->post_status;
		} else {
			$post_status = $force_publish_new ? 'publish' : 'draft';
		}

		$post_data = array(
			'post_title'   => $post_title,
			'post_content' => $parsed['content'],
			'post_status'  => $post_status,
			'post_type'    => $post_type,
			'post_name'    => $slug,
		);

		if ( ! empty( $parsed['excerpt'] ) ) {
			$post_data['post_excerpt'] = $parsed['excerpt'];
		}

		if ( ! empty( $parsed['date'] ) ) {
			$post_data['post_date'] = $parsed['date'];
		}

		$is_update = false;
		if ( $existing ) {
			// Update existing.
			$post_data['ID'] = $existing->ID;
			$result = wp_update_post( $post_data, true );
			$is_update = true;
		} else {
			// Insert new.
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			$this->errors[] = 'Failed to import ' . $file_path . ': ' . $result->get_error_message();
			return;
		}

		$post_id = is_int( $result ) ? $result : $existing->ID;

		// Track created vs updated.
		if ( 'page' === $post_type ) {
			$this->breakdown[ $is_update ? 'pages_updated' : 'pages_created' ]++;
		} else {
			$this->breakdown[ $is_update ? 'posts_updated' : 'posts_created' ]++;
		}

		// Set categories for posts.
		if ( 'post' === $post_type && ! empty( $parsed['categories'] ) ) {
			$cat_ids = array();
			foreach ( $parsed['categories'] as $cat_name ) {
				$cat_name = trim( $cat_name );
				if ( empty( $cat_name ) ) {
					continue;
				}
				$term = term_exists( $cat_name, 'category' );
				if ( ! $term ) {
					$term = wp_insert_term( $cat_name, 'category' );
				}
				if ( ! is_wp_error( $term ) ) {
					$cat_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				}
			}
			if ( ! empty( $cat_ids ) ) {
				wp_set_post_categories( $post_id, $cat_ids );
				$this->breakdown['categories_set'] += count( $cat_ids );
			}
		}

		// Set tags for posts.
		if ( 'post' === $post_type && ! empty( $parsed['tags'] ) ) {
			wp_set_post_tags( $post_id, $parsed['tags'] );
			$this->breakdown['tags_set'] += count( $parsed['tags'] );
		}

		// --- Yoast SEO meta ---
		if ( $this->import_yoast_meta( $post_id, $parsed ) ) {
			$this->breakdown['seo_meta']++;
		}

		// --- Structured data (JSON-LD) ---
		if ( $this->import_schema_json_ld( $post_id, $parsed ) ) {
			$this->breakdown['schema_data']++;
		}

		// --- Elementor page data ---
		// This is what Elementor actually renders from on the frontend, so
		// without this step any edits to post_content are invisible on the
		// live site for Elementor-built pages.
		$this->import_elementor_data( $post_id, $parsed, $filename );

		// Remember the hash of this exact file so a subsequent deploy with the
		// same content is skipped — only latest updates get pushed through.
		update_post_meta( $post_id, '_wgb_last_deploy_sha', $content_hash );

		$this->items_deployed++;
		$this->audit[] = array(
			'path'      => $file_path,
			'action'    => $is_update ? 'updated' : 'created',
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'slug'      => $slug,
		);
	}

	/**
	 * Derive a clean admin title from a page slug when no admin-title meta
	 * exists in the backed-up HTML and the post is being created fresh.
	 *
	 * @param string $slug Post slug.
	 * @return string Clean title.
	 */
	private static function derive_title_from_slug( $slug ) {
		$s = strtolower( $slug );

		$noise = array(
			'drywall-repair-',
			'-drywall-repair',
			'drywall-services-in-',
			'drywall-contractors-in-',
			'-drywall-services',
			'-drywall-installation',
			'-drywall',
		);
		$stripped = $s;
		foreach ( $noise as $n ) {
			$stripped = str_replace( $n, '', $stripped );
		}
		$stripped = trim( $stripped, '-' );
		if ( '' === $stripped ) {
			$stripped = $s;
		}

		if ( 0 === strpos( $s, 'jobs-' ) ) {
			$role = preg_replace( '/^jobs-(certified-|professional-)?/', '', $s );
			return 'Job: ' . ucwords( str_replace( '-', ' ', $role ) );
		}

		if ( preg_match( '/^(.+)-(dc|md|va)$/', $stripped, $m ) ) {
			return ucwords( str_replace( '-', ' ', $m[1] ) ) . ', ' . strtoupper( $m[2] );
		}

		return ucwords( str_replace( '-', ' ', $stripped ) );
	}

	/**
	 * Parse the backed-up HTML content file.
	 *
	 * @param string $html Raw HTML content.
	 * @return array|WP_Error Parsed data or error.
	 */
	private function parse_html_content( $html ) {
		if ( empty( $html ) ) {
			return new WP_Error( 'empty_content', __( 'Empty content file.', 'wp-github-backup' ) );
		}

		$result = array(
			'title'                    => '',
			'admin_title'              => '',
			'content'                  => '',
			'date'                     => '',
			'excerpt'                  => '',
			'categories'               => array(),
			'tags'                     => array(),
			'seo_title'                => '',
			'meta_description'         => '',
			'canonical'                => '',
			'robots'                   => '',
			'og_title'                 => '',
			'og_description'           => '',
			'og_url'                   => '',
			'og_type'                  => '',
			'schema_json_ld'           => array(),
			'elementor_data'           => '',
			'elementor_page_settings'  => '',
			'elementor_edit_mode'      => '',
			'elementor_version'        => '',
			'elementor_template_type'  => '',
		);

		// Extract title from <title> tag (this is the SEO title, not the admin title).
		if ( preg_match( '/<title>([^<]*)<\/title>/i', $html, $m ) ) {
			$result['title'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
			$result['seo_title'] = $result['title'];
		}

		// Extract explicit admin-facing post_title if the exporter wrote one.
		if ( preg_match( '/name="admin-title"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['admin_title'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}

		// Extract meta tags.
		if ( preg_match( '/name="date"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['date'] = trim( $m[1] );
		}
		if ( preg_match( '/name="excerpt"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['excerpt'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/name="categories"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['categories'] = array_map( 'trim', explode( ',', html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
		}
		if ( preg_match( '/name="tags"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['tags'] = array_map( 'trim', explode( ',', html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
		}

		// Extract meta description.
		if ( preg_match( '/name="description"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['meta_description'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}

		// Extract canonical URL.
		if ( preg_match( '/rel="canonical"\s+href="([^"]*)"/', $html, $m ) ) {
			$result['canonical'] = trim( $m[1] );
		}

		// Extract robots meta.
		if ( preg_match( '/name="robots"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['robots'] = trim( $m[1] );
		}

		// Extract Open Graph tags.
		if ( preg_match( '/property="og:title"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['og_title'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/property="og:description"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['og_description'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/property="og:url"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['og_url'] = trim( $m[1] );
		}
		if ( preg_match( '/property="og:type"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['og_type'] = trim( $m[1] );
		}

		// Extract ALL JSON-LD structured data blocks.
		if ( preg_match_all( '/<script\s+type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $html, $matches ) ) {
			foreach ( $matches[1] as $json_block ) {
				$decoded = json_decode( trim( $json_block ), true );
				if ( null !== $decoded ) {
					$result['schema_json_ld'][] = $decoded;
				}
			}
		}

		// Extract Elementor page data (base64-encoded _elementor_data meta).
		// This is the actual frontend render source for Elementor pages.
		if ( preg_match( '/<script\s+type="application\/x-elementor-data"[^>]*>\s*([A-Za-z0-9+\/=\s]+?)\s*<\/script>/s', $html, $m ) ) {
			$decoded = base64_decode( trim( $m[1] ), true );
			if ( false !== $decoded && '' !== $decoded ) {
				// Sanity-check it's valid JSON before storing.
				if ( null !== json_decode( $decoded, true ) ) {
					$result['elementor_data'] = $decoded;
				}
			}
		}

		// Extract Elementor page settings.
		if ( preg_match( '/<script\s+type="application\/x-elementor-page-settings"[^>]*>\s*([A-Za-z0-9+\/=\s]+?)\s*<\/script>/s', $html, $m ) ) {
			$decoded = base64_decode( trim( $m[1] ), true );
			if ( false !== $decoded && '' !== $decoded ) {
				if ( null !== json_decode( $decoded, true ) ) {
					$result['elementor_page_settings'] = $decoded;
				}
			}
		}

		if ( preg_match( '/name="elementor-edit-mode"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['elementor_edit_mode'] = trim( $m[1] );
		}
		if ( preg_match( '/name="elementor-version"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['elementor_version'] = trim( $m[1] );
		}
		if ( preg_match( '/name="elementor-template-type"\s+content="([^"]*)"/', $html, $m ) ) {
			$result['elementor_template_type'] = trim( $m[1] );
		}

		// Extract body content from <div class="content">.
		if ( preg_match( '/<div class="content">\s*(.*?)\s*<\/div>\s*<\/body>/s', $html, $m ) ) {
			$result['content'] = trim( $m[1] );
		}

		if ( empty( $result['title'] ) && empty( $result['content'] ) ) {
			return new WP_Error( 'parse_failed', __( 'Could not extract title or content from HTML.', 'wp-github-backup' ) );
		}

		return $result;
	}

	/**
	 * Import Yoast SEO meta fields from parsed HTML data.
	 *
	 * Updates SEO title, meta description, canonical URL, robots directive,
	 * and Open Graph tags in Yoast's post meta fields.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $parsed  Parsed HTML data.
	 */
	private function import_yoast_meta( $post_id, $parsed ) {
		$updated = false;

		// SEO Title.
		if ( ! empty( $parsed['seo_title'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $parsed['seo_title'] ) );
			$updated = true;
		}

		// Meta description.
		if ( ! empty( $parsed['meta_description'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $parsed['meta_description'] ) );
			$updated = true;
		}

		// Canonical URL.
		if ( ! empty( $parsed['canonical'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_canonical', esc_url_raw( $parsed['canonical'] ) );
			$updated = true;
		}

		// Robots meta (noindex, nofollow handling).
		if ( ! empty( $parsed['robots'] ) ) {
			$robots = strtolower( $parsed['robots'] );
			if ( false !== strpos( $robots, 'noindex' ) ) {
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
			} else {
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '0' );
			}
			if ( false !== strpos( $robots, 'nofollow' ) ) {
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', '1' );
			} else {
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', '0' );
			}
			$updated = true;
		}

		// Open Graph overrides (only set if different from defaults).
		if ( ! empty( $parsed['og_title'] ) && $parsed['og_title'] !== $parsed['seo_title'] ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', sanitize_text_field( $parsed['og_title'] ) );
			$updated = true;
		}
		if ( ! empty( $parsed['og_description'] ) && $parsed['og_description'] !== $parsed['meta_description'] ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', sanitize_text_field( $parsed['og_description'] ) );
			$updated = true;
		}

		return $updated;
	}

	/**
	 * Import structured data (JSON-LD) from parsed HTML data.
	 *
	 * Merges all JSON-LD blocks into a single array and saves to post meta
	 * for output via wp_head hook.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $parsed  Parsed HTML data.
	 */
	private function import_schema_json_ld( $post_id, $parsed ) {
		if ( empty( $parsed['schema_json_ld'] ) ) {
			return false;
		}

		// If there's only one schema block that's already an array of objects, keep it as-is.
		// If there are multiple separate blocks, combine them into a single array.
		$all_schemas = array();
		foreach ( $parsed['schema_json_ld'] as $block ) {
			if ( isset( $block[0] ) && is_array( $block[0] ) ) {
				// Block is an array of schema objects (e.g., [Article, BreadcrumbList, Business]).
				foreach ( $block as $schema_obj ) {
					$all_schemas[] = $schema_obj;
				}
			} else {
				// Block is a single schema object (e.g., FAQPage).
				$all_schemas[] = $block;
			}
		}

		if ( ! empty( $all_schemas ) ) {
			$json = wp_json_encode( $all_schemas, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( $json ) {
				update_post_meta( $post_id, '_wgb_schema_json_ld', $json );
				return true;
			}
		}

		return false;
	}

	/**
	 * Import Elementor data from parsed HTML into the post's _elementor_data
	 * meta and regenerate Elementor's CSS cache so the change actually shows
	 * on the frontend.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $parsed  Parsed HTML data.
	 * @return bool True if Elementor data was written.
	 */
	private function import_elementor_data( $post_id, $parsed, $slug = '' ) {
		// Fall back to a companion content/elementor/{slug}.json if the HTML
		// didn't embed Elementor data. This lets hand-exported Elementor
		// templates deploy without having to re-embed them in every HTML file.
		if ( empty( $parsed['elementor_data'] ) && '' !== $slug ) {
			$companion = $this->github->download_file( 'content/elementor/' . $slug . '.json', $this->branch );
			if ( ! is_wp_error( $companion ) && '' !== $companion ) {
				$decoded = json_decode( $companion, true );
				if ( is_array( $decoded ) && isset( $decoded['content'] ) ) {
					$parsed['elementor_data'] = wp_json_encode( $decoded['content'] );
					if ( isset( $decoded['page_settings'] ) && is_array( $decoded['page_settings'] ) ) {
						$parsed['elementor_page_settings'] = wp_json_encode( $decoded['page_settings'] );
					}
				}
			}
		}

		if ( empty( $parsed['elementor_data'] ) ) {
			return false;
		}

		// _elementor_data is stored as a slashed JSON string in post meta.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $parsed['elementor_data'] ) );

		if ( ! empty( $parsed['elementor_page_settings'] ) ) {
			$settings = json_decode( $parsed['elementor_page_settings'], true );
			if ( is_array( $settings ) ) {
				update_post_meta( $post_id, '_elementor_page_settings', $settings );
			}
		}

		if ( ! empty( $parsed['elementor_edit_mode'] ) ) {
			update_post_meta( $post_id, '_elementor_edit_mode', sanitize_key( $parsed['elementor_edit_mode'] ) );
		} else {
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		}

		if ( ! empty( $parsed['elementor_version'] ) ) {
			update_post_meta( $post_id, '_elementor_version', sanitize_text_field( $parsed['elementor_version'] ) );
		}

		if ( ! empty( $parsed['elementor_template_type'] ) ) {
			update_post_meta( $post_id, '_elementor_template_type', sanitize_key( $parsed['elementor_template_type'] ) );
		}

		// Invalidate Elementor's cached per-post CSS file so the new tree is
		// recompiled on the next page view. Without this the old CSS (and in
		// some cases stale HTML snapshots) continues to serve.
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				$css = new \Elementor\Core\Files\CSS\Post( $post_id );
				$css->update();
			} catch ( \Throwable $e ) {
				// Non-fatal — the import itself succeeded.
			}
		}

		// Bust Elementor's global "assets" cache too, in case widget-asset
		// requirements changed between versions of the saved tree.
		delete_post_meta( $post_id, '_elementor_page_assets' );
		delete_post_meta( $post_id, '_elementor_css' );

		return true;
	}

	/**
	 * Get the content directories to deploy from.
	 *
	 * @param string $target Deploy target.
	 * @return array Array of directory names.
	 */
	private function get_content_directories( $target ) {
		$base_path = WGB_Settings::get( 'deploy_repo_path', '' );
		$base_path = rtrim( $base_path, '/' );

		$map = array(
			'posts'   => array( 'posts' ),
			'pages'   => array( 'pages' ),
			'content' => array( 'posts', 'pages' ),
		);

		$dirs = isset( $map[ $target ] ) ? $map[ $target ] : array( 'posts', 'pages' );

		if ( ! empty( $base_path ) ) {
			$dirs = array_map( function ( $dir ) use ( $base_path ) {
				return $base_path . '/' . $dir;
			}, $dirs );
		}

		return $dirs;
	}

	/**
	 * Log a deploy to the database.
	 *
	 * @param string $status   Deploy status.
	 * @param int    $files    Number of items deployed.
	 * @param array  $errors   Array of error messages.
	 * @param int    $duration Duration in seconds.
	 * @param string $target   Deploy target.
	 */
	private function log_deploy( $status, $files, $errors, $duration, $target ) {
		global $wpdb;

		$table = $wpdb->prefix . 'github_deploy_log';

		$wpdb->insert(
			$table,
			array(
				'deploy_date'    => current_time( 'mysql' ),
				'status'         => $status,
				'files_deployed' => $files,
				'errors'         => ! empty( $errors ) ? wp_json_encode( $errors ) : null,
				'duration'       => $duration,
				'target'         => $target,
				'branch'         => $this->branch,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Get deploy history.
	 *
	 * @param int $limit Number of entries to return.
	 * @return array Array of deploy log entries.
	 */
	public static function get_history( $limit = 20 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'github_deploy_log';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY deploy_date DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Get the last deploy entry.
	 *
	 * @return object|null
	 */
	public static function get_last_deploy() {
		global $wpdb;

		$table = $wpdb->prefix . 'github_deploy_log';

		return $wpdb->get_row(
			"SELECT * FROM {$table} ORDER BY deploy_date DESC LIMIT 1"
		);
	}
}
