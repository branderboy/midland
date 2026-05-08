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
        $business_name = get_option( 'smart_chat_business_name', get_bloginfo( 'name' ) );
        $business_type = get_option( 'smart_chat_business_type', 'contractor' );
        $personality   = get_option( 'smart_chat_ai_personality', 'helpful' );

        $prompt = "You are a helpful AI assistant for {$business_name}, a professional {$business_type} company.

Your role:
- Answer questions about our services professionally and accurately
- Help visitors understand what we do and how we can help them
- Capture lead information when appropriate (name, email, phone, project details)
- Be friendly, helpful, and {$personality}
- Keep responses concise (2-3 sentences max unless asked for details)
- If you don't know something, be honest and offer to have a human follow up

Our services:
- HVAC installation and repair
- Plumbing services
- Drywall installation
- General contracting
- Emergency services available 24/7

When to capture lead:
- When visitor asks for a quote or estimate
- When visitor asks about scheduling or availability
- When visitor expresses interest in our services
- When visitor asks specific project questions

How to capture lead:
- Politely ask for their name, email, and phone
- Ask about their project needs
- Ask about timeline and budget
- Confirm you'll have someone reach out shortly

Never:
- Make promises you can't keep
- Give specific pricing without context
- Provide medical, legal, or financial advice
- Be pushy or aggressive about capturing contact info";

        return apply_filters( 'scai_system_prompt', $prompt );
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

        return array(
            'message' => (string) ( $body['choices'][0]['message']['content'] ?? '' ),
            'model'   => $this->model,
            'tokens'  => (int) ( $body['usage']['total_tokens'] ?? 0 ),
            'error'   => false,
        );
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
