<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wp_ai_agent_handle_chat_request( $request ) {
    $t0      = microtime( true );
    $params  = $request->get_json_params();
    $message = isset( $params['message'] ) ? sanitize_text_field( $params['message'] ) : '';

    // Cap the message length so a pathological paste (e.g. thousands of digits)
    // can never be processed or echoed back. A real chat message is short.
    $max_len = (int) apply_filters( 'wp_ai_agent_max_message_length', 500 );
    if ( function_exists( 'mb_strlen' ) ? mb_strlen( $message ) > $max_len : strlen( $message ) > $max_len ) {
        $message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, $max_len ) : substr( $message, 0, $max_len );
    }

    if ( '' === trim( $message ) ) {
        // Tags-only / empty-after-sanitize input (e.g. "<?php") — answer gently
        // instead of returning a hard error the widget shows repeatedly.
        return new WP_REST_Response( array(
            'message' => __( "I didn't quite catch that. Could you rephrase your question? 😊", 'wp-ai-agent' ),
            'source'  => 'agent',
            'matched' => false,
        ), 200 );
    }

    $session_id = isset( $params['session_id'] ) ? sanitize_text_field( $params['session_id'] ) : '';
    $page_url   = isset( $params['page_url'] ) ? esc_url_raw( $params['page_url'] ) : '';

    // Elapsed milliseconds since the request entered this handler (response time).
    $elapsed = function () use ( $t0 ) {
        return (int) round( ( microtime( true ) - $t0 ) * 1000 );
    };

    // Route through the AI Agent: it detects intent, continues any pending
    // multi-step flow, executes the right tool (product search, order tracking,
    // lead capture, booking, support ticket, WhatsApp handoff, navigation), and
    // falls back to website-content search — all website/tool grounded, never
    // general knowledge.
    if ( function_exists( 'wp_ai_agent_agent_respond' ) ) {
        $result = wp_ai_agent_agent_respond( $message, $session_id, $page_url );
    } else {
        // Defensive fallback to the legacy path if the agent layer is unavailable.
        $result = wp_ai_agent_legacy_respond( $message );
    }

    $answer = isset( $result['message'] ) ? $result['message'] : '';

    // Remember which products were shown this turn so a later objection
    // ("show me something else") can avoid repeating the same recommendations.
    if ( ! empty( $result['data']['products'] ) && is_array( $result['data']['products'] ) && function_exists( 'wp_ai_agent_record_shown_products' ) ) {
        $shown_ids = array();
        foreach ( $result['data']['products'] as $card ) {
            if ( ! empty( $card['id'] ) ) {
                $shown_ids[] = (int) $card['id'];
            }
        }
        wp_ai_agent_record_shown_products( $session_id, $shown_ids );
    }

    // A tool may mask what gets logged as the user message (e.g. a password
    // typed during login/registration must never be stored in the log).
    $log_user = isset( $result['log_user'] ) ? $result['log_user'] : $message;

    wp_ai_agent_log_conversation( $session_id, $page_url, $log_user, $answer, $elapsed() );

    $response = array(
        'message' => $answer,
        'source'  => isset( $result['source'] ) ? $result['source'] : 'website',
        'matched' => isset( $result['matched'] ) ? (bool) $result['matched'] : true,
        'intent'  => isset( $result['intent'] ) ? $result['intent'] : '',
    );
    if ( ! empty( $result['data'] ) ) {
        $response['data'] = $result['data'];
    }

    // AI Search Debugger (inline): attach the retrieval trace for administrators
    // when WP_DEBUG is on, so the search pipeline is visible during real chats.
    // Off for normal visitors; toggle via the wp_ai_agent_expose_search_debug filter.
    $expose = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    if ( (bool) apply_filters( 'wp_ai_agent_expose_search_debug', $expose, $message ) && function_exists( 'wp_ai_agent_search_debug' ) ) {
        $response['debug'] = wp_ai_agent_search_debug( $message );
    }

    return new WP_REST_Response( $response, 200 );
}

/**
 * Legacy (pre-agent) response path. Kept as a safety net.
 *
 * @param string $message User message.
 * @return array
 */
