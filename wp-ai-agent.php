<?php
/*
Plugin Name: WP AI Agent
Plugin URI: https://example.com/
Description: Universal WordPress AI Agent plugin with chat widget, content indexing, AI engine, and WooCommerce support.
Version: 1.2.2
Author: Kuldeep Pankaj, Arvind Meghwanshi
Text Domain: wp-ai-agent
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_AI_AGENT_VERSION' ) ) {
    define( 'WP_AI_AGENT_VERSION', '1.2.2' );
}

if ( ! defined( 'WP_AI_AGENT_PLUGIN_FILE' ) ) {
    define( 'WP_AI_AGENT_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'WP_AI_AGENT_PLUGIN_DIR' ) ) {
    define( 'WP_AI_AGENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WP_AI_AGENT_PLUGIN_URL' ) ) {
    define( 'WP_AI_AGENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/content-indexer.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/universal-indexer.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/embeddings.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/qa-manager.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/woocommerce-search.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/image-search.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/website-profile.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/appearance.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/ai-engine.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/chat-handler.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/conversations.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/lead-manager.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/booking-manager.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/whatsapp.php';
// AI Agent layer: intent detection, conversation state, tools, and router.
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/agent/conversation-state.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/agent/intent-detection.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/agent/agent-tools.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/agent/user-auth.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'includes/agent/tool-router.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'api/rest-routes.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'admin/settings.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'admin/analytics.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'admin/conversations.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'admin/conversation-dashboard.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'admin/agent-admin.php';
require_once WP_AI_AGENT_PLUGIN_DIR . 'admin/training.php';

register_activation_hook( WP_AI_AGENT_PLUGIN_FILE, 'wp_ai_agent_activate' );

function wp_ai_agent_activate() {
    wp_ai_agent_create_tables();
    wp_ai_agent_create_index_table();
    wp_ai_agent_create_conversations_table();
    update_option( 'wp_ai_agent_conv_db_version', WP_AI_AGENT_CONV_DB_VERSION );
    wp_ai_agent_create_embeddings_cache_table();
    if ( function_exists( 'wp_ai_agent_create_agent_tables' ) ) {
        wp_ai_agent_create_agent_tables();
    }
    wp_ai_agent_create_qa_table();
    wp_ai_agent_index_content( true );
    wp_ai_agent_rebuild_index();

    // Detect the website type and build the Website Profile immediately, so the
    // assistant adapts its personality from the very first message.
    if ( function_exists( 'wp_ai_agent_get_website_profile' ) ) {
        wp_ai_agent_get_website_profile( true );
    }
}

function wp_ai_agent_create_tables() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ai_agent_leads';
    $charset_collate = $wpdb->get_charset_collate();

    // Lead Qualification schema: source, page, session, status and score are
    // added on top of the original contact fields (dbDelta adds the new columns
    // in place on existing installs).
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(191) NOT NULL DEFAULT '',
        email varchar(191) NOT NULL DEFAULT '',
        phone varchar(50) NOT NULL DEFAULT '',
        message text NOT NULL,
        lead_source varchar(50) NOT NULL DEFAULT 'chat',
        page_url varchar(255) NOT NULL DEFAULT '',
        session_id varchar(64) NOT NULL DEFAULT '',
        lead_status varchar(20) NOT NULL DEFAULT 'new',
        score tinyint(3) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY lead_status (lead_status),
        KEY created_at (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

add_action( 'admin_menu', 'wp_ai_agent_add_admin_pages' );
add_action( 'admin_init', 'wp_ai_agent_register_settings' );
add_action( 'wp_enqueue_scripts', 'wp_ai_agent_enqueue_frontend_assets' );
add_action( 'wp_footer', 'wp_ai_agent_render_chat_widget' );

function wp_ai_agent_add_admin_pages() {
    add_menu_page(
        __( 'AI Agent', 'wp-ai-agent' ),
        __( 'AI Agent', 'wp-ai-agent' ),
        'manage_options',
        'wp-ai-agent',
        'wp_ai_agent_admin_settings_page',
        'dashicons-hammer',
        58
    );

    add_submenu_page(
        'wp-ai-agent',
        __( 'Training', 'wp-ai-agent' ),
        __( 'Training', 'wp-ai-agent' ),
        'manage_options',
        'wp-ai-agent-training',
        'wp_ai_agent_admin_training_page'
    );

    add_submenu_page(
        'wp-ai-agent',
        __( 'Q&A', 'wp-ai-agent' ),
        __( 'Q&A', 'wp-ai-agent' ),
        'manage_options',
        'wp-ai-agent-qa',
        'wp_ai_agent_admin_qa_page'
    );

    add_submenu_page(
        'wp-ai-agent',
        __( 'Conversations', 'wp-ai-agent' ),
        __( 'Conversations', 'wp-ai-agent' ),
        'manage_options',
        'wp-ai-agent-conversations',
        'wp_ai_agent_conversation_dashboard_page'
    );

    add_submenu_page(
        'wp-ai-agent',
        __( 'AI Leads', 'wp-ai-agent' ),
        __( 'AI Leads', 'wp-ai-agent' ),
        'manage_options',
        'wp-ai-agent-leads',
        'wp_ai_agent_admin_leads_page'
    );

    add_submenu_page(
        'wp-ai-agent',
        __( 'Bookings', 'wp-ai-agent' ),
        __( 'Bookings', 'wp-ai-agent' ),
        'manage_options',
        'wp-ai-agent-bookings',
        'wp_ai_agent_admin_bookings_page'
    );

    add_submenu_page(
        'wp-ai-agent',
        __( 'Analytics', 'wp-ai-agent' ),
        __( 'Analytics', 'wp-ai-agent' ),
        'manage_options',
        'wp-ai-agent-analytics',
        'wp_ai_agent_admin_analytics_page'
    );

    add_submenu_page(
        'wp-ai-agent',
        __( 'Appearance', 'wp-ai-agent' ),
        __( 'Appearance', 'wp-ai-agent' ),
        'manage_options',
        'wp-ai-agent-appearance',
        'wp_ai_agent_appearance_page'
    );
}

/**
 * Ensure the agent tables (bookings, tickets) exist on existing installs.
 */
