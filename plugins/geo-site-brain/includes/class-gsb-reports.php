<?php
/**
 * Client-facing reports. Aggregates scan stats, knowledge graph, visibility
 * scores and fix queue into plain-language summaries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Reports {

	public static function available() {
		return array(
			'executive'  => __( 'Executive GEO Report', 'geo-site-brain' ),
			'visibility' => __( 'AI Visibility Report', 'geo-site-brain' ),
			'knowledge'  => __( 'Knowledge Graph Report', 'geo-site-brain' ),
			'local'      => __( 'Local GEO Report', 'geo-site-brain' ),
		);
	}

	/**
	 * All data behind every report view.
	 */
	public static function data() {
		$counts       = GSB_Database::entity_counts();
		$vis          = GSB_Database::get_visibility();
		$overall      = GSB_Visibility::overall_score();
		$chunk_stats  = GSB_Database::chunk_stats();
		$progress     = GSB_Indexer::get_instance()->progress();
		$open_recs    = GSB_Database::get_recommendations( 'open' );
		$applied_recs = GSB_Database::get_recommendations( 'applied' ); // includes old 'done'

		// Competitor gap count: how many of owner's services a competitor covers
		// that the owner doesn't have a page for yet.
		$comp_gaps = 0;
		if ( ! empty( GSB_Settings::competitor_urls() ) ) {
			$comp_data  = GSB_Competitors::compare();
			foreach ( (array) ( $comp_data['service_rows'] ?? array() ) as $row ) {
				if ( 'gap' === ( $row['verdict'] ?? '' ) ) {
					$comp_gaps++;
				}
			}
		}

		// Average visibility score across all engines.
		$avg_vis = null;
		if ( ! empty( $vis ) ) {
			$sum = 0;
			foreach ( $vis as $e ) { $sum += (int) $e->visibility_score; }
			$avg_vis = (int) round( $sum / count( $vis ) );
		}

		$found_services  = self::found( 'service' );
		$found_locations = self::found( 'location' );

		return array(
			'business'          => self::business_name(),
			'generated'         => current_time( 'F j, Y' ),
			'overall'           => $overall,
			'engines'           => $vis,
			'counts'            => $counts,
			'services'          => $found_services,
			'locations'         => $found_locations,
			'missing_services'  => self::recommended_names( 'service' ),
			'missing_locations' => self::recommended_names( 'location' ),
			'missing_links'     => GSB_Knowledge_Graph::missing_link_count(),
			'top_fixes'         => array_slice( $open_recs, 0, 8 ),
			'avg_page_score'    => GSB_Database::site_score(),
			// Scan summary (issue #11)
			'last_scan'         => $progress['last_reindex'],
			'indexed_pages'     => (int) $chunk_stats['posts'],
			'total_chunks'      => (int) $chunk_stats['chunks'],
			'embedded_chunks'   => (int) $chunk_stats['embedded'],
			'unembedded_chunks' => max( 0, (int) $chunk_stats['chunks'] - (int) $chunk_stats['embedded'] ),
			'avg_vis_score'     => $avg_vis,
			'open_fixes'        => count( $open_recs ),
			'applied_fixes'     => count( $applied_recs ),
			'competitor_gaps'   => $comp_gaps,
			'has_competitors'   => ! empty( GSB_Settings::competitor_urls() ),
		);
	}

	private static function business_name() {
		$rows = GSB_Database::get_entities( 'business' );
		if ( $rows ) {
			return $rows[0]->name;
		}
		return trim( (string) GSB_Settings::get( 'business_name' ) ) ?: get_bloginfo( 'name' );
	}

	private static function found( $type ) {
		$out = array();
		foreach ( GSB_Database::get_entities( $type ) as $e ) {
			if ( in_array( $e->status, array( 'found', 'inferred' ), true ) ) {
				$out[] = $e->name;
			}
		}
		return $out;
	}

	private static function recommended_names( $type ) {
		$out = array();
		foreach ( GSB_Database::get_entities( $type, 'recommended' ) as $e ) {
			$out[] = $e->name;
		}
		return $out;
	}
}
