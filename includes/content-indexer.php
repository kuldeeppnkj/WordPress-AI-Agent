<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wp_ai_agent_get_indexable_post_types() {
    $types = array( 'post', 'page' );
    $custom_types = get_post_types( array( 'public' => true, '_builtin' => false ), 'names' );
    foreach ( $custom_types as $type ) {
        $types[] = $type;
    }
    return array_unique( $types );
}

function wp_ai_agent_get_content_index() {
    $content = array();

    $post_types = wp_ai_agent_get_indexable_post_types();
    foreach ( $post_types as $post_type ) {
        $posts = get_posts( array(
            'post_type'      => $post_type,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ) );

        foreach ( $posts as $post ) {
            $content[] = array(
                'type'    => $post_type,
                'title'   => get_the_title( $post ),
                'content' => wp_strip_all_tags( $post->post_content ),
                'url'     => get_permalink( $post ),
            );
        }
    }

    if ( function_exists( 'wc_get_products' ) ) {
        $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) );
        foreach ( $products as $product ) {
            $content[] = array(
                'type'    => 'product',
                'title'   => $product->get_name(),
                'content' => wp_strip_all_tags( $product->get_description() . ' ' . $product->get_short_description() ),
                'url'     => get_permalink( $product->get_id() ),
            );
        }
    }

    $categories = get_categories( array( 'hide_empty' => false ) );
    foreach ( $categories as $category ) {
        $content[] = array(
            'type'    => 'category',
            'title'   => $category->name,
            'content' => wp_strip_all_tags( $category->description ),
            'url'     => get_category_link( $category->term_id ),
        );
    }

    $tags = get_tags( array( 'hide_empty' => false ) );
    foreach ( $tags as $tag ) {
        $content[] = array(
            'type'    => 'tag',
            'title'   => $tag->name,
            'content' => wp_strip_all_tags( $tag->description ),
            'url'     => get_tag_link( $tag->term_id ),
        );
    }

    return $content;
}

function wp_ai_agent_index_content( $force_refresh = false ) {
    $transient_key = 'wp_ai_agent_content_index';
    if ( ! $force_refresh ) {
        $cached = get_transient( $transient_key );
        if ( false !== $cached ) {
            return $cached;
        }
    }

    $content = wp_ai_agent_get_content_index();
    set_transient( $transient_key, $content, 12 * HOUR_IN_SECONDS );
    return $content;
}

/**
 * Break a user query into meaningful search tokens.
 *
 * Lower-cases, strips punctuation, drops very short words and common stop words
 * (English + romanized Hindi/Hinglish fillers) so a question reduces to its
 * meaningful terms.
 *
 * @param string $query Raw user query.
 * @return string[] Unique, lower-cased tokens.
 */
