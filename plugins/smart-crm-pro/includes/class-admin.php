<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 40 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );

        add_action( 'wp_ajax_scrm_launch_campaign', array( $this, 'ajax_launch_campaign' ) );
        add_action( 'wp_ajax_scrm_delete_campaign', array( $this, 'ajax_delete_campaign' ) );
    }

    public function add_menu() {
        add_submenu_page( 'sfco-forms', __( 'Reactivation', 'smart-crm-pro' ), __( 'Reactivation', 'smart-crm-pro' ), 'manage_options', 'scrm-reactivation', array( $this, 'render_reactivation_page' ) );
        add_submenu_page( 'sfco-forms', __( 'Campaigns', 'smart-crm-pro' ), __( 'Campaigns', 'smart-crm-pro' ), 'manage_options', 'scrm-campaigns', array( $this, 'render_campaigns_page' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'scrm-' ) === false && strpos( $hook, 'sfco-' ) === false ) {
            return;
        }
        wp_enqueue_style( 'scrm-pro-admin', SCRM_PRO_URL . 'admin/css/admin.css', array(), SCRM_PRO_VERSION );
        wp_enqueue_script( 'scrm-pro-admin', SCRM_PRO_URL . 'admin/js/admin.js', array( 'jquery' ), SCRM_PRO_VERSION, true );
        wp_localize_script( 'scrm-pro-admin', 'scrmProData', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'scrm_pro_nonce' ),
        ) );
    }

    public function handle_actions() {
        if ( isset( $_POST['scrm_save_campaign'] ) && current_user_can( 'manage_options' ) ) {
            $nonce = isset( $_POST['_scrm_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'scrm_save_campaign' ) ) {
                wp_die( esc_html__( 'Security check failed.', 'smart-crm-pro' ) );
            }

            $data = array(
                'name'               => isset( $_POST['campaign_name'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_name'] ) ) : '',
                'segment'            => isset( $_POST['campaign_segment'] ) ? sanitize_key( $_POST['campaign_segment'] ) : 'recent_cold',
                'email_subject'      => isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
                'email_body'         => isset( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '',
                'follow_up_subject'  => isset( $_POST['follow_up_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['follow_up_subject'] ) ) : '',
                'follow_up_body'     => isset( $_POST['follow_up_body'] ) ? wp_kses_post( wp_unslash( $_POST['follow_up_body'] ) ) : '',
                'follow_up_delay'    => isset( $_POST['follow_up_delay'] ) ? absint( $_POST['follow_up_delay'] ) : 3,
                'filter_min_days'    => isset( $_POST['filter_min_days'] ) ? absint( $_POST['filter_min_days'] ) : 30,
                'filter_max_days'    => isset( $_POST['filter_max_days'] ) ? absint( $_POST['filter_max_days'] ) : 365,
                'filter_status'      => isset( $_POST['filter_status'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_status'] ) ) : '',
                'filter_project_type' => isset( $_POST['filter_project_type'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_project_type'] ) ) : '',
                'filter_min_estimate' => isset( $_POST['filter_min_estimate'] ) ? floatval( $_POST['filter_min_estimate'] ) : 0,
            );

            $id = SCRM_Pro_Campaign_Manager::create_campaign( $data );
            wp_safe_redirect( admin_url( 'admin.php?page=scrm-campaigns&saved=1' ) );
            exit;
        }
    }

    public function ajax_launch_campaign() {
        check_ajax_referer( 'scrm_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

        $id     = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
        $queued = SCRM_Pro_Campaign_Manager::launch_campaign( $id );

        if ( false === $queued ) {
            wp_send_json_error( array( 'message' => __( 'Campaign not found or already launched.', 'smart-crm-pro' ) ) );
        }

        wp_send_json_success( array( 'message' => sprintf( __( 'Campaign launched! %d emails queued.', 'smart-crm-pro' ), $queued ) ) );
    }

    public function ajax_delete_campaign() {
        check_ajax_referer( 'scrm_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

        $id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
        SCRM_Pro_Campaign_Manager::delete_campaign( $id );
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
     *  Reactivation Dashboard
     * ----------------------------------------------------------------*/

    public function render_reactivation_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $overview  = SCRM_Pro_Analytics::get_overview();
        $segments  = SCRM_Pro_Analytics::get_segment_breakdown();
        $seg_labels = array(
            'high_value_quoted' => __( 'High-Value Quoted', 'smart-crm-pro' ),
            'recent_cold'       => __( 'Recent Cold (< 90 days)', 'smart-crm-pro' ),
            'lost_winback'      => __( 'Lost / Win-Back', 'smart-crm-pro' ),
            'aging_leads'       => __( 'Aging Database', 'smart-crm-pro' ),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Lead Reactivation', 'smart-crm-pro' ); ?></h1>

            <div class="scrm-stats-grid">
                <div class="scrm-stat-card">
                    <span class="scrm-stat-label"><?php esc_html_e( 'Cold Leads', 'smart-crm-pro' ); ?></span>
                    <span class="scrm-stat-value"><?php echo esc_html( $overview['total_cold'] ); ?></span>
                </div>
                <div class="scrm-stat-card">
                    <span class="scrm-stat-label"><?php esc_html_e( 'Cold Lead Value', 'smart-crm-pro' ); ?></span>
                    <span class="scrm-stat-value">$<?php echo esc_html( number_format( $overview['cold_value'] ) ); ?></span>
                </div>
                <div class="scrm-stat-card">
                    <span class="scrm-stat-label"><?php esc_html_e( 'Campaigns Sent', 'smart-crm-pro' ); ?></span>
                    <span class="scrm-stat-value"><?php echo esc_html( $overview['total_campaigns'] ); ?></span>
                </div>
                <div class="scrm-stat-card">
                    <span class="scrm-stat-label"><?php esc_html_e( 'Emails Sent', 'smart-crm-pro' ); ?></span>
                    <span class="scrm-stat-value"><?php echo esc_html( $overview['emails_sent'] ); ?></span>
                </div>
                <div class="scrm-stat-card">
                    <span class="scrm-stat-label"><?php esc_html_e( 'Reactivated', 'smart-crm-pro' ); ?></span>
                    <span class="scrm-stat-value scrm-green"><?php echo esc_html( $overview['reactivated'] ); ?></span>
                </div>
                <div class="scrm-stat-card">
                    <span class="scrm-stat-label"><?php esc_html_e( 'Reactivation Rate', 'smart-crm-pro' ); ?></span>
                    <span class="scrm-stat-value"><?php echo esc_html( $overview['reactivation_rate'] ); ?>%</span>
                </div>
                <div class="scrm-stat-card">
                    <span class="scrm-stat-label"><?php esc_html_e( 'Revenue Recovered', 'smart-crm-pro' ); ?></span>
                    <span class="scrm-stat-value scrm-green">$<?php echo esc_html( number_format( $overview['revenue_recovered'] ) ); ?></span>
                </div>
            </div>

            <h2><?php esc_html_e( 'Cold Lead Segments', 'smart-crm-pro' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Your cold leads segmented by reactivation potential. Click "Create Campaign" to target a segment.', 'smart-crm-pro' ); ?></p>

            <div class="scrm-segment-grid">
                <?php foreach ( $segments as $key => $data ) :
                    $label = $seg_labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
                ?>
                    <div class="scrm-segment-card">
                        <h3><?php echo esc_html( $label ); ?></h3>
                        <div class="scrm-segment-stats">
                            <span><strong><?php echo esc_html( $data['count'] ); ?></strong> <?php esc_html_e( 'leads', 'smart-crm-pro' ); ?></span>
                            <span>$<?php echo esc_html( number_format( $data['value'] ) ); ?> <?php esc_html_e( 'potential', 'smart-crm-pro' ); ?></span>
                        </div>
                        <?php if ( $data['count'] > 0 ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=scrm-campaigns&action=new&segment=' . $key ) ); ?>" class="button button-primary"><?php esc_html_e( 'Create Campaign', 'smart-crm-pro' ); ?></a>
                        <?php else : ?>
                            <span class="description"><?php esc_html_e( 'No leads in this segment.', 'smart-crm-pro' ); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     *  Campaigns Page
     * ----------------------------------------------------------------*/

    public function render_campaigns_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        if ( 'new' === $action ) {
            $this->render_campaign_builder();
            return;
        }

        // Campaign list.
        $campaigns = SCRM_Pro_Campaign_Manager::get_campaigns();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Reactivation Campaigns', 'smart-crm-pro' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=scrm-campaigns&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'New Campaign', 'smart-crm-pro' ); ?></a>
            <hr class="wp-header-end">

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Campaign created! Click Launch to start sending.', 'smart-crm-pro' ); ?></p></div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Campaign', 'smart-crm-pro' ); ?></th>
                        <th><?php esc_html_e( 'Segment', 'smart-crm-pro' ); ?></th>
                        <th><?php esc_html_e( 'Targeted', 'smart-crm-pro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'smart-crm-pro' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'smart-crm-pro' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'smart-crm-pro' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $campaigns ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No campaigns yet. Create one from the Reactivation dashboard.', 'smart-crm-pro' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $campaigns as $c ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $c->name ); ?></strong></td>
                                <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $c->segment ) ) ); ?></td>
                                <td><?php echo esc_html( $c->leads_targeted ); ?></td>
                                <td>
                                    <?php if ( 'active' === $c->status ) : ?>
                                        <span style="color:#00a32a;font-weight:600;"><?php esc_html_e( 'Active', 'smart-crm-pro' ); ?></span>
                                    <?php elseif ( 'draft' === $c->status ) : ?>
                                        <span style="color:#f0b849;font-weight:600;"><?php esc_html_e( 'Draft', 'smart-crm-pro' ); ?></span>
                                    <?php else : ?>
                                        <?php echo esc_html( ucfirst( $c->status ) ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $c->created_at ) ) ); ?></td>
                                <td>
                                    <?php if ( 'draft' === $c->status ) : ?>
                                        <button type="button" class="button scrm-launch-btn" data-id="<?php echo esc_attr( $c->id ); ?>"><?php esc_html_e( 'Launch', 'smart-crm-pro' ); ?></button>
                                    <?php endif; ?>
                                    <button type="button" class="button scrm-delete-btn" data-id="<?php echo esc_attr( $c->id ); ?>"><?php esc_html_e( 'Delete', 'smart-crm-pro' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_campaign_builder() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $segment   = isset( $_GET['segment'] ) ? sanitize_key( $_GET['segment'] ) : 'recent_cold';
        $templates = SCRM_Pro_Campaign_Manager::get_segment_templates();
        $tpl       = $templates[ $segment ] ?? $templates['recent_cold'];

        $seg_labels = array(
            'high_value_quoted' => __( 'High-Value Quoted', 'smart-crm-pro' ),
            'recent_cold'       => __( 'Recent Cold', 'smart-crm-pro' ),
            'lost_winback'      => __( 'Lost / Win-Back', 'smart-crm-pro' ),
            'aging_leads'       => __( 'Aging Database', 'smart-crm-pro' ),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Create Reactivation Campaign', 'smart-crm-pro' ); ?></h1>

            <form method="post">
                <?php wp_nonce_field( 'scrm_save_campaign', '_scrm_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="campaign_name"><?php esc_html_e( 'Campaign Name', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="text" name="campaign_name" id="campaign_name" class="regular-text" value="<?php echo esc_attr( $tpl['name'] ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="campaign_segment"><?php esc_html_e( 'Segment', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <select name="campaign_segment" id="campaign_segment">
                                <?php foreach ( $seg_labels as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $segment, $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="filter_min_days"><?php esc_html_e( 'Lead Age (days)', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="number" name="filter_min_days" id="filter_min_days" value="30" min="1" style="width:80px;"> <?php esc_html_e( 'to', 'smart-crm-pro' ); ?>
                            <input type="number" name="filter_max_days" value="365" min="1" style="width:80px;"> <?php esc_html_e( 'days old', 'smart-crm-pro' ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="filter_min_estimate"><?php esc_html_e( 'Min Estimate Value', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="number" name="filter_min_estimate" id="filter_min_estimate" value="0" min="0" step="100" style="width:120px;"> <span class="description">$</span></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Initial Email', 'smart-crm-pro' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Tags: {name}, {email}, {phone}, {project_type}, {timeline}, {estimate}, {business}', 'smart-crm-pro' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th><label for="email_subject"><?php esc_html_e( 'Subject', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="text" name="email_subject" id="email_subject" class="large-text" value="<?php echo esc_attr( $tpl['email_subject'] ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="email_body"><?php esc_html_e( 'Body', 'smart-crm-pro' ); ?></label></th>
                        <td><textarea name="email_body" id="email_body" class="large-text" rows="8"><?php echo esc_textarea( $tpl['email_body'] ); ?></textarea></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Follow-Up Email (optional)', 'smart-crm-pro' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="follow_up_delay"><?php esc_html_e( 'Send After', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="number" name="follow_up_delay" id="follow_up_delay" value="<?php echo esc_attr( $tpl['follow_up_delay'] ); ?>" min="1" style="width:80px;"> <?php esc_html_e( 'days', 'smart-crm-pro' ); ?></td>
                    </tr>
                    <tr>
                        <th><label for="follow_up_subject"><?php esc_html_e( 'Subject', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="text" name="follow_up_subject" id="follow_up_subject" class="large-text" value="<?php echo esc_attr( $tpl['follow_up_subject'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="follow_up_body"><?php esc_html_e( 'Body', 'smart-crm-pro' ); ?></label></th>
                        <td><textarea name="follow_up_body" id="follow_up_body" class="large-text" rows="6"><?php echo esc_textarea( $tpl['follow_up_body'] ); ?></textarea></td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="scrm_save_campaign" value="1" class="button button-primary"><?php esc_html_e( 'Create Campaign', 'smart-crm-pro' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}
