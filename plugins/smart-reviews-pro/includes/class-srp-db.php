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

    public static function get_survey_by_token( $token ) {
        global $wpdb;
        $id = (int) base64_decode( $token ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        if ( ! $id ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}srp_surveys WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

    public static function get_pending_reminders() {
        global $wpdb;
        $threshold = date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
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
}
