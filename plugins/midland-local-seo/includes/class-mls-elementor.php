<?php
/**
 * Self-contained Elementor template builder.
 *
 * Wraps any post in the Midland page template (Hero, Content, CTA sections)
 * with the full body copy inside the content widget. No other plugin needed:
 * this is the guarantee that every page the Mirror creates or rewrites renders
 * in the site design instead of as raw text.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and applies the Elementor layout.
 */
class MLS_Elementor {

	/**
	 * Apply the template to a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    { hero_kicker, hero_title, intro, body_html, cta_heading, cta_sub }.
	 */
	public static function apply( $post_id, $args ) {
		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
		$phone    = ! empty( $identity['business_phone'] ) ? $identity['business_phone'] : '(240) 532-9097';

		$defaults = array(
			'hero_kicker' => __( 'Our Services', 'midland-local-seo' ),
			'hero_title'  => get_the_title( $post_id ),
			'intro'       => sprintf( '%s serves homes and businesses across the DMV with professional floor care.', $business ),
			'body_html'   => (string) get_post_field( 'post_content', $post_id ),
			'cta_heading' => __( 'Ready for floors that work as hard as you do?', 'midland-local-seo' ),
			'cta_sub'     => __( 'Free on-site evaluation and a quote that does not change after the job starts.', 'midland-local-seo' ),
			'links_html'  => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$data = self::build_sections(
			$args['hero_kicker'],
			$args['hero_title'],
			$args['intro'],
			$args['body_html'],
			$args['cta_heading'],
			$args['cta_sub'],
			$phone,
			$args['links_html']
		);

		$data = array_values( array_filter( $data ) );

		// wp_slash because update_post_meta runs through wp_unslash on read,
		// and Elementor expects the JSON to survive that round-trip intact.
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_elementor_version', '3.21.0' );
		// Keep the theme/Elementor Pro header + footer wrapped around the page.
		update_post_meta( $post_id, '_wp_page_template', 'elementor_header_footer' );

		self::refresh_css( $post_id );
	}

	/**
	 * Regenerate the page's Elementor CSS. Writing _elementor_data directly
	 * leaves the old generated stylesheet in place, and without this the
	 * section colors (CTA background, buttons) fall back to theme globals.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function refresh_css( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			$css = \Elementor\Core\Files\CSS\Post::create( (int) $post_id );
			$css->update();
		} elseif ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/**
	 * Hero, Content, (Links,) CTA sections. Every visual style is INLINE in
	 * the widget markup, so the design renders correctly even when Elementor's
	 * generated CSS is stale or missing. Cache-proof by construction.
	 *
	 * @param string $kicker      Small heading above the H1.
	 * @param string $hero_title  H1.
	 * @param string $intro       Hero intro line.
	 * @param string $body_html   Full page copy for the content widget.
	 * @param string $cta_heading CTA section heading.
	 * @param string $cta_sub     CTA section subline.
	 * @param string $phone       Phone for the CTA button.
	 * @param string $links_html  Optional internal-links block.
	 * @return array
	 */
	private static function build_sections( $kicker, $hero_title, $intro, $body_html, $cta_heading, $cta_sub, $phone, $links_html = '' ) {
		$tel_href = 'tel:' . preg_replace( '/[^0-9+]/', '', $phone );

		$hero_html = '<div style="background:#F3FCF4;text-align:center;padding:56px 24px 40px;">'
			. '<p style="color:#2F8137;font-size:13px;font-weight:800;letter-spacing:2px;text-transform:uppercase;margin:0 0 10px;">' . esc_html( $kicker ) . '</p>'
			. '<h1 style="color:#0F1411;font-size:44px;font-weight:800;line-height:1.05;margin:0 auto 16px;max-width:880px;">' . esc_html( $hero_title ) . '</h1>'
			. '<p style="color:#4B5563;font-size:17px;line-height:1.6;max-width:760px;margin:0 auto;">' . esc_html( $intro ) . '</p>'
			. '</div>';

		$content_html = '<div style="background:#FFFFFF;padding:48px 24px;">'
			. '<div style="max-width:880px;margin:0 auto;color:#0F1411;font-size:17px;line-height:1.7;">' . $body_html . '</div>'
			. '</div>';

		$button_style = 'display:inline-block;padding:18px 32px;border-radius:4px;font-weight:800;font-size:16px;letter-spacing:1px;text-transform:uppercase;text-decoration:none;margin:6px;';
		$cta_html     = '<div style="background:#0E2F14;text-align:center;padding:56px 24px;">'
			. '<h2 style="color:#FFFFFF;font-size:36px;font-weight:800;margin:0 0 12px;">' . esc_html( $cta_heading ) . '</h2>'
			. '<p style="color:#B7E5BD;font-size:17px;margin:0 0 24px;">' . esc_html( $cta_sub ) . '</p>'
			. '<p style="margin:0;">'
			. '<a href="' . esc_attr( $tel_href ) . '" style="' . $button_style . 'background:#43A94B;color:#FFFFFF;border:2px solid #43A94B;">' . esc_html( $phone ) . '</a>'
			. '<a href="/schedule-a-visit/" style="' . $button_style . 'background:#FFFFFF;color:#2F8137;border:2px solid #FFFFFF;">' . esc_html__( 'Schedule a Visit', 'midland-local-seo' ) . '</a>'
			. '</p></div>';

		$sections = array(
			self::section( 'Hero - ' . $hero_title, $hero_html ),
			self::section( 'Content', $content_html ),
		);

		if ( '' !== trim( $links_html ) ) {
			$sections[] = self::section(
				'Explore',
				'<div style="background:#F3FCF4;padding:32px 24px;">'
				. '<div style="max-width:880px;margin:0 auto;color:#0F1411;font-size:16px;line-height:1.7;">' . $links_html . '</div>'
				. '</div>'
			);
		}

		$sections[] = self::section( 'CTA', $cta_html );

		return $sections;
	}

	/**
	 * One full-width container holding one text-editor widget whose markup is
	 * fully inline-styled.
	 *
	 * @param string $title Section label in the editor.
	 * @param string $html  Inline-styled HTML.
	 * @return array
	 */
	private static function section( $title, $html ) {
		return array(
			'id'       => self::eid(),
			'elType'   => 'container',
			'settings' => array(
				'_title'        => $title,
				'content_width' => 'full',
				'padding'       => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ),
			),
			'elements' => array(
				array(
					'id'         => self::eid(),
					'elType'     => 'widget',
					'widgetType' => 'text-editor',
					'settings'   => array(
						'editor' => $html,
					),
					'elements'   => array(),
				),
			),
		);
	}

	/**
	 * Elementor element IDs are 7-char lowercase hex in the kit; matching that
	 * format keeps the editor happy.
	 *
	 * @return string
	 */
	private static function eid() {
		return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
	}
}
