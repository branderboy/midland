<?php
/**
 * Smart Chat → Smart Forms bridge.
 *
 * Chat used to be a half-integrated silo: leads landed in wp_smart_chat_leads
 * but never made it into wp_sfco_leads, which meant the whole journey
 * machinery (CRM scoring, ServiceM8 push, visit-draft, Vapi, ops
 * notifications, Floor Care Plan, NPS survey) skipped chat leads entirely.
 *
 * This bridge listens on scai_lead_captured, normalizes the chat payload
 * into the Smart Forms lead schema, inserts a wp_sfco_leads row, then
 * fires sfco_lead_submitted so chat leads flow through the exact same
 * journey as a normal form submission.
 *
 * Dedupe: stamps a marker into wp_smart_chat_leads.sfco_lead_id (added via
 * an idempotent ALTER on bridge load) so a re-fired scai_lead_captured
 * for the same chat lead won't insert a duplicate sfco_leads row.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Chat_Forms_Bridge {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Priority 5 so we mirror the chat lead into wp_sfco_leads BEFORE
        // any other scai_lead_captured listener (e.g. the WhatsApp handoff
        // at priority 10) reads from it. The AC tag-application doesn't
        // hook scai_lead_captured directly anymore — it picks up the
        // mirrored lead through sfco_lead_submitted and tags it from
        // there, including the midland-source-chat source tag.
        add_action( 'scai_lead_captured', array( $this, 'mirror_to_smart_forms' ), 5, 2 );
        add_action( 'init',               array( $this, 'maybe_add_link_column' ), 20 );

        // Chat-owned Calendly (SCAI_Calendly) fires these when a visitor books or
        // cancels from the chat. We advance the SAME CRM lead the capture created
        // so the existing deal moves to Booked/Canceled and ServiceM8 +
        // ActiveCampaign run their booked/canceled flows — the chat's booking is
        // tagged in the CRM with no Smart Forms plugin in the loop.
        add_action( 'scai_lead_booked',   array( $this, 'on_chat_booked' ), 10, 2 );
        add_action( 'scai_lead_canceled', array( $this, 'on_chat_canceled' ), 10, 3 );
    }

    /**
     * Relay a chat booking to the CRM booked conversion on the mirrored lead.
     */
    public function on_chat_booked( $chat_lead_id, $data = array() ) {
        $sfco = $this->sfco_lead_row( $chat_lead_id );
        if ( ! $sfco ) {
            return; // capture was never mirrored (e.g. no email) — nothing to advance
        }
        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'sfco_leads',
            array( 'status' => 'booked' ),
            array( 'id' => (int) $sfco->id ),
            array( '%s' ),
            array( '%d' )
        );
        $sfco->status = 'booked';
        do_action( 'sfco_lead_booked', $sfco, false );
    }

    /**
     * Relay a chat cancellation to the CRM canceled conversion.
     */
    public function on_chat_canceled( $chat_lead_id, $data = array(), $reason = '' ) {
        $sfco = $this->sfco_lead_row( $chat_lead_id );
        if ( ! $sfco ) {
            return;
        }
        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'sfco_leads',
            array( 'status' => 'canceled' ),
            array( 'id' => (int) $sfco->id ),
            array( '%s' ),
            array( '%d' )
        );
        $sfco->status = 'canceled';
        do_action( 'sfco_lead_canceled', $sfco, sanitize_text_field( (string) $reason ) );
    }

    /**
     * Load the mirrored wp_sfco_leads row for a chat lead, or null.
     */
    private function sfco_lead_row( $chat_lead_id ) {
        global $wpdb;
        $sfco_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT sfco_lead_id FROM {$wpdb->prefix}smart_chat_leads WHERE id = %d",
            (int) $chat_lead_id
        ) );
        if ( $sfco_id <= 0 ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$wpdb->prefix}sfco_leads WHERE id = %d",
            $sfco_id
        ) );
    }

    /**
     * Idempotent schema patch — add sfco_lead_id to wp_smart_chat_leads so
     * we can dedupe re-fires. Runs once per request; the SHOW COLUMNS guard
     * keeps it cheap.
     */
    public function maybe_add_link_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_leads';
        $has   = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SHOW COLUMNS FROM {$table} LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'sfco_lead_id'
        ) );
        if ( ! $has ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN sfco_lead_id BIGINT UNSIGNED DEFAULT NULL AFTER id" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    /**
     * Hook target.
     *
     * @param int   $chat_lead_id  wp_smart_chat_leads.id
     * @param array $data          Original chat lead payload.
     */
    public function mirror_to_smart_forms( $chat_lead_id, $data ) {
        if ( ! is_array( $data ) ) {
            return;
        }
        $chat_lead_id = (int) $chat_lead_id;
        if ( $chat_lead_id <= 0 ) {
            return;
        }

        // Dedupe — if we already mirrored this chat lead, do nothing.
        if ( $this->already_mirrored( $chat_lead_id ) ) {
            return;
        }

        // Smart Forms requires customer_email — chat leads should always
        // collect it during the capture flow, but bail gracefully if not.
        $email = sanitize_email( (string) ( $data['email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            return;
        }

        $row = $this->map_to_sfco_row( $data );

        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        $ok = $wpdb->insert( $table, $row, $this->format_for( $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( false === $ok ) {
            return;
        }
        $sfco_lead_id = (int) $wpdb->insert_id;

        // Stamp the dedupe marker on the chat lead.
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'smart_chat_leads',
            array( 'sfco_lead_id' => $sfco_lead_id ),
            array( 'id' => $chat_lead_id ),
            array( '%d' ),
            array( '%d' )
        );

        // Hydrate the lead row + a minimal "form" object so listeners that
        // expect both args (priority 10 bridges, visit-draft, Vapi, ops
        // notifications) all work uniformly.
        $lead_row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $sfco_lead_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $lead_data = $lead_row ? get_object_vars( $lead_row ) : $row + array( 'id' => $sfco_lead_id, 'form_id' => $row['form_id'] );

        // Surface any stored extra fields (lead_intent, property_type, etc.) at
        // the top level so journey listeners see the same shape as a normal
        // form submission. DB columns win on any name clash.
        if ( ! empty( $lead_data['extra_fields_json'] ) ) {
            $extra = json_decode( (string) $lead_data['extra_fields_json'], true );
            if ( is_array( $extra ) ) {
                $lead_data = array_merge( $extra, $lead_data );
            }
        }

        $form = (object) array(
            'id'    => (int) $row['form_id'],
            'title' => __( 'Chat (AI)', 'smart-crm-pro' ),
            'slug'  => 'chat',
        );

        /**
         * Hand the chat-sourced lead off to the standard Smart Forms journey.
         * This is the same action a normal form submission fires, so every
         * downstream listener (CRM scoring, AC, SM8 push, visit-draft, Vapi,
         * ops notifications) treats chat leads as first-class.
         */
        do_action( 'sfco_lead_submitted', $sfco_lead_id, $lead_data, $form );
    }

    /**
     * Translate chat lead payload → Smart Forms lead row.
     *
     * Chat collects a free-form 'message' plus optional service_type /
     * budget / timeline. Smart Forms expects discrete columns
     * (project_type, additional_notes, timeline, etc.). The mapping below
     * preserves everything that maps cleanly and stashes the rest in
     * extra_fields_json so nothing is lost.
     */
    private function map_to_sfco_row( $data ) {
        $name    = sanitize_text_field( (string) ( $data['name']  ?? '' ) );
        $email   = sanitize_email( (string) ( $data['email'] ?? '' ) );
        $phone   = sanitize_text_field( (string) ( $data['phone'] ?? '' ) );
        $message = sanitize_textarea_field( (string) ( $data['message'] ?? '' ) );

        // Service type → project_type if present; falls back to "Chat inquiry"
        // so the lead can be filtered in the Smart Forms entries view.
        $project_type = sanitize_text_field( (string) ( $data['service_type'] ?? '' ) );
        if ( '' === $project_type ) {
            $project_type = 'Chat inquiry';
        }

        $extra = array(
            'lead_source'    => 'chat',
            'session_id'     => sanitize_text_field( (string) ( $data['session_id'] ?? '' ) ),
            'project_budget' => sanitize_text_field( (string) ( $data['project_budget'] ?? '' ) ),
        );

        return array(
            'form_id'           => (int) apply_filters( 'scrm_chat_bridge_form_id', 1 ),
            'customer_name'     => $name,
            'customer_email'    => $email,
            'customer_phone'    => $phone,
            'project_type'      => $project_type,
            'timeline'          => sanitize_text_field( (string) ( $data['timeline'] ?? '' ) ),
            'zip_code'          => sanitize_text_field( (string) ( $data['zip_code'] ?? '' ) ),
            'additional_notes'  => $message,
            'extra_fields_json' => wp_json_encode( $extra ),
            'status'            => 'new',
            'created_at'        => current_time( 'mysql' ),
        );
    }

    private function format_for( $row ) {
        $formats = array();
        foreach ( $row as $key => $val ) {
            if ( 'form_id' === $key ) {
                $formats[] = '%d';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }

    private function already_mirrored( $chat_lead_id ) {
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT sfco_lead_id FROM {$wpdb->prefix}smart_chat_leads WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $chat_lead_id
        ) );
        return ! empty( $existing );
    }
}

SCRM_Chat_Forms_Bridge::get_instance();
