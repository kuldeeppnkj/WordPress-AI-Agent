<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Branding (falls back to the built-in defaults when not customised in Appearance).
$wpaia_brand = function_exists( 'wp_ai_agent_appearance' )
    ? array(
        'assistant'   => wp_ai_agent_appearance( 'assistant_name', __( 'AI Agent', 'wp-ai-agent' ) ),
        'greeting'    => wp_ai_agent_appearance( 'greeting', __( 'Hi there', 'wp-ai-agent' ) ),
        'placeholder' => wp_ai_agent_appearance( 'placeholder', __( 'Ask a question...', 'wp-ai-agent' ) ),
    )
    : array(
        'assistant'   => __( 'AI Agent', 'wp-ai-agent' ),
        'greeting'    => __( 'Hi there', 'wp-ai-agent' ),
        'placeholder' => __( 'Ask a question...', 'wp-ai-agent' ),
    );

// The robot avatar — an inline SVG (vector = crisp on every screen, no external
// asset). Rendered inside a white circle in the launcher and the header, so the
// robot becomes the visual identity of the assistant.
$wpaia_robot = '<svg class="wp-ai-agent-robot" viewBox="0 0 48 48" fill="none" aria-hidden="true" focusable="false">'
    . '<circle cx="24" cy="6" r="2.6" fill="currentColor"/>'
    . '<rect x="22.8" y="8" width="2.4" height="6" rx="1.2" fill="currentColor"/>'
    . '<rect x="8" y="13" width="32" height="27" rx="12" fill="currentColor"/>'
    . '<rect x="3" y="21" width="6" height="12" rx="3" fill="currentColor"/>'
    . '<rect x="39" y="21" width="6" height="12" rx="3" fill="currentColor"/>'
    . '<rect x="13" y="20" width="22" height="14" rx="7" fill="#ffffff"/>'
    . '<circle cx="20" cy="27" r="2.5" fill="currentColor"/>'
    . '<circle cx="28" cy="27" r="2.5" fill="currentColor"/>'
    . '<path d="M19.5 30.6c1.6 1.7 7.4 1.7 9 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>'
    . '</svg>';
