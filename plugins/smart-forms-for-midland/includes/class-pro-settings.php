<?php
/**
 * Unified Smart Forms settings page.
 *
 * One WP-admin settings page with every integration's fields inline,
 * one Save button at the bottom, traditional form-table layout. No
 * cards, no Configure buttons, no separate sub-pages to hunt through.
 *
 * The individual Pro module pages (Resend, CRM, GCal, Calendly,
 * Notifications) still exist as their own submenus because OAuth
 * callbacks and test-email flows need a real page to land on, but
 * the unified form here writes to the same wp_options keys, so the
 * operator can configure everything from one screen and the Pro
 * modules pick those values up automatically.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Settings {

    const PAGE_SLUG = 'smart-forms-settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register' ), 25 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
    }

    public function register() {
        add_submenu_page(
            'smart-forms',
            __( 'Settings', 'smart-forms-for-midland' ),
            __( 'Settings', 'smart-forms-for-midland' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render' )
        );
    }

    /**
     * Save handler for the unified form. Writes each section straight
     * to the option keys the underlying Pro modules already read, so
     * no module-level refactor is needed and per-module pages keep
     * working too.
     */
    public function handle_save() {
        if ( empty( $_POST['sfco_unified_save'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! check_admin_referer( 'sfco_unified_save', '_sfco_unified_nonce' ) ) {
            return;
        }

        // Form Notifications.
        $notif_defaults = class_exists( 'SFCO_Pro_Notifications' ) ? SFCO_Pro_Notifications::defaults() : array();
        $notif = wp_parse_args(
            array(
                'admin_enabled'        => isset( $_POST['admin_enabled'] ) ? 1 : 0,
                'admin_to'             => sanitize_text_field( wp_unslash( $_POST['admin_to'] ?? '' ) ),
                'admin_subject'        => sanitize_text_field( wp_unslash( $_POST['admin_subject'] ?? '' ) ),
                'admin_body'           => wp_kses_post( wp_unslash( $_POST['admin_body'] ?? '' ) ),
                'autoreply_enabled'    => isset( $_POST['autoreply_enabled'] ) ? 1 : 0,
                'autoreply_from_name'  => sanitize_text_field( wp_unslash( $_POST['autoreply_from_name'] ?? '' ) ),
                'autoreply_from_email' => sanitize_email( wp_unslash( $_POST['autoreply_from_email'] ?? '' ) ),
                'autoreply_subject'    => sanitize_text_field( wp_unslash( $_POST['autoreply_subject'] ?? '' ) ),
                'autoreply_body'       => wp_kses_post( wp_unslash( $_POST['autoreply_body'] ?? '' ) ),
            ),
            $notif_defaults
        );
        update_option( 'sfco_pro_notifications', $notif, false );

        // Resend.
        update_option( 'sfco_resend_enabled',    isset( $_POST['resend_enabled'] ) ? 1 : 0 );
        update_option( 'sfco_resend_api_key',    sanitize_text_field( wp_unslash( $_POST['resend_api_key'] ?? '' ) ) );
        update_option( 'sfco_resend_from_name',  sanitize_text_field( wp_unslash( $_POST['resend_from_name'] ?? '' ) ) );
        update_option( 'sfco_resend_from_email', sanitize_email( wp_unslash( $_POST['resend_from_email'] ?? '' ) ) );

        // ActiveCampaign CRM.
        update_option( 'sfco_pro_crm_api_url', untrailingslashit( esc_url_raw( wp_unslash( $_POST['crm_api_url'] ?? '' ) ) ) );
        $crm_key = sanitize_text_field( wp_unslash( $_POST['crm_api_key'] ?? '' ) );
        if ( '' !== $crm_key ) {
            update_option( 'sfco_pro_crm_api_key', $crm_key );
        }

        // Google Calendar OAuth credentials (refresh token is set by the
        // OAuth handshake, not this form).
        update_option( 'sfco_gcal_client_id',     sanitize_text_field( wp_unslash( $_POST['gcal_client_id'] ?? '' ) ) );
        $gcal_secret = sanitize_text_field( wp_unslash( $_POST['gcal_client_secret'] ?? '' ) );
        if ( '' !== $gcal_secret ) {
            update_option( 'sfco_gcal_client_secret', $gcal_secret );
        }

        // Calendly — URL is the only required field; widget rendering and
        // the post-submission booking link both read sfco_pro_calendly_url.
        $cal_url = esc_url_raw( wp_unslash( $_POST['calendly_url'] ?? '' ) );
        update_option( 'sfco_pro_calendly_url', $cal_url );
        update_option( 'sfco_pro_calendly_enabled', '' !== $cal_url ? 1 : 0 );

        // Branding (stored as a single array, matching class-pro-branding).
        $branding = array(
            'primary_color' => sanitize_hex_color( wp_unslash( $_POST['branding_primary_color'] ?? '' ) ),
            'logo_url'      => esc_url_raw( wp_unslash( $_POST['branding_logo_url'] ?? '' ) ),
            'thank_you'     => wp_kses_post( wp_unslash( $_POST['branding_thank_you'] ?? '' ) ),
        );
        update_option( 'sfco_pro_branding', $branding, false );

        // Tracking pixels (Ad platforms).
        $tracking = array(
            'google_ads_send_to'  => sanitize_text_field( wp_unslash( $_POST['google_ads_send_to'] ?? '' ) ),
            'google_ads_value'    => sanitize_text_field( wp_unslash( $_POST['google_ads_value'] ?? '' ) ),
            'google_ads_currency' => sanitize_text_field( wp_unslash( $_POST['google_ads_currency'] ?? 'USD' ) ),
            'facebook_pixel_id'   => sanitize_text_field( wp_unslash( $_POST['facebook_pixel_id'] ?? '' ) ),
            'facebook_event'      => sanitize_text_field( wp_unslash( $_POST['facebook_event'] ?? 'Lead' ) ),
            'tiktok_pixel_id'     => sanitize_text_field( wp_unslash( $_POST['tiktok_pixel_id'] ?? '' ) ),
            'tiktok_event'        => sanitize_text_field( wp_unslash( $_POST['tiktok_event'] ?? 'SubmitForm' ) ),
        );
        update_option( 'sfco_tracking', $tracking, false );

        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $notif    = class_exists( 'SFCO_Pro_Notifications' ) ? SFCO_Pro_Notifications::get_settings() : array();
        $branding = (array) get_option( 'sfco_pro_branding', array() );
        $tracking = (array) get_option( 'sfco_tracking', array() );

        $gcal_connected = (string) get_option( 'sfco_gcal_refresh_token', '' ) !== '';
        $gcal_class     = class_exists( 'SFCO_Pro_GCal' ) ? SFCO_Pro_GCal::get_instance() : null;
        $gcal_oauth_url = $gcal_class ? $gcal_class->get_oauth_url() : '';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart Forms Settings', 'smart-forms-for-midland' ); ?></h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-forms-for-midland' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'sfco_unified_save', '_sfco_unified_nonce' ); ?>
                <input type="hidden" name="sfco_unified_save" value="1">

                <h2><?php esc_html_e( 'Form Notifications', 'smart-forms-for-midland' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Placeholders: {name} {email} {phone} {position} {form_title} {site_name} {entry_url} {fields}', 'smart-forms-for-midland' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Admin notification', 'smart-forms-for-midland' ); ?></th>
                        <td><label><input type="checkbox" name="admin_enabled" value="1" <?php checked( ! empty( $notif['admin_enabled'] ) ); ?>> <?php esc_html_e( 'Email a team member on every form submission', 'smart-forms-for-midland' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="admin_to"><?php esc_html_e( 'Send to', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="admin_to" name="admin_to" class="regular-text" value="<?php echo esc_attr( $notif['admin_to'] ?? '' ); ?>"><p class="description"><?php esc_html_e( 'Comma- or space-separated emails.', 'smart-forms-for-midland' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="admin_subject"><?php esc_html_e( 'Admin subject', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="admin_subject" name="admin_subject" class="large-text" value="<?php echo esc_attr( $notif['admin_subject'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="admin_body"><?php esc_html_e( 'Admin body', 'smart-forms-for-midland' ); ?></label></th>
                        <td><textarea id="admin_body" name="admin_body" rows="6" class="large-text"><?php echo esc_textarea( $notif['admin_body'] ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Auto-reply', 'smart-forms-for-midland' ); ?></th>
                        <td><label><input type="checkbox" name="autoreply_enabled" value="1" <?php checked( ! empty( $notif['autoreply_enabled'] ) ); ?>> <?php esc_html_e( 'Email the submitter back to confirm receipt', 'smart-forms-for-midland' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="autoreply_from_name"><?php esc_html_e( 'Auto-reply From name', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="autoreply_from_name" name="autoreply_from_name" class="regular-text" value="<?php echo esc_attr( $notif['autoreply_from_name'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="autoreply_from_email"><?php esc_html_e( 'Auto-reply From email', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="email" id="autoreply_from_email" name="autoreply_from_email" class="regular-text" value="<?php echo esc_attr( $notif['autoreply_from_email'] ?? '' ); ?>"><p class="description"><?php esc_html_e( 'Must be on a domain authenticated in Resend.', 'smart-forms-for-midland' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="autoreply_subject"><?php esc_html_e( 'Auto-reply subject', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="autoreply_subject" name="autoreply_subject" class="large-text" value="<?php echo esc_attr( $notif['autoreply_subject'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="autoreply_body"><?php esc_html_e( 'Auto-reply body', 'smart-forms-for-midland' ); ?></label></th>
                        <td><textarea id="autoreply_body" name="autoreply_body" rows="8" class="large-text"><?php echo esc_textarea( $notif['autoreply_body'] ?? '' ); ?></textarea></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Resend Email (SMTP)', 'smart-forms-for-midland' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable', 'smart-forms-for-midland' ); ?></th>
                        <td><label><input type="checkbox" name="resend_enabled" value="1" <?php checked( (int) get_option( 'sfco_resend_enabled' ), 1 ); ?>> <?php esc_html_e( 'Route all WordPress emails through Resend', 'smart-forms-for-midland' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="resend_api_key"><?php esc_html_e( 'API key', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="password" id="resend_api_key" name="resend_api_key" class="regular-text" value="<?php echo esc_attr( get_option( 'sfco_resend_api_key', '' ) ); ?>" autocomplete="off"><p class="description"><?php esc_html_e( 'resend.com → API Keys.', 'smart-forms-for-midland' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="resend_from_name"><?php esc_html_e( 'From name', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="resend_from_name" name="resend_from_name" class="regular-text" value="<?php echo esc_attr( get_option( 'sfco_resend_from_name', get_bloginfo( 'name' ) ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="resend_from_email"><?php esc_html_e( 'From email', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="email" id="resend_from_email" name="resend_from_email" class="regular-text" value="<?php echo esc_attr( get_option( 'sfco_resend_from_email', get_option( 'admin_email' ) ) ); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'ActiveCampaign CRM', 'smart-forms-for-midland' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="crm_api_url"><?php esc_html_e( 'API URL', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="url" id="crm_api_url" name="crm_api_url" class="regular-text" value="<?php echo esc_attr( get_option( 'sfco_pro_crm_api_url', '' ) ); ?>" placeholder="https://midland.api-us1.com"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crm_api_key"><?php esc_html_e( 'API key', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="password" id="crm_api_key" name="crm_api_key" class="regular-text" value="<?php echo esc_attr( get_option( 'sfco_pro_crm_api_key', '' ) ); ?>" autocomplete="off"><p class="description"><?php esc_html_e( 'AC Settings → Developer.', 'smart-forms-for-midland' ); ?></p></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Google Calendar', 'smart-forms-for-midland' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Status', 'smart-forms-for-midland' ); ?></th>
                        <td><?php if ( $gcal_connected ) : ?>
                            <strong style="color:#2F8137;"><?php esc_html_e( 'Connected', 'smart-forms-for-midland' ); ?></strong>
                            &nbsp;<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'sfco-gcal' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Manage / disconnect', 'smart-forms-for-midland' ); ?></a>
                        <?php elseif ( $gcal_oauth_url ) : ?>
                            <a href="<?php echo esc_url( $gcal_oauth_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Connect with Google', 'smart-forms-for-midland' ); ?></a>
                            <p class="description"><?php esc_html_e( 'Paste Client ID + Secret below and Save, then click Connect.', 'smart-forms-for-midland' ); ?></p>
                        <?php else : ?>
                            <em><?php esc_html_e( 'Add Client ID + Secret below and Save first.', 'smart-forms-for-midland' ); ?></em>
                        <?php endif; ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcal_client_id"><?php esc_html_e( 'OAuth Client ID', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="gcal_client_id" name="gcal_client_id" class="regular-text" value="<?php echo esc_attr( get_option( 'sfco_gcal_client_id', '' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcal_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="password" id="gcal_client_secret" name="gcal_client_secret" class="regular-text" value="<?php echo esc_attr( get_option( 'sfco_gcal_client_secret', '' ) ); ?>" autocomplete="off"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Calendly', 'smart-forms-for-midland' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="calendly_url"><?php esc_html_e( 'Calendly URL', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="url" id="calendly_url" name="calendly_url" class="regular-text" value="<?php echo esc_attr( get_option( 'sfco_pro_calendly_url', '' ) ); ?>" placeholder="https://calendly.com/midlandfloors/30min"><p class="description"><?php esc_html_e( 'Paste your public Calendly scheduling URL. After a form submits, the submitter is offered this booking link.', 'smart-forms-for-midland' ); ?></p></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Branding', 'smart-forms-for-midland' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="branding_primary_color"><?php esc_html_e( 'Primary color', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="branding_primary_color" name="branding_primary_color" value="<?php echo esc_attr( $branding['primary_color'] ?? '#2F8137' ); ?>" placeholder="#2F8137"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="branding_logo_url"><?php esc_html_e( 'Logo URL', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="url" id="branding_logo_url" name="branding_logo_url" class="regular-text" value="<?php echo esc_attr( $branding['logo_url'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="branding_thank_you"><?php esc_html_e( 'Thank-you message', 'smart-forms-for-midland' ); ?></label></th>
                        <td><textarea id="branding_thank_you" name="branding_thank_you" rows="3" class="large-text"><?php echo esc_textarea( $branding['thank_you'] ?? '' ); ?></textarea></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Tracking (Ad Pixels)', 'smart-forms-for-midland' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="google_ads_send_to"><?php esc_html_e( 'Google Ads conversion send-to', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="google_ads_send_to" name="google_ads_send_to" class="regular-text" value="<?php echo esc_attr( $tracking['google_ads_send_to'] ?? '' ); ?>" placeholder="AW-1234567/abcDEFgh"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_ads_value"><?php esc_html_e( 'Conversion value', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="google_ads_value" name="google_ads_value" value="<?php echo esc_attr( $tracking['google_ads_value'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_ads_currency"><?php esc_html_e( 'Conversion currency', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="google_ads_currency" name="google_ads_currency" value="<?php echo esc_attr( $tracking['google_ads_currency'] ?? 'USD' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="facebook_pixel_id"><?php esc_html_e( 'Meta Pixel ID', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="facebook_pixel_id" name="facebook_pixel_id" value="<?php echo esc_attr( $tracking['facebook_pixel_id'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tiktok_pixel_id"><?php esc_html_e( 'TikTok Pixel ID', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="tiktok_pixel_id" name="tiktok_pixel_id" value="<?php echo esc_attr( $tracking['tiktok_pixel_id'] ?? '' ); ?>"></td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'smart-forms-for-midland' ); ?></button></p>
            </form>
        </div>
        <?php
    }
}

new SFCO_Pro_Settings();
