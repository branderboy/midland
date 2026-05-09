<?php
/**
 * Plugin Name:       Smart Intel
 * Plugin URI:        https://midland.local/smart-intel
 * Description:       Weekly marketing intel plays for the manager — what GMB / FB ads to run, what video clips to distribute, where keywords + zip codes are underperforming, and which local backlinks to chase. Plays generated from your vertical and target zips via Perplexity.
 * Version:           1.0.0
 * Author:            Midland
 * Author URI:        https://midland.local
 * License:           GPL-2.0+
 * Text Domain:       smart-intel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SINT_VERSION', '1.0.0' );
define( 'SINT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SINT_URL', plugin_dir_url( __FILE__ ) );

class Smart_Intel {

    const OPT_VERTICAL    = 'sint_vertical';
    const OPT_CITY        = 'sint_city';
    const OPT_STATE       = 'sint_state';
    const OPT_ZIPS        = 'sint_zips';
    const OPT_MANAGER     = 'sint_manager_email';
    const OPT_COMPETITORS = 'sint_competitors';
    const OPT_PLAYS       = 'sint_plays';
    const OPT_LAST_RUN    = 'sint_last_run';
    const CRON_HOOK       = 'sint_weekly_run';

    // Reuse Perplexity API key already stored by Smart Chat AI.
    const PPLX_KEY_OPTION = 'scai_perplexity_api_key';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',          array( $this, 'add_menu' ) );
        add_action( 'admin_init',          array( $this, 'handle_save' ) );
        add_action( 'admin_post_sint_generate', array( $this, 'handle_generate' ) );
        add_action( 'admin_post_sint_status',   array( $this, 'handle_status_change' ) );
        add_action( self::CRON_HOOK,       array( $this, 'cron_run' ) );

        register_activation_hook( __FILE__,   array( __CLASS__, 'on_activate' ) );
        register_deactivation_hook( __FILE__, array( __CLASS__, 'on_deactivate' ) );
    }

    public static function on_activate() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Mondays at 7am site time.
            wp_schedule_event( strtotime( 'next monday 7:00am' ), 'weekly', self::CRON_HOOK );
        }
    }

    public static function on_deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ------------------------------------------------------------------ */
    /* Admin UI                                                           */
    /* ------------------------------------------------------------------ */

    public function add_menu() {
        add_menu_page(
            esc_html__( 'Smart Intel', 'smart-intel' ),
            esc_html__( 'Smart Intel', 'smart-intel' ),
            'manage_options',
            'smart-intel',
            array( $this, 'render_dashboard' ),
            'dashicons-chart-line',
            58
        );
        add_submenu_page(
            'smart-intel',
            esc_html__( 'Plays', 'smart-intel' ),
            esc_html__( 'Plays', 'smart-intel' ),
            'manage_options',
            'smart-intel',
            array( $this, 'render_dashboard' )
        );
        add_submenu_page(
            'smart-intel',
            esc_html__( 'Settings', 'smart-intel' ),
            esc_html__( 'Settings', 'smart-intel' ),
            'manage_options',
            'smart-intel-settings',
            array( $this, 'render_settings' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['sint_save_settings'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_sint_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sint_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sint_save_settings' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-intel' ) );
        }

        update_option( self::OPT_VERTICAL,    isset( $_POST['vertical'] )    ? sanitize_text_field( wp_unslash( $_POST['vertical'] ) )    : '' );
        update_option( self::OPT_CITY,        isset( $_POST['city'] )        ? sanitize_text_field( wp_unslash( $_POST['city'] ) )        : '' );
        update_option( self::OPT_STATE,       isset( $_POST['state'] )       ? sanitize_text_field( wp_unslash( $_POST['state'] ) )       : '' );
        update_option( self::OPT_ZIPS,        isset( $_POST['zips'] )        ? sanitize_textarea_field( wp_unslash( $_POST['zips'] ) )    : '' );
        update_option( self::OPT_COMPETITORS, isset( $_POST['competitors'] ) ? sanitize_textarea_field( wp_unslash( $_POST['competitors'] ) ) : '' );
        update_option( self::OPT_MANAGER,     isset( $_POST['manager_email'] ) ? sanitize_email( wp_unslash( $_POST['manager_email'] ) ) : '' );

        wp_safe_redirect( admin_url( 'admin.php?page=smart-intel-settings&saved=1' ) );
        exit;
    }

    public function handle_generate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'no' );
        }
        check_admin_referer( 'sint_generate' );
        $this->generate_plays();
        wp_safe_redirect( admin_url( 'admin.php?page=smart-intel&generated=1' ) );
        exit;
    }

    public function handle_status_change() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'no' );
        }
        check_admin_referer( 'sint_status' );

        $play_id = isset( $_POST['play_id'] ) ? sanitize_text_field( wp_unslash( $_POST['play_id'] ) ) : '';
        $status  = isset( $_POST['status'] )  ? sanitize_key( $_POST['status'] ) : '';
        if ( ! in_array( $status, array( 'open', 'shipped', 'skipped' ), true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=smart-intel' ) );
            exit;
        }

        $plays = (array) get_option( self::OPT_PLAYS, array() );
        foreach ( $plays as &$p ) {
            if ( ! empty( $p['id'] ) && $p['id'] === $play_id ) {
                $p['status']     = $status;
                $p['updated_at'] = time();
                break;
            }
        }
        unset( $p );
        update_option( self::OPT_PLAYS, $plays );

        wp_safe_redirect( admin_url( 'admin.php?page=smart-intel#' . $play_id ) );
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Plays generation                                                   */
    /* ------------------------------------------------------------------ */

    public function cron_run() {
        $plays = $this->generate_plays();
        $this->email_manager( $plays );
    }

    /**
     * Build prompt context once, then ask Perplexity for each category.
     * Each category is a separate call so a single failure doesn't lose
     * the whole batch and so token budgets stay tight per response.
     */
    public function generate_plays() {
        $key = (string) get_option( self::PPLX_KEY_OPTION, '' );
        if ( '' === $key ) {
            update_option( self::OPT_LAST_RUN, array(
                'at'    => time(),
                'error' => 'No Perplexity key configured in Smart Chat AI settings.',
            ) );
            return array();
        }

        $context = $this->build_context();
        if ( '' === trim( $context['vertical'] ) ) {
            update_option( self::OPT_LAST_RUN, array(
                'at'    => time(),
                'error' => 'Vertical not set. Configure it under Smart Intel > Settings.',
            ) );
            return array();
        }

        $categories = array(
            'ads_gmb'   => 'Google Business Profile (GBP) ads — keyword themes, ad copy angles, audience or radius adjustments. 3 specific plays.',
            'ads_fb'    => 'Facebook / Meta ads — creative angles, audience targets, lead-form vs landing-page recs, daily budget guidance. 3 specific plays.',
            'videos'    => 'Short-form video topics for a clipping campaign (Reels / TikTok / Shorts). Each play = a single shoot list / hook / on-camera CTA. 4 plays.',
            'keywords'  => 'Underperforming keywords + zip codes to attack this week. Each play = (keyword, zip, why it matters, what to do — page, GBP post, ad, citation). 4 plays.',
            'backlinks' => 'Local backlink targets — specific named directories, partner orgs, sponsorships, news angles for the city/state. Each play = (target, angle, who to email, expected DR/value). 4 plays.',
        );

        $existing = (array) get_option( self::OPT_PLAYS, array() );

        // Keep shipped/skipped plays so the manager's history doesn't reset.
        $kept = array_filter( $existing, function( $p ) {
            return ! empty( $p['status'] ) && in_array( $p['status'], array( 'shipped', 'skipped' ), true );
        } );

        $new_plays = array();
        foreach ( $categories as $cat => $instruction ) {
            $items = $this->ask_perplexity( $key, $context, $cat, $instruction );
            foreach ( $items as $item ) {
                $new_plays[] = array(
                    'id'           => 'p_' . wp_generate_uuid4(),
                    'category'     => $cat,
                    'title'        => $item['title'],
                    'body'         => $item['body'],
                    'status'       => 'open',
                    'generated_at' => time(),
                    'updated_at'   => time(),
                );
            }
        }

        $plays = array_values( array_merge( $new_plays, $kept ) );
        update_option( self::OPT_PLAYS, $plays );
        update_option( self::OPT_LAST_RUN, array(
            'at'    => time(),
            'count' => count( $new_plays ),
        ) );

        return $new_plays;
    }

    private function build_context() {
        return array(
            'vertical'    => (string) get_option( self::OPT_VERTICAL, '' ),
            'city'        => (string) get_option( self::OPT_CITY, '' ),
            'state'       => (string) get_option( self::OPT_STATE, '' ),
            'zips'        => array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) get_option( self::OPT_ZIPS, '' ) ) ) ),
            'competitors' => array_filter( array_map( 'trim', preg_split( "/[\r\n]+/", (string) get_option( self::OPT_COMPETITORS, '' ) ) ) ),
            'site'        => home_url( '/' ),
            'business'    => get_bloginfo( 'name' ),
        );
    }

    /**
     * Ask Perplexity for plays in one category. We force a JSON response so
     * we can parse it; if the model wraps the JSON in prose we still pull
     * the JSON out with a regex fallback.
     */
    private function ask_perplexity( $key, array $ctx, $category, $instruction ) {
        $zips_line = empty( $ctx['zips'] ) ? '(no zips configured)' : implode( ', ', array_slice( $ctx['zips'], 0, 25 ) );
        $comps     = empty( $ctx['competitors'] ) ? '(none provided)' : implode( '; ', array_slice( $ctx['competitors'], 0, 10 ) );

        $system = "You are a marketing strategist generating concrete, actionable weekly plays for a local-services business. "
                . "Output STRICT JSON ONLY — no prose, no markdown fences. Schema: "
                . '{"plays":[{"title":"<short imperative title, max 80 chars>","body":"<2-4 sentences with the actual play details, named entities, channels, numbers, and exact next step>"}]}'
                . " Each play must be specific (named tools/keywords/orgs/zip codes), not generic best-practice fluff.";

        $user = "Business: {$ctx['business']} ({$ctx['site']})\n"
              . "Vertical / what we do: {$ctx['vertical']}\n"
              . "Service area: {$ctx['city']}, {$ctx['state']}\n"
              . "Target zip codes: {$zips_line}\n"
              . "Known competitors: {$comps}\n\n"
              . "Generate this week's plays for the following category:\n"
              . "Category: {$category}\n"
              . "Instruction: {$instruction}\n\n"
              . "Use current/recent local context (search-grounded). Return JSON only.";

        $body = array(
            'model'       => 'sonar',
            'messages'    => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user',   'content' => $user ),
            ),
            'temperature' => 0.4,
        );

        $response = wp_remote_post( 'https://api.perplexity.ai/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[smart-intel] perplexity ' . $category . ' error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return array();
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            error_log( '[smart-intel] perplexity ' . $category . ' http ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return array();
        }

        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( '' === $content ) {
            return array();
        }

        // Pull JSON out — model usually returns clean JSON, but be tolerant.
        $json = $content;
        if ( preg_match( '/\{.*\}/s', $content, $m ) ) {
            $json = $m[0];
        }
        $parsed = json_decode( $json, true );
        $items  = $parsed['plays'] ?? array();

        $out = array();
        foreach ( $items as $it ) {
            $title = (string) ( $it['title'] ?? '' );
            $body  = (string) ( $it['body']  ?? '' );
            if ( '' === $title || '' === $body ) {
                continue;
            }
            $out[] = array(
                'title' => sanitize_text_field( $title ),
                'body'  => sanitize_textarea_field( $body ),
            );
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Manager email                                                      */
    /* ------------------------------------------------------------------ */

    private function email_manager( $plays ) {
        $to = (string) get_option( self::OPT_MANAGER, '' );
        if ( ! is_email( $to ) || empty( $plays ) ) {
            return;
        }

        $by_cat = array();
        foreach ( $plays as $p ) {
            $by_cat[ $p['category'] ][] = $p;
        }

        $labels = $this->category_labels();

        $lines   = array();
        $lines[] = 'This week\'s Smart Intel plays for ' . get_bloginfo( 'name' ) . '.';
        $lines[] = '';
        foreach ( $labels as $cat => $label ) {
            if ( empty( $by_cat[ $cat ] ) ) {
                continue;
            }
            $lines[] = '== ' . $label . ' ==';
            foreach ( $by_cat[ $cat ] as $p ) {
                $lines[] = '- ' . $p['title'];
                $lines[] = '  ' . $p['body'];
            }
            $lines[] = '';
        }
        $lines[] = 'Open the dashboard: ' . admin_url( 'admin.php?page=smart-intel' );

        wp_mail(
            $to,
            sprintf( '[%s] Smart Intel — weekly plays', get_bloginfo( 'name' ) ),
            implode( "\n", $lines )
        );
    }

    private function category_labels() {
        return array(
            'ads_gmb'   => 'GBP / Google Ads',
            'ads_fb'    => 'Facebook / Meta Ads',
            'videos'    => 'Video Clipping Campaign',
            'keywords'  => 'Underperforming Keywords + Zip Codes',
            'backlinks' => 'Local Backlinks to Chase',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Dashboard                                                          */
    /* ------------------------------------------------------------------ */

    public function render_dashboard() {
        $plays    = (array) get_option( self::OPT_PLAYS, array() );
        $last_run = (array) get_option( self::OPT_LAST_RUN, array() );
        $labels   = $this->category_labels();

        $by_cat = array_fill_keys( array_keys( $labels ), array() );
        foreach ( $plays as $p ) {
            if ( isset( $by_cat[ $p['category'] ] ) ) {
                $by_cat[ $p['category'] ][] = $p;
            }
        }

        $key_set = '' !== (string) get_option( self::PPLX_KEY_OPTION, '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $generated = isset( $_GET['generated'] );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:14px;">
                <?php esc_html_e( 'Smart Intel — Weekly Plays', 'smart-intel' ); ?>
                <span style="font-size:13px;color:#6b7280;font-weight:400;"><?php esc_html_e( 'by Midland', 'smart-intel' ); ?></span>
            </h1>

            <?php if ( ! $key_set ) : ?>
                <div class="notice notice-warning"><p>
                    <?php
                    /* translators: %s: link to Smart Chat AI settings */
                    printf(
                        esc_html__( 'Perplexity API key not set. Configure it in %s and come back.', 'smart-intel' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=smart-chat-ai-settings' ) ) . '">' . esc_html__( 'Smart Chat AI settings', 'smart-intel' ) . '</a>'
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if ( $generated ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Plays generated.', 'smart-intel' ); ?></p></div>
            <?php endif; ?>

            <?php if ( ! empty( $last_run['error'] ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $last_run['error'] ); ?></p></div>
            <?php endif; ?>

            <p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'sint_generate' ); ?>
                    <input type="hidden" name="action" value="sint_generate">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Generate Plays Now', 'smart-intel' ); ?>
                    </button>
                </form>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-intel-settings' ) ); ?>" class="button"><?php esc_html_e( 'Settings', 'smart-intel' ); ?></a>
                <?php if ( ! empty( $last_run['at'] ) ) : ?>
                    <span style="margin-left:10px;color:#6b7280;font-size:13px;">
                        <?php
                        /* translators: %s: relative time ("2 hours ago") */
                        printf( esc_html__( 'Last run: %s', 'smart-intel' ), esc_html( human_time_diff( (int) $last_run['at'] ) . ' ago' ) );
                        ?>
                    </span>
                <?php endif; ?>
            </p>

            <?php if ( empty( $plays ) ) : ?>
                <div style="background:#f9fafb;border:1px dashed #d1d5db;padding:30px;text-align:center;border-radius:8px;margin-top:20px;">
                    <p style="margin:0;color:#6b7280;"><?php esc_html_e( 'No plays yet. Set your vertical and zip codes in Settings, then click Generate.', 'smart-intel' ); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ( $labels as $cat => $label ) :
                    $items = $by_cat[ $cat ];
                    if ( empty( $items ) ) {
                        continue;
                    }
                ?>
                    <h2 style="margin-top:32px;"><?php echo esc_html( $label ); ?></h2>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px;">
                        <?php foreach ( $items as $p ) :
                            $status = $p['status'] ?? 'open';
                            $border = 'open' === $status ? '#3b82f6' : ( 'shipped' === $status ? '#10b981' : '#9ca3af' );
                            $bg     = 'open' === $status ? '#eff6ff' : ( 'shipped' === $status ? '#ecfdf5' : '#f3f4f6' );
                        ?>
                            <div id="<?php echo esc_attr( $p['id'] ); ?>" style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid <?php echo esc_attr( $border ); ?>;border-radius:8px;padding:16px;">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                                    <strong style="font-size:14px;line-height:1.35;"><?php echo esc_html( $p['title'] ); ?></strong>
                                    <span style="background:<?php echo esc_attr( $bg ); ?>;color:#1f2937;font-size:11px;text-transform:uppercase;letter-spacing:0.06em;padding:3px 8px;border-radius:10px;"><?php echo esc_html( $status ); ?></span>
                                </div>
                                <p style="color:#374151;font-size:13px;margin:10px 0 12px;line-height:1.5;"><?php echo esc_html( $p['body'] ); ?></p>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;">
                                    <?php wp_nonce_field( 'sint_status' ); ?>
                                    <input type="hidden" name="action" value="sint_status">
                                    <input type="hidden" name="play_id" value="<?php echo esc_attr( $p['id'] ); ?>">
                                    <?php foreach ( array( 'open' => 'Open', 'shipped' => 'Shipped', 'skipped' => 'Skip' ) as $s => $lbl ) : ?>
                                        <button type="submit" name="status" value="<?php echo esc_attr( $s ); ?>" class="button<?php echo $s === $status ? ' button-primary' : ''; ?>" style="padding:2px 10px;font-size:12px;height:auto;line-height:1.6;">
                                            <?php echo esc_html( $lbl ); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_settings() {
        $vertical    = (string) get_option( self::OPT_VERTICAL, '' );
        $city        = (string) get_option( self::OPT_CITY, '' );
        $state       = (string) get_option( self::OPT_STATE, '' );
        $zips        = (string) get_option( self::OPT_ZIPS, '' );
        $competitors = (string) get_option( self::OPT_COMPETITORS, '' );
        $manager     = (string) get_option( self::OPT_MANAGER, '' );
        $key_set     = '' !== (string) get_option( self::PPLX_KEY_OPTION, '' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved       = isset( $_GET['saved'] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart Intel — Settings', 'smart-intel' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-intel' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'sint_save_settings', '_sint_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="vertical"><?php esc_html_e( 'Vertical', 'smart-intel' ); ?></label></th>
                        <td>
                            <input type="text" name="vertical" id="vertical" class="regular-text" value="<?php echo esc_attr( $vertical ); ?>" placeholder="commercial floor care &amp; emergency restoration">
                            <p class="description"><?php esc_html_e( 'A one-line description of what you do. The plays generator uses this as the lead context.', 'smart-intel' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="city"><?php esc_html_e( 'Primary City', 'smart-intel' ); ?></label></th>
                        <td>
                            <input type="text" name="city" id="city" class="regular-text" value="<?php echo esc_attr( $city ); ?>" placeholder="Phoenix">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="state"><?php esc_html_e( 'State', 'smart-intel' ); ?></label></th>
                        <td>
                            <input type="text" name="state" id="state" value="<?php echo esc_attr( $state ); ?>" placeholder="AZ" maxlength="3" style="width:80px;">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="zips"><?php esc_html_e( 'Target Zip Codes', 'smart-intel' ); ?></label></th>
                        <td>
                            <textarea name="zips" id="zips" rows="3" class="large-text"><?php echo esc_textarea( $zips ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Comma- or newline-separated. Used by the keywords/zips and ads plays.', 'smart-intel' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="competitors"><?php esc_html_e( 'Known Competitors', 'smart-intel' ); ?></label></th>
                        <td>
                            <textarea name="competitors" id="competitors" rows="4" class="large-text" placeholder="Competitor name, URL — one per line"><?php echo esc_textarea( $competitors ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Optional. Helps the AI find gaps to attack.', 'smart-intel' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="manager_email"><?php esc_html_e( 'Manager Email', 'smart-intel' ); ?></label></th>
                        <td>
                            <input type="email" name="manager_email" id="manager_email" class="regular-text" value="<?php echo esc_attr( $manager ); ?>">
                            <p class="description"><?php esc_html_e( 'Weekly plays digest is mailed here every Monday morning.', 'smart-intel' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Perplexity API Key', 'smart-intel' ); ?></th>
                        <td>
                            <?php if ( $key_set ) : ?>
                                <span style="color:#10b981;">&#10003;</span> <?php esc_html_e( 'Configured (from Smart Chat AI).', 'smart-intel' ); ?>
                            <?php else : ?>
                                <span style="color:#b91c1c;">&#10007;</span>
                                <?php
                                printf(
                                    /* translators: %s: link to Smart Chat AI settings */
                                    esc_html__( 'Not set. Add it in %s.', 'smart-intel' ),
                                    '<a href="' . esc_url( admin_url( 'admin.php?page=smart-chat-ai-settings' ) ) . '">' . esc_html__( 'Smart Chat AI settings', 'smart-intel' ) . '</a>'
                                );
                                ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="sint_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'smart-intel' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}

Smart_Intel::get_instance();
