<?php
/**
 * Database handler for Midland Smart Forms.
 *
 * Two tables:
 *  - sfco_forms    — form definitions (title, status, fields JSON, settings JSON, view counter)
 *  - sfco_leads    — submissions, tied to a form via form_id
 *
 * sfco_leads existed before multi-form support; the form_id column is added
 * via dbDelta and existing rows default to form_id = 1 (the legacy default form).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Database {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $forms_table = $wpdb->prefix . 'sfco_forms';
        $leads_table = $wpdb->prefix . 'sfco_leads';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$forms_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL DEFAULT '',
            slug varchar(100) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'active',
            fields_json longtext DEFAULT NULL,
            settings_json longtext DEFAULT NULL,
            view_count int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY slug (slug)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$leads_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL DEFAULT 1,
            customer_name varchar(255) NOT NULL,
            customer_email varchar(255) NOT NULL,
            customer_phone varchar(50) NOT NULL,
            project_type varchar(100) NOT NULL,
            square_footage int(11) DEFAULT NULL,
            material_type varchar(100) DEFAULT NULL,
            finish_level varchar(100) DEFAULT NULL,
            timeline varchar(50) DEFAULT NULL,
            zip_code varchar(20) DEFAULT NULL,
            additional_notes text DEFAULT NULL,
            photo_urls text DEFAULT NULL,
            extra_fields_json longtext DEFAULT NULL,
            estimated_cost_min decimal(10,2) DEFAULT NULL,
            estimated_cost_max decimal(10,2) DEFAULT NULL,
            priority varchar(20) DEFAULT NULL,
            area varchar(20) DEFAULT NULL,
            reminder_due_at datetime DEFAULT NULL,
            job_id varchar(100) DEFAULT NULL,
            status varchar(50) DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY timeline (timeline),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};" );

        update_option( 'sfco_version', SFCO_VERSION );

        // Seed the floor-care template library on first activation. Idempotent —
        // checks by slug, skips any that already exist so operator edits aren't
        // overwritten.
        self::seed_templates();
    }

    /**
     * Pre-seed Midland's floor-care form templates. Only inserts forms whose
     * slugs don't already exist.
     */
    public static function seed_templates() {
        $templates = self::default_templates();
        foreach ( $templates as $tpl ) {
            $existing = self::get_form_by_slug( $tpl['slug'] );
            if ( $existing ) continue;
            self::create_form( array(
                'title'         => $tpl['title'],
                'slug'          => $tpl['slug'],
                'status'        => $tpl['status'] ?? 'active',
                'fields_json'   => wp_json_encode( $tpl['fields'] ),
                'settings_json' => wp_json_encode( $tpl['settings'] ?? array() ),
            ) );
        }
    }

    /**
     * The starter-template library. Each entry seeds one row in sfco_forms.
     */
    public static function default_templates() {
        return array(
            array(
                'slug'   => 'free-floor-care-evaluation',
                'title'  => 'Free Floor Care Evaluation Visit',
                'status' => 'active',
                'fields' => array(
                    array( 'type' => 'text',     'key' => 'customer_name',  'label' => 'Name',          'required' => true ),
                    array( 'type' => 'email',    'key' => 'customer_email', 'label' => 'Email',         'required' => true ),
                    array( 'type' => 'tel',      'key' => 'customer_phone', 'label' => 'Phone',         'required' => true ),
                    array( 'type' => 'select',   'key' => 'project_type',   'label' => 'Service',       'required' => true,
                           'options' => array( 'Residential Carpet Cleaning', 'Commercial Carpet Care', 'Commercial Floor Stripping & Wax', 'Tile & Grout Cleaning', 'Water Damage Restoration', 'Concrete Polishing' ) ),
                    array( 'type' => 'text',     'key' => 'zip_code',       'label' => 'ZIP Code' ),
                    array( 'type' => 'date',     'key' => 'visit_day',      'label' => 'Preferred Visit Day' ),
                    array( 'type' => 'textarea', 'key' => 'additional_notes','label' => 'Notes' ),
                ),
                'settings' => array(
                    'notify_email' => '',
                    'confirmation' => 'Thanks! We\'ll reach out within one business day to schedule your free evaluation.',
                    'crm_push'     => true,
                ),
            ),
            array(
                'slug'   => 'residential-carpet-quote',
                'title'  => 'Quote Request — Residential Carpet Cleaning',
                'status' => 'active',
                'fields' => array(
                    array( 'type' => 'text',     'key' => 'customer_name',  'label' => 'Name',           'required' => true ),
                    array( 'type' => 'email',    'key' => 'customer_email', 'label' => 'Email',          'required' => true ),
                    array( 'type' => 'tel',      'key' => 'customer_phone', 'label' => 'Phone',          'required' => true ),
                    array( 'type' => 'number',   'key' => 'square_footage', 'label' => 'Approx. sq ft' ),
                    array( 'type' => 'select',   'key' => 'timeline',       'label' => 'Timeline',
                           'options' => array( 'ASAP', 'This Week', 'This Month', 'Just Researching' ) ),
                    array( 'type' => 'text',     'key' => 'zip_code',       'label' => 'ZIP Code' ),
                    array( 'type' => 'textarea', 'key' => 'additional_notes','label' => 'Stains, pets, or anything else we should know' ),
                ),
                'settings' => array(
                    'confirmation' => 'Thanks! You\'ll get a ballpark quote by email shortly.',
                    'crm_push'     => true,
                ),
            ),
            array(
                'slug'   => 'commercial-floor-care-quote',
                'title'  => 'Quote Request — Commercial Floor Care',
                'status' => 'active',
                'fields' => array(
                    array( 'type' => 'text',     'key' => 'customer_name',  'label' => 'Your Name',         'required' => true ),
                    array( 'type' => 'text',     'key' => 'company_name',   'label' => 'Company',           'required' => true ),
                    array( 'type' => 'email',    'key' => 'customer_email', 'label' => 'Email',             'required' => true ),
                    array( 'type' => 'tel',      'key' => 'customer_phone', 'label' => 'Phone',             'required' => true ),
                    array( 'type' => 'select',   'key' => 'project_type',   'label' => 'Service Needed',    'required' => true,
                           'options' => array( 'Commercial Carpet Care', 'Floor Stripping & Wax', 'Tile & Grout', 'Concrete Polishing', 'Multiple / Mixed' ) ),
                    array( 'type' => 'number',   'key' => 'square_footage', 'label' => 'Approx. total sq ft' ),
                    array( 'type' => 'select',   'key' => 'timeline',       'label' => 'Timeline',
                           'options' => array( 'ASAP', 'Within 2 weeks', 'Within a month', 'Quarterly recurring' ) ),
                    array( 'type' => 'text',     'key' => 'zip_code',       'label' => 'Building ZIP Code' ),
                    array( 'type' => 'textarea', 'key' => 'additional_notes','label' => 'Access notes, hours, anything else' ),
                ),
                'settings' => array(
                    'confirmation' => 'Thanks. A commercial specialist will follow up within one business day.',
                    'crm_push'     => true,
                ),
            ),
            array(
                'slug'   => 'reach-us',
                'title'  => 'Reach Us',
                'status' => 'active',
                'fields' => array(
                    array( 'type' => 'text',     'key' => 'customer_name',  'label' => 'Name',  'required' => true ),
                    array( 'type' => 'email',    'key' => 'customer_email', 'label' => 'Email', 'required' => true ),
                    array( 'type' => 'tel',      'key' => 'customer_phone', 'label' => 'Phone' ),
                    array( 'type' => 'textarea', 'key' => 'additional_notes','label' => 'How can we help?', 'required' => true ),
                ),
                'settings' => array(
                    'confirmation' => 'Thanks for reaching out — we\'ll respond within one business day.',
                    'crm_push'     => false,
                ),
            ),
            array(
                'slug'   => 'schedule-a-visit',
                'title'  => 'Schedule a Visit / Site Audit',
                'status' => 'active',
                'fields' => array(
                    array( 'type' => 'text',     'key' => 'first_name',           'label' => 'First Name',                  'required' => true ),
                    array( 'type' => 'text',     'key' => 'last_name',            'label' => 'Last Name',                   'required' => true ),
                    array( 'type' => 'text',     'key' => 'business_name',        'label' => 'Business Name',               'required' => true ),
                    array( 'type' => 'email',    'key' => 'customer_email',       'label' => 'Email',                       'required' => true ),
                    array( 'type' => 'tel',      'key' => 'customer_phone',       'label' => 'Phone' ),
                    array( 'type' => 'select',   'key' => 'multiple_locations',   'label' => 'Are there multiple locations?',
                           'options' => array( 'No', 'Yes' ) ),
                    array( 'type' => 'select',   'key' => 'package_interest',     'label' => 'What package are you interested in?',
                           'options' => array( 'Standard', 'Premium', 'Enterprise', 'Not sure yet' ) ),
                    array( 'type' => 'select',   'key' => 'emergency_service',    'label' => 'Do you require emergency service?',
                           'options' => array( 'No', 'Yes' ) ),
                    array( 'type' => 'number',   'key' => 'square_footage',       'label' => 'Square Footage' ),
                    array( 'type' => 'select',   'key' => 'flooring_type',        'label' => 'Flooring Type',
                           'options' => array( 'Carpet', 'Tile', 'VCT / Vinyl', 'Hardwood', 'Concrete', 'Mixed' ) ),
                    array( 'type' => 'checkbox', 'key' => 'pain_points',          'label' => 'What problems are you trying to solve?',
                           'options' => array(
                               'Stains and spills that are difficult to remove',
                               'Maintaining a consistent shine and polish',
                               'Scratches and scuffs from heavy foot traffic',
                               'Discoloration and fading over time',
                               'Keeping the floors dust-free and clean',
                           ) ),
                    array( 'type' => 'select',   'key' => 'floor_finish',         'label' => 'Preferred floor finish',
                           'options' => array( 'High Gloss', 'Satin', 'Matte', 'No preference' ) ),
                    array( 'type' => 'select',   'key' => 'schedule_site_visit',  'label' => 'Would you like to schedule a site visit?',
                           'options' => array( 'Yes', 'No' ) ),
                    array( 'type' => 'text',     'key' => 'address_street',       'label' => 'Business Location — Street Address' ),
                    array( 'type' => 'text',     'key' => 'address_line2',        'label' => 'Address Line 2' ),
                    array( 'type' => 'text',     'key' => 'address_city',         'label' => 'City' ),
                    array( 'type' => 'text',     'key' => 'address_state',        'label' => 'State / Province' ),
                    array( 'type' => 'text',     'key' => 'zip_code',             'label' => 'ZIP / Postal Code' ),
                    array( 'type' => 'textarea', 'key' => 'additional_notes',     'label' => 'Notes' ),
                ),
                'settings' => array(
                    'confirmation' => 'Thanks! A commercial specialist will be in touch to schedule your site visit.',
                    'crm_push'     => true,
                ),
            ),
            array(
                'slug'   => 'homepage-form',
                'title'  => 'Homepage Form',
                'status' => 'active',
                'fields' => array(
                    array( 'type' => 'text',     'key' => 'customer_name',  'label' => 'Name',                            'required' => true ),
                    array( 'type' => 'tel',      'key' => 'customer_phone', 'label' => 'Phone',                           'required' => true ),
                    array( 'type' => 'checkbox', 'key' => 'project_type',   'label' => 'Choose a Service',                'required' => true,
                           'options' => array( 'Floor Cleaning', 'Carpet Cleaning', 'Carpet Installation' ) ),
                    array( 'type' => 'select',   'key' => 'emergency_service','label' => 'Do you require emergency service?',
                           'options' => array( 'No', 'Yes' ) ),
                    array( 'type' => 'text',     'key' => 'zip_code',       'label' => 'Zip Code',                        'required' => true ),
                ),
                'settings' => array(
                    'confirmation' => "Got it! We'll be in touch within 15 minutes during business hours. (After hours? We'll call first thing tomorrow.)",
                    'crm_push'     => true,
                ),
            ),
            array(
                'slug'   => 'residential-same-day-booking',
                'title'  => 'Residential Same-Day Booking',
                'status' => 'active',
                'fields' => array(
                    array( 'type' => 'text',     'key' => 'customer_name',   'label' => 'Name',                     'required' => true ),
                    array( 'type' => 'tel',      'key' => 'customer_phone',  'label' => 'Phone',                    'required' => true ),
                    array( 'type' => 'text',     'key' => 'address_street',  'label' => 'Address',                  'required' => true ),
                    array( 'type' => 'text',     'key' => 'zip_code',        'label' => 'ZIP Code',                 'required' => true ),
                    array( 'type' => 'select',   'key' => 'project_type',    'label' => 'Service Needed',           'required' => true,
                           'options' => array(
                               'Carpet Cleaning',
                               'Tile & Grout Cleaning',
                               'Stain / Spot Treatment',
                               'Water Damage Extraction (urgent)',
                               'Other — explain in notes',
                           ) ),
                    array( 'type' => 'select',   'key' => 'time_window',     'label' => 'When can we come out?',    'required' => true,
                           'options' => array( 'Today AM', 'Today PM', 'Tomorrow AM', 'Tomorrow PM', 'This weekend' ) ),
                    array( 'type' => 'select',   'key' => 'emergency_service','label' => 'Is this an emergency?',
                           'options' => array( 'No', 'Yes — water damage / urgent' ) ),
                    array( 'type' => 'textarea', 'key' => 'additional_notes','label' => 'Notes (gate code, pets, stairs, etc.)' ),
                ),
                'settings' => array(
                    'confirmation' => 'Got it! We\'ll call you back within 15 minutes to confirm the visit window and dispatch a tech.',
                    'crm_push'     => true,
                ),
            ),
            array(
                'slug'   => 'short-form-free-estimate',
                'title'  => 'Short Form — Free Estimate',
                'status' => 'active',
                'fields' => array(
                    array( 'type' => 'text',     'key' => 'customer_name',  'label' => 'Name',         'required' => true ),
                    array( 'type' => 'tel',      'key' => 'customer_phone', 'label' => 'Phone',        'required' => true ),
                    array( 'type' => 'text',     'key' => 'zip_code',       'label' => 'ZIP Code' ),
                ),
                'settings' => array(
                    'confirmation' => 'Got it. We\'ll call you back to lock in a free estimate.',
                    'crm_push'     => true,
                ),
            ),
        );
    }

    // ── Forms ──────────────────────────────────────────────────────────────────

    public static function create_form( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_forms';
        $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB
            'title'         => sanitize_text_field( $data['title'] ),
            'slug'          => sanitize_title( $data['slug'] ?? $data['title'] ),
            'status'        => sanitize_text_field( $data['status'] ?? 'active' ),
            'fields_json'   => isset( $data['fields_json'] )   ? wp_kses_post( $data['fields_json'] )   : null,
            'settings_json' => isset( $data['settings_json'] ) ? wp_kses_post( $data['settings_json'] ) : null,
        ), array( '%s', '%s', '%s', '%s', '%s' ) );
        return $wpdb->insert_id;
    }

    public static function update_form( $form_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_forms';
        $update = array();
        $fmt    = array();
        foreach ( array( 'title', 'slug', 'status' ) as $k ) {
            if ( isset( $data[ $k ] ) ) { $update[ $k ] = sanitize_text_field( $data[ $k ] ); $fmt[] = '%s'; }
        }
        foreach ( array( 'fields_json', 'settings_json' ) as $k ) {
            if ( isset( $data[ $k ] ) ) { $update[ $k ] = wp_kses_post( $data[ $k ] ); $fmt[] = '%s'; }
        }
        if ( ! $update ) return false;
        $update['updated_at'] = current_time( 'mysql' );
        $fmt[] = '%s';
        return $wpdb->update( $table, $update, array( 'id' => absint( $form_id ) ), $fmt, array( '%d' ) ); // phpcs:ignore
    }

    public static function delete_form( $form_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_forms';
        return $wpdb->delete( $table, array( 'id' => absint( $form_id ) ), array( '%d' ) ); // phpcs:ignore
    }

    public static function get_form( $form_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_forms';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $form_id ) ) ); // phpcs:ignore
    }

    public static function get_form_by_slug( $slug ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_forms';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $slug ) ) ); // phpcs:ignore
    }

    public static function get_forms( $status = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_forms';
        if ( $status ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC", sanitize_text_field( $status ) ) ); // phpcs:ignore
        }
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore
    }

    public static function get_form_stats( $form_id ) {
        global $wpdb;
        $leads = $wpdb->prefix . 'sfco_leads';
        $forms = $wpdb->prefix . 'sfco_forms';
        $form  = self::get_form( $form_id );
        $entries = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads} WHERE form_id = %d", absint( $form_id ) ) ); // phpcs:ignore
        $views = $form ? (int) $form->view_count : 0;
        $conv = $views > 0 ? round( ( $entries / $views ) * 100, 1 ) : 0;
        return array( 'entries' => $entries, 'views' => $views, 'conversion' => $conv );
    }

    public static function increment_form_view( $form_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_forms';
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET view_count = view_count + 1 WHERE id = %d", absint( $form_id ) ) ); // phpcs:ignore
    }

    // ── Leads / entries ────────────────────────────────────────────────────────

    public static function get_lead( $lead_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $lead_id ) ) ); // phpcs:ignore
    }

    public static function get_leads( $args = array() ) {
        global $wpdb;
        $defaults = array(
            'form_id'  => 0,
            'status'   => '',
            'timeline' => '',
            'limit'    => 20,
            'offset'   => 0,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $allowed_orderby = array( 'created_at', 'status', 'timeline', 'id', 'customer_name' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';
        $table   = $wpdb->prefix . 'sfco_leads';

        $where_sql = '1=1';
        $prepare   = array();

        if ( ! empty( $args['form_id'] ) ) {
            $where_sql .= ' AND form_id = %d';
            $prepare[] = absint( $args['form_id'] );
        }
        if ( ! empty( $args['status'] ) ) {
            $where_sql .= ' AND status = %s';
            $prepare[] = sanitize_text_field( $args['status'] );
        }
        if ( ! empty( $args['timeline'] ) ) {
            $where_sql .= ' AND timeline = %s';
            $prepare[] = sanitize_text_field( $args['timeline'] );
        }

        $prepare[] = absint( $args['limit'] );
        $prepare[] = absint( $args['offset'] );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $prepare );
        return $wpdb->get_results( $sql ); // phpcs:ignore
    }

    public static function create_lead( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        $row = array(
            'form_id'            => isset( $data['form_id'] )         ? absint( $data['form_id'] )         : 1,
            'customer_name'      => sanitize_text_field( $data['customer_name'] ?? '' ),
            'customer_email'     => sanitize_email( $data['customer_email'] ?? '' ),
            'customer_phone'     => sanitize_text_field( $data['customer_phone'] ?? '' ),
            'project_type'       => sanitize_text_field( $data['project_type'] ?? '' ),
            'square_footage'     => isset( $data['square_footage'] )   ? absint( $data['square_footage'] )            : null,
            'material_type'      => isset( $data['material_type'] )    ? sanitize_text_field( $data['material_type'] ) : null,
            'finish_level'       => isset( $data['finish_level'] )     ? sanitize_text_field( $data['finish_level'] )  : null,
            'timeline'           => isset( $data['timeline'] )         ? sanitize_text_field( $data['timeline'] )      : null,
            'zip_code'           => isset( $data['zip_code'] )         ? sanitize_text_field( $data['zip_code'] )      : null,
            'additional_notes'   => isset( $data['additional_notes'] ) ? sanitize_textarea_field( $data['additional_notes'] ) : null,
            'photo_urls'         => isset( $data['photo_urls'] )       ? wp_json_encode( $data['photo_urls'] ) : null,
            'extra_fields_json'  => isset( $data['extra_fields'] )     ? wp_json_encode( $data['extra_fields'] ) : null,
            'estimated_cost_min' => isset( $data['estimated_cost_min'] ) ? floatval( $data['estimated_cost_min'] ) : null,
            'estimated_cost_max' => isset( $data['estimated_cost_max'] ) ? floatval( $data['estimated_cost_max'] ) : null,
        );
        $fmt = array( '%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%f','%f' );
        $wpdb->insert( $table, $row, $fmt ); // phpcs:ignore
        $lead_id = $wpdb->insert_id;

        /**
         * Fires immediately after a Smart Forms lead is saved. Smart CRM Pro
         * (and anything else) hooks this to push the lead into a CRM, tag it,
         * etc. Receives the new lead ID + the row data + the parent form row.
         */
        do_action( 'sfco_lead_submitted', $lead_id, $row, self::get_form( $row['form_id'] ) );

        return $lead_id;
    }

    public static function update_lead_status( $lead_id, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        return $wpdb->update( $table, array( 'status' => sanitize_text_field( $status ) ), array( 'id' => absint( $lead_id ) ), array( '%s' ), array( '%d' ) ); // phpcs:ignore
    }
}
