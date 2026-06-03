<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Fixer {

    /**
     * Apply a single fix from the fixes table.
     *
     * @param int $fix_id
     * @return true|WP_Error
     */
    public static function apply( $fix_id ) {
        global $wpdb;
        $fix = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_fixes WHERE id = %d",
            $fix_id
        ) );

        if ( ! $fix ) {
            return new WP_Error( 'not_found', __( 'Fix not found.', 'real-smart-seo' ) );
        }
        if ( $fix->applied ) {
            return new WP_Error( 'already_applied', __( 'This fix has already been applied.', 'real-smart-seo' ) );
        }

        $result = self::apply_fix( $fix );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        RSSEO_Database::apply_fix( $fix_id );

        // Update report fix count. ($wpdb already declared global above.)
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "UPDATE {$wpdb->prefix}rsseo_reports SET fixes_applied = fixes_applied + 1 WHERE id = %d",
            $fix->report_id
        ) );

        return true;
    }

    /**
     * Apply all pending fixes for a report.
     *
     * @param int $report_id
     * @return array Results: [ 'applied' => int, 'errors' => array ]
     */
    public static function apply_all( $report_id ) {
        $fixes   = RSSEO_Database::get_fixes( $report_id );
        $applied = 0;
        $errors  = array();

        foreach ( $fixes as $fix ) {
            if ( $fix->applied ) {
                continue;
            }
            $result = self::apply_fix( $fix );
            if ( is_wp_error( $result ) ) {
                $errors[] = $fix->id . ': ' . $result->get_error_message();
            } else {
                RSSEO_Database::apply_fix( $fix->id );
                $applied++;
            }
        }

        if ( $applied > 0 ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "UPDATE {$wpdb->prefix}rsseo_reports SET fixes_applied = fixes_applied + %d WHERE id = %d",
                $applied,
                $report_id
            ) );
        }

        return array( 'applied' => $applied, 'errors' => $errors );
    }

    /**
     * Execute the actual WordPress update for a fix row.
     */
    private static function apply_fix( $fix ) {
        $post_id   = (int) $fix->post_id;
        $fix_type  = $fix->fix_type;
        $field_key = $fix->field_key;
        $new_value = $fix->new_value;

        switch ( $fix_type ) {

            case 'title':
                if ( $post_id > 0 ) {
                    $result = wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $new_value ) ), true );
                    if ( is_wp_error( $result ) ) {
                        return $result;
                    }
                }
                // Also update SEO plugin meta title if field_key is a meta key.
                if ( $field_key && 'post_title' !== $field_key && $post_id > 0 ) {
                    update_post_meta( $post_id, $field_key, sanitize_text_field( $new_value ) );
                }
                return true;

            case 'meta_description':
                if ( $post_id > 0 && $field_key ) {
                    update_post_meta( $post_id, $field_key, sanitize_textarea_field( $new_value ) );
                }
                return true;

            case 'content':
                if ( $post_id > 0 ) {
                    $result = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( $new_value ) ), true );
                    if ( is_wp_error( $result ) ) {
                        return $result;
                    }
                }
                return true;

            case 'alt_text':
                if ( $post_id > 0 ) {
                    update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( $new_value ) );
                }
                return true;

            default:
                // Generic meta update.
                if ( $post_id > 0 && $field_key ) {
                    update_post_meta( $post_id, $field_key, sanitize_textarea_field( $new_value ) );
                    return true;
                }
                return new WP_Error( 'unknown_fix_type', __( 'Unknown fix type.', 'real-smart-seo' ) );
        }
    }

    /**
     * Preview what a fix will change — returns old/new for confirmation modal.
     */
    public static function preview( $fix_id ) {
        global $wpdb;
        $fix = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_fixes WHERE id = %d",
            $fix_id
        ) );

        if ( ! $fix ) {
            return null;
        }

        $post_title = '';
        if ( $fix->post_id > 0 ) {
            $post = get_post( $fix->post_id );
            $post_title = $post ? $post->post_title : '(post #' . $fix->post_id . ')';
        }

        return array(
            'id'         => $fix->id,
            'post_id'    => $fix->post_id,
            'post_title' => $post_title,
            'fix_type'   => $fix->fix_type,
            'field_key'  => $fix->field_key,
            'old_value'  => $fix->old_value,
            'new_value'  => $fix->new_value,
            'applied'    => (bool) $fix->applied,
        );
    }
}
