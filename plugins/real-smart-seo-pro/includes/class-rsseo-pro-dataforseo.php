<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DataForSEO API integration.
 * BYOK — user provides their own DataForSEO login + password.
 * Docs: https://docs.dataforseo.com/v3/
 */
class RSSEO_Pro_DataForSEO {

    const BASE_URL = 'https://api.dataforseo.com/v3/';

    public static function get_login() {
        return get_option( 'rsseo_pro_dfs_login', '' );
    }

    public static function get_password() {
        return RSSEO_Settings::decrypt_key( get_option( 'rsseo_pro_dfs_password', '' ) );
    }

    public static function is_configured() {
        return ! empty( self::get_login() ) && ! empty( self::get_password() );
    }

    public static function save_credentials( $login, $password ) {
        update_option( 'rsseo_pro_dfs_login', sanitize_text_field( $login ) );
        update_option( 'rsseo_pro_dfs_password', RSSEO_Settings::encrypt_key( $password ) );
    }

    /**
     * Make a POST request to the DataForSEO API.
     */
    private static function post( $endpoint, $payload ) {
        $login    = self::get_login();
        $password = self::get_password();

        if ( empty( $login ) || empty( $password ) ) {
            return new WP_Error( 'no_credentials', __( 'DataForSEO credentials not configured.', 'real-smart-seo-pro' ) );
        }

        $response = wp_remote_post( self::BASE_URL . $endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $payload ),
        ) );

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
     * Get keyword search volume + CPC + competition for a list of keywords.
     *
     * @param array  $keywords List of keyword strings.
     * @param string $location_code DataForSEO location code (e.g. 2840 for USA).
     * @param string $language_code e.g. 'en'
     * @return array|WP_Error
     */
    public static function get_keyword_data( $keywords, $location_code = 2840, $language_code = 'en' ) {
        $payload = array(
            array(
                'keywords'      => array_slice( array_map( 'sanitize_text_field', $keywords ), 0, 700 ),
                'location_code' => (int) $location_code,
                'language_code' => sanitize_text_field( $language_code ),
            ),
        );

        $result = self::post( 'keywords_data/google_ads/search_volume/live', $payload );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $items = $result['tasks'][0]['result'] ?? array();
        $output = array();

        foreach ( $items as $item ) {
            $output[] = array(
                'keyword'     => $item['keyword'],
                'volume'      => $item['search_volume'],
                'cpc'         => $item['cpc'],
                'competition' => $item['competition'],
                'trend'       => $item['monthly_searches'] ?? array(),
            );
        }

        return $output;
    }

    /**
     * Get Google Trends data for keywords.
     *
     * @param array  $keywords Up to 5 keywords.
     * @param string $location_code
     * @param string $date_from Y-m-d
     * @param string $date_to   Y-m-d
     * @return array|WP_Error
     */
    public static function get_trends( $keywords, $location_code = 2840, $date_from = null, $date_to = null ) {
        if ( null === $date_from ) {
            $date_from = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
        }
        if ( null === $date_to ) {
            $date_to = gmdate( 'Y-m-d' );
        }

        $payload = array(
            array(
                'keywords'      => array_slice( array_map( 'sanitize_text_field', $keywords ), 0, 5 ),
                'location_code' => (int) $location_code,
                'date_from'     => $date_from,
                'date_to'       => $date_to,
                'type'          => 'web',
            ),
        );

        $result = self::post( 'keywords_data/google_trends/explore/live', $payload );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result['tasks'][0]['result'] ?? array();
    }

    /**
     * Get live SERP results for a keyword (competitor analysis).
     *
     * @param string $keyword
     * @param string $location_code
     * @param string $language_code
     * @param int    $depth Number of results (10, 20, 30...).
     * @return array|WP_Error
     */
    public static function get_serp( $keyword, $location_code = 2840, $language_code = 'en', $depth = 10 ) {
        $payload = array(
            array(
                'keyword'       => sanitize_text_field( $keyword ),
                'location_code' => (int) $location_code,
                'language_code' => sanitize_text_field( $language_code ),
                'depth'         => (int) $depth,
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
                'rank'        => $item['rank_absolute'],
                'url'         => $item['url'],
                'title'       => $item['title'],
                'description' => $item['description'],
                'domain'      => $item['domain'],
            );
        }

        return $output;
    }

    /**
     * Get live SERP for a keyword at a specific lat/lng — used by the Geo-Grid Rank Tracker.
     * Returns the same shape as get_serp().
     *
     * @param string $keyword
     * @param float  $lat
     * @param float  $lng
     * @param int    $radius_km
     * @param string $language_code
     * @param int    $depth
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
     * Test credentials with a minimal API call.
     *
     * @return true|WP_Error
     */
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
        $cache_key = 'rsseo_pro_backlinks_summary_' . md5( $target );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $payload = array( array(
            'target'                => $target,
            'internal_list_limit'   => 10,
            'backlinks_status_type' => 'live',
        ) );
        $result = self::post( 'backlinks/summary/live', $payload );
        if ( is_wp_error( $result ) ) return $result;

        $row = $result['tasks'][0]['result'][0] ?? null;
        if ( ! is_array( $row ) ) {
            return new WP_Error( 'dfs_empty', 'DataForSEO returned no backlinks data for ' . $target );
        }
        $summary = array(
            'target'              => $target,
            'rank'                => (int) ( $row['rank'] ?? 0 ),
            'backlinks'           => (int) ( $row['backlinks'] ?? 0 ),
            'referring_domains'   => (int) ( $row['referring_domains'] ?? 0 ),
            'referring_main'      => (int) ( $row['referring_main_domains'] ?? 0 ),
            'broken_backlinks'    => (int) ( $row['broken_backlinks'] ?? 0 ),
            'lost_backlinks_30d'  => (int) ( $row['referring_domains_lost_60_days'] ?? 0 ),
            'new_backlinks_30d'   => (int) ( $row['referring_domains_new_60_days'] ?? 0 ),
            'anchors'             => (int) ( $row['anchors'] ?? 0 ),
            'crawled_at'          => time(),
        );
        set_transient( $cache_key, $summary, HOUR_IN_SECONDS * 6 );
        return $summary;
    }

    /**
     * Top referring domains for a target. Cached.
     *
     * @return array|WP_Error
     */
    public static function get_referring_domains( $target = '', $limit = 25 ) {
        if ( '' === $target ) {
            $target = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
        }
        $cache_key = 'rsseo_pro_referring_' . md5( $target . '|' . $limit );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $payload = array( array(
            'target'              => $target,
            'limit'               => (int) $limit,
            'order_by'            => array( 'rank,desc' ),
            'backlinks_status_type' => 'live',
        ) );
        $result = self::post( 'backlinks/referring_domains/live', $payload );
        if ( is_wp_error( $result ) ) return $result;

        $items = $result['tasks'][0]['result'][0]['items'] ?? array();
        $out = array();
        foreach ( $items as $row ) {
            $out[] = array(
                'domain'        => $row['domain'] ?? '',
                'rank'          => (int) ( $row['rank'] ?? 0 ),
                'backlinks'     => (int) ( $row['backlinks'] ?? 0 ),
                'first_seen'    => $row['first_seen'] ?? null,
                'lost'          => ! empty( $row['is_lost'] ),
                'broken'        => ! empty( $row['is_broken'] ),
            );
        }
        set_transient( $cache_key, $out, HOUR_IN_SECONDS * 6 );
        return $out;
    }

    public static function test_connection() {
        $result = self::post( 'keywords_data/google_ads/search_volume/live', array(
            array(
                'keywords'      => array( 'test' ),
                'location_code' => 2840,
                'language_code' => 'en',
            ),
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    /**
     * Pull all DataForSEO data for a pro scan and return formatted string for Claude prompt.
     *
     * @param array  $keywords     Keywords entered by user.
     * @param string $location     Location string (e.g. "Washington DC").
     * @param int    $location_code DataForSEO location code.
     * @return string
     */
    public static function pull_scan_data( $keywords, $location, $location_code = 2840 ) {
        if ( empty( $keywords ) || ! self::is_configured() ) {
            return '';
        }

        $output = "## DATAFORSEO — LIVE DATA\n";
        $output .= "Location: {$location}\n\n";

        // Keyword volume.
        $kw_data = self::get_keyword_data( $keywords, $location_code );
        if ( ! is_wp_error( $kw_data ) && ! empty( $kw_data ) ) {
            $output .= "### Keyword Data (Volume / CPC / Competition)\n";
            foreach ( $kw_data as $kw ) {
                $output .= "- {$kw['keyword']}: vol={$kw['volume']}, cpc=\${$kw['cpc']}, competition={$kw['competition']}\n";
            }
            $output .= "\n";
        }

        // Trends.
        $trends = self::get_trends( array_slice( $keywords, 0, 5 ), $location_code );
        if ( ! is_wp_error( $trends ) && ! empty( $trends ) ) {
            $output .= "### Google Trends (12 months)\n";
            $output .= wp_json_encode( $trends ) . "\n\n";
        }

        // SERP for top 3 keywords.
        foreach ( array_slice( $keywords, 0, 3 ) as $kw ) {
            $serp = self::get_serp( $kw, $location_code );
            if ( ! is_wp_error( $serp ) && ! empty( $serp ) ) {
                $output .= "### SERP Results: \"{$kw}\"\n";
                foreach ( $serp as $r ) {
                    $output .= "#{$r['rank']} {$r['domain']} — {$r['title']}\n";
                }
                $output .= "\n";
            }
        }

        return $output;
    }
}
