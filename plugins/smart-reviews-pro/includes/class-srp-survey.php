<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SRP Survey — sends a 1–5 star survey after job completion.
 * Hooks:
 *   srp_job_completed( $data ) — fire this from any plugin/theme to trigger the survey.
 *   srp_survey_response        — public AJAX handler for the survey form.
 * Cron fires reminders at 24h and 48h if no response.
 */
class SRP_Survey {

    const THRESHOLD = 4; // 1–5 star scale: score >= THRESHOLD (4★) → route to Google review.
    const MAX_SCORE = 5; // Top of the rating scale.

    /**
     * Midland brand defaults — all overridable in Settings, mirroring the
     * pattern used by Midland Chat (business_name / chat_color / logo). These
     * keep customer-facing copy off the long SEO site title and onto the brand.
     */
    const DEFAULT_BUSINESS   = 'Midland Floors';
    const DEFAULT_COLOR      = '#43A94B'; // Midland primary green.
    const DEFAULT_REVIEW_URL = 'https://search.google.com/local/writereview?placeid=ChIJ59SJ6ue7t4kRIVMYpQVYY6Y';
    // Hosted on the live (public) site so it actually loads in customer inboxes —
    // the GitHub repo is private, so raw.githubusercontent URLs 404 in email.
    const DEFAULT_LOGO_URL   = 'https://midlandfloors.com/wp-content/uploads/2026/05/midland-small-logo-16.png';

    /** Customer-facing business name — never the long SEO site title. */
    public static function business_name() {
        $name = trim( (string) get_option( 'srp_business_name', '' ) );
        return '' !== $name ? $name : self::DEFAULT_BUSINESS;
    }

    /** Primary brand color for buttons and email/survey headers. */
    public static function brand_color() {
        $c = trim( (string) get_option( 'srp_brand_color', '' ) );
        return '' !== $c ? $c : self::DEFAULT_COLOR;
    }

    /** White logo shown on the brand-color header. */
    public static function logo_url() {
        return trim( (string) get_option( 'srp_logo_url', self::DEFAULT_LOGO_URL ) );
    }

    /** Configured Google review link, falling back to the verified Midland link. */
    public static function review_url() {
        $url = trim( (string) get_option( 'srp_gmb_review_url', '' ) );
        return '' !== $url ? $url : self::DEFAULT_REVIEW_URL;
    }

    /** Shared branded header row: dark logo on a white band with a green accent rule. */
    public static function brand_header_html() {
        $logo  = self::logo_url();
        $name  = self::business_name();
        $color = self::brand_color();
        $inner = $logo
            ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" height="46" style="height:46px;width:auto;max-width:260px;border:0;display:inline-block;">'
            : '<span style="color:#0F1411;font-size:20px;font-weight:700;">' . esc_html( $name ) . '</span>';
        return '<tr><td style="background:#ffffff;padding:22px;text-align:center;border-bottom:3px solid ' . esc_attr( $color ) . ';">' . $inner . '</td></tr>';
    }

    /** Full standalone branded page shell: dark logo on a white header + white card. */
    public static function page_shell( $title, $inner_html ) {
        $color = self::brand_color();
        $logo  = self::logo_url();
        $name  = self::business_name();
        $head  = $logo
            ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" height="42" style="height:42px;width:auto;max-width:240px;border:0;">'
            : '<span style="color:#0F1411;font-size:18px;font-weight:700;">' . esc_html( $name ) . '</span>';
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head>
<body style="margin:0;padding:40px 16px;background:#F3FCF4;font-family:system-ui,-apple-system,sans-serif;text-align:center;">
<div style="max-width:540px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 16px rgba(14,47,20,.10);">
  <div style="background:#ffffff;padding:22px;border-bottom:3px solid ' . esc_attr( $color ) . ';">' . $head . '</div>
  <div style="padding:36px 32px;">' . $inner_html . '</div>
</div>
</body></html>';
    }

    private static $instance = null;

