<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Reactivation_Engine {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $campaigns = $wpdb->prefix . 'scrm_campaigns';
        $queue     = $wpdb->prefix . 'scrm_campaign_queue';

        $sql = "CREATE TABLE {$campaigns} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            segment varchar(100) NOT NULL,
            status varchar(20) DEFAULT 'draft',
            email_subject varchar(255) NOT NULL,
            email_body longtext NOT NULL,
            follow_up_subject varchar(255) DEFAULT NULL,
            follow_up_body longtext DEFAULT NULL,
            follow_up_delay int(11) DEFAULT 3,
            filter_min_days int(11) DEFAULT 30,
            filter_max_days int(11) DEFAULT 365,
            filter_status text DEFAULT NULL,
            filter_project_type text DEFAULT NULL,
            filter_min_estimate decimal(10,2) DEFAULT NULL,
            leads_targeted int(11) DEFAULT 0,
            leads_opened int(11) DEFAULT 0,
            leads_replied int(11) DEFAULT 0,
            leads_reactivated int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY segment (segment)
        ) {$charset};

        CREATE TABLE {$queue} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            lead_id bigint(20) unsigned NOT NULL,
            email varchar(255) NOT NULL,
            step varchar(20) DEFAULT 'initial',
            status varchar(20) DEFAULT 'pending',
            sent_at datetime DEFAULT NULL,
            opened_at datetime DEFAULT NULL,
            replied_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY lead_id (lead_id),
            KEY status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'scrm_pro_version', SCRM_PRO_VERSION );
    }

    /**
     * Find cold/dead leads from the sfco_leads table.
     */
    public static function find_cold_leads( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';

        $defaults = array(
            'min_days'     => 30,
            'max_days'     => 365,
            'status'       => array( 'new', 'contacted', 'quoted', 'lost' ),
            'project_type' => '',
            'min_estimate' => 0,
            'limit'        => 100,
        );
        $args = wp_parse_args( $args, $defaults );

        $where   = array( '1=1' );
        $prepare = array();

        // Age filter.
        $where[]   = 'created_at < DATE_SUB(NOW(), INTERVAL %d DAY)';
        $prepare[] = absint( $args['min_days'] );

        $where[]   = 'created_at > DATE_SUB(NOW(), INTERVAL %d DAY)';
        $prepare[] = absint( $args['max_days'] );

        // Status filter.
        if ( ! empty( $args['status'] ) && is_array( $args['status'] ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
            $where[]      = "status IN ({$placeholders})";
            foreach ( $args['status'] as $s ) {
                $prepare[] = sanitize_text_field( $s );
            }
        }

        // Project type filter.
        if ( ! empty( $args['project_type'] ) ) {
            $where[]   = 'project_type = %s';
            $prepare[] = sanitize_text_field( $args['project_type'] );
        }

        // Minimum estimate filter.
        if ( $args['min_estimate'] > 0 ) {
            $where[]   = 'estimated_cost_max >= %f';
            $prepare[] = floatval( $args['min_estimate'] );
        }

        $where_clause = implode( ' AND ', $where );
        $prepare[]    = absint( $args['limit'] );

        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY estimated_cost_max DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $prepare
        ) );
    }

    /**
     * Score a lead's reactivation potential (0-100).
     */
    public static function score_reactivation( $lead ) {
        $score = 50; // Base score.

        // Higher estimate = higher value to reactivate.
        $max_cost = floatval( $lead->estimated_cost_max ?? 0 );
        if ( $max_cost >= 10000 ) {
            $score += 25;
        } elseif ( $max_cost >= 5000 ) {
            $score += 20;
        } elseif ( $max_cost >= 2500 ) {
            $score += 15;
        } elseif ( $max_cost >= 1000 ) {
            $score += 10;
        }

        // Status scoring.
        $status = $lead->status ?? '';
        if ( 'quoted' === $status ) {
            $score += 15; // Was quoted but didn't close — high potential.
        } elseif ( 'contacted' === $status ) {
            $score += 10;
        } elseif ( 'lost' === $status ) {
            $score += 5;
        }

        // Recency scoring - newer cold leads are easier to reactivate.
        $days_old = 0;
        if ( ! empty( $lead->created_at ) ) {
            $days_old = ( time() - strtotime( $lead->created_at ) ) / DAY_IN_SECONDS;
        }
        if ( $days_old < 60 ) {
            $score += 10;
        } elseif ( $days_old < 120 ) {
            $score += 5;
        } elseif ( $days_old > 270 ) {
            $score -= 10;
        }

        return max( 0, min( 100, $score ) );
    }

    /**
     * Segment leads into buckets.
     */
    public static function segment_leads( $leads ) {
        $segments = array(
            'high_value_quoted' => array(),
            'recent_cold'       => array(),
            'lost_winback'      => array(),
            'aging_leads'       => array(),
        );

        foreach ( $leads as $lead ) {
            $max_cost = floatval( $lead->estimated_cost_max ?? 0 );
            $days_old = ! empty( $lead->created_at ) ? ( time() - strtotime( $lead->created_at ) ) / DAY_IN_SECONDS : 999;

            if ( 'quoted' === $lead->status && $max_cost >= 2500 ) {
                $segments['high_value_quoted'][] = $lead;
            } elseif ( $days_old < 90 ) {
                $segments['recent_cold'][] = $lead;
            } elseif ( 'lost' === $lead->status ) {
                $segments['lost_winback'][] = $lead;
            } else {
                $segments['aging_leads'][] = $lead;
            }
        }

        return $segments;
    }

    /**
     * Daily cron: auto-detect newly cold leads and flag them.
     */
    public static function daily_cold_lead_scan() {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';

        // Find leads that just went cold (30+ days, status still new/contacted).
        $newly_cold = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$table}
             WHERE status IN ('new', 'contacted')
             AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
             AND created_at > DATE_SUB(NOW(), INTERVAL 31 DAY)
             LIMIT 50" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        if ( ! empty( $newly_cold ) ) {
            $admin_email = get_option( 'admin_email' );
            $count       = count( $newly_cold );

            wp_mail(
                $admin_email,
                sprintf(
                    /* translators: %d: number of cold leads */
                    __( 'Smart CRM PRO: %d leads just went cold', 'smart-crm-pro' ),
                    $count
                ),
                sprintf(
                    /* translators: %d: number of cold leads */
                    __( "You have %d leads that are now 30+ days old without a response.\n\nGo to Smart CRM PRO > Reactivation to create a win-back campaign.\n\n%s", 'smart-crm-pro' ),
                    $count,
                    admin_url( 'admin.php?page=scrm-reactivation' )
                )
            );
        }
    }
}
