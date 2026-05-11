<?php
/**
 * Google Indexing API — pushes URL_UPDATED / URL_DELETED notifications to
 * Google whenever a JobPosting goes live, gets edited, or is taken down.
 *
 * Google only accepts these calls for two schema types: JobPosting and
 * BroadcastEvent. JobPosting is exactly what this plugin produces.
 *
 * Auth flow:
 *   1. Plugin admin pastes a Google Cloud service-account JSON key
 *      (the one downloaded from console.cloud.google.com → IAM →
 *      Service Accounts → Keys → Add Key → JSON).
 *   2. We sign a short-lived JWT with the service account's private key
 *      and exchange it at oauth2.googleapis.com/token for an access token,
 *      cached in a transient for 50 minutes.
 *   3. POST {url, type} to indexing.googleapis.com/v3/urlNotifications:publish
 *      with Authorization: Bearer <token>.
 *
 * Last 30 calls are logged to a wp_options array for visibility from the
 * Settings page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Indexing_API {

    const PUBLISH_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    const SCOPE       = 'https://www.googleapis.com/auth/indexing';

    const OPT_JSON    = 'dpjp_indexing_api_json';
    const OPT_ENABLED = 'dpjp_indexing_api_enabled';
    const OPT_LOG     = 'dpjp_indexing_api_log';
    const TRANSIENT   = 'dpjp_indexing_api_token';

    public static function register(): void {
        // Lifecycle listeners for the dpjp_job CPT only.
        add_action( 'save_post_dpjp_job',    [ __CLASS__, 'on_save' ], 99, 3 );
        add_action( 'untrashed_post',        [ __CLASS__, 'on_untrash' ] );
        add_action( 'trashed_post',          [ __CLASS__, 'on_trash' ] );
        add_action( 'before_delete_post',    [ __CLASS__, 'on_trash' ] );
        add_action( 'transition_post_status',[ __CLASS__, 'on_status_change' ], 10, 3 );

        // Admin form integration.
        add_action( 'dpjp_render_settings_extra',          [ __CLASS__, 'render_section' ] );
        add_action( 'admin_post_dpjp_save_indexing_api',   [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_dpjp_test_indexing_api',   [ __CLASS__, 'handle_test' ] );
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public static function on_save( int $post_id, $post = null, bool $update = false ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( 'publish' !== get_post_status( $post_id ) ) return;
        $url = get_permalink( $post_id );
        if ( ! $url ) return;
        self::notify( $url, 'URL_UPDATED', $post_id );
    }

    public static function on_untrash( int $post_id ): void {
        if ( 'dpjp_job' !== get_post_type( $post_id ) ) return;
        if ( 'publish' !== get_post_status( $post_id ) ) return;
        $url = get_permalink( $post_id );
        if ( $url ) self::notify( $url, 'URL_UPDATED', $post_id );
    }

    public static function on_trash( int $post_id ): void {
        if ( 'dpjp_job' !== get_post_type( $post_id ) ) return;
        $url = get_permalink( $post_id );
        if ( $url ) self::notify( $url, 'URL_DELETED', $post_id );
    }

    public static function on_status_change( string $new, string $old, $post ): void {
        if ( ! $post || 'dpjp_job' !== $post->post_type ) return;
        if ( 'publish' === $old && 'publish' !== $new ) {
            $url = get_permalink( $post->ID );
            if ( $url ) self::notify( $url, 'URL_DELETED', $post->ID );
        }
    }

    // ── API call ──────────────────────────────────────────────────────────────

    public static function notify( string $url, string $type, int $post_id = 0 ): bool {
        if ( ! self::is_enabled() ) return false;
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            self::log( $post_id, $type, $url, 'token_error', $token->get_error_message() );
            return false;
        }
        $response = wp_remote_post( self::PUBLISH_URL, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [ 'url' => $url, 'type' => $type ] ),
        ] );
        if ( is_wp_error( $response ) ) {
            self::log( $post_id, $type, $url, 'http_error', $response->get_error_message() );
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code >= 200 && $code < 300 ) {
            self::log( $post_id, $type, $url, 'ok', '' );
            return true;
        }
        self::log( $post_id, $type, $url, 'api_error', sprintf( 'HTTP %d: %s', $code, mb_substr( $body, 0, 240 ) ) );
        return false;
    }

    // ── OAuth2 / JWT ──────────────────────────────────────────────────────────

    public static function get_access_token() {
        $cached = get_transient( self::TRANSIENT );
        if ( $cached ) return $cached;

        $json = self::get_service_account();
        if ( empty( $json ) ) return new WP_Error( 'no_json', 'Service account JSON not configured.' );

        $sa = json_decode( $json, true );
        if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
            return new WP_Error( 'bad_json', 'Service account JSON is missing client_email or private_key.' );
        }

        $now = time();
        $header = self::b64url( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $claim  = self::b64url( wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ] ) );
        $unsigned = $header . '.' . $claim;

        $pkey = openssl_pkey_get_private( $sa['private_key'] );
        if ( ! $pkey ) return new WP_Error( 'bad_key', 'Invalid private_key in service account JSON.' );

        $signature = '';
        $ok = openssl_sign( $unsigned, $signature, $pkey, OPENSSL_ALGO_SHA256 );
        if ( ! $ok ) return new WP_Error( 'sign_failed', 'Failed to sign JWT — check your private key.' );

        $jwt = $unsigned . '.' . self::b64url( $signature );

        $response = wp_remote_post( self::TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );
        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            $err = isset( $data['error_description'] ) ? $data['error_description'] : ( $data['error'] ?? 'unknown' );
            return new WP_Error( 'no_token', 'OAuth2 token endpoint error: ' . $err );
        }
        set_transient( self::TRANSIENT, $data['access_token'], 3000 ); // 50 min
        return $data['access_token'];
    }

    private static function b64url( string $s ): string {
        return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
    }

    // ── State ─────────────────────────────────────────────────────────────────

    public static function is_configured(): bool {
        return '' !== self::get_service_account();
    }

    public static function is_enabled(): bool {
        return self::is_configured() && (bool) get_option( self::OPT_ENABLED, 0 );
    }

    public static function get_service_account(): string {
        return (string) get_option( self::OPT_JSON, '' );
    }

    public static function log( int $post_id, string $type, string $url, string $status, string $message ): void {
        $log = (array) get_option( self::OPT_LOG, [] );
        array_unshift( $log, [
            'time'    => time(),
            'post_id' => $post_id,
            'type'    => $type,
            'url'     => $url,
            'status'  => $status,
            'message' => $message,
        ] );
        $log = array_slice( $log, 0, 30 );
        update_option( self::OPT_LOG, $log, false );
    }

    // ── Admin UI ──────────────────────────────────────────────────────────────

    public static function render_section(): void {
        $json     = self::get_service_account();
        $enabled  = (bool) get_option( self::OPT_ENABLED, 0 );
        $log      = (array) get_option( self::OPT_LOG, [] );
        $action   = admin_url( 'admin-post.php' );
        $test_url = wp_nonce_url( add_query_arg( 'action', 'dpjp_test_indexing_api', $action ), 'dpjp_test_indexing_api' );
        $status   = isset( $_GET['indexing_api'] ) ? sanitize_key( $_GET['indexing_api'] ) : '';
        $msg      = isset( $_GET['indexing_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['indexing_msg'] ) ) : '';
        ?>
        <hr>
        <h2>Google Indexing API</h2>
        <p>Push <code>URL_UPDATED</code> / <code>URL_DELETED</code> directly to Google whenever a job is published, edited, or removed — minutes instead of days for new listings to appear in Google for Jobs.</p>

        <?php if ( 'ok' === $status ) : ?>
            <div class="notice notice-success is-dismissible"><p>✓ Indexing API connection successful.</p></div>
        <?php elseif ( 'fail' === $status && $msg ) : ?>
            <div class="notice notice-error is-dismissible"><p>✗ <?php echo esc_html( $msg ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( $action ); ?>">
            <?php wp_nonce_field( 'dpjp_save_indexing_api', 'dpjp_indexing_nonce' ); ?>
            <input type="hidden" name="action" value="dpjp_save_indexing_api">

            <table class="form-table">
                <tr>
                    <th><label for="dpjp_indexing_api_enabled">Enabled</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="dpjp_indexing_api_enabled" name="dpjp_indexing_api_enabled" value="1" <?php checked( $enabled ); ?>>
                            Send URL notifications to Google automatically when a job changes.
                        </label>
                        <?php if ( ! self::is_configured() ) : ?>
                            <p class="description" style="color:#b32d2e;">Paste your service account JSON below before enabling.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="dpjp_indexing_api_json">Service Account JSON</label></th>
                    <td>
                        <textarea id="dpjp_indexing_api_json" name="dpjp_indexing_api_json" rows="10" cols="80" class="large-text code" placeholder='{"type":"service_account","project_id":"...","private_key":"...","client_email":"...",...}'><?php echo esc_textarea( $json ); ?></textarea>
                        <p class="description">
                            Paste the full JSON key file you downloaded from <code>console.cloud.google.com → IAM → Service Accounts → Keys</code>.
                            The service account must have the <strong>Owner</strong> role on a Google Search Console property covering this site, and the <strong>Indexing API</strong> must be enabled in your Cloud project.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Indexing API Settings', 'primary', 'submit', false ); ?>
            <a href="<?php echo esc_url( $test_url ); ?>" class="button" style="margin-left:6px;">Test Connection</a>
        </form>

        <?php if ( ! empty( $log ) ) : ?>
            <h3>Recent activity</h3>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr><th>When</th><th>Type</th><th>URL</th><th>Status</th><th>Message</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $log as $row ) :
                    $color = 'ok' === $row['status'] ? '#1e7e34' : '#b32d2e';
                    ?>
                    <tr>
                        <td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $row['time'] ) ); ?></td>
                        <td><code><?php echo esc_html( $row['type'] ); ?></code></td>
                        <td><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['url'] ); ?></a></td>
                        <td style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $row['status'] ); ?></td>
                        <td><?php echo esc_html( $row['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>How to set this up</h3>
        <ol>
            <li>Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">console.cloud.google.com</a> and create (or pick) a project.</li>
            <li><strong>APIs &amp; Services → Library</strong> → enable <em>"Indexing API"</em>.</li>
            <li><strong>IAM &amp; Admin → Service Accounts</strong> → Create Service Account. Copy the email (looks like <code>foo@project.iam.gserviceaccount.com</code>).</li>
            <li>Open that service account → <strong>Keys → Add Key → Create new key → JSON</strong> → download.</li>
            <li><a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">Google Search Console</a> → your site property → <strong>Settings → Users and permissions → Add user</strong> → paste the service account email, set role to <strong>Owner</strong>.</li>
            <li>Paste the JSON file's contents above, save, click <em>Test Connection</em>, then tick <em>Enabled</em>.</li>
        </ol>
        <?php
    }

    public static function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No.' );
        check_admin_referer( 'dpjp_save_indexing_api', 'dpjp_indexing_nonce' );

        $json = trim( (string) wp_unslash( $_POST['dpjp_indexing_api_json'] ?? '' ) );
        if ( '' !== $json ) {
            $parsed = json_decode( $json, true );
            if ( ! is_array( $parsed ) || empty( $parsed['client_email'] ) || empty( $parsed['private_key'] ) ) {
                self::redirect( 'fail', 'Pasted JSON is not a valid service account key.' );
                return;
            }
        }
        update_option( self::OPT_JSON, $json );
        update_option( self::OPT_ENABLED, isset( $_POST['dpjp_indexing_api_enabled'] ) ? 1 : 0 );
        delete_transient( self::TRANSIENT );
        self::redirect( '', '', true );
    }

    public static function handle_test(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No.' );
        check_admin_referer( 'dpjp_test_indexing_api' );
        delete_transient( self::TRANSIENT );
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            self::redirect( 'fail', $token->get_error_message() );
            return;
        }
        self::redirect( 'ok', '' );
    }

    private static function redirect( string $status, string $msg, bool $saved = false ): void {
        $args = [ 'post_type' => 'dpjp_job', 'page' => 'dpjp-settings' ];
        if ( $saved )      $args['saved']         = 1;
        if ( $status )     $args['indexing_api']  = $status;
        if ( '' !== $msg ) $args['indexing_msg']  = $msg;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
    }
}
