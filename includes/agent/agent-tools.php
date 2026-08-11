<?php
/**
 * AI Agent tools.
 *
 * Each tool takes the user message (and session for multi-step flows) and
 * returns a normalized response array, or null when it cannot handle the
 * request (so the router can fall back). Tools execute a real action — product
 * search, order lookup, lead capture, booking, support ticket, WhatsApp handoff,
 * navigation — and only then produce the answer.
 *
 * Response shape: array(
 *   'message' => string,   // text shown to the visitor
 *   'handled' => bool,     // whether the tool produced the final answer
 *   'source'  => string,   // for analytics/debug
 *   'intent'  => string,
 *   'pending' => bool,     // a multi-step flow is awaiting the next message
 *   'matched' => bool,     // whether real content/action backed the answer
 *   'data'    => array,    // optional structured payload
 * )
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * Tables (bookings, tickets). Leads reuse the existing wp_ai_agent_leads.
 * ---------------------------------------------------------------------- */

/** @return string */
function wp_ai_agent_bookings_table() {
    global $wpdb;
    return $wpdb->prefix . 'ai_agent_bookings';
}

/** @return string */
function wp_ai_agent_tickets_table() {
    global $wpdb;
    return $wpdb->prefix . 'ai_agent_tickets';
}

/** @return string */
function wp_ai_agent_leads_table() {
    global $wpdb;
    return $wpdb->prefix . 'ai_agent_leads';
}

/** @return string */
function wp_ai_agent_order_logs_table() {
    global $wpdb;
    return $wpdb->prefix . 'ai_agent_order_logs';
}

/** @return string */
function wp_ai_agent_handoffs_table() {
    global $wpdb;
    return $wpdb->prefix . 'ai_agent_handoffs';
}

/**
 * Create the bookings + tickets tables (and ensure the leads table exists).
 */
function wp_ai_agent_create_agent_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $bookings = wp_ai_agent_bookings_table();
    dbDelta( "CREATE TABLE {$bookings} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(191) NOT NULL DEFAULT '',
        email varchar(191) NOT NULL DEFAULT '',
        phone varchar(50) NOT NULL DEFAULT '',
        service varchar(191) NOT NULL DEFAULT '',
        booking_date varchar(100) NOT NULL DEFAULT '',
        booking_time varchar(100) NOT NULL DEFAULT '',
        notes text NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        session_id varchar(64) NOT NULL DEFAULT '',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset_collate};" );
    
    $tickets = wp_ai_agent_tickets_table();
    dbDelta( "CREATE TABLE {$tickets} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        ticket_number varchar(20) NOT NULL DEFAULT '',
        name varchar(191) NOT NULL DEFAULT '',
        email varchar(191) NOT NULL DEFAULT '',
        subject varchar(255) NOT NULL DEFAULT '',
        message text NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'open',
        session_id varchar(64) NOT NULL DEFAULT '',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY ticket_number (ticket_number),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset_collate};" );

    $order_logs = wp_ai_agent_order_logs_table();
    dbDelta( "CREATE TABLE {$order_logs} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        order_number varchar(50) NOT NULL DEFAULT '',
        session_id varchar(64) NOT NULL DEFAULT '',
        found tinyint(1) NOT NULL DEFAULT 0,
        status varchar(30) NOT NULL DEFAULT '',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY order_number (order_number),
        KEY created_at (created_at)
    ) {$charset_collate};" );

    $handoffs = wp_ai_agent_handoffs_table();
    dbDelta( "CREATE TABLE {$handoffs} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event varchar(10) NOT NULL DEFAULT 'shown',
        query text NOT NULL,
        session_id varchar(64) NOT NULL DEFAULT '',
        page_url varchar(255) NOT NULL DEFAULT '',
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY event (event),
        KEY created_at (created_at)
    ) {$charset_collate};" );
}

/* -------------------------------------------------------------------------
 * Shared helpers.
 * ---------------------------------------------------------------------- */

/**
 * Build a normalized tool response.
 *
 * @param string $message Visitor-facing text.
 * @param array  $args    Overrides.
 * @return array
 */
function wp_ai_agent_tool_response( $message, $args = array() ) {
    return array_merge( array(
        'message' => $message,
        'handled' => true,
        'source'  => 'agent',
        'intent'  => '',
        'pending' => false,
        'matched' => true,
        'data'    => array(),
    ), $args );
}

/**
 * Read a plugin option value with a fallback.
 *
 * @param string $key     Option key.
 * @param string $default Default.
 * @return string
 */
function wp_ai_agent_option( $key, $default = '' ) {
    $o = wp_ai_agent_get_options();
    return ( isset( $o[ $key ] ) && '' !== $o[ $key ] ) ? $o[ $key ] : $default;
}

/**
 * Ask the configured AI provider to phrase an answer from website/product context.
 *
 * @param string $message User message.
 * @param string $context Retrieved context.
 * @param string $mode    'match' | 'overview'.
 * @return string
 */
function wp_ai_agent_engine_answer( $message, $context, $mode = 'match' ) {
    if ( ! class_exists( 'WP_AI_Agent_AI_Engine' ) ) {
        return $context;
    }
    $engine = new WP_AI_Agent_AI_Engine();
    return $engine->ask( $message, $context, $mode );
}

/**
 * Notify the site admin (or configured email) about a new lead/booking/ticket.
 *
 * @param string $subject Subject.
 * @param string $body    Body.
 * @return void
 */
function wp_ai_agent_notify_admin( $subject, $body ) {
    $to = wp_ai_agent_option( 'notify_email', get_option( 'admin_email' ) );
    if ( $to && is_email( $to ) ) {
        wp_mail( $to, $subject, $body );
    }
}

/**
 * Friendly canned reply for everyday small talk (greetings, thanks, how-are-you,
 * who-are-you, capabilities, goodbye, acknowledgements). Returns '' when the
 * message is not small talk, so real questions still go to search/tools.
 *
 * These are conversational courtesies — NOT general-knowledge answers — so they
 * keep the assistant feeling human without breaking the website-only rule.
 *
 * @param string $message User message.
 * @return string Reply, or ''.
 */
function wp_ai_agent_smalltalk_reply( $message ) {
    // Normalize: lowercase, drop punctuation/emoji, collapse spaces.
    $t = strtolower( preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', (string) $message ) );
    $t = trim( preg_replace( '/\s+/', ' ', $t ) );
    if ( '' === $t ) {
        return '';
    }

    $site = get_bloginfo( 'name' );
    if ( '' === $site ) {
        $site = __( 'our website', 'wp-ai-agent' );
    }

    // Personalize with the logged-in user's name when available.
    $first = function_exists( 'wp_ai_agent_user_first_name' ) ? wp_ai_agent_user_first_name() : '';
    $comma = ( '' !== $first ) ? ', ' . $first : '';

    // Thanks.
    if ( preg_match( '/\b(thanks|thank you|thank u|thankyou|thx|tysm|shukriya|shukriaa|dhanyavad|dhanyavaad)\b/', $t ) ) {
        return sprintf( __( "You're welcome%s! 😊 Is there anything else I can help you with?", 'wp-ai-agent' ), $comma );
    }

    // Goodbye (including "good night" sign-offs).
    if ( preg_match( '/\b(bye|byee|goodbye|good bye|see you|see ya|cya|alvida|tata|tata bye|good ?night|goodnight|gn|take care)\b/', $t ) ) {
        return __( 'Goodbye! Have a great day. 👋 I\'m here whenever you need anything.', 'wp-ai-agent' );
    }

    // Casual conversation ("can we talk", "I'm bored", "tell me something") —
    // stay friendly and gently steer toward how we can help, never a cold refusal.
    if ( preg_match( '/\b(can we (talk|chat)|lets (talk|chat)|i want to (talk|chat)|talk to me|just (talking|chatting|want to talk)|i am bored|i m bored|im bored|bored|tell me something|say something|entertain me|i am sad|feeling sad|good day to you)\b/', $t ) ) {
        return sprintf( __( "I'm happy to chat! 😊 I'm the assistant for %s, so I'm at my best helping you explore what we offer. Would you like to see our popular products, the latest arrivals, or is there something specific I can help you find?", 'wp-ai-agent' ), $site );
    }

    // How are you / what's up.
    if ( preg_match( '/\b(how are you|how r u|how are u|hows it going|how do you do|kaise ho|kaise hain|kaisे ho|kya haal|kya hal|kya chal raha|whats up|sup)\b/u', $t ) ) {
        return sprintf( __( "I'm doing great, thanks for asking! 😊 How can I help you with %s today?", 'wp-ai-agent' ), $site );
    }

    // Who / what are you (identity).
    if ( preg_match( '/\b(who are you|what are you|are you a bot|are you a robot|are you human|are you real|are you ai|tum kaun ho|aap kaun ho|tum kya ho|your name|tumhara naam|aapka naam)\b/', $t ) ) {
        return sprintf( __( "I'm the AI assistant for %s. I can help you find products, track orders, answer questions about the website, and connect you with our team. What would you like to do?", 'wp-ai-agent' ), $site );
    }

    // Capabilities / general "can you help me?" — offer help and ask what they
    // need instead of searching. Kept to essentially-standalone asks, so
    // "help me choose a shirt" still flows to the shopping assistant.
    if ( preg_match( '/^(help|menu|options|start|what can you do|what do you do|what all can you do|how can you help|how do you help|kya kar sakte ho|kya kar sakte hain|tum kya kar sakte ho|madad|help me)$/', $t )
        || preg_match( '/^(can|could|would) you (please |kindly )?(help|assist)( me| us)?( out| please)?$/', $t )
        || preg_match( '/^(please |pls )?(help|assist)( me| us)?( out)?( please)?$/', $t )
        || preg_match( '/^(i )?(need|want) (some |your )?(help|assistance|guidance)$/', $t ) ) {
        $commerce_help = function_exists( 'wp_ai_agent_commerce_enabled' ) ? wp_ai_agent_commerce_enabled() : function_exists( 'wc_get_products' );
        if ( $commerce_help ) {
            return sprintf( __( "Of course%s — I'd be happy to help! 😊 I can help you:\n• Find & recommend products\n• Track your order\n• Answer questions about this website (shipping, returns, payment, contact)\n• Book a request or raise a support ticket\n\nWhat are you looking for today?", 'wp-ai-agent' ), $comma );
        }
        // Non-store website: describe capabilities without commerce features.
        return sprintf( __( "Of course%s — I'd be happy to help! 😊 I can:\n• Answer questions about this website and what we offer\n• Help you find the right page or information\n• Book a request or raise a support ticket\n• Connect you with our team\n\nWhat can I help you with today?", 'wp-ai-agent' ), $comma );
    }

    // Greetings (message starts with a greeting word).
    if ( preg_match( '/^(hi+|hey+|hello+|helo|hii+|hlo|hy|yo|hola|namaste|namaskar|salaam|salam|good (morning|afternoon|evening|day))\b/', $t ) ) {
        if ( '' !== $first ) {
            return sprintf( __( "Hello %1\$s! 👋 How can I help you today? You can check your orders, ask about our products, or tap 📞 Contact.", 'wp-ai-agent' ), $first );
        }
        return sprintf( __( "Hello! 👋 Welcome to %s. How can I help you today? You can ask about our products, track an order, or tap 📞 Contact to reach our team.", 'wp-ai-agent' ), $site );
    }

    // Affirmations ("yes / yeah / sure") on their own — usually a reply to
    // "anything else?". Re-open the conversation instead of searching for "yes".
    if ( preg_match( '/^(yes+|yess+|yep|yup|yeah|yeh|ya|yaa|sure|ok yes|haan ji|bilkul|of course)$/', $t ) ) {
        return sprintf( __( '👍 Sure%s! What would you like help with? You can ask about our products, track an order, shipping, returns, or tap 📞 Contact.', 'wp-ai-agent' ), $comma );
    }

    // Declines ("no / nope / no thanks").
    if ( preg_match( '/^(no+|nope|nah|naa|no thanks|no thank you|nahi|nahin|not now|i am good|im good)$/', $t ) ) {
        return __( 'No problem! 😊 I\'m here whenever you need me — just ask anytime.', 'wp-ai-agent' );
    }

    // Confusion / needs clarification — reassure and offer to explain simply.
    if ( preg_match( '/^(i )?(dont|do not|didnt|did not) understand\b/', $t )
        || preg_match( '/^(i ?m|i am)?\s*(confused|lost)\b/', $t )
        || preg_match( '/^(not clear|what do you mean|makes no sense|too confusing|this is confusing)\b/', $t ) ) {
        return __( "No worries — I'll keep it simple. 😊 Could you tell me which part you'd like me to explain, or what you're trying to do? I'll guide you step by step.", 'wp-ai-agent' );
    }

    // Short acknowledgements / affirmations (varied so it never feels canned).
    if ( preg_match( '/^(ok|okay|okk+|k|kk|hmm+|haan|han|ji|ji haan|theek|thik|thik hai|theek hai|achha|acha|accha|great|nice|cool|good|awesome|perfect|fine|wow|super)$/', $t ) ) {
        $acks = array(
            __( '👍 Anything else I can help you with?', 'wp-ai-agent' ),
            __( 'Glad to help! 😊 Is there anything else you need?', 'wp-ai-agent' ),
            __( 'Great! Let me know if there is anything else I can do for you.', 'wp-ai-agent' ),
        );
        return $acks[ wp_rand( 0, count( $acks ) - 1 ) ];
    }

    return '';
}

/**
 * Dynamic "what I can help with" reply buttons, built from what this site
 * actually offers. Used to keep the conversation going after a not-found reply
 * instead of dead-ending.
 *
 * @return array[] List of { label, query }.
 */
function wp_ai_agent_help_actions() {
    $actions  = array();
    $commerce = function_exists( 'wp_ai_agent_commerce_enabled' ) ? wp_ai_agent_commerce_enabled() : function_exists( 'wc_get_products' );

    if ( $commerce ) {
        $actions[] = array( 'label' => __( '🛍️ Products', 'wp-ai-agent' ), 'query' => 'show products' );
        $actions[] = array( 'label' => __( '📂 Categories', 'wp-ai-agent' ), 'query' => 'what categories do you have' );
        $actions[] = array( 'label' => __( '🚚 Shipping', 'wp-ai-agent' ), 'query' => 'shipping information' );
        if ( is_user_logged_in() && function_exists( 'wc_get_orders' ) ) {
            $actions[] = array( 'label' => __( '📦 My orders', 'wp-ai-agent' ), 'query' => 'my orders' );
        }
    } elseif ( function_exists( 'wp_ai_agent_profile_quick_actions' ) ) {
        // Non-store site: offer this website's own starter actions instead of
        // commerce buttons.
        foreach ( array_slice( wp_ai_agent_profile_quick_actions(), 0, 3 ) as $pa ) {
            $actions[] = $pa;
        }
    }
    $actions[] = array( 'label' => __( '📞 Contact', 'wp-ai-agent' ), 'query' => 'contact information' );

    return apply_filters( 'wp_ai_agent_help_actions', $actions );
}

/**
 * A friendly, varied "what next?" line so product replies never repeat the same
 * closing sentence over and over (a common robotic tell). Picked at random from
 * a small pool; filterable so a store can set its own voice.
 *
 * @return string
 */
function wp_ai_agent_followup_line() {
    $lines = apply_filters( 'wp_ai_agent_followup_lines', array(
        __( "Would you like to see another colour, a cheaper option, or our best sellers? I'm happy to help. 😊", 'wp-ai-agent' ),
        __( 'Shall I narrow these down by price, colour, or category?', 'wp-ai-agent' ),
        __( 'Want me to compare a few of these, or show something in a different style?', 'wp-ai-agent' ),
        __( "Happy to refine this — by budget, brand, or category. Just let me know! 🙂", 'wp-ai-agent' ),
        __( 'Would you like to see more options, or should I help you pick the right one?', 'wp-ai-agent' ),
    ) );
    $lines = array_values( array_filter( (array) $lines ) );
    if ( empty( $lines ) ) {
        return '';
    }
    return $lines[ wp_rand( 0, count( $lines ) - 1 ) ];
}

/**
 * Standard "couldn't find that" reply that still guides the visitor — it carries
 * helpful next-step buttons so the conversation never ends abruptly.
 *
 * @param string $intent Detected intent (for analytics/debug).
 * @return array
 */
function wp_ai_agent_not_found_response( $intent = 'website_info' ) {
    $msg = function_exists( 'wp_ai_agent_not_found_message' )
        ? wp_ai_agent_not_found_message()
        : __( "Sorry, I couldn't find this information on this website.", 'wp-ai-agent' );

    return wp_ai_agent_tool_response( $msg, array(
        'intent'  => $intent,
        'matched' => false,
        'source'  => 'website',
        'data'    => array( 'actions' => wp_ai_agent_help_actions() ),
    ) );
}

/* -------------------------------------------------------------------------
 * Product tool.
 * ---------------------------------------------------------------------- */

/**
 * Clean, plain-text price for a product. Avoids WooCommerce's price HTML, whose
 * stripped screen-reader text ("Original price was: …") otherwise garbles the
 * card. Handles variable price ranges and sale prices.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function wp_ai_agent_product_price_text( $product ) {
    $fmt = function ( $value ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES );
    };

    if ( $product->is_type( 'variable' ) ) {
        $min = $product->get_variation_price( 'min', true );
        $max = $product->get_variation_price( 'max', true );
        if ( '' === (string) $min ) {
            return '';
        }
        return ( $min !== $max ) ? $fmt( $min ) . ' – ' . $fmt( $max ) : $fmt( $min );
    }

    if ( $product->is_on_sale() && '' !== (string) $product->get_regular_price() && '' !== (string) $product->get_sale_price() ) {
        /* translators: 1: sale price, 2: original price. */
        return sprintf( __( '%1$s (was %2$s)', 'wp-ai-agent' ), $fmt( $product->get_sale_price() ), $fmt( $product->get_regular_price() ) );
    }

    $price = $product->get_price();
    return ( '' !== (string) $price ) ? $fmt( $price ) : '';
}

/**
 * Extract how many products the visitor asked for ("suggest 2 products",
 * "top 3 shoes", "best five laptops"). Returns $default when no count is given.
 * Ignores large numbers (e.g. prices like "under 5000") by only accepting 1–10.
 *
 * @param string $message User message.
 * @param int    $default Fallback count.
 * @return int
 */
function wp_ai_agent_requested_count( $message, $default = 6 ) {
    $m   = ' ' . strtolower( (string) $message ) . ' ';
    $max = (int) apply_filters( 'wp_ai_agent_max_card_count', 10 );

    // Number words near a quantity cue.
    $words = array( 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10 );
    foreach ( $words as $w => $n ) {
        if ( preg_match( '/\b(?:top|best|suggest|show|give|recommend|me|first)\s+' . $w . '\b/', $m )
            || preg_match( '/\b' . $w . '\s+(?:best|top|products?|items?|options?|results?)\b/', $m ) ) {
            return min( $n, $max );
        }
    }

    // Digit form: "best 2", "top 3", "suggest me 5" / "2 best products", "3 items".
    if ( preg_match( '/\b(?:top|best|suggest|show|give|recommend|me|first)\s+(\d{1,2})\b/', $m, $mm )
        || preg_match( '/\b(\d{1,2})\s+(?:best|top|products?|items?|options?|results?)\b/', $m, $mm ) ) {
        $n = (int) $mm[1];
        if ( $n >= 1 && $n <= $max ) {
            return $n;
        }
    }

    return $default;
}

/**
 * Build a rich product card (image, price, link, add-to-cart) for the chat UI.
 *
 * @param WC_Product $product Product.
 * @return array
 */
function wp_ai_agent_product_card( $product ) {
    $image_id = $product->get_image_id();
    $image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
    if ( ! $image && function_exists( 'wc_placeholder_img_src' ) ) {
        $image = wc_placeholder_img_src( 'woocommerce_thumbnail' );
    }

    $price = wp_ai_agent_product_price_text( $product );

    $short = $product->get_short_description();
    if ( '' === trim( (string) $short ) ) {
        $short = $product->get_description();
    }
    $short = wp_trim_words( wp_strip_all_tags( $short ), 18, '…' );

    // First category name (Step 9: cards show the category).
    $cat_names = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
    $category  = ( ! is_wp_error( $cat_names ) && ! empty( $cat_names ) ) ? $cat_names[0] : '';

    return array(
        'id'          => $product->get_id(),
        'name'        => $product->get_name(),
        'price'       => $price,
        'url'         => get_permalink( $product->get_id() ),
        'image'       => $image ? $image : '',
        'short'       => $short,
        'category'    => $category,
        'add_url'     => $product->add_to_cart_url(),
        'add_text'    => $product->add_to_cart_text(),
        'in_stock'    => $product->is_in_stock(),
        'purchasable' => ( $product->is_purchasable() && $product->is_in_stock() ),
        // Simple products can be added via WooCommerce AJAX (no page reload).
        'add_ajax'    => ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ),
    );
}

/**
 * Fallback product list for generic product requests ("what do you sell",
 * "suggest products", "cheapest items") when no specific keyword matched.
 * Returns featured products, a price-sorted list, or the newest products.
 *
 * @param string $message User message.
 * @param int    $limit   Max products.
 * @return WC_Product[]
 */
function wp_ai_agent_fallback_products( $message, $limit ) {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return array();
    }

    $m     = strtolower( $message );
    $cheap = preg_match( '/cheap|lowest|sasta|saste|budget|low ?price|kam ?price/', $m );
    $exp   = preg_match( '/expensive|costly|highest|premium|mehng/', $m );

    // Price-sorted when the visitor asked by price.
    if ( $cheap || $exp ) {
        $products = wc_get_products( array( 'status' => 'publish', 'limit' => 100 ) );
        usort( $products, function ( $a, $b ) use ( $exp ) {
            $pa = (float) $a->get_price();
            $pb = (float) $b->get_price();
            if ( $pa === $pb ) {
                return 0;
            }
            if ( $exp ) {
                return ( $pa < $pb ) ? 1 : -1;
            }
            return ( $pa < $pb ) ? -1 : 1;
        } );
    } else {
        // Featured products first, else the newest products.
        $products = wc_get_products( array( 'status' => 'publish', 'featured' => true, 'limit' => 50 ) );
        if ( empty( $products ) ) {
            $products = wc_get_products( array( 'status' => 'publish', 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC' ) );
        }
    }

    if ( ! is_array( $products ) || empty( $products ) ) {
        return array();
    }

    // In-stock products first (never recommend out-of-stock first), preserving
    // the order computed above within each group.
    $in  = array();
    $out = array();
    foreach ( $products as $p ) {
        if ( $p->is_in_stock() ) {
            $in[] = $p;
        } else {
            $out[] = $p;
        }
    }

    return array_slice( array_merge( $in, $out ), 0, $limit );
}

/**
 * Extract a TARGET price from a message ("$150", "150 rupees", "around 150",
 * "150 ka product"). Returns null for range queries (under/above/between) and
 * when no price cue is present.
 *
 * @param string $message User message.
 * @return float|null
 */
function wp_ai_agent_extract_target_price( $message ) {
    $m = strtolower( $message );

    // Ranges are handled elsewhere — not a single target price.
    if ( preg_match( '/\b(under|below|less than|upto|up to|within|above|over|more than|greater than|between|range|se kam|se jyada|se zyada|tak)\b/u', $m ) ) {
        return null;
    }
    if ( ! preg_match( '/(\d[\d,]*\.?\d*)/', $m, $mm ) ) {
        return null;
    }
    $num = (float) str_replace( ',', '', $mm[1] );
    if ( $num <= 0 ) {
        return null;
    }

    // Require a price cue so plain counts ("2 products") are not treated as price.
    if ( ! preg_match( '/[₹$]|\b(rs|inr|rupees?|price|priced|cost|value|worth|around|about|approx|approximately|ka|ke|kii?)\b/u', $m ) ) {
        return null;
    }
    return $num;
}

/**
 * Find products at (or nearest to) a target price, optionally limited to a
 * product type in the message ("shirt around 150").
 *
 * @param string $message User message.
 * @param float  $target  Target price.
 * @param int    $limit   Max products.
 * @return array{exact:bool,products:WC_Product[]}
 */
function wp_ai_agent_price_match_products( $message, $target, $limit ) {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return array( 'exact' => false, 'products' => array() );
    }

    $type_keywords = array();
    if ( function_exists( 'wp_ai_agent_wc_query_keywords' ) ) {
        $type_keywords = array_values( array_diff( wp_ai_agent_wc_query_keywords( $message ), wp_ai_agent_generic_terms() ) );
    }

    $products = wc_get_products( array( 'status' => 'publish', 'limit' => (int) apply_filters( 'wp_ai_agent_wc_search_limit', 200 ) ) );
    if ( empty( $products ) ) {
        return array( 'exact' => false, 'products' => array() );
    }

    $cand = array();
    foreach ( $products as $product ) {
        $price = $product->get_price();
        if ( '' === (string) $price ) {
            continue;
        }
        $price = (float) $price;

        // Type filter (so "shirt for 150" only considers shirts).
        if ( ! empty( $type_keywords ) ) {
            $hay = ' ' . strtolower( $product->get_name() . ' ' . wp_ai_agent_wc_terms( $product ) ) . ' ';
            $ok  = false;
            foreach ( $type_keywords as $kw ) {
                $needles = function_exists( 'wp_ai_agent_expand_token' ) ? wp_ai_agent_expand_token( $kw ) : array( $kw );
                foreach ( $needles as $needle ) {
                    if ( wp_ai_agent_term_match( $hay, $needle ) ) {
                        $ok = true;
                        break 2;
                    }
                }
            }
            if ( ! $ok ) {
                continue;
            }
        }

        $cand[] = array( 'product' => $product, 'price' => $price, 'diff' => abs( $price - $target ) );
    }

    if ( empty( $cand ) ) {
        return array( 'exact' => false, 'products' => array() );
    }

    // Exact (rounded) matches first.
    $exact = array_values( array_filter( $cand, function ( $c ) use ( $target ) {
        return round( $c['price'] ) === round( $target );
    } ) );

    if ( ! empty( $exact ) ) {
        usort( $exact, function ( $a, $b ) {
            return (int) $b['product']->get_total_sales() <=> (int) $a['product']->get_total_sales();
        } );
        return array( 'exact' => true, 'products' => array_map( function ( $c ) {
            return $c['product'];
        }, array_slice( $exact, 0, (int) $limit ) ) );
    }

    // Otherwise the closest by price.
    usort( $cand, function ( $a, $b ) {
        return $a['diff'] <=> $b['diff'];
    } );
    return array( 'exact' => false, 'products' => array_map( function ( $c ) {
        return $c['product'];
    }, array_slice( $cand, 0, (int) $limit ) ) );
}

/**
 * A dynamic, query-specific intro line for product results — mentions the
 * actual thing the visitor searched for instead of a generic repeated sentence.
 *
 * @param string $message     User message.
 * @param bool   $is_fallback Whether results are a generic featured fallback.
 * @param int    $count       Number of products shown.
 * @return string
 */
function wp_ai_agent_product_intro( $message, $is_fallback, $count ) {
    if ( $is_fallback ) {
        return __( 'Here are some of our products:', 'wp-ai-agent' );
    }
    $kw = function_exists( 'wp_ai_agent_wc_query_keywords' ) ? wp_ai_agent_wc_query_keywords( $message ) : array();
    if ( empty( $kw ) ) {
        return ( 1 === (int) $count ) ? __( 'Here is a product I found:', 'wp-ai-agent' ) : __( 'Here are the products I found:', 'wp-ai-agent' );
    }
    // Quote the searched terms so the line always reads correctly regardless of
    // the words ("...for \"red\":", "...for \"running shoes\":").
    $subject = trim( implode( ' ', array_slice( $kw, 0, 4 ) ) );
    if ( '' === $subject ) {
        return ( 1 === (int) $count ) ? __( 'Here is a product I found:', 'wp-ai-agent' ) : __( 'Here are the products I found:', 'wp-ai-agent' );
    }
    /* translators: %s: the product/category the visitor searched for. */
    return sprintf( __( 'Here are the products I found for "%s":', 'wp-ai-agent' ), $subject );
}

/**
 * Whether a message expresses wanting/finding a product ("i want shoes",
 * "looking for a watch", "do you have rings", "pair of X"). Used so a product
 * request the store can't fulfil gets an honest "not available" reply instead
 * of a generic AI answer.
 *
 * @param string $message User message.
 * @return bool
 */
function wp_ai_agent_looks_like_product_request( $message ) {
    return (bool) preg_match( '/\b(want|wanna|looking for|look for|need|buy|purchase|pair of|pairs? of|do you (have|sell|stock)|chahiye|dikhao|dikha do|lena hai|kharidna|khareedna)\b/i', (string) $message );
}

/**
 * Live count of published posts of a type — a direct DB query (no object cache),
 * so website-statistics answers are always exact and real-time.
 *
 * @param string $post_type Post type.
 * @return int
 */
function wp_ai_agent_db_count_posts( $post_type ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
        $post_type
    ) );
}

/**
 * Live count of terms in a taxonomy — a direct DB query (no cache).
 *
 * @param string $taxonomy Taxonomy.
 * @return int
 */
function wp_ai_agent_db_count_terms( $taxonomy ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
        $taxonomy
    ) );
}

/**
 * Top product categories of the store (name + link), most-stocked first.
 *
 * @param int $limit Max categories.
 * @return array[] List of array( 'name' => string, 'link' => string ).
 */
function wp_ai_agent_store_categories( $limit = 8 ) {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return array();
    }
    $terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'number'     => (int) $limit,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ) );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return array();
    }
    $out = array();
    foreach ( $terms as $t ) {
        if ( 0 === strcasecmp( $t->name, 'Uncategorized' ) ) {
            continue;
        }
        $link  = get_term_link( $t );
        $out[] = array( 'name' => $t->name, 'link' => is_wp_error( $link ) ? '' : $link );
    }
    return $out;
}

/**
 * Honest "we don't sell that" reply when the visitor asked for a specific
 * product type the store doesn't carry — listing the categories it DOES offer
 * (as tappable buttons) instead of showing unrelated products.
 *
 * @param string[] $keywords The unmatched product keywords (e.g. ["shoes"]).
 * @param string   $intent   Detected intent.
 * @return array
 */
function wp_ai_agent_products_not_available( $keywords, $intent = 'product_search' ) {
    $what = implode( ' ', array_slice( $keywords, 0, 3 ) );
    $cats = wp_ai_agent_store_categories( 8 );

    if ( empty( $cats ) ) {
        return wp_ai_agent_tool_response(
            sprintf( __( "Sorry, we don't have \"%s\" on this website.", 'wp-ai-agent' ), $what ),
            array( 'source' => 'woocommerce', 'intent' => $intent, 'matched' => false )
        );
    }

    $names   = wp_list_pluck( $cats, 'name' );
    $msg     = sprintf(
        /* translators: 1: searched term, 2: category list. */
        __( "Sorry, we don't have \"%1\$s\". We currently offer: %2\$s. You can ask me about any of these. 🙂", 'wp-ai-agent' ),
        $what,
        implode( ', ', $names )
    );

    $actions = array();
    foreach ( array_slice( $cats, 0, 5 ) as $c ) {
        $actions[] = array( 'label' => $c['name'], 'query' => $c['name'] );
    }

    return wp_ai_agent_tool_response( $msg, array(
        'source'  => 'woocommerce',
        'intent'  => $intent,
        'matched' => false,
        'data'    => array( 'actions' => $actions ),
    ) );
}

