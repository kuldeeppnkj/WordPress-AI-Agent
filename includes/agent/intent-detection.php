<?php
/**
 * Intent Detection Engine.
 *
 * Classifies a visitor message into one of the supported agent intents BEFORE
 * any response is generated, and extracts useful entities (order number, email,
 * phone). Detection is rule-based (fast, deterministic, no API cost) and tuned
 * for English + romanized Hindi/Hinglish, with an optional LLM fallback for
 * ambiguous messages.
 *
 * The router uses the detected intent to pick a tool.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The canonical list of supported intents.
 *
 * @return string[]
 */
function wp_ai_agent_intents() {
    return array(
        'login',                  // sign in (customer)
        'admin_login',            // administrator / wp-admin access (explicit only)
        'register',               // create account
        'logout',                 // sign out
        'account',                // my account / profile
        'my_bookings',            // my appointments / bookings
        'catalog',                // how many products/categories/brands…
        'category_discovery',     // what categories/collections do you have (list categories, NOT products)
        'coupons',                // active coupons / promo codes
        'feedback',               // objections / dislikes / "show me something else"
        'product_browse',         // cheapest / expensive / featured / top rated / new arrivals
        'payment',                // accepted payment methods / gateways
        'how_to',                 // how do I order / checkout / track / refund…
        'shopping_help',          // confused shopper — guide them to a product
        'shipping',               // shipping / delivery info
        'product_sale',           // products on sale / discounted
        'human_support',          // talk to a human / agent
        'cart_view',              // show my cart / basket
        'clarify_number',         // bare number — order # or price?
        'order_tracking',         // where is my order / status
        'support_request',        // complaint / damaged / not working
        'booking',                // appointment / schedule
        'lead_generation',        // consultation / quote / contact sales
        'product_comparison',     // compare A vs B
        'product_recommendation', // best/suggest X for Y
        'product_search',         // find products, price filters
        'navigation',             // open/show a page
        'contact_info',           // phone / email / address
        'faq',                    // frequently asked
        'website_info',           // general info (default)
    );
}

/**
 * Detect the intent of a message.
 *
 * @param string $message User message.
 * @return array{intent:string,confidence:float,entities:array}
 */