add_action( 'admin_init', 'wp_ai_agent_maybe_create_agent_tables' );
function wp_ai_agent_maybe_create_agent_tables() {
    // Bump this version whenever an agent table is added/changed so existing
    // installs run dbDelta again (v2 = order-tracking log, v3 = lead
    // qualification columns, v4 = WhatsApp handoff log).
    if ( '4' === get_option( 'wp_ai_agent_agent_tables_ready' ) ) {
        return;
    }
    if ( function_exists( 'wp_ai_agent_create_agent_tables' ) ) {
        wp_ai_agent_create_agent_tables();
    }
    wp_ai_agent_create_tables(); // upgrade the leads table schema.
    update_option( 'wp_ai_agent_agent_tables_ready', '4' );
}

function wp_ai_agent_register_settings() {
    register_setting( 'wp_ai_agent_settings', 'wp_ai_agent_options', 'wp_ai_agent_sanitize_options' );
}

function wp_ai_agent_sanitize_options( $input ) {
    return array(
        'provider'        => isset( $input['provider'] ) ? sanitize_text_field( $input['provider'] ) : 'openai',
        'api_key'         => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
        'api_key_openai'  => isset( $input['api_key_openai'] ) ? sanitize_text_field( $input['api_key_openai'] ) : '',
        'api_key_gemini'  => isset( $input['api_key_gemini'] ) ? sanitize_text_field( $input['api_key_gemini'] ) : '',
        'api_key_groq'    => isset( $input['api_key_groq'] ) ? sanitize_text_field( $input['api_key_groq'] ) : '',
        'model'           => isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'gpt-3.5-turbo',
        'enable_chat'     => isset( $input['enable_chat'] ) ? '1' : '0',
        'enable_semantic' => isset( $input['enable_semantic'] ) ? '1' : '0',
        'guided_mode'     => isset( $input['guided_mode'] ) ? '1' : '0',
        'voice_mode'      => isset( $input['voice_mode'] ) ? '1' : '0',
        'voice_reply'     => isset( $input['voice_reply'] ) ? '1' : '0',
        'voice_manual_send' => isset( $input['voice_manual_send'] ) ? '1' : '0',
        'speech_rate'     => isset( $input['speech_rate'] ) ? (string) min( 2, max( 0.5, (float) $input['speech_rate'] ) ) : '1',
        'speech_pitch'    => isset( $input['speech_pitch'] ) ? (string) min( 2, max( 0, (float) $input['speech_pitch'] ) ) : '1',
        'speech_volume'   => isset( $input['speech_volume'] ) ? (string) min( 1, max( 0, (float) $input['speech_volume'] ) ) : '1',
        'widget_position' => ( isset( $input['widget_position'] ) && in_array( $input['widget_position'], array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true ) ) ? $input['widget_position'] : 'bottom-right',
        'show_homepage'   => isset( $input['show_homepage'] ) ? '1' : '0',
        'show_pages'      => isset( $input['show_pages'] ) ? '1' : '0',
        'show_posts'      => isset( $input['show_posts'] ) ? '1' : '0',
        'show_products'   => isset( $input['show_products'] ) ? '1' : '0',
        'show_archives'   => isset( $input['show_archives'] ) ? '1' : '0',
        'exclude_ids'     => isset( $input['exclude_ids'] ) ? sanitize_text_field( $input['exclude_ids'] ) : '',
        'allow_general_ai' => isset( $input['allow_general_ai'] ) ? '1' : '0',
        'lead_mode'       => ( isset( $input['lead_mode'] ) && in_array( $input['lead_mode'], array( 'form', 'ai', 'both' ), true ) ) ? $input['lead_mode'] : 'form',
        'whatsapp_number' => isset( $input['whatsapp_number'] ) ? sanitize_text_field( $input['whatsapp_number'] ) : '',
        'whatsapp_default_message' => isset( $input['whatsapp_default_message'] ) ? sanitize_text_field( $input['whatsapp_default_message'] ) : '',
        'business_name'   => isset( $input['business_name'] ) ? sanitize_text_field( $input['business_name'] ) : '',
        'notify_email'    => isset( $input['notify_email'] ) ? sanitize_email( $input['notify_email'] ) : '',
    );
}