    /**
     * Outcome of the most recent send_survey() per email, so the CRM dedupe
     * only marks a lead "surveyed" when the email actually went out — a failed
     * send is left unmarked so the hourly poll retries it.
     *
     * @var array<string,bool>
     */
    private static $last_send_ok = array();

    /** Whether the last survey email to $email was sent successfully. */
    public static function was_sent( $email ) {
        return ! empty( self::$last_send_ok[ sanitize_email( (string) $email ) ] );
    }

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Anyone can fire this action with customer data: array( name, email, phone, job_id ).
        add_action( 'srp_job_completed',             array( $this, 'send_survey' ) );
        // Public AJAX — score submission from survey email link.
        add_action( 'wp_ajax_nopriv_srp_survey_respond', array( $this, 'handle_response' ) );
        add_action( 'wp_ajax_srp_survey_respond',        array( $this, 'handle_response' ) );
        // Cron reminders.
        add_action( 'srp_cron_reminders', array( $this, 'process_reminders' ) );
        if ( ! wp_next_scheduled( 'srp_cron_reminders' ) ) {
            wp_schedule_event( time(), 'hourly', 'srp_cron_reminders' );
        }
        // Public survey page (token-based URL).
        add_action( 'init', array( $this, 'handle_survey_page' ) );
    }

    /**
     * Send the NPS survey email.
     *
     * @param array $data {
     *   name   string  Customer name
     *   email  string  Customer email
     *   phone  string  Customer phone (optional)
     *   job_id string  Internal job/invoice ID (optional)
     * }
     */
    public function send_survey( $data ) {
        $email = sanitize_email( $data['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return;
        }

        $survey_id = SRP_DB::insert_survey( array(
            'customer_name'  => sanitize_text_field( $data['name'] ?? '' ),
            'customer_email' => $email,
            'customer_phone' => sanitize_text_field( $data['phone'] ?? '' ),
            'job_id'         => sanitize_text_field( $data['job_id'] ?? '' ),
            'survey_sent_at' => current_time( 'mysql' ),
        ) );

        $token    = SRP_DB::build_token( $survey_id );
        $survey_url = add_query_arg( array(
            'srp_survey' => $token,
        ), home_url( '/' ) );

        $business  = self::business_name();
        $from_name = $data['name'] ? 'Hi ' . $data['name'] . ',' : 'Hi there,';

        $subject = 'Tell us how did we do?';
        $body    = $this->survey_email_html( $from_name, $business, $survey_url, $token );

        $sent = wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

        // Record the outcome so SRP_CRM_Integration only marks the lead as
        // surveyed on a successful send — a failed send stays unmarked and the
        // hourly poll retries it next run.
        self::$last_send_ok[ $email ] = (bool) $sent;
        return (bool) $sent;
    }

    /**
     * Render the survey email HTML.
     */
    private function survey_email_html( $greeting, $business, $survey_url, $token ) {
        // Email shows score buttons that link to the on-site confirmation page.
        // The actual score submission requires a POST + nonce there, so email-scanner
        // pre-fetches (Mimecast / Defender / etc.) cannot record a fake score.
        $scores_html = '';
        for ( $i = 1; $i <= self::MAX_SCORE; $i++ ) {
            $score_url    = add_query_arg( array( 'srp_survey' => $token, 'pick' => $i ), home_url( '/' ) );
            $bg           = $i >= self::THRESHOLD ? self::brand_color() : ( $i >= 3 ? '#f59e0b' : '#DC2525' );
            $scores_html .= '<a href="' . esc_url( $score_url ) . '" style="display:inline-block;width:48px;height:48px;line-height:48px;text-align:center;background:' . $bg . ';color:#fff;font-weight:bold;font-size:18px;border-radius:8px;text-decoration:none;margin:3px;">' . $i . '&#9733;</a>';
        }

        return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:system-ui,-apple-system,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center" style="padding:40px 16px;">
    <table role="presentation" width="560" style="max-width:560px;width:100%;background:#fff;border-radius:12px;overflow:hidden;">
      ' . self::brand_header_html() . '
      <tr><td style="padding:40px 32px;">
        <p style="font-size:16px;color:#0F1411;margin:0 0 8px;">' . esc_html( $greeting ) . '</p>
        <p style="font-size:15px;color:#555;margin:0 0 32px;">Thank you for choosing ' . esc_html( $business ) . '. We\'d love to know how we did. How would you rate your experience?</p>
        <p style="font-size:13px;color:#888;margin:0 0 12px;">1&#9733; = Poor &nbsp;&nbsp; 5&#9733; = Excellent</p>
        <p style="margin:0 0 32px;">' . $scores_html . '</p>
        <p style="font-size:13px;color:#aaa;margin:0;">Takes about 20 seconds. Your feedback directly improves our service.</p>
      </td></tr>
      <tr><td style="background:#f9f9f9;padding:16px 32px;text-align:center;">
        <p style="margin:0;font-size:12px;color:#bbb;">' . esc_html( $business ) . ' · Unsubscribe not required for one-time survey</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>';
    }

    /**
     * Handle the survey URL click.
     * GET = render the confirm page (shows a Submit button if a score is "picked" via ?pick=).
     * POST = actually record the score (nonce-verified, real user click required).
     * This blocks email-scanner false positives.
     */
    public function handle_survey_page() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['srp_survey'] ) && ! isset( $_POST['srp_survey'] ) ) {
            return;
        }
        // phpcs:enable

        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) && isset( $_POST['srp_survey'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_POST['srp_survey'] ) );
            $survey = SRP_DB::get_survey_by_token( $token );
            if ( ! $survey ) {
                return;
            }

            $nonce = isset( $_POST['_srp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_srp_nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'srp_score_' . $token ) ) {
                wp_die( esc_html__( 'Security check failed. Please reload the page from the email link and try again.', 'smart-reviews-pro' ), 403 );
            }

            $score = isset( $_POST['score'] ) ? (int) $_POST['score'] : null;
            if ( null !== $score && null === $survey->score ) {
                $score = min( self::MAX_SCORE, max( 1, $score ) );
                SRP_DB::update_survey( $survey->id, array(
                    'score'        => $score,
                    'responded_at' => current_time( 'mysql' ),
                ) );
                $survey->score = $score;
                do_action( 'srp_score_received', $survey->id, $score, $survey );
            }

            $this->render_survey_page( $survey, $token );
            exit;
        }

        // GET path — render the confirm page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token = sanitize_text_field( wp_unslash( $_GET['srp_survey'] ?? '' ) );
        $survey = SRP_DB::get_survey_by_token( $token );
        if ( ! $survey ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $picked = isset( $_GET['pick'] ) ? (int) $_GET['pick'] : null;
        if ( null !== $picked ) {
            $picked = min( self::MAX_SCORE, max( 1, $picked ) );
        }

        $this->render_survey_page( $survey, $token, $picked );
        exit;
    }

    /**
     * Output the inline survey response page.
     * @param object   $survey
     * @param string   $token
     * @param int|null $picked Score the user picked from the email link, awaiting Submit.
     */
    private function render_survey_page( $survey, $token, $picked = null ) {
        $business = self::business_name();
        $score    = $survey->score;

        if ( null === $score || '' === $score ) {
            $scores_html = '';
            for ( $i = 1; $i <= self::MAX_SCORE; $i++ ) {
                $url = add_query_arg( array( 'srp_survey' => $token, 'pick' => $i ), home_url( '/' ) );
                $bg  = $i >= self::THRESHOLD ? self::brand_color() : ( $i >= 3 ? '#f59e0b' : '#DC2525' );
                $scores_html .= '<a href="' . esc_url( $url ) . '" style="display:inline-block;width:52px;height:52px;line-height:52px;text-align:center;background:' . $bg . ';color:#fff;font-weight:bold;font-size:18px;border-radius:8px;text-decoration:none;margin:3px;">' . $i . '&#9733;</a>';
            }

            $confirm_html = '';
            if ( null !== $picked ) {
                $bg = $picked >= self::THRESHOLD ? self::brand_color() : ( $picked >= 3 ? '#f59e0b' : '#DC2525' );
                $confirm_html = '
<form method="post" action="' . esc_url( home_url( '/' ) ) . '" style="margin-top:24px;">
  <input type="hidden" name="srp_survey" value="' . esc_attr( $token ) . '">
  <input type="hidden" name="score" value="' . esc_attr( (string) $picked ) . '">
  <input type="hidden" name="_srp_nonce" value="' . esc_attr( wp_create_nonce( 'srp_score_' . $token ) ) . '">
  <p style="color:#0F1411;font-size:15px;margin:0 0 16px;">You picked <strong style="color:' . $bg . ';">' . esc_html( (string) $picked ) . ' / 5&#9733;</strong>. Tap submit to confirm.</p>
  <button type="submit" style="background:' . esc_attr( self::brand_color() ) . ';color:#fff;border:none;padding:14px 28px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">Submit my rating</button>
</form>';
            }

            $inner = '<h1 style="font-size:22px;margin:0 0 8px;color:#0F1411;">How was your experience?</h1>
<p style="color:#4B5563;margin:0 0 32px;">1&#9733; = Poor &nbsp;&nbsp; 5&#9733; = Excellent</p>'
                . $scores_html . $confirm_html;
            echo self::page_shell( __( 'How did we do?', 'smart-reviews-pro' ), $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        $score    = (int) $score;
        $is_happy = $score >= self::THRESHOLD;
        $gmb_url  = self::review_url();
        $brand    = self::brand_color();

        if ( $is_happy && $gmb_url ) {
            $inner = '<div style="font-size:48px;margin-bottom:16px;">&#127775;</div>
<h1 style="font-size:22px;margin:0 0 12px;color:#0F1411;">That makes our day!</h1>
<p style="color:#4B5563;margin:0 0 28px;">Would you mind sharing that on Google? It takes about 60 seconds and means the world to us.</p>
<a href="' . esc_url( $gmb_url ) . '" target="_blank" style="display:inline-block;background:' . esc_attr( $brand ) . ';color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">Leave a Google Review &#8594;</a>
<p style="color:#9aa39c;font-size:13px;margin:24px 0 0;">Thank you for choosing ' . esc_html( $business ) . '.</p>';
        } else {
            $inner = '<div style="font-size:48px;margin-bottom:16px;">&#128591;</div>
<h1 style="font-size:22px;margin:0 0 12px;color:#0F1411;">Thank you for your honest feedback</h1>
<p style="color:#4B5563;margin:0 0 28px;">We\'re sorry we didn\'t fully meet your expectations. Our owner will personally review your response and follow up with you.</p>
<form id="srp-feedback" style="text-align:left;">
<input type="hidden" name="token" value="' . esc_attr( $token ) . '">
<input type="hidden" name="action" value="srp_save_feedback">
<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'srp_feedback_' . $token ) ) . '">
<textarea name="feedback" rows="4" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:14px;resize:vertical;" placeholder="What could we have done better? (optional)"></textarea>
<br><button type="submit" style="margin-top:12px;background:' . esc_attr( $brand ) . ';color:#fff;border:none;padding:12px 24px;border-radius:6px;font-size:14px;cursor:pointer;">Send Feedback</button>
</form>
<script>
document.getElementById("srp-feedback").addEventListener("submit",function(e){
  e.preventDefault();
  var f=new FormData(this);
  fetch("' . esc_url( admin_url( 'admin-ajax.php' ) ) . '",{method:"POST",body:f})
    .then(function(){document.getElementById("srp-feedback").innerHTML="<p style=\'color:' . esc_attr( $brand ) . ';font-weight:600;\'>Sent. Thank you.</p>";});
});
</script>
<p style="color:#9aa39c;font-size:13px;margin:24px 0 0;">Thank you for choosing ' . esc_html( $business ) . '.</p>';
        }

        echo self::page_shell( __( 'Thank you!', 'smart-reviews-pro' ), $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * AJAX: save written feedback from low-score respondents.
     */
    public function handle_response() {
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'srp_feedback_' . $token ) ) {
            wp_send_json_error( 'Nonce invalid.' );
        }

        $survey = SRP_DB::get_survey_by_token( $token );
        if ( ! $survey ) {
            wp_send_json_error( 'Survey not found.' );
        }

        $feedback = sanitize_textarea_field( wp_unslash( $_POST['feedback'] ?? '' ) );
        SRP_DB::update_survey( $survey->id, array( 'feedback' => $feedback ) );

        // Notify owner.
        // Re-sanitize DB values for the email context: strip tags/control chars so
        // a stored name or email can't inject headers or unexpected content.
        $owner_email       = get_option( 'admin_email' );
        $business          = wp_strip_all_tags( self::business_name() );
        $safe_name         = sanitize_text_field( $survey->customer_name );
        $safe_email        = sanitize_email( $survey->customer_email );
        $safe_score        = absint( $survey->score );
        wp_mail(
            $owner_email,
            "[{$business}] Private feedback received — score {$safe_score}/5",
            "Customer: {$safe_name} ({$safe_email})\nScore: {$safe_score}/5\n\nFeedback:\n{$feedback}",
            array( 'Content-Type: text/plain; charset=UTF-8' )
        );

        wp_send_json_success();
    }

    /**
     * Cron: send reminder emails at 24h (reminder1) and 48h (reminder2) for non-respondents.
     */
    public function process_reminders() {
        $business = self::business_name();

        foreach ( (array) SRP_DB::get_pending_reminders() as $survey ) {
            $this->send_reminder( $survey, $business, 1 );
            SRP_DB::update_survey( $survey->id, array( 'reminder1_at' => current_time( 'mysql' ) ) );
        }

        foreach ( (array) SRP_DB::get_pending_reminders_second() as $survey ) {
            $this->send_reminder( $survey, $business, 2 );
            SRP_DB::update_survey( $survey->id, array( 'reminder2_at' => current_time( 'mysql' ) ) );
        }
    }

    private function send_reminder( $survey, $business, $which ) {
        $email = sanitize_email( $survey->customer_email );
        if ( ! is_email( $email ) ) {
            return;
        }
        $token = SRP_DB::build_token( $survey->id );
        $url   = add_query_arg( array( 'srp_survey' => $token ), home_url( '/' ) );
        $name  = $survey->customer_name ?: 'there';

        $subject = 1 === $which
            ? 'Quick reminder, how did we do?'
            : 'Last chance to share your feedback';

        $intro = 1 === $which
            ? 'we sent you a quick survey yesterday'
            : 'we are still hoping to hear from you';

        $brand = self::brand_color();
        $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:system-ui,-apple-system,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 16px;">
<table role="presentation" width="560" style="max-width:560px;width:100%;background:#fff;border-radius:12px;overflow:hidden;">
' . self::brand_header_html() . '
<tr><td style="padding:36px 32px;">
<p style="font-size:16px;color:#0F1411;margin:0 0 8px;">Hi ' . esc_html( $name ) . ',</p>
<p style="color:#4B5563;margin:0 0 24px;">' . esc_html( ucfirst( $intro ) ) . '. If you have 20 seconds, we\'d love your feedback:</p>
<p style="margin:0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . esc_attr( $brand ) . ';color:#fff;padding:13px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">Rate your experience &#8594;</a></p>
</td></tr>
<tr><td style="background:#f9f9f9;padding:16px 32px;text-align:center;"><p style="margin:0;font-size:12px;color:#bbb;">' . esc_html( $business ) . '</p></td></tr>
</table></td></tr></table></body></html>';

        wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    public static function safe_truncate( $string, $length ) {
        $string = (string) $string;
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $string, 0, $length );
        }
        return substr( $string, 0, $length );
    }
}

SRP_Survey::get_instance();
