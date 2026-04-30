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
     * Test credentials with a minimal API call.
     *
     * @return true|WP_Error
     */
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
