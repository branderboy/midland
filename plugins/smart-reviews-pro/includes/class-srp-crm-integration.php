<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CRM Integration — auto-fires the NPS survey when a project / lead is marked complete.
 *
 * Listens for:
 *   sfco_lead_completed( $lead )         — Smart Forms Pro
 *   sfco_lead_status_changed( $lead, $old, $new ) — Smart Forms Pro
 *   scrm_pro_job_completed( $lead )      — Smart CRM Pro
 *
 * Plus a fallback hourly cron that scans wp_sfco_leads for rows whose status matches
 * the configured "trigger status" and have not yet had a survey sent. Belt-and-suspenders
 * so we still catch completions even if nobody fires the action.
 *
 * Settings: Midland Reviews > CRM Linking
 */
class SRP_CRM_Integration {

    const META_SURVEY_FIRED = '_srp_survey_fired';
    const CRON_HOOK         = 'srp_crm_poll';
    const OPT_TRIGGER       = 'srp_crm_trigger_status';
    const OPT_AUTOFIRE      = 'srp_crm_autofire';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Action-based integration — other plugins fire these and we react.
        add_action( 'sfco_lead_completed',       array( $this, 'handle_completed_lead' ), 10, 1 );
        add_action( 'sfco_lead_status_changed',  array( $this, 'handle_status_change' ), 10, 3 );
        add_action( 'scrm_pro_job_completed',    array( $this, 'handle_completed_lead' ), 10, 1 );

