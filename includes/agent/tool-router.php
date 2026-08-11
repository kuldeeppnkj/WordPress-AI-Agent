<?php
/**
 * Tool Router.
 *
 * The agent's decision layer. For each message it:
 *   1. Continues any pending multi-step flow (order/lead/booking/ticket).
 *   2. Returns an admin-trained Q&A answer if one matches (highest priority).
 *   3. Detects intent, then routes to the matching tool and executes it.
 *   4. Falls back to website-content search for information intents.
 *
 * Returns a normalized response array consumed by the REST chat handler.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main agent entry point: turn a message into an executed response.
 *
 * @param string $message    User message.
 * @param string $session_id Visitor session id.
 * @param string $page_url   Page URL (unused here but kept for parity/future).
 * @return array Normalized response (message, source, intent, matched, pending).
 */
function wp_ai_agent_agent_respond( $message, $session_id, $page_url = '' ) {
    $message  = trim( (string) $message );
    $entities = wp_ai_agent_extract_entities( $message );

    // 1) Continue a pending multi-step flow first, so follow-up answers
    //    ("#1234", "John", "john@x.com") are routed back to the right tool —
    //    UNLESS the visitor has clearly changed the subject, in which case we
    //    abandon the stuck flow and route the new request (so the agent never
    //    keeps repeating "please share your order number").
    $state = wp_ai_agent_get_state( $session_id );
    if ( $state ) {
        if ( wp_ai_agent_is_cancel( $message ) ) {
            wp_ai_agent_clear_state( $session_id );
            return wp_ai_agent_tool_response( __( 'No problem, I have cancelled that. How else can I help?', 'wp-ai-agent' ), array( 'intent' => 'website_info', 'matched' => false ) );
        }

        if ( wp_ai_agent_flow_should_abandon( $state, $message, $entities ) ) {
            wp_ai_agent_clear_state( $session_id ); // topic changed — fall through to fresh routing.
        } else {
            $continued = wp_ai_agent_continue_flow( $state, $message, $session_id, $entities, $page_url );
            if ( null !== $continued ) {
                return $continued;
            }
        }
    }

    // 2) Admin-trained Q&A takes priority (rule: trained answers win).
    if ( function_exists( 'wp_ai_agent_match_custom_qa' ) ) {
        $qa = wp_ai_agent_match_custom_qa( $message );
        if ( '' !== $qa ) {
            return wp_ai_agent_tool_response( $qa, array( 'source' => 'qa', 'intent' => 'faq' ) );
        }
    }

    // 2b) Everyday small talk (greetings, thanks, how-are-you, who-are-you,
    // goodbye, acknowledgements) — reply like a human instead of falling through
    // to a "not found" message. Conversational courtesy, not outside knowledge.
    $smalltalk = wp_ai_agent_smalltalk_reply( $message );
    if ( '' !== $smalltalk ) {
        return wp_ai_agent_tool_response( $smalltalk, array( 'source' => 'smalltalk', 'intent' => 'smalltalk', 'matched' => true ) );
    }

    // 2c) Shopping conversation memory — continue refining the current product
    //     search when the follow-up is just a filter ("red", "only men's",
    //     "under $100", "cotton") or a request to compare the previous results.
    //     Carries the accumulated filters forward, like an in-store assistant.
    if ( function_exists( 'wp_ai_agent_maybe_continue_shopping' ) ) {
        $continued_shop = wp_ai_agent_maybe_continue_shopping( $message, $session_id, $entities );
        if ( null !== $continued_shop ) {
            return $continued_shop;
        }
    }

    // 3) Detect intent, then route to a tool.
    $detected = wp_ai_agent_detect_intent( $message );
    $intent   = $detected['intent'];

    /**
     * Allow overriding the detected intent / routing.
     *
     * @param string $intent   Detected intent.
     * @param string $message  Message.
     * @param array  $detected Full detection result.
     */
    $intent = apply_filters( 'wp_ai_agent_detected_intent', $intent, $message, $detected );

    // If the visitor volunteers an email or phone number without a stronger
    // intent (order/product/booking/etc.), treat it as a lead — they clearly
    // want our team to contact them, even if they never said "pricing".
    // BUT a bare number on its own (no words) is not a lead — it's usually a
    // mistaken/test entry, so we don't start collecting details from it.
    if (
        ( '' !== $entities['email'] || '' !== $entities['phone'] )
        && preg_match( '/\p{L}/u', $message ) // must contain actual words, not just digits
        && in_array( $intent, array( 'website_info', 'faq', 'contact_info', 'navigation' ), true )
    ) {
        $intent = 'lead_generation';
    }

    $response = wp_ai_agent_route_intent( $intent, $message, $session_id, $entities, $page_url );
    if ( null !== $response ) {
        // Keep the shopping memory in sync: a new product line resets the
        // filters; an unrelated topic (contact/payment/shipping/…) clears them.
        if ( function_exists( 'wp_ai_agent_update_shopping_context' ) ) {
            wp_ai_agent_update_shopping_context( $session_id, $message, $intent, $response );
        }
        return $response;
    }

    // 4) Nothing found on the website. If the admin enabled "General AI
    // Answers", let the AI reply from general knowledge (ChatGPT-style);
    // otherwise return the website-only not-found message.
    if (
        '1' === wp_ai_agent_option( 'allow_general_ai', '0' )
        && class_exists( 'WP_AI_Agent_AI_Engine' )
        && function_exists( 'wp_ai_agent_is_provider_configured' ) && wp_ai_agent_is_provider_configured()
    ) {
        $engine = new WP_AI_Agent_AI_Engine();
        $answer = $engine->ask( $message, '', 'general' );
        if ( is_string( $answer ) && '' !== trim( $answer ) ) {
            return wp_ai_agent_tool_response( $answer, array( 'intent' => $intent, 'matched' => true, 'source' => 'ai' ) );
        }
    }

    // 4b) Low-confidence / nothing matched: if a WhatsApp number is configured,
    // suggest continuing on WhatsApp with a human instead of a flat dead-end.
    if ( '' !== wp_ai_agent_whatsapp_url( '' ) ) {
        return wp_ai_agent_tool_human(
            $message,
            $session_id,
            $page_url,
            __( "I'm not sure I have the answer to that. 🤔 Would you like to continue this conversation on WhatsApp with our team?", 'wp-ai-agent' )
        );
    }

    // 5) Final fallback: website-only not-found, with helpful next-step buttons
    //    so the conversation never dead-ends.
    return wp_ai_agent_not_found_response( $intent );
}

