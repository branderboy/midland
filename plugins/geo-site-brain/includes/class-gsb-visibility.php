<?php
/**
 * AI Visibility engine. Simulates how the major AI systems (ChatGPT, Claude,
 * Gemini, Perplexity) understand the business, from the knowledge graph + page
 * signals. Produces four 0–100 scores per engine (visibility, confidence,
 * knowledge completeness, recommendation likelihood) plus a "can AI identify…?"
 * checklist. A natural-language narrative ("how this engine would describe you")
 * is generated on demand when an AI key is present.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Visibility {

	const ENGINES = array( 'chatgpt', 'claude', 'gemini', 'perplexity' );

	public static function engine_label( $engine ) {
		$map = array(
			'chatgpt'    => 'ChatGPT',
			'claude'     => 'Claude',
			'gemini'     => 'Gemini',
			'perplexity' => 'Perplexity',
		);
		return $map[ $engine ] ?? ucfirst( $engine );
	}

	/**
	 * What each engine weights most (weights sum to 1.0 per engine).
	 */
	private static function profiles() {
		return array(
			'chatgpt'    => array( 'business_clarity' => .18, 'services' => .18, 'schema' => .16, 'answers' => .12, 'locations' => .08, 'trust' => .1, 'faqs' => .08, 'authority' => .06, 'testimonials' => .02, 'freshness' => .02 ),
			'claude'     => array( 'answers' => .2, 'faqs' => .18, 'services' => .16, 'business_clarity' => .14, 'schema' => .1, 'trust' => .08, 'locations' => .06, 'authority' => .04, 'testimonials' => .02, 'freshness' => .02 ),
			'gemini'     => array( 'locations' => .2, 'schema' => .18, 'trust' => .16, 'business_clarity' => .12, 'services' => .12, 'faqs' => .08, 'answers' => .06, 'testimonials' => .04, 'authority' => .02, 'freshness' => .02 ),
			'perplexity' => array( 'trust' => .18, 'faqs' => .16, 'answers' => .14, 'authority' => .14, 'freshness' => .1, 'services' => .1, 'business_clarity' => .08, 'schema' => .04, 'locations' => .04, 'testimonials' => .02 ),
		);
	}

	/**
	 * Recompute and persist scores for every engine. Deterministic — no API
	 * calls (the narrative is generated separately, on demand).
	 */
	public static function recompute( $pages = null ) {
		$signals = self::signals( $pages );
		$check   = self::checklist( $signals );

		foreach ( self::ENGINES as $engine ) {
			$scores = self::scores_for( $engine, $signals );
			$summary = self::template_summary( $engine, $check, $scores );
			GSB_Database::save_visibility( $engine, $scores, $summary, array(
				'checklist' => $check,
				'signals'   => $signals,
			) );
		}

		// Track the headline number over time for the dashboard sparkline.
		self::record_history();
	}

	public static function overall_score() {
		$rows = GSB_Database::get_visibility();
		if ( empty( $rows ) ) {
			return null;
		}
		$sum = 0;
		foreach ( $rows as $r ) {
			$sum += (int) $r->visibility_score;
		}
		return (int) round( $sum / count( $rows ) );
	}

	/* ---------------------------------------------------------------- signals */

	/**
	 * Site-level signals, each normalized 0–100.
	 */
	private static function signals( $pages ) {
		$counts = GSB_Database::entity_counts();

		// Entity coverage ratios.
		$svc_found = self::found_count( 'service' );
		$loc_found = self::found_count( 'location' );
		$svc_expected = max( count( GSB_Settings::services() ), $svc_found, 1 );
		$loc_expected = max( count( GSB_Settings::locations() ), $loc_found, 1 );

		// Page sub-score averages.
		$avg = self::subscore_averages();

		// Business clarity from the business entity.
		$business = self::business_entity();
		$business_clarity = $business ? (int) $business->confidence : 20;

		// Freshness from the most recently modified indexed post.
		$freshness = self::freshness();

		// Differentiators / authority.
		$differentiators = self::differentiators_score( $pages );
		$authority = (int) round( ( $avg['internal_linking'] * 0.5 ) + min( 50, ( $counts['author'] ?? 0 ) * 10 + min( 30, ( $counts['case_study'] ?? 0 ) * 10 ) ) );

		return array(
			'business_clarity' => $business_clarity,
			'services'         => (int) round( 100 * min( 1, $svc_found / $svc_expected ) ),
			'locations'        => (int) round( 100 * min( 1, $loc_found / $loc_expected ) ),
			'faqs'             => self::band_count( $counts['faq'] ?? 0, 8, 5, 3 ),
			'testimonials'     => self::band_count( $counts['testimonial'] ?? 0, 5, 3, 1 ),
			'schema'           => $avg['schema_coverage'],
			'trust'            => $avg['trust_signals'],
			'answers'          => $avg['answer_completeness'],
			'authority'        => min( 100, $authority ),
			'freshness'        => $freshness,
			'differentiators'  => $differentiators,
		);
	}

	private static function scores_for( $engine, $signals ) {
		$profile = self::profiles()[ $engine ];
		$visibility = 0;
		foreach ( $profile as $sig => $w ) {
			$visibility += $w * ( $signals[ $sig ] ?? 0 );
		}

		$nap = self::nap_consistency();
		$confidence = (int) round( ( $signals['business_clarity'] + $signals['schema'] + $nap ) / 3 );

		$knowledge = (int) round( (
			$signals['services'] + $signals['locations']
			+ ( $signals['faqs'] > 0 ? 100 : 0 )
			+ ( $signals['testimonials'] > 0 ? 100 : 0 )
			+ ( $signals['business_clarity'] >= 60 ? 100 : 50 )
		) / 5 );

		$recommendation = (int) round( ( $signals['trust'] + $signals['testimonials'] + $signals['differentiators'] + $signals['answers'] ) / 4 );

		return array(
			'visibility'     => (int) round( $visibility ),
			'confidence'     => $confidence,
			'knowledge'      => $knowledge,
			'recommendation' => $recommendation,
		);
	}

	/**
	 * Booleans: can AI identify…?
	 */
	private static function checklist( $signals ) {
		return array(
			'what_you_do'      => $signals['business_clarity'] >= 55,
			'service_areas'    => self::found_count( 'location' ) >= 1,
			'expertise'        => self::found_count( 'service' ) >= 2,
			'trust_signals'    => $signals['trust'] >= 50,
			'differentiators'  => $signals['differentiators'] >= 40,
			'authority'        => $signals['authority'] >= 50,
			'answer_questions' => ( GSB_Database::entity_counts()['faq'] ?? 0 ) >= 1,
		);
	}

	public static function checklist_labels() {
		return array(
			'what_you_do'      => __( 'What the business does', 'geo-site-brain' ),
			'service_areas'    => __( 'Service areas', 'geo-site-brain' ),
			'expertise'        => __( 'Areas of expertise', 'geo-site-brain' ),
			'trust_signals'    => __( 'Trust signals', 'geo-site-brain' ),
			'differentiators'  => __( 'Unique differentiators', 'geo-site-brain' ),
			'authority'        => __( 'Authority', 'geo-site-brain' ),
			'answer_questions' => __( 'Answer customer questions', 'geo-site-brain' ),
		);
	}

	private static function template_summary( $engine, $check, $scores ) {
		$label = self::engine_label( $engine );
		$can = array(); $cannot = array();
		$labels = self::checklist_labels();
		foreach ( $check as $k => $ok ) {
			if ( $ok ) { $can[] = strtolower( $labels[ $k ] ); }
			else { $cannot[] = strtolower( $labels[ $k ] ); }
		}
		$s = sprintf( /* translators: 1: engine 2: score */ __( '%1$s can understand your business at about %2$d%%.', 'geo-site-brain' ), $label, (int) $scores['visibility'] );
		if ( $can ) {
			$s .= ' ' . sprintf( __( 'It can identify: %s.', 'geo-site-brain' ), implode( ', ', array_slice( $can, 0, 4 ) ) );
		}
		if ( $cannot ) {
			$s .= ' ' . sprintf( __( 'It cannot yet confirm: %s.', 'geo-site-brain' ), implode( ', ', array_slice( $cannot, 0, 4 ) ) );
		}
		return $s;
	}

	/* --------------------------------------------------- LIVE per-engine probe */

	/**
	 * Probe a real AI model: give it the business knowledge and ask, in
	 * structured JSON, what it can identify and what it can't. Scores are then
	 * derived from the model's ACTUAL answer (not a simulation) — measuring how
	 * understandable the business is to that specific engine. Marks the result
	 * "live" so the UI can distinguish it.
	 *
	 * @return array|WP_Error details on success.
	 */
	public static function probe( $engine ) {
		if ( ! in_array( $engine, self::ENGINES, true ) ) {
			return new WP_Error( 'gsb_engine', __( 'Unknown engine.', 'geo-site-brain' ) );
		}
		if ( ! GSB_AI_Providers::has_key( $engine ) ) {
			return new WP_Error( 'gsb_no_key', sprintf( __( 'Add a %s API key in Settings to run a live probe.', 'geo-site-brain' ), self::engine_label( $engine ) ) );
		}

		$profile = self::business_profile_text();
		$label   = self::engine_label( $engine );
		$system  = "You are {$label}. A user is asking you about a local business. Using ONLY the business knowledge provided, respond with a single JSON object and nothing else, with these keys:\n"
			. '{"description": "2-3 sentence description as you would tell a user", "services": ["..."], "locations": ["..."], "can_answer_questions": true/false, "has_trust_signals": true/false, "has_differentiators": true/false, "cannot_determine": ["..."]}'
			. "\nDo not invent anything not supported by the knowledge. If something is unclear, put it in cannot_determine.";

		$raw = GSB_AI_Providers::chat( $engine, $system, $profile, 700 );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( null === $raw ) {
			return new WP_Error( 'gsb_no_key', __( 'No key for this engine.', 'geo-site-brain' ) );
		}

		$parsed = self::parse_json( (string) $raw );
		if ( ! is_array( $parsed ) ) {
			// Model didn't return clean JSON — keep the prose as the narrative and
			// fall back to deterministic scores so the card still updates.
			$signals = self::signals( null );
			$scores  = self::scores_for( $engine, $signals );
			GSB_Database::save_visibility( $engine, $scores, trim( (string) $raw ), array(
				'live'      => true,
				'checklist' => self::checklist( $signals ),
				'parsed'    => false,
			) );
			self::record_history();
			return array( 'live' => true, 'summary' => trim( (string) $raw ) );
		}

		// Ground truth from our own knowledge graph.
		$graph_services  = self::names( 'service' );
		$graph_locations = self::names( 'location' );

		$id_services  = self::clean_list( $parsed['services'] ?? array() );
		$id_locations = self::clean_list( $parsed['locations'] ?? array() );

		$recall_s = self::recall( $graph_services, $id_services );
		$recall_l = self::recall( $graph_locations, $id_locations );
		$coverage = (int) round( 100 * ( ( $recall_s + $recall_l ) / 2 ) );

		$desc   = trim( (string) ( $parsed['description'] ?? '' ) );
		$cannot = self::clean_list( $parsed['cannot_determine'] ?? array() );
		$can_q  = ! empty( $parsed['can_answer_questions'] );
		$trust  = ! empty( $parsed['has_trust_signals'] );
		$diff   = ! empty( $parsed['has_differentiators'] );

		$scores = array(
			'visibility'     => (int) round( 0.4 * $coverage + 0.15 * ( $desc ? 100 : 0 ) + 0.15 * ( $can_q ? 100 : 0 ) + 0.15 * ( $trust ? 100 : 0 ) + 0.15 * ( $diff ? 100 : 0 ) ),
			'confidence'     => max( 0, 100 - min( 100, count( $cannot ) * 20 ) ),
			'knowledge'      => $coverage,
			'recommendation' => (int) round( 0.4 * ( $trust ? 100 : 0 ) + 0.3 * ( $diff ? 100 : 0 ) + 0.3 * ( $can_q ? 100 : 0 ) ),
		);

		$missing_services  = array_values( array_diff( array_map( 'strtolower', $graph_services ), array_map( 'strtolower', $id_services ) ) );
		$missing_locations = array_values( array_diff( array_map( 'strtolower', $graph_locations ), array_map( 'strtolower', $id_locations ) ) );

		$summary = $desc;
		if ( $cannot ) {
			$summary .= "\n\n" . __( "Can't determine: ", 'geo-site-brain' ) . implode( ', ', $cannot );
		}

		GSB_Database::save_visibility( $engine, $scores, $summary, array(
			'live'              => true,
			'parsed'            => true,
			'identified_services'  => $id_services,
			'identified_locations' => $id_locations,
			'missing_services'  => $missing_services,
			'missing_locations' => $missing_locations,
			'cannot_determine'  => $cannot,
			'checklist'         => array(
				'what_you_do'      => '' !== $desc,
				'service_areas'    => ! empty( $id_locations ),
				'expertise'        => count( $id_services ) >= 2,
				'trust_signals'    => $trust,
				'differentiators'  => $diff,
				'authority'        => self::found_count( 'author' ) >= 1,
				'answer_questions' => $can_q,
			),
		) );
		self::record_history();

		GSB_Logger::info( 'visibility', sprintf( 'Live probe complete for %s (visibility %d).', $label, $scores['visibility'] ) );
		return array( 'live' => true, 'summary' => $summary, 'scores' => $scores );
	}

	/** Probe every engine that has a key. Returns count probed. */
	public static function probe_all() {
		$n = 0;
		foreach ( GSB_AI_Providers::live_engines() as $engine ) {
			$res = self::probe( $engine );
			if ( ! is_wp_error( $res ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function parse_json( $raw ) {
		$raw = preg_replace( '/```[a-z]*\s*/i', '', $raw );
		$raw = str_replace( '```', '', $raw );
		if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
			$raw = $m[0];
		}
		$data = json_decode( trim( $raw ), true );
		return is_array( $data ) ? $data : null;
	}

	private static function clean_list( $list ) {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $v ) {
			$v = trim( wp_strip_all_tags( (string) $v ) );
			if ( '' !== $v ) {
				$out[] = $v;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Fraction of ground-truth items the model reproduced (substring match). */
	private static function recall( $truth, $found ) {
		if ( empty( $truth ) ) {
			return empty( $found ) ? 1.0 : 1.0;
		}
		$found_l = array_map( 'strtolower', $found );
		$hit = 0;
		foreach ( $truth as $t ) {
			$t_l = strtolower( $t );
			foreach ( $found_l as $f ) {
				if ( false !== strpos( $f, $t_l ) || false !== strpos( $t_l, $f ) ) {
					$hit++;
					break;
				}
			}
		}
		return $hit / count( $truth );
	}

	/* ----------------------------------------------------- on-demand narrative */

	/**
	 * Generate a natural-language "how would {engine} describe this business"
	 * narrative from the knowledge graph (Found only), plus what it can't
	 * determine. Requires an AI key; updates the stored summary. Returns the text
	 * or WP_Error.
	 */
	public static function narrative( $engine ) {
		$openai = new GSB_OpenAI();
		if ( ! $openai->has_key() ) {
			return new WP_Error( 'gsb_no_key', __( 'Connect AI in Setup to generate the narrative.', 'geo-site-brain' ) );
		}
		$profile = self::business_profile_text();
		$label   = self::engine_label( $engine );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => "You are simulating how {$label}, an AI assistant, would describe a local business to a user — using ONLY the structured business knowledge provided. Do not invent facts. Reply in two short labelled parts:\n\"Description:\" a 2–3 sentence description as {$label} would give it.\n\"Can't determine:\" a short comma-separated list of things the knowledge does not make clear.",
			),
			array( 'role' => 'user', 'content' => $profile ),
		);
		$text = $openai->chat( $messages, 0.3, 400 );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		// Persist into the engine's summary.
		$rows = GSB_Database::get_visibility();
		foreach ( $rows as $r ) {
			if ( $r->engine === $engine ) {
				$details = json_decode( (string) $r->details, true ) ?: array();
				GSB_Database::save_visibility( $engine, array(
					'visibility'     => $r->visibility_score,
					'confidence'     => $r->confidence_score,
					'knowledge'      => $r->knowledge_score,
					'recommendation' => $r->recommendation_score,
				), $text, $details );
				break;
			}
		}
		return $text;
	}

	/**
	 * Compact, Found-only profile of the business for the narrative prompt.
	 */
	private static function business_profile_text() {
		$out = '';
		$biz = self::business_entity();
		if ( $biz ) {
			$out .= 'BUSINESS: ' . $biz->name . "\n";
			if ( $biz->description ) {
				$out .= 'ABOUT: ' . wp_strip_all_tags( $biz->description ) . "\n";
			}
			$attr = json_decode( (string) $biz->attributes, true ) ?: array();
			if ( ! empty( $attr['phone'] ) )   { $out .= 'PHONE: ' . $attr['phone'] . "\n"; }
			if ( ! empty( $attr['address'] ) ) { $out .= 'ADDRESS: ' . $attr['address'] . "\n"; }
		}
		$out .= 'SERVICES: ' . implode( ', ', self::names( 'service' ) ) . "\n";
		$out .= 'SERVICE AREAS: ' . implode( ', ', self::names( 'location' ) ) . "\n";
		$faqs = GSB_Database::get_entities( 'faq', 'found' );
		if ( $faqs ) {
			$out .= 'COMMON QUESTIONS: ' . implode( ' | ', array_slice( array_map( static function ( $f ) { return $f->name; }, $faqs ), 0, 6 ) ) . "\n";
		}
		$out .= 'TESTIMONIALS ON FILE: ' . ( self::found_count( 'testimonial' ) ) . "\n";
		return $out;
	}

	private static function names( $type ) {
		$out = array();
		foreach ( GSB_Database::get_entities( $type ) as $e ) {
			if ( in_array( $e->status, array( 'found', 'inferred' ), true ) ) {
				$out[] = $e->name;
			}
		}
		return $out;
	}

	/* ----------------------------------------------------------------- helpers */

	private static function business_entity() {
		$rows = GSB_Database::get_entities( 'business' );
		return $rows ? $rows[0] : null;
	}

	private static function found_count( $type ) {
		$n = 0;
		foreach ( GSB_Database::get_entities( $type ) as $e ) {
			if ( in_array( $e->status, array( 'found', 'inferred' ), true ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function nap_consistency() {
		$biz = self::business_entity();
		if ( ! $biz ) {
			return 40;
		}
		$attr = json_decode( (string) $biz->attributes, true ) ?: array();
		$score = 40;
		if ( ! empty( $attr['phone'] ) )   { $score += 30; }
		if ( ! empty( $attr['address'] ) ) { $score += 30; }
		return min( 100, $score );
	}

	private static function band_count( $n, $high, $mid, $low ) {
		$n = (int) $n;
		if ( $n >= $high ) { return 100; }
		if ( $n >= $mid )  { return 80; }
		if ( $n >= $low )  { return 55; }
		return $n > 0 ? 35 : 10;
	}

	private static function subscore_averages() {
		$scores = GSB_Database::get_scores( 'score', 'DESC', 1000 );
		$keys   = array( 'schema_coverage', 'trust_signals', 'answer_completeness', 'internal_linking', 'entity_coverage' );
		$sum    = array_fill_keys( $keys, 0 );
		$count  = 0;
		foreach ( $scores as $row ) {
			$sub = json_decode( (string) $row->subscores, true );
			if ( ! is_array( $sub ) ) {
				continue;
			}
			foreach ( $keys as $k ) {
				$sum[ $k ] += (int) ( $sub[ $k ] ?? 0 );
			}
			$count++;
		}
		$avg = array();
		foreach ( $keys as $k ) {
			$avg[ $k ] = $count ? (int) round( $sum[ $k ] / $count ) : 0;
		}
		return $avg;
	}

	private static function freshness() {
		$recent = get_posts( array(
			'post_type'      => GSB_Scanner::scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		if ( empty( $recent ) ) {
			return 20;
		}
		$ts   = get_post_modified_time( 'U', true, $recent[0] );
		$days = ( time() - $ts ) / DAY_IN_SECONDS;
		if ( $days <= 30 )  { return 100; }
		if ( $days <= 90 )  { return 75; }
		if ( $days <= 180 ) { return 50; }
		if ( $days <= 365 ) { return 30; }
		return 15;
	}

	private static function differentiators_score( $pages ) {
		if ( null === $pages ) {
			$pages = array();
			foreach ( GSB_Scanner::all_post_ids() as $id ) {
				$a = GSB_Scanner::analyze( $id );
				if ( $a ) { $pages[ $id ] = $a; }
			}
		}
		$blob = '';
		foreach ( $pages as $a ) {
			$blob .= ' ' . $a['plain'];
		}
		$hits = 0;
		$signals = array( 'guarantee', 'certified', 'licensed', 'insured', 'award', 'years', 'family owned', 'eco', 'same day', 'emergency', 'satisfaction' );
		foreach ( $signals as $s ) {
			if ( stripos( $blob, $s ) !== false ) {
				$hits++;
			}
		}
		return min( 100, $hits * 15 );
	}

	private static function record_history() {
		$overall = self::overall_score();
		if ( null === $overall ) {
			return;
		}
		$history = (array) GSB_Database::get_state( 'visibility_history', array() );
		$history[] = array( 'date' => current_time( 'Y-m-d' ), 'score' => $overall );
		// Keep the most recent ~52 points.
		if ( count( $history ) > 52 ) {
			$history = array_slice( $history, -52 );
		}
		GSB_Database::set_state( 'visibility_history', $history );
	}
}
