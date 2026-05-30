<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServiceM8 → Smart CRM Pro bridge.
 *
 * Receives a ServiceM8 webhook (HMAC-signed) and, when the event maps to a job
 * completion, marks the matching wp_sfco_leads row as completed. Marking the
 * status fires sfco_lead_status_changed which Smart Reviews Pro listens for to
 * auto-send the NPS survey, and which the AC bridge listens for to sync the
 * contact + tag to ActiveCampaign so AC flows can run.
 *
 * Endpoint:  POST /wp-json/smart-crm-pro/v1/servicem8
 * Headers:   X-ServiceM8-Signature: t=<unix>,v1=<hmac-sha256>
 * Settings:  Smart CRM Pro > ServiceM8
 */
class SCRM_Pro_ServiceM8 {

    const OPT_SECRET           = 'scrm_pro_sm8_secret';
    const OPT_COMPLETION_KEY   = 'scrm_pro_sm8_completion_status';
    const OPT_LAST_HIT         = 'scrm_pro_sm8_last_hit';
    // Outbound API (CRM → ServiceM8) credentials + defaults.
    const OPT_API_KEY          = 'scrm_pro_sm8_api_key';      // ServiceM8 account API key
    const OPT_COMPANY_UUID     = 'scrm_pro_sm8_company_uuid'; // default ServiceM8 company UUID
    const OPT_AUTO_PUSH_HOT    = 'scrm_pro_sm8_auto_push_hot';// legacy: auto-create job for Hot leads only
    const OPT_AUTO_PUSH_MODE   = 'scrm_pro_sm8_auto_push_mode';// '' off | 'hot' | 'all' (default 'all')
    const OPT_LAST_POLL        = 'scrm_pro_sm8_last_poll';    // unix ts of last successful poll
    const CRON_POLL            = 'scrm_pro_sm8_poll_jobs';    // cron hook for status polling
    const SM8_API_BASE         = 'https://api.servicem8.com/api_1.0/';
    // Dedupe markers stored in postmeta with post_id=0 (mirrors the
    // pattern Smart Reviews uses to flag surveys already fired).
    const META_JOB_OPENED      = '_scrm_job_opened_fired';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_route' ) );
        add_action( 'admin_menu',    array( $this, 'add_menu' ), 42 );
        add_action( 'admin_init',    array( $this, 'handle_save' ) );

        // Growing-plan bridge: no webhooks, so Smart CRM polls ServiceM8
        // via the API key it already uses for outbound pushes. Each lead
        // already has a job_id stamped on it from push_lead_as_job(), so
        // we only need to check those specific jobs.
        add_filter( 'cron_schedules',          array( $this, 'add_cron_schedule' ) );
        add_action( self::CRON_POLL,           array( $this, 'poll_active_jobs' ) );
        if ( ! wp_next_scheduled( self::CRON_POLL ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'scrm_pro_ten_min', self::CRON_POLL );
        }
        add_action( 'admin_post_scrm_sm8_poll_now', array( $this, 'handle_manual_poll' ) );

