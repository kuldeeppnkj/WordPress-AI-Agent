<?php
/**
 * Appearance & Branding.
 *
 * A dedicated "Appearance" screen lets the administrator theme the chat widget
 * to match ANY client's brand — colours (via CSS custom properties), ready-made
 * preset themes, a live preview that updates without a page refresh, branding
 * text (assistant name, greeting, welcome, placeholder), and a one-click
 * auto-detect that suggests a matching theme from the site's own colours/logo.
 *
 * Nothing is hardcoded: every colour is stored as an option and emitted as a
 * `--wpaia-*` CSS variable, which the stylesheet consumes with sensible
 * fallbacks — so an unconfigured site looks exactly as before.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Colour keys → the CSS custom property each one drives. Kept in one place so the
 * admin pickers, the preset themes, and the frontend generator all stay in sync.
 *
 * @return array<string,string>
 */
function wp_ai_agent_appearance_color_vars() {
    return array(
        'primary'      => '--wpaia-primary',
        'primary_dark' => '--wpaia-primary-dark',
        'accent'       => '--wpaia-accent',
        'background'   => '--wpaia-bg',
        'ai_bubble'    => '--wpaia-ai-bubble',
        'ai_text'      => '--wpaia-ai-text',
        'user_text'    => '--wpaia-user-text',
        'success'      => '--wpaia-success',
        'error'        => '--wpaia-error',
    );
}

/**
 * Human labels for the colour pickers.
 *
 * @return array<string,string>
 */
function wp_ai_agent_appearance_color_labels() {
    return array(
        'primary'      => __( 'Primary (buttons, header, links)', 'wp-ai-agent' ),
        'primary_dark' => __( 'Primary Dark (gradient end)', 'wp-ai-agent' ),
        'accent'       => __( 'Accent (user message bubble)', 'wp-ai-agent' ),
        'background'   => __( 'Chat Background', 'wp-ai-agent' ),
        'ai_bubble'    => __( 'AI Bubble Background', 'wp-ai-agent' ),
        'ai_text'      => __( 'AI Bubble Text', 'wp-ai-agent' ),
        'user_text'    => __( 'User Bubble Text', 'wp-ai-agent' ),
        'success'      => __( 'Success / WhatsApp', 'wp-ai-agent' ),
        'error'        => __( 'Error / Alerts', 'wp-ai-agent' ),
    );
}

/**
 * Ready-made preset themes. Each provides every colour key. "custom" is a marker
 * (no fixed palette) so the admin can hand-pick.
 *
 * @return array<string,array>
 */
