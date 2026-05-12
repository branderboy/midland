<?php
/**
 * Plugin Name: Midland Smart Forms Pro
 * Description: PRO upgrade for Midland Smart Forms. Resend email transport, Google Calendar integration, automated follow-ups, CRM (HubSpot, Salesforce, Pipedrive, ActiveCampaign), analytics dashboard, team management, and custom branding.
 * Version: 1.2.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-forms-pro
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Requires Plugins: smart-forms-for-midland
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SFCO_PRO_VERSION', '1.1.0' );
define( 'SFCO_PRO_FILE',    __FILE__ );
define( 'SFCO_PRO_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SFCO_PRO_URL',     plugin_dir_url( __FILE__ ) );

class SFCO_Pro {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
    }

    public function init() {
        if ( ! defined( 'SFCO_VERSION' ) ) {
            add_action( 'admin_notices', array( $this, 'missing_free_notice' ) );
            return;
        }

        $this->load_dependencies();

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_pro_assets' ) );
        register_activation_hook( SFCO_PRO_FILE, array( $this, 'activate' ) );
    }

    public function missing_free_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e( 'Smart Forms PRO requires the free Smart Forms for Contractors plugin to be installed and active.', 'smart-forms-pro' ); ?></strong></p>
        </div>
        <?php
    }

    private function load_dependencies() {
        // Core (existing).
        require_once SFCO_PRO_DIR . 'includes/class-pro-license.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-db.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-admin.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-automations.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-crm.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-calendly.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-analytics.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-branding.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-team.php';
        // New modules.
        require_once SFCO_PRO_DIR . 'includes/class-pro-resend.php';
        require_once SFCO_PRO_DIR . 'includes/class-pro-gcal.php';
    }

    public function enqueue_pro_assets( $hook ) {
        if ( strpos( $hook, 'sfco-' ) === false ) {
            return;
        }

        wp_enqueue_style( 'sfco-pro-admin', SFCO_PRO_URL . 'assets/css/pro-admin.css', array( 'sfco-admin' ), SFCO_PRO_VERSION );
        wp_enqueue_script( 'sfco-pro-admin', SFCO_PRO_URL . 'assets/js/pro-admin.js', array( 'jquery', 'sfco-admin' ), SFCO_PRO_VERSION, true );

        wp_localize_script( 'sfco-pro-admin', 'sfcoProAdmin', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sfco_pro_admin' ),
        ) );
    }

    public function activate() {
        if ( ! defined( 'SFCO_VERSION' ) ) {
            return;
        }
        SFCO_Pro_DB::create_tables();
    }
}

SFCO_Pro::get_instance();