function wp_ai_agent_get_options() {
    $defaults = array(
        'provider'       => 'openai',
        'api_key'        => '',
        'api_key_openai' => '',
        'api_key_gemini' => '',
        'api_key_groq'   => '',
        'model'          => 'gpt-3.5-turbo',
        'enable_chat'    => '1',
        'enable_semantic' => '1',
        'guided_mode'    => '1',
        'voice_mode'     => '1',
        'voice_reply'    => '0',
        'voice_manual_send' => '0',
        'speech_rate'    => '1',
        'speech_pitch'   => '1',
        'speech_volume'  => '1',
        'widget_position' => 'bottom-right',
        'show_homepage'  => '1',
        'show_pages'     => '1',
        'show_posts'     => '1',
        'show_products'  => '1',
        'show_archives'  => '1',
        'exclude_ids'    => '',
        'allow_general_ai' => '0',
        'lead_mode'       => 'form',
        'whatsapp_number' => '',
        'whatsapp_default_message' => 'Hello, I need support regarding:',
        'business_name'   => '',
        'notify_email'    => '',
    );

    return wp_parse_args( get_option( 'wp_ai_agent_options', array() ), $defaults );
}

/**
 * Resolve the API key for the active provider.
 *
 * Each provider keeps its own saved key so the user does not have to re-enter it
 * when switching providers. Falls back to the legacy shared 'api_key' when the
 * provider-specific key has not been set.
 *
 * @param array $options Plugin options (defaults to stored options when omitted).
 * @return string
 */
