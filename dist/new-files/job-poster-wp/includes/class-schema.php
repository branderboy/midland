<?php
/**
 * Google for Jobs — injects JobPosting JSON-LD + SEO meta on every job page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Schema {

    public static function register(): void {
        add_action( 'wp_head', [ __CLASS__, 'inject' ] );
    }

    public static function inject(): void {
        if ( ! is_singular( 'dpjp_job' ) ) return;
        $post = get_queried_object();
        if ( ! $post instanceof WP_Post ) return;

        $meta    = DPJP_Meta_Fields::get( $post->ID );
        $desc    = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        $salary  = self::parse_salary( $meta['dpjp_pay'] ?? '' );
        $typemap = [ 'full-time' => 'FULL_TIME', 'part-time' => 'PART_TIME', 'contract' => 'CONTRACTOR', 'seasonal' => 'TEMPORARY' ];
        $emptype = $typemap[ $meta['dpjp_employment_type'] ?? 'full-time' ] ?? 'FULL_TIME';

        $schema = [
            '@context'           => 'https://schema.org/',
            '@type'              => 'JobPosting',
            'title'              => get_the_title( $post ),
            'description'        => $desc,
            'datePosted'         => get_the_date( 'c', $post ),
            'employmentType'     => $emptype,
            'hiringOrganization' => [
                '@type'  => 'Organization',
                'name'   => get_bloginfo( 'name' ),
                'sameAs' => home_url(),
            ],
            'jobLocation' => [
                '@type'   => 'Place',
                'address' => [
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $meta['dpjp_location'] ?? '',
                    'addressCountry'  => 'US',
                ],
            ],
        ];

        if ( ! empty( $meta['dpjp_valid_through'] ) ) {
            $schema['validThrough'] = $meta['dpjp_valid_through'] . 'T23:59:00';
        }
        if ( $salary ) {
            $schema['baseSalary'] = $salary;
        }
        if ( ! empty( $meta['dpjp_requirements'] ) ) {
            $schema['qualifications'] = $meta['dpjp_requirements'];
        }

        // SEO meta
        $og_desc = esc_attr( 'Hiring: ' . get_the_title( $post ) . ' in ' . ( $meta['dpjp_location'] ?? '' ) . ' — ' . ( $meta['dpjp_pay'] ?? '' ) . '. Apply at ' . get_bloginfo( 'name' ) . '.' );
        $og_url  = esc_url( get_permalink( $post ) );
        $og_title = esc_attr( get_the_title( $post ) . ' | ' . get_bloginfo( 'name' ) );

        echo '<script type="application/ld+json">' . "\n" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n</script>\n";
        echo "<meta name='description' content='{$og_desc}'>\n";
        echo "<meta property='og:title' content='{$og_title}'>\n";
        echo "<meta property='og:description' content='{$og_desc}'>\n";
        echo "<meta property='og:url' content='{$og_url}'>\n";
        echo "<meta property='og:type' content='website'>\n";
        echo "<link rel='canonical' href='{$og_url}'>\n";
    }

    private static function parse_salary( string $pay ): ?array {
        if ( ! $pay ) return null;
        if ( stripos( $pay, '/yr' ) !== false || stripos( $pay, 'year' ) !== false ) {
            $unit = 'YEAR';
        } elseif ( stripos( $pay, '/wk' ) !== false || stripos( $pay, 'week' ) !== false ) {
            $unit = 'WEEK';
        } elseif ( stripos( $pay, '/day' ) !== false || stripos( $pay, 'daily' ) !== false ) {
            $unit = 'DAY';
        } elseif ( stripos( $pay, '/mo' ) !== false || stripos( $pay, 'month' ) !== false ) {
            $unit = 'MONTH';
        } else {
            $unit = 'HOUR';
        }
        preg_match_all( '/\d[\d,]*/', $pay, $m );
        if ( empty( $m[0] ) ) return null;
        $nums = array_map( fn( $n ) => (float) str_replace( ',', '', $n ), $m[0] );
        return [
            '@type'    => 'MonetaryAmount',
            'currency' => 'USD',
            'value'    => [ '@type' => 'QuantitativeValue', 'minValue' => $nums[0], 'maxValue' => $nums[1] ?? $nums[0], 'unitText' => $unit ],
        ];
    }
}
