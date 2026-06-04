<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RSSEO_Profile — the one business profile the Command Center is built around.
 *
 * Setup owns it; Site Scan, Opportunities, and Page Builder read from it. It
 * replaces the scattered storage (rsseo_business_profile + the sameas identity
 * option) with a single canonical accessor, while still mirroring the overlap
 * back into rsseo_business_profile so the existing analyzer / programmatic
 * readers keep working until later phases repoint them here.
 *
 * Canonical store: option `rsseo_profile` (array).
 */
class RSSEO_Profile {

    const OPTION        = 'rsseo_profile';
    const LEGACY_OPTION = 'rsseo_business_profile';
    const SAMEAS_OPTION = 'rsseo_sameas_identity';

    /** Readiness states for the Setup checklist. */
    const READY     = 'ready';
    const MISSING   = 'missing';
    const ATTENTION = 'attention';

    /** Canonical field set with empty defaults. */
    public static function defaults() {
        return array(
            'business_name' => '',
            'category'      => '',
            'services'      => '', // one per line
            'cities'        => '', // one per line (service areas)
            'gbp_url'       => '',
            'competitors'   => '', // one per line
        );
    }

    /**
     * The current profile, migrating scattered legacy data in on first read so
     * nothing the user already entered is lost.
     */
    public static function get() {
        $stored = get_option( self::OPTION, null );
        if ( is_array( $stored ) ) {
            return wp_parse_args( $stored, self::defaults() );
        }
        $migrated = self::migrate_from_legacy();
        update_option( self::OPTION, $migrated );
        return $migrated;
    }

    /** Build an initial profile from the old business-profile + sameas options. */
    private static function migrate_from_legacy() {
        $p      = self::defaults();
        $legacy = (array) get_option( self::LEGACY_OPTION, array() );
        $sameas = (array) get_option( self::SAMEAS_OPTION, array() );

        $p['business_name'] = (string) ( $legacy['name'] ?? $sameas['business_name'] ?? '' );
        $p['category']      = (string) ( $legacy['category'] ?? $sameas['business_type'] ?? '' );
        $p['gbp_url']       = (string) ( $legacy['gmb_url'] ?? $sameas['gmb_url'] ?? '' );
        $p['cities']        = (string) ( $legacy['service_areas'] ?? $sameas['service_areas'] ?? '' );
        $p['competitors']   = (string) ( $legacy['competitors'] ?? '' );
        // No legacy source for a discrete services list — operator fills it in.
        $p['services'] = '';

        return $p;
    }

    /**
     * Persist the profile. Sanitises every field and mirrors the overlapping
     * fields back into the legacy option so current consumers stay in sync.
     *
     * @param array $data Raw (already-unslashed) field values.
     */
    public static function save( $data ) {
        $p = array(
            'business_name' => sanitize_text_field( (string) ( $data['business_name'] ?? '' ) ),
            'category'      => sanitize_text_field( (string) ( $data['category'] ?? '' ) ),
            'services'      => sanitize_textarea_field( (string) ( $data['services'] ?? '' ) ),
            'cities'        => sanitize_textarea_field( (string) ( $data['cities'] ?? '' ) ),
            'gbp_url'       => esc_url_raw( (string) ( $data['gbp_url'] ?? '' ) ),
            'competitors'   => sanitize_textarea_field( (string) ( $data['competitors'] ?? '' ) ),
        );
        update_option( self::OPTION, $p );

        // Transitional mirror: keep the legacy option fresh for readers that
        // haven't been repointed to RSSEO_Profile yet.
        update_option( self::LEGACY_OPTION, array(
            'name'          => $p['business_name'],
            'category'      => $p['category'],
            'gmb_url'       => $p['gbp_url'],
            'service_areas' => $p['cities'],
            'competitors'   => $p['competitors'],
        ) );

        return $p;
    }

    /** Website is derived, not stored. */
    public static function website() {
        return home_url( '/' );
    }

