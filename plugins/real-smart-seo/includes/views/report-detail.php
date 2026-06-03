<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap rsseo-wrap">
    <h1>
        <?php esc_html_e( 'SEO Report', 'real-smart-seo' ); ?>
        <span class="rsseo-report-label">— <?php echo esc_html( $report->label ); ?></span>
    </h1>

    <div class="rsseo-report-meta">
        <span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $report->scan_date ) ) ); ?></span>
        <span>·</span>
        <span><?php echo esc_html( $report->model ); ?></span>
        <span>·</span>
        <span><?php echo esc_html( number_format( $report->tokens_used ) ); ?> <?php esc_html_e( 'tokens', 'real-smart-seo' ); ?></span>
    </div>

    <!-- Issue summary badges -->
    <div class="rsseo-issue-summary">
        <?php if ( $report->issues_critical ) : ?>
            <span class="rsseo-badge rsseo-badge--critical rsseo-badge--lg"><?php echo esc_html( $report->issues_critical ); ?> <?php esc_html_e( 'Critical', 'real-smart-seo' ); ?></span>
        <?php endif; ?>
        <?php if ( $report->issues_high ) : ?>
            <span class="rsseo-badge rsseo-badge--high rsseo-badge--lg"><?php echo esc_html( $report->issues_high ); ?> <?php esc_html_e( 'High', 'real-smart-seo' ); ?></span>
        <?php endif; ?>
        <?php if ( $report->issues_medium ) : ?>
            <span class="rsseo-badge rsseo-badge--medium rsseo-badge--lg"><?php echo esc_html( $report->issues_medium ); ?> <?php esc_html_e( 'Medium', 'real-smart-seo' ); ?></span>
        <?php endif; ?>
        <?php if ( $report->issues_low ) : ?>
            <span class="rsseo-badge rsseo-badge--low rsseo-badge--lg"><?php echo esc_html( $report->issues_low ); ?> <?php esc_html_e( 'Low', 'real-smart-seo' ); ?></span>
        <?php endif; ?>
    </div>

    <!-- Fixes panel -->
    <?php if ( ! empty( $fixes ) ) : ?>
    <div class="rsseo-fixes-panel">
        <div class="rsseo-fixes-panel__header">
            <h2><?php esc_html_e( 'Auto-Fixes Available', 'real-smart-seo' ); ?></h2>
            <p><?php
                /* translators: 1: fixes applied, 2: total fixes */
                printf( esc_html__( '%1$d of %2$d fixes applied.', 'real-smart-seo' ), (int) $report->fixes_applied, (int) $report->fixes_available );
            ?></p>
            <?php if ( $report->fixes_applied < $report->fixes_available ) : ?>
            <button class="button button-primary rsseo-apply-all" data-report-id="<?php echo esc_attr( $report->id ); ?>">
                <?php esc_html_e( 'Apply All Fixes', 'real-smart-seo' ); ?>
            </button>
            <?php endif; ?>
            <?php if ( (int) $report->fixes_applied > 0 ) : ?>
            <button class="button rsseo-restore-all" data-report-id="<?php echo esc_attr( $report->id ); ?>">
                <?php esc_html_e( 'Revert All Applied', 'real-smart-seo' ); ?>
            </button>
            <?php endif; ?>
            <p class="description" style="margin-top:8px;"><?php esc_html_e( 'Every applied fix is backed up first — use “Revert” on any row (or “Revert All”) to restore the previous value. Expand “Review full diff” before applying content changes.', 'real-smart-seo' ); ?></p>
        </div>

        <table class="wp-list-table widefat fixed striped rsseo-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Page', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'Fix Type', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'Current Value', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'New Value', 'real-smart-seo' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'real-smart-seo' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $fixes as $fix ) :
                $post_title = '';
                if ( $fix->post_id > 0 ) {
                    $p = get_post( $fix->post_id );
                    $post_title = $p ? $p->post_title : '#' . $fix->post_id;
                }
            ?>
                <tr class="<?php echo $fix->applied ? 'rsseo-fix--applied' : ''; ?>" id="rsseo-fix-<?php echo esc_attr( $fix->id ); ?>">
                    <td><?php echo $post_title ? esc_html( $post_title ) : '—'; ?></td>
                    <td><code><?php echo esc_html( $fix->fix_type ); ?></code></td>
                    <td class="rsseo-fix-value"><?php echo esc_html( mb_strimwidth( (string) $fix->old_value, 0, 80, '…' ) ); ?></td>
                    <td class="rsseo-fix-value rsseo-fix-value--new">
                        <?php echo esc_html( mb_strimwidth( (string) $fix->new_value, 0, 80, '…' ) ); ?>
                        <details class="rsseo-diff" style="margin-top:6px;">
                            <summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Review full diff', 'real-smart-seo' ); ?></summary>
                            <div style="margin-top:6px;">
                                <div style="font-size:11px;color:#666;"><?php esc_html_e( 'Before', 'real-smart-seo' ); ?></div>
                                <pre style="white-space:pre-wrap;background:#fff5f5;border:1px solid #f0c6c6;border-radius:4px;padding:6px;max-height:200px;overflow:auto;"><?php echo esc_html( (string) $fix->old_value ); ?></pre>
                                <div style="font-size:11px;color:#666;margin-top:4px;"><?php esc_html_e( 'After', 'real-smart-seo' ); ?></div>
                                <pre style="white-space:pre-wrap;background:#f3fcf4;border:1px solid #cdeccf;border-radius:4px;padding:6px;max-height:200px;overflow:auto;"><?php echo esc_html( (string) $fix->new_value ); ?></pre>
                            </div>
                        </details>
                    </td>
                    <td>
                        <?php if ( $fix->applied ) : ?>
                            <span class="rsseo-status rsseo-status--complete"><?php esc_html_e( 'Applied', 'real-smart-seo' ); ?></span>
                        <?php else : ?>
                            <span class="rsseo-status rsseo-status--pending"><?php esc_html_e( 'Pending', 'real-smart-seo' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( ! $fix->applied ) : ?>
                            <button class="button button-small rsseo-apply-fix" data-fix-id="<?php echo esc_attr( $fix->id ); ?>">
                                <?php esc_html_e( 'Fix', 'real-smart-seo' ); ?>
                            </button>
                        <?php else : ?>
                            <button class="button button-small rsseo-restore-fix" data-fix-id="<?php echo esc_attr( $fix->id ); ?>">
                                <?php esc_html_e( 'Revert', 'real-smart-seo' ); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php
    /**
     * Lets a Pro add-on render extra report sections (schema blocks, backlink
     * opportunities) below the base fixes table.
     */
    do_action( 'rsseo_after_report_fixes', $report );
    ?>

    <!-- Full Report -->
    <div class="rsseo-report-body">
        <h2><?php esc_html_e( 'Full Report', 'real-smart-seo' ); ?></h2>
        <div class="rsseo-report-content">
            <?php
            // Convert markdown-style headings and formatting to HTML for display.
            $raw = $report->report_raw;
            $raw = esc_html( $raw );
            // H1
            $raw = preg_replace( '/^# (.+)$/m', '<h2>$1</h2>', $raw );
            // H2
            $raw = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $raw );
            // H3 with priority badge
            $raw = preg_replace_callback(
                '/^### \[PRIORITY: (CRITICAL|HIGH|MEDIUM|LOW)\] — (.+)$/m',
                function( $m ) {
                    return '<h4><span class="rsseo-badge rsseo-badge--' . strtolower( $m[1] ) . '">' . esc_html( $m[1] ) . '</span> ' . esc_html( $m[2] ) . '</h4>';
                },
                $raw
            );
            // Bold
            $raw = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $raw );
            // WP_FIX lines — hide from display
            $raw = preg_replace( '/<strong>WP_FIX:<\/strong>.+/m', '<em class="rsseo-wp-fix-tag">[Auto-fix captured]</em>', $raw );
            // Numbered lists
            $raw = preg_replace( '/^\d+\. (.+)$/m', '<li>$1</li>', $raw );
            $raw = preg_replace( '/(<li>.+<\/li>\n?)+/', '<ol>$0</ol>', $raw );
            // Line breaks
            $raw = nl2br( $raw );
            echo wp_kses_post( $raw );
            ?>
        </div>
    </div>

    <p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-reports' ) ); ?>" class="button">
            ← <?php esc_html_e( 'All Reports', 'real-smart-seo' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-new-scan' ) ); ?>" class="button button-primary">
            <?php esc_html_e( 'Run New Scan', 'real-smart-seo' ); ?>
        </a>
    </p>
</div>
