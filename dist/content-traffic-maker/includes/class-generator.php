<?php
/**
 * Video brief generator — 6 videos: 3 commercial + 3 residential.
 * Every video has a title. Language is simple and social-native.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTM_Generator {

    const ENDPOINT = 'https://api.perplexity.ai/chat/completions';

    public static function fields() {
        return array(
            // ── COMMERCIAL ─────────────────────────────────────────
            'com_seo_keyword',
            'com_seo_search_intent',
            'com_seo_video_title',
            'com_seo_hook',
            'com_seo_cta',
            'com_seo_difficulty',
            'com_seo_priority',
            'com_seo_example_title',
            'com_seo_example_url',

            'com_offer_video_title',
            'com_offer_name',
            'com_offer_audience',
            'com_offer_hook',
            'com_offer_cta',
            'com_offer_priority',
            'com_offer_example_title',
            'com_offer_example_url',

            'com_viral_video_title',
            'com_viral_trending_format',
            'com_viral_concept',
            'com_viral_opening_shot',
            'com_viral_trend_reason',
            'com_viral_cta',
            'com_viral_priority',
            'com_viral_example_title',
            'com_viral_example_url',

            // ── RESIDENTIAL ────────────────────────────────────────
            'res_seo_keyword',
            'res_seo_search_intent',
            'res_seo_video_title',
            'res_seo_hook',
            'res_seo_cta',
            'res_seo_difficulty',
            'res_seo_priority',
            'res_seo_example_title',
            'res_seo_example_url',

            'res_offer_video_title',
            'res_offer_name',
            'res_offer_audience',
            'res_offer_hook',
            'res_offer_cta',
            'res_offer_priority',
            'res_offer_example_title',
            'res_offer_example_url',

            'res_viral_video_title',
            'res_viral_trending_format',
            'res_viral_concept',
            'res_viral_opening_shot',
            'res_viral_trend_reason',
            'res_viral_cta',
            'res_viral_priority',
            'res_viral_example_title',
            'res_viral_example_url',

            'brief_date',
        );
    }

    public static function generate( $settings ) {
        $api_key = trim( (string) ( $settings['api_key'] ?? '' ) );
        if ( '' === $api_key ) {
            return new WP_Error( 'ctm_no_key', __( 'Add your Perplexity API key in settings first.', 'content-traffic-maker' ) );
        }

        $model = sanitize_text_field( $settings['model'] ?? 'sonar' ) ?: 'sonar';

        $body = array(
            'model'       => $model,
            'temperature' => 0.4,
            // 6 videos x ~9 multi-sentence fields = a large JSON object; 3500 was
            // too small and truncated the trailing (residential) block, producing
            // invalid JSON. Give the model enough room to finish.
            'max_tokens'  => 7000,
            'messages'    => array(
                array( 'role' => 'system', 'content' => self::system_prompt() ),
                array( 'role' => 'user',   'content' => self::build_prompt( $settings ) ),
            ),
        );

        $response = wp_remote_post( self::ENDPOINT, array(
            'timeout' => 75,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( 200 !== $code ) {
            $msg = is_array( $data ) && ! empty( $data['error']['message'] )
                ? $data['error']['message']
                : sprintf( __( 'Perplexity returned HTTP %d.', 'content-traffic-maker' ), $code );
            return new WP_Error( 'ctm_api_error', $msg );
        }

        $content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
        $brief   = self::extract_json( $content );
        if ( ! is_array( $brief ) ) {
            return new WP_Error( 'ctm_bad_json', __( 'Perplexity did not return valid JSON. Try again.', 'content-traffic-maker' ) );
        }

        // The real, grounded source URLs live in Perplexity's structured
        // response (search_results / citations) — NOT reliably inside the JSON
        // the model writes. Pull them out and use them to backfill any example
        // slots the model left empty, so the brief always shows real examples.
        $brief = self::merge_real_examples( $brief, self::extract_sources( $data ) );

        return self::sanitize_brief( $brief );
    }

    /**
     * Collect the real sources Perplexity grounded its answer on.
     * Newer API responses expose `search_results` (title + url); older ones
     * expose a flat `citations` array of URL strings. Support both.
     *
     * @return array List of array{ url:string, title:string }.
     */
    private static function extract_sources( $data ) {
        $sources = array();

        if ( ! empty( $data['search_results'] ) && is_array( $data['search_results'] ) ) {
            foreach ( $data['search_results'] as $r ) {
                $url = is_array( $r ) ? (string) ( $r['url'] ?? '' ) : '';
                if ( '' === $url ) {
                    continue;
                }
                $sources[] = array(
                    'url'   => $url,
                    'title' => is_array( $r ) ? (string) ( $r['title'] ?? '' ) : '',
                );
            }
        }

        if ( empty( $sources ) && ! empty( $data['citations'] ) && is_array( $data['citations'] ) ) {
            foreach ( $data['citations'] as $url ) {
                if ( is_string( $url ) && '' !== $url ) {
                    $sources[] = array( 'url' => $url, 'title' => '' );
                }
            }
        }

        return $sources;
    }

    /**
     * Backfill empty *_example_url / *_example_title fields from the real
     * sources, preferring video platforms (YouTube / TikTok) and never reusing
     * the same URL twice.
     */
    private static function merge_real_examples( $brief, $sources ) {
        if ( empty( $sources ) ) {
            return $brief;
        }

        // Float YouTube/TikTok results to the front — they're the relevant
        // "example videos to copy" for a short-form brief.
        usort( $sources, function ( $a, $b ) {
            return self::is_video_url( $b['url'] ) <=> self::is_video_url( $a['url'] );
        } );

        $url_keys = array(
            'com_seo_example_url', 'com_offer_example_url', 'com_viral_example_url',
            'res_seo_example_url', 'res_offer_example_url', 'res_viral_example_url',
        );

        $i = 0;
        foreach ( $url_keys as $url_key ) {
            $existing = trim( (string) ( $brief[ $url_key ] ?? '' ) );
            if ( '' !== $existing ) {
                continue; // Model already supplied a URL — keep it.
            }
            if ( ! isset( $sources[ $i ] ) ) {
                break; // No more real sources to assign.
            }
            $src = $sources[ $i++ ];
            $brief[ $url_key ] = $src['url'];

            // If the matching example title is also empty, use the source title
            // (or its host) so the example block actually renders.
            $title_key = str_replace( '_url', '_title', $url_key );
            if ( '' === trim( (string) ( $brief[ $title_key ] ?? '' ) ) ) {
                $brief[ $title_key ] = '' !== $src['title']
                    ? $src['title']
                    : (string) wp_parse_url( $src['url'], PHP_URL_HOST );
            }
        }

        return $brief;
    }

    /** True for YouTube / TikTok URLs. */
    private static function is_video_url( $url ) {
        return (int) (bool) preg_match( '~(youtube\.com|youtu\.be|tiktok\.com)~i', (string) $url );
    }

    private static function system_prompt() {
        return 'You are a social media video strategist for a Washington DC floor and carpet cleaning company called Midland Floors.

You write for TikTok and YouTube — NOT for blogs or corporate websites.

TWO audiences:
1. COMMERCIAL — DC property managers, office building managers, facility teams
2. RESIDENTIAL — DC homeowners, renters, people selling their home

LANGUAGE RULES (critical):
- Write like a real person talking, not a marketer writing copy.
- NO industry jargon: no "VCT", no "tile substrate", no "facility maintenance protocol".
- Say "office floors" not "commercial flooring". Say "carpet" not "floor covering". Say "before and after" not "transformation reveal".
- Video titles must sound like real TikTok captions or YouTube titles that get clicks from normal people.
- BAD title: "VCT Strip and Wax Maintenance for DC Facility Managers"
- GOOD title: "This DC Office Floor Hadnt Been Cleaned in 2 Years. Watch What Happened."
- BAD hook: "As a property manager, floor maintenance is critical."
- GOOD hook: "Your tenants notice the floors before anything else."
- Offers must feel like real deals, not marketing speak.
- BAD offer: "Commercial Floor Assessment Package"
- GOOD offer: "Free floor cleaning quote for any DC office this week"

EXAMPLES — for every *_example_url use a REAL TikTok or YouTube URL drawn from your live web search results for this query. Prefer youtube.com / youtu.be / tiktok.com links. Put the real video title in *_example_title. If you genuinely cannot find a matching real video, leave the URL empty and give a realistic title describing the type of video that performs well (the system will backfill a real source link automatically).

Return ONLY a minified JSON object. No prose, no markdown, no code fences.';
    }

    public static function build_prompt( $settings ) {
        $business = sanitize_text_field( (string) ( $settings['business_name'] ?? 'Midland Floors' ) );
        $city     = sanitize_text_field( (string) ( $settings['target_city']   ?? 'Washington' ) );
        $state    = sanitize_text_field( (string) ( $settings['target_state']  ?? 'DC' ) );
        $today    = gmdate( 'F j, Y' );
        $keys     = implode( ', ', self::fields() );

        return "Today is {$today}. Business: {$business}, {$city} {$state}.

Return JSON with EXACTLY these keys: {$keys}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
COMMERCIAL (com_* keys)
Audience: DC office building managers, property managers
Services: office floor cleaning, tile cleaning, waxing
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

[COMMERCIAL SEO VIDEO]
com_seo_keyword       — exact phrase someone types on YouTube/Google (plain words, not jargon)
com_seo_search_intent — one sentence: who is this person and what problem are they trying to solve
com_seo_video_title   — write like a YouTube title that gets clicks from normal people. Simple. Curious. Real.
com_seo_hook          — first 8 words spoken on camera. Stops the scroll.
com_seo_cta           — one specific action to take (call, DM, book online)
com_seo_difficulty    — Easy / Medium / Hard to rank for
com_seo_priority      — 1-10
com_seo_example_title — title of a REAL video on TikTok or YouTube doing this type of content right now
com_seo_example_url   — URL if you can verify it exists, otherwise empty string

[COMMERCIAL OFFER VIDEO]
com_offer_video_title  — plain social-media-style title (e.g. \"Free Floor Cleaning Quote for DC Offices This Week\")
com_offer_name         — the actual offer in plain words
com_offer_audience     — exact person this targets
com_offer_hook         — opening line that creates urgency or stakes in plain language
com_offer_cta          — specific CTA
com_offer_priority     — 1-10
com_offer_example_title — real example of a service offer video performing well on TikTok/YouTube
com_offer_example_url   — URL if verifiable

[COMMERCIAL VIRAL VIDEO]
com_viral_video_title   — write the caption/title like it would go viral on TikTok. Emotional. Relatable.
com_viral_trending_format — name the real trend this uses (e.g. \"before/after reveal\", \"satisfying clean\", \"secret nobody tells you\") — use your web search to confirm this format is active RIGHT NOW
com_viral_concept       — what happens in the video in plain words
com_viral_opening_shot  — describe the exact first frame in plain words
com_viral_trend_reason  — why this format is working right now — name real evidence
com_viral_cta           — specific CTA
com_viral_priority      — 1-10
com_viral_example_title — real viral video using this format right now
com_viral_example_url   — URL if verifiable

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
RESIDENTIAL (res_* keys)
Audience: DC homeowners, renters, people preparing to sell
Services: carpet cleaning, carpet installation, home floor cleaning
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

[RESIDENTIAL SEO VIDEO]
res_seo_keyword       — exact phrase (e.g. \"carpet cleaning before selling home\")
res_seo_search_intent — one sentence: who is searching and why
res_seo_video_title   — plain YouTube/TikTok title normal people click
res_seo_hook          — first 8 words on camera
res_seo_cta           — specific CTA
res_seo_difficulty    — Easy / Medium / Hard
res_seo_priority      — 1-10
res_seo_example_title — real example video performing on this topic
res_seo_example_url   — URL if verifiable

[RESIDENTIAL OFFER VIDEO]
res_offer_video_title  — plain social title for the offer video
res_offer_name         — the actual offer in plain language
res_offer_audience     — exact person
res_offer_hook         — opening line, plain language, urgency or stakes
res_offer_cta          — specific CTA
res_offer_priority     — 1-10
res_offer_example_title — real example of a home service offer video
res_offer_example_url   — URL if verifiable

[RESIDENTIAL VIRAL VIDEO]
res_viral_video_title   — TikTok-style caption. Relatable. Stops the scroll.
res_viral_trending_format — real trend format active RIGHT NOW
res_viral_concept       — what happens in plain words
res_viral_opening_shot  — describe the exact first frame
res_viral_trend_reason  — real evidence this format is working now
res_viral_cta           — specific CTA
res_viral_priority      — 1-10
res_viral_example_title — real viral video using this format
res_viral_example_url   — URL if verifiable

brief_date: \"{$today}\"";
    }

    private static function extract_json( $content ) {
        $content = trim( $content );
        $decoded = json_decode( $content, true );
        if ( is_array( $decoded ) ) return $decoded;
        $content = preg_replace( '/^```(?:json)?|```$/m', '', $content );
        $start   = strpos( $content, '{' );
        $end     = strrpos( $content, '}' );
        if ( false !== $start && false !== $end && $end > $start ) {
            $decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );
            if ( is_array( $decoded ) ) return $decoded;
        }
        return null;
    }

    private static function sanitize_brief( $brief ) {
        $int_fields = array(
            'com_seo_priority', 'com_offer_priority', 'com_viral_priority',
            'res_seo_priority', 'res_offer_priority', 'res_viral_priority',
        );
        $url_fields = array(
            'com_seo_example_url', 'com_offer_example_url', 'com_viral_example_url',
            'res_seo_example_url', 'res_offer_example_url', 'res_viral_example_url',
        );
        $clean = array();
        foreach ( self::fields() as $key ) {
            $val = $brief[ $key ] ?? '';
            if ( in_array( $key, $int_fields, true ) ) {
                $clean[ $key ] = max( 1, min( 10, (int) $val ) );
            } elseif ( in_array( $key, $url_fields, true ) ) {
                $clean[ $key ] = esc_url_raw( is_scalar( $val ) ? (string) $val : '' );
            } else {
                $text = is_scalar( $val ) ? (string) $val : wp_json_encode( $val );
                // Strip Perplexity inline citation markers like [1] / [1][2].
                $text = preg_replace( '/\s?\[\d+\]/', '', $text );
                $clean[ $key ] = sanitize_textarea_field( $text );
            }
        }
        return $clean;
    }
}
