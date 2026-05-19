<?php
/**
 * Unified Smart CRM Settings hub.
 *
 * Single sidebar entry — "Smart CRM → Settings" — with a tabbed page
 * that surfaces ActiveCampaign, ServiceM8, Vapi, Google Calendar, and
 * Floor Care Plan settings in one place. Each tab dispatches to the
 * existing module's render_page() so nothing has to be rewritten.
 *
 * Individual integration submenus that used to clutter the sidebar
 * (ActiveCampaign, ServiceM8, Vapi, Floor Care Plans) are hidden via
 * null parent but remain accessible at their original slugs so OAuth
 * callbacks and old bookmarks keep working.
 *
 * Test-connection buttons (one per integration) hit a dedicated AJAX
 * endpoint that pings the provider and reports OK / error inline.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_Settings {

    const PAGE = 'smart-crm';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // No add_submenu_page — the top-level Smart CRM menu (registered by
        // class-admin.php with slug 'smart-crm') points its callback at our
        // render(), so we appear as the single sidebar entry without a
        // duplicate submenu row.
        add_action( 'wp_ajax_scrm_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'admin_init', array( $this, 'redirect_legacy_pages' ), 1 );
    }

    /**
     * Catch the integration modules' legacy URL slugs (scrm-vapi,
     * scrm-pro-activecampaign, etc.) and bounce them to the unified
     * Settings tab. Without this, an old bookmark or a stale install
     * drops the user on a bare orphan page with no tabs or sidebar
     * context. Only redirects GETs — POST save handlers run first
     * (their hooks fire at default priority 10) and we run at 1, but
     * a save POST always wp_safe_redirect()s, so we never see those.
     */
    public function redirect_legacy_pages() {
        if ( ! is_admin() || empty( $_GET['page'] ) ) {
            return;
        }
        if ( ! empty( $_POST ) || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }
        $map = array(
            'scrm-vapi'                 => 'vapi',
            'scrm-pro-activecampaign'   => 'activecampaign',
            'scrm-pro-servicem8'        => 'servicem8',
            'scrm-pro-floor-care-plan'  => 'floorplan',
        );
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
        if ( ! isset( $map[ $page ] ) ) {
            return;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=smart-crm&tab=' . $map[ $page ] ) );
        exit;
    }

    private function tabs(): array {
        return array(
            'activecampaign' => array(
                'label'  => __( 'ActiveCampaign', 'smart-crm-pro' ),
                'render' => function () {
                    if ( class_exists( 'SCRM_Pro_ActiveCampaign' ) ) {
                        SCRM_Pro_ActiveCampaign::get_instance()->render_page();
                    }
                },
                'test'   => 'activecampaign',
            ),
            'servicem8' => array(
                'label'  => __( 'ServiceM8', 'smart-crm-pro' ),
                'render' => function () {
                    if ( class_exists( 'SCRM_Pro_ServiceM8' ) ) {
                        SCRM_Pro_ServiceM8::get_instance()->render_page();
                    }
                },
                'test'   => 'servicem8',
            ),
            'vapi' => array(
                'label'  => __( 'Vapi', 'smart-crm-pro' ),
                'render' => function () {
                    if ( class_exists( 'SCRM_Pro_Vapi' ) ) {
                        SCRM_Pro_Vapi::get_instance()->render_page();
                    }
                },
                'test'   => 'vapi',
            ),
            'gcal' => array(
                'label'  => __( 'Google Calendar', 'smart-crm-pro' ),
                'render' => function () {
                    // GCal connection lives in Smart Forms (cross-plugin)
                    // because the OAuth callback URL points at the
                    // sfco-gcal slug. Surface a small status here with a
                    // deep link rather than re-rendering its full page.
                    echo '<h2>Google Calendar</h2>';
                    if ( class_exists( 'SFCO_Pro_GCal' ) ) {
                        $g  = SFCO_Pro_GCal::get_instance();
                        $ok = $g && $g->is_connected();
                        echo $ok
                            ? '<p style="color:#2F8137;font-weight:700;">✓ Connected</p>'
                            : '<p style="color:#7a1d1d;">Not connected.</p>';
                    }
                    echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=sfco-gcal' ) ) . '">' . esc_html__( 'Manage / OAuth handshake', 'smart-crm-pro' ) . '</a></p>';
                    echo '<p class="description">' . esc_html__( 'Google Calendar lives in Smart Forms because the OAuth callback URL is wired to it. Tentative visit drafts created by Smart CRM are still posted to this calendar.', 'smart-crm-pro' ) . '</p>';
                },
                'test'   => 'gcal',
            ),
            'floorplan' => array(
                'label'  => __( 'Floor Care Plan', 'smart-crm-pro' ),
                'render' => function () {
                    if ( class_exists( 'SCRM_Pro_Floor_Care_Plan' ) ) {
                        SCRM_Pro_Floor_Care_Plan::get_instance()->render_page();
                    }
                },
                'test'   => null,
            ),
        );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $tabs    = $this->tabs();
        // phpcs:ignore WordPress.Security.NonceVerification
        $current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'activecampaign';
        if ( ! isset( $tabs[ $current ] ) ) {
            $current = 'activecampaign';
        }
        $tab_url = function ( $slug ) {
            return add_query_arg( array( 'page' => self::PAGE, 'tab' => $slug ), admin_url( 'admin.php' ) );
        };
        $nonce = wp_create_nonce( 'scrm_test_connection' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart CRM Settings', 'smart-crm-pro' ); ?></h1>
            <h2 class="nav-tab-wrapper">
                <?php foreach ( $tabs as $slug => $tab ) : ?>
                    <a class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab_url( $slug ) ); ?>"><?php echo esc_html( $tab['label'] ); ?></a>
                <?php endforeach; ?>
            </h2>

            <?php if ( ! empty( $tabs[ $current ]['test'] ) ) : ?>
                <div style="background:#fff;border:1px solid #d6e6dc;border-radius:6px;padding:14px 18px;margin:16px 0;display:flex;align-items:center;gap:12px;">
                    <strong style="color:#0F1411;"><?php esc_html_e( 'Test connection', 'smart-crm-pro' ); ?></strong>
                    <button type="button" class="button" data-scrm-test="<?php echo esc_attr( $tabs[ $current ]['test'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Run test', 'smart-crm-pro' ); ?></button>
                    <span class="scrm-test-result" style="font-size:14px;"></span>
                </div>
                <script>
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('[data-scrm-test]');
                    if (!btn) return;
                    var integration = btn.dataset.scrmTest;
                    var nonce       = btn.dataset.nonce;
                    var result      = btn.parentElement.querySelector('.scrm-test-result');
                    result.textContent = 'Testing…';
                    result.style.color = '#6b7280';
                    btn.disabled = true;
                    var fd = new FormData();
                    fd.append('action', 'scrm_test_connection');
                    fd.append('integration', integration);
                    fd.append('_ajax_nonce', nonce);
                    fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            btn.disabled = false;
                            if (json && json.success) {
                                result.textContent = '✓ ' + (json.data.message || 'OK');
                                result.style.color = '#2F8137';
                            } else {
                                result.textContent = '✗ ' + ((json && json.data && json.data.message) || 'Failed');
                                result.style.color = '#7a1d1d';
                            }
                        })
                        .catch(function (err) {
                            btn.disabled = false;
                            result.textContent = '✗ Network error';
                            result.style.color = '#7a1d1d';
                        });
                });
                </script>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #d6e6dc;border-radius:8px;padding:18px 22px;margin-top:8px;">
                <?php call_user_func( $tabs[ $current ]['render'] ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX endpoint behind the "Test connection" buttons. Pings the
     * relevant provider and returns OK / error inline.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'scrm_test_connection' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        $which = isset( $_POST['integration'] ) ? sanitize_key( wp_unslash( $_POST['integration'] ) ) : '';

        switch ( $which ) {
            case 'activecampaign':
                $url = (string) get_option( 'scrm_pro_ac_api_url' );
                $key = (string) get_option( 'scrm_pro_ac_api_key' );
                if ( '' === $url || '' === $key ) {
                    wp_send_json_error( array( 'message' => 'API URL + key not set.' ) );
                }
                $r = wp_remote_get( untrailingslashit( $url ) . '/api/3/users', array(
                    'headers' => array( 'Api-Token' => $key, 'Accept' => 'application/json' ),
                    'timeout' => 12,
                ) );
                if ( is_wp_error( $r ) ) {
                    wp_send_json_error( array( 'message' => $r->get_error_message() ) );
                }
                $code = wp_remote_retrieve_response_code( $r );
                if ( $code >= 200 && $code < 300 ) {
                    wp_send_json_success( array( 'message' => 'ActiveCampaign connected (HTTP ' . $code . ')' ) );
                }
                wp_send_json_error( array( 'message' => 'AC returned HTTP ' . $code ) );

            case 'servicem8':
                $key = (string) get_option( 'scrm_pro_sm8_api_key' );
                if ( '' === $key ) {
                    wp_send_json_error( array( 'message' => 'ServiceM8 API key not set.' ) );
                }
                $r = wp_remote_get( 'https://api.servicem8.com/api_1.0/company.json', array(
                    'headers' => array( 'Authorization' => 'Bearer ' . $key ),
                    'timeout' => 12,
                ) );
                if ( is_wp_error( $r ) ) {
                    wp_send_json_error( array( 'message' => $r->get_error_message() ) );
                }
                $code = wp_remote_retrieve_response_code( $r );
                if ( $code >= 200 && $code < 300 ) {
                    wp_send_json_success( array( 'message' => 'ServiceM8 connected (HTTP ' . $code . ')' ) );
                }
                wp_send_json_error( array( 'message' => 'ServiceM8 returned HTTP ' . $code ) );

            case 'vapi':
                $key = (string) get_option( SCRM_Pro_Vapi::OPT_API_KEY );
                $aid = (string) get_option( SCRM_Pro_Vapi::OPT_ASSISTANT_ID );
                if ( '' === $key ) {
                    wp_send_json_error( array( 'message' => 'Vapi private API key not set.' ) );
                }
                // GET /assistant lists every assistant on the org — works
                // without an ID, so we can verify the key independently of
                // whether the Assistant ID field is filled in correctly.
                $endpoint = '' === $aid
                    ? 'https://api.vapi.ai/assistant'
                    : 'https://api.vapi.ai/assistant/' . rawurlencode( $aid );
                $r = wp_remote_get( $endpoint, array(
                    'headers' => array( 'Authorization' => 'Bearer ' . $key ),
                    'timeout' => 12,
                ) );
                if ( is_wp_error( $r ) ) {
                    wp_send_json_error( array( 'message' => $r->get_error_message() ) );
                }
                $code = wp_remote_retrieve_response_code( $r );
                if ( 401 === (int) $code || 403 === (int) $code ) {
                    wp_send_json_error( array( 'message' => 'Vapi rejected the key (HTTP ' . (int) $code . '). Use the PRIVATE key from Vapi → Settings → API Keys, not the public key.' ) );
                }
                if ( 404 === (int) $code && '' !== $aid ) {
                    wp_send_json_error( array( 'message' => 'Vapi auth OK but Assistant ID not found (HTTP 404). Check the Assistant ID.' ) );
                }
                if ( $code >= 200 && $code < 300 ) {
                    wp_send_json_success( array( 'message' => '' === $aid ? 'Vapi key works (HTTP ' . $code . '). Add an Assistant ID to enable calls.' : 'Vapi assistant reachable (HTTP ' . $code . ')' ) );
                }
                wp_send_json_error( array( 'message' => 'Vapi returned HTTP ' . $code ) );

            case 'gcal':
                if ( ! class_exists( 'SFCO_Pro_GCal' ) ) {
                    wp_send_json_error( array( 'message' => 'Smart Forms Google Calendar module not loaded.' ) );
                }
                $g = SFCO_Pro_GCal::get_instance();
                if ( $g && $g->is_connected() ) {
                    wp_send_json_success( array( 'message' => 'Google Calendar connected.' ) );
                }
                wp_send_json_error( array( 'message' => 'GCal not connected — complete OAuth in Smart Forms.' ) );
        }
        wp_send_json_error( array( 'message' => 'Unknown integration.' ) );
    }
}

SCRM_Pro_Settings::get_instance();
