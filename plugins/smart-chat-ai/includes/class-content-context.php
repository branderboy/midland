<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Site content ingestion for the chat AI.
 *
 * On a schedule (and on demand) it walks the site's sitemap, fetches each page,
 * strips it down to plain text, and caches the chunks in an option. When the
 * AI handler builds a system prompt it asks this class for the chunks most
 * relevant to the current user message and pastes them in as context.
 *
 * No third-party services — sitemap is local, fetches go through wp_remote_get,
 * relevance ranking is a simple keyword overlap so we don't need an embedding store.
 *
 * Settings: Midland Smart Chat > Site Content
 */
class SCAI_Content_Context {

    const OPT_ENABLED      = 'scai_ctx_enabled';
    const OPT_SITEMAP_URL  = 'scai_ctx_sitemap_url';
    const OPT_LAST_REFRESH = 'scai_ctx_last_refresh';
    const OPT_CHUNKS       = 'scai_ctx_chunks';
    const OPT_PAGE_LIMIT   = 'scai_ctx_page_limit';
    const OPT_CHARS_PER    = 'scai_ctx_chars_per_page';
    const CRON_HOOK        = 'scai_ctx_refresh';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 61 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_init', array( $this, 'handle_refresh' ) );
        add_action( 'admin_init', array( $this, 'maybe_bootstrap_first_crawl' ) );

        // Inject site context into the system prompt.
        add_filter( 'scai_system_prompt', array( $this, 'inject_context' ), 10, 1 );

