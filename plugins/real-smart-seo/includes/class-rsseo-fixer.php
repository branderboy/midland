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
        self::sync_count( (int) $fix->report_id );

        return true;
    }

    /**
     * Recompute fixes_applied from the actual applied rows, so the counter can
     * never drift from reality even if an apply/restore step partially fails.
     */
    private static function sync_count( $report_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "UPDATE {$wpdb->prefix}rsseo_reports
             SET fixes_applied = ( SELECT COUNT(*) FROM {$wpdb->prefix}rsseo_fixes WHERE report_id = %d AND applied = 1 )
             WHERE id = %d",
            (int) $report_id,
            (int) $report_id
        ) );
    }

    /** Is this fix one we can safely apply? Mirrors apply_fix()'s allow-list. */
    private static function is_supported( $fix ) {
        $type = (string) $fix->fix_type;
        if ( in_array( $type, array( 'title', 'meta_description', 'content', 'alt_text' ), true ) ) {
            return true;
        }
        $allow = array(
            '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_seopress_titles_desc',
            '_yoast_wpseo_title', 'rank_math_title', '_aioseo_title', '_seopress_titles_title',
        );
        return ! empty( $fix->field_key ) && in_array( $fix->field_key, $allow, true );
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
            self::sync_count( (int) $report_id );
        }

        return array( 'applied' => $applied, 'errors' => $errors );
    }

    /**
     * Snapshot the CURRENT live value before a fix overwrites it, so the change
     * is reversible. Stored per-fix (autoload off) and read back by restore().
     */
    private static function capture_backup( $fix ) {
        $post_id = (int) $fix->post_id;
        if ( $post_id <= 0 ) {
            return;
        }
        switch ( $fix->fix_type ) {
            case 'title':
                $value = get_post_field( 'post_title', $post_id );
                break;
            case 'content':
                $value = get_post_field( 'post_content', $post_id );
                break;
            case 'alt_text':
                $value = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
                break;
            case 'meta_description':
            default:
                $value = $fix->field_key ? get_post_meta( $post_id, $fix->field_key, true ) : '';
                break;
        }
        update_option( 'rsseo_fix_backup_' . (int) $fix->id, array(
            'value'   => $value,
            'time'    => time(),
            'type'    => $fix->fix_type,
            'field'   => $fix->field_key,
            'post_id' => $post_id,
        ), false );
    }

    /**
     * Restore a single applied fix from its backup and mark it un-applied.
     *
     * @param int $fix_id
     * @return true|WP_Error
     */
    public static function restore( $fix_id ) {
        $backup = get_option( 'rsseo_fix_backup_' . (int) $fix_id, null );
        if ( ! is_array( $backup ) ) {
            return new WP_Error( 'no_backup', __( 'No backup is available for this fix — it cannot be reverted automatically.', 'real-smart-seo' ) );
        }
        $post_id = (int) $backup['post_id'];
        if ( $post_id <= 0 ) {
            return new WP_Error( 'bad_backup', __( 'Backup is missing its target post.', 'real-smart-seo' ) );
        }

        switch ( $backup['type'] ) {
            case 'title':
                $result = wp_update_post( array( 'ID' => $post_id, 'post_title' => $backup['value'] ), true );
                break;
            case 'content':
                $result = wp_update_post( array( 'ID' => $post_id, 'post_content' => $backup['value'] ), true );
                break;
            case 'alt_text':
                update_post_meta( $post_id, '_wp_attachment_image_alt', $backup['value'] );
                $result = true;
                break;
            default:
                if ( ! empty( $backup['field'] ) ) {
                    update_post_meta( $post_id, $backup['field'], $backup['value'] );
                }
                $result = true;
                break;
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rsseo_fixes', array( 'applied' => 0 ), array( 'id' => (int) $fix_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $fix = $wpdb->get_row( $wpdb->prepare( "SELECT report_id FROM {$wpdb->prefix}rsseo_fixes WHERE id = %d", (int) $fix_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $fix ) {
            self::sync_count( (int) $fix->report_id );
        }
        delete_option( 'rsseo_fix_backup_' . (int) $fix_id );
        return true;
    }

    /**
     * Revert every applied fix in a report.
     *
     * @param int $report_id
     * @return array [ 'restored' => int, 'errors' => array ]
     */
    public static function restore_all( $report_id ) {
        $restored = 0;
        $errors   = array();
        foreach ( RSSEO_Database::get_fixes( $report_id ) as $fix ) {
            if ( ! $fix->applied ) {
                continue;
            }
            $result = self::restore( $fix->id );
            if ( is_wp_error( $result ) ) {
                $errors[] = $fix->id . ': ' . $result->get_error_message();
            } else {
                $restored++;
            }
        }
        return array( 'restored' => $restored, 'errors' => $errors );
    }

    /**
     * Execute the actual WordPress update for a fix row.
     */
    private static function apply_fix( $fix ) {
        // Refuse unsupported fixes BEFORE creating a backup, so a malformed
        // suggestion can't litter wp_options with orphan backup entries.
        if ( ! self::is_supported( $fix ) ) {
            return new WP_Error( 'unknown_fix_type', __( 'Unsupported fix — skipped for safety.', 'real-smart-seo' ) );
        }

        // Snapshot the live value first so every applied fix is reversible.
        self::capture_backup( $fix );

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
                // Generic meta update — but only to known SEO meta keys, never an
                // arbitrary field. Stops a malformed fix from clobbering meta like
                // _thumbnail_id or _wp_page_template.
                $allow = array(
                    '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_seopress_titles_desc',
                    '_yoast_wpseo_title', 'rank_math_title', '_aioseo_title', '_seopress_titles_title',
                );
                if ( $post_id > 0 && $field_key && in_array( $field_key, $allow, true ) ) {
                    update_post_meta( $post_id, $field_key, sanitize_textarea_field( $new_value ) );
                    return true;
                }
                return new WP_Error( 'unknown_fix_type', __( 'Unsupported fix — skipped for safety.', 'real-smart-seo' ) );
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
