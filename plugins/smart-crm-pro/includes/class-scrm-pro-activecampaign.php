<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Smart CRM Pro → ActiveCampaign bridge.
 *
 * When a lead's status changes to "completed" (typically from the ServiceM8
 * webhook) we sync the contact to ActiveCampaign with a tag like
 * "midland-job-completed" so AC's own automation flows can fire (welcome,
 * upsell, reactivation series, etc.).
 *
 * Settings: Smart CRM Pro > ActiveCampaign
 */
class SCRM_Pro_ActiveCampaign {

    const OPT_API_URL    = 'scrm_pro_ac_api_url';
    const OPT_API_KEY    = 'scrm_pro_ac_api_key';
    const OPT_TAG        = 'scrm_pro_ac_tag';
    const OPT_ENABLED    = 'scrm_pro_ac_enabled';
    const OPT_LAST_PUSH  = 'scrm_pro_ac_last_push';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',                array( $this, 'add_menu' ), 22 );
        add_action( 'admin_init',                array( $this, 'handle_save' ) );
        add_action( 'admin_init',                array( $this, 'handle_test' ) );

        // React to status changes from ServiceM8 / smart-forms-pro / direct.
        add_action( 'sfco_lead_status_changed',  array( $this, 'on_status_changed' ), 10, 3 );
        add_action( 'sfco_lead_completed',       array( $this, 'on_lead_completed' ) );
        add_action( 'scrm_pro_job_completed',    array( $this, 'on_lead_completed' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'smart-crm-pro',
            esc_html__( 'ActiveCampaign', 'smart-crm-pro' ),
            esc_html__( 'ActiveCampaign', 'smart-crm-pro' ),
            'manage_options',
            'scrm-pro-activecampaign',
            array( $this, 'render_page' )
        );
    }

    public function on_status_changed( $lead, $old_status, $new_status ) {
        if ( 'completed' !== strtolower( (string) $new_status ) ) {
            return;
        }
        $this->push_lead( $lead );
    }

    public function on_lead_completed( $lead ) {
        $this->push_lead( $lead );
    }

