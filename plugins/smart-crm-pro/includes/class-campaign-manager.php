<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Campaign_Manager {

    /**
     * Create a new reactivation campaign.
     */
    public static function create_campaign( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'scrm_campaigns';

        $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'name'               => sanitize_text_field( $data['name'] ),
            'segment'            => sanitize_key( $data['segment'] ),
            'email_subject'      => sanitize_text_field( $data['email_subject'] ),
            'email_body'         => wp_kses_post( $data['email_body'] ),
            'follow_up_subject'  => sanitize_text_field( $data['follow_up_subject'] ?? '' ),
            'follow_up_body'     => wp_kses_post( $data['follow_up_body'] ?? '' ),
            'follow_up_delay'    => absint( $data['follow_up_delay'] ?? 3 ),
            'filter_min_days'    => absint( $data['filter_min_days'] ?? 30 ),
            'filter_max_days'    => absint( $data['filter_max_days'] ?? 365 ),
            'filter_status'      => sanitize_text_field( $data['filter_status'] ?? '' ),
            'filter_project_type' => sanitize_text_field( $data['filter_project_type'] ?? '' ),
            'filter_min_estimate' => floatval( $data['filter_min_estimate'] ?? 0 ),
            'status'             => 'draft',
            'created_at'         => current_time( 'mysql' ),
        ) );

        return $wpdb->insert_id;
    }

    /**
     * Launch a campaign: find matching leads, queue emails, schedule sends.
     */
    public static function launch_campaign( $campaign_id ) {
        global $wpdb;
        $campaigns_table = $wpdb->prefix . 'scrm_campaigns';
        $queue_table     = $wpdb->prefix . 'scrm_campaign_queue';

        $campaign = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$campaigns_table} WHERE id = %d", absint( $campaign_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );

        if ( ! $campaign || 'draft' !== $campaign->status ) {
            return false;
        }

        // Find matching cold leads.
        $statuses = ! empty( $campaign->filter_status ) ? explode( ',', $campaign->filter_status ) : array( 'new', 'contacted', 'quoted', 'lost' );

        $leads = SCRM_Pro_Reactivation_Engine::find_cold_leads( array(
            'min_days'     => $campaign->filter_min_days,
            'max_days'     => $campaign->filter_max_days,
            'status'       => $statuses,
            'project_type' => $campaign->filter_project_type,
            'min_estimate' => $campaign->filter_min_estimate,
            'limit'        => 500,
        ) );

        if ( empty( $leads ) ) {
            return 0;
        }

        // Queue initial emails.
        $queued = 0;
        foreach ( $leads as $lead ) {
            if ( empty( $lead->customer_email ) ) {
                continue;
            }

            // Don't re-queue leads already in this campaign.
            $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT id FROM {$queue_table} WHERE campaign_id = %d AND lead_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $campaign_id,
                $lead->id
            ) );
            if ( $exists ) {
                continue;
            }

            $wpdb->insert( $queue_table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                'campaign_id' => $campaign_id,
                'lead_id'     => $lead->id,
                'email'       => sanitize_email( $lead->customer_email ),
                'step'        => 'initial',
                'status'      => 'pending',
            ) );

            // Schedule the send (stagger by 30 seconds per lead to avoid spam).
            wp_schedule_single_event(
                time() + ( $queued * 30 ),
                'scrm_pro_send_campaign_email',
                array( $wpdb->insert_id, 'initial' )
            );

            $queued++;
        }

        // Update campaign.
        $wpdb->update( $campaigns_table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'status'         => 'active',
            'leads_targeted' => $queued,
            'sent_at'        => current_time( 'mysql' ),
        ), array( 'id' => $campaign_id ) );

        return $queued;
    }

    /**
     * Send a single campaign email (called by cron).
     */
    public static function send_scheduled_email( $queue_id, $step ) {
        global $wpdb;
        $queue_table     = $wpdb->prefix . 'scrm_campaign_queue';
        $campaigns_table = $wpdb->prefix . 'scrm_campaigns';
        $leads_table     = $wpdb->prefix . 'sfco_leads';

        $queue_item = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$queue_table} WHERE id = %d", absint( $queue_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );

        if ( ! $queue_item || 'pending' !== $queue_item->status ) {
            return;
        }

        $campaign = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$campaigns_table} WHERE id = %d", $queue_item->campaign_id // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );

        $lead = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$leads_table} WHERE id = %d", $queue_item->lead_id // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );

        if ( ! $campaign || ! $lead ) {
            return;
        }

        // Pick subject/body based on step.
        $subject = 'initial' === $step ? $campaign->email_subject : $campaign->follow_up_subject;
        $body    = 'initial' === $step ? $campaign->email_body : $campaign->follow_up_body;

        // Replace merge tags.
        $tags = array(
            '{name}'         => $lead->customer_name ?? '',
            '{email}'        => $lead->customer_email ?? '',
            '{phone}'        => $lead->customer_phone ?? '',
            '{project_type}' => $lead->project_type ?? '',
            '{timeline}'     => $lead->timeline ?? '',
            '{estimate}'     => '$' . number_format( floatval( $lead->estimated_cost_max ?? 0 ) ),
            '{business}'     => get_bloginfo( 'name' ),
        );

        $subject = str_replace( array_keys( $tags ), array_values( $tags ), $subject );
        $body    = str_replace( array_keys( $tags ), array_values( $tags ), $body );

        $sent = wp_mail( $queue_item->email, $subject, $body );

        // Update queue.
        $wpdb->update( $queue_table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'status'  => $sent ? 'sent' : 'failed',
            'sent_at' => current_time( 'mysql' ),
        ), array( 'id' => $queue_id ) );

        // Schedule follow-up if initial was sent and follow-up exists.
        if ( $sent && 'initial' === $step && ! empty( $campaign->follow_up_subject ) ) {
            $delay_seconds = absint( $campaign->follow_up_delay ) * DAY_IN_SECONDS;

            // Create follow-up queue entry.
            $wpdb->insert( $queue_table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                'campaign_id' => $campaign->id,
                'lead_id'     => $lead->id,
                'email'       => $queue_item->email,
                'step'        => 'follow_up',
                'status'      => 'pending',
            ) );

            wp_schedule_single_event(
                time() + $delay_seconds,
                'scrm_pro_send_campaign_email',
                array( $wpdb->insert_id, 'follow_up' )
            );
        }
    }

    /**
     * Get all campaigns.
     */
    public static function get_campaigns() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}scrm_campaigns ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Get campaign with stats.
     */
    public static function get_campaign( $id ) {
        global $wpdb;
        $campaign = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}scrm_campaigns WHERE id = %d", absint( $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );

        if ( $campaign ) {
            $queue_table = $wpdb->prefix . 'scrm_campaign_queue';
            $campaign->sent_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d AND status = 'sent'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $campaign->pending_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d AND status = 'pending'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $campaign->failed_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d AND status = 'failed'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return $campaign;
    }

    /**
     * Delete campaign + queue.
     */
    public static function delete_campaign( $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'scrm_campaign_queue', array( 'campaign_id' => absint( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $wpdb->prefix . 'scrm_campaigns', array( 'id' => absint( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Get default campaign templates per segment.
     */
    public static function get_segment_templates() {
        return array(
            'high_value_quoted' => array(
                'name'              => __( 'Win Back: High-Value Quoted Leads', 'smart-crm-pro' ),
                'email_subject'     => __( 'Still thinking about your {project_type} project?', 'smart-crm-pro' ),
                'email_body'        => __( "Hi {name},\n\nA while back you requested a quote for {project_type} — estimated around {estimate}.\n\nWe wanted to check if you're still considering this project. We have availability coming up and can offer priority scheduling.\n\nReply to this email or call us to get started.\n\nBest,\n{business}", 'smart-crm-pro' ),
                'follow_up_subject' => __( 'Quick follow-up on your {project_type} quote', 'smart-crm-pro' ),
                'follow_up_body'    => __( "Hi {name},\n\nJust following up one more time about your {project_type} project. Our schedule is filling up — if you're ready to move forward, now is a great time.\n\nNo pressure — just let us know.\n\nThanks,\n{business}", 'smart-crm-pro' ),
                'follow_up_delay'   => 5,
            ),
            'recent_cold' => array(
                'name'              => __( 'Re-Engage: Recent Cold Leads', 'smart-crm-pro' ),
                'email_subject'     => __( 'Did we miss your call? Re: {project_type}', 'smart-crm-pro' ),
                'email_body'        => __( "Hi {name},\n\nYou reached out about {project_type} a few weeks ago and we may not have connected. Sorry about that.\n\nWe'd still love to help. Reply with a good time to call, or just hit reply with any questions.\n\nThanks,\n{business}", 'smart-crm-pro' ),
                'follow_up_subject' => __( 'One more try — {business} here', 'smart-crm-pro' ),
                'follow_up_body'    => __( "Hi {name},\n\nLast try from us — we don't want to be a bother. If you're still interested in {project_type}, we're here.\n\nThanks,\n{business}", 'smart-crm-pro' ),
                'follow_up_delay'   => 3,
            ),
            'lost_winback' => array(
                'name'              => __( 'Win Back: Lost Leads', 'smart-crm-pro' ),
                'email_subject'     => __( 'Things change — so do our prices. Re: {project_type}', 'smart-crm-pro' ),
                'email_body'        => __( "Hi {name},\n\nWe understand you went another direction for your {project_type} project. No hard feelings.\n\nBut if things didn't work out, or if you have a new project coming up, we'd love another chance.\n\nReply anytime.\n\n{business}", 'smart-crm-pro' ),
                'follow_up_subject' => '',
                'follow_up_body'    => '',
                'follow_up_delay'   => 0,
            ),
            'aging_leads' => array(
                'name'              => __( 'Reconnect: Aging Database Leads', 'smart-crm-pro' ),
                'email_subject'     => __( 'It\'s been a while — {business} checking in', 'smart-crm-pro' ),
                'email_body'        => __( "Hi {name},\n\nIt's been a while since you inquired about {project_type}. We're still here and would love to help if you have any upcoming projects.\n\nFeel free to reply or call us anytime.\n\nThanks,\n{business}", 'smart-crm-pro' ),
                'follow_up_subject' => '',
                'follow_up_body'    => '',
                'follow_up_delay'   => 0,
            ),
        );
    }
}
