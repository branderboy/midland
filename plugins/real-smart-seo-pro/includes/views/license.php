<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap rsseo-wrap">
    <h1><?php esc_html_e( 'Real Smart SEO for Local Pro — License', 'real-smart-seo-pro' ); ?></h1>

    <div id="rsseo-pro-license-msg" class="rsseo-notice" style="display:none;"></div>

    <div class="rsseo-settings-section">
        <?php if ( $is_active ) : ?>
            <div class="rsseo-license-active">
                <span class="rsseo-license-icon">✓</span>
                <div>
                    <h2><?php esc_html_e( 'License Active', 'real-smart-seo-pro' ); ?></h2>
                    <p>
                        <?php esc_html_e( 'Key:', 'real-smart-seo-pro' ); ?>
                        <code><?php echo esc_html( substr( $license_key, 0, 8 ) . '••••••••••••' ); ?></code>
                        <?php if ( $expiry ) : ?>
                            &nbsp;·&nbsp; <?php esc_html_e( 'Expires:', 'real-smart-seo-pro' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expiry ) ) ); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <button class="button rsseo-pro-license-btn" data-action="deactivate">
                <?php esc_html_e( 'Deactivate License', 'real-smart-seo-pro' ); ?>
            </button>
        <?php else : ?>
            <h2><?php esc_html_e( 'Activate Your License', 'real-smart-seo-pro' ); ?></h2>
            <p><?php esc_html_e( 'Enter your license key to unlock all Pro features.', 'real-smart-seo-pro' ); ?></p>
            <div class="rsseo-license-form">
                <input type="text" id="rsseo-pro-license-key" class="regular-text"
                       placeholder="<?php esc_attr_e( 'XXXX-XXXX-XXXX-XXXX', 'real-smart-seo-pro' ); ?>" autocomplete="off">
                <button class="button button-primary rsseo-pro-license-btn" data-action="activate">
                    <?php esc_html_e( 'Activate', 'real-smart-seo-pro' ); ?>
                </button>
            </div>
            <p class="description">
                <?php esc_html_e( "Don't have a license?", 'real-smart-seo-pro' ); ?>
                <a href="https://tagglefish.com/real-smart-seo-pro" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Get Real Smart SEO for Local Pro →', 'real-smart-seo-pro' ); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>

    <div class="rsseo-settings-section">
        <h2><?php esc_html_e( 'What You Get with Pro', 'real-smart-seo-pro' ); ?></h2>
        <ul class="rsseo-pro-features">
            <li>📈 <?php esc_html_e( 'Google Trends analysis — target what\'s rising before competitors do', 'real-smart-seo-pro' ); ?></li>
            <li>📍 <?php esc_html_e( 'Google Business Profile analysis — find every gap in your local listing', 'real-smart-seo-pro' ); ?></li>
            <li>⭐ <?php esc_html_e( 'Review sentiment analysis — understand what your reviews are telling Google', 'real-smart-seo-pro' ); ?></li>
            <li>🏗️ <?php esc_html_e( 'Schema implementation — LocalBusiness, Service, Review, FAQ applied in one click', 'real-smart-seo-pro' ); ?></li>
            <li>🔗 <?php esc_html_e( 'Hyper-local backlink targets — .gov, .org, nonprofits, city resources specific to your trade and market', 'real-smart-seo-pro' ); ?></li>
            <li>🔬 <?php esc_html_e( 'Perplexity-powered competitor research', 'real-smart-seo-pro' ); ?></li>
            <li>📋 <?php esc_html_e( 'Looker Studio integration', 'real-smart-seo-pro' ); ?></li>
            <li>🎯 <?php esc_html_e( 'PAA targeting + featured snippet optimization', 'real-smart-seo-pro' ); ?></li>
            <li>✍️ <?php esc_html_e( 'About page rewrite with local entity signals', 'real-smart-seo-pro' ); ?></li>
            <li>🌐 <?php esc_html_e( 'External linking strategy for local authority signals', 'real-smart-seo-pro' ); ?></li>
        </ul>
    </div>
</div>
