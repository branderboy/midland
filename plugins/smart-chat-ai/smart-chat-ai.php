<?php
/**
 * Plugin Name: Midland Chat
 * Description: Midland-branded AI chat widget + live messaging. Leverages site content (sitemap + pages) to answer 24/7, captures quote info, and hands off to live customer service via the bundled WhatsApp + SMS layer during business hours.
 * Version: 1.1.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-chat-ai
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('SCAI_VERSION', '1.1.0');
define('SCAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCAI_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Smart Chat AI Class
 */
class SCAI_Plugin {
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_footer', array($this, 'render_chat_widget'));
        
        // AJAX hooks
        add_action('wp_ajax_scai_send_message', array($this, 'ajax_send_message'));
        add_action('wp_ajax_nopriv_scai_send_message', array($this, 'ajax_send_message'));
        add_action('wp_ajax_scai_capture_lead', array($this, 'ajax_capture_lead'));
        add_action('wp_ajax_nopriv_scai_capture_lead', array($this, 'ajax_capture_lead'));
    }
    
    /**
     * Load dependencies
     */
    private function load_dependencies() {
        require_once SCAI_PLUGIN_DIR . 'includes/class-ai-handler.php';
        require_once SCAI_PLUGIN_DIR . 'includes/class-lead-manager.php';
        require_once SCAI_PLUGIN_DIR . 'includes/class-analytics.php';
        require_once SCAI_PLUGIN_DIR . 'includes/class-handoff.php';
        SCAI_Handoff::get_instance();
        require_once SCAI_PLUGIN_DIR . 'includes/class-content-context.php';
        SCAI_Content_Context::get_instance();

        // Bundled messaging layer (formerly the standalone Midland Smart Messages
        // plugin). Class-exists guards keep us safe if an old install still has
        // the standalone plugin active — we let that one win and skip our copies.
        if ( ! defined( 'SMSG_VERSION' ) ) {
            define( 'SMSG_VERSION', '2.1.0-merged' );
            define( 'SMSG_PATH', SCAI_PLUGIN_DIR );
            define( 'SMSG_URL', SCAI_PLUGIN_URL );
        }
        if ( ! class_exists( 'SMSG_WhatsApp_API' ) ) {
            require_once SCAI_PLUGIN_DIR . 'includes/class-smsg-whatsapp-api.php';
        }
        if ( ! class_exists( 'SMSG_Hooks' ) ) {
            require_once SCAI_PLUGIN_DIR . 'includes/class-smsg-hooks.php';
        }
        if ( ! class_exists( 'SMSG_Admin' ) ) {
            require_once SCAI_PLUGIN_DIR . 'includes/class-smsg-admin.php';
        }
        if ( class_exists( 'SMSG_WhatsApp_API' ) ) {
            SMSG_WhatsApp_API::get_instance();
        }
        if ( class_exists( 'SMSG_Hooks' ) ) {
            SMSG_Hooks::get_instance();
        }
        if ( class_exists( 'SMSG_Admin' ) ) {
            SMSG_Admin::get_instance();
        }
    }
    
    /**
     * Activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();

        // Set default options
        $defaults = array(
            'chat_enabled' => true,
            'chat_position' => 'bottom-right',
            'chat_color' => '#2563EB',
            'chat_title' => 'Chat with us!',
            'chat_subtitle' => 'We typically reply in a few minutes',
            'ai_provider' => 'openai',
            'openai_api_key' => '',
            'ai_model' => 'gpt-4',
            'ai_temperature' => 0.7,
            'lead_email' => get_option('admin_email'),
            'enable_email_notifications' => true,
            'business_name' => get_bloginfo('name'),
            'business_type' => 'contractor',
            'ai_personality' => 'helpful',
        );
        
        foreach ($defaults as $key => $value) {
            if (false === get_option('smart_chat_' . $key)) {
                add_option('smart_chat_' . $key, $value);
            }
        }
    }
    
    /**
     * Deactivation
     */
    public function deactivate() {
        // Nothing to clean up — no cron events, no license server pings.
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Leads table
        $table_leads = $wpdb->prefix . 'smart_chat_leads';
        $sql_leads = "CREATE TABLE IF NOT EXISTS $table_leads (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            message text DEFAULT NULL,
            service_type varchar(100) DEFAULT NULL,
            project_budget varchar(50) DEFAULT NULL,
            timeline varchar(50) DEFAULT NULL,
            source varchar(50) DEFAULT 'chat',
            status varchar(20) DEFAULT 'new',
            ip_address varchar(100) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Conversations table
        $table_conversations = $wpdb->prefix . 'smart_chat_conversations';
        $sql_conversations = "CREATE TABLE IF NOT EXISTS $table_conversations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            lead_id bigint(20) DEFAULT NULL,
            message text NOT NULL,
            sender varchar(20) NOT NULL,
            ai_model varchar(50) DEFAULT NULL,
            tokens_used int DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY lead_id (lead_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_leads);
        dbDelta($sql_conversations);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Midland Chat', 'smart-chat-ai' ),
            __( 'Midland Chat', 'smart-chat-ai' ),
            'manage_options',
            'smart-chat-ai',
            array($this, 'admin_dashboard_page'),
            'dashicons-format-chat',
            30
        );

        add_submenu_page(
            'smart-chat-ai',
            __( 'Leads', 'smart-chat-ai' ),
            __( 'Leads', 'smart-chat-ai' ),
            'manage_options',
            'smart-chat-leads',
            array($this, 'admin_leads_page')
        );

        add_submenu_page(
            'smart-chat-ai',
            __( 'Conversations', 'smart-chat-ai' ),
            __( 'Conversations', 'smart-chat-ai' ),
            'manage_options',
            'smart-chat-conversations',
            array($this, 'admin_conversations_page')
        );

        add_submenu_page(
            'smart-chat-ai',
            __( 'Settings', 'smart-chat-ai' ),
            __( 'Settings', 'smart-chat-ai' ),
            'manage_options',
            'smart-chat-settings',
            array($this, 'admin_settings_page')
        );

    }

    /**
     * Register settings
     */
    public function register_settings() {
        $settings = array(
            'chat_enabled',
            'chat_position',
            'chat_color',
            'chat_title',
            'chat_subtitle',
            'ai_provider',
            'perplexity_api_key',
            'openai_api_key',
            'ai_model',
            'ai_temperature',
            'lead_email',
            'enable_email_notifications',
            'business_name',
            'business_type',
            'ai_personality',
        );
        
        foreach ($settings as $setting) {
            register_setting('scai_settings', 'smart_chat_' . $setting);
        }
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'smart-chat') === false) {
            return;
        }
        
        wp_enqueue_style(
            'scai-admin',
            SCAI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SCAI_VERSION
        );
        
        wp_enqueue_script(
            'scai-admin',
            SCAI_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            SCAI_VERSION,
            true
        );
        
        wp_localize_script('scai-admin', 'scaiAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('scai_admin'),
        ));
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        // Check if chat is enabled
        if (!get_option('smart_chat_chat_enabled')) {
            return;
        }
        
        wp_enqueue_style(
            'scai-widget',
            SCAI_PLUGIN_URL . 'assets/css/widget.css',
            array(),
            SCAI_VERSION
        );
        
        wp_enqueue_script(
            'scai-widget',
            SCAI_PLUGIN_URL . 'assets/js/widget.js',
            array('jquery'),
            SCAI_VERSION,
            true
        );
        
        wp_localize_script('scai-widget', 'scaiConfig', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('scai_widget'),
            'position' => get_option('smart_chat_chat_position', 'bottom-right'),
            'color' => get_option('smart_chat_chat_color', '#2563EB'),
            'title' => get_option('smart_chat_chat_title', 'Chat with us!'),
            'subtitle' => get_option('smart_chat_chat_subtitle', 'We typically reply in a few minutes'),
            'businessName' => get_option('smart_chat_business_name', get_bloginfo('name')),
        ));
    }
    
    /**
     * Render chat widget
     */
    public function render_chat_widget() {
        // Check if chat is enabled
        if (!get_option('smart_chat_chat_enabled')) {
            return;
        }
        
        include SCAI_PLUGIN_DIR . 'templates/widget.php';
    }
    
    /**
     * AJAX: Send message
     */
    public function ajax_send_message() {
        check_ajax_referer('scai_widget', 'nonce');
        
        $message = sanitize_text_field($_POST['message']);
        $session_id = sanitize_text_field($_POST['session_id']);
        
        // Get AI response
        $ai_handler = new SCAI_AI_Handler();
        $response = $ai_handler->get_response($message, $session_id);
        
        // Save conversation
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_conversations';
        
        $wpdb->insert($table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'session_id' => $session_id,
            'message' => $message,
            'sender' => 'user',
            'created_at' => current_time('mysql'),
        ));
        
        $wpdb->insert($table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'session_id' => $session_id,
            'message' => $response['message'],
            'sender' => 'ai',
            'ai_model' => $response['model'],
            'tokens_used' => $response['tokens'],
            'created_at' => current_time('mysql'),
        ));
        
        wp_send_json_success($response);
    }
    
    /**
     * AJAX: Capture lead
     */
    public function ajax_capture_lead() {
        check_ajax_referer('scai_widget', 'nonce');
        
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $message = sanitize_textarea_field($_POST['message']);
        $session_id = sanitize_text_field($_POST['session_id']);
        
        // Save lead
        $lead_manager = new SCAI_Lead_Manager();
        $lead_id = $lead_manager->create_lead(array(
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
            'session_id' => $session_id,
        ));
        
        // Send notification email
        if (get_option('smart_chat_enable_email_notifications')) {
            $this->send_lead_notification($lead_id);
        }
        
        wp_send_json_success(array(
            'lead_id' => $lead_id,
            'message' => __( "Thanks! We'll get back to you shortly.", 'smart-chat-ai' ),
        ));
    }
    
    /**
     * Send lead notification email
     */
    private function send_lead_notification($lead_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_leads';
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $lead_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        if (!$lead) {
            return;
        }
        
        $to = get_option('smart_chat_lead_email', get_option('admin_email'));
        $subject = sprintf( __( 'New Lead from Smart Chat AI: %s', 'smart-chat-ai' ), $lead->name );

        $message  = __( 'You have a new lead from your website!', 'smart-chat-ai' ) . "\n\n";
        $message .= __( 'Name:', 'smart-chat-ai' ) . ' ' . $lead->name . "\n";
        $message .= __( 'Email:', 'smart-chat-ai' ) . ' ' . $lead->email . "\n";
        $message .= __( 'Phone:', 'smart-chat-ai' ) . ' ' . $lead->phone . "\n";
        $message .= __( 'Message:', 'smart-chat-ai' ) . ' ' . $lead->message . "\n\n";
        $message .= __( 'View in dashboard:', 'smart-chat-ai' ) . ' ' . admin_url( 'admin.php?page=smart-chat-leads' );
        
        wp_mail($to, $subject, $message);
    }
    
    /**
     * Admin dashboard page
     */
    public function admin_dashboard_page() {
        include SCAI_PLUGIN_DIR . 'admin/dashboard.php';
    }
    
    /**
     * Admin leads page
     */
    public function admin_leads_page() {
        include SCAI_PLUGIN_DIR . 'admin/leads.php';
    }
    
    /**
     * Admin conversations page
     */
    public function admin_conversations_page() {
        include SCAI_PLUGIN_DIR . 'admin/conversations.php';
    }
    
    /**
     * Admin settings page
     */
    public function admin_settings_page() {
        include SCAI_PLUGIN_DIR . 'admin/settings.php';
    }
}

// Initialize plugin
function scai_init() {
    return SCAI_Plugin::get_instance();
}

add_action('plugins_loaded', 'scai_init');