function wp_ai_agent_legacy_respond( $message ) {
    if ( function_exists( 'wp_ai_agent_match_custom_qa' ) ) {
        $qa_answer = wp_ai_agent_match_custom_qa( $message );
        if ( '' !== $qa_answer ) {
            return array( 'message' => $qa_answer, 'source' => 'qa', 'matched' => true );
        }
    }

    $product_context = function_exists( 'wp_ai_agent_wc_product_search' ) ? wp_ai_agent_wc_product_search( $message ) : '';
    if ( '' !== $product_context ) {
        $engine = new WP_AI_Agent_AI_Engine();
        return array( 'message' => $engine->ask( $message, $product_context, 'match' ), 'source' => 'woocommerce', 'matched' => true );
    }

    $price_context = wp_ai_agent_product_price_context( $message );
    if ( '' !== $price_context ) {
        $engine = new WP_AI_Agent_AI_Engine();
        return array( 'message' => $engine->ask( $message, $price_context, 'match' ), 'source' => 'woocommerce', 'matched' => true );
    }

    $retrieval = wp_ai_agent_retrieve_context( $message );
    if ( empty( $retrieval['has_match'] ) ) {
        return array( 'message' => wp_ai_agent_not_found_message(), 'source' => 'website', 'matched' => false );
    }
    $mode   = isset( $retrieval['mode'] ) ? $retrieval['mode'] : 'match';
    $engine = new WP_AI_Agent_AI_Engine();
    return array( 'message' => $engine->ask( $message, $retrieval['context'], $mode ), 'source' => 'website', 'matched' => true );
}

/**
 * AI Search Debugger: trace exactly how a query is understood and retrieved —
 * detected intent, extracted entities, the sources searched, the ranked matches
 * (with scores), the context that would be sent to the AI, and index health.
 * Powers the admin /search-debug endpoint so you can see WHY a query does or
 * doesn't find content that exists on the website.
 *
 * @param string $query User query.
 * @return array
 */
function wp_ai_agent_search_debug( $query ) {
    $query = trim( (string) $query );
    $out   = array( 'query' => $query );
    if ( '' === $query ) {
        return $out;
    }

    // 1) Intent + entities.
    if ( function_exists( 'wp_ai_agent_detect_intent' ) ) {
        $det               = wp_ai_agent_detect_intent( $query );
        $out['intent']     = isset( $det['intent'] ) ? $det['intent'] : '';
        $out['confidence'] = isset( $det['confidence'] ) ? round( (float) $det['confidence'], 2 ) : null;
    }
    if ( function_exists( 'wp_ai_agent_extract_entities' ) ) {
        $out['entities'] = array_filter( wp_ai_agent_extract_entities( $query ) );
    }

    // 2) Ranked matches from the INDEX (semantic + keyword), with scores.
    $out['index_matches'] = array();
    if ( function_exists( 'wp_ai_agent_universal_search' ) ) {
        foreach ( wp_ai_agent_universal_search( $query, 8 ) as $it ) {
            $out['index_matches'][] = array(
                'title' => isset( $it['title'] ) ? $it['title'] : '',
                'type'  => isset( $it['content_type'] ) ? $it['content_type'] : '',
                'url'   => isset( $it['url'] ) ? $it['url'] : '',
                'score' => isset( $it['relevance'] ) ? round( (float) $it['relevance'] * 100 ) . '%' : '',
            );
        }
    }

    // 3) Live query-time matches (title + content + Elementor/ACF), with the
    //    number of query terms each page contains.
    $out['live_matches'] = array();
    if ( function_exists( 'wp_ai_agent_live_search' ) ) {
        foreach ( wp_ai_agent_live_search( $query, 6 ) as $it ) {
            $out['live_matches'][] = array(
                'title'         => isset( $it['title'] ) ? $it['title'] : '',
                'type'          => isset( $it['content_type'] ) ? $it['content_type'] : '',
                'url'           => isset( $it['url'] ) ? $it['url'] : '',
                'terms_matched' => isset( $it['matched'] ) ? (int) $it['matched'] : null,
            );
        }
    }

    // 4) The exact context that would be sent to the AI.
    if ( function_exists( 'wp_ai_agent_retrieve_context' ) ) {
        $ctx                    = wp_ai_agent_retrieve_context( $query );
        $out['has_match']       = ! empty( $ctx['has_match'] );
        $out['retrieval_mode']  = isset( $ctx['mode'] ) ? $ctx['mode'] : '';
        $preview                = isset( $ctx['context'] ) ? (string) $ctx['context'] : '';
        $out['context_preview'] = ( strlen( $preview ) > 1500 ) ? substr( $preview, 0, 1500 ) . '…' : $preview;
    }

    // 5) Index health (so an empty/stale index is obvious).
    if ( function_exists( 'wp_ai_agent_index_table_name' ) ) {
        global $wpdb;
        $table = wp_ai_agent_index_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $out['index_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $out['index_with_embeddings'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE embedding <> ''" );
        } else {
            $out['index_rows'] = 0;
        }
    }
    $out['semantic_enabled'] = function_exists( 'wp_ai_agent_semantic_enabled' ) ? (bool) wp_ai_agent_semantic_enabled() : false;

    return $out;
}

