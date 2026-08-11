<?php
/**
 * Conversation state for the AI Agent.
 *
 * Multi-step tools (order tracking, lead capture, booking, support tickets)
 * need to remember where a visitor is in a flow across messages — e.g. after
 * the agent asks "what's your order number?" the next message must be routed
 * back to the order tool. State is kept per visitor session in a transient.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Transient key for a session's pending flow state.
 *
 * @param string $session_id Visitor session id.
 * @return string
 */
function wp_ai_agent_state_key( $session_id ) {
    return 'wp_ai_agent_flow_' . md5( (string) $session_id );
}

/**
 * Read the pending flow state for a session.
 *
 * @param string $session_id Visitor session id.
 * @return array{flow:string,step:string,data:array}|null
 */
function wp_ai_agent_get_state( $session_id ) {
    if ( '' === (string) $session_id ) {
        return null;
    }
    $state = get_transient( wp_ai_agent_state_key( $session_id ) );
    return ( is_array( $state ) && ! empty( $state['flow'] ) ) ? $state : null;
}

/**
 * Start / update a pending flow for a session.
 *
 * @param string $session_id Visitor session id.
 * @param string $flow       Flow name (order|lead|booking|ticket).
 * @param string $step       Current step within the flow.
 * @param array  $data       Collected data so far.
 * @return void
 */
function wp_ai_agent_set_state( $session_id, $flow, $step, $data = array() ) {
    if ( '' === (string) $session_id ) {
        return;
    }
    $ttl = (int) apply_filters( 'wp_ai_agent_flow_ttl', 30 * MINUTE_IN_SECONDS );
    set_transient(
        wp_ai_agent_state_key( $session_id ),
        array(
            'flow' => (string) $flow,
            'step' => (string) $step,
            'data' => is_array( $data ) ? $data : array(),
        ),
        $ttl
    );
}

/**
 * Clear a session's pending flow (flow finished or cancelled).
 *
 * @param string $session_id Visitor session id.
 * @return void
 */
function wp_ai_agent_clear_state( $session_id ) {
    if ( '' === (string) $session_id ) {
        return;
    }
    delete_transient( wp_ai_agent_state_key( $session_id ) );
}

/* -------------------------------------------------------------------------
 * Lightweight conversation memory (customer preferences + products already
 * shown). Kept in its own transient — separate from the pending-flow state, so
 * it survives across completed flows and guides later recommendations. Works
 * identically for guests and logged-in visitors (keyed by session id).
 * ---------------------------------------------------------------------- */

/**
 * Transient key for a session's remembered preferences.
 *
 * @param string $session_id Visitor session id.
 * @return string
 */
function wp_ai_agent_prefs_key( $session_id ) {
    return 'wp_ai_agent_prefs_' . md5( (string) $session_id );
}

/**
 * Read a session's remembered preferences ( budget, avoid_color, shown_ids … ).
 *
 * @param string $session_id Visitor session id.
 * @return array
 */
function wp_ai_agent_get_prefs( $session_id ) {
    if ( '' === (string) $session_id ) {
        return array();
    }
    $prefs = get_transient( wp_ai_agent_prefs_key( $session_id ) );
    return is_array( $prefs ) ? $prefs : array();
}

/**
 * Merge changes into a session's remembered preferences.
 *
 * @param string $session_id Visitor session id.
 * @param array  $changes    Keys to set/merge.
 * @return void
 */
function wp_ai_agent_update_prefs( $session_id, $changes ) {
    if ( '' === (string) $session_id || ! is_array( $changes ) ) {
        return;
    }
    $prefs = array_merge( wp_ai_agent_get_prefs( $session_id ), $changes );
    // 24h lifetime — the guest conversation memory (filters, context, shown
    // products) survives refreshes and navigation for a full day.
    $ttl   = (int) apply_filters( 'wp_ai_agent_prefs_ttl', DAY_IN_SECONDS );
    set_transient( wp_ai_agent_prefs_key( $session_id ), $prefs, $ttl );
}

/**
 * Remember which product IDs were just shown, so later "show me something else"
 * / objection replies can avoid repeating the same recommendations.
 *
 * @param string $session_id Visitor session id.
 * @param int[]  $ids        Product IDs shown in this turn.
 * @return void
 */
function wp_ai_agent_record_shown_products( $session_id, $ids ) {
    if ( '' === (string) $session_id || empty( $ids ) ) {
        return;
    }
    $prefs   = wp_ai_agent_get_prefs( $session_id );
    $current = ( isset( $prefs['shown_ids'] ) && is_array( $prefs['shown_ids'] ) ) ? $prefs['shown_ids'] : array();
    $merged  = array_values( array_unique( array_merge( $current, array_map( 'intval', $ids ) ) ) );

    // Keep only the most recent N so the list never grows unbounded.
    $cap = (int) apply_filters( 'wp_ai_agent_shown_products_cap', 40 );
    if ( count( $merged ) > $cap ) {
        $merged = array_slice( $merged, -$cap );
    }

    wp_ai_agent_update_prefs( $session_id, array( 'shown_ids' => $merged ) );
}

