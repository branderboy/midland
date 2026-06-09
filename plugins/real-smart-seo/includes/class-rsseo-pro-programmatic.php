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

    const CPT          = 'mfc_location';
    const TAXONOMY     = 'mfc_service';
    const OPT_TEMPLATES = 'rsseo_prog_templates';

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
        add_action( 'admin_init',       array( $this, 'handle_save_templates' ) );
        add_action( 'wp_head',          array( $this, 'output_location_schema' ) );
        add_filter( 'document_title_parts', array( $this, 'filter_location_title' ) );
        add_action( 'wp_head',          array( $this, 'output_location_meta' ), 2 );
        add_action( 'admin_init',       array( $this, 'handle_export_csv' ) );
        add_filter( 'the_content',      array( $this, 'append_internal_links' ) );
    }

    public function register_cpt() {
        register_post_type( self::CPT, array(
            'labels' => array(
                'name'          => __( 'Locations', 'real-smart-seo' ),
                'singular_name' => __( 'Location', 'real-smart-seo' ),
                'add_new_item'  => __( 'Add New Location', 'real-smart-seo' ),
                'edit_item'     => __( 'Edit Location', 'real-smart-seo' ),
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
                'name'          => __( 'Services', 'real-smart-seo' ),
                'singular_name' => __( 'Service', 'real-smart-seo' ),
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
            null,
            esc_html__( 'Programmatic Pages', 'real-smart-seo' ),
            esc_html__( 'Programmatic Pages', 'real-smart-seo' ),
            'manage_options',
            'rsseo-programmatic',
            array( $this, 'render_page' )
        );
    }

    /* ===================== Template builder ===================== */

    /** Default page templates (used until the operator saves their own). */
    private function default_templates() {
        return array(
            'title' => '{service} in {city}, {state} | {business}',
            'meta'  => 'Expert {services} in {city}, {state}. {business} serves the DMV area — licensed, insured, same-day available. Free quote.',
            'slug'  => '{service}-{city}-{state}',
            'body'  => "<h2>Professional {service} in {city}, {state}</h2>\n"
                . "<p>{business} proudly serves {city} and the surrounding {state} area with expert floor care — including {services}. Our certified technicians bring professional-grade equipment to your home or business.</p>\n"
                . "<h3>Services available in {city}, {state}</h3>\n{services_list}\n"
                . "<p>Ready to start? <a href=\"/contact/\">Request a free quote</a> or call {phone}. We serve {city} and every surrounding neighborhood in {state}.</p>",
            'use_elementor' => 1,
        );
    }

    /** Saved templates merged over the defaults. */
    public function get_templates() {
        $saved = get_option( self::OPT_TEMPLATES, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->default_templates() );
    }

    /**
     * Substitute {city} {state} {service} {services} {services_list} {business}
     * {phone} {year} in a template string.
     */
    public function render_template( $tpl, $city, $state, $services ) {
        $services = array_values( array_filter( (array) $services ) );
        $identity = get_option( 'rsseo_sameas_identity', array() );
        $business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
        $phone    = ! empty( $identity['business_phone'] ) ? $identity['business_phone'] : '';
        $primary  = ! empty( $services ) ? $services[0] : 'Floor Care';
        $list      = ! empty( $services ) ? implode( ', ', $services ) : 'floor care services';

        $list_html = '';
        foreach ( $services as $svc ) {
            $list_html .= '<li>' . esc_html( $svc ) . ' in ' . esc_html( $city ) . ', ' . esc_html( $state ) . '</li>';
        }
        $list_html = $list_html ? '<ul>' . $list_html . '</ul>' : '';

        return strtr( (string) $tpl, array(
            '{city}'          => $city,
            '{state}'         => $state,
            '{service}'       => $primary,
            '{services}'      => $list,
            '{services_list}' => $list_html,
            '{business}'      => $business,
            '{phone}'         => $phone,
            '{year}'          => gmdate( 'Y' ),
        ) );
    }

    /** Render the slug template into a safe, non-empty post slug. */
    private function build_slug( $city, $state, $services ) {
        $t    = $this->get_templates();
        $slug = sanitize_title( $this->render_template( $t['slug'], $city, $state, $services ) );
        return $slug ?: sanitize_title( $city . '-' . $state );
    }

    /** Save the page templates from the builder form. */
    public function handle_save_templates() {
        if ( ! isset( $_POST['rsseo_save_templates'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rsseo_tpl_nonce'] ?? '' ) ), 'rsseo_prog_templates' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo' ) );
        }
        update_option( self::OPT_TEMPLATES, array(
            'title'         => sanitize_text_field( wp_unslash( $_POST['tpl_title'] ?? '' ) ),
            'meta'          => sanitize_text_field( wp_unslash( $_POST['tpl_meta'] ?? '' ) ),
            'slug'          => sanitize_text_field( wp_unslash( $_POST['tpl_slug'] ?? '' ) ),
            'body'          => wp_kses_post( wp_unslash( $_POST['tpl_body'] ?? '' ) ),
            'use_elementor' => isset( $_POST['tpl_use_elementor'] ) ? 1 : 0,
        ) );
        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-programmatic&tpl_saved=1' ) );
        exit;
    }

    /* ============================================================ */

    /**
     * Create or update a single mfc_location page via the programmatic engine.
     *
     * Public + static so the Midland Local SEO plugin can drive this engine to
     * produce a real location page (instead of its own draft fallback). This is
     * the single reusable path used by handle_save_location(), handle_bulk_generate(),
     * and external callers. Callers MUST enforce their own capability + nonce
     * checks before calling — this method performs no auth.
     *
     * @param string $city     City name (already sanitized by caller).
     * @param string $state    State / region (already sanitized by caller).
     * @param array  $services Service names to attach (mfc_service terms + meta).
     * @param array  $args     Optional: 'status' (draft|publish, default publish),
     *                         'wiki_url' (string), 'ping' (bool, default true).
     * @return int|WP_Error    New/existing post ID, or WP_Error on failure.
     */
    public static function generate_location_page( $city, $state, $services = array() ) {
        $args = func_num_args() > 3 ? func_get_arg( 3 ) : array();
        if ( ! is_array( $args ) ) {
            $args = array();
        }

        $city     = sanitize_text_field( (string) $city );
        $state    = sanitize_text_field( (string) $state );
        $services = array_values( array_filter( array_map( 'sanitize_text_field', (array) $services ) ) );
        $status   = ( isset( $args['status'] ) && 'publish' === $args['status'] ) ? 'publish' : 'draft';
        $wiki_url = isset( $args['wiki_url'] ) ? esc_url_raw( (string) $args['wiki_url'] ) : '';
        $ping     = ! isset( $args['ping'] ) || (bool) $args['ping'];

        if ( '' === $city ) {
            return new WP_Error( 'rsseo_no_city', __( 'A city is required to generate a location page.', 'real-smart-seo' ) );
        }

        $self = self::get_instance();

        // Match on city+state meta so "Bethesda, MD" and "Bethesda, OH" don't collide.
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

        $content = $self->generate_location_content( $city, $state, $services );

        if ( $existing ) {
            $post_id = $existing[0];
            wp_update_post( array(
                'ID'           => $post_id,
                'post_status'  => $status,
                'post_content' => $content,
            ) );
        } else {
            $post_id = wp_insert_post( array(
                'post_type'    => self::CPT,
                'post_title'   => $city . ', ' . $state,
                'post_name'    => $self->build_slug( $city, $state, $services ),
                'post_status'  => $status,
                'post_content' => $content,
                'post_author'  => get_current_user_id(),
            ) );
        }

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        if ( ! $post_id ) {
            return new WP_Error( 'rsseo_insert_fail', __( 'Could not create the location page.', 'real-smart-seo' ) );
        }

        update_post_meta( $post_id, '_mfc_city', $city );
        update_post_meta( $post_id, '_mfc_state', $state );
        if ( '' !== $wiki_url ) {
            update_post_meta( $post_id, '_mfc_wiki_url', $wiki_url );
        }
        update_post_meta( $post_id, '_mfc_services', $services );

        if ( $self->get_templates()['use_elementor'] ) {
            $self->apply_elementor_template( $post_id, $city, $state, $services );
        }

        // Assign mfc_service terms (create missing).
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
        wp_set_post_terms( $post_id, array_filter( $term_ids ), self::TAXONOMY );

        // IndexNow only for published pages — never announce a draft.
        if ( $ping && 'publish' === get_post_status( $post_id ) ) {
            $permalink = get_permalink( $post_id );
            if ( $permalink ) {
                do_action( 'rsseo_indexnow_ping', $permalink );
            }
        }

        return (int) $post_id;
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
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo' ) );
        }

        $city      = sanitize_text_field( wp_unslash( $_POST['location_city'] ?? '' ) );
        $state     = sanitize_text_field( wp_unslash( $_POST['location_state'] ?? '' ) );
        $wiki_url  = esc_url_raw( wp_unslash( $_POST['location_wiki_url'] ?? '' ) );
        $services  = isset( $_POST['location_services'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['location_services'] ) ) : array();
        $status    = ( 'publish' === sanitize_key( wp_unslash( $_POST['location_status'] ?? 'draft' ) ) ) ? 'publish' : 'draft';

        if ( empty( $city ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-programmatic&error=no_city' ) );
            exit;
        }
        // Quality guard: a location page with no services is a thin doorway page.
        if ( empty( $services ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-programmatic&error=no_services' ) );
            exit;
        }

        $post_id = self::generate_location_page( $city, $state, $services, array(
            'status'   => $status,
            'wiki_url' => $wiki_url,
        ) );

        if ( is_wp_error( $post_id ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-programmatic&error=insert_fail' ) );
            exit;
        }

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
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo' ) );
        }

        $raw_locations = sanitize_textarea_field( wp_unslash( $_POST['bulk_locations'] ?? '' ) );
        $bulk_services = isset( $_POST['bulk_services'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['bulk_services'] ) ) : array();
        $status        = ( 'publish' === sanitize_key( wp_unslash( $_POST['bulk_status'] ?? 'draft' ) ) ) ? 'publish' : 'draft';

        // Quality guard: generating pages with no services = thin doorway pages.
        if ( empty( $bulk_services ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-programmatic&error=no_services' ) );
            exit;
        }

        // Accept locations from the textarea AND an optional uploaded CSV file
        // (City, State, Wikipedia URL per row — a header row is ignored).
        $csv_lines = $this->read_uploaded_csv();
        $lines = array_filter( array_map( 'trim', array_merge( explode( "\n", $raw_locations ), $csv_lines ) ) );

        // Hard cap to keep wp_insert_post + IndexNow + term creation under one request budget.
        // Bigger batches should be split or queued externally.
        $batch_limit = (int) apply_filters( 'rsseo_programmatic_batch_limit', 50 );
        $skipped     = max( 0, count( $lines ) - $batch_limit );
        $lines       = array_slice( $lines, 0, $batch_limit );

        $created = 0;
        $updated = 0;
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

            // Detect existing so we can keep the created/updated tally; the page
            // create/update itself is delegated to the shared engine method.
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

            // ping=false: IndexNow is batched once after the loop.
            $post_id = self::generate_location_page( $city, $state, $bulk_services, array(
                'status'   => $status,
                'wiki_url' => $wiki,
                'ping'     => false,
            ) );

            if ( is_wp_error( $post_id ) ) {
                continue;
            }

            if ( $existing ) {
                $updated++;
            } else {
                $created++;
            }

            // Only announce published pages to IndexNow — never drafts.
            if ( 'publish' === get_post_status( $post_id ) ) {
                $permalink = get_permalink( $post_id );
                if ( $permalink ) {
                    $urls[] = $permalink;
                }
            }
        }

        // Batch ping IndexNow.
        if ( $urls ) {
            do_action( 'rsseo_indexnow_batch_ping', $urls );
        }

        $redirect = admin_url( 'admin.php?page=rsseo-programmatic&generated=' . $created . '&updated=' . $updated );
        if ( $skipped > 0 ) {
            $redirect = add_query_arg( 'skipped', $skipped, $redirect );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Read an optional uploaded CSV of locations (City, State, Wikipedia URL per
     * row). A leading "City,..." header row is ignored. Returns "City, State,
     * Wiki" lines to merge with the textarea. Runs inside handle_bulk_generate
     * after the nonce check.
     */
    private function read_uploaded_csv() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( empty( $_FILES['bulk_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bulk_csv']['tmp_name'] ) ) {
            return array();
        }
        $tmp = $_FILES['bulk_csv']['tmp_name']; // phpcs:ignore
        // phpcs:enable
        $rows = array();
        $fh   = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( ! $fh ) {
            return array();
        }
        $first = true;
        while ( ( $cols = fgetcsv( $fh ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
            $cols = array_map( 'sanitize_text_field', array_map( 'trim', (array) $cols ) );
            if ( $first ) {
                $first = false;
                if ( isset( $cols[0] ) && 0 === strcasecmp( $cols[0], 'city' ) ) {
                    continue; // skip header
                }
            }
            $line = implode( ', ', array_filter( array( $cols[0] ?? '', $cols[1] ?? '', $cols[2] ?? '' ), 'strlen' ) );
            if ( '' !== $line ) {
                $rows[] = $line;
            }
        }
        fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return $rows;
    }

    /**
     * Export every location page to CSV (City, State, Status, Services,
     * Wikipedia URL, URL) so the list can be edited in a spreadsheet and
     * re-imported, or handed off.
     */
    public function handle_export_csv() {
        if ( ! isset( $_GET['rsseo_export_locations'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'rsseo_export_locations' );

        $posts = get_posts( array(
            'post_type'   => self::CPT,
            'numberposts' => -1,
            'post_status' => 'any',
            'orderby'     => 'title',
            'order'       => 'ASC',
        ) );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=service-area-locations.csv' );
        $out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        fputcsv( $out, array( 'City', 'State', 'Status', 'Services', 'WikipediaURL', 'URL' ) ); // phpcs:ignore
        foreach ( $posts as $p ) {
            $svcs = (array) get_post_meta( $p->ID, '_mfc_services', true );
            fputcsv( $out, array( // phpcs:ignore
                (string) get_post_meta( $p->ID, '_mfc_city', true ),
                (string) get_post_meta( $p->ID, '_mfc_state', true ),
                $p->post_status,
                implode( '|', $svcs ),
                (string) get_post_meta( $p->ID, '_mfc_wiki_url', true ),
                (string) get_permalink( $p->ID ),
            ) );
        }
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /**
     * Append an internal-link block ("Service Areas We Also Cover") to location
     * pages on the front end. Built live from the other published location pages
     * (same state first) so the internal-link mesh stays current as new pages
     * are added — no stale baked-in links.
     */
    public function append_internal_links( $content ) {
        if ( is_admin() || ! is_singular( self::CPT ) || ! is_main_query() || ! in_the_loop() ) {
            return $content;
        }
        $current = get_the_ID();
        $state   = get_post_meta( $current, '_mfc_state', true );

        $args = array(
            'post_type'    => self::CPT,
            'post_status'  => 'publish',
            'numberposts'  => 12,
            'post__not_in' => array( $current ),
            'orderby'      => 'title',
            'order'        => 'ASC',
        );
        if ( $state ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $args['meta_query'] = array( array( 'key' => '_mfc_state', 'value' => $state, 'compare' => '=' ) );
        }
        $siblings = get_posts( $args );
        if ( empty( $siblings ) ) {
            return $content;
        }

        $items = '';
        foreach ( $siblings as $s ) {
            $items .= '<li><a href="' . esc_url( get_permalink( $s->ID ) ) . '">' . esc_html( get_the_title( $s->ID ) ) . '</a></li>';
        }
        return $content
            . '<section class="rsseo-service-areas" style="margin-top:2.5rem;">'
            . '<h2>' . esc_html__( 'Service Areas We Also Cover', 'real-smart-seo' ) . '</h2>'
            . '<ul class="rsseo-service-areas__list">' . $items . '</ul>'
            . '</section>';
    }

    /**
     * Generate template content for a location page.
     */
    private function generate_location_content( $city, $state, $services ) {
        // Render the operator's body template (falls back to the default).
        $t = $this->get_templates();
        return $this->render_template( $t['body'], $city, $state, $services );
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
        // elementor_header_footer keeps the theme/Elementor Pro Theme Builder
        // header + footer wrapped around the page (the kit ships header.json
        // and footer.json as section templates). elementor_canvas would strip
        // both, which is wrong for these location landing pages.
        update_post_meta( $post_id, '_wp_page_template', 'elementor_header_footer' );
    }

    /**
     * Build the three-section Elementor structure (Hero, Content, CTA) that
     * mirrors templates/commercial-carpet-cleaning-services.json from the kit.
     */
    private function generate_elementor_data( $city, $state, $services ) {
        $identity = get_option( 'rsseo_sameas_identity', array() );
        $profile  = class_exists( 'RSSEO_Profile' ) ? RSSEO_Profile::get() : array();
        $business = ! empty( $profile['business_name'] )
            ? $profile['business_name']
            : ( ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' ) );
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
                $body_html .= '<li><p>' . esc_html( $svc ) . ' tailored to ' . esc_html( $city ) . ' facilities</p></li>';
            }
            $body_html .= '</ul>';
        }

        $body_html .= '<p><strong>Why ' . esc_html( $city ) . ' businesses choose ' . esc_html( $business ) . '</strong></p><ul>'
            . '<li><p>Licensed and insured for serving the DMV metro area</p></li>'
            . '<li><p>Same-day and next-day appointments available</p></li>'
            . '<li><p>Commercial-grade equipment for deeper, longer-lasting results</p></li>'
            . '<li><p>Flexible scheduling: after-hours and weekends available</p></li>'
            . '<li><p>Satisfaction guaranteed: backed by our Midland Shine Standard</p></li>'
            . '</ul>';

        $body_html .= '<p><a href="/schedule-a-visit/"><strong>Ready to schedule an on-site visit in ' . esc_html( $city ) . '?</strong></a><br />Call us or request a visit online and we&rsquo;ll build a plan around your facility.</p>';

        $flex_gap_20 = array( 'column' => '20', 'row' => '20', 'isLinked' => true, 'unit' => 'px', 'size' => 20 );
        $pad_zero    = array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true );

        return array(
            // SECTION 1 for Hero
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

            // SECTION 2 for Content
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
                            'boxed_width'    => array( 'unit' => 'px', 'size' => 920, 'sizes' => array() ),
                            'padding'        => array( 'unit' => 'em', 'top' => '3', 'right' => '1.5', 'bottom' => '2', 'left' => '1.5', 'isLinked' => false ),
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

            // SECTION 3 for CTA
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

        // Hex-escape <, >, &, ', " so a stray </script> can't break out of the
        // <script> block — schema values are partly model/user-generated.
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
        $t        = $this->get_templates();

        $parts['title'] = wp_strip_all_tags( $this->render_template( $t['title'], $city, $state, $services ) );
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
        $t        = $this->get_templates();

        $desc = wp_strip_all_tags( $this->render_template( $t['meta'], $city, $state, $services ) );

        // Multibyte-safe truncate so a Spanish/French character at the boundary doesn't get half-chopped.
        $truncated = function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, 160 ) : substr( $desc, 0, 160 );
        echo '<meta name="description" content="' . esc_attr( $truncated ) . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function render_page() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $generated      = isset( $_GET['generated'] ) ? absint( $_GET['generated'] ) : -1;
        $updated_count  = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0;
        $skipped        = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
        $location_saved = isset( $_GET['location_saved'] );
        $tpl_saved      = isset( $_GET['tpl_saved'] );
        $error          = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
        // phpcs:enable

        $tpl = $this->get_templates();

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
            <h1><?php esc_html_e( 'Programmatic City × Service Pages', 'real-smart-seo' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Generate location × service pages at scale. Each page gets optimized title, meta description, and LocalBusiness+Service schema automatically.', 'real-smart-seo' ); ?></p>

            <?php if ( $generated >= 0 ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    if ( $generated > 0 && $updated_count > 0 ) {
                        printf(
                            esc_html__( '%1$d new location pages created, %2$d existing pages refreshed.', 'real-smart-seo' ),
                            $generated,
                            $updated_count
                        );
                    } elseif ( $generated > 0 ) {
                        printf( esc_html__( '%d new location pages created.', 'real-smart-seo' ), $generated );
                    } elseif ( $updated_count > 0 ) {
                        printf( esc_html__( '%d existing location pages refreshed.', 'real-smart-seo' ), $updated_count );
                    } else {
                        esc_html_e( 'No changes — all submitted locations already exist and were up to date.', 'real-smart-seo' );
                    }
                ?></p></div>
            <?php endif; ?>
            <?php if ( $skipped > 0 ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php printf( esc_html__( '%d locations skipped because the batch limit was exceeded. Submit them in another run.', 'real-smart-seo' ), $skipped ); ?></p></div>
            <?php endif; ?>
            <?php if ( $location_saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Location saved and submitted to IndexNow.', 'real-smart-seo' ); ?></p></div>
            <?php endif; ?>
            <?php
            if ( $error ) :
                $messages = array(
                    'no_city'     => __( 'Add a city before creating a location page.', 'real-smart-seo' ),
                    'no_services' => __( 'Select at least one service — a location page with no services is a thin doorway page and was not created.', 'real-smart-seo' ),
                    'insert_fail' => __( 'WordPress could not create the page. Please try again.', 'real-smart-seo' ),
                );
                $msg = $messages[ $error ] ?? $error;
                ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
            <?php endif; ?>

            <?php if ( $tpl_saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Page template saved. New pages use it; existing pages keep their content but pick up the title/meta template on the front end.', 'real-smart-seo' ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Page Template', 'real-smart-seo' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Define how every generated page is built. Click a variable to insert it; the preview updates live.', 'real-smart-seo' ); ?>
                <br>
                <?php
                $vars = array( '{city}', '{state}', '{service}', '{services}', '{services_list}', '{business}', '{phone}', '{year}' );
                foreach ( $vars as $v ) {
                    echo '<code class="rsseo-var" style="cursor:pointer;margin:2px 4px 2px 0;display:inline-block;background:#eef;border:1px solid #ccd;border-radius:3px;padding:1px 6px;">' . esc_html( $v ) . '</code>';
                }
                ?>
            </p>
            <form method="post" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px 22px;margin-bottom:24px;">
                <?php wp_nonce_field( 'rsseo_prog_templates', '_rsseo_tpl_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tpl_title"><?php esc_html_e( 'Title template', 'real-smart-seo' ); ?></label></th>
                        <td><input type="text" id="tpl_title" name="tpl_title" class="large-text rsseo-tpl" value="<?php echo esc_attr( $tpl['title'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tpl_meta"><?php esc_html_e( 'Meta description template', 'real-smart-seo' ); ?></label></th>
                        <td><textarea id="tpl_meta" name="tpl_meta" rows="2" class="large-text rsseo-tpl"><?php echo esc_textarea( $tpl['meta'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Trimmed to 160 characters on output.', 'real-smart-seo' ); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="tpl_slug"><?php esc_html_e( 'URL slug template', 'real-smart-seo' ); ?></label></th>
                        <td><input type="text" id="tpl_slug" name="tpl_slug" class="regular-text rsseo-tpl" value="<?php echo esc_attr( $tpl['slug'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tpl_body"><?php esc_html_e( 'Body template (HTML)', 'real-smart-seo' ); ?></label></th>
                        <td><textarea id="tpl_body" name="tpl_body" rows="10" class="large-text code rsseo-tpl"><?php echo esc_textarea( $tpl['body'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Layout', 'real-smart-seo' ); ?></th>
                        <td><label><input type="checkbox" name="tpl_use_elementor" value="1" <?php checked( ! empty( $tpl['use_elementor'] ) ); ?>> <?php esc_html_e( 'Build pages with the Elementor Hero/Content/CTA layout (uncheck to use the HTML body template as the page content).', 'real-smart-seo' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Live preview', 'real-smart-seo' ); ?></th>
                        <td>
                            <div style="border:1px solid #ddd;border-radius:6px;padding:12px;background:#fafafa;">
                                <div style="font-size:12px;color:#666;"><?php esc_html_e( 'Title', 'real-smart-seo' ); ?></div>
                                <div id="prev_title" style="color:#1a0dab;font-size:18px;margin-bottom:6px;"></div>
                                <div id="prev_slug" style="color:#0a7d00;font-size:13px;margin-bottom:6px;"></div>
                                <div id="prev_meta" style="color:#444;font-size:13px;margin-bottom:12px;"></div>
                                <div style="font-size:12px;color:#666;border-top:1px solid #eee;padding-top:8px;"><?php esc_html_e( 'Body', 'real-smart-seo' ); ?></div>
                                <div id="prev_body" style="font-size:14px;"></div>
                            </div>
                            <p class="description"><?php esc_html_e( 'Sample data: Carpet Cleaning · Bethesda, MD.', 'real-smart-seo' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" name="rsseo_save_templates" value="1" class="button button-primary"><?php esc_html_e( 'Save Template', 'real-smart-seo' ); ?></button></p>
            </form>

            <script>
            (function(){
                var sample = {
                    '{city}':'Bethesda', '{state}':'MD', '{service}':'Carpet Cleaning',
                    '{services}':'Carpet Cleaning, Tile & Grout Cleaning, Hardwood Refinishing',
                    '{services_list}':'<ul><li>Carpet Cleaning in Bethesda, MD</li><li>Tile &amp; Grout Cleaning in Bethesda, MD</li></ul>',
                    '{business}':<?php
                        $preview_profile  = class_exists( 'RSSEO_Profile' ) ? RSSEO_Profile::get() : array();
                        $preview_identity = get_option( 'rsseo_sameas_identity', array() );
                        $preview_business = ! empty( $preview_profile['business_name'] )
                            ? $preview_profile['business_name']
                            : ( ! empty( $preview_identity['business_name'] ) ? $preview_identity['business_name'] : get_bloginfo( 'name' ) );
                        echo wp_json_encode( $preview_business );
                    ?>,
                    '{phone}':'(240) 532-9097', '{year}':String(new Date().getFullYear())
                };
                function sub(str){ return String(str||'').replace(/\{[a-z_]+\}/g, function(m){ return (m in sample)?sample[m]:m; }); }
                function slugify(str){ return sub(str).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
                function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
                function refresh(){
                    var g=function(id){var e=document.getElementById(id);return e?e.value:'';};
                    document.getElementById('prev_title').textContent = sub(g('tpl_title'));
                    document.getElementById('prev_meta').textContent  = sub(g('tpl_meta')).slice(0,160);
                    document.getElementById('prev_slug').textContent   = '<?php echo esc_js( trailingslashit( home_url( '/service-area/' ) ) ); ?>' + slugify(g('tpl_slug')) + '/';
                    document.getElementById('prev_body').innerHTML     = sub(g('tpl_body'));
                }
                document.querySelectorAll('.rsseo-tpl').forEach(function(el){ el.addEventListener('input', refresh); });
                document.querySelectorAll('.rsseo-var').forEach(function(c){
                    c.addEventListener('click', function(){
                        var body=document.getElementById('tpl_body');
                        var t=document.activeElement;
                        var target=(t && t.classList && t.classList.contains('rsseo-tpl'))?t:body;
                        var s=target.selectionStart||target.value.length;
                        target.value = target.value.slice(0,s) + c.textContent + target.value.slice(target.selectionEnd||s);
                        target.focus(); refresh();
                    });
                });
                refresh();
            })();
            </script>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

                <div>
                    <h2><?php esc_html_e( 'Add Single Location', 'real-smart-seo' ); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'rsseo_programmatic', '_rsseo_prog_nonce' ); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="location_city"><?php esc_html_e( 'City', 'real-smart-seo' ); ?></label></th>
                                <td><input type="text" id="location_city" name="location_city" class="regular-text" placeholder="Bethesda"></td>
                            </tr>
                            <tr>
                                <th><label for="location_state"><?php esc_html_e( 'State', 'real-smart-seo' ); ?></label></th>
                                <td><input type="text" id="location_state" name="location_state" style="width:80px;" placeholder="MD" value="MD"></td>
                            </tr>
                            <tr>
                                <th><label for="location_wiki_url"><?php esc_html_e( 'Wikipedia URL (sameAs)', 'real-smart-seo' ); ?></label></th>
                                <td>
                                    <input type="url" id="location_wiki_url" name="location_wiki_url" class="large-text" placeholder="https://en.wikipedia.org/wiki/Bethesda,_Maryland">
                                    <p class="description"><?php esc_html_e( 'Grounds this city in the Knowledge Graph. Highest-trust entity reference.', 'real-smart-seo' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Services', 'real-smart-seo' ); ?></th>
                                <td>
                                    <?php foreach ( $all_services as $svc ) : ?>
                                        <label style="display:block;margin-bottom:4px;">
                                            <input type="checkbox" name="location_services[]" value="<?php echo esc_attr( $svc ); ?>">
                                            <?php echo esc_html( $svc ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="description"><?php esc_html_e( 'Pick at least one — pages with no services are blocked as thin content.', 'real-smart-seo' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Publish status', 'real-smart-seo' ); ?></th>
                                <td>
                                    <label style="margin-right:14px;"><input type="radio" name="location_status" value="draft" checked> <?php esc_html_e( 'Draft (review first)', 'real-smart-seo' ); ?></label>
                                    <label><input type="radio" name="location_status" value="publish"> <?php esc_html_e( 'Publish immediately', 'real-smart-seo' ); ?></label>
                                    <p class="description"><?php esc_html_e( 'Draft is recommended — review the page before it goes live.', 'real-smart-seo' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p><button type="submit" name="rsseo_save_location" value="1" class="button button-primary"><?php esc_html_e( 'Create Location Page', 'real-smart-seo' ); ?></button></p>
                    </form>
                </div>

                <div>
                    <h2><?php esc_html_e( 'Bulk Generate Locations', 'real-smart-seo' ); ?></h2>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'rsseo_programmatic', '_rsseo_prog_nonce' ); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="bulk_locations"><?php esc_html_e( 'Locations (one per line)', 'real-smart-seo' ); ?></label></th>
                                <td>
                                    <textarea id="bulk_locations" name="bulk_locations" rows="12" class="large-text" placeholder="Bethesda, MD, https://en.wikipedia.org/wiki/Bethesda,_Maryland&#10;Rockville, MD&#10;Silver Spring, MD&#10;Chevy Chase, MD&#10;Potomac, MD&#10;Gaithersburg, MD&#10;Germantown, MD&#10;Bowie, MD&#10;Columbia, MD&#10;Annapolis, MD&#10;Washington, DC"></textarea>
                                    <p class="description"><?php esc_html_e( 'Format: City, State, Wikipedia URL (Wikipedia optional)', 'real-smart-seo' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Services to assign', 'real-smart-seo' ); ?></th>
                                <td>
                                    <?php foreach ( $all_services as $svc ) : ?>
                                        <label style="display:block;margin-bottom:4px;">
                                            <input type="checkbox" name="bulk_services[]" value="<?php echo esc_attr( $svc ); ?>">
                                            <?php echo esc_html( $svc ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="bulk_csv"><?php esc_html_e( 'Or upload CSV', 'real-smart-seo' ); ?></label></th>
                                <td>
                                    <input type="file" id="bulk_csv" name="bulk_csv" accept=".csv,text/csv">
                                    <p class="description"><?php esc_html_e( 'Columns: City, State, Wikipedia URL (header row optional). Merged with the box above.', 'real-smart-seo' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Publish status', 'real-smart-seo' ); ?></th>
                                <td>
                                    <label style="margin-right:14px;"><input type="radio" name="bulk_status" value="draft" checked> <?php esc_html_e( 'Draft (review first)', 'real-smart-seo' ); ?></label>
                                    <label><input type="radio" name="bulk_status" value="publish"> <?php esc_html_e( 'Publish immediately', 'real-smart-seo' ); ?></label>
                                    <p class="description"><?php esc_html_e( 'Bulk pages default to Draft so you can review before going live. Only published pages are pinged to IndexNow.', 'real-smart-seo' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p><button type="submit" name="rsseo_bulk_generate" value="1" class="button button-primary"><?php esc_html_e( 'Bulk Generate Pages', 'real-smart-seo' ); ?></button></p>
                    </form>
                </div>

            </div>

            <?php if ( $location_posts ) : ?>
                <hr>
                <h2><?php printf( esc_html__( 'Existing Location Pages (%d)', 'real-smart-seo' ), count( $location_posts ) ); ?></h2>
                <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rsseo-programmatic&rsseo_export_locations=1' ), 'rsseo_export_locations' ) ); ?>"><?php esc_html_e( '⬇ Export all to CSV', 'real-smart-seo' ); ?></a></p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Location', 'real-smart-seo' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'real-smart-seo' ); ?></th>
                            <th><?php esc_html_e( 'Services', 'real-smart-seo' ); ?></th>
                            <th><?php esc_html_e( 'sameAs (Wikipedia)', 'real-smart-seo' ); ?></th>
                            <th><?php esc_html_e( 'URL', 'real-smart-seo' ); ?></th>
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
                                <td>
                                    <?php if ( 'publish' === $lp->post_status ) : ?>
                                        <span style="display:inline-block;padding:2px 8px;background:#dcfce7;color:#166534;border-radius:3px;font-size:12px;"><?php esc_html_e( 'Published', 'real-smart-seo' ); ?></span>
                                    <?php else : ?>
                                        <span style="display:inline-block;padding:2px 8px;background:#fef3c7;color:#92400e;border-radius:3px;font-size:12px;"><?php echo esc_html( ucfirst( $lp->post_status ) ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( implode( ', ', $lservices ) ); ?></td>
                                <td>
                                    <?php if ( $lwiki ) : ?>
                                        <a href="<?php echo esc_url( $lwiki ); ?>" target="_blank"><?php esc_html_e( 'Wikipedia', 'real-smart-seo' ); ?></a>
                                    <?php else : ?>
                                        <span style="color:#999;"><?php esc_html_e( 'Not set', 'real-smart-seo' ); ?></span>
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
