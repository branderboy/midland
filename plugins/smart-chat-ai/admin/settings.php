<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Smart Chat AI Settings', 'smart-chat-ai' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'scai_settings' ); ?>

        <h2><?php esc_html_e( 'Chat Widget', 'smart-chat-ai' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="smart_chat_chat_enabled"><?php esc_html_e( 'Enable Chat', 'smart-chat-ai' ); ?></label></th>
                <td><label><input type="checkbox" name="smart_chat_chat_enabled" id="smart_chat_chat_enabled" value="1" <?php checked( get_option( 'smart_chat_chat_enabled' ) ); ?>> <?php esc_html_e( 'Show chat widget on frontend', 'smart-chat-ai' ); ?></label></td>
            </tr>
            <tr>
                <th><label for="smart_chat_chat_position"><?php esc_html_e( 'Position', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <select name="smart_chat_chat_position" id="smart_chat_chat_position">
                        <option value="bottom-right" <?php selected( get_option( 'smart_chat_chat_position' ), 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'smart-chat-ai' ); ?></option>
                        <option value="bottom-left" <?php selected( get_option( 'smart_chat_chat_position' ), 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'smart-chat-ai' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_chat_color"><?php esc_html_e( 'Color', 'smart-chat-ai' ); ?></label></th>
                <td><input type="color" name="smart_chat_chat_color" id="smart_chat_chat_color" value="<?php echo esc_attr( get_option( 'smart_chat_chat_color', '#2563EB' ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="smart_chat_chat_title"><?php esc_html_e( 'Title', 'smart-chat-ai' ); ?></label></th>
                <td><input type="text" name="smart_chat_chat_title" id="smart_chat_chat_title" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_chat_title', 'Chat with us!' ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="smart_chat_chat_subtitle"><?php esc_html_e( 'Subtitle', 'smart-chat-ai' ); ?></label></th>
                <td><input type="text" name="smart_chat_chat_subtitle" id="smart_chat_chat_subtitle" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_chat_subtitle', 'We typically reply in a few minutes' ) ); ?>"></td>
            </tr>
        </table>

        <?php
        $provider = get_option( 'smart_chat_ai_provider', 'perplexity' );
        $model    = get_option( 'smart_chat_ai_model', 'perplexity' === $provider ? 'sonar' : 'gpt-4o-mini' );
        ?>
        <h2><?php esc_html_e( 'AI Configuration', 'smart-chat-ai' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="smart_chat_ai_provider"><?php esc_html_e( 'Provider', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <select name="smart_chat_ai_provider" id="smart_chat_ai_provider">
                        <option value="perplexity" <?php selected( $provider, 'perplexity' ); ?>>Perplexity Sonar (recommended — built-in web search)</option>
                        <option value="openai" <?php selected( $provider, 'openai' ); ?>>OpenAI (legacy)</option>
                    </select>
                    <p class="description"><?php esc_html_e( 'Perplexity\'s API is OpenAI-compatible. The same key powers both this chat AND the AI Rank citation tracker — no second account.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_perplexity_api_key"><?php esc_html_e( 'Perplexity API Key', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="password" name="smart_chat_perplexity_api_key" id="smart_chat_perplexity_api_key" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_perplexity_api_key' ) ); ?>" placeholder="pplx-...">
                    <p class="description"><?php esc_html_e( 'If empty, the chat falls back to the AI Rank module\'s key (rsseo_pro_ai_perplexity_key) so you only have to paste it once.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="password" name="smart_chat_openai_api_key" id="smart_chat_openai_api_key" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_openai_api_key' ) ); ?>" placeholder="sk-...">
                    <p class="description"><?php esc_html_e( 'Only used when Provider = OpenAI.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_ai_model"><?php esc_html_e( 'Model', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <select name="smart_chat_ai_model" id="smart_chat_ai_model">
                        <optgroup label="Perplexity">
                            <option value="sonar" <?php selected( $model, 'sonar' ); ?>>Sonar (cheapest)</option>
                            <option value="sonar-pro" <?php selected( $model, 'sonar-pro' ); ?>>Sonar Pro (better grounding)</option>
                            <option value="sonar-reasoning" <?php selected( $model, 'sonar-reasoning' ); ?>>Sonar Reasoning</option>
                        </optgroup>
                        <optgroup label="OpenAI (legacy)">
                            <option value="gpt-4o-mini" <?php selected( $model, 'gpt-4o-mini' ); ?>>GPT-4o Mini</option>
                            <option value="gpt-4o" <?php selected( $model, 'gpt-4o' ); ?>>GPT-4o</option>
                            <option value="gpt-4" <?php selected( $model, 'gpt-4' ); ?>>GPT-4</option>
                            <option value="gpt-3.5-turbo" <?php selected( $model, 'gpt-3.5-turbo' ); ?>>GPT-3.5 Turbo</option>
                        </optgroup>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_ai_temperature"><?php esc_html_e( 'Temperature', 'smart-chat-ai' ); ?></label></th>
                <td><input type="number" name="smart_chat_ai_temperature" id="smart_chat_ai_temperature" min="0" max="1" step="0.1" value="<?php echo esc_attr( get_option( 'smart_chat_ai_temperature', '0.7' ) ); ?>"></td>
            </tr>
        </table>

        <?php
        $ctx_enabled = (int) get_option( SCAI_Content_Context::OPT_ENABLED, 0 );
        $ctx_sitemap = (string) get_option( SCAI_Content_Context::OPT_SITEMAP_URL, '' );
        $ctx_pages   = (int) get_option( SCAI_Content_Context::OPT_PAGE_LIMIT, 30 );
        $ctx_chars   = (int) get_option( SCAI_Content_Context::OPT_CHARS_PER, 1500 );
        $ctx_last    = get_option( SCAI_Content_Context::OPT_LAST_REFRESH, array() );
        $refresh_url = wp_nonce_url( admin_url( 'admin.php?page=scai-content&scai_ctx_refresh=1' ), 'scai_ctx_refresh' );
        ?>
        <h2><?php esc_html_e( 'Sitemap Ingestion', 'smart-chat-ai' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Walks your sitemap, caches plain-text excerpts, and feeds the most relevant ones to the chat AI on each message so it answers from your actual site instead of guessing.', 'smart-chat-ai' ); ?></p>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Enable Sitemap Context', 'smart-chat-ai' ); ?></th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr( SCAI_Content_Context::OPT_ENABLED ); ?>" value="1" <?php checked( $ctx_enabled ); ?>> <?php esc_html_e( 'Inject relevant site content into every chat AI response', 'smart-chat-ai' ); ?></label>
                </td>
            </tr>
            <tr>
                <th><label for="<?php echo esc_attr( SCAI_Content_Context::OPT_SITEMAP_URL ); ?>"><?php esc_html_e( 'Sitemap URL', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="url" id="<?php echo esc_attr( SCAI_Content_Context::OPT_SITEMAP_URL ); ?>" name="<?php echo esc_attr( SCAI_Content_Context::OPT_SITEMAP_URL ); ?>" class="large-text" value="<?php echo esc_attr( $ctx_sitemap ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-sitemap.xml' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'Leave blank to use /wp-sitemap.xml. Sitemap-index files are followed one level deep.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="<?php echo esc_attr( SCAI_Content_Context::OPT_PAGE_LIMIT ); ?>"><?php esc_html_e( 'Page Limit', 'smart-chat-ai' ); ?></label></th>
                <td><input type="number" id="<?php echo esc_attr( SCAI_Content_Context::OPT_PAGE_LIMIT ); ?>" name="<?php echo esc_attr( SCAI_Content_Context::OPT_PAGE_LIMIT ); ?>" min="1" max="200" value="<?php echo esc_attr( $ctx_pages ); ?>" style="width:90px;"></td>
            </tr>
            <tr>
                <th><label for="<?php echo esc_attr( SCAI_Content_Context::OPT_CHARS_PER ); ?>"><?php esc_html_e( 'Chars Per Page', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="number" id="<?php echo esc_attr( SCAI_Content_Context::OPT_CHARS_PER ); ?>" name="<?php echo esc_attr( SCAI_Content_Context::OPT_CHARS_PER ); ?>" min="200" max="5000" step="100" value="<?php echo esc_attr( $ctx_chars ); ?>" style="width:90px;">
                    <p class="description"><?php esc_html_e( 'Per-page text cap. Lower = leaner prompt, fewer tokens.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Cache Status', 'smart-chat-ai' ); ?></th>
                <td>
                    <?php if ( ! empty( $ctx_last ) ) : ?>
                        <?php printf(
                            /* translators: 1: page count, 2: timestamp */
                            esc_html__( '%1$d pages cached &middot; last refresh %2$s', 'smart-chat-ai' ),
                            (int) ( $ctx_last['count'] ?? 0 ),
                            ! empty( $ctx_last['at'] ) ? esc_html( wp_date( 'Y-m-d H:i', (int) $ctx_last['at'] ) ) : '—'
                        ); ?>
                    <?php else : ?>
                        <em><?php esc_html_e( 'Never refreshed.', 'smart-chat-ai' ); ?></em>
                    <?php endif; ?>
                    <br><a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary" style="margin-top:6px;"><?php esc_html_e( 'Refresh Cache Now', 'smart-chat-ai' ); ?></a>
                </td>
            </tr>
        </table>

        <?php
        $wa_token   = (string) get_option( 'smsg_whatsapp_token', '' );
        $wa_phone   = (string) get_option( 'smsg_whatsapp_phone_id', '' );
        $wa_ready   = ( '' !== $wa_token ) && ( '' !== $wa_phone );
        ?>
        <h2><?php esc_html_e( 'WhatsApp (Live Handoff)', 'smart-chat-ai' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Connect Meta\'s WhatsApp Cloud API so the chat can hand a captured lead off to a human on WhatsApp.', 'smart-chat-ai' ); ?>
            <?php if ( $wa_ready ) : ?>
                <span style="color:#38a169;font-weight:600;">&#x2713; <?php esc_html_e( 'Connected', 'smart-chat-ai' ); ?></span>
            <?php else : ?>
                <span style="color:#e53e3e;font-weight:600;">&#x2717; <?php esc_html_e( 'Not connected', 'smart-chat-ai' ); ?></span>
            <?php endif; ?>
        </p>
        <details style="margin:0 0 12px;padding:10px 14px;background:#f6f7f7;border-left:4px solid #2271b1;max-width:780px;">
            <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'How to get your Access Token and Phone Number ID', 'smart-chat-ai' ); ?></summary>
            <ol style="margin:10px 0 0 20px;line-height:1.6;">
                <li><?php printf(
                    /* translators: %s: link to Meta for Developers */
                    wp_kses_post( __( 'Go to <a href="%s" target="_blank" rel="noopener">Meta for Developers</a> and create (or open) an App of type <strong>Business</strong>.', 'smart-chat-ai' ) ),
                    'https://developers.facebook.com/apps/'
                ); ?></li>
                <li><?php esc_html_e( 'In the left sidebar, add the WhatsApp product and open WhatsApp → API Setup.', 'smart-chat-ai' ); ?></li>
                <li><?php esc_html_e( 'Copy the Temporary access token (24h) — paste it below to test. For production, create a System User in Business Settings and generate a permanent token with the whatsapp_business_messaging + whatsapp_business_management scopes.', 'smart-chat-ai' ); ?></li>
                <li><?php esc_html_e( 'On the same API Setup screen, copy the Phone number ID (the numeric value under the test or verified WhatsApp number — not the phone number itself).', 'smart-chat-ai' ); ?></li>
                <li><?php esc_html_e( 'Add your own phone to "To" recipients to test, then click Send Message. Once a template (e.g. lead_received) is approved in WhatsApp Manager, paste the token + ID here.', 'smart-chat-ai' ); ?></li>
            </ol>
            <p style="margin:10px 0 0;">
                <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/get-started" target="_blank" rel="noopener"><?php esc_html_e( 'Full Cloud API setup guide →', 'smart-chat-ai' ); ?></a>
            </p>
        </details>
        <table class="form-table">
            <tr>
                <th><label for="smsg_whatsapp_token"><?php esc_html_e( 'Access Token', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="password" id="smsg_whatsapp_token" name="smsg_whatsapp_token" class="regular-text" value="<?php echo esc_attr( $wa_token ); ?>" placeholder="EAA...">
                    <p class="description"><?php esc_html_e( 'Meta Business Suite → WhatsApp → API Setup → Temporary or System User access token.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smsg_whatsapp_phone_id"><?php esc_html_e( 'Phone Number ID', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="text" id="smsg_whatsapp_phone_id" name="smsg_whatsapp_phone_id" class="regular-text" value="<?php echo esc_attr( $wa_phone ); ?>">
                    <p class="description"><?php esc_html_e( 'Numeric ID for the verified WhatsApp business number (not the phone number itself).', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smsg_business_name"><?php esc_html_e( 'Business Name (in messages)', 'smart-chat-ai' ); ?></label></th>
                <td><input type="text" id="smsg_business_name" name="smsg_business_name" class="regular-text" value="<?php echo esc_attr( get_option( 'smsg_business_name', get_bloginfo( 'name' ) ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="smsg_contractor_phone"><?php esc_html_e( 'Your Phone (handoff target)', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="text" id="smsg_contractor_phone" name="smsg_contractor_phone" class="regular-text" value="<?php echo esc_attr( get_option( 'smsg_contractor_phone' ) ); ?>" placeholder="+15551234567">
                    <p class="description"><?php esc_html_e( 'Where new booking requests are sent on WhatsApp.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Notify on New Lead', 'smart-chat-ai' ); ?></th>
                <td><label><input type="checkbox" name="smsg_notify_contractor" value="1" <?php checked( get_option( 'smsg_notify_contractor', '1' ), '1' ); ?>> <?php esc_html_e( 'WhatsApp me when a new booking request comes in', 'smart-chat-ai' ); ?></label></td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Business Info', 'smart-chat-ai' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="smart_chat_business_name"><?php esc_html_e( 'Business Name', 'smart-chat-ai' ); ?></label></th>
                <td><input type="text" name="smart_chat_business_name" id="smart_chat_business_name" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_business_name' ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="smart_chat_business_type"><?php esc_html_e( 'Business Type', 'smart-chat-ai' ); ?></label></th>
                <td><input type="text" name="smart_chat_business_type" id="smart_chat_business_type" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_business_type', 'contractor' ) ); ?>"></td>
            </tr>
            <tr>
                <th><label for="smart_chat_ai_personality"><?php esc_html_e( 'AI Personality', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <select name="smart_chat_ai_personality" id="smart_chat_ai_personality">
                        <option value="helpful" <?php selected( get_option( 'smart_chat_ai_personality' ), 'helpful' ); ?>><?php esc_html_e( 'Helpful', 'smart-chat-ai' ); ?></option>
                        <option value="professional" <?php selected( get_option( 'smart_chat_ai_personality' ), 'professional' ); ?>><?php esc_html_e( 'Professional', 'smart-chat-ai' ); ?></option>
                        <option value="friendly" <?php selected( get_option( 'smart_chat_ai_personality' ), 'friendly' ); ?>><?php esc_html_e( 'Friendly', 'smart-chat-ai' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_lead_email"><?php esc_html_e( 'Notification Email', 'smart-chat-ai' ); ?></label></th>
                <td><input type="email" name="smart_chat_lead_email" id="smart_chat_lead_email" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_lead_email', get_option( 'admin_email' ) ) ); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Email Notifications', 'smart-chat-ai' ); ?></th>
                <td><label><input type="checkbox" name="smart_chat_enable_email_notifications" value="1" <?php checked( get_option( 'smart_chat_enable_email_notifications' ) ); ?>> <?php esc_html_e( 'Send email when new lead is captured', 'smart-chat-ai' ); ?></label></td>
            </tr>
        </table>

        <?php submit_button( __( 'Save Settings', 'smart-chat-ai' ) ); ?>
    </form>
</div>
