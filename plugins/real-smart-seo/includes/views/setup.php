<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Setup tab (partial — the shell provides .wrap + page <h1> + tab nav).
 *
 * Vars: $readiness, $overall, $profile, $has_key, $model, $max_tokens, $has_pro.
 * The form reuses #rsseo-settings-form so the existing rsseo-admin.js save +
 * Test Connection handlers apply unchanged. Business-profile field names keep
 * the rsseo_business_profile[...] shape that ajax_save_settings maps into the
 * unified RSSEO_Profile.
 */
$overall_label = RSSEO_Profile::status_label( $overall );
$overall_color = RSSEO_Profile::status_color( $overall );
?>
<div class="rsseo-tabview rsseo-setup">
    <h2><?php esc_html_e( 'Setup', 'real-smart-seo' ); ?></h2>
    <p><?php esc_html_e( 'Get everything ready before you scan. Fill these in once — Site Scan, Opportunities, and Page Builder all read from here.', 'real-smart-seo' ); ?></p>

    <!-- Readiness panel -->
    <div class="rsseo-setup__status" style="border-left:5px solid <?php echo esc_attr( $overall_color ); ?>;background:#fff;padding:14px 16px;border-radius:6px;margin:14px 0 20px;box-shadow:0 1px 2px rgba(0,0,0,.06);">
        <strong style="font-size:14px;">
            <?php esc_html_e( 'Setup status:', 'real-smart-seo' ); ?>
            <span style="color:<?php echo esc_attr( $overall_color ); ?>;"><?php echo esc_html( $overall_label ); ?></span>
        </strong>
        <div class="rsseo-setup__checklist" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px 18px;margin-top:12px;">
            <?php foreach ( $readiness as $item ) :
                $c = RSSEO_Profile::status_color( $item['status'] );
                $l = RSSEO_Profile::status_label( $item['status'] );
                ?>
                <div class="rsseo-setup__item" style="display:flex;gap:8px;align-items:flex-start;">
                    <span title="<?php echo esc_attr( $l ); ?>" style="flex:0 0 auto;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $c ); ?>;margin-top:5px;"></span>
                    <span>
                        <strong style="display:block;line-height:1.3;"><?php echo esc_html( $item['label'] ); ?>
                            <span style="font-weight:600;color:<?php echo esc_attr( $c ); ?>;font-size:11px;">· <?php echo esc_html( $l ); ?></span>
                        </strong>
                        <span class="description" style="font-size:12px;"><?php echo esc_html( $item['hint'] ); ?></span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="rsseo-settings-msg" class="rsseo-notice" style="display:none;"></div>

    <form id="rsseo-settings-form">

        <div class="rsseo-settings-section">
            <h2><?php esc_html_e( 'Business Profile', 'real-smart-seo' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="rsseo_bp_name"><?php esc_html_e( 'Business Name', 'real-smart-seo' ); ?></label></th>
                    <td><input type="text" id="rsseo_bp_name" name="rsseo_business_profile[name]" value="<?php echo esc_attr( $profile['business_name'] ); ?>" class="regular-text" placeholder="Midland Floor Care"></td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_category"><?php esc_html_e( 'Primary Category', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <input type="text" id="rsseo_bp_category" name="rsseo_business_profile[category]" value="<?php echo esc_attr( $profile['category'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Commercial Floor Cleaning Contractor', 'real-smart-seo' ); ?>">
                        <p class="description"><?php esc_html_e( 'Match your Google Business Profile primary category if possible.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_services"><?php esc_html_e( 'Main Services', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <textarea id="rsseo_bp_services" name="rsseo_business_profile[services]" rows="4" class="large-text" placeholder="Carpet cleaning&#10;Tile &amp; grout restoration&#10;Hardwood refinishing&#10;Concrete polishing"><?php echo esc_textarea( $profile['services'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One service per line. Combined with your cities to build city × service pages and spot content gaps.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_areas"><?php esc_html_e( 'Cities / Service Areas', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <textarea id="rsseo_bp_areas" name="rsseo_business_profile[service_areas]" rows="4" class="large-text" placeholder="Washington DC&#10;Bethesda MD&#10;Arlington VA"><?php echo esc_textarea( $profile['cities'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One city/region per line. Feeds Page Builder and Local Falcon-style geo-grid tracking.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_gmb"><?php esc_html_e( 'Google Business Profile URL', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <input type="url" id="rsseo_bp_gmb" name="rsseo_business_profile[gmb_url]" value="<?php echo esc_attr( $profile['gbp_url'] ); ?>" class="regular-text" placeholder="https://maps.google.com/?cid=...">
                        <p class="description"><?php esc_html_e( 'Anchors sameAs schema and local relevance.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rsseo_bp_competitors"><?php esc_html_e( 'Known Competitors', 'real-smart-seo' ); ?></label></th>
                    <td>
                        <textarea id="rsseo_bp_competitors" name="rsseo_business_profile[competitors]" rows="3" class="large-text" placeholder="https://competitor1.com&#10;https://competitor2.com"><?php echo esc_textarea( $profile['competitors'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One per line. The AI pulls their titles, schema, and strategies and tells you what they do better.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="rsseo-settings-section">
            <h2><?php esc_html_e( 'Perplexity API Key', 'real-smart-seo' ); ?> <span style="color:#b32d2e;">*</span></h2>
            <p>
                <?php esc_html_e( 'Required for AI scans. Get a key from', 'real-smart-seo' ); ?>
                <a href="https://www.perplexity.ai/settings/api" target="_blank" rel="noopener noreferrer">perplexity.ai/settings/api</a>.
                <?php esc_html_e( 'Stored encrypted. The same key is reused by the AI Rank module and Smart Chat.', 'real-smart-seo' ); ?>
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
                            <option value="sonar" <?php selected( $model, 'sonar' ); ?>>Sonar — <?php esc_html_e( 'Recommended (cheapest, built-in web search)', 'real-smart-seo' ); ?></option>
                            <option value="sonar-pro" <?php selected( $model, 'sonar-pro' ); ?>>Sonar Pro — <?php esc_html_e( 'Better grounding (higher cost)', 'real-smart-seo' ); ?></option>
                            <option value="sonar-reasoning" <?php selected( $model, 'sonar-reasoning' ); ?>>Sonar Reasoning — <?php esc_html_e( 'Deeper reasoning for complex audits', 'real-smart-seo' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Max Output Tokens', 'real-smart-seo' ); ?></th>
                    <td>
                        <input type="number" name="rsseo_max_tokens" value="<?php echo esc_attr( $max_tokens ); ?>" min="2000" max="16000" step="1000" class="small-text">
                        <p class="description"><?php esc_html_e( '8000 recommended.', 'real-smart-seo' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save Setup', 'real-smart-seo' ); ?></button>
        </p>

    </form>

    <hr style="margin:28px 0;">
    <div class="rsseo-settings-section">
        <h2><?php esc_html_e( 'Business Identity & Schema', 'real-smart-seo' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Publish LocalBusiness / sameAs schema sitewide and confirm your name, address, and profile links to Google.', 'real-smart-seo' ); ?></p>
        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=rsseo-sameas' ) ); ?>"><?php esc_html_e( 'Open Business Identity →', 'real-smart-seo' ); ?></a>
    </div>

    <?php if ( has_action( 'rsseo_render_pro_settings_panel' ) ) : ?>
        <hr style="margin:28px 0;">
        <div class="rsseo-settings-section">
            <h2><?php esc_html_e( 'Connections', 'real-smart-seo' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Connect DataForSEO to power rank tracking, the geo-grid, and the backlinks dashboard.', 'real-smart-seo' ); ?></p>
            <?php do_action( 'rsseo_render_pro_settings_panel' ); ?>
        </div>
    <?php endif; ?>
</div>
