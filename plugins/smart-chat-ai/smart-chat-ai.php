<?php
/**
 * Plugin Name: Midland Chat
 * Description: Midland-branded AI chat widget. Leverages site content (sitemap + pages) to answer 24/7, captures quote info, and offers a one-tap WhatsApp button so visitors can switch to a live conversation on the contractor's phone.
 * Version: 1.9.29
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
define('SCAI_VERSION', '1.9.29');
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

        // Self-healing schema check. Runs cheaply on every admin load so the
        // tables and the lead_id column always exist, even when the plugin was
        // upgraded by overwriting files instead of deactivate/reactivate.
        add_action('admin_init', array($this, 'maybe_upgrade_db'));
        
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_footer', array($this, 'render_chat_widget'));
        
        // AJAX: send a chat message. Native free-hand lead capture runs inside
        // this handler (no separate form). "Schedule a visit" uses Smart Forms.
        add_action('wp_ajax_scai_send_message', array($this, 'ajax_send_message'));
        add_action('wp_ajax_nopriv_scai_send_message', array($this, 'ajax_send_message'));

        // Admin-only: test the AI provider connection and surface the raw result.
        add_action('wp_ajax_scai_test_ai', array($this, 'ajax_test_ai'));
    }
    
    /**
     * Load dependencies
     */
    private function load_dependencies() {
        require_once SCAI_PLUGIN_DIR . 'includes/class-ai-handler.php';
        require_once SCAI_PLUGIN_DIR . 'includes/class-lead-manager.php';
        require_once SCAI_PLUGIN_DIR . 'includes/class-analytics.php';
        require_once SCAI_PLUGIN_DIR . 'includes/class-content-context.php';
        SCAI_Content_Context::get_instance();

        // WhatsApp is now click-to-chat only (wa.me link inside the widget). The
        // old Cloud API + Smart Messages admin layer was removed in 1.9.0 — clients
        // weren't getting through Meta's developer-app onboarding.
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
            'chat_color' => '#43A94B',
            'chat_title' => 'Chat with us!',
            'chat_subtitle' => 'We typically reply in a few minutes',
            'ai_provider' => 'perplexity',
            'openai_api_key' => '',
            'ai_model' => 'sonar',
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

        // Sitemap ingestion — enabled by default, kick off an immediate crawl
        // so the AI has real site content on day one instead of waiting on the
        // first daily cron run.
        if ( class_exists( 'SCAI_Content_Context' ) ) {
            if ( false === get_option( SCAI_Content_Context::OPT_ENABLED ) ) {
                add_option( SCAI_Content_Context::OPT_ENABLED, 1 );
            } else {
                update_option( SCAI_Content_Context::OPT_ENABLED, 1 );
            }
            if ( false === get_option( SCAI_Content_Context::OPT_PAGE_LIMIT ) ) {
                add_option( SCAI_Content_Context::OPT_PAGE_LIMIT, 30 );
            }
            if ( false === get_option( SCAI_Content_Context::OPT_CHARS_PER ) ) {
                add_option( SCAI_Content_Context::OPT_CHARS_PER, 1500 );
            }
            // Single-shot cron event: refreshes the cache ~30 seconds after
            // activation on the next page load that triggers WP-Cron.
            if ( ! wp_next_scheduled( SCAI_Content_Context::CRON_HOOK ) ) {
                wp_schedule_single_event( time() + 30, SCAI_Content_Context::CRON_HOOK );
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
     * Run the schema setup if the stored DB version is behind. Cheap: just an
     * option compare on each admin load, real work only when out of date. This
     * guarantees the conversations table and its lead_id column exist even if
     * the plugin was updated by replacing files without reactivation.
     */
    public function maybe_upgrade_db() {
        $installed = get_option( 'smart_chat_db_version', '0' );
        if ( version_compare( $installed, SCAI_VERSION, '>=' ) ) {
            return;
        }

        $this->create_tables();

        // Backfill the lead_id column on older conversations tables that
        // predate it, since CREATE TABLE IF NOT EXISTS won't add it.
        global $wpdb;
        $conv = $wpdb->prefix . 'smart_chat_conversations';
        $has_col = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SHOW COLUMNS FROM {$conv} LIKE %s",
            'lead_id'
        ) );
        if ( empty( $has_col ) ) {
            $wpdb->query( "ALTER TABLE {$conv} ADD COLUMN lead_id bigint(20) DEFAULT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        update_option( 'smart_chat_db_version', SCAI_VERSION );
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
            'chat_logo',
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
            'preprompt',
        );

        foreach ($settings as $setting) {
            register_setting('scai_settings', 'smart_chat_' . $setting);
        }

        // Sitemap ingestion (Site Content) — same options the dedicated page uses,
        // exposed here so admins can configure everything from one screen.
        register_setting( 'scai_settings', SCAI_Content_Context::OPT_ENABLED, array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
        register_setting( 'scai_settings', SCAI_Content_Context::OPT_SITEMAP_URL, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );
        register_setting( 'scai_settings', SCAI_Content_Context::OPT_PAGE_LIMIT, array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
        register_setting( 'scai_settings', SCAI_Content_Context::OPT_CHARS_PER, array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );

        // WhatsApp click-to-chat — one number, one prefilled greeting, no Meta app needed.
        register_setting( 'scai_settings', 'smart_chat_whatsapp_number', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'scai_settings', 'smart_chat_whatsapp_greeting', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );

        // Calendly / booking link, pasted directly in Settings.
        register_setting( 'scai_settings', 'smart_chat_booking_url', array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );

        // Resend email delivery for lead notifications.
        register_setting( 'scai_settings', 'smart_chat_resend_api_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'scai_settings', 'smart_chat_resend_from', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );

        // Suggested starter questions (one per line) shown when the chat opens.
        register_setting( 'scai_settings', 'smart_chat_suggestions', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'smart-chat') === false) {
            return;
        }

        // wp.media() — needed for the logo picker on the settings screen.
        wp_enqueue_media();

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
        
        $wa_number = preg_replace( '/[^0-9]/', '', (string) get_option( 'smart_chat_whatsapp_number', '' ) );

        // Booking link comes from the Booking / Calendly field in Settings.
        // If that's empty, fall back to the Smart Forms Calendly URL (the
        // Calendar settings page), so the link works wherever it's configured.
        // This reads an option only — no form is embedded.
        $booking_url = (string) get_option( 'smart_chat_booking_url', '' );
        if ( '' === $booking_url ) {
            if ( class_exists( 'SFCO_Pro_Calendly' ) && method_exists( 'SFCO_Pro_Calendly', 'get_booking_url' ) ) {
                $booking_url = (string) SFCO_Pro_Calendly::get_booking_url();
            }
            if ( '' === $booking_url ) {
                // Direct option read in case the class isn't loaded on the front end.
                if ( get_option( 'sfco_pro_calendly_enabled', 0 ) ) {
                    $booking_url = (string) get_option( 'sfco_pro_calendly_url', '' );
                }
            }
        }

        // Suggested starter questions, one per line in Settings. Defaults give
        // useful shortcuts out of the box.
        $raw_suggestions = (string) get_option( 'smart_chat_suggestions', "Do you clean carpet?\nCan I get a quote?\nWhat areas do you serve?\nI want to schedule a visit" );
        $suggestions = array_values( array_filter( array_map( 'trim', explode( "\n", $raw_suggestions ) ) ) );
        $suggestions = array_slice( $suggestions, 0, 6 );

        wp_localize_script('scai-widget', 'scaiConfig', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('scai_widget'),
            'position' => get_option('smart_chat_chat_position', 'bottom-right'),
            'color' => get_option('smart_chat_chat_color', '#43A94B'),
            'title' => get_option('smart_chat_chat_title', 'Chat with us!'),
            'subtitle' => get_option('smart_chat_chat_subtitle', 'We typically reply in a few minutes'),
            'businessName' => get_option('smart_chat_business_name', get_bloginfo('name')),
            'whatsappNumber' => $wa_number,
            'whatsappGreeting' => get_option( 'smart_chat_whatsapp_greeting', "Hi! I'd like to ask about your services." ),
            'bookingUrl' => esc_url_raw( $booking_url ),
            'suggestions' => $suggestions,
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
    /**
     * Admin AJAX: ping the AI provider with a tiny prompt and return the raw
     * outcome so misconfiguration (bad key, wrong provider) is obvious.
     */
    public function ajax_test_ai() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Not allowed.' ) );
        }
        check_ajax_referer( 'scai_test_ai', 'nonce' );

        $ai  = new SCAI_AI_Handler();
        $res = $ai->get_response( 'Say "ok" if you can read this.', 'admin-test-' . time() );

        if ( ! empty( $res['error'] ) ) {
            $msg = $ai->last_error ? $ai->last_error : $res['message'];
            wp_send_json_error( array( 'message' => $msg ) );
        }
        wp_send_json_success( array( 'message' => 'Connected. Reply: ' . $res['message'] ) );
    }

    /**
     * Build a stable session id from the visitor's IP so each IP has exactly
     * one ongoing conversation. Hashed with the site's auth salt so the raw IP
     * isn't used directly as a key.
     */
    private function session_id_for_ip() {
        $ip = '';
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        if ( '' === $ip ) {
            $ip = 'unknown';
        }
        $salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'scai';
        return 'ip_' . substr( hash( 'sha256', $ip . '|' . $salt ), 0, 32 );
    }

    public function ajax_send_message() {
        check_ajax_referer('scai_widget', 'nonce');

        $message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';

        if ( '' === $message ) {
            wp_send_json_error( array( 'message' => __( 'Missing message.', 'smart-chat-ai' ) ) );
        }

        // One conversation per IP address. We derive the session from the
        // visitor's IP (hashed) instead of a per-browser id, so refreshes,
        // new tabs, and return visits all land in the same thread.
        $session_id = $this->session_id_for_ip();
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_conversations';

        // Save the visitor's message FIRST so the conversation is always
        // captured, even if the AI provider call fails or times out.
        $wpdb->insert($table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'session_id' => $session_id,
            'message' => $message,
            'sender' => 'user',
            'created_at' => current_time('mysql'),
        ));

        // Get AI response. Guarded so a provider error never loses the message
        // we just saved or breaks the request.
        try {
            $ai_handler = new SCAI_AI_Handler();
            $response = $ai_handler->get_response($message, $session_id);
        } catch ( \Throwable $e ) {
            error_log( 'Smart Chat AI response failed: ' . $e->getMessage() );
            $response = array(
                'message' => __( 'Sorry, I had trouble responding. Please try again.', 'smart-chat-ai' ),
                'model'   => null,
                'tokens'  => 0,
                'error'   => true,
            );
        }

        $wpdb->insert($table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'session_id' => $session_id,
            'message' => $response['message'],
            'sender' => 'ai',
            'ai_model' => $response['model'],
            'tokens_used' => $response['tokens'],
            'created_at' => current_time('mysql'),
        ));

        // Native, free-hand lead capture. No form: as soon as the visitor has
        // shared a name + an email or phone in the conversation, pull it out and
        // save a chat lead (which bridges into Smart Forms/CRM) and email it.
        // Wrapped so a failing CRM/bridge hook can never break the conversation.
        try {
            $this->maybe_capture_lead( $session_id );
        } catch ( \Throwable $e ) {
            error_log( 'Smart Chat lead capture failed: ' . $e->getMessage() );
        }

        wp_send_json_success($response);
    }

    /**
     * Extract a lead from the free-text conversation and save it once we have a
     * name plus a way to reach them. Runs at most once per session.
     */
    private function maybe_capture_lead( $session_id ) {
        global $wpdb;
        $conv = $wpdb->prefix . 'smart_chat_conversations';

        // Already captured for this session? Check the leads table by a session
        // marker we append to the lead's message, so this never depends on a
        // column that might be missing on an older conversations table.
        $leads = $wpdb->prefix . 'smart_chat_leads';
        $has_lead = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM {$leads} WHERE message LIKE %s",
            '%' . $wpdb->esc_like( '[sid:' . $session_id . ']' ) . '%'
        ) );
        if ( $has_lead ) {
            return;
        }

        // Only spend an extraction call once contact info actually appears.
        $text = (string) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT GROUP_CONCAT(message SEPARATOR ' ') FROM {$conv} WHERE session_id = %s AND sender = 'user'",
            $session_id
        ) );
        $has_email = (bool) preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/', $text );
        $has_phone = (bool) preg_match( '/(?:\+?\d[\s().\-]?){7,}/', $text );
        if ( ! $has_email && ! $has_phone ) {
            return;
        }

        $ai   = new SCAI_AI_Handler();
        $info = $ai->extract_lead_info( $session_id );
        if ( ! is_array( $info ) ) {
            $info = array();
        }

        $name  = sanitize_text_field( (string) ( $info['name'] ?? '' ) );
        $email = sanitize_email( (string) ( $info['email'] ?? '' ) );
        $phone = sanitize_text_field( (string) ( $info['phone'] ?? '' ) );

        // Anti-hallucination: the AI sometimes invents a plausible email or
        // phone the visitor never typed. Only keep a value if it actually
        // appears in the visitor's own messages. Compare digits-only for phone.
        if ( '' !== $email && false === stripos( $text, $email ) ) {
            $email = '';
        }
        if ( '' !== $phone ) {
            $phone_digits = preg_replace( '/\D+/', '', $phone );
            $text_digits  = preg_replace( '/\D+/', '', $text );
            if ( strlen( $phone_digits ) < 7 || false === strpos( $text_digits, $phone_digits ) ) {
                $phone = '';
            }
        }

        // Fallback: if the AI didn't return a usable email/phone but one is
        // clearly present in the visitor text, pull it straight out. The
        // contact info is right there, so we don't depend on the AI to find it.
        if ( '' === $email && preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/', $text, $em ) ) {
            $email = sanitize_email( $em[0] );
        }
        if ( '' === $phone && preg_match( '/\(?\+?\d[\d\s().\-]{6,}\d/', $text, $ph ) ) {
            $cand = preg_replace( '/\D+/', '', $ph[0] );
            if ( strlen( $cand ) >= 7 && strlen( $cand ) <= 15 ) {
                $phone = sanitize_text_field( trim( $ph[0] ) );
            }
        }

        if ( '' === $email && '' === $phone ) {
            return; // nothing the visitor actually provided
        }

        // Don't let an invented name through: keep the AI's name only when it
        // appears in the visitor's text; otherwise fall back to a neutral label.
        if ( '' !== $name && false === stripos( $text, $name ) ) {
            $name = '';
        }
        if ( '' === $name ) {
            $name = $email ? strstr( $email, '@', true ) : __( 'Website visitor', 'smart-chat-ai' );
        }

        $topic = sanitize_text_field( (string) ( $info['service_type'] ?? ( $info['message'] ?? '' ) ) );

        // Classify the visitor's INTENT from what they said. The chat can only
        // know they signaled intent, not that anything was actually booked, so
        // these are intent slugs (the CRM owns the real "booked" tag, which
        // fires from Calendly/ServiceM8 events, not from chat).
        $intent = '';
        if ( preg_match( '/\b(schedul|book|appointment|set up a time|pick a time|come (out|by)|visit|walk[- ]?through)\b/i', $text ) ) {
            $intent = 'booking';
        } elseif ( preg_match( '/\b(quote|estimate|how much|pricing|price|cost)\b/i', $text ) ) {
            $intent = 'quote';
        } elseif ( preg_match( '/\b(call me|call back|callback|reach me|get in touch|follow up|have someone)\b/i', $text ) ) {
            $intent = 'callback';
        }

        // Human-readable label on the lead row for the team to skim.
        if ( 'booking' === $intent ) {
            $label = 'Sought to book appointment';
        } elseif ( 'quote' === $intent ) {
            $label = 'Requested a quote';
        } elseif ( 'callback' === $intent ) {
            $label = 'Requested a callback';
        } else {
            $label = '';
        }
        if ( '' !== $label ) {
            $topic = $label . ( '' !== $topic ? ', ' . $topic : '' );
        }

        // Append a hidden session marker so we can tell this session already
        // produced a lead without relying on the conversations lead_id column.
        $message_with_marker = trim( $topic . ' [sid:' . $session_id . ']' );

        $lead_manager = new SCAI_Lead_Manager();
        $lead_id = $lead_manager->create_lead( array(
            'name'         => $name,
            'email'        => $email,
            'phone'        => $phone,
            'message'      => $message_with_marker,
            'service_type' => $topic,
            'session_id'   => $session_id,
            'intent'       => $intent, // booking | quote | callback | '' (for the CRM bridge)
        ) );

        if ( $lead_id && get_option( 'smart_chat_enable_email_notifications', true ) ) {
            $this->send_lead_notification( $lead_id );
        }
    }

    /**
     * Email the team when a chat lead is captured.
     */
    private function send_lead_notification( $lead_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smart_chat_leads';
        $lead  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $lead ) {
            return;
        }

        // Recipient: the notification email set in Settings, falling back to the
        // support inbox, then the site admin. wp_mail is intercepted by the
        // Smart Forms Resend integration (pre_wp_mail) when Resend is enabled,
        // so this is delivered through Resend without any extra code here.
        $to = get_option( 'smart_chat_lead_email', '' );
        if ( '' === $to || ! is_email( $to ) ) {
            $to = 'support@midlandfloors.com';
        }

        $clean_topic = trim( preg_replace( '/\s*\[sid:[^\]]+\]/', '', (string) $lead->message ) );
        $name = $lead->name ? $lead->name : __( 'Website visitor', 'smart-chat-ai' );

        $subject = sprintf( __( 'New chat lead: %s', 'smart-chat-ai' ), $name );

        $message  = __( 'New lead captured from the website chat:', 'smart-chat-ai' ) . "\n\n";
        $message .= __( 'Name:', 'smart-chat-ai' )  . ' ' . $name . "\n";
        $message .= __( 'Email:', 'smart-chat-ai' ) . ' ' . ( $lead->email ? $lead->email : '-' ) . "\n";
        $message .= __( 'Phone:', 'smart-chat-ai' ) . ' ' . ( $lead->phone ? $lead->phone : '-' ) . "\n";
        $message .= __( 'About:', 'smart-chat-ai' ) . ' ' . ( '' !== $clean_topic ? $clean_topic : '-' ) . "\n\n";
        $message .= __( 'See the full conversation:', 'smart-chat-ai' ) . ' ' . admin_url( 'admin.php?page=smart-chat-conversations' );

        // Reply-To set to the visitor's email so the team can reply straight
        // from the inbox.
        $reply_to = '';
        if ( $lead->email && is_email( $lead->email ) ) {
            $reply_to = $name . ' <' . $lead->email . '>';
        }

        // If a Resend API key is configured here, send directly through Resend.
        // Otherwise fall back to wp_mail (which a site-wide mailer may handle).
        $resend_key = trim( (string) get_option( 'smart_chat_resend_api_key', '' ) );
        if ( '' !== $resend_key ) {
            $sent = $this->send_via_resend( $resend_key, $to, $subject, $message, $reply_to );
            if ( $sent ) {
                return;
            }
            // If Resend failed, fall through to wp_mail as a backup.
        }

        $headers = array();
        if ( '' !== $reply_to ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }
        wp_mail( $to, $subject, $message, $headers );
    }

    /**
     * Send a plain-text notification through the Resend API.
     * Returns true on a 2xx response, false otherwise.
     */
    private function send_via_resend( $api_key, $to, $subject, $message, $reply_to = '' ) {
        // From address: use the configured sender, else a safe default. The
        // sending domain must be verified in your Resend account.
        $from = trim( (string) get_option( 'smart_chat_resend_from', '' ) );
        if ( '' === $from ) {
            $from = 'Midland Floor Care <support@midlandfloors.com>';
        }

        $payload = array(
            'from'    => $from,
            'to'      => array( $to ),
            'subject' => $subject,
            'text'    => $message,
        );
        if ( '' !== $reply_to ) {
            $payload['reply_to'] = $reply_to;
        }

        $response = wp_remote_post( 'https://api.resend.com/emails', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Smart Chat Resend transport error: ' . $response->get_error_message() );
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            error_log( 'Smart Chat Resend error HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
            return false;
        }
        return true;
    }

    /**
     * Admin dashboard page
     */
    public function admin_dashboard_page() {
        include SCAI_PLUGIN_DIR . 'admin/dashboard.php';
    }

    /**
     * Admin leads page — chat leads captured through the conversation.
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
