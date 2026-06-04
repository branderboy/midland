<?php
/**
 * OpenAI client: embeddings (for indexing + query) and chat completions (for
 * the agent). Uses wp_remote_post; no SDK. Returns WP_Error on failure so
 * callers can degrade gracefully.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_OpenAI {

	const EMBED_ENDPOINT = 'https://api.openai.com/v1/embeddings';
	const CHAT_ENDPOINT  = 'https://api.openai.com/v1/chat/completions';

	private $api_key;

	public function __construct( $api_key = null ) {
		$this->api_key = null === $api_key ? trim( (string) GSB_Settings::get( 'openai_api_key' ) ) : $api_key;
	}

	public function has_key() {
		return '' !== $this->api_key;
	}

	/**
	 * Embed one or more strings. Returns an array of float[] vectors in the same
	 * order as $inputs, or WP_Error.
	 *
	 * @param string[] $inputs
	 * @return array|WP_Error
	 */
	public function embed( array $inputs ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'gsb_no_key', __( 'OpenAI API key is not set.', 'geo-site-brain' ) );
		}
		$inputs = array_values( array_map( static function ( $t ) {
			// OpenAI rejects empty strings; cap very long chunks defensively.
			$t = trim( (string) $t );
			return '' === $t ? ' ' : mb_substr( $t, 0, 8000 );
		}, $inputs ) );

		if ( empty( $inputs ) ) {
			return array();
		}

		$response = wp_remote_post( self::EMBED_ENDPOINT, array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model' => GSB_EMBED_MODEL,
				'input' => $inputs,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || isset( $body['error'] ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'gsb_embed_error', $msg );
		}

		// Order by index to be safe.
		$out = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $item ) {
			$out[ (int) $item['index'] ] = array_map( 'floatval', $item['embedding'] );
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Single-string convenience wrapper.
	 *
	 * @return array|WP_Error float[]
	 */
	public function embed_one( $text ) {
		$res = $this->embed( array( $text ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $res[0] ?? new WP_Error( 'gsb_embed_empty', 'No embedding returned.' );
	}

	/**
	 * Chat completion. $messages is an OpenAI-style messages array.
	 *
	 * @return string|WP_Error assistant text
	 */
	public function chat( array $messages, $temperature = 0.2, $max_tokens = 900 ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'gsb_no_key', __( 'OpenAI API key is not set.', 'geo-site-brain' ) );
		}
		$model = (string) GSB_Settings::get( 'chat_model', 'gpt-4o-mini' );

		$response = wp_remote_post( self::CHAT_ENDPOINT, array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'       => $model,
				'messages'    => $messages,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || isset( $body['error'] ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'gsb_chat_error', $msg );
		}
		return (string) ( $body['choices'][0]['message']['content'] ?? '' );
	}

	/**
	 * Lightweight key check used by the "Test connection" button.
	 *
	 * @return true|WP_Error
	 */
	public function test() {
		$res = $this->embed_one( 'ping' );
		return is_wp_error( $res ) ? $res : true;
	}
}
