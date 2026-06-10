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
	 * Whether the footer links already rendered on this request (via the
	 * shortcode placed in a footer template). Suppresses the automatic bar.
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Hook the shortcodes + the automatic pre-footer bar.
	 */
	public static function init() {
		add_shortcode( 'mls_service_links', array( __CLASS__, 'service_links' ) );
		add_shortcode( 'mls_location_links', array( __CLASS__, 'location_links' ) );
		add_shortcode( 'mls_footer_links', array( __CLASS__, 'footer_links' ) );
		add_action( 'wp_footer', array( __CLASS__, 'auto_footer' ), 5 );
		add_filter( 'wp_nav_menu', array( __CLASS__, 'detect_menu_render' ), 10, 2 );
	}

	/**
	 * If the "Service Links" menu renders anywhere on the page (e.g. through
	 * the theme's navigation widget in the footer), skip the automatic bar.
	 *
	 * @param string $nav_menu Rendered menu HTML.
	 * @param object $args     wp_nav_menu args.
	 * @return string Unchanged HTML.
	 */
	public static function detect_menu_render( $nav_menu, $args ) {
		$menu = isset( $args->menu ) ? $args->menu : null;
		$name = '';
		if ( $menu instanceof WP_Term ) {
			$name = $menu->name;
		} elseif ( is_object( $menu ) && isset( $menu->name ) ) {
			$name = (string) $menu->name;
		} elseif ( is_string( $menu ) ) {
			$name = $menu;
		} elseif ( is_numeric( $menu ) ) {
			$term = wp_get_nav_menu_object( (int) $menu );
			$name = $term ? $term->name : '';
		}
		if ( 'service links' === strtolower( trim( $name ) ) || 'service-links' === sanitize_title( $name ) ) {
			self::$rendered = true;
		}
		return $nav_menu;
	}

	/**
	 * Automatic core-links bar at the end of every page. Zero setup: no
	 * shortcode, no menu, no footer editing required. If [mls_footer_links]
	 * already rendered on this request (footer template has it), skip.
	 */
	public static function auto_footer() {
		if ( self::$rendered || is_admin() ) {
			return;
		}
		if ( ! class_exists( 'MLS_GMB_Mirror' ) ) {
			return;
		}
		$core = MLS_GMB_Mirror::get_instance()->core_pages();
		if ( empty( $core ) ) {
			return;
		}
		$links = array();
		foreach ( $core as $url => $label ) {
			$links[] = '<a href="' . esc_url( $url ) . '" style="color:#B7E5BD;text-decoration:none;font-weight:700;margin:0 16px;display:inline-block;">' . esc_html( $label ) . '</a>';
		}
		echo '<div class="mls-auto-footer" style="background:#0E2F14;text-align:center;padding:18px 24px;font-size:15px;line-height:2;">'
			. implode( '<span style="color:#2F8137;">|</span>', $links )
			. '</div>';
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
		self::$rendered = true;
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
