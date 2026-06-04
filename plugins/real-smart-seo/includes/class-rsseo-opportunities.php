<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RSSEO_Opportunities — turns raw findings into the Opportunity Map.
 *
 * Instead of dumping issues into severity tables, it regroups everything the
 * scan surfaced into four action-oriented buckets the operator can reason
 * about by money + effort:
 *
 *   quick_wins → fast on-page fixes (titles, meta, alt text, content tweaks)
 *   local      → missing city / service-area coverage (computed from the profile)
 *   content    → pages to create or expand
 *   technical  → indexing, canonicals, thin pages, speed
 *
 * Sources: the AI report's fix rows (rsseo_fixes), the latest crawler audit
 * (rsseo_audit_issues), and service × city gaps derived from RSSEO_Profile.
 *
 * Each item: array( kind, label, detail, status, post_id, fix_id, action_url ).
 */
class RSSEO_Opportunities {

    const QUICK_WINS = 'quick_wins';
    const LOCAL      = 'local';
    const CONTENT    = 'content';
    const TECHNICAL  = 'technical';

    /** Bucket metadata in display order. */
    public static function buckets() {
        return array(
            self::QUICK_WINS => array(
                'title' => __( 'Quick Wins', 'real-smart-seo' ),
                'desc'  => __( 'Fast on-page fixes: titles, meta descriptions, H1s, image alt text, internal links.', 'real-smart-seo' ),
                'color' => '#0a8754',
            ),
            self::LOCAL => array(
                'title' => __( 'Local SEO Gaps', 'real-smart-seo' ),
                'desc'  => __( 'Service × city combinations you serve but have no page for.', 'real-smart-seo' ),
                'color' => '#2563eb',
            ),
            self::CONTENT => array(
                'title' => __( 'Content Opportunities', 'real-smart-seo' ),
                'desc'  => __( 'Pages to create or expand, FAQs to add, thin content to build out.', 'real-smart-seo' ),
                'color' => '#7c3aed',
            ),
            self::TECHNICAL => array(
                'title' => __( 'Technical Issues', 'real-smart-seo' ),
                'desc'  => __( 'Indexing, canonicals, redirects, thin pages, and speed.', 'real-smart-seo' ),
                'color' => '#b45309',
            ),
        );
    }

    /**
     * Build the grouped opportunity map.
     *
     * @param int $report_id Optional report to pull AI fixes from.
     * @return array bucket_key => item[]
     */
    public static function groups( $report_id = 0 ) {
        $out = array(
            self::QUICK_WINS => array(),
            self::LOCAL      => array(),
            self::CONTENT    => array(),
            self::TECHNICAL  => array(),
        );

        // 1) AI report fixes → Quick Wins (content rewrites → Content).
        if ( $report_id > 0 && class_exists( 'RSSEO_Database' ) ) {
            $fix_queue_base = admin_url( 'admin.php?page=real-smart-seo&tab=fixqueue&report_id=' . (int) $report_id );
            foreach ( (array) RSSEO_Database::get_fixes( $report_id ) as $fix ) {
                $type   = (string) ( $fix->fix_type ?? '' );
                $bucket = ( 'content' === $type ) ? self::CONTENT : self::QUICK_WINS;
                $out[ $bucket ][] = array(
                    'kind'       => 'fix',
                    'label'      => self::fix_label( $type, (int) ( $fix->post_id ?? 0 ) ),
                    'detail'     => self::excerpt( (string) ( $fix->new_value ?? '' ) ),
                    'status'     => ! empty( $fix->applied ) ? RSSEO_Status::APPLIED : RSSEO_Status::RECOMMENDED,
                    'post_id'    => (int) ( $fix->post_id ?? 0 ),
                    'fix_id'     => (int) ( $fix->id ?? 0 ),
                    'action_url' => $fix_queue_base,
                );
            }
        }

        // 2) Latest crawler audit issues → classified by keyword.
        if ( class_exists( 'RSSEO_Database' ) && method_exists( 'RSSEO_Database', 'get_latest_audit' ) ) {
            $audit = RSSEO_Database::get_latest_audit();
            if ( $audit && ! empty( $audit->id ) ) {
                $audit_url = admin_url( 'admin.php?page=real-smart-seo&tab=audit' );
                foreach ( (array) RSSEO_Database::get_audit_issues( $audit->id ) as $issue ) {
                    $bucket = self::classify_issue( (string) ( $issue->issue_type ?? '' ), (string) ( $issue->description ?? '' ) );
                    $out[ $bucket ][] = array(
                        'kind'       => 'issue',
                        'label'      => self::issue_label( $issue ),
                        'detail'     => self::excerpt( (string) ( $issue->suggestion ?? $issue->description ?? '' ) ),
                        'status'     => ! empty( $issue->fixed ) ? RSSEO_Status::APPLIED : RSSEO_Status::DETECTED,
                        'post_id'    => (int) ( $issue->post_id ?? 0 ),
                        'fix_id'     => 0,
                        'action_url' => $audit_url,
                    );
                }
            }
        }

        // 3) Local gaps from the profile (service × city without a page).
        foreach ( self::local_gaps() as $gap ) {
            $out[ self::LOCAL ][] = $gap;
        }

        return $out;
    }

