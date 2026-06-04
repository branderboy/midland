<?php
/**
 * Small presentation helpers shared by the admin views.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_View_Helpers {

	/** Map a 0–100 score to a colour band class. */
	public static function band( $score ) {
		$score = (int) $score;
		if ( $score >= 80 ) {
			return 'good';
		}
		if ( $score >= 60 ) {
			return 'ok';
		}
		if ( $score >= 40 ) {
			return 'warn';
		}
		return 'bad';
	}

	/** Human label for a recommendation type. */
	public static function rec_type_label( $type ) {
		$map = array(
			'weak_page'             => __( 'Weak pages', 'geo-site-brain' ),
			'missing_faq'           => __( 'Missing FAQs', 'geo-site-brain' ),
			'missing_service_page'  => __( 'Missing service pages', 'geo-site-brain' ),
			'missing_location_page' => __( 'Missing location pages', 'geo-site-brain' ),
			'overlapping_pages'     => __( 'Overlapping / duplicate pages', 'geo-site-brain' ),
			'schema'                => __( 'Schema improvements', 'geo-site-brain' ),
			'internal_links'        => __( 'Internal link opportunities', 'geo-site-brain' ),
			'meta_rewrite'          => __( 'Title / meta rewrites', 'geo-site-brain' ),
			'ai_answer_block'       => __( 'AI Overview answer blocks', 'geo-site-brain' ),
			'gbp_post_ideas'        => __( 'Google Business Profile ideas', 'geo-site-brain' ),
		);
		return $map[ $type ] ?? ucwords( str_replace( '_', ' ', (string) $type ) );
	}
}
