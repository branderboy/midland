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

        wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-servicem8&saved=1' ) );
        exit;
    }

    public function render_page() {
        $secret      = (string) get_option( self::OPT_SECRET, '' );
        $key         = (string) get_option( self::OPT_COMPLETION_KEY, 'completed' );
        $webhook_url = rest_url( 'smart-crm-pro/v1/servicem8' );
        $last        = get_option( self::OPT_LAST_HIT, array() );
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