/* -------------------------------------------------------------------------
 * Shopping conversation memory — the accumulated product filters (category /
 * type, colour, gender, size, material, price, sort) + the last results, kept
 * per session so follow-ups ("red", "only men's", "under $100", "cotton",
 * "compare them") refine the current search instead of restarting it. Stored
 * inside the preferences transient, so it shares their lifetime and is cleared
 * by New Chat (a fresh session id) automatically.
 * ---------------------------------------------------------------------- */

/**
 * Read the current shopping context for a session.
 *
 * @param string $session_id Visitor session id.
 * @return array
 */
function wp_ai_agent_get_shop_context( $session_id ) {
    $prefs = wp_ai_agent_get_prefs( $session_id );
    return ( isset( $prefs['shop'] ) && is_array( $prefs['shop'] ) ) ? $prefs['shop'] : array();
}

/**
 * Store the shopping context for a session.
 *
 * @param string $session_id Visitor session id.
 * @param array  $ctx        Context (filters + results).
 * @return void
 */
function wp_ai_agent_set_shop_context( $session_id, $ctx ) {
    wp_ai_agent_update_prefs( $session_id, array( 'shop' => is_array( $ctx ) ? $ctx : array() ) );
}

/**
 * Clear the shopping context (e.g. when the visitor changes the topic).
 *
 * @param string $session_id Visitor session id.
 * @return void
 */
function wp_ai_agent_clear_shop_context( $session_id ) {
    wp_ai_agent_update_prefs( $session_id, array( 'shop' => array() ) );
}

/**
 * A short "you were previously discussing…" summary from the saved shopping
 * context, shown when a guest returns within their 24h window. Returns '' when
 * there is nothing to resume. Built as tappable bullet lines.
 *
 * @param string $session_id Visitor session id.
 * @return string
 */

function wp_ai_agent_shop_context_summary( $session_id ) {
    $ctx = wp_ai_agent_get_shop_context( $session_id );
    if ( empty( $ctx ) ) {
        return '';
    }

    $bits   = array();
    $type   = isset( $ctx['type'] ) ? trim( (string) $ctx['type'] ) : '';
    $gender = isset( $ctx['gender'] ) ? (string) $ctx['gender'] : '';

    // Product line, e.g. "Men's T-Shirts".
    $line = '';
    if ( '' !== $gender ) {
        $line .= ucfirst( $gender ) . "'s ";
    }
    if ( '' !== $type ) {
        $line .= ucwords( $type );
    }
    $line = trim( $line );
    if ( '' !== $line ) {
        $bits[] = $line;
    }

    if ( ! empty( $ctx['colors'] ) ) {
        $bits[] = __( 'Colour', 'wp-ai-agent' ) . ': ' . ucwords( implode( ', ', (array) $ctx['colors'] ) );
    }
    if ( ! empty( $ctx['sizes'] ) ) {
        $bits[] = __( 'Size', 'wp-ai-agent' ) . ': ' . strtoupper( (string) $ctx['sizes'][0] );
    }
    if ( ! empty( $ctx['material'] ) ) {
        $bits[] = __( 'Material', 'wp-ai-agent' ) . ': ' . ucfirst( (string) $ctx['material'] );
    }

    $fmt = function ( $v ) {
        return function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( $v ) ), ENT_QUOTES ) : (string) $v;
    };
    $min = isset( $ctx['min'] ) ? $ctx['min'] : null;
    $max = isset( $ctx['max'] ) ? $ctx['max'] : null;
    if ( null !== $min && null !== $max ) {
        $bits[] = __( 'Budget', 'wp-ai-agent' ) . ': ' . $fmt( $min ) . '–' . $fmt( $max );
    } elseif ( null !== $max ) {
        $bits[] = __( 'Budget', 'wp-ai-agent' ) . ': ' . sprintf( __( 'Under %s', 'wp-ai-agent' ), $fmt( $max ) );
    } elseif ( null !== $min ) {
        $bits[] = __( 'Budget', 'wp-ai-agent' ) . ': ' . sprintf( __( 'Over %s', 'wp-ai-agent' ), $fmt( $min ) );
    }   

    if ( empty( $bits ) ) {
        return '';
    }
    return implode( "\n", array_map( function ( $b ) {
        return '• ' . $b;
    }, $bits ) );
}

/**
 * Whether a message is a request to cancel / stop the current flow.
 *
 * @param string $message User message.
 * @return bool
 */
function wp_ai_agent_is_cancel( $message ) {
    $m = strtolower( trim( (string) $message ) );
    return (bool) preg_match( '/^(cancel|stop|exit|quit|nevermind|never mind|rehne do|rahne do|chhodo|chod do|nahi chahiye|cancel karo)\b/u', $m );
}