        // Calendly booking → create the ServiceM8 job. Fired by the Calendly
        // webhook (SFCO_Pro_Calendly::mark_lead_booked) once a booking maps to
        // a lead. push_lead_as_job() is idempotent — it no-ops if the lead
        // already has a job_id, so a re-delivered webhook won't double-create.
        add_action( 'sfco_lead_booked', array( $this, 'on_lead_booked' ), 20, 1 );
    }

    /**
     * Create the ServiceM8 job for a freshly-booked lead. Runs after the AC
     * bridge (priority 10) so the booked tag/deal fire first. Silent no-op
     * when the API key isn't configured yet — the booking still tags in AC.
     *
     * @param object $lead wp_sfco_leads row (status already = booked).
     */
    public function on_lead_booked( $lead ) {
        $lead_id = (int) ( is_object( $lead ) ? ( $lead->id ?? 0 ) : ( is_array( $lead ) ? ( $lead['id'] ?? 0 ) : 0 ) );
        if ( $lead_id <= 0 ) {
            return;
        }
        if ( '' === (string) get_option( self::OPT_API_KEY, '' ) ) {
            return; // Token not added yet — booking is still tagged in AC.
        }
        $result = self::push_lead_as_job( $lead_id );
        if ( is_wp_error( $result ) ) {
            error_log( 'SCRM SM8 booking push failed for lead ' . $lead_id . ': ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['scrm_pro_ten_min'] ) ) {
            $schedules['scrm_pro_ten_min'] = array(
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 10 minutes (Smart CRM ServiceM8 poll)', 'smart-crm-pro' ),
            );
        }
        return $schedules;
    }

    public function add_menu() {
        add_submenu_page(
            null,
            esc_html__( 'ServiceM8 Bridge', 'smart-crm-pro' ),
            esc_html__( 'ServiceM8', 'smart-crm-pro' ),
            'manage_options',
            'scrm-pro-servicem8',
            array( $this, 'render_page' )
        );
    }

    public function register_route() {
        register_rest_route( 'smart-crm-pro/v1', '/servicem8', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => array( $this, 'verify_signature' ),
        ) );
    }

    /**
     * HMAC-SHA256 verification of ServiceM8 webhook.
     * Header X-ServiceM8-Signature: t=<unix>,v1=<hmac>
     * Signed value: "<unix>.<raw-body>"
     */
    public function verify_signature( $request ) {
        $secret = (string) get_option( self::OPT_SECRET, '' );
        if ( '' === $secret ) {
            return new WP_Error( 'scrm_sm8_no_secret', __( 'ServiceM8 secret not configured.', 'smart-crm-pro' ), array( 'status' => 401 ) );
        }

        $header = (string) $request->get_header( 'x_servicem8_signature' );
        if ( '' === $header ) {
            return new WP_Error( 'scrm_sm8_missing', __( 'Missing X-ServiceM8-Signature header.', 'smart-crm-pro' ), array( 'status' => 401 ) );
        }

        $parts = array();
        foreach ( explode( ',', $header ) as $piece ) {
            $kv = explode( '=', trim( $piece ), 2 );
            if ( 2 === count( $kv ) ) {
                $parts[ $kv[0] ] = $kv[1];
            }
        }
        $timestamp = isset( $parts['t'] ) ? (int) $parts['t'] : 0;
        $signature = $parts['v1'] ?? '';
        if ( ! $timestamp || ! $signature ) {
            return new WP_Error( 'scrm_sm8_malformed', __( 'Malformed ServiceM8 signature.', 'smart-crm-pro' ), array( 'status' => 401 ) );
        }
        if ( abs( time() - $timestamp ) > 300 ) {
            return new WP_Error( 'scrm_sm8_stale', __( 'ServiceM8 timestamp out of tolerance.', 'smart-crm-pro' ), array( 'status' => 401 ) );
        }

        $payload  = $request->get_body();
        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'scrm_sm8_invalid', __( 'ServiceM8 signature mismatch.', 'smart-crm-pro' ), array( 'status' => 401 ) );
        }

        return true;
    }

    /**
     * The actual webhook handler. Runs only after verify_signature passes.
     */
    public function handle_webhook( $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = array();
        }

        update_option( self::OPT_LAST_HIT, array(
            'at'    => time(),
            'event' => sanitize_text_field( (string) ( $body['eventName'] ?? $body['event'] ?? '' ) ),
            'job'   => sanitize_text_field( (string) ( $body['job_uuid'] ?? $body['jobId'] ?? '' ) ),
        ) );

        // We accept several shapes ServiceM8 sends in the wild.
        $status = strtolower( (string) ( $body['status'] ?? $body['jobStatus'] ?? $body['data']['status'] ?? '' ) );
        $event  = strtolower( (string) ( $body['eventName'] ?? $body['event'] ?? '' ) );
        $email  = sanitize_email( (string) ( $body['email'] ?? $body['customerEmail'] ?? $body['data']['customer']['email'] ?? '' ) );
        $phone  = sanitize_text_field( (string) ( $body['phone'] ?? $body['customerPhone'] ?? $body['data']['customer']['phone'] ?? '' ) );
        $name   = sanitize_text_field( (string) ( $body['name'] ?? $body['customerName'] ?? $body['data']['customer']['name'] ?? '' ) );
        $job_id = sanitize_text_field( (string) ( $body['job_uuid'] ?? $body['jobId'] ?? '' ) );

        $is_completion = $this->matches_completion( $status, $event );
        $is_creation   = ! $is_completion && $this->matches_creation( $status, $event );

        if ( ! $is_completion && ! $is_creation ) {
            return new WP_REST_Response( array( 'received' => true, 'action' => 'ignored' ), 200 );
        }

        $lead = $this->find_lead( $email, $phone );

        if ( ! $lead ) {
            return new WP_REST_Response( array(
                'received' => true,
                'action'   => 'no_match',
                'note'     => 'No wp_sfco_leads row matched email/phone',
            ), 200 );
        }

        if ( $is_creation ) {
            $result = $this->mark_lead_job_opened( $lead, $job_id );
            return new WP_REST_Response( array(
                'received' => true,
                'action'   => $result,
                'lead_id'  => (int) $lead->id,
            ), 200 );
        }

        $result = $this->mark_lead_completed( $lead, $job_id );
        if ( 'already_completed' === $result ) {
            return new WP_REST_Response( array( 'received' => true, 'action' => 'already_completed', 'lead_id' => (int) $lead->id ), 200 );
        }

        return new WP_REST_Response( array(
            'received' => true,
            'action'   => 'marked_completed',
            'lead_id'  => (int) $lead->id,
        ), 200 );
    }

    /**
     * Stamp a lead with the SM8 job UUID and fan out the job-created action.
     * Deduped via postmeta so re-delivered webhooks / repeated poller hits don't
     * fire the action twice. Smart Reviews + AC bridge both listen on
     * scrm_pro_job_created.
     *
     * @param object $lead   wp_sfco_leads row.
     * @param string $job_id ServiceM8 job UUID.
     * @return string 'marked_opened' | 'already_opened'
     */
    private function mark_lead_job_opened( $lead, $job_id = '' ) {
        $lead_id = (int) ( $lead->id ?? 0 );
        if ( $lead_id <= 0 ) {
            return 'already_opened';
        }
        if ( $this->job_opened_already_fired( $lead_id ) ) {
            return 'already_opened';
        }

        if ( $job_id && empty( $lead->job_id ) ) {
            global $wpdb;
            $wpdb->update( $wpdb->prefix . 'sfco_leads', array( 'job_id' => $job_id ), array( 'id' => $lead_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $lead->job_id = $job_id;
        }

        do_action( 'scrm_pro_job_created', $lead );
        $this->mark_job_opened_fired( $lead_id );
        return 'marked_opened';
    }

    private function job_opened_already_fired( $lead_id ) {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT meta_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::META_JOB_OPENED,
            (int) $lead_id
        ) );
        return ! empty( $found );
    }

    private function mark_job_opened_fired( $lead_id ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'postmeta', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'post_id'    => 0,
            'meta_key'   => self::META_JOB_OPENED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => (int) $lead_id,         // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ) );
    }

    /**
     * Flip a Smart Forms lead to status=completed and fan out the three
     * actions Smart Reviews + ActiveCampaign listen on. Shared by the
     * webhook handler (Premium tier) and the API poller (Growing tier).
     *
     * @param object $lead   wp_sfco_leads row.
     * @param string $job_id Optional SM8 job UUID to stamp on the row.
     * @return string 'marked_completed' | 'already_completed'
     */
    private function mark_lead_completed( $lead, $job_id = '' ) {
        $old_status = (string) $lead->status;
        if ( 'completed' === strtolower( $old_status ) ) {
            return 'already_completed';
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'sfco_leads', // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            array( 'status' => 'completed' ),
            array( 'id' => (int) $lead->id ),
            array( '%s' ),
            array( '%d' )
        );

        $lead->status = 'completed';
        if ( $job_id && empty( $lead->job_id ) ) {
            $wpdb->update( $wpdb->prefix . 'sfco_leads', array( 'job_id' => $job_id ), array( 'id' => (int) $lead->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $lead->job_id = $job_id;
        }

        do_action( 'sfco_lead_status_changed', $lead, $old_status, 'completed' );
        do_action( 'sfco_lead_completed',      $lead );
        do_action( 'scrm_pro_job_completed',   $lead );

        // Floor-Care-Plan upsell tag — commercial emergencies only. The plan
        // is a recurring-service contract for commercial property managers;
        // residential homeowners get the standard NPS review flow even when
        // their job was an emergency. When/if a commercial customer later
        // subscribes, SCRM_Pro_FloorCarePlan::maybe_generate_plan fires the
        // 'plan_active' tag and the operator's AC automation removes this
        // upsell tag.
        if ( class_exists( 'SCRM_Pro_ActiveCampaign' ) ) {
            $ac            = SCRM_Pro_ActiveCampaign::get_instance();
            $segment       = $ac->lead_segment( $lead );
            $is_emergency  = $ac->is_emergency( $lead );
            $has_plan      = ! empty( $lead->floor_care_plan_id );
            if ( 'commercial' === $segment && $is_emergency && ! $has_plan ) {
                $ac->sync_segment( $lead, 'emergency_no_plan' );
            }
        }

        return 'marked_completed';
    }

    /**
     * Does this webhook payload look like a job-creation / job-opened event?
     * Match either the raw status (e.g. "Work Order") or the event name
     * ("job.created", "job.started", etc.) against a fixed list of vocab
     * ServiceM8 uses for "this job just transitioned from quote to active."
     */
    private function matches_creation( $status, $event ) {
        $status = strtolower( (string) $status );
        $event  = strtolower( (string) $event );
        $aliases = array(
            'work order', 'work_order', 'workorder',
            'in progress', 'in_progress', 'inprogress',
            'started', 'job_started', 'jobstarted',
            'created', 'job_created', 'jobcreated',
            'accepted', 'job_accepted',
            'scheduled', 'job_scheduled',
        );
        foreach ( $aliases as $alias ) {
            if ( $status === $alias || $event === $alias ) {
                return true;
            }
            if ( '' !== $status && false !== strpos( $status, $alias ) ) {
                return true;
            }
            if ( '' !== $event && false !== strpos( $event, $alias ) ) {
                return true;
            }
        }
        return false;
    }

    private function matches_completion( $status, $event ) {
        $key = sanitize_key( (string) get_option( self::OPT_COMPLETION_KEY, 'completed' ) );
        if ( '' === $key ) {
            $key = 'completed';
        }
        if ( false !== strpos( $status, $key ) ) {
            return true;
        }
        if ( false !== strpos( $event, $key ) ) {
            return true;
        }
        // Built-in heuristics for common ServiceM8 vocab.
        $aliases = array( 'completed', 'complete', 'finished', 'closed', 'invoice', 'jobcompleted', 'job_completed' );
        foreach ( $aliases as $alias ) {
            if ( $status === $alias || $event === $alias ) {
                return true;
            }
        }
        return false;
    }

    private function find_lead( $email, $phone ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        // Confirm the table exists — Smart Forms is a hard dependency at plugin level
        // but if it's deactivated this guards against a fatal.
        $table_check = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( ! $table_check ) {
            return null;
        }

        if ( ! empty( $email ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE customer_email = %s ORDER BY id DESC LIMIT 1",
                $email
            ) );
            if ( $row ) {
                return $row;
            }
        }
        if ( ! empty( $phone ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table} WHERE customer_phone = %s ORDER BY id DESC LIMIT 1",
                $phone
            ) );
            if ( $row ) {
                return $row;
            }
        }
        return null;
    }

    // ─── Outbound: CRM → ServiceM8 (create job from a lead) ──────────────────

    /**
     * Push a Smart Forms lead into ServiceM8 as a new job. Returns the SM8
     * job UUID on success, WP_Error on failure. Stamps the returned job UUID
     * back onto the sfco_leads row so subsequent webhooks (job_started,
     * job_completed) match up.
     *
     * @param int $lead_id Smart Forms lead row ID.
     * @return string|WP_Error
     */
    public static function push_lead_as_job( $lead_id ) {
        $api_key      = (string) get_option( self::OPT_API_KEY, '' );
        $company_uuid = (string) get_option( self::OPT_COMPANY_UUID, '' );
        if ( '' === $api_key ) {
            return new WP_Error( 'scrm_sm8_no_api_key', 'ServiceM8 API key not configured.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        $lead  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $lead_id ) ); // phpcs:ignore
        if ( ! $lead ) {
            return new WP_Error( 'scrm_sm8_no_lead', 'Lead not found.' );
        }
        if ( ! empty( $lead->job_id ) ) {
            return $lead->job_id; // already pushed
        }

        // Build the SM8 Job payload. The required schema for ServiceM8's
        // /api_1.0/job.json endpoint: company_uuid + status. We also send
        // a description with the form input so the dispatcher has context.
        $desc_lines = array();
        foreach ( array(
            'project_type'   => 'Service',
            'square_footage' => 'Sq Ft',
            'timeline'       => 'Timeline',
            'priority'       => 'Priority',
            'area'           => 'Area',
            'additional_notes' => 'Notes',
        ) as $col => $label ) {
            if ( ! empty( $lead->$col ) ) {
                $desc_lines[] = $label . ': ' . $lead->$col;
            }
        }

        $job = array(
            'status'                 => 'Quote', // SM8 status — starts as a quote, becomes a Job once accepted
            'work_order_reference'   => 'midland-lead-' . (int) $lead_id,
            'job_description'        => implode( "\n", $desc_lines ),
            'job_address'            => $lead->zip_code ?? '',
            'work_done_description'  => '',
        );
        if ( '' !== $company_uuid ) {
            $job['company_uuid'] = $company_uuid;
        }

        $response = wp_remote_post( self::SM8_API_BASE . 'job.json', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':x' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body'    => wp_json_encode( $job ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'scrm_sm8_http', sprintf( 'ServiceM8 returned HTTP %d', $code ), array( 'body' => wp_remote_retrieve_body( $response ) ) );
        }

        // SM8 returns the UUID in a header (x-record-uuid) for POSTs.
        $headers   = wp_remote_retrieve_headers( $response );
        $job_uuid  = '';
        if ( method_exists( $headers, 'offsetGet' ) ) {
            $job_uuid = (string) $headers->offsetGet( 'x-record-uuid' );
        } elseif ( is_array( $headers ) ) {
            $job_uuid = $headers['x-record-uuid'] ?? $headers['X-Record-UUID'] ?? '';
        }

        if ( '' !== $job_uuid ) {
            $wpdb->update( $table, array( 'job_id' => $job_uuid ), array( 'id' => (int) $lead_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore
        }
        return $job_uuid;
    }

    /**
     * Hook target — fires when the bridge tags a lead as Hot priority. If
     * auto-push is enabled in settings, this creates a SM8 quote-job in
     * the background. Failure is logged silently; the operator can retry
     * by clicking the per-lead "Push to ServiceM8" button.
     */
    public static function maybe_auto_push( $lead, $priority, $area ) {
        if ( empty( $lead['id'] ) ) return;
        // Default mode = 'all' (auto-push every lead). Legacy installs that
        // set OPT_AUTO_PUSH_HOT=1 are migrated to mode='hot'. mode='' = off.
        $mode = (string) get_option( self::OPT_AUTO_PUSH_MODE, '' );
        if ( '' === $mode ) {
            $mode = get_option( self::OPT_AUTO_PUSH_HOT, 0 ) ? 'hot' : 'all';
        }
        if ( '' === $mode || 'off' === $mode ) return;
        if ( 'hot' === $mode && 'Hot' !== $priority ) return;
        $result = self::push_lead_as_job( (int) $lead['id'] );
        if ( is_wp_error( $result ) ) {
            error_log( 'SCRM SM8 auto-push failed for lead ' . $lead['id'] . ': ' . $result->get_error_message() ); // phpcs:ignore
        }
    }

    /**
     * Cron entry-point. For every lead with a ServiceM8 job_id whose lead
     * status is not yet 'completed', query SM8 for that specific job and,
     * if the SM8 status matches the completion keyword, fan the lead out
     * through the same actions the webhook handler uses (Smart Reviews
     * NPS + ActiveCampaign job-completed tag). Returns a small summary
     * array so the manual "Check now" button can show what happened.
     */
    public function poll_active_jobs() {
        $api_key = (string) get_option( self::OPT_API_KEY, '' );
        if ( '' === $api_key ) {
            return array( 'checked' => 0, 'completed' => 0, 'note' => 'no_api_key' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        $table_check = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( ! $table_check ) {
            return array( 'checked' => 0, 'completed' => 0, 'note' => 'no_table' );
        }

        // Only poll leads that were actually pushed to SM8 (job_id set) and
        // aren't already marked completed locally. Cap the batch so a long
        // backlog can't blow the cron timeout.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$table}
             WHERE job_id IS NOT NULL AND job_id <> ''
               AND ( status IS NULL OR LOWER(status) <> 'completed' )
             ORDER BY id DESC
             LIMIT 50"
        );

        $checked   = 0;
        $completed = 0;
        foreach ( (array) $rows as $row ) {
            $checked++;
            if ( $this->check_and_mark_completed( $row, $api_key ) ) {
                $completed++;
            }
        }

        update_option( self::OPT_LAST_POLL, array(
            'at'        => time(),
            'checked'   => $checked,
            'completed' => $completed,
        ) );

        return array( 'checked' => $checked, 'completed' => $completed );
    }

    /**
     * GET /job/{uuid}.json and decide if the lead should be marked done.
     * Also fires the job-created action if the SM8 status indicates the job
     * has transitioned from Quote to active (Work Order / scheduled), but the
     * local lead hasn't been flagged as opened yet — this is the polling
     * fallback for accounts that don't have a webhook configured.
     *
     * Returns true if the lead was just flipped to completed on this call.
     */
    private function check_and_mark_completed( $lead, $api_key ) {
        $job_id = (string) ( $lead->job_id ?? '' );
        if ( '' === $job_id ) {
            return false;
        }

        $response = wp_remote_get( self::SM8_API_BASE . 'job/' . rawurlencode( $job_id ) . '.json', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':x' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
                'Accept'        => 'application/json',
            ),
        ) );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return false;
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return false;
        }

        $status = strtolower( (string) ( $body['status'] ?? $body['job_status'] ?? '' ) );
        if ( '' === $status ) {
            return false;
        }

        if ( $this->matches_completion( $status, '' ) ) {
            $result = $this->mark_lead_completed( $lead, $job_id );
            return 'marked_completed' === $result;
        }

        // Not completed yet — but if the job has moved off "Quote" the
        // job-created fan-out should fire (once, deduped on lead_id).
        if ( $this->matches_creation( $status, '' ) ) {
            $this->mark_lead_job_opened( $lead, $job_id );
        }
        return false;
    }

    // ─── Outbound: CRM → ServiceM8 (push scheduled visit as JobActivity) ─────

    /**
     * Create a ServiceM8 JobActivity (scheduled visit) tied to the lead's
     * existing job. SM8 requires a job_uuid for any activity, so this is a
     * no-op if the lead hasn't been pushed to SM8 yet — call push_lead_as_job
     * first if you need both.
     *
     * @param int    $lead_id     wp_sfco_leads row ID.
     * @param string $start       Visit start, RFC3339 / ISO8601 / strtotime-able.
     * @param string $end         Optional visit end. Defaults to start + 2 hours.
     * @param string $description Optional activity description shown on the SM8 dispatch board.
     * @return string|WP_Error JobActivity UUID on success, WP_Error otherwise.
     */
    public static function push_visit_as_job_activity( $lead_id, $start, $end = '', $description = '' ) {
        $api_key = (string) get_option( self::OPT_API_KEY, '' );
        if ( '' === $api_key ) {
            return new WP_Error( 'scrm_sm8_no_api_key', 'ServiceM8 API key not configured.' );
        }

        global $wpdb;
        $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfco_leads WHERE id = %d", (int) $lead_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( ! $lead ) {
            return new WP_Error( 'scrm_sm8_no_lead', 'Lead not found.' );
        }

        $job_uuid = (string) ( $lead->job_id ?? '' );
        if ( '' === $job_uuid ) {
            return new WP_Error( 'scrm_sm8_no_job', 'Lead has no ServiceM8 job_id yet — push the lead to SM8 first.' );
        }

        $start_ts = is_numeric( $start ) ? (int) $start : strtotime( (string) $start );
        if ( ! $start_ts ) {
            return new WP_Error( 'scrm_sm8_bad_start', 'Visit start time is invalid.' );
        }
        $end_ts = $end ? ( is_numeric( $end ) ? (int) $end : strtotime( (string) $end ) ) : 0;
        if ( ! $end_ts || $end_ts <= $start_ts ) {
            $end_ts = $start_ts + 2 * HOUR_IN_SECONDS;
        }

        // SM8 stores activity timestamps in UTC, format 'Y-m-d H:i:s'.
        $payload = array(
            'job_uuid'               => $job_uuid,
            'start_date'             => gmdate( 'Y-m-d H:i:s', $start_ts ),
            'end_date'               => gmdate( 'Y-m-d H:i:s', $end_ts ),
            'activity_was_scheduled' => 1,
        );
        if ( '' !== $description ) {
            $payload['activity_description'] = $description;
        }

        $response = wp_remote_post( self::SM8_API_BASE . 'jobactivity.json', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':x' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'scrm_sm8_http', sprintf( 'ServiceM8 returned HTTP %d', $code ), array( 'body' => wp_remote_retrieve_body( $response ) ) );
        }

        $headers      = wp_remote_retrieve_headers( $response );
        $activity_id  = '';
        if ( method_exists( $headers, 'offsetGet' ) ) {
            $activity_id = (string) $headers->offsetGet( 'x-record-uuid' );
        } elseif ( is_array( $headers ) ) {
            $activity_id = $headers['x-record-uuid'] ?? $headers['X-Record-UUID'] ?? '';
        }
        return $activity_id;
    }

    /**
     * Admin button: "Check SM8 status now". Runs the poller on-demand so the
     * op doesn't have to wait for the next cron tick after marking a job
     * complete in ServiceM8.
     */
    public function handle_manual_poll() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No.' );
        }
        check_admin_referer( 'scrm_sm8_poll_now' );
        $summary = $this->poll_active_jobs();
        $back    = admin_url( 'admin.php?page=scrm-pro-servicem8&polled=1&checked=' . (int) ( $summary['checked'] ?? 0 ) . '&completed=' . (int) ( $summary['completed'] ?? 0 ) );
        wp_safe_redirect( $back );
        exit;
    }

    public function handle_save() {
        if ( ! isset( $_POST['scrm_save_sm8'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scrm_sm8_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_sm8_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scrm_save_sm8' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-crm-pro' ) );
        }

        update_option( self::OPT_SECRET, sanitize_text_field( wp_unslash( $_POST['sm8_secret'] ?? '' ) ) );
        update_option( self::OPT_COMPLETION_KEY, sanitize_key( wp_unslash( $_POST['sm8_completion_status'] ?? 'completed' ) ) );
        update_option( self::OPT_API_KEY,        sanitize_text_field( wp_unslash( $_POST['sm8_api_key'] ?? '' ) ) );
        update_option( self::OPT_COMPANY_UUID,   sanitize_text_field( wp_unslash( $_POST['sm8_company_uuid'] ?? '' ) ) );
        update_option( self::OPT_AUTO_PUSH_HOT,  isset( $_POST['sm8_auto_push_hot'] ) ? 1 : 0 );
        $mode = isset( $_POST['sm8_auto_push_mode'] ) ? sanitize_key( wp_unslash( $_POST['sm8_auto_push_mode'] ) ) : 'all';
        if ( ! in_array( $mode, array( 'off', 'hot', 'all' ), true ) ) $mode = 'all';
        update_option( self::OPT_AUTO_PUSH_MODE, $mode );

        wp_safe_redirect( admin_url( 'admin.php?page=smart-crm&tab=servicem8&saved=1' ) );
        exit;
    }

    public function render_page() {
        $secret       = (string) get_option( self::OPT_SECRET, '' );
        $key          = (string) get_option( self::OPT_COMPLETION_KEY, 'completed' );
        $api_key      = (string) get_option( self::OPT_API_KEY, '' );
        $company_uuid = (string) get_option( self::OPT_COMPANY_UUID, '' );
        $auto_push    = (int)    get_option( self::OPT_AUTO_PUSH_HOT, 0 );
        $webhook_url  = rest_url( 'smart-crm-pro/v1/servicem8' );
        $last         = get_option( self::OPT_LAST_HIT, array() );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved = isset( $_GET['saved'] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ServiceM8 Bridge', 'smart-crm-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'On the ServiceM8 Growing plan, the only credential exposed in the dashboard is the API key — Developer Tools and webhook subscriptions are gated to higher tiers. Configure the API key below to enable outbound push (lead → ServiceM8 Quote). The webhook fields further down only apply if you upgrade to a plan that exposes them; leave them blank on Growing.', 'smart-crm-pro' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-crm-pro' ); ?></p></div>
            <?php endif; ?>

            <?php
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['polled'] ) ) {
                $checked   = isset( $_GET['checked'] )   ? (int) $_GET['checked']   : 0;
                $completed = isset( $_GET['completed'] ) ? (int) $_GET['completed'] : 0;
                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sprintf(
                    /* translators: 1: leads checked, 2: leads newly completed */
                    __( 'ServiceM8 poll ran: checked %1$d open job(s), %2$d newly marked completed (Smart Reviews + ActiveCampaign fired for those).', 'smart-crm-pro' ),
                    $checked,
                    $completed
                ) ) . '</p></div>';
            }
            // phpcs:enable
            ?>

            <form method="post">
                <?php wp_nonce_field( 'scrm_save_sm8', '_scrm_sm8_nonce' ); ?>
                <h2 style="margin-top:0;"><?php esc_html_e( 'Outbound (CRM → ServiceM8) — works on Growing plan', 'smart-crm-pro' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Lets the per-lead "Push to ServiceM8" button (and the auto-push) create a Quote in ServiceM8 from a Smart Forms lead. Only the API key is required.', 'smart-crm-pro' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th><label for="sm8_api_key"><?php esc_html_e( 'ServiceM8 API Key', 'smart-crm-pro' ); ?> <span style="color:#b32d2e;">*</span></label></th>
                        <td>
                            <input type="password" id="sm8_api_key" name="sm8_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'ServiceM8 → Settings → API Key (Growing plan exposes this directly — no Developer Tools menu required). Paste the key here. This is the only field needed to push leads into ServiceM8.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sm8_company_uuid"><?php esc_html_e( 'Default Company UUID', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="sm8_company_uuid" name="sm8_company_uuid" class="regular-text" value="<?php echo esc_attr( $company_uuid ); ?>" placeholder="00000000-0000-0000-0000-000000000000">
                            <p class="description"><?php esc_html_e( 'Optional. ServiceM8 → Clients → click the master "house account" client → copy the UUID from the URL. Leave blank and ServiceM8 creates a contact-only job from the lead\'s name/email/phone.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Completion polling', 'smart-crm-pro' ); ?></th>
                        <td>
                            <?php
                            $last_poll = get_option( self::OPT_LAST_POLL, array() );
                            $next_ts   = wp_next_scheduled( self::CRON_POLL );
                            $poll_url  = wp_nonce_url( add_query_arg( 'action', 'scrm_sm8_poll_now', admin_url( 'admin-post.php' ) ), 'scrm_sm8_poll_now' );
                            ?>
                            <p style="margin-top:0;">
                                <a class="button button-secondary" href="<?php echo esc_url( $poll_url ); ?>">⚡ <?php esc_html_e( 'Check SM8 status now', 'smart-crm-pro' ); ?></a>
                            </p>
                            <p class="description">
                                <?php esc_html_e( 'Smart CRM polls ServiceM8 every 10 minutes for every lead it pushed (matched by job UUID). When a job\'s status matches the completion keyword above, the lead flips to "completed" — which fires the NPS survey via Smart Reviews and applies the job-completed tag in ActiveCampaign. This is how the chain runs on the Growing plan (no webhook required).', 'smart-crm-pro' ); ?>
                            </p>
                            <?php if ( ! empty( $last_poll['at'] ) ) : ?>
                                <p class="description"><strong><?php esc_html_e( 'Last poll:', 'smart-crm-pro' ); ?></strong>
                                    <?php
                                    echo esc_html( wp_date( 'Y-m-d H:i', (int) $last_poll['at'] ) );
                                    echo ' — ';
                                    echo esc_html( sprintf(
                                        /* translators: 1: jobs checked, 2: marked completed */
                                        __( 'checked %1$d, marked %2$d completed', 'smart-crm-pro' ),
                                        (int) ( $last_poll['checked']   ?? 0 ),
                                        (int) ( $last_poll['completed'] ?? 0 )
                                    ) );
                                    ?>
                                </p>
                            <?php endif; ?>
                            <?php if ( $next_ts ) : ?>
                                <p class="description"><em><?php
                                    echo esc_html( sprintf(
                                        /* translators: %s: human-readable time-until */
                                        __( 'Next automatic poll in %s.', 'smart-crm-pro' ),
                                        human_time_diff( time(), (int) $next_ts )
                                    ) );
                                ?></em></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sm8_auto_push_mode"><?php esc_html_e( 'Auto-push leads', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <?php
                            $current_mode = (string) get_option( self::OPT_AUTO_PUSH_MODE, '' );
                            if ( '' === $current_mode ) {
                                $current_mode = get_option( self::OPT_AUTO_PUSH_HOT, 0 ) ? 'hot' : 'all';
                            }
                            ?>
                            <select id="sm8_auto_push_mode" name="sm8_auto_push_mode">
                                <option value="all" <?php selected( $current_mode, 'all' ); ?>><?php esc_html_e( 'Every lead — hands-off (recommended)', 'smart-crm-pro' ); ?></option>
                                <option value="hot" <?php selected( $current_mode, 'hot' ); ?>><?php esc_html_e( 'Hot priority only', 'smart-crm-pro' ); ?></option>
                                <option value="off" <?php selected( $current_mode, 'off' ); ?>><?php esc_html_e( 'Off — manual only (use the per-lead button)', 'smart-crm-pro' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Default: every lead is auto-pushed to ServiceM8 as a Quote so you don\'t have to babysit the dispatcher. Switch to Hot-only if you want to keep low-intent leads out of SM8.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:24px;"><?php esc_html_e( 'Inbound (ServiceM8 → CRM) — requires a plan that exposes webhooks', 'smart-crm-pro' ); ?></h2>
                <p class="description"><?php esc_html_e( 'These fields are only useful on a ServiceM8 plan that exposes Developer Tools / Webhooks (Premium tier and above). On the Growing plan ServiceM8 does not expose a webhook subscription UI, so leave these blank — the outbound push above is the only half that runs. If/when you upgrade, fill in the secret and point a ServiceM8 webhook at the URL below to auto-mark leads completed (which fires the NPS survey and AC push).', 'smart-crm-pro' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Webhook URL', 'smart-crm-pro' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $webhook_url ); ?></code>
                            <p class="description"><?php esc_html_e( 'Configure this URL in your ServiceM8 webhook subscription (requires Premium plan or higher).', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sm8_secret"><?php esc_html_e( 'Signing Secret', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="password" id="sm8_secret" name="sm8_secret" class="regular-text" value="<?php echo esc_attr( $secret ); ?>">
                            <p class="description"><?php esc_html_e( 'Optional on Growing plan (leave blank). On webhook-capable plans this is required — the endpoint rejects every unsigned request.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sm8_completion_status"><?php esc_html_e( 'Completion Keyword', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="sm8_completion_status" name="sm8_completion_status" value="<?php echo esc_attr( $key ); ?>">
                            <p class="description"><?php esc_html_e( 'Status or event substring that means "this job is done" (default: completed). Only used when a webhook is actually wired up.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" name="scrm_save_sm8" value="1" class="button button-primary"><?php esc_html_e( 'Save', 'smart-crm-pro' ); ?></button></p>
            </form>

            <?php if ( ! empty( $last ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Last Webhook Hit', 'smart-crm-pro' ); ?></h2>
                <p>
                    <strong><?php echo esc_html( $last['event'] ?? '—' ); ?></strong>
                    <?php if ( ! empty( $last['job'] ) ) : ?>
                        — job <code><?php echo esc_html( $last['job'] ); ?></code>
                    <?php endif; ?>
                    — <?php echo esc_html( ! empty( $last['at'] ) ? wp_date( 'Y-m-d H:i', (int) $last['at'] ) : '' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}

SCRM_Pro_ServiceM8::get_instance();
