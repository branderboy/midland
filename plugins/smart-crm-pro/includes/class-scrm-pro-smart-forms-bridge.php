<?php
/**
 * Smart Forms → Midland Smart CRM Lite bridge.
 *
 * Listens to the `sfco_lead_submitted` action fired by Midland Smart Forms
 * after every form submission and turns the raw lead into a CRM-ready
 * contact:
 *
 *   1. Computes a priority bucket (Hot / Warm / Cool) from form data
 *      (emergency service, timeline, commercial vs residential, square
 *      footage, package interest).
 *   2. Detects an area bucket (DC / MD / VA / WV / Other) from the ZIP.
 *   3. Schedules a follow-up reminder due-at timestamp tied to the
 *      priority bucket (Hot = +1 day, Warm = +3 days, Cool = +7 days).
 *   4. Stamps priority + area + reminder_due_at back onto the
 *      sfco_leads row so the Smart Forms entries view + CRM dashboards
 *      can sort/filter on them.
 *   5. Pushes the contact to ActiveCampaign with the priority + area
 *      tags via the existing SCRM_Pro_ActiveCampaign module.
 *
 * No SMTP anywhere. Email goes out via Resend (configured in Midland
 * Smart Forms → Pro → Resend). SMTP is unreliable and we don't use it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SCRM_Pro_Smart_Forms_Bridge {

    const PRIORITY_HOT  = 'Hot';
    const PRIORITY_WARM = 'Warm';
    const PRIORITY_COOL = 'Cool';

    public static function register() {
        add_action( 'sfco_lead_submitted',                   array( __CLASS__, 'on_lead_submitted' ), 10, 3 );
        add_action( 'scrm_pro_follow_up_reminder',           array( __CLASS__, 'fire_reminder' ),    10, 1 );
    }

    /**
     * Main entrypoint — fired by Midland Smart Forms after each form save.
     *
     * @param int    $lead_id  Newly inserted sfco_leads row ID.
     * @param array  $row      The data that was inserted.
     * @param object $form     The sfco_forms row for the parent form (or null).
     */
    public static function on_lead_submitted( $lead_id, $row, $form ) {
        if ( ! $lead_id ) return;

        $priority      = self::score_priority( $row, $form );
        $area          = self::detect_area( $row );
        $reminder_at   = self::reminder_due_at( $priority );

        // Stamp the lead with priority/area/reminder so dashboards can sort.
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        $update = array(
            'priority'        => $priority,
            'area'            => $area,
            'reminder_due_at' => $reminder_at,
        );
        // Some installs may not have the new columns yet (older sfco_leads
        // table on a partial upgrade). Catch + skip silently — the AC push
        // still fires regardless.
        $wpdb->update( $table, $update, array( 'id' => (int) $lead_id ), array( '%s', '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB

        // Schedule a one-shot reminder cron at the due time. WP cron will
        // fire scrm_pro_follow_up_reminder with the lead_id. fire_reminder()
        // sends the operator notification via wp_mail (which is routed
        // through Resend by Midland Smart Forms — no SMTP fallback).
        $ts = strtotime( $reminder_at );
        if ( $ts && $ts > time() ) {
            wp_schedule_single_event( $ts, 'scrm_pro_follow_up_reminder', array( (int) $lead_id ) );
        }

        // Push to ActiveCampaign with priority + area tags. We rebuild the
        // tag list to include our priority + area, then call the existing
        // AC push pipeline via a lightweight wrapper.
        if ( class_exists( 'SCRM_Pro_ActiveCampaign' ) ) {
            $ac_lead = array_merge( (array) $row, array(
                'id'              => (int) $lead_id,
                'priority'        => $priority,
                'area'            => $area,
                'reminder_due_at' => $reminder_at,
            ) );
            /**
             * Lets AC observe the bridge-enriched lead. Existing SCRM_Pro_AC
             * code already has on_chat_lead_captured handling a similar shape.
             */
            do_action( 'scrm_pro_smart_forms_lead', $ac_lead, $priority, $area );
        }
    }

    /**
     * Priority scoring — biggest signal is emergency + commercial.
     * Score buckets: 50+ Hot, 25-49 Warm, <25 Cool.
     */
    public static function score_priority( $row, $form ) {
        $score = 0;
        $get   = function( $k ) use ( $row ) { return isset( $row[ $k ] ) ? (string) $row[ $k ] : ''; };

        // Hard-stop signals
        if ( 'Yes' === $get( 'emergency_service' ) )    $score += 30;
        if ( 'ASAP' === $get( 'timeline' ) )            $score += 25;
        elseif ( 'This Week' === $get( 'timeline' ) )    $score += 15;
        elseif ( 'Within 2 weeks' === $get( 'timeline' ) ) $score += 10;

        // Commercial-shape signals
        if ( '' !== $get( 'business_name' ) )            $score += 10;
        if ( 'Yes' === $get( 'multiple_locations' ) )    $score += 15;
        if ( in_array( $get( 'package_interest' ), array( 'Premium', 'Enterprise' ), true ) ) $score += 15;

        // Size signal
        $sqft = (int) $get( 'square_footage' );
        if ( $sqft >= 5000 )      $score += 12;
        elseif ( $sqft >= 2000 )  $score += 8;
        elseif ( $sqft >= 500 )   $score += 4;

        // Convenience signals
        if ( 'Yes' === $get( 'schedule_site_visit' ) )   $score += 5;
        if ( '' !== $get( 'customer_phone' ) )           $score += 5;

        // Form-level: schedule-a-visit form is high-intent by definition
        if ( $form && isset( $form->slug ) && 'schedule-a-visit' === $form->slug ) {
            $score += 10;
        }

        if ( $score >= 50 ) return self::PRIORITY_HOT;
        if ( $score >= 25 ) return self::PRIORITY_WARM;
        return self::PRIORITY_COOL;
    }

    /**
     * ZIP → area bucket. ZIP ranges cover the DMV; everything else is "Other".
     */
    public static function detect_area( $row ) {
        $zip = '';
        foreach ( array( 'zip_code', 'zip', 'address_zip' ) as $k ) {
            if ( ! empty( $row[ $k ] ) ) { $zip = (string) $row[ $k ]; break; }
        }
        if ( ! preg_match( '/^(\d{5})/', $zip, $m ) ) return 'Unknown';
        $z = (int) $m[1];
        if ( $z >= 20001 && $z <= 20599 ) return 'DC';
        if ( $z >= 20600 && $z <= 21999 ) return 'MD';
        if ( $z >= 22000 && $z <= 24699 ) return 'VA';
        if ( $z >= 24700 && $z <= 26999 ) return 'WV';
        return 'Other';
    }

    /**
     * Reminder due-at MySQL datetime tied to the priority bucket.
     */
    public static function reminder_due_at( $priority ) {
        $hours = self::PRIORITY_HOT  === $priority ? 24
              : ( self::PRIORITY_WARM === $priority ? 72
              : 168 ); // Cool = 7 days
        return gmdate( 'Y-m-d H:i:s', time() + ( $hours * HOUR_IN_SECONDS ) );
    }

    /**
     * WP cron callback for the scheduled follow-up reminder. Emails the
     * operator via wp_mail() — Midland Smart Forms routes wp_mail() through
     * Resend, so no SMTP server is involved.
     */
    public static function fire_reminder( $lead_id ) {
        $lead_id = (int) $lead_id;
        if ( ! $lead_id ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        $lead  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $lead_id ) ); // phpcs:ignore
        if ( ! $lead ) return;

        $to      = get_option( 'admin_email' );
        $subject = sprintf( '[Midland CRM] Follow-up due — %s lead (%s)', $lead->priority ?? 'Cool', $lead->area ?? 'Unknown' );
        $body    = sprintf(
            "Lead #%d needs follow-up.\n\nName: %s\nEmail: %s\nPhone: %s\nService: %s\nPriority: %s\nArea: %s\nSubmitted: %s\n\nReview: %s\n",
            $lead->id,
            $lead->customer_name ?? '',
            $lead->customer_email ?? '',
            $lead->customer_phone ?? '',
            $lead->project_type ?? '',
            $lead->priority ?? 'Cool',
            $lead->area ?? 'Unknown',
            $lead->created_at ?? '',
            admin_url( 'admin.php?page=smart-forms-form-entries&form_id=' . (int) ( $lead->form_id ?? 1 ) )
        );

        wp_mail( $to, $subject, $body );
    }
}

SCRM_Pro_Smart_Forms_Bridge::register();
