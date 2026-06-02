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
                <th><label for="smart_chat_chat_color"><?php esc_html_e( 'Brand Color', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="color" name="smart_chat_chat_color" id="smart_chat_chat_color" value="<?php echo esc_attr( get_option( 'smart_chat_chat_color', '#43A94B' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'Used for the bubble, header, and Send button. Set to your primary brand color.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_chat_logo"><?php esc_html_e( 'Logo', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <?php $logo_url = (string) get_option( 'smart_chat_chat_logo', '' ); ?>
                    <input type="text" name="smart_chat_chat_logo" id="smart_chat_chat_logo" class="regular-text" value="<?php echo esc_attr( $logo_url ); ?>" placeholder="https://...">
                    <button type="button" class="button" id="smart_chat_logo_upload"><?php esc_html_e( 'Choose Image', 'smart-chat-ai' ); ?></button>
                    <p class="description"><?php esc_html_e( 'Shown in the chat header and on the bubble. Use a transparent PNG/SVG with good contrast against your brand color.', 'smart-chat-ai' ); ?></p>
                    <?php if ( $logo_url ) : ?>
                        <p><img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:48px;background:#222;padding:6px 10px;border-radius:6px;"></p>
                    <?php endif; ?>
                </td>
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
            <tr>
                <th><?php esc_html_e( 'Test Connection', 'smart-chat-ai' ); ?></th>
                <td>
                    <button type="button" class="button" id="scai-test-ai"><?php esc_html_e( 'Test AI Connection', 'smart-chat-ai' ); ?></button>
                    <span id="scai-test-ai-result" style="margin-left:10px;font-weight:600;"></span>
                    <p class="description"><?php esc_html_e( 'Save your settings first, then click to verify the API key and model actually work.', 'smart-chat-ai' ); ?></p>
                    <script>
                    (function(){
                        var btn = document.getElementById('scai-test-ai');
                        if(!btn) return;
                        btn.addEventListener('click', function(){
                            var out = document.getElementById('scai-test-ai-result');
                            out.style.color = '#666'; out.textContent = 'Testing...';
                            var data = new FormData();
                            data.append('action','scai_test_ai');
                            data.append('nonce','<?php echo esc_js( wp_create_nonce( 'scai_test_ai' ) ); ?>');
                            fetch(ajaxurl,{method:'POST',body:data,credentials:'same-origin'})
                              .then(function(r){return r.json();})
                              .then(function(j){
                                if(j.success){ out.style.color='#16794C'; out.textContent='✓ '+j.data.message; }
                                else { out.style.color='#B91C1C'; out.textContent='✗ '+(j.data&&j.data.message?j.data.message:'Failed'); }
                              })
                              .catch(function(){ out.style.color='#B91C1C'; out.textContent='✗ Request failed'; });
                        });
                    })();
                    </script>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_preprompt"><?php esc_html_e( 'Custom Preprompt', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <textarea name="smart_chat_preprompt" id="smart_chat_preprompt" rows="10" class="large-text code" placeholder="<?php esc_attr_e( 'Leave blank to use the default contractor prompt. Anything you put here REPLACES the default system prompt entirely.', 'smart-chat-ai' ); ?>"><?php echo esc_textarea( get_option( 'smart_chat_preprompt', '' ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Direct instructions for the AI. Use this to set tone, services, do-not-do rules, and how to capture leads. Sitemap content is still appended automatically below your prompt.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_suggestions"><?php esc_html_e( 'Suggested Questions', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <textarea name="smart_chat_suggestions" id="smart_chat_suggestions" rows="5" class="large-text" placeholder="Do you clean carpet?&#10;Can I get a quote?&#10;What areas do you serve?&#10;I want to schedule a visit"><?php echo esc_textarea( get_option( 'smart_chat_suggestions', "Do you clean carpet?\nCan I get a quote?\nWhat areas do you serve?\nI want to schedule a visit" ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One question per line. These show as tappable shortcuts when the chat first opens. Up to 6 are shown.', 'smart-chat-ai' ); ?></p>
                </td>
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
        $wa_number   = (string) get_option( 'smart_chat_whatsapp_number', '' );
        $wa_greeting = (string) get_option( 'smart_chat_whatsapp_greeting', __( "Hi! I'd like to ask about your services.", 'smart-chat-ai' ) );
        $wa_ready    = '' !== $wa_number;
        ?>
        <h2><?php esc_html_e( 'WhatsApp', 'smart-chat-ai' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Adds a "Chat on WhatsApp" button inside the chat widget. Visitors tap it, WhatsApp opens with your number and a prefilled greeting, and the conversation lands in your free WhatsApp Business app — no Meta developer app or API token needed.', 'smart-chat-ai' ); ?>
            <?php if ( $wa_ready ) : ?>
                <span style="color:#38a169;font-weight:600;">&#x2713; <?php esc_html_e( 'Connected', 'smart-chat-ai' ); ?></span>
            <?php else : ?>
                <span style="color:#888;"><?php esc_html_e( '(disabled until you add a number)', 'smart-chat-ai' ); ?></span>
            <?php endif; ?>
        </p>
        <table class="form-table">
            <tr>
                <th><label for="smart_chat_whatsapp_number"><?php esc_html_e( 'WhatsApp Number', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="text" id="smart_chat_whatsapp_number" name="smart_chat_whatsapp_number" class="regular-text" value="<?php echo esc_attr( $wa_number ); ?>" placeholder="+15551234567">
                    <p class="description"><?php esc_html_e( 'Include country code. US example: +15551234567. This is the number on your phone that runs the free WhatsApp Business app.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_whatsapp_greeting"><?php esc_html_e( 'Prefilled Message', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="text" id="smart_chat_whatsapp_greeting" name="smart_chat_whatsapp_greeting" class="regular-text" value="<?php echo esc_attr( $wa_greeting ); ?>">
                    <p class="description"><?php esc_html_e( 'Pre-populated in the visitor\'s WhatsApp before they hit send. Keep it short.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Booking / Calendly', 'smart-chat-ai' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="smart_chat_booking_url"><?php esc_html_e( 'Booking Link', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="url" id="smart_chat_booking_url" name="smart_chat_booking_url" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_booking_url', '' ) ); ?>" placeholder="https://calendly.com/your-handle/30min">
                    <p class="description"><?php esc_html_e( 'Paste your Calendly (or any scheduling) link. When set, the chat shows a "Pick a time" button when someone wants to schedule.', 'smart-chat-ai' ); ?></p>
                </td>
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
                <td>
                    <input type="email" name="smart_chat_lead_email" id="smart_chat_lead_email" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_lead_email', 'support@midlandfloors.com' ) ); ?>" placeholder="support@midlandfloors.com">
                    <p class="description"><?php esc_html_e( 'Where new chat leads are emailed.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_resend_api_key"><?php esc_html_e( 'Resend API Key', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="password" name="smart_chat_resend_api_key" id="smart_chat_resend_api_key" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_resend_api_key', '' ) ); ?>" placeholder="re_...">
                    <p class="description"><?php esc_html_e( 'Paste your Resend API key to send lead emails through Resend. Leave blank to use the default site mailer.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="smart_chat_resend_from"><?php esc_html_e( 'Resend From', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="text" name="smart_chat_resend_from" id="smart_chat_resend_from" class="regular-text" value="<?php echo esc_attr( get_option( 'smart_chat_resend_from', '' ) ); ?>" placeholder="Midland Floor Care &lt;support@midlandfloors.com&gt;">
                    <p class="description"><?php esc_html_e( 'The from name and address. The domain must be verified in your Resend account.', 'smart-chat-ai' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Email Notifications', 'smart-chat-ai' ); ?></th>
                <td><label><input type="checkbox" name="smart_chat_enable_email_notifications" value="1" <?php checked( get_option( 'smart_chat_enable_email_notifications' ) ); ?>> <?php esc_html_e( 'Send email when new lead is captured', 'smart-chat-ai' ); ?></label></td>
            </tr>
        </table>

        <?php submit_button( __( 'Save Settings', 'smart-chat-ai' ) ); ?>
    </form>
</div>
