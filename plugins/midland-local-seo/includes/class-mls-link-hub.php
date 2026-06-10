<?php
/**
 * Link hub shortcodes for the mirror pages.
 *
 * [mls_service_links]  - published service pages list
 * [mls_location_links] - published location pages list
 * [mls_footer_links]   - both lists side by side (drop into the footer)
 *
 * Output is dynamic, so the footer never goes stale as pages are added.
 * These shortcodes are render-only: they NEVER touch nav menus or theme
 * locations (the header nav is off limits by design).
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the link-hub shortcodes.
 */
class MLS_Link_Hub {

	/**
	 * Hook the shortcodes.
	 */
	public static function init() {
		add_shortcode( 'mls_service_links', array( __CLASS__, 'service_links' ) );
		add_shortcode( 'mls_location_links', array( __CLASS__, 'location_links' ) );
		add_shortcode( 'mls_footer_links', array( __CLASS__, 'footer_links' ) );
	}

	/**
	 * Published mirror pages by group.
	 *
	 * @param string $group services|locations.
	 * @return array id => label.
	 */
	private static function targets( $group ) {
		if ( ! class_exists( 'MLS_GMB_Mirror' ) ) {
			return array();
		}
		$targets = MLS_GMB_Mirror::get_instance()->link_targets();
		$out     = array();
		foreach ( ( $targets[ $group ] ?? array() ) as $id => $label ) {
			if ( 'publish' === get_post_status( $id ) ) {
				$out[ $id ] = $label;
			}
		}
		return $out;
	}

	/**
	 * <ul> of links.
	 *
	 * @param array  $items        id => label.
	 * @param string $label_prefix Optional prefix (location labels).
	 * @return string
	 */
	private static function render_list( $items, $label_prefix = '' ) {
		if ( empty( $items ) ) {
			return '';
		}
		$html = '<ul class="mls-link-hub">';
		foreach ( $items as $id => $label ) {
			$html .= '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( $label_prefix . $label ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * [mls_service_links]
	 *
	 * @return string
	 */
	public static function service_links() {
		return self::render_list( self::targets( 'services' ) );
	}

	/**
	 * [mls_location_links]
	 *
	 * @return string
	 */
	public static function location_links() {
		return self::render_list( self::targets( 'locations' ), 'Floor Care in ' );
	}

	/**
	 * [mls_footer_links] - the 3-4 CORE hub links only (hub-and-spoke: the
	 * footer points at the hubs, the hubs link to everything). Keeps the
	 * footer short no matter how many pages exist.
	 *
	 * @return string
	 */
	public static function footer_links() {
		if ( ! class_exists( 'MLS_GMB_Mirror' ) ) {
			return '';
		}
		$core = MLS_GMB_Mirror::get_instance()->core_pages();
		if ( empty( $core ) ) {
			return '';
		}
		$html = '<ul class="mls-link-hub mls-core-links">';
		foreach ( $core as $url => $label ) {
			$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		return $html . '</ul>';
	}
}

MLS_Link_Hub::init();
