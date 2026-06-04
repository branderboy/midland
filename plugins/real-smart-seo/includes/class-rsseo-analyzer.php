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
        $result = RSSEO_AI_Client::ask( $prompt, $scan_id );

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
        $dropped = 0;
        preg_match_all( '/\*\*WP_FIX:\*\*\s*(.+)/i', $raw, $fix_matches );
        foreach ( $fix_matches[1] as $fix_line ) {
            $fix_line = trim( $fix_line );
            $parts    = array();

            // new_value is always last and may itself contain "|" or "[]" — capture
            // everything after new_value= to end of line so it can't be truncated,
            // then parse the remaining key=value segments normally.
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
            } else {
                $dropped++;
            }
        }

        return array(
            'counts'          => $counts,
            'fixes'           => $fixes,
            'fixes_available' => count( $fixes ),
            'fixes_dropped'   => $dropped,
        );
    }

    /**
     * Validate + normalize one parsed WP_FIX line before it becomes a writable
     * fix. Returns a safe fix array, or null to drop a malformed/unsafe suggestion
     * (the reviewer flagged the old parser for applying wrong fixes). Guarantees:
     *   - fix_type is one of the four known types,
     *   - the target post actually exists,
     *   - field_key is allow-listed per type (never an arbitrary meta key),
     *   - the new value is non-empty, sanitized, and length-capped.
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
                $html .= '<p class="rsseo-list-item">' . self::inline_markdown( $m[1] ) . '</p>' . "\n";
                continue;
            }

            // Bullet list item.
            if ( preg_match( '/^[-*]\s+(.+)/', $line, $m ) ) {
                $html .= '<p class="rsseo-bullet-item">&bull; ' . self::inline_markdown( $m[1] ) . '</p>' . "\n";
                continue;
            }

            // Empty line → paragraph break.
            if ( '' === $line ) {
                $html .= '<br>' . "\n";
                continue;
            }

            // Default: paragraph with inline markdown.
            $html .= '<p>' . self::inline_markdown( $line ) . '</p>' . "\n";
        }

        return $html;
    }

    /**
     * Convert inline markdown (**bold**, *italic*, `code`) to HTML.
     * esc_html() is applied inside each callback so special characters in
     * the captured text are escaped before the HTML tags are wrapped around
     * them — avoids the double-encoding that happened when esc_html() ran on
     * the whole string first (e.g. & in **this & that** rendered as &amp;).
     */
    private static function inline_markdown( $text ) {
        // Bold: **text**
        $text = preg_replace_callback( '/\*\*(.+?)\*\*/s', function ( $m ) {
            return '<strong>' . esc_html( $m[1] ) . '</strong>';
        }, $text );
        // Italic: *text*
        $text = preg_replace_callback( '/\*(.+?)\*/s', function ( $m ) {
            return '<em>' . esc_html( $m[1] ) . '</em>';
        }, $text );
        // Code: `text`
        $text = preg_replace_callback( '/`(.+?)`/s', function ( $m ) {
            return '<code>' . esc_html( $m[1] ) . '</code>';
        }, $text );
        // Escape any plain text not wrapped in a tag, then allow only the
        // three tags we just produced.
        return wp_kses( $text, array(
            'strong' => array(),
            'em'     => array(),
            'code'   => array(),
        ) );
    }
}
