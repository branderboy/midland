<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMSG_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'Smart Messages', 'smart-messages' ),
            __( 'Smart Messages', 'smart-messages' ),
            'manage_options',
            'smart-messages',
            array( $this, 'page_settings' ),
            'dashicons-whatsapp',
            32
        );
    }

    public function register_settings() {
        // WhatsApp
        register_setting( 'smsg_settings', 'smsg_whatsapp_token' );
        register_setting( 'smsg_settings', 'smsg_whatsapp_phone_id' );
        register_setting( 'smsg_settings', 'smsg_business_name' );

        // Twilio SMS
        register_setting( 'smsg_settings', 'smsg_twilio_sid' );
        register_setting( 'smsg_settings', 'smsg_twilio_token' );
        register_setting( 'smsg_settings', 'smsg_twilio_phone' );
        register_setting( 'smsg_settings', 'smsg_sms_fallback' );

        // Contractor notification
        register_setting( 'smsg_settings', 'smsg_contractor_phone' );
        register_setting( 'smsg_settings', 'smsg_notify_contractor' );

        // Triggers
        register_setting( 'smsg_settings', 'smsg_send_on_lead' );
        register_setting( 'smsg_settings', 'smsg_send_on_request' );
        register_setting( 'smsg_settings', 'smsg_send_on_confirm' );
        register_setting( 'smsg_settings', 'smsg_send_on_deny' );
        register_setting( 'smsg_settings', 'smsg_send_on_suggest' );
        register_setting( 'smsg_settings', 'smsg_send_reminder' );

        // Templates
        register_setting( 'smsg_settings', 'smsg_template_lead' );
        register_setting( 'smsg_settings', 'smsg_template_request' );
        register_setting( 'smsg_settings', 'smsg_template_confirmed' );
        register_setting( 'smsg_settings', 'smsg_template_denied' );
        register_setting( 'smsg_settings', 'smsg_template_suggested' );
        register_setting( 'smsg_settings', 'smsg_template_reminder' );
        register_setting( 'smsg_settings', 'smsg_template_contractor' );
    }


    public function page_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $api      = SMSG_WhatsApp_API::get_instance();
        $messages = $api->get_messages( null, 20 );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart Messages', 'smart-messages' ); ?></h1>

            <div style="display:flex;gap:20px;margin-top:20px;">
                <div style="flex:2;">
                    <form method="post" action="options.php">
                        <?php settings_fields( 'smsg_settings' ); ?>

                        <!-- WhatsApp Config -->
                        <div class="card" style="padding:20px;">
                            <h2><?php esc_html_e( 'WhatsApp Configuration', 'smart-messages' ); ?></h2>
                            <?php if ( $api->is_configured() ) : ?>
                                <p style="color:#38a169;font-weight:600;"><?php esc_html_e( 'WhatsApp Connected', 'smart-messages' ); ?></p>
                            <?php else : ?>
                                <p style="color:#e53e3e;font-weight:600;"><?php esc_html_e( 'Not Connected', 'smart-messages' ); ?></p>
                            <?php endif; ?>

                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e( 'Access Token', 'smart-messages' ); ?></th>
                                    <td><input type="password" name="smsg_whatsapp_token" value="<?php echo esc_attr( get_option( 'smsg_whatsapp_token' ) ); ?>" style="width:100%;"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Phone Number ID', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_whatsapp_phone_id" value="<?php echo esc_attr( get_option( 'smsg_whatsapp_phone_id' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Business Name', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_business_name" value="<?php echo esc_attr( get_option( 'smsg_business_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text"></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Twilio SMS Config -->
                        <div class="card" style="padding:20px;margin-top:20px;">
                            <h2><?php esc_html_e( 'Twilio SMS Configuration', 'smart-messages' ); ?></h2>
                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e( 'Twilio Account SID', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_twilio_sid" value="<?php echo esc_attr( get_option( 'smsg_twilio_sid' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Twilio Auth Token', 'smart-messages' ); ?></th>
                                    <td><input type="password" name="smsg_twilio_token" value="<?php echo esc_attr( get_option( 'smsg_twilio_token' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Twilio Phone Number', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_twilio_phone" value="<?php echo esc_attr( get_option( 'smsg_twilio_phone' ) ); ?>" class="regular-text" placeholder="+15551234567"></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Contractor Notifications -->
                        <div class="card" style="padding:20px;margin-top:20px;">
                            <h2><?php esc_html_e( 'Notify You (Contractor)', 'smart-messages' ); ?></h2>

                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e( 'Your Phone Number', 'smart-messages' ); ?></th>
                                    <td>
                                        <input type="text" name="smsg_contractor_phone" value="<?php echo esc_attr( get_option( 'smsg_contractor_phone' ) ); ?>" class="regular-text" placeholder="+15551234567">
                                        <p class="description"><?php esc_html_e( 'Get WhatsApp when new booking requests come in', 'smart-messages' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Enable Notifications', 'smart-messages' ); ?></th>
                                    <td><label><input type="checkbox" name="smsg_notify_contractor" value="1" <?php checked( get_option( 'smsg_notify_contractor', '1' ), '1' ); ?>> <?php esc_html_e( 'WhatsApp me on new booking requests', 'smart-messages' ); ?></label></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Customer Triggers -->
                        <div class="card" style="padding:20px;margin-top:20px;">
                            <h2><?php esc_html_e( 'Customer Message Triggers', 'smart-messages' ); ?></h2>
                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e( 'New Lead (no booking)', 'smart-messages' ); ?></th>
                                    <td><label><input type="checkbox" name="smsg_send_on_lead" value="1" <?php checked( get_option( 'smsg_send_on_lead', '1' ), '1' ); ?>> <?php esc_html_e( 'Send intro message', 'smart-messages' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Booking Requested', 'smart-messages' ); ?></th>
                                    <td><label><input type="checkbox" name="smsg_send_on_request" value="1" <?php checked( get_option( 'smsg_send_on_request', '1' ), '1' ); ?>> <?php esc_html_e( 'We will confirm shortly', 'smart-messages' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Booking Confirmed', 'smart-messages' ); ?></th>
                                    <td><label><input type="checkbox" name="smsg_send_on_confirm" value="1" <?php checked( get_option( 'smsg_send_on_confirm', '1' ), '1' ); ?>> <?php esc_html_e( 'Your appointment is confirmed', 'smart-messages' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Booking Denied', 'smart-messages' ); ?></th>
                                    <td><label><input type="checkbox" name="smsg_send_on_deny" value="1" <?php checked( get_option( 'smsg_send_on_deny', '1' ), '1' ); ?>> <?php esc_html_e( 'Please pick another time', 'smart-messages' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Time Suggested', 'smart-messages' ); ?></th>
                                    <td><label><input type="checkbox" name="smsg_send_on_suggest" value="1" <?php checked( get_option( 'smsg_send_on_suggest', '1' ), '1' ); ?>> <?php esc_html_e( 'How about this time?', 'smart-messages' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( '24hr Reminder', 'smart-messages' ); ?></th>
                                    <td><label><input type="checkbox" name="smsg_send_reminder" value="1" <?php checked( get_option( 'smsg_send_reminder', '1' ), '1' ); ?>> <?php esc_html_e( 'Remind customer day before', 'smart-messages' ); ?></label></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Templates -->
                        <div class="card" style="padding:20px;margin-top:20px;">
                            <h2><?php esc_html_e( 'WhatsApp Template Names', 'smart-messages' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Enter exact template names from Meta Business Suite', 'smart-messages' ); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e( 'Lead Received', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_template_lead" value="<?php echo esc_attr( get_option( 'smsg_template_lead', 'lead_received' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Booking Requested', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_template_request" value="<?php echo esc_attr( get_option( 'smsg_template_request', 'booking_requested' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Booking Confirmed', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_template_confirmed" value="<?php echo esc_attr( get_option( 'smsg_template_confirmed', 'booking_confirmed' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Booking Denied', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_template_denied" value="<?php echo esc_attr( get_option( 'smsg_template_denied', 'booking_denied' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Time Suggested', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_template_suggested" value="<?php echo esc_attr( get_option( 'smsg_template_suggested', 'time_suggested' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( '24hr Reminder', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_template_reminder" value="<?php echo esc_attr( get_option( 'smsg_template_reminder', 'appointment_reminder' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'New Request (to you)', 'smart-messages' ); ?></th>
                                    <td><input type="text" name="smsg_template_contractor" value="<?php echo esc_attr( get_option( 'smsg_template_contractor', 'new_booking_request' ) ); ?>" class="regular-text"></td>
                                </tr>
                            </table>
                        </div>

                        <?php submit_button( __( 'Save Settings', 'smart-messages' ) ); ?>
                    </form>
                </div>

                <div style="flex:1;">
                    <div class="card" style="padding:20px;">
                        <h2><?php esc_html_e( 'Recent Messages', 'smart-messages' ); ?></h2>
                        <?php if ( empty( $messages ) ) : ?>
                            <p style="color:#666;"><?php esc_html_e( 'No messages sent yet.', 'smart-messages' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( $messages as $m ) :
                                $icon = ( $m->channel ?? 'whatsapp' ) === 'sms' ? '&#x1F4AC;' : '&#x1F4F1;';
                            ?>
                                <div style="border-bottom:1px solid #eee;padding:10px 0;">
                                    <strong><?php echo esc_html( $m->phone ); ?></strong>
                                    <span style="float:right;color:<?php echo $m->status === 'sent' ? '#38a169' : '#e53e3e'; ?>;">
                                        <?php echo $m->status === 'sent' ? '&#x2713;' : '&#x2717;'; ?>
                                    </span>
                                    <br><small style="color:#666;"><?php echo esc_html( $m->template ); ?></small>
                                    <br><small style="color:#999;"><?php echo esc_html( date_i18n( 'M j, g:i A', strtotime( $m->created_at ) ) ); ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="card" style="padding:20px;margin-top:20px;">
                        <h2><?php esc_html_e( 'Template Variables', 'smart-messages' ); ?></h2>
                        <p><strong><?php esc_html_e( 'Customer messages:', 'smart-messages' ); ?></strong></p>
                        <ul style="margin-left:20px;">
                            <li><code>{{1}}</code> = <?php esc_html_e( 'First name', 'smart-messages' ); ?></li>
                            <li><code>{{2}}</code> = <?php esc_html_e( 'Date/time', 'smart-messages' ); ?></li>
                            <li><code>{{3}}</code> = <?php esc_html_e( 'Business name', 'smart-messages' ); ?></li>
                        </ul>
                        <p><strong><?php esc_html_e( 'Contractor messages:', 'smart-messages' ); ?></strong></p>
                        <ul style="margin-left:20px;">
                            <li><code>{{1}}</code> = <?php esc_html_e( 'Customer name', 'smart-messages' ); ?></li>
                            <li><code>{{2}}</code> = <?php esc_html_e( 'Date/time', 'smart-messages' ); ?></li>
                            <li><code>{{3}}</code> = <?php esc_html_e( 'Service type', 'smart-messages' ); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
