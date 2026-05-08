<?php
/**
 * Plugin Name: Midland Smart Chat
 * Plugin URI: https://tagglefish.com/smart-chat-ai
 * Description: Midland-branded AI chat widget + live messaging. Leverages site content (sitemap + pages) to answer 24/7, captures quote info, and hands off to live customer service via the bundled WhatsApp + SMS layer during business hours. Replaces the standalone Smart Messages plugin.
 * Version: 1.0.0
 * Author: TaggleFish
 * Author URI: https://tagglefish.com
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
define('SCAI_VERSION', '1.0.0');
define('SCAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCAI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SCAI_LICENSE_SERVER', 'https://tagglefish.com/wp-json/license/v1/');

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
        add_action('wp_ajax_scai_validate_license', array($this, 'ajax_validate_license'));
        
        // Cron for license check
        add_action('scai_daily_license_check', array($this, 'check_license_status'));
    }
    
    /**
     * Load dependencies
     */
    private function load_dependencies() {
        require_once SCAI_PLUGIN_DIR . 'includes/class-license-manager.php';
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
        
        // Schedule license check
        if (!wp_next_scheduled('scai_daily_license_check')) {
            wp_schedule_event(time(), 'daily', 'scai_daily_license_check');
        }
        
        // Set default options
        $defaults = array(
            'license_key' => '',
            'license_status' => 'inactive',
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
        // Remove scheduled event
        wp_clear_scheduled_hook('scai_daily_license_check');
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
            __( 'Smart Chat AI', 'smart-chat-ai' ),
            __( 'Smart Chat AI', 'smart-chat-ai' ),
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

        add_submenu_page(
            'smart-chat-ai',
            __( 'License', 'smart-chat-ai' ),
            __( 'License', 'smart-chat-ai' ),
            'manage_options',
            'smart-chat-license',
            array($this, 'admin_license_page')
        );

        add_submenu_page(
            'smart-chat-ai',
            'TaggleFish',
            '🐟 TaggleFish',
            'manage_options',
            'smart-chat-tagglefish',
            array($this, 'admin_tagglefish_page')
        );
    }

    /**
     * TaggleFish promotional page
     */
    public function admin_tagglefish_page() {
        ?>
        <div class="wrap">
            <h1>🐟 <?php esc_html_e( 'TaggleFish Products', 'smart-chat-ai' ); ?></h1>
            <p style="font-size:15px;color:#555;margin-bottom:24px;"><?php esc_html_e( 'Tools built by contractors, for contractors. Everything you need to get more leads, close more jobs, and grow your business.', 'smart-chat-ai' ); ?></p>

            <div class="scai-tf-grid">
                <div class="scai-tf-card">
                    <div class="scai-tf-header" style="background:linear-gradient(135deg,#ff6b35,#ff8c42);">
                        <span class="dashicons dashicons-admin-multisite" style="font-size:28px;width:28px;height:28px;"></span>
                        <div><h2 style="margin:0;font-size:18px;color:#fff;"><?php esc_html_e( '$500 Contractor Website', 'smart-chat-ai' ); ?></h2><p style="margin:2px 0 0;font-size:12px;opacity:.9;text-transform:uppercase;letter-spacing:.5px;font-weight:600;"><?php esc_html_e( 'Local Domination Package', 'smart-chat-ai' ); ?></p></div>
                    </div>
                    <p class="scai-tf-tagline"><?php esc_html_e( 'Turn local searches into ringing phones.', 'smart-chat-ai' ); ?></p>
                    <ul class="scai-tf-features">
                        <li><?php esc_html_e( 'Built in 7 days — you own it completely', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'Google Business Profile mirrored', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'Mobile-optimized for contractors', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'No monthly fees — one-time $500', 'smart-chat-ai' ); ?></li>
                    </ul>
                    <a href="https://deals.tagglefish.com/" target="_blank" rel="noopener noreferrer" class="scai-tf-cta" style="background:#ff6b35;"><?php esc_html_e( 'Get Your $500 Website', 'smart-chat-ai' ); ?> &rarr;</a>
                </div>

                <div class="scai-tf-card">
                    <div class="scai-tf-header" style="background:linear-gradient(135deg,#00a32a,#34c759);">
                        <span class="dashicons dashicons-cloud-upload" style="font-size:28px;width:28px;height:28px;"></span>
                        <div><h2 style="margin:0;font-size:18px;color:#fff;"><?php esc_html_e( 'Git Deploy for SEO', 'smart-chat-ai' ); ?></h2><p style="margin:2px 0 0;font-size:12px;opacity:.9;text-transform:uppercase;letter-spacing:.5px;font-weight:600;"><?php esc_html_e( 'FREE WordPress Plugin', 'smart-chat-ai' ); ?></p></div>
                    </div>
                    <p class="scai-tf-tagline"><?php esc_html_e( 'AI-powered SEO content generation at scale.', 'smart-chat-ai' ); ?></p>
                    <ul class="scai-tf-features">
                        <li><?php esc_html_e( 'Generate hundreds of location & service pages with Claude Code', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'GitHub backup — all content version-controlled', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'One-click deploy to WordPress', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'Auto-deploy webhooks on GitHub push', 'smart-chat-ai' ); ?></li>
                    </ul>
                    <a href="https://wordpress.org/plugins/git-deploy-for-seo-by-tagglefish/" target="_blank" rel="noopener noreferrer" class="scai-tf-cta" style="background:#00a32a;"><?php esc_html_e( 'Download FREE Plugin', 'smart-chat-ai' ); ?> &rarr;</a>
                </div>

                <div class="scai-tf-card">
                    <div class="scai-tf-header" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6);">
                        <span class="dashicons dashicons-superhero" style="font-size:28px;width:28px;height:28px;"></span>
                        <div><h2 style="margin:0;font-size:18px;color:#fff;"><?php esc_html_e( 'Smart Forms PRO', 'smart-chat-ai' ); ?></h2><p style="margin:2px 0 0;font-size:12px;opacity:.9;text-transform:uppercase;letter-spacing:.5px;font-weight:600;"><?php esc_html_e( '$399/year', 'smart-chat-ai' ); ?></p></div>
                    </div>
                    <p class="scai-tf-tagline"><?php esc_html_e( 'Stop losing jobs to faster competitors.', 'smart-chat-ai' ); ?></p>
                    <ul class="scai-tf-features">
                        <li><?php esc_html_e( 'Lead scoring — Hot/Warm/Cold priority', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'CRM sync — HubSpot, Salesforce, Pipedrive', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'Automated email follow-ups & SMS alerts', 'smart-chat-ai' ); ?></li>
                        <li><?php esc_html_e( 'Analytics dashboard & calendar integration', 'smart-chat-ai' ); ?></li>
                    </ul>
                    <a href="https://livableforms.com/smart-forms-pro.html" target="_blank" rel="noopener noreferrer" class="scai-tf-cta" style="background:#1e3a8a;"><?php esc_html_e( 'Upgrade to PRO - $399/year', 'smart-chat-ai' ); ?> &rarr;</a>
                </div>
            </div>

            <div class="scai-tf-footer">
                <p><?php esc_html_e( 'Built by', 'smart-chat-ai' ); ?> <a href="https://tagglefish.com" target="_blank" rel="noopener noreferrer"><strong>TaggleFish</strong></a> &nbsp;|&nbsp; <a href="mailto:support@tagglefish.com">support@tagglefish.com</a> &nbsp;|&nbsp; <a href="https://tagglefish.com" target="_blank" rel="noopener noreferrer">tagglefish.com</a></p>
            </div>
        </div>
        <?php
        $css = '.scai-tf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:24px;margin-bottom:30px}.scai-tf-card{background:#fff;border:1px solid #ddd;border-radius:10px;overflow:hidden}.scai-tf-header{display:flex;align-items:center;gap:12px;padding:20px;color:#fff}.scai-tf-tagline{padding:16px 20px 0;font-size:15px;font-weight:600;color:#1d2327;margin:0}.scai-tf-features{list-style:none;margin:12px 0 0;padding:0 20px}.scai-tf-features li{padding:6px 0 6px 22px;position:relative;font-size:13px;color:#555;line-height:1.5}.scai-tf-features li:before{content:"\2713";position:absolute;left:0;color:#00a32a;font-weight:700}.scai-tf-cta{display:block;margin:16px 20px 20px;padding:12px;text-align:center;font-weight:700;font-size:14px;text-decoration:none;border-radius:6px;color:#fff}.scai-tf-cta:hover{opacity:.9;color:#fff;text-decoration:none}.scai-tf-footer{text-align:center;padding:20px;border-top:1px solid #e0e0e0;color:#888;font-size:13px}.scai-tf-footer a{color:#2271b1;text-decoration:none}@media(max-width:768px){.scai-tf-grid{grid-template-columns:1fr}}';
        wp_register_style( 'scai-tf-inline', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        wp_enqueue_style( 'scai-tf-inline' );
        wp_add_inline_style( 'scai-tf-inline', $css );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        $settings = array(
            'license_key',
            'chat_enabled',
            'chat_position',
            'chat_color',
            'chat_title',
            'chat_subtitle',
            'ai_provider',
            'anthropic_api_key',
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
        // Check if chat is enabled and license is active
        if (!get_option('smart_chat_chat_enabled') || get_option('smart_chat_license_status') !== 'active') {
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
        // Check if chat is enabled and license is active
        if (!get_option('smart_chat_chat_enabled') || get_option('smart_chat_license_status') !== 'active') {
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
     * AJAX: Validate license
     */
    public function ajax_validate_license() {
        check_ajax_referer('scai_admin', 'nonce');
        
        $license_key = sanitize_text_field($_POST['license_key']);
        
        $license_manager = new SCAI_License_Manager();
        $result = $license_manager->validate_license($license_key);
        
        if ($result['valid']) {
            update_option('smart_chat_license_key', $license_key);
            update_option('smart_chat_license_status', 'active');
            wp_send_json_success($result);
        } else {
            update_option('smart_chat_license_status', 'inactive');
            wp_send_json_error($result);
        }
    }
    
    /**
     * Check license status (daily cron)
     */
    public function check_license_status() {
        $license_key = get_option('smart_chat_license_key');
        
        if (empty($license_key)) {
            return;
        }
        
        $license_manager = new SCAI_License_Manager();
        $result = $license_manager->validate_license($license_key);
        
        if ($result['valid']) {
            update_option('smart_chat_license_status', 'active');
        } else {
            update_option('smart_chat_license_status', 'inactive');
            
            // Send email notification
            $admin_email = get_option('admin_email');
            wp_mail(
                $admin_email,
                __( 'Smart Chat AI: License Expired', 'smart-chat-ai' ),
                __( 'Your Smart Chat AI license has expired. Please renew at https://tagglefish.com', 'smart-chat-ai' )
            );
        }
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
    
    /**
     * Admin license page
     */
    public function admin_license_page() {
        include SCAI_PLUGIN_DIR . 'admin/license.php';
    }
}

// Initialize plugin
function scai_init() {
    return SCAI_Plugin::get_instance();
}

add_action('plugins_loaded', 'scai_init');
