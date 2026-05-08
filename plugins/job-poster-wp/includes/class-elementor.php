<?php
/**
 * Optional Elementor integration. When Elementor is active, auto-applies a
 * generic two-section job listing layout (banner + content) using the active
 * theme's global colors and typography. Works with any Elementor theme
 * including Hello Elementor, Astra, GeneratePress, etc.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Elementor {

    public static function register(): void {
        add_action( 'save_post_dpjp_job', [ __CLASS__, 'maybe_apply_template' ], 20, 2 );
    }

    private static function elementor_active(): bool {
        return defined( 'ELEMENTOR_VERSION' ) && did_action( 'elementor/loaded' );
    }

    public static function maybe_apply_template( int $post_id, $post ): void {
        if ( ! self::elementor_active() ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;

        $existing = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $existing ) && $existing !== '[]' ) return;
        if ( empty( $post->post_title ) ) return;

        $meta = DPJP_Meta_Fields::get( $post_id );
        $template_data = self::build_template( $post, $meta );
        if ( ! $template_data ) return;

        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
        update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
        update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $template_data ) ) );
        update_post_meta( $post_id, '_elementor_page_settings', [ 'hide_title' => 'yes' ] );

        if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
            update_post_meta( $post_id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
        }

        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            $css = new \Elementor\Core\Files\CSS\Post( $post_id );
            $css->update();
        }
    }

    private static function build_template( $post, array $meta ): array {
        $title    = get_the_title( $post );
        $trade    = $meta['dpjp_trade'] ?? '';
        $pay      = $meta['dpjp_pay'] ?? '';
        $location = $meta['dpjp_location'] ?? '';
        $type_map = [ 'full-time' => 'Full Time', 'part-time' => 'Part Time', 'contract' => 'Contract', 'seasonal' => 'Seasonal' ];
        $type_label = $type_map[ $meta['dpjp_employment_type'] ?? 'full-time' ] ?? 'Full Time';
        $desc     = wp_strip_all_tags( $post->post_content );
        $reqs     = $meta['dpjp_requirements'] ?? '';
        $req_lines = array_filter( array_map( 'trim', explode( "\n", $reqs ) ) );
        $req_html  = '<ul>' . implode( '', array_map( function( $r ) { return '<li>' . esc_html( $r ) . '</li>'; }, $req_lines ) ) . '</ul>';

        return [
            self::banner_section( $title, $type_label, $location ),
            self::content_section( $title, $pay, $type_label, $location, $desc, $req_html, $meta ),
        ];
    }

    private static function banner_section( string $title, string $type, string $location ): array {
        $sub = trim( $type . ( $location ? ' • ' . $location : '' ) );
        return [
            'id' => self::gen_id(), 'elType' => 'section',
            'settings' => [
                'background_background' => 'classic',
                'background_color'      => '#0073aa',
                'padding' => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
                '_title' => 'job-banner',
            ],
            'elements' => [[
                'id' => self::gen_id(), 'elType' => 'column',
                'settings' => [ '_column_size' => 100, '_inline_size' => null ],
                'elements' => [
                    self::heading( $title, 'h1', 'center', 40, '700', '#ffffff' ),
                    self::heading( $sub, 'h4', 'center', 18, '400', '#ffffff' ),
                ],
                'isInner' => false,
            ]],
            'isInner' => false,
        ];
    }

    private static function content_section( string $title, string $pay, string $type, string $location, string $desc, string $req_html, array $meta ): array {
        $info_html = '<p><strong>' . esc_html__( 'Pay:', 'job-manager-pro' ) . '</strong> ' . esc_html( $pay ) . '<br>'
                   . '<strong>' . esc_html__( 'Type:', 'job-manager-pro' ) . '</strong> ' . esc_html( $type ) . '<br>'
                   . '<strong>' . esc_html__( 'Location:', 'job-manager-pro' ) . '</strong> ' . esc_html( $location ) . '</p>';

        $apply_html = '<h3>' . esc_html__( 'Ready to Apply?', 'job-manager-pro' ) . '</h3>'
                    . '<p>[dpjp_apply_form]</p>';

        $body = $info_html
              . '<h3>' . esc_html__( 'About the Role', 'job-manager-pro' ) . '</h3><p>' . esc_html( $desc ) . '</p>'
              . '<h3>' . esc_html__( 'Requirements', 'job-manager-pro' ) . '</h3>' . $req_html
              . $apply_html;

        return [
            'id' => self::gen_id(), 'elType' => 'section',
            'settings' => [
                'padding' => [ 'unit' => 'px', 'top' => '40', 'right' => '20', 'bottom' => '40', 'left' => '20', 'isLinked' => false ],
                '_title' => 'job-content',
            ],
            'elements' => [[
                'id' => self::gen_id(), 'elType' => 'column',
                'settings' => [ '_column_size' => 100, '_inline_size' => null ],
                'elements' => [ self::text_editor( $body ) ],
                'isInner' => false,
            ]],
            'isInner' => false,
        ];
    }

    private static function heading( string $text, string $tag, string $align, int $size, string $weight, string $color ): array {
        return [
            'id' => self::gen_id(), 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => [
                'title' => $text, 'header_size' => $tag, 'align' => $align,
                'title_color' => $color,
                'typography_typography' => 'custom',
                'typography_font_size' => [ 'unit' => 'px', 'size' => $size, 'sizes' => [] ],
                'typography_font_weight' => $weight,
                'typography_line_height' => [ 'unit' => 'em', 'size' => 1.2, 'sizes' => [] ],
            ],
            'elements' => [],
        ];
    }

    private static function text_editor( string $html ): array {
        return [
            'id' => self::gen_id(), 'elType' => 'widget', 'widgetType' => 'text-editor',
            'settings' => [ 'editor' => $html ],
            'elements' => [],
        ];
    }

    private static function gen_id(): string {
        return bin2hex( random_bytes( 4 ) );
    }
}
