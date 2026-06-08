<?php
/**
 * Smart CRM top-level menu.
 *
 * Smart CRM is a passthrough between Smart Forms and the integrations
 * (ActiveCampaign, ServiceM8, Vapi, Google Calendar, Floor Care Plan).
 * There is no sales pipeline, no cold-lead dashboard, no campaign
 * builder — the only sidebar entry is "Smart CRM → Settings".
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Admin {

    /**
     * Single shared instance. Admin is the one module the bootstrap owns
     * directly (the others self-instantiate at load), so this accessor keeps
     * it consistent with the rest of the suite and guards against a second
     * `new SCRM_Pro_Admin()` registering duplicate admin_menu / enqueue hooks.
     *
     * @var SCRM_Pro_Admin|null
     */
    private static $instance = null;

    /**
     * Get (or lazily create) the shared instance.
     *
     * @return SCRM_Pro_Admin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Idempotency guard: if an instance already exists, do not register the
        // hooks again. This makes a stray `new SCRM_Pro_Admin()` harmless.
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = $this;

        add_action( 'admin_menu', array( $this, 'add_menu' ), 40 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function add_menu() {
        // Top-level Smart CRM menu — the callback delegates to the
        // unified Settings page so clicking the parent label lands you
        // straight on Settings.
        add_menu_page(
            __( 'Smart CRM', 'smart-crm-pro' ),
            __( 'Smart CRM', 'smart-crm-pro' ),
            'manage_options',
            'smart-crm',
            array( $this, 'render_landing' ),
            'dashicons-businessman',
            40
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'scrm-' ) === false && strpos( $hook, 'smart-crm' ) === false ) {
            return;
        }
        wp_enqueue_style( 'scrm-pro-admin', SCRM_PRO_URL . 'admin/css/admin.css', array(), SCRM_PRO_VERSION );
        wp_enqueue_script( 'scrm-pro-admin', SCRM_PRO_URL . 'admin/js/admin.js', array( 'jquery' ), SCRM_PRO_VERSION, true );
        wp_localize_script( 'scrm-pro-admin', 'scrmProData', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'scrm_pro_nonce' ),
        ) );
    }

    public function render_landing() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( class_exists( 'SCRM_Pro_Settings' ) ) {
            SCRM_Pro_Settings::get_instance()->render();
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__( 'Smart CRM', 'smart-crm-pro' ) . '</h1><p>' . esc_html__( 'Settings module failed to load.', 'smart-crm-pro' ) . '</p></div>';
    }
}
