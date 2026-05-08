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
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_tagglefish_styles' ) );
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

        add_submenu_page(
            'smart-messages',
            __( 'TaggleFish Products', 'smart-messages' ),
            '🐟 ' . __( 'TaggleFish', 'smart-messages' ),
            'manage_options',
            'smart-messages-tagglefish',
            array( $this, 'page_tagglefish' )
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

    public function enqueue_tagglefish_styles( $hook ) {
        if ( 'smart-messages_page_smart-messages-tagglefish' !== $hook ) {
            return;
        }

        $css = '
            .smsg-tf-wrap { max-width: 1200px; margin: 20px auto; }
            .smsg-tf-intro { font-size: 16px; color: #555; margin-bottom: 30px; }
            .smsg-tf-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 24px;
                margin-bottom: 40px;
            }
            @media (max-width: 1200px) {
                .smsg-tf-grid { grid-template-columns: repeat(2, 1fr); }
            }
            @media (max-width: 782px) {
                .smsg-tf-grid { grid-template-columns: 1fr; }
            }
            .smsg-tf-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                display: flex;
                flex-direction: column;
            }
            .smsg-tf-card-header {
                padding: 24px;
                color: #fff;
                text-align: center;
            }
            .smsg-tf-card-header.smsg-tf-orange {
                background: linear-gradient(135deg, #ff6b35, #ff8f5e);
            }
            .smsg-tf-card-header.smsg-tf-green {
                background: linear-gradient(135deg, #00a32a, #33b84f);
            }
            .smsg-tf-card-header.smsg-tf-blue {
                background: linear-gradient(135deg, #1e3a8a, #3b5fc0);
            }
            .smsg-tf-card-header .dashicons {
                font-size: 40px;
                width: 40px;
                height: 40px;
                margin-bottom: 10px;
            }
            .smsg-tf-card-header h3 {
                margin: 0;
                font-size: 20px;
                color: #fff;
            }
            .smsg-tf-card-body {
                padding: 24px;
                flex: 1;
                display: flex;
                flex-direction: column;
            }
            .smsg-tf-tagline {
                font-size: 14px;
                color: #555;
                font-style: italic;
                margin: 0 0 16px;
            }
            .smsg-tf-features {
                list-style: none;
                margin: 0 0 20px;
                padding: 0;
                flex: 1;
            }
            .smsg-tf-features li {
                padding: 6px 0 6px 24px;
                position: relative;
                font-size: 14px;
                color: #333;
            }
            .smsg-tf-features li::before {
                content: "\2713";
                position: absolute;
                left: 0;
                color: #00a32a;
                font-weight: bold;
            }
            .smsg-tf-cta {
                display: inline-block;
                padding: 12px 24px;
                border-radius: 5px;
                color: #fff !important;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                text-align: center;
                transition: opacity 0.2s;
            }
            .smsg-tf-cta:hover { opacity: 0.9; color: #fff !important; }
            .smsg-tf-cta.smsg-tf-btn-orange { background: #ff6b35; }
            .smsg-tf-cta.smsg-tf-btn-green  { background: #00a32a; }
            .smsg-tf-cta.smsg-tf-btn-blue   { background: #1e3a8a; }
            .smsg-tf-footer {
                text-align: center;
                padding: 20px;
                color: #888;
                font-size: 13px;
                border-top: 1px solid #e0e0e0;
            }
            .smsg-tf-footer a {
                color: #1e3a8a;
                text-decoration: none;
            }
            .smsg-tf-footer a:hover { text-decoration: underline; }
        ';

        wp_register_style( 'smsg-tagglefish', false );
        wp_enqueue_style( 'smsg-tagglefish' );
        wp_add_inline_style( 'smsg-tagglefish', $css );
    }

    public function page_tagglefish() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap smsg-tf-wrap">
            <h1><?php esc_html_e( '🐟 TaggleFish Products', 'smart-messages' ); ?></h1>
            <p class="smsg-tf-intro"><?php esc_html_e( 'Tools built by contractors, for contractors.', 'smart-messages' ); ?></p>

            <div class="smsg-tf-grid">

                <!-- Card 1: $500 Contractor Website -->
                <div class="smsg-tf-card">
                    <div class="smsg-tf-card-header smsg-tf-orange">
                        <span class="dashicons dashicons-admin-multisite"></span>
                        <h3><?php esc_html_e( '$500 Contractor Website', 'smart-messages' ); ?></h3>
                    </div>
                    <div class="smsg-tf-card-body">
                        <p class="smsg-tf-tagline"><?php esc_html_e( 'Turn local searches into ringing phones.', 'smart-messages' ); ?></p>
                        <ul class="smsg-tf-features">
                            <li><?php esc_html_e( 'Built in 7 days', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'GMB mirrored', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'Mobile-optimized', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'No monthly fees', 'smart-messages' ); ?></li>
                        </ul>
                        <a href="https://deals.tagglefish.com/" target="_blank" rel="noopener noreferrer" class="smsg-tf-cta smsg-tf-btn-orange">
                            <?php esc_html_e( 'Get Your $500 Website', 'smart-messages' ); ?>
                        </a>
                    </div>
                </div>

                <!-- Card 2: Git Deploy for SEO -->
                <div class="smsg-tf-card">
                    <div class="smsg-tf-card-header smsg-tf-green">
                        <span class="dashicons dashicons-cloud-upload"></span>
                        <h3><?php esc_html_e( 'Git Deploy for SEO', 'smart-messages' ); ?></h3>
                    </div>
                    <div class="smsg-tf-card-body">
                        <p class="smsg-tf-tagline"><?php esc_html_e( 'AI-powered SEO content generation at scale.', 'smart-messages' ); ?></p>
                        <ul class="smsg-tf-features">
                            <li><?php esc_html_e( 'Generate hundreds of pages with Claude Code', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'GitHub backup', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'One-click deploy', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'Auto-deploy webhooks', 'smart-messages' ); ?></li>
                        </ul>
                        <a href="https://wordpress.org/plugins/git-deploy-for-seo-by-tagglefish/" target="_blank" rel="noopener noreferrer" class="smsg-tf-cta smsg-tf-btn-green">
                            <?php esc_html_e( 'Download FREE Plugin', 'smart-messages' ); ?>
                        </a>
                    </div>
                </div>

                <!-- Card 3: Smart Forms PRO -->
                <div class="smsg-tf-card">
                    <div class="smsg-tf-card-header smsg-tf-blue">
                        <span class="dashicons dashicons-superhero"></span>
                        <h3><?php esc_html_e( 'Smart Forms PRO', 'smart-messages' ); ?></h3>
                    </div>
                    <div class="smsg-tf-card-body">
                        <p class="smsg-tf-tagline"><?php esc_html_e( 'Stop losing jobs to faster competitors.', 'smart-messages' ); ?></p>
                        <ul class="smsg-tf-features">
                            <li><?php esc_html_e( 'Lead scoring', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'CRM sync (HubSpot/Salesforce/Pipedrive)', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'Automated follow-ups & SMS', 'smart-messages' ); ?></li>
                            <li><?php esc_html_e( 'Analytics & calendar', 'smart-messages' ); ?></li>
                        </ul>
                        <a href="https://livableforms.com/smart-forms-pro.html" target="_blank" rel="noopener noreferrer" class="smsg-tf-cta smsg-tf-btn-blue">
                            <?php esc_html_e( 'Upgrade to PRO - $399/year', 'smart-messages' ); ?>
                        </a>
                    </div>
                </div>

            </div>

            <div class="smsg-tf-footer">
                <p>
                    <?php
                    printf(
                        /* translators: 1: link to tagglefish.com, 2: support email link */
                        esc_html__( 'Built by %1$s | Questions? %2$s', 'smart-messages' ),
                        '<a href="https://tagglefish.com" target="_blank" rel="noopener noreferrer">tagglefish.com</a>',
                        '<a href="mailto:support@tagglefish.com">support@tagglefish.com</a>'
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
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
