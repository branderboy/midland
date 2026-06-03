<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap rsseo-wrap">
    <h1><?php esc_html_e( 'Midland Smart SEO Pro — Settings', 'real-smart-seo-pro' ); ?></h1>

    <div id="rsseo-pro-settings-msg" class="rsseo-notice" style="display:none;"></div>

    <div class="rsseo-settings-section">
        <h2><?php esc_html_e( 'DataForSEO API', 'real-smart-seo-pro' ); ?></h2>
        <p>
            <?php esc_html_e( 'Connect DataForSEO to pull live keyword volume, Google Trends, and SERP data during each scan. Get your credentials at', 'real-smart-seo-pro' ); ?>
            <a href="https://app.dataforseo.com/register" target="_blank" rel="noopener noreferrer">dataforseo.com</a>.
            <?php esc_html_e( 'Pay-as-you-go — a typical scan costs $0.01–$0.05.', 'real-smart-seo-pro' ); ?>
        </p>

        <?php if ( $dfs_configured ) : ?>
            <div class="rsseo-license-active">
                <span class="rsseo-license-icon">⚡</span>
                <div>
                    <h3><?php esc_html_e( 'DataForSEO Connected', 'real-smart-seo-pro' ); ?></h3>
                    <p><?php esc_html_e( 'Login:', 'real-smart-seo-pro' ); ?> <code><?php echo esc_html( $dfs_login ); ?></code></p>
                </div>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'DataForSEO Login (Email)', 'real-smart-seo-pro' ); ?></th>
                <td>
                    <input type="email" id="rsseo-dfs-login" class="regular-text"
                           value="<?php echo esc_attr( $dfs_login ); ?>"
                           placeholder="you@example.com">
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'DataForSEO Password', 'real-smart-seo-pro' ); ?></th>
                <td>
                    <input type="password" id="rsseo-dfs-password" class="regular-text"
                           placeholder="<?php echo $dfs_configured ? esc_attr( '••••••••••••' ) : esc_attr__( 'Your DataForSEO password', 'real-smart-seo-pro' ); ?>"
                           autocomplete="off">
                </td>
            </tr>
        </table>

        <p>
            <button type="button" class="button button-primary" id="rsseo-save-dfs">
                <?php esc_html_e( 'Save Credentials', 'real-smart-seo-pro' ); ?>
            </button>
            <?php if ( $dfs_configured ) : ?>
            <button type="button" class="button" id="rsseo-test-dfs">
                <?php esc_html_e( 'Test Connection', 'real-smart-seo-pro' ); ?>
            </button>
            <?php endif; ?>
        </p>

        <div class="rsseo-settings-section" style="margin-top:20px;">
            <h3><?php esc_html_e( 'What DataForSEO Pulls During Each Scan', 'real-smart-seo-pro' ); ?></h3>
            <ul class="rsseo-pro-features">
                <li>📊 <?php esc_html_e( 'Monthly search volume + CPC + competition for your target keywords', 'real-smart-seo-pro' ); ?></li>
                <li>📈 <?php esc_html_e( 'Google Trends — 12 months of interest data + related rising queries', 'real-smart-seo-pro' ); ?></li>
                <li>🔍 <?php esc_html_e( 'Live SERP results for your top 3 keywords — see exactly who you\'re competing against', 'real-smart-seo-pro' ); ?></li>
            </ul>
        </div>
    </div>
</div>
