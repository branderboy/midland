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
            return new WP_Error( 'invalid_json', __( 'Schema JSON is not valid.', 'real-smart-seo' ) );
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
        // Hex-escape <, >, &, ', " so a stray </script> (or quote) inside the
        // JSON-LD can't break out of the <script> block — the schema JSON is
        // model-generated, so treat it as untrusted at the output boundary.
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        // Per-post schema.
        if ( is_singular() ) {
            $post_id = get_the_ID();
            $schema  = get_post_meta( $post_id, '_rsseo_pro_schema', true );
            if ( $schema ) {
                echo '<script type="application/ld+json">' . wp_json_encode( json_decode( $schema ), $flags ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        // Sitewide schema (LocalBusiness etc).
        $global = get_option( 'rsseo_pro_global_schema', array() );
        foreach ( $global as $schema ) {
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, $flags ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Parse SCHEMA_BLOCK lines from raw report text.
     * Format: SCHEMA_BLOCK: type=[LocalBusiness|Service|Review|FAQ] | post_id=[n] | json=[{...}]
     */
    public static function parse_from_report( $raw, $report_id ) {
        // Line-anchored (/m, no /s) with a greedy {...} so the capture runs to
        // the last brace on the line — a non-greedy {.+?} truncated at the
        // first nested } and stored corrupt JSON. Each SCHEMA_BLOCK is emitted
        // on its own single line by the analyzer prompt.
        preg_match_all( '/SCHEMA_BLOCK:\s*type=\[([^\]]+)\]\s*\|\s*post_id=\[(\d+)\]\s*\|\s*json=\[(\{.*\})\]/m', $raw, $matches, PREG_SET_ORDER );

        foreach ( $matches as $m ) {
            // Skip anything that isn't valid JSON rather than storing a row that
            // apply() will later reject.
            json_decode( $m[3] );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                continue;
            }
            RSSEO_Pro_Database::insert_schema( array(
                'report_id'   => $report_id,
                'post_id'     => (int) $m[2],
                'schema_type' => sanitize_text_field( $m[1] ),
                'schema_json' => $m[3],
                'created_at'  => current_time( 'mysql' ),
            ) );
        }
    }
}
