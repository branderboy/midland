<?php
/**
 * Content scanner + chunker. Reads a post (page, post, CPT, service, location,
 * FAQ, testimonial…) and breaks it into ordered, standalone content chunks:
 * title, meta title/description, H1, H2/H3 sections, hero, services, FAQs,
 * testimonials, location mentions, CTAs, schema and internal links.
 *
 * The structured result is also reused by the scorer, so detection logic lives
 * here in one place.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Scanner {

	/**
	 * Which post types to index, intersected with what's actually registered &
	 * public so we never try to walk something that isn't there.
	 */
	public static function scannable_post_types() {
		$wanted = GSB_Settings::indexed_post_types();
		$exists = get_post_types( array(), 'names' );
		$out    = array();
		foreach ( $wanted as $t ) {
			if ( isset( $exists[ $t ] ) ) {
				$out[] = $t;
			}
		}
		return $out ? $out : array( 'page', 'post' );
	}

	/**
	 * IDs of all publishable posts across the indexed post types, ordered for a
	 * resumable scan.
	 */
	public static function all_post_ids() {
		$ids = get_posts( array(
			'post_type'      => self::scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Full structured analysis of a post. Returns an associative array the
	 * indexer turns into chunks and the scorer reads for signals.
	 */
	public static function analyze( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$url       = get_permalink( $post );
		$raw_html  = (string) $post->post_content;
		$rendered  = apply_filters( 'the_content', $raw_html );

		// Page builders (Elementor) store content in post meta, not post_content,
		// so the_content can come back thin. Mine the Elementor tree directly and
		// merge it in so scanning, scoring and search see the real content.
		$elementor = self::elementor_html( $post );
		if ( '' !== $elementor && mb_strlen( wp_strip_all_tags( $rendered ) ) < mb_strlen( wp_strip_all_tags( $elementor ) ) ) {
			$rendered .= "\n" . $elementor;
		}

		$plain     = self::plain_text( $rendered );

		$headings  = self::extract_headings( $rendered );
		$sections  = self::extract_sections( $rendered );

		$data = array(
			'post_id'       => (int) $post->ID,
			'url'           => $url,
			'content_type'  => $post->post_type,
			'title'         => get_the_title( $post ),
			'meta_title'    => self::meta_title( $post ),
			'meta_desc'     => self::meta_description( $post ),
			'excerpt'       => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
			'h1'            => $headings['h1'],
			'sections'      => $sections,
			'plain'         => $plain,
			'word_count'    => str_word_count( $plain ),
			'faqs'          => self::extract_faqs( $rendered, $headings ),
			'testimonials'  => self::extract_testimonials( $rendered, $post ),
			'ctas'          => self::extract_ctas( $rendered ),
			'internal_links'=> self::internal_links( $rendered, $url ),
			'images_alt'    => self::image_alts( $rendered, $post ),
			'schema_types'  => self::schema_types( $rendered, $post ),
			'locations'     => self::location_mentions( $plain ),
			'terms'         => self::post_terms( $post ),
		);

		return $data;
	}

	/**
	 * Turn an analysis into a flat list of chunks ready for embedding. Each
	 * chunk is standalone text prefixed with light context so the embedding is
	 * meaningful out of page order.
	 *
	 * @return array[] each: section_type, text
	 */
	public static function build_chunks( array $data ) {
		$chunks = array();
		$title  = $data['title'];

		$push = static function ( &$chunks, $type, $text ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
			if ( '' !== $text ) {
				$chunks[] = array( 'section_type' => $type, 'text' => $text );
			}
		};

		$push( $chunks, 'title', $title );
		if ( $data['meta_title'] ) {
			$push( $chunks, 'meta_title', $data['meta_title'] );
		}
		if ( $data['meta_desc'] ) {
			$push( $chunks, 'meta_desc', $data['meta_desc'] );
		}
		if ( $data['h1'] && $data['h1'] !== $title ) {
			$push( $chunks, 'h1', $data['h1'] );
		}
		if ( $data['excerpt'] ) {
			$push( $chunks, 'hero', $title . ' — ' . $data['excerpt'] );
		}

		// H2/H3 sections become individual chunks, optionally split on length.
		foreach ( $data['sections'] as $section ) {
			$heading = $section['heading'];
			$body    = $section['text'];
			$type    = self::classify_section( $heading, $body );
			$prefix  = $heading ? ( $heading . ': ' ) : '';
			foreach ( self::split_text( $body, (int) GSB_Settings::get( 'chunk_max_chars', 1500 ) ) as $piece ) {
				$push( $chunks, $type, $prefix . $piece );
			}
		}

		foreach ( $data['faqs'] as $faq ) {
			$push( $chunks, 'faq', 'FAQ: ' . $faq['q'] . ' ' . $faq['a'] );
		}
		foreach ( $data['testimonials'] as $t ) {
			$push( $chunks, 'testimonial', 'Testimonial: ' . $t );
		}
		foreach ( $data['ctas'] as $cta ) {
			$push( $chunks, 'cta', 'Call to action: ' . $cta );
		}
		if ( ! empty( $data['locations'] ) ) {
			$push( $chunks, 'location', $title . ' mentions: ' . implode( ', ', $data['locations'] ) );
		}
		if ( ! empty( $data['internal_links'] ) ) {
			$anchors = array();
			foreach ( $data['internal_links'] as $l ) {
				$anchors[] = $l['anchor'];
			}
			$push( $chunks, 'internal_link', 'Internal links on "' . $title . '": ' . implode( '; ', array_slice( $anchors, 0, 40 ) ) );
		}
		if ( ! empty( $data['schema_types'] ) ) {
			$push( $chunks, 'schema', 'Structured data on "' . $title . '": ' . implode( ', ', $data['schema_types'] ) );
		}

		// Fallback: if a page has almost no structured sections, embed a slice of
		// the plain body so it's still searchable.
		if ( count( $chunks ) < 2 && $data['plain'] ) {
			foreach ( self::split_text( $data['plain'], (int) GSB_Settings::get( 'chunk_max_chars', 1500 ) ) as $piece ) {
				$push( $chunks, 'body', $title . ': ' . $piece );
			}
		}

		return $chunks;
	}

	/* --------------------------------------------------------- meta + helpers */

	public static function meta_title( $post ) {
		$t = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
		if ( ! $t ) {
			$t = get_post_meta( $post->ID, 'rank_math_title', true );
		}
		if ( ! $t ) {
			$t = get_post_meta( $post->ID, '_aioseo_title', true );
		}
		return is_string( $t ) ? trim( $t ) : '';
	}

	public static function meta_description( $post ) {
		$d = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( ! $d ) {
			$d = get_post_meta( $post->ID, 'rank_math_description', true );
		}
		if ( ! $d ) {
			$d = get_post_meta( $post->ID, '_aioseo_description', true );
		}
		return is_string( $d ) ? trim( $d ) : '';
	}

	private static function plain_text( $html ) {
		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	private static function dom( $html ) {
		if ( '' === trim( (string) $html ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		return $dom;
	}

	private static function extract_headings( $html ) {
		$out = array( 'h1' => '', 'h2' => array(), 'h3' => array() );
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return $out;
		}
		foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
				$text = trim( $node->textContent );
				if ( '' === $text ) {
					continue;
				}
				if ( 'h1' === $tag && '' === $out['h1'] ) {
					$out['h1'] = $text;
				} elseif ( 'h1' !== $tag ) {
					$out[ $tag ][] = $text;
				}
			}
		}
		return $out;
	}

	/**
	 * Split the body into (heading, text) sections at each H2/H3.
	 */
	private static function extract_sections( $html ) {
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return array();
		}
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//h2 | //h3 | //p | //li' );
		if ( ! $nodes ) {
			return array();
		}
		$sections = array();
		$current  = array( 'heading' => '', 'text' => '' );
		foreach ( $nodes as $node ) {
			$tag  = strtolower( $node->nodeName );
			$text = trim( $node->textContent );
			if ( '' === $text ) {
				continue;
			}
			if ( 'h2' === $tag || 'h3' === $tag ) {
				if ( '' !== $current['heading'] || '' !== trim( $current['text'] ) ) {
					$sections[] = $current;
				}
				$current = array( 'heading' => $text, 'text' => '' );
			} else {
				$current['text'] .= ' ' . $text;
			}
		}
		if ( '' !== $current['heading'] || '' !== trim( $current['text'] ) ) {
			$sections[] = $current;
		}
		return $sections;
	}

	private static function classify_section( $heading, $body ) {
		$h = strtolower( $heading );
		if ( preg_match( '/\?$/', trim( $heading ) ) || preg_match( '/\b(faq|question)/i', $h ) ) {
			return 'faq';
		}
		if ( preg_match( '/\b(service|what we (do|offer)|solutions|capabilities)\b/i', $h ) ) {
			return 'service';
		}
		if ( preg_match( '/\b(area|location|serv(e|ing)|cities|where)\b/i', $h ) ) {
			return 'location';
		}
		if ( preg_match( '/\b(review|testimonial|client|what.*say)\b/i', $h ) ) {
			return 'testimonial';
		}
		return 'h2_section';
	}

	private static function split_text( $text, $max ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( '' === $text ) {
			return array();
		}
		if ( mb_strlen( $text ) <= $max ) {
			return array( $text );
		}
		$pieces   = array();
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text );
		$buffer    = '';
		foreach ( $sentences as $s ) {
			if ( mb_strlen( $buffer ) + mb_strlen( $s ) + 1 > $max && '' !== $buffer ) {
				$pieces[] = trim( $buffer );
				$buffer   = '';
			}
			$buffer .= ' ' . $s;
		}
		if ( '' !== trim( $buffer ) ) {
			$pieces[] = trim( $buffer );
		}
		return $pieces;
	}

	private static function extract_faqs( $html, $headings ) {
		$faqs = array();
		$dom  = self::dom( $html );
		if ( ! $dom ) {
			return $faqs;
		}
		$xpath = new DOMXPath( $dom );
		// Heading-style FAQs: an H2/H3/H4 ending in "?" followed by content.
		$qnodes = $xpath->query( '//h2 | //h3 | //h4 | //strong | //dt' );
		foreach ( $qnodes as $node ) {
			$q = trim( $node->textContent );
			if ( '' === $q || '?' !== mb_substr( $q, -1 ) ) {
				continue;
			}
			$answer = '';
			$sib    = $node->nextSibling;
			$guard  = 0;
			while ( $sib && $guard < 4 ) {
				if ( XML_ELEMENT_NODE === $sib->nodeType ) {
					$answer .= ' ' . trim( $sib->textContent );
					if ( in_array( strtolower( $sib->nodeName ), array( 'p', 'dd', 'div' ), true ) ) {
						break;
					}
				}
				$sib = $sib->nextSibling;
				$guard++;
			}
			$faqs[] = array( 'q' => $q, 'a' => trim( preg_replace( '/\s+/', ' ', $answer ) ) );
		}
		return $faqs;
	}

	private static function extract_testimonials( $html, $post ) {
		$out = array();
		// CPT-based testimonials/reviews are themselves indexed as posts; here we
		// pull inline blockquotes and elements tagged testimonial/review.
		$dom = self::dom( $html );
		if ( $dom ) {
			foreach ( $dom->getElementsByTagName( 'blockquote' ) as $q ) {
				$t = trim( $q->textContent );
				if ( mb_strlen( $t ) > 20 ) {
					$out[] = $t;
				}
			}
			$xpath = new DOMXPath( $dom );
			$nodes = $xpath->query( '//*[contains(translate(@class,"TESTIMONIALREVIEW","testimonialreview"),"testimonial") or contains(translate(@class,"TESTIMONIALREVIEW","testimonialreview"),"review")]' );
			foreach ( $nodes as $n ) {
				$t = trim( $n->textContent );
				if ( mb_strlen( $t ) > 20 && mb_strlen( $t ) < 600 ) {
					$out[] = $t;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function extract_ctas( $html ) {
		$out = array();
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return $out;
		}
		foreach ( array( 'a', 'button' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
				$text = trim( $node->textContent );
				if ( '' === $text ) {
					continue;
				}
				if ( preg_match( '/\b(call|quote|estimate|contact|book|schedule|get started|request|free|today|now|learn more|sign up)\b/i', $text ) ) {
					$out[] = $text;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function internal_links( $html, $self_url ) {
		$out  = array();
		$dom  = self::dom( $html );
		if ( ! $dom ) {
			return $out;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( '' === $href || 0 === strpos( $href, '#' ) ) {
				continue;
			}
			$lhost = wp_parse_url( $href, PHP_URL_HOST );
			$is_internal = ( ! $lhost || $lhost === $host );
			if ( $is_internal && $href !== $self_url ) {
				$out[] = array( 'href' => $href, 'anchor' => trim( $a->textContent ) );
			}
		}
		return $out;
	}

	private static function image_alts( $html, $post ) {
		$alts = array();
		// Featured image alt.
		$thumb = get_post_thumbnail_id( $post->ID );
		if ( $thumb ) {
			$alt = get_post_meta( $thumb, '_wp_attachment_image_alt', true );
			if ( $alt ) {
				$alts[] = $alt;
			}
		}
		$dom = self::dom( $html );
		if ( $dom ) {
			foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
				$alt = trim( (string) $img->getAttribute( 'alt' ) );
				$alts[] = $alt; // keep empties too — scorer counts missing alts
			}
		}
		return $alts;
	}

	/**
	 * Schema types present on the page: inline JSON-LD plus Yoast/RankMath meta.
	 */
	private static function schema_types( $html, $post ) {
		$types = array();
		if ( preg_match_all( '/<script[^>]*application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				if ( is_array( $data ) ) {
					$types = array_merge( $types, self::collect_types( $data ) );
				}
			}
		}
		// SEO-plugin schema set via meta (Yoast/RankMath render it on the front
		// end, not in post_content, so check meta too).
		$rm = get_post_meta( $post->ID, 'rank_math_rich_snippet', true );
		if ( $rm ) {
			$types[] = ucfirst( (string) $rm );
		}
		$yoast = get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true );
		if ( $yoast ) {
			$types[] = (string) $yoast;
		}
		return array_values( array_unique( array_filter( $types ) ) );
	}

	private static function collect_types( $node ) {
		$types = array();
		if ( isset( $node['@type'] ) ) {
			$t = $node['@type'];
			foreach ( (array) $t as $one ) {
				$types[] = (string) $one;
			}
		}
		foreach ( $node as $v ) {
			if ( is_array( $v ) ) {
				$types = array_merge( $types, self::collect_types( $v ) );
			}
		}
		return $types;
	}

	private static function location_mentions( $plain ) {
		$found     = array();
		$locations = GSB_Settings::locations();
		foreach ( $locations as $loc ) {
			if ( '' !== $loc && stripos( $plain, $loc ) !== false ) {
				$found[] = $loc;
			}
		}
		return $found;
	}

	private static function post_terms( $post ) {
		$out = array( 'categories' => array(), 'tags' => array() );
		$cats = get_the_terms( $post->ID, 'category' );
		if ( is_array( $cats ) ) {
			foreach ( $cats as $c ) {
				$out['categories'][] = $c->name;
			}
		}
		$tags = get_the_terms( $post->ID, 'post_tag' );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $t ) {
				$out['tags'][] = $t->name;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------- Elementor */

	/**
	 * Build an HTML approximation of an Elementor-built page by mining the
	 * _elementor_data tree, so its text feeds the same extraction pipeline.
	 * Returns '' when the post isn't an Elementor page.
	 */
	private static function elementor_html( $post ) {
		$raw = get_post_meta( $post->ID, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return '';
		}
		$data = json_decode( is_string( $raw ) ? $raw : '', true );
		if ( null === $data && is_string( $raw ) ) {
			$data = json_decode( wp_unslash( $raw ), true );
		}
		if ( ! is_array( $data ) ) {
			return '';
		}
		$html = '';
		self::walk_elementor( $data, $html );
		return $html;
	}

	/**
	 * Recursively walk Elementor elements, emitting HTML for the text-bearing
	 * widgets we care about.
	 */
	private static function walk_elementor( $elements, &$html ) {
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$type     = $el['widgetType'] ?? ( $el['elType'] ?? '' );
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();

			switch ( $type ) {
				case 'heading':
					$html .= self::tag( 'h2', $settings['title'] ?? '' );
					break;
				case 'text-editor':
				case 'theme-post-content':
					$html .= self::wrap_html( $settings['editor'] ?? '' );
					break;
				case 'icon-box':
				case 'image-box':
					$html .= self::tag( 'h3', $settings['title_text'] ?? '' );
					$html .= self::wrap_html( $settings['description_text'] ?? '' );
					break;
				case 'testimonial':
					$content = $settings['testimonial_content'] ?? '';
					if ( $content ) {
						$html .= '<blockquote>' . wp_kses_post( $content ) . '</blockquote>';
					}
					break;
				case 'call-to-action':
					$html .= self::tag( 'h3', $settings['title'] ?? '' );
					$html .= self::wrap_html( $settings['description'] ?? '' );
					break;
				case 'button':
					$txt = $settings['text'] ?? '';
					if ( $txt ) {
						$html .= '<a href="#">' . esc_html( $txt ) . '</a>';
					}
					break;
				case 'accordion':
				case 'toggle':
					foreach ( (array) ( $settings['tabs'] ?? array() ) as $tab ) {
						$html .= self::tag( 'h3', $tab['tab_title'] ?? '' );
						$html .= self::wrap_html( $tab['tab_content'] ?? '' );
					}
					break;
				case 'icon-list':
					$html .= '<ul>';
					foreach ( (array) ( $settings['icon_list'] ?? array() ) as $item ) {
						$html .= self::tag( 'li', $item['text'] ?? '' );
					}
					$html .= '</ul>';
					break;
				default:
					// Generic catch: pull common text-bearing fields from any widget.
					foreach ( array( 'title', 'subtitle', 'description', 'caption', 'content', 'editor', 'text' ) as $key ) {
						if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
							$html .= self::wrap_html( $settings[ $key ] );
						}
					}
					break;
			}

			if ( ! empty( $el['elements'] ) ) {
				self::walk_elementor( $el['elements'], $html );
			}
		}
	}

	private static function tag( $tag, $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		return '' === $text ? '' : '<' . $tag . '>' . esc_html( $text ) . '</' . $tag . '>';
	}

	/** Elementor editor fields already contain HTML; keep safe markup only. */
	private static function wrap_html( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return '';
		}
		// If it has no tags, wrap as a paragraph so it parses as a section body.
		if ( $html === wp_strip_all_tags( $html ) ) {
			return '<p>' . esc_html( $html ) . '</p>';
		}
		return wp_kses_post( $html );
	}
}