/**
 * Decide whether to abandon the current pending flow because the visitor has
 * changed the subject. Prevents the agent from getting stuck repeating a prompt
 * (e.g. asking for an order number while the user is now searching products).
 *
 * @param array  $state    Pending state.
 * @param string $message  User message.
 * @param array  $entities Extracted entities.
 * @return bool
 */
function wp_ai_agent_flow_should_abandon( $state, $message, $entities ) {
    // If the message supplies the data this step is waiting for, keep the flow.
    if ( wp_ai_agent_flow_provides_data( $state, $message, $entities ) ) {
        return false;
    }

    // Otherwise the message is NOT the answer we asked for — it's a different
    // request (very often a product/shopping question like "do you have tops").
    // Abandon the stuck flow so the assistant answers what the visitor actually
    // asked, instead of swallowing it as a name/email.
    return true;
}

/**
 * Whether a message looks like the answer the current flow step expects.
 *
 * @param array  $state    Pending state (flow/step/data).
 * @param string $message  User message.
 * @param array  $entities Extracted entities.
 * @return bool
 */
function wp_ai_agent_flow_provides_data( $state, $message, $entities ) {
    $flow = isset( $state['flow'] ) ? $state['flow'] : '';
    $step = isset( $state['step'] ) ? $state['step'] : '';
    $data = ( isset( $state['data'] ) && is_array( $state['data'] ) ) ? $state['data'] : array();
    $awaiting = isset( $data['awaiting'] ) ? $data['awaiting'] : '';

    // Order tracking needs a number (or an email at the confirmation step), so
    // a non-data message can switch topics.
    if ( 'order' === $flow ) {
        if ( 'await_email' === $step ) {
            return '' !== $entities['email'];
        }
        return '' !== $entities['order_number'] || (bool) preg_match( '/\b\d{2,}\b/', $message );
    }

    // Freeform flows (login / register / lead / booking / ticket). If the
    // message is the exact data type we asked for, keep the flow.
    if ( 'email' === $awaiting && '' !== $entities['email'] ) {
        return true;
    }
    if ( 'phone' === $awaiting && ( '' !== $entities['phone'] || preg_match( '/\d{5,}/', $message ) ) ) {
        return true;
    }

    // Let the visitor escape the flow when they clearly ask for a different
    // action (e.g. "talk to human", "track my order", "show products"). This
    // keyword test avoids the bare-number/password false positives that a full
    // intent scan would cause on data answers.
    if ( preg_match( '/\b(talk to (a )?(human|person|agent|someone)|human|agent|customer (care|support|service)|live (agent|chat)|need support|track(ing)?|my orders?|order status|appointment|booking|complaint|refund|products?|buy|buying|pricing|price|shop|cart|basket|payment|coupon|shipping|contact|do you (have|sell|stock|carry|offer)|have you got|looking for|show (me|us)|browse|i want (to buy|to see|a))\b/i', $message ) ) {
        return false;
    }

    // Otherwise treat the message as the answer to the current question. (The
    // visitor can also type "cancel" to exit — handled earlier in the router.)
    return true;
}