    /** Split a one-per-line textarea field into a clean array. */
    public static function lines( $value ) {
        return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $value ) ) ) );
    }

    /**
     * Setup readiness checklist. Each item: key, label, status (ready/missing/
     * attention), hint. Drives the Ready / Missing / Needs Attention panel.
     *
     * @return array[]
     */
    public static function readiness() {
        $p           = self::get();
        $has_key     = class_exists( 'RSSEO_Settings' ) && RSSEO_Settings::has_api_key();
        $dfs_ready   = class_exists( 'RSSEO_Pro_DataForSEO' ) && RSSEO_Pro_DataForSEO::is_configured();
        $seo_plugin  = class_exists( 'RSSEO_Settings' ) ? RSSEO_Settings::detect_seo_plugin() : 'none';

        $filled = function ( $v ) {
            return '' !== trim( (string) $v );
        };

        $items = array();

        $items[] = array(
            'key'    => 'business_name',
            'label'  => __( 'Business name', 'real-smart-seo' ),
            'status' => $filled( $p['business_name'] ) ? self::READY : self::MISSING,
            'hint'   => __( 'Used to ground every AI scan and fix.', 'real-smart-seo' ),
        );
        $items[] = array(
            'key'    => 'website',
            'label'  => __( 'Website', 'real-smart-seo' ),
            'status' => self::READY,
            'hint'   => self::website(),
        );
        $items[] = array(
            'key'    => 'services',
            'label'  => __( 'Main services', 'real-smart-seo' ),
            'status' => $filled( $p['services'] ) ? self::READY : self::MISSING,
            'hint'   => __( 'Feeds the city × service Page Builder and content opportunities.', 'real-smart-seo' ),
        );
        $items[] = array(
            'key'    => 'cities',
            'label'  => __( 'Cities / service areas', 'real-smart-seo' ),
            'status' => $filled( $p['cities'] ) ? self::READY : self::MISSING,
            'hint'   => __( 'Drives local pages and geo-grid rank tracking.', 'real-smart-seo' ),
        );
        $items[] = array(
            'key'    => 'gbp_url',
            'label'  => __( 'Google Business Profile', 'real-smart-seo' ),
            'status' => $filled( $p['gbp_url'] ) ? self::READY : self::ATTENTION,
            'hint'   => __( 'Anchors sameAs schema and local relevance. Recommended.', 'real-smart-seo' ),
        );
        $items[] = array(
            'key'    => 'competitors',
            'label'  => __( 'Competitors', 'real-smart-seo' ),
            'status' => $filled( $p['competitors'] ) ? self::READY : self::ATTENTION,
            'hint'   => __( 'Optional — lets the AI compare you against named rivals.', 'real-smart-seo' ),
        );
        $items[] = array(
            'key'    => 'api_key',
            'label'  => __( 'Perplexity API key', 'real-smart-seo' ),
            'status' => $has_key ? self::READY : self::MISSING,
            'hint'   => __( 'Required for AI scans and recommendations.', 'real-smart-seo' ),
        );
        $items[] = array(
            'key'    => 'dataforseo',
            'label'  => __( 'DataForSEO (rank + backlinks)', 'real-smart-seo' ),
            'status' => $dfs_ready ? self::READY : self::ATTENTION,
            'hint'   => __( 'Optional — powers geo-grid, rank tracking, and backlinks.', 'real-smart-seo' ),
        );
        $items[] = array(
            'key'    => 'seo_plugin',
            'label'  => __( 'SEO plugin', 'real-smart-seo' ),
            'status' => self::READY,
            'hint'   => 'yoast' === $seo_plugin
                ? __( 'Yoast SEO detected — fixes write to Yoast meta.', 'real-smart-seo' )
                : ( 'rankmath' === $seo_plugin
                    ? __( 'Rank Math detected — fixes write to Rank Math meta.', 'real-smart-seo' )
                    : __( 'None detected — fixes use native post meta.', 'real-smart-seo' ) ),
        );

        return $items;
    }

    /**
     * Overall setup state: 'ready' when no required item is missing, otherwise
     * 'attention' (something optional is open) or 'missing' (a required item is).
     */
    public static function overall_status() {
        $required = array( 'business_name', 'services', 'cities', 'api_key' );
        $missing  = false;
        $attention = false;
        foreach ( self::readiness() as $item ) {
            if ( self::MISSING === $item['status'] && in_array( $item['key'], $required, true ) ) {
                $missing = true;
            }
            if ( self::ATTENTION === $item['status'] ) {
                $attention = true;
            }
        }
        if ( $missing ) {
            return self::MISSING;
        }
        return $attention ? self::ATTENTION : self::READY;
    }

    /** Label for a readiness status. */
    public static function status_label( $status ) {
        switch ( $status ) {
            case self::READY:   return __( 'Ready', 'real-smart-seo' );
            case self::MISSING: return __( 'Missing', 'real-smart-seo' );
            default:            return __( 'Needs attention', 'real-smart-seo' );
        }
    }

    /** Hex colour for a readiness status. */
    public static function status_color( $status ) {
        switch ( $status ) {
            case self::READY:   return '#0a8754';
            case self::MISSING: return '#d63638';
            default:            return '#dba617';
        }
    }
}