/**
 * Human count line for a price-range result ("I found 18 products under $200:").
 * Uses wc_price so the currency matches the store. Singular/plural aware.
 *
 * @param array $flags Parsed price flags ( min / max ).
 * @param int   $total Total matching products (before the display cap).
 * @return string
 */
function wp_ai_agent_price_range_intro( $flags, $total ) {
    $fmt = function ( $v ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( $v ) ), ENT_QUOTES );
    };
    $min = isset( $flags['min'] ) ? $flags['min'] : null;
    $max = isset( $flags['max'] ) ? $flags['max'] : null;

    if ( null !== $min && null !== $max ) {
        /* translators: 1: count, 2: min price, 3: max price. */
        return sprintf( _n( 'I found %1$d product between %2$s and %3$s:', 'I found %1$d products between %2$s and %3$s:', $total, 'wp-ai-agent' ), $total, $fmt( $min ), $fmt( $max ) );
    }
    if ( null !== $max ) {
        /* translators: 1: count, 2: max price. */
        return sprintf( _n( 'I found %1$d product under %2$s:', 'I found %1$d products under %2$s:', $total, 'wp-ai-agent' ), $total, $fmt( $max ) );
    }
    if ( null !== $min ) {
        /* translators: 1: count, 2: min price. */
        return sprintf( _n( 'I found %1$d product over %2$s:', 'I found %1$d products over %2$s:', $total, 'wp-ai-agent' ), $total, $fmt( $min ) );
    }
    return __( 'Here are the products I found:', 'wp-ai-agent' );
}

/**
 * The shop URL pre-filtered to the requested price range, so a visitor can see
 * EVERY match when more exist than the chat shows. Uses WooCommerce's native
 * ?min_price / ?max_price query args. Returns '' when there is no shop page.
 *
 * @param array $flags Parsed price flags ( min / max ).
 * @return string
 */
function wp_ai_agent_price_shop_url( $flags ) {
    if ( ! function_exists( 'wc_get_page_permalink' ) ) {
        return '';
    }
    $shop = wc_get_page_permalink( 'shop' );
    if ( ! $shop ) {
        return '';
    }
    $args = array();
    if ( isset( $flags['min'] ) && null !== $flags['min'] ) {
        $args['min_price'] = (int) floor( $flags['min'] );
    }
    if ( isset( $flags['max'] ) && null !== $flags['max'] ) {
        $args['max_price'] = (int) ceil( $flags['max'] );
    }
    return empty( $args ) ? $shop : add_query_arg( $args, $shop );
}

/**
 * The colours actually available across a set of products (scanning each
 * product's name + taxonomy/attribute text). Returns display-cased unique
 * colour names, e.g. array( 'Black', 'Blue', 'Grey' ).
 *
 * @param WC_Product[] $products Products.
 * @return string[]
 */
function wp_ai_agent_product_available_colors( $products ) {
    $terms  = function_exists( 'wp_ai_agent_wc_color_terms' ) ? wp_ai_agent_wc_color_terms() : array();
    $colors = array();
    foreach ( (array) $products as $p ) {
        $hay = ' ' . strtolower( $p->get_name() . ' ' . ( function_exists( 'wp_ai_agent_wc_terms' ) ? wp_ai_agent_wc_terms( $p ) : '' ) ) . ' ';
        foreach ( $terms as $c ) {
            if ( wp_ai_agent_term_match( $hay, $c ) ) {
                $label = ucfirst( $c );
                if ( ! in_array( $label, $colors, true ) ) {
                    $colors[] = $label;
                }
            }
        }
    }
    return $colors;
}

/**
 * Strict Filter Mode alternatives. When a colour (or size) was requested but NO
 * product of that type matches it, we never silently show a different colour.
 * Instead: apologise honestly, then — preserving the product type (and gender) —
 * offer the colours that ARE available, as tappable options. Returns null when
 * this isn't a colour/size request or the product type doesn't exist at all
 * (so the caller can fall back to its normal "not available" handling).
 *
 * @param string $message User message.
 * @param string $intent  Detected intent.
 * @return array|null
 */
function wp_ai_agent_product_strict_alternatives( $message, $intent = 'product_search' ) {
    if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wp_ai_agent_extract_product_filters' ) ) {
        return null;
    }
    $f           = wp_ai_agent_extract_product_filters( $message );
    $wants_color = ! empty( $f['colors'] );
    $wants_size  = ! empty( $f['sizes'] );
    if ( ! $wants_color && ! $wants_size ) {
        return null; // Not a strict colour/size case — let normal handling run.
    }

    // The product TYPE (non-generic keywords), e.g. "tee".
    $kw   = function_exists( 'wp_ai_agent_wc_query_keywords' ) ? array_values( array_diff( wp_ai_agent_wc_query_keywords( $message ), wp_ai_agent_generic_terms() ) ) : array();
    $type = trim( implode( ' ', $kw ) );
    if ( '' === $type ) {
        return null; // No product type to offer alternatives within.
    }

    $gender     = ! empty( $f['genders'] ) ? $f['genders'][0] : '';
    $gender_lbl = ( '' !== $gender ) ? ucfirst( $gender ) . "'s " : '';

    // Preserve any PRICE bound so we don't blame colour when price was the real
    // blocker (e.g. white tees exist but none under $100).
    $price_phrase = '';
    if ( function_exists( 'wp_ai_agent_wc_parse_intent' ) ) {
        $pf = wp_ai_agent_wc_parse_intent( strtolower( (string) $message ) );
        if ( null !== $pf['min'] && null !== $pf['max'] ) {
            $price_phrase = ' between ' . (int) $pf['min'] . ' and ' . (int) $pf['max'];
        } elseif ( null !== $pf['max'] ) {
            $price_phrase = ' under ' . (int) $pf['max'];
        } elseif ( null !== $pf['min'] ) {
            $price_phrase = ' over ' . (int) $pf['min'];
        }
    }

    // Same-type search, RELAXED (drop colour & size, KEEP gender + price) to see
    // what colours the store actually carries in this type within any budget.
    $relaxed_q = trim( ( '' !== $gender ? $gender . ' ' : '' ) . $type . $price_phrase );
    $ignore    = null;
    $alt       = function_exists( 'wp_ai_agent_wc_rank_products' ) ? wp_ai_agent_wc_rank_products( $relaxed_q, 30, $ignore ) : array();
    if ( empty( $alt ) ) {
        return null; // No product of this type (within budget) → let normal handling take over.
    }

    // What did the customer ask for (for the apology line)?
    $req_bits = array();
    if ( '' !== $gender_lbl ) {
        $req_bits[] = trim( $gender_lbl );
    }
    if ( $wants_color ) {
        $req_bits[] = ucwords( implode( '/', $f['colors'] ) );
    }
    $req_bits[] = ucwords( $type );
    $requested  = trim( implode( ' ', $req_bits ) );

    /* translators: %s: the exact product the visitor asked for, e.g. "Men's White Tee". */
    $msg     = sprintf( __( "I'm sorry, I couldn't find any %s on our website.", 'wp-ai-agent' ), $requested );
    $actions = array();

    if ( $wants_color ) {
        $colors = wp_ai_agent_product_available_colors( $alt );
        if ( ! empty( $colors ) ) {
            /* translators: %s: gender + product type, e.g. "Men's Tee". */
            $msg .= "\n\n" . sprintf( __( 'We do have %s in these colours:', 'wp-ai-agent' ), trim( $gender_lbl . ucwords( $type ) ) );
            $msg .= "\n• " . implode( "\n• ", array_slice( $colors, 0, 8 ) );
            $msg .= "\n\n" . __( 'Would you like to see one of these colours?', 'wp-ai-agent' );
            foreach ( array_slice( $colors, 0, 6 ) as $c ) {
                $actions[] = array(
                    'label' => $c,
                    'query' => trim( strtolower( $c ) . ' ' . ( '' !== $gender ? $gender . ' ' : '' ) . $type . $price_phrase ),
                );
            }
        } else {
            /* translators: %s: gender + product type. */
            $msg      .= "\n\n" . sprintf( __( 'Would you like to see our %s range?', 'wp-ai-agent' ), trim( $gender_lbl . ucwords( $type ) ) );
            $actions[] = array( 'label' => sprintf( __( 'View %s', 'wp-ai-agent' ), ucwords( $type ) ), 'query' => $relaxed_q );
        }
    } else {
        /* translators: %s: product type. */
        $msg      .= "\n\n" . sprintf( __( 'Would you like to see our %s range in another size?', 'wp-ai-agent' ), trim( $gender_lbl . ucwords( $type ) ) );
        $actions[] = array( 'label' => sprintf( __( 'View %s', 'wp-ai-agent' ), ucwords( $type ) ), 'query' => $relaxed_q );
    }

    return wp_ai_agent_tool_response( $msg, array(
        'source'  => 'woocommerce',
        'intent'  => $intent,
        'matched' => false,
        'data'    => array( 'actions' => $actions ),
    ) );
}

/**
 * Whether Guided Mode is on (tappable colour/budget/category chips beside
 * results). Admin-toggleable; on by default. Filterable.
 *
 * @return bool
 */
function wp_ai_agent_guided_enabled() {
    $opt = function_exists( 'wp_ai_agent_option' ) ? wp_ai_agent_option( 'guided_mode', '1' ) : '1';
    return (bool) apply_filters( 'wp_ai_agent_guided_mode', '0' !== $opt );
}

/**
 * Guided-mode refine chips for a product result — dynamic, tappable buttons that
 * let the shopper narrow WITHOUT typing: the colours actually present in the
 * results, and a budget filter derived from their price spread. Each chip is a
 * plain filter word ("red", "under 50") that flows through the shopping-context
 * refinement, so it narrows the current search instead of restarting it.
 *
 * @param WC_Product[] $products The products just shown.
 * @return array[] Chip actions.
 */
function wp_ai_agent_guided_refine_actions( $products ) {
    if ( ! wp_ai_agent_guided_enabled() || empty( $products ) ) {
        return array();
    }
    $actions = array();

    // Colour chips (the colours available in these results).
    if ( function_exists( 'wp_ai_agent_product_available_colors' ) ) {
        foreach ( array_slice( wp_ai_agent_product_available_colors( $products ), 0, 4 ) as $c ) {
            $actions[] = array( 'label' => '🎨 ' . $c, 'query' => strtolower( $c ) );
        }
    }

    // A budget chip derived from the median price of the results.
    $prices = array();
    foreach ( $products as $p ) {
        $pr = (float) $p->get_price();
        if ( $pr > 0 ) {
            $prices[] = $pr;
        }
    }
    if ( count( $prices ) >= 2 ) {
        sort( $prices );
        $mid = $prices[ (int) floor( ( count( $prices ) - 1 ) / 2 ) ];
        $t   = $mid;
        // Round the threshold to a friendly number.
        if ( $t >= 100 ) {
            $t = (int) ( ceil( $t / 50 ) * 50 );
        } elseif ( $t >= 20 ) {
            $t = (int) ( ceil( $t / 10 ) * 10 );
        } else {
            $t = (int) ceil( $t );
        }
        if ( $t > 0 && $t < $prices[ count( $prices ) - 1 ] ) {
            $money = function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( $t ) ), ENT_QUOTES ) : ( '$' . $t );
            /* translators: %s: price threshold. */
            $actions[] = array( 'label' => '💸 ' . sprintf( __( 'Under %s', 'wp-ai-agent' ), $money ), 'query' => 'under ' . $t );
        }
    }

    return $actions;
}

/**
 * Product Agent: search / recommend / compare / best sellers / related — using
 * WooCommerce data only. Returns structured product cards (image, price,
 * add-to-cart, link) so the chat can render them richly. Returns null when no
 * product is found so the router can fall back to website info.
 *
 * @param string $message        User message.
 * @param string $intent         Detected intent.
 * @param bool   $allow_fallback Show featured/recent products when nothing
 *                               specific matched (used for genuine product
 *                               intents, NOT for policy/info questions).
 * @return array|null
 */
function wp_ai_agent_tool_product( $message, $intent = 'product_search', $allow_fallback = false, $not_available = false ) {
    // Prefer structured product cards (deterministic, no LLM call, no errors).
    // Honor a requested count ("suggest 2 products", "top 3 shoes").
    $default = (int) apply_filters( 'wp_ai_agent_card_count', 6 );
    $limit   = wp_ai_agent_requested_count( $message, $default );

    // Price-range queries ("products under $200") are STRICT filters and should
    // return many matches, not a few. Detect one up front and raise the display
    // cap (default 10) — unless the visitor asked for a specific smaller count
    // ("top 3 under $200"), in which case that count is respected.
    $is_price_range = false;
    $price_flags    = array( 'min' => null, 'max' => null );
    if ( function_exists( 'wp_ai_agent_wc_parse_intent' ) ) {
        $price_flags    = wp_ai_agent_wc_parse_intent( strtolower( (string) $message ) );
        $is_price_range = ( null !== $price_flags['min'] || null !== $price_flags['max'] );
    }
    if ( $is_price_range && $limit === $default ) {
        $limit = (int) apply_filters( 'wp_ai_agent_price_result_count', 10 );
    }

    // Target-price query ("$150", "around 150"): show products AT that price; if
    // none, apologize and show the closest-priced ones.
    $target = wp_ai_agent_extract_target_price( $message );
    if ( null !== $target ) {
        $res = wp_ai_agent_price_match_products( $message, $target, $limit );
        if ( ! empty( $res['products'] ) ) {
            $cards = array();
            foreach ( $res['products'] as $p ) {
                $cards[] = wp_ai_agent_product_card( $p );
            }
            $label = html_entity_decode( wp_strip_all_tags( wc_price( $target ) ), ENT_QUOTES );
            $intro = $res['exact']
                ? sprintf( __( 'Here are products priced at %s:', 'wp-ai-agent' ), $label )
                : sprintf( __( "Sorry, we don't have a product at exactly %s. Here are the closest ones:", 'wp-ai-agent' ), $label );
            return wp_ai_agent_tool_response( $intro, array(
                'source' => 'woocommerce',
                'intent' => $intent,
                'data'   => array( 'products' => $cards ),
            ) );
        }
    }

    $match_type    = null;
    $total_matches = 0;
    $products      = function_exists( 'wp_ai_agent_wc_rank_products' ) ? wp_ai_agent_wc_rank_products( $message, $limit, $match_type, $total_matches ) : array();

    $is_fallback = false;
    if ( empty( $products ) ) {
        // Strict Filter Mode: a colour/size was requested but nothing of that type
        // matches it → be honest and offer the colours we DO carry in that type,
        // never a silently-swapped colour.
        $strict = wp_ai_agent_product_strict_alternatives( $message, $intent );
        if ( null !== $strict ) {
            return $strict;
        }
        // Price-range query ("under $40") with no matches → say exactly that;
        // never substitute out-of-range products.
        if ( function_exists( 'wp_ai_agent_wc_parse_intent' ) ) {
            $flags = wp_ai_agent_wc_parse_intent( strtolower( $message ) );
            if ( null !== $flags['min'] || null !== $flags['max'] ) {
                return wp_ai_agent_tool_response(
                    __( "I couldn't find any products within that price range.", 'wp-ai-agent' ),
                    array( 'source' => 'woocommerce', 'intent' => $intent, 'matched' => false )
                );
            }
        }
        // The visitor named a specific item the store doesn't carry → say it's
        // not available and list our categories (never dump unrelated products,
        // and never let general AI ramble about it).
        if ( $not_available ) {
            $keywords = function_exists( 'wp_ai_agent_wc_query_keywords' ) ? wp_ai_agent_wc_query_keywords( $message ) : array();
            // Drop info/website words AND vague/person/gift words, so a
            // conversational message ("something for my father", "help me",
            // "anything nice") is never reported as an unavailable product.
            $info = array(
                'about', 'info', 'information', 'detail', 'details', 'know', 'tell', 'help', 'contact',
                'policy', 'policies', 'return', 'returns', 'refund', 'privacy', 'terms', 'shipping', 'delivery',
                'page', 'pages', 'website', 'site', 'service', 'services', 'question', 'open', 'show', 'find', 'give',
                // Vague / conversational.
                'something', 'anything', 'stuff', 'things', 'thing', 'nice', 'better', 'good', 'best', 'cool',
                'want', 'need', 'looking', 'buy', 'order', 'please', 'recommend', 'suggest', 'idea', 'ideas',
                'money', 'value', 'worth', 'quality', 'great', 'deal', 'deals', 'discount', 'discounts', 'offer', 'offers', 'sale', 'list', 'pls', 'plz',
                // Gift / people.
                'gift', 'gifts', 'present', 'presents', 'someone', 'somebody', 'anyone', 'everyone',
                'father', 'dad', 'mother', 'mom', 'mum', 'wife', 'husband', 'son', 'daughter', 'kid', 'kids',
                'child', 'children', 'friend', 'friends', 'boyfriend', 'girlfriend', 'brother', 'sister',
                'parents', 'parent', 'him', 'her', 'them', 'birthday', 'anniversary', 'wedding',
            );
            $keywords = array_values( array_diff( $keywords, $info ) );
            if ( ! empty( $keywords ) ) {
                return wp_ai_agent_products_not_available( $keywords, $intent );
            }
        }
        // Generic request ("suggest products") → featured/recent fallback.
        if ( $allow_fallback ) {
            $products    = wp_ai_agent_fallback_products( $message, $limit );
            $is_fallback = ! empty( $products );
        }
    }

    if ( ! empty( $products ) ) {
        $cards = array();
        foreach ( $products as $product ) {
            $cards[] = wp_ai_agent_product_card( $product );
        }
        $shown = count( $cards );

        // Guided-mode refine chips (colours / budget) built from these results,
        // followed by the standard best-sellers / categories shortcuts.
        $actions = array_merge(
            wp_ai_agent_guided_refine_actions( $products ),
            array(
                array( 'label' => __( '🔥 Best sellers', 'wp-ai-agent' ), 'query' => 'best sellers' ),
                array( 'label' => __( '📂 Categories', 'wp-ai-agent' ), 'query' => 'what categories do you have' ),
            )
        );

        // Exact-match-first: when only related ("similar") products were found —
        // no exact product of the requested type exists — say so honestly before
        // showing them (never pretend a T-Shirt is the "Shirt" that was asked for).
        if ( 'similar' === $match_type && ! $is_fallback ) {
            $kw      = function_exists( 'wp_ai_agent_wc_query_keywords' ) ? wp_ai_agent_wc_query_keywords( $message ) : array();
            $subject = trim( implode( ' ', array_slice( $kw, 0, 4 ) ) );
            $intro   = ( '' !== $subject )
                /* translators: %s: the product the visitor searched for. */
                ? sprintf( __( "I couldn't find an exact match for \"%s\", but here are some similar products you may like:", 'wp-ai-agent' ), $subject )
                : __( "I couldn't find an exact match, but here are some similar products you may like:", 'wp-ai-agent' );
        } elseif ( $is_price_range && ! $is_fallback ) {
            // Price-range result: state the real total ("I found 18 products under
            // $200"), and when more exist than shown, link to the full filtered
            // shop listing so nothing is hidden.
            $total = max( (int) $total_matches, $shown );
            $intro = wp_ai_agent_price_range_intro( $price_flags, $total );
            if ( $total > $shown ) {
                $intro .= "\n\n" . sprintf(
                    /* translators: 1: number shown, 2: total matches. */
                    __( 'Showing the first %1$d of %2$d. You can see them all here:', 'wp-ai-agent' ),
                    $shown,
                    $total
                );
                $see_url = wp_ai_agent_price_shop_url( $price_flags );
                if ( '' !== $see_url ) {
                    array_unshift( $actions, array( 'label' => __( '🛍️ See all matches', 'wp-ai-agent' ), 'url' => $see_url ) );
                }
            }
        } else {
            // Dynamic, query-specific intro (Rule 2/10) — mentions what was asked.
            $intro = wp_ai_agent_product_intro( $message, $is_fallback, $shown );
        }
        // Keep the conversation going (Rule 6/9) with a varied, human follow-up.
        $intro .= "\n\n" . wp_ai_agent_followup_line();

        return wp_ai_agent_tool_response( $intro, array(
            'source' => 'woocommerce',
            'intent' => $intent,
            'data'   => array(
                'products' => $cards,
                'actions'  => $actions,
            ),
        ) );
    }

    // Last resort: price-based questions answered from product data as text.
    $context = function_exists( 'wp_ai_agent_product_price_context' ) ? wp_ai_agent_product_price_context( $message ) : '';
    if ( '' !== $context ) {
        $answer = wp_ai_agent_engine_answer( $message, $context, 'match' );
        return wp_ai_agent_tool_response( $answer, array( 'source' => 'woocommerce', 'intent' => $intent ) );
    }

    return null;
}

/* -------------------------------------------------------------------------
 * Product comparison engine.
 * ---------------------------------------------------------------------- */

/**
 * Find the single best-matching product for a free-text name fragment.
 *
 * @param string $query Product name / fragment.
 * @return WC_Product|null
 */
function wp_ai_agent_first_product_by_name( $query ) {
    $query = trim( (string) $query );
    if ( '' === $query || ! function_exists( 'wc_get_products' ) ) {
        return null;
    }
    if ( function_exists( 'wp_ai_agent_wc_rank_products' ) ) {
        $ignore = null;
        $list   = wp_ai_agent_wc_rank_products( $query, 1, $ignore );
        if ( ! empty( $list ) ) {
            return $list[0];
        }
    }
    $p = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 's' => $query ) );
    return ! empty( $p ) ? $p[0] : null;
}

/**
 * Build the spec lines for one product in a comparison (website data only).
 *
 * @param WC_Product $product Product.
 * @return string[]
 */
function wp_ai_agent_compare_spec_lines( $product ) {
    $lines = array();

    $price = wp_ai_agent_product_price_text( $product );
    if ( '' !== $price ) {
        $lines[] = sprintf( __( 'Price: %s', 'wp-ai-agent' ), $price );
    }
    $pct = function_exists( 'wp_ai_agent_discount_percent' ) ? wp_ai_agent_discount_percent( $product ) : 0;
    if ( $pct > 0 ) {
        $lines[] = sprintf( __( 'Discount: %d%% off', 'wp-ai-agent' ), $pct );
    }

    $cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
    if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
        $lines[] = sprintf( __( 'Category: %s', 'wp-ai-agent' ), implode( ', ', $cats ) );
    }

    if ( function_exists( 'wp_ai_agent_brand_taxonomies' ) ) {
        $brands = array();
        foreach ( wp_ai_agent_brand_taxonomies() as $tax ) {
            if ( taxonomy_exists( $tax ) ) {
                $b = wp_get_post_terms( $product->get_id(), $tax, array( 'fields' => 'names' ) );
                if ( ! is_wp_error( $b ) ) {
                    $brands = array_merge( $brands, $b );
                }
            }
        }
        $brands = array_values( array_unique( array_filter( $brands ) ) );
        if ( ! empty( $brands ) ) {
            $lines[] = sprintf( __( 'Brand: %s', 'wp-ai-agent' ), implode( ', ', $brands ) );
        }
    }

    // Attribute options (size / colour / material / etc.).
    foreach ( $product->get_attributes() as $taxonomy => $attr ) {
        $label = '';
        $vals  = array();
        if ( is_string( $taxonomy ) && taxonomy_exists( $taxonomy ) ) {
            $names = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $names ) ) {
                $vals = $names;
            }
            $label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy ) : $taxonomy;
        } elseif ( is_object( $attr ) && method_exists( $attr, 'get_name' ) ) {
            $label = $attr->get_name();
            $vals  = method_exists( $attr, 'get_options' ) ? (array) $attr->get_options() : array();
        }
        $vals = array_values( array_filter( array_map( 'wp_strip_all_tags', $vals ) ) );
        if ( '' !== $label && ! empty( $vals ) ) {
            $lines[] = sprintf( '%s: %s', $label, implode( ', ', array_slice( $vals, 0, 6 ) ) );
        }
    }

    $rating = (float) $product->get_average_rating();
    $count  = (int) $product->get_review_count();
    if ( $count > 0 && $rating > 0 ) {
        /* translators: 1: rating, 2: review count. */
        $lines[] = sprintf( __( 'Rating: %1$s★ (%2$d reviews)', 'wp-ai-agent' ), round( $rating, 1 ), $count );
    }

    $lines[] = $product->is_in_stock()
        ? __( 'Availability: In stock', 'wp-ai-agent' )
        : __( 'Availability: Out of stock', 'wp-ai-agent' );

    return $lines;
}

/**
 * A recommendation between two products, based ONLY on website data
 * (price, discount, rating). Never invents specs.
 *
 * @param WC_Product $a Product A.
 * @param WC_Product $b Product B.
 * @return string
 */
function wp_ai_agent_compare_recommendation( $a, $b ) {
    $pa = (float) $a->get_price();
    $pb = (float) $b->get_price();
    $ra = (float) $a->get_average_rating();
    $rb = (float) $b->get_average_rating();

    $parts = array( __( 'My recommendation:', 'wp-ai-agent' ) );

    if ( $pa > 0 && $pb > 0 && $pa !== $pb ) {
        $cheaper = ( $pa < $pb ) ? $a : $b;
        $pricier = ( $pa < $pb ) ? $b : $a;
        $parts[] = sprintf(
            /* translators: 1/2: cheaper product + price, 3/4: pricier product + price. */
            __( 'For the best value for money, %1$s is the more affordable choice (%2$s). If you prefer the higher-end option, %3$s is %4$s.', 'wp-ai-agent' ),
            $cheaper->get_name(), wp_ai_agent_product_price_text( $cheaper ),
            $pricier->get_name(), wp_ai_agent_product_price_text( $pricier )
        );
    }
    if ( $ra > 0 && $rb > 0 && $ra !== $rb ) {
        $better  = ( $ra > $rb ) ? $a : $b;
        $parts[] = sprintf( __( '%1$s also has the higher customer rating (%2$s★).', 'wp-ai-agent' ), $better->get_name(), round( max( $ra, $rb ), 1 ) );
    }
    if ( 1 === count( $parts ) ) {
        $parts[] = __( 'Both are strong choices. If you tell me your budget or what matters most (price, quality, or features), I can suggest the best fit for you.', 'wp-ai-agent' );
    }
    return implode( ' ', $parts );
}

/**
 * Comparison tool: compare two products the visitor names ("A vs B", "which is
 * better", "difference between A and B") using website data, then recommend one
 * with reasoning. Asks a follow-up if it cannot resolve two products. Returns
 * null only when WooCommerce is unavailable (so the router can fall back).
 *
 * @param string $message User message.
 * @return array|null
 */
function wp_ai_agent_tool_compare( $message ) {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return null;
    }

    // Remove the comparison lead-in, then split into the two items to compare.
    $q = ' ' . trim( (string) $message ) . ' ';
    $q = preg_replace( '/\b(please|pls|can you|could you|kindly|for me)\b/i', ' ', $q );
    $q = preg_replace( '/^.*?\b(compare|comparison of|difference between|whats the difference between|what is the difference between|which is better|which one is better|which one should i (?:buy|get)|which should i (?:buy|get))\b/i', '', $q );

    $sides = preg_split( '/\s+(?:vs\.?|versus|or|and|,|&)\s+/i', trim( $q ) );
    $sides = array_values( array_filter( array_map( 'trim', (array) $sides ), function ( $s ) {
        return '' !== $s;
    } ) );

    // Need two product references. Otherwise ask which two (follow-up).
    if ( count( $sides ) < 2 ) {
        return wp_ai_agent_tool_response(
            __( "I'd be happy to compare products for you. 😊 Could you tell me which two products you'd like me to compare? For example: \"Product A vs Product B\".", 'wp-ai-agent' ),
            array( 'source' => 'woocommerce', 'intent' => 'product_comparison', 'matched' => true )
        );
    }

    $a = wp_ai_agent_first_product_by_name( $sides[0] );
    $b = wp_ai_agent_first_product_by_name( $sides[1] );

    if ( ! $a || ! $b || $a->get_id() === $b->get_id() ) {
        $missing = array();
        if ( ! $a ) {
            $missing[] = '"' . wp_trim_words( $sides[0], 6, '' ) . '"';
        }
        if ( ! $b ) {
            $missing[] = '"' . wp_trim_words( $sides[1], 6, '' ) . '"';
        }
        $msg = ! empty( $missing )
            ? sprintf( __( "I couldn't find %s in our store. Could you share the exact product names you'd like me to compare?", 'wp-ai-agent' ), implode( __( ' and ', 'wp-ai-agent' ), $missing ) )
            : __( "Could you tell me the two exact products you'd like me to compare?", 'wp-ai-agent' );
        return wp_ai_agent_tool_response( $msg, array( 'source' => 'woocommerce', 'intent' => 'product_comparison', 'matched' => false ) );
    }

    $lines = array( sprintf( __( "Here's how %1\$s and %2\$s compare:", 'wp-ai-agent' ), $a->get_name(), $b->get_name() ), '' );
    foreach ( array( $a, $b ) as $p ) {
        $lines[] = '▸ ' . $p->get_name();
        foreach ( wp_ai_agent_compare_spec_lines( $p ) as $l ) {
            $lines[] = '   • ' . $l;
        }
        $lines[] = '';
    }
    $lines[] = wp_ai_agent_compare_recommendation( $a, $b );

    return wp_ai_agent_tool_response(
        trim( implode( "\n", $lines ) ),
        array(
            'source' => 'woocommerce',
            'intent' => 'product_comparison',
            'data'   => array( 'products' => array( wp_ai_agent_product_card( $a ), wp_ai_agent_product_card( $b ) ) ),
        )
    );
}

/**
 * Cart tool: show the visitor's cart contents (when available) and a link to
 * the cart page.
 *
 * @return array|null
 */
function wp_ai_agent_tool_cart() {
    if ( ! function_exists( 'wc_get_cart_url' ) ) {
        return null;
    }

    $cart_url = wc_get_cart_url();
    $items    = array();
    if ( function_exists( 'WC' ) && WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $ci ) {
            $p = isset( $ci['data'] ) ? $ci['data'] : null;
            if ( $p ) {
                $items[] = $p->get_name() . ' x' . (int) $ci['quantity'];
            }
        }
    }

    if ( ! empty( $items ) ) {
        $msg = __( 'Your cart:', 'wp-ai-agent' ) . "\n- " . implode( "\n- ", $items ) . "\n\n" . sprintf( __( 'View your cart: %s', 'wp-ai-agent' ), $cart_url );
    } else {
        $msg = sprintf( __( 'Your cart appears to be empty. You can view it here: %s', 'wp-ai-agent' ), $cart_url );
    }

    return wp_ai_agent_tool_response( $msg, array( 'source' => 'cart', 'intent' => 'cart_view' ) );
}

/* -------------------------------------------------------------------------
 * Order tracking tool (multi-step).
 * ---------------------------------------------------------------------- */

/**
 * Look up a WooCommerce order by number.
 *
 * @param string $order_number Order number/id.
 * @return WC_Order|null
 */

function wp_ai_agent_lookup_order( $order_number ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return null;
    }
    $id = absint( preg_replace( '/\D/', '', (string) $order_number ) );
    if ( $id <= 0 ) {
        return null;
    }
    $order = wc_get_order( $id );
    return $order ? $order : null;
}

/**
 * Human-readable status sentence for an order.
 *
 * @param WC_Order $order Order.
 * @return string
 */

