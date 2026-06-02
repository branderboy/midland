<?php
/**
 * Content opportunity generator — builds the prompt from business settings and
 * asks Perplexity for a structured local-SEO traffic brief.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTM_Generator {

    // Perplexity exposes an OpenAI-compatible chat-completions API; its
    // search-grounded "sonar" models surface real local directories /
    // associations, which is exactly what we want for backlink targets.
    const ENDPOINT = 'https://api.perplexity.ai/chat/completions';

    /**
     * The structured fields the AI must return, mapped to a human label used
     * when we have to sanitize / fall back.
     */
    public static function fields() {
        return array(
            'guest_post_topic',
            'why_traffic',
            'publishing_target',
            'headline',
            'local_backlink',
            'outreach_angle',
            'gov_backlink',
            'nonprofit_backlink',
            'youtube_idea',
            'youtube_residential',
            'tiktok_seo',
            'tiktok_viral',
            'residential_offer_video',
            'trending_tiktok_idea',
            'cta',
            'priority_score',
        );
    }

    /**
     * Generate a brief for the given settings.
     *
     * @param array $settings CTM_DB::get_settings()
     * @return array|WP_Error Associative brief (see fields()) or error.
     */
    public static function generate( $settings ) {
        $api_key = trim( (string) ( $settings['api_key'] ?? '' ) );
        if ( '' === $api_key ) {
            return new WP_Error( 'ctm_no_key', __( 'Add your Perplexity API key in settings first.', 'content-traffic-maker' ) );
        }

        $model = sanitize_text_field( $settings['model'] ?? 'sonar' ) ?: 'sonar';

        $body = array(
            'model'       => $model,
            'temperature' => 0.7,
            'max_tokens'  => 1300,
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => 'You are a local-SEO and content strategist for a floor-cleaning company. You return ONLY a single minified JSON object and nothing else — no prose, no markdown, no code fences. Every recommendation must be concrete and specific to the exact city, business type, and audience given. Prefer real, currently-existing local organizations, directories, and associations by name. No generic advice, no placeholders.',
                ),
                array(
                    'role'    => 'user',
                    'content' => self::build_prompt( $settings ),
                ),
            ),
        );

        $response = wp_remote_post( self::ENDPOINT, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( 200 !== $code ) {
            $msg = is_array( $data ) && ! empty( $data['error']['message'] )
                ? $data['error']['message']
                : sprintf( /* translators: %d: HTTP status */ __( 'Perplexity returned HTTP %d.', 'content-traffic-maker' ), $code );
            return new WP_Error( 'ctm_api_error', $msg );
        }

        $content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
        $brief   = self::extract_json( $content );
        if ( ! is_array( $brief ) ) {
            return new WP_Error( 'ctm_bad_json', __( 'Perplexity did not return valid JSON. Try again.', 'content-traffic-maker' ) );
        }

        return self::sanitize_brief( $brief );
    }

    /**
     * Pull a JSON object out of a model response that may include a ```json
     * code fence or stray prose around it (Perplexity isn't guaranteed to
     * return bare JSON like OpenAI's json_object mode).
     */
    private static function extract_json( $content ) {
        $content = trim( $content );
        $decoded = json_decode( $content, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }
        // Strip code fences if present.
        $content = preg_replace( '/^```(?:json)?|```$/m', '', $content );
        // Grab the outermost {...} block.
        $start = strpos( $content, '{' );
        $end   = strrpos( $content, '}' );
        if ( false !== $start && false !== $end && $end > $start ) {
            $decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Build the prompt from business settings.
     */
    public static function build_prompt( $settings ) {
        $g = function( $k ) use ( $settings ) {
            return sanitize_text_field( (string) ( $settings[ $k ] ?? '' ) );
        };

        $business = $g( 'business_name' );
        $type     = $g( 'business_type' );
        $city     = $g( 'target_city' );
        $state    = $g( 'target_state' );
        $audience = $g( 'target_audience' );
        $service  = $g( 'main_service' );
        $site     = esc_url_raw( (string) ( $settings['website_url'] ?? '' ) );
        $freq     = ( 'daily' === ( $settings['frequency'] ?? 'weekly' ) ) ? "today's" : "this week's";

        $keys = implode( ', ', self::fields() );

        return "Business profile:\n"
            . "- Name: {$business}\n"
            . "- Type: {$type} (commercial & residential floor cleaning — strip & wax, VCT, tile & grout, carpet extraction)\n"
            . "- City: {$city}\n"
            . "- State: {$state}\n"
            . "- Target audience: {$audience}\n"
            . "- Main service: {$service}\n"
            . "- Website: {$site}\n\n"
            . "Produce {$freq} single best traffic brief for this exact business and city. "
            . "Return a JSON object with EXACTLY these keys: {$keys}.\n\n"
            . "GUEST POST guidance — pitch local business blogs, real estate blogs, property management sites, "
            . "facility management sites, and cleaning-industry sites in {$city}, {$state}.\n"
            . "BACKLINK guidance — choose from real, named targets in {$city}: local chambers of commerce, business "
            . "directories, property manager / apartment associations (e.g. local BOMA, IREM, apartment councils), "
            . "commercial real estate groups, school or vendor pages, nonprofit facility partners, and local "
            . ".gov vendor/resource pages.\n"
            . "VIDEO guidance — YouTube SEO titles like 'How often should commercial floors be cleaned?', "
            . "'Best floor cleaning for office buildings in {$city}', 'Commercial floor stripping and waxing explained', "
            . "'How to remove stains from VCT flooring', 'Residential floor cleaning before selling your home'. "
            . "TikTok SEO: before/after floor cleaning, dirty grout transformations, VCT strip & wax process, carpet "
            . "extraction walkthrough, floor-cleaning mistakes property managers make. Viral TikTok: "
            . "'This floor looked destroyed until we cleaned it', 'POV: the tenant moved out and left the floors like this', "
            . "'Watch this waxed floor come back to life', 'The dirtiest hallway we cleaned this month'.\n\n"
            . "Field requirements (all specific to {$city}, {$state} and floor cleaning):\n"
            . "- guest_post_topic: the single highest-value guest post topic to pitch (use the guest-post guidance).\n"
            . "- why_traffic: one or two sentences on why it will drive traffic.\n"
            . "- publishing_target: a realistic named local/niche site to publish it on.\n"
            . "- headline: a click-worthy, keyword-bearing suggested headline.\n"
            . "- local_backlink: a specific, real, named local backlink target in {$city} (use the backlink guidance).\n"
            . "- outreach_angle: the outreach angle / reason they would link.\n"
            . "- gov_backlink: a realistic .gov backlink idea (city/county vendor registration, gov facility/resource page).\n"
            . "- nonprofit_backlink: a realistic local nonprofit backlink idea (facility partner, sponsorship, resource listing).\n"
            . "- youtube_idea: a YouTube SEO video idea for the COMMERCIAL audience — include the target keyword and a title.\n"
            . "- youtube_residential: a YouTube SEO video idea for the RESIDENTIAL audience (homeowners) — include the keyword and a title.\n"
            . "- tiktok_seo: a TikTok SEO video idea — include the keyword and a hook.\n"
            . "- tiktok_viral: a viral TikTok video idea — include the hook and concept.\n"
            . "- residential_offer_video: a short promotional/offer video idea aimed at RESIDENTIAL customers that ties to a "
            . "specific offer or seasonal hook (e.g. pre-sale home floor refresh, spring deep clean) — include the hook and the offer/CTA.\n"
            . "- trending_tiktok_idea: a TikTok format, sound, or trend that is ACTUALLY popular right now (use live search) and "
            . "show how Midland Floor can adapt it to floor cleaning — name the trend/sound and the adaptation.\n"
            . "- cta: one specific call-to-action to use this week.\n"
            . "- priority_score: an integer 1-10 for how high-impact this brief is.\n\n"
            . "Be direct and concrete. Name real local organizations where possible. No generic advice, no filler.";
    }

    /**
     * Coerce the model output into a clean, expected shape.
     */
    private static function sanitize_brief( $brief ) {
        $clean = array();
        foreach ( self::fields() as $key ) {
            $val = $brief[ $key ] ?? '';
            if ( 'priority_score' === $key ) {
                $clean[ $key ] = max( 1, min( 10, (int) $val ) );
            } else {
                $clean[ $key ] = sanitize_text_field( is_scalar( $val ) ? (string) $val : wp_json_encode( $val ) );
            }
        }
        return $clean;
    }
}
