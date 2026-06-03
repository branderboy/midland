<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress-native site auditor.
 * Crawls all published posts/pages using WP APIs — no uploads needed.
 */
class RSSEO_Crawler {

    const THIN_CONTENT_WORDS  = 300;
    const STALE_DAYS          = 365;
    const TITLE_MAX_LENGTH    = 60;
    const TITLE_MIN_LENGTH    = 20;
    const META_MAX_LENGTH     = 160;
    const MAX_POSTS           = 500;

    /**
     * Run a full site audit. Returns audit ID.
     *
     * @return int|WP_Error Audit ID.
     */
    public static function run() {
        $audit_id = RSSEO_Database::create_audit();
        $seo_plugin = RSSEO_Settings::detect_seo_plugin();

        $posts = self::get_all_posts();
        $issues = array();

        // Per-post checks.
        $all_titles = array();
        $all_metas  = array();
        $all_linked = array(); // post IDs that are linked from somewhere

        foreach ( $posts as $post ) {
            $post_issues = self::audit_post( $post, $seo_plugin );
            $issues      = array_merge( $issues, $post_issues );

            // Collect titles/metas for cross-post duplicate checks.
            $title = self::get_seo_title( $post, $seo_plugin );
            $meta  = self::get_meta_desc( $post, $seo_plugin );

            if ( $title ) {
                $all_titles[ $post->ID ] = strtolower( trim( $title ) );
            }
            if ( $meta ) {
                $all_metas[ $post->ID ] = strtolower( trim( $meta ) );
            }

            // Collect all internal links from this post.
            $linked = self::extract_internal_post_ids( $post->post_content );
            foreach ( $linked as $linked_id ) {
                $all_linked[ $linked_id ] = true;
            }
        }

        // Cross-post: duplicate titles.
        $dup_title_issues = self::check_duplicates( $all_titles, 'title' );
        $issues = array_merge( $issues, $dup_title_issues );

        // Cross-post: duplicate metas.
        $dup_meta_issues = self::check_duplicates( $all_metas, 'meta_description' );
        $issues = array_merge( $issues, $dup_meta_issues );

        // Cross-post: orphaned pages (no other post links to them).
        foreach ( $posts as $post ) {
            if ( 'page' === $post->post_type && ! isset( $all_linked[ $post->ID ] ) ) {
                // Exclude homepage, shop, etc.
                if ( (int) get_option( 'page_on_front' ) !== $post->ID &&
                     (int) get_option( 'page_for_posts' ) !== $post->ID ) {
                    $issues[] = array(
                        'post_id'      => $post->ID,
                        'issue_type'   => 'orphaned_page',
                        'severity'     => 'medium',
                        'description'  => sprintf(
                            /* translators: %s: page title */
                            __( '"%s" has no internal links pointing to it — Google may not discover or prioritize it.', 'real-smart-seo' ),
                            $post->post_title
                        ),
                        'suggestion'   => __( 'Add an internal link to this page from a relevant post or your navigation menu.', 'real-smart-seo' ),
                        'auto_fixable' => 0,
                        'fix_field'    => '',
                        'fix_value'    => '',
                    );
                }
            }
        }

        // Save all issues.
        $counts = array( 'posts_checked' => count( $posts ), 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
        foreach ( $issues as $issue ) {
            $issue['audit_id']   = $audit_id;
            $issue['created_at'] = current_time( 'mysql' );
            RSSEO_Database::insert_audit_issue( $issue );
            $sev = $issue['severity'];
            if ( isset( $counts[ $sev ] ) ) {
                $counts[ $sev ]++;
            }
        }

        RSSEO_Database::complete_audit( $audit_id, $counts );

        // Allow pro plugin to run additional checks on the same audit.
        do_action( 'rsseo_after_audit', $audit_id, $posts, $seo_plugin );

        return $audit_id;
    }

    /**
     * Get all published posts and pages (up to MAX_POSTS).
     */
    public static function get_all_posts() {
        return get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => self::MAX_POSTS,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ) );
    }

    /**
     * Run all checks on a single post.
     */
    private static function audit_post( $post, $seo_plugin ) {
        $issues = array();
        $post_id = $post->ID;

        // 1. Missing SEO title.
        $seo_title = self::get_seo_title( $post, $seo_plugin );
        if ( empty( $seo_title ) ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'missing_title',
                'severity'    => 'high',
                'description' => sprintf(
                    /* translators: %s: post title */
                    __( '"%s" has no SEO title set.', 'real-smart-seo' ),
                    $post->post_title
                ),
                'suggestion'   => __( 'Set a unique, descriptive SEO title under 60 characters.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        } elseif ( strlen( $seo_title ) > self::TITLE_MAX_LENGTH ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'title_too_long',
                'severity'    => 'medium',
                'description' => sprintf(
                    /* translators: 1: post title, 2: char count */
                    __( '"%1$s" SEO title is %2$d characters — over the 60-character limit.', 'real-smart-seo' ),
                    $post->post_title,
                    strlen( $seo_title )
                ),
                'suggestion'   => __( 'Shorten the SEO title to under 60 characters to avoid truncation in search results.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        } elseif ( strlen( $seo_title ) < self::TITLE_MIN_LENGTH ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'title_too_short',
                'severity'    => 'low',
                'description' => sprintf(
                    /* translators: 1: post title, 2: char count */
                    __( '"%1$s" SEO title is only %2$d characters — too short to be descriptive.', 'real-smart-seo' ),
                    $post->post_title,
                    strlen( $seo_title )
                ),
                'suggestion'   => __( 'Expand the SEO title to 20–60 characters with your primary keyword.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        }

        // 2. Missing meta description.
        $meta_desc = self::get_meta_desc( $post, $seo_plugin );
        if ( empty( $meta_desc ) ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'missing_meta_description',
                'severity'    => 'high',
                'description' => sprintf(
                    /* translators: %s: post title */
                    __( '"%s" has no meta description.', 'real-smart-seo' ),
                    $post->post_title
                ),
                'suggestion'   => __( 'Write a compelling meta description under 160 characters that includes your primary keyword.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        } elseif ( strlen( $meta_desc ) > self::META_MAX_LENGTH ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'meta_too_long',
                'severity'    => 'medium',
                'description' => sprintf(
                    /* translators: 1: post title, 2: char count */
                    __( '"%1$s" meta description is %2$d characters — will be truncated in search results.', 'real-smart-seo' ),
                    $post->post_title,
                    strlen( $meta_desc )
                ),
                'suggestion'   => __( 'Trim the meta description to 160 characters or under.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        }

        // 3. Thin content.
        $word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
        if ( $word_count < self::THIN_CONTENT_WORDS && ! empty( $post->post_content ) ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'thin_content',
                'severity'    => 'high',
                'description' => sprintf(
                    /* translators: 1: post title, 2: word count */
                    __( '"%1$s" has only %2$d words — Google considers this thin content.', 'real-smart-seo' ),
                    $post->post_title,
                    $word_count
                ),
                'suggestion'   => __( 'Expand this page to at least 300 words. Add relevant details, FAQs, or service information.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        }

        // 4. H1 checks.
        $h1_count = preg_match_all( '/<h1[\s>]/i', $post->post_content );
        if ( 0 === $h1_count && ! empty( $post->post_content ) ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'missing_h1',
                'severity'    => 'high',
                'description' => sprintf(
                    /* translators: %s: post title */
                    __( '"%s" has no H1 heading in its content.', 'real-smart-seo' ),
                    $post->post_title
                ),
                'suggestion'   => __( 'Add an H1 heading that includes your primary keyword near the top of the page.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        } elseif ( $h1_count > 1 ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'multiple_h1',
                'severity'    => 'medium',
                'description' => sprintf(
                    /* translators: 1: post title, 2: H1 count */
                    __( '"%1$s" has %2$d H1 headings — only one H1 per page is recommended.', 'real-smart-seo' ),
                    $post->post_title,
                    $h1_count
                ),
                'suggestion'   => __( 'Keep one H1 as the main heading. Convert additional H1s to H2 or H3.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        }

        // 5. Images missing alt text.
        $img_issues = self::check_image_alts( $post );
        $issues     = array_merge( $issues, $img_issues );

        // 6. Stale content.
        $days_since_update = ( time() - strtotime( $post->post_modified ) ) / DAY_IN_SECONDS;
        if ( $days_since_update > self::STALE_DAYS && 'page' !== $post->post_type ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'stale_content',
                'severity'    => 'low',
                'description' => sprintf(
                    /* translators: 1: post title, 2: number of days */
                    __( '"%1$s" has not been updated in %2$d days.', 'real-smart-seo' ),
                    $post->post_title,
                    round( $days_since_update )
                ),
                'suggestion'   => __( 'Review and refresh this content — add new information, update statistics, and re-publish to signal freshness to Google.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        }

        // 7. No internal links out.
        if ( ! empty( $post->post_content ) ) {
            $internal_link_count = self::count_internal_links( $post->post_content );
            if ( 0 === $internal_link_count && $word_count > 200 ) {
                $issues[] = array(
                    'post_id'     => $post_id,
                    'issue_type'  => 'no_internal_links',
                    'severity'    => 'low',
                    'description' => sprintf(
                        /* translators: %s: post title */
                        __( '"%s" has no internal links — it\'s not contributing to your site\'s link structure.', 'real-smart-seo' ),
                        $post->post_title
                    ),
                    'suggestion'   => __( 'Add 2–3 internal links to related pages or services on your site.', 'real-smart-seo' ),
                    'auto_fixable' => 0,
                    'fix_field'    => '',
                    'fix_value'    => '',
                );
            }
        }

        // 8. Empty content.
        if ( empty( trim( $post->post_content ) ) ) {
            $issues[] = array(
                'post_id'     => $post_id,
                'issue_type'  => 'empty_content',
                'severity'    => 'critical',
                'description' => sprintf(
                    /* translators: %s: post title */
                    __( '"%s" has no content — this page will hurt your site\'s SEO.', 'real-smart-seo' ),
                    $post->post_title
                ),
                'suggestion'   => __( 'Add meaningful content or set this page to Draft/Private if it\'s not ready.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        }

        return $issues;
    }

    /**
     * Check all images in a post for missing alt text.
     */
    private static function check_image_alts( $post ) {
        $issues = array();

        // Check content images.
        preg_match_all( '/<img[^>]+>/i', $post->post_content, $img_matches );
        $missing_count = 0;
        foreach ( $img_matches[0] as $img_tag ) {
            if ( ! preg_match( '/alt=["\'][^"\']+["\']/', $img_tag ) ) {
                $missing_count++;
            }
        }

        if ( $missing_count > 0 ) {
            $issues[] = array(
                'post_id'     => $post->ID,
                'issue_type'  => 'missing_alt_text',
                'severity'    => 'medium',
                'description' => sprintf(
                    /* translators: 1: post title, 2: image count */
                    _n(
                        '"%1$s" has %2$d image with no alt text.',
                        '"%1$s" has %2$d images with no alt text.',
                        $missing_count,
                        'real-smart-seo'
                    ),
                    $post->post_title,
                    $missing_count
                ),
                'suggestion'   => __( 'Add descriptive alt text to all images. Include your keyword where it naturally fits.', 'real-smart-seo' ),
                'auto_fixable' => 0,
                'fix_field'    => '',
                'fix_value'    => '',
            );
        }

        // Also check featured image.
        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( $thumb_id ) {
            $alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
            if ( empty( $alt ) ) {
                $issues[] = array(
                    'post_id'     => $post->ID,
                    'issue_type'  => 'missing_featured_image_alt',
                    'severity'    => 'medium',
                    'description' => sprintf(
                        /* translators: %s: post title */
                        __( '"%s" featured image has no alt text.', 'real-smart-seo' ),
                        $post->post_title
                    ),
                    'suggestion'   => __( 'Add alt text to the featured image in the Media Library.', 'real-smart-seo' ),
                    'auto_fixable' => 1,
                    'fix_field'    => '_wp_attachment_image_alt:' . $thumb_id,
                    'fix_value'    => sanitize_text_field( $post->post_title ),
                );
            }
        }

        return $issues;
    }

    /**
     * Check for duplicate titles or meta descriptions across posts.
     */
    private static function check_duplicates( $values_by_post_id, $type ) {
        $issues    = array();
        $seen      = array();
        $dupes     = array();

        foreach ( $values_by_post_id as $post_id => $value ) {
            if ( isset( $seen[ $value ] ) ) {
                $dupes[ $value ][] = $post_id;
                $dupes[ $value ][] = $seen[ $value ];
            } else {
                $seen[ $value ] = $post_id;
            }
        }

        foreach ( $dupes as $value => $post_ids ) {
            $post_ids = array_unique( $post_ids );
            foreach ( $post_ids as $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post ) continue;

                if ( 'title' === $type ) {
                    $issues[] = array(
                        'post_id'     => $post_id,
                        'issue_type'  => 'duplicate_title',
                        'severity'    => 'high',
                        'description' => sprintf(
                            /* translators: %s: post title */
                            __( '"%s" has the same SEO title as another page — Google may not rank either well.', 'real-smart-seo' ),
                            $post->post_title
                        ),
                        'suggestion'   => __( 'Make every SEO title unique. Include the specific page topic and service/location.', 'real-smart-seo' ),
                        'auto_fixable' => 0,
                        'fix_field'    => '',
                        'fix_value'    => '',
                    );
                } else {
                    $issues[] = array(
                        'post_id'     => $post_id,
                        'issue_type'  => 'duplicate_meta',
                        'severity'    => 'medium',
                        'description' => sprintf(
                            /* translators: %s: post title */
                            __( '"%s" has a duplicate meta description.', 'real-smart-seo' ),
                            $post->post_title
                        ),
                        'suggestion'   => __( 'Write a unique meta description for each page.', 'real-smart-seo' ),
                        'auto_fixable' => 0,
                        'fix_field'    => '',
                        'fix_value'    => '',
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Extract WordPress post IDs from internal links in content.
     */
    private static function extract_internal_post_ids( $content ) {
        $site_url = home_url();
        $ids      = array();

        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
        foreach ( $matches[1] as $url ) {
            if ( strpos( $url, $site_url ) === 0 || ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) ) {
                $post_id = url_to_postid( $url );
                if ( $post_id ) {
                    $ids[] = $post_id;
                }
            }
        }

        return $ids;
    }

    /**
     * Count internal links in post content.
     */
    private static function count_internal_links( $content ) {
        $site_url = home_url();
        $count    = 0;

        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
        foreach ( $matches[1] as $url ) {
            if ( strpos( $url, $site_url ) === 0 || ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the SEO title for a post (Yoast, RankMath, or post_title).
     */
    public static function get_seo_title( $post, $seo_plugin ) {
        if ( 'yoast' === $seo_plugin ) {
            $title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
            return $title ?: $post->post_title;
        }
        if ( 'rankmath' === $seo_plugin ) {
            $title = get_post_meta( $post->ID, 'rank_math_title', true );
            return $title ?: $post->post_title;
        }
        return $post->post_title;
    }

    /**
     * Get the meta description for a post.
     */
    public static function get_meta_desc( $post, $seo_plugin ) {
        if ( 'yoast' === $seo_plugin ) {
            return get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
        }
        if ( 'rankmath' === $seo_plugin ) {
            return get_post_meta( $post->ID, 'rank_math_description', true );
        }
        return get_post_meta( $post->ID, '_rsseo_meta_desc', true );
    }

    /**
     * Apply an auto-fixable audit issue.
     *
     * @param int $issue_id
     * @return true|WP_Error
     */
    public static function apply_fix( $issue_id ) {
        $issue = RSSEO_Database::get_audit_issue( $issue_id );

        if ( ! $issue ) {
            return new WP_Error( 'not_found', __( 'Issue not found.', 'real-smart-seo' ) );
        }
        if ( ! $issue->auto_fixable ) {
            return new WP_Error( 'not_fixable', __( 'This issue cannot be auto-fixed.', 'real-smart-seo' ) );
        }
        if ( $issue->fixed ) {
            return new WP_Error( 'already_fixed', __( 'Already fixed.', 'real-smart-seo' ) );
        }

        // fix_field format: "meta_key:attachment_id" or just "meta_key"
        $parts    = explode( ':', $issue->fix_field, 2 );
        $field    = $parts[0];
        $extra_id = isset( $parts[1] ) ? (int) $parts[1] : 0;

        if ( $extra_id > 0 ) {
            // Attachment-level meta.
            update_post_meta( $extra_id, $field, sanitize_text_field( $issue->fix_value ) );
        } elseif ( $issue->post_id > 0 ) {
            update_post_meta( $issue->post_id, $field, sanitize_text_field( $issue->fix_value ) );
        } else {
            return new WP_Error( 'no_target', __( 'No target post for this fix.', 'real-smart-seo' ) );
        }

        RSSEO_Database::apply_audit_fix( $issue_id );
        return true;
    }
}