function wp_ai_agent_order_status_sentence( $order ) {
    $status = $order->get_status(); // without wc- prefix.
    $map    = array(
        'pending'    => __( 'is awaiting payment', 'wp-ai-agent' ),
        'on-hold'    => __( 'is on hold (awaiting confirmation)', 'wp-ai-agent' ),
        'processing' => __( 'is being processed', 'wp-ai-agent' ),
        'shipped'    => __( 'has been shipped', 'wp-ai-agent' ),
        'completed'  => __( 'has been completed/delivered', 'wp-ai-agent' ),
        'cancelled'  => __( 'was cancelled', 'wp-ai-agent' ),
        'refunded'   => __( 'was refunded', 'wp-ai-agent' ),
        'failed'     => __( 'failed', 'wp-ai-agent' ),
    );
    return isset( $map[ $status ] ) ? $map[ $status ] : sprintf( __( 'is %s', 'wp-ai-agent' ), wc_get_order_status_name( $status ) );
}

/**
 * Tracking number + URL for an order, read from common shipment-tracking
 * plugins (WooCommerce Shipment Tracking / Advanced Shipment Tracking store
 * `_wc_shipment_tracking_items`) or generic `_tracking_number` / `_tracking_url`
 * meta. Extendable via the wp_ai_agent_order_tracking filter.
 *
 * @param WC_Order $order Order.
 * @return array{number:string,url:string,provider:string}
 */
function wp_ai_agent_order_tracking_info( $order ) {
    $info = array( 'number' => '', 'url' => '', 'provider' => '' );

    $items = $order->get_meta( '_wc_shipment_tracking_items', true );
    if ( is_array( $items ) && ! empty( $items ) ) {
        $last = end( $items );
        if ( is_array( $last ) ) {
            $info['number']   = isset( $last['tracking_number'] ) ? (string) $last['tracking_number'] : '';
            if ( ! empty( $last['tracking_provider'] ) ) {
                $info['provider'] = (string) $last['tracking_provider'];
            } elseif ( ! empty( $last['custom_tracking_provider'] ) ) {
                $info['provider'] = (string) $last['custom_tracking_provider'];
            }
            if ( ! empty( $last['custom_tracking_link'] ) ) {
                $info['url'] = (string) $last['custom_tracking_link'];
            }
        }
    }

    if ( '' === $info['number'] ) {
        $n = $order->get_meta( '_tracking_number', true );
        if ( $n ) {
            $info['number'] = (string) $n;
        }
    }
    if ( '' === $info['url'] ) {
        $u = $order->get_meta( '_tracking_url', true );
        if ( $u ) {
            $info['url'] = (string) $u;
        }
    }

    /**
     * Filter the resolved tracking info so a custom logistics integration can
     * supply a number / URL the built-in detection does not cover.
     *
     * @param array    $info  number / url / provider.
     * @param WC_Order $order Order.
     */
    return apply_filters( 'wp_ai_agent_order_tracking', $info, $order );
}

/**
 * Whether the current (logged-in) visitor owns this order. Guests never pass
 * here — they must confirm the billing email instead.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function wp_ai_agent_user_owns_order( $order ) {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    $customer_id = (int) $order->get_customer_id();
    return $customer_id > 0 && $customer_id === get_current_user_id();
}

/**
 * Log an order-tracking request for the admin (order number, session, result).
 *
 * @param string $number     Requested order number.
 * @param string $session_id Visitor session id.
 * @param bool   $found      Whether the order was found.
 * @param string $status     Order status (or 'not_found').
 * @return void
 */
function wp_ai_agent_log_order_request( $number, $session_id, $found, $status ) {
    global $wpdb;
    $table = wp_ai_agent_order_logs_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        wp_ai_agent_create_agent_tables();
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert( $table, array(
        'order_number' => substr( preg_replace( '/[^0-9A-Za-z#\-]/', '', (string) $number ), 0, 50 ),
        'session_id'   => substr( (string) $session_id, 0, 64 ),
        'found'        => $found ? 1 : 0,
        'status'       => substr( (string) $status, 0, 30 ),
        'created_at'   => current_time( 'mysql' ),
    ), array( '%s', '%s', '%d', '%s', '%s' ) );
}

/**
 * Build the full order-tracking reply (all fields).
 *
 * @param WC_Order $order Order.
 * @return string
 */
function wp_ai_agent_format_order( $order ) {
    $status      = $order->get_status();
    $status_name = wc_get_order_status_name( $status );

    // Payment status.
    if ( $order->is_paid() ) {
        $payment = __( 'Paid', 'wp-ai-agent' );
        if ( $order->get_payment_method_title() ) {
            $payment .= ' (' . $order->get_payment_method_title() . ')';
        }
    } elseif ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
        $payment = $status_name;
    } else {
        $payment = __( 'Awaiting payment', 'wp-ai-agent' );
    }

    // Shipping status (derived from the order status; a "shipped" custom status
    // is honored when a tracking plugin adds it).
    $ship_map = array(
        'pending'    => __( 'Not shipped yet', 'wp-ai-agent' ),
        'on-hold'    => __( 'Not shipped yet', 'wp-ai-agent' ),
        'processing' => __( 'Preparing for shipment', 'wp-ai-agent' ),
        'shipped'    => __( 'Shipped', 'wp-ai-agent' ),
        'completed'  => __( 'Delivered', 'wp-ai-agent' ),
        'cancelled'  => __( 'Cancelled', 'wp-ai-agent' ),
        'refunded'   => __( 'Refunded', 'wp-ai-agent' ),
        'failed'     => __( 'Not shipped', 'wp-ai-agent' ),
    );
    $shipping = isset( $ship_map[ $status ] ) ? $ship_map[ $status ] : $status_name;

    // Dates.
    $order_date = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '—';
    $completed  = $order->get_date_completed();
    $delivery   = $completed ? wc_format_datetime( $completed ) : __( 'Not yet delivered', 'wp-ai-agent' );

    $lines   = array();
    $lines[] = sprintf( __( 'Order Number: #%s', 'wp-ai-agent' ), $order->get_order_number() );
    $lines[] = sprintf( __( 'Order Status: %s', 'wp-ai-agent' ), $status_name );
    $lines[] = sprintf( __( 'Payment Status: %s', 'wp-ai-agent' ), $payment );
    $lines[] = sprintf( __( 'Shipping Status: %s', 'wp-ai-agent' ), $shipping );
    $lines[] = sprintf( __( 'Order Date: %s', 'wp-ai-agent' ), $order_date );
    $lines[] = sprintf( __( 'Delivery Date: %s', 'wp-ai-agent' ), $delivery );

    // Tracking.
    $tracking = wp_ai_agent_order_tracking_info( $order );
    if ( '' !== $tracking['number'] || '' !== $tracking['url'] ) {
        if ( '' !== $tracking['number'] ) {
            $label   = $tracking['provider'] ? $tracking['provider'] . ': ' . $tracking['number'] : $tracking['number'];
            $lines[] = sprintf( __( 'Tracking Number: %s', 'wp-ai-agent' ), $label );
        }
        if ( '' !== $tracking['url'] ) {
            $lines[] = sprintf( __( 'Tracking URL: %s', 'wp-ai-agent' ), $tracking['url'] );
        }
    } else {
        $lines[] = __( 'Your order has been received but tracking information is not yet available.', 'wp-ai-agent' );
    }

    // Items.
    $items = array();
    foreach ( $order->get_items() as $item ) {
        $items[] = $item->get_name() . ' x' . $item->get_quantity();
    }
    if ( ! empty( $items ) ) {
        $lines[] = sprintf( __( 'Items: %s', 'wp-ai-agent' ), implode( ', ', $items ) );
    }

    return implode( "\n", $lines );
}

/**
 * Order tracking tool. Guests must log in; logged-in users get their own orders
 * automatically (no email needed). A specific order number is only revealed if
 * it belongs to the logged-in user.
 *
 * @param string $message    User message.
 * @param string $session_id Session id.
 * @param array  $entities   Extracted entities.
 * @return array
 */
function wp_ai_agent_tool_order( $message, $session_id, $entities ) {
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return wp_ai_agent_tool_response( __( 'Order tracking is not available on this website.', 'wp-ai-agent' ), array( 'matched' => false, 'intent' => 'order_tracking' ) );
    }

    $user   = wp_ai_agent_user();
    $number = isset( $entities['order_number'] ) ? $entities['order_number'] : '';

    // Guests must log in to see order information (privacy).
    if ( ! $user['logged_in'] ) {
        return wp_ai_agent_login_required_response( __( 'your order information', 'wp-ai-agent' ) );
    }

    $name = wp_ai_agent_user_first_name();

    // Specific order requested → must belong to this user.
    if ( '' !== $number ) {
        $order = wp_ai_agent_lookup_order( $number );
        wp_ai_agent_log_order_request( $number, $session_id, (bool) $order, $order ? $order->get_status() : 'not_found' );
        if ( ! $order || (int) $order->get_customer_id() !== (int) $user['id'] ) {
            return wp_ai_agent_tool_response(
                sprintf( __( "%s, I couldn't find that order in your account. Please check the number.", 'wp-ai-agent' ), $name ),
                array( 'matched' => false, 'intent' => 'order_tracking' )
            );
        }
        return wp_ai_agent_tool_response( wp_ai_agent_format_order( $order ), array( 'source' => 'order', 'intent' => 'order_tracking' ) );
    }

    // No number → list the user's recent orders automatically.
    $orders = wc_get_orders( array( 'customer_id' => (int) $user['id'], 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
    if ( empty( $orders ) ) {
        return wp_ai_agent_tool_response( sprintf( __( "%s, you don't have any orders yet.", 'wp-ai-agent' ), $name ), array( 'intent' => 'order_tracking', 'matched' => true ) );
    }

    $lines = array( sprintf( __( 'Here are your recent orders, %s:', 'wp-ai-agent' ), $name ), '' );
    foreach ( $orders as $order ) {
        $lines[] = sprintf(
            /* translators: 1: order number, 2: status, 3: total, 4: date. */
            __( 'Order #%1$s — %2$s — %3$s (%4$s)', 'wp-ai-agent' ),
            $order->get_order_number(),
            wc_get_order_status_name( $order->get_status() ),
            html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES ),
            wc_format_datetime( $order->get_date_created() )
        );
    }
    $lines[] = '';
    $lines[] = __( 'Reply with an order number (e.g. #1234) for full tracking details.', 'wp-ai-agent' );

    return wp_ai_agent_tool_response( implode( "\n", $lines ), array( 'source' => 'order', 'intent' => 'order_tracking' ) );
}

/**
 * Steps 2–4: validate the order exists, verify it belongs to the visitor, then
 * generate the response. Guests must confirm the billing email before any
 * details are revealed (privacy). Every attempt is logged for the admin.
 *
 * @param string $number     Order number.
 * @param string $session_id Session id.
 * @return array
 */
function wp_ai_agent_order_begin_lookup( $number, $session_id ) {
    $order = wp_ai_agent_lookup_order( $number );

    if ( ! $order ) {
        wp_ai_agent_log_order_request( $number, $session_id, false, 'not_found' );
        wp_ai_agent_clear_state( $session_id );
        return wp_ai_agent_tool_response(
            __( 'Sorry, I could not find that order number.', 'wp-ai-agent' ),
            array( 'matched' => false, 'intent' => 'order_tracking' )
        );
    }

    wp_ai_agent_log_order_request( $number, $session_id, true, $order->get_status() );

    // Logged-in customer who owns the order: show immediately.
    if ( wp_ai_agent_user_owns_order( $order ) ) {
        wp_ai_agent_clear_state( $session_id );
        return wp_ai_agent_tool_response( wp_ai_agent_format_order( $order ), array( 'source' => 'order', 'intent' => 'order_tracking' ) );
    }

    // Guest / different user: require billing-email confirmation.
    wp_ai_agent_set_state( $session_id, 'order', 'await_email', array( 'order_id' => $order->get_id(), 'attempts' => 0 ) );
    return wp_ai_agent_tool_response(
        __( 'For your security, please confirm the email address used on the order.', 'wp-ai-agent' ),
        array( 'pending' => true, 'intent' => 'order_tracking' )
    );
}

/**
 * Continue an in-progress order flow (await_number or await_email).
 *
 * @param array  $state      Pending state.
 * @param string $message    User message.
 * @param string $session_id Session id.
 * @param array  $entities   Extracted entities.
 * @return array
 */
function wp_ai_agent_order_continue( $state, $message, $session_id, $entities ) {
    $step = isset( $state['step'] ) ? $state['step'] : '';
    $data = isset( $state['data'] ) ? (array) $state['data'] : array();

    if ( 'await_email' === $step ) {
        $order = isset( $data['order_id'] ) ? wp_ai_agent_lookup_order( $data['order_id'] ) : null;
        if ( ! $order ) {
            wp_ai_agent_clear_state( $session_id );
            return wp_ai_agent_tool_response( __( 'Sorry, I could not find that order number.', 'wp-ai-agent' ), array( 'matched' => false, 'intent' => 'order_tracking' ) );
        }

        $given = $entities['email'];
        if ( '' !== $given && 0 === strcasecmp( trim( $given ), trim( (string) $order->get_billing_email() ) ) ) {
            wp_ai_agent_clear_state( $session_id );
            return wp_ai_agent_tool_response( wp_ai_agent_format_order( $order ), array( 'source' => 'order', 'intent' => 'order_tracking' ) );
        }

        $attempts = (int) ( isset( $data['attempts'] ) ? $data['attempts'] : 0 ) + 1;
        if ( $attempts >= 2 ) {
            wp_ai_agent_clear_state( $session_id );
            return wp_ai_agent_tool_response(
                __( "I couldn't verify that order with the email provided. Please contact support for help with your order.", 'wp-ai-agent' ),
                array( 'matched' => false, 'intent' => 'order_tracking' )
            );
        }
        $data['attempts'] = $attempts;
        wp_ai_agent_set_state( $session_id, 'order', 'await_email', $data );
        return wp_ai_agent_tool_response(
            __( "That email doesn't match our records for this order. Please enter the exact email used at checkout.", 'wp-ai-agent' ),
            array( 'pending' => true, 'intent' => 'order_tracking' )
        );
    }

    // Default: awaiting the order number.
    $number = $entities['order_number'];
    if ( '' === $number && preg_match( '/(\d{2,})/', $message, $mm ) ) {
        $number = $mm[1];
    }
    if ( '' === $number ) {
        // Give up after a few tries instead of repeating the prompt forever.
        $attempts = (int) ( isset( $data['attempts'] ) ? $data['attempts'] : 0 ) + 1;
        if ( $attempts >= 3 ) {
            wp_ai_agent_clear_state( $session_id );
            return wp_ai_agent_tool_response(
                __( "I still don't have a valid order number. You can ask me something else, or contact support for order help.", 'wp-ai-agent' ),
                array( 'matched' => false, 'intent' => 'order_tracking' )
            );
        }
        wp_ai_agent_set_state( $session_id, 'order', 'await_number', array( 'attempts' => $attempts ) );
        return wp_ai_agent_tool_response( __( 'Please share a valid order number (e.g. #1234).', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'order_tracking' ) );
    }
    return wp_ai_agent_order_begin_lookup( $number, $session_id );
}

/* -------------------------------------------------------------------------
 * Lead Qualification Agent (multi-step: name -> email -> phone -> requirement).
 * ---------------------------------------------------------------------- */

/**
 * The lead lifecycle statuses.
 *
 * @return array<string,string> slug => label.
 */
function wp_ai_agent_lead_statuses() {
    return array(
        'new'       => __( 'New', 'wp-ai-agent' ),
        'contacted' => __( 'Contacted', 'wp-ai-agent' ),
        'qualified' => __( 'Qualified', 'wp-ai-agent' ),
        'converted' => __( 'Converted', 'wp-ai-agent' ),
        'lost'      => __( 'Lost', 'wp-ai-agent' ),
    );
}

/**
 * Score a lead 0–100 from its intent signals. Pricing / consultation / quote
 * requests score high; vague general questions score low.
 *
 * @param string $text Combined trigger + requirement text.
 * @param array  $data Collected lead data (email/phone presence adds intent).
 * @return int 0–100.
 */
function wp_ai_agent_score_lead( $text, $data = array() ) {
    $t     = ' ' . strtolower( (string) $text ) . ' ';
    $score = 40;

    if ( preg_match( '/\b(price|pricing|quote|quotation|cost|costing|budget|buy|purchase|order|invoice|plan|package)\b/', $t ) ) {
        $score += 35;
    }
    if ( preg_match( '/\b(consult|consultation|demo|appointment|meeting|onboarding|trial|book a)\b/', $t ) ) {
        $score += 30;
    }
    if ( preg_match( '/\b(contact|call me|callback|call back|reach me|get in touch|talk to sales|enquiry|inquiry|enquire)\b/', $t ) ) {
        $score += 20;
    }
    if ( preg_match( '/\b(interested|need|want|require|looking for|urgent|asap|immediately|ready to)\b/', $t ) ) {
        $score += 15;
    }
    if ( ! empty( $data['email'] ) ) {
        $score += 5;
    }
    if ( ! empty( $data['phone'] ) ) {
        $score += 5;
    }

    $score = max( 10, min( 100, $score ) );

    /**
     * Filter the computed lead score.
     *
     * @param int    $score 0–100.
     * @param string $text  Scored text.
     * @param array  $data  Lead data.
     */
    return (int) apply_filters( 'wp_ai_agent_lead_score', $score, $text, $data );
}

/**
 * Persist a qualified lead and fire the CRM integration hook.
 *
 * @param array  $data       Lead fields (name, email, phone, message, lead_source, score).
 * @param string $session_id Visitor session id.
 * @param string $page_url   Page where the lead was captured.
 * @return int Inserted lead id, or 0.
 */
function wp_ai_agent_save_lead( $data, $session_id = '', $page_url = '' ) {
    global $wpdb;
    $table = wp_ai_agent_leads_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table && function_exists( 'wp_ai_agent_create_tables' ) ) {
        wp_ai_agent_create_tables();
    }

    $row = array(
        'name'        => sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ),
        'email'       => sanitize_email( isset( $data['email'] ) ? $data['email'] : '' ),
        'phone'       => sanitize_text_field( isset( $data['phone'] ) ? $data['phone'] : '' ),
        'message'     => sanitize_textarea_field( isset( $data['message'] ) ? $data['message'] : '' ),
        'lead_source' => sanitize_text_field( isset( $data['lead_source'] ) ? $data['lead_source'] : 'chat' ),
        'page_url'    => esc_url_raw( (string) $page_url ),
        'session_id'  => substr( (string) $session_id, 0, 64 ),
        'lead_status' => 'new',
        'score'       => (int) ( isset( $data['score'] ) ? $data['score'] : 0 ),
        'created_at'  => current_time( 'mysql' ),
    );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) );
    $id = (int) $wpdb->insert_id;

    /**
     * Fires after a lead is captured. Hook this to push leads to a CRM
     * (HubSpot, Zoho, Google Sheets, etc.).
     *
     * @param int   $id   New lead id.
     * @param array $lead The stored lead row (plus 'id').
     */
    do_action( 'wp_ai_agent_lead_created', $id, array_merge( $row, array( 'id' => $id ) ) );

    return $id;
}

/**
 * Update a lead's status.
 *
 * @param int    $id     Lead id.
 * @param string $status One of wp_ai_agent_lead_statuses() keys.
 * @return bool
 */
function wp_ai_agent_update_lead_status( $id, $status ) {
    global $wpdb;
    if ( ! array_key_exists( $status, wp_ai_agent_lead_statuses() ) ) {
        return false;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return false !== $wpdb->update( wp_ai_agent_leads_table(), array( 'lead_status' => $status ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
}

/**
 * Query leads with search, status filter, and pagination (admin dashboard).
 *
 * @param array $args { search, status, per_page, page }.
 * @return array{rows:object[],total:int,pages:int,page:int}
 */
function wp_ai_agent_get_leads( $args = array() ) {
    global $wpdb;
    $defaults = array( 'search' => '', 'status' => '', 'per_page' => 20, 'page' => 1 );
    $args     = wp_parse_args( $args, $defaults );
    $table    = wp_ai_agent_leads_table();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return array( 'rows' => array(), 'total' => 0, 'pages' => 0, 'page' => 1 );
    }

    $where  = array( '1=1' );
    $params = array();
    if ( '' !== $args['search'] ) {
        $like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR message LIKE %s)';
        $params  = array_merge( $params, array( $like, $like, $like, $like ) );
    }
    if ( '' !== $args['status'] && array_key_exists( $args['status'], wp_ai_agent_lead_statuses() ) ) {
        $where[]  = 'lead_status = %s';
        $params[] = $args['status'];
    }
    $where_sql = implode( ' AND ', $where );

    $per_page = max( 1, (int) $args['per_page'] );
    $page     = max( 1, (int) $args['page'] );
    $offset   = ( $page - 1 ) * $per_page;

    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

    $data_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
    $data_params = array_merge( $params, array( $per_page, $offset ) );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ) );

    return array(
        'rows'  => $rows ? $rows : array(),
        'total' => $total,
        'pages' => (int) ceil( $total / $per_page ),
        'page'  => $page,
    );
}

/**
 * Begin or continue lead capture. Collects name, email, phone, and the
 * requirement, scores the lead, saves it, notifies the admin, and fires the
 * CRM hook.
 *
 * @param string $message    User message.
 * @param string $session_id Session id.
 * @param array  $data       Collected data so far.
 * @param array  $entities   Extracted entities (email/phone).
 * @param bool   $starting   True on first invocation.
 * @param string $page_url   Page where the lead started.
 * @return array
 */
function wp_ai_agent_tool_lead( $message, $session_id, $data, $entities, $starting, $page_url = '' ) {
    // Opportunistically capture any email/phone present in the message.
    if ( '' !== $entities['email'] && empty( $data['email'] ) ) {
        $data['email'] = $entities['email'];
    }
    if ( '' !== $entities['phone'] && empty( $data['phone'] ) ) {
        $data['phone'] = $entities['phone'];
    }

    if ( $starting ) {
        $data['trigger']     = $message; // what they originally asked for.
        $data['page_url']    = $page_url;
        $data['lead_source'] = 'chat';

        // Smart contact routing: before asking for name/email/phone in chat,
        // prefer an existing website contact/quote/enquiry form when one exists.
        // Controlled by the admin "Lead Collection Mode" setting:
        //   'form' → always hand off to the form when one exists (recommended);
        //   'both' → offer the form AND a "share here" option;
        //   'ai'   → skip discovery, collect details in chat (legacy behaviour).
        // "Share your details here" bypasses this so chat collection can start.
        $lead_mode = wp_ai_agent_option( 'lead_mode', 'form' );
        if ( 'ai' !== $lead_mode && ! wp_ai_agent_lead_wants_ai_collection( $message ) ) {
            $forms = wp_ai_agent_discover_contact_forms();
            if ( ! empty( $forms ) ) {
                return wp_ai_agent_lead_form_response( $forms, $lead_mode, $page_url );
            }
        }
    } else {
        // The message is the answer to the field we last asked for.
        $awaiting = isset( $data['awaiting'] ) ? $data['awaiting'] : '';
        if ( 'name' === $awaiting && empty( $data['name'] ) ) {
            // A real name contains letters. If the visitor typed only digits/
            // symbols (e.g. a phone number), don't store it as their name —
            // capture it as their phone instead and ask for the name again.
            if ( preg_match( '/\p{L}/u', $message ) ) {
                $data['name'] = sanitize_text_field( $message );
            } else {
                $digits = preg_replace( '/\D/', '', $message );
                if ( strlen( $digits ) >= 5 && empty( $data['phone'] ) ) {
                    $data['phone'] = sanitize_text_field( $message );
                }
                $tries              = (int) ( isset( $data['name_tries'] ) ? $data['name_tries'] : 0 ) + 1;
                $data['name_tries'] = $tries;
                if ( $tries < 2 ) {
                    wp_ai_agent_set_state( $session_id, 'lead', 'collect', $data );
                    return wp_ai_agent_tool_response( __( 'Could you share your name, please? (Just your name — letters only.)', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'lead_generation' ) );
                }
                // After a couple of tries, stop looping and use what we have.
                $data['name'] = __( 'there', 'wp-ai-agent' );
            }
        } elseif ( 'phone' === $awaiting && empty( $data['phone'] ) ) {
            // Accept the typed answer directly. The entity extractor only
            // recognises 8+ digit numbers, so a shorter number the visitor gives
            // here (e.g. "465454") would otherwise be rejected forever. Take any
            // answer with at least 5 digits; after 2 non-numeric replies, stop
            // asking and proceed with what we have (no infinite loop).
            $digits = preg_replace( '/\D/', '', $message );
            if ( strlen( $digits ) >= 5 ) {
                $data['phone'] = sanitize_text_field( $message );
            } else {
                $tries = (int) ( isset( $data['phone_tries'] ) ? $data['phone_tries'] : 0 ) + 1;
                $data['phone_tries'] = $tries;
                if ( $tries >= 2 ) {
                    $data['phone'] = '—'; // give up gracefully; continue the flow.
                }
            }
        } elseif ( 'email' === $awaiting && empty( $data['email'] ) ) {
            // A valid email is captured opportunistically above; if the reply was
            // not a valid email, count the attempt and move on after a few tries.
            $tries = (int) ( isset( $data['email_tries'] ) ? $data['email_tries'] : 0 ) + 1;
            $data['email_tries'] = $tries;
            if ( $tries >= 3 ) {
                $data['email'] = '—';
            }
        } elseif ( 'requirement' === $awaiting && empty( $data['requirement'] ) ) {
            $data['requirement'] = sanitize_textarea_field( $message );
        }
        // email / phone are also captured opportunistically above.
    }

    // Ask for the next missing field, in order.
    if ( empty( $data['name'] ) ) {
        $data['awaiting'] = 'name';
        wp_ai_agent_set_state( $session_id, 'lead', 'collect', $data );
        return wp_ai_agent_tool_response( __( "I'd be happy to help. Can I have your name?", 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'lead_generation' ) );
    }
    if ( empty( $data['email'] ) ) {
        $data['awaiting'] = 'email';
        wp_ai_agent_set_state( $session_id, 'lead', 'collect', $data );
        return wp_ai_agent_tool_response( sprintf( __( 'Thanks, %s! What is the best email address to reach you?', 'wp-ai-agent' ), $data['name'] ), array( 'pending' => true, 'intent' => 'lead_generation' ) );
    }
    if ( empty( $data['phone'] ) ) {
        $data['awaiting'] = 'phone';
        wp_ai_agent_set_state( $session_id, 'lead', 'collect', $data );
        return wp_ai_agent_tool_response( __( 'Great. And a phone number our team can call you on?', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'lead_generation' ) );
    }
    if ( empty( $data['requirement'] ) ) {
        $data['awaiting'] = 'requirement';
        wp_ai_agent_set_state( $session_id, 'lead', 'collect', $data );
        return wp_ai_agent_tool_response( __( 'Lastly, please briefly describe what you need (your requirement).', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'lead_generation' ) );
    }

    // Complete — score + save the lead.
    wp_ai_agent_clear_state( $session_id );

    $trigger     = isset( $data['trigger'] ) ? $data['trigger'] : '';
    $requirement = isset( $data['requirement'] ) ? $data['requirement'] : '';
    $full_message = trim( $trigger . ( '' !== $requirement ? ' — ' . $requirement : '' ) );
    $score        = wp_ai_agent_score_lead( $trigger . ' ' . $requirement, $data );

    $lead_id = wp_ai_agent_save_lead(
        array(
            'name'        => $data['name'],
            'email'       => $data['email'],
            'phone'       => $data['phone'],
            'message'     => $full_message,
            'lead_source' => isset( $data['lead_source'] ) ? $data['lead_source'] : 'chat',
            'score'       => $score,
        ),
        $session_id,
        isset( $data['page_url'] ) ? $data['page_url'] : $page_url
    );

    wp_ai_agent_notify_admin(
        sprintf( __( 'New lead from AI Agent (score %d)', 'wp-ai-agent' ), $score ),
        sprintf(
            "Name: %s\nEmail: %s\nPhone: %s\nRequirement: %s\nLead score: %d/100\nStatus: New\nPage: %s",
            $data['name'],
            $data['email'],
            $data['phone'],
            $full_message,
            $score,
            isset( $data['page_url'] ) ? $data['page_url'] : $page_url
        )
    );

    return wp_ai_agent_tool_response(
        sprintf( __( 'Thank you, %s! Our team will reach out to you shortly. ✅', 'wp-ai-agent' ), $data['name'] ),
        array( 'source' => 'lead', 'intent' => 'lead_generation', 'data' => array( 'lead_id' => $lead_id, 'score' => $score ) )
    );
}

/**
 * True when the visitor explicitly wants to leave their details IN the chat
 * (used by the "share your details here" option to bypass form hand-off).
 *
 * @param string $message User message.
 * @return bool
 */
function wp_ai_agent_lead_wants_ai_collection( $message ) {
    $m = strtolower( (string) $message );
    return ( false !== strpos( $m, 'share' ) && false !== strpos( $m, 'detail' ) )
        || false !== strpos( $m, 'here in the chat' )
        || false !== strpos( $m, 'leave my details here' );
}

/**
 * Discover existing contact / quote / enquiry / support forms on the site —
 * dynamically, with NO hardcoded URLs or plugin dependency. A page qualifies
 * when it contains a known form marker (Contact Form 7, WPForms, Gravity,
 * Fluent, Ninja, Formidable, Forminator, Elementor Pro form, a Gutenberg form
 * block, or a raw HTML form with an email field) OR its title/slug reads like a
 * contact/quote/support page (so builder pages whose form we can't see still
 * count). Cached 12h. Filterable via `wp_ai_agent_contact_forms`.
 *
 * @return array[] List of { id, title, type, url, has_form, rank }.
 */