function wp_ai_agent_appearance_presets() {
    return array(
        'ocean'     => array( 'label' => __( 'Ocean Blue', 'wp-ai-agent' ), 'primary' => '#1e73be', 'primary_dark' => '#155a96', 'accent' => '#2b86d6', 'background' => '#f7f9fc', 'ai_bubble' => '#ffffff', 'ai_text' => '#1f2733', 'user_text' => '#ffffff', 'success' => '#25d366', 'error' => '#b32d2e' ),
        'emerald'   => array( 'label' => __( 'Emerald Green', 'wp-ai-agent' ), 'primary' => '#0f9d58', 'primary_dark' => '#0c7c46', 'accent' => '#34c77b', 'background' => '#f4faf7', 'ai_bubble' => '#ffffff', 'ai_text' => '#14321f', 'user_text' => '#ffffff', 'success' => '#25d366', 'error' => '#d63638' ),
        'royal'     => array( 'label' => __( 'Royal Purple', 'wp-ai-agent' ), 'primary' => '#6d28d9', 'primary_dark' => '#4c1d95', 'accent' => '#8b5cf6', 'background' => '#f8f7fc', 'ai_bubble' => '#ffffff', 'ai_text' => '#241b3a', 'user_text' => '#ffffff', 'success' => '#22c55e', 'error' => '#dc2626' ),
        'sunset'    => array( 'label' => __( 'Sunset Orange', 'wp-ai-agent' ), 'primary' => '#ea580c', 'primary_dark' => '#c2410c', 'accent' => '#fb923c', 'background' => '#fff8f3', 'ai_bubble' => '#ffffff', 'ai_text' => '#3a2314', 'user_text' => '#ffffff', 'success' => '#16a34a', 'error' => '#dc2626' ),
        'dark'      => array( 'label' => __( 'Dark Mode', 'wp-ai-agent' ), 'primary' => '#3b82f6', 'primary_dark' => '#1e3a8a', 'accent' => '#60a5fa', 'background' => '#1f2430', 'ai_bubble' => '#2a3140', 'ai_text' => '#e5e9f0', 'user_text' => '#ffffff', 'success' => '#22c55e', 'error' => '#f87171' ),
        'light'     => array( 'label' => __( 'Light Mode', 'wp-ai-agent' ), 'primary' => '#2563eb', 'primary_dark' => '#1d4ed8', 'accent' => '#3b82f6', 'background' => '#ffffff', 'ai_bubble' => '#f3f4f6', 'ai_text' => '#111827', 'user_text' => '#ffffff', 'success' => '#16a34a', 'error' => '#dc2626' ),
        'minimal'   => array( 'label' => __( 'Minimal', 'wp-ai-agent' ), 'primary' => '#111827', 'primary_dark' => '#000000', 'accent' => '#374151', 'background' => '#ffffff', 'ai_bubble' => '#f9fafb', 'ai_text' => '#111827', 'user_text' => '#ffffff', 'success' => '#059669', 'error' => '#dc2626' ),
        'corporate' => array( 'label' => __( 'Corporate', 'wp-ai-agent' ), 'primary' => '#16314a', 'primary_dark' => '#0f2436', 'accent' => '#245a8d', 'background' => '#f5f7fa', 'ai_bubble' => '#ffffff', 'ai_text' => '#16314a', 'user_text' => '#ffffff', 'success' => '#2e9e5b', 'error' => '#b32d2e' ),
        'modern'    => array( 'label' => __( 'Modern', 'wp-ai-agent' ), 'primary' => '#7048e8', 'primary_dark' => '#5a34c4', 'accent' => '#9775fa', 'background' => '#f7f7fb', 'ai_bubble' => '#ffffff', 'ai_text' => '#1f2733', 'user_text' => '#ffffff', 'success' => '#12b886', 'error' => '#e03131' ),
    );
}

/**
 * Default appearance values (colours = Ocean Blue, which matches the widget's
 * original look; branding = empty so the built-in defaults apply).
 *
 * @return array
 */
function wp_ai_agent_appearance_defaults() {
    $presets  = wp_ai_agent_appearance_presets();
    $ocean    = $presets['ocean'];
    unset( $ocean['label'] );

    return array_merge(
        $ocean,
        array(
            'preset'         => 'ocean',
            'assistant_name' => '',
            'widget_button'  => '',
            'greeting'       => '',
            'subtitle'       => '',
            'welcome'        => '',
            'placeholder'    => '',
        )
    );
}

/**
 * The saved appearance settings, merged over the defaults.
 *
 * @return array
 */
function wp_ai_agent_get_appearance() {
    return wp_parse_args( get_option( 'wp_ai_agent_appearance', array() ), wp_ai_agent_appearance_defaults() );
}

/**
 * A single appearance value with a fallback.
 *
 * @param string $key     Setting key.
 * @param string $default Fallback when the value is empty.
 * @return string
 */
function wp_ai_agent_appearance( $key, $default = '' ) {
    $a = wp_ai_agent_get_appearance();
    return ( isset( $a[ $key ] ) && '' !== $a[ $key ] ) ? $a[ $key ] : $default;
}

/**
 * Sanitize the Appearance option on save.
 *
 * @param array $input Raw input.
 * @return array
 */
