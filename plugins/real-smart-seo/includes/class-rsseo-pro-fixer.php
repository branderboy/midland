<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Pro_Fixer {

    /**
     * Apply a schema block by ID.
     *
     * @param int $schema_id
     * @return true|WP_Error
     */
    public static function apply_schema( $schema_id ) {
        global $wpdb;
        $schema = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_pro_schema WHERE id = %d",
            $schema_id
        ) );

        if ( ! $schema ) {
            return new WP_Error( 'not_found', __( 'Schema block not found.', 'real-smart-seo-pro' ) );
        }
        if ( $schema->applied ) {
            return new WP_Error( 'already_applied', __( 'Schema already applied.', 'real-smart-seo-pro' ) );
        }

        return RSSEO_Pro_Schema::apply( $schema_id, (int) $schema->post_id, $schema->schema_json );
    }

    /**
     * Apply all pending schemas for a report.
     *
     * @param int $report_id
     * @return array
     */
    public static function apply_all_schemas( $report_id ) {
        $schemas = RSSEO_Pro_Database::get_schemas( $report_id );
        $applied = 0;
        $errors  = array();

        foreach ( $schemas as $schema ) {
            if ( $schema->applied ) {
                continue;
            }
            $result = RSSEO_Pro_Schema::apply( $schema->id, (int) $schema->post_id, $schema->schema_json );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
            } else {
                $applied++;
            }
        }

        return array( 'applied' => $applied, 'errors' => $errors );
    }

    /**
     * Mark a backlink as pursued / completed.
     *
     * @param int    $backlink_id
     * @param string $status 'pursuing'|'completed'|'skipped'
     */
    public static function update_backlink( $backlink_id, $status ) {
        RSSEO_Pro_Database::update_backlink_status( $backlink_id, $status );
    }
}
