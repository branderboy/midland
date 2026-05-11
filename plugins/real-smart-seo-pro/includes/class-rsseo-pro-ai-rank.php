<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI Rank + GEO (Generative Engine Optimization) Module.
 * Tracks whether your domain appears in citations from Perplexity (and other LLM
 * providers via filter), so you know how often you're being surfaced in AI answers.
 *
 * Settings: Real Smart SEO Pro > AI Rank
 */
class RSSEO_Pro_AI_Rank {

    const PERPLEXITY_ENDPOINT = 'https://api.perplexity.ai/chat/completions';
    const CRON_HOOK           = 'rsseo_ai_rank_weekly_scan';
    const TICK_HOOK           = 'rsseo_ai_rank_process_one';
    const TICK_DELAY          = 5; // seconds between API calls

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 35 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_init', array( $this, 'handle_run_now' ) );
        add_action( self::CRON_HOOK, array( $this, 'run_scan' ) );
        add_action( self::TICK_HOOK, array( $this, 'process_one' ), 10, 1 );
        add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_pro_ai_rank (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            query varchar(500) NOT NULL,
            source varchar(40) NOT NULL,
            cited tinyint(1) NOT NULL DEFAULT 0,
            citation_rank int DEFAULT NULL,
            citation_url varchar(500) DEFAULT NULL,
            response_excerpt text DEFAULT NULL,
            error_msg varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY query (query(191)),
            KEY source (source)
        ) $charset;" );
    }

    public function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 2 * DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
        }
    }

    public function add_menu() {
        add_submenu_page(
            null,
            esc_html__( 'AI Rank + GEO', 'real-smart-seo-pro' ),
            esc_html__( 'AI Rank + GEO', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-ai-rank',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['rsseo_save_ai_rank'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_rsseo_ai_rank_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_ai_rank_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_save_ai_rank' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $queries_raw = isset( $_POST['ai_queries'] ) ? wp_unslash( $_POST['ai_queries'] ) : '';
        $queries     = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $queries_raw ) as $line ) {
            $q = sanitize_text_field( trim( $line ) );
            if ( '' !== $q ) {
                $queries[] = $q;
            }
        }
        $queries = array_slice( array_unique( $queries ), 0, 25 );

        update_option( 'rsseo_pro_ai_rank_queries', $queries );
        update_option( 'rsseo_pro_ai_rank_target_domain', sanitize_text_field( wp_unslash( $_POST['ai_target_domain'] ?? '' ) ) );
        if ( isset( $_POST['ai_perplexity_key'] ) ) {
            update_option( 'rsseo_pro_ai_perplexity_key', sanitize_text_field( wp_unslash( $_POST['ai_perplexity_key'] ) ) );
        }
        update_option( 'rsseo_pro_ai_perplexity_model', sanitize_text_field( wp_unslash( $_POST['ai_perplexity_model'] ?? 'sonar' ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-ai-rank&saved=1' ) );
        exit;
    }

    public function handle_run_now() {
        if ( ! isset( $_GET['rsseo_ai_rank_run'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_ai_rank_run' ) ) {
            return;
        }
        $this->run_scan();
        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-ai-rank&ran=1' ) );
        exit;
    }

    /**
     * Build the queue of (query, source) pairs and kick off the first tick.
     * Each tick processes exactly one pair so a slow LLM call never blocks the scan.
     */
    public function run_scan() {
        $queries = (array) get_option( 'rsseo_pro_ai_rank_queries', array() );
        $target  = (string) get_option( 'rsseo_pro_ai_rank_target_domain', '' );
        if ( empty( $queries ) || empty( $target ) ) {
            return;
        }

        $sources = apply_filters( 'rsseo_ai_rank_sources', array(
            'perplexity' => array( $this, 'check_perplexity' ),
        ) );

        $queue = array();
        foreach ( $queries as $query ) {
            foreach ( $sources as $source_id => $callable ) {
                if ( ! is_callable( $callable ) ) {
                    continue;
                }
                $queue[] = array( 'query' => $query, 'source' => $source_id );
            }
        }
        if ( empty( $queue ) ) {
            return;
        }

        $batch_id = 'rsseo_ai_rank_batch_' . wp_generate_password( 12, false );
        set_transient( $batch_id, $queue, HOUR_IN_SECONDS );

        wp_schedule_single_event( time(), self::TICK_HOOK, array( $batch_id ) );
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * Process the next pending pair from the batch transient.
     */
    public function process_one( $batch_id ) {
        $batch_id = sanitize_key( (string) $batch_id );
        if ( '' === $batch_id ) {
            return;
        }

        $queue = get_transient( $batch_id );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            delete_transient( $batch_id );
            return;
        }

        $sources = apply_filters( 'rsseo_ai_rank_sources', array(
            'perplexity' => array( $this, 'check_perplexity' ),
        ) );

        $target = (string) get_option( 'rsseo_pro_ai_rank_target_domain', '' );
        $item   = array_shift( $queue );

        if ( '' !== $target && ! empty( $item['query'] ) && ! empty( $item['source'] ) && isset( $sources[ $item['source'] ] ) && is_callable( $sources[ $item['source'] ] ) ) {
            $row = call_user_func( $sources[ $item['source'] ], $item['query'], $target );
            global $wpdb;
            $wpdb->insert( $wpdb->prefix . 'rsseo_pro_ai_rank', array_merge( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                array(
                    'query'  => $item['query'],
                    'source' => $item['source'],
                ),
                $row
            ) );
        }

        if ( ! empty( $queue ) ) {
            set_transient( $batch_id, $queue, HOUR_IN_SECONDS );
            wp_schedule_single_event( time() + self::TICK_DELAY, self::TICK_HOOK, array( $batch_id ) );
        } else {
            delete_transient( $batch_id );
        }
    }

    public function check_perplexity( $query, $target ) {
        $key   = (string) get_option( 'rsseo_pro_ai_perplexity_key', '' );
        $model = (string) get_option( 'rsseo_pro_ai_perplexity_model', 'sonar' );

        if ( empty( $key ) ) {
            return array(
                'cited'             => 0,
                'citation_rank'     => null,
                'citation_url'      => null,
                'response_excerpt'  => null,
                'error_msg'         => 'Perplexity API key not configured',
            );
        }

        $response = wp_remote_post( self::PERPLEXITY_ENDPOINT, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'    => $model,
                'messages' => array(
                    array( 'role' => 'system', 'content' => 'Be concise. Always cite sources.' ),
                    array( 'role' => 'user', 'content' => $query ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'cited'            => 0,
                'citation_rank'    => null,
                'citation_url'     => null,
                'response_excerpt' => null,
                'error_msg'        => substr( $response->get_error_message(), 0, 250 ),
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = $body['error']['message'] ?? ( 'Perplexity HTTP ' . $code );
            return array(
                'cited'            => 0,
                'citation_rank'    => null,
                'citation_url'     => null,
                'response_excerpt' => null,
                'error_msg'        => substr( (string) $msg, 0, 250 ),
            );
        }

        $citations = $body['citations'] ?? ( $body['choices'][0]['message']['citations'] ?? array() );
        $content   = $body['choices'][0]['message']['content'] ?? '';

        $cited = 0;
        $rank  = null;
        $hit_url = null;

        foreach ( (array) $citations as $i => $citation ) {
            $url = is_array( $citation ) ? ( $citation['url'] ?? '' ) : (string) $citation;
            if ( $this->url_matches_domain( $url, $target ) ) {
                $cited   = 1;
                $rank    = $i + 1;
                $hit_url = $url;
                break;
            }
        }

        return array(
            'cited'            => $cited,
            'citation_rank'    => $rank,
            'citation_url'     => $hit_url,
            'response_excerpt' => self::safe_truncate( wp_strip_all_tags( $content ), 500 ),
            'error_msg'        => null,
        );
    }

    private static function safe_truncate( $string, $length ) {
        $string = (string) $string;
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $string, 0, $length );
        }
        return substr( $string, 0, $length );
    }

    private function url_matches_domain( $url, $domain ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $host ) || empty( $domain ) ) {
            return false;
        }
        $host   = strtolower( preg_replace( '#^www\.#', '', $host ) );
        $domain = strtolower( preg_replace( '#^www\.#', '', $domain ) );
        return $host === $domain || ( false !== strpos( $host, '.' . $domain ) );
    }

    private function get_recent( $limit = 50 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_pro_ai_rank ORDER BY id DESC LIMIT %d",
            $limit
        ) );
    }

    private function get_summary() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT query, source, SUM(cited) AS cited_count, COUNT(*) AS total FROM {$wpdb->prefix}rsseo_pro_ai_rank GROUP BY query, source ORDER BY query" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        return $rows ? $rows : array();
    }

    public function render_page() {
        $queries = (array) get_option( 'rsseo_pro_ai_rank_queries', array() );
        $target  = (string) get_option( 'rsseo_pro_ai_rank_target_domain', wp_parse_url( home_url(), PHP_URL_HOST ) );
        $key     = (string) get_option( 'rsseo_pro_ai_perplexity_key', '' );
        $model   = (string) get_option( 'rsseo_pro_ai_perplexity_model', 'sonar' );

        $recent  = $this->get_recent( 50 );
        $summary = $this->get_summary();

        $run_url = wp_nonce_url( admin_url( 'admin.php?page=rsseo-ai-rank&rsseo_ai_rank_run=1' ), 'rsseo_ai_rank_run' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'AI Rank + GEO', 'real-smart-seo-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Track whether your domain is cited in AI answers (Perplexity today; ChatGPT/Google AI Overviews via filter when their APIs allow it).', 'real-smart-seo-pro' ); ?></p>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['ran'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scan complete.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'rsseo_save_ai_rank', '_rsseo_ai_rank_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="ai_target_domain"><?php esc_html_e( 'Target Domain', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="ai_target_domain" name="ai_target_domain" class="regular-text" value="<?php echo esc_attr( $target ); ?>" placeholder="example.com"></td>
                    </tr>
                    <tr>
                        <th><label for="ai_queries"><?php esc_html_e( 'Tracked Queries', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <textarea id="ai_queries" name="ai_queries" rows="6" class="large-text" placeholder="<?php esc_attr_e( "best commercial floor cleaning DC\nemergency carpet cleaning Maryland\n...", 'real-smart-seo-pro' ); ?>"><?php echo esc_textarea( implode( "\n", $queries ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'One query per line. Up to 25.', 'real-smart-seo-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_perplexity_key"><?php esc_html_e( 'Perplexity API Key', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="password" id="ai_perplexity_key" name="ai_perplexity_key" class="regular-text" value="<?php echo esc_attr( $key ); ?>" placeholder="pplx-..."></td>
                    </tr>
                    <tr>
                        <th><label for="ai_perplexity_model"><?php esc_html_e( 'Perplexity Model', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <select id="ai_perplexity_model" name="ai_perplexity_model">
                                <?php foreach ( array( 'sonar', 'sonar-pro', 'sonar-reasoning' ) as $m ) : ?>
                                    <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $model, $m ); ?>><?php echo esc_html( $m ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="rsseo_save_ai_rank" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'real-smart-seo-pro' ); ?></button>
                    <a href="<?php echo esc_url( $run_url ); ?>" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Run Scan Now', 'real-smart-seo-pro' ); ?></a>
                </p>
            </form>

            <?php if ( ! empty( $summary ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Citation Rate', 'real-smart-seo-pro' ); ?></h2>
                <table class="widefat striped" style="max-width:780px;">
                    <thead><tr>
                        <th><?php esc_html_e( 'Query', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Cited / Runs', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Rate', 'real-smart-seo-pro' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $summary as $row ) :
                            $rate = $row->total ? round( ( $row->cited_count / $row->total ) * 100 ) : 0;
                            $color = $rate >= 50 ? '#0a8754' : ( $rate >= 20 ? '#dba617' : '#d63638' );
                        ?>
                            <tr>
                                <td><?php echo esc_html( $row->query ); ?></td>
                                <td><?php echo esc_html( $row->source ); ?></td>
                                <td><?php echo esc_html( (int) $row->cited_count . ' / ' . (int) $row->total ); ?></td>
                                <td style="color:<?php echo esc_attr( $color ); ?>;font-weight:700;"><?php echo esc_html( $rate ); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( ! empty( $recent ) ) : ?>
                <h2><?php esc_html_e( 'Recent Runs', 'real-smart-seo-pro' ); ?></h2>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'When', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Query', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Cited?', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Citation', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Note', 'real-smart-seo-pro' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $recent as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r->created_at ); ?></td>
                                <td><?php echo esc_html( $r->source ); ?></td>
                                <td><?php echo esc_html( $r->query ); ?></td>
                                <td>
                                    <?php if ( $r->cited ) : ?>
                                        <span style="color:#0a8754;font-weight:700;">&#10003; #<?php echo esc_html( (int) $r->citation_rank ); ?></span>
                                    <?php else : ?>
                                        <span style="color:#d63638;">&#10005;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $r->citation_url ? '<a href="' . esc_url( $r->citation_url ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $r->citation_url, PHP_URL_PATH ) ?: '/' ) . '</a>' : '—'; ?></td>
                                <td><?php echo esc_html( $r->error_msg ?: ( $r->response_excerpt ? self::safe_truncate( $r->response_excerpt, 80 ) . '…' : '' ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>
            <h2><?php esc_html_e( 'GEO Optimization Checklist', 'real-smart-seo-pro' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Practices that increase the odds of being cited by LLM answers.', 'real-smart-seo-pro' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Publish FAQ blocks with schema.org/FAQPage markup on every key page.', 'real-smart-seo-pro' ); ?></li>
                <li><?php esc_html_e( 'Lead each section with a one-sentence factual answer, then expand. LLMs prefer extractable claims.', 'real-smart-seo-pro' ); ?></li>
                <li><?php esc_html_e( 'Use numbered lists for "best", "top", "how to" content — directly extractable.', 'real-smart-seo-pro' ); ?></li>
                <li><?php esc_html_e( 'Cite primary sources and include the year. Recency boosts trust.', 'real-smart-seo-pro' ); ?></li>
                <li><?php esc_html_e( 'Add Organization, LocalBusiness, and Service schema (handled by sameAs + schema modules).', 'real-smart-seo-pro' ); ?></li>
                <li><?php esc_html_e( 'Keep paragraphs short. Token-bounded models prefer dense, scoped chunks.', 'real-smart-seo-pro' ); ?></li>
            </ol>
        </div>
        <?php
    }
}

RSSEO_Pro_AI_Rank::get_instance();
