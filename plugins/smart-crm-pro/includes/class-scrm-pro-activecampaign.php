<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Smart CRM Pro → ActiveCampaign bridge.
 *
 * When a lead's status changes to "completed" (typically from the ServiceM8
 * webhook) we sync the contact to ActiveCampaign with a tag like
 * "midland-job-completed" so AC's own automation flows can fire (welcome,
 * upsell, reactivation series, etc.).
 *
 * Settings: Smart CRM Pro > ActiveCampaign
 */
class SCRM_Pro_ActiveCampaign {

    const OPT_API_URL    = 'scrm_pro_ac_api_url';
    const OPT_API_KEY    = 'scrm_pro_ac_api_key';
    const OPT_TAG        = 'scrm_pro_ac_tag';
    const OPT_ENABLED    = 'scrm_pro_ac_enabled';
    const OPT_LAST_PUSH  = 'scrm_pro_ac_last_push';
    // Deal pipeline — AC is the sales-pipeline master per Midland's playbook.
    // Each Smart Forms submission creates a contact + a Deal that progresses
    // through pipeline stages on lifecycle events (SM8 quote sent, job won, etc.).
    const OPT_DEAL_ENABLED   = 'scrm_pro_ac_deal_enabled';
    const OPT_PIPELINE_ID    = 'scrm_pro_ac_pipeline_id';   // AC "group" (pipeline) ID
    const OPT_DEAL_OWNER     = 'scrm_pro_ac_deal_owner';     // AC user ID of the deal owner
    const OPT_DEAL_CURRENCY  = 'scrm_pro_ac_deal_currency';
    const OPT_STAGE_NEW      = 'scrm_pro_ac_stage_new';
    const OPT_STAGE_QUOTED   = 'scrm_pro_ac_stage_quoted';
    const OPT_STAGE_BOOKED   = 'scrm_pro_ac_stage_booked';
    const OPT_STAGE_WON      = 'scrm_pro_ac_stage_won';
    const OPT_STAGE_LOST     = 'scrm_pro_ac_stage_lost';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',                array( $this, 'add_menu' ), 22 );
        add_action( 'admin_init',                array( $this, 'handle_save' ) );
        add_action( 'admin_init',                array( $this, 'handle_test' ) );

        // Booking events fire when a new lead is captured from any source.
        add_action( 'sfco_lead_created',         array( $this, 'on_lead_booked' ) );
        add_action( 'scai_lead_captured',        array( $this, 'on_chat_lead_captured' ), 10, 2 );

