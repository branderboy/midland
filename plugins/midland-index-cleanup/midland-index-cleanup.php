<?php
/**
 * Plugin Name: Midland Index Cleanup (Temp)
 * Description: Temporary cleanup for the GSC "Crawled - currently not indexed" junk (June 2026 drilldown: 1,132 URLs, ~94% shopdetail / index.php spam remnants). Serves 410 Gone for spam URL patterns, adds robots.txt disallows, and noindexes thin archives. Review and remove after ~90 days once GSC coverage is clean.
 * Version: 1.0.0
 * Author: Midland Floor Care
 * License: GPL v2 or later
 * Text Domain: midland-index-cleanup
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spam-pattern 410s + robots rules + thin-archive noindex.
 */
class Midland_Index_Cleanup {

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve_410_for_spam' ), 0 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_rules' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'noindex_thin_archives' ), 1 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	/**
	 * Whether the current request matches a spam remnant pattern.
	 *
	 * Patterns from the 2026-06-10 GSC drilldown:
	 *  - /shopdetail/12345...            (516 URLs)
	 *  - /index.php?shopdetail/123...    (121 URLs)
	 *  - /?shopdetail/123...
	 *  - /discount.php?shopdetail/...    (11 URLs)
	 *  - /index.php/zhHant/product/...   (Japanese marketplace spam)
	 *  - /index.php/man/..., /index.php/feature/...
	 *
	 * @return bool
	 */
	private static function is_spam_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $uri ) {
			return false;
		}
		// Any mention of shopdetail anywhere (path or query) is spam here.
		if ( false !== stripos( $uri, 'shopdetail' ) ) {
			return true;
		}
		// discount.php does not exist on a WordPress site.
		if ( false !== stripos( $uri, '/discount.php' ) ) {
			return true;
		}
		// PATH_INFO style /index.php/... URLs: this site uses pretty permalinks,
		// so every one of these is hack residue (zhHant/surugaya/kaitori spam).
		if ( 0 === stripos( $uri, '/index.php/' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * 410 Gone for spam remnants: tells Google the URL is permanently dead,
	 * which clears them from crawl rotation far faster than 404.
	 */
	public static function serve_410_for_spam() {
		if ( ! self::is_spam_request() ) {
			return;
		}

		// Lightweight tally so the plugins screen shows it is working.
		$count = (int) get_option( 'mic_410_count', 0 );
		update_option( 'mic_410_count', $count + 1, false );

		status_header( 410 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		echo '<!DOCTYPE html><html><head><title>410 Gone</title></head><body><h1>410 Gone</h1><p>This URL never belonged to this site and has been permanently removed.</p></body></html>';
		exit;
	}

	/**
	 * robots.txt rules so crawlers stop wasting budget on the dead patterns.
	 *
	 * @param string $output Existing robots.txt content.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public static function robots_rules( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}
		$output .= "\n# Midland Index Cleanup: spam remnants from old hack footprint (temp)\n";
		$output .= "User-agent: *\n";
		$output .= "Disallow: /shopdetail/\n";
		$output .= "Disallow: /*?shopdetail\n";
		$output .= "Disallow: /*?*shopdetail\n";
		$output .= "Disallow: /discount.php\n";
		$output .= "Disallow: /index.php/\n";
		return $output;
	}

	/**
	 * noindex,follow for thin archives that leak into GSC (tag, author, date,
	 * testimonial-category). Links remain crawlable; pages drop from coverage.
	 */
	public static function noindex_thin_archives() {
		if ( is_tag() || is_author() || is_date() || is_tax( 'testimonial-category' ) ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}
	}

	/**
	 * Show the running 410 tally on the plugins screen.
	 *
	 * @param array  $meta Plugin row meta.
	 * @param string $file Plugin file.
	 * @return array
	 */
	public static function row_meta( $meta, $file ) {
		if ( false !== strpos( (string) $file, 'midland-index-cleanup' ) ) {
			$meta[] = sprintf( '410s served: %d', (int) get_option( 'mic_410_count', 0 ) );
		}
		return $meta;
	}
}

Midland_Index_Cleanup::init();
