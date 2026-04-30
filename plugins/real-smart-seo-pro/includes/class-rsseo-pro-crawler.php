<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pro crawler extension.
 *
 * Hooks into the free plugin's do_action('rsseo_after_audit') and appends
 * pro-level checks: NAP consistency, schema presence, keyword cannibalization,
 * and service area page coverage.
 */
class RSSEO_Pro_Crawler {

    /**
     * Register the hook — called once from RSSEO_Pro_Admin.
     */
    public static function register() {
        add_action( 'rsseo_after_audit', array( __CLASS__, 'run_pro_checks' ), 10, 3 );
    }

    /**
     * Run all pro-specific audit checks.
     *
     * @param int    $audit_id   ID of the just-completed audit.
     * @param array  $posts      WP_Post objects that were audited.
     * @param string $seo_plugin 'yoast', 'rankmath', or 'none'.
     */
    public static function run_pro_checks( $audit_id, $posts, $seo_plugin ) {
        self::check_nap_consistency( $audit_id, $posts );
        self::check_schema_presence( $audit_id, $posts, $seo_plugin );
        self::check_keyword_cannibalization( $audit_id, $posts, $seo_plugin );
        self::check_service_area_pages( $audit_id, $posts );
    }

    // ── NAP Consistency ────────────────────────────────────────────────────────

    /**
     * Check that Name / Address / Phone appear consistently across key pages.
     * Looks for phone number patterns; flags pages with no phone anywhere in content.
     *
     * @param int   $audit_id
     * @param array $posts
     */
    private static function check_nap_consistency( $audit_id, $posts ) {
        // Phone pattern: US-style (123) 456-7890, 123-456-7890, +1-800-555-0100, etc.
        $phone_pattern = '/(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/';

        $key_page_types = array( 'page' );
        $pages_without_phone = array();

        foreach ( $posts as $post ) {
            if ( ! in_array( $post->post_type, $key_page_types, true ) ) {
                continue;
            }
            $content = $post->post_content;
            if ( ! preg_match( $phone_pattern, $content ) ) {
                $pages_without_phone[] = $post;
            }
        }

        if ( count( $pages_without_phone ) > 3 ) {
            // Only flag if most pages are missing it (home + about + contact are the critical ones).
            foreach ( $pages_without_phone as $post ) {
                $slug = $post->post_name;
                if ( ! in_array( $slug, array( 'home', 'about', 'about-us', 'contact', 'contact-us' ), true ) ) {
                    continue;
                }
                RSSEO_Database::insert_audit_issue( array(
                    'audit_id'     => $audit_id,
                    'post_id'      => $post->ID,
                    'issue_type'   => 'nap_missing_phone',
                    'severity'     => 'high',
                    'description'  => sprintf(
                        /* translators: post title */
                        __( '"%s" has no phone number in the page content — NAP inconsistency risk.', 'real-smart-seo-pro' ),
                        $post->post_title
                    ),
                    'suggestion'   => __( 'Add your business phone number to this page. NAP (Name, Address, Phone) consistency across all key pages is a core local SEO ranking factor.', 'real-smart-seo-pro' ),
                    'auto_fixable' => 0,
                    'created_at'   => current_time( 'mysql' ),
                ) );
            }
        }
    }

    // ── Schema Presence ────────────────────────────────────────────────────────

