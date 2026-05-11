<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Pro_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook into base plugin scan form to add pro fields.
        add_action( 'rsseo_scan_form_fields',    array( $this, 'render_pro_scan_fields' ) );

        // Override base plugin scan handler to also save pro fields.
        add_filter( 'rsseo_after_scan_created',  array( $this, 'save_pro_scan_data' ), 10, 2 );

        // Override base analyzer to run pro analysis.
        add_filter( 'rsseo_run_analyzer',        array( $this, 'run_pro_analyzer' ), 10, 2 );

        // Extend report detail view.
        add_action( 'rsseo_after_report_fixes',  array( $this, 'render_pro_report_sections' ) );

        // Admin menu additions.
        add_action( 'admin_menu',                array( $this, 'add_pro_menu' ) );
        add_action( 'admin_enqueue_scripts',     array( $this, 'enqueue_assets' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_rsseo_pro_apply_schema',       array( $this, 'ajax_apply_schema' ) );
        add_action( 'wp_ajax_rsseo_pro_apply_all_schemas',  array( $this, 'ajax_apply_all_schemas' ) );
        add_action( 'wp_ajax_rsseo_pro_update_backlink',    array( $this, 'ajax_update_backlink' ) );
        add_action( 'wp_ajax_rsseo_pro_save_license',       array( $this, 'ajax_save_license' ) );
        add_action( 'wp_ajax_rsseo_pro_save_settings',      array( $this, 'ajax_save_pro_settings' ) );
        add_action( 'wp_ajax_rsseo_pro_test_dfs',           array( $this, 'ajax_test_dfs' ) );

        // Output schema on front end.
        add_action( 'wp_head', array( 'RSSEO_Pro_Schema', 'output_schema' ), 5 );

        // License gate on scan.
        add_filter( 'rsseo_can_run_scan', array( $this, 'check_license_gate' ) );
    }

    public function add_pro_menu() {
        // Pro pages are hidden from the sidebar (parent=null). The free plugin
        // routes to them inline via its Basic | Pro Settings sub-tab and the
        // Index / Insights tab cards, so users don't have to think about
        // "where does this Pro feature live in the menu".
        add_submenu_page(
            null,
            __( 'Pro Settings', 'real-smart-seo-pro' ),
            __( 'Pro Settings', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-pro-settings',
            array( $this, 'page_pro_settings' )
        );
        add_submenu_page(
            null,
            __( 'Pro License', 'real-smart-seo-pro' ),
            __( '⭐ Pro License', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-pro-license',
            array( $this, 'page_license' )
        );

        // Render the Pro settings panel inline when the free plugin's Settings
        // tab is on the Pro sub-tab.
        add_action( 'rsseo_render_pro_settings_panel', array( $this, 'page_pro_settings' ) );
    }

    public function page_pro_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }
        $dfs_login      = RSSEO_Pro_DataForSEO::get_login();
        $dfs_configured = RSSEO_Pro_DataForSEO::is_configured();
        require RSSEO_PRO_PATH . 'includes/views/pro-settings.php';
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'real-smart-seo' ) === false && strpos( $hook, 'rsseo' ) === false ) {
            return;
        }
        wp_enqueue_style( 'rsseo-pro-admin', RSSEO_PRO_URL . 'assets/css/rsseo-pro-admin.css', array( 'rsseo-admin' ), RSSEO_PRO_VERSION );
        wp_enqueue_script( 'rsseo-pro-admin', RSSEO_PRO_URL . 'assets/js/rsseo-pro-admin.js', array( 'jquery', 'rsseo-admin' ), RSSEO_PRO_VERSION, true );
        wp_localize_script( 'rsseo-pro-admin', 'rsseoProData', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'rsseo_pro_nonce' ),
            'strings'  => array(
                'applying'   => __( 'Applying...', 'real-smart-seo-pro' ),
                'applied'    => __( 'Applied!', 'real-smart-seo-pro' ),
                'error'      => __( 'Error. Try again.', 'real-smart-seo-pro' ),
                'confirm'    => __( 'Apply this schema to your site?', 'real-smart-seo-pro' ),
                'confirm_all'=> __( 'Apply all schema blocks to your site?', 'real-smart-seo-pro' ),
            ),
        ) );
    }

    // ── License gate ──────────────────────────────────────────────────────────

    public function check_license_gate( $can_run ) {
        if ( ! RSSEO_Pro_License::is_active() ) {
            return new WP_Error( 'no_license', __( 'Real Smart SEO for Local Pro requires an active license. Go to Real Smart SEO → Pro License to activate.', 'real-smart-seo-pro' ) );
        }
        return $can_run;
    }

    // ── Pro scan fields (hooks into free plugin's new-scan form) ──────────────

    public function render_pro_scan_fields() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        require RSSEO_PRO_PATH . 'includes/views/pro-scan-fields.php';
    }

    // ── Save pro scan data after base scan is created ─────────────────────────

    public function save_pro_scan_data( $scan_id, $post_data ) {
        // Parse keywords.
        $keywords_raw  = sanitize_textarea_field( wp_unslash( $post_data['rsseo_pro_text_keywords'] ?? '' ) );
        $keywords      = array_filter( array_map( 'trim', explode( "\n", $keywords_raw ) ) );
        $location      = sanitize_text_field( wp_unslash( $post_data['rsseo_pro_text_location'] ?? '' ) );
        $location_code = isset( $post_data['rsseo_pro_location_code'] ) ? (int) $post_data['rsseo_pro_location_code'] : 2840;

        // Pull live DataForSEO data if configured.
        $dfs_data = '';
        if ( ! empty( $keywords ) && RSSEO_Pro_DataForSEO::is_configured() ) {
            $dfs_data = RSSEO_Pro_DataForSEO::pull_scan_data( $keywords, $location, $location_code );
            if ( is_wp_error( $dfs_data ) ) {
                $dfs_data = 'DataForSEO error: ' . $dfs_data->get_error_message();
            }
        }

        $pro_data = array(
            'scan_id'          => $scan_id,
            'keywords_input'   => implode( "\n", $keywords ),
            'location_input'   => $location,
            'location_code'    => $location_code,
            'dataforseo_data'  => $dfs_data ?: null,
            'competitor_sf_data' => self::get_pro_field( 'competitor_sf', $post_data ),
            'gmb_data'         => self::get_pro_field( 'gmb',             $post_data ),
            'reviews_data'     => self::get_pro_field( 'reviews',         $post_data ),
            'perplexity_data'  => self::get_pro_field( 'perplexity',      $post_data ),
            'created_at'       => current_time( 'mysql' ),
        );

        RSSEO_Pro_Database::insert_pro_scan( $pro_data );
        return $scan_id;
    }

    private static function get_pro_field( $source, $post_data ) {
        $file_key = 'rsseo_pro_file_' . $source;
        $text_key = 'rsseo_pro_text_' . $source;

        if ( isset( $_FILES[ $file_key ] ) && ! empty( $_FILES[ $file_key ]['tmp_name'] ) && UPLOAD_ERR_OK === $_FILES[ $file_key ]['error'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $tmp  = $_FILES[ $file_key ]['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $name = sanitize_file_name( $_FILES[ $file_key ]['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            if ( in_array( $ext, array( 'csv', 'txt', 'tsv' ), true ) ) {
                $content = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                return $content ? wp_strip_all_tags( $content ) : '';
            }
        }

        if ( ! empty( $post_data[ $text_key ] ) ) {
            return sanitize_textarea_field( wp_unslash( $post_data[ $text_key ] ) );
        }

        return '';
    }

    // ── Run pro analyzer instead of base ─────────────────────────────────────

    public function run_pro_analyzer( $report_id, $scan_id ) {
        return RSSEO_Pro_Analyzer::analyze( $scan_id );
    }

    // ── Render pro sections in report detail view ─────────────────────────────

    public function render_pro_report_sections( $report ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $schemas   = RSSEO_Pro_Database::get_schemas( $report->id );
        $backlinks = RSSEO_Pro_Database::get_backlinks( $report->id );
        require RSSEO_PRO_PATH . 'includes/views/pro-report-sections.php';
    }

    // ── Page: License ─────────────────────────────────────────────────────────

    public function page_license() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }
        $is_active  = RSSEO_Pro_License::is_active();
        $license_key = RSSEO_Pro_License::get_key();
        $expiry      = RSSEO_Pro_License::get_expiry();
        require RSSEO_PRO_PATH . 'includes/views/license.php';
    }

    // ── AJAX: Apply schema ────────────────────────────────────────────────────

    public function ajax_apply_schema() {
        check_ajax_referer( 'rsseo_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }

        $schema_id = isset( $_POST['schema_id'] ) ? (int) $_POST['schema_id'] : 0;
        if ( ! $schema_id ) {
            wp_send_json_error( __( 'Invalid schema ID.', 'real-smart-seo-pro' ) );
        }

        $result = RSSEO_Pro_Fixer::apply_schema( $schema_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'message' => __( 'Schema applied.', 'real-smart-seo-pro' ) ) );
    }

    // ── AJAX: Apply all schemas ───────────────────────────────────────────────

    public function ajax_apply_all_schemas() {
        check_ajax_referer( 'rsseo_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }

        $report_id = isset( $_POST['report_id'] ) ? (int) $_POST['report_id'] : 0;
        if ( ! $report_id ) {
            wp_send_json_error( __( 'Invalid report ID.', 'real-smart-seo-pro' ) );
        }

        $result = RSSEO_Pro_Fixer::apply_all_schemas( $report_id );
        wp_send_json_success( $result );
    }

    // ── AJAX: Update backlink status ──────────────────────────────────────────

    public function ajax_update_backlink() {
        check_ajax_referer( 'rsseo_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }

        $backlink_id = isset( $_POST['backlink_id'] ) ? (int) $_POST['backlink_id'] : 0;
        $status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

        if ( ! $backlink_id || ! in_array( $status, array( 'pursuing', 'completed', 'skipped', 'pending' ), true ) ) {
            wp_send_json_error( __( 'Invalid data.', 'real-smart-seo-pro' ) );
        }

        RSSEO_Pro_Fixer::update_backlink( $backlink_id, $status );
        wp_send_json_success();
    }

    // ── AJAX: Save license ────────────────────────────────────────────────────

    public function ajax_save_license() {
        check_ajax_referer( 'rsseo_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }

        $action_type = isset( $_POST['license_action'] ) ? sanitize_text_field( wp_unslash( $_POST['license_action'] ) ) : '';
        $key         = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        if ( 'activate' === $action_type ) {
            if ( empty( $key ) ) {
                wp_send_json_error( __( 'Please enter a license key.', 'real-smart-seo-pro' ) );
            }
            $result = RSSEO_Pro_License::activate( $key );
            if ( $result['success'] ) {
                wp_send_json_success( array( 'message' => __( 'License activated successfully!', 'real-smart-seo-pro' ) ) );
            } else {
                wp_send_json_error( $result['error'] );
            }
        } elseif ( 'deactivate' === $action_type ) {
            RSSEO_Pro_License::deactivate();
            wp_send_json_success( array( 'message' => __( 'License deactivated.', 'real-smart-seo-pro' ) ) );
        } else {
            wp_send_json_error( __( 'Invalid action.', 'real-smart-seo-pro' ) );
        }
    }

    // ── AJAX: Save Pro Settings ───────────────────────────────────────────────

    public function ajax_save_pro_settings() {
        check_ajax_referer( 'rsseo_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }

        $login    = isset( $_POST['dfs_login'] )    ? sanitize_text_field( wp_unslash( $_POST['dfs_login'] ) )    : '';
        $password = isset( $_POST['dfs_password'] ) ? sanitize_text_field( wp_unslash( $_POST['dfs_password'] ) ) : '';

        if ( ! empty( $login ) && ! empty( $password ) ) {
            RSSEO_Pro_DataForSEO::save_credentials( $login, $password );
        }

        wp_send_json_success( array( 'message' => __( 'Settings saved.', 'real-smart-seo-pro' ) ) );
    }

    // ── AJAX: Test DataForSEO ─────────────────────────────────────────────────

    public function ajax_test_dfs() {
        check_ajax_referer( 'rsseo_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }

        $result = RSSEO_Pro_DataForSEO::test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( array( 'message' => __( 'DataForSEO connected!', 'real-smart-seo-pro' ) ) );
    }
}
