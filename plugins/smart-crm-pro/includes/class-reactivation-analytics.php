<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Analytics {

    public static function get_overview() {
        global $wpdb;
        $leads_table     = $wpdb->prefix . 'sfco_leads';
        $campaigns_table = $wpdb->prefix . 'scrm_campaigns';
        $queue_table     = $wpdb->prefix . 'scrm_campaign_queue';

        // Total cold leads (30+ days, not won).
        $total_cold = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM {$leads_table} WHERE status NOT IN ('won') AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        // Cold lead value (sum of estimates).
        $cold_value = floatval( $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COALESCE(SUM(estimated_cost_max), 0) FROM {$leads_table} WHERE status NOT IN ('won') AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );

        // Total campaigns.
        $total_campaigns = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$campaigns_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Emails sent.
        $emails_sent = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status = 'sent'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Reactivated (leads that changed from cold status to 'contacted'/'quoted'/'won' after campaign).
        $reactivated = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(DISTINCT q.lead_id) FROM {$queue_table} q
             JOIN {$leads_table} l ON q.lead_id = l.id
             WHERE q.status = 'sent' AND l.status IN ('contacted', 'quoted', 'won')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        // Revenue recovered (won leads that were in campaigns).
        $revenue = floatval( $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COALESCE(SUM(l.estimated_cost_max), 0) FROM {$queue_table} q
             JOIN {$leads_table} l ON q.lead_id = l.id
             WHERE q.status = 'sent' AND l.status = 'won'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );

        $reactivation_rate = $emails_sent > 0 ? round( ( $reactivated / $emails_sent ) * 100, 1 ) : 0;

        return array(
            'total_cold'        => $total_cold,
            'cold_value'        => $cold_value,
            'total_campaigns'   => $total_campaigns,
            'emails_sent'       => $emails_sent,
            'reactivated'       => $reactivated,
            'reactivation_rate' => $reactivation_rate,
            'revenue_recovered' => $revenue,
        );
    }

    /**
     * Cold leads by segment.
     */
    public static function get_segment_breakdown() {
        $cold_leads = SCRM_Pro_Reactivation_Engine::find_cold_leads( array( 'limit' => 1000 ) );
        $segments   = SCRM_Pro_Reactivation_Engine::segment_leads( $cold_leads );

        $breakdown = array();
        foreach ( $segments as $key => $leads ) {
            $total_value = 0;
            foreach ( $leads as $lead ) {
                $total_value += floatval( $lead->estimated_cost_max ?? 0 );
            }
            $breakdown[ $key ] = array(
                'count' => count( $leads ),
                'value' => $total_value,
            );
        }

        return $breakdown;
    }
}
