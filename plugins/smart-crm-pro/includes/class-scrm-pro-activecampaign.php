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

        // Booking events fire when a new lead is captured from any source.
        add_action( 'sfco_lead_created',         array( $this, 'on_lead_booked' ) );
        add_action( 'scai_lead_captured',        array( $this, 'on_chat_lead_captured' ), 10, 2 );

        // Lifecycle events when a job actually completes.
        add_action( 'sfco_lead_status_changed',  array( $this, 'on_status_changed' ), 10, 3 );
        add_action( 'sfco_lead_completed',       array( $this, 'on_lead_completed' ) );
        add_action( 'scrm_pro_job_completed',    array( $this, 'on_lead_completed' ) );
    }

    /**
     * Booking from any source that fires sfco_lead_created with a lead row/array.
     */
    public function on_lead_booked( $lead ) {
        $this->push_lead( $lead, 'booked' );
    }

    /**
     * Booking from the chat plugin — different payload shape (associative array).
     */
    public function on_chat_lead_captured( $lead_id, $data ) {
        // Reshape so push_lead's normalization picks it up.
        $lead = (object) array_merge(
            array( 'id' => (int) $lead_id ),
            (array) $data
        );
        $this->push_lead( $lead, 'booked' );
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
        $this->push_lead( $lead, 'completed' );
    }

    public function on_lead_completed( $lead ) {
        $this->push_lead( $lead, 'completed' );
    }

    /**
     * Categorize the lead so AC flows can branch on commercial / residential / emergency.
     * Order of precedence:
     *   1. explicit category field set by the form
     *   2. timeline matches an emergency keyword
     *   3. project_type matches a commercial keyword
     *   4. default: residential
     *
     * Filters: rsseo_lead_category lets a 3rd party override the inferred result.
     */
    public function categorize_lead( $lead ) {
        $explicit = strtolower( (string) $this->get_field( $lead, array( 'category', 'job_category', 'lead_category' ) ) );
        if ( in_array( $explicit, array( 'commercial', 'residential', 'emergency' ), true ) ) {
            return apply_filters( 'scrm_pro_lead_category', $explicit, $lead );
        }

        $timeline     = strtolower( (string) $this->get_field( $lead, array( 'timeline' ) ) );
        $project_type = strtolower( (string) $this->get_field( $lead, array( 'project_type', 'service_type' ) ) );
        $message      = strtolower( (string) $this->get_field( $lead, array( 'message' ) ) );

        $emergency_re = '/\b(emergency|urgent|asap|same[ -]?day|24[ -]?h(our)?s?|right[ -]now|today)\b/i';
        if ( preg_match( $emergency_re, $timeline ) || preg_match( $emergency_re, $message ) ) {
            return apply_filters( 'scrm_pro_lead_category', 'emergency', $lead );
        }

        $commercial_re = '/\b(commercial|business|office|retail|warehouse|industrial|hoa|property[ -]?manag)/i';
        if ( preg_match( $commercial_re, $project_type ) || preg_match( $commercial_re, $message ) ) {
            return apply_filters( 'scrm_pro_lead_category', 'commercial', $lead );
        }

        return apply_filters( 'scrm_pro_lead_category', 'residential', $lead );
    }

    /**
     * Map (lifecycle, category) to the AC tags that should be applied.
     * Operators fully control the actual flow on the AC side; this only emits
     * the trigger-tag the flows listen for.
     */
    public function tags_for( $lifecycle, $category ) {
        $base = (string) get_option( self::OPT_TAG, 'midland-job-completed' );

        $tags = array();
        switch ( $lifecycle ) {
            case 'booked':
                if ( 'commercial' === $category ) {
                    $tags[] = 'midland-onsite-booked-commercial';
                } elseif ( 'residential' === $category ) {
                    $tags[] = 'midland-job-booked-residential';
                } elseif ( 'emergency' === $category ) {
                    $tags[] = 'midland-job-booked-emergency';
                }
                break;
            case 'completed':
                if ( 'emergency' === $category ) {
                    // Emergency gets BOTH the post-job flow tag and the floor-care-plan tag
                    // so AC can branch on whichever automation it wants to run first.
                    $tags[] = 'midland-job-completed-emergency';
                    $tags[] = 'midland-floor-care-plan-offer';
                } elseif ( 'residential' === $category ) {
                    $tags[] = 'midland-job-completed-residential';
                } elseif ( 'commercial' === $category ) {
                    $tags[] = 'midland-job-completed-commercial';
                } else {
                    $tags[] = $base;
                }
                break;
        }
        return apply_filters( 'scrm_pro_ac_tags', $tags, $lifecycle, $category );
    }

    /**
     * Push a lead to ActiveCampaign with category-aware tags + booking metadata
     * as fieldValues so AC flows can personalize.
     *
     * @param mixed  $lead       Object or array.
     * @param string $lifecycle  'booked' or 'completed'.
     */
    private function push_lead( $lead, $lifecycle = 'completed' ) {
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

        $category = $this->categorize_lead( $lead );

        $name_parts = explode( ' ', trim( $name ), 2 );

        // Forward booking metadata as AC fieldValues so flows can render
        // "you booked carpet cleaning at 1500 sqft on Tuesday" without us
        // pre-templating it. AC just needs the contact field to exist with
        // matching field name — which is operator-side setup.
        $field_values = array();
        $forward = array(
            'project_type'         => array( 'project_type', 'service_type' ),
            'timeline'             => array( 'timeline' ),
            'zip_code'             => array( 'zip_code', 'zip' ),
            'square_footage'       => array( 'square_footage', 'sqft', 'square_feet' ),
            'floor_type'           => array( 'floor_type', 'flooring' ),
            'frequency'            => array( 'frequency', 'cleaning_frequency' ),
            'job_id'               => array( 'job_id', 'id' ),
            'midland_category'     => array(),
            'floor_care_plan_url'  => array( 'floor_care_plan_url' ),
        );

        foreach ( $forward as $ac_field => $sources ) {
            if ( 'midland_category' === $ac_field ) {
                $value = $category;
            } else {
                $value = (string) $this->get_field( $lead, $sources );
            }
            if ( '' !== $value ) {
                $field_values[] = array( 'field' => $ac_field, 'value' => $value );
            }
        }

        $contact = array(
            'email'     => $email,
            'firstName' => $name_parts[0] ?? '',
            'lastName'  => $name_parts[1] ?? '',
            'phone'     => $phone,
        );
        if ( ! empty( $field_values ) ) {
            $contact['fieldValues'] = $field_values;
        }

        $response = wp_remote_post( untrailingslashit( $api_url ) . '/api/3/contact/sync', array(
            'headers' => array(
                'Api-Token'    => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'contact' => $contact ) ),
            'timeout' => 15,
        ) );

        $contact_id = null;
        if ( ! is_wp_error( $response ) ) {
            $body       = json_decode( wp_remote_retrieve_body( $response ), true );
            $contact_id = isset( $body['contact']['id'] ) ? (int) $body['contact']['id'] : null;
        }

        $tags = $this->tags_for( $lifecycle, $category );
        if ( $contact_id ) {
            foreach ( $tags as $tag ) {
                $this->apply_tag( $api_url, $api_key, $contact_id, $tag );
            }
        }

        update_option( self::OPT_LAST_PUSH, array(
            'at'        => time(),
            'email'     => $email,
            'lifecycle' => $lifecycle,
            'category'  => $category,
            'tags'      => $tags,
            'ok'        => $contact_id ? 1 : 0,
        ) );

        do_action( 'scrm_pro_ac_pushed', $lead, $lifecycle, $category, $contact_id, $tags );
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
                    — <?php echo esc_html( $last['lifecycle'] ?? ( $last['tag'] ?? '' ) ); ?>
                    / <?php echo esc_html( $last['category'] ?? '' ); ?>
                    — <?php echo esc_html( ! empty( $last['ok'] ) ? __( 'OK', 'smart-crm-pro' ) : __( 'failed', 'smart-crm-pro' ) ); ?>
                    — <?php echo esc_html( ! empty( $last['at'] ) ? wp_date( 'Y-m-d H:i', (int) $last['at'] ) : '' ); ?>
                </p>
                <?php if ( ! empty( $last['tags'] ) ) : ?>
                    <p style="margin:0 0 12px;color:#555;">
                        <?php esc_html_e( 'Tags applied:', 'smart-crm-pro' ); ?>
                        <?php foreach ( (array) $last['tags'] as $t ) : ?>
                            <code style="margin-right:6px;"><?php echo esc_html( $t ); ?></code>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

SCRM_Pro_ActiveCampaign::get_instance();
