<?php
/**
 * Outbound webhooks. Fires signed JSON POSTs to the configured endpoint(s) when
 * something notable happens — most importantly when AI visibility drops — so
 * external tools (Zapier, Make, a dashboard, Slack relay) can react.
 *
 * Each request carries an HMAC-SHA256 signature of the body in
 * X-GSB-Signature so receivers can verify authenticity.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Webhooks {

	const DROP_THRESHOLD = 5;

	public static function enabled() {
		return (int) GSB_Settings::get( 'webhooks_enabled', 0 ) === 1 && ! empty( self::urls() );
	}

	public static function urls() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) GSB_Settings::get( 'webhook_urls' ) ) as $u ) {
			$u = esc_url_raw( trim( $u ) );
			if ( $u ) {
				$out[] = $u;
			}
		}
		return array_slice( array_unique( $out ), 0, 5 );
	}

	/**
	 * Fire an event to every configured endpoint (non-blocking).
	 */
	public static function fire( $event, array $data ) {
		if ( ! self::enabled() ) {
			return;
		}
		$payload = array(
			'event'     => $event,
			'site'      => home_url( '/' ),
			'business'  => trim( (string) GSB_Settings::get( 'business_name' ) ) ?: get_bloginfo( 'name' ),
			'timestamp' => gmdate( 'c' ),
			'data'      => $data,
		);
		$body   = wp_json_encode( $payload );
		$secret = GSB_Settings::webhook_secret();
		$sig    = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$sent = 0;
		foreach ( self::urls() as $url ) {
			// SSRF guard: never POST to loopback / link-local / private hosts.
			if ( false === GSB_Settings::safe_remote_url( $url ) ) {
				GSB_Logger::warning( 'webhooks', 'Skipped non-public webhook URL: ' . $url );
				continue;
			}
			wp_remote_post( $url, array(
				'timeout'  => 8,
				'blocking' => false, // don't slow the page; fire-and-forget
				'headers'  => array(
					'Content-Type'     => 'application/json',
					'X-GSB-Event'      => $event,
					'X-GSB-Signature'  => $sig,
				),
				'body'     => $body,
			) );
		}
		GSB_Logger::info( 'webhooks', 'Fired ' . $event . ' to ' . count( self::urls() ) . ' endpoint(s).' );
	}

	/**
	 * Compute the current visibility delta and fire visibility.updated, plus
	 * visibility.drop when it falls past the threshold. Called after each
	 * understanding rebuild.
	 */
	public static function fire_visibility() {
		if ( ! self::enabled() ) {
			return;
		}
		$overall = GSB_Visibility::overall_score();
		if ( null === $overall ) {
			return;
		}
		$history = (array) GSB_Database::get_state( 'visibility_history', array() );
		$previous = count( $history ) >= 2 ? (int) $history[ count( $history ) - 2 ]['score'] : null;
		$delta    = null === $previous ? 0 : ( $overall - $previous );

		$engines = array();
		foreach ( GSB_Database::get_visibility() as $e ) {
			$engines[ $e->engine ] = (int) $e->visibility_score;
		}

		self::fire( 'visibility.updated', array(
			'overall'  => $overall,
			'previous' => $previous,
			'delta'    => $delta,
			'engines'  => $engines,
		) );

		if ( null !== $previous && $delta <= -self::DROP_THRESHOLD ) {
			self::fire( 'visibility.drop', array(
				'overall'  => $overall,
				'previous' => $previous,
				'delta'    => $delta,
				'engines'  => $engines,
			) );
		}
	}
}
