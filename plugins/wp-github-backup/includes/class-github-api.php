<?php
/**
 * GitHub API communication class for WP GitHub Backup.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_GitHub_API {

	/**
	 * GitHub API base URL.
	 *
	 * @var string
	 */
	private $api_base = 'https://api.github.com';

	/**
	 * GitHub personal access token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Repository owner.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * Repository name.
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Maximum retry attempts for rate limiting.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Constructor.
	 *
	 * @param string $token GitHub personal access token.
	 * @param string $owner Repository owner (GitHub username).
	 * @param string $repo  Repository name.
	 */
	public function __construct( $token, $owner, $repo ) {
		$this->token = $token;
		$this->owner = $owner;
		$this->repo  = $repo;
	}

	/**
	 * URL-encode each segment of a repo path while preserving slashes.
	 *
	 * @param string $path Repository path (e.g., "database/backup-2024-01-15.sql.gz").
	 * @return string URL-encoded path.
	 */
	private function encode_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * Get default request headers.
	 *
	 * @return array
	 */
	private function get_headers() {
		// Classic tokens (ghp_) use "token" prefix; fine-grained (github_pat_) use "Bearer".
		$prefix = ( 0 === strpos( $this->token, 'ghp_' ) ) ? 'token' : 'Bearer';

		return array(
			'Authorization' => $prefix . ' ' . $this->token,
			'Accept'        => 'application/vnd.github.v3+json',
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'WP-GitHub-Backup/' . WGB_VERSION,
		);
	}

	/**
	 * Make an API request with rate limiting retry logic.
	 *
	 * @param string $url     Full API URL.
	 * @param array  $args    Request arguments for wp_remote_request().
	 * @param int    $attempt Current attempt number.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	private function request( $url, $args, $attempt = 1 ) {
		$args['headers'] = $this->get_headers();
		$args['timeout']  = 60;

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// Handle rate limiting (only retry when GitHub signals rate limit, not auth errors).
		if ( 403 === $code && $attempt <= $this->max_retries ) {
			$retry_after  = wp_remote_retrieve_header( $response, 'retry-after' );
			$rate_remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			if ( $retry_after || '0' === $rate_remaining ) {
				$wait = $retry_after ? (int) $retry_after : ( $attempt * 5 );
				sleep( $wait );
				return $this->request( $url, $args, $attempt + 1 );
			}
		}

		return $response;
	}

	/**
	 * Push (create or update) a file to the repository.
	 *
	 * @param string $repo_path      Path in the repository.
	 * @param string $content        File content (raw, will be base64 encoded).
	 * @param string $commit_message Commit message.
	 * @return array|WP_Error Response data or error.
	 */
	public function push_file( $repo_path, $content, $commit_message ) {
		$url = sprintf(
			'%s/repos/%s/%s/contents/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			$this->encode_path( $repo_path )
		);

		$body = array(
			'message' => $commit_message,
			'content' => base64_encode( $content ),
		);

		// Check if file already exists to get SHA.
		$sha = $this->get_file_sha( $repo_path );
		if ( ! is_wp_error( $sha ) && ! empty( $sha ) ) {
			$body['sha'] = $sha;
		}

		$response = $this->request( $url, array(
			'method' => 'PUT',
			'body'   => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return $data;
		}

		$message = isset( $data['message'] ) ? $data['message'] : 'Unknown GitHub API error';
		return new WP_Error( 'github_api_error', $message, array( 'status' => $code ) );
	}

	/**
	 * Get the SHA of an existing file in the repository.
	 *
	 * @param string $repo_path Path in the repository.
	 * @return string|WP_Error File SHA or error.
	 */
	public function get_file_sha( $repo_path ) {
		$url = sprintf(
			'%s/repos/%s/%s/contents/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			$this->encode_path( $repo_path )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			return '';
		}

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			return isset( $data['sha'] ) ? $data['sha'] : '';
		}

		return new WP_Error( 'github_api_error', 'Failed to get file SHA', array( 'status' => $code ) );
	}

	/**
	 * Delete a file from the repository.
	 *
	 * @param string $repo_path Path in the repository.
	 * @param string $sha       File SHA.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_file( $repo_path, $sha ) {
		$url = sprintf(
			'%s/repos/%s/%s/contents/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			$this->encode_path( $repo_path )
		);

		$body = array(
			'message' => 'Delete old backup: ' . $repo_path,
			'sha'     => $sha,
		);

		$response = $this->request( $url, array(
			'method' => 'DELETE',
			'body'   => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = isset( $data['message'] ) ? $data['message'] : 'Failed to delete file';
		return new WP_Error( 'github_api_error', $message, array( 'status' => $code ) );
	}

	/**
	 * List all files in a repository directory.
	 *
	 * @param string $directory Directory path in the repository.
	 * @return array|WP_Error Array of file info or error.
	 */
	public function list_files( $directory ) {
		$url = sprintf(
			'%s/repos/%s/%s/contents/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			$this->encode_path( $directory )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			return array();
		}

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			return is_array( $data ) ? $data : array();
		}

		return new WP_Error( 'github_api_error', 'Failed to list files', array( 'status' => $code ) );
	}

	/**
	 * Get the current HEAD commit SHA of a branch.
	 *
	 * @param string $branch Branch name.
	 * @return string|WP_Error Commit SHA or error.
	 */
	public function get_branch_head_sha( $branch ) {
		$url = sprintf(
			'%s/repos/%s/%s/branches/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			rawurlencode( $branch )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'github_api_error', 'Failed to read branch ' . $branch . ' (HTTP ' . $code . ').' );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$sha  = isset( $data['commit']['sha'] ) ? $data['commit']['sha'] : '';

		if ( empty( $sha ) ) {
			return new WP_Error( 'github_api_error', __( 'Branch response missing commit SHA.', 'wp-github-backup' ) );
		}

		return $sha;
	}

	/**
	 * Compare two commits and return the list of changed files.
	 *
	 * @param string $base Base commit SHA or ref.
	 * @param string $head Head commit SHA or ref.
	 * @return array|WP_Error Array of file entries (each with 'filename' and 'status') or error.
	 */
	public function compare_commits( $base, $head ) {
		$url = sprintf(
			'%s/repos/%s/%s/compare/%s...%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			rawurlencode( $base ),
			rawurlencode( $head )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'github_api_error', 'Failed to compare ' . $base . '...' . $head . ' (HTTP ' . $code . ').' );
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$files = isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array();

		return $files;
	}

	/**
	 * Create the repository on GitHub if it does not exist.
	 *
	 * @return true|WP_Error True on success or if already exists, WP_Error on failure.
	 */
	public function ensure_repo_exists() {
		// First check if repo already exists.
		$url = sprintf(
			'%s/repos/%s/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );

		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				return true; // Already exists.
			}

			// 403 means token lacks permission to read this repo — don't try to create.
			if ( 403 === $code ) {
				return new WP_Error( 'github_api_error', 'Token does not have access to this repository. Check that your token has Contents (read/write) permission for ' . $this->owner . '/' . $this->repo . '.' );
			}
		}

		// Repo doesn't exist (404) — try to create it.
		$create_url = $this->api_base . '/user/repos';

		$body = array(
			'name'        => $this->repo,
			'private'     => true,
			'description' => 'WordPress backup repository created by WP GitHub Backup plugin.',
			'auto_init'   => true,
		);

		$response = $this->request( $create_url, array(
			'method' => 'POST',
			'body'   => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = isset( $data['message'] ) ? $data['message'] : 'Failed to create repository';

		if ( 403 === $code ) {
			$message = 'Repository not found and token lacks permission to create one. Please create the repo on GitHub first, or use a classic token with the "repo" scope.';
		}

		return new WP_Error( 'repo_create_failed', $message, array( 'status' => $code ) );
	}

	/**
	 * Check if the repository exists and is accessible.
	 *
	 * @return true|WP_Error True if accessible, WP_Error otherwise.
	 */
	public function test_connection() {
		$result = $this->ensure_repo_exists();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get the repository tree recursively.
	 *
	 * @param string $branch Branch or commit SHA.
	 * @param string $path   Optional subdirectory path to filter.
	 * @return array|WP_Error Array of tree entries or error.
	 */
	public function get_tree( $branch = 'main', $path = '' ) {
		$url = sprintf(
			'%s/repos/%s/%s/git/trees/%s?recursive=1',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			rawurlencode( $branch )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			$tree = isset( $data['tree'] ) ? $data['tree'] : array();

			if ( ! empty( $path ) ) {
				$path = rtrim( $path, '/' ) . '/';
				$tree = array_filter( $tree, function ( $entry ) use ( $path ) {
					return 0 === strpos( $entry['path'], $path );
				} );
				$tree = array_values( $tree );
			}

			return $tree;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = isset( $data['message'] ) ? $data['message'] : 'Unknown error';
		return new WP_Error(
			'github_api_error',
			sprintf( 'Failed to get repository tree (HTTP %d: %s). Check that the branch "%s" exists in %s/%s.', $code, $message, $branch, $this->owner, $this->repo ),
			array( 'status' => $code )
		);
	}

	/**
	 * Download a file's raw content from the repository.
	 *
	 * @param string $repo_path Path in the repository.
	 * @param string $ref       Branch or commit SHA.
	 * @return string|WP_Error File content or error.
	 */
	public function download_file( $repo_path, $ref = 'main' ) {
		$url = sprintf(
			'%s/repos/%s/%s/contents/%s?ref=%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			$this->encode_path( $repo_path ),
			rawurlencode( $ref )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $data['content'] ) ) {
				return base64_decode( $data['content'] );
			}

			// For large files, use the download_url.
			if ( isset( $data['download_url'] ) ) {
				$auth_prefix = ( 0 === strpos( $this->token, 'ghp_' ) ) ? 'token' : 'Bearer';
				$download = wp_remote_get( $data['download_url'], array(
					'timeout' => 120,
					'headers' => array(
						'Authorization' => $auth_prefix . ' ' . $this->token,
						'User-Agent'    => 'WP-GitHub-Backup/' . WGB_VERSION,
					),
				) );

				if ( is_wp_error( $download ) ) {
					return $download;
				}

				return wp_remote_retrieve_body( $download );
			}

			return new WP_Error( 'github_api_error', __( 'No content available for file', 'wp-github-backup' ) );
		}

		return new WP_Error( 'github_api_error', 'Failed to download file', array( 'status' => $code ) );
	}

	/**
	 * List branches in the repository.
	 *
	 * @return array|WP_Error Array of branch info or error.
	 */
	public function list_branches() {
		$url = sprintf(
			'%s/repos/%s/%s/branches',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return json_decode( wp_remote_retrieve_body( $response ), true );
		}

		return new WP_Error( 'github_api_error', 'Failed to list branches', array( 'status' => $code ) );
	}

	/**
	 * Get the default branch of the repository.
	 *
	 * @return string|WP_Error Default branch name or error.
	 */
	public function get_default_branch() {
		$url = sprintf(
			'%s/repos/%s/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			return isset( $data['default_branch'] ) ? $data['default_branch'] : 'main';
		}

		return new WP_Error( 'github_api_error', 'Failed to get repo info', array( 'status' => $code ) );
	}

	/**
	 * Get the latest commit info for a branch.
	 *
	 * @param string $branch Branch name.
	 * @return array|WP_Error Commit data or error.
	 */
	public function get_latest_commit( $branch = 'main' ) {
		$url = sprintf(
			'%s/repos/%s/%s/commits/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			rawurlencode( $branch )
		);

		$response = $this->request( $url, array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return json_decode( wp_remote_retrieve_body( $response ), true );
		}

		return new WP_Error( 'github_api_error', 'Failed to get latest commit', array( 'status' => $code ) );
	}

	/**
	 * Create a blob from raw content.
	 *
	 * @param string $content Raw bytes.
	 * @return string|WP_Error Blob SHA or error.
	 */
	public function create_blob( $content ) {
		$url = sprintf(
			'%s/repos/%s/%s/git/blobs',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = $this->request(
			$url,
			array(
				'method' => 'POST',
				'body'   => wp_json_encode( array(
					'content'  => base64_encode( $content ),
					'encoding' => 'base64',
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && isset( $data['sha'] ) ) {
			return $data['sha'];
		}

		$message = isset( $data['message'] ) ? $data['message'] : 'Failed to create blob';
		return new WP_Error( 'github_api_error', $message, array( 'status' => $code ) );
	}

	/**
	 * Create a new tree on top of an existing one.
	 *
	 * @param string $base_tree_sha Parent tree SHA.
	 * @param array  $entries       Entries: [ ['path'=>'x','mode'=>'100644','type'=>'blob','sha'=>'...'], ... ].
	 * @return string|WP_Error New tree SHA or error.
	 */
	public function create_tree( $base_tree_sha, $entries ) {
		$url = sprintf(
			'%s/repos/%s/%s/git/trees',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = $this->request(
			$url,
			array(
				'method' => 'POST',
				'body'   => wp_json_encode( array(
					'base_tree' => $base_tree_sha,
					'tree'      => array_values( $entries ),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && isset( $data['sha'] ) ) {
			return $data['sha'];
		}

		$message = isset( $data['message'] ) ? $data['message'] : 'Failed to create tree';
		return new WP_Error( 'github_api_error', $message, array( 'status' => $code ) );
	}

	/**
	 * Create a commit pointing at a tree.
	 *
	 * @param string $message    Commit message.
	 * @param string $tree_sha   Tree SHA the commit snapshots.
	 * @param array  $parent_shas Parent commit SHAs (usually one).
	 * @return string|WP_Error Commit SHA or error.
	 */
	public function create_commit( $message, $tree_sha, $parent_shas ) {
		$url = sprintf(
			'%s/repos/%s/%s/git/commits',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = $this->request(
			$url,
			array(
				'method' => 'POST',
				'body'   => wp_json_encode( array(
					'message' => $message,
					'tree'    => $tree_sha,
					'parents' => array_values( $parent_shas ),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && isset( $data['sha'] ) ) {
			return $data['sha'];
		}

		$message = isset( $data['message'] ) ? $data['message'] : 'Failed to create commit';
		return new WP_Error( 'github_api_error', $message, array( 'status' => $code ) );
	}

	/**
	 * Move a branch ref to a new commit SHA.
	 *
	 * @param string $branch     Branch name.
	 * @param string $commit_sha Commit SHA to point the ref at.
	 * @param bool   $force      Whether to force-update (allows non-ff). Default false.
	 * @return true|WP_Error True on success, WP_Error otherwise.
	 */
	public function update_ref( $branch, $commit_sha, $force = false ) {
		$url = sprintf(
			'%s/repos/%s/%s/git/refs/heads/%s',
			$this->api_base,
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo ),
			rawurlencode( $branch )
		);

		$response = $this->request(
			$url,
			array(
				'method' => 'PATCH',
				'body'   => wp_json_encode( array(
					'sha'   => $commit_sha,
					'force' => (bool) $force,
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = isset( $data['message'] ) ? $data['message'] : 'Failed to update ref';
		return new WP_Error( 'github_api_error', $message, array( 'status' => $code ) );
	}

	/**
	 * Push a batch of files in a single atomic commit.
	 *
	 * Replaces the per-file Contents API loop that produced one commit
	 * per file (892 commits/24h on a real site). Uses the Git Data API:
	 * blobs → tree → commit → update_ref. Result is exactly ONE commit
	 * regardless of how many files are in the batch.
	 *
	 * Files that fail to blob (e.g. transient API hiccup) are skipped
	 * but logged in the returned result so the caller can surface
	 * partial success.
	 *
	 * @param array  $files   Array of [ 'path' => 'repo/path', 'content' => 'raw bytes' ].
	 * @param string $message Commit message.
	 * @param string $branch  Branch name.
	 * @return array|WP_Error { commit_sha, pushed_count, skipped, errors } or error.
	 */
	public function commit_batch( $files, $message, $branch = 'main' ) {
		if ( empty( $files ) ) {
			return new WP_Error( 'empty_batch', __( 'No files to commit.', 'wp-github-backup' ) );
		}

		// 1. Resolve current HEAD + its tree SHA. This is our base.
		$head_commit_sha = $this->get_branch_head_sha( $branch );
		if ( is_wp_error( $head_commit_sha ) ) {
			return $head_commit_sha;
		}

		$head_commit = $this->get_latest_commit( $branch );
		if ( is_wp_error( $head_commit ) ) {
			return $head_commit;
		}

		$base_tree_sha = isset( $head_commit['commit']['tree']['sha'] ) ? $head_commit['commit']['tree']['sha'] : '';
		if ( empty( $base_tree_sha ) ) {
			return new WP_Error( 'github_api_error', __( 'Could not resolve base tree SHA from HEAD commit.', 'wp-github-backup' ) );
		}

		// 2. Upload every file as a blob, building up tree entries.
		$entries = array();
		$skipped = array();
		foreach ( $files as $file ) {
			if ( empty( $file['path'] ) || ! isset( $file['content'] ) ) {
				$skipped[] = array(
					'path'  => isset( $file['path'] ) ? $file['path'] : '(no path)',
					'error' => 'malformed entry',
				);
				continue;
			}

			$blob_sha = $this->create_blob( $file['content'] );
			if ( is_wp_error( $blob_sha ) ) {
				$skipped[] = array(
					'path'  => $file['path'],
					'error' => $blob_sha->get_error_message(),
				);
				continue;
			}

			$entries[] = array(
				'path' => $file['path'],
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $blob_sha,
			);
		}

		if ( empty( $entries ) ) {
			return new WP_Error(
				'all_blobs_failed',
				__( 'Every file failed to upload as a blob; nothing to commit.', 'wp-github-backup' ),
				array( 'skipped' => $skipped )
			);
		}

		// 3. Stack the new entries on top of the existing tree.
		$new_tree_sha = $this->create_tree( $base_tree_sha, $entries );
		if ( is_wp_error( $new_tree_sha ) ) {
			return $new_tree_sha;
		}

		// 4. Cut a commit pointing at the new tree.
		$new_commit_sha = $this->create_commit( $message, $new_tree_sha, array( $head_commit_sha ) );
		if ( is_wp_error( $new_commit_sha ) ) {
			return $new_commit_sha;
		}

		// 5. Move the branch to the new commit.
		$update = $this->update_ref( $branch, $new_commit_sha, false );
		if ( is_wp_error( $update ) ) {
			return $update;
		}

		return array(
			'commit_sha'   => $new_commit_sha,
			'pushed_count' => count( $entries ),
			'skipped'      => $skipped,
			'errors'       => array(),
		);
	}
}
