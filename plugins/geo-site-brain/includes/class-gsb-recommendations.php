<?php
/**
 * Recommendations engine. Rebuilds the open recommendation list from the latest
 * scores and site-wide content gaps (heuristic). Targets the weakest scoring
 * dimensions and surfaces missing FAQs/services/locations, weak/overlapping
 * pages, schema + internal-link gaps, meta rewrites, AI answer blocks and
 * Google Business Profile post ideas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Recommendations {

	/**
	 * Regenerate all open heuristic recommendations.
	 */
	public static function rebuild() {
		GSB_Database::clear_recommendations( 'heuristic' );

		$scores = GSB_Database::get_scores( 'score', 'ASC', 500 );
		self::per_page_recs( $scores );
		self::site_gap_recs( $scores );
		self::overlap_recs();

		GSB_Logger::info( 'recommendations', 'Recommendations rebuilt.' );
	}

	/* --------------------------------------------------------- per-page recs */

	private static function per_page_recs( $scores ) {
		foreach ( $scores as $row ) {
			$sub   = json_decode( (string) $row->subscores, true ) ?: array();
			$title = get_the_title( $row->post_id );
			$title = $title ? $title : ( $row->url ?: ( '#' . $row->post_id ) );

			if ( (int) $row->score < 60 ) {
				$weak = self::weakest( $sub, 3 );
				GSB_Database::add_recommendation( array(
					'post_id'  => $row->post_id,
					'rec_type' => 'weak_page',
					'priority' => (int) $row->score < 40 ? 'high' : 'medium',
					'title'    => sprintf( /* translators: 1: page title 2: score */ __( 'Strengthen weak page: %1$s (GEO %2$d)', 'geo-site-brain' ), $title, (int) $row->score ),
					'detail'   => __( 'Lowest dimensions: ', 'geo-site-brain' ) . implode( ', ', $weak ),
				) );
			}

			if ( isset( $sub['faq_coverage'] ) && $sub['faq_coverage'] < 55 ) {
				GSB_Database::add_recommendation( array(
					'post_id'  => $row->post_id,
					'rec_type' => 'missing_faq',
					'priority' => 'medium',
					'title'    => sprintf( __( 'Add an FAQ section to: %s', 'geo-site-brain' ), $title ),
					'detail'   => __( 'Add 3–5 question-and-answer pairs using real customer questions. Question-style headings feed FAQ schema and AI answer engines.', 'geo-site-brain' ),
				) );
			}

			if ( isset( $sub['schema_coverage'] ) && $sub['schema_coverage'] < 50 ) {
				GSB_Database::add_recommendation( array(
					'post_id'  => $row->post_id,
					'rec_type' => 'schema',
					'priority' => 'medium',
					'title'    => sprintf( __( 'Add structured data to: %s', 'geo-site-brain' ), $title ),
					'detail'   => __( 'Add relevant JSON-LD (LocalBusiness, Service, FAQPage, or Review) so search and AI engines can parse this page.', 'geo-site-brain' ),
				) );
			}

			if ( isset( $sub['internal_linking'] ) && $sub['internal_linking'] < 50 ) {
				GSB_Database::add_recommendation( array(
					'post_id'  => $row->post_id,
					'rec_type' => 'internal_links',
					'priority' => 'low',
					'title'    => sprintf( __( 'Add internal links on: %s', 'geo-site-brain' ), $title ),
					'detail'   => __( 'Link to related services, location pages, and supporting content. Aim for 3–5 relevant internal links.', 'geo-site-brain' ),
				) );
			}

			// Meta rewrites from stored evidence.
			$details = json_decode( (string) $row->details, true ) ?: array();
			$svc     = isset( $details['service_clarity'] ) ? (array) $details['service_clarity'] : array();
			if ( in_array( 'meta description missing', $svc, true ) ) {
				GSB_Database::add_recommendation( array(
					'post_id'  => $row->post_id,
					'rec_type' => 'meta_rewrite',
					'priority' => 'medium',
					'title'    => sprintf( __( 'Write a meta title & description for: %s', 'geo-site-brain' ), $title ),
					'detail'   => __( 'Missing meta description. Write a 150–160 character description that leads with the primary service + location.', 'geo-site-brain' ),
				) );
			}

			if ( isset( $sub['ai_answer_readiness'] ) && $sub['ai_answer_readiness'] < 55 ) {
				GSB_Database::add_recommendation( array(
					'post_id'  => $row->post_id,
					'rec_type' => 'ai_answer_block',
					'priority' => 'medium',
					'title'    => sprintf( __( 'Add an AI Overview answer block to: %s', 'geo-site-brain' ), $title ),
					'detail'   => __( 'Open the page with a concise 2–3 sentence answer to the core question, then expand. This is what AI Overviews and answer engines extract.', 'geo-site-brain' ),
				) );
			}
		}
	}

	private static function weakest( $sub, $n ) {
		if ( empty( $sub ) ) {
			return array();
		}
		asort( $sub );
		$labels = GSB_Scorer::labels();
		$out    = array();
		foreach ( array_slice( $sub, 0, $n, true ) as $k => $v ) {
			$out[] = ( $labels[ $k ] ?? $k ) . ' (' . (int) $v . ')';
		}
		return $out;
	}

	/* ------------------------------------------------------ site-level gaps */

	private static function site_gap_recs( $scores ) {
		$titles = self::all_titles();

		// Missing service pages.
		foreach ( GSB_Settings::services() as $service ) {
			if ( '' === $service ) {
				continue;
			}
			if ( ! self::topic_has_page( $service, $titles ) ) {
				GSB_Database::add_recommendation( array(
					'post_id'  => 0,
					'rec_type' => 'missing_service_page',
					'priority' => 'high',
					'title'    => sprintf( __( 'Create a service page for: %s', 'geo-site-brain' ), $service ),
					'detail'   => __( 'No page clearly targets this service. A dedicated page improves entity coverage, service clarity, and topical authority.', 'geo-site-brain' ),
				) );
			}
		}

		// Missing location pages.
		foreach ( GSB_Settings::locations() as $loc ) {
			if ( '' === $loc ) {
				continue;
			}
			if ( ! self::topic_has_page( $loc, $titles ) ) {
				GSB_Database::add_recommendation( array(
					'post_id'  => 0,
					'rec_type' => 'missing_location_page',
					'priority' => 'medium',
					'title'    => sprintf( __( 'Create a location page for: %s', 'geo-site-brain' ), $loc ),
					'detail'   => __( 'No page clearly targets this location. Location pages capture "near me" and city-level GEO/AEO queries.', 'geo-site-brain' ),
				) );
			}
		}

		// Google Business Profile post ideas: service × location combinations.
		$services  = array_slice( GSB_Settings::services(), 0, 4 );
		$locations = array_slice( GSB_Settings::locations(), 0, 3 );
		if ( $services && $locations ) {
			$ideas = array();
			foreach ( $services as $s ) {
				foreach ( $locations as $l ) {
					$ideas[] = sprintf( '%s in %s', $s, $l );
				}
			}
			$ideas = array_slice( $ideas, 0, 8 );
			GSB_Database::add_recommendation( array(
				'post_id'  => 0,
				'rec_type' => 'gbp_post_ideas',
				'priority' => 'low',
				'title'    => __( 'Google Business Profile post ideas', 'geo-site-brain' ),
				'detail'   => __( 'Publish GBP posts on: ', 'geo-site-brain' ) . implode( '; ', $ideas ),
			) );
		}
	}

	private static function all_titles() {
		$ids = GSB_Scanner::all_post_ids();
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = strtolower( get_the_title( $id ) . ' ' . get_post_field( 'post_name', $id ) );
		}
		return $out;
	}

	private static function topic_has_page( $topic, $titles ) {
		$topic = strtolower( trim( $topic ) );
		if ( '' === $topic ) {
			return true;
		}
		foreach ( $titles as $hay ) {
			if ( false !== strpos( $hay, $topic ) ) {
				return true;
			}
			// also match on the topic's main keyword
			$word = preg_split( '/\s+/', $topic )[0];
			if ( strlen( $word ) > 4 && false !== strpos( $hay, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------- overlap / duplicate pages */

	/**
	 * Flag near-duplicate pages by comparing their title-chunk embeddings.
	 * Cheap and bounded — only title chunks, only the local JSON vectors.
	 */
	private static function overlap_recs() {
		global $wpdb;
		$table = GSB_Database::table( 'chunks' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT post_id, url, embedding FROM {$table}
			 WHERE section_type = 'title' AND embedded = 1 AND embedding IS NOT NULL
			 LIMIT 400"
		);
		$vecs = array();
		foreach ( $rows as $r ) {
			$v = json_decode( (string) $r->embedding, true );
			if ( is_array( $v ) && $v ) {
				$vecs[] = array( 'post_id' => (int) $r->post_id, 'url' => $r->url, 'v' => $v, 'n' => self::norm( $v ) );
			}
		}
		$count = count( $vecs );
		$seen  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( $vecs[ $i ]['post_id'] === $vecs[ $j ]['post_id'] ) {
					continue;
				}
				$sim = self::cosine( $vecs[ $i ], $vecs[ $j ] );
				if ( $sim >= 0.92 ) {
					$key = min( $vecs[ $i ]['post_id'], $vecs[ $j ]['post_id'] ) . '-' . max( $vecs[ $i ]['post_id'], $vecs[ $j ]['post_id'] );
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					$a = get_the_title( $vecs[ $i ]['post_id'] ) ?: $vecs[ $i ]['url'];
					$b = get_the_title( $vecs[ $j ]['post_id'] ) ?: $vecs[ $j ]['url'];
					GSB_Database::add_recommendation( array(
						'post_id'  => $vecs[ $i ]['post_id'],
						'rec_type' => 'overlapping_pages',
						'priority' => 'medium',
						'title'    => sprintf( __( 'Overlapping pages: "%1$s" & "%2$s"', 'geo-site-brain' ), $a, $b ),
						'detail'   => sprintf( __( 'These pages are %d%% similar by topic. Consider consolidating, differentiating intent, or canonicalising to avoid cannibalisation.', 'geo-site-brain' ), (int) round( $sim * 100 ) ),
					) );
				}
			}
		}
	}

	private static function norm( $v ) {
		$s = 0.0;
		foreach ( $v as $x ) {
			$s += $x * $x;
		}
		return sqrt( $s );
	}

	private static function cosine( $a, $b ) {
		$dot = 0.0;
		$n   = min( count( $a['v'] ), count( $b['v'] ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$dot += $a['v'][ $i ] * $b['v'][ $i ];
		}
		if ( $a['n'] <= 0 || $b['n'] <= 0 ) {
			return 0.0;
		}
		return $dot / ( $a['n'] * $b['n'] );
	}
}