function wp_ai_agent_discover_contact_forms() {
    $cached = get_transient( 'wp_ai_agent_contact_forms' );
    if ( is_array( $cached ) ) {
        return apply_filters( 'wp_ai_agent_contact_forms', $cached );
    }

    $forms = array();
    $pages = get_posts( array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
    ) );

    // Shortcode / block / builder markers for the common form plugins.
    $markers = apply_filters( 'wp_ai_agent_contact_form_markers', array(
        'contact-form-7', 'contact-form', 'wpforms', 'gravityform', 'gravity_form',
        'fluentform', 'fluent_form', 'ninja_form', 'formidable', 'forminator',
        'everest_form', 'weforms', 'happyforms', 'caldera_form', 'wp:contact-form',
        'wpcf7', 'quform', 'kali_form', 'metform',
    ) );

    // Title/slug cues → friendly form-type label (for the multi-form chooser).
    $title_map = array(
        __( 'Request a Quote', 'wp-ai-agent' )      => array( 'request a quote', 'request quote', 'get a quote', 'get quote', 'quotation', 'estimate' ),
        __( 'Book a Consultation', 'wp-ai-agent' )  => array( 'book a consultation', 'consultation', 'book a call', 'book appointment' ),
        __( 'Technical Support', 'wp-ai-agent' )    => array( 'technical support', 'help desk', 'helpdesk', 'support' ),
        __( 'Sales Enquiry', 'wp-ai-agent' )        => array( 'sales enquiry', 'sales inquiry', 'sales' ),
        __( 'Get in Touch', 'wp-ai-agent' )         => array( 'get in touch', 'getintouch' ),
        __( 'Contact Us', 'wp-ai-agent' )           => array( 'contact us', 'contact', 'contactus', 'enquiry', 'inquiry', 'enquiries', 'reach us' ),
    );

    // Skip WooCommerce functional pages (My Account / Cart / Checkout / Shop).
    $exclude_ids = array();
    if ( function_exists( 'wc_get_page_id' ) ) {
        foreach ( array( 'myaccount', 'cart', 'checkout', 'shop' ) as $wc_page ) {
            $pid = (int) wc_get_page_id( $wc_page );
            if ( $pid > 0 ) {
                $exclude_ids[] = $pid;
            }
        }
    }

    foreach ( $pages as $page ) {
        if ( in_array( (int) $page->ID, $exclude_ids, true ) ) {
            continue;
        }

        $content  = (string) $page->post_content;
        $has_form = false;
        foreach ( $markers as $mk ) {
            if ( false !== stripos( $content, $mk ) ) {
                $has_form = true;
                break;
            }
        }
        // Elementor Pro forms live in page meta, not post_content.
        if ( ! $has_form ) {
            $el = get_post_meta( $page->ID, '_elementor_data', true );
            if ( is_string( $el ) && '' !== $el && false !== stripos( $el, '"widgettype":"form"' ) ) {
                $has_form = true;
            }
        }
        // A raw HTML form that has an email field.
        if ( ! $has_form && preg_match( '/<form[\s\S]{0,4000}?type=["\']email["\']/i', $content ) ) {
            $has_form = true;
        }

        $hay        = strtolower( get_the_title( $page ) . ' ' . $page->post_name );
        $type_label = '';
        foreach ( $title_map as $label => $cues ) {
            foreach ( $cues as $cue ) {
                if ( false !== strpos( $hay, $cue ) ) {
                    $type_label = $label;
                    break 2;
                }
            }
        }
        $title_match = ( '' !== $type_label );

        if ( $has_form || $title_match ) {
            $forms[] = array(
                'id'       => (int) $page->ID,
                'title'    => get_the_title( $page ),
                'type'     => '' !== $type_label ? $type_label : get_the_title( $page ),
                'url'      => get_permalink( $page->ID ),
                'has_form' => $has_form,
                // Real detected forms rank above title-only matches; a contact-named
                // page WITH a form is the strongest candidate.
                'rank'     => ( $has_form ? 2 : 0 ) + ( $title_match ? 1 : 0 ),
            );
        }
    }

    // Strongest candidates first; de-duplicate by URL; keep a handful.
    usort( $forms, function ( $a, $b ) {
        return $b['rank'] - $a['rank'];
    } );
    $seen   = array();
    $unique = array();
    foreach ( $forms as $f ) {
        if ( isset( $seen[ $f['url'] ] ) ) {
            continue;
        }
        $seen[ $f['url'] ] = true;
        $unique[]          = $f;
    }
    $unique = array_slice( $unique, 0, 4 );

    set_transient( 'wp_ai_agent_contact_forms', $unique, 12 * HOUR_IN_SECONDS );
    return apply_filters( 'wp_ai_agent_contact_forms', $unique );
}

/**
 * Build the "use our contact form" response (single or multiple forms), with a
 * tappable button per form. When a form sits on the CURRENT page, the button
 * scrolls to it instead of navigating away (keeping the chat open). In 'both'
 * mode a "share your details here" option is added so the visitor can still opt
 * into in-chat lead collection.
 *
 * @param array[] $forms     Discovered forms.
 * @param string  $mode      'form' | 'both'.
 * @param string  $page_url  The page the visitor is currently on.
 * @return array Tool response.
 */
function wp_ai_agent_lead_form_response( $forms, $mode, $page_url = '' ) {
    $current_path = '';
    if ( '' !== (string) $page_url ) {
        $current_path = untrailingslashit( (string) wp_parse_url( $page_url, PHP_URL_PATH ) );
    }

    $actions = array();
    foreach ( $forms as $f ) {
        $form_path = untrailingslashit( (string) wp_parse_url( $f['url'], PHP_URL_PATH ) );
        $on_page   = ( '' !== $current_path && $form_path === $current_path );
        // Prefix the label with a document icon; multi-form uses the type label.
        $label = ( count( $forms ) > 1 ) ? $f['type'] : __( 'Open Contact Form', 'wp-ai-agent' );
        $action = array(
            'label'    => '📄 ' . $label,
            'url'      => $f['url'],
            'same_tab' => true, // a contact page is the destination, not an aside.
        );
        if ( $on_page ) {
            // Same page → scroll to the form instead of reloading.
            $action['scroll'] = true;
        }
        $actions[] = $action;
    }

    if ( count( $forms ) > 1 ) {
        $message = __( "I'd be happy to help. We have a few ways to get in touch — pick the one that fits:", 'wp-ai-agent' );
    } else {
        $message = __( "I'd be happy to help. You can submit your enquiry using our contact form — just tap below to continue.", 'wp-ai-agent' );
    }

    // 'both' mode also lets the visitor share details right here in the chat.
    if ( 'both' === $mode ) {
        $actions[] = array(
            'label' => '💬 ' . __( 'Or share your details here', 'wp-ai-agent' ),
            // Phrase chosen so intent detection routes it back to lead_generation
            // AND wp_ai_agent_lead_wants_ai_collection() recognises the bypass.
            'query' => __( 'I want to leave my details here in the chat', 'wp-ai-agent' ),
        );
    }

    return wp_ai_agent_tool_response(
        $message,
        array(
            'source' => 'lead',
            'intent' => 'lead_generation',
            'data'   => array( 'actions' => $actions ),
        )
    );
}

/* -------------------------------------------------------------------------
 * Appointment Booking tool (date -> slot -> name -> email -> phone).
 * ---------------------------------------------------------------------- */

/**
 * Booking lifecycle statuses.
 *
 * @return array<string,string>
 */
function wp_ai_agent_booking_statuses() {
    return array(
        'pending'   => __( 'Pending', 'wp-ai-agent' ),
        'confirmed' => __( 'Confirmed', 'wp-ai-agent' ),
        'completed' => __( 'Completed', 'wp-ai-agent' ),
        'cancelled' => __( 'Cancelled', 'wp-ai-agent' ),
    );
}

/**
 * The bookable time slots (filterable).
 *
 * @return string[]
 */
function wp_ai_agent_booking_slots() {
    return apply_filters( 'wp_ai_agent_booking_slots', array( '10:00 AM', '11:00 AM', '2:00 PM', '4:00 PM' ) );
}

/**
 * Selectable booking dates (next N days) as {label, value(Y-m-d)} options.
 *
 * @param int $count Number of days to offer.
 * @return array[]
 */
function wp_ai_agent_booking_date_options( $count = 6 ) {
    $now  = current_time( 'timestamp' );
    $opts = array();
    for ( $i = 0; $i < $count; $i++ ) {
        $ts = $now + $i * DAY_IN_SECONDS;
        if ( 0 === $i ) {
            $label = __( 'Today', 'wp-ai-agent' );
        } elseif ( 1 === $i ) {
            $label = __( 'Tomorrow', 'wp-ai-agent' );
        } else {
            $label = date_i18n( 'D, M j', $ts );
        }
        $opts[] = array( 'label' => $label, 'value' => gmdate( 'Y-m-d', $ts ) );
    }
    return $opts;
}

/**
 * Slots still available on a date (configured slots minus non-cancelled
 * bookings already taken).
 *
 * @param string $date Y-m-d.
 * @return string[]
 */
function wp_ai_agent_available_slots( $date ) {
    $all = wp_ai_agent_booking_slots();

    global $wpdb;
    $table = wp_ai_agent_bookings_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return $all;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $booked = $wpdb->get_col( $wpdb->prepare( "SELECT booking_time FROM {$table} WHERE booking_date = %s AND status <> 'cancelled'", $date ) );

    return array_values( array_diff( $all, (array) $booked ) );
}

/**
 * Human-friendly date label for a Y-m-d string.
 *
 * @param string $date Y-m-d.
 * @return string
 */
function wp_ai_agent_booking_display_date( $date ) {
    $ts = strtotime( $date );
    return $ts ? date_i18n( 'D, M j, Y', $ts ) : $date;
}

/**
 * Build clickable date / slot options as reply-action buttons.
 *
 * @param array[] $options { label, value } list.
 * @return array[]
 */
function wp_ai_agent_booking_actions( $options ) {
    $actions = array();
    foreach ( $options as $o ) {
        $actions[] = array( 'label' => $o['label'], 'query' => $o['value'] );
    }
    return $actions;
}

/** @return int Inserted booking id, or 0. */
function wp_ai_agent_save_booking( $data, $session_id ) {
    global $wpdb;
    $table = wp_ai_agent_bookings_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        wp_ai_agent_create_agent_tables();
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert( $table, array(
        'name'         => sanitize_text_field( $data['name'] ?? '' ),
        'email'        => sanitize_email( $data['email'] ?? '' ),
        'phone'        => sanitize_text_field( $data['phone'] ?? '' ),
        'service'      => sanitize_text_field( $data['service'] ?? '' ),
        'booking_date' => sanitize_text_field( $data['date'] ?? '' ),
        'booking_time' => sanitize_text_field( $data['time'] ?? '' ),
        'notes'        => sanitize_textarea_field( $data['notes'] ?? '' ),
        'status'       => 'pending',
        'session_id'   => substr( (string) $session_id, 0, 64 ),
        'created_at'   => current_time( 'mysql' ),
    ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
    return (int) $wpdb->insert_id;
}

/**
 * Update a booking's status.
 *
 * @param int    $id     Booking id.
 * @param string $status One of wp_ai_agent_booking_statuses() keys.
 * @return bool
 */
function wp_ai_agent_update_booking_status( $id, $status ) {
    global $wpdb;
    if ( ! array_key_exists( $status, wp_ai_agent_booking_statuses() ) ) {
        return false;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return false !== $wpdb->update( wp_ai_agent_bookings_table(), array( 'status' => $status ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
}

/**
 * Query bookings with search, status filter, and pagination (admin dashboard).
 *
 * @param array $args { search, status, per_page, page }.
 * @return array{rows:object[],total:int,pages:int,page:int}
 */
function wp_ai_agent_get_bookings( $args = array() ) {
    global $wpdb;
    $defaults = array( 'search' => '', 'status' => '', 'per_page' => 20, 'page' => 1 );
    $args     = wp_parse_args( $args, $defaults );
    $table    = wp_ai_agent_bookings_table();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return array( 'rows' => array(), 'total' => 0, 'pages' => 0, 'page' => 1 );
    }

    $where  = array( '1=1' );
    $params = array();
    if ( '' !== $args['search'] ) {
        $like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
        $params  = array_merge( $params, array( $like, $like, $like ) );
    }
    if ( '' !== $args['status'] && array_key_exists( $args['status'], wp_ai_agent_booking_statuses() ) ) {
        $where[]  = 'status = %s';
        $params[] = $args['status'];
    }
    $where_sql = implode( ' AND ', $where );

    $per_page = max( 1, (int) $args['per_page'] );
    $page     = max( 1, (int) $args['page'] );
    $offset   = ( $page - 1 ) * $per_page;

    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

    $data_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
    $data_params = array_merge( $params, array( $per_page, $offset ) );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ) );

    return array(
        'rows'  => $rows ? $rows : array(),
        'total' => $total,
        'pages' => (int) ceil( $total / $per_page ),
        'page'  => $page,
    );
}

/**
 * Total bookings (optionally within the analytics date window).
 *
 * @param array $filters Analytics filters (honors 'since').
 * @return int
 */
function wp_ai_agent_bookings_count( $filters = array() ) {
    global $wpdb;
    $table = wp_ai_agent_bookings_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return 0;
    }
    if ( ! empty( $filters['since'] ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $filters['since'] ) );
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

/**
 * Begin or continue an appointment booking: date → slot → name → email →
 * phone → create. Slots are shown as buttons and validated against availability.
 *
 * @param string $message    User message.
 * @param string $session_id Session id.
 * @param array  $data       Collected data so far.
 * @param array  $entities   Extracted entities.
 * @param bool   $starting   True on first invocation.
 * @return array
 */
function wp_ai_agent_tool_booking( $message, $session_id, $data, $entities, $starting ) {
    // Opportunistically capture email/phone whenever present.
    if ( '' !== $entities['phone'] && empty( $data['phone'] ) ) {
        $data['phone'] = $entities['phone'];
    }
    if ( '' !== $entities['email'] && empty( $data['email'] ) ) {
        $data['email'] = $entities['email'];
    }

    if ( ! $starting ) {
        $awaiting = isset( $data['awaiting'] ) ? $data['awaiting'] : '';

        if ( 'date' === $awaiting && empty( $data['date'] ) ) {
            // Validate: a real date, not in the past.
            $ts    = strtotime( $message );
            $today = strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) ) );
            if ( false === $ts ) {
                wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
                return wp_ai_agent_tool_response( __( "I couldn't read that date. Please pick one below:", 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'booking', 'data' => array( 'actions' => wp_ai_agent_booking_actions( wp_ai_agent_booking_date_options() ) ) ) );
            }
            if ( $ts < $today ) {
                wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
                return wp_ai_agent_tool_response( __( 'That date is in the past. Please choose an upcoming date:', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'booking', 'data' => array( 'actions' => wp_ai_agent_booking_actions( wp_ai_agent_booking_date_options() ) ) ) );
            }
            $data['date'] = gmdate( 'Y-m-d', $ts );
        } elseif ( 'time' === $awaiting && empty( $data['time'] ) ) {
            // Validate against the available slots for the chosen date.
            $avail = wp_ai_agent_available_slots( $data['date'] );
            $match = '';
            foreach ( $avail as $slot ) {
                if ( 0 === strcasecmp( $slot, trim( $message ) ) ) {
                    $match = $slot;
                    break;
                }
            }
            if ( '' === $match ) {
                wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
                return wp_ai_agent_tool_response( __( 'Please pick one of the available slots below:', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'booking', 'data' => array( 'actions' => wp_ai_agent_booking_actions( array_map( function ( $s ) {
                    return array( 'label' => $s, 'value' => $s );
                }, $avail ) ) ) ) );
            }
            $data['time'] = $match;
        } elseif ( 'name' === $awaiting && empty( $data['name'] ) ) {
            $data['name'] = sanitize_text_field( $message );
        } elseif ( 'phone' === $awaiting && empty( $data['phone'] ) ) {
            $data['phone'] = sanitize_text_field( $message );
        }
        // email is captured opportunistically above.
    }

    // Step 1: date (with selectable buttons).
    if ( empty( $data['date'] ) ) {
        $data['awaiting'] = 'date';
        wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
        return wp_ai_agent_tool_response( __( 'I can set up an appointment. Please select a date:', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'booking', 'data' => array( 'actions' => wp_ai_agent_booking_actions( wp_ai_agent_booking_date_options() ) ) ) );
    }

    // Step 2 & 3: show available slots and let them pick one.
    if ( empty( $data['time'] ) ) {
        $avail = wp_ai_agent_available_slots( $data['date'] );
        if ( empty( $avail ) ) {
            unset( $data['date'] );
            $data['awaiting'] = 'date';
            wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
            return wp_ai_agent_tool_response( __( 'Sorry, no slots are available on that date. Please choose another date:', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'booking', 'data' => array( 'actions' => wp_ai_agent_booking_actions( wp_ai_agent_booking_date_options() ) ) ) );
        }
        $data['awaiting'] = 'time';
        wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
        $slot_actions = wp_ai_agent_booking_actions( array_map( function ( $s ) {
            return array( 'label' => $s, 'value' => $s );
        }, $avail ) );
        return wp_ai_agent_tool_response(
            sprintf( __( 'Available slots for %s — please select a time:', 'wp-ai-agent' ), wp_ai_agent_booking_display_date( $data['date'] ) ),
            array( 'pending' => true, 'intent' => 'booking', 'data' => array( 'actions' => $slot_actions ) )
        );
    }

    // Step 4: contact details.
    if ( empty( $data['name'] ) ) {
        $data['awaiting'] = 'name';
        wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
        return wp_ai_agent_tool_response( __( 'Great choice! What is your name for the booking?', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'booking' ) );
    }
    if ( empty( $data['email'] ) ) {
        $data['awaiting'] = 'email';
        wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
        return wp_ai_agent_tool_response( sprintf( __( 'Thanks, %s! What email should we send the confirmation to?', 'wp-ai-agent' ), $data['name'] ), array( 'pending' => true, 'intent' => 'booking' ) );
    }
    if ( empty( $data['phone'] ) ) {
        $data['awaiting'] = 'phone';
        wp_ai_agent_set_state( $session_id, 'booking', 'collect', $data );
        return wp_ai_agent_tool_response( __( 'Lastly, a contact number to confirm the booking?', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'booking' ) );
    }

    // Step 5: create the booking + notify.
    wp_ai_agent_clear_state( $session_id );
    $booking_id = wp_ai_agent_save_booking( $data, $session_id );
    $when       = wp_ai_agent_booking_display_date( $data['date'] ) . ' ' . $data['time'];

    // Admin email.
    wp_ai_agent_notify_admin(
        __( 'New appointment booking from AI Agent', 'wp-ai-agent' ),
        sprintf( "Name: %s\nEmail: %s\nPhone: %s\nWhen: %s\nStatus: Pending", $data['name'], $data['email'], $data['phone'], $when )
    );

    // User confirmation email.
    if ( is_email( $data['email'] ) ) {
        $business = wp_ai_agent_option( 'business_name', get_bloginfo( 'name' ) );
        wp_mail(
            $data['email'],
            sprintf( __( 'Your appointment request — %s', 'wp-ai-agent' ), $business ),
            sprintf( __( "Hi %1\$s,\n\nWe've received your appointment request for %2\$s.\nStatus: Pending confirmation.\n\nOur team will confirm shortly.\n\n%3\$s", 'wp-ai-agent' ), $data['name'], $when, $business )
        );
    }

    /**
     * Fires after a booking is created. Hook this for calendar / meeting
     * integrations (Calendly, Google Calendar, Outlook, Zoom).
     *
     * @param int   $booking_id New booking id.
     * @param array $data       Collected booking data (date/time/name/email/phone).
     */
    do_action( 'wp_ai_agent_booking_created', $booking_id, $data );

    return wp_ai_agent_tool_response(
        sprintf( __( 'Thank you, %1$s! Your appointment for %2$s is booked (Pending confirmation). A confirmation email is on its way. ✅', 'wp-ai-agent' ), $data['name'], $when ),
        array( 'source' => 'booking', 'intent' => 'booking', 'data' => array( 'booking_id' => $booking_id ) )
    );
}

/* -------------------------------------------------------------------------
 * Support ticket tool (multi-step: issue -> email -> create).
 * ---------------------------------------------------------------------- */

/** @return string A unique ticket number. */
function wp_ai_agent_generate_ticket_number() {
    return 'TK' . gmdate( 'ymd' ) . strtoupper( substr( wp_generate_password( 6, false, false ), 0, 5 ) );
}

/** @return int Inserted ticket id, or 0. */
function wp_ai_agent_save_ticket( $data, $session_id ) {
    global $wpdb;
    $table = wp_ai_agent_tickets_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        wp_ai_agent_create_agent_tables();
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert( $table, array(
        'ticket_number' => $data['ticket_number'],
        'name'          => sanitize_text_field( $data['name'] ?? '' ),
        'email'         => sanitize_email( $data['email'] ?? '' ),
        'subject'       => sanitize_text_field( $data['subject'] ?? '' ),
        'message'       => sanitize_textarea_field( $data['message'] ?? '' ),
        'status'        => 'open',
        'session_id'    => substr( (string) $session_id, 0, 64 ),
        'created_at'    => current_time( 'mysql' ),
    ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
    return (int) $wpdb->insert_id;
}

/**
 * Begin or continue a support ticket.
 *
 * @param string $message    User message.
 * @param string $session_id Session id.
 * @param array  $data       Collected data so far.
 * @param array  $entities   Extracted entities.
 * @param bool   $starting   True on first invocation.
 * @return array
 */
function wp_ai_agent_tool_ticket( $message, $session_id, $data, $entities, $starting ) {
    if ( '' !== $entities['email'] && empty( $data['email'] ) ) {
        $data['email'] = $entities['email'];
    }

    if ( $starting ) {
        // The opening message is the complaint itself.
        $data['message'] = $message;
        $data['subject'] = wp_trim_words( $message, 10, '' );
    } elseif ( ( $data['awaiting'] ?? '' ) === 'email' && empty( $data['email'] ) && '' !== $entities['email'] ) {
        $data['email'] = $entities['email'];
    } elseif ( ( $data['awaiting'] ?? '' ) === 'message' && empty( $data['message'] ) ) {
        $data['message'] = $message;
        $data['subject'] = wp_trim_words( $message, 10, '' );
    }

    if ( empty( $data['message'] ) ) {
        $data['awaiting'] = 'message';
        wp_ai_agent_set_state( $session_id, 'ticket', 'collect', $data );
        return wp_ai_agent_tool_response( __( "I'm sorry to hear that. Please describe the issue in a bit more detail.", 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'support_request' ) );
    }
    if ( empty( $data['email'] ) ) {
        $data['awaiting'] = 'email';
        wp_ai_agent_set_state( $session_id, 'ticket', 'collect', $data );
        return wp_ai_agent_tool_response( __( 'Thanks. What email should we use to update you on this?', 'wp-ai-agent' ), array( 'pending' => true, 'intent' => 'support_request' ) );
    }

    // Complete — create the ticket.
    wp_ai_agent_clear_state( $session_id );
    $data['ticket_number'] = wp_ai_agent_generate_ticket_number();
    wp_ai_agent_save_ticket( $data, $session_id );
    wp_ai_agent_notify_admin(
        sprintf( __( 'New support ticket %s', 'wp-ai-agent' ), $data['ticket_number'] ),
        sprintf( "Ticket: %s\nEmail: %s\nIssue: %s", $data['ticket_number'], $data['email'], $data['message'] )
    );

    return wp_ai_agent_tool_response(
        sprintf( __( 'Your support ticket has been created. Reference number: %s. Our team will get back to you by email. ✅', 'wp-ai-agent' ), $data['ticket_number'] ),
        array( 'source' => 'ticket', 'intent' => 'support_request' )
    );
}

/* -------------------------------------------------------------------------
 * Human handoff (WhatsApp) tool.
 * ---------------------------------------------------------------------- */

/**
 * Build the WhatsApp deep link with a prefilled support message that includes
 * the visitor's query. Returns '' when no WhatsApp number is configured.
 *
 * @param string $query The visitor's question/message.
 * @return string
 */
function wp_ai_agent_whatsapp_url( $query ) {
    $number = preg_replace( '/[^0-9]/', '', (string) wp_ai_agent_option( 'whatsapp_number', '' ) );
    if ( '' === $number ) {
        return '';
    }

    $template = wp_ai_agent_option( 'whatsapp_default_message', __( 'Hello, I need support regarding:', 'wp-ai-agent' ) );
    $text     = trim( $template );
    $query    = trim( wp_strip_all_tags( (string) $query ) );
    if ( '' !== $query ) {
        $text .= "\n" . $query;
    }

    return 'https://wa.me/' . $number . '?text=' . rawurlencode( $text );
}

/**
 * Record a handoff event ("shown" when offered, "click" when the WhatsApp
 * button is used) for analytics + conversation logging.
 *
 * @param string $event      'shown' | 'click'.
 * @param string $session_id Visitor session id.
 * @param string $page_url   Page URL.
 * @param string $query      The visitor's query.
 * @return void
 */
function wp_ai_agent_log_handoff( $event, $session_id, $page_url, $query ) {
    global $wpdb;
    $table = wp_ai_agent_handoffs_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        wp_ai_agent_create_agent_tables();
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert( $table, array(
        'event'      => ( 'click' === $event ) ? 'click' : 'shown',
        'query'      => sanitize_textarea_field( (string) $query ),
        'session_id' => substr( (string) $session_id, 0, 64 ),
        'page_url'   => esc_url_raw( (string) $page_url ),
        'created_at' => current_time( 'mysql' ),
    ), array( '%s', '%s', '%s', '%s', '%s' ) );
}

/**
 * Handoff stats for the analytics dashboard.
 *
 * @param array $filters Analytics filters (honors 'since').
 * @return array{shown:int,clicks:int}
 */
function wp_ai_agent_handoff_stats( $filters = array() ) {
    global $wpdb;
    $out   = array( 'shown' => 0, 'clicks' => 0 );
    $table = wp_ai_agent_handoffs_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return $out;
    }

    $since   = ( ! empty( $filters['since'] ) ) ? $filters['since'] : '';
    $where   = $since ? ' AND created_at >= %s' : '';
    $shown_q = "SELECT COUNT(*) FROM {$table} WHERE event = 'shown'" . $where;
    $click_q = "SELECT COUNT(*) FROM {$table} WHERE event = 'click'" . $where;

    if ( $since ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $out['shown']  = (int) $wpdb->get_var( $wpdb->prepare( $shown_q, $since ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $out['clicks'] = (int) $wpdb->get_var( $wpdb->prepare( $click_q, $since ) );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $out['shown']  = (int) $wpdb->get_var( $shown_q );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $out['clicks'] = (int) $wpdb->get_var( $click_q );
    }

    return $out;
}

/**
 * Offer a WhatsApp human handoff with a "Continue on WhatsApp" button. Falls
 * back to the admin email when no WhatsApp number is configured. Logs the
 * handoff for analytics.
 *
 * @param string $message    The visitor's query (prefilled into WhatsApp).
 * @param string $session_id Session id.
 * @param string $page_url   Page URL.
 * @param string $intro      Optional lead-in line (used by the low-confidence path).
 * @return array
 */
function wp_ai_agent_tool_human( $message, $session_id = '', $page_url = '', $intro = '' ) {
    $url      = wp_ai_agent_whatsapp_url( $message );
    $business = wp_ai_agent_option( 'business_name', get_bloginfo( 'name' ) );

    if ( '' !== $url ) {
        wp_ai_agent_log_handoff( 'shown', $session_id, $page_url, $message );

        if ( '' !== $intro ) {
            $msg = $intro;
        } elseif ( $business ) {
            $msg = sprintf( __( 'I can connect you with the %s support team.', 'wp-ai-agent' ), $business );
        } else {
            $msg = __( 'I can connect you with our support team.', 'wp-ai-agent' );
        }

        return wp_ai_agent_tool_response( $msg, array(
            'source' => 'human',
            'intent' => 'human_support',
            'data'   => array(
                'actions' => array(
                    array(
                        'label' => __( '💬 Continue on WhatsApp', 'wp-ai-agent' ),
                        'url'   => $url,
                        'track' => 'handoff',
                        'query' => $message,
                    ),
                ),
            ),
        ) );
    }

    // Fallback: no WhatsApp number — point them to the admin email.
    $email = get_option( 'admin_email' );
    return wp_ai_agent_tool_response(
        sprintf( __( "I'll connect you with our team. Please reach us at %s and we'll help you right away.", 'wp-ai-agent' ), $email ),
        array( 'source' => 'human', 'intent' => 'human_support' )
    );
}

/* -------------------------------------------------------------------------
 * Information tools (navigation / contact / faq / website info) — reuse the
 * existing website-content retrieval + AI engine, which already search first.
 * ---------------------------------------------------------------------- */

/**
 * Navigation tool: find the page(s) the visitor is asking for (refund/return
 * policy, privacy, terms, shipping, about, contact, etc.) and return their
 * titles + clickable URLs, with a short summary. Returns null when no page
 * matches (so the caller can fall back to general content search).
 *
 * @param string $message User message.
 * @return array|null
 */
function wp_ai_agent_tool_navigation( $message ) {
    $tokens = function_exists( 'wp_ai_agent_tokenize_query' ) ? wp_ai_agent_tokenize_query( $message ) : array();
    // Add common navigation words even if tokenizer dropped them.
    $extra = array();
    foreach ( array( 'policy', 'refund', 'return', 'privacy', 'terms', 'shipping', 'delivery', 'contact', 'about', 'faq', 'cancellation', 'exchange' ) as $w ) {
        if ( false !== stripos( $message, $w ) ) {
            $extra[] = $w;
        }
    }
    $tokens = array_values( array_unique( array_merge( $tokens, $extra ) ) );
    if ( empty( $tokens ) ) {
        return null;
    }

    $pages = get_posts( array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
    ) );
    if ( empty( $pages ) ) {
        return null;
    }

    // WooCommerce functional pages (My Account, Cart, Checkout, Shop) are not
    // informational — never surface them for a policy/info lookup.
    $exclude_ids = array();
    if ( function_exists( 'wc_get_page_id' ) ) {
        foreach ( array( 'myaccount', 'cart', 'checkout', 'shop' ) as $wc_page ) {
            $pid = (int) wc_get_page_id( $wc_page );
            if ( $pid > 0 ) {
                $exclude_ids[] = $pid;
            }
        }
    }

    $scored = array();
    foreach ( $pages as $page ) {
        if ( in_array( (int) $page->ID, $exclude_ids, true ) ) {
            continue;
        }

        // Readable text with shortcodes/blocks removed. A page whose content is
        // ONLY shortcodes (e.g. "Shipping Addresses" → [woocommerce_account_addresses])
        // is a functional page, not an answer — skip it so raw [shortcodes] are
        // never shown. Builder pages (empty post_content) are still allowed.
        $raw_content = (string) $page->post_content;
        $readable    = trim( wp_strip_all_tags( strip_shortcodes( $raw_content ) ) );
        $had_text    = ( '' !== trim( wp_strip_all_tags( $raw_content ) ) );
        if ( '' === $readable && $had_text ) {
            continue;
        }

        $title_hay = ' ' . strtolower( get_the_title( $page ) ) . ' ';
        $score     = 0;
        foreach ( $tokens as $t ) {
            $needles = function_exists( 'wp_ai_agent_expand_token' ) ? wp_ai_agent_expand_token( $t ) : array( strtolower( $t ) );
            foreach ( $needles as $needle ) {
                if ( wp_ai_agent_term_match( $title_hay, $needle ) ) {
                    $score += 2; // title matches weigh most.
                    break;
                }
            }
        }
        if ( $score > 0 ) {
            $scored[ $page->ID ] = array( 'page' => $page, 'score' => $score, 'readable' => $readable );
        }
    }

    if ( empty( $scored ) ) {
        return null;
    }

    usort( $scored, function ( $a, $b ) {
        return ( $a['score'] === $b['score'] ) ? 0 : ( ( $a['score'] < $b['score'] ) ? 1 : -1 );
    } );
    $scored = array_slice( $scored, 0, 3 );

    $lines = array( __( 'Here is what you are looking for:', 'wp-ai-agent' ), '' );
    foreach ( $scored as $row ) {
        $page    = $row['page'];
        // Shortcode-free excerpt (never shows raw [shortcodes]).
        $excerpt = wp_trim_words( isset( $row['readable'] ) ? $row['readable'] : wp_strip_all_tags( strip_shortcodes( (string) $page->post_content ) ), 25, '…' );
        // Order: Title → description → link. The URL goes LAST so the "View"
        // button always renders at the END of the item (not between the title
        // and the text).
        $lines[] = get_the_title( $page );
        if ( '' !== trim( $excerpt ) ) {
            $lines[] = $excerpt;
        }
        $lines[] = get_permalink( $page->ID );
        $lines[] = '';
    }

    return wp_ai_agent_tool_response( trim( implode( "\n", $lines ) ), array( 'source' => 'navigation', 'intent' => 'navigation' ) );
}

/* -------------------------------------------------------------------------
 * Category discovery.
 * ---------------------------------------------------------------------- */

/**
 * Light singular form used for matching category names (e.g. "socks"→"sock",
 * "mens"→"men"). Strips a trailing "s" for words of 4+ letters, but never on
 * "ss" endings ("dress" stays "dress").
 *
 * @param string $word Word.
 * @return string
 */
function wp_ai_agent_cat_singular( $word ) {
    $word = strtolower( trim( (string) $word ) );
    if ( strlen( $word ) >= 4 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
        return substr( $word, 0, -1 );
    }
    return $word;
}

/**
 * Extract the "scope" a category-discovery question is narrowed to — the parent
 * section named in the message (e.g. "for Men", "under Accessories", "Men's
 * categories"). Returns '' for an unscoped "what categories do you have".
 *
 * Purely structural (no hardcoded category names) so it adapts to any store.
 *
 * @param string $message User message.
 * @return string Scope phrase (lower-case), or '' when none.
 */
function wp_ai_agent_category_scope( $message ) {
    $m    = strtolower( trim( (string) $message ) );
    $cand = '';

    // "…for/under/in/within/of <X> [categories]?" — X is the scope.
    if ( preg_match( '/\b(?:for|under|in|within|inside|of|from)\s+(?:the\s+)?([a-z0-9][a-z0-9 &\'\-]{1,40}?)(?:\s+(?:categor(?:y|ies)|collections?|sections?|departments?|range|please))?\s*[?.!]*$/i', $m, $mm ) ) {
        $cand = $mm[1];
    // "men's categories" / "womens collection" — possessive before the keyword.
    } elseif ( preg_match( '/\b([a-z][a-z0-9 &\'\-]{1,40}?)(?:\'s|s\'|s)?\s+(?:categor(?:y|ies)|collections?|sections?|departments?)\b/i', $m, $mm ) ) {
        $cand = $mm[1];
    }

    $cand = trim( (string) $cand );
    // Drop leading question/filler words the patterns may have captured. The
    // trailing group allows a whitespace OR end-of-string so a lone "what" (as in
    // "what categories do you have") is stripped too — not just "what men".
    $cand = preg_replace( '/^(?:what\'?s|what|which|whichever|show me|show|list|tell me|the|all|available|your|our|do you have|do you|does|are there|are|is|me|any|some|other|more|kinds? of|sorts? of|types? of)(?:\s+|$)/i', '', $cand );
    $cand = trim( $cand );

    // Generic words that really mean "all categories" — treat as unscoped. Also
    // covers lone question/stop words that a pattern may have captured on their own.
    $generic = array(
        '', 'category', 'categories', 'collection', 'collections', 'section',
        'sections', 'department', 'departments', 'product', 'products', 'type',
        'types', 'item', 'items', 'thing', 'things', 'goods', 'gear', 'range',
        'store', 'shop', 'website', 'site', 'you', 'u', 'offer', 'sell', 'have',
        'stock', 'carry', 'available',
        'what', 'whats', 'which', 'any', 'some', 'do', 'does', 'there', 'here',
        'kind', 'kinds', 'sort', 'sorts', 'name', 'names', 'list', 'main', 'top',
    );
    if ( in_array( $cand, $generic, true ) ) {
        return '';
    }
    return $cand;
}

/**
 * Resolve a scope phrase to the best-matching product_cat term (or null). Uses
 * exact → singular/plural → prefix → whole-word → substring scoring so gendered
 * sections disambiguate correctly ("men" → Mens, not Womens). No hardcoding.
 *
 * @param string $scope Scope phrase.
 * @return WP_Term|null
 */
function wp_ai_agent_find_category_term( $scope ) {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return null;
    }
    $x = strtolower( trim( (string) $scope ) );
    if ( '' === $x ) {
        return null;
    }
    $xs    = wp_ai_agent_cat_singular( $x );
    $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return null;
    }

    $best = null;
    $best_score = 0;
    foreach ( $terms as $t ) {
        if ( 0 === strcasecmp( $t->name, 'Uncategorized' ) ) {
            continue;
        }
        $n     = strtolower( $t->name );
        $s     = strtolower( str_replace( '-', ' ', $t->slug ) );
        $ns    = wp_ai_agent_cat_singular( $n );
        $ss    = wp_ai_agent_cat_singular( $s );
        $score = 0;
        if ( $n === $x || $s === $x ) {
            $score = 100;
        } elseif ( $ns === $xs || $ss === $xs ) {
            $score = 90;
        } elseif ( 0 === strpos( $n, $x ) || 0 === strpos( $x, $n ) ) {
            $score = 70;
        } elseif ( false !== strpos( ' ' . $n . ' ', ' ' . $x . ' ' ) ) {
            $score = 60;
        } elseif ( strlen( $x ) >= 4 && ( false !== strpos( $n, $x ) || false !== strpos( $x, $n ) ) ) {
            $score = 40;
        }
        // Prefer a parent (section) term over a leaf on a tie.
        if ( $score > 0 && $score === $best_score && $best && (int) $t->parent === 0 && (int) $best->parent !== 0 ) {
            $best = $t;
        }
        if ( $score > $best_score ) {
            $best_score = $score;
            $best       = $t;
        }
    }
    return ( $best_score >= 40 ) ? $best : null;
}

