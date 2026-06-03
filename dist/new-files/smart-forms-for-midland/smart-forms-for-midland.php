<?php
/**
 * Plugin Name: Midland Smart Forms
 * Description: Multi-form lead capture for Midland Floor Care — floor-care templates, per-form shortcodes, file uploads, automation, Smart CRM Pro sync, Resend email, Google Calendar, branding, analytics, team management. (Formerly Smart Forms Basic + Smart Forms PRO, combined into one.)
 * Version: 2.19.6
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-forms-for-midland
 * Domain Path: /languages
 * Requires at least: 5.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants. Internal SFCO_/SFCO_PRO_ prefixes preserved so all
// existing class code keeps working with zero changes when we merged the
// Pro plugin into this folder.
define( 'SFCO_VERSION', '2.19.6' );
define( 'SFCO_PLUGIN_FILE', __FILE__ );
define( 'SFCO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFCO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Pro constants point at the same dir now — Pro classes were merged in.
define( 'SFCO_PRO_VERSION', SFCO_VERSION );
define( 'SFCO_PRO_FILE',    __FILE__ );
define( 'SFCO_PRO_DIR',     SFCO_PLUGIN_DIR );
define( 'SFCO_PRO_URL',     SFCO_PLUGIN_URL );

/**
 * Main plugin class
 */
class SFCO_Plugin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    private function load_dependencies() {
        // Core (formerly the free Basic plugin)
        require_once SFCO_PLUGIN_DIR . 'includes/class-database.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-form-handler.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-admin.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-shortcode.php';