function wp_ai_agent_get_active_api_key( $options = null ) {
    if ( null === $options ) {
        $options = wp_ai_agent_get_options();
    }

    $provider = isset( $options['provider'] ) ? $options['provider'] : 'openai';
    $key_field = 'api_key_' . $provider;

    if ( ! empty( $options[ $key_field ] ) ) {
        return $options[ $key_field ];
    }

    return isset( $options['api_key'] ) ? $options['api_key'] : '';
}

/**
 * Whether the chat widget should render on the CURRENT request. Controlled only
 * by admin settings — enabled by default on every page type, with per-type
 * toggles and a page/post exclude list. A page type is never hidden implicitly.
 *
 * @return bool
 */
function wp_ai_agent_should_display_widget() {
    $o = wp_ai_agent_get_options();
    if ( '0' === $o['enable_chat'] ) {
        return false;
    }

    // Excluded specific pages/posts (comma or space separated IDs).
    $exclude = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $o['exclude_ids'] ) ) );
    $current = ( function_exists( 'get_queried_object_id' ) ) ? (int) get_queried_object_id() : 0;
    if ( $current && in_array( $current, $exclude, true ) ) {
        return false;
    }

    // Per page-type visibility (all ON by default).
    $show = true;
    if ( is_front_page() || is_home() ) {
        $show = ( '1' === $o['show_homepage'] );
    } elseif ( function_exists( 'is_product' ) && is_product() ) {
        $show = ( '1' === $o['show_products'] );
    } elseif ( is_singular( 'post' ) ) {
        $show = ( '1' === $o['show_posts'] );
    } elseif ( is_page() ) {
        $show = ( '1' === $o['show_pages'] );
    } elseif ( is_archive() || is_search() || ( function_exists( 'is_shop' ) && is_shop() ) ) {
        $show = ( '1' === $o['show_archives'] );
    }

    /**
     * Final say on whether to show the widget on this request.
     *
     * @param bool $show Whether to display.
     */
    return (bool) apply_filters( 'wp_ai_agent_should_display', $show );
}

function wp_ai_agent_enqueue_frontend_assets() {
    $options = wp_ai_agent_get_options();
    if ( ! wp_ai_agent_should_display_widget() ) {
        return;
    }

    wp_enqueue_style( 'wp-ai-agent-chat', WP_AI_AGENT_PLUGIN_URL . 'assets/css/chat.css', array(), WP_AI_AGENT_VERSION );
    wp_enqueue_script( 'wp-ai-agent-chat', WP_AI_AGENT_PLUGIN_URL . 'assets/js/chat.js', array(), WP_AI_AGENT_VERSION, true );
    
    // Fix: Use 'wp_rest' nonce properly
    wp_localize_script( 'wp-ai-agent-chat', 'wpAiAgentData', array(
        'restUrl'      => esc_url_raw( rest_url( 'wp-ai-agent/v1/chat' ) ),
        'searchUrl'    => esc_url_raw( rest_url( 'wp-ai-agent/v1/search-content' ) ),
        'logUrl'       => esc_url_raw( rest_url( 'wp-ai-agent/v1/log-conversation' ) ),
        'historyUrl'   => esc_url_raw( rest_url( 'wp-ai-agent/v1/history' ) ),
        'clearUrl'     => esc_url_raw( rest_url( 'wp-ai-agent/v1/clear-history' ) ),
        'handoffUrl'   => esc_url_raw( rest_url( 'wp-ai-agent/v1/handoff-click' ) ),
        'imageUrl'     => esc_url_raw( rest_url( 'wp-ai-agent/v1/image-search' ) ),
        'imageSearch'  => ( '' !== wp_ai_agent_vision_provider() && ( ! function_exists( 'wp_ai_agent_commerce_enabled' ) || wp_ai_agent_commerce_enabled() ) ) ? 1 : 0,
        'voice'        => ( '1' === $options['voice_mode'] ) ? 1 : 0,
        'voiceReply'   => ( '1' === $options['voice_reply'] ) ? 1 : 0,
        'manualSend'   => ( '0' === $options['voice_manual_send'] ) ? 0 : 1,
        'speechRate'   => $options['speech_rate'],
        'speechPitch'  => $options['speech_pitch'],
        'speechVolume' => $options['speech_volume'],
        'lang'         => str_replace( '_', '-', get_bloginfo( 'language' ) ),
        'nonce'        => wp_create_nonce( 'wp_rest' ), // This should work
        'welcome'      => wp_ai_agent_get_welcome_message(),
        'homeIntro'    => wp_ai_agent_get_home_intro(),
        'homeCards'    => wp_ai_agent_get_home_cards(),
        'whatsappUrl'  => function_exists( 'wp_ai_agent_whatsapp_url' ) ? wp_ai_agent_whatsapp_url( '' ) : '',
        'cartAddUrl'   => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
        'cartUrl'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
        'quickActions' => wp_ai_agent_get_quick_actions(),
    ) );
}