/**
 * Category discovery tool: show the store's CATEGORIES (never products). If the
 * question names a section (Men, Women, Accessories…), list that section's
 * sub-categories; otherwise list the top-level categories. Each is a tappable
 * button — a parent drills into its sub-categories, a leaf shows its products —
 * so the customer is guided step by step (categories → then products).
 *
 * Categories are read live from the product_cat taxonomy: no hardcoding, adapts
 * to any WooCommerce store. Returns null when the site has no product categories.
 *
 * @param string $message User message.
 * @return array|null
 */
function wp_ai_agent_tool_categories( $message ) {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return null;
    }

    $get_children = function ( $parent_id ) {
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => (int) $parent_id,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }
        return array_values( array_filter( $terms, function ( $t ) {
            return 0 !== strcasecmp( $t->name, 'Uncategorized' );
        } ) );
    };

    $scope      = wp_ai_agent_category_scope( $message );
    $scope_term = ( '' !== $scope ) ? wp_ai_agent_find_category_term( $scope ) : null;
    $not_found  = ( '' !== $scope && ! $scope_term );

    $parent_id = $scope_term ? (int) $scope_term->term_id : 0;
    $list      = $get_children( $parent_id );

    // Named section that has NO sub-categories → it's a shoppable leaf category.
    if ( $scope_term && empty( $list ) ) {
        return wp_ai_agent_tool_response(
            sprintf(
                /* translators: %s: category name. */
                __( '“%s” is one of our categories — it has no sub-categories, but I can show you what\'s inside. 😊', 'wp-ai-agent' ),
                $scope_term->name
            ),
            array( 'source' => 'woocommerce', 'intent' => 'category_discovery', 'data' => array( 'actions' => array(
                array(
                    /* translators: %s: category name. */
                    'label' => sprintf( __( '🛍️ Show %s', 'wp-ai-agent' ), $scope_term->name ),
                    'query' => 'show me ' . $scope_term->name,
                ),
            ) ) )
        );
    }

    // No categories at all on the store.
    if ( empty( $list ) ) {
        return wp_ai_agent_tool_response(
            __( "We don't have product categories set up on this website yet.", 'wp-ai-agent' ),
            array( 'source' => 'woocommerce', 'intent' => 'category_discovery', 'matched' => false )
        );
    }

    // Heading.
    if ( $scope_term ) {
        /* translators: %s: parent section name. */
        $heading = sprintf( __( 'Here are the categories under %s:', 'wp-ai-agent' ), $scope_term->name );
    } elseif ( $not_found ) {
        /* translators: %s: what the visitor asked for. */
        $heading = sprintf( __( "I couldn't find a specific “%s” section, but here are our categories:", 'wp-ai-agent' ), trim( $scope ) );
    } else {
        $heading = __( 'Here are our product categories:', 'wp-ai-agent' );
    }

    // List rows: a parent drills into its sub-categories; a leaf shows its products.
    $max_buttons = (int) apply_filters( 'wp_ai_agent_category_buttons', 12 );
    $rows        = array();
    $names       = array();
    foreach ( array_slice( $list, 0, $max_buttons ) as $t ) {
        $kids    = $get_children( $t->term_id );
        $names[] = $t->name;
        $rows[]  = ! empty( $kids )
            ? array( 'label' => $t->name, 'query' => 'what categories are under ' . $t->name )
            : array( 'label' => $t->name, 'query' => 'show me ' . $t->name );
    }

    // Heading bubble. We ALSO list the category names inline (as a bullet list) so
    // the answer is always readable even where the tappable cards can't render —
    // the cards below are an enhancement, not the only way to see the categories.
    $bullets = '';
    foreach ( $names as $n ) {
        $bullets .= "\n• " . $n;
    }
    $msg  = $heading . $bullets . "\n\n";
    $msg .= __( 'Which one would you like to explore? Tap a category below. 😊', 'wp-ai-agent' );

    return wp_ai_agent_tool_response(
        $msg,
        array( 'source' => 'woocommerce', 'intent' => 'category_discovery', 'data' => array( 'list' => $rows ) )
    );
}

/**
 * Catalog stats tool: answer "how many products / categories / brands / posts",
 * and "which categories/collections do you have" — with accurate DB counts and
 * tappable category buttons. Returns null when it's not a stats question.
 *
 * @param string $message User message.
 * @return array|null
 */
function wp_ai_agent_tool_catalog( $message ) {
    $m = strtolower( $message );

    // Categories / collections (exact live count, all of them).
    if ( preg_match( '/\b(categor|collection)/', $m ) ) {
        $terms = taxonomy_exists( 'product_cat' ) ? get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) : array();
        $terms = is_wp_error( $terms ) ? array() : array_values( array_filter( $terms, function ( $t ) {
            return 0 !== strcasecmp( $t->name, 'Uncategorized' );
        } ) );
        $count = count( $terms );
        if ( 0 === $count ) {
            return wp_ai_agent_tool_response( __( "We don't have product categories set up yet.", 'wp-ai-agent' ), array( 'source' => 'catalog', 'intent' => 'catalog' ) );
        }
        $names = wp_list_pluck( array_slice( $terms, 0, 25 ), 'name' );
        $msg   = sprintf(
            /* translators: 1: count, 2: names. */
            _n( 'We have %1$d category: %2$s.', 'We have %1$d categories: %2$s.', $count, 'wp-ai-agent' ),
            $count,
            implode( ', ', $names )
        );
        $msg    .= "\n" . __( 'Tap one to explore:', 'wp-ai-agent' );
        $actions = array();
        foreach ( array_slice( $terms, 0, 6 ) as $t ) {
            $actions[] = array( 'label' => $t->name, 'query' => $t->name );
        }
        return wp_ai_agent_tool_response( $msg, array( 'source' => 'catalog', 'intent' => 'catalog', 'data' => array( 'actions' => $actions ) ) );
    }

    // Brands.
    if ( preg_match( '/\bbrand/', $m ) ) {
        $brands = array();
        if ( function_exists( 'wp_ai_agent_brand_taxonomies' ) ) {
            foreach ( wp_ai_agent_brand_taxonomies() as $tax ) {
                if ( taxonomy_exists( $tax ) ) {
                    $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
                    if ( ! is_wp_error( $terms ) ) {
                        foreach ( $terms as $t ) {
                            $brands[ $t->name ] = true;
                        }
                    }
                }
            }
        }
        $names = array_keys( $brands );
        if ( empty( $names ) ) {
            return wp_ai_agent_tool_response( __( "We don't list brands separately on this website.", 'wp-ai-agent' ), array( 'source' => 'catalog', 'intent' => 'catalog' ) );
        }
        return wp_ai_agent_tool_response(
            sprintf( _n( 'We have %1$d brand: %2$s.', 'We have %1$d brands: %2$s.', count( $names ), 'wp-ai-agent' ), count( $names ), implode( ', ', $names ) ),
            array( 'source' => 'catalog', 'intent' => 'catalog' )
        );
    }

    // Services (if the site uses a service/services custom post type).
    if ( preg_match( '/\bservice/', $m ) ) {
        $cpt = post_type_exists( 'service' ) ? 'service' : ( post_type_exists( 'services' ) ? 'services' : '' );
        if ( '' !== $cpt ) {
            $n = wp_ai_agent_db_count_posts( $cpt );
            return wp_ai_agent_tool_response(
                sprintf( _n( 'We offer %d service.', 'We offer %d services.', $n, 'wp-ai-agent' ), $n ),
                array( 'source' => 'catalog', 'intent' => 'catalog' )
            );
        }
        return null; // No services CPT — let content search answer instead.
    }

    // Products.
    if ( preg_match( '/\b(product|item)/', $m ) ) {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return null;
        }
        $n      = wp_ai_agent_db_count_posts( 'product' );
        $msg    = sprintf( _n( 'We currently have %d product.', 'We currently have %d products.', $n, 'wp-ai-agent' ), $n );
        $msg   .= "\n" . __( 'Would you like to see them or browse by category?', 'wp-ai-agent' );
        return wp_ai_agent_tool_response( $msg, array(
            'source' => 'catalog',
            'intent' => 'catalog',
            'data'   => array( 'actions' => array(
                array( 'label' => __( 'Show products', 'wp-ai-agent' ), 'query' => 'show products' ),
                array( 'label' => __( 'Categories', 'wp-ai-agent' ), 'query' => 'what categories do you have' ),
            ) ),
        ) );
    }

    // Blog posts / articles.
    if ( preg_match( '/\b(post|blog|article)/', $m ) ) {
        $n = wp_ai_agent_db_count_posts( 'post' );
        return wp_ai_agent_tool_response(
            sprintf( _n( 'We have %d blog post published.', 'We have %d blog posts published.', $n, 'wp-ai-agent' ), $n ),
            array( 'source' => 'catalog', 'intent' => 'catalog' )
        );
    }

    // FAQs (live count from the trained-answers table).
    if ( preg_match( '/\bfaq/', $m ) ) {
        global $wpdb;
        $n = 0;
        if ( function_exists( 'wp_ai_agent_qa_table_name' ) ) {
            $table = wp_ai_agent_qa_table_name();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            }
        }
        return wp_ai_agent_tool_response(
            sprintf( _n( 'We have %d FAQ answer available.', 'We have %d FAQ answers available.', $n, 'wp-ai-agent' ), $n ),
            array( 'source' => 'catalog', 'intent' => 'catalog' )
        );
    }

    // Pages.
    if ( preg_match( '/\bpage/', $m ) ) {
        $n = wp_ai_agent_db_count_posts( 'page' );
        return wp_ai_agent_tool_response(
            sprintf( _n( 'We have %d page on the website.', 'We have %d pages on the website.', $n, 'wp-ai-agent' ), $n ),
            array( 'source' => 'catalog', 'intent' => 'catalog' )
        );
    }

    return null;
}

/**
 * Coupons tool: list currently ACTIVE WooCommerce coupons. Never shows products.
 *
 * @return array
 */
function wp_ai_agent_tool_coupons() {
    if ( ! post_type_exists( 'shop_coupon' ) || ! class_exists( 'WC_Coupon' ) ) {
        return wp_ai_agent_tool_response( __( "Coupons aren't available on this website.", 'wp-ai-agent' ), array( 'intent' => 'coupons', 'source' => 'coupons', 'matched' => false ) );
    }

    $posts  = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 100 ) );
    $now    = time();
    $active = array();
    foreach ( $posts as $post ) {
        $coupon = new WC_Coupon( $post->ID );
        $exp    = $coupon->get_date_expires();
        if ( $exp && $exp->getTimestamp() < $now ) {
            continue;
        }
        $limit = $coupon->get_usage_limit();
        if ( $limit && $coupon->get_usage_count() >= $limit ) {
            continue;
        }
        $active[] = $coupon;
    }

    if ( empty( $active ) ) {
        return wp_ai_agent_tool_response(
            __( 'Currently there are no active coupons or discount offers available on this website.', 'wp-ai-agent' ),
            array( 'intent' => 'coupons', 'source' => 'coupons', 'matched' => false )
        );
    }

    $lines = array( __( 'Here are the active coupons you can use:', 'wp-ai-agent' ), '' );
    foreach ( $active as $coupon ) {
        $amount = $coupon->get_amount();
        if ( 'percent' === $coupon->get_discount_type() ) {
            $disc = sprintf( '%s%% off', wc_format_decimal( $amount, '' ) );
        } else {
            $disc = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES ) . __( ' off', 'wp-ai-agent' );
        }
        $line = '• ' . strtoupper( $coupon->get_code() ) . ' — ' . $disc;
        $min  = $coupon->get_minimum_amount();
        if ( $min ) {
            $line .= sprintf( __( ' (min spend %s)', 'wp-ai-agent' ), html_entity_decode( wp_strip_all_tags( wc_price( $min ) ), ENT_QUOTES ) );
        }
        $exp = $coupon->get_date_expires();
        if ( $exp ) {
            $line .= sprintf( __( ', valid till %s', 'wp-ai-agent' ), date_i18n( get_option( 'date_format' ), $exp->getTimestamp() ) );
        }
        $lines[] = $line;
    }
    return wp_ai_agent_tool_response( implode( "\n", $lines ), array( 'intent' => 'coupons', 'source' => 'coupons' ) );
}

/**
 * Helpful next-step buttons for shipping/delivery replies (policy, contact,
 * WhatsApp) so the conversation always continues — and NEVER with products.
 *
 * @return array[]
 */
function wp_ai_agent_shipping_actions() {
    $actions = array(
        array( 'label' => __( '📄 Shipping policy', 'wp-ai-agent' ), 'query' => 'shipping policy' ),
        array( 'label' => __( '📞 Contact support', 'wp-ai-agent' ), 'query' => 'contact information' ),
    );
    $wa = function_exists( 'wp_ai_agent_whatsapp_url' ) ? wp_ai_agent_whatsapp_url( '' ) : '';
    if ( '' !== $wa ) {
        $actions[] = array( 'label' => __( '💬 Chat on WhatsApp', 'wp-ai-agent' ), 'url' => $wa, 'track' => 'handoff', 'query' => 'shipping' );
    }
    return $actions;
}

/**
 * Shipping & delivery tool. Answers any shipping / delivery / service-area
 * question from WooCommerce shipping zones + methods (with the regions served)
 * and the shipping policy page. Understands a requested delivery MODE (express /
 * same-day / next-day / international / pickup) and says clearly whether it is
 * offered. NEVER shows products — only shipping info and helpful next steps.
 *
 * @param string $message User message.
 * @return array
 */
function wp_ai_agent_tool_shipping( $message ) {
    $m       = strtolower( (string) $message );
    $actions = wp_ai_agent_shipping_actions();

    // Shipping questions about TIME, POLICY or a SCENARIO (how long, "what if I'm
    // not home", missed delivery, redelivery, damaged/lost parcel, change address,
    // safe place, signature…) are answered from the FAQ / Shipping Policy /
    // Delivery page — WooCommerce settings only list methods & costs. So for these
    // search the website content FIRST; only cost/method questions ("charges",
    // "how much", "free shipping", "shipping methods") go straight to the methods
    // below. Fall back to methods if nothing relevant is found.
    $is_cost_q = (bool) preg_match( '/\b(charges?|costs?|fees?|rates?|pricing|how much|free shipping|shipping (method|methods|option|options))\b/', $m );
    $is_faq_q  = (bool) preg_match( '/\b(how long|how many days?|how soon|when (will|do|does|can|is)|delivery time|shipping time|estimate|estimated|lead time|turnaround|what if|not (at )?home|missed|miss(ed)? (the|my)|re-?deliver\w*|delivery attempt|attempt(ed)? delivery|collect(ion)?|safe place|leave (it|the|with|my)|neighbou?r|signature|someone else|no ?one|nobody|damaged|broken|lost|undelivered|didn\'?t (arrive|receive|get|come)|hasn\'?t (arrived|come)|wrong address|change (my )?address|track)\b/', $m );
    if ( $is_faq_q && ! $is_cost_q && function_exists( 'wp_ai_agent_tool_information' ) ) {
        $info = wp_ai_agent_tool_information( $message, 'shipping' );
        if ( null !== $info ) {
            if ( empty( $info['data'] ) || empty( $info['data']['actions'] ) ) {
                $info['data']['actions'] = $actions;
            }
            return $info;
        }
    }

    // A specific delivery mode the visitor asked about (if any).
    $mode        = '';
    $mode_needle = '';
    if ( preg_match( '/\bexpress\b/', $m ) ) {
        $mode = __( 'express delivery', 'wp-ai-agent' );
        $mode_needle = 'express';
    } elseif ( preg_match( '/\bsame[- ]?day\b/', $m ) ) {
        $mode = __( 'same-day delivery', 'wp-ai-agent' );
        $mode_needle = 'same';
    } elseif ( preg_match( '/\b(next[- ]?day|overnight)\b/', $m ) ) {
        $mode = __( 'next-day delivery', 'wp-ai-agent' );
        $mode_needle = 'next';
    } elseif ( preg_match( '/\binternational\b/', $m ) ) {
        $mode = __( 'international shipping', 'wp-ai-agent' );
        $mode_needle = 'international';
    } elseif ( preg_match( '/\b(pick ?up|collect)\b/', $m ) ) {
        $mode = __( 'local pickup', 'wp-ai-agent' );
        $mode_needle = 'pick';
    }

    $free    = array();
    $methods = array();
    $regions = array();

    if ( class_exists( 'WC_Shipping_Zones' ) ) {
        $zones   = WC_Shipping_Zones::get_zones();
        $rest    = new WC_Shipping_Zone( 0 );
        $zones[] = array(
            'zone_name'               => $rest->get_zone_name(),
            'shipping_methods'        => $rest->get_shipping_methods(),
            'formatted_zone_location' => '',
        );

        foreach ( $zones as $zone ) {
            $zone_name = isset( $zone['zone_name'] ) ? $zone['zone_name'] : '';
            if ( ! empty( $zone['formatted_zone_location'] ) ) {
                $regions[] = $zone['formatted_zone_location'];
            }
            foreach ( (array) ( isset( $zone['shipping_methods'] ) ? $zone['shipping_methods'] : array() ) as $method ) {
                if ( ! $method->is_enabled() ) {
                    continue;
                }
                if ( 'free_shipping' === $method->id ) {
                    $min    = $method->get_option( 'min_amount' );
                    $free[] = $method->get_title() . ( $zone_name ? ' — ' . $zone_name : '' ) . ( $min ? sprintf( __( ' (min order %s)', 'wp-ai-agent' ), html_entity_decode( wp_strip_all_tags( wc_price( $min ) ), ENT_QUOTES ) ) : '' );
                } else {
                    $cost      = method_exists( $method, 'get_option' ) ? $method->get_option( 'cost' ) : '';
                    $methods[] = $method->get_title() . ( $zone_name ? ' — ' . $zone_name : '' ) . ( '' !== $cost && '0' !== (string) $cost ? ': ' . html_entity_decode( wp_strip_all_tags( wc_price( $cost ) ), ENT_QUOTES ) : '' );
                }
            }
        }
    }

    $all      = array_values( array_unique( array_merge( $free, $methods ) ) );
    $titles   = strtolower( implode( ' | ', $all ) );
    $regions  = array_values( array_unique( array_filter( $regions ) ) );
    $ships_to = ! empty( $regions ) ? "\n\n" . sprintf( __( 'We currently ship to: %s.', 'wp-ai-agent' ), implode( '; ', $regions ) ) : '';

    // "Which PRODUCTS have free shipping?" — a product-scoped free-shipping
    // question. WooCommerce free shipping is order-level (a minimum spend or a
    // coupon), not attached to individual products, so answer honestly: if a
    // free-shipping rule exists, explain that every product qualifies once the
    // order meets it; if none exists, say so plainly and offer the real options.
    // NEVER imply that specific products ship free.
    $asks_free_products = (bool) preg_match( '/\bfree\b/', $m )
        && preg_match( '/\b(ship|shipping|deliver|delivery)\b/', $m )
        && preg_match( '/\b(product|products|item|items|which|what|any|list|show|eligible|qualif)\w*/', $m );
    if ( $asks_free_products ) {
        if ( ! empty( $free ) ) {
            $msg = __( "Free shipping isn't tied to specific products — it applies to your whole order once it qualifies:", 'wp-ai-agent' )
                . "\n• " . implode( "\n• ", array_unique( $free ) )
                . "\n\n" . __( 'So every product ships free once your cart meets that condition. 😊', 'wp-ai-agent' )
                . ( ! empty( $methods ) ? "\n\n" . __( 'Otherwise, standard rates apply:', 'wp-ai-agent' ) . "\n• " . implode( "\n• ", array_unique( $methods ) ) : '' )
                . $ships_to;
            return wp_ai_agent_tool_response( $msg, array( 'intent' => 'shipping', 'source' => 'shipping', 'data' => array( 'actions' => $actions ) ) );
        }
        // No free shipping configured at all — be upfront, then offer the real
        // options (flat rate / express) and point to current deals.
        $msg = __( "We don't offer free shipping on individual products right now.", 'wp-ai-agent' );
        if ( ! empty( $methods ) ) {
            $msg .= ' ' . __( 'Here are the shipping options we do have:', 'wp-ai-agent' ) . "\n• " . implode( "\n• ", array_unique( $methods ) );
        }
        $msg .= "\n\n" . __( "We do run flat-rate shipping and regular discounts, though — want me to show you what's on sale?", 'wp-ai-agent' ) . $ships_to;
        $free_actions   = $actions;
        $free_actions[] = array( 'label' => __( '🏷️ See current deals', 'wp-ai-agent' ), 'query' => 'products on sale' );
        return wp_ai_agent_tool_response( $msg, array( 'intent' => 'shipping', 'source' => 'shipping', 'matched' => false, 'data' => array( 'actions' => $free_actions ) ) );
    }

    // A specific mode was asked about → answer whether it exists.
    if ( '' !== $mode ) {
        $offered = ( '' !== $mode_needle && false !== strpos( $titles, $mode_needle ) );
        if ( $offered ) {
            return wp_ai_agent_tool_response(
                sprintf( __( 'Yes, %s is available. Here are our shipping options:', 'wp-ai-agent' ), $mode ) . "\n• " . implode( "\n• ", $all ) . $ships_to,
                array( 'intent' => 'shipping', 'source' => 'shipping', 'data' => array( 'actions' => $actions ) )
            );
        }
        if ( ! empty( $all ) ) {
            return wp_ai_agent_tool_response(
                sprintf( __( "I couldn't find a dedicated %s option on this website. Here are the shipping methods we do offer:", 'wp-ai-agent' ), $mode ) . "\n• " . implode( "\n• ", $all ) . $ships_to,
                array( 'intent' => 'shipping', 'source' => 'shipping', 'data' => array( 'actions' => $actions ) )
            );
        }
        // No methods configured — fall through to policy/not-found below.
    }

    if ( ! empty( $free ) ) {
        return wp_ai_agent_tool_response(
            __( 'Yes, we offer free shipping:', 'wp-ai-agent' ) . "\n• " . implode( "\n• ", array_unique( $free ) )
                . ( ! empty( $methods ) ? "\n\n" . __( 'Other options:', 'wp-ai-agent' ) . "\n• " . implode( "\n• ", array_unique( $methods ) ) : '' ) . $ships_to,
            array( 'intent' => 'shipping', 'source' => 'shipping', 'data' => array( 'actions' => $actions ) )
        );
    }
    if ( ! empty( $methods ) ) {
        return wp_ai_agent_tool_response(
            __( 'Here are our shipping options:', 'wp-ai-agent' ) . "\n• " . implode( "\n• ", array_unique( $methods ) ) . $ships_to,
            array( 'intent' => 'shipping', 'source' => 'shipping', 'data' => array( 'actions' => $actions ) )
        );
    }

    // No configured methods → try a shipping/delivery policy page (shortcode-safe).
    if ( function_exists( 'wp_ai_agent_tool_navigation' ) ) {
        $nav = wp_ai_agent_tool_navigation( 'shipping delivery policy' );
        if ( null !== $nav ) {
            $nav['intent'] = 'shipping';
            if ( empty( $nav['data'] ) || empty( $nav['data']['actions'] ) ) {
                $nav['data']['actions'] = $actions;
            }
            return $nav;
        }
    }

    // Nothing found — stay helpful, and NEVER suggest products.
    return wp_ai_agent_tool_response(
        __( "I'm sorry, I couldn't find shipping or delivery details on this website. Our team can confirm the options for your area — would you like to contact them or read our shipping policy?", 'wp-ai-agent' ),
        array( 'intent' => 'shipping', 'source' => 'shipping', 'matched' => false, 'data' => array( 'actions' => $actions ) )
    );
}

/**
 * Payment tool: list the ENABLED WooCommerce payment methods (gateways). Reads
 * the configured gateways directly so it reports exactly what the store accepts,
 * regardless of cart state. Never shows products. Falls back to a payment /
 * checkout policy page, then an honest "not found".
 *
 * @return array
 */
function wp_ai_agent_tool_payment() {
    $methods = array();

    if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
        // Use the full gateway list filtered by the stored "enabled" flag (rather
        // than get_available_payment_gateways(), which can hide methods like COD
        // when there is no active cart context).
        foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
            $enabled = isset( $gateway->enabled ) ? $gateway->enabled : ( method_exists( $gateway, 'is_available' ) && $gateway->is_available() ? 'yes' : 'no' );
            if ( 'yes' !== $enabled ) {
                continue;
            }
            $title = method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : ( isset( $gateway->title ) ? $gateway->title : $gateway->id );
            $title = trim( wp_strip_all_tags( (string) $title ) );
            if ( '' !== $title ) {
                $methods[] = $title;
            }
        }
    }

    $methods = array_values( array_unique( array_filter( $methods ) ) );

    if ( ! empty( $methods ) ) {
        return wp_ai_agent_tool_response(
            __( 'We accept the following payment methods:', 'wp-ai-agent' ) . "\n• " . implode( "\n• ", $methods ),
            array( 'intent' => 'payment', 'source' => 'woocommerce' )
        );
    }

    // No gateways readable → try a payment / checkout policy page.
    if ( function_exists( 'wp_ai_agent_tool_navigation' ) ) {
        $nav = wp_ai_agent_tool_navigation( 'payment methods checkout' );
        if ( null !== $nav ) {
            return $nav;
        }
    }

    return wp_ai_agent_tool_response(
        __( "I couldn't find payment information on this website.", 'wp-ai-agent' ),
        array( 'intent' => 'payment', 'source' => 'woocommerce', 'matched' => false )
    );
}

/**
 * Normalise a raw phone string for display and validate it looks like a real
 * number (7–15 digits). Returns '' when it doesn't. Keeps the human formatting
 * (+, spaces, dashes, brackets) the site used.
 *
 * @param string $raw Raw phone text.
 * @return string
 */
function wp_ai_agent_clean_phone( $raw ) {
    $raw     = html_entity_decode( (string) $raw, ENT_QUOTES );
    $display = trim( preg_replace( '/[^0-9+()\s.\-]/', '', $raw ) );
    $display = trim( preg_replace( '/\s{2,}/', ' ', $display ) );
    $digits  = preg_replace( '/\D/', '', $display );
    if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
        return '';
    }
    return $display;
}

/**
 * Pull a phone number out of a blob of site text — first from an explicit
 * `tel:` link (most reliable), then from a labelled "Phone/Call/Tel/Mobile …"
 * pattern. Returns a cleaned number or ''.
 *
 * @param string $text Text/HTML/JSON to scan.
 * @return string
 */
