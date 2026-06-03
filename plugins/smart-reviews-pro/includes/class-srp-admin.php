<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SRP_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_test_survey' ) );
    }

    public function add_menu() {
        add_menu_page(
            esc_html__( 'Midland Smart Reviews', 'smart-reviews-pro' ),
            esc_html__( 'Midland Reviews', 'smart-reviews-pro' ),
            'manage_options',
            'smart-reviews-pro',
            array( $this, 'render_dashboard' ),
            'dashicons-star-filled',
            58
        );
        add_submenu_page( 'smart-reviews-pro', esc_html__( 'Dashboard', 'smart-reviews-pro' ), esc_html__( 'Dashboard', 'smart-reviews-pro' ), 'manage_options', 'smart-reviews-pro', array( $this, 'render_dashboard' ) );
        add_submenu_page( 'smart-reviews-pro', esc_html__( 'Settings', 'smart-reviews-pro' ), esc_html__( 'Settings', 'smart-reviews-pro' ), 'manage_options', 'srp-settings', array( $this, 'render_settings' ) );
        add_submenu_page( 'smart-reviews-pro', esc_html__( 'All Responses', 'smart-reviews-pro' ), esc_html__( 'All Responses', 'smart-reviews-pro' ), 'manage_options', 'srp-responses', array( $this, 'render_responses' ) );
    }

    public function handle_save_settings() {
        if ( ! isset( $_POST['srp_save_settings'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_srp_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_srp_settings_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'srp_save_settings' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-reviews-pro' ) );
        }

        update_option( 'srp_gmb_review_url', esc_url_raw( wp_unslash( $_POST['gmb_review_url'] ?? '' ) ) );
        update_option( 'srp_threshold',      min( SRP_Survey::MAX_SCORE, max( 1, absint( $_POST['score_threshold'] ?? SRP_Survey::THRESHOLD ) ) ) );
        update_option( 'srp_owner_email',    sanitize_email( wp_unslash( $_POST['owner_email'] ?? '' ) ) );

        // Branding (mirrors Midland Chat: business name / brand color / logo).
        update_option( 'srp_business_name', sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ) );
        update_option( 'srp_brand_color',   sanitize_hex_color( wp_unslash( $_POST['brand_color'] ?? '' ) ) ?: SRP_Survey::DEFAULT_COLOR );
        update_option( 'srp_logo_url',      esc_url_raw( wp_unslash( $_POST['logo_url'] ?? '' ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=srp-settings&saved=1' ) );
        exit;
    }

    public function handle_test_survey() {
        if ( ! isset( $_POST['srp_test_survey'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_srp_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_srp_settings_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'srp_save_settings' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-reviews-pro' ) );
        }

        $test_email = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
        if ( ! is_email( $test_email ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=srp-settings&test=invalid' ) );
            exit;
        }

        do_action( 'srp_job_completed', array(
            'name'   => 'Test Customer',
            'email'  => $test_email,
            'phone'  => '',
            'job_id' => 'TEST-' . time(),
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=srp-settings&test=sent' ) );
        exit;
    }

    public function render_dashboard() {
        global $wpdb;

        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}srp_surveys" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $responded = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}srp_surveys WHERE score IS NOT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $avg_score = $responded ? round( (float) $wpdb->get_var( "SELECT AVG(score) FROM {$wpdb->prefix}srp_surveys WHERE score IS NOT NULL" ), 1 ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $routed_gmb = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}srp_surveys WHERE route_type = 'gmb'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $response_rate = $total > 0 ? round( ( $responded / $total ) * 100 ) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart Reviews Pro', 'smart-reviews-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Survey-gated review collection. Only happy customers get the GMB review link — protecting your rating while gathering private feedback from everyone else.', 'smart-reviews-pro' ); ?></p>

            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:24px 0;">
                <?php
                $stats = array(
                    array( 'Surveys Sent', $total, '#3b82f6' ),
                    array( 'Response Rate', $response_rate . '%', '#8b5cf6' ),
                    array( 'Avg Score', $avg_score . ' / 10', '#f59e0b' ),
                    array( 'GMB Review Requests', $routed_gmb, '#22c55e' ),
                );
                foreach ( $stats as $s ) :
                ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:<?php echo esc_attr( $s[2] ); ?>;"><?php echo esc_html( $s[1] ); ?></div>
                        <div style="font-size:13px;color:#6b7280;margin-top:4px;"><?php echo esc_html( $s[0] ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2><?php esc_html_e( 'Recent Responses', 'smart-reviews-pro' ); ?></h2>
            <?php
            $recent = SRP_DB::get_surveys( array( 'limit' => 10 ) );
            if ( $recent ) : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Customer', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Score', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Route', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Feedback', 'smart-reviews-pro' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'smart-reviews-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent as $row ) :
                            $score_null = null === $row->score || '' === $row->score;
                            $score_color = $score_null ? '#999' : ( $row->score >= 4 ? '#22c55e' : ( $row->score >= 3 ? '#f59e0b' : '#ef4444' ) );
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $row->customer_name ); ?></strong><br>
                                    <small><?php echo esc_html( $row->customer_email ); ?></small>
                                </td>
                                <td>
                                    <?php if ( $score_null ) : ?>
                                        <span style="color:#999;"><?php esc_html_e( 'No response', 'smart-reviews-pro' ); ?></span>
                                    <?php else : ?>
                                        <strong style="color:<?php echo esc_attr( $score_color ); ?>;font-size:18px;"><?php echo esc_html( $row->score ); ?></strong><span style="color:#999;">/5&#9733;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( 'gmb' === $row->route_type ) : ?>
                                        <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:12px;">GMB sent</span>
                                    <?php elseif ( 'private' === $row->route_type ) : ?>
                                        <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:12px;">Private</span>
                                    <?php else : ?>
                                        <span style="color:#999;font-size:12px;">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;color:#555;"><?php echo esc_html( SRP_Survey::safe_truncate( $row->feedback ?? '', 100 ) ); ?></td>
                                <td style="font-size:12px;color:#999;"><?php echo esc_html( substr( $row->created_at, 0, 10 ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e( 'No surveys sent yet. Fire srp_job_completed action or send a test from Settings.', 'smart-reviews-pro' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_settings() {
        // Pre-fill the review URL with the verified Midland link when none is set,
        // so review routing works out of the box.
        $gmb_url    = SRP_Survey::review_url();
        $threshold  = (int) get_option( 'srp_threshold', SRP_Survey::THRESHOLD );
        if ( $threshold < 1 || $threshold > SRP_Survey::MAX_SCORE ) { $threshold = SRP_Survey::THRESHOLD; } // heal a stale 0–10 value
        $owner_email = get_option( 'srp_owner_email', get_option( 'admin_email' ) );

        // Branding.
        $business_name = SRP_Survey::business_name();
        $brand_color   = SRP_Survey::brand_color();
        $logo_url      = SRP_Survey::logo_url();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved = isset( $_GET['saved'] );
        $test  = isset( $_GET['test'] ) ? sanitize_key( $_GET['test'] ) : '';
        // phpcs:enable
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart Reviews — Settings', 'smart-reviews-pro' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-reviews-pro' ); ?></p></div>
            <?php endif; ?>
            <?php if ( 'sent' === $test ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test survey email sent. Check your inbox.', 'smart-reviews-pro' ); ?></p></div>
            <?php elseif ( 'invalid' === $test ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Invalid test email address.', 'smart-reviews-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'srp_save_settings', '_srp_settings_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="business_name"><?php esc_html_e( 'Business Name', 'smart-reviews-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="business_name" name="business_name" class="regular-text" value="<?php echo esc_attr( $business_name ); ?>" placeholder="Midland Floors">
                            <p class="description"><?php esc_html_e( 'Shown in survey/review emails and subject lines. Set this to your brand name — not the long SEO site title.', 'smart-reviews-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="brand_color"><?php esc_html_e( 'Brand Color', 'smart-reviews-pro' ); ?></label></th>
                        <td>
                            <input type="color" id="brand_color" name="brand_color" value="<?php echo esc_attr( $brand_color ); ?>">
                            <p class="description"><?php esc_html_e( 'Used for email headers, buttons, and the review link. Default: Midland green #43A94B.', 'smart-reviews-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="logo_url"><?php esc_html_e( 'Logo URL', 'smart-reviews-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="logo_url" name="logo_url" class="large-text" value="<?php echo esc_attr( $logo_url ); ?>" placeholder="https://yoursite.com/wp-content/uploads/logo.png">
                            <p class="description"><?php esc_html_e( 'Your logo, shown on a white header in emails. Must be hosted on a public URL (e.g. your Media Library) — private repo links will not load in inboxes.', 'smart-reviews-pro' ); ?></p>
                            <?php if ( $logo_url ) : ?>
                                <p><img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:48px;background:#fff;border:1px solid #e5e7eb;padding:8px 12px;border-radius:6px;"></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gmb_review_url"><?php esc_html_e( 'Google Review URL', 'smart-reviews-pro' ); ?></label></th>
                        <td>
                            <input type="url" id="gmb_review_url" name="gmb_review_url" class="large-text" value="<?php echo esc_attr( $gmb_url ); ?>" placeholder="https://search.google.com/local/writereview?placeid=...">
                            <p class="description"><?php esc_html_e( 'Pre-filled with the verified Midland Google review link. Customers scoring ≥ threshold are sent here. Save to confirm.', 'smart-reviews-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="score_threshold"><?php esc_html_e( 'Review Request Threshold', 'smart-reviews-pro' ); ?></label></th>
                        <td>
                            <input type="number" id="score_threshold" name="score_threshold" value="<?php echo esc_attr( $threshold ); ?>" min="1" max="5" style="width:70px;">
                            <span class="description"><?php esc_html_e( 'Star rating ≥ this number → Google review link sent; below it → manager is emailed. 1–5 scale, default: 4', 'smart-reviews-pro' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="owner_email"><?php esc_html_e( 'Owner Notification Email', 'smart-reviews-pro' ); ?></label></th>
                        <td>
                            <input type="email" id="owner_email" name="owner_email" class="regular-text" value="<?php echo esc_attr( $owner_email ); ?>">
                            <p class="description"><?php esc_html_e( 'Receives notification when a low score comes in.', 'smart-reviews-pro' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="srp_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'smart-reviews-pro' ); ?></button></p>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Send Test Survey', 'smart-reviews-pro' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Fires srp_job_completed with a test payload — sends a real survey email so you can preview the experience.', 'smart-reviews-pro' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'srp_save_settings', '_srp_settings_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="test_email"><?php esc_html_e( 'Send Test To', 'smart-reviews-pro' ); ?></label></th>
                        <td><input type="email" id="test_email" name="test_email" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="srp_test_survey" value="1" class="button"><?php esc_html_e( 'Send Test Survey', 'smart-reviews-pro' ); ?></button></p>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Integration: Trigger a Survey from Code', 'smart-reviews-pro' ); ?></h2>
            <p><?php esc_html_e( 'Fire this action anywhere in your codebase when a job is marked complete:', 'smart-reviews-pro' ); ?></p>
            <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;font-size:13px;">do_action( 'srp_job_completed', array(
    'name'   => $customer_name,
    'email'  => $customer_email,
    'phone'  => $customer_phone,
    'job_id' => $job_id,
) );</pre>
        </div>
        <?php
    }

    public function render_responses() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';

        $args = array( 'limit' => 100 );
        if ( 'gmb' === $filter ) {
            $args['min_score'] = (int) get_option( 'srp_threshold', SRP_Survey::THRESHOLD );
        } elseif ( 'private' === $filter ) {
            $args['max_score'] = (int) get_option( 'srp_threshold', SRP_Survey::THRESHOLD ) - 1;
        } elseif ( 'pending' === $filter ) {
            // Only surveys without a score.
        }

        $responses = SRP_DB::get_surveys( $args );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'All Survey Responses', 'smart-reviews-pro' ); ?></h1>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=srp-responses&filter=all' ) ); ?>" <?php echo 'all' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'All', 'smart-reviews-pro' ); ?></a> |</li>
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=srp-responses&filter=gmb' ) ); ?>" <?php echo 'gmb' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'GMB routed (happy)', 'smart-reviews-pro' ); ?></a> |</li>
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=srp-responses&filter=private' ) ); ?>" <?php echo 'private' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Private feedback', 'smart-reviews-pro' ); ?></a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Customer', 'smart-reviews-pro' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Score', 'smart-reviews-pro' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Route', 'smart-reviews-pro' ); ?></th>
                        <th><?php esc_html_e( 'Feedback', 'smart-reviews-pro' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Responded', 'smart-reviews-pro' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $responses ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No responses yet.', 'smart-reviews-pro' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $responses as $row ) :
                            $score_null = null === $row->score || '' === $row->score;
                            $score_color = $score_null ? '#999' : ( $row->score >= 4 ? '#22c55e' : ( $row->score >= 3 ? '#f59e0b' : '#ef4444' ) );
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $row->customer_name ); ?></strong><br>
                                    <a href="mailto:<?php echo esc_attr( $row->customer_email ); ?>"><?php echo esc_html( $row->customer_email ); ?></a>
                                    <?php if ( $row->job_id ) : ?>
                                        <br><small style="color:#999;">Job: <?php echo esc_html( $row->job_id ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $score_null ) : ?>
                                        <span style="color:#999;">—</span>
                                    <?php else : ?>
                                        <strong style="color:<?php echo esc_attr( $score_color ); ?>;font-size:20px;"><?php echo esc_html( $row->score ); ?></strong><span style="color:#999;font-size:13px;">/5&#9733;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( 'gmb' === $row->route_type ) : ?>
                                        <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:12px;">&#11088; GMB</span>
                                    <?php elseif ( 'private' === $row->route_type ) : ?>
                                        <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:12px;">Private</span>
                                    <?php else : ?>
                                        <span style="color:#aaa;font-size:12px;">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:13px;"><?php echo esc_html( $row->feedback ?? '' ); ?></td>
                                <td style="font-size:12px;color:#999;"><?php echo esc_html( $row->responded_at ? substr( $row->responded_at, 0, 10 ) : '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

SRP_Admin::get_instance();
