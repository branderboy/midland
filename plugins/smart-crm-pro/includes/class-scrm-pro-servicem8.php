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
    const OPT_AUTO_PUSH_HOT    = 'scrm_pro_sm8_auto_push_hot';// auto-create job for Hot leads
    const SM8_API_BASE         = 'https://api.servicem8.com/api_1.0/';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_route' ) );
        add_action( 'admin_menu',    array( $this, 'add_menu' ), 21 );
        add_action( 'admin_init',    array( $this, 'handle_save' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'smart-crm-pro',
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

        if ( ! $is_completion ) {
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

        $old_status = (string) $lead->status;
        if ( 'completed' === strtolower( $old_status ) ) {
            return new WP_REST_Response( array( 'received' => true, 'action' => 'already_completed', 'lead_id' => (int) $lead->id ), 200 );
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'sfco_leads', // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            array( 'status' => 'completed' ),
            array( 'id' => (int) $lead->id ),
            array( '%s' ),
            array( '%d' )
        );

        // Refresh and broadcast.
        $lead->status = 'completed';
        if ( $job_id && empty( $lead->job_id ) ) {
            $wpdb->update( $wpdb->prefix . 'sfco_leads', array( 'job_id' => $job_id ), array( 'id' => (int) $lead->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $lead->job_id = $job_id;
        }

        do_action( 'sfco_lead_status_changed', $lead, $old_status, 'completed' );
        do_action( 'sfco_lead_completed',      $lead );
        do_action( 'scrm_pro_job_completed',   $lead );

        return new WP_REST_Response( array(
            'received' => true,
            'action'   => 'marked_completed',
            'lead_id'  => (int) $lead->id,
        ), 200 );
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
        if ( ! get_option( self::OPT_AUTO_PUSH_HOT, 0 ) ) return;
        if ( 'Hot' !== $priority ) return;
        if ( empty( $lead['id'] ) ) return;
        $result = self::push_lead_as_job( (int) $lead['id'] );
        if ( is_wp_error( $result ) ) {
            error_log( 'SCRM SM8 auto-push failed for lead ' . $lead['id'] . ': ' . $result->get_error_message() ); // phpcs:ignore
        }
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

        wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-servicem8&saved=1' ) );
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
            <p class="description"><?php esc_html_e( 'When a job is completed in ServiceM8, the matching lead is marked completed. That fires the NPS survey via Smart Reviews Pro and the ActiveCampaign push.', 'smart-crm-pro' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-crm-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'scrm_save_sm8', '_scrm_sm8_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Webhook URL', 'smart-crm-pro' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $webhook_url ); ?></code>
                            <p class="description"><?php esc_html_e( 'Configure this URL in your ServiceM8 webhook subscription.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sm8_secret"><?php esc_html_e( 'Signing Secret', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="password" id="sm8_secret" name="sm8_secret" class="regular-text" value="<?php echo esc_attr( $secret ); ?>">
                            <p class="description"><?php esc_html_e( 'Required. Webhook is HMAC-SHA256 verified against this secret. Without it the endpoint rejects every request.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sm8_completion_status"><?php esc_html_e( 'Completion Keyword', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="sm8_completion_status" name="sm8_completion_status" value="<?php echo esc_attr( $key ); ?>">
                            <p class="description"><?php esc_html_e( 'Status or event substring that means "this job is done" (default: completed).', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:24px;"><?php esc_html_e( 'Outbound (CRM → ServiceM8)', 'smart-crm-pro' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Lets the per-lead "Push to ServiceM8" button (and Hot-lead auto-push) create a Quote in ServiceM8 from a Smart Forms lead.', 'smart-crm-pro' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th><label for="sm8_api_key"><?php esc_html_e( 'ServiceM8 API Key', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="password" id="sm8_api_key" name="sm8_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'ServiceM8 → Settings → Developer Tools → Generate API key. Premium Plus plan supports full API + webhooks.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sm8_company_uuid"><?php esc_html_e( 'Default Company UUID', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="sm8_company_uuid" name="sm8_company_uuid" class="regular-text" value="<?php echo esc_attr( $company_uuid ); ?>" placeholder="00000000-0000-0000-0000-000000000000">
                            <p class="description"><?php esc_html_e( 'Optional. ServiceM8 → Clients → click the master "house account" client → copy the UUID from the URL. Leave blank to let ServiceM8 create a contact-only job.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Auto-push Hot leads', 'smart-crm-pro' ); ?></th>
                        <td>
                            <label><input type="checkbox" id="sm8_auto_push_hot" name="sm8_auto_push_hot" value="1" <?php checked( $auto_push, 1 ); ?>>
                                <?php esc_html_e( 'When Smart Forms scores a lead as Priority: Hot, automatically create a Quote in ServiceM8.', 'smart-crm-pro' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Off by default. Hot = emergency / ASAP / large commercial. Tune the scoring in SCRM_Pro_Smart_Forms_Bridge::score_priority().', 'smart-crm-pro' ); ?></p>
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
