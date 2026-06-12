<?php
/**
 * Form notifications — the two automations every form should ship with.
 *
 *   1. Auto-reply to the submitter. The classic "Thanks, we got your
 *      message" email that confirms the form went through. Fires
 *      immediately on submit.
 *
 *   2. Admin notification. An email to one or more internal recipients
 *      with the lead's details so the operator does not have to log
 *      into WP to see new submissions.
 *
 * Both are intentionally kept simple. Trigger-action rules (tag the
 * lead if a field equals X, fire a webhook, send drip sequences, sync
 * to ActiveCampaign) live in Smart CRM Pro, which is the right plugin
 * for that kind of work. Smart Forms only handles the response-to-the-
 * submission flow.
 *
 * Placeholders accepted in subject / body:
 *   {name}        Customer's name from the form.
 *   {email}       Customer's email.
 *   {phone}       Customer's phone.
 *   {position}    "Position applying for" (job applications).
 *   {form_title}  Title of the form the lead submitted.
 *   {site_name}   This site's name.
 *   {entry_url}   Direct link to view the lead in WP admin.
 *   {fields}      A compiled list of every submitted field, one per line.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Notifications {

    const OPTION = 'sfco_pro_notifications';
    const PAGE   = 'sfco-notifications';

    public function __construct() {
        add_action( 'admin_menu',         array( $this, 'add_menu' ), 30 );
        add_action( 'admin_init',         array( $this, 'handle_save' ) );
        // Fire on every successful lead submission. Priority 30 so CRM
        // (priority 20) and any other lead listeners finish first.
        add_action( 'sfco_lead_submitted', array( $this, 'on_lead_submitted' ), 30, 3 );
    }

    public function add_menu() {
        // Visible under the Smart Forms menu so the operator can turn on and
        // edit the instant email reply that goes to the person who submitted.
        add_submenu_page(
            'smart-forms',
            __( 'Email Notifications', 'smart-forms-for-midland' ),
            __( 'Email Notifications', 'smart-forms-for-midland' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render_page' )
        );
    }

    /**
     * Default values for a fresh install. Auto-reply is OFF by default so
     * we don't surprise a site that already sends its own confirmation;
     * admin notification is ON by default to the site admin email so a
     * brand-new install at least pings somebody when a lead comes in.
     */
    public static function defaults(): array {
        return array(
            'autoreply_enabled'   => 0,
            'autoreply_subject'   => 'We received your message, Midland Floor Care',
            'autoreply_body'      => "Hi {name},\n\nThanks for reaching out to Midland Floor Care. We received your message and will respond within one business day.\n\n{fields}\n\nIf it is urgent, call or text us at (240) 532-9097.",
            'autoreply_from_name' => 'Midland Floor Care',
            'autoreply_from_email' => get_option( 'admin_email' ),

            'admin_enabled'   => 1,
            'admin_to'        => get_option( 'admin_email' ),
            'admin_subject'   => 'New lead from {form_title} — {name}',
            'admin_body'      => "New submission on {form_title}:\n\nName: {name}\nEmail: {email}\nPhone: {phone}\n\nAll fields:\n{fields}\n\nView in admin: {entry_url}",
        );
    }

    public static function get_settings(): array {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return wp_parse_args( $stored, self::defaults() );
    }

    public function handle_save() {
        if ( ! isset( $_POST['sfco_save_notifications'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! check_admin_referer( 'sfco_save_notifications', '_sfco_notif_nonce' ) ) {
            return;
        }

        $clean = array(
            'autoreply_enabled'    => isset( $_POST['autoreply_enabled'] ) ? 1 : 0,
            'autoreply_subject'    => sanitize_text_field( wp_unslash( $_POST['autoreply_subject'] ?? '' ) ),
            'autoreply_body'       => wp_kses_post( wp_unslash( $_POST['autoreply_body'] ?? '' ) ),
            'autoreply_from_name'  => sanitize_text_field( wp_unslash( $_POST['autoreply_from_name'] ?? '' ) ),
            'autoreply_from_email' => sanitize_email( wp_unslash( $_POST['autoreply_from_email'] ?? '' ) ),

            'admin_enabled'  => isset( $_POST['admin_enabled'] ) ? 1 : 0,
            'admin_to'       => sanitize_text_field( wp_unslash( $_POST['admin_to'] ?? '' ) ), // can be comma-separated
            'admin_subject'  => sanitize_text_field( wp_unslash( $_POST['admin_subject'] ?? '' ) ),
            'admin_body'     => wp_kses_post( wp_unslash( $_POST['admin_body'] ?? '' ) ),
        );

        update_option( self::OPTION, $clean, false );

        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * The actual automation: on every form submission, fire auto-reply
     * and admin notification if enabled.
     */
    public function on_lead_submitted( $lead_id, $row, $form ) {
        $settings = self::get_settings();
        $vars     = $this->build_placeholders( $lead_id, $row, $form );

        if ( $settings['admin_enabled'] && ! empty( $settings['admin_to'] ) ) {
            $to      = $this->parse_recipients( $settings['admin_to'] );
            $subject = $this->replace( $settings['admin_subject'], $vars );
            $body    = $this->replace( $settings['admin_body'], $vars );
            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            if ( ! empty( $vars['{email}'] ) ) {
                $headers[] = 'Reply-To: ' . $vars['{email}'];
            }
            wp_mail( $to, $subject, $this->wrap_html( $body ), $headers );
        }

        if ( $settings['autoreply_enabled'] && ! empty( $vars['{email}'] ) ) {
            $subject = $this->replace( $settings['autoreply_subject'], $vars );
            $body    = $this->replace( $settings['autoreply_body'], $vars );
            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            if ( ! empty( $settings['autoreply_from_name'] ) && ! empty( $settings['autoreply_from_email'] ) ) {
                $headers[] = sprintf( 'From: %s <%s>', $settings['autoreply_from_name'], $settings['autoreply_from_email'] );
            }
            wp_mail( $vars['{email}'], $subject, $this->wrap_html( $body ), $headers );
        }
    }

    /**
     * Wrap a plain-text body in the Midland-branded HTML email shell: green
     * header with the logo, white card for the content, footer with contact.
     * Inline styles only, so every mail client renders it.
     *
     * @param string $body Plain text (placeholders already replaced).
     * @return string HTML email.
     */
    private function wrap_html( string $body ): string {
        // Logo: the chat widget logo, falling back to the theme custom logo.
        $logo = (string) get_option( 'smart_chat_chat_logo', '' );
        if ( '' === $logo ) {
            $logo_id = (int) get_theme_mod( 'custom_logo' );
            if ( $logo_id ) {
                $logo = (string) wp_get_attachment_image_url( $logo_id, 'medium' );
            }
        }

        $header_inner = '' !== $logo
            ? '<img src="' . esc_url( $logo ) . '" alt="Midland Floor Care" style="max-height:48px;width:auto;display:inline-block;">'
            : '<span style="color:#FFFFFF;font-size:22px;font-weight:800;letter-spacing:1px;">Midland Floor Care</span>';

        $content = nl2br( esc_html( $body ) );

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#F3FCF4;">'
            . '<div style="max-width:600px;margin:0 auto;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="background:#0E2F14;text-align:center;padding:22px 24px;">' . $header_inner . '</div>'
            . '<div style="background:#FFFFFF;padding:28px 28px 24px;color:#0F1411;font-size:15px;line-height:1.7;">' . $content . '</div>'
            . '<div style="background:#0E2F14;text-align:center;padding:16px 24px;color:#B7E5BD;font-size:13px;line-height:1.8;">'
            . 'Midland Floor Care &nbsp;|&nbsp; <a href="tel:2405329097" style="color:#FFFFFF;text-decoration:none;font-weight:700;">(240) 532-9097</a> &nbsp;|&nbsp; '
            . '<a href="https://midlandfloors.com" style="color:#FFFFFF;text-decoration:none;font-weight:700;">midlandfloors.com</a>'
            . '<br>Washington DC, Maryland and Northern Virginia'
            . '</div></div></body></html>';
    }

    private function build_placeholders( $lead_id, $row, $form ): array {
        $row = is_array( $row ) ? $row : (array) $row;

        // Compiled "all fields" block — every non-empty column from the
        // lead row except internal columns. Useful for the admin body so
        // they see everything the lead typed without us hard-coding.
        $skip = array(
            'id', 'form_id', 'created_at', 'updated_at', 'status', 'ip', 'user_agent',
            // Internal/bridge columns: never show these to anyone in an email,
            // least of all the customer (session ids, source slugs, raw JSON).
            'lead_source', 'session_id', 'extra_fields_json', 'sfco_lead_id',
            'source', 'intent', 'utm_source', 'utm_medium', 'utm_campaign', 'gclid',
        );
        $compiled = array();
        $seen_val = array();
        foreach ( $row as $k => $v ) {
            if ( in_array( $k, $skip, true ) || '' === (string) $v ) {
                continue;
            }
            // Raw JSON blobs are machine data, not email content.
            if ( '_json' === substr( $k, -5 ) ) {
                continue;
            }
            $v = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
            // Scrub the internal session marker the chat bridge appends.
            $v = trim( preg_replace( '/\s*\[sid:[^\]]*\]/i', '', $v ) );
            if ( '' === $v ) {
                continue;
            }
            // Don't repeat the same text under two labels (e.g. project type
            // duplicated into the notes by the chat bridge).
            if ( in_array( $v, $seen_val, true ) ) {
                continue;
            }
            $seen_val[]  = $v;
            $label       = ucwords( str_replace( '_', ' ', preg_replace( '/^customer_/', '', $k ) ) );
            $compiled[]  = $label . ': ' . $v;
        }

        $entry_url = admin_url( 'admin.php?page=smart-forms-form-entries&form_id=' . absint( $row['form_id'] ?? 0 ) . '&lead_id=' . absint( $lead_id ) );

        return array(
            '{name}'       => (string) ( $row['customer_name'] ?? '' ),
            '{email}'      => (string) ( $row['customer_email'] ?? '' ),
            '{phone}'      => (string) ( $row['customer_phone'] ?? '' ),
            '{position}'   => (string) ( $row['project_type'] ?? '' ),
            '{form_title}' => is_object( $form ) ? (string) ( $form->title ?? '' ) : ( is_array( $form ) ? (string) ( $form['title'] ?? '' ) : '' ),
            '{site_name}'  => get_bloginfo( 'name' ),
            '{entry_url}'  => $entry_url,
            '{fields}'     => implode( "\n", $compiled ),
        );
    }

    private function replace( string $template, array $vars ): string {
        return strtr( $template, $vars );
    }

    private function parse_recipients( string $raw ): array {
        $out = array();
        foreach ( preg_split( '/[,;\s]+/', $raw ) as $candidate ) {
            $candidate = sanitize_email( $candidate );
            if ( is_email( $candidate ) ) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = self::get_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Form Notifications', 'smart-forms-for-midland' ); ?></h1>
            <p style="max-width:720px;color:#4B5563;font-size:15px;line-height:1.5;">
                <?php esc_html_e( 'When a form is submitted, fire two emails: an auto-reply confirming receipt to the submitter, and an admin notification to your team. This is the "regular automation" path. Anything more (tagging, segmenting, sequencing, ActiveCampaign sync) lives in Smart CRM.', 'smart-forms-for-midland' ); ?>
            </p>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Notifications saved.', 'smart-forms-for-midland' ); ?></p></div>
            <?php endif; ?>

            <form method="post" style="max-width:780px;">
                <?php wp_nonce_field( 'sfco_save_notifications', '_sfco_notif_nonce' ); ?>

                <h2 style="margin-top:28px;color:#0F1411;border-bottom:1px solid #d6e6dc;padding-bottom:6px;"><?php esc_html_e( 'Admin notification', 'smart-forms-for-midland' ); ?></h2>
                <p style="color:#6b8278;font-size:13px;margin:0 0 14px;"><?php esc_html_e( 'Sends to your team the moment a lead comes in. Reply-To is set to the submitter so you can hit reply.', 'smart-forms-for-midland' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable', 'smart-forms-for-midland' ); ?></th>
                        <td><label><input type="checkbox" name="admin_enabled" value="1" <?php checked( $s['admin_enabled'], 1 ); ?>> <?php esc_html_e( 'Email an admin on every form submission', 'smart-forms-for-midland' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="admin_to"><?php esc_html_e( 'Send to', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="admin_to" name="admin_to" class="regular-text" value="<?php echo esc_attr( $s['admin_to'] ); ?>" placeholder="support@midlandfloors.com">
                            <p class="description"><?php esc_html_e( 'One or more email addresses, comma or space separated.', 'smart-forms-for-midland' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="admin_subject"><?php esc_html_e( 'Subject', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="admin_subject" name="admin_subject" class="large-text" value="<?php echo esc_attr( $s['admin_subject'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="admin_body"><?php esc_html_e( 'Body', 'smart-forms-for-midland' ); ?></label></th>
                        <td><textarea id="admin_body" name="admin_body" rows="8" class="large-text"><?php echo esc_textarea( $s['admin_body'] ); ?></textarea></td>
                    </tr>
                </table>

                <h2 style="margin-top:36px;color:#0F1411;border-bottom:1px solid #d6e6dc;padding-bottom:6px;"><?php esc_html_e( 'Auto-reply to submitter', 'smart-forms-for-midland' ); ?></h2>
                <p style="color:#6b8278;font-size:13px;margin:0 0 14px;"><?php esc_html_e( 'Confirms the submission went through. Use a real From address so it does not land in spam.', 'smart-forms-for-midland' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable', 'smart-forms-for-midland' ); ?></th>
                        <td><label><input type="checkbox" name="autoreply_enabled" value="1" <?php checked( $s['autoreply_enabled'], 1 ); ?>> <?php esc_html_e( 'Send an auto-reply to the submitter on every form submission', 'smart-forms-for-midland' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="autoreply_from_name"><?php esc_html_e( 'From name', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="autoreply_from_name" name="autoreply_from_name" class="regular-text" value="<?php echo esc_attr( $s['autoreply_from_name'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="autoreply_from_email"><?php esc_html_e( 'From email', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="email" id="autoreply_from_email" name="autoreply_from_email" class="regular-text" value="<?php echo esc_attr( $s['autoreply_from_email'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Must be an address on a domain you have authenticated in Resend (SPF/DKIM). Otherwise Resend rejects the send.', 'smart-forms-for-midland' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="autoreply_subject"><?php esc_html_e( 'Subject', 'smart-forms-for-midland' ); ?></label></th>
                        <td><input type="text" id="autoreply_subject" name="autoreply_subject" class="large-text" value="<?php echo esc_attr( $s['autoreply_subject'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="autoreply_body"><?php esc_html_e( 'Body', 'smart-forms-for-midland' ); ?></label></th>
                        <td><textarea id="autoreply_body" name="autoreply_body" rows="10" class="large-text"><?php echo esc_textarea( $s['autoreply_body'] ); ?></textarea></td>
                    </tr>
                </table>

                <h3 style="margin-top:32px;color:#0F1411;"><?php esc_html_e( 'Placeholders', 'smart-forms-for-midland' ); ?></h3>
                <p style="color:#4B5563;font-size:14px;line-height:1.6;">
                    <?php esc_html_e( 'Drop any of these into the subject or body — they will be replaced when the email sends:', 'smart-forms-for-midland' ); ?><br>
                    <code>{name}</code> <code>{email}</code> <code>{phone}</code> <code>{position}</code> <code>{form_title}</code> <code>{site_name}</code> <code>{entry_url}</code> <code>{fields}</code>
                </p>

                <p class="submit"><button type="submit" name="sfco_save_notifications" class="button button-primary" style="background:#43A94B;border-color:#43A94B;font-weight:700;"><?php esc_html_e( 'Save Notifications', 'smart-forms-for-midland' ); ?></button></p>
            </form>
        </div>
        <?php
    }
}

new SFCO_Pro_Notifications();
