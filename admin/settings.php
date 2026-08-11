<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wp_ai_agent_admin_settings_page() {
    $options = wp_ai_agent_get_options();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'AI Agent Settings', 'wp-ai-agent' ); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'wp_ai_agent_settings' );
            do_settings_sections( 'wp_ai_agent_settings' );
            ?>
            <?php // Preserve any legacy single key so existing installs keep working as a fallback. ?>
            <input type="hidden" name="wp_ai_agent_options[api_key]" value="<?php echo esc_attr( $options['api_key'] ); ?>" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[provider]"><?php esc_html_e( 'AI Provider', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <select name="wp_ai_agent_options[provider]" id="wp_ai_agent_options[provider]">
                            <option value="openai" <?php selected( $options['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'wp-ai-agent' ); ?></option>
                            <option value="gemini" <?php selected( $options['provider'], 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'wp-ai-agent' ); ?></option>
                            <option value="groq" <?php selected( $options['provider'], 'groq' ); ?>><?php esc_html_e( 'Groq', 'wp-ai-agent' ); ?></option>
                            <option value="claude" <?php selected( $options['provider'], 'claude' ); ?>><?php esc_html_e( 'Anthropic Claude (coming soon)', 'wp-ai-agent' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Choose your AI provider, then enter its API key below. The selected provider is used everywhere — chat and image search both work with it.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr class="wp-ai-agent-key-row" data-provider="openai">
                    <th scope="row"><label for="wp_ai_agent_options[api_key_openai]"><?php esc_html_e( 'OpenAI API Key', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[api_key_openai]" type="password" id="wp_ai_agent_options[api_key_openai]" value="<?php echo esc_attr( $options['api_key_openai'] ); ?>" class="regular-text" autocomplete="off" />
                        <p class="description"><?php esc_html_e( 'Used when the OpenAI provider is selected.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr class="wp-ai-agent-key-row" data-provider="gemini">
                    <th scope="row"><label for="wp_ai_agent_options[api_key_gemini]"><?php esc_html_e( 'Google Gemini API Key', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[api_key_gemini]" type="password" id="wp_ai_agent_options[api_key_gemini]" value="<?php echo esc_attr( $options['api_key_gemini'] ); ?>" class="regular-text" autocomplete="off" />
                        <p class="description"><?php esc_html_e( 'Used when the Google Gemini provider is selected.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr class="wp-ai-agent-key-row" data-provider="groq">
                    <th scope="row"><label for="wp_ai_agent_options[api_key_groq]"><?php esc_html_e( 'Groq API Key', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[api_key_groq]" type="password" id="wp_ai_agent_options[api_key_groq]" value="<?php echo esc_attr( $options['api_key_groq'] ); ?>" class="regular-text" autocomplete="off" />
                        <p class="description"><?php esc_html_e( 'Used when the Groq provider is selected.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[model]"><?php esc_html_e( 'Model', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[model]" type="text" id="wp_ai_agent_options[model]" value="<?php echo esc_attr( $options['model'] ); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Recommended models — OpenAI: gpt-4o-mini, Gemini: gemini-1.5-flash, Groq: llama-3.3-70b-versatile', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[whatsapp_number]"><?php esc_html_e( 'WhatsApp Number', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[whatsapp_number]" type="text" id="wp_ai_agent_options[whatsapp_number]" value="<?php echo esc_attr( $options['whatsapp_number'] ); ?>" class="regular-text" placeholder="+919876543210" />
                        <p class="description"><?php esc_html_e( 'Used for "talk to a human" handoff. Include country code (digits only also fine). Leave blank to disable WhatsApp handoff.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[whatsapp_default_message]"><?php esc_html_e( 'WhatsApp Default Message', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[whatsapp_default_message]" type="text" id="wp_ai_agent_options[whatsapp_default_message]" value="<?php echo esc_attr( $options['whatsapp_default_message'] ); ?>" class="large-text" placeholder="Hello, I need support regarding:" />
                        <p class="description"><?php esc_html_e( "Prefilled into WhatsApp. The visitor's question is added on the next line.", 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[business_name]"><?php esc_html_e( 'Business Name', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[business_name]" type="text" id="wp_ai_agent_options[business_name]" value="<?php echo esc_attr( $options['business_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
                        <p class="description"><?php esc_html_e( 'Shown in the handoff message ("I can connect you with the {Business} support team"). Defaults to your site name.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[notify_email]"><?php esc_html_e( 'Notification Email', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[notify_email]" type="email" id="wp_ai_agent_options[notify_email]" value="<?php echo esc_attr( $options['notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                        <p class="description"><?php esc_html_e( 'Where new leads, bookings, and support tickets are emailed. Defaults to the site admin email.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[lead_mode]"><?php esc_html_e( 'Lead Collection Mode', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <?php $lead_mode = isset( $options['lead_mode'] ) ? $options['lead_mode'] : 'form'; ?>
                        <select name="wp_ai_agent_options[lead_mode]" id="wp_ai_agent_options[lead_mode]">
                            <option value="form" <?php selected( $lead_mode, 'form' ); ?>><?php esc_html_e( 'Prefer website contact form (Recommended)', 'wp-ai-agent' ); ?></option>
                            <option value="ai" <?php selected( $lead_mode, 'ai' ); ?>><?php esc_html_e( 'AI lead collection (ask in chat)', 'wp-ai-agent' ); ?></option>
                            <option value="both" <?php selected( $lead_mode, 'both' ); ?>><?php esc_html_e( 'Both — offer the form and in-chat option', 'wp-ai-agent' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'When a visitor wants to get in touch: “Prefer form” hands them to your existing contact/quote/enquiry form when one exists (and only collects details in chat if none is found). “AI lead collection” always asks for name, email and phone in chat. “Both” offers the form and a “share here” option. Forms are detected automatically — no URLs to configure.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable Chat Widget', 'wp-ai-agent' ); ?></th>
                    <td>
                        <label><input name="wp_ai_agent_options[enable_chat]" type="checkbox" value="1" <?php checked( $options['enable_chat'], '1' ); ?> /> <?php esc_html_e( 'Show chat widget on the frontend', 'wp-ai-agent' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[widget_position]"><?php esc_html_e( 'Widget Position', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <?php $wpaia_pos = isset( $options['widget_position'] ) ? $options['widget_position'] : 'bottom-right'; ?>
                        <select name="wp_ai_agent_options[widget_position]" id="wp_ai_agent_options[widget_position]">
                            <option value="bottom-right" <?php selected( $wpaia_pos, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'wp-ai-agent' ); ?></option>
                            <option value="bottom-left" <?php selected( $wpaia_pos, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'wp-ai-agent' ); ?></option>
                            <option value="top-right" <?php selected( $wpaia_pos, 'top-right' ); ?>><?php esc_html_e( 'Top Right', 'wp-ai-agent' ); ?></option>
                            <option value="top-left" <?php selected( $wpaia_pos, 'top-left' ); ?>><?php esc_html_e( 'Top Left', 'wp-ai-agent' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Fixed floating corner for the widget on every page.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show On', 'wp-ai-agent' ); ?></th>
                    <td>
                        <?php
                        $wpaia_show = array(
                            'show_homepage' => __( 'Homepage', 'wp-ai-agent' ),
                            'show_pages'    => __( 'Pages', 'wp-ai-agent' ),
                            'show_posts'    => __( 'Posts', 'wp-ai-agent' ),
                            'show_products' => __( 'Products', 'wp-ai-agent' ),
                            'show_archives' => __( 'Categories / Archives / Shop', 'wp-ai-agent' ),
                        );
                        foreach ( $wpaia_show as $key => $label ) :
                            ?>
                            <label style="display:inline-block;margin:0 16px 6px 0;"><input name="wp_ai_agent_options[<?php echo esc_attr( $key ); ?>]" type="checkbox" value="1" <?php checked( isset( $options[ $key ] ) ? $options[ $key ] : '1', '1' ); ?> /> <?php echo esc_html( $label ); ?></label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e( 'The widget shows on all these page types by default. Untick any where it should be hidden.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp_ai_agent_options[exclude_ids]"><?php esc_html_e( 'Exclude Pages', 'wp-ai-agent' ); ?></label></th>
                    <td>
                        <input name="wp_ai_agent_options[exclude_ids]" id="wp_ai_agent_options[exclude_ids]" type="text" class="regular-text" value="<?php echo esc_attr( isset( $options['exclude_ids'] ) ? $options['exclude_ids'] : '' ); ?>" placeholder="e.g. 12, 34, 56" />
                        <p class="description"><?php esc_html_e( 'Comma-separated page/post IDs where the widget must NOT appear (e.g. checkout or a landing page).', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Guided Mode', 'wp-ai-agent' ); ?></th>
                    <td>
                        <label><input name="wp_ai_agent_options[guided_mode]" type="checkbox" value="1" <?php checked( isset( $options['guided_mode'] ) ? $options['guided_mode'] : '1', '1' ); ?> /> <?php esc_html_e( 'Guide shoppers with tappable quick-action chips (colour, budget, categories) beside results', 'wp-ai-agent' ); ?></label>
                        <p class="description"><?php esc_html_e( 'When ON, product replies include dynamic buttons (available colours, a budget filter, categories, best sellers) built from your live catalog — so customers can refine without typing. Free-text chat always works too.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Voice Assistant', 'wp-ai-agent' ); ?></th>
                    <td>
                        <label><input name="wp_ai_agent_options[voice_mode]" type="checkbox" value="1" <?php checked( isset( $options['voice_mode'] ) ? $options['voice_mode'] : '1', '1' ); ?> /> <?php esc_html_e( 'Show the Voice button in the widget (tap & speak — the browser converts speech to text)', 'wp-ai-agent' ); ?></label>
                        <p class="description"><?php esc_html_e( 'The large centre button in the bottom navigation. Speech is transcribed by the visitor’s browser and sent to the AI exactly like a typed message — no extra API or key needed. Works in Chrome, Edge and Safari.', 'wp-ai-agent' ); ?></p>
                        <label style="display:block;margin-top:8px;"><input name="wp_ai_agent_options[voice_manual_send]" type="checkbox" value="1" <?php checked( isset( $options['voice_manual_send'] ) ? $options['voice_manual_send'] : '0', '1' ); ?> /> <?php esc_html_e( 'Require manual Send (off by default = ChatGPT-style: the transcript is sent automatically after you finish speaking; tick this to instead drop it in the box for review/edit)', 'wp-ai-agent' ); ?></label>
                        <label style="display:block;margin-top:8px;"><input name="wp_ai_agent_options[voice_reply]" type="checkbox" value="1" <?php checked( isset( $options['voice_reply'] ) ? $options['voice_reply'] : '0', '1' ); ?> /> <?php esc_html_e( 'Read replies aloud when the visitor used voice (text-to-speech). Raw URLs are never spoken.', 'wp-ai-agent' ); ?></label>
                        <p style="margin-top:8px;">
                            <label style="margin-right:14px;"><?php esc_html_e( 'Speed', 'wp-ai-agent' ); ?> <input name="wp_ai_agent_options[speech_rate]" type="number" min="0.5" max="2" step="0.1" style="width:70px;" value="<?php echo esc_attr( isset( $options['speech_rate'] ) ? $options['speech_rate'] : '1' ); ?>" /></label>
                            <label style="margin-right:14px;"><?php esc_html_e( 'Pitch', 'wp-ai-agent' ); ?> <input name="wp_ai_agent_options[speech_pitch]" type="number" min="0" max="2" step="0.1" style="width:70px;" value="<?php echo esc_attr( isset( $options['speech_pitch'] ) ? $options['speech_pitch'] : '1' ); ?>" /></label>
                            <label><?php esc_html_e( 'Volume', 'wp-ai-agent' ); ?> <input name="wp_ai_agent_options[speech_volume]" type="number" min="0" max="1" step="0.1" style="width:70px;" value="<?php echo esc_attr( isset( $options['speech_volume'] ) ? $options['speech_volume'] : '1' ); ?>" /></label>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'General AI Answers', 'wp-ai-agent' ); ?></th>
                    <td>
                        <label><input name="wp_ai_agent_options[allow_general_ai]" type="checkbox" value="1" <?php checked( $options['allow_general_ai'], '1' ); ?> /> <?php esc_html_e( 'Let the AI answer from general knowledge when nothing is found on the website', 'wp-ai-agent' ); ?></label>
                        <p class="description"><?php esc_html_e( 'OFF (default) = website-only: replies strictly from your site content + tools. ON = ChatGPT-style: if the answer is not on your site, the AI answers from its general knowledge (it still will not invent your prices, stock, or policies).', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Semantic Search', 'wp-ai-agent' ); ?></th>
                    <td>
                        <label><input name="wp_ai_agent_options[enable_semantic]" type="checkbox" value="1" <?php checked( $options['enable_semantic'], '1' ); ?> /> <?php esc_html_e( 'Use AI embeddings for meaning-based content search (recommended)', 'wp-ai-agent' ); ?></label>
                        <p class="description"><?php esc_html_e( 'Finds related content even when exact keywords differ (e.g. "cost" matches "pricing"). Requires an OpenAI or Gemini API key. Rebuild the content index after enabling.', 'wp-ai-agent' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <script>
    ( function () {
        var providerSelect = document.getElementById( 'wp_ai_agent_options[provider]' );
        var keyRows = document.querySelectorAll( '.wp-ai-agent-key-row' );
        if ( ! providerSelect || ! keyRows.length ) {
            return;
        }
        // Show only the selected provider's API key field.
        function toggleKeyRows() {
            var selected = providerSelect.value;
            keyRows.forEach( function ( row ) {
                row.style.display = ( row.getAttribute( 'data-provider' ) === selected ) ? '' : 'none';
            } );
        }
        providerSelect.addEventListener( 'change', toggleKeyRows );
        toggleKeyRows();
    } )();
    </script>
    <?php
}
