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
	/**
	 * Rebuild the full knowledge graph: entities, relationships, visibility,
	 * and the fix queue. Safe to call from cron or AJAX.
	 *
	 * Concurrency: a transient lock prevents concurrent rebuilds from racing
	 * each other (duplicate recommendations, inconsistent entity counts). The
	 * lock expires after 5 minutes so a crashed process never blocks forever.
	 *
	 * Partial-state on failure: MySQL does not support nested transactions via
	 * wpdb, so we cannot atomically roll back all four sub-steps. Instead we
	 * log exactly which step failed so the admin knows which data may be stale.
	 * The lock is released immediately on any failure so a retry is possible.
	 * Steps run in dependency order — entities before relationships, visibility
	 * and fixes last — so partial output is always internally consistent up to
	 * the failing step.
	 */
	public static function rebuild_all() {
		$lock_key = 'gsb_rebuild_lock';

		// Acquire lock. If another process holds it, bail immediately.
		if ( get_transient( $lock_key ) ) {
			throw new \RuntimeException( __( 'A rebuild is already in progress. Try again in a moment.', 'geo-site-brain' ) );
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		$step = '';
		try {
			$pages = array();
			foreach ( GSB_Scanner::all_post_ids() as $id ) {
				$a = GSB_Scanner::analyze( $id );
				if ( $a ) {
					$pages[ $id ] = $a;
				}
			}

			$step = 'entities';
			GSB_Entities::rebuild_from_site( $pages );

			$step = 'relationships';
			self::build_relationships( $pages );

			$step = 'visibility';
			GSB_Visibility::recompute( $pages );

			$step = 'fixes';
			GSB_Fixes::generate();

			GSB_Database::set_state( 'last_understanding', current_time( 'mysql' ) );
			// Notify external tools (visibility.updated, and visibility.drop when
			// the score falls past the threshold).
			if ( class_exists( 'GSB_Webhooks' ) ) {
				GSB_Webhooks::fire_visibility();
			}
			GSB_Logger::info( 'graph', 'Knowledge graph rebuilt.' );
		} catch ( \Throwable $e ) {
			delete_transient( $lock_key );
			// Log which step failed so the admin can tell which data is stale.
			GSB_Logger::error( 'graph', sprintf(
				/* translators: 1: step name, 2: error message */
				__( 'Rebuild failed at step "%1$s": %2$s. Data up to that step may be partially updated.', 'geo-site-brain' ),
				$step,
				$e->getMessage()
			) );
			throw $e;
		}

		delete_transient( $lock_key );
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
