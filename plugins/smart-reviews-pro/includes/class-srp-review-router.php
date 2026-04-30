<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Routes survey scores:
 * Score >= 9 → GMB review link (+ 2 follow-up reminders if not clicked)
 * Score < 9  → private feedback form only, owner notified
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
    }

    /**
     * @param int    $survey_id
     * @param int    $score
     * @param object $survey DB row
     */
    public function route( $survey_id, $score, $survey ) {
        $threshold = (int) get_option( 'srp_threshold', SRP_Survey::THRESHOLD );

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
        $gmb_url  = get_option( 'srp_gmb_review_url', '' );
        $business = get_bloginfo( 'name' );
        $name     = $survey->customer_name ?: 'there';
        $email    = $survey->customer_email;

        if ( ! is_email( $email ) || ! $gmb_url ) {
            return;
        }

        $subject = "Would you share your experience? — {$business}";
        $body    = $this->review_email_html( $name, $business, $gmb_url );

        wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

        // Schedule reminder at 48h if they don't click (we can track via a redirect if needed, for now just schedule).
        wp_schedule_single_event( time() + 2 * DAY_IN_SECONDS, 'srp_review_reminder', array( $survey_id ) );
    }

    private function review_email_html( $name, $business, $gmb_url ) {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:system-ui,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center" style="padding:40px 16px;">
    <table role="presentation" width="560" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;">
      <tr><td style="background:#1a1a2e;padding:24px;text-align:center;">
        <p style="margin:0;color:#fff;font-size:18px;font-weight:600;">' . esc_html( $business ) . '</p>
      </td></tr>
      <tr><td style="padding:40px 32px;text-align:center;">
        <div style="font-size:48px;margin-bottom:16px;">&#11088;</div>
        <h1 style="font-size:22px;margin:0 0 16px;">Thank you, ' . esc_html( $name ) . '!</h1>
        <p style="color:#555;margin:0 0 28px;font-size:15px;">We\'re so glad we exceeded your expectations. If you have 60 seconds, a Google review would mean the world to our small business — and helps other local homeowners find us.</p>
        <a href="' . esc_url( $gmb_url ) . '" target="_blank" style="display:inline-block;background:#4285F4;color:#fff;padding:16px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;">Leave Us a Google Review &#8594;</a>
        <p style="color:#aaa;font-size:13px;margin:32px 0 0;">No account required if you use Gmail. Thank you again for trusting us with your home.</p>
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
        $business    = get_bloginfo( 'name' );
        $score       = (int) $survey->score;

        wp_mail(
            $owner_email,
            "[{$business}] Score {$score}/10 — follow up needed",
            "Customer {$survey->customer_name} ({$survey->customer_email}) rated their experience {$score}/10.\n\nThis customer did NOT receive a Google review request.\n\nPlease follow up directly to resolve any concerns.",
            array( 'Content-Type: text/plain; charset=UTF-8' )
        );
    }
}

SRP_Review_Router::get_instance();
