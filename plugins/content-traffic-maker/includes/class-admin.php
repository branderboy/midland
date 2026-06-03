<?php
/**
 * Admin — settings, manual generate, brief display.
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
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_init',            array( $this, 'handle_save' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_ctm_generate_now', array( $this, 'handle_generate_now' ) );
        add_action( 'admin_post_ctm_send_brief',   array( $this, 'handle_send_brief' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'Video Brief', 'content-traffic-maker' ),
            __( 'Video Brief', 'content-traffic-maker' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render_page' ),
            'dashicons-video-alt3',
            58
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_' . self::PAGE !== $hook ) return;
        wp_enqueue_style( 'ctm-admin', CTM_URL . 'assets/admin.css', array(), CTM_VERSION );
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    public function handle_save() {
        if ( ! isset( $_POST['ctm_save_settings'] ) || ! current_user_can( 'manage_options' ) ) return;
        if ( ! check_admin_referer( 'ctm_save_settings', '_ctm_nonce' ) ) return;

        $current = CTM_DB::get_settings();

        $settings = array(
            'business_name' => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
            'target_city'   => sanitize_text_field( wp_unslash( $_POST['target_city']   ?? '' ) ),
            'target_state'  => sanitize_text_field( wp_unslash( $_POST['target_state']  ?? '' ) ),
            'recipient'     => sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) ),
            'frequency'     => 'daily' === sanitize_key( wp_unslash( $_POST['frequency'] ?? 'weekly' ) ) ? 'daily' : 'weekly',
            'send_time'     => $this->sanitize_time( wp_unslash( $_POST['send_time'] ?? '08:00' ) ),
            'model'         => sanitize_text_field( wp_unslash( $_POST['model'] ?? 'sonar' ) ) ?: 'sonar',
            'enabled'       => isset( $_POST['enabled'] ) ? 1 : 0,
            'from_name'     => sanitize_text_field( wp_unslash( $_POST['from_name']  ?? 'Midland Floors' ) ),
            'from_email'    => sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) ),
        );

        $posted_key    = trim( (string) wp_unslash( $_POST['api_key']        ?? '' ) );
        $posted_resend = trim( (string) wp_unslash( $_POST['resend_api_key'] ?? '' ) );
        $settings['api_key']        = '' !== $posted_key    ? sanitize_text_field( $posted_key )    : (string) ( $current['api_key']        ?? '' );
        $settings['resend_api_key'] = '' !== $posted_resend ? sanitize_text_field( $posted_resend ) : (string) ( $current['resend_api_key'] ?? '' );

        CTM_DB::update_settings( wp_parse_args( $settings, $current ) );
        CTM_Cron::reschedule();

        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private function sanitize_time( $value ) {
        $value = sanitize_text_field( $value );
        return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value ) ? $value : '08:00';
    }

    // ── Generate (no email) ───────────────────────────────────────────────────

    public function handle_generate_now() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Insufficient permissions.', 'content-traffic-maker' ) );
        check_admin_referer( 'ctm_generate_now' );

        $result = CTM_Cron::generate_only();
        if ( is_wp_error( $result ) ) {
            $args = array( 'page' => self::PAGE, 'gen_error' => rawurlencode( $result->get_error_message() ) );
        } else {
            $args = array( 'page' => self::PAGE, 'generated' => (int) $result['brief_id'] );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── Send existing brief by ID ─────────────────────────────────────────────

    public function handle_send_brief() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Insufficient permissions.', 'content-traffic-maker' ) );
        check_admin_referer( 'ctm_send_brief' );

        $brief_id = absint( $_POST['brief_id'] ?? 0 );
        if ( ! $brief_id ) {
            wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'gen_error' => rawurlencode( 'No brief ID provided.' ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $result = CTM_Cron::send_brief_by_id( $brief_id );
        if ( is_wp_error( $result ) ) {
            $args = array( 'page' => self::PAGE, 'generated' => $brief_id, 'gen_error' => rawurlencode( $result->get_error_message() ) );
        } else {
            $args = array( 'page' => self::PAGE, 'generated' => $brief_id, 'emailed' => 1 );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── Render page ───────────────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = CTM_DB::get_settings();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved     = isset( $_GET['saved'] );
        $generated = isset( $_GET['generated'] ) ? absint( $_GET['generated'] ) : 0;
        $emailed   = isset( $_GET['emailed'] );
        $gen_error = isset( $_GET['gen_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gen_error'] ) ) : '';
        // phpcs:enable

        $key_set    = '' !== (string) ( $s['api_key']        ?? '' );
        $resend_set = '' !== (string) ( $s['resend_api_key'] ?? '' );
        ?>
        <div class="wrap ctm-wrap">
            <h1>📹 <?php esc_html_e( 'Midland Floors — Daily Video Brief', 'content-traffic-maker' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Daily SEO keyword, offer, and viral video ideas — delivered to your client via email.', 'content-traffic-maker' ); ?></p>

            <?php if ( $saved )     : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'content-traffic-maker' ); ?></p></div><?php endif; ?>
            <?php if ( $emailed )   : ?><div class="notice notice-success is-dismissible"><p>✅ <?php esc_html_e( 'Brief emailed to client.', 'content-traffic-maker' ); ?></p></div><?php endif; ?>
            <?php if ( $generated && ! $emailed ) : ?><div class="notice notice-info is-dismissible"><p>📄 <?php esc_html_e( 'Brief generated. Hit "Send Brief" to email it.', 'content-traffic-maker' ); ?></p></div><?php endif; ?>
            <?php if ( $gen_error ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $gen_error ); ?></p></div><?php endif; ?>

            <div class="ctm-grid">
                <div class="ctm-col">

                    <!-- Settings -->
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="ctm-card">
                        <?php wp_nonce_field( 'ctm_save_settings', '_ctm_nonce' ); ?>
                        <h2><?php esc_html_e( 'Settings', 'content-traffic-maker' ); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr><th><label for="ctm_business_name"><?php esc_html_e( 'Business name', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_business_name" name="business_name" class="regular-text" value="<?php echo esc_attr( $s['business_name'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_target_city"><?php esc_html_e( 'Target city', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_target_city" name="target_city" class="regular-text" value="<?php echo esc_attr( $s['target_city'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_target_state"><?php esc_html_e( 'Target state', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_target_state" name="target_state" class="regular-text" value="<?php echo esc_attr( $s['target_state'] ); ?>" style="max-width:80px;"></td></tr>

                            <tr><th colspan="2"><hr style="border:none;border-top:1px solid #eee;margin:4px 0;"></th></tr>
                            <tr><th><label for="ctm_recipient"><?php esc_html_e( 'Client email', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="email" id="ctm_recipient" name="recipient" class="regular-text" value="<?php echo esc_attr( $s['recipient'] ); ?>">
                                    <p class="description"><?php esc_html_e( 'Who receives the brief. Both plain text and HTML sent.', 'content-traffic-maker' ); ?></p></td></tr>
                            <tr><th><label for="ctm_from_name"><?php esc_html_e( 'From name', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_from_name" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ?? 'Midland Floors' ); ?>"></td></tr>
                            <tr><th><label for="ctm_from_email"><?php esc_html_e( 'From email', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="email" id="ctm_from_email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ?? '' ); ?>">
                                    <p class="description"><?php esc_html_e( 'Must match a verified domain in your Resend account.', 'content-traffic-maker' ); ?></p></td></tr>

                            <tr><th colspan="2"><hr style="border:none;border-top:1px solid #eee;margin:4px 0;"></th></tr>
                            <tr><th><label for="ctm_resend_api_key"><?php esc_html_e( 'Resend API key', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="password" id="ctm_resend_api_key" name="resend_api_key" class="regular-text" value="" autocomplete="off"
                                    placeholder="<?php echo esc_attr( $resend_set ? '•••••••• saved — leave blank to keep' : 're_...' ); ?>">
                                    <p class="description"><?php esc_html_e( 'From resend.com → API Keys. Falls back to wp_mail if empty.', 'content-traffic-maker' ); ?></p></td></tr>

                            <tr><th colspan="2"><hr style="border:none;border-top:1px solid #eee;margin:4px 0;"></th></tr>
                            <tr><th><label for="ctm_frequency"><?php esc_html_e( 'Frequency', 'content-traffic-maker' ); ?></label></th>
                                <td><select id="ctm_frequency" name="frequency">
                                    <option value="daily"  <?php selected( $s['frequency'], 'daily'  ); ?>><?php esc_html_e( 'Daily', 'content-traffic-maker' ); ?></option>
                                    <option value="weekly" <?php selected( $s['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'content-traffic-maker' ); ?></option>
                                </select></td></tr>
                            <tr><th><label for="ctm_send_time"><?php esc_html_e( 'Send time', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="time" id="ctm_send_time" name="send_time" value="<?php echo esc_attr( $s['send_time'] ); ?>"></td></tr>
                            <tr><th><label for="ctm_model"><?php esc_html_e( 'Perplexity model', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="text" id="ctm_model" name="model" class="regular-text" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="sonar">
                                    <p class="description"><?php esc_html_e( 'sonar or sonar-pro. Sonar-pro gives better real-time results.', 'content-traffic-maker' ); ?></p></td></tr>
                            <tr><th><label for="ctm_api_key"><?php esc_html_e( 'Perplexity API key', 'content-traffic-maker' ); ?></label></th>
                                <td><input type="password" id="ctm_api_key" name="api_key" class="regular-text" value="" autocomplete="off"
                                    placeholder="<?php echo esc_attr( $key_set ? '•••••••• saved — leave blank to keep' : 'pplx-...' ); ?>">
                                    <p class="description"><?php esc_html_e( 'perplexity.ai → Settings → API Keys', 'content-traffic-maker' ); ?></p></td></tr>
                            <tr><th><?php esc_html_e( 'Enable auto-send', 'content-traffic-maker' ); ?></th>
                                <td><label><input type="checkbox" name="enabled" value="1" <?php checked( (int) $s['enabled'], 1 ); ?>>
                                    <?php esc_html_e( 'Send brief automatically on schedule', 'content-traffic-maker' ); ?></label></td></tr>
                        </table>
                        <p class="submit"><button type="submit" name="ctm_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'content-traffic-maker' ); ?></button></p>
                    </form>

                    <!-- Generate now -->
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ctm-card ctm-generate">
                        <?php wp_nonce_field( 'ctm_generate_now' ); ?>
                        <input type="hidden" name="action" value="ctm_generate_now">
                        <h2><?php esc_html_e( 'Generate Brief', 'content-traffic-maker' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Pulls today\'s video ideas from Perplexity and shows them here. Does not send an email — use the "Send Brief" button after reviewing.', 'content-traffic-maker' ); ?></p>
                        <p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( '⚡ Generate Video Brief', 'content-traffic-maker' ); ?></button></p>
                    </form>

                    <!-- Brief history -->
                    <?php $this->render_history(); ?>

                </div>

                <div class="ctm-col">
                    <?php
                    if ( $generated ) {
                        $row = CTM_DB::get_brief( $generated );
                        if ( $row ) {
                            $brief = json_decode( (string) $row->brief_json, true );
                            $this->render_brief_card( is_array( $brief ) ? $brief : array(), $row );
                        }
                    } elseif ( ! $generated ) {
                        // Show most recent brief on page load
                        $recent = CTM_DB::get_briefs( 1 );
                        if ( ! empty( $recent ) ) {
                            $row   = $recent[0];
                            $brief = json_decode( (string) $row->brief_json, true );
                            $this->render_brief_card( is_array( $brief ) ? $brief : array(), $row );
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Brief card ────────────────────────────────────────────────────────────

    private function render_brief_card( $brief, $row ) {
        $e   = fn( $k ) => esc_html( (string) ( $brief[ $k ] ?? '' ) );
        $lnk = fn( $k ) => esc_url( (string)  ( $brief[ $k ] ?? '' ) );

        $videos = array(
            array( 'num'=>'01', 'label'=>'SEO Video',   'section'=>'Commercial', 'accent'=>'#1d4ed8', 'pri'=>'com_seo_priority',
                'fields'=>array('Video Title'=>array('key'=>'com_seo_video_title','style'=>'title'),'Keyword'=>array('key'=>'com_seo_keyword','style'=>'kw'),'Search Intent'=>array('key'=>'com_seo_search_intent'),'Hook'=>array('key'=>'com_seo_hook','style'=>'hook'),'CTA'=>array('key'=>'com_seo_cta','style'=>'cta'),'Difficulty'=>array('key'=>'com_seo_difficulty')),
                'ex_t'=>'com_seo_example_title','ex_u'=>'com_seo_example_url'),
            array( 'num'=>'02', 'label'=>'Offer Video',  'section'=>'Commercial', 'accent'=>'#1d4ed8', 'pri'=>'com_offer_priority',
                'fields'=>array('Video Title'=>array('key'=>'com_offer_video_title','style'=>'title'),'Offer'=>array('key'=>'com_offer_name'),'Audience'=>array('key'=>'com_offer_audience'),'Hook'=>array('key'=>'com_offer_hook','style'=>'hook'),'CTA'=>array('key'=>'com_offer_cta','style'=>'cta')),
                'ex_t'=>'com_offer_example_title','ex_u'=>'com_offer_example_url'),
            array( 'num'=>'03', 'label'=>'Viral Video',  'section'=>'Commercial', 'accent'=>'#1d4ed8', 'pri'=>'com_viral_priority',
                'fields'=>array('Video Title'=>array('key'=>'com_viral_video_title','style'=>'title'),'Trend Format'=>array('key'=>'com_viral_trending_format'),'Concept'=>array('key'=>'com_viral_concept'),'Opening Shot'=>array('key'=>'com_viral_opening_shot'),'Why It Works'=>array('key'=>'com_viral_trend_reason'),'CTA'=>array('key'=>'com_viral_cta','style'=>'cta')),
                'ex_t'=>'com_viral_example_title','ex_u'=>'com_viral_example_url'),
            array( 'num'=>'04', 'label'=>'SEO Video',   'section'=>'Residential', 'accent'=>'#7c3aed', 'pri'=>'res_seo_priority',
                'fields'=>array('Video Title'=>array('key'=>'res_seo_video_title','style'=>'title'),'Keyword'=>array('key'=>'res_seo_keyword','style'=>'kw'),'Search Intent'=>array('key'=>'res_seo_search_intent'),'Hook'=>array('key'=>'res_seo_hook','style'=>'hook'),'CTA'=>array('key'=>'res_seo_cta','style'=>'cta'),'Difficulty'=>array('key'=>'res_seo_difficulty')),
                'ex_t'=>'res_seo_example_title','ex_u'=>'res_seo_example_url'),
            array( 'num'=>'05', 'label'=>'Offer Video', 'section'=>'Residential', 'accent'=>'#7c3aed', 'pri'=>'res_offer_priority',
                'fields'=>array('Video Title'=>array('key'=>'res_offer_video_title','style'=>'title'),'Offer'=>array('key'=>'res_offer_name'),'Audience'=>array('key'=>'res_offer_audience'),'Hook'=>array('key'=>'res_offer_hook','style'=>'hook'),'CTA'=>array('key'=>'res_offer_cta','style'=>'cta')),
                'ex_t'=>'res_offer_example_title','ex_u'=>'res_offer_example_url'),
            array( 'num'=>'06', 'label'=>'Viral Video', 'section'=>'Residential', 'accent'=>'#7c3aed', 'pri'=>'res_viral_priority',
                'fields'=>array('Video Title'=>array('key'=>'res_viral_video_title','style'=>'title'),'Trend Format'=>array('key'=>'res_viral_trending_format'),'Concept'=>array('key'=>'res_viral_concept'),'Opening Shot'=>array('key'=>'res_viral_opening_shot'),'Why It Works'=>array('key'=>'res_viral_trend_reason'),'CTA'=>array('key'=>'res_viral_cta','style'=>'cta')),
                'ex_t'=>'res_viral_example_title','ex_u'=>'res_viral_example_url'),
        );
        $prev_section = '';
        ?>
        <div class="ctm-card" style="padding:0;overflow:hidden;">

            <!-- header -->
            <div style="background:#0f172a;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div>
                    <div style="color:#fff;font-size:15px;font-weight:800;">Midland Floors — Video Brief</div>
                    <div style="color:#475569;font-size:11px;margin-top:2px;"><?php echo $e('brief_date'); ?></div>
                </div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                    <?php wp_nonce_field( 'ctm_send_brief' ); ?>
                    <input type="hidden" name="action"   value="ctm_send_brief">
                    <input type="hidden" name="brief_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
                    <button type="submit" class="button button-primary" style="font-size:12px;">📧 Send Brief</button>
                </form>
            </div>

            <?php foreach ( $videos as $v ) :
                $pri      = (int) ( $brief[ $v['pri'] ] ?? 0 );
                $ex_title = (string) ( $brief[ $v['ex_t'] ] ?? '' );
                $ex_url   = $lnk( $v['ex_u'] );
                $pri_color = $pri >= 8 ? '#16a34a' : ( $pri >= 5 ? '#d97706' : '#94a3b8' );

                // Section separator
                if ( $v['section'] !== $prev_section ) :
                    $prev_section = $v['section'];
                    $sec_bg = 'Commercial' === $v['section'] ? '#1e3a5f' : '#2e1065';
                    $sec_sub = 'Commercial' === $v['section'] ? 'Office Floor Cleaning' : 'Carpet Cleaning & Installation';
                    ?>
                    <div style="background:<?php echo esc_attr( $sec_bg ); ?>;padding:9px 20px;">
                        <span style="color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;">
                            <?php echo 'Commercial' === $v['section'] ? '🏢' : '🏠'; ?> <?php echo esc_html( $v['section'] ); ?>
                        </span>
                        <span style="color:rgba(255,255,255,.5);font-size:11px;margin-left:8px;"><?php echo esc_html( $sec_sub ); ?></span>
                    </div>
                <?php endif; ?>

                <!-- single video row — NO card wrapper -->
                <div style="padding:14px 20px;border-bottom:1px solid #f1f5f9;">

                    <!-- num + label + priority inline -->
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                        <span style="background:<?php echo esc_attr( $v['accent'] ); ?>;color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:3px;"><?php echo esc_html( $v['num'] ); ?></span>
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;"><?php echo esc_html( $v['label'] ); ?></span>
                        <span style="background:<?php echo esc_attr( $pri_color ); ?>;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;"><?php echo esc_html( (string) $pri ); ?>/10</span>
                    </div>

                    <!-- flat field rows -->
                    <?php foreach ( $v['fields'] as $label => $opt ) :
                        $val = (string) ( $brief[ $opt['key'] ] ?? '' );
                        if ( '' === $val ) continue;
                        $style = $opt['style'] ?? '';
                    ?>
                    <div style="display:flex;gap:0;margin-bottom:6px;align-items:flex-start;">
                        <span style="min-width:100px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;padding-top:2px;flex-shrink:0;"><?php echo esc_html( $label ); ?></span>
                        <span style="font-size:13px;line-height:1.5;<?php
                            if ( 'title' === $style ) echo 'font-size:14px;font-weight:700;color:#0f172a;';
                            elseif ( 'hook'  === $style ) echo 'font-style:italic;font-weight:600;color:#0f172a;';
                            elseif ( 'kw'    === $style ) echo 'background:#eff6ff;color:#1d4ed8;font-weight:700;padding:1px 7px;border-radius:4px;font-size:12px;';
                            elseif ( 'cta'   === $style ) echo 'color:#15803d;font-weight:600;';
                            else echo 'color:#374151;';
                        ?>"><?php
                            if ( 'hook' === $style ) echo '&ldquo;' . esc_html( $val ) . '&rdquo;';
                            else echo esc_html( $val );
                        ?></span>
                    </div>
                    <?php endforeach; ?>

                    <!-- example -->
                    <?php if ( $ex_title ) : ?>
                    <div style="margin-top:8px;display:flex;gap:8px;align-items:flex-start;">
                        <span style="min-width:100px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;padding-top:2px;flex-shrink:0;">Example</span>
                        <?php if ( $ex_url ) : ?>
                            <a href="<?php echo $ex_url; ?>" target="_blank" rel="noopener" style="font-size:12px;color:#1d4ed8;text-decoration:underline;line-height:1.4;"><?php echo esc_html( $ex_title ); ?></a>
                        <?php else : ?>
                            <span style="font-size:12px;color:#64748b;font-style:italic;line-height:1.4;"><?php echo esc_html( $ex_title ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>
        <?php
    }

        // ── History ───────────────────────────────────────────────────────────────

    private function render_history() {
        $briefs = CTM_DB::get_briefs( 20 );
        ?>
        <div class="ctm-card">
            <h2><?php esc_html_e( 'Brief History', 'content-traffic-maker' ); ?></h2>
            <?php if ( empty( $briefs ) ) : ?>
                <p class="description"><?php esc_html_e( 'No briefs yet. Click "Generate Video Brief Now" to create your first one.', 'content-traffic-maker' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Date', 'content-traffic-maker' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'content-traffic-maker' ); ?></th>
                        <th><?php esc_html_e( 'Sent to', 'content-traffic-maker' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $briefs as $b ) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'generated' => (int) $b->id ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $b->created_at ); ?></a></td>
                                <td><span class="ctm-badge ctm-badge--<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( $b->status ); ?></span></td>
                                <td><?php echo esc_html( $b->sent_to ?: '—' ); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                                        <?php wp_nonce_field( 'ctm_send_brief' ); ?>
                                        <input type="hidden" name="action"   value="ctm_send_brief">
                                        <input type="hidden" name="brief_id" value="<?php echo esc_attr( (string) $b->id ); ?>">
                                        <button type="submit" class="button button-small">📧 Send</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