function wp_ai_agent_tokenize_query( $query ) {
    $query = strtolower( wp_strip_all_tags( (string) $query ) );
    $query = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $query );
    $words = preg_split( '/\s+/', trim( $query ), -1, PREG_SPLIT_NO_EMPTY );

    if ( empty( $words ) ) {
        return array();
    }

    $stopwords = array(
        // English.
        'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'a', 'an', 'of',
        'to', 'in', 'on', 'at', 'for', 'and', 'or', 'but', 'with', 'about', 'from',
        'into', 'what', 'whats', 'who', 'whom', 'whose', 'how', 'why', 'when', 'where',
        'which', 'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'shall',
        'you', 'your', 'yours', 'i', 'me', 'my', 'we', 'our', 'ours', 'us', 'it', 'its',
        'this', 'that', 'these', 'those', 'there', 'here', 'please', 'tell', 'give', 'get',
        'have', 'has', 'had', 'any', 'some', 'all', 'as', 'so', 'if', 'than', 'then',
        'show', 'want', 'need', 'know', 'related', 'something', 'anything',
        // Romanized Hindi / Hinglish fillers.
        'hai', 'hain', 'tha', 'thi', 'kya', 'kyu', 'kyun', 'kaise', 'kab', 'kahan',
        'kaun', 'kar', 'karo', 'karna', 'karte', 'kuch', 'koi', 'bhi', 'aur',
        'nahi', 'nhi', 'han', 'haan', 'batao', 'bata', 'bataye', 'dikhao', 'mujhe', 'hume',
        'mera', 'meri', 'mere', 'tera', 'teri', 'aap', 'apka', 'apki', 'apke', 'wala',
        'wali', 'wale', 'yeh', 'yah', 'woh', 'jo', 'par', 'agar', 'toh', 'tho',
    );

    $tokens = array();
    foreach ( $words as $word ) {
        if ( strlen( $word ) < 3 ) {
            continue;
        }
        if ( in_array( $word, $stopwords, true ) ) {
            continue;
        }
        $tokens[] = $word;
    }

    /**
     * Allow customizing the parsed query tokens (e.g. to add synonyms).
     *
     * @param string[] $tokens Parsed tokens.
     * @param string   $query  Original query.
     */
    $tokens = apply_filters( 'wp_ai_agent_query_tokens', array_values( array_unique( $tokens ) ), $query );

    return $tokens;
}

/**
 * Light singular form of a token so plural/singular queries match either way
 * (e.g. "blogs" -> "blog", "services" -> "service"). Used as a LIKE/substring
 * needle so it matches both forms.
 *
 * @param string $token Token.
 * @return string
 */
function wp_ai_agent_token_stem( $token ) {
    if ( strlen( $token ) > 4 && 's' === substr( $token, -1 ) && 'ss' !== substr( $token, -2 ) ) {
        return substr( $token, 0, -1 );
    }
    return $token;
}

/**
 * Build a relevant snippet of content centered on the first matching token, so
 * the part of the page that actually matches the question reaches the AI (not
 * just the opening words).
 *
 * @param string   $content    Plain content.
 * @param string[] $tokens     Query tokens.
 * @param int      $word_limit Max words in the snippet.
 * @return string
 */
function wp_ai_agent_make_snippet( $content, $tokens, $word_limit = 160 ) {
    $content = trim( wp_strip_all_tags( (string) $content ) );
    if ( '' === $content ) {
        return '';
    }

    $lower = strtolower( $content );
    $pos   = false;
    foreach ( $tokens as $token ) {
        $needle = wp_ai_agent_token_stem( $token );
        $p      = strpos( $lower, $needle );
        if ( false !== $p && ( false === $pos || $p < $pos ) ) {
            $pos = $p;
        }
    }

    if ( false === $pos ) {
        return wp_trim_words( $content, $word_limit, '…' );
    }

    // Start a little before the match so the surrounding context is included.
    $start   = max( 0, $pos - 200 );
    $snippet = wp_trim_words( substr( $content, $start ), $word_limit, '…' );

    return ( $start > 0 ) ? '…' . $snippet : $snippet;
}

/**
 * Synonym map so meaning-related words match even without embeddings
 * (e.g. "offer" finds coupons/discounts). Filterable.
 *
 * @return array<string,string[]>
 */