/**
 * A short, curated set of cards for the Home tab (kept intentionally minimal —
 * Sign in, Policies, and Products). The WhatsApp "Connect with customer care"
 * card is separate. Filterable via wp_ai_agent_home_cards.
 *
 * @return array[] List of array( 'label' => string, 'query' => string ).
 */
function wp_ai_agent_get_home_cards() {
    $cards    = array();
    $commerce = function_exists( 'wp_ai_agent_commerce_enabled' ) ? wp_ai_agent_commerce_enabled() : function_exists( 'wc_get_products' );
    // Only surface account/sign-in where a real account system exists (store,
    // membership, registration or a login page) — never on a plain business site.
    $accounts = function_exists( 'wp_ai_agent_has_accounts' ) ? wp_ai_agent_has_accounts() : $commerce;

    if ( $accounts ) {
        if ( is_user_logged_in() ) {
            $cards[] = $commerce
                ? array( 'label' => __( '📦 My orders', 'wp-ai-agent' ), 'query' => 'my orders' )
                : array( 'label' => __( '👤 My account', 'wp-ai-agent' ), 'query' => 'my account' );
        } else {
            $cards[] = array( 'label' => __( '🔐 Sign in', 'wp-ai-agent' ), 'query' => 'login' );
        }
    }

    // Website-type starter cards (Menu / Doctors / Courses / Services / … ) so the
    // Home tab reflects THIS kind of website — not a generic store.
    if ( function_exists( 'wp_ai_agent_profile_quick_actions' ) ) {
        foreach ( array_slice( wp_ai_agent_profile_quick_actions(), 0, 2 ) as $pa ) {
            $cards[] = $pa;
        }
    }

    if ( $commerce ) {
        $cards[] = array( 'label' => __( '🛍️ Products', 'wp-ai-agent' ), 'query' => 'products' );
        $cards[] = array( 'label' => __( '📄 Policies', 'wp-ai-agent' ), 'query' => 'return policy' );
    } else {
        // No store → offer a direct way to reach the team instead of "Policies".
        $cards[] = array( 'label' => __( '📞 Contact', 'wp-ai-agent' ), 'query' => 'how can I contact you' );
    }

    return apply_filters( 'wp_ai_agent_home_cards', $cards );
}

/**
 * Short tagline shown under "Hi there 👋" on the Home tab.
 *
 * @return string
 */
function wp_ai_agent_get_home_intro() {
    $intro = __( 'Welcome! How can I assist you today?', 'wp-ai-agent' );
    return apply_filters( 'wp_ai_agent_home_intro', $intro );
}

/**
 * The greeting shown automatically when the chat panel is opened.
 *
 * @return string
 */
