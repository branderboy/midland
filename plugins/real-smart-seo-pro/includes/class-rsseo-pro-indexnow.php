<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * IndexNow + Rapid URL Indexer.
 * Dual indexing layer: pings IndexNow (Bing/Yandex/others) + Rapid URL Indexer API on every publish.
 * Settings: Midland Smart SEO Pro > IndexNow
 */
class RSSEO_Pro_IndexNow {

    const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
    const RUI_ENDPOINT      = 'https://rapidurlindexer.com/api/v1/submit'; // Rapid URL Indexer REST API

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',     array( $this, 'add_menu' ), 29 );
        add_action( 'admin_init',     array( $this, 'handle_save' ) );
        add_action( 'admin_init',     array( $this, 'handle_test_ping' ) );
        // Fire on every post publish / update.
        add_action( 'save_post',      array( $this, 'on_save_post' ), 10, 2 );
        // Batch pings from Programmatic module.
        add_action( 'rsseo_indexnow_ping',       array( $this, 'ping_single' ) );
        add_action( 'rsseo_indexnow_batch_ping', array( $this, 'ping_batch' ) );
        // Serve the IndexNow key file at /<api-key>.txt.
        add_action( 'init',           array( $this, 'serve_key_file' ) );
    }

    public function add_menu() {
        add_submenu_page(
            null,
            esc_html__( 'IndexNow', 'real-smart-seo-pro' ),
            esc_html__( 'IndexNow', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-indexnow',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['rsseo_save_indexnow'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_rsseo_indexnow_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_indexnow_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_save_indexnow' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        update_option( 'rsseo_indexnow_api_key',    sanitize_text_field( wp_unslash( $_POST['indexnow_api_key'] ?? '' ) ) );
        update_option( 'rsseo_indexnow_enabled',    isset( $_POST['indexnow_enabled'] ) ? 1 : 0 );
        update_option( 'rsseo_rui_api_key',         sanitize_text_field( wp_unslash( $_POST['rui_api_key'] ?? '' ) ) );
        update_option( 'rsseo_rui_enabled',         isset( $_POST['rui_enabled'] ) ? 1 : 0 );
        update_option( 'rsseo_indexnow_post_types', isset( $_POST['indexnow_post_types'] ) ? array_map( 'sanitize_key', $_POST['indexnow_post_types'] ) : array() );

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-indexnow&saved=1' ) );
        exit;
    }

    public function handle_test_ping() {
        if ( ! isset( $_POST['rsseo_test_indexnow'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_rsseo_indexnow_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_indexnow_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_save_indexnow' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $url    = home_url( '/' );
        $result = $this->ping_indexnow( $url );

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-indexnow&test_result=' . ( $result ? 'ok' : 'fail' ) ) );
        exit;
    }

    /**
     * Serve the IndexNow API key verification file.
     * URL: https://yourdomain.com/<api-key>.txt
     */
    public function serve_key_file() {
        $api_key = get_option( 'rsseo_indexnow_api_key', '' );
        if ( ! $api_key ) {
            return;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        if ( '/' . $api_key . '.txt' === $request_uri ) {
            header( 'Content-Type: text/plain' );
            echo esc_html( $api_key );
            exit;
        }
    }

    /**
     * Fire on save_post for enabled post types.
     */
    public function on_save_post( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( 'publish' !== $post->post_status ) {
            return;
        }

        $enabled_types = get_option( 'rsseo_indexnow_post_types', array( 'post', 'page', 'mfc_location' ) );
        if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
            return;
        }

        $url = get_permalink( $post_id );
        if ( $url ) {
            $this->ping_single( $url );
        }
    }

    /**
     * Ping a single URL to IndexNow + Rapid URL Indexer.
     */
    public function ping_single( $url ) {
        if ( get_option( 'rsseo_indexnow_enabled' ) ) {
            $this->ping_indexnow( $url );
        }
        if ( get_option( 'rsseo_rui_enabled' ) ) {
            $this->ping_rui( array( $url ) );
        }
    }

    /**
     * Batch ping multiple URLs.
     *
     * @param array $urls
     */
    public function ping_batch( $urls ) {
        $urls = array_filter( (array) $urls );
        if ( empty( $urls ) ) {
            return;
        }

        if ( get_option( 'rsseo_indexnow_enabled' ) ) {
            // IndexNow supports batch via JSON body.
            $this->ping_indexnow_batch( $urls );
        }
        if ( get_option( 'rsseo_rui_enabled' ) ) {
            $this->ping_rui( $urls );
        }
    }

    /**
     * Submit single URL to IndexNow.
     */
    private function ping_indexnow( $url ) {
        $api_key = get_option( 'rsseo_indexnow_api_key', '' );
        if ( ! $api_key ) {
            return false;
        }

        $endpoint = add_query_arg( array(
            'url'     => rawurlencode( $url ),
            'key'     => $api_key,
            'keyLocation' => rawurlencode( home_url( '/' . $api_key . '.txt' ) ),
        ), self::INDEXNOW_ENDPOINT );

        $response = wp_remote_get( $endpoint, array( 'timeout' => 10 ) );
        $code     = wp_remote_retrieve_response_code( $response );

        $this->log( $url, 'indexnow', $code );

        return in_array( (int) $code, array( 200, 202 ), true );
    }

    /**
     * Submit batch of URLs to IndexNow JSON endpoint.
     */
    private function ping_indexnow_batch( $urls ) {
        $api_key = get_option( 'rsseo_indexnow_api_key', '' );
        if ( ! $api_key ) {
            return;
        }

        $host = wp_parse_url( home_url(), PHP_URL_HOST );

        $response = wp_remote_post( self::INDEXNOW_ENDPOINT, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'host'        => $host,
                'key'         => $api_key,
                'keyLocation' => home_url( '/' . $api_key . '.txt' ),
                'urlList'     => array_values( $urls ),
            ) ),
            'timeout' => 15,
        ) );

        $code = wp_remote_retrieve_response_code( $response );
        $this->log( implode( ', ', array_slice( $urls, 0, 3 ) ) . '...', 'indexnow_batch', $code );
    }

    /**
     * Submit URLs to Rapid URL Indexer.
     */
    private function ping_rui( $urls ) {
        $api_key = get_option( 'rsseo_rui_api_key', '' );
        if ( ! $api_key ) {
            return;
        }

        $response = wp_remote_post( self::RUI_ENDPOINT, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'urls' => array_values( $urls ) ) ),
            'timeout' => 15,
        ) );

        $code = wp_remote_retrieve_response_code( $response );
        $this->log( implode( ', ', array_slice( $urls, 0, 3 ) ) . '...', 'rapid_url_indexer', $code );
    }

    private function log( $url, $service, $code ) {
        $logs   = get_option( 'rsseo_indexnow_logs', array() );
        $logs[] = array(
            'time'    => current_time( 'mysql' ),
            'url'     => substr( $url, 0, 200 ),
            'service' => $service,
            'code'    => $code,
        );
        // Keep last 100 log entries.
        if ( count( $logs ) > 100 ) {
            $logs = array_slice( $logs, -100 );
        }
        update_option( 'rsseo_indexnow_logs', $logs );
    }

    public function render_page() {
        $api_key    = get_option( 'rsseo_indexnow_api_key', '' );
        $enabled    = get_option( 'rsseo_indexnow_enabled', 0 );
        $rui_key    = get_option( 'rsseo_rui_api_key', '' );
        $rui_enabled = get_option( 'rsseo_rui_enabled', 0 );
        $post_types = get_option( 'rsseo_indexnow_post_types', array( 'post', 'page', 'mfc_location' ) );
        $logs       = array_reverse( get_option( 'rsseo_indexnow_logs', array() ) );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved       = isset( $_GET['saved'] );
        $test_result = isset( $_GET['test_result'] ) ? sanitize_key( $_GET['test_result'] ) : '';
        // phpcs:enable

        $all_post_types = get_post_types( array( 'public' => true ), 'objects' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'IndexNow + Rapid URL Indexer', 'real-smart-seo-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Dual indexing layer: pings IndexNow (Bing, Yandex, others) and Rapid URL Indexer on every publish/update. No more waiting weeks for Googlebot to rediscover pages.', 'real-smart-seo-pro' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>
            <?php if ( 'ok' === $test_result ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'IndexNow ping successful. Your homepage URL was submitted.', 'real-smart-seo-pro' ); ?></p></div>
            <?php elseif ( 'fail' === $test_result ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'IndexNow ping failed. Check your API key and that the key file is accessible.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'rsseo_save_indexnow', '_rsseo_indexnow_nonce' ); ?>

                <h2><?php esc_html_e( 'IndexNow', 'real-smart-seo-pro' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable IndexNow', 'real-smart-seo-pro' ); ?></th>
                        <td><label><input type="checkbox" name="indexnow_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Submit URLs to IndexNow on publish', 'real-smart-seo-pro' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="indexnow_api_key"><?php esc_html_e( 'IndexNow API Key', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="indexnow_api_key" name="indexnow_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>">
                            <p class="description">
                                <?php esc_html_e( 'Any random 32-128 char alphanumeric string. Generate one at', 'real-smart-seo-pro' ); ?>
                                <a href="https://www.indexnow.org/documentation" target="_blank">indexnow.org</a>.
                            </p>
                            <?php if ( $api_key ) : ?>
                                <p class="description">
                                    <?php esc_html_e( 'Key file served at:', 'real-smart-seo-pro' ); ?>
                                    <code><?php echo esc_html( home_url( '/' . $api_key . '.txt' ) ); ?></code>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Rapid URL Indexer', 'real-smart-seo-pro' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable Rapid URL Indexer', 'real-smart-seo-pro' ); ?></th>
                        <td><label><input type="checkbox" name="rui_enabled" value="1" <?php checked( $rui_enabled ); ?>> <?php esc_html_e( 'Also submit to Rapid URL Indexer', 'real-smart-seo-pro' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="rui_api_key"><?php esc_html_e( 'Rapid URL Indexer API Key', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <input type="password" id="rui_api_key" name="rui_api_key" class="regular-text" value="<?php echo esc_attr( $rui_key ); ?>">
                            <p class="description"><?php esc_html_e( 'Get your API key from rapidurlindexer.com — free plan available.', 'real-smart-seo-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Post Types to Track', 'real-smart-seo-pro' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Auto-submit on publish', 'real-smart-seo-pro' ); ?></th>
                        <td>
                            <?php foreach ( $all_post_types as $pt ) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="indexnow_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $post_types, true ) ); ?>>
                                    <?php echo esc_html( $pt->label ); ?> <code style="font-size:11px;">(<?php echo esc_html( $pt->name ); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="rsseo_save_indexnow" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'real-smart-seo-pro' ); ?></button>
                </p>
            </form>

            <h2><?php esc_html_e( 'Test IndexNow Ping', 'real-smart-seo-pro' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'rsseo_save_indexnow', '_rsseo_indexnow_nonce' ); ?>
                <p>
                    <button type="submit" name="rsseo_test_indexnow" value="1" class="button"><?php esc_html_e( 'Ping Homepage Now', 'real-smart-seo-pro' ); ?></button>
                    <span class="description" style="margin-left:8px;"><?php esc_html_e( 'Submits your homepage URL to IndexNow — confirms key file is accessible.', 'real-smart-seo-pro' ); ?></span>
                </p>
            </form>

            <?php if ( $logs ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Submission Log (last 100)', 'real-smart-seo-pro' ); ?></h2>
                <table class="wp-list-table widefat fixed striped" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th style="width:160px;"><?php esc_html_e( 'Time', 'real-smart-seo-pro' ); ?></th>
                            <th><?php esc_html_e( 'URL', 'real-smart-seo-pro' ); ?></th>
                            <th style="width:160px;"><?php esc_html_e( 'Service', 'real-smart-seo-pro' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'HTTP', 'real-smart-seo-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( array_slice( $logs, 0, 30 ) as $entry ) :
                            $ok = in_array( (int) ( $entry['code'] ?? 0 ), array( 200, 202 ), true );
                        ?>
                            <tr>
                                <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                <td style="word-break:break-all;"><?php echo esc_html( $entry['url'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $entry['service'] ?? '' ); ?></td>
                                <td style="color:<?php echo $ok ? '#46b450' : '#dc3232'; ?>"><strong><?php echo esc_html( $entry['code'] ?? '—' ); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

RSSEO_Pro_IndexNow::get_instance();
