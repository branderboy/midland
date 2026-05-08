<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handoff bridge — when the chat captures a lead during business hours, ping
 * the live customer-service team via Midland Smart Messages (WhatsApp). Outside
 * business hours the AI's "we'll follow up soon" reply is the only response.
 *
 * Settings: Midland Smart Chat > Handoff
 */
class SCAI_Handoff {

    const OPT_ENABLED          = 'scai_handoff_enabled';
    const OPT_OWNER_PHONE       = 'scai_handoff_owner_phone';
    const OPT_TEMPLATE          = 'scai_handoff_template';
    const OPT_HOURS_START       = 'scai_handoff_hours_start';
    const OPT_HOURS_END         = 'scai_handoff_hours_end';
    const OPT_DAYS              = 'scai_handoff_days';
    const OPT_NOTIFY_CUSTOMER   = 'scai_handoff_notify_customer';
    const OPT_CUSTOMER_TEMPLATE = 'scai_handoff_customer_template';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'scai_lead_captured', array( $this, 'on_lead_captured' ), 10, 2 );
        add_action( 'admin_menu',         array( $this, 'add_menu' ), 60 );
        add_action( 'admin_init',         array( $this, 'handle_save' ) );
    }

    public function on_lead_captured( $lead_id, $data ) {
        if ( ! get_option( self::OPT_ENABLED, 0 ) ) {
            return;
        }
        if ( ! $this->is_within_business_hours() ) {
            // Outside hours — no live handoff. The AI already told the visitor we'll follow up soon.
            return;
        }
        if ( ! class_exists( 'SMSG_WhatsApp_API' ) ) {
            return;
        }
        $api = SMSG_WhatsApp_API::get_instance();
        if ( ! $api->is_configured() ) {
            return;
        }

        $owner_phone = (string) get_option( self::OPT_OWNER_PHONE, '' );
        $template    = sanitize_text_field( (string) get_option( self::OPT_TEMPLATE, '' ) );

        if ( $owner_phone && $template ) {
            $params = array(
                (string) ( $data['name'] ?? '' ),
                (string) ( $data['service_type'] ?? $data['message'] ?? '' ),
                (string) ( $data['phone'] ?? $data['email'] ?? '' ),
            );
            $api->send_template_message( $owner_phone, $template, $params, $lead_id );
        }

        // Optional customer auto-confirmation.
        if ( get_option( self::OPT_NOTIFY_CUSTOMER, 0 ) ) {
            $customer_phone    = (string) ( $data['phone'] ?? '' );
            $customer_template = sanitize_text_field( (string) get_option( self::OPT_CUSTOMER_TEMPLATE, '' ) );
            if ( $customer_phone && $customer_template ) {
                $params = array( (string) ( $data['name'] ?? '' ) );
                $api->send_template_message( $customer_phone, $customer_template, $params, $lead_id );
            }
        }
    }

    /**
     * Check whether the current site time is inside the configured business hours.
     * Days are stored as a comma-separated list of numbers 0..6 where 0 = Sunday.
     */
    public function is_within_business_hours() {
        $start_raw = (string) get_option( self::OPT_HOURS_START, '09:00' );
        $end_raw   = (string) get_option( self::OPT_HOURS_END, '17:00' );
        $days_raw  = (string) get_option( self::OPT_DAYS, '1,2,3,4,5' ); // Mon–Fri

        $allowed_days = array_filter( array_map( 'intval', explode( ',', $days_raw ) ), function( $d ) {
            return $d >= 0 && $d <= 6;
        } );
        if ( empty( $allowed_days ) ) {
            return false;
        }

        $now = current_datetime();
        if ( ! in_array( (int) $now->format( 'w' ), $allowed_days, true ) ) {
            return false;
        }

        $current = (int) $now->format( 'Hi' );
        $start   = (int) preg_replace( '/[^0-9]/', '', $start_raw );
        $end     = (int) preg_replace( '/[^0-9]/', '', $end_raw );

        if ( $start === $end ) {
            return false;
        }
        if ( $start < $end ) {
            return $current >= $start && $current < $end;
        }
        // Overnight window (e.g. 22:00 → 06:00).
        return $current >= $start || $current < $end;
    }

    public function add_menu() {
        add_submenu_page(
            'smart-chat-ai',
            esc_html__( 'Handoff', 'smart-chat-ai' ),
            esc_html__( 'Handoff', 'smart-chat-ai' ),
            'manage_options',
            'scai-handoff',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['scai_save_handoff'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scai_handoff_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scai_handoff_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scai_save_handoff' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-chat-ai' ) );
        }

        update_option( self::OPT_ENABLED,        isset( $_POST['handoff_enabled'] ) ? 1 : 0 );
        update_option( self::OPT_OWNER_PHONE,    sanitize_text_field( wp_unslash( $_POST['owner_phone'] ?? '' ) ) );
        update_option( self::OPT_TEMPLATE,       sanitize_text_field( wp_unslash( $_POST['template_name'] ?? '' ) ) );
        update_option( self::OPT_HOURS_START,    sanitize_text_field( wp_unslash( $_POST['hours_start'] ?? '09:00' ) ) );
        update_option( self::OPT_HOURS_END,      sanitize_text_field( wp_unslash( $_POST['hours_end'] ?? '17:00' ) ) );

        $days = isset( $_POST['days'] ) && is_array( $_POST['days'] )
            ? implode( ',', array_filter( array_map( 'intval', wp_unslash( $_POST['days'] ) ), function( $d ) { return $d >= 0 && $d <= 6; } ) )
            : '';
        update_option( self::OPT_DAYS, $days );

        update_option( self::OPT_NOTIFY_CUSTOMER,   isset( $_POST['notify_customer'] ) ? 1 : 0 );
        update_option( self::OPT_CUSTOMER_TEMPLATE, sanitize_text_field( wp_unslash( $_POST['customer_template'] ?? '' ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=scai-handoff&saved=1' ) );
        exit;
    }

    public function render_page() {
        $enabled    = (int) get_option( self::OPT_ENABLED, 0 );
        $phone      = (string) get_option( self::OPT_OWNER_PHONE, '' );
        $template   = (string) get_option( self::OPT_TEMPLATE, '' );
        $start      = (string) get_option( self::OPT_HOURS_START, '09:00' );
        $end        = (string) get_option( self::OPT_HOURS_END, '17:00' );
        $days_csv   = (string) get_option( self::OPT_DAYS, '1,2,3,4,5' );
        $days       = array_filter( array_map( 'intval', explode( ',', $days_csv ) ) );
        $notify_c   = (int) get_option( self::OPT_NOTIFY_CUSTOMER, 0 );
        $cust_tpl   = (string) get_option( self::OPT_CUSTOMER_TEMPLATE, '' );

        $msgs_active = class_exists( 'SMSG_WhatsApp_API' );
        $within_now  = $this->is_within_business_hours();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved = isset( $_GET['saved'] );

        $day_names = array(
            0 => __( 'Sun', 'smart-chat-ai' ),
            1 => __( 'Mon', 'smart-chat-ai' ),
            2 => __( 'Tue', 'smart-chat-ai' ),
            3 => __( 'Wed', 'smart-chat-ai' ),
            4 => __( 'Thu', 'smart-chat-ai' ),
            5 => __( 'Fri', 'smart-chat-ai' ),
            6 => __( 'Sat', 'smart-chat-ai' ),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Handoff to Live Messages', 'smart-chat-ai' ); ?></h1>
            <p class="description"><?php esc_html_e( 'When the chat captures a lead during business hours, fire a WhatsApp template via Midland Smart Messages so a real person can take over. Outside hours the chat just promises a follow-up.', 'smart-chat-ai' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Handoff settings saved.', 'smart-chat-ai' ); ?></p></div>
            <?php endif; ?>

            <p>
                <strong><?php esc_html_e( 'Status:', 'smart-chat-ai' ); ?></strong>
                <?php if ( ! $msgs_active ) : ?>
                    <span style="color:#d63638;">&#10005; <?php esc_html_e( 'Midland Smart Messages plugin is not active. Activate it first.', 'smart-chat-ai' ); ?></span>
                <?php elseif ( $enabled && $within_now ) : ?>
                    <span style="color:#0a8754;">&#10003; <?php esc_html_e( 'Live handoff is active right now.', 'smart-chat-ai' ); ?></span>
                <?php elseif ( $enabled ) : ?>
                    <span style="color:#dba617;">&#9203; <?php esc_html_e( 'Outside business hours — handoff paused. AI will reply only.', 'smart-chat-ai' ); ?></span>
                <?php else : ?>
                    <span style="color:#999;"><?php esc_html_e( 'Disabled.', 'smart-chat-ai' ); ?></span>
                <?php endif; ?>
            </p>

            <form method="post">
                <?php wp_nonce_field( 'scai_save_handoff', '_scai_handoff_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable Handoff', 'smart-chat-ai' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="handoff_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Send WhatsApp on every chat-captured lead during business hours.', 'smart-chat-ai' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="owner_phone"><?php esc_html_e( 'Owner WhatsApp Number', 'smart-chat-ai' ); ?></label></th>
                        <td>
                            <input type="text" id="owner_phone" name="owner_phone" class="regular-text" value="<?php echo esc_attr( $phone ); ?>" placeholder="+1 301 555 0100">
                            <p class="description"><?php esc_html_e( 'Includes country code. The receiving number must already be on the WhatsApp Business approved list.', 'smart-chat-ai' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="template_name"><?php esc_html_e( 'Owner Template Name', 'smart-chat-ai' ); ?></label></th>
                        <td>
                            <input type="text" id="template_name" name="template_name" class="regular-text" value="<?php echo esc_attr( $template ); ?>" placeholder="lead_alert">
                            <p class="description"><?php esc_html_e( 'Pre-approved Meta WhatsApp template. Receives 3 body params: customer name, service/message, contact (phone or email).', 'smart-chat-ai' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Business Hours', 'smart-chat-ai' ); ?></th>
                        <td>
                            <input type="time" name="hours_start" value="<?php echo esc_attr( $start ); ?>"> &nbsp;–&nbsp;
                            <input type="time" name="hours_end" value="<?php echo esc_attr( $end ); ?>">
                            <p class="description"><?php esc_html_e( 'Site timezone:', 'smart-chat-ai' ); ?> <code><?php echo esc_html( wp_timezone_string() ); ?></code></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Days', 'smart-chat-ai' ); ?></th>
                        <td>
                            <?php foreach ( $day_names as $d => $lbl ) : ?>
                                <label style="margin-right:14px;">
                                    <input type="checkbox" name="days[]" value="<?php echo esc_attr( $d ); ?>" <?php checked( in_array( $d, $days, true ) ); ?>>
                                    <?php echo esc_html( $lbl ); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Customer Auto-Reply', 'smart-chat-ai' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="notify_customer" value="1" <?php checked( $notify_c ); ?>> <?php esc_html_e( 'Also WhatsApp the customer a confirmation with their name as param 1.', 'smart-chat-ai' ); ?></label>
                            <br>
                            <input type="text" name="customer_template" class="regular-text" value="<?php echo esc_attr( $cust_tpl ); ?>" placeholder="lead_received" style="margin-top:6px;">
                            <p class="description"><?php esc_html_e( 'Pre-approved customer-facing template name.', 'smart-chat-ai' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="scai_save_handoff" value="1" class="button button-primary"><?php esc_html_e( 'Save Handoff Settings', 'smart-chat-ai' ); ?></button></p>
            </form>
        </div>
        <?php
    }
}

SCAI_Handoff::get_instance();
