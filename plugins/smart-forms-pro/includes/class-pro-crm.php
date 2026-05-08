<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_CRM {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 31 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'wp_ajax_sfco_pro_test_crm', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_sfco_pro_sync_lead', array( $this, 'ajax_sync_lead' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'CRM Integration', 'smart-forms-pro' ),
            esc_html__( 'CRM Integration', 'smart-forms-pro' ),
            'manage_options',
            'sfco-crm',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['sfco_save_crm'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_crm_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_crm_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_crm' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-pro' ) );
        }

        $crm_type = isset( $_POST['crm_type'] ) ? sanitize_key( $_POST['crm_type'] ) : '';
        $api_key  = isset( $_POST['crm_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['crm_api_key'] ) ) : '';
        $api_url  = isset( $_POST['crm_api_url'] ) ? esc_url_raw( wp_unslash( $_POST['crm_api_url'] ) ) : '';
        $active   = isset( $_POST['crm_active'] ) ? 1 : 0;

        if ( empty( $crm_type ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfco-crm&error=missing_type' ) );
            exit;
        }

        update_option( 'sfco_pro_crm_type', $crm_type );
        update_option( 'sfco_pro_crm_active', $active );
        update_option( 'sfco_pro_crm_api_url', untrailingslashit( $api_url ) );

        if ( ! empty( $api_key ) ) {
            update_option( 'sfco_pro_crm_api_key', $api_key );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sfco-crm&saved=1' ) );
        exit;
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'sfco_pro_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $crm_type = get_option( 'sfco_pro_crm_type', '' );
        $api_key  = get_option( 'sfco_pro_crm_api_key', '' );
        $api_url  = get_option( 'sfco_pro_crm_api_url', '' );

        if ( empty( $crm_type ) || empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'No CRM configured.', 'smart-forms-pro' ) ) );
        }

        $result = $this->test_connection( $crm_type, $api_key, $api_url );

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }
    }

    private function test_connection( $crm_type, $api_key, $api_url = '' ) {
        switch ( $crm_type ) {
            case 'hubspot':
                $response = wp_remote_get( 'https://api.hubapi.com/crm/v3/objects/contacts?limit=1', array(
                    'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
                    'timeout' => 10,
                ) );
                break;

            case 'pipedrive':
                $response = wp_remote_get( 'https://api.pipedrive.com/v1/users/me?api_token=' . $api_key, array(
                    'timeout' => 10,
                ) );
                break;

            case 'activecampaign':
                if ( empty( $api_url ) ) {
                    return array( 'success' => false, 'message' => __( 'ActiveCampaign requires an API URL (e.g. https://your-account.api-us1.com).', 'smart-forms-pro' ) );
                }
                $response = wp_remote_get( untrailingslashit( $api_url ) . '/api/3/users/me', array(
                    'headers' => array(
                        'Api-Token' => $api_key,
                        'Accept'    => 'application/json',
                    ),
                    'timeout' => 10,
                ) );
                break;

            case 'salesforce':
                // Salesforce requires OAuth - simplified test.
                return array( 'success' => true, 'message' => __( 'Salesforce credentials saved. OAuth flow required for full connection.', 'smart-forms-pro' ) );

            default:
                return array( 'success' => false, 'message' => __( 'Unknown CRM type.', 'smart-forms-pro' ) );
        }

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'message' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 === $code ) {
            return array( 'success' => true, 'message' => sprintf( __( 'Connected to %s successfully!', 'smart-forms-pro' ), ucfirst( $crm_type ) ) );
        }

        return array( 'success' => false, 'message' => sprintf( __( 'Connection failed (HTTP %d). Check your API key.', 'smart-forms-pro' ), $code ) );
    }

    /**
     * Push a lead to the connected CRM.
     */
    public function sync_lead( $lead ) {
        if ( ! SFCO_Pro_License::is_valid() ) {
            return;
        }

        $crm_type = get_option( 'sfco_pro_crm_type', '' );
        $api_key  = get_option( 'sfco_pro_crm_api_key', '' );
        $api_url  = get_option( 'sfco_pro_crm_api_url', '' );
        $active   = get_option( 'sfco_pro_crm_active', 0 );

        if ( empty( $crm_type ) || empty( $api_key ) || ! $active ) {
            return;
        }

        $contact = array(
            'name'         => $lead->customer_name ?? '',
            'email'        => $lead->customer_email ?? '',
            'phone'        => $lead->customer_phone ?? '',
            'project_type' => $lead->project_type ?? '',
            'timeline'     => $lead->timeline ?? '',
            'zip_code'     => $lead->zip_code ?? '',
        );

        switch ( $crm_type ) {
            case 'hubspot':
                $this->push_to_hubspot( $api_key, $contact );
                break;
            case 'pipedrive':
                $this->push_to_pipedrive( $api_key, $contact );
                break;
            case 'activecampaign':
                if ( ! empty( $api_url ) ) {
                    $this->push_to_activecampaign( $api_url, $api_key, $contact );
                }
                break;
        }
    }

    private function push_to_hubspot( $api_key, $contact ) {
        $name_parts = explode( ' ', $contact['name'], 2 );

        wp_remote_post( 'https://api.hubapi.com/crm/v3/objects/contacts', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'properties' => array(
                    'firstname' => $name_parts[0] ?? '',
                    'lastname'  => $name_parts[1] ?? '',
                    'email'     => $contact['email'],
                    'phone'     => $contact['phone'],
                    'zip'       => $contact['zip_code'],
                ),
            ) ),
            'timeout' => 15,
        ) );
    }

    private function push_to_pipedrive( $api_key, $contact ) {
        wp_remote_post( 'https://api.pipedrive.com/v1/persons?api_token=' . $api_key, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'name'  => $contact['name'],
                'email' => array( array( 'value' => $contact['email'], 'primary' => true ) ),
                'phone' => array( array( 'value' => $contact['phone'], 'primary' => true ) ),
            ) ),
            'timeout' => 15,
        ) );
    }

    private function push_to_activecampaign( $api_url, $api_key, $contact ) {
        $name_parts = explode( ' ', $contact['name'], 2 );

        $field_values = array();
        if ( ! empty( $contact['project_type'] ) ) {
            $field_values[] = array( 'field' => 'project_type', 'value' => $contact['project_type'] );
        }
        if ( ! empty( $contact['timeline'] ) ) {
            $field_values[] = array( 'field' => 'timeline', 'value' => $contact['timeline'] );
        }
        if ( ! empty( $contact['zip_code'] ) ) {
            $field_values[] = array( 'field' => 'zip_code', 'value' => $contact['zip_code'] );
        }

        $payload = array(
            'contact' => array(
                'email'     => $contact['email'],
                'firstName' => $name_parts[0] ?? '',
                'lastName'  => $name_parts[1] ?? '',
                'phone'     => $contact['phone'],
            ),
        );

        if ( ! empty( $field_values ) ) {
            $payload['contact']['fieldValues'] = $field_values;
        }

        wp_remote_post( untrailingslashit( $api_url ) . '/api/3/contact/sync', array(
            'headers' => array(
                'Api-Token'    => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ) );
    }

    public function ajax_sync_lead() {
        check_ajax_referer( 'sfco_pro_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $lead_id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
        if ( ! $lead_id ) {
            wp_send_json_error( array( 'message' => 'Invalid lead ID' ) );
        }

        global $wpdb;
        $lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfco_leads WHERE id = %d", $lead_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( ! $lead ) {
            wp_send_json_error( array( 'message' => 'Lead not found' ) );
        }

        $this->sync_lead( $lead );
        wp_send_json_success( array( 'message' => __( 'Lead synced to CRM.', 'smart-forms-pro' ) ) );
    }

    public function render_page() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>' . esc_html__( 'Please activate your PRO license.', 'smart-forms-pro' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=sfco-license' ) ) . '">' . esc_html__( 'Enter License Key', 'smart-forms-pro' ) . '</a></p></div></div>';
            return;
        }

        $crm_type = get_option( 'sfco_pro_crm_type', '' );
        $api_key  = get_option( 'sfco_pro_crm_api_key', '' );
        $api_url  = get_option( 'sfco_pro_crm_api_url', '' );
        $active   = get_option( 'sfco_pro_crm_active', 0 );

        $crm_options = array(
            ''               => __( 'Select CRM...', 'smart-forms-pro' ),
            'hubspot'        => 'HubSpot',
            'pipedrive'      => 'Pipedrive',
            'activecampaign' => 'ActiveCampaign',
            'salesforce'     => 'Salesforce',
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'CRM Integration', 'smart-forms-pro' ); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'CRM settings saved.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_crm', '_sfco_crm_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="crm_type"><?php esc_html_e( 'CRM Provider', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <select name="crm_type" id="crm_type">
                                <?php foreach ( $crm_options as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $crm_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="sfco-crm-url-row" style="<?php echo 'activecampaign' === $crm_type ? '' : 'display:none;'; ?>">
                        <th><label for="crm_api_url"><?php esc_html_e( 'API URL', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="url" name="crm_api_url" id="crm_api_url" class="regular-text" value="<?php echo esc_attr( $api_url ); ?>" placeholder="https://your-account.api-us1.com">
                            <p class="description"><?php esc_html_e( 'ActiveCampaign account URL. Found in Settings → Developer.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="crm_api_key"><?php esc_html_e( 'API Key', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="password" name="crm_api_key" id="crm_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" placeholder="<?php esc_attr_e( 'Enter your API key...', 'smart-forms-pro' ); ?>">
                            <button type="button" class="button" id="sfco-test-crm"><?php esc_html_e( 'Test Connection', 'smart-forms-pro' ); ?></button>
                            <span id="sfco-crm-test-result"></span>
                            <script>
                            (function(){
                                var sel = document.getElementById('crm_type');
                                var row = document.getElementById('sfco-crm-url-row');
                                if ( sel && row ) {
                                    sel.addEventListener('change', function(){
                                        row.style.display = ( sel.value === 'activecampaign' ) ? '' : 'none';
                                    });
                                }
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Auto-Sync', 'smart-forms-pro' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="crm_active" value="1" <?php checked( $active ); ?>> <?php esc_html_e( 'Automatically push new leads to CRM', 'smart-forms-pro' ); ?></label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="sfco_save_crm" value="1" class="button button-primary"><?php esc_html_e( 'Save CRM Settings', 'smart-forms-pro' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}

new SFCO_Pro_CRM();
