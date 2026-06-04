<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
// Rendered inside the Command Center shell (RSSEO_Admin::render_tabbed_page),
// which already provides the .wrap, the page <h1>, and the tab nav — so this
// view emits inner content only (no second .wrap / duplicate heading).
$url_setup   = admin_url( 'admin.php?page=real-smart-seo&tab=setup' );
$url_scan    = admin_url( 'admin.php?page=real-smart-seo&tab=scan' );
$url_reports = admin_url( 'admin.php?page=real-smart-seo&tab=reports' );
?>
<div class="rsseo-dashboard">

    <?php if ( ! $has_key ) : ?>
    <div class="rsseo-notice rsseo-notice--warning">
        <strong><?php esc_html_e( 'Setup required:', 'real-smart-seo' ); ?></strong>
        <?php esc_html_e( 'Add your Perplexity API key in', 'real-smart-seo' ); ?>
        <a href="<?php echo esc_url( $url_setup ); ?>"><?php esc_html_e( 'Setup', 'real-smart-seo' ); ?></a>
        <?php esc_html_e( 'before you run a scan.', 'real-smart-seo' ); ?>
    </div>
    <?php endif; ?>

    <p class="rsseo-dashboard__lede">
        <?php esc_html_e( 'Your local SEO loop: set up → scan → prioritize → fix → build → index → measure. Start a scan, then work the Opportunities and Fix Queue tabs.', 'real-smart-seo' ); ?>
    </p>

    <?php if ( ! empty( $next ) ) : ?>
    <div class="rsseo-next-action" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;background:#fff;border-left:5px solid #2271b1;border-radius:8px;padding:16px 18px;margin:0 0 20px;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#2271b1;font-weight:700;"><?php esc_html_e( 'Next recommended action', 'real-smart-seo' ); ?></div>
            <strong style="font-size:16px;"><?php echo esc_html( $next['label'] ); ?></strong>
            <div class="description" style="margin-top:2px;"><?php echo esc_html( $next['desc'] ); ?></div>
        </div>
        <a class="button button-primary button-large" href="<?php echo esc_url( $next['url'] ); ?>"><?php echo esc_html( $next['label'] ); ?> →</a>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $growth ) ) : ?>
    <h2><?php esc_html_e( 'What changed', 'real-smart-seo' ); ?></h2>
    <div class="rsseo-growth" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:24px;">
        <?php
        $cards = array(
            array( __( 'Fixes applied', 'real-smart-seo' ),    (int) $growth['fixes_applied'] ),
            array( __( 'Pages built', 'real-smart-seo' ),      (int) $growth['pages_built'] ),
            array( __( 'Schema added', 'real-smart-seo' ),     (int) $growth['schema_applied'] ),
            array( __( 'URLs submitted', 'real-smart-seo' ),   (int) $growth['urls_submitted'] ),
        );
        foreach ( $cards as $c ) :
            ?>
            <div class="rsseo-growth__stat" style="background:#fff;border-radius:8px;padding:16px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.06);">
                <div style="font-size:30px;font-weight:800;line-height:1;color:#1d2327;"><?php echo esc_html( number_format_i18n( $c[1] ) ); ?></div>
                <div class="description" style="margin-top:6px;"><?php echo esc_html( $c[0] ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="rsseo-cards">

        <div class="rsseo-card rsseo-card--action">
            <h2><?php esc_html_e( 'Scan your site', 'real-smart-seo' ); ?></h2>
            <p><?php esc_html_e( 'Crawl your pages (or upload Screaming Frog / GSC / GA / PageSpeed data) to surface issues and opportunities with one-click fixes.', 'real-smart-seo' ); ?></p>
            <a href="<?php echo esc_url( $url_scan ); ?>" class="button button-primary button-large">
                <?php esc_html_e( 'Scan My Site →', 'real-smart-seo' ); ?>
            </a>
        </div>

        <div class="rsseo-card">
            <h3><?php esc_html_e( 'This Month', 'real-smart-seo' ); ?></h3>
            <div class="rsseo-stats">
                <div class="rsseo-stat">
                    <span class="rsseo-stat__number"><?php echo esc_html( $usage->scans ?? 0 ); ?></span>
                    <span class="rsseo-stat__label"><?php esc_html_e( 'Scans', 'real-smart-seo' ); ?></span>
                </div>
                <div class="rsseo-stat">
                    <span class="rsseo-stat__number"><?php echo esc_html( number_format( $usage->total_tokens ?? 0 ) ); ?></span>
                    <span class="rsseo-stat__label"><?php esc_html_e( 'Tokens Used', 'real-smart-seo' ); ?></span>
                </div>
                <div class="rsseo-stat">
                    <span class="rsseo-stat__number">$<?php echo esc_html( number_format( $usage->total_cost ?? 0, 4 ) ); ?></span>
                    <span class="rsseo-stat__label"><?php esc_html_e( 'Est. Cost', 'real-smart-seo' ); ?></span>
                </div>
            </div>
        </div>

        <div class="rsseo-card">
            <h3><?php esc_html_e( 'Environment', 'real-smart-seo' ); ?></h3>
            <ul class="rsseo-checklist">
                <li class="<?php echo $has_key ? 'rsseo-check--ok' : 'rsseo-check--missing'; ?>">
                    <?php esc_html_e( 'Perplexity API Key', 'real-smart-seo' ); ?>
                </li>
                <li class="<?php echo 'none' !== $seo_plugin ? 'rsseo-check--ok' : 'rsseo-check--info'; ?>">
                    <?php
                    if ( 'yoast' === $seo_plugin ) {
                        esc_html_e( 'Yoast SEO detected', 'real-smart-seo' );
                    } elseif ( 'rankmath' === $seo_plugin ) {
                        esc_html_e( 'Rank Math detected', 'real-smart-seo' );
                    } else {
                        esc_html_e( 'No SEO plugin detected (fixes will use post meta)', 'real-smart-seo' );
                    }
                    ?>
                </li>
            </ul>
        </div>

    </div>

    <?php if ( ! empty( $scans ) ) : ?>
    <h2><?php esc_html_e( 'Recent Scans', 'real-smart-seo' ); ?></h2>
    <table class="wp-list-table widefat fixed striped rsseo-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Scan', 'real-smart-seo' ); ?></th>
                <th><?php esc_html_e( 'Date', 'real-smart-seo' ); ?></th>
                <th><?php esc_html_e( 'Issues', 'real-smart-seo' ); ?></th>
                <th><?php esc_html_e( 'Fixes', 'real-smart-seo' ); ?></th>
                <th><?php esc_html_e( 'Status', 'real-smart-seo' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $scans as $scan ) : ?>
            <tr>
                <td><?php echo esc_html( $scan->label ); ?></td>
                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $scan->created_at ) ) ); ?></td>
                <td>
                    <?php if ( $scan->report_id ) : ?>
                        <?php if ( $scan->issues_critical ) : ?><span class="rsseo-badge rsseo-badge--critical"><?php echo esc_html( $scan->issues_critical ); ?> C</span><?php endif; ?>
                        <?php if ( $scan->issues_high ) : ?><span class="rsseo-badge rsseo-badge--high"><?php echo esc_html( $scan->issues_high ); ?> H</span><?php endif; ?>
                        <?php if ( $scan->issues_medium ) : ?><span class="rsseo-badge rsseo-badge--medium"><?php echo esc_html( $scan->issues_medium ); ?> M</span><?php endif; ?>
                        <?php if ( $scan->issues_low ) : ?><span class="rsseo-badge rsseo-badge--low"><?php echo esc_html( $scan->issues_low ); ?> L</span><?php endif; ?>
                    <?php else : ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ( $scan->report_id ) : ?>
                        <?php echo esc_html( $scan->fixes_applied ); ?>/<?php echo esc_html( $scan->fixes_available ); ?> applied
                    <?php else : ?>—<?php endif; ?>
                </td>
                <td><span class="rsseo-status rsseo-status--<?php echo esc_attr( $scan->status ); ?>"><?php echo esc_html( $scan->status ); ?></span></td>
                <td>
                    <?php if ( $scan->report_id ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=real-smart-seo&tab=reports&report_id=' . $scan->report_id ) ); ?>" class="button button-small">
                            <?php esc_html_e( 'View Report', 'real-smart-seo' ); ?>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
