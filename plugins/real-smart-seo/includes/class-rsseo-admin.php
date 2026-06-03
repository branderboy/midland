<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RSSEO_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',           array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_rsseo_apply_fix',   array( $this, 'ajax_apply_fix' ) );
        add_action( 'wp_ajax_rsseo_apply_all',   array( $this, 'ajax_apply_all' ) );
        add_action( 'wp_ajax_rsseo_restore_fix', array( $this, 'ajax_restore_fix' ) );
        add_action( 'wp_ajax_rsseo_restore_all', array( $this, 'ajax_restore_all' ) );
        add_action( 'wp_ajax_rsseo_test_api',    array( $this, 'ajax_test_api' ) );
        add_action( 'wp_ajax_rsseo_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_rsseo_rename_scan',   array( $this, 'ajax_rename_scan' ) );
        add_action( 'admin_post_rsseo_new_scan',    array( $this, 'handle_new_scan' ) );
        add_action( 'wp_ajax_rsseo_run_audit',      array( $this, 'ajax_run_audit' ) );
        add_action( 'wp_ajax_rsseo_apply_audit_fix', array( $this, 'ajax_apply_audit_fix' ) );
    }

    public function register_menu() {
        // One menu item. The page renders a tab strip and delegates to the
        // appropriate page_* method based on the ?tab= query var, so the user
        // never has to click between 5 lookalike submenus.
        add_menu_page(
            __( 'Real Smart SEO', 'real-smart-seo' ),
            __( 'Real Smart SEO', 'real-smart-seo' ),
            'manage_options',
            'real-smart-seo',
            array( $this, 'render_tabbed_page' ),
            'dashicons-chart-line',
            81
        );

        // Legacy slugs kept hidden (parent=null) so old bookmarks/links still
        // resolve. They forward to the tabbed page.
        $legacy = array(
            'rsseo-new-scan'   => 'scan',
            'rsseo-reports'    => 'reports',
            'rsseo-site-audit' => 'audit',
            'rsseo-settings'   => 'settings',
        );
        foreach ( $legacy as $slug => $tab ) {
            add_submenu_page( null, '', '', 'manage_options', $slug, function () use ( $tab ) {
                wp_safe_redirect( admin_url( 'admin.php?page=real-smart-seo&tab=' . $tab ) );
                exit;
            } );
        }
    }

    private function get_active_tab() {
        $allowed = array( 'workflow', 'scan', 'analyze', 'repair', 'index', 'insights', 'settings' );
        $tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'workflow'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Map legacy slugs onto the new vocabulary so old bookmarks work.
        $alias = array( 'audit' => 'scan', 'reports' => 'repair', 'report' => 'repair' );
        if ( isset( $alias[ $tab ] ) ) {
            $tab = $alias[ $tab ];
        }
        return in_array( $tab, $allowed, true ) ? $tab : 'workflow';
    }

    public function render_tabbed_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $active = $this->get_active_tab();

        // Tabs follow the Scan → Analyze → Repair → Insights pipeline.
        // Repair = where the Apply Fixes table lives.
        // Insights = rankings, AI Rank, Geo-Grid (Pro). Free users see a
        //            "what is this" panel pointing at the Pro upgrade.
        $tabs = array(
            'workflow' => __( 'Workflow', 'real-smart-seo' ),
            'scan'     => __( 'Scan',     'real-smart-seo' ),
            'analyze'  => __( 'Analyze',  'real-smart-seo' ),
            'repair'   => __( 'Reports',  'real-smart-seo' ),
            'index'    => __( 'Index',    'real-smart-seo' ),
            'insights' => __( 'Insights', 'real-smart-seo' ),
            'settings' => __( 'Settings', 'real-smart-seo' ),
        );

        echo '<div class="wrap rsseo-wrap">';
        echo '<h1>' . esc_html__( 'Real Smart SEO', 'real-smart-seo' ) . '</h1>';

        echo '<h2 class="nav-tab-wrapper rsseo-tabs">';
        foreach ( $tabs as $slug => $label ) {
            $url   = admin_url( 'admin.php?page=real-smart-seo&tab=' . $slug );
            $class = 'nav-tab' . ( $active === $slug ? ' nav-tab-active' : '' );
            printf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
        }
        echo '</h2>';

        echo '<div class="rsseo-tab-content rsseo-tab-content--' . esc_attr( $active ) . '">';
        switch ( $active ) {
            case 'scan':     $this->page_site_audit(); break;
            case 'analyze':  $this->page_new_scan();   break;
            case 'repair':   $this->page_reports();    break;
            case 'index':    $this->page_index();      break;
            case 'insights': $this->page_insights();   break;
            case 'settings': $this->page_settings();   break;
            case 'workflow':
            default:
                $this->page_workflow();
                break;
        }
        // Pipeline CTA — every tab IN THE PIPELINE ends with a "Next: X →" so
        // the user always knows the one button to click after finishing here.
        // Settings + Workflow + Reports (archive) are excluded — they aren't
        // pipeline steps. Reports is just history; its empty state stands alone.
        if ( ! in_array( $active, array( 'workflow', 'settings', 'repair' ), true ) ) {
            $this->render_pipeline_cta( $active );
        }
        echo '</div></div>';
    }

    /**
     * Print a "Next: X →" CTA bar at the bottom of a pipeline tab. The next
     * step is chosen based on plugin state so the user can never land on an
     * orphan tab with no path forward.
     */
    private function render_pipeline_cta( string $current ): void {
        $scans         = RSSEO_Database::get_scans( 1 );
        $latest_scan   = ! empty( $scans ) ? $scans[0] : null;
        // get_scans() joins the report row in as `report_id` — use that, NOT the
        // scan id (get_report() keys on the report id).
        $report_id     = (int) ( $latest_scan->report_id ?? 0 );
        $latest_report = $report_id ? RSSEO_Database::get_report( $report_id ) : null;
        $has_report    = (bool) $latest_report;
        $report_qs     = $report_id ? '&report_id=' . $report_id : '';

        $url = array(
            'scan'     => admin_url( 'admin.php?page=real-smart-seo&tab=scan' ),
            'analyze'  => admin_url( 'admin.php?page=real-smart-seo&tab=analyze' . $report_qs ),
            'repair'   => admin_url( 'admin.php?page=real-smart-seo&tab=repair' . $report_qs ),
            'index'    => admin_url( 'admin.php?page=real-smart-seo&tab=index' ),
            'insights' => admin_url( 'admin.php?page=real-smart-seo&tab=insights' ),
        );

        $cta = array( 'label' => '', 'href' => '', 'hint' => '' );

        switch ( $current ) {
            case 'scan':
                $cta = $has_report
                    ? array( 'label' => __( 'Continue to Analyze →', 'real-smart-seo' ), 'href' => $url['analyze'], 'hint' => __( 'Next: paste your scan data and let Perplexity Sonar turn it into prioritized fixes.', 'real-smart-seo' ) )
                    : array( 'label' => __( 'Continue to Analyze →', 'real-smart-seo' ), 'href' => $url['analyze'], 'hint' => __( 'After running a scan, paste the data into Analyze for AI fixes.', 'real-smart-seo' ) );
                break;
            case 'analyze':
                $cta = $has_report
                    ? array( 'label' => __( 'Continue to Index →', 'real-smart-seo' ), 'href' => $url['index'], 'hint' => __( 'After applying fixes, ping Google + Bing so the new content gets recrawled fast.', 'real-smart-seo' ) )
                    : array( 'label' => __( 'Run a Scan first →', 'real-smart-seo' ), 'href' => $url['scan'], 'hint' => __( 'Analyze needs scan data. Start with a Site Audit on the Scan tab.', 'real-smart-seo' ) );
                break;
            case 'repair':
                $cta = $has_report
                    ? array( 'label' => __( 'Continue to Index →', 'real-smart-seo' ), 'href' => $url['index'], 'hint' => __( 'Once your fixes are applied, push the updated URLs to Google so the changes get re-crawled.', 'real-smart-seo' ) )
                    : array( 'label' => __( 'Run an Analysis first →', 'real-smart-seo' ), 'href' => $url['analyze'], 'hint' => __( 'No fixes to apply yet. Generate them on the Analyze tab.', 'real-smart-seo' ) );
                break;
            case 'index':
                $cta = array( 'label' => __( 'View Insights →', 'real-smart-seo' ), 'href' => $url['insights'], 'hint' => __( 'After re-indexing, track rankings, backlinks, and AI-recommended optimization strategies in Insights.', 'real-smart-seo' ) );
                break;
            case 'insights':
                $cta = array( 'label' => __( 'Start a new Scan →', 'real-smart-seo' ), 'href' => $url['scan'], 'hint' => __( 'Run the loop again — fresh scan, fresh AI analysis, fresh fixes.', 'real-smart-seo' ) );
                break;
        }

        if ( '' === $cta['label'] ) return;
        echo '<div class="rsseo-next-bar">';
        echo '<div class="rsseo-next-bar__hint">' . esc_html( $cta['hint'] ) . '</div>';
        echo '<a class="button button-primary button-large" href="' . esc_url( $cta['href'] ) . '">' . esc_html( $cta['label'] ) . '</a>';
        echo '</div>';
    }

    /**
     * Index tab — controls for getting (and keeping) pages indexed.
     * Sitemap status, IndexNow ping (Bing + Yandex), and GSC Cleanup links.
     */
    public function page_index() {
        $has_pro     = defined( 'RSSEO_PRO_VERSION' );
        $sitemap_url = home_url( '/sitemap_index.xml' );
        ?>
        <div class="rsseo-index">
            <h2><?php esc_html_e( 'Index', 'real-smart-seo' ); ?></h2>
            <p><?php esc_html_e( 'After Repair, push the updated content into Google, Bing, and Yandex so the fixes get crawled fast — not on whatever schedule the bots feel like.', 'real-smart-seo' ); ?></p>

            <div class="rsseo-insights-grid">
                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'Sitemap', 'real-smart-seo' ); ?></h3>
                    <p><code><?php echo esc_html( $sitemap_url ); ?></code></p>
                    <p><?php esc_html_e( 'Submit this URL in Google Search Console → Sitemaps and Bing Webmaster Tools → Sitemaps once.', 'real-smart-seo' ); ?></p>
                    <a class="button" href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Google Search Console', 'real-smart-seo' ); ?></a>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'IndexNow Ping', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Tell Bing, Yandex, Seznam, and Naver about new + updated URLs the moment they change. No waiting on a crawl schedule.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-indexnow' ) ); ?>"><?php esc_html_e( 'Configure IndexNow →', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'IndexNow', 'real-smart-seo' ), __( 'Bing/Yandex/Seznam/Naver get a notification the moment any post is published, edited, or trashed. Critical for time-sensitive content like job postings.', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'GSC Coverage Cleanup', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Auto-resolve Search Console errors — duplicate canonicals, soft 404s, "discovered but not indexed" pages.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-gsc' ) ); ?>"><?php esc_html_e( 'GSC Cleanup →', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'GSC Cleanup', 'real-smart-seo' ), __( 'Bulk-fix duplicate canonicals, soft 404s, and "Discovered but not indexed" pages from your GSC Coverage report.', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'Rapid URL Indexer', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Force-index stubborn URLs via third-party indexing services (paid). Optional — useful for fresh programmatic pages.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-indexnow#rapid' ) ); ?>"><?php esc_html_e( 'Setup →', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'Rapid URL Indexer', 'real-smart-seo' ), __( 'Optional paid integration with third-party indexers for stubborn URLs Google won\'t crawl on its own.', 'real-smart-seo' ) );
                    endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a Pro-upsell block on a card that's gated behind the paid plugin.
     * Tells the user exactly what they get and where to find the upgrade —
     * no more "Pro feature" pill with zero context.
     */
    private function pro_upsell( $feature_label, $value_line = '' ) {
        $url = 'https://tagglefish.com/real-smart-seo-pro';
        ?>
        <div class="rsseo-upsell">
            <p class="rsseo-upsell__tag"><?php esc_html_e( '🔒 Comes with Real Smart SEO Pro', 'real-smart-seo' ); ?></p>
            <?php if ( $value_line ) : ?>
                <p class="rsseo-upsell__value"><?php echo esc_html( $value_line ); ?></p>
            <?php endif; ?>
            <p>
                <a class="button button-primary button-small" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php
                    /* translators: %s: name of the gated feature */
                    printf( esc_html__( 'Unlock %s →', 'real-smart-seo' ), esc_html( $feature_label ) );
                    ?>
                </a>
                <a class="button button-small" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'See all Pro features', 'real-smart-seo' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Insights tab — rankings, backlinks, AI-recommended optimization
     * strategies. Pro modules (AI Rank, Geo-Grid) hook the action below to
     * inject their dashboards inline; free users see a summary with a
     * "Get AI Recommendation" button that runs an analysis via Perplexity.
     */
    public function page_insights() {
        $has_pro = defined( 'RSSEO_PRO_VERSION' );
        ?>
        <div class="rsseo-insights">
            <h2><?php esc_html_e( 'Insights', 'real-smart-seo' ); ?></h2>
            <p><?php esc_html_e( 'Rankings, backlinks, and AI-generated optimization strategies for your site.', 'real-smart-seo' ); ?></p>

            <div class="rsseo-insights-grid">
                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'Keyword Rankings', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Track positions for your target keywords across Google, Bing, and AI search engines.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-ai-rank' ) ); ?>"><?php esc_html_e( 'Open AI Rank', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'AI Rank Tracking', 'real-smart-seo' ), __( 'Daily check of where you rank for every target keyword on Google + Bing + ChatGPT + Perplexity. History charts so you can prove the work.', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'Local Rank Grid (Local Falcon-style)', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Map-pack rank measured at a grid of geographic points around your business — same idea as Local Falcon. See heat-map style coverage of where you rank #1 vs. where you fall off.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-geogrid' ) ); ?>"><?php esc_html_e( 'Open Geo-Grid →', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'Geo-Grid Tracking', 'real-smart-seo' ), __( 'Local Falcon costs $30/mo per location. This module does it sitewide with DataForSEO pay-as-you-go (~$0.50 per grid scan).', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card rsseo-insights-card--wide">
                    <h3><?php esc_html_e( 'Backlinks', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Inbound link profile, lost / gained links, and toxic-link warnings. Pulls from DataForSEO.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro && has_action( 'rsseo_render_backlinks_panel' ) ) : ?>
                        <?php do_action( 'rsseo_render_backlinks_panel' ); ?>
                    <?php else :
                        $this->pro_upsell( __( 'Backlinks Dashboard', 'real-smart-seo' ), __( 'Ahrefs charges $99/mo for this. This module pulls the same data from DataForSEO at ~$0.02 per refresh, on demand.', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'Indexing', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Google Search Console coverage — indexed / not indexed / errors. Ping new and updated URLs to Bing & Yandex via IndexNow.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-gsc' ) ); ?>"><?php esc_html_e( 'GSC Cleanup', 'real-smart-seo' ); ?></a>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-indexnow' ) ); ?>"><?php esc_html_e( 'IndexNow', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'GSC Cleanup + IndexNow', 'real-smart-seo' ), __( 'Auto-resolves Search Console coverage errors and pings Bing + Yandex on every change — new pages get crawled in minutes instead of days.', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'AI Optimization Strategies', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Ask Perplexity Sonar what to do next based on your latest scan + ranking data. Get a prioritized 30-day plan.', 'real-smart-seo' ); ?></p>
                    <button type="button" class="button button-primary" id="rsseo-get-strategy" disabled title="<?php esc_attr_e( 'Run a scan first so the AI has data to recommend on.', 'real-smart-seo' ); ?>">
                        <?php esc_html_e( 'Get AI Recommendation', 'real-smart-seo' ); ?>
                    </button>
                </div>
            </div>

            <?php
            // Pro modules can hook this action to render a richer inline dashboard.
            if ( $has_pro ) {
                do_action( 'rsseo_render_insights_panel' );
            }
            ?>
        </div>
        <?php
    }

    /**
     * Workflow tab — numbered step-by-step the user can follow start to finish
     * without guessing which submenu to click next.
     */
    public function page_workflow() {
        $has_key       = RSSEO_Settings::has_api_key();
        $scans         = RSSEO_Database::get_scans( 1 );
        $latest_scan   = ! empty( $scans ) ? $scans[0] : null;
        // Use the report id joined in by get_scans(), not the scan id.
        $report_id     = (int) ( $latest_scan->report_id ?? 0 );
        $latest_report = $report_id ? RSSEO_Database::get_report( $report_id ) : null;
        $pending_fixes = 0;
        if ( $latest_report ) {
            $pending_fixes = max( 0, (int) $latest_report->fixes_available - (int) $latest_report->fixes_applied );
        }
        $has_pro = defined( 'RSSEO_PRO_VERSION' );

        $url_scan     = admin_url( 'admin.php?page=real-smart-seo&tab=scan' );
        $url_analyze  = admin_url( 'admin.php?page=real-smart-seo&tab=analyze' );
        $url_repair   = admin_url( 'admin.php?page=real-smart-seo&tab=repair' );
        $url_index    = admin_url( 'admin.php?page=real-smart-seo&tab=index' );
        $url_insights = admin_url( 'admin.php?page=real-smart-seo&tab=insights' );
        $url_settings = admin_url( 'admin.php?page=real-smart-seo&tab=settings' );
        $url_report   = $report_id ? admin_url( 'admin.php?page=real-smart-seo&tab=repair&report_id=' . $report_id ) : $url_repair;

        require RSSEO_PATH . 'includes/views/workflow.php';
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'real-smart-seo' ) === false && strpos( $hook, 'rsseo' ) === false ) {
            return;
        }
        wp_enqueue_style( 'rsseo-admin', RSSEO_URL . 'assets/css/rsseo-admin.css', array(), RSSEO_VERSION );
        wp_enqueue_script( 'rsseo-admin', RSSEO_URL . 'assets/js/rsseo-admin.js', array( 'jquery' ), RSSEO_VERSION, true );
        wp_localize_script( 'rsseo-admin', 'rsseoData', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'rsseo_nonce' ),
            'strings'  => array(
                'applying'   => __( 'Applying fix...', 'real-smart-seo' ),
                'applied'    => __( 'Fixed!', 'real-smart-seo' ),
                'error'      => __( 'Error. Try again.', 'real-smart-seo' ),
                'confirm_fix'=> __( 'Apply this fix to your site?', 'real-smart-seo' ),
                'confirm_all'=> __( 'Apply ALL pending fixes? This will update your site content. Every change is backed up and can be reverted.', 'real-smart-seo' ),
                'confirm_revert'     => __( 'Revert this fix to the previous value?', 'real-smart-seo' ),
                'confirm_revert_all' => __( 'Revert ALL applied fixes back to their previous values?', 'real-smart-seo' ),
                'reverting'  => __( 'Reverting...', 'real-smart-seo' ),
                'analyzing'  => __( 'Analyzing... this may take 30–60 seconds.', 'real-smart-seo' ),
                'auditing'   => __( 'Running audit...', 'real-smart-seo' ),
            ),
        ) );
    }

    // ── Page: Dashboard ────────────────────────────────────────────────────────

    public function page_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $scans   = RSSEO_Database::get_scans( 5 );
        $usage   = RSSEO_Database::get_monthly_usage();
        $has_key = RSSEO_Settings::has_api_key();
        $seo_plugin = RSSEO_Settings::detect_seo_plugin();
        require RSSEO_PATH . 'includes/views/dashboard.php';
    }

    // ── Page: New Scan ─────────────────────────────────────────────────────────

    public function page_new_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $has_key = RSSEO_Settings::has_api_key();

        // If we just finished analyzing, render the report findings inline ABOVE
        // the new-scan form so the user sees their fixes + Apply buttons without
        // hunting through tabs. Each action ties forward to the next step
        // (Analyze → Repair findings inline → Index ping → Insights).
        $report_id = isset( $_GET['report_id'] ) ? (int) $_GET['report_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $report_id > 0 ) {
            $report = RSSEO_Database::get_report( $report_id );
            if ( $report ) {
                $fixes      = RSSEO_Database::get_fixes( $report_id );
                $url_index  = admin_url( 'admin.php?page=real-smart-seo&tab=index' );
                $url_insights = admin_url( 'admin.php?page=real-smart-seo&tab=insights' );
                echo '<div class="rsseo-notice rsseo-notice--success" style="margin-bottom:18px;"><strong>' . esc_html__( '✓ Analysis complete.', 'real-smart-seo' ) . '</strong> ' . esc_html__( 'Review the findings below and click Apply on each row — or "Apply All" to write every fix at once.', 'real-smart-seo' ) . '</div>';

                // Inline rename so the user can name + save this analysis with
                // a meaningful label (e.g. "Q2 Carpet Cleaning audit") and find
                // it later in Repair history.
                $scan_label = isset( $report->label ) ? (string) $report->label : '';
                ?>
                <div class="rsseo-rename-bar">
                    <label for="rsseo-scan-name"><strong><?php esc_html_e( 'Save this analysis as:', 'real-smart-seo' ); ?></strong></label>
                    <input type="text" id="rsseo-scan-name" class="regular-text" data-scan-id="<?php echo esc_attr( $report->scan_id ?? 0 ); ?>" value="<?php echo esc_attr( $scan_label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Q2 Carpet Cleaning audit', 'real-smart-seo' ); ?>">
                    <button type="button" class="button button-secondary" id="rsseo-save-scan-name"><?php esc_html_e( 'Save name', 'real-smart-seo' ); ?></button>
                    <span class="rsseo-rename-status" aria-live="polite"></span>
                </div>
                <?php
                require RSSEO_PATH . 'includes/views/report-detail.php';

                // Next-step CTA — tied to the pipeline so the user always knows
                // the one button to click after the current action finishes.
                echo '<div class="rsseo-next-step">';
                echo '<h2 style="margin-top:0;">' . esc_html__( 'Next: push the fixes into Google', 'real-smart-seo' ) . '</h2>';
                echo '<p>' . esc_html__( 'After applying fixes, ping Google + Bing + Yandex so the new content gets recrawled in minutes instead of days.', 'real-smart-seo' ) . '</p>';
                echo '<p><a class="button button-primary button-large" href="' . esc_url( $url_index ) . '">' . esc_html__( 'Continue to Index →', 'real-smart-seo' ) . '</a> ';
                echo '<a class="button" href="' . esc_url( $url_insights ) . '">' . esc_html__( 'Or view Insights', 'real-smart-seo' ) . '</a></p>';
                echo '</div>';

                echo '<hr style="margin:32px 0;"><h2 style="margin-top:0;">' . esc_html__( 'Run another analysis', 'real-smart-seo' ) . '</h2>';
            }
        }

        require RSSEO_PATH . 'includes/views/new-scan.php';
    }

    // ── Page: Reports ──────────────────────────────────────────────────────────

    public function page_reports() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }

        // Single report view.
        if ( isset( $_GET['report_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $report_id = (int) $_GET['report_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $report    = RSSEO_Database::get_report( $report_id );
            if ( $report ) {
                $fixes = RSSEO_Database::get_fixes( $report_id );
                require RSSEO_PATH . 'includes/views/report-detail.php';
                return;
            }
        }

        // Display all retained scans. The DB prunes to the most recent 10 on
        // every new submission, so this query naturally caps at 10 rows.
        $scans = RSSEO_Database::get_scans( 100 );
        require RSSEO_PATH . 'includes/views/reports-list.php';
    }

    // ── Page: Settings ─────────────────────────────────────────────────────────

    public function page_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $has_pro = defined( 'RSSEO_PRO_VERSION' );

        // Basic | Pro sub-tabs inside the Settings tab. Pro renders inline via
        // an action hook so users don't have to chase a separate "Pro Settings"
        // menu item — there isn't one anymore.
        $sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : 'basic'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $sub, array( 'basic', 'pro' ), true ) ) {
            $sub = 'basic';
        }
        if ( 'pro' === $sub && ! $has_pro ) {
            $sub = 'basic';
        }

        if ( $has_pro ) {
            $basic_url = admin_url( 'admin.php?page=real-smart-seo&tab=settings&sub=basic' );
            $pro_url   = admin_url( 'admin.php?page=real-smart-seo&tab=settings&sub=pro' );
            echo '<div class="rsseo-subtabs">';
            printf(
                '<a href="%s" class="rsseo-subtab%s">%s</a>',
                esc_url( $basic_url ),
                'basic' === $sub ? ' is-active' : '',
                esc_html__( 'Basic', 'real-smart-seo' )
            );
            printf(
                '<a href="%s" class="rsseo-subtab%s">%s</a>',
                esc_url( $pro_url ),
                'pro' === $sub ? ' is-active' : '',
                esc_html__( 'Pro', 'real-smart-seo' )
            );
            echo '</div>';
        }

        if ( 'pro' === $sub ) {
            if ( has_action( 'rsseo_render_pro_settings_panel' ) ) {
                do_action( 'rsseo_render_pro_settings_panel' );
            } else {
                echo '<p>' . esc_html__( 'Pro plugin not active.', 'real-smart-seo' ) . '</p>';
            }
            return;
        }

        $has_key    = RSSEO_Settings::has_api_key();
        $model      = RSSEO_Settings::get_model();
        $max_tokens = RSSEO_Settings::get_max_tokens();
        require RSSEO_PATH . 'includes/views/settings.php';
    }

    // ── Form Handler: New Scan ─────────────────────────────────────────────────

    public function handle_new_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        check_admin_referer( 'rsseo_new_scan' );

        $scan_id = RSSEO_Importer::process_submission( $_POST, $_FILES ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( is_wp_error( $scan_id ) ) {
            wp_redirect( add_query_arg( array(
                'page'  => 'rsseo-new-scan',
                'error' => urlencode( $scan_id->get_error_message() ),
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        /**
         * Lets a Pro add-on persist its own scan inputs against the scan that
         * was just created (DataForSEO pull, GMB/reviews/competitor uploads).
         */
        $scan_id = apply_filters( 'rsseo_after_scan_created', $scan_id, $_POST ); // phpcs:ignore WordPress.Security

        // Run analysis immediately (synchronous for simplicity — fine for most sites).
        // A Pro add-on may take over analysis entirely (its own AI pass + report
        // row); when none is hooked the filter returns null and the base
        // analyzer runs.
        $report_id = apply_filters( 'rsseo_run_analyzer', null, $scan_id );
        if ( null === $report_id ) {
            $report_id = RSSEO_Analyzer::analyze( $scan_id );
        }

        if ( is_wp_error( $report_id ) ) {
            wp_redirect( add_query_arg( array(
                'page'  => 'rsseo-new-scan',
                'error' => urlencode( $report_id->get_error_message() ),
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_redirect( add_query_arg( array(
            'page'      => 'real-smart-seo',
            'tab'       => 'analyze',
            'report_id' => $report_id,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── AJAX: Apply Single Fix ─────────────────────────────────────────────────

    public function ajax_apply_fix() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }

        $fix_id = isset( $_POST['fix_id'] ) ? (int) $_POST['fix_id'] : 0;
        if ( ! $fix_id ) {
            wp_send_json_error( __( 'Invalid fix ID.', 'real-smart-seo' ) );
        }

        $result = RSSEO_Fixer::apply( $fix_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'message' => __( 'Fix applied successfully.', 'real-smart-seo' ) ) );
    }

    // ── AJAX: Apply All Fixes ──────────────────────────────────────────────────

    public function ajax_apply_all() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }

        $report_id = isset( $_POST['report_id'] ) ? (int) $_POST['report_id'] : 0;
        if ( ! $report_id ) {
            wp_send_json_error( __( 'Invalid report ID.', 'real-smart-seo' ) );
        }

        $result = RSSEO_Fixer::apply_all( $report_id );
        wp_send_json_success( $result );
    }

    // ── AJAX: Revert (rollback) ────────────────────────────────────────────────

    public function ajax_restore_fix() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $fix_id = isset( $_POST['fix_id'] ) ? (int) $_POST['fix_id'] : 0;
        if ( ! $fix_id ) {
            wp_send_json_error( __( 'Invalid fix ID.', 'real-smart-seo' ) );
        }
        $result = RSSEO_Fixer::restore( $fix_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( array( 'message' => __( 'Reverted to the previous value.', 'real-smart-seo' ) ) );
    }

    public function ajax_restore_all() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $report_id = isset( $_POST['report_id'] ) ? (int) $_POST['report_id'] : 0;
        if ( ! $report_id ) {
            wp_send_json_error( __( 'Invalid report ID.', 'real-smart-seo' ) );
        }
        wp_send_json_success( RSSEO_Fixer::restore_all( $report_id ) );
    }

    // ── AJAX: Test API Key ─────────────────────────────────────────────────────

    public function ajax_test_api() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }

        $result = RSSEO_Claude_API::test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( array( 'message' => __( 'Connected successfully!', 'real-smart-seo' ) ) );
    }

    // ── AJAX: Save Settings ────────────────────────────────────────────────────

    public function ajax_rename_scan() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $scan_id = isset( $_POST['scan_id'] ) ? (int) $_POST['scan_id'] : 0;
        $label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        if ( $scan_id <= 0 ) {
            wp_send_json_error( __( 'Invalid scan.', 'real-smart-seo' ) );
        }
        RSSEO_Database::update_scan( $scan_id, array( 'label' => $label ) );
        wp_send_json_success( array( 'label' => $label, 'message' => __( '✓ Saved.', 'real-smart-seo' ) ) );
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }

        if ( isset( $_POST['rsseo_api_key'] ) ) {
            $key = sanitize_text_field( wp_unslash( $_POST['rsseo_api_key'] ) );
            if ( ! empty( $key ) && 'pplx-' !== substr( $key, 0, 5 ) ) {
                wp_send_json_error( __( 'Perplexity API key should start with pplx-', 'real-smart-seo' ) );
            }
            if ( ! empty( $key ) ) {
                RSSEO_Settings::save_api_key( $key );
            }
        }

        if ( isset( $_POST['rsseo_model'] ) ) {
            $allowed_models = array( 'sonar', 'sonar-pro', 'sonar-reasoning' );
            $model          = sanitize_text_field( wp_unslash( $_POST['rsseo_model'] ) );
            if ( ! in_array( $model, $allowed_models, true ) ) {
                wp_send_json_error( sprintf(
                    /* translators: %s: comma-separated list of valid model names */
                    __( 'Invalid model. Must be one of: %s', 'real-smart-seo' ),
                    implode( ', ', $allowed_models )
                ) );
            }
            update_option( 'rsseo_model', $model );
        }


        if ( isset( $_POST['rsseo_max_tokens'] ) ) {
            update_option( 'rsseo_max_tokens', min( 16000, max( 2000, (int) wp_unslash( $_POST['rsseo_max_tokens'] ) ) ) );
        }

        if ( isset( $_POST['rsseo_business_profile'] ) && is_array( $_POST['rsseo_business_profile'] ) ) {
            $raw     = wp_unslash( $_POST['rsseo_business_profile'] );
            $profile = array(
                'name'          => isset( $raw['name'] )          ? sanitize_text_field( $raw['name'] )          : '',
                'category'      => isset( $raw['category'] )      ? sanitize_text_field( $raw['category'] )      : '',
                'gmb_url'       => isset( $raw['gmb_url'] )       ? esc_url_raw( $raw['gmb_url'] )                : '',
                'service_areas' => isset( $raw['service_areas'] ) ? sanitize_textarea_field( $raw['service_areas'] ) : '',
                'competitors'   => isset( $raw['competitors'] )   ? sanitize_textarea_field( $raw['competitors'] )   : '',
            );
            update_option( 'rsseo_business_profile', $profile );
        }

        wp_send_json_success( array( 'message' => __( 'Settings saved.', 'real-smart-seo' ) ) );
    }

    // ── Page: Site Audit ───────────────────────────────────────────────────────

    public function page_site_audit() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $audit  = RSSEO_Database::get_latest_audit();
        $issues = $audit ? RSSEO_Database::get_audit_issues( $audit->id ) : array();
        require RSSEO_PATH . 'includes/views/site-audit.php';
    }

    // ── AJAX: Run Audit ────────────────────────────────────────────────────────

    public function ajax_run_audit() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }

        $audit_id = RSSEO_Crawler::run();

        if ( is_wp_error( $audit_id ) ) {
            wp_send_json_error( $audit_id->get_error_message() );
        }

        $audit  = RSSEO_Database::get_audit( $audit_id );
        $issues = RSSEO_Database::get_audit_issues( $audit_id );

        wp_send_json_success( array(
            'audit_id'  => $audit_id,
            'audit'     => $audit,
            'issues'    => $issues,
            'message'   => sprintf(
                /* translators: 1: posts checked, 2: total issues */
                __( 'Audit complete — %1$d posts checked, %2$d issues found.', 'real-smart-seo' ),
                (int) $audit->posts_checked,
                (int) $audit->issues_critical + (int) $audit->issues_high + (int) $audit->issues_medium + (int) $audit->issues_low
            ),
        ) );
    }

    // ── AJAX: Apply Audit Fix ──────────────────────────────────────────────────

    public function ajax_apply_audit_fix() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }

        $issue_id = isset( $_POST['issue_id'] ) ? (int) $_POST['issue_id'] : 0;
        if ( ! $issue_id ) {
            wp_send_json_error( __( 'Invalid issue ID.', 'real-smart-seo' ) );
        }

        $result = RSSEO_Crawler::apply_fix( $issue_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'message' => __( 'Fix applied.', 'real-smart-seo' ) ) );
    }
}
