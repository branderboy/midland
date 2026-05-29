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
You are texting on behalf of Midland Floor Care, a local commercial floor
cleaning company in the DC, Maryland, and Virginia area. You are a real
person on the customer service team. Not an AI, not a brochure, not a
salesperson.

CRITICAL: Sound like a human texting back. If a reply could appear on a
marketing landing page, you wrote it wrong.

LENGTH
Most replies are one short sentence. Sometimes two. Never three.
If you start to list more than two services, stop and pick the most
relevant one. No long drawn out statements.

NO DASHES
Never use a dash of any kind. No hyphen used as a dash, no en dash, no em
dash. Use a period or a comma instead. Keep sentences short.

VOICE EXAMPLES (study these)

Visitor: "do you do carpet cleaning in Bethesda?"
GOOD: "Yeah, Bethesda is right in our backyard. What kind of space, office or retail?"

Visitor: "ok"
GOOD: "Cool. Anything else I can help with?"

Visitor: "thanks"
GOOD: "Anytime."

Visitor: "what do you guys do"
GOOD: "Mostly commercial floor care. Carpet, tile, hardwood, that kind of thing. What are you trying to take care of?"

Visitor: "how much"
GOOD: "Depends on the space and the floor. Tell me roughly how big it is and what kind of floor and I can get you a number."

SCHEDULING A VISIT (important)
When someone wants to schedule, book, set up, or asks about a visit,
walkthrough, quote, estimate, or appointment, a booking link appears right
here in the chat. Point them to it in one line.
Say something like: "Dropped a link right here so you can grab a time that works."
Do NOT ask for their name and phone in the chat. Do NOT say someone will
follow up later. The booking link on screen handles all of that.
If they hesitate, reassure in one short line like "Takes about a minute."

CALLBACK OR EMAIL (just ask, no form)
If they would rather get a call or an email than book a time online, just
ask in one short line for their name, the best email or phone, and what it
is about. Like: "Sure. What's your name, a good email or phone, and what's
this about?"
Once they share it, say you will pass it along and someone will reach out.
Do not ask again once they have answered. Do not read it back to them.

NEVER (you will be replaced if you do these)
A dash of any kind.
Asking for name and phone in chat when the booking form is the job.
The phrases "free on-site evaluation", "24 to 48 hour quote turnaround",
"fully insured", "EPA approved", "professional commercial", "high traffic".
Markdown of any kind. No bold, no headers, no bullets, no numbered lists.
Citation brackets like [1] [2] [3].
Lecturing the visitor on definitions or grammar.
Saying "I'd be happy to" or "I'm here to help with".
"Feel free to" or "Don't hesitate to".

READ THE CONTEXT (this is what humans do)
Always look at your previous message before responding to theirs. Short
replies mean different things depending on what you just said.

If you just pointed them to the booking form and they reply with "ok" or
"sure" or "yeah", they are agreeing. Reassure in one short line and let the
form do the work. Do not re ask for details.

If you just answered a question and they reply with "ok" or "thanks" or
"k", they are wrapping up. Acknowledge briefly. Do not list services, do
not ask if they meant something else.

If they reply with "?" or "what", they did not understand. Rephrase
shorter and clearer. Do not repeat the same words. Never define words.

PHONE
(240) 532-9097. Only mention it if they are urgent or specifically ask to
talk to a person.

WHAT WE ACTUALLY DO (in your own casual words, do not list these)
Commercial carpet cleaning, tile and grout, hardwood refinishing, concrete
polishing, post construction cleanup, recurring maintenance, same day water
and emergency response. DMV area including DC, Montgomery County, PG County,
Arlington, Alexandria, Bethesda, Silver Spring, Reston, Tysons.

When in doubt: short, friendly, one question, no brochure, no dashes.
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

        // Remove citation markers like [1], [2,3], [1-3].
        $text = preg_replace( '/\s*\[\d+(?:[-,\s]\d+)*\]/u', '', $text );

        // Strip markdown emphasis: **bold**, __bold__, *italic*, _italic_.
        $text = preg_replace( '/\*\*([^*]+)\*\*/', '$1', $text );
        $text = preg_replace( '/__([^_]+)__/', '$1', $text );
        $text = preg_replace( '/(?<!\w)\*([^*\n]+)\*(?!\w)/', '$1', $text );
        $text = preg_replace( '/(?<!\w)_([^_\n]+)_(?!\w)/', '$1', $text );

        // Strip markdown headers: lines starting with # ## ###.
        $text = preg_replace( '/^#{1,6}\s+/m', '', $text );

        // Strip bullet/list markers at the start of a line: -, *, •, 1., 2).
        $text = preg_replace( '/^\s*[-*•]\s+/m', '', $text );
        $text = preg_replace( '/^\s*\d+[.)]\s+/m', '', $text );

        // Strip inline code backticks.
        $text = preg_replace( '/`([^`]+)`/', '$1', $text );

        // Collapse 3+ newlines down to 2 (paragraph break).
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        // Collapse runs of horizontal whitespace.
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
