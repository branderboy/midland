<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Importer {

    /** Hard cap on an uploaded data file (2 MB). */
    const MAX_UPLOAD_BYTES = 2097152;
    /** Cap on the characters from any one source that feed the prompt (~200 KB). */
    const MAX_SOURCE_CHARS = 200000;

    /**
     * Process a scan form submission.
     * Accepts file uploads and/or pasted text for each data source.
     *
     * @param array $post  $_POST data.
     * @param array $files $_FILES data.
     * @return int|WP_Error Scan ID or error.
     */
    /**
     * Default scan label — "Scan N — May 12, 2026 2:50 PM" in site-local
     * time, where N is the 1-based position of this scan in the rsseo_scans
     * table. Stable and self-describing so users can rename later if they
     * want something more specific.
     */
    /**
     * Format a Site Audit's issues into a text block the AI can consume as a
     * data source. One issue per line: type | severity | post | description |
     * suggestion. Compact enough to fit alongside other dumps without bloating
     * the prompt.
     */
    public static function format_audit_as_text( $audit_id ) {
        $audit = RSSEO_Database::get_audit( (int) $audit_id );
        if ( ! $audit ) return '';
        $issues = RSSEO_Database::get_audit_issues( (int) $audit_id );
        if ( empty( $issues ) ) return '';
        $lines = array();
        $lines[] = sprintf( '# Site Audit #%d (%s) — %d posts checked, %d issues',
            (int) $audit->id, (string) $audit->created_at,
            (int) $audit->posts_checked, count( $issues )
        );
        foreach ( $issues as $i ) {
            $title = $i->post_id ? get_the_title( (int) $i->post_id ) : '(site-wide)';
            $lines[] = sprintf(
                '- [%s] %s | %s | %s | suggest: %s',
                strtoupper( (string) $i->severity ),
                (string) $i->issue_type,
                $title,
                trim( wp_strip_all_tags( (string) $i->description ) ),
                trim( wp_strip_all_tags( (string) $i->suggestion ) )
            );
        }
        return implode( "\n", $lines );
    }

    public static function auto_label() {
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rsseo_scans" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        return sprintf( 'Scan %d — %s', $count + 1, wp_date( 'M j, Y g:i A' ) );
    }

    public static function process_submission( $post, $files ) {
        $label = ! empty( $post['rsseo_scan_label'] )
            ? sanitize_text_field( wp_unslash( $post['rsseo_scan_label'] ) )
            : self::auto_label();

        $sf_data    = self::get_source_data( 'screaming_frog', $post, $files );
        $gsc_data   = self::get_source_data( 'gsc', $post, $files );
        $ga_data    = self::get_source_data( 'ga', $post, $files );
        $ps_data    = self::get_source_data( 'pagespeed', $post, $files );

        // Option B: pick a past Site Audit (our internal crawl) as a data source.
        // We pull its issues, format them as text, and prepend to the screaming_frog
        // field so the AI sees it as part of the technical SEO data. No copy/paste
        // between internal steps.
        $audit_id = isset( $post['rsseo_use_audit_id'] ) ? (int) $post['rsseo_use_audit_id'] : 0;
        if ( $audit_id > 0 ) {
            $audit_text = self::format_audit_as_text( $audit_id );
            if ( '' !== $audit_text ) {
                $sf_data = '' === $sf_data ? $audit_text : $audit_text . "\n\n---\n\n" . $sf_data;
            }
        }

        if ( empty( $sf_data ) && empty( $gsc_data ) && empty( $ga_data ) && empty( $ps_data ) ) {
            return new WP_Error( 'no_data', __( 'Please provide at least one data source to analyze.', 'real-smart-seo' ) );
        }

        // Track which inputs fed this scan, plus whether any Pro/DataForSEO
        // data was pulled — drives the Sources + Tier badges on the Reports
        // archive table.
        $sources = array();
        if ( $audit_id > 0 && isset( $audit_text ) && '' !== $audit_text ) $sources[] = 'audit';   // first-party (internal)
        if ( ! empty( $sf_data ) )  $sources[] = 'frog';   // external (paste/upload)
        if ( ! empty( $gsc_data ) ) $sources[] = 'gsc';
        if ( ! empty( $ga_data ) )  $sources[] = 'ga';
        if ( ! empty( $ps_data ) )  $sources[] = 'psi';
        $tier = defined( 'RSSEO_PRO_VERSION' ) && class_exists( 'RSSEO_Pro_DataForSEO' ) && RSSEO_Pro_DataForSEO::is_configured()
            ? 'pro' : 'basic';
        if ( 'pro' === $tier ) $sources[] = 'dfs';

        $scan_id = RSSEO_Database::insert_scan( array(
            'label'           => $label,
            'screaming_frog'  => $sf_data  ?: null,
            'gsc_data'        => $gsc_data ?: null,
            'ga_data'         => $ga_data  ?: null,
            'pagespeed_data'  => $ps_data  ?: null,
            'sources_used'    => implode( ',', $sources ),
            'tier'            => $tier,
            'status'          => 'pending',
            'created_at'      => current_time( 'mysql' ),
        ) );

        // Bug 1 fix: RSSEO_Pro_Admin hooks this filter (add_filter on line 22) to
        // save DataForSEO and other Pro scan data alongside the core scan row.
        // Without firing it the Pro scan record is never created, so the Pro
        // analyzer fails with "Scan data not found." when the job runs.
        $scan_id = apply_filters( 'rsseo_after_scan_created', $scan_id, $post );

        // Rolling 10-scan window — older runs (and their reports + fixes) are
        // pruned so the archive doesn't grow forever.
        RSSEO_Database::prune_old_scans( 10 );
        return $scan_id;
    }

    /**
     * Get data for a single source — prefers file upload, falls back to pasted text.
     */
    private static function get_source_data( $source, $post, $files ) {
        $file_key = 'rsseo_file_' . $source;
        $text_key = 'rsseo_text_' . $source;

        // Try file upload first.
        if ( isset( $files[ $file_key ] ) && ! empty( $files[ $file_key ]['tmp_name'] ) && UPLOAD_ERR_OK === (int) $files[ $file_key ]['error'] ) {
            $tmp  = $files[ $file_key ]['tmp_name'];

            // Must be a genuine PHP upload, not an arbitrary server path.
            if ( ! is_uploaded_file( $tmp ) ) {
                return '';
            }

            // Enforce a hard size cap before reading anything into memory.
            $size = isset( $files[ $file_key ]['size'] ) ? (int) $files[ $file_key ]['size'] : (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if ( $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
                return '';
            }

            $name = sanitize_file_name( $files[ $file_key ]['name'] );
            $ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, array( 'csv', 'txt', 'tsv' ), true ) ) {
                return '';
            }

            // Validate the real content is text (not a binary masquerading as .csv).
            $type = function_exists( 'mime_content_type' ) ? @mime_content_type( $tmp ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if ( $type && 0 !== strpos( $type, 'text/' ) && ! in_array( $type, array( 'application/csv', 'application/vnd.ms-excel', 'inode/x-empty' ), true ) ) {
                return '';
            }

            // Read at most MAX_UPLOAD_BYTES so a spoofed size can't exhaust memory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $content = file_get_contents( $tmp, false, null, 0, self::MAX_UPLOAD_BYTES );
            if ( false === $content || '' === $content ) {
                return '';
            }
            return self::cap( wp_strip_all_tags( $content ) );
        }

        // Fall back to pasted text.
        if ( ! empty( $post[ $text_key ] ) ) {
            return self::cap( sanitize_textarea_field( wp_unslash( $post[ $text_key ] ) ) );
        }

        return '';
    }

    /** Truncate any single source to MAX_SOURCE_CHARS so prompts stay bounded. */
    private static function cap( $text ) {
        $text = (string) $text;
        return ( strlen( $text ) > self::MAX_SOURCE_CHARS )
            ? substr( $text, 0, self::MAX_SOURCE_CHARS )
            : $text;
    }
}
