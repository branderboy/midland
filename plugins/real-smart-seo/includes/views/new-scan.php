<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="rsseo-tabview">
    <h2><?php esc_html_e( 'SEO Analysis', 'real-smart-seo' ); ?></h2>

    <?php if ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
        <div class="rsseo-notice rsseo-notice--error">
            <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
        </div>
    <?php endif; ?>

    <?php if ( ! $has_key ) : ?>
        <div class="rsseo-notice rsseo-notice--warning">
            <?php esc_html_e( 'You need to add your Perplexity API key in Settings before running a scan.', 'real-smart-seo' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=real-smart-seo&tab=settings' ) ); ?>"><?php esc_html_e( 'Go to Settings →', 'real-smart-seo' ); ?></a>
        </div>
    <?php endif; ?>

    <p class="rsseo-intro">
        <?php esc_html_e( 'Provide any combination of the data sources below. The more data you include, the more precise the report.', 'real-smart-seo' ); ?>
    </p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="rsseo-scan-form">
        <?php wp_nonce_field( 'rsseo_new_scan' ); ?>
        <input type="hidden" name="action" value="rsseo_new_scan">

        <div class="rsseo-field">
            <label for="rsseo_scan_label"><?php esc_html_e( 'Scan Label', 'real-smart-seo' ); ?></label>
            <input type="text" id="rsseo_scan_label" name="rsseo_scan_label"
                   placeholder="<?php echo esc_attr( RSSEO_Importer::auto_label() ); ?>"
                   class="regular-text">
        </div>

        <!-- Internal Site Audit picker — no copy/paste between internal steps -->
        <?php
        $past_audits = RSSEO_Database::get_audits( 20 );
        if ( ! empty( $past_audits ) ) :
        ?>
            <div class="rsseo-data-source rsseo-data-source--internal">
                <div class="rsseo-data-source__header">
                    <span class="rsseo-data-source__icon">🤖</span>
                    <div>
                        <h3><?php esc_html_e( 'Use Site Audit data', 'real-smart-seo' ); ?> <span class="rsseo-pill"><?php esc_html_e( 'internal — no paste needed', 'real-smart-seo' ); ?></span></h3>
                        <p><?php esc_html_e( 'Pick a past Site Audit from the Scan tab and we\'ll feed its findings directly into the AI alongside any external data below.', 'real-smart-seo' ); ?></p>
                    </div>
                </div>
                <select name="rsseo_use_audit_id" class="regular-text" style="min-width:420px;">
                    <option value="0"><?php esc_html_e( '— Don\'t use a Site Audit —', 'real-smart-seo' ); ?></option>
                    <?php foreach ( $past_audits as $a ) :
                        $total = (int) $a->issues_critical + (int) $a->issues_high + (int) $a->issues_medium + (int) $a->issues_low;
                        $label = sprintf(
                            'Site Audit #%d — %s (%d posts, %d issues)',
                            (int) $a->id,
                            wp_date( 'M j, Y g:i A', strtotime( (string) $a->created_at ) ),
                            (int) $a->posts_checked,
                            $total
                        );
                    ?>
                        <option value="<?php echo esc_attr( $a->id ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <!-- Screaming Frog -->
        <div class="rsseo-data-source">
            <div class="rsseo-data-source__header">
                <span class="rsseo-data-source__icon">🕷️</span>
                <div>
                    <h3><?php esc_html_e( 'Screaming Frog Export', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Export → All in one tab from Screaming Frog (free version works). CSV or paste.', 'real-smart-seo' ); ?></p>
                    <a href="#rsseo-guide-sf" class="rsseo-guide-link"><?php esc_html_e( 'How to export →', 'real-smart-seo' ); ?></a>
                </div>
            </div>
            <input type="file" name="rsseo_file_screaming_frog" accept=".csv,.txt,.tsv">
            <p class="rsseo-or"><?php esc_html_e( '— or paste below —', 'real-smart-seo' ); ?></p>
            <textarea name="rsseo_text_screaming_frog" rows="6" placeholder="<?php esc_attr_e( 'Paste CSV rows here...', 'real-smart-seo' ); ?>"></textarea>
        </div>

        <!-- Google Search Console -->
        <div class="rsseo-data-source">
            <div class="rsseo-data-source__header">
                <span class="rsseo-data-source__icon">🔍</span>
                <div>
                    <h3><?php esc_html_e( 'Google Search Console', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Export queries and pages reports from GSC. CSV or paste.', 'real-smart-seo' ); ?></p>
                    <a href="#rsseo-guide-gsc" class="rsseo-guide-link"><?php esc_html_e( 'How to export →', 'real-smart-seo' ); ?></a>
                </div>
            </div>
            <input type="file" name="rsseo_file_gsc" accept=".csv,.txt,.tsv">
            <p class="rsseo-or"><?php esc_html_e( '— or paste below —', 'real-smart-seo' ); ?></p>
            <textarea name="rsseo_text_gsc" rows="6" placeholder="<?php esc_attr_e( 'Paste GSC data here...', 'real-smart-seo' ); ?>"></textarea>
        </div>

        <!-- Google Analytics -->
        <div class="rsseo-data-source">
            <div class="rsseo-data-source__header">
                <span class="rsseo-data-source__icon">📊</span>
                <div>
                    <h3><?php esc_html_e( 'Google Analytics', 'real-smart-seo' ); ?></h3>
                    <p><?php esc_html_e( 'Export pages report from GA4 (Sessions, Bounce Rate, Avg. Engagement). CSV or paste.', 'real-smart-seo' ); ?></p>
                    <a href="#rsseo-guide-ga" class="rsseo-guide-link"><?php esc_html_e( 'How to export →', 'real-smart-seo' ); ?></a>
                </div>
            </div>
            <input type="file" name="rsseo_file_ga" accept=".csv,.txt,.tsv">
            <p class="rsseo-or"><?php esc_html_e( '— or paste below —', 'real-smart-seo' ); ?></p>
            <textarea name="rsseo_text_ga" rows="6" placeholder="<?php esc_attr_e( 'Paste GA data here...', 'real-smart-seo' ); ?>"></textarea>
        </div>

        <!-- PageSpeed -->
        <div class="rsseo-data-source">
            <div class="rsseo-data-source__header">
                <span class="rsseo-data-source__icon">⚡</span>
                <div>
                    <h3><?php esc_html_e( 'PageSpeed / Core Web Vitals', 'real-smart-seo' ); ?></h3>
                    <p>
                        <?php esc_html_e( 'Go to', 'real-smart-seo' ); ?>
                        <a href="https://pagespeed.web.dev/" target="_blank" rel="noopener noreferrer">pagespeed.web.dev</a>,
                        <?php esc_html_e( 'run your URL, then copy and paste the results below.', 'real-smart-seo' ); ?>
                    </p>
                </div>
            </div>
            <textarea name="rsseo_text_pagespeed" rows="6" placeholder="<?php esc_attr_e( 'Paste PageSpeed results here (scores, diagnostics, opportunities)...', 'real-smart-seo' ); ?>"></textarea>
        </div>

        <?php
        /**
         * Additional scan inputs (keywords, location, DataForSEO, GMB,
         * reviews, competitor data) injected here by bundled modules.
         */
        do_action( 'rsseo_scan_form_fields' );
        ?>

        <p class="rsseo-submit">
            <button type="submit" class="button button-primary button-large" <?php echo $has_key ? '' : 'disabled'; ?>>
                <?php esc_html_e( 'Analyze My Site', 'real-smart-seo' ); ?>
            </button>
            <span class="rsseo-analyzing-msg" style="display:none;"><?php esc_html_e( 'Analyzing… this may take 30–60 seconds.', 'real-smart-seo' ); ?></span>
        </p>

    </form>

    <!-- Export Guides -->
    <div class="rsseo-guides">
        <h2><?php esc_html_e( 'How to Export Your Data', 'real-smart-seo' ); ?></h2>

        <div class="rsseo-guide" id="rsseo-guide-sf">
            <h3><?php esc_html_e( 'Screaming Frog', 'real-smart-seo' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'Download and install Screaming Frog SEO Spider (free at screamingfrog.co.uk).', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Enter your site URL and click Start.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Wait for the crawl to finish.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Click the Internal tab, then Export → Export All.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Save as CSV and upload above (free version crawls up to 500 URLs).', 'real-smart-seo' ); ?></li>
            </ol>
        </div>

        <div class="rsseo-guide" id="rsseo-guide-gsc">
            <h3><?php esc_html_e( 'Google Search Console', 'real-smart-seo' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'Go to search.google.com/search-console and select your property.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Click Performance → Search Results.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Set date range to Last 3 months. Enable all 4 metrics (Clicks, Impressions, CTR, Position).', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Click the Export button (top right) → Download CSV.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Repeat for the Pages tab export. Upload both above.', 'real-smart-seo' ); ?></li>
            </ol>
        </div>

        <div class="rsseo-guide" id="rsseo-guide-ga">
            <h3><?php esc_html_e( 'Google Analytics (GA4)', 'real-smart-seo' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'Go to analytics.google.com and select your property.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Click Reports → Engagement → Pages and screens.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Set date range to Last 3 months.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Click the Download icon (top right) → Download CSV.', 'real-smart-seo' ); ?></li>
                <li><?php esc_html_e( 'Upload the CSV above.', 'real-smart-seo' ); ?></li>
            </ol>
        </div>
    </div>
</div>
