<?php
/**
 * Retrieval-first chat agent. Every answer starts from the embedded knowledge
 * base: the question is embedded, the nearest chunks are retrieved (Neon or
 * local), and only that context is handed to the model. The model is forced to
 * separate what it found, what it inferred, and what it recommends — and to say
 * so plainly when the site doesn't cover something, instead of inventing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Agent {

	/**
	 * Answer a question. Returns array(answer, sources[], backend, used_ai).
	 */
	public function ask( $question ) {
		$question = trim( wp_strip_all_tags( (string) $question ) );
		if ( '' === $question ) {
			return array(
				'answer'  => __( 'Please enter a question.', 'geo-site-brain' ),
				'sources' => array(),
				'backend' => 'none',
				'used_ai' => false,
			);
		}

		$k       = (int) GSB_Settings::get( 'retrieval_k', 8 );
		$store   = new GSB_Vector_Store();
		$openai  = new GSB_OpenAI();
		$matches = array();

		// Retrieval requires an embedding of the question. Without an OpenAI key
		// we can't embed, so we fall back to a keyword search over chunk text so
		// the agent still grounds answers in real content.
		if ( $openai->has_key() ) {
			$qvec = $openai->embed_one( $question );
			if ( ! is_wp_error( $qvec ) ) {
				$matches = $store->search( $qvec, $k );
			}
		}
		if ( empty( $matches ) ) {
			$matches = $this->keyword_search( $question, $k );
			$backend = 'keyword';
		} else {
			$backend = $store->last_backend();
		}

		$sources = $this->sources_from_matches( $matches );
		$graph   = $this->graph_context();

		if ( empty( $matches ) && '' === $graph ) {
			return array(
				'answer'  => __( "There's no business knowledge yet. Scan your website first, then ask again.", 'geo-site-brain' ),
				'sources' => array(),
				'backend' => $backend,
				'used_ai' => false,
			);
		}

		// No model available → return the grounded knowledge directly.
		if ( ! $openai->has_key() ) {
			return array(
				'answer'  => ( $graph ? $graph . "\n\n" : '' ) . ( $matches ? $this->retrieval_only_answer( $matches ) : '' ),
				'sources' => $sources,
				'backend' => $backend,
				'used_ai' => false,
			);
		}

		// The graph (business-level facts) leads; retrieved page passages support it.
		$context = trim( $graph . "\n\n" . $this->build_context( $matches ) );
		$answer  = $openai->chat( array(
			array( 'role' => 'system', 'content' => $this->system_prompt() ),
			array( 'role' => 'user', 'content' => "QUESTION:\n{$question}\n\nSITE CONTEXT (retrieved chunks):\n{$context}" ),
		) );

		if ( is_wp_error( $answer ) ) {
			GSB_Logger::error( 'agent', 'Chat failed: ' . $answer->get_error_message() );
			return array(
				'answer'  => $this->retrieval_only_answer( $matches ),
				'sources' => $sources,
				'backend' => $backend,
				'used_ai' => false,
			);
		}

		return array(
			'answer'  => $answer,
			'sources' => $sources,
			'backend' => $backend,
			'used_ai' => true,
		);
	}

	/* --------------------------------------------------------------- prompt */

	private function system_prompt() {
		$brand = trim( (string) GSB_Settings::get( 'business_name' ) );
		$who   = $brand ? "the website for {$brand}" : 'this website';

		return "You are GEO Site Brain, an analyst for {$who}. You answer questions about the site's content, GEO/AEO/SEO, schema, internal linking and content strategy.

STRICT RULES — follow exactly:
1. Use ONLY the SITE CONTEXT provided below. Do not use outside knowledge about the business. If the context does not contain the answer, say so.
2. Never invent services, locations, facts, reviews, or pages that are not in the context.
3. Structure every answer under these headings, omitting any that are empty:
   • Found on site — facts directly present in the context (quote/paraphrase + which page).
   • Inferred from site — reasonable conclusions drawn from the context, clearly marked as inference.
   • Recommended addition — gaps, improvements, or content that is NOT on the site yet but should be. Mark these clearly as recommendations, never as existing facts.
4. Be concrete and concise. When you reference a page, name it.
5. If asked for things like 'what services does the site offer', list only services evidenced in the context.";
	}

	/**
	 * A compact, business-language summary of the knowledge graph that leads
	 * every answer, so the agent reasons about the business — not raw pages.
	 */
	private function graph_context() {
		if ( ! class_exists( 'GSB_Database' ) ) {
			return '';
		}
		$lines = array();

		$biz = GSB_Database::get_entities( 'business' );
		if ( $biz ) {
			$b = $biz[0];
			$lines[] = 'BUSINESS: ' . $b->name . ( $b->description ? ' — ' . wp_strip_all_tags( $b->description ) : '' );
		}

		$svc_found = $this->entity_names( 'service', array( 'found', 'inferred' ) );
		if ( $svc_found ) {
			$lines[] = 'SERVICES (found on site): ' . implode( ', ', $svc_found );
		}
		$svc_missing = $this->entity_names( 'service', array( 'recommended' ) );
		if ( $svc_missing ) {
			$lines[] = 'SERVICES (not on site yet / recommended): ' . implode( ', ', $svc_missing );
		}
		$loc_found = $this->entity_names( 'location', array( 'found', 'inferred' ) );
		if ( $loc_found ) {
			$lines[] = 'SERVICE AREAS (found): ' . implode( ', ', $loc_found );
		}
		$loc_missing = $this->entity_names( 'location', array( 'recommended' ) );
		if ( $loc_missing ) {
			$lines[] = 'SERVICE AREAS (recommended): ' . implode( ', ', $loc_missing );
		}

		$counts = GSB_Database::entity_counts();
		$lines[] = sprintf( 'KNOWLEDGE: %d FAQs, %d testimonials, %d authors, %d case studies on file.',
			(int) ( $counts['faq'] ?? 0 ), (int) ( $counts['testimonial'] ?? 0 ),
			(int) ( $counts['author'] ?? 0 ), (int) ( $counts['case_study'] ?? 0 ) );

		return empty( $lines ) ? '' : "BUSINESS KNOWLEDGE GRAPH:\n" . implode( "\n", $lines );
	}

	private function entity_names( $type, $statuses ) {
		$out = array();
		foreach ( GSB_Database::get_entities( $type ) as $e ) {
			if ( in_array( $e->status, $statuses, true ) ) {
				$out[] = $e->name;
			}
		}
		return array_slice( $out, 0, 40 );
	}

	private function build_context( $matches ) {
		$out = '';
		foreach ( $matches as $i => $m ) {
			$title = get_the_title( $m['post_id'] );
			$label = $title ? $title : ( $m['url'] ?: ( 'chunk #' . $m['id'] ) );
			$out  .= sprintf(
				"[%d] (%s | %s | relevance %.2f)\n%s\n\n",
				$i + 1,
				$label,
				$m['section_type'],
				$m['score'],
				mb_substr( $m['chunk_text'], 0, 1200 )
			);
		}
		return trim( $out );
	}

	private function retrieval_only_answer( $matches ) {
		$out = __( "OpenAI isn't configured, so here are the most relevant passages found on the site (Found on site):", 'geo-site-brain' ) . "\n\n";
		foreach ( $matches as $i => $m ) {
			$title = get_the_title( $m['post_id'] ) ?: ( $m['url'] ?: ( 'chunk #' . $m['id'] ) );
			$out  .= sprintf( "%d. %s — %s\n   %s\n", $i + 1, $title, $m['section_type'], mb_substr( $m['chunk_text'], 0, 240 ) );
		}
		return $out;
	}

	private function sources_from_matches( $matches ) {
		$sources = array();
		$seen    = array();
		foreach ( $matches as $m ) {
			$pid = (int) $m['post_id'];
			if ( $pid && isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;
			$sources[]    = array(
				'title' => get_the_title( $pid ) ?: ( $m['url'] ?: ( 'chunk #' . $m['id'] ) ),
				'url'   => $m['url'],
				'score' => round( (float) $m['score'], 3 ),
			);
		}
		return $sources;
	}

	/* ------------------------------------------------------ keyword fallback */

	/**
	 * Naive keyword search over chunk text, used only when embeddings are
	 * unavailable so answers are still grounded in real content.
	 */
	private function keyword_search( $question, $k ) {
		global $wpdb;
		$table = GSB_Database::table( 'chunks' );
		$words = array_filter( preg_split( '/\s+/', strtolower( $question ) ), static function ( $w ) {
			return strlen( $w ) > 3;
		} );
		$words = array_slice( array_values( $words ), 0, 6 );
		if ( empty( $words ) ) {
			return array();
		}
		$like = array();
		$args = array();
		foreach ( $words as $w ) {
			$like[] = 'chunk_text LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $w ) . '%';
		}
		$args[] = (int) $k;
		$sql = "SELECT id, post_id, url, content_type, section_type, chunk_text
			FROM {$table} WHERE " . implode( ' OR ', $like ) . ' LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'           => (int) $r->id,
				'post_id'      => (int) $r->post_id,
				'url'          => (string) $r->url,
				'content_type' => (string) $r->content_type,
				'section_type' => (string) $r->section_type,
				'chunk_text'   => (string) $r->chunk_text,
				'score'        => 0.0,
			);
		}
		return $out;
	}
}