function wp_ai_agent_detect_intent( $message ) {
    $raw = trim( (string) $message );
    $m   = ' ' . strtolower( $raw ) . ' ';

    $entities = wp_ai_agent_extract_entities( $raw );

    // Helper: does the message contain any of these substrings/patterns?
    $has = function ( $patterns ) use ( $m ) {
        foreach ( (array) $patterns as $p ) {
            if ( false !== strpos( $m, ' ' . $p . ' ' ) || false !== strpos( $m, $p ) ) {
                return true;
            }
        }
        return false;
    };

    $result = function ( $intent, $confidence ) use ( $entities ) {
        return array(
            'intent'     => $intent,
            'confidence' => (float) $confidence,
            'entities'   => $entities,
        );
    };

    // 0) Authentication — login / register / logout / account / my bookings.
    if ( preg_match( '/^(log\s?out|sign\s?out|logout)\b/i', $raw ) ) {
        return $result( 'logout', 0.95 );
    }
    // Administrator access — ONLY when explicitly requested. Detected before the
    // customer login/register rules so a normal "login" never reaches wp-admin.
    if ( preg_match( '/\b(wp[- ]?admin|wp[- ]?login|wordpress (admin|login|dashboard|backend|back end)|admin (login|panel|dashboard|access|area)|administrator (login|access|panel|dashboard)|i am (the |a )?(website |site )?(administrator|admin|owner)|site owner|admin area|back[- ]?end login)\b/i', $raw ) ) {
        return $result( 'admin_login', 0.9 );
    }
    if ( preg_match( '/\b(register|sign\s?up|signup|create (an )?account|new account|registration|account banao)\b/i', $raw ) ) {
        return $result( 'register', 0.92 );
    }
    if ( preg_match( '/^(login|log\s?in|sign\s?in)\b/i', $raw ) || preg_match( '/\b(log me in|sign me in|i want to (login|log in|sign in))\b/i', $raw ) ) {
        return $result( 'login', 0.92 );
    }
    if ( preg_match( '/\b(my (appointment|appointments|booking|bookings)|show my (appointment|booking)|meri booking|booking history|appointment history)\b/i', $raw ) ) {
        return $result( 'my_bookings', 0.9 );
    }
    if ( preg_match( '/\b(my account|my profile|account (info|information|details)|profile (info|details)|mera account)\b/i', $raw ) ) {
        return $result( 'account', 0.88 );
    }

    // 0a-2) Category discovery — the visitor wants to SEE the store's categories /
    //       collections / departments (or "what types of products do you sell"),
    //       NOT products and NOT a count. Answered with the category list only; a
    //       follow-up tap then shows that category's products. Excludes count cues
    //       ("how many …") so those still fall to catalog stats below.
    // An explicit request to SEE PRODUCTS ("show me products in X", "products
    // under $50") is a product search even if it mentions the word "category" —
    // so it must NOT be swallowed by category discovery.
    $is_product_request = (bool) (
        preg_match( '/\b(show|see|find|list|display|browse|view|give|get|buy|shop|want|need)\b.{0,30}\bproducts?\b/i', $raw )
        || preg_match( '/\bproducts?\b.{0,15}\b(in|under|from|within|for|below|over|above|between)\b/i', $raw )
    );
    if (
        ! $is_product_request
        && ! preg_match( '/\b(how many|how much|number of|total|count|kitne|kitni|kitna)\b/i', $raw )
        && (
            preg_match( '/\b(categor(?:y|ies)|collections?|departments?|sections?)\b/i', $raw )
            || preg_match( '/\b(types?|kinds?|sorts?|range)\s+of\s+(products?|items?|things?|goods?|stuff|gear)\b/i', $raw )
            || preg_match( '/\bwhat\s+(?:do|does|kind|type)s?.{0,20}\b(you|u)\b.{0,10}\b(sell|offer|stock|carry|have)\b/i', $raw )
        )
    ) {
        return $result( 'category_discovery', 0.9 );
    }

    // 0b) Catalog stats — counts / lists of brands & other taxonomies, and any
    //     "how many …" count question. (Category *listing* is handled just above.)
    if (
        ( preg_match( '/\b(how many|how much|number of|total|count|kitne|kitni|kitna)\b/i', $raw )
            && preg_match( '/\b(products?|items?|categor|collection|brands?|posts?|blogs?|articles?|faqs?|pages?)\b/i', $raw ) )
        || preg_match( '/\b(which|what|list|name the|show me the)\b.{0,20}\b(brands?)\b/i', $raw )
    ) {
        return $result( 'catalog', 0.9 );
    }

    // 0b-2) Business Information / Company Overview — "tell me about your company/
    //        brand", "what does your company do", "what is this website", "what
    //        makes you different". Answered from About/homepage/overview content,
    //        NEVER products. Brand-name match is dynamic (via the site title), so
    //        nothing is hardcoded.
    $site_name = get_bloginfo( 'name' );
    if (
        preg_match( '/\b(about (your|the|this|our) (company|brand|business|store|shop|website|site|organisation|organization)|about us|company (profile|overview|info|information|background|history|story)|what (is|does) (your|this|the) (company|business|brand)|what does (your |the |this )?(company|business|brand) do|what is this (website|site|company|business|brand|store|shop)|tell me about (this|your|the|our) (website|site|company|business|brand|store|shop)|your (mission|vision|story|history|values)|what makes (you|your company|your brand|this brand) (different|unique|special|stand out)|why (should i )?(choose|buy from|shop with) (you|us))\b/i', $raw )
        || ( $site_name && strlen( $site_name ) >= 4 && preg_match( '/\b(about|tell me about|what is|who is|know more about|info(rmation)? about)\b/i', $raw ) && false !== stripos( $raw, $site_name ) )
    ) {
        return $result( 'business_info', 0.9 );
    }

    // 0b-3) Products Overview — "tell me about your products", "what products do
    //        you sell", "your product range". A natural SUMMARY of the range plus
    //        category options — NOT an immediate product listing. (Explicit "show
    //        products / socks / under $100" still goes to product search below.)
    if ( preg_match( '/\b(tell me (more )?about (your |the |our )?products|what (products|items) do (you|u) (sell|have|offer|stock|carry|make)|your product (range|line|lineup|catalog|catalogue|selection|portfolio)|product (range|overview|catalogue|catalog|selection)|describe (your )?products|overview of (your )?products|what all do (you|u) sell)\b/i', $raw ) ) {
        return $result( 'products_overview', 0.88 );
    }

    // 0c) Coupons / promo codes.
    if ( preg_match( '/\b(coupon|coupons|promo ?codes?|voucher|discount codes?|active (coupon|offer|discount|deal|promo)|any (coupon|offer|deal|discount|promo))\b/i', $raw ) ) {
        return $result( 'coupons', 0.9 );
    }

    // 0c-2) Customer feedback / objections / vague browsing. Detected BEFORE the
    //        shopping intents so "too expensive" routes to cheaper options (not a
    //        list of expensive products) and "I don't like these" is handled like
    //        a sales rep instead of a failed product search. Matched on a
    //        punctuation-stripped copy so stray characters ("dont ;like") still
    //        register.
    $clean_fb = strtolower( trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $raw ) ) ) );
    if ( preg_match(
        '/\b(dont like|don t like|do not like|didn t like|didnt like|not like it|not liking|dislike|not good|no good|not nice|not impressed|not interested|dont want|do not want|not what i (want|need|am looking|wanted|expected)|hate (these|this|it|them)|too expensive|too costly|too pricey|very expensive|so expensive|out of (my )?budget|cant afford|cannot afford|something better|anything better|show (me )?(something |anything )?better|need (something |a )?better|better (option|quality)|something (cheaper|nicer|else)|anything (cheaper|else)|need (something )?cheaper|another (option|one|colou?r|brand|style|size)|other (option|options|colou?r|brand)|different (one|option|colou?r|brand|style|size)|show (me )?(other|another|something else)|not this one|nothing i like|just looking|just browsing|browsing|dont know what i (want|need)|do not know what i (want|need)|not sure what i (want|need))\b/i',
        $clean_fb
    ) ) {
        return $result( 'feedback', 0.8 );
    }

    // 0c-3) Negative preference ("don't show women's products", "no men", "hide
    //        kids items") — a gender EXCLUSION, handled conversationally rather
    //        than searched as a product type.
    if ( preg_match( '/\b(don\'?t|do not|dont|not|no|without|hide|exclude|skip|remove|avoid)\b.{0,14}\b(men\'?s?|man|male|gents?|boys?|women\'?s?|woman|female|ladies|girls?|kids?)\b/i', $raw ) ) {
        return $result( 'feedback', 0.82 );
    }

    // 0d) Products on sale / discounted / offers / deals / clearance.
    //     "offer(s)" is a discount noun here — NOT the verb in "do you offer free
    //     shipping?" (a shipping question) — so the verb form is excluded.
    $sale_hit = (bool) preg_match( '/\b(on sale|sale products?|sale items?|products? on sale|items? on sale|big sale|flash sale|mega sale|discounts?|discounted|on discount|clearance|bargains?|deals?|best deals?|great deals?|cheap deals?|hot deals?|special offers?|price drops?|price cut|save money|save big|markdowns?|marked down|reduced price|reductions?)\b/i', $raw );
    if ( ! $sale_hit && preg_match( '/\boffers?\b/i', $raw ) ) {
        $offer_verb = preg_match( '/\b(do|does|can|could|will|would)\s+(you|we|they)\s+offer\b/i', $raw )
            || preg_match( '/\b(you|we|they)\s+offer\b/i', $raw )
            || preg_match( '/\boffer\s+(free|a|an|the|any|to|cod|delivery|shipping|payment|refund|returns?)\b/i', $raw );
        $sale_hit = ! $offer_verb;
    }
    if ( $sale_hit ) {
        return $result( 'product_sale', 0.88 );
    }

    // 0d-2) Catalog browse by sort — cheapest / most expensive / premium /
    //        featured / best sellers / top rated / new arrivals. Only when there
    //        is NO explicit numeric price filter ("under 50", "500") — those are
    //        handled as a normal product search that respects the price range.
    if (
        ! preg_match( '/\b(under|below|above|over|between|less than|more than|upto|up to|within)\b/i', $raw )
        && ! preg_match( '/\d{2,}/', $raw )
        // Not about editorial content ("latest articles/posts/blogs/news").
        && ! preg_match( '/\b(article|articles|post|posts|blog|blogs|news|story|stories|update|updates)\b/i', $raw )
        && preg_match( '/\b(cheap\w*|lowest(\s+price)?|low(\s+|-)?(price|cost)|budget|affordable|inexpensive|least expensive|most expensive|expensive|costl\w*|premium|luxury|high[- ]?end|high(\s+|-)?price|top[- ]?rated|highest rated|best rated|highly rated|featured|best[- ]?sell\w*|bestsellers?|top ?sell\w*|most sold|popular|trending|new arrivals?|newest|latest|recently added|new products?|best (products?|value|pick|choice|one|option|item)|value for money|most popular|top products?|free (products?|items?|stuff|samples?|things?|gifts?)|freebies?)\b/i', $raw )
    ) {
        return $result( 'product_browse', 0.85 );
    }

    // 0e-pre) Delivery-TIME questions ("how long will it take to receive my
    //         order", "when will my order arrive", "delivery time", "how many
    //         days for delivery") → Shipping / Delivery information. These are
    //         about the delivery estimate, NOT "how to order" and NOT tracking a
    //         specific order — so they take priority even when they mention
    //         "order".
    if (
        preg_match( '/\b(how long|how many days?|how soon|when (will|do|does|can|is)|delivery time|shipping time|delivery estimate|estimated delivery|lead time|dispatch time|turnaround)\b/i', $raw )
        && preg_match( '/\b(deliver|delivery|delivered|delivering|receive|received|arrive|arrival|arrives|ship|shipping|shipped|dispatch|reach)\b/i', $raw )
    ) {
        return $result( 'shipping', 0.9 );
    }

    // 0e) Shipping / delivery / service area / logistics — detected broadly so
    //     ANY shipping question (express / same-day / international / pickup,
    //     "deliver to <city>", zones, charges, policy, PIN codes) is handled by
    //     the shipping tool and is NEVER routed to product search. Personal
    //     order-status queries ("where is my order", "track my delivery") are
    //     excluded — those are order tracking, handled below.
    $order_status_q = (bool) ( preg_match( '/\b(my (order|delivery|parcel|package|shipment)|track|order status|order\s*#?\s*\d)\b/i', $raw )
        || preg_match( '/where(\s+is|\'?s)?\s+my\b/i', $raw ) );
    if ( ! $order_status_q && (
        preg_match( '/\b(shipping|shipment|dispatch|courier|logistics)\b/i', $raw )
        || preg_match( '/\b(deliver|delivery|delivered|delivering)\b/i', $raw )
        || preg_match( '/\b(pick ?up|click and collect|collect in[- ]?store)\b/i', $raw )
        || preg_match( '/\b(service area|delivery area|shipping zone|shipping area|delivery zone)\b/i', $raw )
        || preg_match( '/\b(pin ?code|postal code|zip ?code)\b/i', $raw )
    ) ) {
        return $result( 'shipping', 0.85 );
    }

    // 0f) Payment methods / gateways. Detects generic "how do I pay" / "payment
    //     methods" questions and explicit method names (cards, UPI, PayPal,
    //     Stripe, COD, Google/Apple Pay, net banking, Razorpay, bank transfer).
    //     The method-name branch requires a payment noun nearby so "do you accept
    //     returns" is never misread as a payment question.
    if (
        preg_match( '/\b(payment methods?|payment options?|payment mode|mode of payment|ways? to pay|how (can|do|to) i? ?pay|what payments?|forms? of payment)\b/i', $raw )
        || preg_match( '/\b(credit ?card|debit ?card|net ?banking|cash on delivery|\bcod\b|\bupi\b|paypal|stripe|razorpay|paytm|phonepe|google ?pay|gpay|apple ?pay|bank transfer)\b/i', $raw )
        || preg_match( '/\bdo you (accept|take|support)\b.{0,30}\b(card|cards|payment|payments|upi|paypal|cod|cash|online)\b/i', $raw )
    ) {
        return $result( 'payment', 0.9 );
    }

    // 0g) Shopping assistant — a confused shopper, OR a gift / "something for
    //     someone" request. Answered consultatively (ask budget/interests, offer
    //     popular products) instead of a keyword product search — so "something
    //     for my father" never becomes "we don't have father".
    if (
        preg_match( '/\b(help me (choose|pick|decide|select|find something|shop)|don\'?t know (which|what)|not sure (which|what|where)|i\'?m confused|im confused|confused about|which (product|one|item) (should|to|do)|what should i (buy|get|order)|suggest me something|recommend me something|guide me to (a|the) (product|right))\b/i', $raw )
        || preg_match( '/\bgifts?\b/i', $raw )
        || preg_match( '/\bpresents? for\b/i', $raw )
        || preg_match( '/\bsomething for (my|a|an|him|her|someone|the)\b/i', $raw )
        || preg_match( '/\bfor my (dad|father|mom|mum|mother|wife|husband|son|daughter|kid|kids|child|children|friend|boyfriend|girlfriend|brother|sister|parents?|grandma|grandpa|grandmother|grandfather)\b/i', $raw )
    ) {
        return $result( 'shopping_help', 0.85 );
    }

    // 0h) "How do I…" / website guide. Step-by-step help for completing a task
    //     (order, cart, checkout, account, login, coupon, password, profile,
    //     track, cancel, refund, return, contact). A "how/steps/guide/process"
    //     cue plus a task word — so it never swallows "how many products" or
    //     "how much is shipping" (those have no task verb / are caught earlier).
    if (
        ( preg_match( '/\bhow\b/i', $raw )
            || preg_match( '/\b(steps?|guide me|walk me|process|procedure|where (do|can) i)\b/i', $raw ) )
        // Task words use stems (no trailing boundary) so verb/plural forms match:
        // "purchas" → purchase/purchases/purchasing, "registr" → register/
        // registration, "deliver" → deliver/delivery, "modif" → modify/…
        && preg_match( '/\b(order|buy|buying|bought|purchas|checkout|check ?out|add.{0,12}cart|cart|pay|paying|payment|account|registr|sign ?up|sign ?in|log ?in|login|coupon|promo|voucher|password|profile|track|cancel|modif|return|refund|exchange|deliver|ship|contact|reach (you|us|support)|use (the|this) (site|website))/i', $raw )
    ) {
        return $result( 'how_to', 0.88 );
    }

    // 0i) Contact Support — the visitor wants to reach the team / see contact
    //     details ("customer care", "customer support", "contact support",
    //     "support team", "help centre/desk", "get in touch", "reach your team",
    //     "call/email support", "connect me to customer care", "how do I contact
    //     you"). Detected HERE — before human_support and before any general
    //     website search — so these NEVER fall through to FAQ / About / People
    //     page results. Routed to contact_info → the contact-details engine.
    //     (Product-shopping help like "help me choose a shirt" is caught earlier
    //     by shopping_help, so bare "help" is intentionally NOT matched here.)
    if (
        preg_match( '/\b(customer (care|support|service)|contact support|support (team|centre|center|desk)|help ?(centre|center|desk)|help ?line)\b/i', $raw )
        || preg_match( '/\b(contact|reach|connect (me|us)?|speak|talk|get in touch)\b.{0,20}\b(customer care|customer support|support (team|staff|desk)|your team|the team|customer service)\b/i', $raw )
        || preg_match( '/\b(contact (us|you|your team|the team|customer care|customer support|support)|get in touch|reach (out|you|us|your team|the team)|connect me (to|with))\b/i', $raw )
        || preg_match( '/\b(call|email|e-mail|mail|message)\b.{0,15}\b(customer (care|support|service)|support|help ?desk|your team)\b/i', $raw )
        || preg_match( '/\b(how (can|do|could) i (contact|reach|get in touch with))\b/i', $raw )
        || preg_match( '/\b(live (support|help)|speak to (someone|customer care|support)|talk to (your|the) team|need (customer )?(care|support))\b/i', $raw )
    ) {
        return $result( 'contact_info', 0.95 );
    }

    // 0j) Social media / newsletter — the visitor wants the brand's social
    //     profiles (Facebook, Instagram, X, LinkedIn, YouTube, …) or its
    //     newsletter / subscribe link. Answered from the site's own links, never
    //     invented.

    if ( preg_match( '/\b(social (media|links?|handles?|profiles?|accounts?|pages?|icons?)|follow (us|you)(\s+on)?|facebook|fb|instagram|insta|twitter|linkedin|you ?tube|pinterest|tik ?tok|telegram|threads|snapchat|newsletter|subscribe|mailing list|email updates)\b/i', $raw ) ) {
        return $result( 'social', 0.9 );
    }

    // 1) Human support — explicit request to reach a live PERSON (handoff). The
    //    broader "customer care / support" family is handled above by Contact
    //    Support; this remains for pure human/agent/live-chat requests.
    
    if (
        preg_match( '/\b(human|agent|representative|real person|live (chat|agent)|talk to (someone|a person))\b/i', $raw )
        || preg_match( '/(insaan|aadmi|vyakti)\s*(se)?\s*(baat|baate)/iu', $raw )
        || ( preg_match( '/baat\s+kar/iu', $raw ) && $has( array( 'human', 'agent', 'team', 'insaan', 'support' ) ) )
    ) {
        return $result( 'human_support', 0.95 );
    }

    // 1b) Cart — show my cart / basket contents.
    if ( preg_match( '/\b(my cart|shopping cart|view cart|show cart|basket|cart me|cart mein|cart kya)\b/i', $raw ) ) {
        return $result( 'cart_view', 0.9 );
    }

    // 2) Order tracking — "my orders" / "order history" (personalized), or
    //    order + status/where, or a bare order number.
    if ( preg_match( '/\b(my orders?|order history|track my order|my order status)\b/i', $raw ) ) {
        return $result( 'order_tracking', 0.92 );
    }
    if (
        preg_match( '/\b(order|tracking|shipment|parcel|package|delivery)\b/i', $raw )
        && (
            preg_match( '/\b(track|status|where|kahan|kaha|kab|when|update|locate|trace)\b/i', $raw )
            || ! empty( $entities['order_number'] )
            || preg_match( '/(kaha|kahan)\s*(hai|he)/iu', $raw )
        )
    ) {
        return $result( 'order_tracking', 0.92 );
    }
    // Explicit order number with a "#" clearly means an order. Capped at 12
    // digits — a longer run is not a real order/price, so it isn't treated as one.
    if ( preg_match( '/^#\s*\d{2,12}\s*$/', $raw ) ) {
        return $result( 'order_tracking', 0.85 );
    }
    // A bare number ("100") is ambiguous — it could be an order number OR a
    // price. Ask the visitor instead of assuming. Capped at 10 digits so a giant
    // pasted number is never echoed back.
    if ( preg_match( '/^\s*\d{2,10}\s*$/', $raw ) ) {
        return $result( 'clarify_number', 0.7 );
    }

    // 3) Support request — complaint / problem with a product.
    if ( preg_match( '/\b(complaint|complain|damaged|broken|defective|faulty|not working|stopped working|wrong (item|product|order)|missing|refund request|return request|issue with|problem with|kharab|toot|shikayat|kaam nahi)\b/iu', $raw ) ) {
        return $result( 'support_request', 0.9 );
    }

    // 4) Booking — appointment / schedule / demo.
    if ( preg_match( '/\b(book(ing)?|appointment|schedule|reserve|reservation|slot|demo|meeting|consultation call)\b/i', $raw )
        || preg_match( '/(appointment|booking|slot)\s*(book|chahiye|karni|karna)/iu', $raw ) ) {
        // "consultation" alone leans lead; require booking-ish words here.
        return $result( 'booking', 0.88 );
    }

    // 5) (Lead generation intent is detected BELOW, AFTER all product intents —
    //     see the spec priority: Product Search first, Lead Capture LAST. Shopping
    //     phrases like "I want to buy tops" must be treated as a product search,
    //     never as a lead.)

    // 6) Product comparison — A vs B / compare / difference between.
    if ( preg_match( '/\b(compare|comparison|vs\.?|versus|difference between|which is better)\b/i', $raw ) ) {
        return $result( 'product_comparison', 0.85 );
    }

    // 7) Product recommendation — best/suggest/recommend X (for Y).
    if ( preg_match( '/\b(recommend|suggest|best .* (for|under|to)|good for|which (product|one) should|kaun ?sa|konsa|behtar)\b/i', $raw ) ) {
        return $result( 'product_recommendation', 0.8 );
    }

    // 8) Product search — price filters, buy, or matches the WooCommerce parser.
    $product_signal = preg_match( '/\b(buy|purchase|shop|price|cost|under|below|above|cheap|cheapest|expensive|budget|product|products|sell|available|in stock|order karna|kharidna|khareedna)\b/i', $raw )
        || preg_match( '/\d/', $raw ) && $has( array( 'under', 'below', 'rs', 'rupee', 'rupees', 'inr', '₹', '$', 'price' ) );
    if ( $product_signal ) {
        return $result( 'product_search', 0.7 );
    }

    // 8b) Lead generation — LAST, and ONLY for an EXPLICIT request to be contacted
    //     / quoted / called back / to leave details. It is deliberately checked
    //     AFTER every product intent, and its wording excludes shopping phrases
    //     ("buy", "pricing", "interested in a shirt"), so normal product discovery
    //     ("do you have tops", "I want to buy leggings") is NEVER a lead.
    if ( preg_match( '/\b(get a quote|request (a |an )?(quote|quotation|call ?back|callback)|quotation|call me( back)?|call ?back\b|contact me\b|talk to sales|(someone|somebody) (should |to )?(call|contact) me|have (someone|somebody) (call|contact) me|leave (my )?(details|contact|contact details|number|phone)|(want|would like|i\'?d like)( to)? (be contacted|a callback|someone to (call|contact) me|a consultation|a quote)|need (a )?consultation|consultation call|book (a )?consultation)\b/i', $raw ) ) {
        return $result( 'lead_generation', 0.82 );
    }

    // 9) Navigation — open/show/go to a PAGE or a named policy. It now REQUIRES an
    //    actual page/policy target nearby, so a generic "show me tee" (a product)
    //    is never mistaken for a page lookup — that falls through to product
    //    search and returns proper product cards.
    if (
        preg_match( '/\b(open|go to|take me to|navigate( to)?|link to|page for|show me|show|view)\b.{0,40}\b(page|policy|policies|refund|return|privacy|terms|conditions|shipping|delivery|contact|about|faq|checkout|cart|account|home\s?page|sitemap)\b/i', $raw )
        || preg_match( '/\b(refund|return|privacy|terms|shipping|delivery|cancellation|exchange)\s+(policy|page)\b/i', $raw )
    ) {
        return $result( 'navigation', 0.78 );
    }

    // 10) Contact info — phone / email / address / hours / customer care.
    if ( preg_match( '/\b(contact|customer (care|support|service)|help ?line|help ?desk|support (email|number|phone)|sales (email|team|number)|phone|mobile|telephone|email|e-mail|mail id|address|location|office|store address|business (hours?|address)|opening hours?|working hours?|timings?|whats ?app|where are you (located|based)|reach you|call you)\b/i', $raw ) ) {
        return $result( 'contact_info', 0.8 );
    }

    // 11) FAQ — frequently asked / how-to / policy-style questions.
    if ( preg_match( '/\b(faq|frequently asked|how (do|can|to)|do you (offer|have|provide|accept)|what (is|are) your)\b/i', $raw ) ) {
        return $result( 'faq', 0.6 );
    }

    // 12) Default — general website information.
    return $result( 'website_info', 0.4 );
}