/**
 * Continue an in-progress flow. Returns null if the flow is unknown.
 *
 * @param array  $state      Pending state.
 * @param string $message    User message.
 * @param string $session_id Session id.
 * @param array  $entities   Extracted entities.
 * @return array|null
 */
function wp_ai_agent_continue_flow( $state, $message, $session_id, $entities, $page_url = '' ) {
    $flow = isset( $state['flow'] ) ? $state['flow'] : '';
    $data = isset( $state['data'] ) ? (array) $state['data'] : array();

    switch ( $flow ) {
        case 'order':
            // Two-step: awaiting order number, or awaiting email confirmation.
            return wp_ai_agent_order_continue( $state, $message, $session_id, $entities );

        case 'lead':
            return wp_ai_agent_tool_lead( $message, $session_id, $data, $entities, false, $page_url );

        case 'booking':
            return wp_ai_agent_tool_booking( $message, $session_id, $data, $entities, false );

        case 'ticket':
            return wp_ai_agent_tool_ticket( $message, $session_id, $data, $entities, false );
    }

    return null;
}

/**
 * Route a detected intent to its tool. Returns null when the tool found nothing
 * (so the caller can apply the global fallback).
 *
 * @param string $intent     Intent.
 * @param string $message    Message.
 * @param string $session_id Session id.
 * @param array  $entities   Extracted entities.
 * @return array|null
 */