/**
 * Name of the per-visitor / per-page conversation log table.
 *
 * @return string
 */
function wp_ai_agent_conversations_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'ai_conversations';
}

/**
 * Create the conversation log table (per visitor session, per page URL).
 */
function wp_ai_agent_create_conversations_table() {
    global $wpdb;

    $table           = wp_ai_agent_conversations_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    // The composite (session_id, page_url) index powers fast per-visitor,
    // per-page history loads; created_at speeds up date-ranged analytics.
    // page_url uses a 100-char prefix so the composite key stays within the
    // older MySQL 767-byte index limit even on utf8mb4.
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        session_id varchar(64) NOT NULL DEFAULT '',
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        page_url varchar(255) NOT NULL DEFAULT '',
        user_message text NOT NULL,
        bot_message text NOT NULL,
        response_ms int(10) unsigned NOT NULL DEFAULT 0,
        ip_address varchar(45) NOT NULL DEFAULT '',
        user_agent varchar(255) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'active',
        admin_read tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY session_id (session_id),
        KEY page_url (page_url),
        KEY session_page (session_id, page_url(100)),
        KEY created_at (created_at),
        KEY user_id (user_id),
        KEY status (status)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Insert one conversation exchange into the log table. Best-effort.
 *
 * @param string $session_id   Visitor session id.
 * @param string $page_url      Page URL where the chat happened.
 * @param string $user_message  The user's message.
 * @param string $bot_message   The assistant's reply.
 * @param int    $response_ms   Time taken to produce the reply, in milliseconds.
 * @return bool Whether a row was written.
 */
function wp_ai_agent_log_conversation( $session_id, $page_url, $user_message, $bot_message, $response_ms = 0 ) {
    global $wpdb;

    $user_message = sanitize_textarea_field( (string) $user_message );
    $bot_message  = sanitize_textarea_field( (string) $bot_message );
    if ( '' === $user_message && '' === $bot_message ) {
        return false;
    }

    $table = wp_ai_agent_conversations_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        wp_ai_agent_create_conversations_table();
    }

    // Capture who + from where, for the admin conversation dashboard. Logged-in
    // visitors are linked to their WP user (name/email derived at display time);
    // guests stay identified only by their session id. IP / user-agent are
    // best-effort context (used for device detection), never required.
    $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
    $ip      = wp_ai_agent_client_ip();
    $ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert(
        $table,
        array(
            'session_id'   => substr( (string) $session_id, 0, 64 ),
            'user_id'      => $user_id,
            'page_url'     => esc_url_raw( (string) $page_url ),
            'user_message' => $user_message,
            'bot_message'  => $bot_message,
            'response_ms'  => max( 0, (int) $response_ms ),
            'ip_address'   => $ip,
            'user_agent'   => $ua,
            'status'       => 'active',
            'admin_read'   => 0,
            'created_at'   => current_time( 'mysql' ),
        ),
        array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
    );

    return true;
}

/**
 * Best-effort client IP for the conversation log. Prefers the direct remote
 * address; falls back to the first hop in a forwarded-for header. Returns '' if
 * nothing usable is present. Never fatal.
 *
 * @return string
 */
function wp_ai_agent_client_ip() {
    $candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
    foreach ( $candidates as $key ) {
        if ( empty( $_SERVER[ $key ] ) ) {
            continue;
        }
        $raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
        // X-Forwarded-For can be a comma-separated list; take the first entry.
        $first = trim( explode( ',', $raw )[0] );
        $ip    = filter_var( $first, FILTER_VALIDATE_IP );
        if ( $ip ) {
            return substr( $ip, 0, 45 );
        }
    }
    return '';
}

/**
 * REST endpoint to log a conversation from the client (kept for compatibility).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function wp_ai_agent_handle_log_conversation_request( $request ) {
    $params  = $request->get_json_params();
    $logged  = wp_ai_agent_log_conversation(
        isset( $params['session_id'] ) ? $params['session_id'] : '',
        isset( $params['page_url'] ) ? $params['page_url'] : '',
        isset( $params['user_message'] ) ? $params['user_message'] : '',
        isset( $params['bot_message'] ) ? $params['bot_message'] : ''
    );

    return new WP_REST_Response( array( 'logged' => $logged ), 200 );
}

/**
 * Universal content search endpoint. Returns indexed website content grouped
 * by category. Pass an optional ?query= to filter; omit it to browse the index.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function wp_ai_agent_handle_search_content_request( $request ) {
    $query = $request->get_param( 'query' );
    $query = is_string( $query ) ? sanitize_text_field( $query ) : '';

    $response = wp_ai_agent_get_categorized_content( $query );

    // Also expose the unified ranked results (title, content, content_type,
    // relevance) when a query is provided.
    if ( '' !== $query && function_exists( 'wp_ai_agent_universal_search' ) ) {
        $response['results'] = wp_ai_agent_universal_search( $query, 10 );
    }

    return new WP_REST_Response( $response, 200 );
}

/**
 * Build product context for price-based questions (cheapest, most expensive,
 * under / over / around a price). Returns '' when the question is not a price
 * query or WooCommerce is not active.
 *
 * @param string $message User message.
 * @return string
 */
