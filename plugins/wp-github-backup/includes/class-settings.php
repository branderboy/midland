<?php
/**
 * Settings storage for WP GitHub Backup.
 *
 * Handles persistence of plugin options, including at-rest obfuscation
 * of sensitive values (GitHub personal access tokens, Anthropic API
 * keys) using AES-256-CBC when OpenSSL is available. Falls back to a
 * clearly-labelled base64 mode when it isn't, so a site without OpenSSL
 * still works — but an admin notice warns that storage is unencrypted.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Settings {

	/**
	 * Prefix used to tag payloads with their storage format. Lets the
	 * decrypt side tell a 3.3+ AES value apart from a pre-3.3 base64
	 * value and from a raw token that was stored unencrypted.
	 */
	const AES_PREFIX   = 'wgbaes:';
	const B64_PREFIX   = 'b64:';
	const CIPHER       = 'aes-256-cbc';

	/**
	 * Return the 32-byte key used to encrypt/decrypt stored secrets.
	 *
	 * Derives from wp_salt( 'auth' ). This is NOT a HSM-grade
	 * protection — anyone with filesystem access can read the salt —
	 * but it is real encryption at-rest and prevents casual exposure
	 * via a leaked database dump.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|wp-github-backup|v3', true );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $value Plain text.
	 * @return string Encrypted payload with format prefix.
	 */
	public static function encrypt( $value ) {
		if ( '' === (string) $value ) {
			return '';
		}
		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) ) {
			try {
				$iv     = random_bytes( 16 );
				$cipher = openssl_encrypt( $value, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );
				if ( false !== $cipher ) {
					return self::AES_PREFIX . base64_encode( $iv . $cipher );
				}
			} catch ( \Throwable $e ) {
				// Fall through to base64 mode.
			}
		}
		return self::B64_PREFIX . base64_encode( $value );
	}

	/**
	 * Decrypt a stored secret. Accepts the three historical formats:
	 *   - 'wgbaes:...'  AES-256-CBC (3.3+).
	 *   - 'b64:...'     base64 (2.x and no-OpenSSL 3.3 fallback).
	 *   - raw           pre-2.x values that were stored unencoded.
	 *
	 * @param string $value Stored payload.
	 * @return string Plain text, or empty string on failure.
	 */
	public static function decrypt( $value ) {
		if ( '' === (string) $value ) {
			return '';
		}
		if ( 0 === strpos( $value, self::AES_PREFIX ) ) {
			$raw = base64_decode( substr( $value, strlen( self::AES_PREFIX ) ), true );
			if ( false === $raw || strlen( $raw ) < 17 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 16 );
			$cipher = substr( $raw, 16 );
			$plain  = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}
		if ( 0 === strpos( $value, self::B64_PREFIX ) ) {
			$plain = base64_decode( substr( $value, strlen( self::B64_PREFIX ) ), true );
			return false === $plain ? '' : $plain;
		}
		// Pre-2.x legacy: values might be plain text or undecorated b64.
		$maybe = base64_decode( $value, true );
		if ( false !== $maybe && preg_match( '/^(ghp_|github_pat_|sk-ant-)/', $maybe ) ) {
			return $maybe;
		}
		if ( preg_match( '/^(ghp_|github_pat_|sk-ant-)/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Return the decrypted GitHub token, or an empty string.
	 *
	 * @return string
	 */
	public static function get_token() {
		$raw = get_option( 'wgb_github_token', '' );
		if ( empty( $raw ) ) {
			return '';
		}
		return self::decrypt( trim( $raw ) );
	}

	/**
	 * Persist the GitHub token encrypted. Existing value is overwritten;
	 * pass an empty string to clear.
	 *
	 * @param string $token Plain text token.
	 */
	public static function save_token( $token ) {
		update_option( 'wgb_github_token', self::encrypt( $token ), false );
	}

	/**
	 * Generic option getter with 'wgb_' prefix.
	 *
	 * @param string $key     Key without prefix.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		return get_option( 'wgb_' . $key, $default );
	}

	/**
	 * Generic option setter with 'wgb_' prefix. Sanitises scalars with
	 * sanitize_text_field; pass arrays as-is (caller owns shape).
	 *
	 * @param string $key   Key without prefix.
	 * @param mixed  $value Value.
	 */
	public static function save( $key, $value ) {
		if ( is_scalar( $value ) ) {
			$value = sanitize_text_field( $value );
		}
		update_option( 'wgb_' . $key, $value, false );
	}

	/**
	 * All user-editable backup settings as an associative array.
	 *
	 * @return array
	 */
	public static function get_all() {
		return array(
			'github_username'    => self::get( 'github_username' ),
			'repo_name'          => self::get( 'repo_name' ),
			'schedule'           => self::get( 'schedule', 'manual' ),
			'include_db'         => self::get( 'include_db', '1' ),
			'include_themes'     => self::get( 'include_themes', '1' ),
			'include_plugins'    => self::get( 'include_plugins', '1' ),
			'include_uploads'    => self::get( 'include_uploads', '0' ),
			'include_posts'      => self::get( 'include_posts', '1' ),
			'include_pages'      => self::get( 'include_pages', '1' ),
			'exclude_folders'    => self::get( 'exclude_folders', 'cache,node_modules,upgrade' ),
			'retention_days'     => self::get( 'retention_days', '30' ),
			'notification_email' => self::get( 'notification_email' ),
		);
	}

	/**
	 * Excluded folder list as an array.
	 *
	 * @return string[]
	 */
	public static function get_excluded_folders() {
		$folders = self::get( 'exclude_folders', 'cache,node_modules,upgrade' );
		return array_map( 'trim', explode( ',', $folders ) );
	}

	/**
	 * True when stored secrets use real AES-256 at rest. Lets the admin
	 * UI warn when the site fell back to base64 obfuscation.
	 *
	 * @return bool
	 */
	public static function is_openssl_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' );
	}
}
