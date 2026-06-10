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
	 * Hero, Content, CTA container structure (ported from the site kit).
	 *
	 * @param string $kicker      Small heading above the H1.
	 * @param string $hero_title  H1.
	 * @param string $intro       Hero intro line.
	 * @param string $body_html   Full page copy for the content widget.
	 * @param string $cta_heading CTA section heading.
	 * @param string $cta_sub     CTA section subline.
	 * @param string $phone       Phone for the CTA button.
	 * @return array
	 */
	private static function build_sections( $kicker, $hero_title, $intro, $body_html, $cta_heading, $cta_sub, $phone, $links_html = '' ) {
		$tel_href    = 'tel:' . preg_replace( '/[^0-9+]/', '', $phone );
		$flex_gap_20 = array( 'column' => '20', 'row' => '20', 'isLinked' => true, 'unit' => 'px', 'size' => 20 );
		$pad_zero    = array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true );
		$boxed       = array( 'unit' => 'px', 'size' => 920, 'sizes' => array() );

		return array(
			// SECTION 1: Hero.
			array(
				'id'       => self::eid(),
				'elType'   => 'container',
				'settings' => array(
					'_title'                => 'Hero - ' . $hero_title,
					'background_background' => 'classic',
					'background_color'      => '#F3FCF4',
					'content_width'         => 'full',
					'flex_direction'        => 'column',
					'flex_gap'              => $flex_gap_20,
					'padding'               => $pad_zero,
				),
				'elements' => array(
					array(
						'id'       => self::eid(),
						'elType'   => 'container',
						'settings' => array(
							'flex_direction'   => 'column',
							'content_width'    => 'boxed',
							'flex_gap'         => $flex_gap_20,
							'boxed_width'      => $boxed,
							'padding'          => array( 'unit' => 'em', 'top' => '3', 'right' => '1.5', 'bottom' => '2', 'left' => '1.5', 'isLinked' => false ),
							'flex_align_items' => 'center',
						),
						'elements' => array(
							array(
								'id'         => self::eid(),
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array(
									'title'                  => $kicker,
									'header_size'            => 'h6',
									'align'                  => 'center',
									'title_color'            => '#2F8137',
									'typography_typography'  => 'custom',
									'typography_font_size'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
									'typography_font_weight' => '800',
									'_margin'                => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '10', 'left' => '0', 'isLinked' => false ),
								),
								'elements'   => array(),
							),
							array(
								'id'         => self::eid(),
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array(
									'title'                  => $hero_title,
									'header_size'            => 'h1',
									'align'                  => 'center',
									'title_color'            => '#0F1411',
									'typography_typography'  => 'custom',
									'typography_font_size'   => array( 'unit' => 'px', 'size' => 44, 'sizes' => array() ),
									'typography_font_weight' => '800',
									'typography_line_height' => array( 'unit' => 'em', 'size' => 1.05, 'sizes' => array() ),
								),
								'elements'   => array(),
							),
							array(
								'id'         => self::eid(),
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array(
									'editor'                 => '<p>' . esc_html( $intro ) . '</p>',
									'align'                  => 'center',
									'text_color'             => '#4B5563',
									'typography_typography'  => 'custom',
									'typography_font_size'   => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
									'typography_line_height' => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
								),
								'elements'   => array(),
							),
						),
					),
				),
			),

			// SECTION 2: Content (the full done-for-you copy).
			array(
				'id'       => self::eid(),
				'elType'   => 'container',
				'settings' => array(
					'_title'                => 'Content',
					'background_background' => 'classic',
					'background_color'      => '#FFFFFF',
					'content_width'         => 'full',
					'flex_direction'        => 'column',
					'flex_gap'              => $flex_gap_20,
					'padding'               => $pad_zero,
				),
				'elements' => array(
					array(
						'id'       => self::eid(),
						'elType'   => 'container',
						'settings' => array(
							'flex_direction' => 'column',
							'content_width'  => 'boxed',
							'flex_gap'       => $flex_gap_20,
							'boxed_width'    => $boxed,
							'padding'        => array( 'unit' => 'em', 'top' => '3', 'right' => '1.5', 'bottom' => '2', 'left' => '1.5', 'isLinked' => false ),
						),
						'elements' => array(
							array(
								'id'         => self::eid(),
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array(
									'editor'                 => $body_html,
									'align'                  => 'left',
									'text_color'             => '#0F1411',
									'typography_typography'  => 'custom',
									'typography_font_size'   => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
									'typography_line_height' => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
								),
								'elements'   => array(),
							),
						),
					),
				),
			),

			// SECTION 3: Internal links (kills orphan pages: every generated
			// page links to the rest of the set).
			'' === trim( $links_html ) ? null : array(
				'id'       => self::eid(),
				'elType'   => 'container',
				'settings' => array(
					'_title'                => 'Explore',
					'background_background' => 'classic',
					'background_color'      => '#F3FCF4',
					'content_width'         => 'full',
					'flex_direction'        => 'column',
					'flex_gap'              => $flex_gap_20,
					'padding'               => $pad_zero,
				),
				'elements' => array(
					array(
						'id'       => self::eid(),
						'elType'   => 'container',
						'settings' => array(
							'flex_direction' => 'column',
							'content_width'  => 'boxed',
							'flex_gap'       => $flex_gap_20,
							'boxed_width'    => $boxed,
							'padding'        => array( 'unit' => 'em', 'top' => '2', 'right' => '1.5', 'bottom' => '2', 'left' => '1.5', 'isLinked' => false ),
						),
						'elements' => array(
							array(
								'id'         => self::eid(),
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array(
									'editor'                 => $links_html,
									'align'                  => 'left',
									'text_color'             => '#0F1411',
									'typography_typography'  => 'custom',
									'typography_font_size'   => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
									'typography_line_height' => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
								),
								'elements'   => array(),
							),
						),
					),
				),
			),

			// SECTION 4: CTA.
			array(
				'id'       => self::eid(),
				'elType'   => 'container',
				'settings' => array(
					'_title'                => 'CTA',
					'background_background' => 'classic',
					'background_color'      => '#0E2F14',
					'content_width'         => 'full',
					'flex_direction'        => 'column',
					'flex_gap'              => $flex_gap_20,
					'padding'               => $pad_zero,
				),
				'elements' => array(
					array(
						'id'       => self::eid(),
						'elType'   => 'container',
						'settings' => array(
							'flex_direction'   => 'column',
							'content_width'    => 'boxed',
							'flex_gap'         => $flex_gap_20,
							'boxed_width'      => $boxed,
							'padding'          => array( 'unit' => 'em', 'top' => '3', 'right' => '1.5', 'bottom' => '3', 'left' => '1.5', 'isLinked' => false ),
							'flex_align_items' => 'center',
						),
						'elements' => array(
							array(
								'id'         => self::eid(),
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array(
									'title'                  => $cta_heading,
									'header_size'            => 'h2',
									'align'                  => 'center',
									'title_color'            => '#FFFFFF',
									'typography_typography'  => 'custom',
									'typography_font_size'   => array( 'unit' => 'px', 'size' => 36, 'sizes' => array() ),
									'typography_font_weight' => '800',
								),
								'elements'   => array(),
							),
							array(
								'id'         => self::eid(),
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array(
									'editor'                => '<p>' . esc_html( $cta_sub ) . '</p>',
									'align'                 => 'center',
									'text_color'            => '#B7E5BD',
									'typography_typography' => 'custom',
									'typography_font_size'  => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
								),
								'elements'   => array(),
							),
							array(
								'id'       => self::eid(),
								'elType'   => 'container',
								'settings' => array(
									'flex_direction'       => 'row',
									'content_width'        => 'full',
									'flex_gap'             => array( 'column' => '12', 'row' => '12', 'isLinked' => true, 'unit' => 'px', 'size' => 12 ),
									'padding'              => $pad_zero,
									'flex_align_items'     => 'center',
									'flex_justify_content' => 'center',
								),
								'elements' => array(
									self::button( $phone, $tel_href, '#43A94B', '#FFFFFF', '#43A94B', '#2F8137' ),
									self::button( __( 'Schedule a Visit', 'midland-local-seo' ), '/schedule-a-visit/', '#FFFFFF', '#2F8137', '#FFFFFF', '#43A94B' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * CTA button widget.
	 *
	 * @param string $text        Button text.
	 * @param string $url         Link.
	 * @param string $bg          Background color.
	 * @param string $color       Text color.
	 * @param string $border      Border color.
	 * @param string $hover_bg    Hover background color.
	 * @return array
	 */
	private static function button( $text, $url, $bg, $color, $border, $hover_bg ) {
		return array(
			'id'         => self::eid(),
			'elType'     => 'widget',
			'widgetType' => 'button',
			'settings'   => array(
				'text'                          => $text,
				'link'                          => array( 'url' => $url, 'is_external' => '', 'nofollow' => '' ),
				'size'                          => 'lg',
				'align'                         => 'center',
				'background_color'              => $bg,
				'button_text_color'             => $color,
				'border_border'                 => 'solid',
				'border_color'                  => $border,
				'border_width'                  => array( 'unit' => 'px', 'top' => '2', 'right' => '2', 'bottom' => '2', 'left' => '2', 'isLinked' => true ),
				'border_radius'                 => array( 'unit' => 'px', 'top' => '4', 'right' => '4', 'bottom' => '4', 'left' => '4', 'isLinked' => true ),
				'typography_typography'         => 'custom',
				'typography_font_weight'        => '800',
				'typography_text_transform'     => 'uppercase',
				'typography_letter_spacing'     => array( 'unit' => 'px', 'size' => 1, 'sizes' => array() ),
				'typography_font_size'          => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
				'text_padding'                  => array( 'unit' => 'px', 'top' => '20', 'right' => '32', 'bottom' => '20', 'left' => '32', 'isLinked' => false ),
				'hover_color'                   => '#FFFFFF',
				'button_background_hover_color' => $hover_bg,
				'border_hover_color'            => $hover_bg,
			),
			'elements'   => array(),
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
