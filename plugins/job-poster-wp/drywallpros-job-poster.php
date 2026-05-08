<?php
/**
 * Plugin Name: Midland Job Manager
 * Plugin URI:  https://github.com/branderboy/midland
 * Description: Midland-branded job manager. Creates job listings with Google for Jobs schema, distributes to Facebook, Indeed, Nextdoor, and Craigslist, and runs an application form with resume + cover-letter uploads.
 * Version:     1.4.1
 * Author:      Job Manager Pro
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: job-manager-pro
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'JMP_VERSION', '1.4.1' );
define( 'JMP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'JMP_URL',     plugin_dir_url( __FILE__ ) );
define( 'JMP_FILE',    __FILE__ );

// Back-compat constants (internal code still uses DPJP_*)
define( 'DPJP_VERSION', JMP_VERSION );
define( 'DPJP_DIR',     JMP_DIR );
define( 'DPJP_URL',     JMP_URL );
define( 'DPJP_FILE',    JMP_FILE );

// Auto-flush rewrite rules when version changes
add_action( 'init', function() {
    if ( get_option( 'jmp_version' ) !== JMP_VERSION ) {
        flush_rewrite_rules();
        update_option( 'jmp_version', JMP_VERSION );
    }
}, 999 );

require_once JMP_DIR . 'includes/class-post-type.php';
require_once JMP_DIR . 'includes/class-meta-fields.php';
require_once JMP_DIR . 'includes/class-content.php';
require_once JMP_DIR . 'includes/class-schema.php';
require_once JMP_DIR . 'includes/class-facebook.php';
require_once JMP_DIR . 'includes/class-indeed.php';
require_once JMP_DIR . 'includes/class-admin.php';
require_once JMP_DIR . 'includes/class-elementor.php';
require_once JMP_DIR . 'includes/class-importer.php';
require_once JMP_DIR . 'includes/class-shortcode.php';
require_once JMP_DIR . 'includes/class-application.php';

add_action( 'init', function() {
    DPJP_Post_Type::register();
    DPJP_Meta_Fields::register();
    DPJP_Schema::register();
    DPJP_Admin::register();
    DPJP_Facebook::register();
    DPJP_Indeed::register();
    DPJP_Elementor::register();
    DPJP_Importer::register();
    DPJP_Shortcode::register();
    DPJP_Application::register();
} );

register_activation_hook( __FILE__, function() {
    DPJP_Post_Type::register();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// Load translations
add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'job-manager-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );
