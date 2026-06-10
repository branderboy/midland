<?php
/**
 * DataForSEO API client (standalone, bring-your-own-key).
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DataForSEO API integration — standalone (own credentials).
 *
 * BYOK — user provides their own DataForSEO login + password.
 * Docs: https://docs.dataforseo.com/v3/
 */
class MLS_DataForSEO {

	const BASE_URL = 'https://api.dataforseo.com/v3/';

	/**
	 * Get the stored API login.
	 *
	 * @return string
	 */
	public static function get_login() {
		// Prefer Smart SEO's connection when it is configured: on this site it
		// is the verified-working one, and both plugins share one DataForSEO
		// account. Local SEO's own copy is the backup.
		if ( class_exists( 'RSSEO_Pro_DataForSEO' ) && RSSEO_Pro_DataForSEO::is_configured() ) {
			return (string) RSSEO_Pro_DataForSEO::get_login();
		}
		return get_option( 'mls_dfs_login', '' );
	}

	/**
	 * Get the decrypted API password.
	 *
	 * @return string
	 */
	public static function get_password() {
		if ( class_exists( 'RSSEO_Pro_DataForSEO' ) && RSSEO_Pro_DataForSEO::is_configured() ) {
			return (string) RSSEO_Pro_DataForSEO::get_password();
		}
		return self::decrypt( get_option( 'mls_dfs_password', '' ) );
	}

	/**
	 * Whether both credentials are present.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return ! empty( self::get_login() ) && ! empty( self::get_password() );
	}

	/**
	 * Persist credentials (password encrypted at rest).
	 *
	 * @param string $login    API login.
	 * @param string $password API password.
	 */
	public static function save_credentials( $login, $password ) {
		update_option( 'mls_dfs_login', sanitize_text_field( $login ) );
		update_option( 'mls_dfs_password', self::encrypt( $password ) );
	}

	// ── Self-contained AES-256-CBC crypto (key derived from wp_salt) ──────────

	/**
	 * Encrypt a secret. Stores base64( iv . ciphertext ). Falls back to plain
	 * (prefixed) storage when openssl is unavailable so we never lose the value.
	 *
	 * @param string $plain Plaintext secret.
	 * @return string
	 */
	private static function encrypt( $plain ) {
		if ( '' === $plain || null === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . $plain;
		}
		$method = 'aes-256-cbc';
		$key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$iv_len = openssl_cipher_iv_length( $method );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$cipher = openssl_encrypt( $plain, $method, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return 'plain:' . $plain;
		}
		return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret produced by encrypt().
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	private static function decrypt( $stored ) {
		if ( '' === $stored || null === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return substr( $stored, 6 );
		}
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			// Unrecognized format — assume it was stored plain.
			return $stored;
		}
		$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		$method = 'aes-256-cbc';
		$key    = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$iv_len = openssl_cipher_iv_length( $method );
		if ( strlen( $raw ) <= $iv_len ) {
			return '';
		}
		$iv     = substr( $raw, 0, $iv_len );
		$cipher = substr( $raw, $iv_len );
		$plain  = openssl_decrypt( $cipher, $method, $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Make a POST request to the DataForSEO API.
	 *
	 * @param string $endpoint API endpoint (relative to BASE_URL).
	 * @param array  $payload  Request payload (DataForSEO task array).
	 * @return array|WP_Error
	 */
	private static function post( $endpoint, $payload ) {
		$login    = self::get_login();
		$password = self::get_password();

		if ( empty( $login ) || empty( $password ) ) {
			return new WP_Error( 'no_credentials', __( 'DataForSEO credentials not configured.', 'midland-local-seo' ) );
		}

		$response = wp_remote_post(
			self::BASE_URL . $endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = $data['status_message'] ?? 'DataForSEO API error (HTTP ' . $code . ')';
			return new WP_Error( 'dfs_error', $msg );
		}

		return $data;
	}

	/**
	 * Test credentials with a minimal API call.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		$result = self::post(
			'keywords_data/google_ads/search_volume/live',
			array(
				array(
					'keywords'      => array( 'test' ),
					'location_code' => 2840,
					'language_code' => 'en',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get live SERP for a keyword at a specific lat/lng — used by the Geo-Grid.
	 *
	 * @param string $keyword       Search keyword.
	 * @param float  $lat           Latitude.
	 * @param float  $lng           Longitude.
	 * @param int    $radius_km     Search radius in km.
	 * @param string $language_code Language code.
	 * @param int    $depth         Result depth.
	 * @return array|WP_Error
	 */
	public static function get_serp_at_coordinate( $keyword, $lat, $lng, $radius_km = 5, $language_code = 'en', $depth = 100 ) {
		$payload = array(
			array(
				'keyword'             => sanitize_text_field( $keyword ),
				'location_coordinate' => sprintf( '%F,%F,%d', (float) $lat, (float) $lng, (int) $radius_km ),
				'language_code'       => sanitize_text_field( $language_code ),
				'depth'               => (int) $depth,
			),
		);

		$result = self::post( 'serp/google/organic/live/advanced', $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items  = $result['tasks'][0]['result'][0]['items'] ?? array();
		$output = array();

		foreach ( $items as $item ) {
			if ( 'organic' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			$output[] = array(
				'rank'   => $item['rank_absolute'],
				'url'    => $item['url'],
				'title'  => $item['title'],
				'domain' => $item['domain'],
			);
		}

		return $output;
	}

	/**
	 * Local-pack (Google Maps) ranking at a coordinate — the actual local-finder
	 * results, NOT organic SERP. This is what the Geo-Grid must use so it reports
	 * true map-pack position; organic results would report the wrong number.
	 *
	 * @param string $keyword Search term.
	 * @param float  $lat     Latitude.
	 * @param float  $lng     Longitude.
	 * @param int    $depth   Number of map results to scan (1-100).
	 * @return array|WP_Error List of { rank, title, domain, url }.
	 */
	public static function get_local_pack_at_coordinate( $keyword, $lat, $lng, $depth = 100 ) {
		$keyword = sanitize_text_field( $keyword );
		if ( '' === $keyword ) {
			return new WP_Error( 'no_keyword', __( 'A keyword is required.', 'midland-local-seo' ) );
		}

		$depth   = max( 1, min( 100, (int) $depth ) );
		$payload = array(
			array(
				// Google Maps "location_coordinate" is "lat,lng,zoom" (zoom 0-21).
				'keyword'             => $keyword,
				'location_coordinate' => sprintf( '%F,%F,%d', (float) $lat, (float) $lng, 14 ),
				'language_code'       => 'en',
				'depth'               => $depth,
			),
		);

		$result = self::post( 'serp/google/maps/live/advanced', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = isset( $result['tasks'][0]['result'][0]['items'] ) && is_array( $result['tasks'][0]['result'][0]['items'] )
			? $result['tasks'][0]['result'][0]['items']
			: array();

		$output = array();
		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			// SERP-Maps returns several item types for ranked map listings.
			$allowed_types = array( 'maps_search', 'local_pack', 'maps' );
			if ( '' !== $type && ! in_array( $type, $allowed_types, true ) ) {
				continue;
			}
			$title = (string) ( isset( $row['title'] ) ? $row['title'] : '' );
			$rank  = (int) ( isset( $row['rank_absolute'] ) ? $row['rank_absolute'] : 0 );
			// A usable listing needs at least a title or a rank.
			if ( '' === $title && 0 === $rank ) {
				continue;
			}
			$output[] = array(
				'rank'   => $rank,
				'title'  => $title,
				'domain' => (string) ( isset( $row['domain'] ) ? $row['domain'] : '' ),
				'url'    => (string) ( isset( $row['url'] ) ? $row['url'] : '' ),
			);
		}

		return $output;
	}

	/**
	 * Backlinks summary: total backlinks, referring domains, lost / gained,
	 * anchor distribution. Cached in a transient because DataForSEO charges
	 * per call.
	 *
	 * @param string $target Domain (no protocol). Default = home_url host.
	 * @return array|WP_Error
	 */
	public static function get_backlinks_summary( $target = '' ) {
		if ( '' === $target ) {
			$target = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
		}
		$cache_key = 'mls_backlinks_summary_' . md5( $target );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$payload = array(
			array(
				'target'                => $target,
				'internal_list_limit'   => 10,
				'backlinks_status_type' => 'live',
			),
		);
		$result  = self::post( 'backlinks/summary/live', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row = $result['tasks'][0]['result'][0] ?? null;
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'dfs_empty', 'DataForSEO returned no backlinks data for ' . $target );
		}
		$summary = array(
			'target'             => $target,
			'rank'               => (int) ( $row['rank'] ?? 0 ),
			'backlinks'          => (int) ( $row['backlinks'] ?? 0 ),
			'referring_domains'  => (int) ( $row['referring_domains'] ?? 0 ),
			'referring_main'     => (int) ( $row['referring_main_domains'] ?? 0 ),
			'broken_backlinks'   => (int) ( $row['broken_backlinks'] ?? 0 ),
			'lost_backlinks_30d' => (int) ( $row['referring_domains_lost_60_days'] ?? 0 ),
			'new_backlinks_30d'  => (int) ( $row['referring_domains_new_60_days'] ?? 0 ),
			'anchors'            => (int) ( $row['anchors'] ?? 0 ),
			'crawled_at'         => time(),
		);
		set_transient( $cache_key, $summary, HOUR_IN_SECONDS * 6 );
		return $summary;
	}

	/**
	 * Top referring domains for a target. Cached.
	 *
	 * @param string $target Domain (no protocol). Default = home_url host.
	 * @param int    $limit  Max domains.
	 * @return array|WP_Error
	 */
	public static function get_referring_domains( $target = '', $limit = 25 ) {
		if ( '' === $target ) {
			$target = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
		}
		$cache_key = 'mls_referring_' . md5( $target . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$payload = array(
			array(
				'target'                => $target,
				'limit'                 => (int) $limit,
				'order_by'              => array( 'rank,desc' ),
				'backlinks_status_type' => 'live',
			),
		);
		$result  = self::post( 'backlinks/referring_domains/live', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = $result['tasks'][0]['result'][0]['items'] ?? array();
		$out   = array();
		foreach ( $items as $row ) {
			$out[] = array(
				'domain'     => $row['domain'] ?? '',
				'rank'       => (int) ( $row['rank'] ?? 0 ),
				'backlinks'  => (int) ( $row['backlinks'] ?? 0 ),
				'first_seen' => $row['first_seen'] ?? null,
				'lost'       => ! empty( $row['is_lost'] ),
				'broken'     => ! empty( $row['is_broken'] ),
			);
		}
		set_transient( $cache_key, $out, HOUR_IN_SECONDS * 6 );
		return $out;
	}

	/**
	 * Normalize a host/domain for set comparison: lowercase, strip scheme, path
	 * and a leading "www.".
	 *
	 * @param string $host Raw host or URL.
	 * @return string
	 */
	public static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( '' === $host ) {
			return '';
		}
		$host = preg_replace( '#^https?://#', '', $host );
		$host = preg_replace( '#/.*$#', '', $host );
		$host = preg_replace( '#^www\.#', '', $host );
		return $host;
	}

	/**
	 * Link-gap discovery: find referring domains that link to >= 1 competitor but
	 * NOT to you. For each competitor domain we pull its referring domains (via the
	 * existing get_referring_domains()), union them, pull YOUR OWN referring
	 * domains (home host), and return the gap with each domain's DFS rank and which
	 * competitor(s) it links to. Cached per competitor set; WP_Error/empty-safe;
	 * never fatal.
	 *
	 * @param array $competitor_domains Competitor hosts/domains.
	 * @param int   $limit              Max referring domains pulled per target.
	 * @return array Map of domain => { rank:int, competitors:array } (gap only).
	 */
	public static function discover_link_prospects( $competitor_domains, $limit = 50 ) {
		if ( ! is_array( $competitor_domains ) ) {
			return array();
		}
		$limit = max( 1, min( 1000, (int) $limit ) );

		// Normalize + de-dupe the competitor set.
		$competitors = array();
		foreach ( $competitor_domains as $d ) {
			$n = self::normalize_host( $d );
			if ( '' !== $n ) {
				$competitors[ $n ] = true;
			}
		}
		$competitors = array_keys( $competitors );
		if ( empty( $competitors ) ) {
			return array();
		}

		$own_host  = self::normalize_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$cache_key = 'mls_linkgap_' . md5( implode( ',', $competitors ) . '|' . $own_host . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Your own referring domains (the set to subtract).
		$own_refs = array();
		$mine     = self::get_referring_domains( $own_host, $limit );
		if ( ! is_wp_error( $mine ) && is_array( $mine ) ) {
			foreach ( $mine as $row ) {
				$h = self::normalize_host( isset( $row['domain'] ) ? $row['domain'] : '' );
				if ( '' !== $h ) {
					$own_refs[ $h ] = true;
				}
			}
		}

		// Union of competitor referring domains, tracking provenance.
		$gap = array();
		foreach ( $competitors as $competitor ) {
			$refs = self::get_referring_domains( $competitor, $limit );
			if ( is_wp_error( $refs ) || ! is_array( $refs ) ) {
				continue;
			}
			foreach ( $refs as $row ) {
				$h = self::normalize_host( isset( $row['domain'] ) ? $row['domain'] : '' );
				if ( '' === $h ) {
					continue;
				}
				// Skip domains that already link to you, the competitor itself, or you.
				if ( isset( $own_refs[ $h ] ) || $h === $own_host || in_array( $h, $competitors, true ) ) {
					continue;
				}
				$rank = isset( $row['rank'] ) ? (int) $row['rank'] : 0;
				if ( ! isset( $gap[ $h ] ) ) {
					$gap[ $h ] = array(
						'rank'        => $rank,
						'competitors' => array(),
					);
				}
				$gap[ $h ]['rank'] = max( $gap[ $h ]['rank'], $rank );
				if ( ! in_array( $competitor, $gap[ $h ]['competitors'], true ) ) {
					$gap[ $h ]['competitors'][] = $competitor;
				}
			}
		}

		// Sort the gap by rank desc so the best prospects lead.
		uasort(
			$gap,
			static function ( $a, $b ) {
				if ( $a['rank'] === $b['rank'] ) {
					return 0;
				}
				return ( $a['rank'] > $b['rank'] ) ? -1 : 1;
			}
		);

		set_transient( $cache_key, $gap, HOUR_IN_SECONDS * 6 );
		// Track the key so MLS_Backlinks::handle_refresh() can clear it via
		// delete_transient() (object-cache safe).
		$keys = get_option( 'mls_linkgap_keys', array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		if ( ! in_array( $cache_key, $keys, true ) ) {
			$keys[] = $cache_key;
			update_option( 'mls_linkgap_keys', $keys, false );
		}
		return $gap;
	}

	/**
	 * Fetch a Google Business Profile listing for the GMB optimizer.
	 *
	 * Uses the SERP Maps endpoint (serp/google/maps/live/advanced) and name-matches
	 * the listing — the Business Data API is a separate DataForSEO product many plans
	 * don't include. Field names follow the maps_search response; anything missing
	 * degrades to null/empty so the optimizer/mirror never fatals.
	 *
	 * @param string     $name Business name to match.
	 * @param float|null $lat  Optional latitude to bias the search.
	 * @param float|null $lng  Optional longitude to bias the search.
	 * @return array|WP_Error Parsed listing or WP_Error.
	 */
	public static function get_gmb_listing( $name, $lat = null, $lng = null ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'no_name', __( 'A business name is required to look up the listing.', 'midland-local-seo' ) );
		}

		$cache_key = 'mls_gmb_listing_' . md5( $name . '|' . (string) $lat . '|' . (string) $lng );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Use the SERP Maps endpoint (serp/google/maps/live/advanced) rather than the
		// Business Data API. Many DataForSEO plans include SERP but NOT Business Data,
		// and the maps_search items carry the same listing fields we need (rating,
		// category, additional_categories, photos, hours, address, phone, url).
		$payload = array(
			array(
				'keyword'       => $name,
				'language_code' => 'en',
				'depth'         => 20,
			),
		);
		if ( null !== $lat && null !== $lng ) {
			// Maps "location_coordinate" is "lat,lng,zoom" (zoom 0-21).
			$payload[0]['location_coordinate'] = sprintf( '%F,%F,%d', (float) $lat, (float) $lng, 14 );
		}

		$result = self::post( 'serp/google/maps/live/advanced', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = $result['tasks'][0]['result'][0]['items'] ?? array();
		if ( empty( $items ) || ! is_array( $items ) ) {
			return new WP_Error(
				'dfs_empty',
				sprintf(
				/* translators: %s: business name */
					__( 'No Google Business Profile found matching "%s".', 'midland-local-seo' ),
					$name
				)
			);
		}

		// Pick the best title match (case-insensitive substring), else first.
		$row    = $items[0];
		$needle = strtolower( $name );
		foreach ( $items as $candidate ) {
			$title = strtolower( (string) ( $candidate['title'] ?? '' ) );
			if ( '' !== $title && false !== strpos( $title, $needle ) ) {
				$row = $candidate;
				break;
			}
		}
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'dfs_empty', __( 'DataForSEO returned an unexpected listing format.', 'midland-local-seo' ) );
		}

		$rating          = isset( $row['rating'] ) && is_array( $row['rating'] ) ? $row['rating'] : array();
		$additional_cats = array();
		if ( ! empty( $row['additional_categories'] ) && is_array( $row['additional_categories'] ) ) {
			$additional_cats = array_values( array_filter( array_map( 'strval', $row['additional_categories'] ) ) );
		}
		$work_hours_present = false;
		if ( isset( $row['work_time'] ) && ! empty( $row['work_time'] ) ) {
			$work_hours_present = true;
		} elseif ( ! empty( $row['work_hours'] ) ) {
			$work_hours_present = true;
		}
		$attributes = array();
		if ( ! empty( $row['attributes'] ) && is_array( $row['attributes'] ) ) {
			$attributes = $row['attributes'];
		}
		$address = '';
		if ( ! empty( $row['address'] ) ) {
			$address = (string) $row['address'];
		} elseif ( ! empty( $row['address_info']['address'] ) ) {
			$address = (string) $row['address_info']['address'];
		}

		$listing = array(
			'title'                 => (string) ( $row['title'] ?? $name ),
			'rating'                => isset( $rating['value'] ) ? (float) $rating['value'] : null,
			'rating_votes'          => isset( $rating['votes_count'] ) ? (int) $rating['votes_count'] : (int) ( $row['rating_votes'] ?? 0 ),
			'category'              => (string) ( $row['category'] ?? '' ),
			'additional_categories' => $additional_cats,
			'work_hours_present'    => (bool) $work_hours_present,
			'photos_count'          => (int) ( $row['total_photos'] ?? ( $row['photos_count'] ?? 0 ) ),
			'attributes'            => $attributes,
			'description'           => (string) ( $row['description'] ?? '' ),
			'phone'                 => (string) ( $row['phone'] ?? '' ),
			'address'               => $address,
			'url'                   => (string) ( $row['url'] ?? '' ),
			'claimed'               => isset( $row['is_claimed'] ) ? (bool) $row['is_claimed'] : null,
		);

		set_transient( $cache_key, $listing, HOUR_IN_SECONDS * 6 );
		return $listing;
	}

	/**
	 * Fetch Google Maps competitors for a category/keyword at a coordinate.
	 *
	 * Uses DataForSEO SERP Google Maps live endpoint:
	 *   serp/google/maps/live/advanced
	 * Documented payload fields: keyword, location_coordinate ("lat,lng,zoom"),
	 * language_code, depth. Each "maps_search" item carries title, rating
	 * (value + votes_count), category, additional_categories, work_hours,
	 * total_photos, address, phone, url, domain. We parse defensively and
	 * degrade to a partial list (or WP_Error) — never fatal.
	 *
	 * @param string     $category_or_keyword Search term, e.g. "floor cleaning".
	 * @param float|null $lat                 Latitude.
	 * @param float|null $lng                 Longitude.
	 * @param int        $depth               Result depth (max competitors).
	 * @return array|WP_Error List of competitor arrays.
	 */
	public static function get_maps_competitors( $category_or_keyword, $lat, $lng, $depth = 20 ) {
		$keyword = sanitize_text_field( $category_or_keyword );
		if ( '' === $keyword ) {
			return new WP_Error( 'no_keyword', __( 'A category or keyword is required to find competitors.', 'midland-local-seo' ) );
		}
		if ( null === $lat || null === $lng ) {
			return new WP_Error( 'no_coordinate', __( 'A center latitude and longitude are required.', 'midland-local-seo' ) );
		}

		$depth     = max( 1, min( 100, (int) $depth ) );
		$cache_key = 'mls_maps_competitors_' . md5( $keyword . '|' . (string) $lat . '|' . (string) $lng . '|' . $depth );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Google Maps "location_coordinate" is "lat,lng,zoom" (zoom 0-21).
		$payload = array(
			array(
				'keyword'             => $keyword,
				'location_coordinate' => sprintf( '%F,%F,%d', (float) $lat, (float) $lng, 14 ),
				'language_code'       => 'en',
				'depth'               => $depth,
			),
		);

		$result = self::post( 'serp/google/maps/live/advanced', $payload );

		// Fallback: if the DataForSEO plan lacks the Maps SERP endpoint, retry with
		// the organic SERP endpoint and pull the local pack (3-pack) from it — the
		// parse loop below already accepts 'local_pack' item types.
		if ( is_wp_error( $result ) ) {
			$msg     = $result->get_error_message();
			$is_auth = ( false !== stripos( $msg, 'not authorized' ) || false !== stripos( $msg, 'access denied' ) || false !== stripos( $msg, '40301' ) );
			if ( ! $is_auth ) {
				return $result;
			}
			$organic = array(
				array(
					'keyword'             => $keyword,
					'location_coordinate' => sprintf( '%F,%F,%d', (float) $lat, (float) $lng, 20 ),
					'language_code'       => 'en',
					'depth'               => 30,
				),
			);
			$result = self::post( 'serp/google/organic/live/advanced', $organic );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$items = isset( $result['tasks'][0]['result'][0]['items'] ) && is_array( $result['tasks'][0]['result'][0]['items'] )
			? $result['tasks'][0]['result'][0]['items']
			: array();

		$out = array();
		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			// SERP-Maps returns several item types for ranked map listings.
			$allowed_types = array( 'maps_search', 'local_pack', 'maps' );
			if ( '' !== $type && ! in_array( $type, $allowed_types, true ) ) {
				continue;
			}
			$title = (string) ( isset( $row['title'] ) ? $row['title'] : '' );
			$rank  = (int) ( isset( $row['rank_absolute'] ) ? $row['rank_absolute'] : 0 );
			// A usable listing needs at least a title or a rank.
			if ( '' === $title && 0 === $rank ) {
				continue;
			}
			$rating          = isset( $row['rating'] ) && is_array( $row['rating'] ) ? $row['rating'] : array();
			$additional_cats = array();
			if ( ! empty( $row['additional_categories'] ) && is_array( $row['additional_categories'] ) ) {
				$additional_cats = array_values( array_filter( array_map( 'strval', $row['additional_categories'] ) ) );
			}
			$address = '';
			if ( ! empty( $row['address'] ) ) {
				$address = (string) $row['address'];
			} elseif ( ! empty( $row['address_info']['address'] ) ) {
				$address = (string) $row['address_info']['address'];
			}
			$out[] = array(
				'title'                 => $title,
				'rating'                => isset( $rating['value'] ) ? (float) $rating['value'] : null,
				'rating_votes'          => isset( $rating['votes_count'] ) ? (int) $rating['votes_count'] : 0,
				'category'              => (string) ( isset( $row['category'] ) ? $row['category'] : '' ),
				'additional_categories' => $additional_cats,
				'photos_count'          => (int) ( isset( $row['total_photos'] ) ? $row['total_photos'] : ( isset( $row['photos_count'] ) ? $row['photos_count'] : 0 ) ),
				'work_hours_present'    => ! empty( $row['work_hours'] ) || ! empty( $row['work_time'] ),
				'phone'                 => (string) ( isset( $row['phone'] ) ? $row['phone'] : '' ),
				'address'               => $address,
				'url'                   => (string) ( isset( $row['url'] ) ? $row['url'] : '' ),
				'domain'                => (string) ( isset( $row['domain'] ) ? $row['domain'] : '' ),
				'rank'                  => $rank,
			);
		}

		if ( empty( $out ) ) {
			return new WP_Error(
				'dfs_empty',
				sprintf(
				/* translators: %s: keyword */
					__( 'No Google Maps competitors returned for "%s".', 'midland-local-seo' ),
					$keyword
				)
			);
		}

		set_transient( $cache_key, $out, HOUR_IN_SECONDS * 6 );
		return $out;
	}
}
