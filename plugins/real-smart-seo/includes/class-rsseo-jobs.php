<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RSSEO_Jobs — runs the AI analysis as a background job instead of blocking the
 * admin request.
 *
 * The Perplexity call can take up to ~2 minutes; running it inside the scan
 * submit POST made the page hang, time out, or look broken. Now submitting a
 * scan only enqueues the work: the scan is marked queued, a single WP-Cron
 * event fires it off-request, and the Opportunities tab polls for progress and
 * swaps in the report when it's done.
 *
 * Progress is stored per scan in option `rsseo_job_{scan_id}` as:
 *   array( status => queued|running|complete|error, report_id, message, at )
 * mirroring the RSSEO_Status vocabulary loosely (queued≈detected, running,
 * complete≈recommended, error≈failed).
 */
class RSSEO_Jobs {

    const HOOK   = 'rsseo_analyze_scan';
    const PREFIX = 'rsseo_job_';

    /** Wire the cron callback. Called once at bootstrap. */
    public static function register() {
        add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 1 );
    }

    private static function key( $scan_id ) {
        return self::PREFIX . (int) $scan_id;
    }

    /** Read job progress for a scan (defaults to unknown). */
    public static function status( $scan_id ) {
        $state = get_option( self::key( $scan_id ), null );
        if ( ! is_array( $state ) ) {
            return array( 'status' => 'unknown', 'report_id' => 0, 'message' => '', 'at' => 0 );
        }
        return wp_parse_args( $state, array( 'status' => 'unknown', 'report_id' => 0, 'message' => '', 'at' => 0 ) );
    }

    private static function set( $scan_id, $status, $extra = array() ) {
        update_option( self::key( $scan_id ), array_merge( array(
            'status'    => $status,
            'report_id' => 0,
            'message'   => '',
            'at'        => time(),
        ), $extra ), false );
    }

    /**
     * Queue analysis for a freshly-created scan and kick WP-Cron so it starts
     * (almost) immediately rather than waiting for the next pageview.
     */
    public static function enqueue( $scan_id ) {
        $scan_id = (int) $scan_id;
        if ( $scan_id <= 0 ) {
            return;
        }
        self::set( $scan_id, 'queued' );
        if ( class_exists( 'RSSEO_Database' ) ) {
            RSSEO_Database::update_scan( $scan_id, array( 'status' => 'pending' ) );
        }
        if ( ! wp_next_scheduled( self::HOOK, array( $scan_id ) ) ) {
            wp_schedule_single_event( time(), self::HOOK, array( $scan_id ) );
        }
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * Cron entry point — run the analysis off-request. Mirrors the path
     * handle_new_scan used: let a Pro analyzer take over via the filter, else
     * fall back to the base analyzer.
     */
    public static function run( $scan_id ) {
        $scan_id = (int) $scan_id;
        if ( $scan_id <= 0 ) {
            return;
        }

        $current = self::status( $scan_id );
        if ( 'running' === $current['status'] ) {
            return; // already in flight — don't double-run
        }
        self::set( $scan_id, 'running' );

        $report_id = apply_filters( 'rsseo_run_analyzer', null, $scan_id );
        if ( null === $report_id ) {
            $report_id = RSSEO_Analyzer::analyze( $scan_id );
        }

        if ( is_wp_error( $report_id ) ) {
            self::set( $scan_id, 'error', array( 'message' => $report_id->get_error_message() ) );
            return;
        }

        self::set( $scan_id, 'complete', array( 'report_id' => (int) $report_id ) );
    }
}

RSSEO_Jobs::register();
