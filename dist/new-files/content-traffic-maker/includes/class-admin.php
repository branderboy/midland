<?php
/**
 * Admin settings page, manual generate button, and brief history.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTM_Admin {

    const PAGE = 'content-traffic-maker';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_ctm_generate_now', array( $this, 'handle_generate_now' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'Content Traffic Maker', 'content-traffic-maker' ),
            __( 'Content Traffic Maker', 'content-traffic-maker' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render_page' ),
            'dashicons-chart-line',
            58
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_' . self::PAGE !== $hook ) {
            return;
        }
        wp_enqueue_style( 'ctm-admin', CTM_URL . 'assets/admin.css', array(), CTM_VERSION );
    }

    // ── Save settings ─────────────────────────────────────────────────────────

    public function handle_save() {
        if ( ! isset( $_POST['ctm_save_settings'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! check_admin_referer( 'ctm_save_settings', '_ctm_nonce' ) ) {
            return;
        }

        $current = CTM_DB::get_settings();

        $types = array( 'lender', 'realtor', 'contractor', 'lawyer', 'healthcare', 'other' );
        $type  = sanitize_key( wp_unslash( $_POST['business_type'] ?? 'lender' ) );

        $settings = array(
            'business_name'   => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
            'business_type'   => in_array( $type, $types, true ) ? $type : 'other',
            'target_city'     => sanitize_text_field( wp_unslash( $_POST['target_city'] ?? '' ) ),
            'target_state'    => sanitize_text_field( wp_unslash( $_POST['target_state'] ?? '' ) ),
            'target_audience' => sanitize_text_field( wp_unslash( $_POST['target_audience'] ?? '' ) ),
            'main_service'    => sanitize_text_field( wp_unslash( $_POST['main_service'] ?? '' ) ),
            'website_url'     => esc_url_raw( wp_unslash( $_POST['website_url'] ?? '' ) ),
            'recipient'       => sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) ),
            'frequency'       => 'daily' === sanitize_key( wp_unslash( $_POST['frequency'] ?? 'weekly' ) ) ? 'daily' : 'weekly',
            'send_time'       => $this->sanitize_time( wp_unslash( $_POST['send_time'] ?? '08:00' ) ),
            'model'           => sanitize_text_field( wp_unslash( $_POST['model'] ?? 'sonar' ) ) ?: 'sonar',
            'enabled'         => isset( $_POST['enabled'] ) ? 1 : 0,
        );

        // API key is a secret: never echoed back, so a blank field means
        // "keep the stored key" rather than "clear it".
        $posted_key = trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) );
        $settings['api_key'] = '' !== $posted_key ? sanitize_text_field( $posted_key ) : (string) ( $current['api_key'] ?? '' );

        CTM_DB::update_settings( wp_parse_args( $settings, $current ) );

        // Apply the new schedule immediately.
        CTM_Cron::reschedule();

        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private function sanitize_time( $value ) {
        $value = sanitize_text_field( $value );
        return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value ) ? $value : '08:00';
    }

    // ── Manual generate ───────────────────────────────────────────────────────

    public function handle_generate_now() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'content-traffic-maker' ) );
        }
        check_admin_referer( 'ctm_generate_now' );

        $result = CTM_Cron::run( true );
        if ( is_wp_error( $result ) ) {
            $args = array( 'page' => self::PAGE, 'gen_error' => rawurlencode( $result->get_error_message() ) );
        } else {
            $args = array( 'page' => self::PAGE, 'generated' => (int) $result['brief_id'] );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = CTM_DB::get_settings();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved     = isset( $_GET['saved'] );
        $generated = isset( $_GET['generated'] ) ? absint( $_GET['generated'] ) : 0;
        $gen_error = isset( $_GET['gen_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gen_error'] ) ) : '';
        // phpcs:enable

        $types = array(
            'lender'     => __( 'Lender', 'content-traffic-maker' ),
            'realtor'    => __( 'Realtor', 'content-traffic-maker' ),
            'contractor' => __( 'Contractor', 'content-traffic-maker' ),
            'lawyer'     => __( 'Lawyer', 'content-traffic-maker' ),
            'healthcare' => __( 'Healthcare', 'content-traffic-maker' ),
            'other'      => __( 'Other', 'content-traffic-maker' ),
        );
        $key_set = '' !== (string) ( $s['api_key'] ?? '' );
        ?>
        <div class="wrap ctm-wrap">
            <h1><?php esc_html_e( 'Content Traffic Maker', 'content-traffic-maker' ); ?></h1>
            <p class="description ctm-intro"><?php esc_html_e( 'Daily or weekly AI traffic briefs — guest posts, local + .gov + nonprofit backlinks, and YouTube/TikTok video ideas, all specific to your city and business.', 'content-traffic-maker' ); ?></p>

            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'content-traffic-maker' ); ?></p></div><?php endif; ?>
            <?php if ( $gen_error ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $gen_error ); ?></p></div><?php endif; ?>
            <?php if ( $generated ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Traffic brief generated.', 'content-traffic-maker' ); ?></p></div><?php endif; ?>

            <div class="ctm-grid">
                <div class="ctm-col">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="ctm-card">
                        <?php wp_nonce_field( 'ctm_save_settings', '_ctm_nonce' ); ?>
                        <h2><?php esc_html_e( 'Settings', 'content-traffic-maker' ); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr><th><label for="ctm_business_name"><?php esc_html_e( 'Business name', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_business_name" name="business_name" class="regular-text" value="<?php echo esc_attr( $s['business_name'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_business_type"><?php esc_html_e( 'Business type', 'content-traffic-maker' ); ?></label></th>
                                <td><select id="ctm_business_type" name="business_type">
                                    <?php foreach ( $types as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['business_type'], $val ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select></td></tr>
                            <tr><th><label for="ctm_target_city"><?php esc_html_e( 'Target city', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_target_city" name="target_city" class="regular-text" value="<?php echo esc_attr( $s['target_city'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_target_state"><?php esc_html_e( 'Target state', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_target_state" name="target_state" class="regular-text" value="<?php echo esc_attr( $s['target_state'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_target_audience"><?php esc_html_e( 'Target audience', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_target_audience" name="target_audience" class="regular-text" value="<?php echo esc_attr( $s['target_audience'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. first-time homebuyers', 'content-traffic-maker' ); ?>"></td></tr>
                            <tr><th><label for="ctm_main_service"><?php esc_html_e( 'Main service', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_main_service" name="main_service" class="regular-text" value="<?php echo esc_attr( $s['main_service'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_website_url"><?php esc_html_e( 'Website URL', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="url" id="ctm_website_url" name="website_url" class="regular-text" value="<?php echo esc_attr( $s['website_url'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_recipient"><?php esc_html_e( 'Email recipient', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="email" id="ctm_recipient" name="recipient" class="regular-text" value="<?php echo esc_attr( $s['recipient'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_frequency"><?php esc_html_e( 'Alert frequency', 'content-traffic-maker' ); ?></label></th>
                                <td><select id="ctm_frequency" name="frequency">
                                    <option value="weekly" <?php selected( $s['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'content-traffic-maker' ); ?></option>
                                    <option value="daily" <?php selected( $s['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'content-traffic-maker' ); ?></option>
                                </select></td></tr>
                            <tr><th><label for="ctm_send_time"><?php esc_html_e( 'Preferred send time', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="time" id="ctm_send_time" name="send_time" value="<?php echo esc_attr( $s['send_time'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_model"><?php esc_html_e( 'Perplexity model', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_model" name="model" class="regular-text" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="sonar">
                                    <p class="description"><?php esc_html_e( 'e.g. sonar or sonar-pro.', 'content-traffic-maker' ); ?></p></td></tr>
                            <tr><th><label for="ctm_api_key"><?php esc_html_e( 'Perplexity API key', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="password" id="ctm_api_key" name="api_key" class="regular-text" value="" autocomplete="off" placeholder="<?php echo esc_attr( $key_set ? __( '•••••••• saved — leave blank to keep', 'content-traffic-maker' ) : 'pplx-...' ); ?>">
                                    <p class="description"><?php esc_html_e( 'From perplexity.ai → Settings → API. Stored server-side and never shown again.', 'content-traffic-maker' ); ?></p></td></tr>
                            <tr><th><?php esc_html_e( 'Enable alerts', 'content-traffic-maker' ); ?></th>
                                <td><label><input type="checkbox" name="enabled" value="1" <?php checked( (int) $s['enabled'], 1 ); ?>> <?php esc_html_e( 'Send briefs automatically on the schedule above', 'content-traffic-maker' ); ?></label></td></tr>
                        </table>
                        <p class="submit"><button type="submit" name="ctm_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'content-traffic-maker' ); ?></button></p>
                    </form>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ctm-card ctm-generate">
                        <?php wp_nonce_field( 'ctm_generate_now' ); ?>
                        <input type="hidden" name="action" value="ctm_generate_now">
                        <h2><?php esc_html_e( 'Generate now', 'content-traffic-maker' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Build a brief immediately (and email it to the recipient).', 'content-traffic-maker' ); ?></p>
                        <p><button type="submit" class="button button-secondary button-hero"><?php esc_html_e( 'Generate Traffic Brief Now', 'content-traffic-maker' ); ?></button></p>
                    </form>
                </div>

                <div class="ctm-col">
                    <?php
                    if ( $generated ) {
                        $row = CTM_DB::get_brief( $generated );
                        if ( $row ) {
                            $brief = json_decode( (string) $row->brief_json, true );
                            $this->render_brief_card( is_array( $brief ) ? $brief : array(), $row );
                        }
                    }
                    $this->render_history();
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a generated brief inside the admin (from sanitized JSON fields).
     */
    private function render_brief_card( $brief, $row ) {
        $rows = array(
            __( 'Priority', 'content-traffic-maker' )            => ( (int) ( $brief['priority_score'] ?? 0 ) ) . '/10',
            __( 'Guest post topic', 'content-traffic-maker' )    => $brief['guest_post_topic'] ?? '',
            __( 'Why it drives traffic', 'content-traffic-maker' ) => $brief['why_traffic'] ?? '',
            __( 'Publishing target', 'content-traffic-maker' )   => $brief['publishing_target'] ?? '',
            __( 'Suggested headline', 'content-traffic-maker' )  => $brief['headline'] ?? '',
            __( 'Local backlink', 'content-traffic-maker' )      => $brief['local_backlink'] ?? '',
            __( 'Outreach angle', 'content-traffic-maker' )      => $brief['outreach_angle'] ?? '',
            __( '.gov backlink', 'content-traffic-maker' )       => $brief['gov_backlink'] ?? '',
            __( 'Nonprofit backlink', 'content-traffic-maker' )  => $brief['nonprofit_backlink'] ?? '',
            __( 'YouTube SEO (commercial)', 'content-traffic-maker' )            => $brief['youtube_idea'] ?? '',
            __( 'YouTube SEO (residential carpet/install)', 'content-traffic-maker' ) => $brief['youtube_residential'] ?? '',
            __( 'TikTok SEO video', 'content-traffic-maker' )                    => $brief['tiktok_seo'] ?? '',
            __( 'Viral TikTok video', 'content-traffic-maker' )                  => $brief['tiktok_viral'] ?? '',
            __( 'Residential carpet/install offer video', 'content-traffic-maker' ) => $brief['residential_offer_video'] ?? '',
            __( 'CTA', 'content-traffic-maker' )                                 => $brief['cta'] ?? '',
        );
        ?>
        <div class="ctm-card ctm-brief">
            <h2><?php esc_html_e( 'Latest brief', 'content-traffic-maker' ); ?>
                <span class="ctm-badge ctm-badge--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></span>
            </h2>
            <table class="ctm-brief-table">
                <?php foreach ( $rows as $label => $value ) : ?>
                    <tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    private function render_history() {
        $briefs = CTM_DB::get_briefs( 20 );
        ?>
        <div class="ctm-card">
            <h2><?php esc_html_e( 'Brief history', 'content-traffic-maker' ); ?></h2>
            <?php if ( empty( $briefs ) ) : ?>
                <p class="description"><?php esc_html_e( 'No briefs yet. Click “Generate Traffic Brief Now” to create your first one.', 'content-traffic-maker' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'When', 'content-traffic-maker' ); ?></th>
                        <th><?php esc_html_e( 'Business', 'content-traffic-maker' ); ?></th>
                        <th><?php esc_html_e( 'Priority', 'content-traffic-maker' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'content-traffic-maker' ); ?></th>
                        <th><?php esc_html_e( 'Sent to', 'content-traffic-maker' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $briefs as $b ) :
                            $bj = json_decode( (string) $b->brief_json, true );
                            $pri = is_array( $bj ) ? (int) ( $bj['priority_score'] ?? 0 ) : 0;
                            ?>
                            <tr>
                                <td><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'generated' => (int) $b->id ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $b->created_at ); ?></a></td>
                                <td><?php echo esc_html( $b->business_name ); ?></td>
                                <td><?php echo esc_html( $pri ? $pri . '/10' : '—' ); ?></td>
                                <td><span class="ctm-badge ctm-badge--<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( $b->status ); ?></span></td>
                                <td><?php echo esc_html( $b->sent_to ?: '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
