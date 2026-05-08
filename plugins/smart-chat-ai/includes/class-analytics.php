<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCAI_Analytics {

    public function get_stats( $days = 30 ) {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'smart_chat_leads';
        $convos_table = $wpdb->prefix . 'smart_chat_conversations';

        $start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        $total_leads = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$leads_table} WHERE created_at >= %s", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $total_conversations = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$convos_table} WHERE created_at >= %s", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $total_messages = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$convos_table} WHERE created_at >= %s", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $total_tokens = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_used), 0) FROM {$convos_table} WHERE created_at >= %s AND sender = 'ai'", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $leads_by_status = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) as cnt FROM {$leads_table} WHERE created_at >= %s GROUP BY status", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return array(
            'total_leads'         => $total_leads,
            'total_conversations' => $total_conversations,
            'total_messages'      => $total_messages,
            'total_tokens'        => $total_tokens,
            'leads_by_status'     => $leads_by_status,
        );
    }
}
