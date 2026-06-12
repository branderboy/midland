<?php
/**
 * Plugin Name: Midland Smart CRM
 * Description: Passthrough between Smart Forms and the integrations (ActiveCampaign, ServiceM8, Vapi, Google Calendar, Floor Care Plan). One sidebar entry: Smart CRM → Settings.
 * Version: 2.4.3
 * Author: Midland Floor Care
 * Author URI: https://midlandfloors.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-crm-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SCRM_PRO_VERSION', '2.4.3' );
define( 'SCRM_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCRM_PRO_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'smart_crm_pro_init', 25 );

function smart_crm_pro_init() {
    load_plugin_textdomain( 'smart-crm-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // The CRM runs the ship, standalone. It does NOT depend on the forms
    // plugin: the chat bridge creates its own leads table when forms is
    // absent, intake events fire either way, and email responses are sent
    // from here. Forms and chat are just doors into this CRM.

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
    require_once SCRM_PRO_DIR . 'includes/class-scrm-lead-emails.php';
    require_once SCRM_PRO_DIR . 'includes/class-scrm-pro-settings.php';

    // Each module's class file self-instantiates its singleton at load
    // time. Instantiating again here would create duplicate admin_menu
    // hooks and double-render the page. The bootstrap owns Admin, which
    // now exposes get_instance() and an idempotency guard so a stray
    // second instantiation anywhere can't double-register its hooks.
    SCRM_Pro_Admin::get_instance();
}

register_deactivation_hook( __FILE__, 'scrm_pro_deactivate' );
function scrm_pro_deactivate() {
    wp_clear_scheduled_hook( 'scrm_pro_daily_scan' );
    wp_clear_scheduled_hook( 'scrm_pro_send_campaign_email' );
    wp_clear_scheduled_hook( 'scrm_pro_sm8_poll_jobs' );
    // Per-lead follow-up reminders are single events scheduled WITH a lead_id
    // arg; clear them all regardless of args so deactivation leaves nothing in
    // the cron schedule.
    if ( function_exists( 'wp_unschedule_hook' ) ) {
        wp_unschedule_hook( 'scrm_pro_follow_up_reminder' );
    }
}
