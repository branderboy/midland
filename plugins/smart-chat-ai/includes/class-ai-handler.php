<?php
/**
 * Smart Chat AI Handler
 *
 * Dispatches chat completions to the configured provider. Default is Anthropic
 * Claude (claude-haiku-4-5); OpenAI is kept as a legacy option so old installs
 * with OpenAI keys still work without flipping a setting.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCAI_AI_Handler {

    const PROVIDER_ANTHROPIC = 'anthropic';
    const PROVIDER_OPENAI    = 'openai';

    private $provider;
    private $model;
    private $temperature;

    public function __construct() {
        $this->provider    = get_option( 'smart_chat_ai_provider', self::PROVIDER_ANTHROPIC );
        $this->temperature = floatval( get_option( 'smart_chat_ai_temperature', 0.7 ) );
        $this->model       = get_option( 'smart_chat_ai_model', $this->default_model() );
    }

    private function default_model() {
        if ( self::PROVIDER_OPENAI === $this->provider ) {
            return 'gpt-4o-mini';
        }
        return 'claude-haiku-4-5';
    }

    /**
     * Get AI response
     */
    public function get_response( $user_message, $session_id ) {
        $history       = $this->get_conversation_history( $session_id );
        $system_prompt = $this->build_system_prompt();

        // Build a unified messages array (role/content). The provider-specific
        // adapter pulls the system text out of $system_prompt before serializing.
        $messages = array();
        foreach ( $history as $msg ) {
            $messages[] = array(
                'role'    => $msg->sender === 'user' ? 'user' : 'assistant',
                'content' => $msg->message,
            );
        }
        $messages[] = array( 'role' => 'user', 'content' => $user_message );

        if ( self::PROVIDER_OPENAI === $this->provider ) {
            return $this->call_openai( $system_prompt, $messages );
        }
        return $this->call_anthropic( $system_prompt, $messages );
    }

    /**
     * Build system prompt (unchanged — site context module adds to this via
     * the scai_system_prompt filter).
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
     * Anthropic Claude (Messages API).
     * Docs: https://docs.anthropic.com/en/api/messages
     */
    private function call_anthropic( $system_prompt, $messages ) {
        $api_key = (string) get_option( 'smart_chat_anthropic_api_key', '' );
        if ( '' === $api_key ) {
            return $this->unavailable_response();
        }

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'model'       => $this->model,
                'max_tokens'  => 500,
                'temperature' => $this->temperature,
                'system'      => $system_prompt,
                'messages'    => $messages,
            ) ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( 'Sorry, I am having trouble connecting. Please try again.' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            error_log( 'Smart Chat (Anthropic) error: ' . wp_json_encode( $body['error'] ) );
            return $this->error_response( 'Sorry, I encountered an error. Please contact us directly.' );
        }

        // Anthropic returns content as an array of blocks. Concatenate the text blocks.
        $text   = '';
        $blocks = $body['content'] ?? array();
        foreach ( (array) $blocks as $block ) {
            if ( ( $block['type'] ?? '' ) === 'text' ) {
                $text .= (string) ( $block['text'] ?? '' );
            }
        }
        if ( '' === $text ) {
            $text = 'Sorry, I do not have a response right now.';
        }

        $tokens = (int) ( $body['usage']['input_tokens'] ?? 0 ) + (int) ( $body['usage']['output_tokens'] ?? 0 );

        return array(
            'message' => $text,
            'model'   => $this->model,
            'tokens'  => $tokens,
            'error'   => false,
        );
    }

    /**
     * OpenAI Chat Completions — kept for installs that haven't migrated keys.
     */
    private function call_openai( $system_prompt, $messages ) {
        $api_key = (string) get_option( 'smart_chat_openai_api_key', '' );
        if ( '' === $api_key ) {
            return $this->unavailable_response();
        }

        $payload_messages = array_merge(
            array( array( 'role' => 'system', 'content' => $system_prompt ) ),
            $messages
        );

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'model'       => $this->model,
                'messages'    => $payload_messages,
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
            error_log( 'Smart Chat (OpenAI) error: ' . $body['error']['message'] );
            return $this->error_response( 'Sorry, I encountered an error. Please contact us directly.' );
        }

        return array(
            'message' => (string) ( $body['choices'][0]['message']['content'] ?? '' ),
            'model'   => $this->model,
            'tokens'  => (int) ( $body['usage']['total_tokens'] ?? 0 ),
            'error'   => false,
        );
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

        $system   = 'Extract lead information from this conversation. Return JSON with fields: name, email, phone, service_type, budget, timeline. Return null for missing fields.';
        $messages = array( array( 'role' => 'user', 'content' => $conversation ) );

        $response = self::PROVIDER_OPENAI === $this->provider
            ? $this->call_openai( $system, $messages )
            : $this->call_anthropic( $system, $messages );

        if ( $response['error'] ) {
            return null;
        }

        return json_decode( $response['message'], true );
    }
}
