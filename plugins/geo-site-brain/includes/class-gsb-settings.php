<?php
/**
 * Configuration accessor. Plugin config (API keys, Neon DSN, options) lives in
 * wp_options under the gsb_* namespace so it works with standard WP tooling;
 * operational state (scan cursors, health) lives in the gsb_settings KV table
 * via GSB_Database::get_state()/set_state().
 *
 * Secrets are stored in options but never echoed back to the browser — the
 * settings screen shows only whether a value is set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Settings {

	const OPTION_PREFIX = 'gsb_';

	/** Defaults seeded on activation. Secrets default to empty. */
	private static function defaults() {
		return array(
			'openai_api_key'    => '',
			'chat_model'        => 'gpt-4o-mini',
			// Optional keys for LIVE per-engine probing (Visibility screen).
			'anthropic_api_key' => '',
			'gemini_api_key'    => '',
			'perplexity_api_key'=> '',
			'anthropic_model'   => 'claude-3-5-haiku-latest',
			'gemini_model'      => 'gemini-1.5-flash',
			'perplexity_model'  => 'sonar',
			'neon_enabled'      => 0,
			'neon_dsn'          => '',          // postgresql://user:pass@host/db?sslmode=require
			'post_types'        => array( 'page', 'post' ),
			'chunk_max_chars'   => 1500,
			'embed_batch'       => 64,
			'retrieval_k'       => 8,
			'weekly_reindex'    => 1,
			'business_name'     => 'Midland Floor Care',
			'business_locations'=> "Washington, DC\nBethesda, MD\nRockville, MD\nSilver Spring, MD\nTemple Hills, MD\nArlington, VA\nAlexandria, VA\nFairfax, VA",          // newline-separated cities/regions
			'core_services'     => "Commercial Carpet Cleaning\nHardwood Floor Cleaning\nCarpet Installation\nTile Cleaning\nFloor Refinishing\nWood Floor Refinishing\nUpholstery Cleaning\nJanitorial Services\nFloor Maintenance and Restoration",          // newline-separated services
			// Phase 3 — competitors, monitoring, white-label.
			'competitor_urls'   => '',          // newline-separated competitor homepages
			'enable_digest'     => 0,
			'digest_email'      => '',
			'agency_name'       => '',
			'agency_logo'       => '',
			'report_contact'    => '',
			// REST API + webhooks.
			'api_key'           => '',
			'webhooks_enabled'  => 0,
			'webhook_urls'      => '',
			'webhook_secret'    => '',
		);
	}

	public static function set_defaults() {
		foreach ( self::defaults() as $key => $value ) {
			if ( false === get_option( self::OPTION_PREFIX . $key ) ) {
				add_option( self::OPTION_PREFIX . $key, $value );
			}
		}
	}

	public static function get( $key, $fallback = null ) {
		$defaults = self::defaults();
		$default  = $fallback;
		if ( null === $default && array_key_exists( $key, $defaults ) ) {
			$default = $defaults[ $key ];
		}
		return get_option( self::OPTION_PREFIX . $key, $default );
	}

	public static function set( $key, $value ) {
		update_option( self::OPTION_PREFIX . $key, $value );
	}

	public static function has_openai() {
		return '' !== trim( (string) self::get( 'openai_api_key' ) );
	}

	public static function neon_active() {
		return (int) self::get( 'neon_enabled' ) === 1 && '' !== trim( (string) self::get( 'neon_dsn' ) );
	}

	/**
	 * Post types this site indexes. Always falls back to page+post.
	 */
	public static function indexed_post_types() {
		$types = self::get( 'post_types' );
		if ( ! is_array( $types ) || empty( $types ) ) {
			$types = array( 'page', 'post' );
		}
		return array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
	}

	/** Business locations as an array of trimmed strings. */
	public static function locations() {
		return self::lines( (string) self::get( 'business_locations' ) );
	}

	/** Core services as an array of trimmed strings. */
	public static function services() {
		return self::lines( (string) self::get( 'core_services' ) );
	}

	/** Competitor homepage URLs as an array. */
	public static function competitor_urls() {
		$out = array();
		foreach ( self::lines( (string) self::get( 'competitor_urls' ) ) as $u ) {
			$u = esc_url_raw( $u );
			if ( $u ) {
				$out[] = $u;
			}
		}
		return array_slice( array_unique( $out ), 0, 5 );
	}

	private static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ) ) );
	}

	/* ----------------------------------------------- API key + webhook secret */

	/** The REST API key, generated lazily on first use. */
	public static function api_key() {
		$key = trim( (string) self::get( 'api_key' ) );
		if ( '' === $key ) {
			$key = self::regenerate_api_key();
		}
		return $key;
	}

	public static function regenerate_api_key() {
		$key = 'gsb_' . wp_generate_password( 40, false, false );
		self::set( 'api_key', $key );
		return $key;
	}

	/** The webhook signing secret, generated lazily on first use. */
	public static function webhook_secret() {
		$s = trim( (string) self::get( 'webhook_secret' ) );
		if ( '' === $s ) {
			$s = self::regenerate_webhook_secret();
		}
		return $s;
	}

	public static function regenerate_webhook_secret() {
		$s = wp_generate_password( 48, false, false );
		self::set( 'webhook_secret', $s );
		return $s;
	}

	/* ------------------------------------------------------------ SSRF guard */

	/**
	 * Defense-in-depth guard for admin-supplied outbound URLs (competitor pages,
	 * webhook endpoints). Requires an http/https URL whose resolved host is not
	 * loopback, link-local, or RFC1918-private — blocking SSRF probes against
	 * 127.0.0.1, ::1, localhost, 169.254.169.254 (cloud metadata), etc.
	 *
	 * Built on wp_http_validate_url(), which already rejects those ranges (unless
	 * a site explicitly opts out via http_request_host_is_external); we add a
	 * strict scheme check on top of it.
	 *
	 * @param string $url
	 * @return string|false The validated URL, or false if unsafe/invalid.
	 */
	public static function safe_remote_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		return wp_http_validate_url( $url ) ? $url : false;
	}
}