        // Pro modules (formerly the separate Smart Forms PRO plugin, merged
        // into this folder so it's one install). class-pro-license.php is
        // a no-op shim that hard-returns true so every Pro feature is
        // unlocked without any license enforcement.
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-license.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-db.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-log.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-webhooks.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-admin.php';
        // Automations module is intentionally NOT loaded here. Lightweight
        // form responses (auto-reply + admin notification) live in
        // class-pro-notifications.php. Heavier trigger-action flows
        // (segment, tag, sync, drip) belong to Smart CRM Pro so the two
        // plugins don't overlap.
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-crm.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-calendly.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-analytics.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-branding.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-team.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-resend.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-gcal.php';
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-notifications.php';
        // Settings hub last — it lists every integration above and hides
        // their individual sidebar entries, so it has to load after them.
        require_once SFCO_PLUGIN_DIR . 'includes/class-pro-settings.php';
    }
    
    private function init_hooks() {
        register_activation_hook( __FILE__, array( 'SFCO_Plugin', 'activate' ) );
        register_uninstall_hook(  __FILE__, array( 'SFCO_Plugin', 'uninstall' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        // Defensive: run table creation on every load if the DB version
        // tag is missing or stale, so a plugin upgraded via the GitHub
        // Vault deploy (which does not fire register_activation_hook)
        // still has every Pro table. dbDelta is idempotent — if the
        // table already exists at the current schema this is a no-op.
        add_action( 'plugins_loaded', array( $this, 'maybe_install_tables' ), 20 );
    }

    public function maybe_install_tables() {
        $current = get_option( 'sfco_db_version', '0' );
        if ( version_compare( $current, SFCO_VERSION, '>=' ) ) {
            return;
        }
        SFCO_Database::create_tables();
        if ( class_exists( 'SFCO_Pro_DB' ) && method_exists( 'SFCO_Pro_DB', 'create_tables' ) ) {
            SFCO_Pro_DB::create_tables();
        }
        if ( class_exists( 'SFCO_Pro_Log' ) ) {
            SFCO_Pro_Log::create_table();
        }
        $this->maybe_seed_midland_forms();
        update_option( 'sfco_db_version', SFCO_VERSION );
    }

    /**
     * Seed the two Midland forms the operator actually uses:
     *   1. Midland — Short  (homepage hero / footer) — 4 fields, fast capture
     *   2. Midland — Long   (quote page) — full unified intake with intent,
     *      property subtype, service-by-segment filtering, timeline, ZIP,
     *      notes, photos.
     *
     * Idempotent: each form is created only if no form with its slug
     * already exists, so re-activating the plugin doesn't duplicate them.
     */
    public function maybe_seed_midland_forms() {
        if ( ! class_exists( 'SFCO_Database' ) ) {
            return;
        }
        if ( ! SFCO_Database::get_form_by_slug( 'midland-short' ) ) {
            SFCO_Database::create_form( array(
                'title'         => 'Midland — Short Quote Form (Homepage)',
                'slug'          => 'midland-short',
                'status'        => 'active',
                'fields_json'   => wp_json_encode( $this->seed_short_fields() ),
                'settings_json' => wp_json_encode( array(
                    'submit_text'       => 'Get a Free Quote',
                    'confirmation_type' => 'message',
                    'confirmation'      => 'Thanks! We received your request. A team member will call you within one business day.',
                    'honeypot'          => 1,
                    'description'       => 'Tell us a little about you and we\'ll be in touch fast.',
                ) ),
            ) );
        }
        if ( ! SFCO_Database::get_form_by_slug( 'midland-long' ) ) {
            SFCO_Database::create_form( array(
                'title'         => 'Midland — Full Quote Form (Quote Page)',
                'slug'          => 'midland-long',
                'status'        => 'active',
                'fields_json'   => wp_json_encode( $this->seed_long_fields() ),
                'settings_json' => wp_json_encode( array(
                    'submit_text'       => 'Request a Visit',
                    'confirmation_type' => 'message',
                    'confirmation'      => 'Thanks! We\'ll call you to confirm the visit time within one business day. If it\'s urgent, dial (240) 532-9097.',
                    'honeypot'          => 1,
                    'description'       => 'Fill this out so we have what we need to schedule the on-site visit and prep a quote.',
                ) ),
            ) );
        }
    }

    private function seed_short_fields(): array {
        return array(
            array( 'key' => 'customer_name',  'type' => 'text',  'label' => 'Name',  'required' => true,  'placeholder' => 'Full name' ),
            array( 'key' => 'customer_email', 'type' => 'email', 'label' => 'Email', 'required' => true,  'placeholder' => 'you@example.com' ),
            array( 'key' => 'customer_phone', 'type' => 'tel',   'label' => 'Phone', 'required' => true,  'placeholder' => '(240) 555-0000' ),
            array( 'key' => 'property_type',  'type' => 'radio', 'label' => 'Commercial or residential?', 'required' => true, 'options' => array( 'Commercial', 'Residential' ) ),
        );
    }

    private function seed_long_fields(): array {
        return array(
            array( 'key' => 'customer_name',     'type' => 'text',     'label' => 'Name',                                'required' => true ),
            array( 'key' => 'customer_email',    'type' => 'email',    'label' => 'Email',                               'required' => true ),
            array( 'key' => 'customer_phone',    'type' => 'tel',      'label' => 'Phone',                               'required' => true ),
            array( 'key' => 'property_type',     'type' => 'radio',    'label' => 'Commercial or residential?',          'required' => true, 'options' => array( 'Commercial', 'Residential' ) ),
            array( 'key' => 'lead_intent',       'type' => 'radio',    'label' => 'What brings you to Midland?',         'required' => true, 'options' => array(
                'Emergency — I need help now',
                'Request a visit — we come on-site to see the space and quote',
                'Request a call — call me to discuss (residential)',
                'Planning a future project (commercial)',
                'Just researching for now',
            ) ),
            array( 'key' => 'property_subtype',  'type' => 'select',   'label' => 'Property type',                       'required' => true, 'options' => array(
                'House', 'Townhouse', 'Condo', 'Apartment',
                'Office', 'Retail / Storefront', 'Medical / Dental', 'School / Education',
                'Hotel / Hospitality', 'Restaurant', 'Warehouse / Industrial', 'Government / Municipal',
                'Property Management', 'Other',
            ) ),
            array( 'key' => 'project_type',      'type' => 'select',   'label' => 'What service?',                       'options' => array(
                'Carpet Cleaning', 'Carpet Installation', 'Tile & Grout Cleaning',
                'Floor Stripping & Wax', 'Hardwood Floor Care', 'Concrete Polishing',
                'Upholstery Cleaning', 'Water Damage Restoration', 'Other / Not sure',
            ) ),
            array( 'key' => 'square_footage',    'type' => 'number',   'label' => 'Square footage (approx.)',            'placeholder' => 'Skip if not sure' ),
            array( 'key' => 'timeline',          'type' => 'select',   'label' => 'How soon?',                            'options' => array( 'ASAP (this week)', 'This month', 'Within 3 months', 'Just exploring' ) ),
            array( 'key' => 'zip_code',          'type' => 'text',     'label' => 'ZIP code' ),
            array( 'key' => 'additional_notes',  'type' => 'textarea', 'label' => 'Anything we should know?',             'rows' => 4 ),
        );
    }

    /**
     * Activation: create both the core forms/leads tables and the Pro side
     * tables (automations log, analytics events, etc.) plus seed the
     * Midland template library.
     */
    public static function activate() {
        SFCO_Database::create_tables();
        if ( class_exists( 'SFCO_Pro_DB' ) && method_exists( 'SFCO_Pro_DB', 'create_tables' ) ) {
            SFCO_Pro_DB::create_tables();
        }
    }
    
    public function enqueue_frontend_assets() {
        wp_enqueue_style( 'sfco-frontend', SFCO_PLUGIN_URL . 'assets/css/frontend.css', array(), SFCO_VERSION );
        wp_enqueue_script( 'sfco-frontend', SFCO_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), SFCO_VERSION, true );

        // Ad-platform conversion pixels (configured at Smart Forms → Tracking).
        // Frontend JS fires gtag conversion, fbq('track','Lead'), and
        // ttq.track('SubmitForm') on every successful submit when each pixel
        // ID is set AND the matching tag script is on the page.
        $tracking = get_option( 'sfco_tracking', array() );
        if ( ! is_array( $tracking ) ) $tracking = array();

        wp_localize_script( 'sfco-frontend', 'sfcoData', array(
            'ajaxurl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'sfco_submit' ),
            'tracking' => array(
                'google_ads_send_to'  => $tracking['google_ads_send_to']  ?? '', // AW-1234567/abcDEFgh
                'google_ads_value'    => $tracking['google_ads_value']    ?? '',
                'google_ads_currency' => $tracking['google_ads_currency'] ?? 'USD',
                'facebook_pixel_id'   => $tracking['facebook_pixel_id']   ?? '',
                'facebook_event'      => $tracking['facebook_event']      ?? 'Lead',
                'tiktok_pixel_id'     => $tracking['tiktok_pixel_id']     ?? '',
                'tiktok_event'        => $tracking['tiktok_event']        ?? 'SubmitForm',
            ),
        ) );
    }
    
    public static function uninstall() {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }
        
        global $wpdb;

        // Drop every plugin-owned table, not just leads — otherwise forms, the
        // integration log, and the four Pro tables (plus any secrets they hold)
        // survive an uninstall.
        $tables = array(
            'sfco_leads',
            'sfco_forms',
            'sfco_integration_log',
            'sfco_automations',
            'sfco_automation_logs',
            'sfco_crm_sync',
            'sfco_team_members',
        );
        foreach ( $tables as $t ) {
            $table = $wpdb->prefix . $t;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name built from $wpdb->prefix.
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }

        // Remove every option this plugin and its Pro modules wrote (version,
        // settings, integration keys/secrets, branding, tracking, calendly,
        // gcal, resend, notifications, etc.) — all share the sfco prefix.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'sfco' ) . '%' ) );
    }
}

// Initialize plugin
SFCO_Plugin::get_instance();
