<?php
/**
 * Knowledge graph. Orchestrates the post-scan "understanding" pass — one
 * analysis sweep shared by entities, relationships, visibility and fixes — and
 * builds the relationships between entities (the headline being the
 * Service × Location matrix). Also exposes the graph analysis the UI needs:
 * the matrix, orphan entities, and missing/weak links.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Knowledge_Graph {

	/**
	 * Full rebuild: analyse every page once, then refresh entities,
	 * relationships, visibility scores and the fix queue from that single pass.
	 */
	public static function rebuild_all() {
		$pages = array();
		foreach ( GSB_Scanner::all_post_ids() as $id ) {
			$a = GSB_Scanner::analyze( $id );
			if ( $a ) {
				$pages[ $id ] = $a;
			}
		}

		GSB_Entities::rebuild_from_site( $pages );
		self::build_relationships( $pages );
		GSB_Visibility::recompute( $pages );
		GSB_Fixes::generate();

		GSB_Database::set_state( 'last_understanding', current_time( 'mysql' ) );
		// Notify external tools (fires visibility.updated, and visibility.drop
		// when the score falls past the threshold).
		GSB_Webhooks::fire_visibility();
		GSB_Logger::info( 'graph', 'Knowledge graph rebuilt.' );
	}

	/**
	 * Build Service ↔ Location relationships. A pair is "found" when a single
	 * page mentions both (strength scales with a dedicated page), otherwise it's
	 * a "recommended" (missing) link the business should create.
	 */
	private static function build_relationships( $pages ) {
		$services  = GSB_Database::get_entities( 'service' );
		$locations = GSB_Database::get_entities( 'location' );
		if ( empty( $services ) || empty( $locations ) ) {
			return;
		}

		foreach ( $services as $s ) {
			$s_l = strtolower( $s->name );
			foreach ( $locations as $l ) {
				$l_l = strtolower( $l->name );
				$status = 'recommended';
				$strength = 0;
				$evidence = 0;

				foreach ( $pages as $pid => $a ) {
					$hay   = strtolower( $a['title'] . ' ' . $a['plain'] );
					$has_s = ( '' !== $s_l && false !== strpos( $hay, $s_l ) );
					$has_l = ( '' !== $l_l && false !== strpos( $hay, $l_l ) );
					if ( $has_s && $has_l ) {
						$dedicated = ( stripos( $a['title'], $s->name ) !== false && stripos( $a['title'], $l->name ) !== false );
						$status    = 'found';
						$strength  = $dedicated ? 95 : 60;
						$evidence  = $pid;
						if ( $dedicated ) {
							break;
						}
					}
				}

				GSB_Database::add_relationship( $s->id, $l->id, 'offered_in', $strength, $status, $evidence );
			}
		}
	}

	/* ----------------------------------------------------------- analysis API */

	/**
	 * Build the Service × Location matrix for the Knowledge Graph view.
	 * Returns [ services[], locations[], cells[service_id][location_id] => status ].
	 */
	public static function matrix() {
		$services  = GSB_Database::get_entities( 'service' );
		$locations = GSB_Database::get_entities( 'location' );
		$rels      = GSB_Database::get_relationships( 'offered_in' );

		$cells = array();
		foreach ( $rels as $r ) {
			$status = $r->status;
			if ( 'found' === $status && (int) $r->strength < 70 ) {
				$status = 'weak';
			}
			$cells[ (int) $r->from_id ][ (int) $r->to_id ] = $status;
		}

		return array( 'services' => $services, 'locations' => $locations, 'cells' => $cells );
	}

	/**
	 * Entities that aren't connected to anything and have no source page —
	 * knowledge that exists in isolation and should be linked or removed.
	 */
	public static function orphans() {
		$all  = GSB_Database::get_entities();
		$rels = GSB_Database::get_relationships();
		$linked = array();
		foreach ( $rels as $r ) {
			if ( 'found' === $r->status ) {
				$linked[ (int) $r->from_id ] = true;
				$linked[ (int) $r->to_id ]   = true;
			}
		}
		$orphans = array();
		foreach ( $all as $e ) {
			if ( in_array( $e->entity_type, array( 'business', 'faq' ), true ) ) {
				continue; // these stand alone by nature
			}
			if ( empty( $linked[ (int) $e->id ] ) && (int) $e->source_post_id === 0 && 'found' === $e->status ) {
				$orphans[] = $e;
			}
		}
		return $orphans;
	}

	/** Count of missing (recommended) Service × Location links. */
	public static function missing_link_count() {
		$rels = GSB_Database::get_relationships( 'offered_in' );
		$n = 0;
		foreach ( $rels as $r ) {
			if ( 'recommended' === $r->status ) {
				$n++;
			}
		}
		return $n;
	}
}
