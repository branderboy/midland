<?php
/**
 * Smart Chat AI Handler
 * Handles AI responses using OpenAI API
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCAI_AI_Handler {
    
    private $api_key;
    private $model;
    private $temperature;
    
    public function __construct() {
        $this->api_key = get_option('smart_chat_openai_api_key');
        $this->model = get_option('smart_chat_ai_model', 'gpt-4');
        $this->temperature = floatval(get_option('smart_chat_ai_temperature', 0.7));
    }
    
    /**
     * Get AI response
     */
    public function get_response($user_message, $session_id) {
        // Get conversation history
        $history = $this->get_conversation_history($session_id);
        
        // Build system prompt
        $system_prompt = $this->build_system_prompt();
        
        // Build messages array
        $messages = array(
            array('role' => 'system', 'content' => $system_prompt)
        );
        
        // Add conversation history
        foreach ($history as $msg) {
            $messages[] = array(
                'role' => $msg->sender === 'user' ? 'user' : 'assistant',
                'content' => $msg->message
            );
        }
        
        // Add current message
        $messages[] = array('role' => 'user', 'content' => $user_message);
        
        // Call OpenAI API
        $response = $this->call_openai_api($messages);
        
        return $response;
    }
    
    /**
     * Build system prompt
     */
    private function build_system_prompt() {
        $business_name = get_option('smart_chat_business_name', get_bloginfo('name'));
        $business_type = get_option('smart_chat_business_type', 'contractor');
        $personality = get_option('smart_chat_ai_personality', 'helpful');
        
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

        return apply_filters('scai_system_prompt', $prompt);
    }
    
    /**
     * Get conversation history
     */
    private function get_conversation_history($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_conversations';
        
        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT message, sender FROM $table 
            WHERE session_id = %s 
            ORDER BY created_at ASC 
            LIMIT 20",
            $session_id
        ));
    }
    
    /**
     * Call OpenAI API
     */
    private function call_openai_api($messages) {
        if (empty($this->api_key)) {
            return array(
                'message' => 'Chat is currently unavailable. Please contact us directly.',
                'model' => null,
                'tokens' => 0,
                'error' => true,
            );
        }
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => $this->temperature,
                'max_tokens' => 500,
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return array(
                'message' => 'Sorry, I\'m having trouble connecting. Please try again or contact us directly.',
                'model' => $this->model,
                'tokens' => 0,
                'error' => true,
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            error_log('Smart Chat AI Error: ' . $body['error']['message']);
            return array(
                'message' => 'Sorry, I encountered an error. Please contact us directly.',
                'model' => $this->model,
                'tokens' => 0,
                'error' => true,
            );
        }
        
        return array(
            'message' => $body['choices'][0]['message']['content'],
            'model' => $this->model,
            'tokens' => $body['usage']['total_tokens'],
            'error' => false,
        );
    }
    
    /**
     * Extract lead information from conversation
     */
    public function extract_lead_info($session_id) {
        $history = $this->get_conversation_history($session_id);
        
        // Build conversation text
        $conversation = '';
        foreach ($history as $msg) {
            $conversation .= $msg->sender . ': ' . $msg->message . "\n";
        }
        
        // Use AI to extract structured data
        $messages = array(
            array(
                'role' => 'system',
                'content' => 'Extract lead information from this conversation. Return JSON with fields: name, email, phone, service_type, budget, timeline. Return null for missing fields.'
            ),
            array(
                'role' => 'user',
                'content' => $conversation
            )
        );
        
        $response = $this->call_openai_api($messages);
        
        if ($response['error']) {
            return null;
        }
        
        return json_decode($response['message'], true);
    }
}
