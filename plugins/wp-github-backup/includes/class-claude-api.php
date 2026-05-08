<?php
/**
 * Claude API integration for WP GitHub Backup.
 *
 * Provides AI-powered content analysis, commit message generation,
 * and backup summaries using the Anthropic Claude API.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Claude_API {

	const API_URL     = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';
	const MODEL       = 'claude-sonnet-4-6-20250514';
	const MAX_RETRIES = 3;

	/**
	 * Send a prompt to the Claude API.
	 *
	 * @param string $prompt     The user message.
	 * @param int    $max_tokens Maximum tokens for the response.
	 * @return string|WP_Error   Response text or error.
	 */
	public static function ask( $prompt, $max_tokens = 1024 ) {
		// WordPress.org requires explicit opt-in for third-party data
		// transfers. The plugin does NOT call Anthropic unless the admin
		// (a) saved an API key AND (b) ticked the "I understand this sends
		// content to api.anthropic.com" consent checkbox in settings.
		if ( ! self::consent_granted() ) {
			return new WP_Error(
				'no_consent',
				__( 'The Claude/Anthropic integration is disabled. Enable it in Settings → AI Assistant and confirm the data-transfer consent checkbox before using AI features.', 'wp-github-backup' )
			);
		}

		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'no_api_key',
				__( 'Anthropic API key is not configured. Set it under Settings → AI Assistant.', 'wp-github-backup' )
			);
		}

		$body = wp_json_encode( array(
			'model'      => self::MODEL,
			'max_tokens' => $max_tokens,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
		) );

		$retry_count = 0;
		$response    = null;

		while ( $retry_count <= self::MAX_RETRIES ) {
			$response = wp_remote_post( self::API_URL, array(
				'timeout' => 60,
				'headers' => array(
					'x-api-key'        => $api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => $body,
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( ( 429 === $code || 500 === $code ) && $retry_count < self::MAX_RETRIES ) {
				$retry_count++;
				sleep( pow( 2, $retry_count ) );
				continue;
			}

			break;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code ) {
			return new WP_Error( 'invalid_key', __( 'Invalid Anthropic API key.', 'wp-github-backup' ) );
		}

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limited', __( 'Claude API rate limit exceeded.', 'wp-github-backup' ) );
		}

		if ( $code >= 400 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'API error (HTTP ' . $code . ')';
			return new WP_Error( 'api_error', $msg );
		}

		// Extract text from response.
		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		return $text;
	}

	/**
	 * Generate a summary of backup changes.
	 *
	 * @param array $files_changed Array of file paths that changed.
	 * @param array $db_tables     Array of database tables backed up.
	 * @return string|WP_Error     Human-readable summary.
	 */
	public static function summarize_backup( $files_changed, $db_tables = array() ) {
		$file_list  = implode( "\n", array_slice( $files_changed, 0, 50 ) );
		$table_list = ! empty( $db_tables ) ? implode( ', ', $db_tables ) : 'none';
		$total      = count( $files_changed );

		$prompt = "You are a WordPress site admin assistant. Summarize this backup in 2-3 sentences.\n\n"
			. "Files changed ({$total} total):\n{$file_list}\n\n"
			. "Database tables: {$table_list}\n\n"
			. "Give a concise, useful summary of what was backed up. Mention the most important files/areas.";

		return self::ask( $prompt, 256 );
	}

	/**
	 * Generate a smart commit message for a backup push.
	 *
	 * @param array $files Array of file paths being pushed.
	 * @return string|WP_Error Commit message.
	 */
	public static function generate_commit_message( $files ) {
		$file_list = implode( "\n", array_slice( $files, 0, 30 ) );
		$total     = count( $files );

		$prompt = "Generate a concise git commit message (under 72 chars) for this WordPress backup.\n\n"
			. "Files ({$total} total):\n{$file_list}\n\n"
			. "Return ONLY the commit message, no quotes, no explanation.";

		$result = self::ask( $prompt, 100 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return trim( str_replace( array( '"', "'" ), '', $result ) );
	}

	/**
	 * Analyze content before deploy — check for SEO issues, broken links, thin content.
	 *
	 * @param string $title   Page title.
	 * @param string $content Page HTML content.
	 * @return array|WP_Error Analysis results.
	 */
	public static function analyze_deploy_content( $title, $content ) {
		$content = mb_substr( wp_strip_all_tags( $content ), 0, 10000 );

		$prompt = "Analyze this WordPress page for deployment readiness.\n\n"
			. "Title: {$title}\n"
			. "Content: {$content}\n\n"
			. "Return JSON with these keys:\n"
			. "- ready: boolean (true if page is good to deploy)\n"
			. "- seo_score: 1-10\n"
			. "- word_count: approximate word count\n"
			. "- issues: array of strings (any problems found)\n"
			. "- suggestions: array of strings (improvements)\n\n"
			. "Return ONLY valid JSON.";

		$result = self::ask( $prompt, 512 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = self::extract_json( $result );
		return $parsed ?: array(
			'ready'       => true,
			'seo_score'   => 0,
			'word_count'  => str_word_count( $content ),
			'issues'      => array( 'Could not parse AI response.' ),
			'suggestions' => array(),
		);
	}

	/**
	 * Generate SEO meta (title + description) for content being deployed.
	 *
	 * @param string $title   Page title.
	 * @param string $content Page content.
	 * @return array|WP_Error Array with 'seo_title' and 'meta_description'.
	 */
	public static function generate_deploy_seo( $title, $content ) {
		$content = mb_substr( wp_strip_all_tags( $content ), 0, 5000 );

		$prompt = "Generate SEO metadata for this drywall company page.\n\n"
			. "Title: {$title}\nContent: {$content}\n\n"
			. "Return JSON with:\n"
			. "- seo_title: under 60 chars, include relevant drywall/location keywords\n"
			. "- meta_description: 120-155 chars, include a call to action with (240) 300-0555\n"
			. "- focus_keyword: primary keyword for this page\n\n"
			. "Return ONLY valid JSON.";

		$result = self::ask( $prompt, 256 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = self::extract_json( $result );
		return $parsed ?: new WP_Error( 'parse_error', __( 'Could not parse AI response.', 'wp-github-backup' ) );
	}

	/**
	 * Get the Anthropic API key.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$key = get_option( 'wgb_anthropic_api_key', '' );
		if ( ! empty( $key ) ) {
			return WGB_Settings::decrypt( $key );
		}

		// Fallback: check if WP Claude Manager has a key configured.
		if ( class_exists( 'WCM_Settings' ) ) {
			return WCM_Settings::get_api_key();
		}

		return '';
	}

	/**
	 * Save the Anthropic API key.
	 *
	 * @param string $key Plain text API key.
	 */
	public static function save_api_key( $key ) {
		update_option( 'wgb_anthropic_api_key', WGB_Settings::encrypt( $key ) );
	}

	/**
	 * Check if the API key is configured.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return ! empty( self::get_api_key() );
	}

	/**
	 * Has the admin explicitly consented to send data to Anthropic?
	 *
	 * The consent option is stored under wgb_anthropic_consent and is set
	 * only by the AI Assistant settings form's opt-in checkbox. This is
	 * separate from having an API key on file so users can store the key
	 * without it being live yet.
	 *
	 * @return bool
	 */
	public static function consent_granted() {
		return '1' === (string) get_option( 'wgb_anthropic_consent', '0' );
	}

	/**
	 * Record or revoke consent.
	 *
	 * @param bool $granted Whether the admin granted consent.
	 */
	public static function set_consent( $granted ) {
		update_option( 'wgb_anthropic_consent', $granted ? '1' : '0', false );
	}

	/**
	 * Extract JSON from text that may contain markdown fences or extra text.
	 *
	 * @param string $text Response text.
	 * @return array|null
	 */
	private static function extract_json( $text ) {
		$decoded = json_decode( $text, true );
		if ( null !== $decoded ) {
			return $decoded;
		}
		if ( preg_match( '/```(?:json)?\s*\n?([\s\S]*?)\n?```/', $text, $matches ) ) {
			$decoded = json_decode( trim( $matches[1] ), true );
			if ( null !== $decoded ) {
				return $decoded;
			}
		}
		if ( preg_match( '/\{[\s\S]*\}/', $text, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
			if ( null !== $decoded ) {
				return $decoded;
			}
		}
		return null;
	}
}