function wp_ai_agent_sanitize_appearance( $input ) {
    $defaults = wp_ai_agent_appearance_defaults();
    $out      = array();

    foreach ( wp_ai_agent_appearance_color_vars() as $key => $var ) {
        $val         = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
        $out[ $key ] = $val ? $val : $defaults[ $key ];
    }

    $presets          = wp_ai_agent_appearance_presets();
    $out['preset']    = ( isset( $input['preset'] ) && ( 'custom' === $input['preset'] || isset( $presets[ $input['preset'] ] ) ) ) ? sanitize_key( $input['preset'] ) : 'custom';

    $out['assistant_name'] = isset( $input['assistant_name'] ) ? sanitize_text_field( $input['assistant_name'] ) : '';
    $out['widget_button']  = isset( $input['widget_button'] ) ? sanitize_text_field( $input['widget_button'] ) : '';
    $out['greeting']       = isset( $input['greeting'] ) ? sanitize_text_field( $input['greeting'] ) : '';
    $out['subtitle']       = isset( $input['subtitle'] ) ? sanitize_text_field( $input['subtitle'] ) : '';
    $out['welcome']        = isset( $input['welcome'] ) ? sanitize_textarea_field( $input['welcome'] ) : '';
    $out['placeholder']    = isset( $input['placeholder'] ) ? sanitize_text_field( $input['placeholder'] ) : '';

    return $out;
}

add_action( 'admin_init', 'wp_ai_agent_register_appearance_settings' );
function wp_ai_agent_register_appearance_settings() {
    register_setting( 'wp_ai_agent_appearance_group', 'wp_ai_agent_appearance', 'wp_ai_agent_sanitize_appearance' );
}

/**
 * Build the inline CSS that maps the saved colours onto the widget's CSS
 * variables. Emitted after the stylesheet so it overrides the defaults.
 *
 * @return string
 */
function wp_ai_agent_appearance_css() {
    $a    = wp_ai_agent_get_appearance();
    $rules = '';
    foreach ( wp_ai_agent_appearance_color_vars() as $key => $var ) {
        if ( ! empty( $a[ $key ] ) ) {
            $rules .= $var . ':' . $a[ $key ] . ';';
        }
    }
    if ( '' === $rules ) {
        return '';
    }
    return '#wp-ai-agent-widget{' . $rules . '}';
}

/**
 * Push the theme colours onto the frontend widget as CSS-variable overrides.
 */
add_action( 'wp_enqueue_scripts', 'wp_ai_agent_appearance_inline_css', 20 );
function wp_ai_agent_appearance_inline_css() {
    if ( ! wp_style_is( 'wp-ai-agent-chat', 'enqueued' ) ) {
        return;
    }
    $css = wp_ai_agent_appearance_css();
    if ( '' !== $css ) {
        wp_add_inline_style( 'wp-ai-agent-chat', $css );
    }
}

/* -------------------------------------------------------------------------
 * Branding: let custom text override the built-in welcome / subtitle.
 * ---------------------------------------------------------------------- */

add_filter( 'wp_ai_agent_welcome_message', 'wp_ai_agent_appearance_welcome' );
function wp_ai_agent_appearance_welcome( $default ) {
    $custom = wp_ai_agent_appearance( 'welcome', '' );
    return ( '' !== $custom ) ? $custom : $default;
}

add_filter( 'wp_ai_agent_home_intro', 'wp_ai_agent_appearance_subtitle' );
function wp_ai_agent_appearance_subtitle( $default ) {
    $custom = wp_ai_agent_appearance( 'subtitle', '' );
    return ( '' !== $custom ) ? $custom : $default;
}

/* -------------------------------------------------------------------------
 * Auto brand detection — suggest a matching palette from the site itself.
 * ---------------------------------------------------------------------- */

/**
 * Relative luminance (0–1) of a #rrggbb colour, for light/dark decisions.
 *
 * @param string $hex Hex colour.
 * @return float
 */