function wp_ai_agent_synonyms() {
    $map = array(
        'offer'    => array( 'coupon', 'discount', 'deal', 'sale', 'promo', 'voucher', 'save', 'off' ),
        'offers'   => array( 'coupon', 'discount', 'deal', 'sale', 'promo', 'voucher' ),
        'coupon'   => array( 'offer', 'discount', 'deal', 'promo', 'code', 'voucher', 'sale' ),
        'discount' => array( 'offer', 'coupon', 'sale', 'deal', 'off', 'promo' ),
        'deal'     => array( 'offer', 'discount', 'sale', 'coupon' ),
        'sale'     => array( 'discount', 'offer', 'deal' ),
        'price'    => array( 'cost', 'pricing', 'rate', 'charge', 'fee', 'amount' ),
        'cost'     => array( 'price', 'pricing', 'rate', 'charge', 'fee' ),
        'buy'      => array( 'purchase', 'order', 'shop' ),
        'purchase' => array( 'buy', 'order' ),
        'shipping' => array( 'delivery', 'courier', 'dispatch' ),
        'delivery' => array( 'shipping', 'courier' ),
        'return'   => array( 'refund', 'exchange' ),
        'refund'   => array( 'return', 'exchange' ),
        'contact'  => array( 'support', 'help', 'email', 'phone', 'reach' ),
        'support'  => array( 'help', 'contact', 'assistance' ),
        'service'  => array( 'services', 'solution', 'offering' ),
        'product'  => array( 'item', 'products', 'goods' ),
        'phone'    => array( 'mobile', 'number', 'call', 'contact' ),
        // Gender variations so "men/man/male/gents/boys" and
        // "women/woman/ladies/female/girls" map to the right products.
        'men'      => array( 'man', 'mens', 'male', 'gents', 'boy', 'boys' ),
        'man'      => array( 'men', 'mens', 'male', 'gents' ),
        'mens'     => array( 'men', 'man', 'male', 'gents' ),
        'male'     => array( 'men', 'man', 'mens', 'gents' ),
        'boys'     => array( 'boy', 'kids', 'men', 'male' ),
        'women'    => array( 'woman', 'womens', 'ladies', 'female', 'girl', 'girls' ),
        'woman'    => array( 'women', 'womens', 'ladies', 'female' ),
        'womens'   => array( 'women', 'woman', 'ladies', 'female' ),
        'ladies'   => array( 'women', 'woman', 'womens', 'female' ),
        'female'   => array( 'women', 'woman', 'womens', 'ladies' ),
        'girls'    => array( 'girl', 'kids', 'women', 'female' ),
    );

    return apply_filters( 'wp_ai_agent_synonyms', $map );
}

/**
 * Expand a token into all needles to look for: the token, its stem, and any
 * synonyms (with their stems), so related words still match.
 *
 * @param string $token Token.
 * @return string[] Unique lower-case needles.
 */
function wp_ai_agent_expand_token( $token ) {
    $token   = strtolower( $token );
    $needles = array( $token, wp_ai_agent_token_stem( $token ) );

    $map = wp_ai_agent_synonyms();
    if ( isset( $map[ $token ] ) ) {
        foreach ( $map[ $token ] as $syn ) {
            $syn       = strtolower( $syn );
            $needles[] = $syn;
            $needles[] = wp_ai_agent_token_stem( $syn );
        }
    }

    return array_values( array_unique( array_filter( $needles ) ) );
}

/**
 * Whole-word match: true only when $needle appears as a word in $haystack
 * (a left word boundary is required, and a trailing plural "s"/"es" is allowed).
 *
 * This prevents substring false-positives such as "ring" matching "earring" or
 * "men" matching "women" — the cause of wrong product results.
 *
 * @param string $haystack Lower-cased text to search.
 * @param string $needle   Lower-cased term.
 * @return bool
 */
function wp_ai_agent_term_match( $haystack, $needle ) {
    if ( '' === $needle || '' === $haystack ) {
        return false;
    }
    return (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?:es|s)?(?![\p{L}\p{N}])/u', $haystack );
}

/**
 * Stricter whole-word match: like wp_ai_agent_term_match(), but a HYPHEN also
 * blocks the boundary. This distinguishes a product TYPE from a compound word —
 * e.g. "shirt" does NOT match "t-shirt", "shoe" does not match "over-shoe" — so
 * an exact product type ("Shirt") is never confused with a different one
 * ("T-Shirt"). Used for exact-match-first product ranking.
 *
 * @param string $haystack Lower-cased text to search.
 * @param string $needle   Lower-cased term.
 * @return bool
 */
