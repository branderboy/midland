<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google Calendar integration.
 * OAuth2 connect → on appointment confirm → create GCal event automatically.
 * Settings: Smart Forms PRO > Google Calendar
 */
class SFCO_Pro_GCal {

    const OAUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_ENDPOINT  = 'https://oauth2.googleapis.com/token';
    const EVENTS_ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    const SCOPES          = 'https://www.googleapis.com/auth/calendar.events';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',     array( $this, 'add_menu' ), 36 );
        add_action( 'admin_init',     array( $this, 'handle_save' ) );
        add_action( 'admin_init',     array( $this, 'handle_oauth_callback' ) );
        add_action( 'admin_init',     array( $this, 'handle_disconnect' ) );
        // Hook: sfco_appointment_confirmed fires from class-pro-automations when a booking is confirmed.
        add_action( 'sfco_appointment_confirmed', array( $this, 'create_event' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'Google Calendar', 'smart-forms-pro' ),
            esc_html__( 'Google Calendar', 'smart-forms-pro' ),
            'manage_options',
            'sfco-gcal',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['sfco_save_gcal'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_gcal_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_gcal_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_gcal' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-pro' ) );
        }

        update_option( 'sfco_gcal_client_id',     sanitize_text_field( wp_unslash( $_POST['gcal_client_id'] ?? '' ) ) );
        update_option( 'sfco_gcal_client_secret', sanitize_text_field( wp_unslash( $_POST['gcal_client_secret'] ?? '' ) ) );
        update_option( 'sfco_gcal_event_duration', absint( $_POST['gcal_event_duration'] ?? 60 ) );
        update_option( 'sfco_gcal_location',       sanitize_text_field( wp_unslash( $_POST['gcal_location'] ?? '' ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=sfco-gcal&saved=1' ) );
        exit;
    }

    /**
     * Initiate OAuth flow → redirect to Google.
     */
    public function get_oauth_url() {
        $client_id    = get_option( 'sfco_gcal_client_id', '' );
        $redirect_uri = admin_url( 'admin.php?page=sfco-gcal' );

        $state = wp_create_nonce( 'sfco_gcal_oauth' );
        update_option( 'sfco_gcal_oauth_state', $state );

        return add_query_arg( array(
            'client_id'             => rawurlencode( $client_id ),
            'redirect_uri'          => rawurlencode( $redirect_uri ),
            'response_type'         => 'code',
            'scope'                 => rawurlencode( self::SCOPES ),
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'state'                 => $state,
        ), self::OAUTH_ENDPOINT );
    }

    /**
     * Handle OAuth callback — exchange code for tokens.
     */
    public function handle_oauth_callback() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['code'] ) || ! isset( $_GET['page'] ) || 'sfco-gcal' !== $_GET['page'] ) {
            return;
        }

        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        // phpcs:enable

        if ( ! wp_verify_nonce( $state, 'sfco_gcal_oauth' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Google OAuth state mismatch. Try again.', 'smart-forms-pro' ) . '</p></div>';
            } );
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code         = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $client_id    = get_option( 'sfco_gcal_client_id', '' );
        $client_secret = get_option( 'sfco_gcal_client_secret', '' );
        $redirect_uri = admin_url( 'admin.php?page=sfco-gcal' );

        $response = wp_remote_post( self::TOKEN_ENDPOINT, array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $tokens = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $tokens['access_token'] ) ) {
            update_option( 'sfco_gcal_access_token',  $tokens['access_token'] );
            update_option( 'sfco_gcal_token_expires',  time() + ( $tokens['expires_in'] ?? 3600 ) );
            if ( ! empty( $tokens['refresh_token'] ) ) {
                update_option( 'sfco_gcal_refresh_token', $tokens['refresh_token'] );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=sfco-gcal&connected=1' ) );
            exit;
        }
    }

    public function handle_disconnect() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['sfco_gcal_disconnect'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, 'sfco_gcal_disconnect' ) ) {
            return;
        }

        delete_option( 'sfco_gcal_access_token' );
        delete_option( 'sfco_gcal_refresh_token' );
        delete_option( 'sfco_gcal_token_expires' );

        wp_safe_redirect( admin_url( 'admin.php?page=sfco-gcal&disconnected=1' ) );
        exit;
    }

    /**
     * Get a valid access token, refreshing if expired.
     */
    private function get_access_token() {
        $token   = get_option( 'sfco_gcal_access_token', '' );
        $expires = (int) get_option( 'sfco_gcal_token_expires', 0 );

        if ( $token && time() < $expires - 60 ) {
            return $token;
        }

        $refresh = get_option( 'sfco_gcal_refresh_token', '' );
        if ( ! $refresh ) {
            return false;
        }

        $response = wp_remote_post( self::TOKEN_ENDPOINT, array(
            'body' => array(
                'refresh_token' => $refresh,
                'client_id'     => get_option( 'sfco_gcal_client_id', '' ),
                'client_secret' => get_option( 'sfco_gcal_client_secret', '' ),
                'grant_type'    => 'refresh_token',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $tokens = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $tokens['access_token'] ) ) {
            update_option( 'sfco_gcal_access_token', $tokens['access_token'] );
            update_option( 'sfco_gcal_token_expires', time() + ( $tokens['expires_in'] ?? 3600 ) );
            return $tokens['access_token'];
        }

        return false;
    }

    /**
     * Create a Google Calendar event.
     *
     * @param array $appointment {
     *   title       string  Event title
     *   description string  Optional description
     *   start       string  ISO 8601 datetime (e.g. 2026-05-01T10:00:00-04:00)
     *   end         string  ISO 8601 datetime
     *   attendee_email string  Client email
     *   attendee_name  string  Client name
     * }
     * @return string|false Event ID on success, false on failure.
     */
    public function create_event( $appointment ) {
        $token = $this->get_access_token();
        if ( ! $token ) {
            return false;
        }

        $duration = (int) get_option( 'sfco_gcal_event_duration', 60 );
        $location = get_option( 'sfco_gcal_location', '' );

        $start = $appointment['start'] ?? gmdate( 'c', strtotime( '+1 day' ) );

        if ( empty( $appointment['end'] ) ) {
            $end = gmdate( 'c', strtotime( $start ) + $duration * 60 );
        } else {
            $end = $appointment['end'];
        }

        $event = array(
            'summary'     => sanitize_text_field( $appointment['title'] ?? 'Appointment' ),
            'description' => wp_kses_post( $appointment['description'] ?? '' ),
            'location'    => $location,
            'start'       => array( 'dateTime' => $start, 'timeZone' => wp_timezone_string() ),
            'end'         => array( 'dateTime' => $end, 'timeZone' => wp_timezone_string() ),
            'reminders'   => array(
                'useDefault' => false,
                'overrides'  => array(
                    array( 'method' => 'email', 'minutes' => 1440 ), // 24h
                    array( 'method' => 'popup', 'minutes' => 120 ),  // 2h
                ),
            ),
        );

        if ( ! empty( $appointment['attendee_email'] ) && is_email( $appointment['attendee_email'] ) ) {
            $event['attendees'] = array(
                array(
                    'email'       => $appointment['attendee_email'],
                    'displayName' => $appointment['attendee_name'] ?? '',
                ),
            );
            $event['sendUpdates'] = 'all';
        }

        $response = wp_remote_post( self::EVENTS_ENDPOINT, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $event ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['id'] ?? false;
    }

    public function is_connected() {
        return ! empty( get_option( 'sfco_gcal_refresh_token', '' ) );
    }

    public function render_page() {
        $client_id    = get_option( 'sfco_gcal_client_id', '' );
        $client_secret = get_option( 'sfco_gcal_client_secret', '' );
        $duration     = get_option( 'sfco_gcal_event_duration', 60 );
        $location     = get_option( 'sfco_gcal_location', '' );
        $connected    = $this->is_connected();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved       = isset( $_GET['saved'] );
        $just_conn   = isset( $_GET['connected'] );
        $disconnected = isset( $_GET['disconnected'] );
        // phpcs:enable

        $redirect_uri = admin_url( 'admin.php?page=sfco-gcal' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Google Calendar Integration', 'smart-forms-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Auto-create calendar events when appointments are confirmed. Client gets 24h + 2h reminders automatically.', 'smart-forms-pro' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $just_conn ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google Calendar connected successfully!', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $disconnected ) : ?>
                <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Google Calendar disconnected.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Connection Status', 'smart-forms-pro' ); ?></h2>
            <?php if ( $connected ) : ?>
                <p>
                    <span style="color:#46b450;">&#10003;</span>
                    <strong><?php esc_html_e( 'Connected', 'smart-forms-pro' ); ?></strong>
                    &nbsp;
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=sfco-gcal&sfco_gcal_disconnect=1' ), 'sfco_gcal_disconnect' ) ); ?>" class="button button-secondary button-small"><?php esc_html_e( 'Disconnect', 'smart-forms-pro' ); ?></a>
                </p>
            <?php else : ?>
                <p>
                    <span style="color:#dc3232;">&#10007;</span>
                    <strong><?php esc_html_e( 'Not connected', 'smart-forms-pro' ); ?></strong>
                    — <?php esc_html_e( 'enter credentials below then click Connect.', 'smart-forms-pro' ); ?>
                </p>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_gcal', '_sfco_gcal_nonce' ); ?>

                <h2><?php esc_html_e( 'OAuth Credentials', 'smart-forms-pro' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Create OAuth 2.0 credentials in Google Cloud Console → APIs & Services → Credentials.', 'smart-forms-pro' ); ?><br>
                    <?php esc_html_e( 'Authorized redirect URI:', 'smart-forms-pro' ); ?> <code><?php echo esc_html( $redirect_uri ); ?></code>
                </p>

                <table class="form-table">
                    <tr>
                        <th><label for="gcal_client_id"><?php esc_html_e( 'Client ID', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="text" id="gcal_client_id" name="gcal_client_id" class="large-text" value="<?php echo esc_attr( $client_id ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="gcal_client_secret"><?php esc_html_e( 'Client Secret', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="password" id="gcal_client_secret" name="gcal_client_secret" class="regular-text" value="<?php echo esc_attr( $client_secret ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="gcal_event_duration"><?php esc_html_e( 'Default Duration (minutes)', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="number" id="gcal_event_duration" name="gcal_event_duration" value="<?php echo esc_attr( $duration ); ?>" min="15" step="15" style="width:80px;"></td>
                    </tr>
                    <tr>
                        <th><label for="gcal_location"><?php esc_html_e( 'Default Location', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="gcal_location" name="gcal_location" class="regular-text" value="<?php echo esc_attr( $location ); ?>" placeholder="e.g. Client's address">
                            <p class="description"><?php esc_html_e( 'Appears in the calendar event. Can be overridden per appointment.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="sfco_save_gcal" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'smart-forms-pro' ); ?></button>

                    <?php if ( $client_id ) : ?>
                        <a href="<?php echo esc_url( $this->get_oauth_url() ); ?>" class="button button-secondary" style="margin-left:8px;">
                            <?php echo $connected ? esc_html__( 'Reconnect Google', 'smart-forms-pro' ) : esc_html__( 'Connect Google Calendar', 'smart-forms-pro' ); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </form>

            <hr>
            <h3><?php esc_html_e( 'How It Works', 'smart-forms-pro' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'Lead submits form → automation confirms appointment → GCal event created in seconds.', 'smart-forms-pro' ); ?></li>
                <li><?php esc_html_e( 'Client receives Google Calendar invite with 24h + 2h email reminders.', 'smart-forms-pro' ); ?></li>
                <li><?php esc_html_e( 'Owner\'s calendar stays in sync — no double bookings, no no-shows.', 'smart-forms-pro' ); ?></li>
            </ol>
        </div>
        <?php
    }
}

SFCO_Pro_GCal::get_instance();
