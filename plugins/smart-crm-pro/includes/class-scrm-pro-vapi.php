<?php
/**
 * Vapi outbound AI call.
 *
 * Fires when a residential lead picks "Request a call" on the unified
 * quote form (lead_intent === 'request_call'). Vapi places an immediate
 * outbound call to confirm receipt and gather context before a human
 * follows up. Residential "Request a visit" leads route through the
 * visit-draft / SM8 JobActivity path instead and do NOT get a robocall.
 *
 * Commercial leads never trigger Vapi — the form doesn't even expose
 * the request_call option to them, so the intent gate naturally
 * excludes commercial regardless of the segment-trigger setting.
 *
 * Settings live at Smart CRM -> Vapi:
 *   - API key (Bearer token)
 *   - Assistant ID (which agent to run)
 *   - Outbound phone-number ID (Vapi provides one, or BYO Twilio number)
 *   - Enable / disable toggle
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Vapi {

    const OPT_ENABLED       = 'scrm_pro_vapi_enabled';
    const OPT_API_KEY       = 'scrm_pro_vapi_api_key';
    const OPT_ASSISTANT_ID  = 'scrm_pro_vapi_assistant_id';
    const OPT_FROM_NUMBER   = 'scrm_pro_vapi_from_number_id';
    const OPT_TRIGGER       = 'scrm_pro_vapi_trigger';   // 'residential', 'both', 'none'

    const API_BASE = 'https://api.vapi.ai/call';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',          array( $this, 'add_menu' ), 45 );
        add_action( 'admin_init',          array( $this, 'handle_save' ) );
        // Priority 60 so it runs after the bridge tags AC and after
        // the floor-care-plan / tentative-gcal triggers have stamped
        // the lead with extra fields.
        add_action( 'sfco_lead_submitted', array( $this, 'maybe_call' ), 60, 3 );
    }

    public function add_menu() {
        add_submenu_page(
            null,
            __( 'Vapi (AI Call)', 'smart-crm-pro' ),
            __( 'Vapi (AI Call)', 'smart-crm-pro' ),
            'manage_options',
            'scrm-vapi',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['scrm_save_vapi'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scrm_vapi_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_vapi_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scrm_save_vapi' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-crm-pro' ) );
        }

        update_option( self::OPT_ENABLED,      isset( $_POST['vapi_enabled'] ) ? 1 : 0 );
        update_option( self::OPT_API_KEY,      sanitize_text_field( wp_unslash( $_POST['vapi_api_key'] ?? '' ) ) );
        update_option( self::OPT_ASSISTANT_ID, sanitize_text_field( wp_unslash( $_POST['vapi_assistant_id'] ?? '' ) ) );
        update_option( self::OPT_FROM_NUMBER,  sanitize_text_field( wp_unslash( $_POST['vapi_from_number_id'] ?? '' ) ) );
        $trigger = sanitize_key( $_POST['vapi_trigger'] ?? 'residential' );
        update_option( self::OPT_TRIGGER, in_array( $trigger, array( 'residential', 'both', 'none' ), true ) ? $trigger : 'residential' );

        wp_safe_redirect( admin_url( 'admin.php?page=smart-crm&tab=vapi&saved=1' ) );
        exit;
    }

    /**
     * Sanity-check the lead + settings, then POST to Vapi.
     */
    public function maybe_call( $lead_id, $row, $form ) {
        if ( ! get_option( self::OPT_ENABLED ) ) {
            return;
        }
        if ( ! is_array( $row ) ) {
            return;
        }

        // Gate by lead segment per the operator's trigger setting.
        $trigger = (string) get_option( self::OPT_TRIGGER, 'residential' );
        if ( 'none' === $trigger ) {
            return;
        }
        $segment = ( strtolower( (string) ( $row['property_type'] ?? '' ) ) === 'commercial' ) ? 'commercial' : 'residential';
        if ( 'residential' === $trigger && 'residential' !== $segment ) {
            return;
        }

        // Intent gate — Vapi only fires for "Request a call" submissions.
        // "Request a visit" leads route through visit-draft + SM8 JobActivity
        // and explicitly do NOT get a robocall. Emergencies / researching /
        // future-project intents also skip — they need a human, not an AI.
        $intent = strtolower( (string) ( $row['lead_intent'] ?? '' ) );
        if ( 'request_call' !== $intent ) {
            return;
        }

        $api_key       = (string) get_option( self::OPT_API_KEY );
        $assistant_id  = (string) get_option( self::OPT_ASSISTANT_ID );
        $from_number   = (string) get_option( self::OPT_FROM_NUMBER );
        if ( '' === $api_key || '' === $assistant_id || '' === $from_number ) {
            return;
        }

        // Vapi expects E.164. Strip everything but digits, default to +1
        // since Midland only serves the DMV (US-only via the Cloudflare
        // geo-block already in place).
        $raw   = preg_replace( '/\D/', '', (string) ( $row['customer_phone'] ?? '' ) );
        if ( 10 === strlen( $raw ) ) {
            $raw = '1' . $raw;
        }
        if ( strlen( $raw ) < 11 ) {
            return;
        }
        $phone_e164 = '+' . $raw;

        $first_name = trim( explode( ' ', (string) ( $row['customer_name'] ?? '' ) )[0] );
        $payload = array(
            'assistantId'   => $assistant_id,
            'phoneNumberId' => $from_number,
            'customer'      => array(
                'number' => $phone_e164,
                'name'   => (string) ( $row['customer_name'] ?? '' ),
            ),
            // Variables the operator can reference inside the Vapi
            // assistant's prompt: {{name}}, {{property_type}}, etc.
            'assistantOverrides' => array(
                'variableValues' => array(
                    'name'             => $first_name,
                    'full_name'        => (string) ( $row['customer_name'] ?? '' ),
                    'segment'          => $segment,
                    'property_subtype' => (string) ( $row['property_subtype'] ?? '' ),
                    'service'          => (string) ( $row['project_type'] ?? '' ),
                    'lead_intent'      => (string) ( $row['lead_intent'] ?? '' ),
                    'site_name'        => get_bloginfo( 'name' ),
                ),
            ),
        );

        $response = wp_remote_post(
            self::API_BASE,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( $payload ),
            )
        );

        if ( class_exists( 'SFCO_Pro_Log' ) ) {
            if ( is_wp_error( $response ) ) {
                SFCO_Pro_Log::record( 'vapi', 'error', 'Transport: ' . $response->get_error_message(), (int) ( $form->id ?? 0 ), (int) $lead_id, $payload );
            } else {
                $code  = (int) wp_remote_retrieve_response_code( $response );
                $body  = wp_remote_retrieve_body( $response );
                $json  = json_decode( $body, true );
                $ok    = ( $code >= 200 && $code < 300 );
                SFCO_Pro_Log::record( 'vapi', $ok ? 'ok' : 'error', sprintf( 'POST %s → HTTP %d', self::API_BASE, $code ), (int) ( $form->id ?? 0 ), (int) $lead_id, $payload, $json ?: $body );
            }
        }
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $enabled      = (int) get_option( self::OPT_ENABLED, 0 );
        $api_key      = (string) get_option( self::OPT_API_KEY );
        $assistant_id = (string) get_option( self::OPT_ASSISTANT_ID );
        $from_number  = (string) get_option( self::OPT_FROM_NUMBER );
        $trigger      = (string) get_option( self::OPT_TRIGGER, 'residential' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Vapi — Outbound AI Call', 'smart-crm-pro' ); ?></h1>
            <p class="description" style="max-width:720px;">
                <?php esc_html_e( 'When a new lead submits the form, Vapi makes an immediate outbound call from your Midland number, plays a short message confirming receipt, and tells them a real person will follow up. Replaces the "did the form actually go through?" anxiety with a phone call before they leave the page.', 'smart-crm-pro' ); ?>
            </p>

            <?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-crm-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'scrm_save_vapi', '_scrm_vapi_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable', 'smart-crm-pro' ); ?></th>
                        <td><label><input type="checkbox" name="vapi_enabled" value="1" <?php checked( $enabled, 1 ); ?>> <?php esc_html_e( 'Fire an outbound Vapi call on new residential leads that requested a call back', 'smart-crm-pro' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Calls fire only for residential leads whose intent is "request a call", and require an Assistant ID and a Phone Number ID below.', 'smart-crm-pro' ); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="vapi_api_key"><?php esc_html_e( 'Vapi private API key', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="password" id="vapi_api_key" name="vapi_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'Vapi dashboard → Settings → API Keys → copy the PRIVATE key (not the public one). The public key is for browser SDKs only; server-side calls like this one are rejected with HTTP 401.', 'smart-crm-pro' ); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="vapi_assistant_id"><?php esc_html_e( 'Assistant ID', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="text" id="vapi_assistant_id" name="vapi_assistant_id" class="regular-text" value="<?php echo esc_attr( $assistant_id ); ?>">
                            <p class="description"><?php esc_html_e( 'The ID of the Vapi assistant that handles the call. Configure the prompt + voice in Vapi.', 'smart-crm-pro' ); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="vapi_from_number_id"><?php esc_html_e( 'From phone number ID', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="text" id="vapi_from_number_id" name="vapi_from_number_id" class="regular-text" value="<?php echo esc_attr( $from_number ); ?>">
                            <p class="description"><?php esc_html_e( 'Vapi → Phone Numbers → copy the number\'s ID (not the digits).', 'smart-crm-pro' ); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Trigger for', 'smart-crm-pro' ); ?></th>
                        <td>
                            <label><input type="radio" name="vapi_trigger" value="residential" <?php checked( $trigger, 'residential' ); ?>> <?php esc_html_e( 'Residential leads only', 'smart-crm-pro' ); ?></label><br>
                            <label><input type="radio" name="vapi_trigger" value="both" <?php checked( $trigger, 'both' ); ?>> <?php esc_html_e( 'Both residential and commercial', 'smart-crm-pro' ); ?></label><br>
                            <label><input type="radio" name="vapi_trigger" value="none" <?php checked( $trigger, 'none' ); ?>> <?php esc_html_e( 'Disabled', 'smart-crm-pro' ); ?></label>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="scrm_save_vapi" class="button button-primary" style="background:#43A94B;border-color:#43A94B;"><?php esc_html_e( 'Save Vapi Settings', 'smart-crm-pro' ); ?></button></p>
            </form>

            <h2><?php esc_html_e( 'Suggested assistant prompt', 'smart-crm-pro' ); ?></h2>
            <pre style="background:#0F1411;color:#7CCE8E;padding:14px;border-radius:6px;white-space:pre-wrap;">You are Midland Floors' confirmation agent. Keep it short — under 20 seconds.

"Hi {{name}}, this is Midland Floors. We just received your visit request for your {{property_subtype}} — a real team member will call you back within one business day to schedule. If it's urgent, please call us directly at (240) 532-9097. Thanks!"

End the call after you finish. Do not collect information. Do not answer questions.</pre>
        </div>
        <?php
    }
}

SCRM_Pro_Vapi::get_instance();