function wp_ai_agent_hex_luminance( $hex ) {
    $hex = ltrim( (string) $hex, '#' );
    if ( 3 === strlen( $hex ) ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if ( 6 !== strlen( $hex ) ) {
        return 1.0;
    }
    $r = hexdec( substr( $hex, 0, 2 ) ) / 255;
    $g = hexdec( substr( $hex, 2, 2 ) ) / 255;
    $b = hexdec( substr( $hex, 4, 2 ) ) / 255;
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/**
 * Suggest an appearance palette from the site's own brand cues — the theme's
 * background (light vs dark) and any brand colour stored in the Customizer theme
 * mods. Best-effort and never destructive: it only pre-fills the pickers; the
 * admin still has to Save. No colour is hardcoded per-site.
 *
 * @return array Colour keys → hex.
 */
function wp_ai_agent_appearance_suggest() {
    $presets = wp_ai_agent_appearance_presets();
    $bg_hex  = get_background_color(); // 'ffffff' style, may be empty.
    $is_dark = ( $bg_hex && wp_ai_agent_hex_luminance( '#' . $bg_hex ) < 0.4 );

    // Start from a light or dark base.
    $base = $is_dark ? $presets['dark'] : $presets['ocean'];
    unset( $base['label'] );

    // Try to find a brand/primary colour in the theme mods (skip the background).
    $mods = get_theme_mods();
    if ( is_array( $mods ) ) {
        foreach ( $mods as $value ) {
            if ( ! is_string( $value ) ) {
                continue;
            }
            $hex = sanitize_hex_color( '#' . ltrim( $value, '#' ) );
            if ( ! $hex ) {
                continue;
            }
            $bare = strtolower( ltrim( $hex, '#' ) );
            if ( $bg_hex && $bare === strtolower( $bg_hex ) ) {
                continue; // that's the background, not the brand colour.
            }
            // A usable brand colour: adopt it as primary + a darker variant.
            $base['primary']      = $hex;
            $base['primary_dark'] = $hex;
            $base['accent']       = $hex;
            break;
        }
    }

    if ( $is_dark && $bg_hex ) {
        $base['background'] = '#' . ltrim( $bg_hex, '#' );
    }

    return $base;
}

/* -------------------------------------------------------------------------
 * Admin page.
 * ---------------------------------------------------------------------- */

/**
 * Enqueue the WordPress colour picker on the Appearance screen only.
 *
 * @param string $hook Current admin page hook.
 */
add_action( 'admin_enqueue_scripts', 'wp_ai_agent_appearance_admin_assets' );
function wp_ai_agent_appearance_admin_assets( $hook ) {
    if ( false === strpos( (string) $hook, 'wp-ai-agent-appearance' ) ) {
        return;
    }
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );
}

/**
 * Render the Appearance admin screen.
 */
function wp_ai_agent_appearance_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
                        
    $a        = wp_ai_agent_get_appearance();
    $vars     = wp_ai_agent_appearance_color_vars();
    $labels   = wp_ai_agent_appearance_color_labels();
    $presets  = wp_ai_agent_appearance_presets();
    $suggest  = wp_ai_agent_appearance_suggest();
    
    // JS-friendly maps.
    $presets_js = array();
    foreach ( $presets as $slug => $p ) {
        unset( $p['label'] );
        $presets_js[ $slug ] = $p;
    }
    ?>
                     
    <div class="wrap">
        <h1><?php esc_html_e( 'AI Agent — Appearance', 'wp-ai-agent' ); ?></h1>
        <p><?php esc_html_e( 'Match the chat widget to your brand. Pick a preset or fine-tune each colour — the live preview updates instantly. Nothing changes on your site until you press Save.', 'wp-ai-agent' ); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'wp_ai_agent_appearance_group' ); ?>
            <input type="hidden" id="wpaia-preset" name="wp_ai_agent_appearance[preset]" value="<?php echo esc_attr( $a['preset'] ); ?>" />

            <div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:1 1 460px;min-width:320px;">

                    <h2><?php esc_html_e( 'Preset Themes', 'wp-ai-agent' ); ?></h2>
                    <div id="wpaia-presets" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
                        <?php foreach ( $presets as $slug => $p ) : ?>
                            <button type="button" class="button wpaia-preset-btn" data-preset="<?php echo esc_attr( $slug ); ?>" style="display:flex;align-items:center;gap:7px;">
                                <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:<?php echo esc_attr( $p['primary'] ); ?>;border:1px solid rgba(0,0,0,.15);"></span>
                                <?php echo esc_html( $p['label'] ); ?>
                            </button>
                        <?php endforeach; ?>
                        <button type="button" class="button" id="wpaia-autodetect"><?php esc_html_e( '✨ Auto-detect from my website', 'wp-ai-agent' ); ?></button>
                    </div>

                    <h2><?php esc_html_e( 'Colours', 'wp-ai-agent' ); ?></h2>
                    <table class="form-table" role="presentation"><tbody>
                        <?php foreach ( $vars as $key => $var ) : ?>
                            <tr>
                                <th scope="row"><label for="wpaia-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $labels[ $key ] ); ?></label></th>
                                <td>
                                    <input type="text" id="wpaia-<?php echo esc_attr( $key ); ?>" class="wpaia-color"
                                        data-var="<?php echo esc_attr( $var ); ?>"
                                        name="wp_ai_agent_appearance[<?php echo esc_attr( $key ); ?>]"
                                        value="<?php echo esc_attr( $a[ $key ] ); ?>"
                                        data-default-color="<?php echo esc_attr( $a[ $key ] ); ?>" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table>

                    <h2><?php esc_html_e( 'Branding', 'wp-ai-agent' ); ?></h2>
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><label for="wpaia-assistant_name"><?php esc_html_e( 'Assistant Name', 'wp-ai-agent' ); ?></label></th>
                            <td><input type="text" id="wpaia-assistant_name" class="regular-text" name="wp_ai_agent_appearance[assistant_name]" value="<?php echo esc_attr( $a['assistant_name'] ); ?>" placeholder="<?php esc_attr_e( 'AI Assistant', 'wp-ai-agent' ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpaia-widget_button"><?php esc_html_e( 'Widget Button Text', 'wp-ai-agent' ); ?></label></th>
                            <td><input type="text" id="wpaia-widget_button" class="regular-text" name="wp_ai_agent_appearance[widget_button]" value="<?php echo esc_attr( $a['widget_button'] ); ?>" placeholder="<?php esc_attr_e( 'Need Help?', 'wp-ai-agent' ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpaia-greeting"><?php esc_html_e( 'Home Greeting', 'wp-ai-agent' ); ?></label></th>
                            <td><input type="text" id="wpaia-greeting" class="regular-text" name="wp_ai_agent_appearance[greeting]" value="<?php echo esc_attr( $a['greeting'] ); ?>" placeholder="<?php esc_attr_e( 'Hi there', 'wp-ai-agent' ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpaia-subtitle"><?php esc_html_e( 'Home Subtitle', 'wp-ai-agent' ); ?></label></th>
                            <td><input type="text" id="wpaia-subtitle" class="large-text" name="wp_ai_agent_appearance[subtitle]" value="<?php echo esc_attr( $a['subtitle'] ); ?>" placeholder="<?php esc_attr_e( 'Welcome! How can I assist you today?', 'wp-ai-agent' ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpaia-welcome"><?php esc_html_e( 'Welcome Message', 'wp-ai-agent' ); ?></label></th>
                            <td><textarea id="wpaia-welcome" rows="3" class="large-text" name="wp_ai_agent_appearance[welcome]" placeholder="<?php esc_attr_e( 'Leave blank to use the smart default greeting.', 'wp-ai-agent' ); ?>"><?php echo esc_textarea( $a['welcome'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wpaia-placeholder"><?php esc_html_e( 'Input Placeholder', 'wp-ai-agent' ); ?></label></th>
                            <td><input type="text" id="wpaia-placeholder" class="regular-text" name="wp_ai_agent_appearance[placeholder]" value="<?php echo esc_attr( $a['placeholder'] ); ?>" placeholder="<?php esc_attr_e( 'Ask a question...', 'wp-ai-agent' ); ?>" /></td>
                        </tr>
                    </tbody></table>

                    <?php submit_button( __( 'Save Appearance', 'wp-ai-agent' ) ); ?>
                </div>

                <div style="flex:0 0 320px;position:sticky;top:40px;">
                    <h2><?php esc_html_e( 'Live Preview', 'wp-ai-agent' ); ?></h2>
                    <div id="wpaia-preview" style="
                        --wpaia-primary:<?php echo esc_attr( $a['primary'] ); ?>;
                        --wpaia-primary-dark:<?php echo esc_attr( $a['primary_dark'] ); ?>;
                        --wpaia-accent:<?php echo esc_attr( $a['accent'] ); ?>;
                        --wpaia-bg:<?php echo esc_attr( $a['background'] ); ?>;
                        --wpaia-ai-bubble:<?php echo esc_attr( $a['ai_bubble'] ); ?>;
                        --wpaia-ai-text:<?php echo esc_attr( $a['ai_text'] ); ?>;
                        --wpaia-user-text:<?php echo esc_attr( $a['user_text'] ); ?>;
                        --wpaia-success:<?php echo esc_attr( $a['success'] ); ?>;
                        --wpaia-error:<?php echo esc_attr( $a['error'] ); ?>;
                        width:300px;border-radius:16px;overflow:hidden;box-shadow:0 12px 30px rgba(0,0,0,.18);border:1px solid #e2e6ea;">
                        <div style="background:linear-gradient(135deg,var(--wpaia-primary),var(--wpaia-primary-dark));color:#fff;padding:14px 16px;font-weight:700;">
                            <span id="wpaia-pv-name"><?php echo esc_html( '' !== $a['assistant_name'] ? $a['assistant_name'] : __( 'AI Assistant', 'wp-ai-agent' ) ); ?></span>
                        </div>
                        <div style="background:var(--wpaia-bg);padding:14px;display:flex;flex-direction:column;gap:10px;min-height:210px;">
                            <div style="align-self:flex-start;max-width:80%;background:var(--wpaia-ai-bubble);color:var(--wpaia-ai-text);border-radius:14px;border-bottom-left-radius:4px;padding:9px 13px;font-size:13px;box-shadow:0 1px 2px rgba(0,0,0,.08);"><?php esc_html_e( 'Hi! How can I help you today? 😊', 'wp-ai-agent' ); ?></div>
                            <div style="align-self:flex-end;max-width:80%;background:linear-gradient(135deg,var(--wpaia-primary),var(--wpaia-accent));color:var(--wpaia-user-text);border-radius:14px;border-bottom-right-radius:4px;padding:9px 13px;font-size:13px;"><?php esc_html_e( 'What are your best sellers?', 'wp-ai-agent' ); ?></div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
                                <span style="background:var(--wpaia-ai-bubble);color:var(--wpaia-primary);border:1px solid var(--wpaia-primary);border-radius:16px;padding:5px 11px;font-size:12px;"><?php esc_html_e( '🛍️ Products', 'wp-ai-agent' ); ?></span>
                                <span style="background:var(--wpaia-success);color:#fff;border-radius:16px;padding:5px 11px;font-size:12px;"><?php esc_html_e( '💬 WhatsApp', 'wp-ai-agent' ); ?></span>
                            </div>
                        </div>
                        <div style="background:#fff;border-top:1px solid #eef1f5;padding:10px;display:flex;gap:8px;align-items:center;">
                            <span style="flex:1;color:#9aa7b6;font-size:12.5px;border:1px solid #dde3ea;border-radius:20px;padding:8px 12px;" id="wpaia-pv-placeholder"><?php echo esc_html( '' !== $a['placeholder'] ? $a['placeholder'] : __( 'Ask a question...', 'wp-ai-agent' ) ); ?></span>
                            <span style="width:34px;height:34px;border-radius:50%;background:var(--wpaia-primary);display:inline-flex;align-items:center;justify-content:center;color:#fff;">➤</span>
                        </div>
                    </div>
                    <p class="description" style="margin-top:10px;"><?php esc_html_e( 'Updates live as you change colours. Press Save Appearance to apply on the site.', 'wp-ai-agent' ); ?></p>
                </div>
            </div>
        </form>
    </div>

    <script>
    ( function () {
        var presets = <?php echo wp_json_encode( $presets_js ); ?>;
        var suggested = <?php echo wp_json_encode( $suggest ); ?>;
        var preview = document.getElementById( 'wpaia-preview' );
        var presetInput = document.getElementById( 'wpaia-preset' );
        var $ = window.jQuery;

        function setVar( cssVar, value ) {
            if ( preview && value ) { preview.style.setProperty( cssVar, value ); }
        }

        // Initialise the WordPress colour pickers.
        function initPickers() {
            if ( ! $ || ! $.fn || ! $.fn.wpColorPicker ) { return false; }
            $( '.wpaia-color' ).wpColorPicker( {
                change: function ( event, ui ) {
                    var el = event.target;
                    var v  = ui.color ? ui.color.toString() : el.value;
                    setVar( el.getAttribute( 'data-var' ), v );
                    if ( presetInput ) { presetInput.value = 'custom'; }
                },
                clear: function ( event ) {
                    // no-op: leaving a colour empty falls back to the stylesheet default.
                }
            } );
            return true;
        }

        // Apply a palette object to every picker + the preview.
        function applyPalette( palette, presetSlug ) {
            Object.keys( palette ).forEach( function ( key ) {
                var input = document.getElementById( 'wpaia-' + key );
                if ( ! input ) { return; }
                if ( $ && $.fn.wpColorPicker ) {
                    $( input ).wpColorPicker( 'color', palette[ key ] );
                } else {
                    input.value = palette[ key ];
                }
                setVar( input.getAttribute( 'data-var' ), palette[ key ] );
            } );
            if ( presetInput ) { presetInput.value = presetSlug || 'custom'; }
        }

        document.querySelectorAll( '.wpaia-preset-btn' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var slug = btn.getAttribute( 'data-preset' );
                if ( presets[ slug ] ) { applyPalette( presets[ slug ], slug ); }
            } );
        } );
                                    
        var auto = document.getElementById( 'wpaia-autodetect' );
        if ( auto ) {
            auto.addEventListener( 'click', function () {
                applyPalette( suggested, 'custom' );
            } );
        }

        // Live-update preview branding text.
        function bindText( inputId, targetId, fallback ) {
            var input = document.getElementById( inputId );
            var target = document.getElementById( targetId );
            if ( ! input || ! target ) { return; }
            input.addEventListener( 'input', function () {
                target.textContent = input.value || fallback;
            } );
        }
        bindText( 'wpaia-assistant_name', 'wpaia-pv-name', <?php echo wp_json_encode( __( 'AI Assistant', 'wp-ai-agent' ) ); ?> );
        bindText( 'wpaia-placeholder', 'wpaia-pv-placeholder', <?php echo wp_json_encode( __( 'Ask a question...', 'wp-ai-agent' ) ); ?> );

        // wp-color-picker loads with jQuery; init when ready.
        if ( $ ) { $( initPickers ); } else { document.addEventListener( 'DOMContentLoaded', initPickers ); }
    } )();
    </script>
    <?php
}