$wpaia_pos = function_exists( 'wp_ai_agent_option' ) ? wp_ai_agent_option( 'widget_position', 'bottom-right' ) : 'bottom-right';
?>
<div id="wp-ai-agent-widget" class="wp-ai-agent-widget wpaia-pos-<?php echo esc_attr( $wpaia_pos ); ?>">
    <button class="wp-ai-agent-toggle" aria-label="<?php esc_attr_e( 'Open chat', 'wp-ai-agent' ); ?>">
        <span class="wp-ai-agent-avatar wp-ai-agent-avatar-lg"><?php echo $wpaia_robot; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
    </button>

    <div class="wp-ai-agent-panel is-hidden">

        <!-- SHARED HEADER (identical on Home + Chat): robot avatar + name + close -->
        <div class="wp-ai-agent-topbar">
            <span class="wp-ai-agent-avatar"><?php echo $wpaia_robot; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <span class="wp-ai-agent-brand"><?php echo esc_html( $wpaia_brand['assistant'] ); ?></span>
            <button type="button" class="wp-ai-agent-close" aria-label="<?php esc_attr_e( 'Close', 'wp-ai-agent' ); ?>">&times;</button>
        </div>

        <!-- HOME VIEW -->
        <div class="wp-ai-agent-view" data-view="home">
            <div class="wp-ai-agent-home-body">
                <div class="wp-ai-agent-greeting">
                    <h2 class="wp-ai-agent-greeting-title"><?php echo esc_html( $wpaia_brand['greeting'] ); ?> 👋</h2>
                    <p class="wp-ai-agent-greeting-sub" id="wp-ai-agent-home-intro"></p>
                </div>

                <div class="wp-ai-agent-home-cards" id="wp-ai-agent-home-cards"></div>

                <button type="button" class="wp-ai-agent-care-card" id="wp-ai-agent-care">
                    <span class="wp-ai-agent-care-icon"><svg class="wp-ai-agent-mi" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M21 12.22C21 6.73 16.74 3 12 3c-4.69 0-9 3.65-9 9.28-.6.34-1 .98-1 1.72v2c0 1.1.9 2 2 2h1v-6.1c0-3.87 3.13-7 7-7s7 3.13 7 7V19h-8v2h8c1.1 0 2-.9 2-2v-1.22c.59-.31 1-.92 1-1.64v-2.3c0-.7-.41-1.31-1-1.62zM9 14c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm6 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/></svg></span>
                    <span class="wp-ai-agent-care-text">
                        <strong><?php esc_html_e( 'Connect with customer care', 'wp-ai-agent' ); ?></strong>
                        <small><?php esc_html_e( 'Chat with our team on WhatsApp', 'wp-ai-agent' ); ?></small>
                    </span>
                    <svg class="wp-ai-agent-mi" viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true" focusable="false"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>

                <div class="wp-ai-agent-home-actions">
                    <button type="button" class="wp-ai-agent-mini-link" id="wp-ai-agent-newchat"><svg class="wp-ai-agent-mi" viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true" focusable="false"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg> <?php esc_html_e( 'New chat', 'wp-ai-agent' ); ?></button>
                    <button type="button" class="wp-ai-agent-mini-link" id="wp-ai-agent-clearchat"><svg class="wp-ai-agent-mi" viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true" focusable="false"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg> <?php esc_html_e( 'Clear history', 'wp-ai-agent' ); ?></button>
                </div>
            </div>
        </div>

        <!-- CHAT VIEW (same header above) -->
        <div class="wp-ai-agent-view is-hidden" data-view="chat">
            <div class="wp-ai-agent-messages" id="wp-ai-agent-messages"></div>
            <form id="wp-ai-agent-form" class="wp-ai-agent-form">
                <input type="file" id="wp-ai-agent-image" class="wp-ai-agent-file" accept="image/*" hidden style="display:none!important;" />
                <button type="button" id="wp-ai-agent-image-btn" class="wp-ai-agent-icon-btn" aria-label="<?php esc_attr_e( 'Upload product image', 'wp-ai-agent' ); ?>" title="<?php esc_attr_e( 'Search by image', 'wp-ai-agent' ); ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2zM8.5 13.5l2.5 3 3.5-4.5 4.5 6H5l3.5-4.5zM8 10a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
                </button>
                <textarea id="wp-ai-agent-message" class="wp-ai-agent-input" rows="1" placeholder="<?php echo esc_attr( $wpaia_brand['placeholder'] ); ?>" autocomplete="off"></textarea>
                <button type="submit" class="wp-ai-agent-submit" aria-label="<?php esc_attr_e( 'Send', 'wp-ai-agent' ); ?>">
                    <svg class="wp-ai-agent-send-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </form>
        </div>

        <!-- BOTTOM NAV -->
        <?php $wpaia_voice = ! function_exists( 'wp_ai_agent_option' ) || '1' === wp_ai_agent_option( 'voice_mode', '1' ); ?>
        <div class="wp-ai-agent-nav<?php echo $wpaia_voice ? ' has-voice' : ''; ?>">
            <button type="button" class="wp-ai-agent-nav-btn is-active" data-nav="home">
                <svg class="wp-ai-agent-mi" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span><?php esc_html_e( 'Home', 'wp-ai-agent' ); ?></span>
            </button>

            <?php if ( $wpaia_voice ) : ?>
                <div class="wp-ai-agent-nav-voice">
                    <button type="button" class="wp-ai-agent-voice-btn" id="wp-ai-agent-voice" aria-label="<?php esc_attr_e( 'Voice assistant — tap and speak', 'wp-ai-agent' ); ?>" title="<?php esc_attr_e( 'Voice assistant', 'wp-ai-agent' ); ?>">
                        <svg class="wp-ai-agent-mi wp-ai-agent-mic" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                    </button>
                    <span class="wp-ai-agent-voice-label"><?php esc_html_e( 'Voice', 'wp-ai-agent' ); ?></span>
                </div>
            <?php endif; ?>

            <button type="button" class="wp-ai-agent-nav-btn" data-nav="chat">
                <svg class="wp-ai-agent-mi" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                <span><?php esc_html_e( 'Chat', 'wp-ai-agent' ); ?></span>
            </button>
        </div>
    </div>
</div>
