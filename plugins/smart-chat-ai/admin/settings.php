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