function wp_ai_agent_route_intent( $intent, $message, $session_id, $entities, $page_url = '' ) {
    // Directory listings first: on a non-store vertical (restaurant, clinic,
    // school, hotel, agency, blog, …) a request for that site's main listing
    // (menu, doctors, courses, rooms, services, articles, …) is answered with
    // real CPT items as cards. It self-gates by type + keywords, so it is a
    // no-op on plain stores and never hijacks unrelated questions.
    if ( function_exists( 'wp_ai_agent_tool_directory' ) ) {
        $directory = wp_ai_agent_tool_directory( $message );
        if ( null !== $directory ) {
            return $directory;
        }
    }

    // Smart module gating: on a NON-store website, never run commerce tools —
    // there are no Products, Cart, Orders, Coupons, etc. Such a query is answered
    // from the site's own content / pages instead, so the assistant stays on-brand
    // for that kind of website (business, medical, restaurant, blog, …).
    $store_only = array(
        'product_search', 'product_recommendation', 'product_comparison',
        'product_browse', 'product_sale', 'cart_view', 'order_tracking',
        'coupons', 'catalog', 'category_discovery', 'shopping_help', 'clarify_number',
    );
    if (
        in_array( $intent, $store_only, true )
        && function_exists( 'wp_ai_agent_commerce_enabled' ) && ! wp_ai_agent_commerce_enabled()
    ) {
        $info = wp_ai_agent_tool_information( $message, 'website_info' );
        if ( null !== $info ) {
            return $info;
        }
        $nav = wp_ai_agent_tool_navigation( $message );
        return ( null !== $nav ) ? $nav : null;
    }

    switch ( $intent ) {
        case 'login':
            return wp_ai_agent_tool_login( $page_url );

        case 'admin_login':
            // Explicit administrator request only — customers never reach this.
            return wp_ai_agent_tool_admin_login();

        case 'register':
            return wp_ai_agent_tool_register( $page_url );

        case 'logout':
            return wp_ai_agent_tool_logout();

        case 'account':
            return wp_ai_agent_tool_account();

        case 'my_bookings':
            return wp_ai_agent_tool_my_bookings();

        case 'catalog':
            $cat = wp_ai_agent_tool_catalog( $message );
            return ( null !== $cat ) ? $cat : wp_ai_agent_tool_information( $message, $intent );

        case 'category_discovery':
            // Show the store's CATEGORIES only (scoped to a parent if named) — a
            // follow-up tap then shows that category's products. Never products.
            $cats = function_exists( 'wp_ai_agent_tool_categories' ) ? wp_ai_agent_tool_categories( $message ) : null;
            if ( null !== $cats ) {
                return $cats;
            }
            // No product categories on this site — fall back to catalog/content.
            $cat = wp_ai_agent_tool_catalog( $message );
            return ( null !== $cat ) ? $cat : wp_ai_agent_tool_information( $message, $intent );

        case 'coupons':
            return wp_ai_agent_tool_coupons();

        case 'feedback':
            // Customer feedback / objection — respond like a sales rep and keep
            // the conversation going (never a failed product search).
            return wp_ai_agent_tool_feedback( $message, $session_id, $page_url );

        case 'payment':
            return wp_ai_agent_tool_payment();

        case 'how_to':
            // Step-by-step guidance for completing a task on the site.
            return wp_ai_agent_tool_howto( $message, $session_id, $page_url );

        case 'shopping_help':
            // Confused shopper — guide them with consultative questions.
            return wp_ai_agent_tool_shopping_help();

        case 'contact_info':
            // Dedicated contact tool runs BEFORE any product attempt, so a
            // "customer care email?" question never returns products.
            return wp_ai_agent_tool_contact( $message, $session_id, $page_url );

        case 'social':
            // Brand social profiles (Facebook / Instagram / X / … ) + newsletter,
            // discovered dynamically from the website.
            return wp_ai_agent_tool_social( $message );

        case 'business_info':
            // Company / brand / "about us" question → answer from About / homepage
            // / site overview content, never a product listing.
            $bi = wp_ai_agent_tool_business_info( $message );
            if ( null !== $bi ) {
                return $bi;
            }
            $info = wp_ai_agent_tool_information( $message, 'website_info' );
            return ( null !== $info ) ? $info : wp_ai_agent_tool_navigation( $message );

        case 'products_overview':
            // "Tell me about your products" → a natural summary of the range +
            // category options, NOT an immediate product listing.
            $po = function_exists( 'wp_ai_agent_tool_products_overview' ) ? wp_ai_agent_tool_products_overview() : null;
            if ( null !== $po ) {
                return $po;
            }
            // No store → describe the business / site instead.
            $bi = wp_ai_agent_tool_business_info( $message );
            if ( null !== $bi ) {
                return $bi;
            }
            return wp_ai_agent_tool_information( $message, 'website_info' );

        case 'shipping':
            return wp_ai_agent_tool_shipping( $message );

        case 'product_sale':
            return wp_ai_agent_tool_sale_products( $message, (int) apply_filters( 'wp_ai_agent_card_count', 6 ) );

        case 'product_browse':
            // Cheapest / most expensive / premium / featured / best sellers /
            // top rated / new arrivals — queried live from WooCommerce.
            $browse = wp_ai_agent_tool_browse_products( $message );
            if ( null !== $browse ) {
                return $browse;
            }
            // No browse mode resolved — fall back to a normal product search.
            $product = wp_ai_agent_tool_product( $message, 'product_search', true, false );
            return ( null !== $product ) ? $product : wp_ai_agent_tool_information( $message, $intent );

        case 'human_support':
            return wp_ai_agent_tool_human( $message, $session_id, $page_url );

        case 'cart_view':
            $cart = wp_ai_agent_tool_cart();
            return ( null !== $cart ) ? $cart : wp_ai_agent_tool_information( $message, $intent );

        case 'clarify_number':
            // A bare number is ambiguous — ask whether it's an order or a price.
            // Truncate defensively so an oversized number can never overflow the reply.
            $num = substr( preg_replace( '/\D/', '', $message ), 0, 10 );
            return wp_ai_agent_tool_response(
                sprintf( __( 'Just to confirm — did you mean order #%1$s, or products around %1$s?', 'wp-ai-agent' ), $num ),
                array(
                    'intent' => 'clarify_number',
                    'matched' => true,
                    'data'   => array( 'actions' => array(
                        array( 'label' => sprintf( __( '📦 Track order #%s', 'wp-ai-agent' ), $num ), 'query' => 'order #' . $num ),
                        array( 'label' => sprintf( __( '🛍️ Products under %s', 'wp-ai-agent' ), $num ), 'query' => 'products under ' . $num ),
                    ) ),
                )
            );

        case 'order_tracking':
            return wp_ai_agent_tool_order( $message, $session_id, $entities );

        case 'support_request':
            return wp_ai_agent_tool_ticket( $message, $session_id, array(), $entities, true );

        case 'booking':
            return wp_ai_agent_tool_booking( $message, $session_id, array(), $entities, true );

        case 'lead_generation':
            return wp_ai_agent_tool_lead( $message, $session_id, array(), $entities, true, $page_url );

        case 'product_comparison':
            // Compare two named products with a website-data table + recommendation.
            $cmp = wp_ai_agent_tool_compare( $message );
            if ( null !== $cmp ) {
                return $cmp;
            }
            // WooCommerce unavailable — fall through to a normal product reply.
            $product = wp_ai_agent_tool_product( $message, $intent, true, true );
            return ( null !== $product ) ? $product : wp_ai_agent_tool_information( $message, $intent );

        case 'product_search':
        case 'product_recommendation':
            // Genuine product intent: featured fallback for generic asks, and an
            // honest "not available + our categories" reply for a specific item
            // the store doesn't carry.
            $product = wp_ai_agent_tool_product( $message, $intent, true, true );
            if ( null !== $product ) {
                return $product;
            }
            // No products at all — try website info before giving up.
            return wp_ai_agent_tool_information( $message, $intent );

        case 'navigation':
            // Page lookups (refund/return/privacy/terms/shipping/about/contact)
            // return the page title + clickable URL directly.
            $nav = wp_ai_agent_tool_navigation( $message );
            if ( null !== $nav ) {
                return $nav;
            }
            return wp_ai_agent_tool_information( $message, $intent );

        case 'faq':
        case 'website_info':
        default:
            // A policy/page-style question? Return the page link first.
            if ( preg_match( '/\b(policy|policies|refund|return|privacy|terms|shipping|cancellation|exchange)\b/i', $message ) ) {
                $nav = wp_ai_agent_tool_navigation( $message );
                if ( null !== $nav ) {
                    return $nav;
                }
            }
            $wants_product = function_exists( 'wp_ai_agent_looks_like_product_request' ) && wp_ai_agent_looks_like_product_request( $message );

            // Is this phrased as an information / how-to QUESTION ("what is…",
            // "how do I look after my socks", "tell me about…")? If so — and it
            // isn't an explicit product request — search the website content
            // FIRST, so a care/instruction question returns the relevant page
            // (e.g. a "Product Care" page) rather than any product that merely
            // shares a word (e.g. a sock for "look after my socks").
            $is_info_question = (bool) preg_match(
                '/^\s*(what\s+(is|are|\'s|s|does|do)|whats|how\s+(do|can|to|should|would|does|could|may|long|often)|how to|tell me about|tell me more|explain|describe|who\s+(is|are)|define|meaning of|what do you mean|guide me on)\b/i',
                $message
            );

            if ( $is_info_question && ! $wants_product ) {
                $info = wp_ai_agent_tool_information( $message, $intent );
                if ( null !== $info ) {
                    return $info;
                }
                // Page-by-title match (robust for named sections like "about",
                // "careers", "ambassadors") even if the body wasn't indexed.
                $nav = wp_ai_agent_tool_navigation( $message );
                if ( null !== $nav ) {
                    return $nav;
                }
                // Nothing in the content index — only then consider products.
                $product = wp_ai_agent_tool_product( $message, 'product_search', false, false );
                return ( null !== $product ) ? $product : null;
            }

            // Browse / product-style phrasing: try a direct WooCommerce product
            // match first. If the message expresses wanting an item ("i want
            // shoes pair"), an unavailable type gets the honest "not available +
            // categories" reply (so general AI never rambles about products we
            // don't sell).
            $product = wp_ai_agent_tool_product( $message, 'product_search', false, $wants_product );
            if ( null !== $product ) {
                return $product;
            }

            $info = wp_ai_agent_tool_information( $message, $intent );
            if ( null !== $info ) {
                return $info;
            }
            // Last resort within this route: match a PAGE by its title, so a
            // navigational query ("ambassadors", "careers", "company", "sizing")
            // returns that page even when its body content isn't in the index
            // (e.g. a builder/mega-menu page). Deterministic — no LLM needed.
            $nav = wp_ai_agent_tool_navigation( $message );
            if ( null !== $nav ) {
                return $nav;
            }
            return null;
    }
}

