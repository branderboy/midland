<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Programmatic city × service pages.
 * Registers mfc_location CPT and mfc_service taxonomy.
 * Admin: add locations, manage services, bulk-generate pages.
 * Each generated page gets title, meta description, and LocalBusiness+Service schema injected.
 */
class RSSEO_Pro_Programmatic {

    const CPT      = 'mfc_location';
    const TAXONOMY = 'mfc_service';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',             array( $this, 'register_cpt' ) );
        add_action( 'init',             array( $this, 'register_taxonomy' ) );
        add_action( 'admin_menu',       array( $this, 'add_menu' ), 28 );
        add_action( 'admin_init',       array( $this, 'handle_bulk_generate' ) );
        add_action( 'admin_init',       array( $this, 'handle_save_location' ) );
        add_action( 'wp_head',          array( $this, 'output_location_schema' ) );
        add_filter( 'document_title_parts', array( $this, 'filter_location_title' ) );
        add_action( 'wp_head',          array( $this, 'output_location_meta' ), 2 );
    }

    public function register_cpt() {
        register_post_type( self::CPT, array(
            'labels' => array(
                'name'          => __( 'Locations', 'real-smart-seo-pro' ),
                'singular_name' => __( 'Location', 'real-smart-seo-pro' ),
                'add_new_item'  => __( 'Add New Location', 'real-smart-seo-pro' ),
                'edit_item'     => __( 'Edit Location', 'real-smart-seo-pro' ),
            ),
            'public'       => true,
            'has_archive'  => false,
            'rewrite'      => array( 'slug' => 'service-area', 'with_front' => false ),
            'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-location',
        ) );
    }

    public function register_taxonomy() {
        register_taxonomy( self::TAXONOMY, self::CPT, array(
            'labels' => array(
                'name'          => __( 'Services', 'real-smart-seo-pro' ),
                'singular_name' => __( 'Service', 'real-smart-seo-pro' ),
            ),
            'public'            => true,
            'hierarchical'      => false,
            'rewrite'           => array( 'slug' => 'service' ),
            'show_in_rest'      => true,
            'show_admin_column' => true,
        ) );
    }

    public function add_menu() {
        add_submenu_page(
            'real-smart-seo',
            esc_html__( 'Programmatic Pages', 'real-smart-seo-pro' ),
            esc_html__( 'Programmatic Pages', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-programmatic',
            array( $this, 'render_page' )
        );
    }

    /**
     * Save or update a single location's meta (city, state, Wikipedia URL, service list).
     */
    public function handle_save_location() {
        if ( ! isset( $_POST['rsseo_save_location'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_rsseo_prog_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_prog_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_programmatic' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $city      = sanitize_text_field( wp_unslash( $_POST['location_city'] ?? '' ) );
        $state     = sanitize_text_field( wp_unslash( $_POST['location_state'] ?? '' ) );
        $wiki_url  = esc_url_raw( wp_unslash( $_POST['location_wiki_url'] ?? '' ) );
        $services  = isset( $_POST['location_services'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['location_services'] ) ) : array();

        if ( empty( $city ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-programmatic&error=no_city' ) );
            exit;
        }

        // Check if location CPT post exists.
        $existing = get_posts( array(
            'post_type'  => self::CPT,
            'title'      => $city . ', ' . $state,
            'fields'     => 'ids',
            'numberposts' => 1,
        ) );

        if ( $existing ) {
            $post_id = $existing[0];
        } else {
            $post_id = wp_insert_post( array(
                'post_type'   => self::CPT,
                'post_title'  => $city . ', ' . $state,
                'post_name'   => sanitize_title( $city . '-' . $state ),
                'post_status' => 'publish',
                'post_content' => $this->generate_location_content( $city, $state, $services ),
            ) );
        }

        if ( is_wp_error( $post_id ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-programmatic&error=insert_fail' ) );
            exit;
        }

        update_post_meta( $post_id, '_mfc_city', $city );
        update_post_meta( $post_id, '_mfc_state', $state );
        update_post_meta( $post_id, '_mfc_wiki_url', $wiki_url );
        update_post_meta( $post_id, '_mfc_services', $services );

        $this->apply_elementor_template( $post_id, $city, $state, $services );

        // Assign service terms.
        $term_ids = array();
        foreach ( $services as $service ) {
            $term = get_term_by( 'name', $service, self::TAXONOMY );
            if ( ! $term ) {
                $term = wp_insert_term( $service, self::TAXONOMY );
                if ( ! is_wp_error( $term ) ) {
                    $term_ids[] = $term['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }
        wp_set_post_terms( $post_id, $term_ids, self::TAXONOMY );

        // Trigger IndexNow.
        do_action( 'rsseo_indexnow_ping', get_permalink( $post_id ) );

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-programmatic&location_saved=1&post_id=' . $post_id ) );
        exit;
    }

    /**
     * Bulk generate location pages from a CSV-style textarea: City, State, Wikipedia URL (optional).
     */
    public function handle_bulk_generate() {
        if ( ! isset( $_POST['rsseo_bulk_generate'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_rsseo_prog_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_prog_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_programmatic' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $raw_locations = sanitize_textarea_field( wp_unslash( $_POST['bulk_locations'] ?? '' ) );
        $bulk_services = isset( $_POST['bulk_services'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['bulk_services'] ) ) : array();

        $lines = array_filter( array_map( 'trim', explode( "\n", $raw_locations ) ) );

        // Hard cap to keep wp_insert_post + IndexNow + term creation under one request budget.
        // Bigger batches should be split or queued externally.
        $batch_limit = (int) apply_filters( 'rsseo_programmatic_batch_limit', 50 );
        $skipped     = max( 0, count( $lines ) - $batch_limit );
        $lines       = array_slice( $lines, 0, $batch_limit );

        $created = 0;
        $urls    = array();

        foreach ( $lines as $line ) {
            // Limit to 3 splits so a Wikipedia URL like "Bethesda,_Maryland" stays intact in $parts[2].
            $parts = array_map( 'trim', explode( ',', $line, 3 ) );
            $city  = $parts[0] ?? '';
            $state = $parts[1] ?? 'MD';
            $wiki  = $parts[2] ?? '';

            if ( empty( $city ) ) {
                continue;
            }

            // Match on city+state so "Bethesda, MD" and "Bethesda, OH" don't collide.
            $existing = get_posts( array(
                'post_type'   => self::CPT,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query'  => array(
                    'relation' => 'AND',
                    array( 'key' => '_mfc_city', 'value' => $city, 'compare' => '=' ),
                    array( 'key' => '_mfc_state', 'value' => $state, 'compare' => '=' ),
                ),
                'fields'      => 'ids',
                'numberposts' => 1,
            ) );

            if ( $existing ) {
                $post_id = $existing[0];
            } else {
                $post_id = wp_insert_post( array(
                    'post_type'    => self::CPT,
                    'post_title'   => $city . ', ' . $state,
                    'post_name'    => sanitize_title( $city . '-' . strtolower( $state ) ),
                    'post_status'  => 'publish',
                    'post_content' => $this->generate_location_content( $city, $state, $bulk_services ),
                ) );
                $created++;
            }

            if ( is_wp_error( $post_id ) ) {
                continue;
            }

            update_post_meta( $post_id, '_mfc_city', $city );
            update_post_meta( $post_id, '_mfc_state', $state );
            if ( $wiki ) {
                update_post_meta( $post_id, '_mfc_wiki_url', esc_url_raw( $wiki ) );
            }
            update_post_meta( $post_id, '_mfc_services', $bulk_services );

            $this->apply_elementor_template( $post_id, $city, $state, $bulk_services );

            $term_ids = array();
            foreach ( $bulk_services as $service ) {
                $term = get_term_by( 'name', $service, self::TAXONOMY );
                if ( ! $term ) {
                    $term = wp_insert_term( $service, self::TAXONOMY );
                    $term_ids[] = is_wp_error( $term ) ? 0 : $term['term_id'];
                } else {
                    $term_ids[] = $term->term_id;
                }
            }
            wp_set_post_terms( $post_id, array_filter( $term_ids ), self::TAXONOMY );

            $permalink = get_permalink( $post_id );
            if ( $permalink ) {
                $urls[] = $permalink;
            }
        }

        // Batch ping IndexNow.
        if ( $urls ) {
            do_action( 'rsseo_indexnow_batch_ping', $urls );
        }

        $redirect = admin_url( 'admin.php?page=rsseo-programmatic&generated=' . $created );
        if ( $skipped > 0 ) {
            $redirect = add_query_arg( 'skipped', $skipped, $redirect );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Generate template content for a location page.
     */
    private function generate_location_content( $city, $state, $services ) {
        $business = get_bloginfo( 'name' );
        $services_list = implode( ', ', $services );
        $services_html = '';
        foreach ( $services as $svc ) {
            $services_html .= '<li>' . esc_html( $svc ) . ' in ' . esc_html( $city ) . ', ' . esc_html( $state ) . '</li>';
        }

        return "<h2>Professional Floor Care Services in {$city}, {$state}</h2>
<p>{$business} proudly serves {$city} and the surrounding {$state} area with expert floor care services including {$services_list}. Our certified technicians bring professional-grade equipment directly to your home or business.</p>
<h3>Services Available in {$city}, {$state}</h3>
<ul>{$services_html}</ul>
<h3>Why Choose {$business} in {$city}?</h3>
<ul>
<li>Licensed and insured — serving the DMV metro area</li>
<li>Same-day and next-day appointments available</li>
<li>Residential and commercial — no job too large or small</li>
<li>Free estimates with upfront, transparent pricing</li>
</ul>
<p>Ready to get started? <a href=\"/contact/\">Request a free quote</a> or call us today. We serve {$city} and all surrounding neighborhoods in {$state}.</p>";
    }

    /**
     * Write Elementor builder data so location pages render with the same
     * Hero / Content / CTA layout used by the Midland Elementor Kit service pages.
     */
    private function apply_elementor_template( $post_id, $city, $state, $services ) {
        $data = $this->generate_elementor_data( $city, $state, $services );

        // wp_slash because update_post_meta runs through wp_unslash on read,
        // and Elementor expects the JSON to survive that round-trip intact.
        update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
        update_post_meta( $post_id, '_elementor_version', '3.21.0' );
        update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
    }

    /**
     * Build the three-section Elementor structure (Hero, Content, CTA) that
     * mirrors templates/commercial-carpet-cleaning-services.json from the kit.
     */
    private function generate_elementor_data( $city, $state, $services ) {
        $business = get_bloginfo( 'name' );
        $identity = get_option( 'rsseo_sameas_identity', array() );
        $phone    = ! empty( $identity['business_phone'] ) ? $identity['business_phone'] : '(240) 532-9097';
        $tel_href = 'tel:' . preg_replace( '/[^0-9+]/', '', $phone );

        $primary       = ! empty( $services ) ? $services[0] : 'Floor Care';
        $hero_title    = $primary . ' in ' . $city . ', ' . $state;
        $services_list = ! empty( $services ) ? implode( ', ', $services ) : 'floor care services';
        $intro         = "Professional {$services_list} for businesses and property managers in {$city}, {$state}. Same-day quotes, after-hours service, and the Midland Shine Standard on every visit.";

        $body_html  = "<h2><strong>{$primary} in {$city}, {$state} That Protects Your Brand Image and Investment</strong></h2>";
        $body_html .= "<p>{$business} serves {$city} and the surrounding {$state} area with commercial-grade floor care. From high-traffic lobbies to back-of-house corridors, we keep your facility looking sharp and operating safely.</p>";

        if ( ! empty( $services ) ) {
            $body_html .= '<p><strong>Services available in ' . esc_html( $city ) . ', ' . esc_html( $state ) . '</strong></p><ul>';
            foreach ( $services as $svc ) {
                $body_html .= '<li><p>' . esc_html( $svc ) . ' &mdash; tailored to ' . esc_html( $city ) . ' facilities</p></li>';
            }
            $body_html .= '</ul>';
        }

        $body_html .= '<p><strong>Why ' . esc_html( $city ) . ' businesses choose ' . esc_html( $business ) . '</strong></p><ul>'
            . '<li><p>Licensed and insured &mdash; serving the DMV metro area</p></li>'
            . '<li><p>Same-day and next-day appointments available</p></li>'
            . '<li><p>Commercial-grade equipment for deeper, longer-lasting results</p></li>'
            . '<li><p>Flexible scheduling: after-hours and weekends available</p></li>'
            . '<li><p>Satisfaction guaranteed: backed by our Midland Shine Standard</p></li>'
            . '</ul>';

        $body_html .= '<p><a href="/schedule-a-visit/"><strong>Ready to schedule an on-site visit in ' . esc_html( $city ) . '?</strong></a><br />Call us or request a visit online and we&rsquo;ll build a plan around your facility.</p>';

        $flex_gap_20 = array( 'column' => '20', 'row' => '20', 'isLinked' => true, 'unit' => 'px', 'size' => 20 );
        $pad_zero    = array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true );

        return array(
            // SECTION 1 — Hero
            array(
                'id'       => $this->elementor_id(),
                'elType'   => 'container',
                'settings' => array(
                    '_title'                => 'Hero - ' . $hero_title,
                    'background_background' => 'classic',
                    'background_color'      => '#F3FCF4',
                    'content_width'         => 'full',
                    'flex_direction'        => 'column',
                    'flex_gap'              => $flex_gap_20,
                    'padding'               => $pad_zero,
                ),
                'elements' => array(
                    array(
                        'id'       => $this->elementor_id(),
                        'elType'   => 'container',
                        'settings' => array(
                            'flex_direction'    => 'column',
                            'content_width'     => 'boxed',
                            'flex_gap'          => $flex_gap_20,
                            'boxed_width'       => array( 'unit' => 'px', 'size' => 920, 'sizes' => array() ),
                            'padding'           => array( 'unit' => 'em', 'top' => '3', 'right' => '1.5', 'bottom' => '2', 'left' => '1.5', 'isLinked' => false ),
                            'flex_align_items'  => 'center',
                        ),
                        'elements' => array(
                            array(
                                'id'         => $this->elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'heading',
                                'settings'   => array(
                                    'title'                  => 'Service Area',
                                    'header_size'            => 'h6',
                                    'align'                  => 'center',
                                    'title_color'            => '#2F8137',
                                    'typography_typography'  => 'custom',
                                    'typography_font_size'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
                                    'typography_font_weight' => '800',
                                    '_margin'                => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '10', 'left' => '0', 'isLinked' => false ),
                                ),
                                'elements' => array(),
                            ),
                            array(
                                'id'         => $this->elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'heading',
                                'settings'   => array(
                                    'title'                   => $hero_title,
                                    'header_size'             => 'h1',
                                    'align'                   => 'center',
                                    'title_color'             => '#0F1411',
                                    'typography_typography'   => 'custom',
                                    'typography_font_size'    => array( 'unit' => 'px', 'size' => 44, 'sizes' => array() ),
                                    'typography_font_weight'  => '800',
                                    'typography_line_height'  => array( 'unit' => 'em', 'size' => 1.05, 'sizes' => array() ),
                                ),
                                'elements' => array(),
                            ),
                            array(
                                'id'         => $this->elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'text-editor',
                                'settings'   => array(
                                    'editor'                  => '<p>' . esc_html( $intro ) . '</p>',
                                    'align'                   => 'center',
                                    'text_color'              => '#4B5563',
                                    'typography_typography'   => 'custom',
                                    'typography_font_size'    => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
                                    'typography_line_height'  => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
                                ),
                                'elements' => array(),
                            ),
                        ),
                    ),
                ),
            ),

            // SECTION 2 — Content
            array(
                'id'       => $this->elementor_id(),
                'elType'   => 'container',
                'settings' => array(
                    '_title'                => 'Content',
                    'background_background' => 'classic',
                    'background_color'      => '#FFFFFF',
                    'content_width'         => 'full',
                    'flex_direction'        => 'column',
                    'flex_gap'              => $flex_gap_20,
                    'padding'               => $pad_zero,
                ),
                'elements' => array(
                    array(
                        'id'       => $this->elementor_id(),
                        'elType'   => 'container',
                        'settings' => array(
                            'flex_direction' => 'column',
                            'content_width'  => 'boxed',
                            'flex_gap'       => $flex_gap_20,
                            'boxed_width'    => array( 'unit' => 'px', 'size' => 820, 'sizes' => array() ),
                            'padding'        => array( 'unit' => 'em', 'top' => '2', 'right' => '1.5', 'bottom' => '3', 'left' => '1.5', 'isLinked' => false ),
                        ),
                        'elements' => array(
                            array(
                                'id'         => $this->elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'text-editor',
                                'settings'   => array(
                                    'editor'                  => $body_html,
                                    'align'                   => 'left',
                                    'text_color'              => '#0F1411',
                                    'typography_typography'   => 'custom',
                                    'typography_font_size'    => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
                                    'typography_line_height'  => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
                                ),
                                'elements' => array(),
                            ),
                        ),
                    ),
                ),
            ),

            // SECTION 3 — CTA
            array(
                'id'       => $this->elementor_id(),
                'elType'   => 'container',
                'settings' => array(
                    '_title'                => 'CTA',
                    'background_background' => 'classic',
                    'background_color'      => '#0E2F14',
                    'content_width'         => 'full',
                    'flex_direction'        => 'column',
                    'flex_gap'              => $flex_gap_20,
                    'padding'               => $pad_zero,
                ),
                'elements' => array(
                    array(
                        'id'       => $this->elementor_id(),
                        'elType'   => 'container',
                        'settings' => array(
                            'flex_direction'   => 'column',
                            'content_width'    => 'boxed',
                            'flex_gap'         => $flex_gap_20,
                            'boxed_width'      => array( 'unit' => 'px', 'size' => 920, 'sizes' => array() ),
                            'padding'          => array( 'unit' => 'em', 'top' => '3', 'right' => '1.5', 'bottom' => '3', 'left' => '1.5', 'isLinked' => false ),
                            'flex_align_items' => 'center',
                        ),
                        'elements' => array(
                            array(
                                'id'         => $this->elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'heading',
                                'settings'   => array(
                                    'title'                  => 'Ready for floors that sell for you?',
                                    'header_size'            => 'h2',
                                    'align'                  => 'center',
                                    'title_color'            => '#FFFFFF',
                                    'typography_typography'  => 'custom',
                                    'typography_font_size'   => array( 'unit' => 'px', 'size' => 36, 'sizes' => array() ),
                                    'typography_font_weight' => '800',
                                ),
                                'elements' => array(),
                            ),
                            array(
                                'id'         => $this->elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'text-editor',
                                'settings'   => array(
                                    'editor'                 => '<p>Free on-site evaluation and Facility Score in 48 hours for ' . esc_html( $city ) . ', ' . esc_html( $state ) . '.</p>',
                                    'align'                  => 'center',
                                    'text_color'             => '#B7E5BD',
                                    'typography_typography'  => 'custom',
                                    'typography_font_size'   => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
                                ),
                                'elements' => array(),
                            ),
                            array(
                                'id'       => $this->elementor_id(),
                                'elType'   => 'container',
                                'settings' => array(
                                    'flex_direction'       => 'row',
                                    'content_width'        => 'full',
                                    'flex_gap'             => array( 'column' => '12', 'row' => '12', 'isLinked' => true, 'unit' => 'px', 'size' => 12 ),
                                    'padding'              => $pad_zero,
                                    'flex_align_items'     => 'center',
                                    'flex_justify_content' => 'center',
                                ),
                                'elements' => array(
                                    array(
                                        'id'         => $this->elementor_id(),
                                        'elType'     => 'widget',
                                        'widgetType' => 'button',
                                        'settings'   => array(
                                            'text'                          => $phone,
                                            'link'                          => array( 'url' => $tel_href, 'is_external' => '', 'nofollow' => '' ),
                                            'size'                          => 'lg',
                                            'align'                         => 'center',
                                            'background_color'              => '#43A94B',
                                            'button_text_color'             => '#FFFFFF',
                                            'border_border'                 => 'solid',
                                            'border_color'                  => '#43A94B',
                                            'border_width'                  => array( 'unit' => 'px', 'top' => '2', 'right' => '2', 'bottom' => '2', 'left' => '2', 'isLinked' => true ),
                                            'border_radius'                 => array( 'unit' => 'px', 'top' => '4', 'right' => '4', 'bottom' => '4', 'left' => '4', 'isLinked' => true ),
                                            'typography_typography'         => 'custom',
                                            'typography_font_weight'        => '800',
                                            'typography_text_transform'     => 'uppercase',
                                            'typography_letter_spacing'     => array( 'unit' => 'px', 'size' => 1, 'sizes' => array() ),
                                            'typography_font_size'          => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
                                            'text_padding'                  => array( 'unit' => 'px', 'top' => '20', 'right' => '32', 'bottom' => '20', 'left' => '32', 'isLinked' => false ),
                                            'hover_color'                   => '#FFFFFF',
                                            'button_background_hover_color' => '#2F8137',
                                            'border_hover_color'            => '#2F8137',
                                        ),
                                        'elements' => array(),
                                    ),
                                    array(
                                        'id'         => $this->elementor_id(),
                                        'elType'     => 'widget',
                                        'widgetType' => 'button',
                                        'settings'   => array(
                                            'text'                          => 'Schedule a Visit',
                                            'link'                          => array( 'url' => '/schedule-a-visit/', 'is_external' => '', 'nofollow' => '' ),
                                            'size'                          => 'lg',
                                            'align'                         => 'center',
                                            'background_color'              => '#FFFFFF',
                                            'button_text_color'             => '#2F8137',
                                            'border_border'                 => 'solid',
                                            'border_color'                  => '#FFFFFF',
                                            'border_width'                  => array( 'unit' => 'px', 'top' => '2', 'right' => '2', 'bottom' => '2', 'left' => '2', 'isLinked' => true ),
                                            'border_radius'                 => array( 'unit' => 'px', 'top' => '4', 'right' => '4', 'bottom' => '4', 'left' => '4', 'isLinked' => true ),
                                            'typography_typography'         => 'custom',
                                            'typography_font_weight'        => '800',
                                            'typography_text_transform'     => 'uppercase',
                                            'typography_letter_spacing'     => array( 'unit' => 'px', 'size' => 1, 'sizes' => array() ),
                                            'typography_font_size'          => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
                                            'text_padding'                  => array( 'unit' => 'px', 'top' => '20', 'right' => '32', 'bottom' => '20', 'left' => '32', 'isLinked' => false ),
                                            'hover_color'                   => '#FFFFFF',
                                            'button_background_hover_color' => '#43A94B',
                                            'border_hover_color'            => '#43A94B',
                                        ),
                                        'elements' => array(),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Elementor element IDs are 7-char lowercase hex in the kit; matching that
     * format keeps the editor happy.
     */
    private function elementor_id() {
        return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
    }

    /**
     * Output LocalBusiness + Service schema on location CPT single pages.
     */
    public function output_location_schema() {
        if ( ! is_singular( self::CPT ) ) {
            return;
        }

        $post_id  = get_the_ID();
        $city     = get_post_meta( $post_id, '_mfc_city', true );
        $state    = get_post_meta( $post_id, '_mfc_state', true );
        $wiki_url = get_post_meta( $post_id, '_mfc_wiki_url', true );
        $services = get_post_meta( $post_id, '_mfc_services', true ) ?: array();

        $identity = get_option( 'rsseo_sameas_identity', array() );
        $business_name = $identity['business_name'] ?? get_bloginfo( 'name' );
        $site_url = trailingslashit( home_url() );

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => array( 'LocalBusiness', 'CleaningService' ),
            '@id'      => $site_url . '#business',
            'name'     => $business_name,
            'url'      => get_permalink( $post_id ),
            'areaServed' => array(
                array(
                    '@type' => 'City',
                    'name'  => $city,
                    'containedInPlace' => array(
                        '@type'  => 'State',
                        'name'   => $state,
                        'sameAs' => 'https://en.wikipedia.org/wiki/' . rawurlencode( $state ),
                    ),
                ),
            ),
        );

        if ( $wiki_url ) {
            $schema['areaServed'][0]['sameAs'] = $wiki_url;
        }

        if ( ! empty( $identity['business_phone'] ) ) {
            $schema['telephone'] = $identity['business_phone'];
        }

        if ( $services ) {
            $schema['hasOfferCatalog'] = array(
                '@type'     => 'OfferCatalog',
                'name'      => 'Services in ' . $city . ', ' . $state,
                'itemListElement' => array_map( function( $svc ) use ( $city, $state ) {
                    return array(
                        '@type'       => 'Offer',
                        'itemOffered' => array(
                            '@type'       => 'Service',
                            'name'        => $svc . ' in ' . $city . ', ' . $state,
                            'areaServed'  => $city . ', ' . $state,
                        ),
                    );
                }, $services ),
            );
        }

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Set dynamic title for location pages: "Service in City, State | Business"
     */
    public function filter_location_title( $parts ) {
        if ( ! is_singular( self::CPT ) ) {
            return $parts;
        }

        $post_id  = get_the_ID();
        $city     = get_post_meta( $post_id, '_mfc_city', true );
        $state    = get_post_meta( $post_id, '_mfc_state', true );
        $services = get_post_meta( $post_id, '_mfc_services', true ) ?: array();
        $primary  = $services[0] ?? 'Floor Care';

        $parts['title'] = $primary . ' in ' . $city . ', ' . $state;
        return $parts;
    }

    /**
     * Output meta description for location pages.
     */
    public function output_location_meta() {
        if ( ! is_singular( self::CPT ) ) {
            return;
        }

        $post_id  = get_the_ID();
        $city     = get_post_meta( $post_id, '_mfc_city', true );
        $state    = get_post_meta( $post_id, '_mfc_state', true );
        $services = get_post_meta( $post_id, '_mfc_services', true ) ?: array();
        $business = get_option( 'rsseo_sameas_identity', array() )['business_name'] ?? get_bloginfo( 'name' );

        $svc_str = ! empty( $services ) ? implode( ', ', array_slice( $services, 0, 3 ) ) : 'floor care';
        $desc    = "Expert {$svc_str} in {$city}, {$state}. {$business} serves the DMV area — licensed, insured, same-day available. Free quote.";

        // Multibyte-safe truncate so a Spanish/French character at the boundary doesn't get half-chopped.
        $truncated = function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, 160 ) : substr( $desc, 0, 160 );
        echo '<meta name="description" content="' . esc_attr( $truncated ) . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function render_page() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $generated      = isset( $_GET['generated'] ) ? absint( $_GET['generated'] ) : -1;
        $skipped        = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
        $location_saved = isset( $_GET['location_saved'] );
        $error          = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
        // phpcs:enable

        $default_services = array(
            'Carpet Cleaning',
            'Hardwood Floor Refinishing',
            'Tile & Grout Cleaning',
            'Vinyl Floor Cleaning',
            'Commercial Floor Care',
            'Area Rug Cleaning',
            'Floor Waxing & Buffing',
            'Water Damage & Restoration',
        );

        $all_terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) );
        $existing_services = array_map( function( $t ) { return $t->name; }, is_array( $all_terms ) ? $all_terms : array() );
        $all_services = array_unique( array_merge( $default_services, $existing_services ) );
        sort( $all_services );

        $location_posts = get_posts( array(
            'post_type'   => self::CPT,
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Programmatic City × Service Pages', 'real-smart-seo-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Generate location × service pages at scale. Each page gets optimized title, meta description, and LocalBusiness+Service schema automatically.', 'real-smart-seo-pro' ); ?></p>

            <?php if ( $generated >= 0 ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( '%d new location pages generated and submitted to IndexNow.', 'real-smart-seo-pro' ), $generated ); ?></p></div>
            <?php endif; ?>
            <?php if ( $skipped > 0 ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php printf( esc_html__( '%d locations skipped because the batch limit was exceeded. Submit them in another run.', 'real-smart-seo-pro' ), $skipped ); ?></p></div>
            <?php endif; ?>
            <?php if ( $location_saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Location saved and submitted to IndexNow.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

                <div>
                    <h2><?php esc_html_e( 'Add Single Location', 'real-smart-seo-pro' ); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'rsseo_programmatic', '_rsseo_prog_nonce' ); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="location_city"><?php esc_html_e( 'City', 'real-smart-seo-pro' ); ?></label></th>
                                <td><input type="text" id="location_city" name="location_city" class="regular-text" placeholder="Bethesda"></td>
                            </tr>
                            <tr>
                                <th><label for="location_state"><?php esc_html_e( 'State', 'real-smart-seo-pro' ); ?></label></th>
                                <td><input type="text" id="location_state" name="location_state" style="width:80px;" placeholder="MD" value="MD"></td>
                            </tr>
                            <tr>
                                <th><label for="location_wiki_url"><?php esc_html_e( 'Wikipedia URL (sameAs)', 'real-smart-seo-pro' ); ?></label></th>
                                <td>
                                    <input type="url" id="location_wiki_url" name="location_wiki_url" class="large-text" placeholder="https://en.wikipedia.org/wiki/Bethesda,_Maryland">
                                    <p class="description"><?php esc_html_e( 'Grounds this city in the Knowledge Graph. Highest-trust entity reference.', 'real-smart-seo-pro' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Services', 'real-smart-seo-pro' ); ?></th>
                                <td>
                                    <?php foreach ( $all_services as $svc ) : ?>
                                        <label style="display:block;margin-bottom:4px;">
                                            <input type="checkbox" name="location_services[]" value="<?php echo esc_attr( $svc ); ?>">
                                            <?php echo esc_html( $svc ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        </table>
                        <p><button type="submit" name="rsseo_save_location" value="1" class="button button-primary"><?php esc_html_e( 'Create Location Page', 'real-smart-seo-pro' ); ?></button></p>
                    </form>
                </div>

                <div>
                    <h2><?php esc_html_e( 'Bulk Generate Locations', 'real-smart-seo-pro' ); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'rsseo_programmatic', '_rsseo_prog_nonce' ); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="bulk_locations"><?php esc_html_e( 'Locations (one per line)', 'real-smart-seo-pro' ); ?></label></th>
                                <td>
                                    <textarea id="bulk_locations" name="bulk_locations" rows="12" class="large-text" placeholder="Bethesda, MD, https://en.wikipedia.org/wiki/Bethesda,_Maryland&#10;Rockville, MD&#10;Silver Spring, MD&#10;Chevy Chase, MD&#10;Potomac, MD&#10;Gaithersburg, MD&#10;Germantown, MD&#10;Bowie, MD&#10;Columbia, MD&#10;Annapolis, MD&#10;Washington, DC"></textarea>
                                    <p class="description"><?php esc_html_e( 'Format: City, State, Wikipedia URL (Wikipedia optional)', 'real-smart-seo-pro' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Services to assign', 'real-smart-seo-pro' ); ?></th>
                                <td>
                                    <?php foreach ( $all_services as $svc ) : ?>
                                        <label style="display:block;margin-bottom:4px;">
                                            <input type="checkbox" name="bulk_services[]" value="<?php echo esc_attr( $svc ); ?>">
                                            <?php echo esc_html( $svc ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        </table>
                        <p><button type="submit" name="rsseo_bulk_generate" value="1" class="button button-primary"><?php esc_html_e( 'Bulk Generate & Submit to IndexNow', 'real-smart-seo-pro' ); ?></button></p>
                    </form>
                </div>

            </div>

            <?php if ( $location_posts ) : ?>
                <hr>
                <h2><?php printf( esc_html__( 'Existing Location Pages (%d)', 'real-smart-seo-pro' ), count( $location_posts ) ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Location', 'real-smart-seo-pro' ); ?></th>
                            <th><?php esc_html_e( 'Services', 'real-smart-seo-pro' ); ?></th>
                            <th><?php esc_html_e( 'sameAs (Wikipedia)', 'real-smart-seo-pro' ); ?></th>
                            <th><?php esc_html_e( 'URL', 'real-smart-seo-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $location_posts as $lp ) :
                            $lservices = get_post_meta( $lp->ID, '_mfc_services', true ) ?: array();
                            $lwiki     = get_post_meta( $lp->ID, '_mfc_wiki_url', true );
                            $lperma    = get_permalink( $lp->ID );
                        ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo esc_url( get_edit_post_link( $lp->ID ) ); ?>"><?php echo esc_html( $lp->post_title ); ?></a></strong>
                                </td>
                                <td><?php echo esc_html( implode( ', ', $lservices ) ); ?></td>
                                <td>
                                    <?php if ( $lwiki ) : ?>
                                        <a href="<?php echo esc_url( $lwiki ); ?>" target="_blank"><?php esc_html_e( 'Wikipedia', 'real-smart-seo-pro' ); ?></a>
                                    <?php else : ?>
                                        <span style="color:#999;"><?php esc_html_e( 'Not set', 'real-smart-seo-pro' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><a href="<?php echo esc_url( $lperma ); ?>" target="_blank"><?php echo esc_html( $lperma ); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

RSSEO_Pro_Programmatic::get_instance();
