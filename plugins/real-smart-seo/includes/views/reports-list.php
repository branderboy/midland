<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Reports archive — every row is one report. A report can stack three layers
 * of data: Internal (Site Audit), External (Screaming Frog / GSC / GA /
 * PageSpeed), and DataForSEO (Pro). Badges in the Sources column tell the
 * user at a glance what fed each report.
 */
$badge = function ( $label, $tone = 'neutral' ) {
    $colors = array(
        'internal' => 'background:#dcfce7;color:#166534;border:1px solid #16a34a;',
        'external' => 'background:#dbeafe;color:#1e40af;border:1px solid #3b82f6;',
        'dfs'      => 'background:#fef3c7;color:#92400e;border:1px solid #f0b429;',
        'neutral'  => 'background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;',
    );
    $style = $colors[ $tone ] ?? $colors['neutral'];
    return sprintf(
        '<span style="display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.03em;margin-right:3px;%s">%s</span>',
        esc_attr( $style ),
        esc_html( $label )
    );
};

$source_meta = array(
    // slug => [ short label, tone, group ]
    'audit' => array( 'Audit',      'internal' ),
    'frog'  => array( 'Frog',       'external' ),
    'gsc'   => array( 'GSC',        'external' ),
    'ga'    => array( 'GA',         'external' ),
    'psi'   => array( 'PageSpeed',  'external' ),
    'dfs'   => array( 'DataForSEO', 'dfs'      ),
);
?>
<div class="rsseo-tabview">

    <h2 style="margin-top:0;"><?php esc_html_e( 'Reports archive', 'real-smart-seo' ); ?></h2>

    <div class="rsseo-legend">
        <strong class="rsseo-legend__title"><?php esc_html_e( 'Data layers:', 'real-smart-seo' ); ?></strong>
        <span class="rsseo-legend__row">
            <?php echo $badge( __( 'Internal', 'real-smart-seo' ), 'internal' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <span class="rsseo-legend__hint"><?php esc_html_e( 'Site Audit (our internal crawl)', 'real-smart-seo' ); ?></span>
        </span>
        <span class="rsseo-legend__row">
            <?php echo $badge( __( 'External', 'real-smart-seo' ), 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <span class="rsseo-legend__hint"><?php esc_html_e( 'Screaming Frog / GSC / GA / PageSpeed pastes + uploads', 'real-smart-seo' ); ?></span>
        </span>
        <span class="rsseo-legend__row">
            <?php echo $badge( 'DataForSEO', 'dfs' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <span class="rsseo-legend__hint"><?php esc_html_e( 'Pro tier — backlinks, SERPs, keyword volume', 'real-smart-seo' ); ?></span>
        </span>
    </div>
    <p class="rsseo-muted"><?php esc_html_e( 'Every report can stack all three layers. Rolling window of the 10 most recent — older runs are auto-pruned.', 'real-smart-seo' ); ?></p>

    <?php if ( empty( $scans ) ) : ?>
        <div class="rsseo-empty-card">
            <span class="rsseo-empty-card__icon">📊</span>
            <p><strong><?php esc_html_e( 'No reports yet.', 'real-smart-seo' ); ?></strong></p>
            <p class="rsseo-muted"><?php esc_html_e( 'Once you run a Scan or an Analysis, the resulting reports appear here.', 'real-smart-seo' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped rsseo-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Report', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'When', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'Sources', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'Tier', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'Issues', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'Fixes', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'real-smart-seo' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $scans as $scan ) :
                $sources = isset( $scan->sources_used ) ? array_filter( explode( ',', (string) $scan->sources_used ) ) : array();
                $tier    = isset( $scan->tier ) && 'pro' === $scan->tier ? 'pro' : 'basic';
                $issues_total = $scan->report_id
                    ? ( (int) $scan->issues_critical + (int) $scan->issues_high + (int) $scan->issues_medium + (int) $scan->issues_low )
                    : 0;
                ?>
                <tr>
                    <td><?php echo esc_html( $scan->label ); ?></td>
                    <td><?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $scan->created_at ) ) ); ?></td>
                    <td>
                        <?php
                        if ( empty( $sources ) ) {
                            echo '<span class="rsseo-muted">—</span>';
                        } else {
                            // Render badges in fixed order: internal → external → dfs.
                            $order = array( 'audit', 'frog', 'gsc', 'ga', 'psi', 'dfs' );
                            foreach ( $order as $s ) {
                                if ( in_array( $s, $sources, true ) && isset( $source_meta[ $s ] ) ) {
                                    [ $lbl, $tone ] = $source_meta[ $s ];
                                    echo $badge( $lbl, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput
                                }
                            }
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        echo 'pro' === $tier
                            ? $badge( 'Pro',   'dfs' )    // phpcs:ignore WordPress.Security.EscapeOutput
                            : $badge( 'Basic', 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput
                        ?>
                    </td>
                    <td><?php echo $scan->report_id ? esc_html( $issues_total ) : '—'; ?></td>
                    <td><?php echo $scan->report_id ? esc_html( $scan->fixes_applied ) . '/' . esc_html( $scan->fixes_available ) : '—'; ?></td>
                    <td><span class="rsseo-status rsseo-status--<?php echo esc_attr( $scan->status ); ?>"><?php echo esc_html( $scan->status ); ?></span></td>
                    <td>
                        <?php if ( $scan->report_id ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=real-smart-seo&tab=repair&report_id=' . $scan->report_id ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'View', 'real-smart-seo' ); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>
