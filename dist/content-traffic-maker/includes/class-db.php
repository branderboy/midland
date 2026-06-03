<?php
/**
 * Database layer — owns the wp_content_traffic_maker_briefs table and the
 * settings option.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTM_DB {

    const TABLE       = 'content_traffic_maker_briefs';
    const OPTION      = 'ctm_settings';
    const DB_VERSION  = '1.8.0';
    const DB_VERS_KEY = 'ctm_db_version';

    /** Fully-qualified table name. */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create / migrate the briefs table via dbDelta.
     */
    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            business_name VARCHAR(255) NOT NULL DEFAULT '',
            brief_json LONGTEXT NULL,
            brief_html LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            sent_to VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'generated',
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );
        update_option( self::DB_VERS_KEY, self::DB_VERSION );
    }

    /**
     * Re-run create_table() when the plugin is updated in place (no
     * deactivate/reactivate). Cheap no-op once versions match.
     */
    public static function maybe_upgrade() {
        if ( get_option( self::DB_VERS_KEY ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    /**
     * Insert a brief row.
     *
     * @param array $data business_name, brief_json, brief_html, sent_to, status
     * @return int Inserted row ID (0 on failure).
     */
    public static function insert_brief( $data ) {
        global $wpdb;
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            self::table(),
            array(
                'business_name' => sanitize_text_field( $data['business_name'] ?? '' ),
                'brief_json'    => wp_json_encode( $data['brief'] ?? array() ),
                'brief_html'    => (string) ( $data['brief_html'] ?? '' ),
                'created_at'    => current_time( 'mysql' ),
                'sent_to'       => sanitize_text_field( $data['sent_to'] ?? '' ),
                'status'        => sanitize_key( $data['status'] ?? 'generated' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Recent briefs, newest first.
     *
     * @param int $limit
     * @return array
     */
    public static function get_briefs( $limit = 20 ) {
        global $wpdb;
        $limit = max( 1, min( 200, (int) $limit ) );
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d',
            $limit
        ) );
    }

    /**
     * Mark a stored brief as sent.
     */
    public static function mark_sent( $id, $sent_to ) {
        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            self::table(),
            array(
                'status'  => 'sent',
                'sent_to' => sanitize_text_field( $sent_to ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    public static function get_brief( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'SELECT * FROM ' . self::table() . ' WHERE id = %d',
            (int) $id
        ) );
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public static function defaults() {
        return array(
            'business_name'   => 'Midland Floors',
            'target_city'     => 'Washington',
            'target_state'    => 'DC',
            'recipient'       => get_option( 'admin_email' ),
            'frequency'       => 'daily',
            'send_time'       => '08:00',
            'api_key'         => '',
            'model'           => 'sonar',
            'enabled'         => 0,
            'resend_api_key'  => '',
            'from_name'       => 'Midland Floors',
            'from_email'      => '',
        );
    }

    /**
     * @return array Settings merged over defaults.
     */
    public static function get_settings() {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return wp_parse_args( $stored, self::defaults() );
    }

    public static function update_settings( $settings ) {
        update_option( self::OPTION, $settings, false );
    }
}
