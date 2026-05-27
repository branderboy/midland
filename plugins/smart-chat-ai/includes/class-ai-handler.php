<?php
/**
 * Smart Chat AI Handler
 *
 * Default provider: Perplexity Sonar (one key for chat + AI Rank citation tracking).
 * OpenAI is kept as a legacy option for installs that haven't migrated yet.
 *
 * Perplexity's API is drop-in compatible with the OpenAI /chat/completions shape,
 * so the same call wrapper handles both — only endpoint and auth header differ.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCAI_AI_Handler {

    const PROVIDER_PERPLEXITY = 'perplexity';
    const PROVIDER_OPENAI     = 'openai';

    const PERPLEXITY_ENDPOINT = 'https://api.perplexity.ai/chat/completions';
    const OPENAI_ENDPOINT     = 'https://api.openai.com/v1/chat/completions';

    private $provider;
    private $model;
    private $temperature;

    public function __construct() {
        $this->provider    = get_option( 'smart_chat_ai_provider', self::PROVIDER_PERPLEXITY );
        $this->temperature = floatval( get_option( 'smart_chat_ai_temperature', 0.7 ) );
        $this->model       = get_option( 'smart_chat_ai_model', $this->default_model() );
    }

    private function default_model() {
        if ( self::PROVIDER_OPENAI === $this->provider ) {
            return 'gpt-4o-mini';
        }
        return 'sonar'; // cheapest Perplexity model with built-in web grounding.
    }

    /**
     * Get AI response.
     */
    public function get_response( $user_message, $session_id ) {
        $history       = $this->get_conversation_history( $session_id );
        $system_prompt = $this->build_system_prompt();

        $messages = array( array( 'role' => 'system', 'content' => $system_prompt ) );
        foreach ( $history as $msg ) {
            $messages[] = array(
                'role'    => $msg->sender === 'user' ? 'user' : 'assistant',
                'content' => $msg->message,
            );
        }
        $messages[] = array( 'role' => 'user', 'content' => $user_message );

        return $this->call_chat_completions( $messages );
    }

    /**
     * Build system prompt (Site Content module appends to this via the
     * scai_system_prompt filter).
     */
    private function build_system_prompt() {
        $custom = trim( (string) get_option( 'smart_chat_preprompt', '' ) );
        $prompt = '' !== $custom ? $custom : self::default_preprompt();
        return apply_filters( 'scai_system_prompt', $prompt );
    }

    /**
     * Midland Floor Care default system prompt. Used unless an admin has
     * pasted a custom preprompt into Settings → AI Configuration.
     */
    public static function default_preprompt() {
        return <<<'PROMPT'
You are the AI assistant for Midland Floor Care — a fully insured commercial
floor cleaning and restoration company serving Washington D.C., Maryland, and
Virginia (the DMV). Your job is to answer visitor questions accurately and
turn interested visitors into booked site evaluations.

WHO WE ARE
- Local DMV-based commercial floor care contractor. A faster, more
  accountable alternative to MilliCare, Stanley Steemer, and Aramark.
- Service area: Washington D.C., Montgomery County, Prince George's County,
  Arlington, Alexandria, Bethesda, Silver Spring, Reston, McLean, Tysons,
  Navy Yard, Chinatown — and the surrounding Mid-Atlantic region.
- Industries we serve: retail, corporate offices, government facilities,
  healthcare and clinics, schools and universities, hotels and restaurants.
- We work nights, weekends, and holidays so client operations never stop.
- 774+ satisfied clients. Fully insured. EPA-approved disinfectants.

WHAT WE DO
- Commercial carpet cleaning (steam, stain removal, odor removal, protection)
- Tile and grout deep cleaning and restoration
- Hardwood floor refinishing and recoating
- Hard-surface, concrete polishing, and post-construction cleanup
- EPA-approved disinfecting
- Recurring maintenance programs (weekly, monthly, quarterly)
- Same-day and next-day emergency response for water events, post-event
  cleanup, move-outs, and inspections

HOW TO RESPOND
- Keep replies short — 2 to 3 sentences unless the visitor asks for detail.
- Sound like a knowledgeable local pro, not a marketing brochure.
- Plain prose only. NEVER include citation markers like [1], [2], or [3].
- NEVER include source URLs, footnotes, or "according to the website" phrasing.
- If you don't know something specific (pricing, schedule openings,
  certifications), say so honestly and offer to have a human follow up.
- Don't quote prices. Pricing depends on square footage, surface, soil
  level, and access — always direct pricing questions to a free on-site
  evaluation.

WHEN TO CAPTURE A LEAD
Capture contact info any time the visitor:
- Asks for a quote, estimate, or pricing
- Asks about availability or scheduling
- Describes a specific project (square footage, surface, timeline)
- Mentions an emergency (water damage, event cleanup, inspection deadline)

