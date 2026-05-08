<?php
/**
 * Plugin Name: Midland Smart CRM Pro
 * Plugin URI: https://tagglefish.com/smart-crm-pro
 * Description: Midland-branded CRM. Lead reactivation engine + ServiceM8 webhook bridge that marks projects complete, fires the NPS survey, and feeds ActiveCampaign.
 * Version: 1.0.0
 * Author: TaggleFish
 * Author URI: https://tagglefish.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-crm-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: smart-forms-for-contractors
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'plugins_loaded', 'smart_crm_pro_init', 25 );

function smart_crm_pro_init() {
    // Require Smart Forms.
    if ( ! defined( 'SFCO_VERSION' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Smart CRM PRO requires Smart Forms for Contractors to be installed and active.', 'smart-crm-pro' ) . '</strong></p></div>';
        });
        return;
    }

    define( 'SCRM_PRO_VERSION', '1.0.0' );
    define( 'SCRM_PRO_DIR', plugin_dir_path( __FILE__ ) );
    define( 'SCRM_PRO_URL', plugin_dir_url( __FILE__ ) );

    require_once SCRM_PRO_DIR . 'includes/class-reactivation-engine.php';
    require_once SCRM_PRO_DIR . 'includes/class-campaign-manager.php';
    require_once SCRM_PRO_DIR . 'includes/class-reactivation-analytics.php';
    require_once SCRM_PRO_DIR . 'includes/class-license.php';
    require_once SCRM_PRO_DIR . 'includes/class-admin.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-servicem8.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-activecampaign.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-floor-care-plan.php';

    new SCRM_Pro_Admin();
    new SCRM_Pro_License();
    SCRM_Pro_ServiceM8::get_instance();
    SCRM_Pro_ActiveCampaign::get_instance();
    SCRM_Pro_Floor_Care_Plan::get_instance();

    // Cron for sending scheduled reactivation emails.
    add_action( 'scrm_pro_send_campaign_email', array( 'SCRM_Pro_Campaign_Manager', 'send_scheduled_email' ), 10, 2 );

    if ( ! wp_next_scheduled( 'scrm_pro_daily_scan' ) ) {
        wp_schedule_event( time(), 'daily', 'scrm_pro_daily_scan' );
    }
    add_action( 'scrm_pro_daily_scan', array( 'SCRM_Pro_Reactivation_Engine', 'daily_cold_lead_scan' ) );
}

register_activation_hook( __FILE__, 'scrm_pro_activate' );
function scrm_pro_activate() {
    if ( ! defined( 'SFCO_VERSION' ) ) {
        return;
    }
    SCRM_Pro_Reactivation_Engine::create_tables();
}

register_deactivation_hook( __FILE__, 'scrm_pro_deactivate' );
function scrm_pro_deactivate() {
    wp_clear_scheduled_hook( 'scrm_pro_daily_scan' );
    wp_clear_scheduled_hook( 'scrm_pro_send_campaign_email' );
}
