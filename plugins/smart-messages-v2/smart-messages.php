<?php
/**
 * Plugin Name: Midland Smart Messages
 * Plugin URI: https://tagglefish.com/smart-messages
 * Description: Live customer-service handoff layer. Sends WhatsApp / SMS during business hours so Midland Smart Chat can hand a hot lead from AI to a real person.
 * Version: 2.0.0
 * Author: TaggleFish
 * Author URI: https://tagglefish.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-messages
 * Domain Path: /languages
 * Requires at least: 5.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SMSG_VERSION', '2.0.0' );
define( 'SMSG_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMSG_URL', plugin_dir_url( __FILE__ ) );

class SMSG_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'smart-messages', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function init() {
        $this->includes();
        $this->init_classes();
    }

    private function includes() {
        require_once SMSG_PATH . 'includes/class-smsg-whatsapp-api.php';
        require_once SMSG_PATH . 'includes/class-smsg-hooks.php';
        require_once SMSG_PATH . 'includes/class-smsg-admin.php';
    }

    private function init_classes() {
        SMSG_WhatsApp_API::get_instance();
        SMSG_Hooks::get_instance();
        SMSG_Admin::get_instance();
    }
}

SMSG_Plugin::get_instance();