function wp_ai_agent_product_price_context( $message ) {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return '';
    }

    $m = strtolower( $message );

    // Does the question concern price at all?
    $price_words = array(
        'price', 'cost', 'cheap', 'cheapest', 'lowest', 'expensive', 'costly', 'highest',
        'budget', 'under', 'below', 'above', 'over', 'between', 'affordable',
        'sasta', 'saste', 'sasti', 'mehnga', 'mehnge', 'mehngi', 'keemat', 'daam', 'kimat',
        'rupee', 'rupees', 'rs', 'inr', '₹', '$',
    );
    $has_intent = false;
    foreach ( $price_words as $w ) {
        if ( false !== strpos( $m, $w ) ) {
            $has_intent = true;
            break;
        }
    }
    if ( ! $has_intent ) {
        return '';
    }

    // Extract numbers from the message (e.g. "under 500", "between 200 and 800").
    preg_match_all( '/\d[\d,]*\.?\d*/', $m, $num_matches );
    $numbers = array();
    foreach ( $num_matches[0] as $n ) {
        $numbers[] = (float) str_replace( ',', '', $n );
    }

    // Sort direction: cheapest first by default; expensive => high first.
    $order = 'ASC';
    if ( preg_match( '/expensive|costly|highest|mehng|maximum|most\b|jyada|zyada|jada/', $m ) ) {
        $order = 'DESC';
    }

    // Price range.
    $min = null;
    $max = null;
    if ( count( $numbers ) >= 2 && preg_match( '/between|range|se .*tak|to /', $m ) ) {
        sort( $numbers );
        $min = $numbers[0];
        $max = $numbers[ count( $numbers ) - 1 ];
    } elseif ( ! empty( $numbers ) ) {
        $target = $numbers[0];
        if ( preg_match( '/under|below|less than|upto|up to|within|cheaper than|andar|se kam|tak|niche|kam/', $m ) ) {
            $max = $target;
        } elseif ( preg_match( '/above|over|more than|greater than|upar|se jyada|se zyada|se jada|adhik/', $m ) ) {
            $min = $target;
        } else {
            // "around / in this price X" -> a +/- 25% window.
            $min = $target * 0.75;
            $max = $target * 1.25;
        }
    }

    // Fetch products sorted by price.
    $products = wc_get_products( array(
        'limit'    => (int) apply_filters( 'wp_ai_agent_price_query_limit', 100 ),
        'status'   => 'publish',
        'orderby'  => 'meta_value_num',
        'meta_key' => '_price', // phpcs:ignore WordPress.DB.SlowDBQuery
        'order'    => $order,
    ) );
    if ( empty( $products ) ) {
        return '';
    }

    // Guarantee price order in PHP (independent of query-arg support).
    usort( $products, function ( $a, $b ) use ( $order ) {
        $pa = (float) $a->get_price();
        $pb = (float) $b->get_price();
        if ( $pa === $pb ) {
            return 0;
        }
        if ( 'DESC' === $order ) {
            return ( $pa < $pb ) ? 1 : -1;
        }
        return ( $pa < $pb ) ? -1 : 1;
    } );

    // Filter by range if one was given.
    $matched = array();
    foreach ( $products as $product ) {
        $price = $product->get_price();
        if ( '' === $price || null === $price ) {
            continue;
        }
        $price = (float) $price;
        if ( null !== $min && $price < $min ) {
            continue;
        }
        if ( null !== $max && $price > $max ) {
            continue;
        }
        $matched[] = $product;
    }

    // If the range matched nothing, fall back to the closest products (already
    // price-sorted) so the AI can say "none in that range, the nearest are…".
    $use = ! empty( $matched ) ? $matched : $products;
    $use = array_slice( $use, 0, 8 );

    $context = '';
    foreach ( $use as $product ) {
        $price_text = html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ) );
        $desc       = $product->get_short_description();
        if ( '' === $desc ) {
            $desc = $product->get_description();
        }
        $context .= sprintf(
            "Title: %s\nURL: %s\nContent: Price: %s. %s\n\n",
            $product->get_name(),
            get_permalink( $product->get_id() ),
            $price_text,
            wp_trim_words( wp_strip_all_tags( $desc ), 30, '' )
        );
    }

    return trim( $context );
}
