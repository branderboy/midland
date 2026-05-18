<?php
/**
 * Settings hub.
 *
 * Before this class, every Pro integration (Resend, CRM, Google Calendar,
 * Calendly, Automations, Branding, Team, Analytics) registered its own
 * top-level submenu under Smart Forms. The operator saw eight items in
 * the sidebar with no shared landing page, which read as "all over the
 * place" and made it unclear which integrations were active.
 *
 * This class adds a single "Settings" entry under Smart Forms that
 * lists every integration as a card with name, description, the
 * connection status (configured / not configured), and a Configure
 * button that deep-links to the integration's existing settings page.
 * It then hides the individual Pro submenus from the sidebar so the
 * sidebar collapses from nine entries down to five.
 *
 * The individual pages still exist at their original slugs — the
 * Configure buttons link to them, OAuth callbacks still work, and
 * direct URLs continue to resolve.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Settings {

    const PAGE_SLUG = 'smart-forms-settings';

    /**
     * The eight integration cards rendered on the Settings hub. Keyed by
     * page slug so hide_individual_submenus() can iterate the same list
     * to strip them from the sidebar.
     *
     * @return array<string, array{label:string, description:string, status_option:string, status_check:?callable}>
     */
    private function integrations(): array {
        return array(
            'sfco-notifications' => array(
                'label'         => __( 'Form Notifications', 'smart-forms-for-midland' ),
                'description'   => __( 'The two automations every form needs: auto-reply to the submitter confirming the message went through, and an admin notification to your team. Lightweight by design. Heavier flows (segmenting, tagging, sequencing) belong in Smart CRM.', 'smart-forms-for-midland' ),
                'status_check'  => function () {
                    $s = get_option( 'sfco_pro_notifications', array() );
                    return is_array( $s ) && ( ! empty( $s['admin_enabled'] ) || ! empty( $s['autoreply_enabled'] ) );
                },
            ),
            'sfco-resend' => array(
                'label'         => __( 'Resend Email', 'smart-forms-for-midland' ),
                'description'   => __( 'Route every WordPress email (form notifications, password resets, system messages) through Resend SMTP so leads do not vanish into spam. Paste your Resend API key once.', 'smart-forms-for-midland' ),
                'status_check'  => function () {
                    return (bool) get_option( 'sfco_resend_enabled' ) && (string) get_option( 'sfco_resend_api_key', '' ) !== '';
                },
            ),
            'sfco-crm' => array(
                'label'         => __( 'CRM (ActiveCampaign)', 'smart-forms-for-midland' ),
                'description'   => __( 'Every form submission pushes the contact into ActiveCampaign with tags and form context, so a lead is in your sales pipeline within seconds. Needs the API URL and key from ActiveCampaign Settings.', 'smart-forms-for-midland' ),
                'status_check'  => function () {
                    return (string) get_option( 'sfco_pro_crm_api_url', '' ) !== '' && (string) get_option( 'sfco_pro_crm_api_key', '' ) !== '';
                },
            ),
            'sfco-gcal' => array(
                'label'         => __( 'Google Calendar', 'smart-forms-for-midland' ),
                'description'   => __( 'When an appointment is confirmed (via Calendly or a booking form), a calendar event is created on your Google Calendar with the lead, time, and notes. OAuth handshake required once.', 'smart-forms-for-midland' ),
                'status_check'  => function () {
                    return (string) get_option( 'sfco_gcal_refresh_token', '' ) !== '';
                },
            ),
            'sfco-calendar' => array(
                'label'         => __( 'Calendly', 'smart-forms-for-midland' ),
                'description'   => __( 'Drop a Calendly booking widget on any page and tie confirmations into Google Calendar + CRM via the appointment-confirmed hook.', 'smart-forms-for-midland' ),
                'status_check'  => function () {
                    return (string) get_option( 'sfco_pro_calendly_api_key', '' ) !== '';
                },
            ),
            'sfco-automations' => array(
                'label'         => __( 'Automations', 'smart-forms-for-midland' ),
                'description'   => __( 'Build trigger-action rules: when a form is submitted with certain values, send an email, fire a webhook, tag the lead, or schedule a task.', 'smart-forms-for-midland' ),
                'status_check'  => function () {
                    global $wpdb;
                    $table = $wpdb->prefix . 'sfco_automations';
                    // Defensive — the table may not exist yet on a fresh
                    // install / pre-table-creation upgrade. Suppress
                    // errors so the Settings hub never breaks the page.
                    $exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
                    if ( ! $exists ) {
                        return false;
                    }
                    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                },
            ),
            'sfco-branding' => array(
                'label'         => __( 'Branding', 'smart-forms-for-midland' ),
                'description'   => __( 'Match the form to the Midland Floors brand: logo on confirmation emails, accent color overrides, custom thank-you copy.', 'smart-forms-for-midland' ),
                'status_check'  => function () {
                    $b = get_option( 'sfco_pro_branding', array() );
                    return is_array( $b ) && ( ! empty( $b['primary_color'] ) || ! empty( $b['logo_url'] ) );
                },
            ),
            'sfco-team' => array(
                'label'         => __( 'Team', 'smart-forms-for-midland' ),
                'description'   => __( 'Route lead notifications to specific team members by form type, service requested, or geography. Multiple recipients, with reply-to set to the lead.', 'smart-forms-for-midland' ),
                'status_check'  => function () {
                    global $wpdb;
                    $table  = $wpdb->prefix . 'sfco_team_members';
                    $exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
                    if ( ! $exists ) {
                        return false;
                    }
                    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                },
            ),
            'sfco-analytics' => array(
                'label'         => __( 'Analytics', 'smart-forms-for-midland' ),
                'description'   => __( 'Per-form view, submission, and conversion counts plus top-converting pages. Lives in WP admin, no third-party script needed.', 'smart-forms-for-midland' ),
                'status_check'  => null,
            ),
        );
    }

    public function __construct() {
        // Register the Settings landing page right after the free Tracking
        // page in the sidebar (free admin runs at default priority 10).
        add_action( 'admin_menu', array( $this, 'register' ), 25 );
        // Strip the individual Pro submenus from the sidebar after every
        // Pro module has finished registering (Pro modules use 30-36).
        add_action( 'admin_menu', array( $this, 'hide_individual_submenus' ), 999 );
    }

    public function register() {
        add_submenu_page(
            'smart-forms',
            __( 'Settings', 'smart-forms-for-midland' ),
            __( 'Settings', 'smart-forms-for-midland' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render' )
        );
    }

    /**
     * Remove every Pro integration submenu so the sidebar shows only the
     * free entries plus our consolidated Settings link. The submenu
     * removal does not unregister the underlying page — direct URLs
     * (?page=sfco-resend, etc.) still load.
     */
    public function hide_individual_submenus() {
        foreach ( array_keys( $this->integrations() ) as $slug ) {
            remove_submenu_page( 'smart-forms', $slug );
        }
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $integrations = $this->integrations();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart Forms Settings', 'smart-forms-for-midland' ); ?></h1>
            <p style="max-width:720px;color:#4B5563;font-size:15px;line-height:1.5;">
                <?php esc_html_e( 'Every submission flows through this stack: the lead is saved, then automatically synced to your CRM, emailed via Resend, tracked for ads, and (for appointment forms) added to Google Calendar. Click Configure on each card to wire it up.', 'smart-forms-for-midland' ); ?>
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:18px;margin-top:24px;">
                <?php foreach ( $integrations as $slug => $info ) :
                    $configured = is_callable( $info['status_check'] ) ? (bool) call_user_func( $info['status_check'] ) : null;
                    $url        = add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) );
                    ?>
                    <div style="background:#fff;border:1px solid #d6e6dc;border-top:4px solid #2F8137;border-radius:8px;padding:22px;display:flex;flex-direction:column;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px;">
                            <h2 style="margin:0;font-size:18px;color:#0F1411;font-weight:800;"><?php echo esc_html( $info['label'] ); ?></h2>
                            <?php if ( true === $configured ) : ?>
                                <span style="background:#F3FCF4;color:#2F8137;font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;padding:4px 10px;border-radius:999px;border:1px solid #7CCE8E;"><?php esc_html_e( 'Connected', 'smart-forms-for-midland' ); ?></span>
                            <?php elseif ( false === $configured ) : ?>
                                <span style="background:#fdecec;color:#7a1d1d;font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;padding:4px 10px;border-radius:999px;border:1px solid #f1b4b4;"><?php esc_html_e( 'Not set up', 'smart-forms-for-midland' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <p style="margin:0 0 18px;color:#4B5563;font-size:14px;line-height:1.55;flex:1;"><?php echo esc_html( $info['description'] ); ?></p>
                        <a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="align-self:flex-start;background:#43A94B;border-color:#43A94B;font-weight:700;">
                            <?php echo true === $configured ? esc_html__( 'Manage', 'smart-forms-for-midland' ) : esc_html__( 'Configure', 'smart-forms-for-midland' ); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <p style="margin-top:32px;color:#6b8278;font-size:13px;">
                <?php esc_html_e( 'Looking for Tracking (Google Ads / Meta / TikTok pixels)? That stayed in the sidebar as its own page so you can wire ad pixels without coming through here.', 'smart-forms-for-midland' ); ?>
            </p>
        </div>
        <?php
    }
}

new SFCO_Pro_Settings();