/* -------------------------------------------------------------------------
 * Shopping conversation memory (context-aware follow-ups).
 * ---------------------------------------------------------------------- */

/**
 * Extract the product FACETS present in a single message: product type
 * keywords, colour(s), gender, size(s), material, price bounds, and sort.
 *
 * @param string $message User message.
 * @return array{type:string,colors:array,gender:string,sizes:array,material:string,min:?float,max:?float,sort:string}
 */
function wp_ai_agent_extract_facets( $message ) {
    $filters = function_exists( 'wp_ai_agent_extract_product_filters' )
        ? wp_ai_agent_extract_product_filters( $message )
        : array( 'colors' => array(), 'genders' => array(), 'sizes' => array() );
    $flags = function_exists( 'wp_ai_agent_wc_parse_intent' )
        ? wp_ai_agent_wc_parse_intent( strtolower( (string) $message ) )
        : array( 'min' => null, 'max' => null, 'order' => '' );

    // Material — only the fabrics that are "generic descriptors" (so they never
    // double as a product type). A distinctive material like "merino" is treated
    // as a product type instead (a new search), not a filter.
    $material = '';
    foreach ( array( 'cotton', 'silk', 'leather', 'denim', 'wool', 'linen', 'polyester', 'suede' ) as $mat ) {
        if ( preg_match( '/\b' . $mat . '\b/i', (string) $message ) ) {
            $material = $mat;
            break;
        }
    }

    // Product-type keywords = meaningful keywords minus generic descriptors.
    $type = '';
    if ( function_exists( 'wp_ai_agent_wc_query_keywords' ) && function_exists( 'wp_ai_agent_generic_terms' ) ) {
        $kw   = array_values( array_diff( wp_ai_agent_wc_query_keywords( $message ), wp_ai_agent_generic_terms() ) );
        $type = trim( implode( ' ', $kw ) );
    }

    $sort = '';
    if ( 'ASC' === $flags['order'] || preg_match( '/\b(cheap|cheaper|cheapest|lower price|budget|affordable)\b/i', $message ) ) {
        $sort = 'cheapest';
    } elseif ( 'DESC' === $flags['order'] || preg_match( '/\b(expensive|premium|higher price|costlier|dearer|high[- ]?end)\b/i', $message ) ) {
        $sort = 'expensive';
    }

    return array(
        'type'     => $type,
        'colors'   => isset( $filters['colors'] ) ? $filters['colors'] : array(),
        'gender'   => ( ! empty( $filters['genders'] ) ) ? $filters['genders'][0] : '',
        'sizes'    => isset( $filters['sizes'] ) ? $filters['sizes'] : array(),
        'material' => $material,
        'min'      => $flags['min'],
        'max'      => $flags['max'],
        'sort'     => $sort,
    );
}

