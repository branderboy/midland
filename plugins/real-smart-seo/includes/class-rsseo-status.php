<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RSSEO_Status — the single source of truth for the lifecycle every unit of
 * work in the Command Center moves through.
 *
 * One vocabulary is shared by scans, issues, fixes, and generated pages so the
 * UI (Opportunity Map, Fix Queue, Page Builder, Growth Dashboard) can render a
 * consistent badge and decide the next action from one place instead of each
 * module inventing its own ad-hoc states.
 *
 *   detected    → the scan/crawl found it (raw signal, no recommendation yet)
 *   recommended → the engine has a suggested fix / action for it
 *   previewed   → the operator has seen the before/after (or page draft)
 *   applied     → the change has been written to the site (content/schema/link/page)
 *   submitted   → the affected URL(s) were pushed to search engines (sitemap/IndexNow)
 *   verified    → the change was confirmed live / indexed (re-crawl or GSC check)
 *
 * Plus two terminal off-ramps that aren't part of the forward flow:
 *   dismissed   → operator chose to ignore this item
 *   failed      → an apply/submit step errored and needs attention
 */
class RSSEO_Status {

    const DETECTED    = 'detected';
    const RECOMMENDED = 'recommended';
    const PREVIEWED   = 'previewed';
    const APPLIED     = 'applied';
    const SUBMITTED   = 'submitted';
    const VERIFIED    = 'verified';
    const DISMISSED   = 'dismissed';
    const FAILED      = 'failed';

    /**
     * The forward pipeline, in order. Index position doubles as the progress
     * step so the UI can draw a "3 of 6" style stepper.
     *
     * @return string[]
     */
    public static function pipeline() {
        return array(
            self::DETECTED,
            self::RECOMMENDED,
            self::PREVIEWED,
            self::APPLIED,
            self::SUBMITTED,
            self::VERIFIED,
        );
    }

    /** All known statuses, including the terminal off-ramps. */
    public static function all() {
        return array_merge( self::pipeline(), array( self::DISMISSED, self::FAILED ) );
    }

    /** Normalise an arbitrary stored value to a known status (defaults to detected). */
    public static function normalize( $status ) {
        $status = is_string( $status ) ? strtolower( trim( $status ) ) : '';
        return in_array( $status, self::all(), true ) ? $status : self::DETECTED;
    }

    /** Human label for a status. */
    public static function label( $status ) {
        $labels = array(
            self::DETECTED    => __( 'Detected', 'real-smart-seo' ),
            self::RECOMMENDED => __( 'Recommended', 'real-smart-seo' ),
            self::PREVIEWED   => __( 'Previewed', 'real-smart-seo' ),
            self::APPLIED     => __( 'Applied', 'real-smart-seo' ),
            self::SUBMITTED   => __( 'Submitted', 'real-smart-seo' ),
            self::VERIFIED    => __( 'Verified', 'real-smart-seo' ),
            self::DISMISSED   => __( 'Dismissed', 'real-smart-seo' ),
            self::FAILED      => __( 'Needs attention', 'real-smart-seo' ),
        );
        $status = self::normalize( $status );
        return $labels[ $status ];
    }

    /**
     * A hex colour for the status badge. Greens deepen as an item advances
     * through the pipeline; off-ramps are grey (dismissed) / red (failed).
     */
    public static function color( $status ) {
        $colors = array(
            self::DETECTED    => '#64748b', // slate — just a signal
            self::RECOMMENDED => '#2563eb', // blue — action ready
            self::PREVIEWED   => '#7c3aed', // violet — reviewed
            self::APPLIED     => '#0a8754', // green — done on-site
            self::SUBMITTED   => '#047857', // deeper green — pushed to engines
            self::VERIFIED    => '#065f46', // deepest green — confirmed live
            self::DISMISSED   => '#9ca3af', // grey
            self::FAILED      => '#d63638', // red
        );
        $status = self::normalize( $status );
        return $colors[ $status ];
    }

    /** Zero-based position in the forward pipeline, or -1 for off-ramp states. */
    public static function step( $status ) {
        $status = self::normalize( $status );
        $pos    = array_search( $status, self::pipeline(), true );
        return false === $pos ? -1 : (int) $pos;
    }

    /** True when $status is at or past $target in the forward pipeline. */
    public static function at_least( $status, $target ) {
        $a = self::step( $status );
        $b = self::step( $target );
        return $a >= 0 && $b >= 0 && $a >= $b;
    }

    /** The next forward status, or null at the end / for off-ramp states. */
    public static function next( $status ) {
        $pos = self::step( $status );
        if ( $pos < 0 ) {
            return null;
        }
        $pipeline = self::pipeline();
        return isset( $pipeline[ $pos + 1 ] ) ? $pipeline[ $pos + 1 ] : null;
    }

    /** True for the two terminal off-ramp states. */
    public static function is_terminal( $status ) {
        return in_array( self::normalize( $status ), array( self::DISMISSED, self::FAILED ), true );
    }

    /**
     * Render a small inline status badge (escaped). Safe to echo directly.
     *
     * @param string $status
     * @return string HTML
     */
    public static function badge( $status ) {
        $status = self::normalize( $status );
        return sprintf(
            '<span class="rsseo-status-badge rsseo-status-badge--%1$s" style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;line-height:1.6;color:#fff;background:%2$s;">%3$s</span>',
            esc_attr( $status ),
            esc_attr( self::color( $status ) ),
            esc_html( self::label( $status ) )
        );
    }
}
