<?php
/**
 * Form submission handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Smart_Forms_Handler {
    
    public function __construct() {
        add_action( 'wp_ajax_sfco_submit', array( $this, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_sfco_submit', array( $this, 'handle_submission' ) );
    }
    
    public function handle_submission() {
        // CRITICAL FIX: Sanitize nonce BEFORE verification
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        
        if ( ! wp_verify_nonce( $nonce, 'sfco_submit' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed', 'smart-forms-for-midland' ) ) );
        }
        
        // Validate required fields
        $required_fields = array( 'customer_name', 'customer_email', 'customer_phone', 'project_type' );
        foreach ( $required_fields as $field ) {
            if ( empty( $_POST[ $field ] ) ) {
                wp_send_json_error( array( 
                    'message' => sprintf( 
                        /* translators: %s: field name */
                        esc_html__( '%s is required', 'smart-forms-for-midland' ), 
                        esc_html( $field ) 
                    ) 
                ) );
            }
        }
        
        // Validate email
        if ( ! isset( $_POST['customer_email'] ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Email is required', 'smart-forms-for-midland' ) ) );
        }
        
        $email = sanitize_email( wp_unslash( $_POST['customer_email'] ) );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid email address', 'smart-forms-for-midland' ) ) );
        }
        
        // Handle photo uploads
        $photo_urls = array();
        
        if ( isset( $_FILES['photos'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified at start of handle_submission(); files validated in handle_photo_uploads().
            $photo_urls = $this->handle_photo_uploads();
            
            if ( is_wp_error( $photo_urls ) ) {
                wp_send_json_error(
                    array(
                        'message' => esc_html( $photo_urls->get_error_message() ),
                    )
                );
            }
        }
        
        // Calculate estimate
        $square_footage = isset( $_POST['square_footage'] ) ? absint( $_POST['square_footage'] ) : 0;
        $project_type = isset( $_POST['project_type'] ) ? sanitize_text_field( wp_unslash( $_POST['project_type'] ) ) : '';
        $estimate = $this->calculate_estimate( $square_footage, $project_type );
        
        // Prepare lead data
        $lead_data = array(
            'customer_name' => isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '',
            'customer_email' => $email,
            'customer_phone' => isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '',
            'project_type' => $project_type,
            'square_footage' => $square_footage,
            'material_type' => isset( $_POST['material_type'] ) ? sanitize_text_field( wp_unslash( $_POST['material_type'] ) ) : '',
            'finish_level' => isset( $_POST['finish_level'] ) ? sanitize_text_field( wp_unslash( $_POST['finish_level'] ) ) : '',
            'timeline' => isset( $_POST['timeline'] ) ? sanitize_text_field( wp_unslash( $_POST['timeline'] ) ) : '',
            'zip_code' => isset( $_POST['zip_code'] ) ? sanitize_text_field( wp_unslash( $_POST['zip_code'] ) ) : '',
            'additional_notes' => isset( $_POST['additional_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['additional_notes'] ) ) : '',
            'photo_urls' => $photo_urls,
            'estimated_cost_min' => $estimate['min'],
            'estimated_cost_max' => $estimate['max'],
        );
        
        // Save to database
        $lead_id = SFCO_Database::create_lead( $lead_data );
        
        if ( ! $lead_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Failed to save lead', 'smart-forms-for-midland' ) ) );
        }
        
        // Send notification email
        $this->send_notification_email( $lead_id, $lead_data );
        
        // Success response
        wp_send_json_success( array(
            'message' => esc_html__( 'Thank you! We\'ll contact you soon.', 'smart-forms-for-midland' ),
            'lead_id' => absint( $lead_id ),
            'estimate' => array(
                'min' => floatval( $estimate['min'] ),
                'max' => floatval( $estimate['max'] ),
            ),
        ) );
    }
    
    private function handle_photo_uploads() {
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        $uploaded_files = array();
        
        // Check if files exist and are valid
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_submission()
        if ( ! isset( $_FILES['photos'] ) || ! isset( $_FILES['photos']['name'] ) ) {
            return $uploaded_files;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_submission(), files validated below
        $files = $_FILES['photos'];
        
        // Max 5 photos, 5MB each
        $max_files = 5;
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file_count = is_array( $files['name'] ) ? count( $files['name'] ) : 1;
        
        if ( $file_count > $max_files ) {
            return new WP_Error( 'too_many_files', sprintf( 
                /* translators: %d: maximum number of files */
                esc_html__( 'Maximum %d photos allowed', 'smart-forms-for-midland' ), 
                $max_files 
            ) );
        }
        
        for ( $i = 0; $i < $file_count; $i++ ) {
            // Defensive checks for all array indexes
            if ( ! isset( $files['name'][ $i ] ) || ! isset( $files['tmp_name'][ $i ] ) ) {
                continue;
            }
            
            if ( ! isset( $files['error'][ $i ] ) || $files['error'][ $i ] !== UPLOAD_ERR_OK ) {
                continue;
            }
            
            if ( ! isset( $files['size'][ $i ] ) || $files['size'][ $i ] > $max_size ) {
                return new WP_Error( 'file_too_large', esc_html__( 'File size exceeds 5MB limit', 'smart-forms-for-midland' ) );
            }
            
            $file = array(
                'name' => sanitize_file_name( $files['name'][ $i ] ),
                'tmp_name' => $files['tmp_name'][ $i ],
                'error' => $files['error'][ $i ],
                'size' => $files['size'][ $i ],
            );
            
            // Validate file type using WordPress
            $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
            
            if ( ! $filetype['ext'] || ! $filetype['type'] ) {
                return new WP_Error( 'invalid_file_type', esc_html__( 'Invalid file type', 'smart-forms-for-midland' ) );
            }
            
            // Only allow images
            $allowed_types = array( 'jpg', 'jpeg', 'png', 'gif' );
            if ( ! in_array( $filetype['ext'], $allowed_types, true ) ) {
                return new WP_Error( 'invalid_image', esc_html__( 'Only JPG, PNG, and GIF images allowed', 'smart-forms-for-midland' ) );
            }
            
            $file['type'] = $filetype['type'];
            
            $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
            
            if ( isset( $upload['error'] ) ) {
                return new WP_Error( 'upload_failed', esc_html( $upload['error'] ) );
            }
            
            $uploaded_files[] = esc_url_raw( $upload['url'] );
        }
        
        return $uploaded_files;
    }
    
    private function calculate_estimate( $square_footage, $project_type ) {
        $rates = array(
            'Drywall Repair' => array( 'min' => 3.00, 'max' => 5.00 ),
            'Drywall Installation' => array( 'min' => 2.50, 'max' => 4.00 ),
            'Painting' => array( 'min' => 2.00, 'max' => 3.50 ),
            'Texturing' => array( 'min' => 1.50, 'max' => 2.50 ),
        );
        
        $rate = isset( $rates[ $project_type ] ) ? $rates[ $project_type ] : array( 'min' => 2.00, 'max' => 4.00 );
        
        return array(
            'min' => $square_footage * $rate['min'],
            'max' => $square_footage * $rate['max'],
        );
    }
    
    private function send_notification_email( $lead_id, $lead_data ) {
        $admin_email = get_option( 'admin_email' );
        $subject = sprintf( 
            /* translators: %s: customer name */
            esc_html__( 'New Lead from %s', 'smart-forms-for-midland' ), 
            $lead_data['customer_name'] 
        );
        
        $message = sprintf(
            "New lead #%d\n\nCustomer: %s\nEmail: %s\nPhone: %s\nProject: %s\nSquare Footage: %d\nTimeline: %s\n\nView in dashboard: %s",
            absint( $lead_id ),
            sanitize_text_field( $lead_data['customer_name'] ),
            sanitize_email( $lead_data['customer_email'] ),
            sanitize_text_field( $lead_data['customer_phone'] ),
            sanitize_text_field( $lead_data['project_type'] ),
            absint( $lead_data['square_footage'] ),
            sanitize_text_field( $lead_data['timeline'] ),
            esc_url( admin_url( 'admin.php?page=smart-forms-leads&lead_id=' . $lead_id ) )
        );
        
        wp_mail( $admin_email, $subject, $message );
    }
}

new Smart_Forms_Handler();
