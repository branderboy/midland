<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
// Variables from RSSEO_Admin::page_workflow():
//   $has_key, $latest_scan, $latest_report, $pending_fixes, $has_pro,
//   $url_scan, $url_analyze, $url_insights, $url_report, $url_settings
$scan_done    = ! empty( $latest_scan );
$analyze_done = ! empty( $latest_report );
$can_repair   = $analyze_done && (int) $latest_report->fixes_available > 0;
$repair_done  = $can_repair && 0 === (int) $pending_fixes;
$has_insights = $analyze_done;

if ( ! function_exists( 'rsseo_workflow_badge' ) ) {
    function rsseo_workflow_badge( $state ) {
        switch ( $state ) {
            case 'done':   return '<span class="rsseo-step-badge rsseo-step-badge--done">✓ Done</span>';
            case 'active': return '<span class="rsseo-step-badge rsseo-step-badge--active">Next →</span>';
            case 'locked': return '<span class="rsseo-step-badge rsseo-step-badge--locked">Locked</span>';
        }
        return '';
    }
}

if ( ! $has_key ) {
    $state = array( 'scan' => 'locked', 'analyze' => 'locked', 'repair' => 'locked', 'index' => 'locked', 'insights' => 'locked' );
} elseif ( ! $scan_done ) {
    $state = array( 'scan' => 'active', 'analyze' => 'locked', 'repair' => 'locked', 'index' => 'locked', 'insights' => 'locked' );
} elseif ( ! $analyze_done ) {
    $state = array( 'scan' => 'done', 'analyze' => 'active', 'repair' => 'locked', 'index' => 'locked', 'insights' => 'locked' );
} elseif ( $can_repair && ! $repair_done ) {
    $state = array( 'scan' => 'done', 'analyze' => 'done', 'repair' => 'active', 'index' => 'locked', 'insights' => 'locked' );
} else {
    $state = array( 'scan' => 'done', 'analyze' => 'done', 'repair' => 'done', 'index' => 'active', 'insights' => 'active' );
}
?>
<div class="rsseo-workflow">

    <?php if ( ! $has_key ) : ?>
        <div class="rsseo-notice rsseo-notice--warning" style="margin:0 0 18px">
            <strong><?php esc_html_e( 'Setup required:', 'real-smart-seo' ); ?></strong>
            <?php esc_html_e( 'Add your Perplexity API key in Settings before you can run a scan.', 'real-smart-seo' ); ?>
            <a class="button button-primary" href="<?php echo esc_url( $url_settings ); ?>"><?php esc_html_e( 'Add API Key →', 'real-smart-seo' ); ?></a>
        </div>
    <?php endif; ?>

    <ol class="rsseo-steps">

        <li class="rsseo-step rsseo-step--<?php echo esc_attr( $state['scan'] ); ?>">
            <div class="rsseo-step__num">1</div>
            <div class="rsseo-step__body">
                <h3><?php esc_html_e( 'Scan', 'real-smart-seo' ); ?> <?php echo rsseo_workflow_badge( $state['scan'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                <p><?php esc_html_e( 'Run a crawl-based audit of the site and produce a single raw report you can hand off to the AI.', 'real-smart-seo' ); ?></p>
                <p>
                    <a class="button <?php echo $has_key ? 'button-primary' : 'disabled'; ?>"
                       href="<?php echo $has_key ? esc_url( $url_scan ) : '#'; ?>"
                       <?php disabled( ! $has_key ); ?>>
                        <?php esc_html_e( 'Run Scan →', 'real-smart-seo' ); ?>
                    </a>
                </p>
            </div>
        </li>

        <li class="rsseo-step rsseo-step--<?php echo esc_attr( $state['analyze'] ); ?>">
            <div class="rsseo-step__num">2</div>
            <div class="rsseo-step__body">
                <h3><?php esc_html_e( 'Analyze', 'real-smart-seo' ); ?> <?php echo rsseo_workflow_badge( $state['analyze'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                <p><?php esc_html_e( 'Paste the scan output (or Screaming Frog / GSC / GA / PageSpeed exports) into the AI and let Perplexity Sonar turn it into prioritized fixes.', 'real-smart-seo' ); ?></p>
                <p>
                    <a class="button <?php echo $scan_done ? 'button-primary' : 'disabled'; ?>"
                       href="<?php echo $scan_done ? esc_url( $url_analyze ) : '#'; ?>"
                       <?php disabled( ! $scan_done ); ?>>
                        <?php esc_html_e( 'Analyze →', 'real-smart-seo' ); ?>
                    </a>
                </p>
                <?php if ( $analyze_done ) : ?>
                    <p>
                        <?php
                        /* translators: %d: number of generated fixes */
                        printf( esc_html__( 'AI generated %d fix suggestions.', 'real-smart-seo' ), (int) $latest_report->fixes_available );
                        ?>
                    </p>
                <?php endif; ?>
            </div>
        </li>

        <li class="rsseo-step rsseo-step--<?php echo esc_attr( $state['repair'] ); ?>">
            <div class="rsseo-step__num">3</div>
            <div class="rsseo-step__body">
                <h3><?php esc_html_e( 'Apply Fixes', 'real-smart-seo' ); ?> <?php echo rsseo_workflow_badge( $state['repair'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                <p><?php esc_html_e( 'Bulk-apply the AI-suggested fixes to your posts and pages — or review and apply one at a time. The Apply buttons live inside the Insights tab.', 'real-smart-seo' ); ?></p>
                <?php if ( $can_repair && $pending_fixes > 0 ) : ?>
                    <p><strong>
                        <?php
                        /* translators: %d: pending fix count */
                        printf( esc_html__( '%d fixes pending.', 'real-smart-seo' ), (int) $pending_fixes );
                        ?>
                    </strong></p>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url( $url_report ); ?>">
                            <?php esc_html_e( 'Open Insights &amp; Apply Fixes →', 'real-smart-seo' ); ?>
                        </a>
                    </p>
                <?php elseif ( $repair_done ) : ?>
                    <p><?php esc_html_e( 'All fixes applied.', 'real-smart-seo' ); ?></p>
                <?php else : ?>
                    <p><em><?php esc_html_e( 'Available after Analyze finishes.', 'real-smart-seo' ); ?></em></p>
                <?php endif; ?>
            </div>
        </li>

        <li class="rsseo-step rsseo-step--<?php echo esc_attr( $state['index'] ); ?>">
            <div class="rsseo-step__num">4</div>
            <div class="rsseo-step__body">
                <h3><?php esc_html_e( 'Index', 'real-smart-seo' ); ?> <?php echo rsseo_workflow_badge( $state['index'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                <p><?php esc_html_e( 'Push your repaired pages into Google, Bing, and Yandex right now via sitemap submission + IndexNow ping — no waiting on a crawl cycle.', 'real-smart-seo' ); ?></p>
                <p>
                    <a class="button <?php echo $repair_done ? 'button-primary' : 'disabled'; ?>"
                       href="<?php echo $repair_done ? esc_url( $url_index ) : '#'; ?>"
                       <?php disabled( ! $repair_done ); ?>>
                        <?php esc_html_e( 'Open Index Tools →', 'real-smart-seo' ); ?>
                    </a>
                </p>
            </div>
        </li>

        <li class="rsseo-step rsseo-step--<?php echo esc_attr( $state['insights'] ); ?>">
            <div class="rsseo-step__num">5</div>
            <div class="rsseo-step__body">
                <h3><?php esc_html_e( 'Insights', 'real-smart-seo' ); ?> <?php echo rsseo_workflow_badge( $state['insights'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                <p><?php esc_html_e( 'Read the full audit narrative — what was broken, what got fixed, what to track next. The Insights tab also lists every past scan.', 'real-smart-seo' ); ?></p>
                <?php if ( $has_insights ) : ?>
                    <p>
                        <a class="button" href="<?php echo esc_url( $url_report ); ?>">
                            <?php esc_html_e( 'View Latest Insights →', 'real-smart-seo' ); ?>
                        </a>
                        <a class="button" href="<?php echo esc_url( $url_insights ); ?>">
                            <?php esc_html_e( 'All Insights', 'real-smart-seo' ); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p><em><?php esc_html_e( 'Available after Analyze finishes.', 'real-smart-seo' ); ?></em></p>
                <?php endif; ?>
            </div>
        </li>

    </ol>

    <?php if ( $has_pro && $has_insights ) : ?>
        <div class="rsseo-workflow__pro">
            <h3><?php esc_html_e( 'Next: Pro cleanups', 'real-smart-seo' ); ?></h3>
            <p><?php esc_html_e( 'After repairs, run the Pro modules to consolidate the win — GSC error cleanup, Schema, IndexNow ping, Programmatic city × service pages, AI Rank tracking.', 'real-smart-seo' ); ?></p>
            <p>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-gsc' ) ); ?>"><?php esc_html_e( 'GSC Cleanup', 'real-smart-seo' ); ?></a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-schema' ) ); ?>"><?php esc_html_e( 'Schema', 'real-smart-seo' ); ?></a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-programmatic' ) ); ?>"><?php esc_html_e( 'Programmatic Pages', 'real-smart-seo' ); ?></a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-indexnow' ) ); ?>"><?php esc_html_e( 'IndexNow', 'real-smart-seo' ); ?></a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-ai-rank' ) ); ?>"><?php esc_html_e( 'AI Rank', 'real-smart-seo' ); ?></a>
            </p>
        </div>
    <?php endif; ?>

</div>