function wp_ai_agent_extract_phone_from_text( $text ) {
    if ( ! is_string( $text ) || '' === $text ) {
        return '';
    }
    // Explicit tel: link (guard against "hotel:", "motel:" via \b).
    if ( preg_match( '/\btel:\s*([+(]?[0-9][0-9()\s.\-]{5,}[0-9])/i', $text, $m ) ) {
        $p = wp_ai_agent_clean_phone( $m[1] );
        if ( '' !== $p ) {
            return $p;
        }
    }
    // Labelled number: "Phone: …", "Call us …", "Tel …", "Mobile …", "Ph …".
    if ( preg_match( '/\b(?:tele?phone|phone|call us|call|mobile|mob|contact(?:\s*(?:number|no|us))?|tel|ph)\b\D{0,18}([+(]?[0-9][0-9()\s.\-]{5,}[0-9])/i', $text, $m ) ) {
        return wp_ai_agent_clean_phone( $m[1] );
    }
    return '';
}

/**
 * Discover the business phone number, dynamically and with NO hardcoding. Tries,
 * in order: known options (WooCommerce/site), ACF phone fields, then the site's
 * own content — `tel:` links and labelled "Phone:" text in pages, Elementor
 * builder data (incl. footer/global templates), widgets and the customizer. This
 * is why a number shown only in the footer is still found. Cached for 12h.
 *
 * @return string Cleaned phone number for display, or '' when none exists.
 */
function wp_ai_agent_discover_phone() {
    $cached = get_transient( 'wp_ai_agent_phone_number' );
    if ( false !== $cached ) {
        return (string) $cached;
    }

    $phone = '';

    // 1) Known options.
    foreach ( array( 'woocommerce_store_phone', 'admin_phone', 'phone', 'contact_phone', 'business_phone' ) as $opt ) {
        $val = get_option( $opt );
        if ( is_string( $val ) && '' !== trim( $val ) ) {
            $phone = wp_ai_agent_clean_phone( wp_strip_all_tags( $val ) );
            if ( '' !== $phone ) {
                break;
            }
        }
    }

    // 2) ACF phone-style fields (front page, then options).
    if ( '' === $phone && function_exists( 'get_field' ) ) {
        $page_id = (int) get_option( 'page_on_front' );
        foreach ( array( 'phone', 'phone_number', 'contact_number', 'mobile', 'telephone' ) as $f ) {
            $val = $page_id ? get_field( $f, $page_id ) : get_field( $f, 'option' );
            if ( is_string( $val ) && '' !== trim( $val ) ) {
                $phone = wp_ai_agent_clean_phone( wp_strip_all_tags( $val ) );
                if ( '' !== $phone ) {
                    break;
                }
            }
        }
    }

    // 3) The site's own content (footer, contact page, builder data, widgets).
    if ( '' === $phone ) {
        $phone = wp_ai_agent_scan_site_phone();
    }

    set_transient( 'wp_ai_agent_phone_number', $phone, 12 * HOUR_IN_SECONDS );
    return $phone;
}

/**
 * Scan the site's stored content for a phone number: `tel:` links first (across
 * post content, Elementor data and options), then labelled "Phone:" text in the
 * front page, contact page, Elementor footer/global templates, text widgets and
 * the customizer. Bounded queries; no hardcoded numbers.
 *
 * @return string Cleaned phone number, or ''.
 */
function wp_ai_agent_scan_site_phone() {
    global $wpdb;

    // --- Pass 1: explicit tel: links (very low false-positive). ---
    $like_tel = '%tel:%';
    $sources  = array();
    $sources  = array_merge( $sources, (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 20",
        $like_tel
    ) ) );
    $sources = array_merge( $sources, (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s LIMIT 20",
        $like_tel
    ) ) );
    $sources = array_merge( $sources, (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 40",
        $like_tel
    ) ) );
    foreach ( $sources as $s ) {
        $p = wp_ai_agent_extract_phone_from_text( $s );
        if ( '' !== $p ) {
            return $p;
        }
    }

    // --- Pass 2: labelled "Phone:" text in the most likely places. ---
    $blobs = array();

    $front = (int) get_option( 'page_on_front' );
    if ( $front ) {
        $blobs[] = get_post_field( 'post_content', $front );
        $blobs[] = (string) get_post_meta( $front, '_elementor_data', true );
    }
    foreach ( array( 'contact', 'contact-us', 'contactus', 'get-in-touch' ) as $slug ) {
        $page = get_page_by_path( $slug );
        if ( $page instanceof WP_Post ) {
            $blobs[] = $page->post_content;
            $blobs[] = (string) get_post_meta( $page->ID, '_elementor_data', true );
        }
    }
    // Elementor footer / global templates and any builder data mentioning a phone.
    $blobs = array_merge( $blobs, (array) $wpdb->get_col(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE '%phone%' LIMIT 20"
    ) );
    // Text/HTML widgets and customizer theme mods that mention a phone.
    $blobs = array_merge( $blobs, (array) $wpdb->get_col(
        "SELECT option_value FROM {$wpdb->options} WHERE ( option_name LIKE 'widget_%' OR option_name LIKE '%theme_mods%' ) AND option_value LIKE '%phone%' LIMIT 40"
    ) );

    foreach ( $blobs as $b ) {
        $p = wp_ai_agent_extract_phone_from_text( $b );
        if ( '' !== $p ) {
            return $p;
        }
    }

    return '';
}

/**
 * Discover the PUBLIC business email — the one shown on the website itself
 * (footer / contact page / mailto: links) — NOT the WordPress admin/developer
 * email. Prefers an address on the site's own domain, so an agency/dev admin
 * email (e.g. on a different domain) is never exposed. Cached 12h.
 *
 * @return string Business email, or '' when none can be determined.
 */
function wp_ai_agent_discover_email() {
    $cached = get_transient( 'wp_ai_agent_business_email' );
    if ( false !== $cached ) {
        return (string) $cached;
    }
    $email = wp_ai_agent_scan_business_email();
    set_transient( 'wp_ai_agent_business_email', $email, 12 * HOUR_IN_SECONDS );
    return $email;
}

/**
 * Scan for the public business email. Order: an admin-set Notification Email →
 * an email found on the site (mailto: links / "Email:" text in pages, Elementor,
 * widgets, and the rendered homepage) preferring the site's own domain → the
 * WooCommerce/admin email ONLY when it is on the site's own domain. Returns ''
 * otherwise (so a developer email on a foreign domain is never shown).
 *
 * @return string
 */
function wp_ai_agent_scan_business_email() {
    // An explicit Notification Email set by the admin is the business email.
    $notify = wp_ai_agent_option( 'notify_email', '' );
    if ( $notify && is_email( $notify ) ) {
        return $notify;
    }

    $site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    $site_host = preg_replace( '/^www\./', '', $site_host );
    $skip      = array( 'example.com', 'example.org', 'wordpress.com', 'wp.com', 'sentry.io', 'sentry.wordpress.com' );

    $candidates = array();
    $collect    = function ( $text ) use ( &$candidates, $site_host ) {
        $text = str_replace( '\/', '/', (string) $text );
        if ( preg_match_all( '/mailto:([^"\'\s<>?&]+@[^"\'\s<>?&]+)/i', $text, $mm ) ) {
            foreach ( $mm[1] as $e ) {
                $e = sanitize_email( rawurldecode( $e ) );
                if ( $e && is_email( $e ) ) {
                    $candidates[] = $e;
                }
            }
        }
        // Labelled "Email: x@y" / "Contact: x@y" plain text.
        if ( preg_match_all( '/\b(?:e-?mail|mail id|contact)\b\D{0,14}([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $text, $lm ) ) {
            foreach ( $lm[1] as $e ) {
                $e = sanitize_email( $e );
                if ( $e && is_email( $e ) ) {
                    $candidates[] = $e;
                }
            }
        }
        // Any address on the site's OWN domain (a footer "web@yoursite.com" that
        // is plain text, no mailto). Domain-restricted, so it never grabs junk.
        if ( $site_host && preg_match_all( '/([a-z0-9._%+\-]+@' . preg_quote( $site_host, '/' ) . ')/i', $text, $dm ) ) {
            foreach ( $dm[1] as $e ) {
                $e = sanitize_email( $e );
                if ( $e && is_email( $e ) ) {
                    $candidates[] = $e;
                }
            }
        }
    };

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    foreach ( (array) $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%mailto:%' LIMIT 20" ) as $b ) {
        $collect( $b );
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE '%mailto:%' LIMIT 20" ) as $b ) {
        $collect( $b );
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    foreach ( (array) $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE ( option_name LIKE 'widget_%' OR option_name LIKE '%theme_mods%' OR option_name LIKE '%options' ) AND option_value LIKE '%mailto:%' LIMIT 40" ) as $b ) {
        $collect( $b );
    }
    // The rendered homepage catches a theme-coded footer email.
    $resp = wp_remote_get( home_url( '/' ), array( 'timeout' => 8, 'redirection' => 2 ) );
    if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
        $collect( wp_remote_retrieve_body( $resp ) );
    }

    // Prefer an email on the site's own domain; else the first real one found.
    $preferred = '';
    $first     = '';
    foreach ( $candidates as $e ) {
        $host = preg_replace( '/^www\./', '', strtolower( (string) substr( strrchr( $e, '@' ), 1 ) ) );
        if ( in_array( $host, $skip, true ) ) {
            continue;
        }
        if ( '' === $first ) {
            $first = $e;
        }
        if ( $site_host && $host === $site_host ) {
            $preferred = $e;
            break;
        }
    }
    if ( '' !== $preferred ) {
        return $preferred;
    }
    if ( '' !== $first ) {
        return $first;
    }

    // Fallback: the store/admin email ONLY when it is on the site's OWN domain —
    // never a developer/agency address on a different domain.
    foreach ( array( get_option( 'woocommerce_email_from_address' ), get_option( 'admin_email' ) ) as $c ) {
        if ( $c && is_email( $c ) ) {
            $host = preg_replace( '/^www\./', '', strtolower( (string) substr( strrchr( $c, '@' ), 1 ) ) );
            if ( '' === $site_host || $host === $site_host ) {
                return $c;
            }
        }
    }

    return '';
}

/**
 * Which SINGLE contact detail the visitor asked for, so the answer stays focused
 * on exactly that (Response Discipline: "phone number?" → return ONLY the phone,
 * never email/address/products). Returns 'phone' | 'email' | 'address' | 'hours'
 * | 'whatsapp' when the message asks for exactly one of them, or 'all' for a
 * generic "contact us / how do I reach you" request (or when several are asked).
 *
 * @param string $message User message.
 * @return string
 */
function wp_ai_agent_contact_field_requested( $message ) {
    $m      = ' ' . strtolower( (string) $message ) . ' ';
    $fields = array();

    if ( preg_match( '/\b(e-?mail|email address|mail id|mail address)\b/', $m ) ) {
        $fields['email'] = true;
    }
    if ( preg_match( '/\bwhats ?app\b/', $m ) ) {
        $fields['whatsapp'] = true;
    }
    if ( preg_match( '/\b(phone|mobile|telephone|tel|call (you|us|your)|contact number|contact no|phone number|phone no|helpline|help ?line|number to (call|reach))\b/', $m ) ) {
        $fields['phone'] = true;
    }
    if ( preg_match( '/\b(address|location|located|where are you|office address|store address|shop address|find you)\b/', $m ) ) {
        $fields['address'] = true;
    }
    if ( preg_match( '/\b(business hours?|opening hours?|working hours?|open hours?|store hours?|timings?|hours of operation|what time.*(open|close)|when.*(open|close))\b/', $m ) ) {
        $fields['hours'] = true;
    }

    if ( 1 === count( $fields ) ) {
        reset( $fields );
        return (string) key( $fields );
    }
    return 'all';
}

/**
 * Discover the business hours (opening / working hours) dynamically — from site
 * options first, then ACF fields — with NO hardcoding. Returns '' when the site
 * does not record them (so we never invent hours). Cached for 12h. Extendable
 * via the wp_ai_agent_business_hours filter.
 *
 * @return string
 */
function wp_ai_agent_discover_hours() {
    $cached = get_transient( 'wp_ai_agent_business_hours' );
    if ( false !== $cached ) {
        return (string) $cached;
    }

    $hours = '';
    foreach ( array( 'woocommerce_store_hours', 'business_hours', 'store_hours', 'opening_hours', 'hours', 'working_hours', 'timings' ) as $opt ) {
        $val = get_option( $opt );
        if ( is_string( $val ) && '' !== trim( $val ) ) {
            $hours = trim( wp_strip_all_tags( $val ) );
            break;
        }
    }

    if ( '' === $hours && function_exists( 'get_field' ) ) {
        $page_id = (int) get_option( 'page_on_front' );
        foreach ( array( 'business_hours', 'opening_hours', 'store_hours', 'hours', 'working_hours', 'timings' ) as $f ) {
            $val = $page_id ? get_field( $f, $page_id ) : get_field( $f, 'option' );
            if ( is_string( $val ) && '' !== trim( $val ) ) {
                $hours = trim( wp_strip_all_tags( $val ) );
                break;
            }
        }
    }

    /** Allow a theme/plugin to supply business hours the auto-discovery missed. */
    $hours = (string) apply_filters( 'wp_ai_agent_business_hours', $hours );

    set_transient( 'wp_ai_agent_business_hours', $hours, 12 * HOUR_IN_SECONDS );
    return $hours;
}

/**
 * Contact tool: gather every available business contact detail (customer care /
 * support / sales email, phone, WhatsApp, store address, business name) from
 * WooCommerce store settings, site options, theme mods and ACF.
 *
 * Response Discipline: when the visitor asks for ONE specific detail ("what is
 * your phone number?"), it returns ONLY that detail — never the rest. A generic
 * "contact us / how do I reach you" returns everything together. Never shows
 * products. Falls back to a contact page, then a human handoff, so the visitor
 * always has a way to reach the team.
 *
 * @param string $message    User message (kept for parity).
 * @param string $session_id Session id (for handoff logging).
 * @param string $page_url   Page URL (for handoff logging).
 * @return array
 */
function wp_ai_agent_tool_contact( $message = '', $session_id = '', $page_url = '' ) {
    $business = wp_ai_agent_option( 'business_name', get_bloginfo( 'name' ) );

    // --- Email: the PUBLIC business email shown on the site (footer / contact /
    //     mailto), never the WordPress admin/developer email. ---
    $emails        = array();
    $primary_email = function_exists( 'wp_ai_agent_discover_email' ) ? wp_ai_agent_discover_email() : '';
    if ( '' !== $primary_email ) {
        $emails[] = $primary_email;
    }

    // --- Phone / WhatsApp. ---
    $whatsapp = preg_replace( '/[^0-9+]/', '', (string) wp_ai_agent_option( 'whatsapp_number', '' ) );
    // Discover the phone dynamically — options/ACF first, then the site's own
    // content (tel: links & labelled "Phone:" text in pages, Elementor footer/
    // global templates, widgets, customizer). This finds a number shown only in
    // the footer, which the old option-only lookup missed. No hardcoding.
    $phone = function_exists( 'wp_ai_agent_discover_phone' ) ? wp_ai_agent_discover_phone() : '';

    // --- Store address (WooCommerce settings). ---
    $address = '';
    if ( function_exists( 'WC' ) ) {
        $parts = array_filter( array(
            get_option( 'woocommerce_store_address' ),
            get_option( 'woocommerce_store_address_2' ),
            get_option( 'woocommerce_store_city' ),
            get_option( 'woocommerce_store_postcode' ),
        ) );
        $country = (string) get_option( 'woocommerce_default_country' );
        if ( '' !== $country && function_exists( 'WC' ) && WC()->countries ) {
            $code      = strtok( $country, ':' );
            $countries = WC()->countries->get_countries();
            if ( isset( $countries[ $code ] ) ) {
                $parts[] = $countries[ $code ];
            }
        }
        $address = trim( implode( ', ', array_map( 'wp_strip_all_tags', $parts ) ) );
    }

    // --- Response Discipline: answer ONLY the specific detail asked for. ---
    // When the visitor asks a single, precise question ("phone number?", "email?",
    // "address?", "business hours?", "WhatsApp?"), return just that — never the
    // other channels. Only a specific detail that actually EXISTS answers early;
    // if it isn't recorded on the site, we fall through to the combined reply so
    // the visitor still gets a way to reach the team.
    $want = wp_ai_agent_contact_field_requested( $message );

    if ( 'phone' === $want && '' !== $phone ) {
        return wp_ai_agent_tool_response(
            /* translators: %s: business phone number. */
            sprintf( __( 'You can reach us by phone at %s.', 'wp-ai-agent' ), $phone ),
            array( 'source' => 'contact', 'intent' => 'contact_info' )
        );
    }

    if ( 'email' === $want && ! empty( $emails ) ) {
        return wp_ai_agent_tool_response(
            /* translators: %s: business email address. */
            sprintf( __( 'You can email us at %s.', 'wp-ai-agent' ), $emails[0] ),
            array( 'source' => 'contact', 'intent' => 'contact_info' )
        );
    }

    if ( 'address' === $want && '' !== $address ) {
        return wp_ai_agent_tool_response(
            /* translators: %s: store address. */
            sprintf( __( 'Our address is: %s', 'wp-ai-agent' ), $address ),
            array( 'source' => 'contact', 'intent' => 'contact_info' )
        );
    }

    if ( 'whatsapp' === $want ) {
        $wa = function_exists( 'wp_ai_agent_whatsapp_url' ) ? wp_ai_agent_whatsapp_url( '' ) : '';
        if ( '' !== $wa ) {
            if ( '' !== $session_id && function_exists( 'wp_ai_agent_log_handoff' ) ) {
                wp_ai_agent_log_handoff( 'shown', $session_id, $page_url, 'contact' );
            }
            return wp_ai_agent_tool_response(
                __( 'You can chat with us on WhatsApp — just tap below. 💬', 'wp-ai-agent' ),
                array(
                    'source' => 'contact',
                    'intent' => 'contact_info',
                    'data'   => array( 'actions' => array(
                        array( 'label' => __( '💬 Chat on WhatsApp', 'wp-ai-agent' ), 'url' => $wa, 'track' => 'handoff', 'query' => 'contact' ),
                    ) ),
                )
            );
        }
    }

    if ( 'hours' === $want ) {
        $hours = wp_ai_agent_discover_hours();
        if ( '' !== $hours ) {
            return wp_ai_agent_tool_response(
                /* translators: %s: business hours. */
                sprintf( __( 'Our business hours are: %s', 'wp-ai-agent' ), $hours ),
                array( 'source' => 'contact', 'intent' => 'contact_info' )
            );
        }
        // Hours aren't listed on the site — answer honestly (don't dump every
        // other detail as if it were the hours), and offer a way to reach the team.
        return wp_ai_agent_tool_response(
            __( "I couldn't find our business hours listed on the website. Our team can confirm them for you — would you like their contact details?", 'wp-ai-agent' ),
            array(
                'source'  => 'contact',
                'intent'  => 'contact_info',
                'matched' => false,
                'data'    => array( 'actions' => array(
                    array( 'label' => __( '📞 Contact details', 'wp-ai-agent' ), 'query' => 'how can I contact you' ),
                ) ),
            )
        );
    }

    // --- Build the combined reply from whatever was found (a general "customer
    //     care / contact us / support" request). Formatted like a support desk:
    //     labelled contact channels only — never products, categories, FAQ, About
    //     or other unrelated pages. ---
    $hours = function_exists( 'wp_ai_agent_discover_hours' ) ? wp_ai_agent_discover_hours() : '';

    $lines   = array();
    $lines[] = $business
        /* translators: %s: business name. */
        ? sprintf( __( 'Sure! You can contact %s customer support using the methods below:', 'wp-ai-agent' ), $business )
        : __( 'Sure! You can contact our customer support using the methods below:', 'wp-ai-agent' );
    $lines[] = '';

    // Phone first — it's what "contact number" / "phone" questions ask for.
    if ( '' !== $phone ) {
        /* translators: %s: phone number. */
        $lines[] = '📞 ' . sprintf( __( 'Phone: %s', 'wp-ai-agent' ), $phone );
    }
    foreach ( $emails as $i => $email ) {
        $label   = ( 0 === $i ) ? __( 'Email', 'wp-ai-agent' ) : __( 'Alternate email', 'wp-ai-agent' );
        $lines[] = '📧 ' . sprintf( '%s: %s', $label, $email );
    }
    // WhatsApp: never expose the raw number — the public "Chat on WhatsApp" button
    // below opens the wa.me link.
    if ( '' !== $whatsapp ) {
        $lines[] = '💬 ' . __( 'WhatsApp: tap “Chat on WhatsApp” below to message us', 'wp-ai-agent' );
    }
    if ( '' !== $address ) {
        /* translators: %s: store address. */
        $lines[] = '📍 ' . sprintf( __( 'Address: %s', 'wp-ai-agent' ), $address );
    }
    if ( '' !== $hours ) {
        /* translators: %s: business hours. */
        $lines[] = '🕒 ' . sprintf( __( 'Business Hours: %s', 'wp-ai-agent' ), $hours );
    }

    // "Found" means at least one real contact CHANNEL exists (hours alone is not
    // a way to reach the team).
    $found_any = ( ! empty( $emails ) || '' !== $phone || '' !== $whatsapp || '' !== $address );

    // Suggested next actions: WhatsApp deep-link (if configured) + leave details.
    $actions = array();
    $wa_url  = function_exists( 'wp_ai_agent_whatsapp_url' ) ? wp_ai_agent_whatsapp_url( '' ) : '';
    if ( '' !== $wa_url ) {
        $actions[] = array( 'label' => __( '💬 Chat on WhatsApp', 'wp-ai-agent' ), 'url' => $wa_url, 'track' => 'handoff', 'query' => 'contact' );
        if ( '' !== $session_id && function_exists( 'wp_ai_agent_log_handoff' ) ) {
            wp_ai_agent_log_handoff( 'shown', $session_id, $page_url, 'contact' );
        }
    }
    $actions[] = array( 'label' => __( '📩 Leave my details', 'wp-ai-agent' ), 'query' => 'I want to leave my contact details' );

    if ( $found_any ) {
        $lines[] = '';
        $lines[] = __( 'If you need help with an order, please keep your order number ready when contacting our support team.', 'wp-ai-agent' );
        return wp_ai_agent_tool_response( trim( implode( "\n", $lines ) ), array(
            'source' => 'contact',
            'intent' => 'contact_info',
            'data'   => array( 'actions' => $actions ),
        ) );
    }

    // Nothing structured found → try a Contact page (a legitimate contact source,
    // never About/FAQ/People), then a WhatsApp/email handoff.
    if ( function_exists( 'wp_ai_agent_tool_navigation' ) ) {
        $nav = wp_ai_agent_tool_navigation( 'contact us contact' );
        if ( null !== $nav ) {
            return $nav;
        }
    }
    if ( '' !== $wa_url && function_exists( 'wp_ai_agent_tool_human' ) ) {
        return wp_ai_agent_tool_human( $message, $session_id, $page_url );
    }

    // Honest fallback — no contact details anywhere. Never a page dump.
    return wp_ai_agent_tool_response(
        __( "I'm sorry, I couldn't find any customer support contact details on this website.", 'wp-ai-agent' ),
        array( 'source' => 'contact', 'intent' => 'contact_info', 'matched' => false )
    );
}

/* -------------------------------------------------------------------------
 * Social media + newsletter tool.
 * ---------------------------------------------------------------------- */

/**
 * The social platforms the assistant recognises (label + icon + the domains that
 * identify each). Filterable so a site can add a network.
 *
 * @return array<string,array>
 */
function wp_ai_agent_social_platforms() {
    return apply_filters( 'wp_ai_agent_social_platforms', array(
        'facebook'  => array( 'label' => 'Facebook',    'icon' => '📘', 'domains' => array( 'facebook.com', 'fb.com', 'fb.me' ) ),
        'instagram' => array( 'label' => 'Instagram',   'icon' => '📷', 'domains' => array( 'instagram.com', 'instagr.am' ) ),
        'twitter'   => array( 'label' => 'X (Twitter)', 'icon' => '🐦', 'domains' => array( 'twitter.com', 'x.com' ) ),
        'linkedin'  => array( 'label' => 'LinkedIn',    'icon' => '💼', 'domains' => array( 'linkedin.com', 'lnkd.in' ) ),
        'youtube'   => array( 'label' => 'YouTube',     'icon' => '▶️', 'domains' => array( 'youtube.com', 'youtu.be' ) ),
        'pinterest' => array( 'label' => 'Pinterest',   'icon' => '📌', 'domains' => array( 'pinterest.com', 'pin.it' ) ),
        'tiktok'    => array( 'label' => 'TikTok',      'icon' => '🎵', 'domains' => array( 'tiktok.com' ) ),
        'telegram'  => array( 'label' => 'Telegram',    'icon' => '✈️', 'domains' => array( 't.me', 'telegram.me' ) ),
        'threads'   => array( 'label' => 'Threads',     'icon' => '🧵', 'domains' => array( 'threads.net' ) ),
        'snapchat'  => array( 'label' => 'Snapchat',    'icon' => '👻', 'domains' => array( 'snapchat.com' ) ),
    ) );
}

/**
 * Classify a URL to a social platform key, or '' when it is not a (brand) social
 * profile. Share / intent endpoints (facebook.com/sharer, twitter.com/intent …)
 * are excluded — those are share buttons, not the brand's own page.
 *
 * @param string $url       URL.
 * @param array  $platforms Platform map.
 * @return string
 */
function wp_ai_agent_classify_social_url( $url, $platforms ) {
    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    if ( '' === $host ) {
        return '';
    }
    $path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
    if ( preg_match( '#(sharer|/share|/intent/|sharearticle|/dialog/|/plugins/|share\.php)#', $path ) ) {
        return '';
    }
    foreach ( $platforms as $key => $p ) {
        foreach ( $p['domains'] as $d ) {
            $suffix = '.' . $d;
            if ( $host === $d || $host === 'www.' . $d
                || ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) ) {
                return $key;
            }
        }
    }
    return '';
}

/**
 * Discover the brand's social profile URLs dynamically from the site — nav
 * menus, Customizer theme mods, social/widget options, and Elementor footer/
 * global data. No URL is hardcoded. Cached for 12h. Returns platform => URL.
 *
 * @return array<string,string>
 */
function wp_ai_agent_discover_social() {
    $cached = get_transient( 'wp_ai_agent_social_links' );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $platforms = wp_ai_agent_social_platforms();
    $found     = array();

    $consider = function ( $url ) use ( &$found, $platforms ) {
        $url = trim( (string) $url );
        if ( '' === $url || 0 !== strpos( $url, 'http' ) ) {
            return;
        }
        $key = wp_ai_agent_classify_social_url( $url, $platforms );
        if ( '' !== $key && empty( $found[ $key ] ) ) {
            $found[ $key ] = esc_url_raw( $url );
        }
    };

    // 1) Navigation menus (the most common home for social icons).
    $menus = wp_get_nav_menus();
    if ( ! is_wp_error( $menus ) ) {
        foreach ( (array) $menus as $menu ) {
            foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $it ) {
                if ( isset( $it->url ) ) {
                    $consider( $it->url );
                }
            }
        }
    }

    // 2) Theme mods + bounded option / Elementor blobs → extract every URL.
    $blobs = array();
    $mods  = get_theme_mods();
    if ( is_array( $mods ) ) {
        $blobs[] = wp_json_encode( $mods );
    }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $blobs = array_merge( $blobs, (array) $wpdb->get_col(
        "SELECT option_value FROM {$wpdb->options} WHERE ( option_name LIKE 'widget_%' OR option_name LIKE '%theme_mods%' OR option_name LIKE '%social%' ) AND option_value LIKE '%http%' LIMIT 80"
    ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $blobs = array_merge( $blobs, (array) $wpdb->get_col(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND ( meta_value LIKE '%facebook.com%' OR meta_value LIKE '%instagram.com%' OR meta_value LIKE '%youtube.com%' OR meta_value LIKE '%linkedin.com%' OR meta_value LIKE '%twitter.com%' OR meta_value LIKE '%x.com%' OR meta_value LIKE '%tiktok.com%' OR meta_value LIKE '%pinterest.com%' OR meta_value LIKE '%t.me%' ) LIMIT 20"
    ) );

    foreach ( $blobs as $blob ) {
        $blob = str_replace( '\/', '/', (string) $blob ); // Elementor/JSON escaped slashes.
        if ( preg_match_all( '#https?://[^\s"\'<>\\\\)]+#i', $blob, $mm ) ) {
            foreach ( $mm[0] as $u ) {
                $consider( $u );
            }
        }
    }

    set_transient( 'wp_ai_agent_social_links', $found, 12 * HOUR_IN_SECONDS );
    return $found;
}

/**
 * Discover a newsletter / subscribe page URL by common slugs. Returns '' when
 * none exists (so we never invent one).
 *
 * @return string
 */
function wp_ai_agent_discover_newsletter() {
    foreach ( array( 'newsletter', 'subscribe', 'newsletter-signup', 'mailing-list', 'email-signup', 'subscribe-newsletter' ) as $slug ) {
        $page = get_page_by_path( $slug );
        if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
            $url = get_permalink( $page );
            if ( $url ) {
                return $url;
            }
        }
    }
    return '';
}

/**
 * Whether the site has a newsletter / subscribe capability, and (if any) a
 * dedicated page URL. Many sites put the signup in a FOOTER form (no page), so
 * detection is layered: dedicated page → known newsletter plugin → form markers
 * in widgets / Elementor / theme mods → a last-resort scan of the rendered
 * homepage for a "newsletter/subscribe" label next to an email field. Cached
 * 12h. No signup is ever invented.
 *
 * @return array{url:string,available:bool}
 */