HOW TO CAPTURE A LEAD
Ask politely, one or two fields at a time — don't dump a form on them:
1. Name and the best phone number to reach them
2. Email
3. Type of facility and approximate square footage
4. Surface(s) involved (carpet, tile, hardwood, concrete, etc.)
5. Timeline — is this urgent, this week, this month, or planning ahead?
Then say: "Thanks — someone from Midland will reach out shortly to set up
your free on-site evaluation."

ALWAYS OFFER
- The phone CTA for urgent or complex needs: (240) 532-9097
- A free on-site evaluation with a 24–48 hour quote turnaround
- Virtual consultation as a fast alternative when on-site isn't possible

NEVER
- Quote specific dollar amounts or rates
- Promise specific dates, crew availability, or arrival windows
- Give medical, legal, or financial advice
- Recommend competing vendors
- Be pushy — if they're just browsing, answer their question and let them go
PROMPT;
    }

    private function get_conversation_history( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_conversations';

        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT message, sender FROM {$table}
            WHERE session_id = %s
            ORDER BY created_at ASC
            LIMIT 20",
            $session_id
        ) );
    }

    /**
     * Single completions wrapper. Routes to Perplexity or OpenAI based on the
     * configured provider. Both APIs share the OpenAI request/response shape so
     * the only difference is endpoint + auth.
     */
    private function call_chat_completions( $messages ) {
        $api_key = $this->current_api_key();
        if ( '' === $api_key ) {
            return $this->unavailable_response();
        }

        $endpoint = self::PROVIDER_OPENAI === $this->provider
            ? self::OPENAI_ENDPOINT
            : self::PERPLEXITY_ENDPOINT;

        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $this->temperature,
                'max_tokens'  => 500,
            ) ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( 'Sorry, I am having trouble connecting. Please try again.' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            error_log( 'Smart Chat (' . $this->provider . ') error: ' . wp_json_encode( $body['error'] ) );
            return $this->error_response( 'Sorry, I encountered an error. Please contact us directly.' );
        }

        $content = (string) ( $body['choices'][0]['message']['content'] ?? '' );
        $content = $this->clean_response( $content );

        return array(
            'message' => $content,
            'model'   => $this->model,
            'tokens'  => (int) ( $body['usage']['total_tokens'] ?? 0 ),
            'error'   => false,
        );
    }

    /**
     * Perplexity Sonar peppers replies with [1], [2,3], etc. citation markers.
     * Strip them — the chat widget has no citation panel and they read as noise.
     */
    private function clean_response( $text ) {
        if ( '' === $text ) {
            return $text;
        }
        // Remove [1], [2,3], [1][2][3], ranges like [1-3], etc.
        $text = preg_replace( '/\s*\[\d+(?:[-,\s]\d+)*\]/u', '', $text );
        // Collapse double spaces left behind.
        $text = preg_replace( '/[ \t]{2,}/', ' ', $text );
        return trim( $text );
    }

    private function current_api_key() {
        if ( self::PROVIDER_OPENAI === $this->provider ) {
            return (string) get_option( 'smart_chat_openai_api_key', '' );
        }
        // Perplexity — first try the chat-specific key, then the AI Rank key
        // already configured on the SEO plugin so admins don't paste twice.
        $key = (string) get_option( 'smart_chat_perplexity_api_key', '' );
        if ( '' === $key ) {
            $key = (string) get_option( 'rsseo_pro_ai_perplexity_key', '' );
        }
        return $key;
    }

    private function unavailable_response() {
        return array(
            'message' => 'Chat is currently unavailable. Please contact us directly.',
            'model'   => null,
            'tokens'  => 0,
            'error'   => true,
        );
    }

    private function error_response( $message ) {
        return array(
            'message' => $message,
            'model'   => $this->model,
            'tokens'  => 0,
            'error'   => true,
        );
    }

    /**
     * Extract structured lead data from a session via the configured provider.
     */
    public function extract_lead_info( $session_id ) {
        $history = $this->get_conversation_history( $session_id );

        $conversation = '';
        foreach ( $history as $msg ) {
            $conversation .= $msg->sender . ': ' . $msg->message . "\n";
        }

        $messages = array(
            array(
                'role'    => 'system',
                'content' => 'Extract lead information from this conversation. Return JSON with fields: name, email, phone, service_type, budget, timeline. Return null for missing fields.',
            ),
            array(
                'role'    => 'user',
                'content' => $conversation,
            ),
        );

        $response = $this->call_chat_completions( $messages );

        if ( $response['error'] ) {
            return null;
        }

        return json_decode( $response['message'], true );
    }
}