/**
 * Extract entities (order number, email, phone, name) from a message.
 *
 * @param string $message User message.
 * @return array{order_number:string,email:string,phone:string,name:string}
 */
function wp_ai_agent_extract_entities( $message ) {
    $message = (string) $message;

    $email = '';
    if ( preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $message, $em ) ) {
        $email = $em[0];
    }

    // Phone: 8-15 digits, allowing +, spaces, dashes.
    $phone = '';
    if ( preg_match( '/(\+?\d[\d\s\-]{7,16}\d)/', $message, $pm ) ) {
        $digits = preg_replace( '/[^\d]/', '', $pm[1] );
        if ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) {
            $phone = trim( $pm[1] );
        }
    }

    // Order number: "#1234", "order 1234", "order no 1234". Bounded to 2–12
    // digits AND to a self-contained run (negative lookahead), so an absurdly
    // long pasted number is never mistaken for an order id.
    $order_number = '';
    if ( preg_match( '/#\s*(\d{2,12})(?!\d)/', $message, $om ) ) {
        $order_number = $om[1];
    } elseif ( preg_match( '/\border(?:\s*(?:no\.?|number|id|#))?\s*[:#]?\s*(\d{2,12})(?!\d)/i', $message, $om ) ) {
        $order_number = $om[1];
    } elseif ( preg_match( '/^#?\s*(\d{2,12})\s*$/', trim( $message ), $om ) ) {
        $order_number = $om[1];
    }

    return array(
        'order_number' => $order_number,
        'email'        => $email,
        'phone'        => $phone,
        'name'         => '',
    );
}