function wp_ai_agent_get_welcome_message() {
    $site = get_bloginfo( 'name' );
    $site = $site ? $site : __( 'our website', 'wp-ai-agent' );

    if ( is_user_logged_in() ) {
        $u    = wp_get_current_user();
        $name = $u->display_name ? $u->display_name : $u->user_login;
        /* translators: 1: user name, 2: site name. */
        $default = sprintf(
            __( "👋 Welcome back, %1\$s! How can I help you today?\n\nI can check your orders & bookings, recommend products, and answer questions about %2\$s.", 'wp-ai-agent' ),
            $name,
            $site
        );
    } else {
        /* translators: %s: site name. */
        $default = sprintf(
            __( "👋 Welcome to %s.\n\nTip: type \"login\" or \"register\" for personalized help — order tracking, bookings and more.", 'wp-ai-agent' ),
            $site
        );
    }

    return apply_filters( 'wp_ai_agent_welcome_message', $default );
}

/**
 * Quick-action buttons shown beneath the welcome message.
 *
 * Built dynamically from THIS website's own content (key pages, WooCommerce,
 * top categories, posts) so the suggestions fit whatever site the plugin is
 * installed on. Cached for an hour for performance.
 *
 * @return array[] List of array( 'label' => string, 'query' => string ).
 */
/**
 * Prepend per-user auth quick actions (Login/Register for guests, My orders/
 * Logout for members). Kept out of the shared cache since it differs per user.
 *
 * @param array[] $actions Cached content actions.
 * @return array[]
 */
function wp_ai_agent_merge_auth_quick_actions( $actions ) {
    $actions = is_array( $actions ) ? $actions : array();

    // No account system on this site → never offer Login / Register / My account.
    if ( function_exists( 'wp_ai_agent_has_accounts' ) && ! wp_ai_agent_has_accounts() ) {
        return $actions;
    }

    $commerce = function_exists( 'wp_ai_agent_commerce_enabled' ) ? wp_ai_agent_commerce_enabled() : function_exists( 'wc_get_products' );

    $auth = array();
    if ( is_user_logged_in() ) {
        $auth[] = $commerce
            ? array( 'label' => __( '📦 My orders', 'wp-ai-agent' ), 'query' => 'my orders' )
            : array( 'label' => __( '👤 My account', 'wp-ai-agent' ), 'query' => 'my account' );
        $auth[] = array( 'label' => __( '🚪 Logout', 'wp-ai-agent' ), 'query' => 'logout' );
    } else {
        $auth[] = array( 'label' => __( '🔐 Login', 'wp-ai-agent' ), 'query' => 'login' );
        // Only offer Register when registration is actually open.
        if ( get_option( 'users_can_register' ) || ( function_exists( 'wp_ai_agent_commerce_enabled' ) && wp_ai_agent_commerce_enabled() ) ) {
            $auth[] = array( 'label' => __( '📝 Register', 'wp-ai-agent' ), 'query' => 'register' );
        }
    }
    return array_merge( $auth, $actions );
}

