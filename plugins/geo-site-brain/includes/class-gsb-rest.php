<?php
/**
 * Read-only REST API exposing the AI-visibility data to external tools under
 * the gsb/v1 namespace. Authenticate either as a logged-in admin, or with the
 * plugin API key sent as `Authorization: Bearer <key>` or `X-GSB-Key: <key>`.
 *
 *   GET /wp-json/gsb/v1/summary
 *   GET /wp-json/gsb/v1/visibility
 *   GET /wp-json/gsb/v1/scores
 *   GET /wp-json/gsb/v1/fixes
 *   GET /wp-json/gsb/v1/entities?type=service
 *   GET /wp-json/gsb/v1/competitors
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Rest {

	const NS = 'gsb/v1';

	public static function register_routes() {
		$auth = array( __CLASS__, 'authorize' );

		$routes = array(
			'summary'     => 'summary',
			'visibility'  => 'visibility',
			'scores'      => 'scores',
			'fixes'       => 'fixes',
			'entities'    => 'entities',
			'competitors' => 'competitors',
		);
		foreach ( $routes as $path => $cb ) {
			register_rest_route( self::NS, '/' . $path, array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_' . $cb ),
				'permission_callback' => $auth,
			) );
		}
	}

	/* ------------------------------------------------------------- auth */

	public static function authorize( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$key = $request->get_header( 'x_gsb_key' );
		if ( ! $key ) {
			$auth = (string) $request->get_header( 'authorization' );
			if ( preg_match( '/Bearer\s+(.+)/i', $auth, $m ) ) {
				$key = trim( $m[1] );
			}
		}
		$stored = GSB_Settings::api_key();
		if ( $key && $stored && hash_equals( $stored, $key ) ) {
			return true;
		}
		return new WP_Error( 'gsb_forbidden', __( 'Invalid or missing API key.', 'geo-site-brain' ), array( 'status' => 401 ) );
	}

	/* --------------------------------------------------------- endpoints */

	public static function get_summary() {
		$counts  = GSB_Database::entity_counts();
		$engines = GSB_Database::get_visibility();
		$know = 0;
		foreach ( $engines as $e ) { $know += (int) $e->knowledge_score; }
		return rest_ensure_response( array(
			'business'          => trim( (string) GSB_Settings::get( 'business_name' ) ) ?: get_bloginfo( 'name' ),
			'ai_visibility'     => GSB_Visibility::overall_score(),
			'knowledge'         => $engines ? (int) round( $know / count( $engines ) ) : null,
			'avg_page_score'    => GSB_Database::site_score(),
			'entities'          => $counts,
			'open_fixes'        => count( GSB_Database::get_recommendations( 'open' ) ),
			'last_updated'      => GSB_Database::get_state( 'last_understanding', '' ),
		) );
	}

	public static function get_visibility() {
		$out = array();
		foreach ( GSB_Database::get_visibility() as $e ) {
			$details = json_decode( (string) $e->details, true ) ?: array();
			$out[] = array(
				'engine'         => $e->engine,
				'label'          => GSB_Visibility::engine_label( $e->engine ),
				'visibility'     => (int) $e->visibility_score,
				'confidence'     => (int) $e->confidence_score,
				'knowledge'      => (int) $e->knowledge_score,
				'recommendation' => (int) $e->recommendation_score,
				'live'           => ! empty( $details['live'] ),
				'summary'        => $e->summary,
				'computed_at'    => $e->computed_at,
			);
		}
		return rest_ensure_response( array( 'overall' => GSB_Visibility::overall_score(), 'engines' => $out ) );
	}

	public static function get_scores() {
		$out = array();
		foreach ( GSB_Database::get_scores( 'score', 'ASC', 500 ) as $r ) {
			$out[] = array(
				'post_id'   => (int) $r->post_id,
				'title'     => get_the_title( $r->post_id ),
				'url'       => $r->url,
				'score'     => (int) $r->score,
				'subscores' => json_decode( (string) $r->subscores, true ),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function get_fixes() {
		$out = array();
		foreach ( GSB_Database::get_recommendations( 'open' ) as $r ) {
			$out[] = array(
				'id'         => (int) $r->id,
				'title'      => $r->title,
				'impact'     => $r->impact,
				'difficulty' => $r->difficulty,
				'reason'     => $r->reason,
				'action'     => $r->fix_action,
				'post_id'    => (int) $r->post_id,
			);
		}
		return rest_ensure_response( $out );
	}

	public static function get_entities( $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$rows = GSB_Database::get_entities( $type ?: null );
		$out  = array();
		foreach ( $rows as $e ) {
			$out[] = array(
				'type'       => $e->entity_type,
				'name'       => $e->name,
				'status'     => $e->status,
				'confidence' => (int) $e->confidence,
				'post_id'    => (int) $e->source_post_id,
			);
		}
		return rest_ensure_response( $out );
	}

	public static function get_competitors() {
		$out = array();
		foreach ( GSB_Database::get_competitors() as $c ) {
			$out[] = array(
				'name'      => $c->name,
				'url'       => $c->url,
				'ai_score'  => (int) $c->ai_score,
				'snapshot'  => json_decode( (string) $c->snapshot, true ),
				'scored_at' => $c->scored_at,
			);
		}
		return rest_ensure_response( $out );
	}
}
