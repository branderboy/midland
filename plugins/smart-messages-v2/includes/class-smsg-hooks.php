<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMSG_Hooks {

    private static $instance = null;
    private $api;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api = SMSG_WhatsApp_API::get_instance();

        // Smart Forms hooks
        add_action( 'sfc_lead_created', array( $this, 'on_lead_created' ), 10, 1 );
        add_action( 'sfc_booking_requested', array( $this, 'on_booking_requested' ), 10, 1 );
        add_action( 'sfc_booking_confirmed', array( $this, 'on_booking_confirmed' ), 10, 1 );
        add_action( 'sfc_booking_denied', array( $this, 'on_booking_denied' ), 10, 2 );
        add_action( 'sfc_booking_suggested', array( $this, 'on_booking_suggested' ), 10, 3 );

        // Reminder cron
        add_action( 'smsg_send_reminders', array( $this, 'send_appointment_reminders' ) );
        add_action( 'wp', array( $this, 'schedule_reminder_cron' ) );
    }

    /**
     * Schedule daily reminder check.
     */
    public function schedule_reminder_cron() {
        if ( ! wp_next_scheduled( 'smsg_send_reminders' ) ) {
            wp_schedule_event( time(), 'hourly', 'smsg_send_reminders' );
        }
    }

    /**
     * Send 24-hour appointment reminders.
     */
    public function send_appointment_reminders() {
        if ( get_option( 'smsg_send_reminder', '1' ) !== '1' ) {
            return;
        }
        if ( ! $this->api->is_configured() ) {
            return;
        }

        global $wpdb;

        $tomorrow = gmdate( 'Y-m-d', strtotime( '+1 day' ) );

        // Check Smart Booking table if it exists.
        $table = $wpdb->prefix . 'sb_bookings';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return;
        }

        $bookings = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$table}
             WHERE appointment_date = %s
             AND status = 'confirmed'
             AND (reminder_sent = 0 OR reminder_sent IS NULL)",
            $tomorrow
        ) );

        $business = get_option( 'smsg_business_name', get_bloginfo( 'name' ) );
        $template = get_option( 'smsg_template_reminder', 'appointment_reminder' );

        foreach ( $bookings as $booking ) {
            $date     = new DateTime( $booking->appointment_date );
            $date_str = $date->format( 'l, F j' ) . ' at ' . $booking->appointment_time;

            $this->send_whatsapp_message(
                $booking->customer_phone,
                $template,
                array( $booking->customer_name, $date_str, $business ),
                $booking->lead_id,
                /* translators: 1: business name, 2: date/time string */
                sprintf( __( 'Reminder: Your appointment with %1$s is tomorrow (%2$s). See you then!', 'smart-messages' ), $business, $date_str )
            );

            // Mark reminder as sent.
            $wpdb->update( $table, array( 'reminder_sent' => 1 ), array( 'id' => $booking->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

            $this->log( "Reminder sent to {$booking->customer_phone}" );
        }
    }

    /**
     * Lead created (no booking request).
     */
    public function on_lead_created( $lead ) {
        // Skip if this lead has a booking request (different message).
        if ( ! empty( $lead->preferred_date ) ) {
            return;
        }

        if ( get_option( 'smsg_send_on_lead', '1' ) !== '1' ) {
            return;
        }
        if ( ! $this->api->is_configured() ) {
            return;
        }

        $phone = $lead->phone ?? '';
        if ( empty( $phone ) ) {
            return;
        }

        $business = get_option( 'smsg_business_name', get_bloginfo( 'name' ) );
        $template = get_option( 'smsg_template_lead', 'lead_received' );

        $this->send_whatsapp_message(
            $phone,
            $template,
            array( $lead->name_first, $business ),
            $lead->id,
            /* translators: 1: first name, 2: business name */
            sprintf( __( 'Hi %1$s! Thanks for contacting %2$s. We will be in touch shortly.', 'smart-messages' ), $lead->name_first, $business )
        );

        $this->log( "Lead message sent to {$phone}" );
    }

    /**
     * Booking requested - customer submitted with preferred time.
     */
    public function on_booking_requested( $lead ) {
        // Notify customer.
        if ( get_option( 'smsg_send_on_request', '1' ) === '1' && $this->api->is_configured() ) {
            $phone = $lead->phone ?? '';
            if ( ! empty( $phone ) ) {
                $business = get_option( 'smsg_business_name', get_bloginfo( 'name' ) );
                $template = get_option( 'smsg_template_request', 'booking_requested' );

                $date     = new DateTime( $lead->preferred_date );
                $date_str = $date->format( 'l, F j' ) . ' at ' . $lead->preferred_time;

                $this->send_whatsapp_message(
                    $phone,
                    $template,
                    array( $lead->name_first, $date_str, $business ),
                    $lead->id,
                    /* translators: 1: first name, 2: date/time, 3: business name */
                    sprintf( __( 'Hi %1$s! We received your request for %2$s. We will confirm shortly. - %3$s', 'smart-messages' ), $lead->name_first, $date_str, $business )
                );

                $this->log( "Booking request message sent to {$phone}" );
            }
        }

        // Notify contractor (YOU).
        if ( get_option( 'smsg_notify_contractor', '1' ) === '1' ) {
            $contractor_phone = get_option( 'smsg_contractor_phone', '' );
            if ( ! empty( $contractor_phone ) && $this->api->is_configured() ) {
                $name     = trim( $lead->name_first . ' ' . $lead->name_last );
                $date     = new DateTime( $lead->preferred_date );
                $date_str = $date->format( 'M j' ) . ' ' . $lead->preferred_time;
                $service  = $lead->service_type ?? 'Estimate';

                $template = get_option( 'smsg_template_contractor', 'new_booking_request' );

                $this->send_whatsapp_message(
                    $contractor_phone,
                    $template,
                    array( $name, $date_str, $service ),
                    $lead->id,
                    /* translators: 1: customer name, 2: date/time, 3: service type */
                    sprintf( __( 'NEW REQUEST: %1$s wants %2$s for %3$s. Check dashboard to approve.', 'smart-messages' ), $name, $date_str, $service )
                );

                $this->log( "Contractor notified at {$contractor_phone}" );
            }
        }
    }

    /**
     * Booking confirmed/approved.
     */
    public function on_booking_confirmed( $lead ) {
        if ( get_option( 'smsg_send_on_confirm', '1' ) !== '1' ) {
            return;
        }
        if ( ! $this->api->is_configured() ) {
            return;
        }

        $phone = $lead->phone ?? '';
        if ( empty( $phone ) ) {
            return;
        }

        $business = get_option( 'smsg_business_name', get_bloginfo( 'name' ) );
        $template = get_option( 'smsg_template_confirmed', 'booking_confirmed' );

        $date     = new DateTime( $lead->preferred_date );
        $date_str = $date->format( 'l, F j' ) . ' at ' . $lead->preferred_time;

        $this->send_whatsapp_message(
            $phone,
            $template,
            array( $lead->name_first, $date_str, $business ),
            $lead->id,
            /* translators: 1: first name, 2: date/time, 3: business name */
            sprintf( __( 'CONFIRMED: %1$s, your appointment is set for %2$s. See you then! - %3$s', 'smart-messages' ), $lead->name_first, $date_str, $business )
        );

        $this->log( "Booking confirmed message sent to {$phone}" );
    }

    /**
     * Booking denied.
     */
    public function on_booking_denied( $lead, $reason = '' ) {
        if ( get_option( 'smsg_send_on_deny', '1' ) !== '1' ) {
            return;
        }
        if ( ! $this->api->is_configured() ) {
            return;
        }

        $phone = $lead->phone ?? '';
        if ( empty( $phone ) ) {
            return;
        }

        $business = get_option( 'smsg_business_name', get_bloginfo( 'name' ) );
        $template = get_option( 'smsg_template_denied', 'booking_denied' );

        $this->send_whatsapp_message(
            $phone,
            $template,
            array( $lead->name_first, $business ),
            $lead->id,
            /* translators: 1: first name, 2: business name */
            sprintf( __( 'Hi %1$s, unfortunately we cannot make that time. Please reply with another option. - %2$s', 'smart-messages' ), $lead->name_first, $business )
        );

        $this->log( "Booking denied message sent to {$phone}" );
    }

    /**
     * New time suggested.
     */
    public function on_booking_suggested( $lead, $new_date, $new_time ) {
        if ( get_option( 'smsg_send_on_suggest', '1' ) !== '1' ) {
            return;
        }
        if ( ! $this->api->is_configured() ) {
            return;
        }

        $phone = $lead->phone ?? '';
        if ( empty( $phone ) ) {
            return;
        }

        $business = get_option( 'smsg_business_name', get_bloginfo( 'name' ) );
        $template = get_option( 'smsg_template_suggested', 'time_suggested' );

        $date     = new DateTime( $new_date );
        $date_str = $date->format( 'l, F j' ) . ' at ' . $new_time;

        $this->send_whatsapp_message(
            $phone,
            $template,
            array( $lead->name_first, $date_str, $business ),
            $lead->id,
            /* translators: 1: first name, 2: date/time, 3: business name */
            sprintf( __( 'Hi %1$s, how about %2$s instead? Reply YES to confirm. - %3$s', 'smart-messages' ), $lead->name_first, $date_str, $business )
        );

        $this->log( "Time suggested message sent to {$phone}" );
    }

    /**
     * Send WhatsApp with SMS fallback.
     */
    private function send_whatsapp_message( $phone, $template, $params, $lead_id, $sms_text ) {
        // Try WhatsApp first.
        $result = $this->api->send_template_message( $phone, $template, $params, $lead_id );

        // If WhatsApp failed and SMS fallback is enabled.
        if ( ! $result['success'] && get_option( 'smsg_sms_fallback', '1' ) === '1' ) {
            $this->log( "WhatsApp failed, trying SMS fallback for {$phone}" );
            $sms_result = $this->send_sms( $phone, $sms_text, $lead_id );
            return $sms_result;
        }

        return $result;
    }

    /**
     * Send SMS via Twilio.
     */
    private function send_sms( $to, $message, $lead_id = null ) {
        $account_sid = get_option( 'smsg_twilio_sid', '' );
        $auth_token  = get_option( 'smsg_twilio_token', '' );
        $from_number = get_option( 'smsg_twilio_phone', '' );

        if ( empty( $account_sid ) || empty( $auth_token ) || empty( $from_number ) ) {
            $this->log( 'SMS not configured' );
            return array( 'success' => false, 'error' => __( 'SMS not configured', 'smart-messages' ) );
        }

        $to = $this->format_phone_e164( $to );

        $response = wp_remote_post(
            "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json",
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( "{$account_sid}:{$auth_token}" ),
                ),
                'body'    => array(
                    'From' => $from_number,
                    'To'   => $to,
                    'Body' => $message,
                ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        // Log the SMS.
        $this->api->log_sms( $to, $message, $data, $lead_id );

        if ( isset( $data['sid'] ) ) {
            $this->log( "SMS sent to {$to}: {$data['sid']}" );
            return array( 'success' => true, 'message_id' => $data['sid'] );
        }

        return array( 'success' => false, 'error' => $data['message'] ?? __( 'Unknown error', 'smart-messages' ) );
    }

    private function format_phone_e164( $phone ) {
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        if ( strlen( $phone ) === 10 ) {
            $phone = '1' . $phone;
        }
        return '+' . $phone;
    }

    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Smart Messages] ' . $message );
        }
    }
}
