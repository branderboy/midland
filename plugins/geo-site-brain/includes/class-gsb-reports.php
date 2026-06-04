<?php
/**
 * Client-facing reports. Aggregates the knowledge graph, visibility scores and
 * fix queue into plain-language summaries (no technical vocabulary) that an
 * agency or owner can hand to a client.
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
	 * The data behind every report (the views pick what to show).
	 */
	public static function data() {
		$counts  = GSB_Database::entity_counts();
		$vis     = GSB_Database::get_visibility();
		$overall = GSB_Visibility::overall_score();

		$found_services  = self::found( 'service' );
		$found_locations = self::found( 'location' );

		return array(
			'business'        => self::business_name(),
			'generated'       => current_time( 'F j, Y' ),
			'overall'         => $overall,
			'engines'         => $vis,
			'counts'          => $counts,
			'services'        => $found_services,
			'locations'       => $found_locations,
			'missing_services'=> self::recommended_names( 'service' ),
			'missing_locations'=> self::recommended_names( 'location' ),
			'missing_links'   => GSB_Knowledge_Graph::missing_link_count(),
			'top_fixes'       => array_slice( GSB_Database::get_recommendations( 'open' ), 0, 8 ),
			'avg_page_score'  => GSB_Database::site_score(),
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
