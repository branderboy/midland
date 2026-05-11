<?php
/**
 * Plugin Name: Midland pCloud Embed
 * Plugin URI:  https://tagglefish.com/
 * Description: Embed a pCloud public folder/file viewer in any page via the [pcloud_embed code="..."] shortcode. Hides pCloud's header/footer chrome by default.
 * Version:     1.1.0
 * Author:      TaggleFish
 * License:     GPL v2 or later
 * Text Domain: midland-pcloud-embed
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'pcloud_embed', 'midland_pcloud_embed_shortcode' );

function midland_pcloud_embed_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'code'          => '',
            'height'        => '800',
            'width'         => '100%',
            'title'         => 'pCloud',
            'hide_chrome'   => 'true',
            'header_offset' => '120',
            'footer_offset' => '0',
        ),
        $atts,
        'pcloud_embed'
    );

    $code = preg_replace( '/[^A-Za-z0-9]/', '', (string) $atts['code'] );
    if ( '' === $code ) {
        return current_user_can( 'edit_posts' )
            ? '<p><strong>pcloud_embed:</strong> missing <code>code</code> attribute.</p>'
            : '';
    }

    $visible_height = preg_match( '/^\d+(px|%|vh)?$/', $atts['height'] ) ? $atts['height'] : '800';
    if ( ctype_digit( (string) $visible_height ) ) {
        $visible_height .= 'px';
    }

    $width = preg_match( '/^\d+(px|%)?$/', $atts['width'] ) ? $atts['width'] : '100%';
    if ( ctype_digit( (string) $width ) ) {
        $width .= 'px';
    }

    $hide_chrome = filter_var( $atts['hide_chrome'], FILTER_VALIDATE_BOOLEAN );
    $header_px   = $hide_chrome ? max( 0, (int) $atts['header_offset'] ) : 0;
    $footer_px   = $hide_chrome ? max( 0, (int) $atts['footer_offset'] ) : 0;

    $src = 'https://u.pcloud.link/publink/show?code=' . rawurlencode( $code );

    $wrapper_style = sprintf(
        'width:%s;max-width:100%%;margin:0 auto;position:relative;overflow:hidden;height:%s;',
        esc_attr( $width ),
        esc_attr( $visible_height )
    );

    // Iframe is taller than the wrapper by header+footer and shifted up by the header
    // offset, so pCloud's top bar (logo, account, Download / Save to pCloud, view toggles)
    // and any footer are clipped out of view by the wrapper's overflow:hidden.
    $iframe_style = sprintf(
        'border:0;display:block;position:absolute;left:0;right:0;top:-%dpx;width:100%%;height:calc(100%% + %dpx);',
        $header_px,
        $header_px + $footer_px
    );

    return sprintf(
        '<div class="midland-pcloud-embed" style="%1$s">'
        . '<iframe src="%2$s" style="%3$s" loading="lazy" allow="fullscreen" allowfullscreen '
        . 'referrerpolicy="no-referrer-when-downgrade" title="%4$s"></iframe>'
        . '<noscript><p><a href="%2$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></noscript>'
        . '</div>',
        $wrapper_style,
        esc_url( $src ),
        $iframe_style,
        esc_attr( $atts['title'] )
    );
}
