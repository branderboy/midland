<?php if ( ! defined( 'ABSPATH' ) ) exit;
$profile = (array) get_option( 'rsseo_business_profile', array() );

// Sensible defaults — auto-populated with Midland Floors data on the
// midlandfloors.com install (this is where the plugin is actively tested),
// falling back to site_info on every other install.
$is_midland = false !== stripos( (string) home_url(), 'midlandfloors' );
$defaults   = $is_midland
    ? array(
        'name'          => 'Midland Floor Care',
        'category'      => 'Commercial Floor Cleaning Contractor',
        'gmb_url'       => '',
        'service_areas' => "Washington DC\nBethesda MD\nArlington VA",
        'competitors'   => '',
    )
    : array(
        'name'          => get_bloginfo( 'name' ),
        'category'      => '',
        'gmb_url'       => '',
        'service_areas' => '',
        'competitors'   => '',
    );
$profile = wp_parse_args( $profile, $defaults );
?>
<div class="wrap rsseo-wrap">
    <h1><?php esc_html_e( 'Real Smart SEO Settings', 'real-smart-seo' ); ?></h1>

    <div id="rsseo-settings-msg" class="rsseo-notice" style="display:none;"></div>

    <form id="rsseo-settings-form">

        <div class="rsseo-settings-section">
            <h2><?php esc_html_e( 'Business Profile', 'real-smart-seo' ); ?></h2>
            <p><?php esc_html_e( 'The AI uses these fields to ground scans, generate locally-relevant fixes, and run competitor audits. Fill them in once and they flow into every Analyze + Insights run.', 'real-smart-seo' ); ?></p>
            <table class="form-table">
                <tr>
                    <th><label for="rsseo_bp_name"><?php esc_html_e( 'Business Name', 'real-smart-seo' ); ?></label></th>
                    <td><input type="text" id="rsseo_bp_name" name="rsseo_business_profile[name]" value="<?php echo esc_attr( $profile['name'] ); ?>" class="regular-text" placeholder="Midland Floor Care"></td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_category"><?php esc_html_e( 'Primary Category', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <input type="text" id="rsseo_bp_category" name="rsseo_business_profile[category]" value="<?php echo esc_attr( $profile['category'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Carpet Cleaner, Floor Stripping Contractor, Restoration Service', 'real-smart-seo' ); ?>">
                        <p class="description"><?php esc_html_e( 'Match your Google Business Profile primary category if possible.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_gmb"><?php esc_html_e( 'Google Business Profile URL', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <input type="url" id="rsseo_bp_gmb" name="rsseo_business_profile[gmb_url]" value="<?php echo esc_attr( $profile['gmb_url'] ); ?>" class="regular-text" placeholder="https://g.co/kgs/...">
                        <p class="description"><?php esc_html_e( 'Used to anchor sameAs schema and to seed competitor audits in your map-pack neighborhood.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_areas"><?php esc_html_e( 'Service Areas', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <textarea id="rsseo_bp_areas" name="rsseo_business_profile[service_areas]" rows="3" class="large-text" placeholder="Washington DC&#10;Bethesda MD&#10;Arlington VA"><?php echo esc_textarea( $profile['service_areas'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One city/region per line. Feeds programmatic city × service page generation and Local Falcon-style geo-grid tracking.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_competitors"><?php esc_html_e( 'Known Competitors', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <textarea id="rsseo_bp_competitors" name="rsseo_business_profile[competitors]" rows="3" class="large-text" placeholder="https://competitor1.com&#10;https://competitor2.com"><?php echo esc_textarea( $profile['competitors'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One URL per line. The AI will pull their meta titles, schema, and ranking strategies and tell you what they\'re doing better.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="rsseo-settings-section">
            <h2><?php esc_html_e( 'Perplexity API Key', 'real-smart-seo' ); ?></h2>
            <p>
                <?php esc_html_e( 'Get your API key from', 'real-smart-seo' ); ?>
                <a href="https://www.perplexity.ai/settings/api" target="_blank" rel="noopener noreferrer">perplexity.ai/settings/api</a>.
                <?php esc_html_e( 'Your key is stored encrypted in the database. This same key is reused by the Smart Chat plugin\'s AI chat and the AI Rank module so you only paste it once.', 'real-smart-seo' ); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'API Key', 'real-smart-seo' ); ?></th>
                    <td>
                        <input type="password" name="rsseo_api_key" id="rsseo_api_key"
                               placeholder="<?php echo $has_key ? esc_attr( '••••••••••••••••••••' ) : esc_attr( 'pplx-...' ); ?>"
                               class="regular-text" autocomplete="off">
                        <?php if ( $has_key ) : ?>
                            <span class="rsseo-key-set"><?php esc_html_e( '✓ Key is set', 'real-smart-seo' ); ?></span>
                        <?php endif; ?>
                        <button type="button" class="button" id="rsseo-test-api">
                            <?php esc_html_e( 'Test Connection', 'real-smart-seo' ); ?>
                        </button>
                    </td>
                </tr>
            </table>
        </div>

        <div class="rsseo-settings-section">
            <h2><?php esc_html_e( 'Model', 'real-smart-seo' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Perplexity Sonar Model', 'real-smart-seo' ); ?></th>
                    <td>
                        <select name="rsseo_model">
                            <option value="sonar" <?php selected( $model, 'sonar' ); ?>>
                                Sonar — <?php esc_html_e( 'Recommended (cheapest, built-in web search)', 'real-smart-seo' ); ?>
                            </option>
                            <option value="sonar-pro" <?php selected( $model, 'sonar-pro' ); ?>>
                                Sonar Pro — <?php esc_html_e( 'Better grounding (higher cost)', 'real-smart-seo' ); ?>
                            </option>
                            <option value="sonar-reasoning" <?php selected( $model, 'sonar-reasoning' ); ?>>
                                Sonar Reasoning — <?php esc_html_e( 'Deeper reasoning for complex SEO audits', 'real-smart-seo' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Max Output Tokens', 'real-smart-seo' ); ?></th>
                    <td>
                        <input type="number" name="rsseo_max_tokens" value="<?php echo esc_attr( $max_tokens ); ?>"
                               min="2000" max="16000" step="1000" class="small-text">
                        <p class="description"><?php esc_html_e( 'Higher = more detailed reports. 8000 recommended.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'real-smart-seo' ); ?></button>
        </p>

    </form>
</div>
