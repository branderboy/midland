<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SRP_DB {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $surveys = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}srp_surveys (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_name  VARCHAR(200)  NOT NULL DEFAULT '',
            customer_email VARCHAR(200)  NOT NULL DEFAULT '',
            customer_phone VARCHAR(50)   NOT NULL DEFAULT '',
            job_id         VARCHAR(100)  NOT NULL DEFAULT '',
            score          TINYINT       NULL,
            feedback       TEXT          NULL,
            routed         TINYINT(1)    NOT NULL DEFAULT 0,
            route_type     VARCHAR(20)   NOT NULL DEFAULT '',
            survey_sent_at DATETIME      NULL,
            responded_at   DATETIME      NULL,
            reminder1_at   DATETIME      NULL,
            reminder2_at   DATETIME      NULL,
            created_at     DATETIME      NOT NULL,
            PRIMARY KEY (id),
            KEY customer_email (customer_email),
            KEY score (score),
            KEY routed (routed)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $surveys );
    }

    public static function insert_survey( $data ) {
        global $wpdb;
        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'srp_surveys', $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->insert_id;
    }

    public static function update_survey( $id, $data ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'srp_surveys', $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Resolve a HMAC-signed token "<id>.<sig>" to a survey row.
     * Returns null if the signature is invalid or the row is missing.
     */
    public static function get_survey_by_token( $token ) {
        $id = self::verify_token( (string) $token );
        if ( ! $id ) {
            return null;
        }
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}srp_surveys WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Build a token from a survey id: "<id>.<hmac>".
     */
    public static function build_token( $survey_id ) {
        $survey_id = (int) $survey_id;
        return $survey_id . '.' . self::sign( $survey_id );
    }

    /**
     * Validate a token and return the survey id, or 0 if it fails.
     */
    public static function verify_token( $token ) {
        if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) {
            return 0;
        }
        list( $raw_id, $sig ) = array_pad( explode( '.', $token, 2 ), 2, '' );
        $survey_id = (int) $raw_id;
        if ( $survey_id <= 0 || '' === $sig ) {
            return 0;
        }
        $expected = self::sign( $survey_id );
        if ( ! hash_equals( $expected, $sig ) ) {
            return 0;
        }
        return $survey_id;
    }

    private static function sign( $survey_id ) {
        $secret = self::get_secret();
        return hash_hmac( 'sha256', 'srp:' . (int) $survey_id, $secret );
    }

    private static function get_secret() {
        $secret = (string) get_option( 'srp_token_secret', '' );
        if ( '' === $secret ) {
            $secret = wp_generate_password( 64, true, true );
            add_option( 'srp_token_secret', $secret, '', 'no' );
        }
        return $secret;
    }

    public static function get_surveys( $args = array() ) {
        global $wpdb;
        $limit  = absint( $args['limit'] ?? 50 );
        $offset = absint( $args['offset'] ?? 0 );
        $where  = '1=1';

        if ( isset( $args['routed'] ) ) {
            $where .= $wpdb->prepare( ' AND routed = %d', (int) $args['routed'] );
        }
        if ( isset( $args['min_score'] ) ) {
            $where .= $wpdb->prepare( ' AND score >= %d', (int) $args['min_score'] );
        }
        if ( isset( $args['max_score'] ) ) {
            $where .= $wpdb->prepare( ' AND score <= %d', (int) $args['max_score'] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}srp_surveys WHERE {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}" );
    }

    /**
     * Surveys that need their first reminder (24h after send, no reminder1 yet).
     */
    public static function get_pending_reminders() {
        global $wpdb;
        $threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srp_surveys
                WHERE score IS NULL
                AND survey_sent_at IS NOT NULL
                AND survey_sent_at < %s
                AND reminder1_at IS NULL",
                $threshold
            )
        );
    }

    /**
     * Surveys that need their second reminder (48h after send, reminder1 already gone, no reminder2 yet).
     */
    public static function get_pending_reminders_second() {
        global $wpdb;
        $threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-48 hours' ) );
        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srp_surveys
                WHERE score IS NULL
                AND survey_sent_at IS NOT NULL
                AND survey_sent_at < %s
                AND reminder1_at IS NOT NULL
                AND reminder2_at IS NULL",
                $threshold
            )
        );
    }
}
