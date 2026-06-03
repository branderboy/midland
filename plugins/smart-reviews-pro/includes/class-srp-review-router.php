<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Routes survey scores (1–5 star scale):
 * Score >= 4★ → Google review link (+ 2 follow-up reminders if not clicked)
 * Score < 4★  → private feedback form only, manager notified
 */
class SRP_Review_Router {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'srp_score_received', array( $this, 'route' ), 10, 3 );
        // 48h nudge for happy customers who were sent a Google review request.
        add_action( 'srp_review_reminder', array( $this, 'send_review_reminder' ), 10, 1 );
    }

    /**
     * Reminder fired 48h after a Google-review request (scheduled in
     * send_review_request). Re-sends the review ask to customers who were
     * routed to GMB. Without click-tracking we can't know if they already
     * reviewed, so this is a single, gentle follow-up.
     *
     * @param int $survey_id
     */
    public function send_review_reminder( $survey_id ) {
        $survey = SRP_DB::get_survey( (int) $survey_id );
        if ( ! $survey || 'gmb' !== $survey->route_type ) {
            return; // Only nudge customers who got a Google review request.
        }

        $gmb_url = SRP_Survey::review_url();
        if ( ! is_email( $survey->customer_email ) || ! $gmb_url ) {
            return;
        }

        $business = SRP_Survey::business_name();
        $name     = $survey->customer_name ?: 'there';

        wp_mail(
            $survey->customer_email,
            "Just a quick reminder from {$business}",
            $this->review_email_html( $name, $business, $gmb_url ),
            array( 'Content-Type: text/html; charset=UTF-8' )
        );

        SRP_DB::update_survey( (int) $survey_id, array( 'reminder1_at' => current_time( 'mysql' ) ) );
    }

    /**
     * @param int    $survey_id
     * @param int    $score
     * @param object $survey DB row
     */
    public function route( $survey_id, $score, $survey ) {
        $threshold = (int) get_option( 'srp_threshold', SRP_Survey::THRESHOLD );
        // Guard against a stale option from the old 0–10 scale (e.g. 9): on the
        // 1–5 star scale that would make a Google review unreachable, so fall
        // back to the default 4★ threshold.
        if ( $threshold < 1 || $threshold > SRP_Survey::MAX_SCORE ) {
            $threshold = SRP_Survey::THRESHOLD;
        }

        if ( $score >= $threshold ) {
            $this->send_review_request( $survey_id, $survey );
            SRP_DB::update_survey( $survey_id, array(
                'routed'     => 1,
                'route_type' => 'gmb',
            ) );
        } else {
            $this->notify_owner_low_score( $survey );
            SRP_DB::update_survey( $survey_id, array(
                'routed'     => 1,
                'route_type' => 'private',
            ) );
        }
    }

    /**
     * Send the GMB review request email to a happy customer.
     */
    private function send_review_request( $survey_id, $survey ) {
        $gmb_url  = SRP_Survey::review_url();
        $business = SRP_Survey::business_name();
        $name     = $survey->customer_name ?: 'there';
        $email    = $survey->customer_email;

        if ( ! is_email( $email ) || ! $gmb_url ) {
            return;
        }

        $subject = "Would you share your experience with {$business}?";
        $body    = $this->review_email_html( $name, $business, $gmb_url );

        wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

        // Schedule reminder at 48h if they don't click (we can track via a redirect if needed, for now just schedule).
        wp_schedule_single_event( time() + 2 * DAY_IN_SECONDS, 'srp_review_reminder', array( $survey_id ) );
    }

    private function review_email_html( $name, $business, $gmb_url ) {
        $brand = SRP_Survey::brand_color();
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:system-ui,-apple-system,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center" style="padding:40px 16px;">
    <table role="presentation" width="560" style="max-width:560px;width:100%;background:#fff;border-radius:12px;overflow:hidden;">
      ' . SRP_Survey::brand_header_html() . '
      <tr><td style="padding:40px 32px;text-align:center;">
        <div style="font-size:48px;margin-bottom:16px;">&#11088;</div>
        <h1 style="font-size:22px;margin:0 0 16px;color:#0F1411;">Thank you, ' . esc_html( $name ) . '!</h1>
        <p style="color:#4B5563;margin:0 0 28px;font-size:15px;">We\'re so glad we exceeded your expectations. If you have 60 seconds, a Google review would mean the world to our small business — and helps other local homeowners find us.</p>
        <a href="' . esc_url( $gmb_url ) . '" target="_blank" style="display:inline-block;background:' . esc_attr( $brand ) . ';color:#fff;padding:16px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;">Leave Us a Google Review &#8594;</a>
        <p style="color:#9aa39c;font-size:13px;margin:32px 0 0;">No account required if you use Gmail. Thank you again for trusting us with your floors.</p>
      </td></tr>
      <tr><td style="background:#f9f9f9;padding:16px;text-align:center;">
        <p style="margin:0;font-size:12px;color:#bbb;">' . esc_html( $business ) . '</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>';
    }

    /**
     * Notify owner when a low score comes in.
     */
    private function notify_owner_low_score( $survey ) {
        $owner_email = get_option( 'srp_owner_email', get_option( 'admin_email' ) );
        $business    = SRP_Survey::business_name();
        $score       = (int) $survey->score;

        wp_mail(
            $owner_email,
            "[{$business}] Score {$score}/5 — follow up needed",
            "Customer {$survey->customer_name} ({$survey->customer_email}) rated their experience {$score}/5 stars.\n\nThis customer did NOT receive a Google review request.\n\nPlease follow up directly to resolve any concerns.",
            array( 'Content-Type: text/plain; charset=UTF-8' )
        );
    }
}

SRP_Review_Router::get_instance();
