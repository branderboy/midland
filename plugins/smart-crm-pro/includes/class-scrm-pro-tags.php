<?php
/**
 * Smart CRM — central tag manager.
 *
 * Smart CRM owns every lifecycle tag. Smart Forms (and Calendly / ServiceM8 via
 * Smart Forms) send data + lifecycle events into the CRM; this class computes
 * the canonical tags for each event, PERSISTS them on the lead so the CRM owns
 * them (independent of any external service), and fires a single distribution
 * event so consumers — ActiveCampaign (email flows) and Smart Reviews — receive
 * the same data + tags.
 *
 * Storage:
 *   scrm_pro_lead_tags_{id}  per-lead accumulated tag set.
 *   scrm_pro_tag_activity    capped recent-activity log for the admin display.
 *
 * Distribution:
 *   do_action( 'scrm_pro_tags_applied', $lead, $lifecycle, $tags )
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Tags {

    const OPT_PREFIX = 'scrm_pro_lead_tags_';
    const OPT_LOG    = 'scrm_pro_tag_activity';
    const LOG_CAP    = 50;

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Run a little after the integrations so segment/urgency context is set,
        // but the store is independent of whether AC is configured.
        add_action( 'sfco_lead_submitted',      array( $this, 'on_submitted' ), 15, 3 );
        add_action( 'sfco_lead_booked',         array( $this, 'on_booked' ), 15, 2 );
        add_action( 'sfco_lead_visit_completed', array( $this, 'on_visited' ), 15, 1 );
        add_action( 'sfco_lead_completed',      array( $this, 'on_completed' ), 15, 1 );
        add_action( 'scrm_pro_job_completed',   array( $this, 'on_completed' ), 15, 1 );
        add_action( 'sfco_lead_canceled',       array( $this, 'on_canceled' ), 15, 2 );
    }

    /* ---- lifecycle listeners ---- */

    public function on_submitted( $lead_id, $payload = null, $form = null ) {
        // sfco_lead_submitted fires with ( int $lead_id, array $payload, $form ) —
        // unlike booked/completed/canceled, which hand us the lead row directly.
        // Resolve the real lead row so record()/store()/compute() get the same
        // object shape (and a non-zero id) as every other lifecycle path.
        $lead = $this->resolve_lead( $lead_id, $payload );
        if ( ! $lead ) {
            return;
        }
        $this->record( $lead, 'new_lead' );
    }
    public function on_booked( $lead, $is_rebook = false ) {
        $this->record( $lead, 'booked' );
    }
    public function on_visited( $lead ) {
        // A visit isn't a lifecycle in tags_for(); tag it explicitly.
        $this->apply( $lead, 'visited', array( 'midland-visit-completed' ) );
    }
    public function on_completed( $lead ) {
        $this->record( $lead, 'completed' );
    }
    public function on_canceled( $lead, $reason = '' ) {
        $this->record( $lead, 'canceled' );
    }

    /* ---- core ---- */

    /** Compute the canonical tags for a lifecycle, then store + distribute. */
    private function record( $lead, $lifecycle ) {
        $this->apply( $lead, $lifecycle, $this->compute( $lead, $lifecycle ) );
    }

    /** Persist a tag set on the lead and fan it out to consumers. */
    private function apply( $lead, $lifecycle, $tags ) {
        $tags = array_values( array_unique( array_filter( (array) $tags ) ) );
        $this->store( $lead, $lifecycle, $tags );

        /**
         * Central tag distribution. ActiveCampaign + Smart Reviews (and any
         * future consumer) receive the same lead data and tags from here.
         *
         * @param object|array $lead
         * @param string       $lifecycle new_lead|booked|visited|completed|canceled
         * @param string[]     $tags
         */
        do_action( 'scrm_pro_tags_applied', $lead, $lifecycle, $tags );
    }

    /** Compute tags via the ActiveCampaign taxonomy (segment + urgency aware). */
    private function compute( $lead, $lifecycle ) {
        if ( ! class_exists( 'SCRM_Pro_ActiveCampaign' ) ) {
            return array();
        }
        $ac = SCRM_Pro_ActiveCampaign::get_instance();
        return (array) $ac->tags_for( $lifecycle, $ac->lead_segment( $lead ), $ac->is_emergency( $lead ) );
    }

    private function store( $lead, $lifecycle, $tags ) {
        $id = $this->lead_id( $lead );
        if ( $id ) {
            $existing = (array) get_option( self::OPT_PREFIX . $id, array() );
            $merged   = array_values( array_unique( array_merge( $existing, $tags ) ) );
            update_option( self::OPT_PREFIX . $id, $merged, false );
        }

        $log = (array) get_option( self::OPT_LOG, array() );
        array_unshift( $log, array(
            'id'        => $id,
            'name'      => (string) $this->field( $lead, 'customer_name' ),
            'email'     => (string) $this->field( $lead, 'customer_email' ),
            'lifecycle' => $lifecycle,
            'tags'      => $tags,
            'time'      => time(),
        ) );
        update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_CAP ), false );
    }

    /**
     * Turn the sfco_lead_submitted (int $lead_id, array $payload) args into a
     * real lead row object. Prefers the canonical wp_sfco_leads row so segment
     * / emergency detection sees every column; falls back to the submission
     * payload (which carries customer_name / customer_email and the journey
     * fields) stamped with the real id when the loader isn't available.
     */
    private function resolve_lead( $lead_id, $payload ) {
        $lead_id = (int) $lead_id;
        if ( $lead_id <= 0 ) {
            return null;
        }

        $row = class_exists( 'SFCO_Database' ) ? SFCO_Database::get_lead( $lead_id ) : null;

        if ( $row ) {
            // The canonical DB row has id/name/email/project_type/timeline, but the
            // journey fields that drive segment + emergency tagging (segment,
            // urgency, lead_intent, property_type, emergency_service) live only
            // inside extra_fields_json and are never decoded onto the row. Decode
            // them, and overlay the submission $payload, so lead_segment() and
            // is_emergency() can actually see those signals. Only fill missing or
            // empty properties — never clobber a real column.
            $overlay = array();
            if ( ! empty( $row->extra_fields_json ) ) {
                $extra = json_decode( (string) $row->extra_fields_json, true );
                if ( is_array( $extra ) ) {
                    $overlay = $extra;
                }
            }
            if ( is_array( $payload ) ) {
                $overlay = array_merge( $overlay, $payload );
            }
            foreach ( $overlay as $k => $v ) {
                if ( ! isset( $row->$k ) || '' === $row->$k || null === $row->$k ) {
                    $row->$k = $v;
                }
            }
            $row->id = $lead_id;
            return $row;
        }

        if ( is_array( $payload ) ) {
            $payload['id'] = $lead_id;
            return (object) $payload;
        }

        return null;
    }

    private function lead_id( $lead ) {
        if ( is_object( $lead ) ) {
            return (int) ( $lead->id ?? 0 );
        }
        if ( is_array( $lead ) ) {
            return (int) ( $lead['id'] ?? 0 );
        }
        return 0;
    }

    private function field( $lead, $key ) {
        if ( is_object( $lead ) ) {
            return $lead->$key ?? '';
        }
        if ( is_array( $lead ) ) {
            return $lead[ $key ] ?? '';
        }
        return '';
    }

    /* ---- read API (consumers / admin display) ---- */

    /** Accumulated tags Smart CRM has applied to a lead. */
    public static function get_lead_tags( $lead_id ) {
        return (array) get_option( self::OPT_PREFIX . (int) $lead_id, array() );
    }

    /** Most recent tag-activity entries for the admin display. */
    public static function recent( $limit = 25 ) {
        return array_slice( (array) get_option( self::OPT_LOG, array() ), 0, (int) $limit );
    }
}

SCRM_Pro_Tags::get_instance();
