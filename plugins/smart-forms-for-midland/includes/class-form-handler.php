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
        try {
        // CRITICAL FIX: Sanitize nonce BEFORE verification
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'sfco_submit' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed', 'smart-forms-for-midland' ) ) );
        }

        // Honeypot — every form's shortcode renders a hidden field named
        // sfco_hp_token. Real users never fill it; bots fill every
        // visible input. If the field has any value, silently treat the
        // submission as accepted (return success) so the bot doesn't
        // retry with a different strategy, but skip every side effect.
        if ( ! empty( $_POST['sfco_hp_token'] ) ) {
            wp_send_json_success( array( 'message' => 'OK' ) );
        }
        
        // Identify the form so we validate against ITS fields and connect the
        // lead to the right form. The chat embeds a DB-built form (its own
        // fields), so the old hardcoded customer_name/project_type required list
        // broke capture for anything other than the legacy [sfco_quote] form.
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        $form    = $form_id ? SFCO_Database::get_form( $form_id ) : null;

        $db_fields = array();
        if ( $form && ! empty( $form->fields_json ) ) {
            $decoded = json_decode( $form->fields_json, true );
            if ( is_array( $decoded ) ) {
                $db_fields = $decoded;
            }
        }

        if ( ! empty( $db_fields ) ) {
            // DB-built form: required = whatever the field builder marked required.
            foreach ( $db_fields as $f ) {
                $key = $f['key'] ?? '';
                if ( $key && ! empty( $f['required'] ) && empty( $_POST[ $key ] ) ) {
                    wp_send_json_error( array(
                        /* translators: %s: field label */
                        'message' => sprintf( esc_html__( '%s is required', 'smart-forms-for-midland' ), esc_html( $f['label'] ?? $key ) ),
                    ) );
                }
            }
        } else {
            // Legacy [sfco_quote] form keeps its original required set.
            foreach ( array( 'customer_name', 'customer_email', 'customer_phone', 'project_type' ) as $field ) {
                if ( empty( $_POST[ $field ] ) ) {
                    wp_send_json_error( array(
                        /* translators: %s: field name */
                        'message' => sprintf( esc_html__( '%s is required', 'smart-forms-for-midland' ), esc_html( $field ) ),
                    ) );
                }
            }
        }

        // Flexible identity mapping so custom field keys still produce a usable
        // lead (name / first_name+last_name, email, phone/tel).
        $name = $this->first_nonempty( array( 'customer_name', 'name', 'full_name' ) );
        if ( '' === $name ) {
            $name = trim( $this->first_nonempty( array( 'first_name', 'fname' ) ) . ' ' . $this->first_nonempty( array( 'last_name', 'lname' ) ) );
        }
        $email = sanitize_email( $this->first_nonempty( array( 'customer_email', 'email' ) ) );
        $phone = $this->first_nonempty( array( 'customer_phone', 'phone', 'tel' ) );

        if ( '' !== $email && ! is_email( $email ) ) {
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
        
        // Collect any other submitted fields (custom DB-form fields) so nothing
        // is lost — stored on the lead as extra_fields_json.
        $reserved = array(
            'action', '_wpnonce', '_wp_http_referer', 'sfco_hp_token', 'form_id',
            'customer_name', 'name', 'full_name', 'first_name', 'fname', 'last_name', 'lname',
            'customer_email', 'email', 'customer_phone', 'phone', 'tel',
            'project_type', 'square_footage', 'material_type', 'finish_level',
            'timeline', 'zip_code', 'additional_notes',
        );
        $extra_fields = array();
        foreach ( wp_unslash( $_POST ) as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; values sanitized below
            if ( in_array( $k, $reserved, true ) ) {
                continue;
            }
            $extra_fields[ sanitize_key( $k ) ] = is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( $v );
        }

        // Prepare lead data
        $lead_data = array(
            'form_id' => $form_id ?: 1,
            'customer_name' => sanitize_text_field( $name ),
            'customer_email' => $email,
            'customer_phone' => sanitize_text_field( $phone ),
            'project_type' => $project_type,
            'square_footage' => $square_footage,
            'material_type' => isset( $_POST['material_type'] ) ? sanitize_text_field( wp_unslash( $_POST['material_type'] ) ) : '',
            'finish_level' => isset( $_POST['finish_level'] ) ? sanitize_text_field( wp_unslash( $_POST['finish_level'] ) ) : '',
            'timeline' => isset( $_POST['timeline'] ) ? sanitize_text_field( wp_unslash( $_POST['timeline'] ) ) : '',
            'zip_code' => isset( $_POST['zip_code'] ) ? sanitize_text_field( wp_unslash( $_POST['zip_code'] ) ) : '',
            'additional_notes' => isset( $_POST['additional_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['additional_notes'] ) ) : '',
            'photo_urls' => $photo_urls,
            'extra_fields' => $extra_fields,
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

        // Where to send the visitor after submit (e.g. Calendly). Uses the
        // form's redirect URL if confirmation is set to "redirect", otherwise
        // the per-form Booking link. The chat widget ignores this (it shows its
        // own "Pick a time" button instead of navigating away).
        $redirect = '';
        $fs = ( $form && ! empty( $form->settings_json ) ) ? json_decode( $form->settings_json, true ) : array();
        if ( is_array( $fs ) ) {
            if ( 'redirect' === ( $fs['confirmation_type'] ?? '' ) && ! empty( $fs['redirect_url'] ) ) {
                $redirect = $fs['redirect_url'];
            } elseif ( ! empty( $fs['booking_url'] ) ) {
                $redirect = $fs['booking_url'];
            }
        }
        // Fall back to the global Calendly URL when the form has no per-form link.
        if ( '' === $redirect && class_exists( 'SFCO_Pro_Calendly' ) ) {
            $redirect = SFCO_Pro_Calendly::get_booking_url();
        }

        // Stamp the lead id + identity onto a Calendly booking link so the
        // Calendly webhook can map the resulting booking back to THIS lead
        // (utm_content=LEAD_<id>) and prefill the customer's details. No-op for
        // non-Calendly redirect targets.
        if ( '' !== $redirect && class_exists( 'SFCO_Pro_Calendly' ) ) {
            $redirect = SFCO_Pro_Calendly::decorate_booking_url( $redirect, (int) $lead_id, sanitize_text_field( $name ), $email );
        }

        // Success response
        wp_send_json_success( array(
            'message' => esc_html__( 'Thank you! We\'ll contact you soon.', 'smart-forms-for-midland' ),
            'lead_id' => absint( $lead_id ),
            'redirect' => esc_url_raw( $redirect ),
            'estimate' => array(
                'min' => floatval( $estimate['min'] ),
                'max' => floatval( $estimate['max'] ),
            ),
        ) );
        } catch ( \Throwable $e ) {
            // Surface the real fatal in the on-screen form message instead of a
            // bare "An error occurred", so the cause is visible without DevTools.
            wp_send_json_error( array(
                'message' => 'Error: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(),
            ) );
        }
    }

    /**
     * Return the first non-empty value among a list of $_POST keys. Lets the
     * handler accept several common field-key spellings (customer_name / name,
     * customer_phone / phone, etc.) so DB-built forms map cleanly to a lead.
     * Nonce is verified at the top of handle_submission().
     */
    private function first_nonempty( array $keys ) {
        foreach ( $keys as $k ) {
            if ( isset( $_POST[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $val = trim( (string) wp_unslash( $_POST[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( '' !== $val ) {
                    return $val;
                }
            }
        }
        return '';
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