/**
 * Whether a facet set carries at least one concrete filter value.
 *
 * @param array $f Facets.
 * @return bool
 */
function wp_ai_agent_facets_has_value( $f ) {
    return ( ! empty( $f['colors'] ) || '' !== $f['gender'] || ! empty( $f['sizes'] )
        || '' !== $f['material'] || null !== $f['min'] || null !== $f['max'] || '' !== $f['sort'] );
}

/**
 * Merge new facets over the stored context — replace only the facets the visitor
 * changed, keep everything else (filter preservation).
 *
 * @param array $ctx Existing context.
 * @param array $new New facets from the follow-up.
 * @return array
 */
function wp_ai_agent_merge_facets( $ctx, $new ) {
    $m = is_array( $ctx ) ? $ctx : array();
    if ( ! empty( $new['type'] ) ) {
        $m['type'] = $new['type'];
    }
    if ( ! empty( $new['colors'] ) ) {
        $m['colors'] = $new['colors'];
    }
    if ( '' !== $new['gender'] ) {
        $m['gender'] = $new['gender'];
    }
    if ( ! empty( $new['sizes'] ) ) {
        $m['sizes'] = $new['sizes'];
    }
    if ( '' !== $new['material'] ) {
        $m['material'] = $new['material'];
    }
    if ( null !== $new['min'] ) {
        $m['min'] = $new['min'];
    }
    if ( null !== $new['max'] ) {
        $m['max'] = $new['max'];
    }
    if ( '' !== $new['sort'] ) {
        $m['sort'] = $new['sort'];
    }
    return $m;
}

