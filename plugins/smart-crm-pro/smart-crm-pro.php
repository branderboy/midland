<?php
/**
 * Plugin Name: Midland Smart CRM
 * Description: Passthrough between Smart Forms and the integrations (ActiveCampaign, ServiceM8, Vapi, Google Calendar, Floor Care Plan). One sidebar entry: Smart CRM → Settings.
 * Version: 2.4.1
 * Author: Midland Floor Care
 * Author URI: https://midlandfloors.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-crm-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SCRM_PRO_VERSION', '2.4.2' );
define( 'SCRM_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCRM_PRO_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'smart_crm_pro_init', 25 );

function smart_crm_pro_init() {
    load_plugin_textdomain( 'smart-crm-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    if ( ! defined( 'SFCO_VERSION' ) ) {
        add_action( 'admin_notices', function() {
            $link = current_user_can( 'activate_plugins' )
                ? ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Activate it on the Plugins page.', 'smart-crm-pro' ) . '</a>'
                : '';
            echo '<div class="notice notice-warning"><p><strong>'
                . esc_html__( 'Midland Smart CRM is idle: it needs the "Midland Smart Forms" plugin active to pass leads through.', 'smart-crm-pro' )
                . '</strong>' . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        });
        return;
    }

    require_once SCRM_PRO_DIR . 'includes/class-admin.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-activecampaign.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-tags.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-servicem8.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-floor-care-plan.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-smart-forms-bridge.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-vapi.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-visit-draft.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-ops-notifications.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-chat-forms-bridge.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-settings.php';

    // Each module's class file self-instantiates its singleton at load
    // time. Instantiating again here would create duplicate admin_menu
    // hooks and double-render the page. The bootstrap only owns Admin
    // (which has no singleton accessor).
    new SCRM_Pro_Admin();
}

register_deactivation_hook( __FILE__, 'scrm_pro_deactivate' );
function scrm_pro_deactivate() {
    wp_clear_scheduled_hook( 'scrm_pro_daily_scan' );
    wp_clear_scheduled_hook( 'scrm_pro_send_campaign_email' );
    wp_clear_scheduled_hook( 'scrm_pro_sm8_poll_jobs' );
}
