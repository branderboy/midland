<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Pro_Database {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Extends the base rsseo_scans with pro data sources.
        // We store pro data in a separate table to keep the base plugin clean.
        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_pro_scans (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) NOT NULL,
            keywords_input text DEFAULT NULL,
            location_input varchar(200) DEFAULT NULL,
            location_code int DEFAULT 2840,
            dataforseo_data longtext DEFAULT NULL,
            competitor_sf_data longtext DEFAULT NULL,
            gmb_data longtext DEFAULT NULL,
            reviews_data longtext DEFAULT NULL,
            perplexity_data longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_id (scan_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_pro_schema (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            report_id bigint(20) NOT NULL,
            post_id bigint(20) NOT NULL DEFAULT 0,
            schema_type varchar(50) NOT NULL,
            schema_json longtext NOT NULL,
            applied tinyint(1) NOT NULL DEFAULT 0,
            applied_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY report_id (report_id),
            KEY post_id (post_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_pro_backlinks (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            report_id bigint(20) NOT NULL,
            priority int NOT NULL DEFAULT 0,
            link_type varchar(20) NOT NULL DEFAULT '',
            target_name varchar(200) NOT NULL DEFAULT '',
            target_url varchar(500) NOT NULL DEFAULT '',
            rationale text NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY report_id (report_id)
        ) $charset;" );
    }

    public static function drop_tables() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_scans" );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_schema" );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsseo_pro_backlinks" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function insert_pro_scan( $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_pro_scans', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->insert_id;
    }

    public static function get_pro_scan( $scan_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_pro_scans WHERE scan_id = %d ORDER BY id DESC LIMIT 1",
            $scan_id
        ) );
    }

    public static function insert_schema( $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_pro_schema', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->insert_id;
    }

    public static function get_schemas( $report_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_pro_schema WHERE report_id = %d ORDER BY schema_type",
            $report_id
        ) );
    }

    public static function apply_schema( $id ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rsseo_pro_schema', // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            array( 'applied' => 1, 'applied_at' => current_time( 'mysql' ) ),
            array( 'id' => $id )
        );
    }

    public static function insert_backlink( $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_pro_backlinks', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->insert_id;
    }

    public static function get_backlinks( $report_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_pro_backlinks WHERE report_id = %d ORDER BY priority ASC",
            $report_id
        ) );
    }

    public static function update_backlink_status( $id, $status ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rsseo_pro_backlinks', // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            array( 'status' => sanitize_text_field( $status ) ),
            array( 'id' => $id )
        );
    }
}