/**
 * Rebuild a natural-language product query from the accumulated context, so it
 * can be fed straight into the existing product search pipeline (which already
 * enforces colour / gender / size / price as hard filters).
 *
 * @param array $ctx Context.
 * @return string
 */
function wp_ai_agent_build_shop_query( $ctx ) {
    $parts = array();
    if ( ! empty( $ctx['gender'] ) ) {
        $parts[] = $ctx['gender'];
    }
    if ( ! empty( $ctx['colors'] ) ) {
        $parts = array_merge( $parts, (array) $ctx['colors'] );
    }
    if ( ! empty( $ctx['material'] ) ) {
        $parts[] = $ctx['material'];
    }
    if ( ! empty( $ctx['sizes'] ) ) {
        $parts[] = $ctx['sizes'][0];
    }
    if ( ! empty( $ctx['type'] ) ) {
        $parts[] = $ctx['type'];
    }
    $q = trim( implode( ' ', $parts ) );

    if ( isset( $ctx['sort'] ) && 'cheapest' === $ctx['sort'] ) {
        $q .= ' cheapest';
    } elseif ( isset( $ctx['sort'] ) && 'expensive' === $ctx['sort'] ) {
        $q .= ' expensive';
    }

    $min = isset( $ctx['min'] ) ? $ctx['min'] : null;
    $max = isset( $ctx['max'] ) ? $ctx['max'] : null;
    if ( null !== $min && null !== $max ) {
        $q .= ' between ' . (int) $min . ' and ' . (int) $max;
    } elseif ( null !== $max ) {
        $q .= ' under ' . (int) $max;
    } elseif ( null !== $min ) {
        $q .= ' over ' . (int) $min;
    }

    return trim( $q );
}

/**
 * Store the products just shown, so "compare them" / "compare the first two"
 * can reference the previous results.
 *
 * @param array $ctx      Context (modified copy returned).
 * @param array $response A tool response.
 * @return array
 */
function wp_ai_agent_shop_context_store_results( $ctx, $response ) {
    if ( ! empty( $response['data']['products'] ) && is_array( $response['data']['products'] ) ) {
        $results = array();
        foreach ( array_slice( $response['data']['products'], 0, 6 ) as $c ) {
            if ( ! empty( $c['name'] ) ) {
                $results[] = array( 'id' => isset( $c['id'] ) ? (int) $c['id'] : 0, 'name' => $c['name'] );
            }
        }
        if ( ! empty( $results ) ) {
            $ctx['results'] = $results;
        }
    }
    return $ctx;
}

/**
 * Continue an in-progress shopping conversation. Handles a "compare them" style
 * follow-up on the previous results, and pure filter refinements ("red", "only
 * men's", "under $100", "cotton") that must refine — not restart — the current
 * search. Returns null when the message is not such a follow-up.
 *
 * @param string $message    User message.
 * @param string $session_id Session id.
 * @param array  $entities   Extracted entities.
 * @return array|null
 */
