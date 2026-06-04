<?php
/**
 * Entity engine. Turns the raw scan into business-language entities — Business,
 * Services, Locations, FAQs, Testimonials, Reviews, Authors, Case Studies —
 * each tagged found / inferred / recommended. This is the layer that lets the
 * rest of the product talk about "your services" and "your service areas"
 * instead of pages and chunks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Entities {

	/**
	 * Rebuild the whole entity set from the current site content. Bounded work
	 * (one analysis pass per page); runs at the end of a scan and on cron.
	 */
	public static function rebuild_from_site( $pages = null ) {
		GSB_Database::clear_entities();

		if ( null === $pages ) {
			$pages = array();
			foreach ( GSB_Scanner::all_post_ids() as $id ) {
				$a = GSB_Scanner::analyze( $id );
				if ( $a ) {
					$pages[ $id ] = $a;
				}
			}
		}

		self::build_business( $pages );
		self::build_services( $pages );
		self::build_locations( $pages );
		self::build_faqs( $pages );
		self::build_testimonials_reviews( $pages );
		self::build_authors();
		self::build_case_studies( $pages );

		GSB_Logger::info( 'entities', sprintf( 'Knowledge entities rebuilt from %d pages.', count( $pages ) ) );
	}

	/* --------------------------------------------------------------- business */

	private static function build_business( $pages ) {
		$name = trim( (string) GSB_Settings::get( 'business_name' ) );
		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}

		// Best description: home page meta/excerpt, else tagline.
		$desc = (string) get_bloginfo( 'description' );
		$front = (int) get_option( 'page_on_front' );
		if ( $front && isset( $pages[ $front ] ) ) {
			$desc = $pages[ $front ]['meta_desc'] ?: ( $pages[ $front ]['excerpt'] ?: $desc );
		}

		// Find NAP signals across pages.
		$phone = ''; $address = '';
		foreach ( $pages as $a ) {
			if ( '' === $phone && preg_match( '/(\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4})/', $a['plain'], $m ) ) {
				$phone = $m[1];
			}
			if ( '' === $address && preg_match( '/\d{1,5}\s+[A-Za-z0-9.\s]+(?:St|Street|Ave|Avenue|Rd|Road|Blvd|Suite|Ste|Dr|Drive)\b[^.]{0,40}/', $a['plain'], $m ) ) {
				$address = trim( $m[0] );
			}
			if ( $phone && $address ) {
				break;
			}
		}

		$conf = 60 + ( $desc ? 15 : 0 ) + ( $phone ? 15 : 0 ) + ( $address ? 10 : 0 );

		return GSB_Database::upsert_entity( array(
			'entity_type'    => 'business',
			'name'           => $name,
			'slug'           => 'business',
			'description'    => $desc,
			'status'         => 'found',
			'confidence'     => min( 100, $conf ),
			'source_post_id' => $front,
			'attributes'     => array( 'phone' => $phone, 'address' => $address ),
		) );
	}

	/* --------------------------------------------------------------- services */

	private static function build_services( $pages ) {
		$configured = GSB_Settings::services();

		// Detected service pages (title looks like a service offering).
		$detected = array();
		foreach ( $pages as $pid => $a ) {
			if ( self::looks_like_service( $a['title'] ) ) {
				$detected[ $a['title'] ] = $pid;
			}
		}

		$names = array();
		foreach ( $configured as $c ) { $names[ $c ] = true; }
		foreach ( array_keys( $detected ) as $d ) { $names[ $d ] = true; }

		foreach ( array_keys( $names ) as $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}
			list( $status, $source, $conf, $desc ) = self::evidence_for( $name, $pages );
			GSB_Database::upsert_entity( array(
				'entity_type'    => 'service',
				'name'           => $name,
				'slug'           => $name,
				'description'    => $desc,
				'status'         => $status,
				'confidence'     => $conf,
				'source_post_id' => $source,
			) );
		}
	}

	private static function looks_like_service( $title ) {
		return (bool) preg_match( '/\b(cleaning|repair|restoration|polishing|maintenance|installation|refinishing|removal|service|care|coating|sealing|striping|waxing|janitorial)\b/i', (string) $title );
	}

	/* -------------------------------------------------------------- locations */

	private static function build_locations( $pages ) {
		$configured = GSB_Settings::locations();

		// Detected location pages (title contains a configured location or a
		// city/state pattern).
		$detected = array();
		foreach ( $pages as $pid => $a ) {
			foreach ( $configured as $loc ) {
				if ( $loc && stripos( $a['title'], $loc ) !== false ) {
					$detected[ $loc ] = $pid;
				}
			}
		}

		$names = array();
		foreach ( $configured as $c ) { $names[ $c ] = true; }
		foreach ( array_keys( $detected ) as $d ) { $names[ $d ] = true; }

		foreach ( array_keys( $names ) as $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}
			list( $status, $source, $conf, $desc ) = self::evidence_for( $name, $pages );
			GSB_Database::upsert_entity( array(
				'entity_type'    => 'location',
				'name'           => $name,
				'slug'           => $name,
				'description'    => $desc,
				'status'         => $status,
				'confidence'     => $conf,
				'source_post_id' => $source,
			) );
		}
	}

	/**
	 * Decide whether a term is evidenced on the site and how strongly.
	 * Returns [status, source_post_id, confidence, description].
	 */
	private static function evidence_for( $term, $pages ) {
		$term_l = strtolower( $term );
		// Dedicated page (term in the title) → strongest.
		foreach ( $pages as $pid => $a ) {
			if ( stripos( $a['title'], $term ) !== false ) {
				$desc = $a['meta_desc'] ?: ( $a['excerpt'] ?: wp_trim_words( $a['plain'], 30 ) );
				return array( 'found', $pid, 90, $desc );
			}
		}
		// Mentioned in body of some page → moderate.
		foreach ( $pages as $pid => $a ) {
			if ( strpos( strtolower( $a['plain'] ), $term_l ) !== false ) {
				return array( 'found', $pid, 65, wp_trim_words( $a['plain'], 25 ) );
			}
		}
		// Expected (configured) but not on the site → a gap to recommend.
		return array( 'recommended', 0, 30, '' );
	}

	/* -------------------------------------------------------------------- FAQs */

	private static function build_faqs( $pages ) {
		$count = 0;
		foreach ( $pages as $pid => $a ) {
			foreach ( $a['faqs'] as $faq ) {
				if ( $count >= 300 ) {
					break 2;
				}
				$q = trim( $faq['q'] );
				if ( '' === $q ) {
					continue;
				}
				GSB_Database::upsert_entity( array(
					'entity_type'    => 'faq',
					'name'           => $q,
					'slug'           => substr( md5( $q ), 0, 12 ) . '-' . sanitize_title( wp_trim_words( $q, 6, '' ) ),
					'description'    => $faq['a'],
					'status'         => 'found',
					'confidence'     => 80,
					'source_post_id' => $pid,
					'attributes'     => array( 'answer' => $faq['a'] ),
				) );
				$count++;
			}
		}
	}

	/* ----------------------------------------------------- testimonials/reviews */

	private static function build_testimonials_reviews( $pages ) {
		// Testimonial CPT posts.
		$cpt = get_posts( array(
			'post_type'      => array( 'testimonials', 'testimonial' ),
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		foreach ( $cpt as $id ) {
			$content = wp_strip_all_tags( get_post_field( 'post_content', $id ) );
			$title   = get_the_title( $id );
			GSB_Database::upsert_entity( array(
				'entity_type'    => 'testimonial',
				'name'           => $title ?: wp_trim_words( $content, 8 ),
				'slug'           => 'cpt-' . $id,
				'description'    => $content,
				'status'         => 'found',
				'confidence'     => 85,
				'source_post_id' => $id,
			) );
		}

		// Inline testimonials + review schema mined from pages.
		$has_review_schema = false;
		$i = 0;
		foreach ( $pages as $pid => $a ) {
			foreach ( array_map( 'strtolower', $a['schema_types'] ) as $t ) {
				if ( false !== strpos( $t, 'review' ) || false !== strpos( $t, 'aggregaterating' ) ) {
					$has_review_schema = true;
				}
			}
			foreach ( $a['testimonials'] as $quote ) {
				GSB_Database::upsert_entity( array(
					'entity_type'    => 'testimonial',
					'name'           => wp_trim_words( $quote, 8 ),
					'slug'           => 'inline-' . $pid . '-' . $i,
					'description'    => $quote,
					'status'         => 'found',
					'confidence'     => 70,
					'source_post_id' => $pid,
				) );
				$i++;
			}
		}

		if ( $has_review_schema ) {
			GSB_Database::upsert_entity( array(
				'entity_type' => 'review',
				'name'        => __( 'Customer reviews', 'geo-site-brain' ),
				'slug'        => 'reviews',
				'description' => __( 'Review / rating structured data detected on the site.', 'geo-site-brain' ),
				'status'      => 'found',
				'confidence'  => 80,
			) );
		}
	}

	/* ------------------------------------------------------------------ authors */

	private static function build_authors() {
		// Authors = users who can edit posts; filtered by real post count below.
		$author_ids = get_users( array( 'capability' => array( 'edit_posts' ), 'fields' => 'ID', 'number' => 50 ) );
		if ( empty( $author_ids ) ) {
			$author_ids = get_users( array( 'fields' => 'ID', 'number' => 50 ) );
		}
		foreach ( $author_ids as $uid ) {
			$count = (int) count_user_posts( $uid, 'post' );
			if ( $count < 1 ) {
				continue;
			}
			$name = get_the_author_meta( 'display_name', $uid );
			GSB_Database::upsert_entity( array(
				'entity_type' => 'author',
				'name'        => $name,
				'slug'        => 'author-' . $uid,
				'description' => sprintf( /* translators: %d post count */ _n( '%d published article', '%d published articles', $count, 'geo-site-brain' ), $count ),
				'status'      => 'found',
				'confidence'  => 60,
				'attributes'  => array( 'post_count' => $count, 'user_id' => $uid ),
			) );
		}
	}

	/* -------------------------------------------------------------- case studies */

	private static function build_case_studies( $pages ) {
		foreach ( $pages as $pid => $a ) {
			if ( preg_match( '/\b(case study|success story|scenario|results|how we)\b/i', $a['title'] ) ) {
				GSB_Database::upsert_entity( array(
					'entity_type'    => 'case_study',
					'name'           => $a['title'],
					'slug'           => 'cs-' . $pid,
					'description'    => $a['meta_desc'] ?: wp_trim_words( $a['plain'], 30 ),
					'status'         => 'found',
					'confidence'     => 70,
					'source_post_id' => $pid,
				) );
			}
		}
	}
}
