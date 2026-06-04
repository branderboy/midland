<?php
/**
 * RSSEO_AI_Client
 *
 * Thin client for the AI provider behind the analyzer. It currently calls
 * Perplexity's Sonar API (OpenAI-style chat completions). Formerly named
 * RSSEO_Claude_API back when it called Anthropic — a class_alias at the bottom
 * keeps that old name working for any caller not yet updated.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_AI_Client {

    const API_URL     = 'https://api.perplexity.ai/chat/completions';
    const MAX_RETRIES = 3;

    // Approximate Perplexity Sonar pricing in USD per 1M tokens.
    // Tune in one place if Perplexity changes their rate card.
    const PRICING = array(
        'sonar'            => array( 'input' => 1.00, 'output' => 1.00 ),
        'sonar-pro'        => array( 'input' => 3.00, 'output' => 15.00 ),
        'sonar-reasoning'  => array( 'input' => 1.00, 'output' => 5.00 ),
    );

    /**
     * Send a prompt to Perplexity Sonar and return the response.
     *
     * @param string   $prompt   The user prompt.
     * @param int|null $scan_id  Scan ID for logging.
     * @return array|WP_Error
     */
    public static function ask( $prompt, $scan_id = null ) {
        $api_key = RSSEO_Settings::get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'Perplexity API key is not configured. Go to Midland Smart SEO → Setup to add your key.', 'real-smart-seo' ) );
        }

        $model      = RSSEO_Settings::get_model();
        $max_tokens = RSSEO_Settings::get_max_tokens();

        $body = array(
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'messages'   => array(
                array( 'role' => 'user', 'content' => $prompt ),
            ),
        );

        $retry = 0;
        $response = null;

        while ( $retry <= self::MAX_RETRIES ) {
            $response = wp_remote_post( self::API_URL, array(
                // Bug 5 fix: 120s per attempt × 4 tries could block cron for ~8 min,
                // exceeding the typical 300s process limit on shared hosting and leaving
                // the job stuck in 'running'. 60s is still generous for Perplexity Sonar
                // (p99 latency is well under 30s) while keeping the worst-case total
                // (60s × 4 + 6s sleep) well inside a 5-minute process budget.
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( $body ),
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );

            if ( 429 === $code && $retry < self::MAX_RETRIES ) {
                $retry++;
                sleep( pow( 2, $retry ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.sleep
                continue;
            }
            if ( 500 === $code && $retry < self::MAX_RETRIES ) {
                $retry++;
                sleep( 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.sleep
                continue;
            }
            break;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 401 === $code ) {
            return new WP_Error( 'invalid_key', __( 'Invalid Perplexity API key.', 'real-smart-seo' ) );
        }
        if ( 429 === $code ) {
            return new WP_Error( 'rate_limited', __( 'Perplexity API rate limit exceeded. Please try again in a moment.', 'real-smart-seo' ) );
        }
        if ( $code >= 400 ) {
            $msg = isset( $data['error']['message'] )
                ? $data['error']['message']
                : ( isset( $data['message'] ) ? $data['message'] : 'API error (HTTP ' . $code . ')' );
            return new WP_Error( 'api_error', $msg );
        }

        $text = '';
        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            $text = (string) $data['choices'][0]['message']['content'];
        }

        // Log usage (Perplexity returns OpenAI-style usage fields).
        $input_tokens  = isset( $data['usage']['prompt_tokens'] )     ? (int) $data['usage']['prompt_tokens']     : 0;
        $output_tokens = isset( $data['usage']['completion_tokens'] ) ? (int) $data['usage']['completion_tokens'] : 0;
        $cost          = self::calculate_cost( $model, $input_tokens, $output_tokens );

        RSSEO_Database::log_api_call( $scan_id, $model, $input_tokens, $output_tokens, $cost );

        return array(
            'text'          => $text,
            'input_tokens'  => $input_tokens,
            'output_tokens' => $output_tokens,
            'cost'          => $cost,
            'model'         => $model,
        );
    }

    public static function calculate_cost( $model, $input_tokens, $output_tokens ) {
        $pricing     = isset( self::PRICING[ $model ] ) ? self::PRICING[ $model ] : self::PRICING['sonar'];
        $input_cost  = ( $input_tokens / 1000000 ) * $pricing['input'];
        $output_cost = ( $output_tokens / 1000000 ) * $pricing['output'];
        return round( $input_cost + $output_cost, 6 );
    }

    public static function test_connection() {
        $result = self::ask( 'Reply with just the word: connected', null );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }
}

// Back-compat alias kept for any third-party code that references the old class
// name. All internal callers now use RSSEO_AI_Client directly.
if ( ! class_exists( 'RSSEO_Claude_API' ) ) {
    class_alias( 'RSSEO_AI_Client', 'RSSEO_Claude_API' );
}
