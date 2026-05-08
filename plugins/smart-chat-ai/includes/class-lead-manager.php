<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCAI_Lead_Manager {

    public function create_lead( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_leads';

        $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'name'           => sanitize_text_field( $data['name'] ),
            'email'          => sanitize_email( $data['email'] ),
            'phone'          => sanitize_text_field( $data['phone'] ?? '' ),
            'message'        => sanitize_textarea_field( $data['message'] ?? '' ),
            'service_type'   => sanitize_text_field( $data['service_type'] ?? '' ),
            'project_budget' => sanitize_text_field( $data['project_budget'] ?? '' ),
            'timeline'       => sanitize_text_field( $data['timeline'] ?? '' ),
            'source'         => 'chat',
            'status'         => 'new',
            'ip_address'     => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'created_at'     => current_time( 'mysql' ),
        ) );

        // Link to session if provided.
        if ( ! empty( $data['session_id'] ) ) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prefix . 'smart_chat_conversations',
                array( 'lead_id' => $wpdb->insert_id ),
                array( 'session_id' => sanitize_text_field( $data['session_id'] ) ),
                array( '%d' ),
                array( '%s' )
            );
        }

        return $wpdb->insert_id;
    }

    public function get_leads( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_leads';

        $defaults = array(
            'status' => '',
            'search' => '',
            'limit'  => 20,
            'offset' => 0,
        );
        $args = wp_parse_args( $args, $defaults );

        $where   = array( '1=1' );
        $prepare = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]   = 'status = %s';
            $prepare[] = sanitize_text_field( $args['status'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $like      = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[]   = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
            $prepare[] = $like;
            $prepare[] = $like;
            $prepare[] = $like;
        }

        $where_clause = implode( ' AND ', $where );
        $prepare[]    = absint( $args['limit'] );
        $prepare[]    = absint( $args['offset'] );

        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $prepare
        ) );
    }

    public function get_lead( $lead_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}smart_chat_leads WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            absint( $lead_id )
        ) );
    }

    public function get_count( $status = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_leads';

        if ( $status ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function update_status( $lead_id, $status ) {
        global $wpdb;
        return $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'smart_chat_leads',
            array( 'status' => sanitize_text_field( $status ) ),
            array( 'id' => absint( $lead_id ) )
        );
    }

    public function delete_lead( $lead_id ) {
        global $wpdb;
        return $wpdb->delete( $wpdb->prefix . 'smart_chat_leads', array( 'id' => absint( $lead_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
}
