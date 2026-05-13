<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Site Speed Overhaul.
 * Front-end performance toggles + PageSpeed Insights score check.
 * Settings: Midland Smart SEO Pro > Site Speed
 */
class RSSEO_Pro_Speed {

    const PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    const OPT_DEFER_SCRIPTS    = 'rsseo_speed_defer_scripts';
    const OPT_DISABLE_EMOJIS   = 'rsseo_speed_disable_emojis';
    const OPT_DISABLE_EMBEDS   = 'rsseo_speed_disable_embeds';
    const OPT_REMOVE_MIGRATE   = 'rsseo_speed_remove_jquery_migrate';
    const OPT_PRECONNECT       = 'rsseo_speed_preconnect';
    const OPT_LAZY_IFRAMES     = 'rsseo_speed_lazy_iframes';
    const OPT_FETCHPRIORITY    = 'rsseo_speed_fetchpriority_first_image';
    const OPT_PSI_API_KEY      = 'rsseo_speed_psi_api_key';
    const OPT_PSI_LAST_RESULT  = 'rsseo_speed_psi_last_result';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 33 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_init', array( $this, 'handle_psi_run' ) );

        // Apply front-end optimizations once WP is ready.
        add_action( 'init', array( $this, 'apply_optimizations' ), 5 );
    }

    public function add_menu() {
        add_submenu_page(
            null,
            esc_html__( 'Site Speed', 'real-smart-seo-pro' ),
            esc_html__( 'Site Speed', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-speed',
            array( $this, 'render_page' )
        );
    }

    public function apply_optimizations() {
        if ( is_admin() ) {
            return;
        }

        if ( get_option( self::OPT_DEFER_SCRIPTS, 0 ) ) {
            add_filter( 'script_loader_tag', array( $this, 'defer_script' ), 10, 2 );
        }

        if ( get_option( self::OPT_DISABLE_EMOJIS, 0 ) ) {
            remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
            remove_action( 'wp_print_styles', 'print_emoji_styles' );
            remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
            remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
            remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
        }

        if ( get_option( self::OPT_DISABLE_EMBEDS, 0 ) ) {
            add_action( 'wp_footer', array( $this, 'dequeue_embed' ), 1 );
            remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
            remove_action( 'wp_head', 'wp_oembed_add_host_js' );
        }

        if ( get_option( self::OPT_REMOVE_MIGRATE, 0 ) ) {
            add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
        }

        if ( get_option( self::OPT_PRECONNECT, 0 ) ) {
            add_filter( 'wp_resource_hints', array( $this, 'add_preconnect_hints' ), 10, 2 );
        }

        if ( get_option( self::OPT_LAZY_IFRAMES, 0 ) ) {
            add_filter( 'embed_oembed_html', array( $this, 'lazy_iframe' ), 99 );
            add_filter( 'the_content', array( $this, 'lazy_iframe' ), 99 );
        }

        if ( get_option( self::OPT_FETCHPRIORITY, 0 ) ) {
            add_filter( 'wp_get_attachment_image_attributes', array( $this, 'first_image_priority' ), 10, 1 );
        }
    }

    public function defer_script( $tag, $handle ) {
        if ( is_admin() ) {
            return $tag;
        }
        // Critical handles must NOT be deferred.
        $skip = apply_filters( 'rsseo_speed_defer_skip', array( 'jquery-core', 'jquery' ) );
        if ( in_array( $handle, $skip, true ) ) {
            return $tag;
        }
        if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
            return $tag;
        }
        return str_replace( ' src=', ' defer src=', $tag );
    }

    public function dequeue_embed() {
        wp_dequeue_script( 'wp-embed' );
    }

    public function remove_jquery_migrate( $scripts ) {
        if ( ! empty( $scripts->registered['jquery'] ) ) {
            $deps = $scripts->registered['jquery']->deps;
            $scripts->registered['jquery']->deps = array_values( array_diff( $deps, array( 'jquery-migrate' ) ) );
        }
    }

    public function add_preconnect_hints( $urls, $relation_type ) {
        if ( 'preconnect' !== $relation_type ) {
            return $urls;
        }
        $extra = apply_filters( 'rsseo_speed_preconnect_hosts', array(
            array( 'href' => 'https://fonts.googleapis.com', 'crossorigin' => '' ),
            array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => '' ),
        ) );
        return array_merge( $urls, $extra );
    }

    public function lazy_iframe( $html ) {
        if ( false === strpos( $html, '<iframe' ) ) {
            return $html;
        }
        // Add loading="lazy" only when not already present.
        return preg_replace_callback( '/<iframe(?![^>]*loading=)([^>]*)>/i', function( $m ) {
            return '<iframe loading="lazy"' . $m[1] . '>';
        }, $html );
    }

    private $first_image_seen = false;

    public function first_image_priority( $attr ) {
        if ( $this->first_image_seen ) {
            return $attr;
        }
        $this->first_image_seen = true;
        $attr['fetchpriority'] = 'high';
        // First image should not lazy-load.
        if ( isset( $attr['loading'] ) ) {
            unset( $attr['loading'] );
        }
        return $attr;
    }

    public function handle_save() {
        if ( ! isset( $_POST['rsseo_save_speed'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_rsseo_speed_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_speed_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_save_speed' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $toggles = array(
            self::OPT_DEFER_SCRIPTS,
            self::OPT_DISABLE_EMOJIS,
            self::OPT_DISABLE_EMBEDS,
            self::OPT_REMOVE_MIGRATE,
            self::OPT_PRECONNECT,
            self::OPT_LAZY_IFRAMES,
            self::OPT_FETCHPRIORITY,
        );
        foreach ( $toggles as $opt ) {
            update_option( $opt, isset( $_POST[ $opt ] ) ? 1 : 0 );
        }

        if ( isset( $_POST[ self::OPT_PSI_API_KEY ] ) ) {
            update_option( self::OPT_PSI_API_KEY, sanitize_text_field( wp_unslash( $_POST[ self::OPT_PSI_API_KEY ] ) ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-speed&saved=1' ) );
        exit;
    }

    public function handle_psi_run() {
        if ( ! isset( $_GET['rsseo_psi_run'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_psi_run' ) ) {
            return;
        }

        $url     = home_url( '/' );
        $api_key = get_option( self::OPT_PSI_API_KEY, '' );
        $strategies = array( 'mobile', 'desktop' );
        $results = array( 'url' => $url, 'fetched_at' => time(), 'strategies' => array() );

        foreach ( $strategies as $strategy ) {
            $args = array(
                'url'      => $url,
                'strategy' => $strategy,
                'category' => 'performance',
            );
            if ( $api_key ) {
                $args['key'] = $api_key;
            }
            $endpoint = add_query_arg( $args, self::PSI_ENDPOINT );
            $response = wp_remote_get( $endpoint, array( 'timeout' => 60 ) );

            if ( is_wp_error( $response ) ) {
                $results['strategies'][ $strategy ] = array( 'error' => $response->get_error_message() );
                continue;
            }

            $body  = json_decode( wp_remote_retrieve_body( $response ), true );
            $score = $body['lighthouseResult']['categories']['performance']['score'] ?? null;
            $audits = $body['lighthouseResult']['audits'] ?? array();

            $results['strategies'][ $strategy ] = array(
                'score' => is_numeric( $score ) ? (int) round( $score * 100 ) : null,
                'lcp'   => $audits['largest-contentful-paint']['displayValue'] ?? '',
                'cls'   => $audits['cumulative-layout-shift']['displayValue'] ?? '',
                'tbt'   => $audits['total-blocking-time']['displayValue'] ?? '',
                'fcp'   => $audits['first-contentful-paint']['displayValue'] ?? '',
                'si'    => $audits['speed-index']['displayValue'] ?? '',
            );
        }

        update_option( self::OPT_PSI_LAST_RESULT, $results );
        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-speed&psi=1' ) );
        exit;
    }

    public function render_page() {
        $toggles = array(
            self::OPT_DEFER_SCRIPTS  => array( __( 'Defer non-critical scripts', 'real-smart-seo-pro' ), __( 'Add defer attribute to all enqueued scripts (jQuery is excluded automatically).', 'real-smart-seo-pro' ) ),
            self::OPT_DISABLE_EMOJIS => array( __( 'Remove WP emoji scripts', 'real-smart-seo-pro' ), __( 'Strips wp-emoji JS/CSS from the front end.', 'real-smart-seo-pro' ) ),
            self::OPT_DISABLE_EMBEDS => array( __( 'Disable wp-embed', 'real-smart-seo-pro' ), __( 'Removes the embed.min.js script and oEmbed discovery links.', 'real-smart-seo-pro' ) ),
            self::OPT_REMOVE_MIGRATE => array( __( 'Remove jQuery Migrate', 'real-smart-seo-pro' ), __( 'Drops the jquery-migrate dependency. Verify your theme/plugins are compatible.', 'real-smart-seo-pro' ) ),
            self::OPT_PRECONNECT     => array( __( 'Preconnect to fonts CDN', 'real-smart-seo-pro' ), __( 'Adds preconnect hints for Google Fonts.', 'real-smart-seo-pro' ) ),
            self::OPT_LAZY_IFRAMES   => array( __( 'Lazy-load iframes', 'real-smart-seo-pro' ), __( 'Adds loading="lazy" to embedded iframes.', 'real-smart-seo-pro' ) ),
            self::OPT_FETCHPRIORITY  => array( __( 'Prioritize first image (LCP)', 'real-smart-seo-pro' ), __( 'Adds fetchpriority="high" to the first image and disables its lazy-load.', 'real-smart-seo-pro' ) ),
        );

        $api_key = get_option( self::OPT_PSI_API_KEY, '' );
        $last    = get_option( self::OPT_PSI_LAST_RESULT, array() );
        $psi_url = wp_nonce_url( admin_url( 'admin.php?page=rsseo-speed&rsseo_psi_run=1' ), 'rsseo_psi_run' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Site Speed Overhaul', 'real-smart-seo-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Toggle front-end optimizations and check your PageSpeed score.', 'real-smart-seo-pro' ); ?></p>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Speed settings saved.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['psi'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'PageSpeed test complete. Scroll down for results.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'rsseo_save_speed', '_rsseo_speed_nonce' ); ?>

                <h2><?php esc_html_e( 'Front-end Optimizations', 'real-smart-seo-pro' ); ?></h2>
                <table class="form-table">
                    <?php foreach ( $toggles as $opt => $info ) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( $info[0] ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( $opt ); ?>" value="1" <?php checked( get_option( $opt, 0 ) ); ?>>
                                    <?php esc_html_e( 'Enabled', 'real-smart-seo-pro' ); ?>
                                </label>
                                <p class="description"><?php echo esc_html( $info[1] ); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2><?php esc_html_e( 'PageSpeed Insights', 'real-smart-seo-pro' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="<?php echo esc_attr( self::OPT_PSI_API_KEY ); ?>"><?php esc_html_e( 'PSI API Key (optional)', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <input type="password" id="<?php echo esc_attr( self::OPT_PSI_API_KEY ); ?>" name="<?php echo esc_attr( self::OPT_PSI_API_KEY ); ?>" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>">
                            <p class="description"><?php esc_html_e( 'Higher quota with a key. Get one in Google Cloud Console (PageSpeed Insights API).', 'real-smart-seo-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="rsseo_save_speed" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'real-smart-seo-pro' ); ?></button>
                    <a href="<?php echo esc_url( $psi_url ); ?>" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Run PageSpeed Test', 'real-smart-seo-pro' ); ?></a>
                </p>
            </form>

            <?php if ( ! empty( $last['strategies'] ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Last PageSpeed Result', 'real-smart-seo-pro' ); ?></h2>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: tested URL, 2: timestamp */
                        esc_html__( 'URL: %1$s — fetched %2$s', 'real-smart-seo-pro' ),
                        '<code>' . esc_html( $last['url'] ?? '' ) . '</code>',
                        ! empty( $last['fetched_at'] ) ? esc_html( wp_date( 'Y-m-d H:i', (int) $last['fetched_at'] ) ) : ''
                    );
                    ?>
                </p>
                <table class="widefat striped" style="max-width:780px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Strategy', 'real-smart-seo-pro' ); ?></th>
                            <th><?php esc_html_e( 'Score', 'real-smart-seo-pro' ); ?></th>
                            <th>LCP</th>
                            <th>CLS</th>
                            <th>TBT</th>
                            <th>FCP</th>
                            <th>Speed Index</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $last['strategies'] as $strategy => $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( ucfirst( $strategy ) ); ?></strong></td>
                                <?php if ( isset( $row['error'] ) ) : ?>
                                    <td colspan="6" style="color:#d63638;"><?php echo esc_html( $row['error'] ); ?></td>
                                <?php else :
                                    $score = $row['score'];
                                    $color = '#46b450';
                                    if ( null === $score ) {
                                        $color = '#999';
                                    } elseif ( $score < 50 ) {
                                        $color = '#d63638';
                                    } elseif ( $score < 90 ) {
                                        $color = '#dba617';
                                    }
                                ?>
                                    <td style="color:<?php echo esc_attr( $color ); ?>;font-weight:700;"><?php echo null === $score ? '—' : esc_html( $score ); ?></td>
                                    <td><?php echo esc_html( $row['lcp'] ); ?></td>
                                    <td><?php echo esc_html( $row['cls'] ); ?></td>
                                    <td><?php echo esc_html( $row['tbt'] ); ?></td>
                                    <td><?php echo esc_html( $row['fcp'] ); ?></td>
                                    <td><?php echo esc_html( $row['si'] ); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

RSSEO_Pro_Speed::get_instance();
