<?php
/**
 * Integration log.
 *
 * Every outbound call from a Smart Forms integration (Resend send,
 * ActiveCampaign sync, webhook fire) records a row here so the operator
 * can answer "why didn't this lead reach my CRM / inbox / Zapier?"
 * without grepping server logs.
 *
 * The table is created by SFCO_Pro_DB::create_tables() on activation
 * and by the bootstrap's maybe_install_tables() on every plugin
 * upgrade so post-1.x installs always have it.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Log {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sfco_integration_log';
    }

    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            integration VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL,
            form_id BIGINT(20) UNSIGNED DEFAULT NULL,
            lead_id BIGINT(20) UNSIGNED DEFAULT NULL,
            message TEXT NULL,
            payload LONGTEXT NULL,
            response LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY integration (integration),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};" );
    }

    /**
     * Record an integration event.
     *
     * @param string     $integration e.g. 'resend', 'crm', 'webhook'
     * @param string     $status       'ok' | 'error' | 'skipped'
     * @param string     $message     One-line human summary, e.g. "HTTP 200" or "Missing API key"
     * @param int|null   $form_id
     * @param int|null   $lead_id
     * @param mixed|null $payload     Outbound payload (will be JSON-encoded)
     * @param mixed|null $response    Provider response (will be JSON-encoded)
     */
    public static function record( string $integration, string $status, string $message = '', $form_id = null, $lead_id = null, $payload = null, $response = null ): void {
        global $wpdb;
        // Best-effort write. If the table somehow doesn't exist yet
        // (race during activation) the insert will fail silently —
        // we never want logging to break a live form submission.
        $wpdb->insert(
            self::table(),
            array(
                'created_at'  => current_time( 'mysql', 1 ),
                'integration' => substr( $integration, 0, 64 ),
                'status'      => in_array( $status, array( 'ok', 'error', 'skipped' ), true ) ? $status : 'error',
                'form_id'     => $form_id ? (int) $form_id : null,
                'lead_id'     => $lead_id ? (int) $lead_id : null,
                'message'     => $message ? substr( $message, 0, 2000 ) : null,
                'payload'     => $payload ? wp_json_encode( $payload ) : null,
                'response'    => $response ? wp_json_encode( $response ) : null,
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
        );
    }

    public static function recent( int $limit = 50 ): array {
        global $wpdb;
        $table = self::table();
        // Defensive — table may not exist yet on a stale upgrade.
        $exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return array();
        }
        $limit = max( 1, min( 500, $limit ) );
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
