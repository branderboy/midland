<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Database {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_audits (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            posts_checked int NOT NULL DEFAULT 0,
            issues_critical int NOT NULL DEFAULT 0,
            issues_high int NOT NULL DEFAULT 0,
            issues_medium int NOT NULL DEFAULT 0,
            issues_low int NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_audit_issues (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            audit_id bigint(20) NOT NULL,
            post_id bigint(20) NOT NULL DEFAULT 0,
            issue_type varchar(50) NOT NULL,
            severity varchar(10) NOT NULL DEFAULT 'medium',
            description text NOT NULL,
            suggestion text NOT NULL DEFAULT '',
            auto_fixable tinyint(1) NOT NULL DEFAULT 0,
            fix_field varchar(100) NOT NULL DEFAULT '',
            fix_value longtext NOT NULL DEFAULT '',
            fixed tinyint(1) NOT NULL DEFAULT 0,
            fixed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY audit_id (audit_id),
            KEY post_id (post_id),
            KEY severity (severity)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_scans (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            label varchar(200) NOT NULL DEFAULT '',
            screaming_frog longtext DEFAULT NULL,
            gsc_data longtext DEFAULT NULL,
            ga_data longtext DEFAULT NULL,
            pagespeed_data longtext DEFAULT NULL,
            sources_used varchar(100) NOT NULL DEFAULT '',
            tier varchar(10) NOT NULL DEFAULT 'basic',
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_reports (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) NOT NULL,
            report_raw longtext NOT NULL,
            report_html longtext NOT NULL,
            issues_critical int NOT NULL DEFAULT 0,
            issues_high int NOT NULL DEFAULT 0,
            issues_medium int NOT NULL DEFAULT 0,
            issues_low int NOT NULL DEFAULT 0,
            fixes_available int NOT NULL DEFAULT 0,
            fixes_applied int NOT NULL DEFAULT 0,
            model varchar(100) NOT NULL DEFAULT '',
            tokens_used int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_id (scan_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_fixes (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            report_id bigint(20) NOT NULL,
            post_id bigint(20) NOT NULL,
            fix_type varchar(50) NOT NULL,
            field_key varchar(100) NOT NULL,
            old_value longtext NOT NULL DEFAULT '',
            new_value longtext NOT NULL DEFAULT '',
            applied tinyint(1) NOT NULL DEFAULT 0,
            applied_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY report_id (report_id),
            KEY post_id (post_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_api_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) DEFAULT NULL,
            model varchar(100) NOT NULL DEFAULT '',
            input_tokens int NOT NULL DEFAULT 0,
            output_tokens int NOT NULL DEFAULT 0,
            cost_estimate decimal(10,6) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;" );
    }

    public static function drop_tables() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_scans" );          // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_reports" );        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_fixes" );          // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_api_log" );        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_audits" );         // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_audit_issues" );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function insert_scan( $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_scans', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->insert_id;
    }

    public static function update_scan( $id, $data ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rsseo_scans', $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    public static function get_scan( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_scans WHERE id = %d",
            $id
        ) );
    }

    public static function get_scans( $limit = 20 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT s.*, r.id as report_id, r.issues_critical, r.issues_high, r.issues_medium, r.issues_low, r.fixes_available, r.fixes_applied
             FROM {$wpdb->prefix}rsseo_scans s
             LEFT JOIN {$wpdb->prefix}rsseo_reports r ON r.scan_id = s.id
             ORDER BY s.created_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Keep only the N most recent scans. Anything older is deleted along with
     * its report row and any associated fixes — keeps the Reports archive
     * scannable and avoids the rsseo_scans table growing forever.
     *
     * @param int $keep Number of recent scans to retain. Default 10.
     * @return int Number of scans deleted.
     */
    public static function prune_old_scans( $keep = 10 ) {
        global $wpdb;
        $keep = max( 1, (int) $keep );
        $stale_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id FROM {$wpdb->prefix}rsseo_scans ORDER BY created_at DESC, id DESC LIMIT %d, 1000000",
            $keep
        ) );
        if ( empty( $stale_ids ) ) return 0;
        $placeholders = implode( ',', array_fill( 0, count( $stale_ids ), '%d' ) );
        // Reports first (so fixes can be looked up by report_id), then fixes,
        // then the scans themselves.
        $report_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id FROM {$wpdb->prefix}rsseo_reports WHERE scan_id IN ($placeholders)",
            $stale_ids
        ) );
        if ( ! empty( $report_ids ) ) {
            $rp_placeholders = implode( ',', array_fill( 0, count( $report_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "DELETE FROM {$wpdb->prefix}rsseo_fixes WHERE report_id IN ($rp_placeholders)",
                $report_ids
            ) );
        }
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "DELETE FROM {$wpdb->prefix}rsseo_reports WHERE scan_id IN ($placeholders)",
            $stale_ids
        ) );
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "DELETE FROM {$wpdb->prefix}rsseo_scans WHERE id IN ($placeholders)",
            $stale_ids
        ) );
        return count( $stale_ids );
    }

    public static function insert_report( $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_reports', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->insert_id;
    }

    public static function get_report( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT r.*, s.label, s.created_at as scan_date FROM {$wpdb->prefix}rsseo_reports r
             JOIN {$wpdb->prefix}rsseo_scans s ON s.id = r.scan_id
             WHERE r.id = %d",
            $id
        ) );
    }

    public static function get_report_by_scan( $scan_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_reports WHERE scan_id = %d ORDER BY id DESC LIMIT 1",
            $scan_id
        ) );
    }

    public static function update_report( $id, $data ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rsseo_reports', $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    public static function insert_fix( $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_fixes', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->insert_id;
    }

    public static function get_fixes( $report_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_fixes WHERE report_id = %d ORDER BY fix_type, post_id",
            $report_id
        ) );
    }

    public static function apply_fix( $fix_id ) {
        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'rsseo_fixes',
            array( 'applied' => 1, 'applied_at' => current_time( 'mysql' ) ),
            array( 'id' => $fix_id )
        );
    }

    public static function log_api_call( $scan_id, $model, $input_tokens, $output_tokens, $cost ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_api_log', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'scan_id'       => $scan_id,
            'model'         => $model,
            'input_tokens'  => $input_tokens,
            'output_tokens' => $output_tokens,
            'cost_estimate' => $cost,
            'created_at'    => current_time( 'mysql' ),
        ) );
    }

    // ── Audit methods ──────────────────────────────────────────────────────────

    public static function create_audit() {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_audits', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'status'     => 'running',
            'created_at' => current_time( 'mysql' ),
        ) );
        return $wpdb->insert_id;
    }

    public static function complete_audit( $audit_id, $counts ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rsseo_audits', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'posts_checked'  => (int) $counts['posts_checked'],
            'issues_critical'=> (int) $counts['critical'],
            'issues_high'    => (int) $counts['high'],
            'issues_medium'  => (int) $counts['medium'],
            'issues_low'     => (int) $counts['low'],
            'status'         => 'complete',
        ), array( 'id' => $audit_id ) );
    }

    public static function get_latest_audit() {
        global $wpdb;
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
            "SELECT * FROM {$wpdb->prefix}rsseo_audits WHERE status = 'complete' ORDER BY id DESC LIMIT 1"
        );
    }

    public static function get_audits( $limit = 50 ) {
        global $wpdb;
        $limit = max( 1, (int) $limit );
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_audits WHERE status = 'complete' ORDER BY id DESC LIMIT %d",
            $limit
        ) );
    }

    public static function get_audit( $audit_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_audits WHERE id = %d",
            $audit_id
        ) );
    }

    public static function insert_audit_issue( $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_audit_issues', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->insert_id;
    }

    public static function get_audit_issues( $audit_id, $severity = '' ) {
        global $wpdb;
        if ( $severity ) {
            return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT * FROM {$wpdb->prefix}rsseo_audit_issues WHERE audit_id = %d AND severity = %s ORDER BY post_id",
                $audit_id,
                $severity
            ) );
        }
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_audit_issues WHERE audit_id = %d ORDER BY FIELD(severity,'critical','high','medium','low'), post_id",
            $audit_id
        ) );
    }

    public static function apply_audit_fix( $issue_id ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rsseo_audit_issues', // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            array( 'fixed' => 1, 'fixed_at' => current_time( 'mysql' ) ),
            array( 'id' => $issue_id )
        );
    }

    public static function get_audit_issue( $issue_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_audit_issues WHERE id = %d",
            $issue_id
        ) );
    }

    public static function get_monthly_usage() {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) as scans, COALESCE(SUM(input_tokens+output_tokens),0) as total_tokens, COALESCE(SUM(cost_estimate),0) as total_cost
             FROM {$wpdb->prefix}rsseo_api_log WHERE created_at >= %s",
            gmdate( 'Y-m-01 00:00:00' )
        ) );
    }
}
