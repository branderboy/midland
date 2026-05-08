<?php
/**
 * Smart Chat AI License Manager
 * Handles license validation with TaggleFish license server
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCAI_License_Manager {
    
    private $license_server_url;
    
    public function __construct() {
        $this->license_server_url = SCAI_LICENSE_SERVER;
    }
    
    /**
     * Validate license key
     */
    public function validate_license($license_key) {
        $response = wp_remote_post($this->license_server_url . 'validate', array(
            'body' => array(
                'license_key' => $license_key,
                'domain' => home_url(),
                'product' => 'smart-chat-ai',
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            return array(
                'valid' => false,
                'message' => 'Could not connect to license server.',
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($body['valid']) {
            // Store license data
            update_option('smart_chat_license_expires', $body['expires']);
            update_option('smart_chat_license_email', $body['email']);
            
            return array(
                'valid' => true,
                'message' => 'License activated successfully!',
                'expires' => $body['expires'],
                'email' => $body['email'],
            );
        } else {
            return array(
                'valid' => false,
                'message' => $body['message'] ?? 'Invalid license key.',
            );
        }
    }
    
    /**
     * Deactivate license
     */
    public function deactivate_license($license_key) {
        $response = wp_remote_post($this->license_server_url . 'deactivate', array(
            'body' => array(
                'license_key' => $license_key,
                'domain' => home_url(),
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Could not connect to license server.',
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($body['success']) {
            delete_option('smart_chat_license_key');
            delete_option('smart_chat_license_status');
            delete_option('smart_chat_license_expires');
            delete_option('smart_chat_license_email');
            
            return array(
                'success' => true,
                'message' => 'License deactivated.',
            );
        }
        
        return $body;
    }
    
    /**
     * Check if license is active
     */
    public function is_active() {
        $status = get_option('smart_chat_license_status');
        return $status === 'active';
    }
    
    /**
     * Get license info
     */
    public function get_license_info() {
        return array(
            'key' => get_option('smart_chat_license_key'),
            'status' => get_option('smart_chat_license_status'),
            'expires' => get_option('smart_chat_license_expires'),
            'email' => get_option('smart_chat_license_email'),
        );
    }
}
