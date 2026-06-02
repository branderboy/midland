<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Analyzer {

    /**
     * Run full analysis on a scan and store the report.
     *
     * @param int $scan_id
     * @return int|WP_Error Report ID or error.
     */
    public static function analyze( $scan_id ) {
        $scan = RSSEO_Database::get_scan( $scan_id );
        if ( ! $scan ) {
            return new WP_Error( 'not_found', __( 'Scan not found.', 'real-smart-seo' ) );
        }

        RSSEO_Database::update_scan( $scan_id, array( 'status' => 'analyzing' ) );

        $prompt = self::build_prompt( $scan );
        $result = RSSEO_Claude_API::ask( $prompt, $scan_id );

        if ( is_wp_error( $result ) ) {
            RSSEO_Database::update_scan( $scan_id, array( 'status' => 'error' ) );
            return $result;
        }

        $raw_text   = $result['text'];
        $parsed     = self::parse_report( $raw_text );
        $report_html = self::render_html( $parsed, $raw_text );

        $report_id = RSSEO_Database::insert_report( array(
            'scan_id'         => $scan_id,
            'report_raw'      => $raw_text,
            'report_html'     => $report_html,
            'issues_critical' => $parsed['counts']['critical'],
            'issues_high'     => $parsed['counts']['high'],
            'issues_medium'   => $parsed['counts']['medium'],
            'issues_low'      => $parsed['counts']['low'],
            'fixes_available' => $parsed['fixes_available'],
            'model'           => $result['model'],
            'tokens_used'     => $result['input_tokens'] + $result['output_tokens'],
            'created_at'      => current_time( 'mysql' ),
        ) );

        // Store individual fixable items.
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

        RSSEO_Database::update_scan( $scan_id, array( 'status' => 'complete' ) );

        return $report_id;
    }

    /**
     * Build the full analysis prompt from scan data.
     */
    private static function build_prompt( $scan ) {
        $seo_plugin = RSSEO_Settings::detect_seo_plugin();
        $site_url   = get_site_url();
        $site_name  = get_bloginfo( 'name' );

        $prompt  = "You are an expert SEO analyst. Analyze the following data for the WordPress site \"{$site_name}\" ({$site_url}) and produce a comprehensive SEO report.\n\n";
        $prompt .= "Active SEO plugin: {$seo_plugin}\n\n";
        $prompt .= "---\n\n";

        if ( ! empty( $scan->screaming_frog ) ) {
            $prompt .= "## SCREAMING FROG CRAWL DATA\n";
            $prompt .= $scan->screaming_frog . "\n\n";
        }

        if ( ! empty( $scan->gsc_data ) ) {
            $prompt .= "## GOOGLE SEARCH CONSOLE DATA\n";
            $prompt .= $scan->gsc_data . "\n\n";
        }

        if ( ! empty( $scan->ga_data ) ) {
            $prompt .= "## GOOGLE ANALYTICS DATA\n";
            $prompt .= $scan->ga_data . "\n\n";
        }

        if ( ! empty( $scan->pagespeed_data ) ) {
            $prompt .= "## PAGESPEED / CORE WEB VITALS DATA\n";
            $prompt .= $scan->pagespeed_data . "\n\n";
        }

        $prompt .= "---\n\n";
        $prompt .= self::get_output_instructions( $seo_plugin );

        return $prompt;
    }

    private static function get_output_instructions( $seo_plugin ) {
        $fix_instructions = 'none';
        if ( 'yoast' === $seo_plugin ) {
            $fix_instructions = 'Yoast SEO (meta fields: _yoast_wpseo_title, _yoast_wpseo_metadesc)';
        } elseif ( 'rankmath' === $seo_plugin ) {
            $fix_instructions = 'Rank Math (meta fields: rank_math_title, rank_math_description)';
        }

        return <<<INSTRUCTIONS
Produce your full report in the following exact structure. Do not deviate from this format.

# REAL SMART SEO REPORT

## EXECUTIVE SUMMARY
[2–4 sentences summarizing the overall SEO health, biggest wins, and biggest risks]

## ISSUES

For each issue use this format:
### [PRIORITY: CRITICAL|HIGH|MEDIUM|LOW] — [Issue Title]
**What:** [Clear description of the issue]
**Why it matters:** [Impact on rankings/traffic]
**Affected pages:** [URL or page title, comma-separated. Use "sitewide" if it affects all pages]
**Fix:** [Exact step-by-step instructions to fix it]
[If this is directly fixable in WordPress and you have the exact new value, add this line:]
**WP_FIX:** post_id=[post ID or 0 if unknown] | fix_type=[title|meta_description|content|alt_text] | field_key=[exact meta key or "post_title" or "post_content"] | old_value=[current value] | new_value=[exact replacement text]

## ACTION PLAN
List all issues from highest to lowest priority as a numbered checklist. Format:
1. [CRITICAL] Issue title — one sentence on what to do
2. [HIGH] ...
(continue for all issues)

## OPPORTUNITIES
List 3–5 specific SEO opportunities based on the data — things that aren't broken but could be improved to increase traffic or rankings.

## QUICK WINS
List 3 things that can be done in under 30 minutes that will have the most immediate impact.

---
Notes:
- Be specific. Reference actual URLs, page titles, keywords, and numbers from the data.
- For WP_FIX lines: only include if you are 100% confident in the new value. Leave out if unsure.
- Active SEO plugin: {$fix_instructions}
INSTRUCTIONS;
    }

    /**
     * Parse the raw report text to extract counts and fixable items.
     */
    private static function parse_report( $raw ) {
        $counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
        $fixes  = array();

        // Count priorities.
        preg_match_all( '/###\s+\[PRIORITY:\s*(CRITICAL|HIGH|MEDIUM|LOW)\]/i', $raw, $priority_matches );
        if ( ! empty( $priority_matches[1] ) ) {
            foreach ( $priority_matches[1] as $p ) {
                $key = strtolower( $p );
                if ( isset( $counts[ $key ] ) ) {
                    $counts[ $key ]++;
                }
            }
        }

        // Extract WP_FIX lines.
        // Format: **WP_FIX:** post_id=[…] | fix_type=[…] | field_key=[…] | old_value=[…] | new_value=[…]
        // We split on " | " first, then strip the key=[…] wrapper for each segment.
        // This avoids the old \w+=\[…\] regex which broke on meta keys with hyphens,
        // values containing brackets, or AI output with minor whitespace variation.
        preg_match_all( '/\*\*WP_FIX:\*\*\s*(.+)/i', $raw, $fix_matches );
        foreach ( $fix_matches[1] as $fix_line ) {
            $parts    = array();
            $segments = preg_split( '/\s*\|\s*/', trim( $fix_line ) );
            foreach ( $segments as $segment ) {
                // Each segment looks like: key=[value]  (value may be empty or contain any chars)
                if ( preg_match( '/^([\w-]+)\s*=\s*\[?(.*?)\]?\s*$/', trim( $segment ), $m ) ) {
                    $parts[ strtolower( trim( $m[1] ) ) ] = trim( $m[2] );
                }
            }
            if ( ! empty( $parts['fix_type'] ) && isset( $parts['new_value'] ) && '' !== $parts['new_value'] ) {
                $fixes[] = array(
                    'post_id'   => isset( $parts['post_id'] ) ? (int) $parts['post_id'] : 0,
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

    /**
     * Convert raw markdown report to HTML for display in the admin report-detail view.
     * Handles the structured headings, bold labels, and WP_FIX lines produced by the AI.
     *
     * @param array $parsed_data  Result of parse_report() — not used directly here but
     *                            kept as the parameter signature for consistency.
     * @param string $raw         The raw markdown text to convert.
     * @return string             Safe HTML string.
     */
    private static function render_html( $parsed_data, $raw = '' ) {
        if ( '' === $raw ) {
            return '';
        }

        $html  = '';
        $lines = explode( "\n", $raw );

        foreach ( $lines as $line ) {
            $line = rtrim( $line );

            // Skip WP_FIX machine-readable lines — not meant for human display.
            if ( preg_match( '/^\*\*WP_FIX:\*\*/i', $line ) ) {
                continue;
            }

            // H1: # REAL SMART SEO REPORT
            if ( preg_match( '/^#\s+(.+)/', $line, $m ) ) {
                $html .= '<h1>' . esc_html( $m[1] ) . '</h1>' . "\n";
                continue;
            }

            // H2: ## SECTION HEADING
            if ( preg_match( '/^##\s+(.+)/', $line, $m ) ) {
                $html .= '<h2>' . esc_html( $m[1] ) . '</h2>' . "\n";
                continue;
            }

            // H3: ### [PRIORITY: X] — Issue Title
            if ( preg_match( '/^###\s+(.+)/', $line, $m ) ) {
                $title = $m[1];
                // Wrap priority badge.
                $title_html = preg_replace_callback(
                    '/\[PRIORITY:\s*(CRITICAL|HIGH|MEDIUM|LOW)\]/i',
                    function ( $bm ) {
                        $p = strtolower( $bm[1] );
                        return '<span class="rsseo-priority rsseo-priority-' . esc_attr( $p ) . '">' . esc_html( strtoupper( $p ) ) . '</span>';
                    },
                    esc_html( $title )
                );
                $html .= '<h3>' . $title_html . '</h3>' . "\n";
                continue;
            }

            // Horizontal rule.
            if ( preg_match( '/^---+$/', $line ) ) {
                $html .= '<hr>' . "\n";
                continue;
            }

            // Numbered list item: "1. text"
            if ( preg_match( '/^\d+\.\s+(.+)/', $line, $m ) ) {
                $html .= '<p class="rsseo-list-item">' . wp_kses_post( self::inline_markdown( $m[1] ) ) . '</p>' . "\n";
                continue;
            }

            // Bullet list item.
            if ( preg_match( '/^[-*]\s+(.+)/', $line, $m ) ) {
                $html .= '<p class="rsseo-bullet-item">&bull; ' . wp_kses_post( self::inline_markdown( $m[1] ) ) . '</p>' . "\n";
                continue;
            }

            // Empty line → paragraph break.
            if ( '' === $line ) {
                $html .= '<br>' . "\n";
                continue;
            }

            // Default: paragraph with inline markdown.
            $html .= '<p>' . wp_kses_post( self::inline_markdown( $line ) ) . '</p>' . "\n";
        }

        return $html;
    }

    /**
     * Convert inline markdown (**bold**, *italic*, `code`) to HTML.
     */
    private static function inline_markdown( $text ) {
        $text = esc_html( $text );
        // Bold: **text**
        $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
        // Italic: *text*
        $text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
        // Code: `text`
        $text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
        return $text;
    }
}
