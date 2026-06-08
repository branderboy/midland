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

    const META_SURVEY_FIRED   = '_srp_survey_fired';
    const META_JOB_IN_FLIGHT  = '_srp_job_in_flight';
    const CRON_HOOK           = 'srp_crm_poll';
    const OPT_TRIGGER         = 'srp_crm_trigger_status';
    const OPT_AUTOFIRE        = 'srp_crm_autofire';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Action-based integration — other plugins fire these and we react.
        // handle_completed_lead runs at priority 20 — AFTER SCRM_Pro_Tags
        // (priority 15) has stored the completion tags — so normalize_lead()
        // reads the freshly-written tag set (incl. midland-job-completed-*)
        // rather than the stale pre-completion set. SCRM_Pro_Tags is hooked on
        // these same two completion actions; the status-change path (below)
        // funnels into handle_completed_lead too, so it shares the bump.
        add_action( 'sfco_lead_completed',       array( $this, 'handle_completed_lead' ), 20, 1 );
        add_action( 'sfco_lead_status_changed',  array( $this, 'handle_status_change' ), 20, 3 );
        add_action( 'scrm_pro_job_completed',    array( $this, 'handle_completed_lead' ), 20, 1 );

        // Job opened in ServiceM8 — mark the lead as "survey pending" so
        // the admin page can distinguish jobs awaiting completion from
        // jobs that never reached SM8. No email fires here; the NPS goes
        // out only on completion.
        add_action( 'scrm_pro_job_created',      array( $this, 'handle_job_created' ), 10, 1 );

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

        $email   = $data['email'];
        $lead_id = (int) ( $data['lead_id'] ?? 0 );

        // Dedupe. The lead_id guard alone misses completion events that carry no
        // lead id (ServiceM8 job completions, chat leads), so the same survey
        // re-fired on every event/poll — the "survey goes out too many times"
        // bug. An email-keyed marker (independent of lead_id) stops that: one
        // survey sequence per customer per cooldown window, however many times
        // completion fires.
        if ( $lead_id > 0 && $this->already_fired( $lead_id ) ) {
            return;
        }
        if ( $this->already_fired_email( $email ) ) {
            return;
        }

        do_action( 'srp_job_completed', $data );

        // Only mark surveyed when the email actually went out. If the send
        // failed, leave it unmarked so the hourly poll retries it instead of
        // silently dropping the customer's survey. (Defaults to "sent" when the
        // survey module isn't available, to avoid an endless retry loop.)
        $sent = ! class_exists( 'SRP_Survey' ) || SRP_Survey::was_sent( $email );
        if ( $sent ) {
            $this->mark_fired_email( $email );
            if ( $lead_id > 0 ) {
                $this->mark_fired( $lead_id );
            }
        }
    }

    /**
     * Record that a job has been opened in SM8 for this lead. No survey
     * email — that fires only on completion. Stored as a postmeta marker
     * (post_id=0) so the admin "CRM Linking" page can later show a count
     * of jobs in progress.
     */
    public function handle_job_created( $lead ) {
        $data    = $this->normalize_lead( $lead );
        $lead_id = (int) ( $data['lead_id'] ?? 0 );
        if ( $lead_id <= 0 ) {
            return;
        }
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT meta_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::META_JOB_IN_FLIGHT,
            $lead_id
        ) );
        if ( $existing ) {
            return;
        }
        $wpdb->insert( $wpdb->prefix . 'postmeta', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'post_id'    => 0,
            'meta_key'   => self::META_JOB_IN_FLIGHT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $lead_id,                 // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ) );
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
        $sfco_on    = defined( 'SFCO_VERSION' ); // Midland Smart Forms (merged Basic + Pro).
        $scrm_pro   = defined( 'SCRM_PRO_VERSION' );
        $has_table  = $this->leads_table_exists();

        $ready_leads    = array();
        $in_flight_leads = array();
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

            // Jobs that SM8 has opened (postmeta marker set) but the lead is
            // not yet in the trigger status AND no survey has fired yet.
            // These are "survey pending" — the customer's job is in flight.
            $in_flight_leads = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT l.* FROM {$wpdb->prefix}sfco_leads l
                 INNER JOIN {$wpdb->prefix}postmeta pm_in
                    ON pm_in.meta_key = %s AND pm_in.meta_value = l.id
                 LEFT JOIN {$wpdb->prefix}postmeta pm_done
                    ON pm_done.meta_key = %s AND pm_done.meta_value = l.id
                 WHERE pm_done.meta_id IS NULL
                   AND ( l.status IS NULL OR l.status <> %s )
                 ORDER BY l.id DESC
                 LIMIT 25",
                self::META_JOB_IN_FLIGHT,
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
                    <strong>Smart Forms for Midland</strong>
                    <span style="color:#666;">— <?php echo $sfco_on ? esc_html__( 'active (lead source)', 'smart-reviews-pro' ) : esc_html__( 'not installed', 'smart-reviews-pro' ); ?></span>
                </li>
                <li>
                    <?php echo $scrm_pro ? '<span style="color:#0a8754;">&#10003;</span>' : '<span style="color:#d63638;">&#10005;</span>'; ?>
                    <strong>Smart CRM for Midland</strong>
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
            <h2><?php printf( esc_html__( 'Jobs in Progress — Survey Pending (%d)', 'smart-reviews-pro' ), count( $in_flight_leads ) ); ?></h2>
            <p class="description"><?php esc_html_e( 'ServiceM8 has opened a job for these leads but the job is not yet complete. The NPS survey will fire automatically when SM8 marks the job complete.', 'smart-reviews-pro' ); ?></p>
            <?php if ( empty( $in_flight_leads ) ) : ?>
                <p><em><?php esc_html_e( 'No jobs in progress. Leads appear here once ServiceM8 fires a job-created event.', 'smart-reviews-pro' ); ?></em></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Customer', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'SM8 Job', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Current Status', 'smart-reviews-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $in_flight_leads as $lead ) : ?>
                            <tr>
                                <td><?php echo esc_html( $lead->customer_name ?? '' ); ?></td>
                                <td><?php echo esc_html( $lead->customer_email ?? '' ); ?></td>
                                <td><?php echo $lead->job_id ? '<code>' . esc_html( substr( (string) $lead->job_id, 0, 12 ) ) . '…</code>' : '<em>—</em>'; ?></td>
                                <td><code><?php echo esc_html( $lead->status ?? '' ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>
            <h2><?php printf( esc_html__( 'Leads Ready to Survey (status = %s)', 'smart-reviews-pro' ), '<code>' . esc_html( $trigger ) . '</code>' ); ?></h2>
            <?php if ( ! $has_table ) : ?>
                <p><em><?php esc_html_e( 'wp_sfco_leads table not found — install Smart Forms for Midland first.', 'smart-reviews-pro' ); ?></em></p>
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
        $lead_id = (int) $lead_id;
        // Authoritative, prune-proof dedupe: a dedicated non-autoloaded option.
        // post_id=0 postmeta (below) gets wiped by DB-optimization plugins,
        // which would let the hourly poll re-send a duplicate NPS email to the
        // customer — the embarrassing failure mode this guards against.
        if ( '' !== (string) get_option( self::META_SURVEY_FIRED . '_' . $lead_id, '' ) ) {
            return true;
        }
        // Fall back to the legacy postmeta marker for leads surveyed before
        // this change.
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT meta_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %d LIMIT 1",
            self::META_SURVEY_FIRED,
            $lead_id
        ) );
        return ! empty( $found );
    }

    /** Per-email dedupe key (prune-proof, non-autoloaded option). */
    private function email_marker_key( $email ) {
        return self::META_SURVEY_FIRED . '_email_' . md5( strtolower( sanitize_email( (string) $email ) ) );
    }

    /**
     * True if this email was surveyed within the cooldown. The window lets a
     * genuinely new job months later still get a survey, while suppressing the
     * rapid duplicate completion events (incl. re-testing the same lead).
     */
    private function already_fired_email( $email ) {
        $ts = (int) get_option( $this->email_marker_key( $email ), 0 );
        return $ts > 0 && ( time() - $ts ) < ( 30 * DAY_IN_SECONDS );
    }

    private function mark_fired_email( $email ) {
        update_option( $this->email_marker_key( $email ), time(), false );
    }

    private function mark_fired( $lead_id ) {
        $lead_id = (int) $lead_id;
        // Authoritative dedupe marker that DB-cleanup plugins can't prune.
        update_option( self::META_SURVEY_FIRED . '_' . $lead_id, time(), false );
        // Also keep the postmeta marker so the admin "CRM Linking" list and the
        // poll query (both LEFT JOIN on this meta) still exclude surveyed leads.
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'postmeta', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'post_id'    => 0,
            'meta_key'   => self::META_SURVEY_FIRED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $lead_id,                 // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
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

        $lead_id = (int) ( $get( 'id' ) ?: 0 );

        // Smart CRM owns the tags — read the lead's segment + tags from it so the
        // review is associated with the same data the CRM passes to ActiveCampaign.
        $tags    = ( $lead_id && class_exists( 'SCRM_Pro_Tags' ) ) ? SCRM_Pro_Tags::get_lead_tags( $lead_id ) : array();
        $segment = class_exists( 'SCRM_Pro_ActiveCampaign' ) ? SCRM_Pro_ActiveCampaign::get_instance()->lead_segment( $lead ) : '';

        return array(
            'name'    => sanitize_text_field( (string) $get( 'customer_name' ) ?: (string) $get( 'name' ) ),
            'email'   => sanitize_email( (string) $get( 'customer_email' ) ?: (string) $get( 'email' ) ),
            'phone'   => sanitize_text_field( (string) $get( 'customer_phone' ) ?: (string) $get( 'phone' ) ),
            'job_id'  => sanitize_text_field( (string) ( $get( 'job_id' ) ?: $get( 'id' ) ) ),
            'lead_id' => $lead_id,
            'segment' => sanitize_text_field( (string) $segment ),
            'tags'    => array_map( 'sanitize_text_field', (array) $tags ),
        );
    }
}

SRP_CRM_Integration::get_instance();