function wp_ai_agent_newsletter_status() {
    $cached = get_transient( 'wp_ai_agent_newsletter' );
    if ( is_array( $cached ) && isset( $cached['available'] ) ) {
        return $cached;
    }

    $status = array( 'url' => '', 'available' => false );

    // 1) A dedicated newsletter / subscribe page.
    $status['url'] = wp_ai_agent_discover_newsletter();
    if ( '' !== $status['url'] ) {
        $status['available'] = true;
    }

    // 2) A known newsletter / email-marketing plugin.
    if ( ! $status['available'] && (
        defined( 'MC4WP_VERSION' ) || class_exists( 'MC4WP_MailChimp' )
        || class_exists( 'MailPoet\\API\\API' ) || defined( 'MAILPOET_VERSION' )
        || class_exists( 'Newsletter' ) || defined( 'NEWSLETTER_VERSION' )
        || class_exists( 'SIB_Manager' ) || class_exists( 'Brevo_Manager' )
    ) ) {
        $status['available'] = true;
    }

    // 3) Newsletter form markers in widgets / Elementor / theme mods.
    if ( ! $status['available'] ) {
        global $wpdb;
        $blobs = array();
        $mods  = get_theme_mods();
        if ( is_array( $mods ) ) {
            $blobs[] = wp_json_encode( $mods );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $blobs = array_merge( $blobs, (array) $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget_%' AND ( option_value LIKE '%newsletter%' OR option_value LIKE '%mc4wp%' OR option_value LIKE '%mailchimp%' OR option_value LIKE '%subscribe%' ) LIMIT 60" ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $blobs = array_merge( $blobs, (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND ( meta_value LIKE '%newsletter%' OR meta_value LIKE '%mc4wp%' OR meta_value LIKE '%mailchimp%' OR meta_value LIKE '%subscribe%' ) LIMIT 20" ) );
        $hay     = strtolower( implode( ' ', array_map( 'strval', $blobs ) ) );
        $markers = array( 'mc4wp', 'mailchimp', 'mailpoet', 'tnp-subscription', 'brevo', 'sendinblue', 'mailerlite', 'convertkit', 'omnisend', 'klaviyo' );
        foreach ( $markers as $mk ) {
            if ( false !== strpos( $hay, $mk ) ) {
                $status['available'] = true;
                break;
            }
        }
        if ( ! $status['available'] && false !== strpos( $hay, 'newsletter' ) && ( false !== strpos( $hay, 'email' ) || false !== strpos( $hay, 'subscribe' ) ) ) {
            $status['available'] = true;
        }
    }

    // 4) Last resort — the rendered homepage (catches a theme-coded footer form,
    //    which is exactly how many sites present the newsletter signup).
    if ( ! $status['available'] ) {
        $resp = wp_remote_get( home_url( '/' ), array( 'timeout' => 8, 'redirection' => 2 ) );
        if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
            $html = strtolower( (string) wp_remote_retrieve_body( $resp ) );

            // A newsletter/mailing-list label…
            $has_label = ( false !== strpos( $html, 'newsletter' ) || false !== strpos( $html, 'mailing list' ) );
            // …together with a signup form cue (a Subscribe button, an email
            // input, or an email-style placeholder). The footer field may be a
            // plain text input, so we don't rely on type="email" alone.
            $has_form  = (
                false !== strpos( $html, 'subscribe' )
                || (bool) preg_match( '/type\s*=\s*["\']?email/', $html )
                || (bool) preg_match( '/placeholder\s*=\s*["\'][^"\']*@/', $html )
                || (bool) preg_match( '/name\s*=\s*["\'](email|your-email|em_email|mce_email|mc4wp)/', $html )
            );
            if ( $has_label && $has_form ) {
                $status['available'] = true;
            }
        }
    }

    set_transient( 'wp_ai_agent_newsletter', $status, 12 * HOUR_IN_SECONDS );
    return $status;
}

/**
 * Social media / newsletter tool. Returns the brand's requested social profile
 * (or all of them for a general "social media" request), plus the newsletter
 * link when asked — all discovered from the website, never invented. Answers
 * only what was asked (Facebook → only Facebook), and is honest when a network
 * isn't present.
 *
 * @param string $message User message.
 * @return array
 */
function wp_ai_agent_tool_social( $message ) {
    $m         = ' ' . strtolower( (string) $message ) . ' ';
    $platforms = wp_ai_agent_social_platforms();

    // Which specific network(s) were asked for?
    $kw = array(
        'facebook'  => '/\b(facebook|fb)\b/',
        'instagram' => '/\b(instagram|insta)\b/',
        'twitter'   => '/\b(twitter|x\.com)\b/',
        'linkedin'  => '/\blinkedin\b/',
        'youtube'   => '/\b(you ?tube)\b/',
        'pinterest' => '/\bpinterest\b/',
        'tiktok'    => '/\btik ?tok\b/',
        'telegram'  => '/\btelegram\b/',
        'threads'   => '/\bthreads\b/',
        'snapchat'  => '/\bsnapchat\b/',
    );
    $requested = array();
    foreach ( $kw as $key => $re ) {
        if ( preg_match( $re, $m ) ) {
            $requested[] = $key;
        }
    }

    $wants_newsletter = (bool) preg_match( '/\b(newsletter|subscribe|mailing list|email updates)\b/', $m );
    $ns               = $wants_newsletter ? wp_ai_agent_newsletter_status() : array( 'url' => '', 'available' => false );
    $social           = wp_ai_agent_discover_social();

    // A reusable "join the newsletter" hint for when the signup is a footer form
    // (available but no dedicated page to link to).
    $newsletter_footer_note = "\n\n" . __( '📩 You can also join our newsletter from the Newsletter box at the bottom of any page.', 'wp-ai-agent' );

    $contact_action = array( 'label' => __( '📞 Contact', 'wp-ai-agent' ), 'query' => 'how can I contact you' );

    // Helper: build a link action for a platform.
    $link_action = function ( $key, $url ) use ( $platforms ) {
        $p = isset( $platforms[ $key ] ) ? $platforms[ $key ] : array( 'label' => ucfirst( $key ), 'icon' => '🔗' );
        return array( 'label' => $p['icon'] . ' ' . $p['label'], 'url' => $url );
    };

    // ---- Newsletter-only request. ----
    if ( $wants_newsletter && empty( $requested ) && ! preg_match( '/\bsocial|follow\b/', $m ) ) {
        if ( '' !== $ns['url'] ) {
            return wp_ai_agent_tool_response(
                __( 'You can subscribe to our newsletter here:', 'wp-ai-agent' ),
                array( 'source' => 'social', 'intent' => 'social', 'data' => array( 'actions' => array(
                    array( 'label' => __( '📩 Subscribe', 'wp-ai-agent' ), 'url' => $ns['url'] ),
                ) ) )
            );
        }
        if ( $ns['available'] ) {
            // The signup is a footer form (no dedicated page) — point the visitor to it.
            return wp_ai_agent_tool_response(
                __( 'Yes! 📩 You can join our newsletter — just scroll to the Newsletter box at the bottom of any page and enter your email address.', 'wp-ai-agent' ),
                array( 'source' => 'social', 'intent' => 'social', 'data' => array( 'actions' => array(
                    array( 'label' => __( '🏠 Go to homepage', 'wp-ai-agent' ), 'url' => home_url( '/' ) ),
                ) ) )
            );
        }
        return wp_ai_agent_tool_response(
            __( "I couldn't find a newsletter signup on this website, but I can connect you with our team to be added to updates.", 'wp-ai-agent' ),
            array( 'source' => 'social', 'intent' => 'social', 'matched' => false, 'data' => array( 'actions' => array(
                array( 'label' => __( '📩 Leave my details', 'wp-ai-agent' ), 'query' => 'I want to leave my contact details' ),
                $contact_action,
            ) ) )
        );
    }

    // ---- Specific network(s) requested. ----
    if ( ! empty( $requested ) ) {
        $hit  = array();
        $miss = array();
        foreach ( $requested as $key ) {
            if ( ! empty( $social[ $key ] ) ) {
                $hit[ $key ] = $social[ $key ];
            } else {
                $miss[] = $platforms[ $key ]['label'];
            }
        }

        if ( ! empty( $hit ) ) {
            $actions = array();
            $first   = '';
            foreach ( $hit as $key => $url ) {
                $actions[] = $link_action( $key, $url );
                if ( '' === $first ) {
                    $first = $platforms[ $key ]['label'];
                }
            }
            $msg = ( 1 === count( $hit ) )
                /* translators: %s: social network name. */
                ? sprintf( __( 'Here is our %s — tap below to open it:', 'wp-ai-agent' ), $first )
                : __( 'Here are the profiles you asked for:', 'wp-ai-agent' );
            if ( ! empty( $miss ) ) {
                /* translators: %s: social network name(s). */
                $msg .= "\n\n" . sprintf( __( "We're not on %s, though.", 'wp-ai-agent' ), implode( ', ', $miss ) );
            }
            if ( $wants_newsletter ) {
                if ( '' !== $ns['url'] ) {
                    $actions[] = array( 'label' => __( '📩 Subscribe', 'wp-ai-agent' ), 'url' => $ns['url'] );
                } elseif ( $ns['available'] ) {
                    $msg .= $newsletter_footer_note;
                }
            }
            return wp_ai_agent_tool_response( $msg, array( 'source' => 'social', 'intent' => 'social', 'data' => array( 'actions' => $actions ) ) );
        }

        // Requested network(s) not found. Offer whatever else exists, else honest.
        if ( empty( $social ) ) {
            return wp_ai_agent_tool_response(
                /* translators: %s: social network name(s). */
                sprintf( __( "Sorry, I couldn't find our %s on this website.", 'wp-ai-agent' ), implode( ', ', $miss ) ),
                array( 'source' => 'social', 'intent' => 'social', 'matched' => false, 'data' => array( 'actions' => array( $contact_action ) ) )
            );
        }
        $actions = array();
        foreach ( $social as $key => $url ) {
            $actions[] = $link_action( $key, $url );
        }
        return wp_ai_agent_tool_response(
            /* translators: %s: social network name(s). */
            sprintf( __( "I couldn't find our %s, but here are the social profiles we do have:", 'wp-ai-agent' ), implode( ', ', $miss ) ),
            array( 'source' => 'social', 'intent' => 'social', 'data' => array( 'actions' => $actions ) )
        );
    }

    // ---- General "social media" / "follow us" request → all available. ----
    if ( empty( $social ) ) {
        return wp_ai_agent_tool_response(
            __( "I couldn't find any social media links on this website.", 'wp-ai-agent' ),
            array( 'source' => 'social', 'intent' => 'social', 'matched' => false, 'data' => array( 'actions' => array( $contact_action ) ) )
        );
    }
    $actions = array();
    foreach ( $social as $key => $url ) {
        $actions[] = $link_action( $key, $url );
    }
    $msg = __( 'You can follow us here:', 'wp-ai-agent' );
    if ( $wants_newsletter ) {
        if ( '' !== $ns['url'] ) {
            $actions[] = array( 'label' => __( '📩 Subscribe', 'wp-ai-agent' ), 'url' => $ns['url'] );
        } elseif ( $ns['available'] ) {
            $msg .= $newsletter_footer_note;
        }
    }
    return wp_ai_agent_tool_response( $msg, array( 'source' => 'social', 'intent' => 'social', 'data' => array( 'actions' => $actions ) ) );
}

/**
 * Sale-products tool: show ONLY products currently on sale (optionally of the
 * requested type). If none, say so — never substitute non-sale products.
 *
 * @param string $message User message.
 * @param int    $limit   Max products.
 * @return array
 */
function wp_ai_agent_tool_sale_products( $message, $limit ) {
    if ( ! function_exists( 'wc_get_product_ids_on_sale' ) || ! function_exists( 'wc_get_products' ) ) {
        return wp_ai_agent_tool_response( __( 'Sale information is not available on this website.', 'wp-ai-agent' ), array( 'intent' => 'product_sale', 'matched' => false ) );
    }

    $ids = wc_get_product_ids_on_sale();
    if ( empty( $ids ) ) {
        return wp_ai_agent_tool_response( __( "Sorry, there are no products on sale right now.", 'wp-ai-agent' ), array( 'intent' => 'product_sale', 'source' => 'woocommerce', 'matched' => false ) );
    }

    $products = wc_get_products( array( 'include' => $ids, 'status' => 'publish', 'limit' => 100 ) );
    // Optional type filter ("shirts on sale").
    $type_keywords = function_exists( 'wp_ai_agent_wc_query_keywords' ) ? array_values( array_diff( wp_ai_agent_wc_query_keywords( $message ), wp_ai_agent_generic_terms() ) ) : array();
    $type_keywords = array_values( array_diff( $type_keywords, array( 'sale', 'discount', 'discounted', 'clearance', 'offer', 'offers', 'deal', 'deals' ) ) );

    $picked = array();
    foreach ( $products as $product ) {
        if ( ! empty( $type_keywords ) ) {
            $hay = ' ' . strtolower( $product->get_name() . ' ' . wp_ai_agent_wc_terms( $product ) ) . ' ';
            $ok  = false;
            foreach ( $type_keywords as $kw ) {
                foreach ( ( function_exists( 'wp_ai_agent_expand_token' ) ? wp_ai_agent_expand_token( $kw ) : array( $kw ) ) as $needle ) {
                    if ( wp_ai_agent_term_match( $hay, $needle ) ) {
                        $ok = true;
                        break 2;
                    }
                }
            }
            if ( ! $ok ) {
                continue;
            }
        }
        $picked[] = $product;
    }

    if ( empty( $picked ) ) {
        return wp_ai_agent_tool_response( __( "Sorry, none of the products on sale match that. Would you like to see all our sale items?", 'wp-ai-agent' ), array( 'intent' => 'product_sale', 'source' => 'woocommerce', 'matched' => false ) );
    }

    // Prefer products that are actually available. If any are in stock, show only
    // those (the customer wants something they can buy); otherwise fall back to
    // all on-sale items rather than showing nothing.
    $in_stock = array_values( array_filter( $picked, function ( $p ) {
        return $p->is_in_stock();
    } ) );
    if ( ! empty( $in_stock ) ) {
        $picked = $in_stock;
    }

    // Sort by biggest discount first (regular price vs sale price).
    usort( $picked, function ( $a, $b ) {
        return wp_ai_agent_discount_percent( $b ) <=> wp_ai_agent_discount_percent( $a );
    } );

    $total = count( $picked );
    $cards = array();
    foreach ( array_slice( $picked, 0, (int) $limit ) as $product ) {
        $cards[] = wp_ai_agent_product_card( $product );
    }
    $shown = count( $cards );

    $top_pct = wp_ai_agent_discount_percent( $picked[0] );
    $intro   = ( $top_pct > 0 )
        /* translators: %d: highest discount percentage. */
        ? sprintf( __( 'Here are our current deals — up to %d%% off, biggest discounts first:', 'wp-ai-agent' ), $top_pct )
        : __( 'Here are the products currently on sale:', 'wp-ai-agent' );

    // More on sale than we can show as cards? Tell the customer the full count and
    // give them a link to the whole sale listing (a dedicated Sale/Deals page if
    // the site has one, otherwise the shop) so they can browse ALL of them.
    $data     = array( 'products' => $cards );
    $sale_url = function_exists( 'wp_ai_agent_sale_archive_url' ) ? wp_ai_agent_sale_archive_url() : '';
    $shop_url = ( '' === $sale_url && function_exists( 'wc_get_page_permalink' ) ) ? wc_get_page_permalink( 'shop' ) : '';
    if ( $total > $shown ) {
        $intro .= "\n\n" . sprintf(
            /* translators: 1: number shown, 2: total on sale. */
            __( "That's %1\$d of %2\$d products currently on sale.", 'wp-ai-agent' ),
            $shown,
            $total
        );
        if ( '' !== $sale_url ) {
            // A real Sale/Deals listing exists — send them straight to all deals.
            $intro          .= ' ' . __( 'You can see them all here:', 'wp-ai-agent' );
            $data['actions'] = array(
                array(
                    /* translators: %d: total number of products on sale. */
                    'label' => sprintf( __( '🏷️ See all %d deals', 'wp-ai-agent' ), $total ),
                    'url'   => $sale_url,
                ),
            );
        } elseif ( '' !== $shop_url ) {
            // No dedicated page — point to the shop (honest wording: it's the
            // full catalogue where the sale items live, not a sale-only list).
            $intro          .= ' ' . __( 'Browse the full store to see the rest:', 'wp-ai-agent' );
            $data['actions'] = array(
                array( 'label' => __( '🛍️ Browse all products', 'wp-ai-agent' ), 'url' => $shop_url ),
            );
        }
    } elseif ( '' !== $sale_url ) {
        $data['actions'] = array(
            array( 'label' => __( '🏷️ View the Sale page', 'wp-ai-agent' ), 'url' => $sale_url ),
        );
    }

    return wp_ai_agent_tool_response(
        $intro,
        array( 'intent' => 'product_sale', 'source' => 'woocommerce', 'data' => $data )
    );
}

/**
 * URL of the site's sale / deals listing so the bot can point customers to the
 * FULL set of on-sale products (chat only shows a few cards). Prefers a dedicated
 * page or product category the site already built (e.g. /sale, /clearance),
 * discovered dynamically — never hardcoded — and falls back to the shop page.
 *
 * @return string URL, or '' when none can be determined.
 */
function wp_ai_agent_sale_archive_url() {
    // 1) A dedicated page the site built for deals (by slug).
    foreach ( array( 'sale', 'sales', 'on-sale', 'deals', 'clearance', 'offers', 'specials', 'discount', 'discounts' ) as $slug ) {
        $page = get_page_by_path( $slug );
        if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
            $url = get_permalink( $page );
            if ( $url ) {
                return $url;
            }
        }
    }

    // 2) A product category that represents a sale section.
    if ( function_exists( 'get_term_by' ) && taxonomy_exists( 'product_cat' ) ) {
        foreach ( array( 'sale', 'on-sale', 'clearance', 'deals', 'offers', 'specials' ) as $slug ) {
            $term = get_term_by( 'slug', $slug, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $url = get_term_link( $term );
                if ( ! is_wp_error( $url ) ) {
                    return $url;
                }
            }
        }
    }

    // No dedicated sale listing found. Return '' so the caller can decide whether
    // to offer the generic shop page (with honest wording) instead.
    return '';
}

/**
 * Discount percentage of a product (regular vs sale price). Handles variable
 * products via their lowest variation. Returns 0 when not on sale.
 *
 * @param WC_Product $product Product.
 * @return int 0–100.
 */
function wp_ai_agent_discount_percent( $product ) {
    if ( $product->is_type( 'variable' ) ) {
        $reg  = (float) $product->get_variation_regular_price( 'min', true );
        $sale = (float) $product->get_variation_sale_price( 'min', true );
    } else {
        $reg  = (float) $product->get_regular_price();
        $sale = (float) $product->get_sale_price();
    }
    if ( $reg > 0 && $sale > 0 && $sale < $reg ) {
        return (int) round( ( ( $reg - $sale ) / $reg ) * 100 );
    }
    return 0;
}

/**
 * Intro line for a catalog-browse mode (optionally naming the product type).
 *
 * @param string $mode    cheapest|expensive|toprated|newest|featured|bestseller.
 * @param string $subject Optional product-type subject.
 * @return string
 */
function wp_ai_agent_browse_intro( $mode, $subject = '' ) {
    $s = ( '' !== $subject ) ? ' ' . $subject : __( ' products', 'wp-ai-agent' );
    switch ( $mode ) {
        case 'cheapest':
            /* translators: %s: product type (or " products"). */
            return sprintf( __( 'Here are our most affordable%s (lowest price first):', 'wp-ai-agent' ), $s );
        case 'expensive':
            return sprintf( __( 'Here are our premium%s (highest price first):', 'wp-ai-agent' ), $s );
        case 'toprated':
            return sprintf( __( 'Here are our top-rated%s:', 'wp-ai-agent' ), $s );
        case 'newest':
            return sprintf( __( 'Here are our newest%s:', 'wp-ai-agent' ), $s );
        case 'featured':
            return sprintf( __( 'Here are our featured%s:', 'wp-ai-agent' ), $s );
        case 'bestseller':
            return sprintf( __( 'Here are our best-selling%s:', 'wp-ai-agent' ), $s );
        case 'free':
            return sprintf( __( 'Here are our free%s:', 'wp-ai-agent' ), $s );
    }
    return __( 'Here are the products I found:', 'wp-ai-agent' );
}

/**
 * Catalog-browse tool: cheapest / most expensive / premium / featured / best
 * sellers / top rated / new arrivals — queried live from WooCommerce, so it
 * works on ANY store with no hardcoded categories. Optionally narrowed to a
 * product type the visitor named ("cheapest t-shirts"). Returns product cards
 * sorted by the requested mode (in-stock first), or an honest "no products"
 * reply — never unrelated products. Returns null when no browse mode is present.
 *
 * @param string   $message User message.
 * @param int|null $limit   Max products.
 * @param int[]    $exclude Product IDs to skip (e.g. already shown this session).
 * @return array|null
 */
function wp_ai_agent_tool_browse_products( $message, $limit = null, $exclude = array() ) {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return null;
    }

    $m     = ' ' . strtolower( (string) $message ) . ' ';
    $limit = $limit ? (int) $limit : (int) apply_filters( 'wp_ai_agent_card_count', 6 );

    // 1) Determine the sort mode.
    $mode = '';
    if ( preg_match( '/\b(cheap\w*|lowest|low(\s+|-)?(price|cost)|budget|affordable|inexpensive|least expensive|sasta|saste)\b/', $m ) ) {
        $mode = 'cheapest';
    } elseif ( preg_match( '/\b(most expensive|expensive|costl\w*|premium|luxury|high[- ]?end|high(\s+|-)?price|dearest|mehng)/', $m ) ) {
        $mode = 'expensive';
    } elseif ( preg_match( '/\b(top[- ]?rated|highest rated|best rated|highly rated|best reviewed)\b/', $m ) ) {
        $mode = 'toprated';
    } elseif ( preg_match( '/\b(new arrivals?|newest|latest|recently added|just added|new products?)\b/', $m ) ) {
        $mode = 'newest';
    } elseif ( preg_match( '/\bfeatured\b/', $m ) ) {
        $mode = 'featured';
    } elseif ( preg_match( '/\b(best[- ]?sell\w*|bestsellers?|top ?sell\w*|most sold|popular|trending|hot selling|in demand|best (product|products|value|pick|choice|one|option|item)|value for money|top products?)\b/', $m ) ) {
        $mode = 'bestseller';
    } elseif ( preg_match( '/\bfree (products?|items?|stuff|samples?|things?|gifts?)\b/', $m ) || preg_match( '/\bfreebies?\b/', $m ) ) {
        $mode = 'free'; // genuinely free (price 0) products.
    }
    if ( '' === $mode ) {
        return null; // Not a browse-by-sort request.
    }

    // 2) Optional product-type filter (drop generic descriptors AND mode words).
    $type_keywords = function_exists( 'wp_ai_agent_wc_query_keywords' ) ? wp_ai_agent_wc_query_keywords( $message ) : array();
    $type_keywords = array_values( array_diff( $type_keywords, wp_ai_agent_generic_terms() ) );
    $mode_words    = array(
        'cheapest', 'lowest', 'budget', 'affordable', 'inexpensive', 'expensive', 'costliest', 'costly',
        'premium', 'luxury', 'dearest', 'rated', 'rating', 'reviewed', 'arrival', 'arrivals', 'newest',
        'latest', 'recent', 'recently', 'added', 'featured', 'bestseller', 'bestsellers', 'seller',
        'sellers', 'selling', 'sell', 'popular', 'trending', 'hot', 'demand', 'sold', 'sale', 'discount',
        'discounted', 'offer', 'offers', 'deal', 'deals', 'clearance', 'products', 'product', 'items',
        'item', 'best', 'top', 'most', 'high', 'low', 'price', 'priced', 'cost',
        'free', 'freebie', 'freebies', 'sample', 'samples', 'stuff', 'gift', 'gifts',
    );
    $type_keywords = array_values( array_diff( $type_keywords, $mode_words ) );

    // 3) Candidate set (featured mode filters at the query level).
    $args = array( 'status' => 'publish', 'limit' => (int) apply_filters( 'wp_ai_agent_wc_search_limit', 200 ) );
    if ( 'featured' === $mode ) {
        $args['featured'] = true;
    }
    $products = wc_get_products( $args );

    // 4) Narrow to the named product type (never show unrelated products).
    if ( ! empty( $products ) && ! empty( $type_keywords ) ) {
        $filtered = array();
        foreach ( $products as $p ) {
            $hay = ' ' . strtolower( $p->get_name() . ' ' . wp_ai_agent_wc_terms( $p ) ) . ' ';
            foreach ( $type_keywords as $kw ) {
                $needles = function_exists( 'wp_ai_agent_expand_token' ) ? wp_ai_agent_expand_token( $kw ) : array( $kw );
                $hit     = false;
                foreach ( $needles as $needle ) {
                    if ( wp_ai_agent_term_match( $hay, $needle ) ) {
                        $hit = true;
                        break;
                    }
                }
                if ( $hit ) {
                    $filtered[] = $p;
                    break;
                }
            }
        }
        $products = $filtered;
    }

    // Free-products mode: keep only genuinely free (price 0) products. If the
    // store has none, say so honestly and offer the closest alternatives —
    // never a paid product mislabelled as "free".
    if ( 'free' === $mode ) {
        $products = array_values( array_filter( (array) $products, function ( $p ) {
            $price = $p->get_price();
            return '' !== (string) $price && (float) $price <= 0;
        } ) );
        if ( empty( $products ) ) {
            return wp_ai_agent_tool_response(
                __( "I couldn't find any free products on this website. Would you like to see our most affordable products or current deals instead?", 'wp-ai-agent' ),
                array( 'intent' => 'product_browse', 'source' => 'woocommerce', 'matched' => false, 'data' => array( 'actions' => array(
                    array( 'label' => __( '💸 Cheapest products', 'wp-ai-agent' ), 'query' => 'cheapest products' ),
                    array( 'label' => __( '🏷️ Products on sale', 'wp-ai-agent' ), 'query' => 'products on sale' ),
                ) ) )
            );
        }
    }

    // Skip already-shown products (so "show me something else" never repeats),
    // but keep them rather than return nothing if the catalog is exhausted.
    if ( ! empty( $products ) && ! empty( $exclude ) ) {
        $exclude = array_map( 'intval', (array) $exclude );
        $kept    = array_values( array_filter( $products, function ( $p ) use ( $exclude ) {
            return ! in_array( (int) $p->get_id(), $exclude, true );
        } ) );
        if ( ! empty( $kept ) ) {
            $products = $kept;
        }
    }

    if ( empty( $products ) ) {
        return wp_ai_agent_tool_response(
            __( "I couldn't find any products matching your request.", 'wp-ai-agent' ),
            array( 'intent' => 'product_browse', 'source' => 'woocommerce', 'matched' => false )
        );
    }

    // 5) Sort by mode.
    switch ( $mode ) {
        case 'cheapest':
            usort( $products, function ( $a, $b ) {
                return ( (float) $a->get_price() ) <=> ( (float) $b->get_price() );
            } );
            break;
        case 'expensive':
            usort( $products, function ( $a, $b ) {
                return ( (float) $b->get_price() ) <=> ( (float) $a->get_price() );
            } );
            break;
        case 'toprated':
            usort( $products, function ( $a, $b ) {
                return ( (float) $b->get_average_rating() ) <=> ( (float) $a->get_average_rating() );
            } );
            break;
        case 'newest':
            usort( $products, function ( $a, $b ) {
                $da = $a->get_date_created();
                $db = $b->get_date_created();
                return ( $db ? $db->getTimestamp() : 0 ) <=> ( $da ? $da->getTimestamp() : 0 );
            } );
            break;
        case 'bestseller':
            usort( $products, function ( $a, $b ) {
                return ( (int) $b->get_total_sales() ) <=> ( (int) $a->get_total_sales() );
            } );
            break;
    }

    // 6) In-stock first (never recommend out-of-stock first), keeping mode order.
    $in  = array();
    $out = array();
    foreach ( $products as $p ) {
        if ( $p->is_in_stock() ) {
            $in[] = $p;
        } else {
            $out[] = $p;
        }
    }
    $products = array_merge( $in, $out );
    $products = array_slice( $products, 0, $limit );

    // 7) Cards + a mode-specific intro.
    $cards = array();
    foreach ( $products as $p ) {
        $cards[] = wp_ai_agent_product_card( $p );
    }
    $subject = trim( implode( ' ', array_slice( $type_keywords, 0, 3 ) ) );
    $intro   = wp_ai_agent_browse_intro( $mode, $subject );

    return wp_ai_agent_tool_response( $intro, array(
        'intent' => 'product_browse',
        'source' => 'woocommerce',
        'data'   => array(
            'products' => $cards,
            'actions'  => array_merge(
                wp_ai_agent_guided_refine_actions( $products ),
                array(
                    array( 'label' => __( '🔥 Best sellers', 'wp-ai-agent' ), 'query' => 'best sellers' ),
                    array( 'label' => __( '📂 Categories', 'wp-ai-agent' ), 'query' => 'what categories do you have' ),
                )
            ),
        ),
    ) );
}

/* -------------------------------------------------------------------------
 * Customer feedback / objection handling (behave like a sales executive).
 * ---------------------------------------------------------------------- */

/**
 * Category / shortcut buttons offered alongside a feedback reply, so the
 * conversation always has an obvious next step.
 *
 * @return array[]
 */
function wp_ai_agent_feedback_category_actions() {
    $actions = array();
    if ( function_exists( 'wp_ai_agent_store_categories' ) ) {
        foreach ( wp_ai_agent_store_categories( 4 ) as $cat ) {
            $actions[] = array( 'label' => $cat['name'], 'query' => $cat['name'] );
        }
    }
    if ( function_exists( 'wc_get_products' ) ) {
        $actions[] = array( 'label' => __( '🔥 Best sellers', 'wp-ai-agent' ), 'query' => 'best sellers' );
        $actions[] = array( 'label' => __( '💸 Cheapest', 'wp-ai-agent' ), 'query' => 'cheapest products' );
    }
    return $actions;
}

/**
 * Consultative reply used when the visitor is unhappy or vague — apologise,
 * offer to help, and ask the qualifying questions (never a dead end).
 *
 * @param string $lead Opening empathy line.
 * @return array
 */
function wp_ai_agent_feedback_consult( $lead ) {
    $msg  = $lead . ' ' . __( "I'd love to help you find something you'll like. 😊", 'wp-ai-agent' ) . "\n\n";
    $msg .= __( "Could you tell me a little about what you're after:", 'wp-ai-agent' ) . "\n";
    $msg .= __( "• Type of product\n• Preferred colour\n• Brand (if any)\n• Budget\n• Size", 'wp-ai-agent' ) . "\n\n";
    $msg .= __( 'Or tap a category below to explore.', 'wp-ai-agent' );

    return wp_ai_agent_tool_response( $msg, array(
        'source' => 'guide',
        'intent' => 'feedback',
        'data'   => array( 'actions' => wp_ai_agent_feedback_category_actions() ),
    ) );
}

/**
 * Feedback / objection tool. Understands the customer's sentiment and continues
 * the conversation like a showroom sales rep instead of running a product
 * search:
 *   • "too expensive"        → more affordable options (cheapest, minus shown)
 *   • "show me something better" → premium options (minus shown)
 *   • "don't like this colour"   → note it, ask the preferred colour
 *   • "don't like this brand"    → offer other brands
 *   • "just looking"             → invite them to browse
 *   • generic dislike / vague    → apologise + qualifying questions
 * Recommendations exclude products already shown this session (never repeats).
 *
 * @param string $message    User message.
 * @param string $session_id Session id (for preference memory).
 * @param string $page_url   Page URL.
 * @return array
 */
