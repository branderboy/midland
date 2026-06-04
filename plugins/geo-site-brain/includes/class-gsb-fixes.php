<?php
/**
 * Fix Queue. Turns knowledge-graph gaps into actionable fixes — each with a
 * problem, why it matters, impact, difficulty and (where possible) a one-click
 * apply handler. Apply handlers are reversible and always create drafts or
 * editable metadata rather than publishing live changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Fixes {

	const SITE_SCHEMA_OPTION = 'gsb_localbusiness_jsonld';
	const POST_SCHEMA_META   = '_gsb_jsonld';

	/**
	 * Rebuild the open fix queue from current entities, relationships and scores.
	 */
	public static function generate() {
		GSB_Database::clear_recommendations( 'heuristic' );

		self::fix_missing_services();
		self::fix_missing_locations();
		self::fix_missing_coverage();
		self::fix_localbusiness_schema();
		self::fix_faq_schema();
		self::fix_missing_meta();
		self::fix_weak_pages();

		GSB_Logger::info( 'fixes', 'Fix queue rebuilt.' );
	}

	/* ---------------------------------------------------------- generators */

	private static function fix_missing_services() {
		foreach ( GSB_Database::get_entities( 'service', 'recommended' ) as $svc ) {
			GSB_Database::add_recommendation( array(
				'rec_type'   => 'missing_service_page',
				'title'      => sprintf( __( 'Create a page for "%s"', 'geo-site-brain' ), $svc->name ),
				'detail'     => sprintf( __( 'AI has no clear page describing "%s" as one of your services.', 'geo-site-brain' ), $svc->name ),
				'reason'     => __( 'AI systems recommend businesses for services they can clearly identify. A dedicated page makes this service understandable.', 'geo-site-brain' ),
				'impact'     => 'high',
				'difficulty' => 'easy',
				'fix_action' => 'create_service_page',
				'fix_payload'=> array( 'name' => $svc->name ),
			) );
		}
	}

	private static function fix_missing_locations() {
		foreach ( GSB_Database::get_entities( 'location', 'recommended' ) as $loc ) {
			GSB_Database::add_recommendation( array(
				'rec_type'   => 'missing_location_page',
				'title'      => sprintf( __( 'Create a service-area page for "%s"', 'geo-site-brain' ), $loc->name ),
				'detail'     => sprintf( __( 'AI cannot confirm that you serve "%s".', 'geo-site-brain' ), $loc->name ),
				'reason'     => __( 'Location pages are how AI and local search establish your service area for "near me" and city-level questions.', 'geo-site-brain' ),
				'impact'     => 'medium',
				'difficulty' => 'easy',
				'fix_action' => 'create_location_page',
				'fix_payload'=> array( 'location' => $loc->name ),
			) );
		}
	}

	private static function fix_missing_coverage() {
		$matrix = GSB_Knowledge_Graph::matrix();
		$svc_by_id = array();
		foreach ( $matrix['services'] as $s ) { $svc_by_id[ (int) $s->id ] = $s; }
		$loc_by_id = array();
		foreach ( $matrix['locations'] as $l ) { $loc_by_id[ (int) $l->id ] = $l; }

		$made = 0;
		foreach ( GSB_Database::get_relationships( 'offered_in' ) as $r ) {
			if ( $made >= 6 ) {
				break;
			}
			if ( 'recommended' !== $r->status ) {
				continue;
			}
			$s = $svc_by_id[ (int) $r->from_id ] ?? null;
			$l = $loc_by_id[ (int) $r->to_id ] ?? null;
			if ( ! $s || ! $l ) {
				continue;
			}
			GSB_Database::add_recommendation( array(
				'rec_type'   => 'missing_coverage',
				'title'      => sprintf( __( 'Show "%1$s" in "%2$s"', 'geo-site-brain' ), $s->name, $l->name ),
				'detail'     => sprintf( __( 'No page connects "%1$s" with "%2$s".', 'geo-site-brain' ), $s->name, $l->name ),
				'reason'     => __( 'Service-in-location pages capture high-intent local AI and search queries and strengthen your entity graph.', 'geo-site-brain' ),
				'impact'     => 'medium',
				'difficulty' => 'medium',
				'fix_action' => 'create_location_page',
				'fix_payload'=> array( 'location' => $l->name, 'service' => $s->name ),
			) );
			$made++;
		}
	}

	private static function fix_localbusiness_schema() {
		// Already have it?
		if ( get_option( self::SITE_SCHEMA_OPTION ) ) {
			return;
		}
		// Any page already declaring LocalBusiness/Organization?
		foreach ( GSB_Database::get_scores( 'score', 'DESC', 1000 ) as $row ) {
			$det = json_decode( (string) $row->details, true ) ?: array();
			$schema_notes = isset( $det['schema_coverage'] ) ? strtolower( implode( ' ', (array) $det['schema_coverage'] ) ) : '';
			if ( false !== strpos( $schema_notes, 'localbusiness' ) || false !== strpos( $schema_notes, 'organization' ) ) {
				return;
			}
		}
		GSB_Database::add_recommendation( array(
			'rec_type'   => 'schema',
			'title'      => __( 'Add business (LocalBusiness) structured data', 'geo-site-brain' ),
			'detail'     => __( 'Your site has no LocalBusiness structured data describing the business, its contact details and service area.', 'geo-site-brain' ),
			'reason'     => __( 'LocalBusiness schema is the single most important signal for AI and local search to identify who you are, where you operate and how to contact you.', 'geo-site-brain' ),
			'impact'     => 'high',
			'difficulty' => 'easy',
			'fix_action' => 'generate_localbusiness_schema',
			'fix_payload'=> array(),
		) );
	}

	private static function fix_faq_schema() {
		foreach ( GSB_Database::get_scores( 'score', 'ASC', 1000 ) as $row ) {
			$sub = json_decode( (string) $row->subscores, true ) ?: array();
			if ( ( $sub['faq_coverage'] ?? 0 ) >= 55 && ( $sub['schema_coverage'] ?? 0 ) < 50 ) {
				$title = get_the_title( $row->post_id ) ?: $row->url;
				GSB_Database::add_recommendation( array(
					'post_id'    => $row->post_id,
					'rec_type'   => 'schema',
					'title'      => sprintf( __( 'Add FAQ structured data to "%s"', 'geo-site-brain' ), $title ),
					'detail'     => __( 'This page has question-and-answer content but no FAQ structured data.', 'geo-site-brain' ),
					'reason'     => __( 'AI engines pull FAQ structured data directly into answers. This makes your answers eligible to be quoted.', 'geo-site-brain' ),
					'impact'     => 'high',
					'difficulty' => 'easy',
					'fix_action' => 'generate_faq_schema',
					'fix_payload'=> array( 'post_id' => (int) $row->post_id ),
				) );
			}
		}
	}

	private static function fix_missing_meta() {
		foreach ( GSB_Database::get_scores( 'score', 'ASC', 1000 ) as $row ) {
			$det = json_decode( (string) $row->details, true ) ?: array();
			$svc = isset( $det['service_clarity'] ) ? (array) $det['service_clarity'] : array();
			if ( in_array( 'meta description missing', $svc, true ) ) {
				$title = get_the_title( $row->post_id ) ?: $row->url;
				GSB_Database::add_recommendation( array(
					'post_id'    => $row->post_id,
					'rec_type'   => 'meta_rewrite',
					'title'      => sprintf( __( 'Write a search description for "%s"', 'geo-site-brain' ), $title ),
					'detail'     => __( 'This page has no meta description.', 'geo-site-brain' ),
					'reason'     => __( 'The meta description is often the snippet AI and search engines use to summarise the page. A missing one leaves the summary to chance.', 'geo-site-brain' ),
					'impact'     => 'medium',
					'difficulty' => GSB_Settings::has_openai() ? 'easy' : 'medium',
					'fix_action' => 'generate_meta',
					'fix_payload'=> array( 'post_id' => (int) $row->post_id ),
				) );
			}
		}
	}

	private static function fix_weak_pages() {
		foreach ( GSB_Database::get_scores( 'score', 'ASC', 12 ) as $row ) {
			if ( (int) $row->score >= 50 ) {
				continue;
			}
			$title = get_the_title( $row->post_id ) ?: $row->url;
			GSB_Database::add_recommendation( array(
				'post_id'    => $row->post_id,
				'rec_type'   => 'weak_page',
				'title'      => sprintf( __( 'Strengthen "%1$s" (understood %2$d%%)', 'geo-site-brain' ), $title, (int) $row->score ),
				'detail'     => __( 'AI struggles to understand this page.', 'geo-site-brain' ),
				'reason'     => __( 'Thin or unstructured pages are hard for AI to interpret and unlikely to be cited or recommended.', 'geo-site-brain' ),
				'impact'     => (int) $row->score < 35 ? 'high' : 'medium',
				'difficulty' => 'medium',
				'fix_action' => 'manual',
				'fix_payload'=> array( 'post_id' => (int) $row->post_id ),
			) );
		}
	}

	/* ------------------------------------------------------------- apply */

	/**
	 * Apply a fix by id. Returns array(message, [edit_url]) or WP_Error.
	 * Caller (admin AJAX) enforces nonce + capability.
	 */
	public static function apply( $id ) {
		$rec = GSB_Database::get_recommendation( $id );
		if ( ! $rec ) {
			return new WP_Error( 'gsb_no_fix', __( 'Fix not found.', 'geo-site-brain' ) );
		}
		$payload = json_decode( (string) $rec->fix_payload, true ) ?: array();

		$result = self::dispatch( $rec, $payload );
		if ( ! is_wp_error( $result ) && empty( $result['manual'] ) ) {
			GSB_Webhooks::fire( 'fix.applied', array(
				'id'     => (int) $rec->id,
				'title'  => $rec->title,
				'action' => $rec->fix_action,
			) );
		}
		return $result;
	}

	private static function dispatch( $rec, $payload ) {
		$id = (int) $rec->id;
		switch ( $rec->fix_action ) {
			case 'create_service_page':
				return self::do_create_page( $id, $payload, 'service' );
			case 'create_location_page':
				return self::do_create_page( $id, $payload, 'location' );
			case 'generate_meta':
				return self::do_generate_meta( $id, $payload );
			case 'generate_faq_schema':
				return self::do_generate_faq_schema( $id, $payload );
			case 'generate_localbusiness_schema':
				return self::do_generate_localbusiness_schema( $id );
			default:
				$edit = ! empty( $payload['post_id'] ) ? get_edit_post_link( (int) $payload['post_id'], 'raw' ) : '';
				return array(
					'message'  => __( 'This one is a manual fix — open the editor to make the change.', 'geo-site-brain' ),
					'edit_url' => $edit,
					'manual'   => true,
				);
		}
	}

	private static function do_create_page( $id, $payload, $kind ) {
		$brand = trim( (string) GSB_Settings::get( 'business_name' ) ) ?: get_bloginfo( 'name' );

		if ( 'service' === $kind ) {
			$title = $payload['name'] ?? __( 'New service', 'geo-site-brain' );
		} else {
			$service = $payload['service'] ?? '';
			$loc     = $payload['location'] ?? __( 'New area', 'geo-site-brain' );
			$title   = $service ? sprintf( '%1$s in %2$s', $service, $loc ) : sprintf( __( 'Serving %s', 'geo-site-brain' ), $loc );
		}

		$content  = sprintf( "<h1>%s</h1>\n", esc_html( $title ) );
		$content .= sprintf( "<p>%s</p>\n", esc_html( sprintf( __( '%1$s provides %2$s. Replace this starter copy with details, benefits, and a clear call to action.', 'geo-site-brain' ), $brand, strtolower( $title ) ) ) );
		$content .= "<h2>" . esc_html__( 'What we offer', 'geo-site-brain' ) . "</h2>\n<p>…</p>\n";
		$content .= "<h2>" . esc_html__( 'Frequently asked questions', 'geo-site-brain' ) . "</h2>\n";
		$content .= "<h3>" . esc_html__( 'Question?', 'geo-site-brain' ) . "</h3>\n<p>…</p>\n";

		$post_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => 'page',
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		GSB_Database::mark_recommendation_applied( $id );
		return array(
			'message'  => sprintf( __( 'Draft page "%s" created. Review and publish when ready.', 'geo-site-brain' ), $title ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	private static function do_generate_meta( $id, $payload ) {
		$post_id = (int) ( $payload['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return new WP_Error( 'gsb_no_post', __( 'No page specified.', 'geo-site-brain' ) );
		}
		$openai = new GSB_OpenAI();
		if ( ! $openai->has_key() ) {
			return new WP_Error( 'gsb_no_key', __( 'Connect AI in Setup to auto-write descriptions.', 'geo-site-brain' ) );
		}
		$a = GSB_Scanner::analyze( $post_id );
		if ( ! $a ) {
			return new WP_Error( 'gsb_no_post', __( 'Could not read that page.', 'geo-site-brain' ) );
		}
		$brand = trim( (string) GSB_Settings::get( 'business_name' ) );
		$prompt = "Write a meta description (max 155 characters) for this page. Lead with the primary service and location if relevant. No quotes, one line.\n\n"
			. 'BUSINESS: ' . $brand . "\nTITLE: " . $a['title'] . "\nCONTENT: " . mb_substr( $a['plain'], 0, 1500 );
		$desc = $openai->chat( array( array( 'role' => 'user', 'content' => $prompt ) ), 0.4, 120 );
		if ( is_wp_error( $desc ) ) {
			return $desc;
		}
		$desc = trim( wp_strip_all_tags( $desc ) );
		$desc = mb_substr( $desc, 0, 160 );

		self::save_meta_description( $post_id, $desc );
		GSB_Database::mark_recommendation_applied( $id );
		return array(
			'message'  => sprintf( __( 'Description added: "%s"', 'geo-site-brain' ), $desc ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/** Write to whichever SEO plugin is active; fall back to all common keys. */
	private static function save_meta_description( $post_id, $desc ) {
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
		} elseif ( class_exists( 'RankMath' ) ) {
			update_post_meta( $post_id, 'rank_math_description', $desc );
		} elseif ( defined( 'AIOSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_aioseo_description', $desc );
		} else {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
			update_post_meta( $post_id, 'rank_math_description', $desc );
		}
	}

	private static function do_generate_faq_schema( $id, $payload ) {
		$post_id = (int) ( $payload['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return new WP_Error( 'gsb_no_post', __( 'No page specified.', 'geo-site-brain' ) );
		}
		$a = GSB_Scanner::analyze( $post_id );
		if ( ! $a || empty( $a['faqs'] ) ) {
			return new WP_Error( 'gsb_no_faq', __( 'No FAQ content found on that page.', 'geo-site-brain' ) );
		}
		$entities = array();
		foreach ( $a['faqs'] as $faq ) {
			if ( '' === trim( $faq['q'] ) ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $faq['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $faq['a'] ?: $faq['q'],
				),
			);
		}
		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
		self::store_post_schema( $post_id, 'faq', $schema );
		GSB_Database::mark_recommendation_applied( $id );
		return array(
			'message'  => sprintf( _n( 'FAQ structured data added (%d question).', 'FAQ structured data added (%d questions).', count( $entities ), 'geo-site-brain' ), count( $entities ) ),
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	private static function do_generate_localbusiness_schema( $id ) {
		$rows = GSB_Database::get_entities( 'business' );
		$biz  = $rows ? $rows[0] : null;
		$name = $biz ? $biz->name : ( trim( (string) GSB_Settings::get( 'business_name' ) ) ?: get_bloginfo( 'name' ) );
		$attr = $biz ? ( json_decode( (string) $biz->attributes, true ) ?: array() ) : array();

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'LocalBusiness',
			'name'     => $name,
			'url'      => home_url( '/' ),
		);
		if ( $biz && $biz->description ) {
			$schema['description'] = wp_strip_all_tags( $biz->description );
		}
		if ( ! empty( $attr['phone'] ) ) {
			$schema['telephone'] = $attr['phone'];
		}
		if ( ! empty( $attr['address'] ) ) {
			$schema['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $attr['address'] );
		}
		$areas = array();
		foreach ( GSB_Database::get_entities( 'location' ) as $l ) {
			if ( in_array( $l->status, array( 'found', 'inferred' ), true ) ) {
				$areas[] = $l->name;
			}
		}
		if ( $areas ) {
			$schema['areaServed'] = array_values( array_slice( $areas, 0, 25 ) );
		}

		update_option( self::SITE_SCHEMA_OPTION, wp_json_encode( $schema ) );
		GSB_Database::mark_recommendation_applied( $id );
		return array( 'message' => __( 'Business structured data added across the site. AI and search engines can now identify your business, contact details and service area.', 'geo-site-brain' ) );
	}

	private static function store_post_schema( $post_id, $key, $schema ) {
		$existing = get_post_meta( $post_id, self::POST_SCHEMA_META, true );
		$existing = is_array( $existing ) ? $existing : array();
		$existing[ $key ] = $schema;
		update_post_meta( $post_id, self::POST_SCHEMA_META, $existing );
	}

	/* ----------------------------------------------------- front-end output */

	/**
	 * Output any stored JSON-LD on the front end: the site-wide LocalBusiness on
	 * the front page, and per-page schema on singular views. Hooked on wp_head.
	 */
	public static function render_head() {
		if ( is_admin() ) {
			return;
		}
		if ( is_front_page() || is_home() ) {
			$site = get_option( self::SITE_SCHEMA_OPTION );
			if ( $site ) {
				echo "\n" . '<script type="application/ld+json">' . wp_kses_post( $site ) . '</script>' . "\n";
			}
		}
		if ( is_singular() ) {
			$post_schema = get_post_meta( get_the_ID(), self::POST_SCHEMA_META, true );
			if ( is_array( $post_schema ) ) {
				foreach ( $post_schema as $schema ) {
					echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
				}
			}
		}
	}
}
