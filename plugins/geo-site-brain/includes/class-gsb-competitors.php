<?php
/**
 * Competitive GEO. Fetches a competitor's website, extracts the same business
 * signals we model for the owner (services, locations, FAQs, schema, trust),
 * and compares coverage so the owner can see where a competitor is more
 * AI-legible — and which of their own services/areas a competitor targets that
 * they haven't covered yet.
 *
 * External fetches use the WordPress HTTP API and are bounded (a handful of
 * pages per competitor) to stay polite and fast.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Competitors {

	const MAX_PAGES = 5;

	/**
	 * Analyse and store every configured competitor. Returns count processed.
	 */
	public static function run() {
		$urls = GSB_Settings::competitor_urls();
		$n = 0;
		foreach ( $urls as $url ) {
			$snap = self::analyze_url( $url );
			if ( is_wp_error( $snap ) ) {
				GSB_Logger::warning( 'competitors', 'Could not analyse ' . $url . ': ' . $snap->get_error_message() );
				continue;
			}
			GSB_Database::save_competitor( $url, $snap['name'], $snap, $snap['ai_score'] );
			$n++;
		}
		GSB_Logger::info( 'competitors', sprintf( 'Analysed %d competitor(s).', $n ) );
		return $n;
	}

	/**
	 * Fetch a competitor's homepage + a few internal pages and extract signals.
	 *
	 * @return array|WP_Error
	 */
	public static function analyze_url( $url ) {
		$home = self::fetch( $url );
		if ( is_wp_error( $home ) ) {
			return $home;
		}

		$host  = wp_parse_url( $url, PHP_URL_HOST );
		$html  = $home;
		$pages = 1;

		// Discover a few internal links worth reading.
		foreach ( self::internal_links( $home, $url, $host ) as $link ) {
			if ( $pages >= self::MAX_PAGES ) {
				break;
			}
			$sub = self::fetch( $link );
			if ( ! is_wp_error( $sub ) ) {
				$html .= "\n" . $sub;
				$pages++;
			}
		}

		$text   = self::plain( $html );
		$lower  = strtolower( $text );

		// Match against the owner's own services/locations for apples-to-apples.
		$services  = self::matches( GSB_Settings::services(), $lower );
		$locations = self::matches( GSB_Settings::locations(), $lower );

		$faq_count   = preg_match_all( '/\?(\s|<)/', $html, $m );
		$schema      = self::schema_types( $html );
		$has_testi   = (bool) preg_match( '/\b(testimonial|review|what (our )?(clients|customers) say|★|stars?)\b/i', $text );
		$has_contact = (bool) preg_match( '/(\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4})|\b(contact us|get a quote|free estimate)\b/i', $text );
		$word_count  = str_word_count( $text );

		$name = self::title_from_html( $home ) ?: $host;

		$snapshot = array(
			'name'         => $name,
			'url'          => $url,
			'services'     => $services,
			'locations'    => $locations,
			'faq_count'    => (int) $faq_count,
			'schema_types' => $schema,
			'has_testimonials' => $has_testi,
			'has_contact'  => $has_contact,
			'word_count'   => $word_count,
			'pages'        => $pages,
		);
		$snapshot['ai_score'] = self::ai_score( $snapshot );
		return $snapshot;
	}

	/* --------------------------------------------------------- comparison */

	/**
	 * Build a side-by-side comparison of the owner vs each stored competitor,
	 * plus a per-service "who covers what" breakdown.
	 */
	public static function compare() {
		$you = self::owner_snapshot();
		$competitors = GSB_Database::get_competitors();

		// Per-service coverage (you vs the best competitor that covers it).
		$service_rows = array();
		$your_services = GSB_Database::get_entities( 'service' );
		foreach ( $your_services as $svc ) {
			$you_has  = in_array( $svc->status, array( 'found', 'inferred' ), true );
			$comp_has = false;
			foreach ( $competitors as $c ) {
				$snap = json_decode( (string) $c->snapshot, true ) ?: array();
				foreach ( (array) ( $snap['services'] ?? array() ) as $cs ) {
					if ( 0 === strcasecmp( $cs, $svc->name ) ) {
						$comp_has = true;
						break 2;
					}
				}
			}
			$service_rows[] = array(
				'name'     => $svc->name,
				'you'      => $you_has,
				'comp'     => $comp_has,
				'verdict'  => self::verdict( $you_has, $comp_has ),
			);
		}

		return array(
			'you'          => $you,
			'competitors'  => $competitors,
			'service_rows' => $service_rows,
		);
	}

	private static function verdict( $you, $comp ) {
		if ( $you && $comp )   { return 'parity'; }
		if ( $you && ! $comp ) { return 'advantage'; }
		if ( ! $you && $comp ) { return 'gap'; }
		return 'neither';
	}

	private static function owner_snapshot() {
		$counts = GSB_Database::entity_counts();
		return array(
			'name'      => ( trim( (string) GSB_Settings::get( 'business_name' ) ) ?: get_bloginfo( 'name' ) ),
			'services'  => (int) ( $counts['service'] ?? 0 ),
			'locations' => (int) ( $counts['location'] ?? 0 ),
			'faqs'      => (int) ( $counts['faq'] ?? 0 ),
			'testimonials' => (int) ( $counts['testimonial'] ?? 0 ),
			'ai_score'  => GSB_Visibility::overall_score(),
		);
	}

	/* ----------------------------------------------------------- helpers */

	private static function fetch( $url ) {
		$res = wp_remote_get( $url, array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'GEO-Site-Brain/1.0 (+competitive analysis)',
			'headers'     => array( 'Accept' => 'text/html' ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error( 'gsb_fetch', 'HTTP ' . $code );
		}
		$body = wp_remote_retrieve_body( $res );
		return '' === $body ? new WP_Error( 'gsb_fetch', 'Empty response' ) : $body;
	}

	private static function internal_links( $html, $base, $host ) {
		$out = array();
		if ( ! preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return $out;
		}
		$priority = array();
		foreach ( $m[1] as $href ) {
			$href = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
			if ( 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) ) {
				continue;
			}
			// Resolve relative URLs.
			if ( 0 === strpos( $href, '/' ) ) {
				$href = self::origin( $base ) . $href;
			}
			$lhost = wp_parse_url( $href, PHP_URL_HOST );
			if ( $lhost && $lhost !== $host ) {
				continue;
			}
			if ( ! $lhost ) {
				continue;
			}
			$score = preg_match( '/(service|clean|repair|restoration|location|area|about|faq|commercial)/i', $href ) ? 0 : 1;
			$priority[ $href ] = $score;
		}
		asort( $priority );
		foreach ( array_keys( $priority ) as $href ) {
			if ( $href !== $base ) {
				$out[] = $href;
			}
		}
		return array_slice( $out, 0, self::MAX_PAGES );
	}

	private static function origin( $url ) {
		$p = wp_parse_url( $url );
		return ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' );
	}

	private static function plain( $html ) {
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', ' ', $html );
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', ' ', $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	private static function title_from_html( $html ) {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	private static function matches( $needles, $haystack_lower ) {
		$out = array();
		foreach ( $needles as $n ) {
			$n = trim( $n );
			if ( '' !== $n && false !== strpos( $haystack_lower, strtolower( $n ) ) ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	private static function schema_types( $html ) {
		$types = array();
		if ( preg_match_all( '/<script[^>]*application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				if ( preg_match_all( '/"@type"\s*:\s*"([^"]+)"/', $json, $tm ) ) {
					$types = array_merge( $types, $tm[1] );
				}
			}
		}
		return array_values( array_unique( $types ) );
	}

	private static function ai_score( $snap ) {
		$score = 0;
		$score += min( 25, count( $snap['services'] ) * 6 );
		$score += min( 20, count( $snap['locations'] ) * 5 );
		$score += $snap['faq_count'] >= 3 ? 15 : ( $snap['faq_count'] >= 1 ? 8 : 0 );
		$score += ! empty( $snap['schema_types'] ) ? 20 : 0;
		$score += $snap['has_testimonials'] ? 10 : 0;
		$score += $snap['has_contact'] ? 10 : 0;
		return max( 0, min( 100, $score ) );
	}
}