        // Polling fallback.
        add_action( self::CRON_HOOK, array( $this, 'poll_completed_leads' ) );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
        }

        // Admin settings page.
        add_action( 'admin_menu', array( $this, 'add_menu' ), 12 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_init', array( $this, 'handle_manual_send' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'smart-reviews-pro',
            esc_html__( 'CRM Linking', 'smart-reviews-pro' ),
            esc_html__( 'CRM Linking', 'smart-reviews-pro' ),
            'manage_options',
            'srp-crm',
            array( $this, 'render_page' )
        );
    }

    /**
     * Direct action handler — accepts a lead row (object or array) and fires the survey.
     */
    public function handle_completed_lead( $lead ) {
        $data = $this->normalize_lead( $lead );
        if ( empty( $data['email'] ) ) {
            return;
        }

        // Prevent double-fire if both an action AND the cron poll catch the same lead.
        $lead_id = (int) ( $data['lead_id'] ?? 0 );
        if ( $lead_id > 0 && $this->already_fired( $lead_id ) ) {
            return;
        }

        do_action( 'srp_job_completed', $data );
        if ( $lead_id > 0 ) {
            $this->mark_fired( $lead_id );
        }
    }

    /**
     * Hooked when smart-forms-pro changes a lead status. Only fires if the new status
     * matches the configured trigger.
     */
    public function handle_status_change( $lead, $old_status, $new_status ) {
        $trigger = $this->get_trigger_status();
        if ( $trigger !== sanitize_key( (string) $new_status ) ) {
            return;
        }
        $this->handle_completed_lead( $lead );
    }

    /**
     * Hourly poll of wp_sfco_leads for leads matching trigger status that haven't had a survey.
     */
    public function poll_completed_leads() {
        if ( ! get_option( self::OPT_AUTOFIRE, 0 ) ) {
            return;
        }
        if ( ! $this->leads_table_exists() ) {
            return;
        }

        global $wpdb;
        $trigger = $this->get_trigger_status();

        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT l.* FROM {$wpdb->prefix}sfco_leads l
             LEFT JOIN {$wpdb->prefix}postmeta pm
                ON pm.meta_key = %s AND pm.meta_value = l.id
             WHERE l.status = %s AND pm.meta_id IS NULL
             ORDER BY l.id DESC
             LIMIT 50",
            self::META_SURVEY_FIRED,
            $trigger
        ) );

        foreach ( (array) $rows as $row ) {
            $this->handle_completed_lead( $row );
        }
    }

    public function handle_manual_send() {
        if ( ! isset( $_GET['srp_crm_send'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'srp_crm_send' ) ) {
            return;
        }

        $lead_id = absint( $_GET['srp_crm_send'] );
        if ( ! $lead_id || ! $this->leads_table_exists() ) {
            wp_safe_redirect( admin_url( 'admin.php?page=srp-crm&error=missing' ) );
            exit;
        }

        global $wpdb;
        $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfco_leads WHERE id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( ! $lead ) {
            wp_safe_redirect( admin_url( 'admin.php?page=srp-crm&error=missing' ) );
            exit;
        }

        $this->handle_completed_lead( $lead );
        wp_safe_redirect( admin_url( 'admin.php?page=srp-crm&sent=' . $lead_id ) );
        exit;
    }

    public function handle_save() {
        if ( ! isset( $_POST['srp_save_crm'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_srp_crm_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_srp_crm_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'srp_save_crm' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-reviews-pro' ) );
        }

        $trigger = sanitize_key( wp_unslash( $_POST['trigger_status'] ?? 'completed' ) );
        update_option( self::OPT_TRIGGER, $trigger ?: 'completed' );
        update_option( self::OPT_AUTOFIRE, isset( $_POST['autofire'] ) ? 1 : 0 );

        wp_safe_redirect( admin_url( 'admin.php?page=srp-crm&saved=1' ) );
        exit;
    }

    public function render_page() {
        $trigger    = $this->get_trigger_status();
        $autofire   = (int) get_option( self::OPT_AUTOFIRE, 0 );
        $sfco_on    = defined( 'SFCO_VERSION' );
        $sfco_pro   = defined( 'SFCO_PRO_VERSION' );
        $scrm_pro   = defined( 'SCRM_PRO_VERSION' );
        $has_table  = $this->leads_table_exists();

        $ready_leads = array();
        if ( $has_table ) {
            global $wpdb;
            $ready_leads = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT l.* FROM {$wpdb->prefix}sfco_leads l
                 LEFT JOIN {$wpdb->prefix}postmeta pm
                    ON pm.meta_key = %s AND pm.meta_value = l.id
                 WHERE l.status = %s AND pm.meta_id IS NULL
                 ORDER BY l.id DESC
                 LIMIT 25",
                self::META_SURVEY_FIRED,
                $trigger
            ) );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved = isset( $_GET['saved'] );
        $sent  = isset( $_GET['sent'] ) ? absint( $_GET['sent'] ) : 0;
        $err   = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
        // phpcs:enable
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'CRM Linking', 'smart-reviews-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Automatically fire the NPS survey when a project is marked complete in your CRM.', 'smart-reviews-pro' ); ?></p>

            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-reviews-pro' ); ?></p></div><?php endif; ?>
            <?php if ( $sent ) : ?><div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( 'Survey fired for lead #%d.', 'smart-reviews-pro' ), $sent ); ?></p></div><?php endif; ?>
            <?php if ( 'missing' === $err ) : ?><div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Lead not found.', 'smart-reviews-pro' ); ?></p></div><?php endif; ?>

            <h2><?php esc_html_e( 'Detected CRM Plugins', 'smart-reviews-pro' ); ?></h2>
            <ul>
                <li>
                    <?php echo $sfco_on ? '<span style="color:#0a8754;">&#10003;</span>' : '<span style="color:#d63638;">&#10005;</span>'; ?>
                    <strong>Smart Forms for Contractors</strong>
                    <span style="color:#666;">— <?php echo $sfco_on ? esc_html__( 'active (lead source)', 'smart-reviews-pro' ) : esc_html__( 'not installed', 'smart-reviews-pro' ); ?></span>
                </li>
                <li>
                    <?php echo $sfco_pro ? '<span style="color:#0a8754;">&#10003;</span>' : '<span style="color:#d63638;">&#10005;</span>'; ?>
                    <strong>Smart Forms Pro</strong>
                    <span style="color:#666;">— <?php echo $sfco_pro ? esc_html__( 'active', 'smart-reviews-pro' ) : esc_html__( 'not installed', 'smart-reviews-pro' ); ?></span>
                </li>
                <li>
                    <?php echo $scrm_pro ? '<span style="color:#0a8754;">&#10003;</span>' : '<span style="color:#d63638;">&#10005;</span>'; ?>
                    <strong>Smart CRM Pro</strong>
                    <span style="color:#666;">— <?php echo $scrm_pro ? esc_html__( 'active', 'smart-reviews-pro' ) : esc_html__( 'not installed', 'smart-reviews-pro' ); ?></span>
                </li>
            </ul>

            <form method="post">
                <?php wp_nonce_field( 'srp_save_crm', '_srp_crm_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="trigger_status"><?php esc_html_e( 'Trigger Lead Status', 'smart-reviews-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="trigger_status" name="trigger_status" class="regular-text" value="<?php echo esc_attr( $trigger ); ?>">
                            <p class="description"><?php esc_html_e( 'When a wp_sfco_leads row reaches this status (e.g. "completed", "job_done"), the survey fires automatically.', 'smart-reviews-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Auto-Fire', 'smart-reviews-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="autofire" value="1" <?php checked( $autofire ); ?>>
                                <?php esc_html_e( 'Run hourly cron that finds matching leads and fires the survey automatically.', 'smart-reviews-pro' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Off by default — turn this on once your CRM is using the trigger status above.', 'smart-reviews-pro' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="srp_save_crm" value="1" class="button button-primary"><?php esc_html_e( 'Save', 'smart-reviews-pro' ); ?></button></p>
            </form>

            <hr>
            <h2><?php printf( esc_html__( 'Leads Ready to Survey (status = %s)', 'smart-reviews-pro' ), '<code>' . esc_html( $trigger ) . '</code>' ); ?></h2>
            <?php if ( ! $has_table ) : ?>
                <p><em><?php esc_html_e( 'wp_sfco_leads table not found — install Smart Forms for Contractors first.', 'smart-reviews-pro' ); ?></em></p>
            <?php elseif ( empty( $ready_leads ) ) : ?>
                <p><em><?php esc_html_e( 'No leads waiting. They will appear here when their CRM status matches the trigger.', 'smart-reviews-pro' ); ?></em></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Customer', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'smart-reviews-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $ready_leads as $lead ) :
                            $send_url = wp_nonce_url(
                                admin_url( 'admin.php?page=srp-crm&srp_crm_send=' . (int) $lead->id ),
                                'srp_crm_send'
                            );
                        ?>
                            <tr>
                                <td><?php echo esc_html( $lead->customer_name ?? '' ); ?></td>
                                <td><?php echo esc_html( $lead->customer_email ?? '' ); ?></td>
                                <td><code><?php echo esc_html( $lead->status ?? '' ); ?></code></td>
                                <td><a class="button button-small button-primary" href="<?php echo esc_url( $send_url ); ?>"><?php esc_html_e( 'Send Survey', 'smart-reviews-pro' ); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>
            <h2><?php esc_html_e( 'Hooks for Custom Code', 'smart-reviews-pro' ); ?></h2>
            <p><?php esc_html_e( 'Fire any of these from custom code or another plugin to trigger the survey:', 'smart-reviews-pro' ); ?></p>
            <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;font-size:13px;line-height:1.5;">do_action( 'sfco_lead_completed', $lead );
do_action( 'sfco_lead_status_changed', $lead, $old_status, 'completed' );
do_action( 'scrm_pro_job_completed', $lead );

// Or fire the underlying action directly:
do_action( 'srp_job_completed', array(
    'name'   =&gt; $customer_name,
    'email'  =&gt; $customer_email,
    'phone'  =&gt; $customer_phone,
    'job_id' =&gt; $job_id,
) );</pre>
        </div>
        <?php
    }

    private function get_trigger_status() {
        return sanitize_key( (string) get_option( self::OPT_TRIGGER, 'completed' ) ) ?: 'completed';
    }

    private function leads_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    private function already_fired( $lead_id ) {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT meta_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::META_SURVEY_FIRED,
            (int) $lead_id
        ) );
        return ! empty( $found );
    }

    private function mark_fired( $lead_id ) {
        global $wpdb;
        // Stored in postmeta with post_id=0 — gives us a flat dedupe table without an extra schema.
        $wpdb->insert( $wpdb->prefix . 'postmeta', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'post_id'    => 0,
            'meta_key'   => self::META_SURVEY_FIRED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => (int) $lead_id,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ) );
    }

    /**
     * Coerce a lead row (array or object, from any source) into the payload
     * srp_job_completed expects.
     */
    private function normalize_lead( $lead ) {
        $get = function( $key ) use ( $lead ) {
            if ( is_array( $lead ) ) {
                return $lead[ $key ] ?? '';
            }
            if ( is_object( $lead ) ) {
                return $lead->$key ?? '';
            }
            return '';
        };

        return array(
            'name'    => sanitize_text_field( (string) $get( 'customer_name' ) ?: (string) $get( 'name' ) ),
            'email'   => sanitize_email( (string) $get( 'customer_email' ) ?: (string) $get( 'email' ) ),
            'phone'   => sanitize_text_field( (string) $get( 'customer_phone' ) ?: (string) $get( 'phone' ) ),
            'job_id'  => sanitize_text_field( (string) ( $get( 'job_id' ) ?: $get( 'id' ) ) ),
            'lead_id' => (int) ( $get( 'id' ) ?: 0 ),
        );
    }
}

SRP_CRM_Integration::get_instance();