function wp_ai_agent_tool_feedback( $message, $session_id = '', $page_url = '' ) {
    // Punctuation-stripped, space-padded copy for robust matching.
    $c   = ' ' . strtolower( trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', (string) $message ) ) ) ) . ' ';
    $has = function ( $re ) use ( $c ) {
        return (bool) preg_match( $re, $c );
    };

    // Products already shown this session (so we never repeat recommendations).
    $shown = array();
    if ( '' !== $session_id && function_exists( 'wp_ai_agent_get_prefs' ) ) {
        $prefs = wp_ai_agent_get_prefs( $session_id );
        if ( isset( $prefs['shown_ids'] ) && is_array( $prefs['shown_ids'] ) ) {
            $shown = $prefs['shown_ids'];
        }
    }
    $limit = (int) apply_filters( 'wp_ai_agent_card_count', 6 );

    // 1) Too expensive / needs cheaper → show more affordable options.
    if ( $has( '/\b(too expensive|too costly|too pricey|very expensive|so expensive|out of (my )?budget|cant afford|cannot afford|something cheaper|anything cheaper|need (something )?cheaper|cheaper (option|one|products?)|lower budget|less expensive)\b/' ) ) {
        if ( '' !== $session_id && function_exists( 'wp_ai_agent_update_prefs' ) ) {
            wp_ai_agent_update_prefs( $session_id, array( 'budget' => 'low' ) );
        }
        $resp = function_exists( 'wp_ai_agent_tool_browse_products' ) ? wp_ai_agent_tool_browse_products( 'cheapest', $limit, $shown ) : null;
        if ( $resp && ! empty( $resp['data']['products'] ) ) {
            $resp['message'] = __( "No problem — let's look at some more budget-friendly options: 😊", 'wp-ai-agent' )
                . "\n\n" . __( 'Would you like me to narrow these down by type or colour?', 'wp-ai-agent' );
            $resp['intent']  = 'feedback';
            return $resp;
        }
        return wp_ai_agent_feedback_consult( __( "I understand — let's find something within your budget.", 'wp-ai-agent' ) );
    }

    // 2) Wants something better / premium → show premium options.
    if ( $has( '/\b(something better|anything better|show (me )?(something |anything )?better|need (something |a )?better|better (option|quality)|higher quality|top quality|something nicer|more premium|premium (option|one)|more expensive)\b/' ) ) {
        $resp = function_exists( 'wp_ai_agent_tool_browse_products' ) ? wp_ai_agent_tool_browse_products( 'premium products', $limit, $shown ) : null;
        if ( $resp && ! empty( $resp['data']['products'] ) ) {
            $resp['message'] = __( 'Absolutely — here are some of our premium options: ✨', 'wp-ai-agent' )
                . "\n\n" . __( 'Would you like to see the top-rated ones too?', 'wp-ai-agent' );
            $resp['intent']  = 'feedback';
            return $resp;
        }
        return wp_ai_agent_feedback_consult( __( "Sure — let's find something of higher quality for you.", 'wp-ai-agent' ) );
    }

    // 2b) Gender exclusion ("don't show women's products", "no men") → focus on
    //     the other gender instead. Never a "we don't have …" reply.
    $neg = (bool) preg_match( '/\b(dont|do not|not|no|without|hide|exclude|skip|remove|avoid)\b/', $c );
    $has_women = (bool) preg_match( '/\b(women|womens|woman|female|ladies|girl|girls)\b/', $c );
    $has_men   = (bool) preg_match( '/\b(men|mens|man|male|gents|boys)\b/', $c );
    $exclude_g = '';
    $want_g    = '';
    if ( $neg && $has_women && ! $has_men ) {
        $exclude_g = 'women';
        $want_g    = 'men';
    } elseif ( $neg && $has_men && ! $has_women ) {
        $exclude_g = 'men';
        $want_g    = 'women';
    }
    if ( '' !== $exclude_g ) {
        if ( '' !== $session_id && function_exists( 'wp_ai_agent_update_prefs' ) ) {
            wp_ai_agent_update_prefs( $session_id, array( 'avoid_gender' => $exclude_g ) );
        }
        $resp = function_exists( 'wp_ai_agent_tool_product' ) ? wp_ai_agent_tool_product( $want_g . ' products', 'product_search', false, false ) : null;
        if ( $resp && ! empty( $resp['data']['products'] ) ) {
            /* translators: 1: excluded gender, 2: shown gender. */
            $resp['message'] = sprintf( __( "No problem — I'll leave out %1\$s's products. Here are our %2\$s's options:", 'wp-ai-agent' ), $exclude_g, $want_g );
            $resp['intent']  = 'feedback';
            return $resp;
        }
        /* translators: %s: excluded gender. */
        return wp_ai_agent_tool_response(
            sprintf( __( "Got it — I'll skip %s's products. What are you looking for? Tell me a product type or category and I'll show you the options. 😊", 'wp-ai-agent' ), $exclude_g ),
            array( 'source' => 'guide', 'intent' => 'feedback', 'data' => array( 'actions' => wp_ai_agent_feedback_category_actions() ) )
        );
    }

    // 3) Colour objection → remember it and ask the preferred colour. Triggered
    //    by the word "colour" OR by a colour named in a dislike ("don't like black").
    $avoid = '';
    if ( function_exists( 'wp_ai_agent_wc_color_terms' ) ) {
        foreach ( wp_ai_agent_wc_color_terms() as $col ) {
            if ( preg_match( '/(?<![a-z])' . preg_quote( $col, '/' ) . '(?![a-z])/', $c ) ) {
                $avoid = $col;
                break;
            }
        }
    }
    if ( $has( '/\bcolou?r\b/' ) || '' !== $avoid ) {
        if ( '' !== $avoid && '' !== $session_id && function_exists( 'wp_ai_agent_update_prefs' ) ) {
            wp_ai_agent_update_prefs( $session_id, array( 'avoid_color' => $avoid ) );
        }
        $msg = ( '' !== $avoid )
            /* translators: %s: colour to avoid. */
            ? sprintf( __( "Got it — I'll skip %s. Which colour would you prefer?", 'wp-ai-agent' ), $avoid )
            : __( 'No problem! Which colour are you looking for?', 'wp-ai-agent' );
        $msg .= "\n" . __( "Tell me the colour and the type of product, and I'll show you matching options.", 'wp-ai-agent' );
        return wp_ai_agent_tool_response( $msg, array( 'source' => 'guide', 'intent' => 'feedback', 'data' => array( 'actions' => wp_ai_agent_feedback_category_actions() ) ) );
    }

    // 4) Brand objection → offer other brands.
    if ( $has( '/\bbrand\b/' ) ) {
        return wp_ai_agent_tool_response(
            __( 'Sure — which brand would you prefer? I can show you options from the other brands we carry.', 'wp-ai-agent' ),
            array( 'source' => 'guide', 'intent' => 'feedback', 'data' => array( 'actions' => wp_ai_agent_feedback_category_actions() ) )
        );
    }

    // 5) Just looking / browsing → invite them to explore.
    if ( $has( '/\b(just looking|just browsing|only looking|only browsing|browsing)\b/' ) ) {
        return wp_ai_agent_tool_response(
            __( "Take your time! 😊 Feel free to browse — I'm right here if you'd like a recommendation. Here are a few places to start:", 'wp-ai-agent' ),
            array( 'source' => 'guide', 'intent' => 'feedback', 'data' => array( 'actions' => wp_ai_agent_feedback_category_actions() ) )
        );
    }

    // 6) Generic dislike / "not what I want" / "another option" / unsure.
    return wp_ai_agent_feedback_consult( __( "I'm sorry these didn't match what you had in mind.", 'wp-ai-agent' ) );
}

/* -------------------------------------------------------------------------
 * Directory tool — the universal listing engine for non-store verticals.
 * Lists a website type's main custom-post-type items (menu, doctors, courses,
 * rooms, properties, packages, projects, programmes, services, articles) as
 * rich cards, using the type adapter from the Website Intelligence Engine. One
 * tool serves every vertical; a new vertical is added purely via the
 * wp_ai_agent_type_directory filter — no code changes here.
 * ---------------------------------------------------------------------- */

/**
 * Best-effort price for a directory item (rooms / courses / properties / menu),
 * read from common price meta keys. Returns '' when the item has no price. No
 * key is hardcoded per-site — the list is filterable.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function wp_ai_agent_directory_price( $post_id ) {
    $keys = apply_filters( 'wp_ai_agent_directory_price_keys', array(
        '_price', 'price', '_regular_price', 'item_price', '_item_price',
        '_mphb_price', 'property_price', '_property_price', 'course_price',
        '_course_price', 'tour_price', 'package_price', '_room_price', 'room_price',
    ) );
    foreach ( $keys as $k ) {
        $v = get_post_meta( $post_id, $k, true );
        if ( '' !== $v && is_numeric( $v ) && (float) $v > 0 ) {
            return function_exists( 'wc_price' )
                ? html_entity_decode( wp_strip_all_tags( wc_price( $v ) ), ENT_QUOTES )
                : (string) $v;
        }
    }
    return '';
}

/**
 * Build a card for a directory item (reuses the product-card shape so the chat
 * renders it with the same image + title + description + View button; commerce
 * fields are off so no Add-to-Cart appears).
 *
 * @param WP_Post $post Item.
 * @param array   $cfg  Directory adapter config.
 * @return array
 */
function wp_ai_agent_directory_card( $post, $cfg ) {
    $img_id = get_post_thumbnail_id( $post->ID );
    $image  = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
    $body   = ( '' !== trim( (string) $post->post_excerpt ) ) ? $post->post_excerpt : $post->post_content;
    $short  = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $body ) ), 22, '…' );

    $card = array(
        'id'          => $post->ID,
        'name'        => get_the_title( $post ),
        'url'         => get_permalink( $post->ID ),
        'image'       => $image ? $image : '',
        'short'       => $short,
        'category'    => '',
        'in_stock'    => true,
        'purchasable' => false,
        'add_ajax'    => false,
    );
    if ( ! empty( $cfg['price'] ) ) {
        $price = wp_ai_agent_directory_price( $post->ID );
        if ( '' !== $price ) {
            $card['price'] = $price;
        }
    }
    return $card;
}

/**
 * Directory listing tool. When the site's type has a listing adapter AND the
 * visitor actually asked for that listing (menu / doctors / courses / rooms /
 * listings / packages / projects / programmes / services / articles), it returns
 * the matching items as cards. Returns null otherwise, so ordinary questions
 * fall through to normal website-content search — it never hijacks unrelated
 * queries, and it is a no-op on plain WooCommerce stores.
 *
 * @param string $message User message.
 * @return array|null
 */
function wp_ai_agent_tool_directory( $message ) {
    if ( ! function_exists( 'wp_ai_agent_type_directory' ) || ! function_exists( 'wp_ai_agent_get_website_profile' ) ) {
        return null;
    }

    $profile = wp_ai_agent_get_website_profile();
    $type    = isset( $profile['type'] ) ? $profile['type'] : '';
    $cfg     = wp_ai_agent_type_directory( $type );
    if ( empty( $cfg ) || empty( $cfg['cpts'] ) || empty( $cfg['triggers'] ) ) {
        return null;
    }

    // Only respond when the visitor actually asked for this listing.
    if ( ! preg_match( $cfg['triggers'], (string) $message ) ) {
        return null;
    }

    // First custom post type that actually exists on this site.
    $cpt = '';
    foreach ( (array) $cfg['cpts'] as $c ) {
        if ( post_type_exists( $c ) ) {
            $cpt = $c;
            break;
        }
    }
    if ( '' === $cpt ) {
        return null; // No listing CPT — let normal content search answer.
    }

    $orderby = isset( $cfg['orderby'] ) ? $cfg['orderby'] : 'date';
    $limit   = (int) apply_filters( 'wp_ai_agent_directory_count', 8, $type );
    $posts   = get_posts( array(
        'post_type'      => $cpt,
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => $orderby,
        'order'          => ( 'title' === $orderby ) ? 'ASC' : 'DESC',
    ) );
    if ( empty( $posts ) ) {
        return null;
    }

    $cards = array();
    foreach ( $posts as $p ) {
        $cards[] = wp_ai_agent_directory_card( $p, $cfg );
    }

    return wp_ai_agent_tool_response(
        $cfg['intro'],
        array(
            'source' => 'directory',
            'intent' => 'directory_listing',
            'data'   => array(
                'products' => $cards,
                'actions'  => array(
                    array( 'label' => __( '📞 Contact', 'wp-ai-agent' ), 'query' => 'how can I contact you' ),
                ),
            ),
        )
    );
}

/* -------------------------------------------------------------------------
 * Business information + products overview (understand-before-selling).
 * ---------------------------------------------------------------------- */

/**
 * Build "who is this business" context — site identity, the About page, and the
 * homepage — so a company/brand question is answered from real website content
 * (never products, never invented). Dynamic: no hardcoded names.
 *
 * @return string
 */
function wp_ai_agent_business_context() {
    $parts = array();

    $name = get_bloginfo( 'name' );
    $desc = get_bloginfo( 'description' );
    if ( $name ) {
        $parts[] = 'Website: ' . $name . ( $desc ? ' - ' . $desc : '' );
    }

    // About / company / story page.
    foreach ( array( 'about', 'about-us', 'about-us-2', 'who-we-are', 'our-story', 'story', 'company', 'our-company', 'the-brand' ) as $slug ) {
        $p = get_page_by_path( $slug );
        if ( $p instanceof WP_Post && 'publish' === $p->post_status ) {
            $body = trim( wp_strip_all_tags( strip_shortcodes( (string) $p->post_content ) ) );
            if ( '' === $body && function_exists( 'wp_ai_agent_extract_elementor_text' ) ) {
                $body = trim( wp_strip_all_tags( wp_ai_agent_extract_elementor_text( $p->ID ) ) );
            }
            if ( '' !== $body ) {
                $parts[] = 'Title: ' . get_the_title( $p ) . "\nContent: " . wp_trim_words( $body, 160, '…' );
            }
            break;
        }
    }

    // Homepage content.
    $front = (int) get_option( 'page_on_front' );
    if ( $front ) {
        $fp = get_post( $front );
        if ( $fp ) {
            $body = trim( wp_strip_all_tags( strip_shortcodes( (string) $fp->post_content ) ) );
            if ( '' === $body && function_exists( 'wp_ai_agent_extract_elementor_text' ) ) {
                $body = trim( wp_strip_all_tags( wp_ai_agent_extract_elementor_text( $front ) ) );
            }
            if ( '' !== $body ) {
                $parts[] = 'Homepage: ' . wp_trim_words( $body, 120, '…' );
            }
        }
    }

    // Fall back to a general site overview when we still have little to go on.
    if ( count( $parts ) < 2 && function_exists( 'wp_ai_agent_build_general_context' ) ) {
        $general = wp_ai_agent_build_general_context( 25 );
        if ( '' !== $general ) {
            $parts[] = $general;
        }
    }

    return trim( implode( "\n\n", $parts ) );
}

/**
 * Business Information tool: answer a company / brand / "about us" question from
 * the website's own content (About page, homepage, site identity) — never with a
 * product listing. Ends with a natural next step. Returns null when there is no
 * usable content (so the caller can fall back).
 *
 * @param string $message User message.
 * @return array|null
 */
function wp_ai_agent_tool_business_info( $message ) {
    $context = wp_ai_agent_business_context();
    if ( '' === trim( $context ) ) {
        return null;
    }

    $answer = wp_ai_agent_engine_answer( $message, $context, 'overview' );
    if ( ! is_string( $answer ) || '' === trim( $answer ) ) {
        return null;
    }

    $commerce = function_exists( 'wp_ai_agent_commerce_enabled' ) ? wp_ai_agent_commerce_enabled() : function_exists( 'wc_get_products' );
    $actions  = array();
    if ( $commerce ) {
        $answer  .= "\n\n" . __( 'Would you like to explore our product categories or learn more about a specific product? 😊', 'wp-ai-agent' );
        $actions[] = array( 'label' => __( '📂 Categories', 'wp-ai-agent' ), 'query' => 'what categories do you have' );
        $actions[] = array( 'label' => __( '🛍️ Our products', 'wp-ai-agent' ), 'query' => 'tell me about your products' );
    } else {
        $actions[] = array( 'label' => __( '📞 Contact', 'wp-ai-agent' ), 'query' => 'how can I contact you' );
    }

    return wp_ai_agent_tool_response( $answer, array(
        'source' => 'website',
        'intent' => 'business_info',
        'data'   => array( 'actions' => $actions ),
    ) );
}

/**
 * Products Overview tool: describe the product RANGE (a natural summary built
 * from the store's categories) and invite the visitor to pick a category —
 * WITHOUT dumping a product listing. Products are shown only once they choose a
 * category or ask for a specific item. Returns null when there is no store.
 *
 * @return array|null
 */
function wp_ai_agent_tool_products_overview() {
    $commerce = function_exists( 'wp_ai_agent_commerce_enabled' ) ? wp_ai_agent_commerce_enabled() : function_exists( 'wc_get_products' );
    if ( ! $commerce || ! function_exists( 'wc_get_products' ) ) {
        return null; // No store — let business/overview answer instead.
    }

    $cats = function_exists( 'wp_ai_agent_store_categories' ) ? wp_ai_agent_store_categories( 12 ) : array();

    if ( empty( $cats ) ) {
        // No categories — offer to show the products directly.
        return wp_ai_agent_tool_response(
            __( 'We have a range of products for you to explore. Would you like to see them? 🛍️', 'wp-ai-agent' ),
            array( 'source' => 'catalog', 'intent' => 'products_overview', 'data' => array( 'actions' => array(
                array( 'label' => __( '🛍️ Show products', 'wp-ai-agent' ), 'query' => 'show all products' ),
            ) ) )
        );
    }

    $names = wp_list_pluck( $cats, 'name' );
    $list  = implode( ', ', array_slice( $names, 0, 10 ) );

    /* translators: %s: comma-separated category names. */
    $msg  = sprintf( __( 'We offer a range of products across categories like %s.', 'wp-ai-agent' ), $list );
    $msg .= "\n\n" . __( 'Which category would you like to explore? I can also show our best sellers or find a specific product for you. 😊', 'wp-ai-agent' );

    $rows = array();
    foreach ( array_slice( $cats, 0, 8 ) as $c ) {
        $rows[] = array( 'label' => $c['name'], 'query' => $c['name'] );
    }

    return wp_ai_agent_tool_response( $msg, array(
        'source' => 'catalog',
        'intent' => 'products_overview',
        'data'   => array(
            'list'    => $rows,
            'actions' => array(
                array( 'label' => __( '🔥 Best sellers', 'wp-ai-agent' ), 'query' => 'best sellers' ),
            ),
        ),
    ) );
}

/**
 * Answer an information-type request from indexed website content only.
 *
 * @param string $message User message.
 * @param string $intent  Detected intent.
 * @return array|null Null when nothing relevant was found.
 */
function wp_ai_agent_tool_information( $message, $intent ) {
    if ( ! function_exists( 'wp_ai_agent_retrieve_context' ) ) {
        return null;
    }
    $retrieval = wp_ai_agent_retrieve_context( $message );
    if ( empty( $retrieval['has_match'] ) ) {
        return null;
    }
    $mode   = isset( $retrieval['mode'] ) ? $retrieval['mode'] : 'match';
    $answer = wp_ai_agent_engine_answer( $message, $retrieval['context'], $mode );
    return wp_ai_agent_tool_response( $answer, array( 'source' => 'website', 'intent' => $intent ) );
}

/* -------------------------------------------------------------------------
 * Guided "how-to" / website assistant.
 * ---------------------------------------------------------------------- */

/**
 * Shopping assistant: a confused visitor who wants help choosing. Replies like a
 * store consultant — asks the qualifying questions (budget, colour, brand, size,
 * purpose) and offers category/best-seller shortcuts to get started.
 *
 * @return array
 */
function wp_ai_agent_tool_shopping_help() {
    $msg  = __( "No problem — I'd be happy to help you choose the right product! 😊", 'wp-ai-agent' ) . "\n\n";
    $msg .= __( 'To suggest the best options, could you tell me a little about what you need:', 'wp-ai-agent' ) . "\n";
    $msg .= __( "• Your budget\n• Preferred colour\n• Brand (if any)\n• Size\n• What you'll use it for", 'wp-ai-agent' ) . "\n\n";
    $msg .= __( "Or just tell me the kind of product you're after (for example \"running shoes under $50\") and I'll show you the best matches.", 'wp-ai-agent' );

    $actions = array();
    if ( function_exists( 'wp_ai_agent_store_categories' ) ) {
        foreach ( wp_ai_agent_store_categories( 4 ) as $c ) {
            $actions[] = array( 'label' => $c['name'], 'query' => $c['name'] );
        }
    }
    if ( function_exists( 'wc_get_products' ) ) {
        $actions[] = array( 'label' => __( '🔥 Best sellers', 'wp-ai-agent' ), 'query' => 'best sellers' );
    }

    return wp_ai_agent_tool_response( $msg, array(
        'source' => 'guide',
        'intent' => 'shopping_help',
        'data'   => array( 'actions' => $actions ),
    ) );
}

/**
 * "How do I…" guide: gives clear step-by-step instructions for completing common
 * store tasks (ordering, cart, checkout, account, login, coupons, password,
 * profile, tracking, cancellation, refunds, contact, shipping, payment). Uses the
 * site's REAL links where available and hands policy-dependent topics
 * (refund/return/cancel) to the page search first, so answers stay grounded.
 * Always ends with a helpful next step.
 *
 * @param string $message    User message.
 * @param string $session_id Session id.
 * @param string $page_url   Current page URL.
 * @return array
 */
function wp_ai_agent_tool_howto( $message, $session_id = '', $page_url = '' ) {
    $m   = ' ' . strtolower( (string) $message ) . ' ';
    $has = function ( $pattern ) use ( $m ) {
        return (bool) preg_match( $pattern, $m );
    };

    $shop     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
    $cart     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
    $checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
    $account  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
    $follow   = "\n\n" . __( 'Would you like help with anything else? 😊', 'wp-ai-agent' );

    // --- Track an order. ---
    if ( $has( '/\btrack/' ) || ( $has( '/\bwhere\b/' ) && $has( '/\border/' ) ) ) {
        $msg = __( "Here's how to track your order:", 'wp-ai-agent' ) . "\n"
            . __( "1. Log in to your account.\n2. Go to My Account → Orders.\n3. Open the order to see its status and any tracking details.", 'wp-ai-agent' );
        if ( $account ) {
            $msg .= "\n\n" . sprintf( __( 'Your account: %s', 'wp-ai-agent' ), $account );
        }
        $msg .= "\n\n" . __( "I can pull up your orders right here too — just tap below.", 'wp-ai-agent' );
        return wp_ai_agent_tool_response( $msg, array( 'source' => 'guide', 'intent' => 'how_to', 'data' => array( 'actions' => array(
            array( 'label' => __( '📦 Track my order', 'wp-ai-agent' ), 'query' => 'my orders' ),
        ) ) ) );
    }

    // --- Cancel / modify an order. ---
    if ( $has( '/\b(cancel|modif|change my order|change the order)\b/' ) ) {
        $msg = __( "To cancel or change an order, it helps to act quickly before it ships:", 'wp-ai-agent' ) . "\n"
            . __( "1. Log in and open My Account → Orders.\n2. If the order is still being processed, use the cancel option if it's available.\n3. Otherwise, contact our team and we'll sort it out for you.", 'wp-ai-agent' );
        $nav = function_exists( 'wp_ai_agent_tool_navigation' ) ? wp_ai_agent_tool_navigation( 'cancellation return refund order policy' ) : null;
        if ( null !== $nav && ! empty( $nav['message'] ) ) {
            $msg .= "\n\n" . $nav['message'];
        }
        return wp_ai_agent_tool_response( $msg, array( 'source' => 'guide', 'intent' => 'how_to', 'data' => array( 'actions' => array(
            array( 'label' => __( '📞 Contact support', 'wp-ai-agent' ), 'query' => 'contact information' ),
        ) ) ) );
    }

    // --- Refund / return / exchange (policy-grounded). ---
    if ( $has( '/\b(refund|return|exchange|money back)\b/' ) ) {
        $nav = function_exists( 'wp_ai_agent_tool_navigation' ) ? wp_ai_agent_tool_navigation( 'refund return exchange policy' ) : null;
        if ( null !== $nav && ! empty( $nav['message'] ) ) {
            return wp_ai_agent_tool_response(
                __( "Here's our refund & return information:", 'wp-ai-agent' ) . "\n\n" . $nav['message'] . $follow,
                array( 'source' => 'guide', 'intent' => 'how_to' )
            );
        }
        $info = function_exists( 'wp_ai_agent_tool_information' ) ? wp_ai_agent_tool_information( $message, 'how_to' ) : null;
        if ( null !== $info ) {
            return $info;
        }
        return wp_ai_agent_tool_response(
            __( "I couldn't find refund information on this website, but our team can help you with it directly.", 'wp-ai-agent' ),
            array( 'source' => 'guide', 'intent' => 'how_to', 'matched' => false, 'data' => array( 'actions' => array(
                array( 'label' => __( '📞 Contact support', 'wp-ai-agent' ), 'query' => 'contact information' ),
            ) ) )
        );
    }

    // --- Apply a coupon. ---
    if ( $has( '/\b(coupon|promo|voucher|discount code)\b/' ) ) {
        $msg = __( "Here's how to apply a coupon:", 'wp-ai-agent' ) . "\n"
            . __( "1. Add your items to the cart.\n2. Go to the Cart or Checkout page.\n3. Enter your code in the \"Coupon code\" box.\n4. Click Apply — your total updates with the discount.", 'wp-ai-agent' );
        if ( $cart ) {
            $msg .= "\n\n" . sprintf( __( 'Go to your cart: %s', 'wp-ai-agent' ), $cart );
        }
        return wp_ai_agent_tool_response( $msg, array( 'source' => 'guide', 'intent' => 'how_to', 'data' => array( 'actions' => array(
            array( 'label' => __( '🏷️ See active coupons', 'wp-ai-agent' ), 'query' => 'show active coupons' ),
        ) ) ) );
    }

    // --- Change / reset password. ---
    if ( $has( '/\bpassword\b/' ) ) {
        $msg  = __( "Here's how to change your password:", 'wp-ai-agent' ) . "\n"
            . __( "1. Log in and go to My Account → Account details.\n2. Enter your current password and a new one.\n3. Save changes.", 'wp-ai-agent' ) . "\n\n"
            . __( "Forgot it? Use the \"Lost your password?\" link on the login page to reset it by email.", 'wp-ai-agent' );
        $lost = function_exists( 'wp_lostpassword_url' ) ? wp_lostpassword_url() : '';
        if ( $lost ) {
            $msg .= "\n\n" . sprintf( __( 'Reset your password: %s', 'wp-ai-agent' ), $lost );
        }
        return wp_ai_agent_tool_response( $msg . $follow, array( 'source' => 'guide', 'intent' => 'how_to' ) );
    }

    // --- Update profile / account details / address. ---
    if ( $has( '/\b(profile|account detail|update.{0,15}(detail|info|address|profile)|my account)\b/' ) ) {
        $msg = __( "Here's how to update your details:", 'wp-ai-agent' ) . "\n"
            . __( "1. Log in to your account.\n2. Go to My Account → Account details (or Addresses).\n3. Update your information and save.", 'wp-ai-agent' );
        if ( $account ) {
            $msg .= "\n\n" . sprintf( __( 'Your account: %s', 'wp-ai-agent' ), $account );
        }
        return wp_ai_agent_tool_response( $msg . $follow, array( 'source' => 'guide', 'intent' => 'how_to' ) );
    }

    // --- Create account / register → reuse the auth tool (real link). ---
    if ( $has( '/\b(register|registration|sign ?up|create.{0,12}account)\b/' ) && function_exists( 'wp_ai_agent_tool_register' ) ) {
        return wp_ai_agent_tool_register( $page_url );
    }

    // --- Login → reuse the auth tool (real link). ---
    if ( $has( '/\b(log ?in|sign ?in)\b/' ) && function_exists( 'wp_ai_agent_tool_login' ) ) {
        return wp_ai_agent_tool_login( $page_url );
    }

    // --- Add to cart. ---
    if ( $has( '/\bcart\b/' ) && ! $has( '/\bcheck ?out\b/' ) ) {
        $msg = __( "Here's how to add items to your cart:", 'wp-ai-agent' ) . "\n"
            . __( "1. Open a product you like.\n2. Choose any options (size, colour) if asked.\n3. Click \"Add to Cart\".\n4. Open the cart to review your items.", 'wp-ai-agent' );
        if ( $cart ) {
            $msg .= "\n\n" . sprintf( __( 'View your cart: %s', 'wp-ai-agent' ), $cart );
        }
        $actions = array();
        if ( function_exists( 'wc_get_products' ) ) {
            $actions[] = array( 'label' => __( '🛍️ Browse products', 'wp-ai-agent' ), 'query' => 'show products' );
        }
        return wp_ai_agent_tool_response( $msg, array( 'source' => 'guide', 'intent' => 'how_to', 'data' => array( 'actions' => $actions ) ) );
    }

    // --- Checkout. ---
    if ( $has( '/\bcheck ?out\b/' ) ) {
        $msg = __( "Here's how to check out:", 'wp-ai-agent' ) . "\n"
            . __( "1. Open your cart and click \"Proceed to Checkout\".\n2. Enter your billing & shipping details.\n3. Choose a shipping method.\n4. Choose a payment method.\n5. Review everything and click \"Place Order\".", 'wp-ai-agent' );
        if ( $checkout ) {
            $msg .= "\n\n" . sprintf( __( 'Go to checkout: %s', 'wp-ai-agent' ), $checkout );
        }
        return wp_ai_agent_tool_response( $msg, array( 'source' => 'guide', 'intent' => 'how_to', 'data' => array( 'actions' => array(
            array( 'label' => __( '💳 Payment methods', 'wp-ai-agent' ), 'query' => 'payment methods' ),
        ) ) ) );
    }

    // --- Contact support → reuse the contact tool. ---
    if ( $has( '/\b(contact|reach (you|us|support)|customer (care|support|service))\b/' ) && function_exists( 'wp_ai_agent_tool_contact' ) ) {
        return wp_ai_agent_tool_contact( $message, $session_id, $page_url );
    }

    // --- Delivery / shipping → reuse the shipping tool. ---
    if ( $has( '/\b(ship|shipping|deliver|delivery)\b/' ) && function_exists( 'wp_ai_agent_tool_shipping' ) ) {
        return wp_ai_agent_tool_shipping( $message );
    }

    // --- Payment → reuse the payment tool. ---
    if ( $has( '/\b(pay|payment)\b/' ) && function_exists( 'wp_ai_agent_tool_payment' ) ) {
        return wp_ai_agent_tool_payment();
    }

    // --- Default: how to place an order (covers buy / purchase / order). ---
    $msg = __( "Here's how to place an order:", 'wp-ai-agent' ) . "\n"
        . __( "1. Browse our products.\n2. Open a product you like.\n3. Select the options (size, colour) if needed.\n4. Click \"Add to Cart\".\n5. Open your cart and click \"Proceed to Checkout\".\n6. Enter your shipping details.\n7. Choose your payment method.\n8. Click \"Place Order\".", 'wp-ai-agent' );
    if ( $shop ) {
        $msg .= "\n\n" . sprintf( __( 'Start browsing here: %s', 'wp-ai-agent' ), $shop );
    }
    $msg    .= "\n\n" . __( 'Would you like me to help you find a product first?', 'wp-ai-agent' );
    $actions = array();
    if ( function_exists( 'wc_get_products' ) ) {
        $actions[] = array( 'label' => __( '🛍️ Browse products', 'wp-ai-agent' ), 'query' => 'show products' );
    }
    $actions[] = array( 'label' => __( '💬 Help me choose', 'wp-ai-agent' ), 'query' => 'help me choose a product' );
    return wp_ai_agent_tool_response( $msg, array( 'source' => 'guide', 'intent' => 'how_to', 'data' => array( 'actions' => $actions ) ) );
}

/* -------------------------------------------------------------------------
 * Admin data getters (used by the dashboard pages).
 * ---------------------------------------------------------------------- */

/**
 * Fetch rows from an agent table with simple pagination.
 *
 * @param string $table    Fully-qualified table name.
 * @param int    $per_page Rows per page.
 * @param int    $page     Page number.
 * @return array{rows:object[],total:int,pages:int}
 */
function wp_ai_agent_fetch_rows( $table, $per_page = 20, $page = 1 ) {
    global $wpdb;
    $empty = array( 'rows' => array(), 'total' => 0, 'pages' => 0 );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return $empty;
    }
    $per_page = max( 1, (int) $per_page );
    $page     = max( 1, (int) $page );
    $offset   = ( $page - 1 ) * $per_page;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
    return array(
        'rows'  => $rows ? $rows : array(),
        'total' => $total,
        'pages' => (int) ceil( $total / $per_page ),
    );
}
