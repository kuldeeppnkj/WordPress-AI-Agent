<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function() {
    register_rest_route( 'wp-ai-agent/v1', '/chat', array(
        'methods'             => 'POST',
        'callback'            => 'wp_ai_agent_handle_chat_request',
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'wp-ai-agent/v1', '/search-content', array(
        'methods'             => array( 'GET', 'POST' ),
        'callback'            => 'wp_ai_agent_handle_search_content_request',
        'permission_callback' => '__return_true',
        'args'                => array(
            'query' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ) );

    register_rest_route( 'wp-ai-agent/v1', '/log-conversation', array(
        'methods'             => 'POST',
        'callback'            => 'wp_ai_agent_handle_log_conversation_request',
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'wp-ai-agent/v1', '/image-search', array(
        'methods'             => 'POST',
        'callback'            => 'wp_ai_agent_handle_image_search_request',
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'wp-ai-agent/v1', '/history', array(
        'methods'             => array( 'GET', 'POST' ),
        'callback'            => 'wp_ai_agent_handle_history_request',
        'permission_callback' => '__return_true',
        'args'                => array(
            'session_id' => array(
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'page_url'   => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'esc_url_raw',
            ),
        ),
    ) );

    register_rest_route( 'wp-ai-agent/v1', '/handoff-click', array(
        'methods'             => 'POST',
        'callback'            => 'wp_ai_agent_handle_handoff_click_request',
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'wp-ai-agent/v1', '/clear-history', array(
        'methods'             => 'POST',
        'callback'            => 'wp_ai_agent_handle_clear_history_request',
        'permission_callback' => '__return_true',
    ) );

    // AI Search Debugger — admin only. Shows how a query is understood, which
    // sources are searched, the ranked matches (scores), and the context sent
    // to the AI. Restricted to administrators (it exposes internal retrieval).
    register_rest_route( 'wp-ai-agent/v1', '/search-debug', array(
        'methods'             => array( 'GET', 'POST' ),
        'callback'            => 'wp_ai_agent_handle_search_debug_request',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        },
        'args'                => array(
            'query' => array(
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ) );
} );

/**
 * Return the AI Search Debugger trace for a query (administrators only).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function wp_ai_agent_handle_search_debug_request( $request ) {
    $query = (string) $request->get_param( 'query' );
    $trace = function_exists( 'wp_ai_agent_search_debug' )
        ? wp_ai_agent_search_debug( $query )
        : array( 'error' => 'Search debugger unavailable.' );
    return new WP_REST_Response( $trace, 200 );
}

/**
 * Record a click on the "Continue on WhatsApp" handoff button.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function wp_ai_agent_handle_handoff_click_request( $request ) {
    if ( ! function_exists( 'wp_ai_agent_log_handoff' ) ) {
        return new WP_REST_Response( array( 'ok' => false ), 200 );
    }
    $params  = $request->get_json_params();
    $session = isset( $params['session_id'] ) ? sanitize_text_field( $params['session_id'] ) : '';
    $page    = isset( $params['page_url'] ) ? esc_url_raw( $params['page_url'] ) : '';
    $query   = isset( $params['query'] ) ? sanitize_text_field( $params['query'] ) : '';

    wp_ai_agent_log_handoff( 'click', $session, $page, $query );
    return new WP_REST_Response( array( 'ok' => true ), 200 );
}
