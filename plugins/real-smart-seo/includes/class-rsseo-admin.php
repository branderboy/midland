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
        add_action( 'wp_ajax_rsseo_analysis_status', array( $this, 'ajax_analysis_status' ) );
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
        $allowed = array( 'dashboard', 'setup', 'scan', 'opportunities', 'fixqueue', 'pagebuilder', 'indexing', 'reports' );
        $tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Map the old + legacy slugs onto the Command Center vocabulary so
        // existing bookmarks, redirects, and in-page CTAs keep resolving.
        $alias = array(
            'workflow' => 'dashboard',
            'audit'    => 'scan',
            'analyze'  => 'opportunities',
            'repair'   => 'fixqueue',
            'report'   => 'reports',
            'index'    => 'indexing',
            'insights' => 'reports',
            'settings' => 'setup',
        );
        if ( isset( $alias[ $tab ] ) ) {
            $tab = $alias[ $tab ];
        }
        return in_array( $tab, $allowed, true ) ? $tab : 'dashboard';
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
            'dashboard'     => __( 'Dashboard', 'real-smart-seo' ),
            'setup'         => __( 'Setup', 'real-smart-seo' ),
            'scan'          => __( 'Site Scan', 'real-smart-seo' ),
            'opportunities' => __( 'Opportunities', 'real-smart-seo' ),
            'fixqueue'      => __( 'Fix Queue', 'real-smart-seo' ),
            'pagebuilder'   => __( 'Page Builder', 'real-smart-seo' ),
            'indexing'      => __( 'Indexing', 'real-smart-seo' ),
            'reports'       => __( 'Reports', 'real-smart-seo' ),
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
            case 'setup':         $this->page_setup();           break;
            case 'scan':          $this->page_site_audit();      break;
            case 'opportunities': $this->page_new_scan();        break;
            case 'fixqueue':      $this->page_fix_queue();       break;
            case 'pagebuilder':   $this->page_pagebuilder();     break;
            case 'indexing':      $this->page_index();           break;
            case 'reports':       $this->page_reports_archive(); break;
            case 'dashboard':
            default:
                $this->page_dashboard();
                break;
        }
        // Pipeline CTA — every tab IN THE PIPELINE ends with a "Next: X →" so
        // the user always knows the one button to click after finishing here.
        // Settings + Workflow + Reports (archive) are excluded — they aren't
        // pipeline steps. Reports is just history; its empty state stands alone.
        if ( ! in_array( $active, array( 'dashboard', 'setup', 'reports' ), true ) ) {
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
            'scan'          => admin_url( 'admin.php?page=real-smart-seo&tab=scan' ),
            'opportunities' => admin_url( 'admin.php?page=real-smart-seo&tab=opportunities' . $report_qs ),
            'fixqueue'      => admin_url( 'admin.php?page=real-smart-seo&tab=fixqueue' . $report_qs ),
            'indexing'      => admin_url( 'admin.php?page=real-smart-seo&tab=indexing' ),
            'reports'       => admin_url( 'admin.php?page=real-smart-seo&tab=reports' ),
        );

        $cta = array( 'label' => '', 'href' => '', 'hint' => '' );

        switch ( $current ) {
            case 'scan':
                $cta = array( 'label' => __( 'Find Opportunities →', 'real-smart-seo' ), 'href' => $url['opportunities'], 'hint' => __( 'Turn the scan into a prioritized list of fixes and content opportunities.', 'real-smart-seo' ) );
                break;
            case 'opportunities':
                $cta = $has_report
                    ? array( 'label' => __( 'Go to Fix Queue →', 'real-smart-seo' ), 'href' => $url['fixqueue'], 'hint' => __( 'Review each recommended fix, preview the before/after, and apply.', 'real-smart-seo' ) )
                    : array( 'label' => __( 'Run a scan first →', 'real-smart-seo' ), 'href' => $url['scan'], 'hint' => __( 'Opportunities need scan data. Start with Site Scan.', 'real-smart-seo' ) );
                break;
            case 'fixqueue':
                $cta = $has_report
                    ? array( 'label' => __( 'Continue to Indexing →', 'real-smart-seo' ), 'href' => $url['indexing'], 'hint' => __( 'Once fixes are applied, push the updated URLs to Google + Bing so they get recrawled fast.', 'real-smart-seo' ) )
                    : array( 'label' => __( 'Find Opportunities first →', 'real-smart-seo' ), 'href' => $url['opportunities'], 'hint' => __( 'No fixes queued yet. Generate them on the Opportunities tab.', 'real-smart-seo' ) );
                break;
            case 'pagebuilder':
                $cta = array( 'label' => __( 'Continue to Indexing →', 'real-smart-seo' ), 'href' => $url['indexing'], 'hint' => __( 'After building pages, submit them to search engines so they get indexed.', 'real-smart-seo' ) );
                break;
            case 'indexing':
                $cta = array( 'label' => __( 'View Reports →', 'real-smart-seo' ), 'href' => $url['reports'], 'hint' => __( 'Track what changed — issues fixed, pages built, URLs submitted — and the next recommended action.', 'real-smart-seo' ) );
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
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-indexnow' ) ); ?>"><?php esc_html_e( 'Configure IndexNow →', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'IndexNow', 'real-smart-seo' ), __( 'Bing/Yandex/Seznam/Naver get a notification the moment any post is published, edited, or trashed. Critical for time-sensitive content like job postings.', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'GSC Coverage Cleanup', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Auto-resolve Search Console errors — duplicate canonicals, soft 404s, "discovered but not indexed" pages.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-gsc-cleanup' ) ); ?>"><?php esc_html_e( 'GSC Cleanup →', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'GSC Cleanup', 'real-smart-seo' ), __( 'Bulk-fix duplicate canonicals, soft 404s, and "Discovered but not indexed" pages from your GSC Coverage report.', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'Rapid URL Indexer', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Force-index stubborn URLs via third-party indexing services (paid). Optional — useful for fresh programmatic pages.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-indexnow#rapid' ) ); ?>"><?php esc_html_e( 'Setup →', 'real-smart-seo' ); ?></a>
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
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-ai-rank' ) ); ?>"><?php esc_html_e( 'Open AI Rank', 'real-smart-seo' ); ?></a>
                    <?php else :
                        $this->pro_upsell( __( 'AI Rank Tracking', 'real-smart-seo' ), __( 'Daily check of where you rank for every target keyword on Google + Bing + ChatGPT + Perplexity. History charts so you can prove the work.', 'real-smart-seo' ) );
                    endif; ?>
                </div>

                <div class="rsseo-insights-card">
                    <h3><?php esc_html_e( 'Local Rank Grid (Local Falcon-style)', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Map-pack rank measured at a grid of geographic points around your business — same idea as Local Falcon. See heat-map style coverage of where you rank #1 vs. where you fall off.', 'real-smart-seo' ); ?></p>
                    <?php if ( $has_pro ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-geogrid' ) ); ?>"><?php esc_html_e( 'Open Geo-Grid →', 'real-smart-seo' ); ?></a>
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
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-gsc-cleanup' ) ); ?>"><?php esc_html_e( 'GSC Cleanup', 'real-smart-seo' ); ?></a>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-indexnow' ) ); ?>"><?php esc_html_e( 'IndexNow', 'real-smart-seo' ); ?></a>
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
        $scans      = RSSEO_Database::get_scans( 5 );
        $usage      = RSSEO_Database::get_monthly_usage();
        $has_key    = RSSEO_Settings::has_api_key();
        $seo_plugin = RSSEO_Settings::detect_seo_plugin();
        $growth     = $this->growth_stats();
        $next       = $this->next_action();
        require RSSEO_PATH . 'includes/views/dashboard.php';
    }

    /**
     * "What changed" tallies for the Growth Dashboard — the work the loop has
     * actually done. Each metric is guarded so a missing table/post-type can't
     * fatal.
     *
     * @return array{fixes_applied:int,pages_built:int,schema_applied:int,urls_submitted:int}
     */
    private function growth_stats() {
        global $wpdb;
        $stats = array( 'fixes_applied' => 0, 'pages_built' => 0, 'schema_applied' => 0, 'urls_submitted' => 0 );

        $fixes = $wpdb->prefix . 'rsseo_fixes';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fixes ) ) === $fixes ) { // phpcs:ignore WordPress.DB
            $stats['fixes_applied'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$fixes} WHERE applied = 1" ); // phpcs:ignore WordPress.DB
        }
        $schema = $wpdb->prefix . 'rsseo_pro_schema';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $schema ) ) === $schema ) { // phpcs:ignore WordPress.DB
            $stats['schema_applied'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$schema} WHERE applied = 1" ); // phpcs:ignore WordPress.DB
        }
        if ( post_type_exists( 'mfc_location' ) ) {
            $counts = wp_count_posts( 'mfc_location' );
            $stats['pages_built'] = (int) ( $counts->publish ?? 0 );
        }
        $logs = get_option( 'rsseo_indexnow_logs', array() );
        $stats['urls_submitted'] = is_array( $logs ) ? count( $logs ) : 0;

        return $stats;
    }

    /**
     * The single next recommended action, chosen from setup readiness + scan
     * state, so the Dashboard always points at the one button to click next.
     *
     * @return array{label:string,desc:string,url:string}
     */
    private function next_action() {
        $url = function ( $tab ) {
            return admin_url( 'admin.php?page=real-smart-seo&tab=' . $tab );
        };

        // 1) Setup incomplete?
        if ( class_exists( 'RSSEO_Profile' ) && RSSEO_Profile::MISSING === RSSEO_Profile::overall_status() ) {
            return array(
                'label' => __( 'Finish Setup', 'real-smart-seo' ),
                'desc'  => __( 'A required field or your API key is missing — finish Setup before scanning.', 'real-smart-seo' ),
                'url'   => $url( 'setup' ),
            );
        }

        // 2) No scans yet?
        $scans  = RSSEO_Database::get_scans( 1 );
        $latest = ! empty( $scans ) ? $scans[0] : null;
        if ( ! $latest ) {
            return array(
                'label' => __( 'Run your first scan', 'real-smart-seo' ),
                'desc'  => __( 'Crawl your site to surface issues and opportunities.', 'real-smart-seo' ),
                'url'   => $url( 'scan' ),
            );
        }

        // 3) Pending fixes in the latest report?
        $report_id = (int) ( $latest->report_id ?? 0 );
        $report    = $report_id ? RSSEO_Database::get_report( $report_id ) : null;
        if ( $report ) {
            $pending = max( 0, (int) $report->fixes_available - (int) $report->fixes_applied );
            if ( $pending > 0 ) {
                return array(
                    'label' => sprintf(
                        /* translators: %d: number of pending fixes */
                        _n( 'Apply %d fix in the Fix Queue', 'Apply %d fixes in the Fix Queue', $pending, 'real-smart-seo' ),
                        $pending
                    ),
                    'desc'  => __( 'Preview and apply the recommended fixes from your latest analysis.', 'real-smart-seo' ),
                    'url'   => admin_url( 'admin.php?page=real-smart-seo&tab=fixqueue&report_id=' . $report_id ),
                );
            }
        }

        // 4) Local pages to build?
        if ( class_exists( 'RSSEO_Opportunities' ) ) {
            $gaps = RSSEO_Opportunities::local_gaps();
            if ( ! empty( $gaps ) ) {
                return array(
                    'label' => sprintf(
                        /* translators: %d: number of local page gaps */
                        _n( 'Build %d local page', 'Build %d local pages', count( $gaps ), 'real-smart-seo' ),
                        count( $gaps )
                    ),
                    'desc'  => __( 'You serve these service × city combinations but have no page for them yet.', 'real-smart-seo' ),
                    'url'   => $url( 'pagebuilder' ),
                );
            }
        }

        // 5) Otherwise — keep things indexed.
        return array(
            'label' => __( 'Submit updated URLs', 'real-smart-seo' ),
            'desc'  => __( 'Ping Google + Bing so recent changes get recrawled fast.', 'real-smart-seo' ),
            'url'   => $url( 'indexing' ),
        );
    }

    // ── Page: New Scan ─────────────────────────────────────────────────────────

    public function page_new_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $has_key = RSSEO_Settings::has_api_key();

        // Background analysis in flight? Show the progress panel — it polls and
        // redirects to the report when the job finishes. (Set after a scan is
        // submitted, before the report exists.)
        $scan_id   = isset( $_GET['scan_id'] ) ? (int) $_GET['scan_id'] : 0;     // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $report_id = isset( $_GET['report_id'] ) ? (int) $_GET['report_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $scan_id > 0 && $report_id <= 0 ) {
            $job = RSSEO_Jobs::status( $scan_id );
            if ( in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
                $this->render_analysis_progress( $scan_id, $job );
                return;
            }
            if ( 'complete' === $job['status'] && ! empty( $job['report_id'] ) ) {
                $report_id = (int) $job['report_id'];
            } elseif ( 'error' === $job['status'] ) {
                echo '<div class="rsseo-notice rsseo-notice--error" style="margin-bottom:18px;"><strong>' . esc_html__( 'Analysis failed:', 'real-smart-seo' ) . '</strong> ' . esc_html( $job['message'] ) . '</div>';
            }
        }

        // Analysis done → show the Opportunity Map (findings grouped by action),
        // with a CTA into the Fix Queue where fixes are previewed + applied.
        if ( $report_id > 0 ) {
            $report = RSSEO_Database::get_report( $report_id );
            if ( $report ) {
                $url_fixqueue = admin_url( 'admin.php?page=real-smart-seo&tab=fixqueue&report_id=' . (int) $report_id );
                echo '<div class="rsseo-notice rsseo-notice--success" style="margin-bottom:18px;"><strong>' . esc_html__( '✓ Analysis complete.', 'real-smart-seo' ) . '</strong> ' . esc_html__( 'Findings are grouped into opportunities below. Open the Fix Queue to preview and apply the on-page fixes.', 'real-smart-seo' ) . '</div>';

                // Inline rename so the user can name + save this analysis and
                // find it later in Reports.
                $scan_label = isset( $report->label ) ? (string) $report->label : '';
                ?>
                <div class="rsseo-rename-bar">
                    <label for="rsseo-scan-name"><strong><?php esc_html_e( 'Save this analysis as:', 'real-smart-seo' ); ?></strong></label>
                    <input type="text" id="rsseo-scan-name" class="regular-text" data-scan-id="<?php echo esc_attr( $report->scan_id ?? 0 ); ?>" value="<?php echo esc_attr( $scan_label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Q2 Carpet Cleaning audit', 'real-smart-seo' ); ?>">
                    <button type="button" class="button button-secondary" id="rsseo-save-scan-name"><?php esc_html_e( 'Save name', 'real-smart-seo' ); ?></button>
                    <span class="rsseo-rename-status" aria-live="polite"></span>
                </div>
                <?php
                $this->render_opportunity_map( (int) $report_id );

                echo '<div class="rsseo-next-step" style="margin-top:20px;">';
                echo '<h2 style="margin-top:0;">' . esc_html__( 'Next: work the Fix Queue', 'real-smart-seo' ) . '</h2>';
                echo '<p>' . esc_html__( 'Preview each recommended fix, then apply it. Every change is reversible from the post editor.', 'real-smart-seo' ) . '</p>';
                echo '<p><a class="button button-primary button-large" href="' . esc_url( $url_fixqueue ) . '">' . esc_html__( 'Go to Fix Queue →', 'real-smart-seo' ) . '</a></p>';
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

    // ── Page: Fix Queue ──────────────────────────────────────────────────────

    /**
     * Fix Queue — the recommended fixes for the most recent (or selected)
     * analysis, with preview / apply / rollback. Phase 5 turns the report-detail
     * table into prioritized action cards; for now it reuses the existing fix
     * table so nothing is lost during the transition.
     */
    public function page_fix_queue() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $report_id = isset( $_GET['report_id'] ) ? (int) $_GET['report_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $report_id ) {
            // Fall back to the latest analysis so the queue is never empty by accident.
            $scans     = RSSEO_Database::get_scans( 1 );
            $report_id = (int) ( ! empty( $scans ) ? ( $scans[0]->report_id ?? 0 ) : 0 );
        }
        $report = $report_id ? RSSEO_Database::get_report( $report_id ) : null;
        if ( $report ) {
            $fixes = RSSEO_Database::get_fixes( $report_id );
            require RSSEO_PATH . 'includes/views/fix-queue.php';
            return;
        }
        echo '<div class="rsseo-empty">';
        echo '<h2>' . esc_html__( 'No fixes queued yet', 'real-smart-seo' ) . '</h2>';
        echo '<p>' . esc_html__( 'Run a site scan and generate opportunities — recommended fixes will land here ready to preview and apply.', 'real-smart-seo' ) . '</p>';
        echo '<a class="button button-primary button-large" href="' . esc_url( admin_url( 'admin.php?page=real-smart-seo&tab=scan' ) ) . '">' . esc_html__( 'Run a Site Scan →', 'real-smart-seo' ) . '</a>';
        echo '</div>';
    }

    // ── Page: Reports (archive + measure) ────────────────────────────────────

    /**
     * Reports — history of past analyses plus the measurement tools (rankings,
     * geo-grid, backlinks). Single-report drill-down still works via ?report_id.
     */
    public function page_reports_archive() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }

        // Single report drill-down (kept for ?report_id deep links).
        if ( isset( $_GET['report_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $report_id = (int) $_GET['report_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $report    = RSSEO_Database::get_report( $report_id );
            if ( $report ) {
                $fixes = RSSEO_Database::get_fixes( $report_id );
                require RSSEO_PATH . 'includes/views/report-detail.php';
                return;
            }
        }

        // Measurement tools — the rankings/backlinks that used to hide on the
        // old "Insights" tab now live here, where you measure what changed.
        $has_pro = defined( 'RSSEO_PRO_VERSION' );
        if ( $has_pro ) {
            echo '<h2>' . esc_html__( 'Tracking & Measurement', 'real-smart-seo' ) . '</h2>';
            echo '<div class="rsseo-insights-grid">';

            echo '<div class="rsseo-insights-card"><h3>' . esc_html__( 'Keyword Rankings', 'real-smart-seo' ) . '</h3>';
            echo '<p>' . esc_html__( 'Where you rank for each target keyword across Google, Bing, and AI search engines.', 'real-smart-seo' ) . '</p>';
            echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=rsseo-ai-rank' ) ) . '">' . esc_html__( 'Open AI Rank →', 'real-smart-seo' ) . '</a></div>';

            echo '<div class="rsseo-insights-card"><h3>' . esc_html__( 'Local Rank Grid', 'real-smart-seo' ) . '</h3>';
            echo '<p>' . esc_html__( 'Local Falcon-style map-pack rank measured at a grid of points around your business.', 'real-smart-seo' ) . '</p>';
            echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=rsseo-geogrid' ) ) . '">' . esc_html__( 'Open Geo-Grid →', 'real-smart-seo' ) . '</a></div>';

            echo '<div class="rsseo-insights-card rsseo-insights-card--wide"><h3>' . esc_html__( 'Backlinks', 'real-smart-seo' ) . '</h3>';
            echo '<p>' . esc_html__( 'Inbound link profile, gained / lost links, and toxic-link warnings.', 'real-smart-seo' ) . '</p>';
            if ( has_action( 'rsseo_render_backlinks_panel' ) ) {
                do_action( 'rsseo_render_backlinks_panel' );
            }
            echo '</div>';

            echo '</div>';
            echo '<hr style="margin:28px 0;">';
        }

        echo '<h2>' . esc_html__( 'Analysis History', 'real-smart-seo' ) . '</h2>';
        $scans = RSSEO_Database::get_scans( 100 );
        require RSSEO_PATH . 'includes/views/reports-list.php';
    }

    // ── Page: Page Builder ────────────────────────────────────────────────────

    /**
     * Page Builder — surfaces the page-generation tools as a first-class step
     * instead of burying them in hidden Pro submenus. Programmatic city×service
     * pages are the primary builder; clusters + content briefs support it.
     */
    public function page_pagebuilder() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $cards = array(
            array(
                'slug'    => 'rsseo-programmatic',
                'title'   => __( 'Programmatic City × Service Pages', 'real-smart-seo' ),
                'desc'    => __( 'Generate a landing page for every service in every city you serve — pick services, cities, template, tone, and CTA, then publish as draft or live.', 'real-smart-seo' ),
                'cta'     => __( 'Open Page Builder →', 'real-smart-seo' ),
                'primary' => true,
            ),
            array(
                'slug'    => 'rsseo-clusters',
                'title'   => __( 'Topic Clusters', 'real-smart-seo' ),
                'desc'    => __( 'Group your target keywords into topic clusters so each page targets one clear intent instead of competing with itself.', 'real-smart-seo' ),
                'cta'     => __( 'Build Clusters →', 'real-smart-seo' ),
                'primary' => false,
            ),
            array(
                'slug'    => 'rsseo-content-brief',
                'title'   => __( 'Content Briefs', 'real-smart-seo' ),
                'desc'    => __( 'Generate an AI writer brief for any keyword — headings, questions to answer, entities to mention — before you build the page.', 'real-smart-seo' ),
                'cta'     => __( 'Create a Brief →', 'real-smart-seo' ),
                'primary' => false,
            ),
        );
        echo '<div class="rsseo-pagebuilder">';
        echo '<h2>' . esc_html__( 'Page Builder', 'real-smart-seo' ) . '</h2>';
        echo '<p>' . esc_html__( 'Build the local pages that win the map pack: a dedicated page for each service in each city you serve. Plan the topics, brief the content, then generate the pages.', 'real-smart-seo' ) . '</p>';

        // Coverage summary driven by the Setup profile.
        $p        = RSSEO_Profile::get();
        $services = RSSEO_Profile::lines( $p['services'] );
        $cities   = RSSEO_Profile::lines( $p['cities'] );
        if ( empty( $services ) || empty( $cities ) ) {
            echo '<div class="rsseo-notice rsseo-notice--warning" style="margin:0 0 16px;"><strong>' . esc_html__( 'Add your services and cities in Setup', 'real-smart-seo' ) . '</strong> — ' . esc_html__( 'the builder uses them to map out your local pages.', 'real-smart-seo' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=real-smart-seo&tab=setup' ) ) . '">' . esc_html__( 'Go to Setup →', 'real-smart-seo' ) . '</a></div>';
        } else {
            $possible = count( $services ) * count( $cities );
            $gaps     = class_exists( 'RSSEO_Opportunities' ) ? count( RSSEO_Opportunities::local_gaps() ) : 0;
            echo '<div class="rsseo-coverage" style="background:#fff;border-radius:8px;padding:14px 16px;margin:0 0 18px;box-shadow:0 1px 2px rgba(0,0,0,.06);">';
            echo '<strong>' . esc_html( sprintf(
                /* translators: 1: services count, 2: cities count, 3: possible pages */
                __( '%1$d services × %2$d cities = %3$d possible local pages.', 'real-smart-seo' ),
                count( $services ),
                count( $cities ),
                $possible
            ) ) . '</strong> ';
            if ( $gaps > 0 ) {
                echo '<span style="color:#b45309;font-weight:700;">' . esc_html( sprintf(
                    /* translators: %d: number of gaps */
                    _n( '%d gap not yet covered.', '%d gaps not yet covered.', $gaps, 'real-smart-seo' ),
                    $gaps
                ) ) . '</span>';
            } else {
                echo '<span style="color:#0a8754;font-weight:700;">' . esc_html__( 'Every combination has a page — nice coverage.', 'real-smart-seo' ) . '</span>';
            }
            echo '</div>';
        }

        echo '<div class="rsseo-insights-grid">';
        foreach ( $cards as $c ) {
            echo '<div class="rsseo-insights-card">';
            echo '<h3>' . esc_html( $c['title'] ) . '</h3>';
            echo '<p>' . esc_html( $c['desc'] ) . '</p>';
            printf(
                '<a class="button %1$s" href="%2$s">%3$s</a>',
                $c['primary'] ? 'button-primary' : 'button-secondary',
                esc_url( admin_url( 'admin.php?page=' . $c['slug'] ) ),
                esc_html( $c['cta'] )
            );
            echo '</div>';
        }
        echo '</div></div>';
    }

    // ── Page: Setup ──────────────────────────────────────────────────────────

    /**
     * Setup — the pre-flight. Shows a Ready / Missing / Needs Attention
     * readiness panel, then the unified business profile + API key + model in
     * one form, so the operator configures everything before entering the loop
     * (no more "hit Scan, then discover you need a key").
     */
    public function page_setup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $readiness  = RSSEO_Profile::readiness();
        $overall    = RSSEO_Profile::overall_status();
        $profile    = RSSEO_Profile::get();
        $has_key    = RSSEO_Settings::has_api_key();
        $model      = RSSEO_Settings::get_model();
        $max_tokens = RSSEO_Settings::get_max_tokens();
        $has_pro    = defined( 'RSSEO_PRO_VERSION' );
        require RSSEO_PATH . 'includes/views/setup.php';
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

        // Queue the AI analysis as a background job instead of blocking this
        // POST on a ~2-minute Perplexity call. The Opportunities tab polls for
        // progress and swaps in the report when it's ready. A Pro add-on can
        // still take over the analysis via the rsseo_run_analyzer filter inside
        // the job runner.
        RSSEO_Jobs::enqueue( $scan_id );

        wp_redirect( add_query_arg( array(
            'page'    => 'real-smart-seo',
            'tab'     => 'opportunities',
            'scan_id' => (int) $scan_id,
            'queued'  => 1,
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

    // ── AJAX: Background analysis status ──────────────────────────────────────

    /**
     * Polled by the Opportunities progress panel. Returns the background job's
     * state for a scan, plus the URL to send the browser to once it completes.
     */
    public function ajax_analysis_status() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo' ) );
        }
        $scan_id = isset( $_POST['scan_id'] ) ? (int) $_POST['scan_id'] : 0;
        if ( $scan_id <= 0 ) {
            wp_send_json_error( __( 'Invalid scan.', 'real-smart-seo' ) );
        }
        $job      = RSSEO_Jobs::status( $scan_id );
        $redirect = '';
        if ( 'complete' === $job['status'] && ! empty( $job['report_id'] ) ) {
            $redirect = admin_url( 'admin.php?page=real-smart-seo&tab=opportunities&report_id=' . (int) $job['report_id'] );
        }
        wp_send_json_success( array(
            'status'   => $job['status'],
            'message'  => $job['message'],
            'redirect' => $redirect,
        ) );
    }

    /**
     * Progress panel shown while the background analysis runs. Polls
     * ajax_analysis_status and redirects to the report when complete.
     */
    private function render_analysis_progress( $scan_id, $job ) {
        $running = 'running' === $job['status'];
        ?>
        <div class="rsseo-tabview rsseo-analysis-progress">
            <h2><?php esc_html_e( 'Analyzing your site…', 'real-smart-seo' ); ?></h2>
            <div class="rsseo-progress-card" style="background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.08);max-width:640px;">
                <p style="display:flex;align-items:center;gap:12px;font-size:15px;margin-top:0;">
                    <span class="spinner is-active" style="float:none;margin:0;"></span>
                    <span id="rsseo-progress-text"><?php echo esc_html( $running ? __( 'Perplexity is reviewing your scan data and writing prioritized fixes…', 'real-smart-seo' ) : __( 'Queued — starting analysis…', 'real-smart-seo' ) ); ?></span>
                </p>
                <p class="description"><?php esc_html_e( 'This runs in the background — you can leave this page and come back. Findings will appear here automatically when ready (usually under a minute).', 'real-smart-seo' ); ?></p>
                <p id="rsseo-progress-error" class="rsseo-notice rsseo-notice--error" style="display:none;"></p>
            </div>
        </div>
        <script>
        (function($){
            var d = window.rsseoData || {};
            var ajaxUrl = d.ajax_url || ajaxurl;
            var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'rsseo_nonce' ) ); ?>;
            var scanId  = <?php echo (int) $scan_id; ?>;
            var tries   = 0;
            function poll(){
                tries++;
                $.post(ajaxUrl, { action:'rsseo_analysis_status', nonce:nonce, scan_id:scanId }, function(res){
                    if(!res || !res.success){ return schedule(); }
                    var s = res.data || {};
                    if(s.status === 'complete' && s.redirect){ window.location = s.redirect; return; }
                    if(s.status === 'error'){
                        $('#rsseo-progress-text').text(<?php echo wp_json_encode( __( 'Analysis failed.', 'real-smart-seo' ) ); ?>);
                        $('.rsseo-analysis-progress .spinner').removeClass('is-active');
                        $('#rsseo-progress-error').text(s.message || 'Error').show();
                        return;
                    }
                    if(s.status === 'running'){ $('#rsseo-progress-text').text(<?php echo wp_json_encode( __( 'Perplexity is reviewing your scan data and writing prioritized fixes…', 'real-smart-seo' ) ); ?>); }
                    schedule();
                }).fail(schedule);
            }
            function schedule(){ if(tries < 120){ setTimeout(poll, 3000); } }
            poll();
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Opportunity Map — findings regrouped into Quick Wins / Local SEO Gaps /
     * Content Opportunities / Technical Issues, each item badged with its
     * lifecycle status and linked to where it gets acted on.
     */
    private function render_opportunity_map( $report_id ) {
        $groups = RSSEO_Opportunities::groups( (int) $report_id );
        $meta   = RSSEO_Opportunities::buckets();
        $total  = RSSEO_Opportunities::total( $groups );

        echo '<div class="rsseo-oppmap">';
        if ( 0 === $total ) {
            echo '<p class="description">' . esc_html__( 'No opportunities detected in this scan.', 'real-smart-seo' ) . '</p></div>';
            return;
        }
        echo '<div class="rsseo-oppmap__grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">';
        foreach ( $meta as $key => $m ) {
            $items = isset( $groups[ $key ] ) ? $groups[ $key ] : array();
            echo '<div class="rsseo-oppmap__bucket" style="background:#fff;border-top:4px solid ' . esc_attr( $m['color'] ) . ';border-radius:8px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.06);">';
            echo '<h3 style="margin:0 0 2px;">' . esc_html( $m['title'] ) . ' <span style="color:' . esc_attr( $m['color'] ) . ';">(' . count( $items ) . ')</span></h3>';
            echo '<p class="description" style="margin:0 0 12px;">' . esc_html( $m['desc'] ) . '</p>';
            if ( empty( $items ) ) {
                echo '<p class="description"><em>' . esc_html__( 'Nothing here — nice.', 'real-smart-seo' ) . '</em></p>';
            } else {
                echo '<ul style="margin:0;list-style:none;padding:0;">';
                foreach ( array_slice( $items, 0, 8 ) as $it ) {
                    echo '<li style="padding:7px 0;border-bottom:1px solid #f0f0f1;">';
                    echo RSSEO_Status::badge( $it['status'] ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo '<a href="' . esc_url( $it['action_url'] ) . '" style="font-weight:600;text-decoration:none;">' . esc_html( $it['label'] ) . '</a>';
                    if ( ! empty( $it['detail'] ) ) {
                        echo '<div class="description" style="font-size:12px;margin-top:2px;">' . esc_html( $it['detail'] ) . '</div>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
                if ( count( $items ) > 8 ) {
                    echo '<p style="margin:10px 0 0;"><a href="' . esc_url( $items[0]['action_url'] ) . '">' . esc_html( sprintf(
                        /* translators: %d: number of additional items */
                        __( '+ %d more →', 'real-smart-seo' ),
                        count( $items ) - 8
                    ) ) . '</a></p>';
                }
            }
            echo '</div>';
        }
        echo '</div></div>';
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
            $raw = wp_unslash( $_POST['rsseo_business_profile'] );
            // Persist through the unified profile model (it sanitises and also
            // mirrors back into the legacy rsseo_business_profile option).
            RSSEO_Profile::save( array(
                'business_name' => $raw['name']          ?? '',
                'category'      => $raw['category']      ?? '',
                'services'      => $raw['services']      ?? '',
                'cities'        => $raw['service_areas'] ?? '',
                'gbp_url'       => $raw['gmb_url']        ?? '',
                'competitors'   => $raw['competitors']   ?? '',
            ) );
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