function wp_ai_agent_term_match_strict( $haystack, $needle ) {
    if ( '' === $needle || '' === $haystack ) {
        return false;
    }
    return (bool) preg_match( '/(?<![\p{L}\p{N}\-])' . preg_quote( $needle, '/' ) . '(?:es|s)?(?![\p{L}\p{N}\-])/u', $haystack );
}

/**
 * Generic descriptor words (colour, material, audience, style) that describe a
 * product but are NOT the product type. Used to "type-anchor" product search so
 * a query like "white shirt" must match a shirt, not just anything white.
 *
 * @return string[]
 */
function wp_ai_agent_generic_terms() {
    return apply_filters( 'wp_ai_agent_generic_terms', array(
        // Colours.
        'blue', 'red', 'green', 'black', 'white', 'yellow', 'pink', 'purple', 'orange', 'grey', 'gray', 'brown', 'beige', 'navy', 'maroon', 'gold', 'golden', 'silver', 'cream', 'multicolor', 'multicolour',
        // Materials.
        'cotton', 'silk', 'leather', 'denim', 'wool', 'linen', 'polyester', 'suede', 'knit', 'fabric', 'metal', 'plastic', 'wooden',
        // Style / audience.
        'casual', 'formal', 'party', 'sports', 'sporty', 'vintage', 'modern', 'classic', 'plain', 'printed', 'striped', 'floral', 'solid', 'slim', 'regular', 'loose', 'light', 'dark', 'small', 'medium', 'large',
        'men', 'mens', 'man', 'women', 'womens', 'woman', 'unisex', 'kids', 'kid', 'boys', 'boy', 'girls', 'girl', 'ladies', 'lady', 'gents', 'gent', 'fashion', 'style', 'stylish', 'new', 'latest',
    ) );
}

function wp_ai_agent_search_website_content( $query, $limit = 5 ) {
    $tokens  = wp_ai_agent_tokenize_query( $query );
    $results = array();

    if ( empty( $tokens ) ) {
        return $results;
    }

    // Pull candidates from the centralized index table (or the legacy transient
    // index as a fallback) so search spans every indexed content source.
    $indexed = wp_ai_agent_get_candidate_items( $tokens );

    if ( empty( $indexed ) ) {
        return $results;
    }

    // Each query token becomes a group of needles (token + stem + synonyms).
    // Relevance stays based on the number of ORIGINAL tokens matched.
    $groups = array();
    foreach ( $tokens as $token ) {
        $groups[] = wp_ai_agent_expand_token( $token );
    }
    $token_count = count( $groups );

    foreach ( $indexed as $item ) {
        $title   = strtolower( (string) $item['title'] );
        $content = strtolower( (string) $item['content'] );

        $score   = 0;
        $matched = 0;

        foreach ( $groups as $needles ) {
            $in_title   = false;
            $in_content = false;
            foreach ( $needles as $needle ) {
                if ( ! $in_title && '' !== $title && false !== strpos( $title, $needle ) ) {
                    $in_title = true;
                }
                if ( ! $in_content && '' !== $content && false !== strpos( $content, $needle ) ) {
                    $in_content = true;
                }
                if ( $in_title && $in_content ) {
                    break;
                }
            }

            if ( $in_title || $in_content ) {
                $matched++;
            }
            if ( $in_title ) {
                $score += 10;
            }
            if ( $in_content ) {
                $score += 4;
            }
        }

        if ( $matched > 0 ) {
            $item['score']     = $score;
            $item['relevance'] = $matched / $token_count;
            $results[] = $item;
        }
    }

    usort( $results, function ( $a, $b ) {
        return $b['score'] - $a['score'];
    } );

    return array_slice( $results, 0, $limit );
}

/**
 * Relevance threshold (0-1). If the best matching content scores below this,
 * the website is considered to have no answer and the AI provider is NOT called
 * (strict website-only mode). Raise it to be stricter, lower it to be more
 * permissive, via the wp_ai_agent_relevance_threshold filter.
 *
 * @return float
 */