function wp_ai_agent_maybe_continue_shopping( $message, $session_id, $entities ) {
    if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wp_ai_agent_get_shop_context' ) ) {
        return null;
    }
    $ctx = wp_ai_agent_get_shop_context( $session_id );
    if ( empty( $ctx ) ) {
        return null; // No active shopping conversation.
    }

    $facets = wp_ai_agent_extract_facets( $message );

    // Comparison follow-up on the previous results ("compare them / the first two").
    if (
        ! empty( $ctx['results'] ) && is_array( $ctx['results'] ) && count( $ctx['results'] ) >= 2
        && function_exists( 'wp_ai_agent_tool_compare' )
        && preg_match( '/\bcompare\b/i', $message )
        && preg_match( '/\b(them|these|those|the (first )?two|first two|both|first (one|and)|1 (and|&) 2)\b/i', $message )
    ) {
        $a   = $ctx['results'][0]['name'];
        $b   = $ctx['results'][1]['name'];
        $cmp = wp_ai_agent_tool_compare( 'compare ' . $a . ' vs ' . $b );
        if ( null !== $cmp ) {
            return $cmp;
        }
    }

    // Pure filter refinement: no NEW product type named, but a concrete facet is
    // present. (A new type keyword means a fresh search — handled by normal
    // routing — so we bail here.)
    if ( '' === $facets['type'] && wp_ai_agent_facets_has_value( $facets ) ) {
        $merged = wp_ai_agent_merge_facets( $ctx, $facets );
        $query  = wp_ai_agent_build_shop_query( $merged );
        if ( '' === $query ) {
            return null;
        }
        $resp = function_exists( 'wp_ai_agent_tool_product' )
            ? wp_ai_agent_tool_product( $query, 'product_search', true, true )
            : null;
        if ( null !== $resp ) {
            $merged = wp_ai_agent_shop_context_store_results( $merged, $resp );
            wp_ai_agent_set_shop_context( $session_id, $merged );
            $resp['intent'] = 'product_search';
            return $resp;
        }
    }

    return null;
}

/**
 * Keep the shopping memory in sync after a normal routed response: a new product
 * line RESETS the filters to what was just asked; an unrelated topic (contact,
 * payment, shipping, policy, social, orders, …) CLEARS the shopping context so
 * product filters never leak into website questions.
 *
 * @param string $session_id Session id.
 * @param string $message    User message.
 * @param string $intent     Detected intent.
 * @param array  $response   The routed response.
 * @return void
 */
function wp_ai_agent_update_shopping_context( $session_id, $message, $intent, $response ) {
    if ( ! function_exists( 'wp_ai_agent_get_shop_context' ) ) {
        return;
    }

    // A clear topic change → wipe the shopping memory so product filters never
    // leak into unrelated website questions. Keyed on INTENT (a payment reply,
    // for example, uses the 'woocommerce' source but is not a product listing).
    $topic_intents = array(
        'contact_info', 'payment', 'shipping', 'faq', 'navigation', 'social',
        'order_tracking', 'booking', 'support_request', 'human_support', 'coupons',
        'account', 'my_bookings', 'login', 'register', 'logout', 'admin_login',
        'business_info',
    );
    if ( in_array( $intent, $topic_intents, true ) ) {
        wp_ai_agent_clear_shop_context( $session_id );
        return;
    }

    // A genuine product listing (WooCommerce cards) → set / refresh the context.
    $source       = isset( $response['source'] ) ? $response['source'] : '';
    $has_products = ! empty( $response['data']['products'] );
    if ( 'woocommerce' === $source && $has_products ) {
        $facets = wp_ai_agent_extract_facets( $message );
        $ctx    = wp_ai_agent_get_shop_context( $session_id );
        // A new product type (or no context yet) starts a fresh filter set;
        // otherwise keep the accumulated filters.
        if ( empty( $ctx ) || '' !== $facets['type'] ) {
            $ctx = $facets;
        }
        $ctx = wp_ai_agent_shop_context_store_results( $ctx, $response );
        wp_ai_agent_set_shop_context( $session_id, $ctx );
    }
    // Anything else (info answers, smalltalk-ish) leaves the context untouched.
}
