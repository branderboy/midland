<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Pro_Analyzer {

    /**
     * Run the Pro analysis on top of a completed base scan.
     *
     * @param int $scan_id  Base plugin scan ID.
     * @return int|WP_Error Pro report ID or error.
     */
    public static function analyze( $scan_id ) {
        // The version constant gate in the bootstrap only proves the base
        // plugin is present, not that this specific version still ships the
        // classes/methods we call. Guard the hard dependencies so a base
        // mismatch surfaces a clean error instead of a fatal.
        if ( ! class_exists( 'RSSEO_Database' ) || ! class_exists( 'RSSEO_Claude_API' ) || ! method_exists( 'RSSEO_Claude_API', 'ask' ) ) {
            return new WP_Error( 'base_incompatible', __( 'The installed Real Smart SEO base plugin is missing required components — update it to a compatible version.', 'real-smart-seo' ) );
        }

        $base_scan = RSSEO_Database::get_scan( $scan_id );
        if ( ! $base_scan ) {
            return new WP_Error( 'not_found', __( 'Scan data not found.', 'real-smart-seo' ) );
        }

        $pro_scan = RSSEO_Pro_Database::get_pro_scan( $scan_id );
        if ( ! $pro_scan ) {
            // No Pro data was collected for this scan — return null so RSSEO_Jobs
            // falls back to the base analyzer instead of erroring out.
            return null;
        }

        RSSEO_Database::update_scan( $scan_id, array( 'status' => 'analyzing' ) );

        $prompt = self::build_pro_prompt( $base_scan, $pro_scan );
        $result = RSSEO_Claude_API::ask( $prompt, $scan_id );

        if ( is_wp_error( $result ) ) {
            RSSEO_Database::update_scan( $scan_id, array( 'status' => 'error' ) );
            return $result;
        }

        // Null-coalesce every key — a differently-shaped success array from a
        // newer/older base API must not throw undefined-index warnings.
        $raw_text = (string) ( $result['text'] ?? '' );
        $parsed   = self::parse_pro_report( $raw_text );

        // Store report via base plugin DB.
        $report_id = RSSEO_Database::insert_report( array(
            'scan_id'         => $scan_id,
            'report_raw'      => $raw_text,
            'report_html'     => '',
            'issues_critical' => $parsed['counts']['critical'],
            'issues_high'     => $parsed['counts']['high'],
            'issues_medium'   => $parsed['counts']['medium'],
            'issues_low'      => $parsed['counts']['low'],
            'fixes_available' => $parsed['fixes_available'],
            'model'           => (string) ( $result['model'] ?? '' ),
            'tokens_used'     => (int) ( $result['input_tokens'] ?? 0 ) + (int) ( $result['output_tokens'] ?? 0 ),
            'created_at'      => current_time( 'mysql' ),
        ) );

        // Store base WP_FIX items.
        if ( ! empty( $parsed['fixes'] ) ) {
            foreach ( $parsed['fixes'] as $fix ) {
                RSSEO_Database::insert_fix( array(
                    'report_id'  => $report_id,
                    'post_id'    => $fix['post_id'],
                    'fix_type'   => $fix['fix_type'],
                    'field_key'  => $fix['field_key'],
                    'old_value'  => $fix['old_value'],
                    'new_value'  => $fix['new_value'],
                    'created_at' => current_time( 'mysql' ),
                ) );
            }
        }

        // Store pro-specific items.
        RSSEO_Pro_Schema::parse_from_report( $raw_text, $report_id );
        RSSEO_Pro_Schema::parse_backlinks_from_report( $raw_text, $report_id );

        RSSEO_Database::update_scan( $scan_id, array( 'status' => 'complete' ) );

        return $report_id;
    }

    private static function build_pro_prompt( $base_scan, $pro_scan ) {
        $seo_plugin = RSSEO_Settings::detect_seo_plugin();
        $site_url   = get_site_url();
        $site_name  = get_bloginfo( 'name' );
        $site_desc  = get_bloginfo( 'description' );

        $prompt  = "You are an expert local SEO strategist specializing in small and medium local businesses (contractors, service businesses, home services).\n\n";
        $prompt .= "Analyze the following data for \"{$site_name}\" — {$site_desc}\n";
        $prompt .= "Site URL: {$site_url}\n";
        $prompt .= "Active SEO plugin: {$seo_plugin}\n\n";
        $prompt .= "---\n\n";

        // Base scan data.
        if ( ! empty( $base_scan->screaming_frog ) ) {
            $prompt .= "## SCREAMING FROG CRAWL DATA\n" . $base_scan->screaming_frog . "\n\n";
        }
        if ( ! empty( $base_scan->gsc_data ) ) {
            $prompt .= "## GOOGLE SEARCH CONSOLE DATA\n" . $base_scan->gsc_data . "\n\n";
        }
        if ( ! empty( $base_scan->ga_data ) ) {
            $prompt .= "## GOOGLE ANALYTICS DATA\n" . $base_scan->ga_data . "\n\n";
        }
        if ( ! empty( $base_scan->pagespeed_data ) ) {
            $prompt .= "## PAGESPEED / CORE WEB VITALS\n" . $base_scan->pagespeed_data . "\n\n";
        }

        // Pro scan data.
        if ( ! empty( $pro_scan->keywords_input ) ) {
            $prompt .= "## TARGET KEYWORDS\nLocation: " . ( $pro_scan->location_input ?: 'not specified' ) . "\n" . $pro_scan->keywords_input . "\n\n";
        }
        if ( ! empty( $pro_scan->dataforseo_data ) ) {
            $prompt .= $pro_scan->dataforseo_data . "\n\n";
        }
        if ( ! empty( $pro_scan->competitor_sf_data ) ) {
            $prompt .= "## COMPETITOR CRAWL DATA (Screaming Frog)\n" . $pro_scan->competitor_sf_data . "\n\n";
        }
        if ( ! empty( $pro_scan->gmb_data ) ) {
            $prompt .= "## GOOGLE BUSINESS PROFILE (GMB) DATA\n" . $pro_scan->gmb_data . "\n\n";
        }
        if ( ! empty( $pro_scan->reviews_data ) ) {
            $prompt .= "## CUSTOMER REVIEWS\n" . $pro_scan->reviews_data . "\n\n";
        }
        if ( ! empty( $pro_scan->perplexity_data ) ) {
            $prompt .= "## COMPETITOR / MARKET RESEARCH (PERPLEXITY)\n" . $pro_scan->perplexity_data . "\n\n";
        }

        $prompt .= "---\n\n";
        $prompt .= self::get_pro_output_instructions( $seo_plugin );

        return $prompt;
    }

    private static function get_pro_output_instructions( $seo_plugin ) {
        $meta_title_key = 'post_title';
        $meta_desc_key  = 'post_content';

        if ( 'yoast' === $seo_plugin ) {
            $meta_title_key = '_yoast_wpseo_title';
            $meta_desc_key  = '_yoast_wpseo_metadesc';
        } elseif ( 'rankmath' === $seo_plugin ) {
            $meta_title_key = 'rank_math_title';
            $meta_desc_key  = 'rank_math_description';
        }

        return <<<INSTRUCTIONS
Produce your full local SEO report in this exact structure.

# REAL SMART SEO PRO REPORT

## EXECUTIVE SUMMARY
[3–5 sentences on overall local SEO health, top opportunities, and biggest risks]

## LOCAL SEO HEALTH SCORE
Score: [X/100]
[2–3 sentences explaining the score based on the data]

## ISSUES
For each issue use this format:
### [PRIORITY: CRITICAL|HIGH|MEDIUM|LOW] — [Issue Title]
**What:** [Description]
**Why it matters:** [Local SEO impact]
**Affected pages/areas:** [Specific URLs, GMB, or sitewide]
**Fix:** [Step-by-step instructions]
[If WordPress-fixable:]
**WP_FIX:** post_id=[id] | fix_type=[title|meta_description|content|alt_text] | field_key=[{$meta_title_key}|{$meta_desc_key}|post_title|post_content] | old_value=[current] | new_value=[replacement]

## REVIEW SENTIMENT ANALYSIS
[Only if reviews data was provided]
**Overall sentiment:** [Positive/Neutral/Negative — X%/Y%/Z%]
**Top positive themes:** [What customers love — with examples from reviews]
**Top negative themes:** [What customers complain about — with examples]
**Keywords mentioned in reviews:** [List of keywords Google may use as signals]
**Response rate assessment:** [Are reviews being responded to?]
**Action items:** [Specific things to fix or highlight based on reviews]

## GOOGLE TRENDS INSIGHTS
[Only if trends data was provided]
**Rising keywords to target:** [List with trend direction]
**Seasonal opportunities:** [Month-by-month recommendations]
**Content to create NOW:** [Specific pages/posts based on trends]

## SCHEMA MARKUP TO IMPLEMENT
For each schema block use this exact format — do not skip the SCHEMA_BLOCK line:
**[Schema type] — [What page/section]**
[Explanation of why this schema is needed]
SCHEMA_BLOCK: type=[LocalBusiness|Service|Review|FAQ] | post_id=[post ID or 0 for sitewide] | json=[{complete valid JSON-LD object — no line breaks inside}]

## ABOUT PAGE OPTIMIZATION
**Current issues:** [What's wrong with the current About page]
**Rewrite:** [Complete rewritten About page content optimized for local SEO — include entity mentions, service areas, trust signals, local authority references]
**WP_FIX:** post_id=[about page post ID if known, else 0] | fix_type=[content] | field_key=[post_content] | old_value=[current about content summary] | new_value=[complete rewritten content]

## EXTERNAL LINKING STRATEGY
List 5–8 specific external links to add across the site:
- **[Page to add link to]:** Link to [specific URL or type of site — city permit office, supplier, trade association] with anchor text "[suggested anchor text]" — [why this helps]

## BACKLINK TARGETS
Focus exclusively on high-authority LOCAL targets: .gov, .org, nonprofits, city/county resources, local chambers, neighborhood orgs.
For each target use this exact format:
BACKLINK: priority=[1-20] | type=[.gov|.org|nonprofit|chamber|local|directory] | name=[Organization Name] | url=[URL if known, else blank] | rationale=[1 sentence on why this link matters for local SEO]

## PEOPLE ALSO ASK OPPORTUNITIES
List 5–8 PAA questions your site should answer, based on your keywords and market:
- **Question:** [PAA question]
  **Target page:** [Which existing page or new page to add this to]
  **Answer to add:** [The exact concise answer to add to that page — 2–4 sentences]

## FEATURED SNIPPET TARGETS
List 3–5 queries where you can win a featured snippet:
- **Query:** [Search query]
  **Current ranking:** [Position if known from GSC data]
  **Content change:** [Exact addition/restructure to win the snippet]
  **Target page:** [URL]

## ACTION PLAN
Numbered list, highest priority first:
1. [CRITICAL] What to do — one line
2. [HIGH] ...
(continue for all items)

## 30-DAY QUICK WINS
3 things that will move the needle fastest in the next 30 days, specific to this business.

---
Important:
- Reference actual data from the uploads — real URLs, real review quotes, real keyword positions.
- SCHEMA_BLOCK json must be complete valid JSON-LD on a single line.
- BACKLINK targets must be specific to this business's trade and location — no generic directories.
- Active SEO plugin: {$seo_plugin}
INSTRUCTIONS;
    }

    private static function parse_pro_report( $raw ) {
        $counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
        $fixes  = array();

        preg_match_all( '/###\s+\[PRIORITY:\s*(CRITICAL|HIGH|MEDIUM|LOW)\]/i', $raw, $pm );
        if ( ! empty( $pm[1] ) ) {
            foreach ( $pm[1] as $p ) {
                $key = strtolower( $p );
                if ( isset( $counts[ $key ] ) ) {
                    $counts[ $key ]++;
                }
            }
        }

        preg_match_all( '/\*\*WP_FIX:\*\*\s*(.+)/i', $raw, $fix_matches );
        foreach ( $fix_matches[1] as $fix_line ) {
            // Mirror RSSEO_Analyzer::parse_report(): the old /(\w+)=\[([^\]]*)\]/
            // pattern broke on meta keys with hyphens and silently TRUNCATED any
            // new_value that itself contained "]" (common in titles/HTML/JSON),
            // then wrote the truncated value to live posts. Split on " | " and
            // capture new_value to end-of-line so it can't be truncated.
            $fix_line = trim( $fix_line );
            $parts    = array();

            if ( preg_match( '/new_value\s*=\s*(.*)$/i', $fix_line, $mm ) ) {
                $val = trim( $mm[1] );
                if ( '' !== $val && '[' === $val[0] && ']' === substr( $val, -1 ) ) {
                    $val = substr( $val, 1, -1 );
                }
                $parts['new_value'] = $val;
                $head = (string) preg_replace( '/\|\s*new_value\s*=.*$/i', '', $fix_line );
            } else {
                $head = $fix_line;
            }

            foreach ( preg_split( '/\s*\|\s*/', $head ) as $segment ) {
                if ( preg_match( '/^([\w-]+)\s*=\s*\[?(.*?)\]?\s*$/', trim( $segment ), $m ) ) {
                    $parts[ strtolower( trim( $m[1] ) ) ] = trim( $m[2] );
                }
            }

            $fix = self::validate_fix( $parts );
            if ( $fix ) {
                $fixes[] = $fix;
            }
        }

        return array(
            'counts'          => $counts,
            'fixes'           => $fixes,
            'fixes_available' => count( $fixes ),
        );
    }

    /**
     * Validate + normalize one parsed WP_FIX line before it becomes a writable
     * fix — mirrors RSSEO_Analyzer::validate_fix(), which is private to the base
     * plugin and therefore can't be reused. Returns a safe fix array, or null to
     * drop a malformed/unsafe suggestion. Guarantees fix_type is one of the four
     * known types, the target post exists, field_key is allow-listed per type
     * (never an arbitrary meta key), and the new value is sanitized + length-capped.
     */
    private static function validate_fix( $parts ) {
        $type = strtolower( sanitize_key( $parts['fix_type'] ?? '' ) );
        if ( ! in_array( $type, array( 'title', 'meta_description', 'content', 'alt_text' ), true ) ) {
            return null;
        }

        $post_id = isset( $parts['post_id'] ) ? (int) $parts['post_id'] : 0;
        if ( $post_id <= 0 || ! get_post( $post_id ) ) {
            return null; // must target a real post
        }

        $new = (string) ( $parts['new_value'] ?? '' );
        if ( '' === trim( $new ) ) {
            return null;
        }

        $key_in = sanitize_text_field( $parts['field_key'] ?? '' );

        switch ( $type ) {
            case 'title':
                $allow = array( 'post_title', '_yoast_wpseo_title', 'rank_math_title', '_aioseo_title', '_seopress_titles_title' );
                $field = in_array( $key_in, $allow, true ) ? $key_in : 'post_title';
                $new   = self::trim_len( wp_strip_all_tags( $new ), 200 );
                break;

            case 'meta_description':
                // No safe default — we can't guess which SEO plugin owns the field.
                $allow = array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_seopress_titles_desc' );
                if ( ! in_array( $key_in, $allow, true ) ) {
                    return null;
                }
                $field = $key_in;
                $new   = self::trim_len( wp_strip_all_tags( $new ), 320 );
                break;

            case 'content':
                $field = 'post_content';
                $new   = wp_kses_post( $new );
                break;

            case 'alt_text':
                $field = '_wp_attachment_image_alt';
                $new   = self::trim_len( wp_strip_all_tags( $new ), 300 );
                break;

            default:
                return null;
        }

        if ( '' === trim( (string) $new ) ) {
            return null;
        }

        return array(
            'post_id'   => $post_id,
            'fix_type'  => $type,
            'field_key' => $field,
            'old_value' => (string) ( $parts['old_value'] ?? '' ),
            'new_value' => $new,
        );
    }

    /** Multibyte-safe length cap. */
    private static function trim_len( $str, $max ) {
        $str = (string) $str;
        return function_exists( 'mb_substr' ) ? mb_substr( $str, 0, $max ) : substr( $str, 0, $max );
    }
}
