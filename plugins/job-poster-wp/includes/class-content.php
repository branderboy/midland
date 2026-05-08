<?php
/**
 * Generates platform-specific post content from a job record for distribution
 * to Facebook, Indeed, Nextdoor, and Craigslist.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Content {

    /**
     * Build the " in the X area" phrase for a job location.
     *
     * Skips appending " area" when the location already ends in a geographic
     * suffix (area, region, metro, county, etc.) so we don't produce "Metro
     * Area area" or "Montgomery County area".
     */
    private static function location_phrase( string $location ): string {
        $location = trim( $location );
        if ( '' === $location ) {
            return '';
        }
        if ( preg_match( '/\b(area|region|metro|metropolitan|county|district|dmv)\b\s*$/i', $location ) ) {
            return " in the {$location}";
        }
        return " in the {$location} area";
    }

    public static function for_facebook( $post, array $meta ): string {
        $title    = get_the_title( $post );
        $trade    = $meta['dpjp_trade'] ?? '';
        $location = $meta['dpjp_location'] ?? '';
        $pay      = $meta['dpjp_pay'] ?? '';
        $type     = $meta['dpjp_employment_type'] ?? 'full-time';
        $desc     = wp_strip_all_tags( $post->post_content );
        $reqs     = self::req_list( $meta, '• ' );
        $cta      = $meta['dpjp_call_to_action'] ?? '';
        $name     = $meta['dpjp_contact_name'] ?? '';
        $phone    = $meta['dpjp_contact_phone'] ?? '';
        $email    = $meta['dpjp_contact_email'] ?? '';
        $tag      = $trade ? str_replace( ' ', '', $trade ) : 'Hiring';

        return "📋 NOW HIRING — {$title}
" . ( $trade ? "{$trade} | " : '' ) . "{$location}

We're looking for a reliable {$title}. " . ucfirst( $type ) . " position available now.

💰 Pay: {$pay}
📍 Location: {$location}
🕐 Type: {$type}

ABOUT THE ROLE
{$desc}

WHAT WE'RE LOOKING FOR
{$reqs}

{$cta}

" . ( $name && $phone ? "📞 Contact {$name}: {$phone}" : ( $phone ? "📞 Call/text: {$phone}" : '' ) ) . "
" . ( $email ? "📧 {$email}" : '' ) . "

#Hiring #{$tag} #NowHiring";
    }

    public static function for_nextdoor( $post, array $meta ): string {
        $title    = get_the_title( $post );
        $trade    = $meta['dpjp_trade'] ?? '';
        $location = $meta['dpjp_location'] ?? '';
        $pay      = $meta['dpjp_pay'] ?? '';
        $type     = $meta['dpjp_employment_type'] ?? 'full-time';
        $desc     = wp_strip_all_tags( $post->post_content );
        $reqs     = self::req_list( $meta, '- ' );
        $cta      = $meta['dpjp_call_to_action'] ?? '';
        $name     = $meta['dpjp_contact_name'] ?? '';
        $phone    = $meta['dpjp_contact_phone'] ?? '';
        $email    = $meta['dpjp_contact_email'] ?? '';
        $company  = get_bloginfo( 'name' );

        return "Hi neighbors — {$company} is hiring!

We have an opening for a {$title}" . self::location_phrase( $location ) . ".

This is a {$type} position paying {$pay}. We're a local team and we take care of our people.

About the position:
{$desc}

What we need from you:
{$reqs}

If you or someone you know might be a good fit, we'd love to hear from you.

{$cta}

" . ( $name && $phone ? "Reach {$name} at {$phone}" : ( $phone ? "Call/text {$phone}" : '' ) ) . ( $email ? " or email {$email}" : '' ) . ".

— {$company}";
    }

    public static function for_craigslist( $post, array $meta ): array {
        $title    = get_the_title( $post );
        $trade    = $meta['dpjp_trade'] ?? '';
        $location = $meta['dpjp_location'] ?? '';
        $pay      = $meta['dpjp_pay'] ?? '';
        $type     = $meta['dpjp_employment_type'] ?? 'full-time';
        $desc     = wp_strip_all_tags( $post->post_content );
        $reqs     = self::req_list( $meta, '  * ' );
        $cta      = $meta['dpjp_call_to_action'] ?? '';
        $name     = $meta['dpjp_contact_name'] ?? '';
        $phone    = $meta['dpjp_contact_phone'] ?? '';
        $email    = $meta['dpjp_contact_email'] ?? '';
        $region   = $meta['dpjp_craigslist_region'] ?? '';
        $company  = get_bloginfo( 'name' );

        $cl_title = "{$title} – {$trade} – {$pay} – {$location}";
        $cl_body  = "COMPANY: {$company}
LOCATION: {$location}
JOB TITLE: {$title}
TRADE: {$trade}
EMPLOYMENT TYPE: {$type}
COMPENSATION: {$pay}

─────────────────────────
JOB DESCRIPTION
─────────────────────────
{$desc}

─────────────────────────
REQUIREMENTS
─────────────────────────
{$reqs}

─────────────────────────
COMPENSATION
─────────────────────────
{$pay} — based on experience.

─────────────────────────
HOW TO APPLY
─────────────────────────
{$cta}

" . ( $name ? "Contact: {$name}\n" : '' )
        . ( $phone ? "Phone/Text: {$phone}\n" : '' )
        . ( $email ? "Email: {$email}\n" : '' );

        return [
            'title'    => $cl_title,
            'body'     => $cl_body,
            'post_url' => $region ? "https://{$region}.craigslist.org/post?s=E&category_all=jjj" : 'https://craigslist.org/',
        ];
    }

    public static function for_indeed( $post, array $meta ): string {
        $title    = get_the_title( $post );
        $trade    = $meta['dpjp_trade'] ?? '';
        $location = $meta['dpjp_location'] ?? '';
        $pay      = $meta['dpjp_pay'] ?? '';
        $type     = $meta['dpjp_employment_type'] ?? 'full-time';
        $desc     = wp_strip_all_tags( $post->post_content );
        $reqs     = self::req_list( $meta, '• ' );
        $cta      = $meta['dpjp_call_to_action'] ?? '';
        $name     = $meta['dpjp_contact_name'] ?? '';
        $phone    = $meta['dpjp_contact_phone'] ?? '';
        $email    = $meta['dpjp_contact_email'] ?? '';
        $company  = get_bloginfo( 'name' );

        return "About the Role
{$desc}

{$company} is hiring now for a {$type} {$title}" . self::location_phrase( $location ) . ".

Responsibilities
• Perform " . ( $trade ?: 'job duties' ) . " on assigned projects
• Work as part of a skilled, professional team
• Maintain a safe and organized work environment

Requirements
{$reqs}

Compensation
{$pay} — pay based on experience.

How to Apply
{$cta}

" . ( $name ? "Contact {$name}:\n" : '' )
        . ( $phone ? "📞 {$phone}\n" : '' )
        . ( $email ? "📧 {$email}" : '' );
    }

    private static function req_list( array $meta, string $prefix ): string {
        $raw  = $meta['dpjp_requirements'] ?? '';
        $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        return implode( "\n", array_map( function( $r ) use ( $prefix ) { return $prefix . $r; }, $lines ) );
    }
}