    /**
     * Check whether JSON-LD schema markup is present in post content or meta.
     * If a page has no schema block at all, flag it.
     *
     * @param int    $audit_id
     * @param array  $posts
     * @param string $seo_plugin
     */
    private static function check_schema_presence( $audit_id, $posts, $seo_plugin ) {
        $schema_pattern = '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>/i';

        foreach ( $posts as $post ) {
            $has_schema = (bool) preg_match( $schema_pattern, $post->post_content );

            // Check Yoast / RankMath meta for schema data.
            if ( ! $has_schema ) {
                if ( 'yoast' === $seo_plugin ) {
                    $schema_meta = get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true );
                    $has_schema  = ! empty( $schema_meta ) && 'WebPage' !== $schema_meta;
                } elseif ( 'rankmath' === $seo_plugin ) {
                    $schema_meta = get_post_meta( $post->ID, 'rank_math_rich_snippet', true );
                    $has_schema  = ! empty( $schema_meta );
                }
            }

            if ( $has_schema ) {
                continue;
            }

            // Only flag high-value page types.
            $slug        = $post->post_name;
            $is_key_page = in_array( $post->post_type, array( 'page' ), true ) &&
                           in_array( $slug, array(
                               'home', '', 'about', 'about-us', 'services', 'contact', 'contact-us',
                               'reviews', 'testimonials', 'faq',
                           ), true );

            if ( ! $is_key_page && 'page' !== $post->post_type ) {
                continue;
            }

            RSSEO_Database::insert_audit_issue( array(
                'audit_id'     => $audit_id,
                'post_id'      => $post->ID,
                'issue_type'   => 'schema_missing',
                'severity'     => $is_key_page ? 'high' : 'medium',
                'description'  => sprintf(
                    /* translators: post title */
                    __( '"%s" has no JSON-LD schema markup.', 'real-smart-seo-pro' ),
                    $post->post_title
                ),
                'suggestion'   => __( 'Add LocalBusiness, Service, FAQ, or Review schema to this page. Run a Pro scan to get AI-generated schema blocks ready to apply in one click.', 'real-smart-seo-pro' ),
                'auto_fixable' => 0,
                'created_at'   => current_time( 'mysql' ),
            ) );
        }
    }

    // ── Keyword Cannibalization ─────────────────────────────────────────────────

    /**
     * Detect posts whose SEO titles share the same leading keyword phrase
     * (first 4 words), which can cause cannibalization.
     *
     * @param int    $audit_id
     * @param array  $posts
     * @param string $seo_plugin
     */
    private static function check_keyword_cannibalization( $audit_id, $posts, $seo_plugin ) {
        $keyword_map = array();

        foreach ( $posts as $post ) {
            $seo_title = self::get_seo_title( $post, $seo_plugin );
            if ( empty( $seo_title ) ) {
                continue;
            }
            // Normalize: lowercase, strip site name suffix, take first 3 words.
            $title_clean = strtolower( preg_replace( '/\s*[|\-–—].*$/', '', $seo_title ) );
            $words       = preg_split( '/\s+/', trim( $title_clean ) );
            $key_phrase  = implode( ' ', array_slice( $words, 0, 3 ) );

            if ( strlen( $key_phrase ) < 6 ) {
                continue;
            }
            $keyword_map[ $key_phrase ][] = $post;
        }

        foreach ( $keyword_map as $phrase => $competing_posts ) {
            if ( count( $competing_posts ) < 2 ) {
                continue;
            }
            $titles = implode( ', ', array_map( function( $p ) { return '"' . $p->post_title . '"'; }, $competing_posts ) );
            foreach ( $competing_posts as $post ) {
                RSSEO_Database::insert_audit_issue( array(
                    'audit_id'    => $audit_id,
                    'post_id'     => $post->ID,
                    'issue_type'  => 'keyword_cannibalization',
                    'severity'    => 'high',
                    'description' => sprintf(
                        /* translators: 1: phrase, 2: list of titles */
                        __( 'Keyword cannibalization — "%1$s" competes with: %2$s', 'real-smart-seo-pro' ),
                        esc_html( $phrase ),
                        esc_html( $titles )
                    ),
                    'suggestion'  => __( 'Differentiate these pages by targeting distinct long-tail keywords. Consolidate or canonicalize weaker pages. Run a Pro scan for AI-generated differentiation recommendations.', 'real-smart-seo-pro' ),
                    'auto_fixable' => 0,
                    'created_at'  => current_time( 'mysql' ),
                ) );
            }
        }
    }

    // ── Service Area Page Gaps ─────────────────────────────────────────────────

    /**
     * For local businesses, check whether the site has any location/service-area
     * pages. If there are zero posts with city/area keywords in the title, flag it.
     *
     * @param int   $audit_id
     * @param array $posts
     */
    private static function check_service_area_pages( $audit_id, $posts ) {
        // Heuristic: if any post title contains typical geo-specific words it likely has SAPs.
        $geo_pattern = '/\b(near|serving|in the|area|city|county|neighborhood|district|zip|local)\b/i';
        $has_sap     = false;

        foreach ( $posts as $post ) {
            if ( preg_match( $geo_pattern, $post->post_title ) ) {
                $has_sap = true;
                break;
            }
            if ( preg_match( $geo_pattern, $post->post_content ) ) {
                $has_sap = true;
                break;
            }
        }

        if ( ! $has_sap ) {
            RSSEO_Database::insert_audit_issue( array(
                'audit_id'    => $audit_id,
                'post_id'     => 0,
                'issue_type'  => 'no_service_area_pages',
                'severity'    => 'high',
                'description' => __( 'No service area pages detected. Local businesses without city/area-specific pages miss the majority of "near me" and "[city] + [service]" searches.', 'real-smart-seo-pro' ),
                'suggestion'  => __( 'Create at least one dedicated page per service area city you target. Each page should include the city name in the H1, title, meta description, and body copy. Run a Pro scan for a custom content brief.', 'real-smart-seo-pro' ),
                'auto_fixable' => 0,
                'created_at'  => current_time( 'mysql' ),
            ) );
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param WP_Post $post
     * @param string  $seo_plugin
     * @return string
     */
    private static function get_seo_title( $post, $seo_plugin ) {
        if ( 'yoast' === $seo_plugin ) {
            $t = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
            if ( $t ) return $t;
        } elseif ( 'rankmath' === $seo_plugin ) {
            $t = get_post_meta( $post->ID, 'rank_math_title', true );
            if ( $t ) return $t;
        }
        return $post->post_title;
    }
}
