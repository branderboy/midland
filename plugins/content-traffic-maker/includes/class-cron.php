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
        // Self-heal the schedule on each load (e.g. after an update).
        add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
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
        $now  = current_time( 'timestamp' );
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
     * Cron callback: generate, store, and email the brief.
     *
     * @param bool $force Bypass the enabled check (used by the manual button).
     * @return array|WP_Error { brief, brief_html, brief_id } or error.
     */
    public static function run( $force = false ) {
        $settings = CTM_DB::get_settings();
        if ( ! $force && empty( $settings['enabled'] ) ) {
            return new WP_Error( 'ctm_disabled', __( 'Alerts are disabled.', 'content-traffic-maker' ) );
        }
        // One email per day: if the scheduled alert already went out today
        // (e.g. WP-Cron fired twice), skip without spending an API call.
        if ( ! $force && CTM_Emailer::already_sent_today() ) {
            return new WP_Error( 'ctm_already_sent', __( 'A brief was already emailed today.', 'content-traffic-maker' ) );
        }

        $brief = CTM_Generator::generate( $settings );
        if ( is_wp_error( $brief ) ) {
            return $brief;
        }

        $html = CTM_Emailer::render_html( $brief, $settings );
        $sent = CTM_Emailer::send( $brief, $settings, $html );

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
}