function wp_ai_agent_relevance_threshold() {
    $threshold = (float) apply_filters( 'wp_ai_agent_relevance_threshold', 0.2 );
    if ( $threshold < 0 ) {
        $threshold = 0.0;
    }
    if ( $threshold > 1 ) {
        $threshold = 1.0;
    }
    return $threshold;
}

/**
 * The reply shown when the website content does not cover the question.
 *
 * @return string
 */

function wp_ai_agent_not_found_message() {
    return apply_filters(
        'wp_ai_agent_not_found_message',
        __( "I'm sorry, I couldn't find that information on this website. 🙂 If you're looking for something similar, I'd be happy to help you find it — just tell me a bit more. You can also ask me about our products, orders, shipping, refunds, or contact details, or tap 📞 Contact to reach our team.", 'wp-ai-agent' )
    );
}

/**
 * Retrieve website context for a query and decide whether it is relevant enough
 * to hand to the LLM.
 *
 * @param string $query User query.
 * @return array {
 *     @type bool   $has_match Whether relevant content was found.
 *     @type float  $relevance Coverage of query terms (0-1).
 *     @type string $context   Concatenated website content for the LLM.
 *     @type array  $results   The raw matched content items.
 * }
 */
/**
 * Universal search — ONE function that searches every indexed website content
 * source (posts, pages, categories, tags, custom post types, WooCommerce
 * products, product categories/taxonomies, FAQs, policies, terms, privacy,
 * refund, attachments, etc.) and returns a single ranked result set.
 *
 * It combines semantic (embeddings) and keyword matching, de-duplicates, and
 * returns each result with: title, content, content_type, url, relevance.
 *
 * @param string $query Search query.
 * @param int    $limit Max results.
 * @return array[] Ranked results, each: title, content, content_type, url, relevance (0-1).
 */
function wp_ai_agent_universal_search( $query, $limit = 8 ) {
    $semantic = function_exists( 'wp_ai_agent_semantic_search' ) ? wp_ai_agent_semantic_search( $query, $limit * 2 ) : array();
    $keyword  = wp_ai_agent_search_website_content( $query, $limit * 2 );

    $map = array();

    // Normalize an item from either source into the unified shape.
    $shape = function ( $item, $relevance ) {
        $type = 'other';
        if ( isset( $item['content_type'] ) && '' !== $item['content_type'] ) {
            $type = $item['content_type'];
        } elseif ( isset( $item['type'] ) && '' !== $item['type'] ) {
            $type = $item['type'];
        }
        return array(
            'title'        => isset( $item['title'] ) ? $item['title'] : '',
            'content'      => isset( $item['content'] ) ? $item['content'] : '',
            'content_type' => $type,
            'url'          => isset( $item['url'] ) ? $item['url'] : '',
            'relevance'    => round( (float) $relevance, 4 ),
        );
    };

    $key_of = function ( $item ) {
        $title = isset( $item['title'] ) ? $item['title'] : '';
        $url   = isset( $item['url'] ) ? $item['url'] : '';
        return md5( $title . '|' . $url );
    };

    // Semantic matches: relevance = cosine similarity (0-1).
    foreach ( $semantic as $item ) {
        $rel              = isset( $item['relevance'] ) ? (float) $item['relevance'] : 0.0;
        $map[ $key_of( $item ) ] = $shape( $item, $rel );
    }

    // Keyword matches: relevance = query-term coverage (0-1). Items found by
    // BOTH methods get a small boost; otherwise add if new.
    foreach ( $keyword as $item ) {
        $key = $key_of( $item );
        $rel = isset( $item['relevance'] ) ? (float) $item['relevance'] : 0.0;
        if ( isset( $map[ $key ] ) ) {
            $map[ $key ]['relevance'] = round( min( 1.0, max( $map[ $key ]['relevance'], $rel ) + 0.1 ), 4 );
        } else {
            $map[ $key ] = $shape( $item, $rel );
        }
    }

    $results = array_values( $map );

    usort( $results, function ( $a, $b ) {
        if ( $a['relevance'] === $b['relevance'] ) {
            return 0;
        }
        return ( $a['relevance'] < $b['relevance'] ) ? 1 : -1;
    } );

    $results = array_slice( $results, 0, $limit );

    /**
     * Filter the unified ranked search results.
     *
     * @param array[] $results Ranked results.
     * @param string  $query   The query.
     */
    return apply_filters( 'wp_ai_agent_universal_search_results', $results, $query );
}

