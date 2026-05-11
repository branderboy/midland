<?php
/**
 * Plugin Name: Midland pCloud Embed
 * Plugin URI:  https://tagglefish.com/
 * Description: Embed a pCloud public folder/file viewer in any page via the [pcloud_embed code="..."] shortcode.
 * Version:     1.0.0
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
            'code'   => '',
            'height' => '800',
            'width'  => '100%',
            'title'  => 'pCloud',
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

    $height = preg_match( '/^\d+(px|%)?$/', $atts['height'] ) ? $atts['height'] : '800';
    if ( ctype_digit( (string) $height ) ) {
        $height .= 'px';
    }
    $width = preg_match( '/^\d+(px|%)?$/', $atts['width'] ) ? $atts['width'] : '100%';
    if ( ctype_digit( (string) $width ) ) {
        $width .= 'px';
    }

    $src = 'https://u.pcloud.link/publink/show?code=' . rawurlencode( $code );

    return sprintf(
        '<div class="midland-pcloud-embed" style="width:%1$s;max-width:100%%;margin:0 auto;">'
        . '<iframe src="%2$s" width="100%%" height="%3$s" style="border:0;display:block;" '
        . 'loading="lazy" allow="fullscreen" allowfullscreen referrerpolicy="no-referrer-when-downgrade" '
        . 'title="%4$s"></iframe>'
        . '<noscript><p><a href="%2$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></noscript>'
        . '</div>',
        esc_attr( $width ),
        esc_url( $src ),
        esc_attr( $height ),
        esc_attr( $atts['title'] )
    );
}
