<?php if ( ! defined( 'ABSPATH' ) ) exit;
$dfs_configured = RSSEO_Pro_DataForSEO::is_configured();
?>

<div class="rsseo-pro-divider">
    <span><?php esc_html_e( 'Pro Data Sources', 'real-smart-seo-pro' ); ?></span>
</div>

<!-- Keywords + Location (feeds DataForSEO + Perplexity Sonar trends) -->
<div class="rsseo-data-source rsseo-data-source--pro">
    <div class="rsseo-data-source__header">
        <span class="rsseo-data-source__icon">🎯</span>
        <div>
            <h3><?php esc_html_e( 'Target Keywords + Location', 'real-smart-seo-pro' ); ?> <span class="rsseo-pro-badge">PRO</span></h3>
            <p><?php esc_html_e( 'Enter your target keywords and service location. The AI uses this for trend intelligence. If DataForSEO is connected, live volume, difficulty, and SERP data is pulled automatically.', 'real-smart-seo-pro' ); ?></p>
            <?php if ( $dfs_configured ) : ?>
                <span class="rsseo-dfs-connected">⚡ <?php esc_html_e( 'DataForSEO connected — live data will be pulled', 'real-smart-seo-pro' ); ?></span>
            <?php else : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-pro-settings' ) ); ?>" class="rsseo-guide-link"><?php esc_html_e( 'Connect DataForSEO for live keyword + trend data →', 'real-smart-seo-pro' ); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="rsseo-pro-keywords-row">
        <div class="rsseo-pro-field">
            <label><?php esc_html_e( 'Target Keywords', 'real-smart-seo-pro' ); ?></label>
            <textarea name="rsseo_pro_text_keywords" rows="4"
                placeholder="<?php esc_attr_e( "drywall repair Washington DC\ndrywall contractor DC\nwater damage drywall repair\ncommercial drywall contractor", 'real-smart-seo-pro' ); ?>"></textarea>
            <p class="description"><?php esc_html_e( 'One keyword per line.', 'real-smart-seo-pro' ); ?></p>
        </div>
        <div class="rsseo-pro-field rsseo-pro-field--sm">
            <label><?php esc_html_e( 'Service Location', 'real-smart-seo-pro' ); ?></label>
            <input type="text" name="rsseo_pro_text_location" class="regular-text"
                   placeholder="<?php esc_attr_e( 'Washington DC', 'real-smart-seo-pro' ); ?>">
            <?php if ( $dfs_configured ) : ?>
            <label style="margin-top:10px;display:block;"><?php esc_html_e( 'DataForSEO Location Code', 'real-smart-seo-pro' ); ?></label>
            <input type="number" name="rsseo_pro_location_code" value="2840" class="small-text">
            <p class="description"><a href="https://api.dataforseo.com/v3/keywords_data/google_ads/locations" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Find your code →', 'real-smart-seo-pro' ); ?></a></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Competitor Research -->
<div class="rsseo-data-source rsseo-data-source--pro">
    <div class="rsseo-data-source__header">
        <span class="rsseo-data-source__icon">🕵️</span>
        <div>
            <h3><?php esc_html_e( 'Competitor Research', 'real-smart-seo-pro' ); ?> <span class="rsseo-pro-badge">PRO</span></h3>
            <p><?php esc_html_e( 'Crawl a competitor\'s site in Screaming Frog (enter their URL instead of yours), export, and upload. The AI will analyze what they\'re doing right that you\'re not.', 'real-smart-seo-pro' ); ?></p>
            <?php if ( $dfs_configured ) : ?>
                <span class="rsseo-dfs-connected">⚡ <?php esc_html_e( 'SERP data will also show who\'s ranking above you', 'real-smart-seo-pro' ); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <input type="file" name="rsseo_pro_file_competitor_sf" accept=".csv,.txt,.tsv">
    <p class="rsseo-or"><?php esc_html_e( '— or paste below —', 'real-smart-seo-pro' ); ?></p>
    <textarea name="rsseo_pro_text_competitor_sf" rows="5"
        placeholder="<?php esc_attr_e( 'Paste competitor Screaming Frog export here...', 'real-smart-seo-pro' ); ?>"></textarea>
</div>

<!-- GMB / Google Business Profile -->
<div class="rsseo-data-source rsseo-data-source--pro">
    <div class="rsseo-data-source__header">
        <span class="rsseo-data-source__icon">📍</span>
        <div>
            <h3><?php esc_html_e( 'Google Business Profile (GMB)', 'real-smart-seo-pro' ); ?> <span class="rsseo-pro-badge">PRO</span></h3>
            <p><?php esc_html_e( 'Copy your GMB profile — categories, services, description, photo count, Q&A, posts, performance stats.', 'real-smart-seo-pro' ); ?></p>
        </div>
    </div>
    <textarea name="rsseo_pro_text_gmb" rows="6"
        placeholder="<?php esc_attr_e( 'Paste your GMB profile data — categories, services, description, photo count, recent posts, Q&A, views, calls...', 'real-smart-seo-pro' ); ?>"></textarea>
</div>

<!-- Reviews -->
<div class="rsseo-data-source rsseo-data-source--pro">
    <div class="rsseo-data-source__header">
        <span class="rsseo-data-source__icon">⭐</span>
        <div>
            <h3><?php esc_html_e( 'Customer Reviews', 'real-smart-seo-pro' ); ?> <span class="rsseo-pro-badge">PRO</span></h3>
            <p><?php esc_html_e( 'Paste your Google reviews. Include star rating, reviewer name, date, and review text.', 'real-smart-seo-pro' ); ?></p>
        </div>
    </div>
    <textarea name="rsseo_pro_text_reviews" rows="8"
        placeholder="<?php esc_attr_e( "5 stars — John D. — March 2025\n\"Great work, fast and clean. Would hire again.\"\n\n4 stars — Sarah M. — Feb 2025\n\"Good job but took longer than expected...\"", 'real-smart-seo-pro' ); ?>"></textarea>
</div>

<!-- Perplexity Research -->
<div class="rsseo-data-source rsseo-data-source--pro">
    <div class="rsseo-data-source__header">
        <span class="rsseo-data-source__icon">🔬</span>
        <div>
            <h3><?php esc_html_e( 'Market Research', 'real-smart-seo-pro' ); ?> <span class="rsseo-pro-badge">PRO</span></h3>
            <p>
                <?php esc_html_e( 'Search your top keywords on', 'real-smart-seo-pro' ); ?>
                <a href="https://www.perplexity.ai/" target="_blank" rel="noopener noreferrer">perplexity.ai</a>
                <?php esc_html_e( 'and paste the results — cited sources, competitor mentions, market context.', 'real-smart-seo-pro' ); ?>
            </p>
        </div>
    </div>
    <textarea name="rsseo_pro_text_perplexity" rows="6"
        placeholder="<?php esc_attr_e( 'Paste Perplexity results, competitor findings, market data...', 'real-smart-seo-pro' ); ?>"></textarea>
</div>
