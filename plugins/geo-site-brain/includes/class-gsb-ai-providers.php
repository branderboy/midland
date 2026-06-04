<?php
/**
 * Multi-provider AI gateway used for LIVE per-engine probing. Each "engine" in
 * the Visibility screen maps to a real provider/API:
 *
 *   chatgpt    → OpenAI            (reuses GSB_OpenAI)
 *   claude     → Anthropic
 *   gemini     → Google Gemini
 *   perplexity → Perplexity (web-grounded, OpenAI-compatible)
 *
 * Each call returns assistant text, WP_Error on failure, or null when that
 * engine has no key configured (so the caller falls back to the deterministic
 * simulation). Keys are read from options and never echoed back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_AI_Providers {

	/** Map engine → the provider option holding its key. */
	private static function key_option( $engine ) {
		switch ( $engine ) {
			case 'chatgpt':    return 'openai_api_key';
			case 'claude':     return 'anthropic_api_key';
			case 'gemini':     return 'gemini_api_key';
			case 'perplexity': return 'perplexity_api_key';
		}
		return '';
	}

	public static function key_for( $engine ) {
		$opt = self::key_option( $engine );
		return $opt ? trim( (string) GSB_Settings::get( $opt ) ) : '';
	}

	public static function has_key( $engine ) {
		return '' !== self::key_for( $engine );
	}

	/** Which engines can be probed live right now. */
	public static function live_engines() {
		$out = array();
		foreach ( GSB_Visibility::ENGINES as $e ) {
			if ( self::has_key( $e ) ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * Send a system+user prompt to the engine's real model.
	 *
	 * @return string|WP_Error|null  text, error, or null when no key.
	 */
	public static function chat( $engine, $system, $user, $max_tokens = 700 ) {
		if ( ! self::has_key( $engine ) ) {
			return null;
		}
		switch ( $engine ) {
			case 'chatgpt':
				$openai = new GSB_OpenAI();
				return $openai->chat( array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				), 0.3, $max_tokens );
			case 'perplexity':
				return self::openai_compatible(
					'https://api.perplexity.ai/chat/completions',
					self::key_for( 'perplexity' ),
					(string) GSB_Settings::get( 'perplexity_model', 'sonar' ),
					$system, $user, $max_tokens
				);
			case 'claude':
				return self::anthropic( $system, $user, $max_tokens );
			case 'gemini':
				return self::gemini( $system, $user, $max_tokens );
		}
		return new WP_Error( 'gsb_engine', __( 'Unknown engine.', 'geo-site-brain' ) );
	}

	/* ----------------------------------------------------------- providers */

	private static function openai_compatible( $endpoint, $key, $model, $system, $user, $max_tokens ) {
		$res = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
				'max_tokens' => $max_tokens,
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || isset( $body['error'] ) ) {
			return new WP_Error( 'gsb_provider', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code ) );
		}
		return (string) ( $body['choices'][0]['message']['content'] ?? '' );
	}

	private static function anthropic( $system, $user, $max_tokens ) {
		$model = (string) GSB_Settings::get( 'anthropic_model', 'claude-3-5-haiku-latest' );
		$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 60,
			'headers' => array(
				'x-api-key'         => self::key_for( 'claude' ),
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || isset( $body['error'] ) ) {
			return new WP_Error( 'gsb_provider', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code ) );
		}
		// Concatenate text blocks.
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( isset( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}
		return $text;
	}

	private static function gemini( $system, $user, $max_tokens ) {
		$model = (string) GSB_Settings::get( 'gemini_model', 'gemini-1.5-flash' );
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( self::key_for( 'gemini' ) );
		$res = wp_remote_post( $url, array(
			'timeout' => 60,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'system_instruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'           => array( array( 'parts' => array( array( 'text' => $user ) ) ) ),
				'generationConfig'   => array( 'maxOutputTokens' => $max_tokens, 'temperature' => 0.3 ),
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || isset( $body['error'] ) ) {
			return new WP_Error( 'gsb_provider', isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code ) );
		}
		$text = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}
		return $text;
	}
}
