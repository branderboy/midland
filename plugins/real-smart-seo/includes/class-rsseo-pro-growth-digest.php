<?php
/**
 * Growth Digest — a recurring AI-generated email to the owner with this
 * period's local-SEO action list:
 *
 *   1. Guest posts to pitch for more referral/organic traffic.
 *   2. Local backlinks to pursue — explicitly including a .gov and a
 *      nonprofit target each send.
 *   3. TikTok + YouTube video ideas optimized for search (SEO).
 *   4. Video ideas designed to go viral.
 *
 * Sends weekly (Monday morning) or daily, to a configurable recipient. Uses
 * the free base plugin's Claude API wrapper for generation. Disabled until a
 * recipient is set and the toggle is on, so nothing goes out unexpectedly.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Pro_Growth_Digest {

    const CRON_HOOK   = 'rsseo_growth_digest_send';
    const OPT_ENABLED = 'rsseo_growth_digest_enabled';
    const OPT_EMAIL   = 'rsseo_growth_digest_email';
    const OPT_FREQ    = 'rsseo_growth_digest_freq';    // 'weekly' | 'daily'
    const OPT_CONTEXT = 'rsseo_growth_digest_context'; // business description for the AI

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK,        array( $this, 'run_digest' ) );
        add_action( 'admin_init',           array( $this, 'maybe_schedule_cron' ) );
        add_action( 'admin_menu',           array( $this, 'add_menu' ), 31 );
        add_action( 'admin_init',           array( $this, 'handle_save' ) );
        add_action( 'admin_post_rsseo_growth_digest_send_now', array( $this, 'handle_send_now' ) );
    }

    // ── Scheduling ────────────────────────────────────────────────────────────

    /**
     * Keep the cron event in sync with the enabled flag + frequency. Clears a
     * stale schedule when disabled or when the frequency changed.
     */
    public function maybe_schedule_cron() {
        $enabled = (int) get_option( self::OPT_ENABLED, 0 );
        $next    = wp_next_scheduled( self::CRON_HOOK );

        if ( ! $enabled ) {
            if ( $next ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }
            return;
        }

        $freq = $this->get_frequency();
        // If something is scheduled on the wrong recurrence, reset it.
        if ( $next ) {
            $event = wp_get_scheduled_event( self::CRON_HOOK );
            if ( $event && isset( $event->schedule ) && $event->schedule !== $freq ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
                $next = false;
            }
        }
        if ( ! $next ) {
            wp_schedule_event( $this->first_run_timestamp( $freq ), $freq, self::CRON_HOOK );
        }
    }

    /** Next Monday 08:00 site-time for weekly; tomorrow 08:00 for daily. */
    private function first_run_timestamp( $freq ) {
        $now      = current_time( 'timestamp' );
        $eight_am = strtotime( 'today 08:00', $now );
        if ( 'daily' === $freq ) {
            $ts = ( $eight_am > $now ) ? $eight_am : strtotime( 'tomorrow 08:00', $now );
        } else {
            $ts = strtotime( 'next monday 08:00', $now );
        }
        // Convert site-time target back to a UTC timestamp for cron.
        return $ts - ( (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
    }

    private function get_frequency() {
        return 'daily' === get_option( self::OPT_FREQ, 'weekly' ) ? 'daily' : 'weekly';
    }

    private function get_recipient() {
        $email = sanitize_email( (string) get_option( self::OPT_EMAIL, '' ) );
        return is_email( $email ) ? $email : '';
    }

    private function default_context() {
        return sprintf(
            'Business: %s. A commercial & residential floor care / flooring company serving Washington DC, Maryland, and Northern Virginia. Services include carpet cleaning, hard-floor strip & wax, tile & grout, and commercial janitorial floor maintenance. Website: %s.',
            get_bloginfo( 'name' ),
            home_url()
        );
    }

    private function get_context() {
        $ctx = trim( (string) get_option( self::OPT_CONTEXT, '' ) );
        return '' !== $ctx ? $ctx : $this->default_context();
    }

    // ── Generation + send ─────────────────────────────────────────────────────

    /**
     * Cron entry-point. Generate the digest and email it. Safe to call when
     * disabled / unconfigured (it no-ops).
     *
     * @param bool $force Send even if the enabled flag is off (used by "Send test now").
     * @return true|WP_Error
     */
    public function run_digest( $force = false ) {
        if ( ! $force && ! (int) get_option( self::OPT_ENABLED, 0 ) ) {
            return new WP_Error( 'disabled', 'Growth Digest is disabled.' );
        }
        $to = $this->get_recipient();
        if ( '' === $to ) {
            return new WP_Error( 'no_recipient', 'No recipient email is configured.' );
        }
        if ( ! class_exists( 'RSSEO_Claude_API' ) || ! method_exists( 'RSSEO_Claude_API', 'ask' ) ) {
            return new WP_Error( 'no_api', 'The base Real Smart SEO plugin (Claude API) is unavailable.' );
        }

        $result = RSSEO_Claude_API::ask( $this->build_prompt() );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $text     = is_array( $result ) ? (string) ( $result['text'] ?? '' ) : '';
        $sections = $this->parse_sections( $text );

        $subject = sprintf(
            /* translators: %s: site name */
            __( 'Your %s growth plan — guest posts, backlinks & video ideas', 'real-smart-seo' ),
            get_bloginfo( 'name' )
        );
        $body = $this->build_email_html( $sections, $text );

        $sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
        return $sent ? true : new WP_Error( 'send_failed', 'wp_mail returned false.' );
    }

    private function build_prompt() {
        $freq_word = 'daily' === $this->get_frequency() ? "today's" : "this week's";
        return "You are a senior local-SEO and content strategist. Context:\n"
            . $this->get_context() . "\n\n"
            . "Produce {$freq_word} concrete growth action list for this business. "
            . "Return EXACTLY four sections. Start each section with its marker line alone, "
            . "then 4-6 bullet items starting with '- '. Keep each item to one or two lines, "
            . "specific and immediately actionable. Plain text only — no markdown bold, no preamble.\n\n"
            . "[GUEST_POSTS]\n"
            . "Guest posts to pitch for more referral + organic traffic. For each: the type of "
            . "site/publication to target and the exact article angle/topic, and the keyword or "
            . "audience it captures.\n\n"
            . "[LOCAL_BACKLINKS]\n"
            . "Local backlinks to pursue. Include at least one .gov target (e.g. city/county "
            . "business directory, vendor/supplier registration, local government resource page) "
            . "and at least one nonprofit target (sponsorship, partnership, resource listing). "
            . "For each: the target type and the outreach angle/reason they'd link.\n\n"
            . "[VIDEO_SEO]\n"
            . "TikTok + YouTube video ideas optimized for search. For each: the platform, the "
            . "target search keyword/intent, and a suggested title.\n\n"
            . "[VIDEO_VIRAL]\n"
            . "Video ideas designed to go viral. For each: the hook and the concept.\n";
    }

    /**
     * Split the model output on the four markers. Returns label => body text.
     */
    private function parse_sections( $text ) {
        $map = array(
            'GUEST_POSTS'    => __( 'Guest posts for more traffic', 'real-smart-seo' ),
            'LOCAL_BACKLINKS'=> __( 'Local backlinks (incl. .gov & nonprofit)', 'real-smart-seo' ),
            'VIDEO_SEO'      => __( 'TikTok & YouTube videos for SEO', 'real-smart-seo' ),
            'VIDEO_VIRAL'    => __( 'Videos to go viral', 'real-smart-seo' ),
        );
        $out = array();
        foreach ( $map as $key => $label ) {
            if ( preg_match( '/\[' . $key . '\]\s*(.*?)(?=\n\s*\[(?:GUEST_POSTS|LOCAL_BACKLINKS|VIDEO_SEO|VIDEO_VIRAL)\]|$)/s', $text, $m ) ) {
                $out[ $label ] = trim( $m[1] );
            }
        }
        return $out;
    }

    private function build_email_html( $sections, $raw ) {
        $business = get_bloginfo( 'name' );
        $emojis   = array(
            __( 'Guest posts for more traffic', 'real-smart-seo' )            => '&#9997;&#65039;',
            __( 'Local backlinks (incl. .gov & nonprofit)', 'real-smart-seo' ) => '&#128279;',
            __( 'TikTok & YouTube videos for SEO', 'real-smart-seo' )          => '&#127909;',
            __( 'Videos to go viral', 'real-smart-seo' )                       => '&#128293;',
        );

        $blocks = '';
        if ( ! empty( $sections ) ) {
            foreach ( $sections as $label => $content ) {
                $items = preg_split( '/\n+/', trim( $content ) );
                $li    = '';
                foreach ( $items as $item ) {
                    $item = trim( preg_replace( '/^[-*\x{2022}]\s*/u', '', $item ) );
                    if ( '' === $item ) {
                        continue;
                    }
                    $li .= '<li style="margin:0 0 8px;line-height:1.5;color:#333;">' . esc_html( $item ) . '</li>';
                }
                $icon    = $emojis[ $label ] ?? '&#9989;';
                $blocks .= '<tr><td style="padding:24px 32px 4px;">'
                    . '<h2 style="font-size:17px;margin:0 0 10px;color:#1a1a2e;">' . $icon . ' ' . esc_html( $label ) . '</h2>'
                    . '<ul style="margin:0;padding-left:20px;">' . $li . '</ul>'
                    . '</td></tr>';
            }
        } else {
            // Fallback: model didn't follow the markers — show the raw text safely.
            $blocks = '<tr><td style="padding:24px 32px;"><pre style="white-space:pre-wrap;font-family:inherit;font-size:14px;color:#333;margin:0;">'
                . esc_html( $raw ) . '</pre></td></tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:system-ui,-apple-system,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="620" style="max-width:620px;width:100%;background:#fff;border-radius:12px;overflow:hidden;">
  <tr><td style="background:#1a1a2e;padding:24px 32px;">
    <p style="margin:0;color:#fff;font-size:18px;font-weight:700;">' . esc_html( $business ) . ' &middot; Growth Plan</p>
    <p style="margin:4px 0 0;color:#9aa;font-size:13px;">' . esc_html( wp_date( 'l, F j, Y' ) ) . '</p>
  </td></tr>
  ' . $blocks . '
  <tr><td style="background:#f9f9f9;padding:16px 32px;">
    <p style="margin:0;font-size:12px;color:#aaa;">Generated by Real Smart SEO. Reply to this email with what you actioned and we will tailor next time.</p>
  </td></tr>
</table></td></tr></table></body></html>';
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function add_menu() {
        add_submenu_page(
            null,
            __( 'Growth Digest', 'real-smart-seo' ),
            __( 'Growth Digest', 'real-smart-seo' ),
            'manage_options',
            'rsseo-growth-digest',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['rsseo_save_growth_digest'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_rsseo_gd_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_gd_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_save_growth_digest' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo' ) );
        }

        // Only enable when a valid recipient is present — otherwise the cron
        // would fire every run with nowhere to send.
        $email   = sanitize_email( wp_unslash( $_POST['gd_email'] ?? '' ) );
        $enabled = ( isset( $_POST['gd_enabled'] ) && is_email( $email ) ) ? 1 : 0;
        update_option( self::OPT_ENABLED, $enabled );
        update_option( self::OPT_EMAIL,   $email );
        $freq = sanitize_key( wp_unslash( $_POST['gd_freq'] ?? 'weekly' ) );
        update_option( self::OPT_FREQ, in_array( $freq, array( 'weekly', 'daily' ), true ) ? $freq : 'weekly' );
        update_option( self::OPT_CONTEXT, sanitize_textarea_field( wp_unslash( $_POST['gd_context'] ?? '' ) ) );

        // Reschedule to reflect the new enabled/frequency state immediately.
        wp_clear_scheduled_hook( self::CRON_HOOK );
        $this->maybe_schedule_cron();

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-growth-digest&saved=1' ) );
        exit;
    }

    public function handle_send_now() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        check_admin_referer( 'rsseo_growth_digest_send_now' );

        $result = $this->run_digest( true );
        $arg    = is_wp_error( $result )
            ? array( 'sent' => 'fail', 'msg' => rawurlencode( $result->get_error_message() ) )
            : array( 'sent' => 'ok' );
        wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'rsseo-growth-digest' ), $arg ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $enabled = (int) get_option( self::OPT_ENABLED, 0 );
        $email   = (string) get_option( self::OPT_EMAIL, '' );
        $freq    = $this->get_frequency();
        $context = (string) get_option( self::OPT_CONTEXT, '' );
        $next    = wp_next_scheduled( self::CRON_HOOK );
        $has_api = class_exists( 'RSSEO_Claude_API' );
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved   = isset( $_GET['saved'] );
        $sent    = isset( $_GET['sent'] ) ? sanitize_key( $_GET['sent'] ) : '';
        $msg     = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
        // phpcs:enable
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Growth Digest', 'real-smart-seo' ); ?></h1>
            <p class="description" style="max-width:680px;">
                <?php esc_html_e( 'An automatic email with this period\'s growth actions: guest posts to pitch for traffic, local backlinks to pursue (including .gov and nonprofit targets), TikTok/YouTube video ideas for SEO, and video ideas built to go viral.', 'real-smart-seo' ); ?>
            </p>

            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'real-smart-seo' ); ?></p></div><?php endif; ?>
            <?php if ( 'ok' === $sent ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test digest sent.', 'real-smart-seo' ); ?></p></div><?php endif; ?>
            <?php if ( 'fail' === $sent ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $msg ?: __( 'Could not send.', 'real-smart-seo' ) ); ?></p></div><?php endif; ?>
            <?php if ( ! $has_api ) : ?><div class="notice notice-warning"><p><?php esc_html_e( 'The free Real Smart SEO base plugin (Claude API) must be active to generate the digest.', 'real-smart-seo' ); ?></p></div><?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'rsseo_save_growth_digest', '_rsseo_gd_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable', 'real-smart-seo' ); ?></th>
                        <td><label><input type="checkbox" name="gd_enabled" value="1" <?php checked( $enabled, 1 ); ?>> <?php esc_html_e( 'Email the growth digest automatically', 'real-smart-seo' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gd_email"><?php esc_html_e( 'Send to', 'real-smart-seo' ); ?></label></th>
                        <td><input type="email" id="gd_email" name="gd_email" class="regular-text" value="<?php echo esc_attr( $email ); ?>" placeholder="owner@example.com">
                            <p class="description"><?php esc_html_e( 'Recipient (the owner). Nothing is sent until this is set and Enable is on.', 'real-smart-seo' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gd_freq"><?php esc_html_e( 'Frequency', 'real-smart-seo' ); ?></label></th>
                        <td>
                            <select id="gd_freq" name="gd_freq">
                                <option value="weekly" <?php selected( $freq, 'weekly' ); ?>><?php esc_html_e( 'Weekly (Monday morning)', 'real-smart-seo' ); ?></option>
                                <option value="daily" <?php selected( $freq, 'daily' ); ?>><?php esc_html_e( 'Daily (8am)', 'real-smart-seo' ); ?></option>
                            </select>
                            <?php if ( $next ) : ?>
                                <p class="description"><?php printf( esc_html__( 'Next send: %s', 'real-smart-seo' ), esc_html( wp_date( 'M j, Y g:i A', $next ) ) ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gd_context"><?php esc_html_e( 'Business context', 'real-smart-seo' ); ?></label></th>
                        <td><textarea id="gd_context" name="gd_context" rows="4" class="large-text" placeholder="<?php echo esc_attr( $this->default_context() ); ?>"><?php echo esc_textarea( $context ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Describe the business, services, and service area so the recommendations are relevant. Leave blank to use the default shown above.', 'real-smart-seo' ); ?></p></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="rsseo_save_growth_digest" value="1" class="button button-primary"><?php esc_html_e( 'Save', 'real-smart-seo' ); ?></button></p>
            </form>

            <hr>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'rsseo_growth_digest_send_now' ); ?>
                <input type="hidden" name="action" value="rsseo_growth_digest_send_now">
                <p><button type="submit" class="button" <?php disabled( ! $has_api ); ?>><?php esc_html_e( 'Send a test digest now', 'real-smart-seo' ); ?></button>
                <span class="description" style="margin-left:8px;"><?php esc_html_e( 'Generates and emails the digest immediately to the recipient above.', 'real-smart-seo' ); ?></span></p>
            </form>
        </div>
        <?php
    }
}

RSSEO_Pro_Growth_Digest::get_instance();
