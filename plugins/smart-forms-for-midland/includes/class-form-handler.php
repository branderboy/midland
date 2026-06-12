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

        // IP-based rate limiting. reCAPTCHA is opt-in and the honeypot only
        // stops the dumbest bots, so an always-on per-IP transient cap is the
        // floor of bot protection that survives even when no keys are set. The
        // limit mirrors Smart Chat's transient approach and is filterable for
        // legitimately high-traffic forms.
        if ( ! $this->check_rate_limit() ) {
            wp_send_json_error( array(
                'message' => esc_html__( 'Too many submissions. Please wait a little while and try again.', 'smart-forms-for-midland' ),
            ) );
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

        // reCAPTCHA v3 (only enforced when the form has both site + secret keys).
        if ( ! $this->verify_recaptcha( $form ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Spam verification failed. Please reload the page and try again.', 'smart-forms-for-midland' ) ) );
        }

        if ( ! empty( $db_fields ) ) {
            // DB-built form: required = whatever the field builder marked required.
            foreach ( $db_fields as $f ) {
                $key = $f['key'] ?? '';
                if ( '' === $key || empty( $f['required'] ) ) {
                    continue;
                }
                if ( 'file' === ( $f['type'] ?? '' ) ) {
                    // File inputs arrive in $_FILES, not $_POST — a required file
                    // field is satisfied when an upload is present (single or [].).
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at top of handle_submission()
                    $fname    = isset( $_FILES[ $key ]['name'] ) ? $_FILES[ $key ]['name'] : '';
                    $has_file = is_array( $fname ) ? ( count( array_filter( (array) $fname ) ) > 0 ) : ( '' !== (string) $fname );
                    if ( ! $has_file ) {
                        wp_send_json_error( array(
                            /* translators: %s: field label */
                            'message' => sprintf( esc_html__( '%s is required', 'smart-forms-for-midland' ), esc_html( $f['label'] ?? $key ) ),
                        ) );
                    }
                } elseif ( empty( $_POST[ $key ] ) ) {
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

        // Builder-created "File Upload" fields render <input type="file"
        // name="{key}">, so their upload arrives as $_FILES[{key}] — NOT photos[].
        // Process each declared file field and attach its stored URL(s) to the
        // lead (under photo_urls so they surface with the other attachments, and
        // keyed in $file_field_urls so the field association is preserved below).
        $file_field_urls = array();
        foreach ( $db_fields as $f ) {
            $key = $f['key'] ?? '';
            if ( '' === $key || 'photos' === $key || 'file' !== ( $f['type'] ?? '' ) ) {
                continue;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at top of handle_submission()
            if ( empty( $_FILES[ $key ] ) ) {
                continue;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; files validated in store_uploaded_files()
            $field_urls = $this->store_uploaded_files( $_FILES[ $key ] );
            if ( is_wp_error( $field_urls ) ) {
                wp_send_json_error( array( 'message' => esc_html( $field_urls->get_error_message() ) ) );
            }
            if ( ! empty( $field_urls ) ) {
                $photo_urls = array_merge( (array) $photo_urls, $field_urls );
                $file_field_urls[ $key ] = $field_urls;
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

        // Uploaded file fields aren't present in $_POST, so record their stored
        // URLs here keyed by field so the association survives on the lead.
        foreach ( $file_field_urls as $fk => $urls ) {
            $extra_fields[ sanitize_key( $fk ) ] = implode( ', ', array_map( 'esc_url_raw', $urls ) );
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
        
        // Email responses are CRM business (SCRM_Lead_Emails fires off
        // sfco_lead_submitted). The forms plugin renders forms and submits
        // leads; it never sends email.

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
            // Log the real fatal server-side for debugging, but never leak file
            // paths, line numbers, or DB internals to the (unauthenticated)
            // visitor — this handler also serves wp_ajax_nopriv.
            error_log( sprintf(
                'Smart Forms submission error: %s @ %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ) );
            wp_send_json_error( array(
                'message' => esc_html__( 'Something went wrong submitting the form. Please try again.', 'smart-forms-for-midland' ),
            ) );
        }
    }

    /**
     * Per-IP submission rate limiter backed by a transient.
     *
     * Keys on a salted hash of REMOTE_ADDR (never the raw IP, so nothing
     * personally identifying lands in the options table) and counts
     * submissions in a rolling window. Returns false once the cap is reached.
     * Both the cap and the window are filterable; a proxy-rotating bot can step
     * around it, but it raises the floor for the common case where neither
     * reCAPTCHA nor anything beyond the honeypot is configured.
     *
     * @return bool True if the submission is allowed, false if rate-limited.
     */
    private function check_rate_limit() {
        $limit  = (int) apply_filters( 'sfco_submission_rate_limit', 10 );
        $window = (int) apply_filters( 'sfco_submission_rate_window', HOUR_IN_SECONDS );

        // A non-positive limit disables the cap entirely (escape hatch).
        if ( $limit <= 0 ) {
            return true;
        }

        $ip   = ! empty( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : 'unknown';
        $salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'sfco';
        $key  = 'sfco_rate_' . substr( hash( 'sha256', $ip . '|' . $salt ), 0, 32 );

        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return false;
        }

        set_transient( $key, $count + 1, $window );
        return true;
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

    /**
     * Verify a Google reCAPTCHA v3 token for the submitted form.
     *
     * Enforcement is opt-in per form and only kicks in when BOTH the site key
     * and the secret are configured — a secret without a site key would block
     * every submission because the frontend can't mint a token. Transport
     * errors (Google outage) fail open so legitimate leads aren't dropped; a
     * present-but-invalid token, or a score below the (filterable) 0.5
     * threshold, fails closed.
     *
     * The form nonce is verified at the top of handle_submission().
     *
     * @param object|null $form Form row (settings_json holds the keys).
     * @return bool True to allow the submission, false to reject it.
     */
    private function verify_recaptcha( $form ) {
        if ( ! $form || empty( $form->settings_json ) ) {
            return true;
        }
        $settings = json_decode( $form->settings_json, true );
        if ( ! is_array( $settings ) ) {
            return true;
        }
        $site   = isset( $settings['recaptcha_site'] ) ? trim( (string) $settings['recaptcha_site'] ) : '';
        $secret = isset( $settings['recaptcha_secret'] ) ? trim( (string) $settings['recaptcha_secret'] ) : '';
        if ( '' === $site || '' === $secret ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle_submission()
        $token = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
        if ( '' === $token ) {
            return false;
        }

        $resp = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
            'timeout' => 10,
            'body'    => array(
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
            ),
        ) );
        if ( is_wp_error( $resp ) ) {
            // Fail open — don't let a Google outage drop real leads.
            return true;
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) || empty( $body['success'] ) ) {
            return false;
        }
        // v3 returns a 0.0–1.0 score; treat below the threshold as a bot.
        $score     = isset( $body['score'] ) ? (float) $body['score'] : 1.0;
        $threshold = (float) apply_filters( 'sfco_recaptcha_threshold', 0.5, $form );
        return $score >= $threshold;
    }

    private function handle_photo_uploads() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_submission(); files validated in store_uploaded_files().
        return isset( $_FILES['photos'] ) ? $this->store_uploaded_files( $_FILES['photos'] ) : array();
    }

    /**
     * Validate and store one $_FILES entry, whether it's a multi-file field
     * (photos[] — array-shaped) or a single-file field (a builder "File Upload"
     * field, name="{key}" — string-shaped). Returns an array of stored URLs, or
     * a WP_Error on a validation failure. Empty / no-file entries return array().
     *
     * @param array $files A single $_FILES[...] entry.
     * @return array|WP_Error
     */
    private function store_uploaded_files( $files ) {
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $uploaded_files = array();

        if ( empty( $files ) || ! isset( $files['name'] ) ) {
            return $uploaded_files;
        }

        // Normalize single-file (string) and multi-file ([] => array) shapes into
        // uniform lists, so indexing $names[$i] is always valid (the old code
        // indexed a string by [0], reading one character of the filename).
        $names = (array) $files['name'];
        $tmps  = (array) $files['tmp_name'];
        $errs  = (array) $files['error'];
        $sizes = (array) $files['size'];

        $max_files = 5;
        $max_size  = 5 * 1024 * 1024; // 5MB
        // Images (project photos) + common résumé/document formats (the job
        // application posts a PDF/DOC/DOCX through this same path).
        $allowed_types = array( 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx' );

        if ( count( $names ) > $max_files ) {
            return new WP_Error( 'too_many_files', sprintf(
                /* translators: %d: maximum number of files */
                esc_html__( 'Maximum %d files allowed', 'smart-forms-for-midland' ),
                $max_files
            ) );
        }

        foreach ( $names as $i => $name ) {
            // Skip empty / unfilled optional file inputs (UPLOAD_ERR_NO_FILE).
            if ( '' === $name || ! isset( $tmps[ $i ] ) ) {
                continue;
            }
            if ( ! isset( $errs[ $i ] ) || UPLOAD_ERR_OK !== $errs[ $i ] ) {
                continue;
            }
            if ( ! isset( $sizes[ $i ] ) || $sizes[ $i ] > $max_size ) {
                return new WP_Error( 'file_too_large', esc_html__( 'File size exceeds 5MB limit', 'smart-forms-for-midland' ) );
            }

            $file = array(
                'name'     => sanitize_file_name( $name ),
                'tmp_name' => $tmps[ $i ],
                'error'    => $errs[ $i ],
                'size'     => $sizes[ $i ],
            );

            $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
            if ( ! $filetype['ext'] || ! $filetype['type'] ) {
                return new WP_Error( 'invalid_file_type', esc_html__( 'Invalid file type', 'smart-forms-for-midland' ) );
            }
            if ( ! in_array( $filetype['ext'], $allowed_types, true ) ) {
                return new WP_Error( 'invalid_file', esc_html__( 'Only JPG, PNG, GIF images and PDF/DOC/DOCX documents are allowed', 'smart-forms-for-midland' ) );
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