function wp_ai_agent_get_quick_actions() {
    $cached = get_transient( 'wp_ai_agent_quick_actions' );
    if ( false !== $cached && is_array( $cached ) ) {
        return apply_filters( 'wp_ai_agent_quick_actions', wp_ai_agent_merge_auth_quick_actions( $cached ) );
    }

    $actions = array();

    // Helper: add a unique action (dedupe by label, case-insensitive).
    $add = function ( $label, $query ) use ( &$actions ) {
        $label = trim( wp_strip_all_tags( (string) $label ) );
        if ( '' === $label ) {
            return;
        }
        foreach ( $actions as $a ) {
            if ( 0 === strcasecmp( $a['label'], $label ) ) {
                return;
            }
        }
        $actions[] = array( 'label' => $label, 'query' => $query );
    };

    $max = (int) apply_filters( 'wp_ai_agent_quick_actions_max', 7 );

    // 1) Always offer a general overview of the site.
    $add( __( 'About this website', 'wp-ai-agent' ), __( 'Tell me about this website and what it offers', 'wp-ai-agent' ) );

    // 1b) Always offer a one-tap way to leave contact details (starts the lead
    // capture flow without the visitor needing to know any keywords).
    $add( __( '📞 Contact us', 'wp-ai-agent' ), __( 'I want to get in touch with your team', 'wp-ai-agent' ) );

    // 1c) Website-type starter actions (Menu / Doctors / Services / Courses / … ),
    // so the suggestions match THIS kind of website.
    if ( function_exists( 'wp_ai_agent_profile_quick_actions' ) ) {
        foreach ( wp_ai_agent_profile_quick_actions() as $pa ) {
            $add( $pa['label'], $pa['query'] );
        }
    }

    // 2) WooCommerce store — only when commerce features are enabled.
    if ( function_exists( 'wp_ai_agent_commerce_enabled' ) ? wp_ai_agent_commerce_enabled() : function_exists( 'wc_get_products' ) ) {
        $add( __( 'Products', 'wp-ai-agent' ), __( 'What products do you sell?', 'wp-ai-agent' ) );
    }

    // 3) Important pages detected by their title (services, contact, faq, etc.).
    $intent_keywords = array( 'service', 'about', 'contact', 'faq', 'pricing', 'price', 'plan', 'shipping', 'refund', 'return', 'appointment', 'booking', 'portfolio', 'course', 'menu', 'gallery', 'team' );
    $pages = get_posts( array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ) );
    foreach ( $pages as $page ) {
        if ( count( $actions ) >= $max ) {
            break;
        }
        $title = get_the_title( $page );
        $lt    = strtolower( $title );
        foreach ( $intent_keywords as $kw ) {
            if ( false !== strpos( $lt, $kw ) ) {
                /* translators: %s: page title. */
                $add( $title, sprintf( __( 'Tell me about %s', 'wp-ai-agent' ), $title ) );
                break;
            }
        }
    }


    
    // 4) Top categories (so news/blog sites get topic buttons).
    if ( count( $actions ) < $max ) {
        $cats = get_categories( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 3, 'hide_empty' => true ) );
        foreach ( $cats as $cat ) {
            if ( count( $actions ) >= $max ) {
                break;
            }
            if ( 'uncategorized' === strtolower( $cat->name ) ) {
                continue;
            }
            /* translators: %s: category name. */
            $add( $cat->name, sprintf( __( 'Show me articles about %s', 'wp-ai-agent' ), $cat->name ) );
        }
    }

    // 5) Fall back to "latest articles" if the site has posts.
    if ( count( $actions ) < $max ) {
        $counts = wp_count_posts();
        if ( $counts && (int) $counts->publish > 0 ) {
            $add( __( 'Latest articles', 'wp-ai-agent' ), __( 'Show me your latest articles', 'wp-ai-agent' ) );
        }
    }

    $actions = array_slice( $actions, 0, $max );

    set_transient( 'wp_ai_agent_quick_actions', $actions, HOUR_IN_SECONDS );

    return apply_filters( 'wp_ai_agent_quick_actions', wp_ai_agent_merge_auth_quick_actions( $actions ) );
}

function wp_ai_agent_render_chat_widget() {
    if ( ! wp_ai_agent_should_display_widget() ) {
        return;
    }

    $widget_file = WP_AI_AGENT_PLUGIN_DIR . 'templates/chatbot-widget.php';
    if ( file_exists( $widget_file ) ) {
        include $widget_file;
    }
}

function wp_ai_agent_is_woocommerce_active() {
    return class_exists( 'WooCommerce' );
}

function wp_ai_agent_is_provider_configured() {
    // Configured if ANY provider has a key (the agent auto-uses whichever exists).
    if ( function_exists( 'wp_ai_agent_effective_provider' ) && function_exists( 'wp_ai_agent_provider_key' ) ) {
        return '' !== wp_ai_agent_provider_key( wp_ai_agent_effective_provider() );
    }
    return ! empty( wp_ai_agent_get_active_api_key() );
}

// In rest-routes.php
add_action( 'rest_api_init', function() {
    register_rest_route( 'wp-ai-agent/v1', '/chat', array(
        'methods'             => 'POST',
        'callback'            => 'wp_ai_agent_handle_chat_request',
        'permission_callback' => '__return_true', // This should already allow it
    ) );
} );

// Hello World test route for debugging