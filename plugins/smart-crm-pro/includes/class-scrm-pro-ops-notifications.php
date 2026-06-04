<?php
/**
 * Ops notifications — actionable emails to the Midland team on each
 * journey event so they can act without logging into WP.
 *
 * Recipients default to justin@midlandfloors.com and
 * support@midlandfloors.com. Override via the
 * scrm_pro_ops_notification_recipients filter if needed.
 *
 * Events:
 *   1. Form submission with lead_intent=request_call    → "CALL REQUEST"
 *   2. Form submission with lead_intent=request_visit   → "VISIT REQUEST"
 *   3. Form submission with lead_intent=emergency       → "EMERGENCY"
 *   4. ServiceM8 opens a job  (scrm_pro_job_created)    → "SM8 Job Opened"
 *   5. ServiceM8 closes a job (scrm_pro_job_completed)  → "Job Completed"
 *
 * Email transport is whatever wp_mail is configured to use — on Midland
 * that's Resend via Smart Forms' pre_wp_mail filter.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Ops_Notifications {

    const DEFAULT_RECIPIENTS = array(
        'justin@midlandfloors.com',
        'support@midlandfloors.com',
    );

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Priority 70 so all earlier listeners (CRM bridge, AC, GCal, SM8
        // push, Vapi) have run first and stamped any fields we want to
        // surface in the notification body.
        add_action( 'sfco_lead_submitted',   array( $this, 'on_lead_submitted' ), 70, 3 );

        // Journey lifecycle events from the SM8 bridge. Listening on the
        // scrm_pro_* actions specifically (not sfco_*) so we get a single
        // notification per transition — mark_lead_completed fires three
        // aliased actions and these are the canonical ones.
        add_action( 'scrm_pro_job_created',   array( $this, 'on_job_created' ), 10, 1 );
        add_action( 'scrm_pro_job_completed', array( $this, 'on_job_completed' ), 10, 1 );
    }

    /**
     * Branch by lead_intent so the operator's inbox shows the right
     * call-to-action subject line. Anything other than the three
     * actionable intents is skipped — researching / future-project leads
     * don't need an immediate ops alert.
     */
    public function on_lead_submitted( $lead_id, $row, $form ) {
        if ( ! is_array( $row ) ) {
            return;
        }
        $intent = strtolower( (string) ( $row['lead_intent'] ?? '' ) );

        switch ( $intent ) {
            case 'request_call':
                $this->send_call_request( $lead_id, $row, $form );
                break;
            case 'request_visit':
                $this->send_visit_request( $lead_id, $row, $form );
                break;
            case 'emergency':
                $this->send_emergency_alert( $lead_id, $row, $form );
                break;
            default:
                return;
        }
    }

    private function send_call_request( $lead_id, $row, $form ) {
        $name    = (string) ( $row['customer_name'] ?? '' );
        $phone   = (string) ( $row['customer_phone'] ?? '' );
        $subject = sprintf( '[Midland CRM] CALL REQUEST — %s (%s)', $name ?: 'unknown', $phone ?: 'no phone' );

        $body = sprintf(
            "%s just submitted the residential quote form asking for a CALL.\n\nPhone: %s\nEmail: %s\nService: %s\nZIP: %s\nNotes: %s\n\nA Vapi AI call has been queued to their phone. If Vapi fails to connect, call manually within 15 minutes.\n\nView lead: %s",
            $name ?: 'A lead',
            $phone ?: '—',
            (string) ( $row['customer_email'] ?? '—' ),
            (string) ( $row['project_type'] ?? '—' ),
            (string) ( $row['zip_code'] ?? '—' ),
            (string) ( $row['additional_notes'] ?? '—' ),
            $this->entry_url( $lead_id, $row )
        );

        $this->send( $subject, $body, $row );
    }

    private function send_visit_request( $lead_id, $row, $form ) {
        $name    = (string) ( $row['customer_name'] ?? '' );
        $phone   = (string) ( $row['customer_phone'] ?? '' );
        $segment = ( strtolower( (string) ( $row['property_type'] ?? '' ) ) === 'commercial' ) ? 'Commercial' : 'Residential';
        $subject = sprintf( '[Midland CRM] VISIT REQUEST — %s (%s)', $name ?: 'unknown', $phone ?: 'no phone' );

        $body = sprintf(
            "%s just submitted the form asking for an ON-SITE VISIT.\n\nPhone: %s\nEmail: %s\nProperty: %s / %s\nService: %s\nZIP: %s\nNotes: %s\n\nA tentative visit has been drafted on Google Calendar for the next business morning at 10:00. A matching JobActivity has been pushed to ServiceM8 if the lead already has a job_id. Confirm the actual date/time with the customer.\n\nView lead: %s",
            $name ?: 'A lead',
            $phone ?: '—',
            (string) ( $row['customer_email'] ?? '—' ),
            $segment,
            (string) ( $row['property_subtype'] ?? '—' ),
            (string) ( $row['project_type'] ?? '—' ),
            (string) ( $row['zip_code'] ?? '—' ),
            (string) ( $row['additional_notes'] ?? '—' ),
            $this->entry_url( $lead_id, $row )
        );

        $this->send( $subject, $body, $row );
    }

    private function send_emergency_alert( $lead_id, $row, $form ) {
        $name    = (string) ( $row['customer_name'] ?? '' );
        $phone   = (string) ( $row['customer_phone'] ?? '' );
        $segment = ( strtolower( (string) ( $row['property_type'] ?? '' ) ) === 'commercial' ) ? 'Commercial' : 'Residential';
        $subject = sprintf( '[Midland CRM] EMERGENCY — %s (%s) — call ASAP', $name ?: 'unknown', $phone ?: 'no phone' );

        $body = sprintf(
            "EMERGENCY LEAD — call %s NOW.\n\nName: %s\nEmail: %s\nProperty: %s / %s\nService: %s\nZIP: %s\nNotes: %s\n\nView lead: %s",
            $phone ?: '(no phone provided)',
            $name ?: '—',
            (string) ( $row['customer_email'] ?? '—' ),
            $segment,
            (string) ( $row['property_subtype'] ?? '—' ),
            (string) ( $row['project_type'] ?? '—' ),
            (string) ( $row['zip_code'] ?? '—' ),
            (string) ( $row['additional_notes'] ?? '—' ),
            $this->entry_url( $lead_id, $row )
        );

        $this->send( $subject, $body, $row );
    }

    public function on_job_created( $lead ) {
        $name    = (string) $this->lead_field( $lead, 'customer_name' );
        $email   = (string) $this->lead_field( $lead, 'customer_email' );
        $job_id  = (string) $this->lead_field( $lead, 'job_id' );
        $subject = sprintf( '[Midland CRM] SM8 Job Opened — %s', $name ?: $email ?: 'unknown' );

        $body = sprintf(
            "ServiceM8 has opened a job for %s.\n\nEmail: %s\nPhone: %s\nSM8 Job ID: %s\nService: %s\nZIP: %s\n\nThe contact has been pushed to ActiveCampaign with the 'booked' lifecycle tag and the deal stage advanced. The Smart Reviews NPS survey will fire automatically when SM8 marks this job complete.",
            $name ?: '(no name)',
            $email ?: '—',
            (string) $this->lead_field( $lead, 'customer_phone' ) ?: '—',
            $job_id ?: '—',
            (string) $this->lead_field( $lead, 'project_type' ) ?: '—',
            (string) $this->lead_field( $lead, 'zip_code' ) ?: '—'
        );

        $this->send( $subject, $body, $this->lead_as_row( $lead ) );
    }

    public function on_job_completed( $lead ) {
        $name    = (string) $this->lead_field( $lead, 'customer_name' );
        $email   = (string) $this->lead_field( $lead, 'customer_email' );
        $job_id  = (string) $this->lead_field( $lead, 'job_id' );
        $subject = sprintf( '[Midland CRM] Job Completed — %s', $name ?: $email ?: 'unknown' );

        $segment      = 'residential';
        $is_emergency = false;
        if ( class_exists( 'SCRM_Pro_ActiveCampaign' ) ) {
            $ac           = SCRM_Pro_ActiveCampaign::get_instance();
            $segment      = $ac->lead_segment( $lead );
            $is_emergency = $ac->is_emergency( $lead );
        }

        $extra = '';
        if ( 'commercial' === $segment ) {
            $extra .= "\n- Floor Care Plan generated; ActiveCampaign will send it via the floor-care-plan-offer automation";
            if ( $is_emergency ) {
                $extra .= " (emergency-variant template)";
            }
            $extra .= '.';
        }

        $body = sprintf(
            "ServiceM8 has marked the job complete for %s.\n\nEmail: %s\nSM8 Job ID: %s\nService: %s\nProperty segment: %s%s\n\nNext steps that fired automatically:\n- Smart Reviews NPS survey emailed to the customer\n- ActiveCampaign updated with the 'completed' lifecycle tag%s",
            $name ?: '(no name)',
            $email ?: '—',
            $job_id ?: '—',
            (string) $this->lead_field( $lead, 'project_type' ) ?: '—',
            ucfirst( $segment ),
            $is_emergency ? ' (emergency)' : '',
            $extra
        );

        $this->send( $subject, $body, $this->lead_as_row( $lead ) );
    }

    /**
     * Resolve recipients (defaults + filter) and send. Reply-To is set to
     * the lead's email when we have one so a quick reply lands with the
     * customer instead of the WP admin email.
     */
    private function send( $subject, $body, $row ) {
        $recipients = apply_filters( 'scrm_pro_ops_notification_recipients', self::DEFAULT_RECIPIENTS );
        $recipients = array_filter( array_map( 'sanitize_email', (array) $recipients ) );
        if ( empty( $recipients ) ) {
            return;
        }

        $headers = array();
        $customer_email = sanitize_email( (string) ( $row['customer_email'] ?? '' ) );
        if ( is_email( $customer_email ) ) {
            $headers[] = 'Reply-To: ' . $customer_email;
        }
        $headers[] = 'From: Midland CRM <support@midlandfloors.com>';

        wp_mail( $recipients, $subject, $body, $headers );
    }

    private function entry_url( $lead_id, $row ) {
        return admin_url(
            'admin.php?page=smart-forms-form-entries&form_id=' . absint( $row['form_id'] ?? 0 ) . '&lead_id=' . absint( $lead_id )
        );
    }

    private function lead_field( $lead, $key ) {
        if ( is_array( $lead ) ) {
            return $lead[ $key ] ?? '';
        }
        if ( is_object( $lead ) ) {
            return $lead->$key ?? '';
        }
        return '';
    }

    private function lead_as_row( $lead ) {
        if ( is_array( $lead ) ) {
            return $lead;
        }
        if ( is_object( $lead ) ) {
            return get_object_vars( $lead );
        }
        return array();
    }
}

SCRM_Pro_Ops_Notifications::get_instance();
