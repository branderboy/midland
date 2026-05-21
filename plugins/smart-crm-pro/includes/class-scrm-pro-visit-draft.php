<?php
/**
 * Tentative Google Calendar event on form submission.
 *
 * The "next step" after a residential / commercial lead submits the
 * quote form: drop a placeholder visit on the operator's Google
 * Calendar, marked tentative, so the team knows to call and confirm a
 * real time. The customer doesn't get an invite email until the op
 * flips status to confirmed in Google Calendar (Smart Forms' GCal
 * module skips sendUpdates for tentative events).
 *
 * Default placeholder time: next business morning at 10:00 local. The
 * op moves the event during the confirmation call.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Visit_Draft {

    public function __construct() {
        // Priority 55: after the AC bridge (10) and Floor Care Plan (5),
        // before Vapi (60) so the Vapi call references an already-drafted
        // visit if the operator's prompt wants to mention it.
        add_action( 'sfco_lead_submitted', array( $this, 'create_draft' ), 55, 3 );
    }

    public function create_draft( $lead_id, $row, $form ) {
        if ( ! class_exists( 'SFCO_Pro_GCal' ) ) {
            return;
        }
        $gcal = SFCO_Pro_GCal::get_instance();
        if ( ! $gcal || ! $gcal->is_connected() ) {
            return;
        }
        if ( ! is_array( $row ) ) {
            return;
        }

        $name             = (string) ( $row['customer_name'] ?? '' );
        $email            = sanitize_email( (string) ( $row['customer_email'] ?? '' ) );
        $phone            = (string) ( $row['customer_phone'] ?? '' );
        $segment          = ( strtolower( (string) ( $row['property_type'] ?? '' ) ) === 'commercial' ) ? 'commercial' : 'residential';
        $property_subtype = (string) ( $row['property_subtype'] ?? '' );
        $service          = (string) ( $row['project_type'] ?? '' );
        $intent           = (string) ( $row['lead_intent'] ?? '' );
        $zip              = (string) ( $row['zip_code'] ?? '' );
        $notes            = (string) ( $row['additional_notes'] ?? '' );

        // Residential carpet leads who picked "Request a call" don't want
        // a site visit yet — they want a phone call. Skip the GCal draft
        // so the operator isn't chasing a calendar event that shouldn't
        // exist. The Vapi callback hook (priority 60) still fires.
        if ( 'request_call' === $intent ) {
            return;
        }

        // Placeholder time: next business morning 10:00 local. Saturday
        // submits push to Monday; Sunday submits push to Monday. The
        // operator drags the event during the confirmation call.
        $tz       = wp_timezone();
        $dt       = new DateTimeImmutable( 'tomorrow 10:00', $tz );
        $weekday  = (int) $dt->format( 'w' ); // 0 = Sunday, 6 = Saturday
        if ( 0 === $weekday ) {
            $dt = $dt->modify( '+1 day' );
        } elseif ( 6 === $weekday ) {
            $dt = $dt->modify( '+2 days' );
        }
        $start = $dt->format( DateTimeInterface::RFC3339 );

        $title = sprintf(
            '[TENTATIVE] Site visit — %s (%s %s)',
            $name ?: $email ?: 'Lead #' . $lead_id,
            ucfirst( $segment ),
            $property_subtype ?: '—'
        );

        $description = "Smart CRM placeholder. Confirm date/time with customer, then change status to Confirmed.\n\n"
            . "Lead ID: {$lead_id}\n"
            . "Name: {$name}\n"
            . "Phone: {$phone}\n"
            . "Email: {$email}\n"
            . "Property: {$segment} / {$property_subtype}\n"
            . "Service requested: {$service}\n"
            . "Intent: {$intent}\n"
            . "ZIP: {$zip}\n\n"
            . ( $notes ? "Customer notes:\n{$notes}\n\n" : '' )
            . 'Lead detail: ' . admin_url( 'admin.php?page=smart-forms-form-entries&form_id=' . absint( $row['form_id'] ?? 0 ) . '&lead_id=' . absint( $lead_id ) );

        $gcal->create_event( array(
            'title'          => $title,
            'description'    => $description,
            'start'          => $start,
            'status'         => 'tentative',
            'attendee_email' => $email, // included but sendUpdates=none on tentative, see GCal class
            'attendee_name'  => $name,
        ) );

        if ( class_exists( 'SFCO_Pro_Log' ) ) {
            SFCO_Pro_Log::record(
                'gcal',
                'ok',
                'Tentative visit drafted: ' . $title,
                (int) ( $form->id ?? 0 ),
                (int) $lead_id,
                array( 'title' => $title, 'start' => $start )
            );
        }
    }
}

new SCRM_Pro_Visit_Draft();
