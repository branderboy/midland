<?php
/**
 * Email alert system — renders a brief as clean HTML and sends it via wp_mail.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTM_Emailer {

    /**
     * Send a brief to the configured recipient.
     *
     * @param array  $brief    Structured brief from CTM_Generator.
     * @param array  $settings Settings.
     * @param string $html     Pre-rendered HTML (optional; rendered if empty).
     * @return bool wp_mail result.
     */
    public static function send( $brief, $settings, $html = '' ) {
        $to = sanitize_email( (string) ( $settings['recipient'] ?? '' ) );
        if ( ! is_email( $to ) ) {
            return false;
        }
        if ( '' === $html ) {
            $html = self::render_html( $brief, $settings );
        }
        return wp_mail( $to, self::subject( $settings ), $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    public static function subject( $settings ) {
        $name = sanitize_text_field( (string) ( $settings['business_name'] ?? get_bloginfo( 'name' ) ) );
        if ( 'daily' === ( $settings['frequency'] ?? 'weekly' ) ) {
            /* translators: %s: business name */
            return sprintf( __( 'Today\'s Traffic Opportunity for %s', 'content-traffic-maker' ), $name );
        }
        /* translators: %s: business name */
        return sprintf( __( 'Weekly Content Traffic Plan for %s', 'content-traffic-maker' ), $name );
    }

    /**
     * Render the brief as a clean HTML email.
     *
     * @param array $brief
     * @param array $settings
     * @return string
     */
    public static function render_html( $brief, $settings ) {
        $b = function( $k ) use ( $brief ) {
            return esc_html( (string) ( $brief[ $k ] ?? '' ) );
        };

        $name     = esc_html( (string) ( $settings['business_name'] ?? get_bloginfo( 'name' ) ) );
        $city     = esc_html( trim( (string) ( $settings['target_city'] ?? '' ) . ' ' . (string) ( $settings['target_state'] ?? '' ) ) );
        $priority = (int) ( $brief['priority_score'] ?? 0 );

        $section = function( $icon, $title, $rows ) {
            $inner = '';
            foreach ( $rows as $label => $value ) {
                if ( '' === (string) $value ) {
                    continue;
                }
                $lbl   = $label ? '<span style="display:block;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#8a8f98;margin-bottom:2px;">' . esc_html( $label ) . '</span>' : '';
                $inner .= '<div style="margin:0 0 12px;">' . $lbl . '<span style="font-size:15px;color:#222;line-height:1.5;">' . $value . '</span></div>';
            }
            return '<tr><td style="padding:22px 32px 4px;">'
                . '<h2 style="font-size:16px;margin:0 0 12px;color:#10233f;">' . $icon . ' ' . esc_html( $title ) . '</h2>'
                . $inner . '</td></tr>'
                . '<tr><td style="padding:0 32px;"><hr style="border:none;border-top:1px solid #eef0f3;margin:6px 0;"></td></tr>';
        };

        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
        $html .= '<body style="margin:0;padding:0;background:#f4f5f7;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 14px;">';
        $html .= '<table role="presentation" width="640" style="max-width:640px;width:100%;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.06);">';

        // Header.
        $html .= '<tr><td style="background:#10233f;padding:26px 32px;">';
        $html .= '<p style="margin:0;color:#fff;font-size:19px;font-weight:700;">' . $name . '</p>';
        $html .= '<p style="margin:4px 0 0;color:#9fb0c9;font-size:13px;">' . esc_html( wp_date( 'l, F j, Y' ) );
        if ( $city ) {
            $html .= ' &middot; ' . $city;
        }
        $html .= '</p></td></tr>';

        // Best Traffic Play (priority + headline).
        $html .= $section( '&#127919;', __( 'Best Traffic Play', 'content-traffic-maker' ), array(
            __( 'Priority', 'content-traffic-maker' ) => '<strong style="color:' . ( $priority >= 8 ? '#1a7f37' : ( $priority >= 5 ? '#b26a00' : '#9aa0a6' ) ) . ';">' . esc_html( (string) $priority ) . '/10</strong>',
            __( 'Suggested headline', 'content-traffic-maker' ) => $b( 'headline' ),
        ) );

        // Guest Post Opportunity.
        $html .= $section( '&#9997;&#65039;', __( 'Guest Post Opportunity', 'content-traffic-maker' ), array(
            __( 'Topic', 'content-traffic-maker' )            => $b( 'guest_post_topic' ),
            __( 'Why it drives traffic', 'content-traffic-maker' ) => $b( 'why_traffic' ),
            __( 'Best publishing target', 'content-traffic-maker' ) => $b( 'publishing_target' ),
        ) );

        // Backlink Target.
        $html .= $section( '&#128279;', __( 'Backlink Target', 'content-traffic-maker' ), array(
            __( 'Local opportunity', 'content-traffic-maker' ) => $b( 'local_backlink' ),
            __( 'Outreach angle', 'content-traffic-maker' )    => $b( 'outreach_angle' ),
        ) );

        // Local Authority Link (.gov + nonprofit).
        $html .= $section( '&#127963;&#65039;', __( 'Local Authority Link', 'content-traffic-maker' ), array(
            __( '.gov idea', 'content-traffic-maker' )      => $b( 'gov_backlink' ),
            __( 'Nonprofit idea', 'content-traffic-maker' ) => $b( 'nonprofit_backlink' ),
        ) );

        // YouTube SEO Video (commercial + residential carpet/installation).
        $html .= $section( '&#9654;&#65039;', __( 'YouTube SEO Video', 'content-traffic-maker' ), array(
            __( 'Commercial', 'content-traffic-maker' )                       => $b( 'youtube_idea' ),
            __( 'Residential (carpet / installation)', 'content-traffic-maker' ) => $b( 'youtube_residential' ),
        ) );

        // TikTok — SEO + viral.
        $html .= $section( '&#127909;', __( 'TikTok Videos', 'content-traffic-maker' ), array(
            __( 'SEO video', 'content-traffic-maker' )   => $b( 'tiktok_seo' ),
            __( 'Viral video', 'content-traffic-maker' ) => $b( 'tiktok_viral' ),
        ) );

        // Residential Offer Video (carpet cleaning / installation).
        $html .= $section( '&#127968;', __( 'Residential Offer Video', 'content-traffic-maker' ), array(
            '' => $b( 'residential_offer_video' ),
        ) );

        // CTA.
        $html .= '<tr><td style="padding:18px 32px 26px;">';
        $html .= '<div style="background:#eef6ff;border:1px solid #cfe3ff;border-radius:10px;padding:16px 18px;">';
        $html .= '<span style="display:block;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#2563eb;margin-bottom:4px;">' . esc_html__( 'CTA to Use This Week', 'content-traffic-maker' ) . '</span>';
        $html .= '<span style="font-size:15px;color:#10233f;font-weight:600;">' . $b( 'cta' ) . '</span>';
        $html .= '</div></td></tr>';

        // Footer.
        $html .= '<tr><td style="background:#f7f8fa;padding:14px 32px;"><p style="margin:0;font-size:12px;color:#9aa0a6;">' . esc_html__( 'Generated by Content Traffic Maker.', 'content-traffic-maker' ) . '</p></td></tr>';

        $html .= '</table></td></tr></table></body></html>';
        return $html;
    }
}