        // Lifecycle events when a job actually completes.
        add_action( 'sfco_lead_status_changed',  array( $this, 'on_status_changed' ), 10, 3 );
        add_action( 'sfco_lead_completed',       array( $this, 'on_lead_completed' ) );
        add_action( 'scrm_pro_job_completed',    array( $this, 'on_lead_completed' ) );
    }

    /**
     * Booking from any source that fires sfco_lead_created with a lead row/array.
     */
    public function on_lead_booked( $lead ) {
        $this->push_lead( $lead, 'booked' );
    }

    /**
     * Booking from the chat plugin — different payload shape (associative array).
     */
    public function on_chat_lead_captured( $lead_id, $data ) {
        // Reshape so push_lead's normalization picks it up.
        $lead = (object) array_merge(
            array( 'id' => (int) $lead_id ),
            (array) $data
        );
        $this->push_lead( $lead, 'booked' );
    }

    public function add_menu() {
        add_submenu_page(
            'smart-crm-pro',
            esc_html__( 'ActiveCampaign', 'smart-crm-pro' ),
            esc_html__( 'ActiveCampaign', 'smart-crm-pro' ),
            'manage_options',
            'scrm-pro-activecampaign',
            array( $this, 'render_page' )
        );
    }

    public function on_status_changed( $lead, $old_status, $new_status ) {
        if ( 'completed' !== strtolower( (string) $new_status ) ) {
            return;
        }
        $this->push_lead( $lead, 'completed' );
    }

    public function on_lead_completed( $lead ) {
        $this->push_lead( $lead, 'completed' );
    }

    /**
     * Lead categorization is two independent axes — segment (commercial vs
     * residential) and urgency (emergency vs normal). Treating them as one
     * enum buried commercial-emergency leads under the "emergency" branch and
     * stopped the Floor Care Plan flow from firing for them. The split below
     * keeps each axis pure so AC tags can compose them.
     *
     * Filters:
     *   - scrm_pro_lead_segment( 'commercial'|'residential', $lead )
     *   - scrm_pro_lead_emergency( bool, $lead )
     *   - scrm_pro_lead_category( 'commercial'|'residential'|'emergency', $lead )
     *     (kept for backward compat; returns 'emergency' if urgency=emergency,
     *     else the segment)
     */
    public function lead_segment( $lead ) {
        $explicit = strtolower( (string) $this->get_field( $lead, array( 'segment', 'lead_segment' ) ) );
        if ( in_array( $explicit, array( 'commercial', 'residential' ), true ) ) {
            return apply_filters( 'scrm_pro_lead_segment', $explicit, $lead );
        }

        // Some forms reuse the legacy "category" field for segment.
        $legacy = strtolower( (string) $this->get_field( $lead, array( 'category', 'job_category', 'lead_category' ) ) );
        if ( in_array( $legacy, array( 'commercial', 'residential' ), true ) ) {
            return apply_filters( 'scrm_pro_lead_segment', $legacy, $lead );
        }

        $project_type = strtolower( (string) $this->get_field( $lead, array( 'project_type', 'service_type' ) ) );
        $message      = strtolower( (string) $this->get_field( $lead, array( 'message' ) ) );

        $commercial_re = '/\b(commercial|business|office|retail|warehouse|industrial|hoa|property[ -]?manag)/i';
        if ( preg_match( $commercial_re, $project_type ) || preg_match( $commercial_re, $message ) ) {
            return apply_filters( 'scrm_pro_lead_segment', 'commercial', $lead );
        }

        return apply_filters( 'scrm_pro_lead_segment', 'residential', $lead );
    }

    public function is_emergency( $lead ) {
        $explicit = strtolower( (string) $this->get_field( $lead, array( 'urgency', 'is_emergency' ) ) );
        if ( in_array( $explicit, array( 'emergency', 'urgent', '1', 'true', 'yes' ), true ) ) {
            return apply_filters( 'scrm_pro_lead_emergency', true, $lead );
        }

        // Legacy single-axis "category" of "emergency" still flips the flag.
        $legacy = strtolower( (string) $this->get_field( $lead, array( 'category', 'job_category', 'lead_category' ) ) );
        if ( 'emergency' === $legacy ) {
            return apply_filters( 'scrm_pro_lead_emergency', true, $lead );
        }

        $timeline = strtolower( (string) $this->get_field( $lead, array( 'timeline' ) ) );
        $message  = strtolower( (string) $this->get_field( $lead, array( 'message' ) ) );

        $emergency_re = '/\b(emergency|urgent|asap|same[ -]?day|24[ -]?h(our)?s?|right[ -]now|today)\b/i';
        if ( preg_match( $emergency_re, $timeline ) || preg_match( $emergency_re, $message ) ) {
            return apply_filters( 'scrm_pro_lead_emergency', true, $lead );
        }

        return apply_filters( 'scrm_pro_lead_emergency', false, $lead );
    }

    /**
     * Backward-compatible single-string category. New code should call
     * lead_segment() and is_emergency() directly so a commercial-emergency lead
     * isn't reduced to just "emergency".
     */
    public function categorize_lead( $lead ) {
        $category = $this->is_emergency( $lead ) ? 'emergency' : $this->lead_segment( $lead );
        return apply_filters( 'scrm_pro_lead_category', $category, $lead );
    }

    /**
     * Map (lifecycle, segment, emergency) to the AC tags that should be applied.
     * Operators fully control the actual flow on the AC side; this only emits
     * the trigger-tag the flows listen for. Floor Care Plan offer is COMMERCIAL
     * only — residential completions don't get it.
     */
    public function tags_for( $lifecycle, $segment, $is_emergency ) {
        $segment     = in_array( $segment, array( 'commercial', 'residential' ), true ) ? $segment : 'residential';
        $is_emergency = (bool) $is_emergency;

        $tags = array();
        switch ( $lifecycle ) {
            case 'booked':
                $tags[] = 'midland-job-booked-' . $segment;
                if ( 'commercial' === $segment ) {
                    // Commercial bookings double up: the on-site visit flow + the
                    // base segment tag, so AC can run different automations off
                    // each (multi-touch outreach vs. simple notification).
                    $tags[] = 'midland-onsite-booked-commercial';
                }
                if ( $is_emergency ) {
                    $tags[] = 'midland-job-booked-emergency';
                    $tags[] = 'midland-job-booked-' . $segment . '-emergency';
                }
                break;

            case 'completed':
                $tags[] = 'midland-job-completed-' . $segment;
                if ( $is_emergency ) {
                    $tags[] = 'midland-job-completed-emergency';
                    $tags[] = 'midland-job-completed-' . $segment . '-emergency';
                }
                // Floor Care Plan offer = commercial only (with extra weight on
                // commercial-emergency since those benefit most from a recurring
                // maintenance plan after a costly emergency call-out).
                if ( 'commercial' === $segment ) {
                    $tags[] = 'midland-floor-care-plan-offer';
                    if ( $is_emergency ) {
                        $tags[] = 'midland-floor-care-plan-offer-emergency';
                    }
                }
                break;
        }
        return apply_filters( 'scrm_pro_ac_tags', $tags, $lifecycle, $segment, $is_emergency );
    }

    /**
     * Push a lead to ActiveCampaign with category-aware tags + booking metadata
     * as fieldValues so AC flows can personalize.
     *
     * @param mixed  $lead       Object or array.
     * @param string $lifecycle  'booked' or 'completed'.
     */
    private function push_lead( $lead, $lifecycle = 'completed' ) {
        if ( ! get_option( self::OPT_ENABLED, 0 ) ) {
            return;
        }
        $api_url = (string) get_option( self::OPT_API_URL, '' );
        $api_key = (string) get_option( self::OPT_API_KEY, '' );
        if ( '' === $api_url || '' === $api_key ) {
            return;
        }

        $email = sanitize_email( $this->get_field( $lead, array( 'customer_email', 'email' ) ) );
        if ( ! is_email( $email ) ) {
            return;
        }
        $name  = (string) $this->get_field( $lead, array( 'customer_name', 'name' ) );
        $phone = (string) $this->get_field( $lead, array( 'customer_phone', 'phone' ) );

        $segment      = $this->lead_segment( $lead );
        $is_emergency = $this->is_emergency( $lead );
        $category     = $is_emergency ? 'emergency' : $segment; // legacy field for AC

        $name_parts = explode( ' ', trim( $name ), 2 );

        // Forward booking metadata as AC fieldValues so flows can render
        // "you booked carpet cleaning at 1500 sqft on Tuesday" without us
        // pre-templating it. AC just needs the contact field to exist with
        // matching field name — which is operator-side setup.
        $field_values = array();
        $forward = array(
            'project_type'         => array( 'project_type', 'service_type' ),
            'timeline'             => array( 'timeline' ),
            'zip_code'             => array( 'zip_code', 'zip' ),
            'square_footage'       => array( 'square_footage', 'sqft', 'square_feet' ),
            'floor_type'           => array( 'floor_type', 'flooring' ),
            'frequency'            => array( 'frequency', 'cleaning_frequency' ),
            'job_id'               => array( 'job_id', 'id' ),
            'midland_category'     => array(),
            'midland_segment'      => array(),
            'midland_is_emergency' => array(),
            'floor_care_plan_url'  => array( 'floor_care_plan_url' ),
        );

        foreach ( $forward as $ac_field => $sources ) {
            if ( 'midland_category' === $ac_field ) {
                $value = $category;
            } elseif ( 'midland_segment' === $ac_field ) {
                $value = $segment;
            } elseif ( 'midland_is_emergency' === $ac_field ) {
                $value = $is_emergency ? '1' : '0';
            } else {
                $value = (string) $this->get_field( $lead, $sources );
            }
            if ( '' !== $value ) {
                $field_values[] = array( 'field' => $ac_field, 'value' => $value );
            }
        }

        $contact = array(
            'email'     => $email,
            'firstName' => $name_parts[0] ?? '',
            'lastName'  => $name_parts[1] ?? '',
            'phone'     => $phone,
        );
        if ( ! empty( $field_values ) ) {
            $contact['fieldValues'] = $field_values;
        }

        $response = wp_remote_post( untrailingslashit( $api_url ) . '/api/3/contact/sync', array(
            'headers' => array(
                'Api-Token'    => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'contact' => $contact ) ),
            'timeout' => 15,
        ) );

        $contact_id = null;
        if ( ! is_wp_error( $response ) ) {
            $body       = json_decode( wp_remote_retrieve_body( $response ), true );
            $contact_id = isset( $body['contact']['id'] ) ? (int) $body['contact']['id'] : null;
        }

        // Deal pipeline (AC owns the sales pipeline). Create a deal on first
        // push, then progress its stage on later lifecycle events via the
        // existing $lifecycle param: booked→Quoted/Booked, completed→Won.
        if ( $contact_id && get_option( self::OPT_DEAL_ENABLED, 0 ) ) {
            $existing_deal = $this->get_field( $lead, array( 'deal_id' ) );
            if ( '' === $existing_deal ) {
                $deal_id = $this->create_deal( $api_url, $api_key, $contact_id, $lead, $lifecycle );
                if ( $deal_id && ! empty( $lead['id'] ) ) {
                    global $wpdb;
                    $wpdb->update( $wpdb->prefix . 'sfco_leads', array( 'deal_id' => $deal_id ), array( 'id' => (int) $lead['id'] ), array( '%s' ), array( '%d' ) ); // phpcs:ignore
                }
            } else {
                $stage_id = $this->stage_id_for_lifecycle( $lifecycle );
                if ( $stage_id ) {
                    $this->update_deal_stage( $api_url, $api_key, $existing_deal, $stage_id );
                }
            }
        }

        $tags = $this->tags_for( $lifecycle, $segment, $is_emergency );
        if ( $contact_id ) {
            foreach ( $tags as $tag ) {
                $this->apply_tag( $api_url, $api_key, $contact_id, $tag );
            }
        }

        update_option( self::OPT_LAST_PUSH, array(
            'at'           => time(),
            'email'        => $email,
            'lifecycle'    => $lifecycle,
            'segment'      => $segment,
            'is_emergency' => $is_emergency ? 1 : 0,
            'category'     => $category, // legacy, kept for the admin label
            'tags'         => $tags,
            'ok'           => $contact_id ? 1 : 0,
        ) );

        do_action( 'scrm_pro_ac_pushed', $lead, $lifecycle, $segment, $is_emergency, $contact_id, $tags );
    }

    private function apply_tag( $api_url, $api_key, $contact_id, $tag_name ) {
        $api_url = untrailingslashit( $api_url );
        $headers = array(
            'Api-Token'    => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        );

        // Look up tag id (or create).
        $lookup = wp_remote_get( $api_url . '/api/3/tags?search=' . rawurlencode( $tag_name ), array(
            'headers' => $headers,
            'timeout' => 10,
        ) );
        $tag_id = null;
        if ( ! is_wp_error( $lookup ) ) {
            $body = json_decode( wp_remote_retrieve_body( $lookup ), true );
            foreach ( (array) ( $body['tags'] ?? array() ) as $t ) {
                if ( strtolower( (string) ( $t['tag'] ?? '' ) ) === strtolower( $tag_name ) ) {
                    $tag_id = (int) $t['id'];
                    break;
                }
            }
        }

        if ( ! $tag_id ) {
            $create = wp_remote_post( $api_url . '/api/3/tags', array(
                'headers' => $headers,
                'timeout' => 10,
                'body'    => wp_json_encode( array( 'tag' => array( 'tag' => $tag_name, 'tagType' => 'contact' ) ) ),
            ) );
            if ( ! is_wp_error( $create ) ) {
                $body   = json_decode( wp_remote_retrieve_body( $create ), true );
                $tag_id = isset( $body['tag']['id'] ) ? (int) $body['tag']['id'] : null;
            }
        }
        if ( ! $tag_id ) {
            return;
        }

        wp_remote_post( $api_url . '/api/3/contactTags', array(
            'headers' => $headers,
            'timeout' => 10,
            'body'    => wp_json_encode( array(
                'contactTag' => array( 'contact' => (int) $contact_id, 'tag' => (int) $tag_id ),
            ) ),
        ) );
    }

    // ── AC Deal pipeline helpers ─────────────────────────────────────────────

    /**
     * Map our lifecycle string → configured AC stage ID. Returns 0 if the
     * stage isn't configured (caller should skip the API call).
     */
    private function stage_id_for_lifecycle( $lifecycle ) {
        switch ( $lifecycle ) {
            case 'completed': return (int) get_option( self::OPT_STAGE_WON,    0 );
            case 'booked':    return (int) get_option( self::OPT_STAGE_BOOKED, 0 );
            case 'quoted':    return (int) get_option( self::OPT_STAGE_QUOTED, 0 );
            case 'lost':      return (int) get_option( self::OPT_STAGE_LOST,   0 );
            case 'new':
            default:          return (int) get_option( self::OPT_STAGE_NEW,    0 );
        }
    }

    /**
     * Estimate the deal value from the lead row. Prefer the operator-set
     * estimated_cost_max, fall back to sqft * a per-service rate, finally 0.
     */
    private function deal_value_for( $lead ) {
        $max = (float) $this->get_field( $lead, array( 'estimated_cost_max' ) );
        if ( $max > 0 ) return $max;
        $sqft = (int) $this->get_field( $lead, array( 'square_footage' ) );
        if ( $sqft > 0 ) {
            $service = strtolower( (string) $this->get_field( $lead, array( 'project_type' ) ) );
            $rate = 0.30; // residential carpet baseline
            if ( str_contains( $service, 'commercial' ) || str_contains( $service, 'stripping' ) ) $rate = 0.45;
            if ( str_contains( $service, 'concrete' ) ) $rate = 1.20;
            if ( str_contains( $service, 'water' ) )    $rate = 3.50;
            return round( $sqft * $rate, 2 );
        }
        return 0;
    }

    /**
     * Create a deal in ActiveCampaign tied to the contact. Returns the new
     * deal ID (string) on success, or '' on failure.
     */
    private function create_deal( $api_url, $api_key, $contact_id, $lead, $lifecycle ) {
        $pipeline = (int) get_option( self::OPT_PIPELINE_ID, 0 );
        $stage    = $this->stage_id_for_lifecycle( $lifecycle );
        if ( ! $pipeline || ! $stage ) return '';

        $value_dollars = $this->deal_value_for( $lead );
        $value_cents   = (int) round( $value_dollars * 100 ); // AC stores deal value in cents
        $currency      = strtolower( (string) get_option( self::OPT_DEAL_CURRENCY, 'usd' ) );
        $owner         = (int) get_option( self::OPT_DEAL_OWNER, 0 );

        $name    = (string) $this->get_field( $lead, array( 'customer_name', 'name' ) );
        $service = (string) $this->get_field( $lead, array( 'project_type' ) );
        $title   = trim( ( $name ?: 'New Lead' ) . ' — ' . ( $service ?: 'Smart Forms' ) );

        $deal = array(
            'contact'  => (int) $contact_id,
            'title'    => $title,
            'value'    => $value_cents,
            'currency' => $currency,
            'group'    => $pipeline,
            'stage'    => $stage,
            'status'   => 0, // 0=open
        );
        if ( $owner > 0 ) $deal['owner'] = $owner;

        $response = wp_remote_post( untrailingslashit( $api_url ) . '/api/3/deals', array(
            'headers' => array(
                'Api-Token'    => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'deal' => $deal ) ),
            'timeout' => 15,
        ) );
        if ( is_wp_error( $response ) ) return '';
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) return '';
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['deal']['id'] ) ? (string) $body['deal']['id'] : '';
    }

    /**
     * Advance an existing deal to a new stage. No-op if stage_id is 0.
     */
    private function update_deal_stage( $api_url, $api_key, $deal_id, $stage_id ) {
        $deal_id  = (string) $deal_id;
        $stage_id = (int) $stage_id;
        if ( '' === $deal_id || $stage_id <= 0 ) return;
        wp_remote_request( untrailingslashit( $api_url ) . '/api/3/deals/' . rawurlencode( $deal_id ), array(
            'method'  => 'PUT',
            'headers' => array(
                'Api-Token'    => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'deal' => array( 'stage' => $stage_id ) ) ),
            'timeout' => 15,
        ) );
    }

    private function get_field( $source, array $keys ) {
        foreach ( $keys as $key ) {
            if ( is_array( $source ) && isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
                return $source[ $key ];
            }
            if ( is_object( $source ) && isset( $source->$key ) && '' !== $source->$key ) {
                return $source->$key;
            }
        }
        return '';
    }

    public function handle_save() {
        if ( ! isset( $_POST['scrm_save_ac'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scrm_ac_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_ac_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scrm_save_ac' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-crm-pro' ) );
        }

        update_option( self::OPT_API_URL, untrailingslashit( esc_url_raw( wp_unslash( $_POST['ac_api_url'] ?? '' ) ) ) );
        update_option( self::OPT_API_KEY, sanitize_text_field( wp_unslash( $_POST['ac_api_key'] ?? '' ) ) );
        update_option( self::OPT_TAG,     sanitize_text_field( wp_unslash( $_POST['ac_tag'] ?? 'midland-job-completed' ) ) );
        update_option( self::OPT_ENABLED, isset( $_POST['ac_enabled'] ) ? 1 : 0 );

        // Deal pipeline settings (AC owns the sales pipeline).
        update_option( self::OPT_DEAL_ENABLED,  isset( $_POST['ac_deal_enabled'] ) ? 1 : 0 );
        update_option( self::OPT_PIPELINE_ID,   absint( $_POST['ac_pipeline_id']   ?? 0 ) );
        update_option( self::OPT_DEAL_OWNER,    absint( $_POST['ac_deal_owner']    ?? 0 ) );
        update_option( self::OPT_DEAL_CURRENCY, sanitize_text_field( wp_unslash( $_POST['ac_deal_currency'] ?? 'usd' ) ) );
        update_option( self::OPT_STAGE_NEW,    absint( $_POST['ac_stage_new']    ?? 0 ) );
        update_option( self::OPT_STAGE_QUOTED, absint( $_POST['ac_stage_quoted'] ?? 0 ) );
        update_option( self::OPT_STAGE_BOOKED, absint( $_POST['ac_stage_booked'] ?? 0 ) );
        update_option( self::OPT_STAGE_WON,    absint( $_POST['ac_stage_won']    ?? 0 ) );
        update_option( self::OPT_STAGE_LOST,   absint( $_POST['ac_stage_lost']   ?? 0 ) );

        wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-activecampaign&saved=1' ) );
        exit;
    }

    public function handle_test() {
        if ( ! isset( $_POST['scrm_test_ac'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scrm_ac_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_ac_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scrm_save_ac' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-crm-pro' ) );
        }

        $api_url = (string) get_option( self::OPT_API_URL, '' );
        $api_key = (string) get_option( self::OPT_API_KEY, '' );

        if ( '' === $api_url || '' === $api_key ) {
            wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-activecampaign&test=missing' ) );
            exit;
        }

        $response = wp_remote_get( $api_url . '/api/3/users/me', array(
            'headers' => array( 'Api-Token' => $api_key, 'Accept' => 'application/json' ),
            'timeout' => 10,
        ) );

        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $key  = 200 === $code ? 'ok' : 'fail';
        wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-activecampaign&test=' . $key ) );
        exit;
    }

    public function render_page() {
        $api_url  = (string) get_option( self::OPT_API_URL, '' );
        $api_key  = (string) get_option( self::OPT_API_KEY, '' );
        $tag      = (string) get_option( self::OPT_TAG, 'midland-job-completed' );
        $enabled  = (int) get_option( self::OPT_ENABLED, 0 );
        $last     = get_option( self::OPT_LAST_PUSH, array() );
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved    = isset( $_GET['saved'] );
        $test     = isset( $_GET['test'] ) ? sanitize_key( $_GET['test'] ) : '';
        // phpcs:enable
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ActiveCampaign Bridge', 'smart-crm-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Pushes the contact + a "job complete" tag to ActiveCampaign whenever a lead is marked complete here. AC then runs its own flows.', 'smart-crm-pro' ); ?></p>

            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-crm-pro' ); ?></p></div><?php endif; ?>
            <?php if ( 'ok' === $test ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connected to ActiveCampaign.', 'smart-crm-pro' ); ?></p></div><?php endif; ?>
            <?php if ( 'fail' === $test ) : ?><div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'ActiveCampaign rejected the credentials. Check the API URL and key.', 'smart-crm-pro' ); ?></p></div><?php endif; ?>
            <?php if ( 'missing' === $test ) : ?><div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Save the API URL and key first, then test.', 'smart-crm-pro' ); ?></p></div><?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'scrm_save_ac', '_scrm_ac_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="ac_enabled"><?php esc_html_e( 'Enable Sync', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <label><input type="checkbox" id="ac_enabled" name="ac_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Push contacts to ActiveCampaign on job completion.', 'smart-crm-pro' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ac_api_url"><?php esc_html_e( 'API URL', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="url" id="ac_api_url" name="ac_api_url" class="regular-text" value="<?php echo esc_attr( $api_url ); ?>" placeholder="https://your-account.api-us1.com"></td>
                    </tr>
                    <tr>
                        <th><label for="ac_api_key"><?php esc_html_e( 'API Key', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="password" id="ac_api_key" name="ac_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="ac_tag"><?php esc_html_e( 'Trigger Tag', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="ac_tag" name="ac_tag" class="regular-text" value="<?php echo esc_attr( $tag ); ?>">
                            <p class="description"><?php esc_html_e( 'Applied to the contact when a job completes. Use this as the trigger in your AC automations.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:24px;"><?php esc_html_e( 'Sales pipeline (Deals)', 'smart-crm-pro' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'ActiveCampaign owns the sales pipeline. Each Smart Forms submission creates a Deal in AC and the stage advances automatically on lifecycle events: New → Quoted (after Push to SM8) → Booked → Won (after SM8 marks the job complete). Plug in your pipeline + stage IDs from your AC account.', 'smart-crm-pro' ); ?>
                </p>
                <?php
                $deal_enabled   = (int) get_option( self::OPT_DEAL_ENABLED, 0 );
                $pipeline_id    = (int) get_option( self::OPT_PIPELINE_ID, 0 );
                $deal_owner     = (int) get_option( self::OPT_DEAL_OWNER, 0 );
                $deal_currency  = (string) get_option( self::OPT_DEAL_CURRENCY, 'usd' );
                $stage_new      = (int) get_option( self::OPT_STAGE_NEW, 0 );
                $stage_quoted   = (int) get_option( self::OPT_STAGE_QUOTED, 0 );
                $stage_booked   = (int) get_option( self::OPT_STAGE_BOOKED, 0 );
                $stage_won      = (int) get_option( self::OPT_STAGE_WON, 0 );
                $stage_lost     = (int) get_option( self::OPT_STAGE_LOST, 0 );
                ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable deal pipeline', 'smart-crm-pro' ); ?></th>
                        <td>
                            <label><input type="checkbox" id="ac_deal_enabled" name="ac_deal_enabled" value="1" <?php checked( $deal_enabled, 1 ); ?>>
                                <?php esc_html_e( 'Create + progress AC Deals from Smart Forms submissions.', 'smart-crm-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ac_pipeline_id"><?php esc_html_e( 'Pipeline ID', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="number" id="ac_pipeline_id" name="ac_pipeline_id" class="small-text" value="<?php echo esc_attr( $pipeline_id ); ?>" min="1">
                            <p class="description"><?php esc_html_e( 'AC → Deals → Pipelines → click your pipeline → ID is in the URL (the number after /pipelines/).', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ac_deal_owner"><?php esc_html_e( 'Default deal owner (AC User ID)', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="number" id="ac_deal_owner" name="ac_deal_owner" class="small-text" value="<?php echo esc_attr( $deal_owner ); ?>" min="0">
                            <p class="description"><?php esc_html_e( 'AC → Settings → Users → click yourself → ID is in the URL. 0 = let AC pick the pipeline default.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ac_deal_currency"><?php esc_html_e( 'Currency', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="ac_deal_currency" name="ac_deal_currency" class="small-text" value="<?php echo esc_attr( $deal_currency ); ?>" maxlength="3" style="width:80px;">
                            <p class="description"><?php esc_html_e( 'Three-letter ISO code, lowercase (usd, cad, gbp, eur).', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Stage IDs', 'smart-crm-pro' ); ?></th>
                        <td>
                            <p class="description" style="margin-top:0;"><?php esc_html_e( 'Find stage IDs at AC → Deals → Pipelines → your pipeline → Manage Stages → hover the edit pencil; the URL shows ?stage=N.', 'smart-crm-pro' ); ?></p>
                            <table class="form-table" style="margin-top:6px;">
                                <tr><th style="padding-left:0;width:120px;">New lead</th>     <td><input type="number" name="ac_stage_new"    value="<?php echo esc_attr( $stage_new ); ?>"    class="small-text" min="0"></td></tr>
                                <tr><th style="padding-left:0;">Quote sent</th>              <td><input type="number" name="ac_stage_quoted" value="<?php echo esc_attr( $stage_quoted ); ?>" class="small-text" min="0"></td></tr>
                                <tr><th style="padding-left:0;">Booked</th>                  <td><input type="number" name="ac_stage_booked" value="<?php echo esc_attr( $stage_booked ); ?>" class="small-text" min="0"></td></tr>
                                <tr><th style="padding-left:0;">Won</th>                     <td><input type="number" name="ac_stage_won"    value="<?php echo esc_attr( $stage_won ); ?>"    class="small-text" min="0"></td></tr>
                                <tr><th style="padding-left:0;">Lost</th>                    <td><input type="number" name="ac_stage_lost"   value="<?php echo esc_attr( $stage_lost ); ?>"   class="small-text" min="0"></td></tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="scrm_save_ac" value="1" class="button button-primary"><?php esc_html_e( 'Save', 'smart-crm-pro' ); ?></button>
                    <button type="submit" name="scrm_test_ac" value="1" class="button" style="margin-left:8px;"><?php esc_html_e( 'Test Connection', 'smart-crm-pro' ); ?></button>
                </p>
            </form>

            <?php if ( ! empty( $last ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Last Push', 'smart-crm-pro' ); ?></h2>
                <p>
                    <strong><?php echo esc_html( $last['email'] ?? '—' ); ?></strong>
                    — <?php echo esc_html( $last['lifecycle'] ?? ( $last['tag'] ?? '' ) ); ?>
                    / <?php echo esc_html( $last['category'] ?? '' ); ?>
                    — <?php echo esc_html( ! empty( $last['ok'] ) ? __( 'OK', 'smart-crm-pro' ) : __( 'failed', 'smart-crm-pro' ) ); ?>
                    — <?php echo esc_html( ! empty( $last['at'] ) ? wp_date( 'Y-m-d H:i', (int) $last['at'] ) : '' ); ?>
                </p>
                <?php if ( ! empty( $last['tags'] ) ) : ?>
                    <p style="margin:0 0 12px;color:#555;">
                        <?php esc_html_e( 'Tags applied:', 'smart-crm-pro' ); ?>
                        <?php foreach ( (array) $last['tags'] as $t ) : ?>
                            <code style="margin-right:6px;"><?php echo esc_html( $t ); ?></code>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

SCRM_Pro_ActiveCampaign::get_instance();