        // Daily auto-refresh.
        add_action( self::CRON_HOOK, array( $this, 'refresh_cache' ) );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * First-run bootstrap. Runs on admin_init so it covers upgrades from older
     * versions (where the activation hook doesn't refire). If sitemap context
     * is enabled but the cache has never been populated, schedule an immediate
     * one-shot cron event to do the first crawl.
     */
    public function maybe_bootstrap_first_crawl() {
        // Default-on for new installs that never touched the toggle.
        if ( false === get_option( self::OPT_ENABLED ) ) {
            update_option( self::OPT_ENABLED, 1 );
        }
        if ( ! (int) get_option( self::OPT_ENABLED, 0 ) ) {
            return;
        }
        $last = get_option( self::OPT_LAST_REFRESH, array() );
        if ( ! empty( $last['at'] ) ) {
            return; // Already ran at least once.
        }
        if ( wp_next_scheduled( self::CRON_HOOK ) > time() + 60 ) {
            // A run is already pending soon.
            return;
        }
        wp_schedule_single_event( time() + 10, self::CRON_HOOK );
    }

    public function add_menu() {
        add_submenu_page(
            'smart-chat-ai',
            esc_html__( 'Site Content', 'smart-chat-ai' ),
            esc_html__( 'Site Content', 'smart-chat-ai' ),
            'manage_options',
            'scai-content',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['scai_save_ctx'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scai_ctx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scai_ctx_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scai_save_ctx' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-chat-ai' ) );
        }

        update_option( self::OPT_ENABLED,     isset( $_POST['ctx_enabled'] ) ? 1 : 0 );
        update_option( self::OPT_SITEMAP_URL, esc_url_raw( wp_unslash( $_POST['sitemap_url'] ?? '' ) ) );
        update_option( self::OPT_PAGE_LIMIT,  max( 1, min( 200, (int) wp_unslash( $_POST['page_limit'] ?? 30 ) ) ) );
        update_option( self::OPT_CHARS_PER,   max( 200, min( 5000, (int) wp_unslash( $_POST['chars_per_page'] ?? 1500 ) ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=scai-content&saved=1' ) );
        exit;
    }

    public function handle_refresh() {
        if ( ! isset( $_GET['scai_ctx_refresh'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scai_ctx_refresh' ) ) {
            return;
        }
        $count = $this->refresh_cache();
        wp_safe_redirect( admin_url( 'admin.php?page=scai-content&refreshed=' . (int) $count ) );
        exit;
    }

    /**
     * Default request args for outbound fetches. Many managed hosts, security
     * plugins, and CDNs (Cloudflare etc.) return 403/empty to the bare
     * "WordPress/x.x" user-agent — which is the #1 cause of a sitemap reading
     * "empty or unreachable". A browser-like UA plus an XML Accept header and
     * redirect following gets us past the common cases.
     */
    private function request_args( $timeout = 15 ) {
        return array(
            'timeout'     => $timeout,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (compatible; MidlandChatBot/1.0; +' . home_url( '/' ) . ')',
            'headers'     => array(
                'Accept'          => 'application/xml,text/xml,text/html;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ),
        );
    }

    /**
     * Walk the sitemap, fetch each page, store plain text in OPT_CHUNKS.
     * Returns the number of pages cached.
     */
    public function refresh_cache() {
        $configured = trim( (string) get_option( self::OPT_SITEMAP_URL, '' ) );

        // Try the admin's explicit URL first, then the two common defaults so
        // the crawl finds a working sitemap regardless of which SEO plugin is
        // active (WP core = /wp-sitemap.xml, Yoast/Rank Math = /sitemap_index.xml).
        $candidates = array();
        if ( '' !== $configured ) {
            $candidates[] = $configured;
        }
        $candidates[] = home_url( '/wp-sitemap.xml' );
        $candidates[] = home_url( '/sitemap_index.xml' );
        $candidates[] = home_url( '/sitemap.xml' );
        $candidates   = array_values( array_unique( $candidates ) );

        $urls   = array();
        $used    = '';
        $errors = array();
        foreach ( $candidates as $candidate ) {
            $found = $this->fetch_sitemap_urls( $candidate, 0, $errors );
            if ( ! empty( $found ) ) {
                $urls = $found;
                $used = $candidate;
                break;
            }
        }

        if ( empty( $urls ) ) {
            $note = 'No sitemap returned URLs. Tried ' . implode( ', ', $candidates );
            if ( ! empty( $errors ) ) {
                $note .= ' — ' . implode( '; ', array_slice( $errors, 0, 5 ) );
            }
            update_option( self::OPT_LAST_REFRESH, array( 'at' => time(), 'count' => 0, 'note' => $note ) );
            return 0;
        }

        $page_limit = (int) get_option( self::OPT_PAGE_LIMIT, 30 );
        $chars_per  = (int) get_option( self::OPT_CHARS_PER, 1500 );
        $urls       = array_slice( $urls, 0, $page_limit );

        $chunks = array();
        foreach ( $urls as $url ) {
            $text = $this->fetch_page_text( $url, $chars_per );
            if ( '' === $text ) {
                continue;
            }
            $chunks[] = array(
                'url'  => $url,
                'text' => $text,
            );
        }

        update_option( self::OPT_CHUNKS, $chunks );

        $note = 'Sitemap: ' . $used;
        if ( count( $chunks ) < count( $urls ) ) {
            // Found sitemap URLs but some pages returned no text (blocked, empty, or timed out).
            $note .= sprintf( ' (%d of %d pages returned text)', count( $chunks ), count( $urls ) );
        }
        update_option( self::OPT_LAST_REFRESH, array( 'at' => time(), 'count' => count( $chunks ), 'note' => $note ) );

        return count( $chunks );
    }

    /**
     * Walk a sitemap, including sitemap-index files (which point at child sitemaps).
     */
    private function fetch_sitemap_urls( $sitemap_url, $depth = 0, &$errors = array() ) {
        if ( $depth > 2 ) {
            return array();
        }

        $response = wp_remote_get( $sitemap_url, $this->request_args( 15 ) );
        if ( is_wp_error( $response ) ) {
            $errors[] = $sitemap_url . ' (' . $response->get_error_message() . ')';
            return array();
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code >= 400 ) {
            $errors[] = $sitemap_url . ' (HTTP ' . $code . ')';
            return array();
        }
        if ( '' === $body ) {
            $errors[] = $sitemap_url . ' (empty response)';
            return array();
        }

        // suppress libxml warnings on malformed sitemaps
        $previous = libxml_use_internal_errors( true );
        $xml      = simplexml_load_string( $body );
        libxml_use_internal_errors( $previous );
        if ( false === $xml ) {
            $errors[] = $sitemap_url . ' (not valid XML — likely an HTML page, not a sitemap)';
            return array();
        }

        $urls = array();
        $name = $xml->getName();

        if ( 'sitemapindex' === $name ) {
            foreach ( $xml->sitemap as $entry ) {
                $loc = trim( (string) $entry->loc );
                if ( $loc ) {
                    $urls = array_merge( $urls, $this->fetch_sitemap_urls( $loc, $depth + 1, $errors ) );
                }
            }
        } else {
            foreach ( $xml->url as $entry ) {
                $loc = trim( (string) $entry->loc );
                if ( $loc ) {
                    $urls[] = $loc;
                }
            }
        }

        return array_values( array_unique( $urls ) );
    }

    private function fetch_page_text( $url, $max_chars ) {
        $response = wp_remote_get( $url, $this->request_args( 10 ) );
        if ( is_wp_error( $response ) ) {
            return '';
        }
        if ( (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
            return '';
        }
        $html = (string) wp_remote_retrieve_body( $response );
        if ( '' === $html ) {
            return '';
        }

        // Strip script/style/nav/footer before extracting text.
        $html = preg_replace( '#<(script|style|nav|footer|aside|header)[^>]*>.*?</\1>#is', ' ', $html );
        $text = wp_strip_all_tags( (string) $html, true );
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = trim( (string) $text );

        if ( function_exists( 'mb_substr' ) ) {
            $text = mb_substr( $text, 0, $max_chars );
        } else {
            $text = substr( $text, 0, $max_chars );
        }

        return $text;
    }

    /**
     * Filter callback that adds the most relevant cached chunks to the system prompt.
     */
    public function inject_context( $prompt ) {
        if ( ! get_option( self::OPT_ENABLED, 0 ) ) {
            return $prompt;
        }
        $chunks = (array) get_option( self::OPT_CHUNKS, array() );
        if ( empty( $chunks ) ) {
            return $prompt;
        }

        // Scope to chunks relevant to the latest user message — accessed via $_POST.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $user_message = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';
        $picked       = $this->rank_chunks( (string) $user_message, $chunks, 4 );

        if ( empty( $picked ) ) {
            return $prompt;
        }

        $reference = "\n\n--- BACKGROUND ON OUR COMPANY ---\nThese are notes from our own website. Use them as background knowledge only — paraphrase casually in your own words. Do NOT quote them verbatim. Do NOT echo marketing phrases like \"free on-site evaluation\" or \"24-48 hour quote turnaround\" — say it like a normal person would.\n\n";
        foreach ( $picked as $c ) {
            $reference .= "Source: " . $c['url'] . "\n" . $c['text'] . "\n\n";
        }

        return $prompt . $reference;
    }

    /**
     * Naive keyword-overlap ranking. Splits the question into tokens, scores each
     * cached chunk by how many tokens it contains, returns the top $n.
     */
    private function rank_chunks( $question, array $chunks, $n = 4 ) {
        $question = strtolower( $question );
        $tokens   = array_filter( preg_split( '/[^a-z0-9]+/', $question ), function( $t ) {
            return strlen( $t ) >= 3;
        } );
        if ( empty( $tokens ) ) {
            // Fall back to the first N chunks so the AI always has site context.
            return array_slice( $chunks, 0, $n );
        }

        $scored = array();
        foreach ( $chunks as $chunk ) {
            $hay = strtolower( $chunk['text'] );
            $score = 0;
            foreach ( $tokens as $t ) {
                $count = substr_count( $hay, $t );
                $score += $count;
            }
            if ( $score > 0 ) {
                $scored[] = array( 'score' => $score, 'chunk' => $chunk );
            }
        }
        usort( $scored, function( $a, $b ) {
            return $b['score'] - $a['score'];
        } );

        $top = array_slice( $scored, 0, $n );
        return array_map( function( $r ) {
            return $r['chunk'];
        }, $top );
    }

    public function render_page() {
        $enabled    = (int) get_option( self::OPT_ENABLED, 0 );
        $sitemap    = (string) get_option( self::OPT_SITEMAP_URL, '' );
        $page_limit = (int) get_option( self::OPT_PAGE_LIMIT, 30 );
        $chars_per  = (int) get_option( self::OPT_CHARS_PER, 1500 );
        $last       = get_option( self::OPT_LAST_REFRESH, array() );
        $chunks     = (array) get_option( self::OPT_CHUNKS, array() );
        $refresh_url = wp_nonce_url( admin_url( 'admin.php?page=scai-content&scai_ctx_refresh=1' ), 'scai_ctx_refresh' );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $saved      = isset( $_GET['saved'] );
        $refreshed  = isset( $_GET['refreshed'] ) ? absint( $_GET['refreshed'] ) : -1;
        // phpcs:enable
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Site Content Context', 'smart-chat-ai' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Walks your sitemap, caches plain-text excerpts, and feeds the most relevant ones to the chat AI on each message. Stops the AI from making up answers about your services.', 'smart-chat-ai' ); ?></p>

            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'smart-chat-ai' ); ?></p></div><?php endif; ?>
            <?php if ( $refreshed >= 0 ) : ?><div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( '%d pages cached.', 'smart-chat-ai' ), $refreshed ); ?></p></div><?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'scai_save_ctx', '_scai_ctx_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable Site Context', 'smart-chat-ai' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="ctx_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Inject relevant site content into every chat AI response.', 'smart-chat-ai' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sitemap_url"><?php esc_html_e( 'Sitemap URL', 'smart-chat-ai' ); ?></label></th>
                        <td>
                            <input type="url" id="sitemap_url" name="sitemap_url" class="large-text" value="<?php echo esc_attr( $sitemap ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-sitemap.xml' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Leave blank to use WordPress core /wp-sitemap.xml. Sitemap-index files are followed one level deep.', 'smart-chat-ai' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="page_limit"><?php esc_html_e( 'Page Limit', 'smart-chat-ai' ); ?></label></th>
                        <td><input type="number" id="page_limit" name="page_limit" min="1" max="200" value="<?php echo esc_attr( $page_limit ); ?>" style="width:90px;"></td>
                    </tr>
                    <tr>
                        <th><label for="chars_per_page"><?php esc_html_e( 'Chars Per Page', 'smart-chat-ai' ); ?></label></th>
                        <td>
                            <input type="number" id="chars_per_page" name="chars_per_page" min="200" max="5000" step="100" value="<?php echo esc_attr( $chars_per ); ?>" style="width:90px;">
                            <p class="description"><?php esc_html_e( 'Per-page text cap. Lower = leaner prompt, fewer tokens.', 'smart-chat-ai' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="scai_save_ctx" value="1" class="button button-primary"><?php esc_html_e( 'Save', 'smart-chat-ai' ); ?></button>
                    <a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Refresh Cache Now', 'smart-chat-ai' ); ?></a>
                </p>
            </form>

            <?php if ( ! empty( $last ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Cache Status', 'smart-chat-ai' ); ?></h2>
                <p>
                    <?php printf(
                        /* translators: 1: page count, 2: timestamp */
                        esc_html__( '%1$d pages cached, last refreshed %2$s', 'smart-chat-ai' ),
                        (int) ( $last['count'] ?? count( $chunks ) ),
                        ! empty( $last['at'] ) ? esc_html( wp_date( 'Y-m-d H:i', (int) $last['at'] ) ) : '—'
                    ); ?>
                    <?php if ( ! empty( $last['note'] ) ) : ?>
                        <br><em style="color:#d63638;"><?php echo esc_html( $last['note'] ); ?></em>
                    <?php endif; ?>
                </p>
                <?php if ( ! empty( $chunks ) ) : ?>
                    <details>
                        <summary><strong><?php esc_html_e( 'Cached URLs', 'smart-chat-ai' ); ?></strong></summary>
                        <ul style="margin-top:8px;">
                            <?php foreach ( $chunks as $c ) : ?>
                                <li><a href="<?php echo esc_url( $c['url'] ); ?>" target="_blank"><?php echo esc_html( $c['url'] ); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

SCAI_Content_Context::get_instance();
