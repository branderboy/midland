<?php
/**
 * Cron — schedules the recurring brief (daily or weekly at the preferred send
 * time) and runs the generate → store → email pipeline.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTM_Cron {

    const HOOK = 'ctm_send_brief';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
        // Self-heal the schedule on admin loads (e.g. after an update). Running
        // this on front-end 'init' would cost a wp_next_scheduled() DB lookup on
        // every visitor request.
        add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
    }

    /** Register the custom "weekly" recurrence (core only ships up to daily). */
    public static function register_schedules( $schedules ) {
        if ( ! is_array( $schedules ) ) {
            $schedules = array();
        }
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'content-traffic-maker' ),
            );
        }
        return $schedules;
    }

    /** Ensure the event matches the enabled flag + frequency. */
    public static function maybe_schedule() {
        $settings = CTM_DB::get_settings();
        $next     = wp_next_scheduled( self::HOOK );

        if ( empty( $settings['enabled'] ) ) {
            if ( $next ) {
                self::clear();
            }
            return;
        }

        $freq = ( 'daily' === ( $settings['frequency'] ?? 'weekly' ) ) ? 'daily' : 'weekly';
        if ( $next ) {
            $event = wp_get_scheduled_event( self::HOOK );
            if ( $event && isset( $event->schedule ) && $event->schedule !== $freq ) {
                self::clear();
                $next = false;
            }
        }
        if ( ! $next ) {
            wp_schedule_event( self::first_run( $settings, $freq ), $freq, self::HOOK );
        }
    }

    /** Force a clean reschedule (called after settings save). */
    public static function reschedule() {
        self::clear();
        self::maybe_schedule();
    }

    public static function clear() {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Next run timestamp (UTC) at the preferred send time. Daily = next
     * occurrence of the time; weekly = next Monday at that time.
     */
    private static function first_run( $settings, $freq ) {
        // WP-local "now" without the discouraged current_time('timestamp') call.
        $now  = time() + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
        $time = preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', (string) ( $settings['send_time'] ?? '08:00' ) )
            ? $settings['send_time']
            : '08:00';

        if ( 'daily' === $freq ) {
            $ts = strtotime( 'today ' . $time, $now );
            if ( $ts <= $now ) {
                $ts = strtotime( 'tomorrow ' . $time, $now );
            }
        } else {
            $ts = strtotime( 'monday ' . $time, $now );
            if ( $ts <= $now ) {
                $ts = strtotime( 'next monday ' . $time, $now );
            }
        }
        // Convert the site-time target to UTC for cron.
        return $ts - (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
    }

    /**
     * Scheduled cron callback — generate + email in one shot.
     * Respects the daily cap and enabled flag.
     */
    public static function run( $force = false ) {
        $settings = CTM_DB::get_settings();
        if ( ! $force && empty( $settings['enabled'] ) ) {
            return new WP_Error( 'ctm_disabled', __( 'Alerts are disabled.', 'content-traffic-maker' ) );
        }
        if ( ! $force && CTM_Emailer::already_sent_today() ) {
            return new WP_Error( 'ctm_already_sent', __( 'A brief was already emailed today.', 'content-traffic-maker' ) );
        }

        $brief = CTM_Generator::generate( $settings );
        if ( is_wp_error( $brief ) ) return $brief;

        $html     = CTM_Emailer::render_html( $brief );
        $sent     = CTM_Emailer::send( $brief, $settings, $html, $force );
        $brief_id = CTM_DB::insert_brief( array(
            'business_name' => $settings['business_name'] ?? '',
            'brief'         => $brief,
            'brief_html'    => $html,
            'sent_to'       => $sent ? ( $settings['recipient'] ?? '' ) : '',
            'status'        => $sent ? 'sent' : 'generated',
        ) );

        return array(
            'brief'      => $brief,
            'brief_html' => $html,
            'brief_id'   => $brief_id,
            'sent'       => (bool) $sent,
        );
    }

    /**
     * Generate and store a brief WITHOUT sending email.
     * Used by the dashboard "Generate" button.
     */
    public static function generate_only() {
        $settings = CTM_DB::get_settings();
        $brief    = CTM_Generator::generate( $settings );
        if ( is_wp_error( $brief ) ) return $brief;

        $html     = CTM_Emailer::render_html( $brief );
        $brief_id = CTM_DB::insert_brief( array(
            'business_name' => $settings['business_name'] ?? '',
            'brief'         => $brief,
            'brief_html'    => $html,
            'sent_to'       => '',
            'status'        => 'generated',
        ) );

        return array(
            'brief'      => $brief,
            'brief_html' => $html,
            'brief_id'   => $brief_id,
            'sent'       => false,
        );
    }

    /**
     * Send an already-stored brief by ID — always bypasses the daily cap.
     * Used by the dashboard "Send Email" button.
     */
    public static function send_brief_by_id( $brief_id ) {
        $settings = CTM_DB::get_settings();
        $row      = CTM_DB::get_brief( (int) $brief_id );
        if ( ! $row ) {
            return new WP_Error( 'ctm_not_found', __( 'Brief not found.', 'content-traffic-maker' ) );
        }

        $brief = json_decode( (string) $row->brief_json, true );
        if ( ! is_array( $brief ) ) {
            return new WP_Error( 'ctm_bad_json', __( 'Brief data is corrupt.', 'content-traffic-maker' ) );
        }

        $html = (string) $row->brief_html;
        if ( '' === $html ) {
            $html = CTM_Emailer::render_html( $brief );
        }

        // Force-send bypasses the daily cap so you can push anytime from the dashboard.
        $sent = CTM_Emailer::send( $brief, $settings, $html, true );

        if ( $sent ) {
            CTM_DB::mark_sent( (int) $brief_id, $settings['recipient'] ?? '' );
        }

        return $sent
            ? array( 'sent' => true, 'brief_id' => (int) $brief_id )
            : new WP_Error( 'ctm_send_failed', __( 'Email failed to send. Check your Resend key and from address.', 'content-traffic-maker' ) );
    }
}
