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
        $base_scan = RSSEO_Database::get_scan( $scan_id );
        $pro_scan  = RSSEO_Pro_Database::get_pro_scan( $scan_id );

        if ( ! $base_scan || ! $pro_scan ) {
            return new WP_Error( 'not_found', __( 'Scan data not found.', 'real-smart-seo-pro' ) );
        }

        RSSEO_Database::update_scan( $scan_id, array( 'status' => 'analyzing' ) );

        $prompt = self::build_pro_prompt( $base_scan, $pro_scan );
        $result = RSSEO_Claude_API::ask( $prompt, $scan_id );

        if ( is_wp_error( $result ) ) {
            RSSEO_Database::update_scan( $scan_id, array( 'status' => 'error' ) );
            return $result;
        }

        $raw_text = $result['text'];
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
            'model'           => $result['model'],
            'tokens_used'     => $result['input_tokens'] + $result['output_tokens'],
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
            preg_match_all( '/(\w+)=\[([^\]]*)\]/', $fix_line, $kv, PREG_SET_ORDER );
            $parts = array();
            foreach ( $kv as $m ) {
                $parts[ $m[1] ] = $m[2];
            }
            if ( ! empty( $parts['fix_type'] ) && ! empty( $parts['new_value'] ) ) {
                $fixes[] = array(
                    'post_id'   => ! empty( $parts['post_id'] ) ? (int) $parts['post_id'] : 0,
                    'fix_type'  => sanitize_text_field( $parts['fix_type'] ),
                    'field_key' => sanitize_text_field( $parts['field_key'] ?? $parts['fix_type'] ),
                    'old_value' => $parts['old_value'] ?? '',
                    'new_value' => $parts['new_value'],
                );
            }
        }

        return array(
            'counts'          => $counts,
            'fixes'           => $fixes,
            'fixes_available' => count( $fixes ),
        );
    }
}