/**
 * Whether the query is a broad "about this website / what do you offer" type
 * question (answerable from a site overview rather than a specific page).
 *
 * @param string $query Query.
 * @return bool
 */
function wp_ai_agent_is_overview_query( $query ) {
    $q = strtolower( (string) $query );
    $patterns = array(
        'about this', 'about the', 'about us', 'about your', 'this website', 'this site',
        'your website', 'your site', 'who are you', 'what is this', 'what do you offer',
        'what do you do', 'what does this', 'what services', 'your services', 'overview',
        'tell me about', 'es website', 'is website', 'website ke bare', 'site ke bare',
    );
    foreach ( $patterns as $p ) {
        if ( false !== strpos( $q, $p ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Live WordPress search (title / content / excerpt) enriched with each match's
 * Elementor and ACF text. This runs against the CURRENT site — so answers work
 * even when the pre-built index is stale or missed builder/tab content (e.g. a
 * "Product Care" accordion). Returns items shaped like index results.
 *
 * @param string $query Query.
 * @param int    $limit Max posts.
 * @return array[] Items: title, content, url, content_type.
 */
function wp_ai_agent_live_search( $query, $limit = 6 ) {
    global $wpdb;

    $tokens = wp_ai_agent_tokenize_query( $query );
    if ( empty( $tokens ) ) {
        return array();
    }

    $types = wp_ai_agent_get_indexable_post_types();
    if ( function_exists( 'wc_get_products' ) && ! in_array( 'product', $types, true ) ) {
        $types[] = 'product';
    }
    // Elementor saved templates / theme-builder & global sections (FAQ blocks,
    // CTAs, etc.) live in the non-public `elementor_library` post type — their
    // text is only in their OWN `_elementor_data`, so a normal page that embeds
    // one by ID would never expose it. Mirror the universal indexer's type set so
    // the live fallback can recover that content when the index is stale or missed
    // it. Honour the same filter used by the indexer for consistency.
    if ( post_type_exists( 'elementor_library' ) && ! in_array( 'elementor_library', $types, true ) ) {
        $types[] = 'elementor_library';
    }
    /** Same filter the universal indexer applies (see wp_ai_agent_collect_posts). */
    $types = apply_filters( 'wp_ai_agent_indexable_post_types', $types );
    $types = array_values( array_unique( array_filter( $types, 'post_type_exists' ) ) );
    if ( empty( $types ) ) {
        return array();
    }

    // Page builders keep rendered text (accordion / toggle / tab / FAQ content)
    // in post meta rather than post_content. Search a small allowlist of the
    // common builder content keys so that text is found even when post_content is
    // empty. Filterable so a site can add its own builder's key.
    $meta_keys = apply_filters( 'wp_ai_agent_live_search_meta_keys', array(
        '_elementor_data',          // Elementor.
        '_bricks_page_content_2',   // Bricks.
        'panels_data',              // SiteOrigin Page Builder.
        '_cornerstone_data',        // Cornerstone / Pro.
    ) );
    $meta_keys = array_values( array_unique( array_filter( array_map( 'strval', (array) $meta_keys ) ) ) );
    if ( empty( $meta_keys ) ) {
        $meta_keys = array( '_elementor_data' );
    }
    $mk_ph = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

    // Match a token in the title, the post content, OR any builder meta value —
    // so accordion / toggle / tab / FAQ content is found even when the page's
    // post_content is empty.
    $type_ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
    $clauses = array();
    $params  = array();
    foreach ( $tokens as $t ) {
        $like      = '%' . $wpdb->esc_like( $t ) . '%';
        $clauses[] = '(p.post_title LIKE %s OR p.post_content LIKE %s OR m.meta_value LIKE %s)';
        $params[]  = $like;
        $params[]  = $like;
        $params[]  = $like;
    }
    $candidate_cap = (int) apply_filters( 'wp_ai_agent_live_search_candidates', 15 );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $sql = $wpdb->prepare(
        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key IN ($mk_ph) )
         WHERE p.post_status = 'publish' AND p.post_type IN ($type_ph) AND ( " . implode( ' OR ', $clauses ) . " )
         LIMIT %d",
        array_merge( $meta_keys, $types, $params, array( $candidate_cap ) )
    );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ids = $wpdb->get_col( $sql );
    if ( empty( $ids ) ) {
        return array();
    }

    // Load each candidate's full text (post content + Elementor + ACF) and rank
    // by how many query tokens it actually contains, so the most relevant pages
    // (e.g. the care page that matches "socks") come first.
    $scored = array();
    foreach ( $ids as $id ) {
        $p = get_post( $id );
        if ( ! $p ) {
            continue;
        }
        $content = (string) $p->post_content;
        if ( ! empty( $p->post_excerpt ) ) {
            $content .= "\n" . $p->post_excerpt;
        }
        if ( function_exists( 'wp_ai_agent_extract_elementor_text' ) ) {
            $content .= ' ' . wp_ai_agent_extract_elementor_text( $id );
        }
        if ( function_exists( 'wp_ai_agent_extract_acf_text' ) ) {
            $content .= ' ' . wp_ai_agent_extract_acf_text( $id );
        }
        $content = trim( wp_strip_all_tags( $content ) );
        $hay     = ' ' . strtolower( $p->post_title . ' ' . $content ) . ' ';

        $matched = 0;
        foreach ( $tokens as $t ) {
            if ( false !== strpos( $hay, $t ) ) {
                $matched++;
            }
        }
        // Elementor templates have no navigable front-end URL — the FAQ/section
        // is embedded inside another page. Drop the URL so the reply never shows
        // a broken "View" link; the LLM still answers from the extracted text.
        $url = ( 'elementor_library' === $p->post_type ) ? '' : get_permalink( $id );
        $scored[] = array(
            'title'        => get_the_title( $p ),
            'content'      => $content,
            'url'          => $url,
            'content_type' => $p->post_type,
            'matched'      => $matched,
        );
    }

    usort( $scored, function ( $a, $b ) {
        return $b['matched'] <=> $a['matched'];
    } );

    return array_slice( $scored, 0, (int) $limit );
}

function wp_ai_agent_retrieve_context( $query ) {
    // Single universal search across every indexed content source.
    $results   = wp_ai_agent_universal_search( $query, 8 );
    $threshold = wp_ai_agent_relevance_threshold();
    $top       = ! empty( $results ) ? (float) $results[0]['relevance'] : 0.0;
    $tokens    = wp_ai_agent_tokenize_query( $query );

    // Items to answer from: relevant INDEX matches (at/above threshold)…
    $index_items = ( ! empty( $results ) && $top >= $threshold ) ? $results : array();

    // …PLUS a live WordPress search of the current site (enriched with Elementor
    // & ACF text). This makes retrieval resilient: on-page content is found even
    // when the index is stale or missed builder/tab content — so the LLM always
    // has the real page text to answer from, never guesses.
    $live_items = function_exists( 'wp_ai_agent_live_search' ) ? wp_ai_agent_live_search( $query, 6 ) : array();

    // 1) Build context from BOTH sources (deduped), when either has something.
    if ( ! empty( $index_items ) || ! empty( $live_items ) ) {
        $seen    = array();
        $context = '';
        $add     = function ( $title, $url, $content ) use ( &$seen, &$context, $tokens ) {
            $key = md5( strtolower( trim( (string) $title ) . '|' . trim( (string) $url ) ) );
            if ( isset( $seen[ $key ] ) ) {
                return;
            }
            $seen[ $key ] = true;
            $excerpt      = wp_ai_agent_make_snippet( (string) $content, $tokens );
            $context     .= sprintf(
                "Title: %s\nURL: %s\nContent: %s\n\n",
                $title,
                ( '' !== (string) $url ) ? $url : 'N/A',
                ( '' !== $excerpt ) ? $excerpt : 'N/A'
            );
        };
        foreach ( $index_items as $item ) {
            $add( $item['title'], isset( $item['url'] ) ? $item['url'] : '', $item['content'] );
        }
        foreach ( $live_items as $item ) {
            $add( $item['title'], isset( $item['url'] ) ? $item['url'] : '', $item['content'] );
        }

        if ( '' !== trim( $context ) ) {
            return array(
                'has_match' => true,
                'mode'      => 'match',
                'relevance' => max( $top, ! empty( $live_items ) ? 0.5 : 0.0 ),
                'context'   => trim( $context ),
                'results'   => ! empty( $index_items ) ? $index_items : $live_items,
            );
        }
    }

    // 2) A broad "about this website / what do you offer" question with no
    //    specific match — describe the site from its OWN content (still
    //    website-only; not general knowledge).
    if ( wp_ai_agent_is_overview_query( $query ) ) {
        $overview = wp_ai_agent_build_general_context();
        if ( '' !== $overview ) {
            return array(
                'has_match' => true,
                'mode'      => 'overview',
                'relevance' => $top,
                'context'   => $overview,
                'results'   => array(),
            );
        }
    }

    // 3) Nothing relevant on the website — return the fallback and DO NOT call
    //    the AI provider (strict website-only mode).
    return array(
        'has_match' => false,
        'mode'      => 'none',
        'relevance' => $top,
        'context'   => '',
        'results'   => $results,
    );
}
                                                    
/**
 * Build a general overview of the website (site identity + a representative
 * sample of indexed content) used as context for broad questions that do not
 * match any specific keyword.
 *
 * @param int $limit Max number of content items to include.
 * @return string
 */
function wp_ai_agent_build_general_context( $limit = 40 ) {
    $parts = array();

    $name = get_bloginfo( 'name' );
    $desc = get_bloginfo( 'description' );
    if ( $name ) {
        $parts[] = 'Website: ' . $name . ( $desc ? ' - ' . $desc : '' );
    }

    // A representative sample of indexed content. Pages (About, Services, etc.)
    // describe the site best, so surface them before posts / other content.
    
    $items = wp_ai_agent_get_candidate_items( array() );
    usort( $items, function ( $a, $b ) {
        $pa = ( isset( $a['type'] ) && 'page' === $a['type'] ) ? 0 : 1;
        $pb = ( isset( $b['type'] ) && 'page' === $b['type'] ) ? 0 : 1;
        return $pa - $pb;
    } );

    $count = 0;
    foreach ( $items as $item ) {
        if ( $count >= $limit ) {
            break;
        }
        $title   = isset( $item['title'] ) ? trim( (string) $item['title'] ) : '';
        $content = isset( $item['content'] ) ? trim( wp_strip_all_tags( (string) $item['content'] ) ) : '';
        if ( '' === $title && '' === $content ) {
            continue;
        }
        $excerpt = ( '' !== $content ) ? wp_trim_words( $content, 60, '…' ) : '';
        $url     = isset( $item['url'] ) ? (string) $item['url'] : '';
        $parts[] = trim( sprintf( 'Title: %s | URL: %s | %s', $title, ( '' !== $url ) ? $url : 'N/A', $excerpt ) );
        $count++;
    }

    return trim( implode( "\n", $parts ) );
}
