<?php
/**
 * GEO/AEO scoring engine. Produces a 1–100 score per page from ten weighted,
 * deterministic sub-scores (no API call needed). Each sub-score reads signals
 * from the scanner analysis; the evidence is stored alongside the score so the
 * dashboard can explain every number and the recommendations engine can target
 * the weakest dimensions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Scorer {

	/** Dimension => weight (sum = 100). */
	public static function weights() {
		return array(
			'entity_coverage'    => 12,
			'service_clarity'    => 12,
			'location_relevance' => 10,
			'faq_coverage'       => 12,
			'answer_completeness'=> 12,
			'schema_coverage'    => 12,
			'internal_linking'   => 8,
			'trust_signals'      => 8,
			'review_usage'       => 6,
			'ai_answer_readiness'=> 8,
		);
	}

	public static function labels() {
		return array(
			'entity_coverage'    => __( 'Entity coverage', 'geo-site-brain' ),
			'service_clarity'    => __( 'Service clarity', 'geo-site-brain' ),
			'location_relevance' => __( 'Location relevance', 'geo-site-brain' ),
			'faq_coverage'       => __( 'FAQ / question coverage', 'geo-site-brain' ),
			'answer_completeness'=> __( 'Answer completeness', 'geo-site-brain' ),
			'schema_coverage'    => __( 'Schema coverage', 'geo-site-brain' ),
			'internal_linking'   => __( 'Internal linking', 'geo-site-brain' ),
			'trust_signals'      => __( 'Trust signals', 'geo-site-brain' ),
			'review_usage'       => __( 'Review / testimonial usage', 'geo-site-brain' ),
			'ai_answer_readiness'=> __( 'AI answer readiness', 'geo-site-brain' ),
		);
	}

	/**
	 * Score a post and persist it. Returns array(score, subscores, details).
	 */
	public static function score_post( $post, $data = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		if ( null === $data ) {
			$data = GSB_Scanner::analyze( $post );
		}

		$sub     = array();
		$details = array();

		list( $sub['entity_coverage'], $details['entity_coverage'] )       = self::entity_coverage( $data );
		list( $sub['service_clarity'], $details['service_clarity'] )       = self::service_clarity( $data );
		list( $sub['location_relevance'], $details['location_relevance'] ) = self::location_relevance( $data );
		list( $sub['faq_coverage'], $details['faq_coverage'] )             = self::faq_coverage( $data );
		list( $sub['answer_completeness'], $details['answer_completeness'] ) = self::answer_completeness( $data );
		list( $sub['schema_coverage'], $details['schema_coverage'] )       = self::schema_coverage( $data );
		list( $sub['internal_linking'], $details['internal_linking'] )     = self::internal_linking( $data );
		list( $sub['trust_signals'], $details['trust_signals'] )           = self::trust_signals( $data );
		list( $sub['review_usage'], $details['review_usage'] )             = self::review_usage( $data );
		list( $sub['ai_answer_readiness'], $details['ai_answer_readiness'] ) = self::ai_answer_readiness( $data );

		$weights = self::weights();
		$sum     = 0;
		$wsum    = 0;
		foreach ( $weights as $k => $w ) {
			$sum  += $w * $sub[ $k ];
			$wsum += $w;
		}
		$score = $wsum ? (int) round( $sum / $wsum ) : 0;
		$score = max( 1, min( 100, $score ) );

		GSB_Database::save_score( $data['post_id'], $data['url'], $score, $sub, $details );

		return array( 'score' => $score, 'subscores' => $sub, 'details' => $details );
	}

	/* --------------------------------------------------------------- helpers */

	private static function clamp( $n ) {
		return max( 0, min( 100, (int) round( $n ) ) );
	}

	/* ------------------------------------------------------------ dimensions */

	private static function entity_coverage( $data ) {
		$score = 25;
		$notes = array();
		$plain = strtolower( $data['plain'] );

		$brand = trim( (string) GSB_Settings::get( 'business_name' ) );
		if ( $brand && stripos( $data['plain'], $brand ) !== false ) {
			$score += 15;
			$notes[] = 'brand mentioned';
		} elseif ( $brand ) {
			$notes[] = 'brand name missing';
		}

		$services = GSB_Settings::services();
		if ( $services ) {
			$hit = 0;
			foreach ( $services as $s ) {
				if ( $s && stripos( $plain, strtolower( $s ) ) !== false ) {
					$hit++;
				}
			}
			$score  += min( 30, (int) round( 30 * $hit / max( 1, count( $services ) ) ) );
			$notes[] = sprintf( '%d/%d core services referenced', $hit, count( $services ) );
		}

		if ( $data['word_count'] > 300 ) {
			$score += 15;
		}
		if ( ! empty( $data['terms']['categories'] ) || ! empty( $data['terms']['tags'] ) ) {
			$score += 10;
			$notes[] = 'categorised/tagged';
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function service_clarity( $data ) {
		$score = 20;
		$notes = array();
		$has_service_section = false;
		foreach ( $data['sections'] as $s ) {
			if ( preg_match( '/\b(service|solution|what we (do|offer)|capabilit)/i', $s['heading'] ) ) {
				$has_service_section = true;
				break;
			}
		}
		if ( $has_service_section ) {
			$score += 35;
			$notes[] = 'dedicated service section';
		} else {
			$notes[] = 'no clear service section';
		}
		if ( $data['meta_desc'] ) {
			$score += 15;
		} else {
			$notes[] = 'meta description missing';
		}
		if ( $data['word_count'] > 200 ) {
			$score += 15;
		}
		if ( ! empty( $data['ctas'] ) ) {
			$score += 15;
			$notes[] = 'has CTA';
		} else {
			$notes[] = 'no call to action';
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function location_relevance( $data ) {
		$score     = 20;
		$notes     = array();
		$locations = GSB_Settings::locations();
		$mentions  = count( $data['locations'] );
		if ( $locations ) {
			$score  += min( 50, (int) round( 50 * $mentions / max( 1, min( 5, count( $locations ) ) ) ) );
			$notes[] = sprintf( '%d configured location(s) mentioned', $mentions );
		} else {
			// No configured locations: reward any city/state-looking mention.
			if ( preg_match( '/\b(washington|maryland|virginia|dc|md|va|county|city)\b/i', $data['plain'] ) ) {
				$score += 30;
			}
			$notes[] = 'no business locations configured';
		}
		$has_area = false;
		foreach ( $data['sections'] as $s ) {
			if ( preg_match( '/\b(service area|areas? we serve|locations?|where)/i', $s['heading'] ) ) {
				$has_area = true;
				break;
			}
		}
		if ( $has_area ) {
			$score += 30;
			$notes[] = 'service-area section present';
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function faq_coverage( $data ) {
		$n     = count( $data['faqs'] );
		$notes = array( sprintf( '%d FAQ(s) detected', $n ) );
		$score = 0;
		if ( $n >= 5 ) {
			$score = 100;
		} elseif ( $n >= 3 ) {
			$score = 80;
		} elseif ( $n >= 1 ) {
			$score = 55;
		} else {
			$score = 15;
			$notes[] = 'no question/answer content';
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function answer_completeness( $data ) {
		$score = 10;
		$notes = array();
		$wc    = $data['word_count'];
		if ( $wc > 800 ) {
			$score += 45;
		} elseif ( $wc > 400 ) {
			$score += 35;
		} elseif ( $wc > 200 ) {
			$score += 20;
		} else {
			$notes[] = 'thin content';
		}
		$section_count = count( $data['sections'] );
		$score += min( 25, $section_count * 5 );
		if ( $section_count >= 3 ) {
			$notes[] = 'well-structured headings';
		}
		// A concise lead answer (short first section) helps extractability.
		if ( ! empty( $data['sections'][0]['text'] ) && mb_strlen( $data['sections'][0]['text'] ) < 600 ) {
			$score += 20;
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function schema_coverage( $data ) {
		$types = array_map( 'strtolower', $data['schema_types'] );
		$notes = array();
		$score = 0;
		if ( empty( $types ) ) {
			return array( 10, array( 'no structured data found' ) );
		}
		$notes[] = 'schema: ' . implode( ', ', $data['schema_types'] );
		$score   = 40; // any schema
		$valuable = array( 'faqpage', 'localbusiness', 'service', 'product', 'review', 'aggregaterating', 'breadcrumblist', 'organization' );
		foreach ( $valuable as $v ) {
			foreach ( $types as $t ) {
				if ( false !== strpos( $t, $v ) ) {
					$score += 12;
					break;
				}
			}
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function internal_linking( $data ) {
		$n     = count( $data['internal_links'] );
		$notes = array( sprintf( '%d internal link(s)', $n ) );
		$score = 10;
		if ( $n >= 5 ) {
			$score = 100;
		} elseif ( $n >= 3 ) {
			$score = 75;
		} elseif ( $n >= 1 ) {
			$score = 45;
		} else {
			$notes[] = 'no internal links';
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function trust_signals( $data ) {
		$plain = $data['plain'];
		$score = 15;
		$notes = array();
		$signals = array(
			'insured'      => '/\b(insured|insurance|bonded)\b/i',
			'licensed'     => '/\b(licensed|certified|certification|accredited)\b/i',
			'guarantee'    => '/\b(guarantee|satisfaction|warranty)\b/i',
			'experience'   => '/\b(\d+\+?\s*years|since\s*\d{4}|established)\b/i',
			'awards'       => '/\b(award|rated|top[- ]rated|recogni[sz]ed)\b/i',
			'contact'      => '/(\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4})|\b(contact us|call us)\b/i',
		);
		foreach ( $signals as $label => $re ) {
			if ( preg_match( $re, $plain ) ) {
				$score  += 15;
				$notes[] = $label;
			}
		}
		if ( empty( $notes ) ) {
			$notes[] = 'no trust signals detected';
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function review_usage( $data ) {
		$n     = count( $data['testimonials'] );
		$types = array_map( 'strtolower', $data['schema_types'] );
		$has_review_schema = false;
		foreach ( $types as $t ) {
			if ( false !== strpos( $t, 'review' ) || false !== strpos( $t, 'aggregaterating' ) ) {
				$has_review_schema = true;
				break;
			}
		}
		$score = 10;
		$notes = array( sprintf( '%d testimonial(s)', $n ) );
		if ( $n >= 3 ) {
			$score = 80;
		} elseif ( $n >= 1 ) {
			$score = 55;
		} else {
			$notes[] = 'no testimonials/reviews';
		}
		if ( $has_review_schema ) {
			$score  += 20;
			$notes[] = 'review schema present';
		}
		return array( self::clamp( $score ), $notes );
	}

	private static function ai_answer_readiness( $data ) {
		$score = 0;
		$notes = array();
		if ( $data['meta_desc'] ) {
			$score += 20;
		} else {
			$notes[] = 'no meta description';
		}
		if ( $data['h1'] ) {
			$score += 15;
		} else {
			$notes[] = 'no H1';
		}
		if ( count( $data['sections'] ) >= 3 ) {
			$score += 20;
		}
		if ( ! empty( $data['faqs'] ) ) {
			$score += 20;
		}
		// A concise opening answer (<= 320 chars) is highly extractable for AI
		// Overviews / answer engines.
		$lead = $data['excerpt'] ?: ( $data['sections'][0]['text'] ?? '' );
		if ( $lead && mb_strlen( trim( $lead ) ) <= 320 && mb_strlen( trim( $lead ) ) > 40 ) {
			$score  += 25;
			$notes[] = 'concise lead answer';
		} else {
			$notes[] = 'no concise lead answer';
		}
		return array( self::clamp( $score ), $notes );
	}
}
