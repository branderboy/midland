<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SRP Survey — sends NPS 0-10 survey after job completion.
 * Hooks:
 *   srp_job_completed( $data ) — fire this from any plugin/theme to trigger the survey.
 *   srp_survey_response        — public AJAX handler for the survey form.
 * Cron fires reminders at 24h and 48h if no response.
 */
class SRP_Survey {

    const THRESHOLD = 9; // Score >= THRESHOLD → route to GMB review link.

    private static $instance = null;

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

        $business  = get_bloginfo( 'name' );
        $from_name = $data['name'] ? 'Hi ' . $data['name'] . ',' : 'Hi there,';

        $subject = "How did we do? Quick question from {$business}";
        $body    = $this->survey_email_html( $from_name, $business, $survey_url, $token );

        wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    /**
     * Render the survey email HTML.
     */
    private function survey_email_html( $greeting, $business, $survey_url, $token ) {
        // Email shows score buttons that link to the on-site confirmation page.
        // The actual score submission requires a POST + nonce there, so email-scanner
        // pre-fetches (Mimecast / Defender / etc.) cannot record a fake score.
        $scores_html = '';
        for ( $i = 0; $i <= 10; $i++ ) {
            $score_url    = add_query_arg( array( 'srp_survey' => $token, 'pick' => $i ), home_url( '/' ) );
            $bg           = $i >= self::THRESHOLD ? '#22c55e' : ( $i >= 7 ? '#f59e0b' : '#ef4444' );
            $scores_html .= '<a href="' . esc_url( $score_url ) . '" style="display:inline-block;width:40px;height:40px;line-height:40px;text-align:center;background:' . $bg . ';color:#fff;font-weight:bold;font-size:16px;border-radius:6px;text-decoration:none;margin:2px;">' . $i . '</a>';
        }

        return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:system-ui,-apple-system,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center" style="padding:40px 16px;">
    <table role="presentation" width="560" style="max-width:560px;width:100%;background:#fff;border-radius:12px;overflow:hidden;">
      <tr><td style="background:#1a1a2e;padding:24px;text-align:center;">
        <p style="margin:0;color:#fff;font-size:18px;font-weight:600;">' . esc_html( $business ) . '</p>
      </td></tr>
      <tr><td style="padding:40px 32px;">
        <p style="font-size:16px;color:#333;margin:0 0 8px;">' . esc_html( $greeting ) . '</p>
        <p style="font-size:15px;color:#555;margin:0 0 32px;">Thank you for choosing ' . esc_html( $business ) . '. We\'d love to know how we did. On a scale of 0–10, how likely are you to recommend us to a friend or neighbor?</p>
        <p style="font-size:13px;color:#888;margin:0 0 12px;">0 = Not at all likely &nbsp;&nbsp; 10 = Extremely likely</p>
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
                $score = min( 10, max( 0, $score ) );
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
            $picked = min( 10, max( 0, $picked ) );
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
        $business = get_bloginfo( 'name' );
        $score    = $survey->score;

        if ( null === $score || '' === $score ) {
            $scores_html = '';
            for ( $i = 0; $i <= 10; $i++ ) {
                $url = add_query_arg( array( 'srp_survey' => $token, 'pick' => $i ), home_url( '/' ) );
                $bg  = $i >= self::THRESHOLD ? '#22c55e' : ( $i >= 7 ? '#f59e0b' : '#ef4444' );
                $scores_html .= '<a href="' . esc_url( $url ) . '" style="display:inline-block;width:48px;height:48px;line-height:48px;text-align:center;background:' . $bg . ';color:#fff;font-weight:bold;font-size:18px;border-radius:8px;text-decoration:none;margin:3px;">' . $i . '</a>';
            }

            $confirm_html = '';
            if ( null !== $picked ) {
                $bg = $picked >= self::THRESHOLD ? '#22c55e' : ( $picked >= 7 ? '#f59e0b' : '#ef4444' );
                $confirm_html = '
<form method="post" action="' . esc_url( home_url( '/' ) ) . '" style="margin-top:24px;">
  <input type="hidden" name="srp_survey" value="' . esc_attr( $token ) . '">
  <input type="hidden" name="score" value="' . esc_attr( (string) $picked ) . '">
  <input type="hidden" name="_srp_nonce" value="' . esc_attr( wp_create_nonce( 'srp_score_' . $token ) ) . '">
  <p style="color:#333;font-size:15px;margin:0 0 16px;">You picked <strong style="color:' . $bg . ';">' . esc_html( (string) $picked ) . ' / 10</strong>. Tap submit to confirm.</p>
  <button type="submit" style="background:#1a1a2e;color:#fff;border:none;padding:14px 28px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">Submit my score</button>
</form>';
            }

            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>How did we do?</title></head>
<body style="margin:0;padding:40px 16px;background:#f4f4f4;font-family:system-ui,sans-serif;text-align:center;">
<div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:40px;box-shadow:0 2px 16px rgba(0,0,0,.08);">
<h1 style="font-size:22px;margin:0 0 8px;">How was your experience?</h1>
<p style="color:#555;margin:0 0 32px;">0 = Not likely at all &nbsp;&nbsp; 10 = Extremely likely</p>
' . $scores_html . $confirm_html . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
'</div></body></html>';
            return;
        }

        $score = (int) $score;
        $is_happy = $score >= self::THRESHOLD;

        $gmb_url = get_option( 'srp_gmb_review_url', '' );

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Thank you!</title></head>
<body style="margin:0;padding:40px 16px;background:#f4f4f4;font-family:system-ui,sans-serif;text-align:center;">';

        if ( $is_happy && $gmb_url ) {
            echo '<div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:40px;box-shadow:0 2px 16px rgba(0,0,0,.08);">
<div style="font-size:48px;margin-bottom:16px;">&#127775;</div>
<h1 style="font-size:22px;margin:0 0 12px;">That makes our day!</h1>
<p style="color:#555;margin:0 0 28px;">Would you mind sharing that on Google? It takes about 60 seconds and means the world to us.</p>
<a href="' . esc_url( $gmb_url ) . '" target="_blank" style="display:inline-block;background:#4285F4;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px;">Leave a Google Review &#8594;</a>
<p style="color:#aaa;font-size:13px;margin:24px 0 0;">Thank you for choosing ' . esc_html( $business ) . '.</p>
</div>';
        } else {
            echo '<div style="max-width:540px;margin:0 auto;background:#fff;border-radius:12px;padding:40px;box-shadow:0 2px 16px rgba(0,0,0,.08);">
<div style="font-size:48px;margin-bottom:16px;">&#128591;</div>
<h1 style="font-size:22px;margin:0 0 12px;">Thank you for your honest feedback</h1>
<p style="color:#555;margin:0 0 28px;">We\'re sorry we didn\'t fully meet your expectations. Our owner will personally review your response and follow up with you.</p>';

            // Show optional feedback box — submits via AJAX.
            echo '<form id="srp-feedback" style="text-align:left;">
<input type="hidden" name="token" value="' . esc_attr( $token ) . '">
<input type="hidden" name="action" value="srp_save_feedback">
<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'srp_feedback_' . $token ) ) . '">
<textarea name="feedback" rows="4" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:14px;resize:vertical;" placeholder="What could we have done better? (optional)"></textarea>
<br><button type="submit" style="margin-top:12px;background:#1a1a2e;color:#fff;border:none;padding:12px 24px;border-radius:6px;font-size:14px;cursor:pointer;">Send Feedback</button>
</form>
<script>
document.getElementById("srp-feedback").addEventListener("submit",function(e){
  e.preventDefault();
  var f=new FormData(this);
  fetch("' . esc_url( admin_url( 'admin-ajax.php' ) ) . '",{method:"POST",body:f})
    .then(function(){document.getElementById("srp-feedback").innerHTML="<p style=\'color:#22c55e;\'>Sent. Thank you.</p>";});
});
</script>
<p style="color:#aaa;font-size:13px;margin:24px 0 0;">Thank you for choosing ' . esc_html( $business ) . '.</p>
</div>';
        }

        echo '</body></html>';
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
        $owner_email = get_option( 'admin_email' );
        $business    = get_bloginfo( 'name' );
        wp_mail(
            $owner_email,
            "[{$business}] Private feedback received — score {$survey->score}/10",
            "Customer: {$survey->customer_name} ({$survey->customer_email})\nScore: {$survey->score}/10\n\nFeedback:\n{$feedback}",
            array( 'Content-Type: text/plain; charset=UTF-8' )
        );

        wp_send_json_success();
    }

    /**
     * Cron: send reminder emails at 24h (reminder1) and 48h (reminder2) for non-respondents.
     */
    public function process_reminders() {
        $business = get_bloginfo( 'name' );

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
            ? "Quick reminder — how did {$business} do?"
            : "Last chance to share your feedback — {$business}";

        $intro = 1 === $which
            ? 'we sent you a quick survey yesterday'
            : 'we are still hoping to hear from you';

        $body = '<!DOCTYPE html><html><body style="font-family:system-ui,sans-serif;background:#f4f4f4;padding:40px 16px;">'
            . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;">'
            . '<p style="font-size:16px;color:#333;">Hi ' . esc_html( $name ) . ',</p>'
            . '<p style="color:#555;">' . esc_html( ucfirst( $intro ) ) . '. If you have 20 seconds: '
            . '<a href="' . esc_url( $url ) . '" style="color:#2563eb;font-weight:600;">rate your experience here</a>.</p>'
            . '<p style="color:#aaa;font-size:13px;margin-top:32px;">' . esc_html( $business ) . '</p>'
            . '</div></body></html>';

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
