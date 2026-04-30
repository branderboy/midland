<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Pro_Schema {

    /**
     * Apply a schema block to a post or sitewide (post_id = 0 = homepage/sitewide).
     * Stores JSON-LD in post meta or a sitewide option.
     *
     * @param int    $schema_id DB row ID in rsseo_pro_schema
     * @param int    $post_id
     * @param string $schema_json Raw JSON-LD string
     * @return true|WP_Error
     */
    public static function apply( $schema_id, $post_id, $schema_json ) {
        // Validate JSON.
        $decoded = json_decode( $schema_json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', __( 'Schema JSON is not valid.', 'real-smart-seo-pro' ) );
        }

        if ( $post_id > 0 ) {
            update_post_meta( $post_id, '_rsseo_pro_schema', wp_json_encode( $decoded ) );
        } else {
            // Sitewide — append to global schema option.
            $existing = get_option( 'rsseo_pro_global_schema', array() );
            $existing[ $schema_id ] = $decoded;
            update_option( 'rsseo_pro_global_schema', $existing );
        }

        RSSEO_Pro_Database::apply_schema( $schema_id );
        return true;
    }

    /**
     * Output schema JSON-LD in the page <head>.
     * Hooked to wp_head.
     */
    public static function output_schema() {
        // Per-post schema.
        if ( is_singular() ) {
            $post_id = get_the_ID();
            $schema  = get_post_meta( $post_id, '_rsseo_pro_schema', true );
            if ( $schema ) {
                echo '<script type="application/ld+json">' . wp_json_encode( json_decode( $schema ) ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        // Sitewide schema (LocalBusiness etc).
        $global = get_option( 'rsseo_pro_global_schema', array() );
        foreach ( $global as $schema ) {
            echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Parse SCHEMA_BLOCK lines from raw report text.
     * Format: SCHEMA_BLOCK: type=[LocalBusiness|Service|Review|FAQ] | post_id=[n] | json=[{...}]
     */
    public static function parse_from_report( $raw, $report_id ) {
        preg_match_all( '/SCHEMA_BLOCK:\s*type=\[([^\]]+)\]\s*\|\s*post_id=\[(\d+)\]\s*\|\s*json=\[({.+?})\]/s', $raw, $matches, PREG_SET_ORDER );

        foreach ( $matches as $m ) {
            RSSEO_Pro_Database::insert_schema( array(
                'report_id'   => $report_id,
                'post_id'     => (int) $m[2],
                'schema_type' => sanitize_text_field( $m[1] ),
                'schema_json' => $m[3],
                'created_at'  => current_time( 'mysql' ),
            ) );
        }
    }

    /**
     * Parse BACKLINK lines from raw report text.
     * Format: BACKLINK: priority=[n] | type=[.gov|.org|nonprofit|local|chamber|directory] | name=[Target Name] | url=[https://...] | rationale=[Why this link matters]
     */
    public static function parse_backlinks_from_report( $raw, $report_id ) {
        preg_match_all( '/BACKLINK:\s*priority=\[(\d+)\]\s*\|\s*type=\[([^\]]+)\]\s*\|\s*name=\[([^\]]+)\]\s*\|\s*url=\[([^\]]*)\]\s*\|\s*rationale=\[([^\]]+)\]/i', $raw, $matches, PREG_SET_ORDER );

        foreach ( $matches as $m ) {
            RSSEO_Pro_Database::insert_backlink( array(
                'report_id'   => $report_id,
                'priority'    => (int) $m[1],
                'link_type'   => sanitize_text_field( $m[2] ),
                'target_name' => sanitize_text_field( $m[3] ),
                'target_url'  => esc_url_raw( $m[4] ),
                'rationale'   => sanitize_textarea_field( $m[5] ),
                'status'      => 'pending',
                'created_at'  => current_time( 'mysql' ),
            ) );
        }
    }
}
