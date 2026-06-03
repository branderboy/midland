<?php
/**
 * Emailer — plain text + HTML via Resend or wp_mail.
 * 6 videos: 3 commercial + 3 residential.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTM_Emailer {

    const SENT_DATE_OPT   = 'ctm_last_sent_date';
    const RESEND_ENDPOINT = 'https://api.resend.com/emails';

    public static function already_sent_today() {
        return get_option( self::SENT_DATE_OPT, '' ) === current_time( 'Y-m-d' );
    }

    public static function send( $brief, $settings, $html = '', $force = false ) {
        if ( ! $force && self::already_sent_today() ) return false;

        $to = sanitize_email( (string) ( $settings['recipient'] ?? '' ) );
        if ( ! is_email( $to ) ) return false;

        $subject = self::subject( $brief );
        $text    = self::render_text( $brief );
        if ( '' === $html ) $html = self::render_html( $brief );

        $resend_key = trim( (string) ( $settings['resend_api_key'] ?? '' ) );

        if ( '' !== $resend_key ) {
            $from_name  = sanitize_text_field( (string) ( $settings['from_name']  ?? 'Midland Floors' ) );
            $from_email = sanitize_email( (string) ( $settings['from_email'] ?? '' ) );
            if ( ! is_email( $from_email ) ) {
                $from_email = 'briefs@' . wp_parse_url( home_url(), PHP_URL_HOST );
            }
            $response = wp_remote_post( self::RESEND_ENDPOINT, array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $resend_key,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'from'    => $from_name . ' <' . $from_email . '>',
                    'to'      => array( $to ),
                    'subject' => $subject,
                    'html'    => $html,
                    'text'    => $text,
                ) ),
            ) );
            $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
            $sent = ( $code >= 200 && $code < 300 );
        } else {
            $sent = wp_mail( $to, $subject, $text, array( 'Content-Type: text/plain; charset=UTF-8' ) );
        }

        if ( $sent ) update_option( self::SENT_DATE_OPT, current_time( 'Y-m-d' ), false );
        return $sent;
    }

    public static function subject( $brief ) {
        $date = (string) ( $brief['brief_date'] ?? wp_date( 'M j, Y' ) );
        return 'Midland Floors Video Brief — ' . $date;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Plain text
    // ─────────────────────────────────────────────────────────────────────────

    public static function render_text( $brief ) {
        $b    = fn( $k ) => (string) ( $brief[ $k ] ?? '' );
        $date = $b('brief_date') ?: wp_date( 'F j, Y' );
        $sep  = str_repeat( '-', 48 );
        $eq   = str_repeat( '=', 48 );

        $ex = function( $title, $url ) {
            if ( ! $title ) return null;
            return $url ? "Example        {$title}\n               {$url}" : "Example        {$title}";
        };

        $lines = array(
            'MIDLAND FLOORS — VIDEO BRIEF',
            $date . ' | Washington DC',
            $eq, '',

            '━━ COMMERCIAL (Office Floor Cleaning) ━━━━━━━', '',

            '01  SEO VIDEO',
            $sep,
            'Video Title    ' . $b('com_seo_video_title'),
            'Keyword        ' . $b('com_seo_keyword'),
            'Search Intent  ' . $b('com_seo_search_intent'),
            'Hook           ' . $b('com_seo_hook'),
            'CTA            ' . $b('com_seo_cta'),
            'Difficulty     ' . $b('com_seo_difficulty'),
            'Priority       ' . $b('com_seo_priority') . '/10',
            $ex( $b('com_seo_example_title'), $b('com_seo_example_url') ),
            '',

            '02  OFFER VIDEO',
            $sep,
            'Video Title    ' . $b('com_offer_video_title'),
            'Offer          ' . $b('com_offer_name'),
            'Audience       ' . $b('com_offer_audience'),
            'Hook           ' . $b('com_offer_hook'),
            'CTA            ' . $b('com_offer_cta'),
            'Priority       ' . $b('com_offer_priority') . '/10',
            $ex( $b('com_offer_example_title'), $b('com_offer_example_url') ),
            '',

            '03  VIRAL VIDEO',
            $sep,
            'Video Title    ' . $b('com_viral_video_title'),
            'Trend Format   ' . $b('com_viral_trending_format'),
            'Concept        ' . $b('com_viral_concept'),
            'Opening Shot   ' . $b('com_viral_opening_shot'),
            'Why It Works   ' . $b('com_viral_trend_reason'),
            'CTA            ' . $b('com_viral_cta'),
            'Priority       ' . $b('com_viral_priority') . '/10',
            $ex( $b('com_viral_example_title'), $b('com_viral_example_url') ),
            '', $eq, '',

            '━━ RESIDENTIAL (Carpet Cleaning & Installation) ━━━', '',

            '04  SEO VIDEO',
            $sep,
            'Video Title    ' . $b('res_seo_video_title'),
            'Keyword        ' . $b('res_seo_keyword'),
            'Search Intent  ' . $b('res_seo_search_intent'),
            'Hook           ' . $b('res_seo_hook'),
            'CTA            ' . $b('res_seo_cta'),
            'Difficulty     ' . $b('res_seo_difficulty'),
            'Priority       ' . $b('res_seo_priority') . '/10',
            $ex( $b('res_seo_example_title'), $b('res_seo_example_url') ),
            '',

            '05  OFFER VIDEO',
            $sep,
            'Video Title    ' . $b('res_offer_video_title'),
            'Offer          ' . $b('res_offer_name'),
            'Audience       ' . $b('res_offer_audience'),
            'Hook           ' . $b('res_offer_hook'),
            'CTA            ' . $b('res_offer_cta'),
            'Priority       ' . $b('res_offer_priority') . '/10',
            $ex( $b('res_offer_example_title'), $b('res_offer_example_url') ),
            '',

            '06  VIRAL VIDEO',
            $sep,
            'Video Title    ' . $b('res_viral_video_title'),
            'Trend Format   ' . $b('res_viral_trending_format'),
            'Concept        ' . $b('res_viral_concept'),
            'Opening Shot   ' . $b('res_viral_opening_shot'),
            'Why It Works   ' . $b('res_viral_trend_reason'),
            'CTA            ' . $b('res_viral_cta'),
            'Priority       ' . $b('res_viral_priority') . '/10',
            $ex( $b('res_viral_example_title'), $b('res_viral_example_url') ),
            '', $eq,
            'Midland Floors — Content Traffic Maker',
        );

        return implode( "\n", array_filter( $lines, fn($l) => null !== $l ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTML
    // ─────────────────────────────────────────────────────────────────────────

    public static function render_html( $brief ) {
        $b   = fn( $k ) => esc_html( (string) ( $brief[ $k ] ?? '' ) );
        $u   = fn( $k ) => esc_url( (string) ( $brief[ $k ] ?? '' ) );
        $date = $b('brief_date') ?: esc_html( wp_date( 'F j, Y' ) );

        // Flat video block — no nested card chrome, just rows + example
        $video_block = function( $num, $accent, $label, $rows, $priority, $ex_title, $ex_url ) {
            $pri_color = $priority >= 8 ? '#16a34a' : ( $priority >= 5 ? '#d97706' : '#94a3b8' );
            $out  = '<tr><td style="padding:0 0 1px;">';
            // num + label bar
            $out .= '<table width="100%" cellpadding="0" cellspacing="0">';
            $out .= '<tr><td style="background:' . $accent . ';padding:9px 24px;">';
            $out .= '<table width="100%" cellpadding="0" cellspacing="0"><tr>';
            $out .= '<td style="color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">' . esc_html( $num . '  ' . $label ) . '</td>';
            $out .= '<td align="right"><span style="background:#fff;color:' . $pri_color . ';font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;">' . esc_html( (string) $priority ) . '/10</span></td>';
            $out .= '</tr></table></td></tr>';
            // rows
            $out .= '<tr><td style="padding:12px 24px 14px;background:#fff;">';
            $out .= '<table width="100%" cellpadding="0" cellspacing="0">';
            foreach ( $rows as $field => $value ) {
                if ( '' === (string) $value ) continue;
                $is_title = ( 'Video Title' === $field );
                $is_hook  = ( 'Hook' === $field );
                $is_kw    = ( 'Keyword' === $field );
                $is_cta   = ( 'CTA' === $field );
                $out .= '<tr><td style="width:100px;vertical-align:top;padding:4px 10px 4px 0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;white-space:nowrap;">' . esc_html( $field ) . '</td>';
                if ( $is_title ) {
                    $out .= '<td style="padding:4px 0;font-size:15px;font-weight:800;color:#0f172a;line-height:1.3;">' . $value . '</td>';
                } elseif ( $is_hook ) {
                    $out .= '<td style="padding:4px 0;font-size:14px;font-weight:700;color:#0f172a;font-style:italic;">&ldquo;' . $value . '&rdquo;</td>';
                } elseif ( $is_kw ) {
                    $out .= '<td style="padding:4px 0;"><span style="background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;padding:3px 8px;border-radius:4px;">' . $value . '</span></td>';
                } elseif ( $is_cta ) {
                    $out .= '<td style="padding:4px 0;"><span style="background:#f0fdf4;border-left:3px solid #16a34a;padding:4px 10px;font-size:13px;color:#15803d;font-weight:600;display:inline-block;">' . $value . '</span></td>';
                } else {
                    $out .= '<td style="padding:4px 0;font-size:13px;color:#374151;line-height:1.5;">' . $value . '</td>';
                }
                $out .= '</tr>';
            }
            $out .= '</table>';
            // example
            if ( $ex_title ) {
                $out .= '<div style="margin-top:10px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">';
                $out .= '<span style="font-size:10px;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:3px;">Example to Copy</span>';
                if ( $ex_url ) {
                    $out .= '<a href="' . $ex_url . '" style="font-size:12px;color:#1d4ed8;text-decoration:underline;" target="_blank">' . $ex_title . '</a>';
                } else {
                    $out .= '<span style="font-size:12px;color:#374151;">' . $ex_title . '</span>';
                }
                $out .= '</div>';
            }
            $out .= '</td></tr></table></td></tr>';
            return $out;
        };

        $section_hdr = function( $title, $sub, $bg ) {
            return '<tr><td style="background:' . $bg . ';padding:12px 24px;">'
                . '<span style="color:#fff;font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:.07em;">' . esc_html( $title ) . '</span>'
                . '<span style="color:rgba(255,255,255,.55);font-size:11px;margin-left:10px;">' . esc_html( $sub ) . '</span>'
                . '</td></tr>';
        };

        $gap = '<tr><td style="background:#f1f5f9;height:6px;font-size:0;">&nbsp;</td></tr>';

        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
        $html .= '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 10px;">';
        $html .= '<table role="presentation" width="600" style="max-width:600px;width:100%;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.10);">';

        // Header
        $html .= '<tr><td style="background:#0f172a;padding:20px 24px 16px;">';
        $html .= '<p style="margin:0;color:#fff;font-size:19px;font-weight:900;">Midland Floors</p>';
        $html .= '<p style="margin:3px 0 0;color:#475569;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Daily Video Brief &middot; ' . $date . ' &middot; Washington DC</p>';
        $html .= '</td></tr>';

        $html .= $section_hdr( 'Commercial', 'Office Floor Cleaning', '#1e3a5f' );
        $html .= $video_block( '01', '#1d4ed8', 'SEO Video', array(
            'Video Title'   => $b('com_seo_video_title'),
            'Keyword'       => $b('com_seo_keyword'),
            'Search Intent' => $b('com_seo_search_intent'),
            'Hook'          => $b('com_seo_hook'),
            'CTA'           => $b('com_seo_cta'),
            'Difficulty'    => $b('com_seo_difficulty'),
        ), (int)($brief['com_seo_priority']??0), $b('com_seo_example_title'), $u('com_seo_example_url') );

        $html .= $gap;
        $html .= $video_block( '02', '#1d4ed8', 'Offer Video', array(
            'Video Title' => $b('com_offer_video_title'),
            'Offer'       => $b('com_offer_name'),
            'Audience'    => $b('com_offer_audience'),
            'Hook'        => $b('com_offer_hook'),
            'CTA'         => $b('com_offer_cta'),
        ), (int)($brief['com_offer_priority']??0), $b('com_offer_example_title'), $u('com_offer_example_url') );

        $html .= $gap;
        $html .= $video_block( '03', '#1d4ed8', 'Viral Video', array(
            'Video Title'  => $b('com_viral_video_title'),
            'Trend Format' => $b('com_viral_trending_format'),
            'Concept'      => $b('com_viral_concept'),
            'Opening Shot' => $b('com_viral_opening_shot'),
            'Why It Works' => $b('com_viral_trend_reason'),
            'CTA'          => $b('com_viral_cta'),
        ), (int)($brief['com_viral_priority']??0), $b('com_viral_example_title'), $u('com_viral_example_url') );

        $html .= '<tr><td style="background:#f1f5f9;height:12px;font-size:0;">&nbsp;</td></tr>';
        $html .= $section_hdr( 'Residential', 'Carpet Cleaning & Installation', '#2e1065' );

        $html .= $video_block( '04', '#7c3aed', 'SEO Video', array(
            'Video Title'   => $b('res_seo_video_title'),
            'Keyword'       => $b('res_seo_keyword'),
            'Search Intent' => $b('res_seo_search_intent'),
            'Hook'          => $b('res_seo_hook'),
            'CTA'           => $b('res_seo_cta'),
            'Difficulty'    => $b('res_seo_difficulty'),
        ), (int)($brief['res_seo_priority']??0), $b('res_seo_example_title'), $u('res_seo_example_url') );

        $html .= $gap;
        $html .= $video_block( '05', '#7c3aed', 'Offer Video', array(
            'Video Title' => $b('res_offer_video_title'),
            'Offer'       => $b('res_offer_name'),
            'Audience'    => $b('res_offer_audience'),
            'Hook'        => $b('res_offer_hook'),
            'CTA'         => $b('res_offer_cta'),
        ), (int)($brief['res_offer_priority']??0), $b('res_offer_example_title'), $u('res_offer_example_url') );

        $html .= $gap;
        $html .= $video_block( '06', '#7c3aed', 'Viral Video', array(
            'Video Title'  => $b('res_viral_video_title'),
            'Trend Format' => $b('res_viral_trending_format'),
            'Concept'      => $b('res_viral_concept'),
            'Opening Shot' => $b('res_viral_opening_shot'),
            'Why It Works' => $b('res_viral_trend_reason'),
            'CTA'          => $b('res_viral_cta'),
        ), (int)($brief['res_viral_priority']??0), $b('res_viral_example_title'), $u('res_viral_example_url') );

        $html .= '<tr><td style="background:#0f172a;padding:12px 24px;">';
        $html .= '<p style="margin:0;font-size:11px;color:#475569;">Midland Floors &middot; Daily Video Brief &middot; Content Traffic Maker</p>';
        $html .= '</td></tr>';
        $html .= '</table></td></tr></table></body></html>';
        return $html;
    }
}