    private function push_lead( $lead ) {
        if ( ! get_option( self::OPT_ENABLED, 0 ) ) {
            return;
        }
        $api_url = (string) get_option( self::OPT_API_URL, '' );
        $api_key = (string) get_option( self::OPT_API_KEY, '' );
        if ( '' === $api_url || '' === $api_key ) {
            return;
        }

        $email = sanitize_email( $this->get_field( $lead, array( 'customer_email', 'email' ) ) );
        if ( ! is_email( $email ) ) {
            return;
        }
        $name  = (string) $this->get_field( $lead, array( 'customer_name', 'name' ) );
        $phone = (string) $this->get_field( $lead, array( 'customer_phone', 'phone' ) );

        $name_parts = explode( ' ', trim( $name ), 2 );

        $payload = array(
            'contact' => array(
                'email'     => $email,
                'firstName' => $name_parts[0] ?? '',
                'lastName'  => $name_parts[1] ?? '',
                'phone'     => $phone,
            ),
        );

        $response = wp_remote_post( untrailingslashit( $api_url ) . '/api/3/contact/sync', array(
            'headers' => array(
                'Api-Token'    => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ) );

        $contact_id = null;
        if ( ! is_wp_error( $response ) ) {
            $body       = json_decode( wp_remote_retrieve_body( $response ), true );
            $contact_id = isset( $body['contact']['id'] ) ? (int) $body['contact']['id'] : null;
        }

        // Apply the tag so AC flows can trigger off it.
        $tag_name = (string) get_option( self::OPT_TAG, 'midland-job-completed' );
        if ( $contact_id && $tag_name ) {
            $this->apply_tag( $api_url, $api_key, $contact_id, $tag_name );
        }

        update_option( self::OPT_LAST_PUSH, array(
            'at'    => time(),
            'email' => $email,
            'tag'   => $tag_name,
            'ok'    => $contact_id ? 1 : 0,
        ) );
    }

    private function apply_tag( $api_url, $api_key, $contact_id, $tag_name ) {
        $api_url = untrailingslashit( $api_url );
        $headers = array(
            'Api-Token'    => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        );

        // Look up tag id (or create).
        $lookup = wp_remote_get( $api_url . '/api/3/tags?search=' . rawurlencode( $tag_name ), array(
            'headers' => $headers,
            'timeout' => 10,
        ) );
        $tag_id = null;
        if ( ! is_wp_error( $lookup ) ) {
            $body = json_decode( wp_remote_retrieve_body( $lookup ), true );
            foreach ( (array) ( $body['tags'] ?? array() ) as $t ) {
                if ( strtolower( (string) ( $t['tag'] ?? '' ) ) === strtolower( $tag_name ) ) {
                    $tag_id = (int) $t['id'];
                    break;
                }
            }
        }

        if ( ! $tag_id ) {
            $create = wp_remote_post( $api_url . '/api/3/tags', array(
                'headers' => $headers,
                'timeout' => 10,
                'body'    => wp_json_encode( array( 'tag' => array( 'tag' => $tag_name, 'tagType' => 'contact' ) ) ),
            ) );
            if ( ! is_wp_error( $create ) ) {
                $body   = json_decode( wp_remote_retrieve_body( $create ), true );
                $tag_id = isset( $body['tag']['id'] ) ? (int) $body['tag']['id'] : null;
            }
        }
        if ( ! $tag_id ) {
            return;
        }

        wp_remote_post( $api_url . '/api/3/contactTags', array(
            'headers' => $headers,
            'timeout' => 10,
            'body'    => wp_json_encode( array(
                'contactTag' => array( 'contact' => (int) $contact_id, 'tag' => (int) $tag_id ),
            ) ),
        ) );
    }

    private function get_field( $source, array $keys ) {
        foreach ( $keys as $key ) {
            if ( is_array( $source ) && isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
                return $source[ $key ];
            }
            if ( is_object( $source ) && isset( $source->$key ) && '' !== $source->$key ) {
                return $source->$key;
            }
        }
        return '';
    }

    public function handle_save() {
        if ( ! isset( $_POST['scrm_save_ac'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scrm_ac_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_ac_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scrm_save_ac' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-crm-pro' ) );
        }

        update_option( self::OPT_API_URL, untrailingslashit( esc_url_raw( wp_unslash( $_POST['ac_api_url'] ?? '' ) ) ) );
        update_option( self::OPT_API_KEY, sanitize_text_field( wp_unslash( $_POST['ac_api_key'] ?? '' ) ) );
        update_option( self::OPT_TAG,     sanitize_text_field( wp_unslash( $_POST['ac_tag'] ?? 'midland-job-completed' ) ) );
        update_option( self::OPT_ENABLED, isset( $_POST['ac_enabled'] ) ? 1 : 0 );

        wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-activecampaign&saved=1' ) );
        exit;
    }

    public function handle_test() {
        if ( ! isset( $_POST['scrm_test_ac'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scrm_ac_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_ac_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scrm_save_ac' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-crm-pro' ) );
        }

        $api_url = (string) get_option( self::OPT_API_URL, '' );
        $api_key = (string) get_option( self::OPT_API_KEY, '' );

        if ( '' === $api_url || '' === $api_key ) {
            wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-activecampaign&test=missing' ) );
            exit;
        }

        $response = wp_remote_get( $api_url . '/api/3/users/me', array(
            'headers' => array( 'Api-Token' => $api_key, 'Accept' => 'application/json' ),
            'timeout' => 10,
        ) );

        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $key  = 200 === $code ? 'ok' : 'fail';
        wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-activecampaign&test=' . $key ) );
        exit;
    }

    public function render_page() {
        $api_url  = (string) get_option( self::OPT_API_URL, '' );
        $api_key  = (string) get_option( self::OPT_API_KEY, '' );
        $tag      = (string) get_option( self::OPT_TAG, 'midland-job-completed' );
        $enabled  = (int) get_option( self::OPT_ENABLED, 0 );
        $last     = get_option( self::OPT_LAST_PUSH, array() );
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved    = isset( $_GET['saved'] );
        $test     = isset( $_GET['test'] ) ? sanitize_key( $_GET['test'] ) : '';
        // phpcs:enable
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ActiveCampaign Bridge', 'smart-crm-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Pushes the contact + a "job complete" tag to ActiveCampaign whenever a lead is marked complete here. AC then runs its own flows.', 'smart-crm-pro' ); ?></p>

            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-crm-pro' ); ?></p></div><?php endif; ?>
            <?php if ( 'ok' === $test ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connected to ActiveCampaign.', 'smart-crm-pro' ); ?></p></div><?php endif; ?>
            <?php if ( 'fail' === $test ) : ?><div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'ActiveCampaign rejected the credentials. Check the API URL and key.', 'smart-crm-pro' ); ?></p></div><?php endif; ?>
            <?php if ( 'missing' === $test ) : ?><div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Save the API URL and key first, then test.', 'smart-crm-pro' ); ?></p></div><?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'scrm_save_ac', '_scrm_ac_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="ac_enabled"><?php esc_html_e( 'Enable Sync', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <label><input type="checkbox" id="ac_enabled" name="ac_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Push contacts to ActiveCampaign on job completion.', 'smart-crm-pro' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ac_api_url"><?php esc_html_e( 'API URL', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="url" id="ac_api_url" name="ac_api_url" class="regular-text" value="<?php echo esc_attr( $api_url ); ?>" placeholder="https://your-account.api-us1.com"></td>
                    </tr>
                    <tr>
                        <th><label for="ac_api_key"><?php esc_html_e( 'API Key', 'smart-crm-pro' ); ?></label></th>
                        <td><input type="password" id="ac_api_key" name="ac_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="ac_tag"><?php esc_html_e( 'Trigger Tag', 'smart-crm-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="ac_tag" name="ac_tag" class="regular-text" value="<?php echo esc_attr( $tag ); ?>">
                            <p class="description"><?php esc_html_e( 'Applied to the contact when a job completes. Use this as the trigger in your AC automations.', 'smart-crm-pro' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="scrm_save_ac" value="1" class="button button-primary"><?php esc_html_e( 'Save', 'smart-crm-pro' ); ?></button>
                    <button type="submit" name="scrm_test_ac" value="1" class="button" style="margin-left:8px;"><?php esc_html_e( 'Test Connection', 'smart-crm-pro' ); ?></button>
                </p>
            </form>

            <?php if ( ! empty( $last ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Last Push', 'smart-crm-pro' ); ?></h2>
                <p>
                    <strong><?php echo esc_html( $last['email'] ?? '—' ); ?></strong>
                    — <?php echo esc_html( $last['tag'] ?? '' ); ?>
                    — <?php echo esc_html( ! empty( $last['ok'] ) ? __( 'OK', 'smart-crm-pro' ) : __( 'failed', 'smart-crm-pro' ) ); ?>
                    — <?php echo esc_html( ! empty( $last['at'] ) ? wp_date( 'Y-m-d H:i', (int) $last['at'] ) : '' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}

SCRM_Pro_ActiveCampaign::get_instance();
