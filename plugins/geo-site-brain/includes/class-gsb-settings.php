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
			'neon_enabled'      => 0,
			'neon_dsn'          => '',          // postgresql://user:pass@host/db?sslmode=require
			'post_types'        => array( 'page', 'post' ),
			'chunk_max_chars'   => 1500,
			'embed_batch'       => 64,
			'retrieval_k'       => 8,
			'weekly_reindex'    => 1,
			'business_name'     => '',
			'business_locations'=> '',          // newline-separated cities/regions
			'core_services'     => '',          // newline-separated services
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

	private static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ) ) );
	}
}
