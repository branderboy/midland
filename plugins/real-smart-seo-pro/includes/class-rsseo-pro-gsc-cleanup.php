<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GSC Cleanup — kill URL bloat.
 * Upload Pages.csv or Coverage CSV from Google Search Console.
 * Categorizes junk URLs (attachment pages, query strings, paginated archives)
 * and generates bulk-action directives: noindex meta, robots.txt blocks, redirect rules.
 */
class RSSEO_Pro_GSC_Cleanup {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 27 );
        add_action( 'admin_init', array( $this, 'handle_upload' ) );
        add_action( 'admin_init', array( $this, 'handle_apply_fixes' ) );
        // Noindex attachment pages + query-string URLs.
        add_action( 'wp_head',    array( $this, 'output_noindex_rules' ), 1 );
        // Block attachment pages from being linked (WP default redirect).
        add_filter( 'redirect_canonical', array( $this, 'maybe_410_attachment' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'rsseo-pro',
            esc_html__( 'GSC Cleanup', 'real-smart-seo-pro' ),
            esc_html__( 'GSC Cleanup', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-gsc-cleanup',
            array( $this, 'render_page' )
        );
    }

    /**
     * Handle CSV upload and parse.
     */
    public function handle_upload() {
        if ( ! isset( $_POST['rsseo_upload_gsc'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_rsseo_gsc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_gsc_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_gsc_upload' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        if ( empty( $_FILES['gsc_csv']['tmp_name'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-gsc-cleanup&error=no_file' ) );
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $tmp  = $_FILES['gsc_csv']['tmp_name'];
        $ext  = strtolower( pathinfo( sanitize_file_name( wp_unslash( $_FILES['gsc_csv']['name'] ?? '' ) ), PATHINFO_EXTENSION ) );

        if ( 'csv' !== $ext ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-gsc-cleanup&error=not_csv' ) );
            exit;
        }

        $rows    = array();
        $handle  = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
        if ( ! $handle ) {
            wp_safe_redirect( admin_url( 'admin.php?page=rsseo-gsc-cleanup&error=read_fail' ) );
            exit;
        }

        $header = null;
        while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgetcsv
            if ( ! $header ) {
                $header = array_map( 'strtolower', array_map( 'trim', $row ) );
                continue;
            }
            if ( count( $row ) < 1 ) {
                continue;
            }
            $mapped = array_combine( $header, array_pad( $row, count( $header ), '' ) );
            $rows[] = $mapped;
        }
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

        $analysis = $this->analyze( $rows );
        update_option( 'rsseo_gsc_analysis', $analysis );
        update_option( 'rsseo_gsc_uploaded_at', current_time( 'mysql' ) );

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-gsc-cleanup&analyzed=1' ) );
        exit;
    }

    /**
     * Categorize URLs into junk types.
     *
     * @param array $rows Parsed CSV rows.
     * @return array {
     *   attachment_pages  array
     *   query_string_urls array
     *   paginated_urls    array
     *   feed_urls         array
     *   not_found_urls    array
     *   robots_blocked    array
     *   real_content      array
     *   summary           array
     * }
     */
    private function analyze( $rows ) {
        $result = array(
            'attachment_pages'  => array(),
            'query_string_urls' => array(),
            'paginated_urls'    => array(),
            'feed_urls'         => array(),
            'not_found_urls'    => array(),
            'robots_blocked'    => array(),
            'real_content'      => array(),
        );

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        foreach ( $rows as $row ) {
            // Support GSC Pages CSV (top pages) and Coverage CSV (different column names).
            $url    = trim( $row['top pages'] ?? $row['url'] ?? $row['page'] ?? '' );
            $clicks = (int) ( $row['clicks'] ?? 0 );
            $status = strtolower( trim( $row['coverage state'] ?? $row['indexing status'] ?? '' ) );

            if ( empty( $url ) ) {
                continue;
            }

            // Normalize: only work with same-site URLs.
            $parsed = wp_parse_url( $url );
            if ( ! empty( $parsed['host'] ) && $parsed['host'] !== $site_host ) {
                continue;
            }

            $path = $parsed['path'] ?? '/';
            $qs   = $parsed['query'] ?? '';

            // Not found / 404.
            if ( str_contains( $status, 'not found' ) || str_contains( $status, '404' ) ) {
                $result['not_found_urls'][] = $url;
                continue;
            }

            // Robots blocked.
            if ( str_contains( $status, 'blocked by robots' ) ) {
                $result['robots_blocked'][] = $url;
                continue;
            }

            // WP attachment pages: /year/month/title/attachment-slug or just check for /attachment: path.
            if ( preg_match( '#/[^/]+/attachment/[^/]+/?$#', $path ) ) {
                $result['attachment_pages'][] = $url;
                continue;
            }

            // Query string URLs.
            if ( ! empty( $qs ) ) {
                $result['query_string_urls'][] = $url;
                continue;
            }

            // Paginated archives.
            if ( preg_match( '#/page/\d+/?$#', $path ) ) {
                $result['paginated_urls'][] = $url;
                continue;
            }

            // Feed URLs.
            if ( preg_match( '#/(feed|rss|rss2|atom)/?$#', $path ) ) {
                $result['feed_urls'][] = $url;
                continue;
            }

            // Author archive, tag, category (with low/no clicks = bloat).
            if ( preg_match( '#^/(author|tag|category)/[^/]+/?$#', $path ) && $clicks < 5 ) {
                $result['paginated_urls'][] = $url;
                continue;
            }

            $result['real_content'][] = $url;
        }

        $result['summary'] = array(
            'total'            => count( $rows ),
            'junk_total'       => count( $result['attachment_pages'] ) + count( $result['query_string_urls'] ) + count( $result['paginated_urls'] ) + count( $result['feed_urls'] ),
            'not_found'        => count( $result['not_found_urls'] ),
            'robots_blocked'   => count( $result['robots_blocked'] ),
            'real_content'     => count( $result['real_content'] ),
        );

        return $result;
    }

    /**
     * Apply the selected fixes (save directives to options — actual file edits are separate).
     */
    public function handle_apply_fixes() {
        if ( ! isset( $_POST['rsseo_apply_gsc_fixes'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_rsseo_gsc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_gsc_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_gsc_upload' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $fixes = array(
            'noindex_attachments'    => isset( $_POST['fix_noindex_attachments'] ),
            'noindex_query_strings'  => isset( $_POST['fix_noindex_query_strings'] ),
            'noindex_pagination'     => isset( $_POST['fix_noindex_pagination'] ),
            'noindex_feeds'          => isset( $_POST['fix_noindex_feeds'] ),
            'block_attachments_robots' => isset( $_POST['fix_block_attachments_robots'] ),
        );

        update_option( 'rsseo_gsc_fixes', $fixes );

        // Generate robots.txt additions.
        if ( $fixes['block_attachments_robots'] ) {
            $this->append_robots_txt_block();
        }

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-gsc-cleanup&fixes_applied=1' ) );
        exit;
    }

    /**
     * Add noindex meta for junk URL types based on active fixes.
     */
    public function output_noindex_rules() {
        $fixes = get_option( 'rsseo_gsc_fixes', array() );

        if ( ! empty( $fixes['noindex_attachments'] ) && is_attachment() ) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        if ( ! empty( $fixes['noindex_query_strings'] ) && ! empty( $_SERVER['QUERY_STRING'] ) ) {
            echo '<meta name="robots" content="noindex, follow">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        if ( ! empty( $fixes['noindex_pagination'] ) && is_paged() ) {
            echo '<meta name="robots" content="noindex, follow">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        if ( ! empty( $fixes['noindex_feeds'] ) && is_feed() ) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }
    }

    /**
     * 410 Gone for direct attachment page hits (optional — only when enabled).
     */
    public function maybe_410_attachment( $redirect ) {
        $fixes = get_option( 'rsseo_gsc_fixes', array() );
        if ( ! empty( $fixes['noindex_attachments'] ) && is_attachment() ) {
            status_header( 410 );
            return false;
        }
        return $redirect;
    }

    /**
     * Append attachment disallow block to robots.txt (WP virtual robots).
     */
    private function append_robots_txt_block() {
        $existing = get_option( 'rsseo_gsc_robots_block', '' );
        if ( $existing ) {
            return; // Already added.
        }

        $block = "\n# GSC Cleanup — attachment pages\nDisallow: /*/attachment/\n";
        update_option( 'rsseo_gsc_robots_block', $block );

        // Hook into WP's virtual robots.txt.
        add_filter( 'robots_txt', function( $output ) use ( $block ) {
            return $output . $block;
        } );
    }

    public function render_page() {
        $analysis     = get_option( 'rsseo_gsc_analysis', array() );
        $fixes        = get_option( 'rsseo_gsc_fixes', array() );
        $uploaded_at  = get_option( 'rsseo_gsc_uploaded_at', '' );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $analyzed      = isset( $_GET['analyzed'] );
        $fixes_applied = isset( $_GET['fixes_applied'] );
        $error         = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
        // phpcs:enable
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'GSC Cleanup — Kill URL Bloat', 'real-smart-seo-pro' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Upload your GSC Pages or Coverage CSV to categorize junk URLs and generate fixes. Bloated URL pools dilute crawl budget and keep real pages from getting indexed.', 'real-smart-seo-pro' ); ?>
            </p>

            <?php if ( $analyzed ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'CSV analyzed. Review categories below.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $fixes_applied ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Fixes applied. Noindex rules are now live.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $this->error_label( $error ) ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Upload GSC Export', 'real-smart-seo-pro' ); ?></h2>
            <p class="description"><?php esc_html_e( 'In GSC: Performance → Pages → Export CSV  OR  Index → Coverage → Export CSV.', 'real-smart-seo-pro' ); ?></p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'rsseo_gsc_upload', '_rsseo_gsc_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="gsc_csv"><?php esc_html_e( 'GSC CSV File', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <input type="file" id="gsc_csv" name="gsc_csv" accept=".csv">
                            <?php if ( $uploaded_at ) : ?>
                                <p class="description"><?php printf( esc_html__( 'Last uploaded: %s', 'real-smart-seo-pro' ), esc_html( $uploaded_at ) ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="rsseo_upload_gsc" value="1" class="button button-primary"><?php esc_html_e( 'Analyze CSV', 'real-smart-seo-pro' ); ?></button></p>
            </form>

            <?php if ( ! empty( $analysis['summary'] ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'URL Audit Results', 'real-smart-seo-pro' ); ?></h2>

                <table class="widefat" style="max-width:600px;margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Category', 'real-smart-seo-pro' ); ?></th>
                            <th><?php esc_html_e( 'Count', 'real-smart-seo-pro' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'real-smart-seo-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php esc_html_e( 'Total URLs in export', 'real-smart-seo-pro' ); ?></td>
                            <td><strong><?php echo esc_html( $analysis['summary']['total'] ); ?></strong></td>
                            <td>—</td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Real content pages', 'real-smart-seo-pro' ); ?></td>
                            <td><strong style="color:#46b450;"><?php echo esc_html( $analysis['summary']['real_content'] ); ?></strong></td>
                            <td><?php esc_html_e( 'Keep as-is', 'real-smart-seo-pro' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Attachment pages', 'real-smart-seo-pro' ); ?></td>
                            <td><strong style="color:#dc3232;"><?php echo esc_html( count( $analysis['attachment_pages'] ?? array() ) ); ?></strong></td>
                            <td><?php esc_html_e( 'Noindex + 410', 'real-smart-seo-pro' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Query string URLs', 'real-smart-seo-pro' ); ?></td>
                            <td><strong style="color:#dc3232;"><?php echo esc_html( count( $analysis['query_string_urls'] ?? array() ) ); ?></strong></td>
                            <td><?php esc_html_e( 'Noindex', 'real-smart-seo-pro' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Paginated / archive URLs', 'real-smart-seo-pro' ); ?></td>
                            <td><strong style="color:#f56e28;"><?php echo esc_html( count( $analysis['paginated_urls'] ?? array() ) ); ?></strong></td>
                            <td><?php esc_html_e( 'Noindex', 'real-smart-seo-pro' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Feed URLs', 'real-smart-seo-pro' ); ?></td>
                            <td><strong><?php echo esc_html( count( $analysis['feed_urls'] ?? array() ) ); ?></strong></td>
                            <td><?php esc_html_e( 'Noindex', 'real-smart-seo-pro' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Not found (404)', 'real-smart-seo-pro' ); ?></td>
                            <td><strong style="color:#dc3232;"><?php echo esc_html( $analysis['summary']['not_found'] ); ?></strong></td>
                            <td><?php esc_html_e( 'Review manually', 'real-smart-seo-pro' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Robots-blocked (verify intentional)', 'real-smart-seo-pro' ); ?></td>
                            <td><strong><?php echo esc_html( $analysis['summary']['robots_blocked'] ); ?></strong></td>
                            <td><?php esc_html_e( 'Verify', 'real-smart-seo-pro' ); ?></td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e( 'Apply Fixes', 'real-smart-seo-pro' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'rsseo_gsc_upload', '_rsseo_gsc_nonce' ); ?>
                    <table class="form-table">
                        <?php
                        $fix_options = array(
                            'fix_noindex_attachments'    => array(
                                __( 'Noindex + 410 attachment pages', 'real-smart-seo-pro' ),
                                __( 'Sends HTTP 410 Gone for WP attachment URLs — signals to Google they are permanently removed.', 'real-smart-seo-pro' ),
                                'noindex_attachments',
                            ),
                            'fix_noindex_query_strings'  => array(
                                __( 'Noindex query-string URLs', 'real-smart-seo-pro' ),
                                __( 'Adds noindex meta to any page request with ?query=string parameters.', 'real-smart-seo-pro' ),
                                'noindex_query_strings',
                            ),
                            'fix_noindex_pagination'     => array(
                                __( 'Noindex paginated / archive pages', 'real-smart-seo-pro' ),
                                __( 'Adds noindex to /page/2/, /page/3/, category archives with low traffic.', 'real-smart-seo-pro' ),
                                'noindex_pagination',
                            ),
                            'fix_noindex_feeds'          => array(
                                __( 'Noindex feed URLs', 'real-smart-seo-pro' ),
                                __( 'Blocks /feed/, /rss/, /atom/ URLs from being indexed.', 'real-smart-seo-pro' ),
                                'noindex_feeds',
                            ),
                            'fix_block_attachments_robots' => array(
                                __( 'Block /*/attachment/ in robots.txt', 'real-smart-seo-pro' ),
                                __( 'Adds Disallow: /*/attachment/ to the virtual robots.txt — stops Googlebot from crawling them at all.', 'real-smart-seo-pro' ),
                                'block_attachments_robots',
                            ),
                        );
                        foreach ( $fix_options as $name => $info ) :
                            $checked = ! empty( $fixes[ $info[2] ] );
                            ?>
                            <tr>
                                <th><?php echo esc_html( $info[0] ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
                                        <span class="description"><?php echo esc_html( $info[1] ); ?></span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <p class="submit"><button type="submit" name="rsseo_apply_gsc_fixes" value="1" class="button button-primary"><?php esc_html_e( 'Apply Selected Fixes', 'real-smart-seo-pro' ); ?></button></p>
                </form>

                <?php if ( ! empty( $analysis['not_found_urls'] ) ) : ?>
                    <hr>
                    <h3><?php esc_html_e( '404 URLs — Review for Redirects', 'real-smart-seo-pro' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'If any of these had real content, set up 301 redirects. Otherwise they will naturally drop from the index.', 'real-smart-seo-pro' ); ?></p>
                    <textarea readonly rows="8" class="large-text" style="font-family:monospace;font-size:12px;"><?php echo esc_textarea( implode( "\n", $analysis['not_found_urls'] ) ); ?></textarea>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function error_label( $error ) {
        $labels = array(
            'no_file'   => __( 'No file selected.', 'real-smart-seo-pro' ),
            'not_csv'   => __( 'File must be a .csv export from Google Search Console.', 'real-smart-seo-pro' ),
            'read_fail' => __( 'Could not read the uploaded file.', 'real-smart-seo-pro' ),
        );
        return $labels[ $error ] ?? __( 'Unknown error.', 'real-smart-seo-pro' );
    }
}

RSSEO_Pro_GSC_Cleanup::get_instance();