    /** Total item count across all buckets. */
    public static function total( $groups ) {
        $n = 0;
        foreach ( $groups as $items ) {
            $n += count( $items );
        }
        return $n;
    }

    /** Classify an audit issue into a bucket by keyword. */
    private static function classify_issue( $type, $desc ) {
        $h = strtolower( $type . ' ' . $desc );
        $has = function ( $needles ) use ( $h ) {
            foreach ( $needles as $n ) {
                if ( false !== strpos( $h, $n ) ) {
                    return true;
                }
            }
            return false;
        };
        if ( $has( array( 'thin', 'word count', 'too short', 'faq', 'expand' ) ) ) {
            return self::CONTENT;
        }
        if ( $has( array( 'index', 'canonical', 'redirect', '404', 'sitemap', 'robots', 'https', 'speed', 'duplicate', 'orphan', 'crawl' ) ) ) {
            return self::TECHNICAL;
        }
        // titles, meta, alt, headings → fast on-page.
        return self::QUICK_WINS;
    }

    private static function fix_label( $type, $post_id ) {
        $labels = array(
            'title'            => __( 'Title tag', 'real-smart-seo' ),
            'meta_description' => __( 'Meta description', 'real-smart-seo' ),
            'content'          => __( 'Content', 'real-smart-seo' ),
            'alt_text'         => __( 'Image alt text', 'real-smart-seo' ),
        );
        $what = $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
        $name = $post_id ? get_the_title( $post_id ) : '';
        return $name ? sprintf( '%s — %s', $what, $name ) : $what;
    }

    private static function issue_label( $issue ) {
        $type = (string) ( $issue->issue_type ?? __( 'Issue', 'real-smart-seo' ) );
        $type = ucwords( str_replace( array( '_', '-' ), ' ', $type ) );
        $name = ! empty( $issue->post_id ) ? get_the_title( (int) $issue->post_id ) : '';
        return $name ? sprintf( '%s — %s', $type, $name ) : $type;
    }

    private static function excerpt( $text ) {
        $text = trim( wp_strip_all_tags( $text ) );
        return ( mb_strlen( $text ) > 120 ) ? mb_substr( $text, 0, 117 ) . '…' : $text;
    }

    /**
     * Service × city combinations the business serves but has no page for.
     * Bounded so a big services/cities matrix can't blow up the page.
     *
     * @return array item[]
     */
    public static function local_gaps() {
        if ( ! class_exists( 'RSSEO_Profile' ) ) {
            return array();
        }
        $p        = RSSEO_Profile::get();
        $services = array_slice( RSSEO_Profile::lines( $p['services'] ), 0, 6 );
        $cities   = array_slice( RSSEO_Profile::lines( $p['cities'] ), 0, 8 );
        if ( empty( $services ) || empty( $cities ) ) {
            return array();
        }

        // Existing page/post titles (lowercased) to test coverage against.
        $ids    = get_posts( array(
            'post_type'   => array( 'post', 'page', 'mfc_location' ),
            'post_status' => 'publish',
            'numberposts' => 500,
            'fields'      => 'ids',
        ) );
        $titles = array();
        foreach ( (array) $ids as $id ) {
            $titles[] = strtolower( (string) get_the_title( $id ) );
        }

        $builder_url = admin_url( 'admin.php?page=real-smart-seo&tab=content' );
        $gaps        = array();
        foreach ( $services as $service ) {
            $svc_token = strtolower( self::primary_token( $service ) );
            foreach ( $cities as $city ) {
                $city_token = strtolower( self::primary_token( $city ) );
                if ( '' === $svc_token || '' === $city_token ) {
                    continue;
                }
                $covered = false;
                foreach ( $titles as $t ) {
                    if ( false !== strpos( $t, $svc_token ) && false !== strpos( $t, $city_token ) ) {
                        $covered = true;
                        break;
                    }
                }
                if ( ! $covered ) {
                    $gaps[] = array(
                        'kind'       => 'gap',
                        'label'      => sprintf(
                            /* translators: 1: service, 2: city */
                            __( '%1$s in %2$s', 'real-smart-seo' ),
                            $service,
                            $city
                        ),
                        'detail'     => __( 'No page targets this service in this city yet.', 'real-smart-seo' ),
                        'status'     => RSSEO_Status::DETECTED,
                        'post_id'    => 0,
                        'fix_id'     => 0,
                        'action_url' => $builder_url,
                    );
                }
                if ( count( $gaps ) >= 24 ) {
                    return $gaps;
                }
            }
        }
        return $gaps;
    }

    /** First meaningful word of a phrase (drops a trailing state abbrev etc.). */
    private static function primary_token( $phrase ) {
        $phrase = trim( (string) $phrase );
        if ( '' === $phrase ) {
            return '';
        }
        $parts = preg_split( '/\s+/', $phrase );
        return $parts[0];
    }
}
