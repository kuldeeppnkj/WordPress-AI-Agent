<?php
/**
 * WooCommerce natural-language product search.
 *
 * Auto-detects WooCommerce and turns questions like "5000 ke under shoes",
 * "best laptop for students", "waterproof shoes", "best sellers" or
 * "recommend a product" into a ranked list of real products (name, price,
 * short description, link) built ONLY from WooCommerce data.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Is WooCommerce active?
 *
 * @return bool
 */
function wp_ai_agent_wc_active() {
    return function_exists( 'wc_get_products' );
}

/**
 * Parse price + intent flags from a message.
 *
 * @param string $m Lowercased message.
 * @return array { min, max, order, best, related, reco }
 */
function wp_ai_agent_wc_parse_intent( $m ) {
    preg_match_all( '/\d[\d,]*\.?\d*/', $m, $nm );
    $nums = array();
    foreach ( $nm[0] as $n ) {
        $nums[] = (float) str_replace( ',', '', $n );
    }

    $min   = null;
    $max   = null;
    $order = '';

    if ( count( $nums ) >= 2 && preg_match( '/between|range/', $m ) ) {
        sort( $nums );
        $min = $nums[0];
        $max = $nums[ count( $nums ) - 1 ];
    } elseif ( ! empty( $nums ) ) {
        $t = $nums[0];
        // "under X" / "above X" set a price BOUND only — they must NOT force a
        // cheapest-first (or priciest-first) sort. Otherwise "best product under
        // $200" surfaces the cheapest junk (a $0 gift card, $5 socks) instead of
        // the best products within budget. An explicit "cheapest"/"expensive"
        // word still sets the sort in the block below (and "cheaper than" hits it
        // via the word "cheap"), so genuine price sorts keep working.
        if ( preg_match( '/under|below|less than|upto|up to|within|cheaper than|max(?:imum)?|budget|at most|not? more than|se kam|tak|niche|andar/', $m ) ) {
            $max = $t;
        } elseif ( preg_match( '/above|over|more than|greater than|at least|minimum|se jyada|se zyada|upar|adhik/', $m ) ) {
            $min = $t;
        } elseif ( preg_match( '/around|about|near|es price|is price|itne|itna/', $m ) ) {
            $min = $t * 0.75;
            $max = $t * 1.25;
        }
    }

    if ( '' === $order ) {
        if ( preg_match( '/cheap|lowest|sasta|saste|budget|low price|kam price/', $m ) ) {
            $order = 'ASC';
        } elseif ( preg_match( '/expensive|costly|highest|mehng|premium/', $m ) ) {
            $order = 'DESC';
        }
    }

    return array(
        'min'     => $min,
        'max'     => $max,
        'order'   => $order,
        'best'    => (bool) preg_match( '/best ?sell|bestseller|best-selling|top ?sell|most sold|popular|trending|sabse zyada bik|hot sell/', $m ),
        'related' => (bool) preg_match( '/related|similar|like this|jaisa|jaise/', $m ),
        'reco'    => (bool) preg_match( '/recommend|suggest|which.*(should|best)|best .* for|good for|kaun ?sa|konsa|behtar|achha|acha/', $m ),
    );
}

/**
 * Extract the NON-price numeric / availability filters from a message — rating,
 * minimum discount, in-stock-only, and a minimum stock quantity. These are HARD
 * filters: they are applied to the candidate set before any relevance/semantic
 * ranking, so ranking only ever orders products that already satisfy them.
 *
 * Price bounds are parsed separately by wp_ai_agent_wc_parse_intent().
 *
 * @param string $message User message.
 * @return array{rating_min:float|null,discount_min:int|null,in_stock_only:bool,qty_min:int|null}
 */
function wp_ai_agent_wc_extract_numeric_filters( $message ) {
    $m   = ' ' . strtolower( (string) $message ) . ' ';
    $out = array( 'rating_min' => null, 'discount_min' => null, 'in_stock_only' => false, 'qty_min' => null );

    // Rating (1–5), only when a rating/star word is present so plain numbers are
    // never mistaken for a rating.
    if ( preg_match( '/\b(rating|rated|stars?)\b/', $m ) ) {
        if (
            preg_match( '/(\d(?:\.\d)?)\s*(?:\+|stars?|and above|or (?:more|above|higher))/', $m, $r )
            || preg_match( '/(?:above|over|at ?least|min(?:imum)?|more than|greater than|rating of|rated)\s*(\d(?:\.\d)?)/', $m, $r )
            || preg_match( '/(\d(?:\.\d)?)\s*stars?/', $m, $r )
        ) {
            $v = (float) $r[1];
            if ( $v >= 1 && $v <= 5 ) {
                $out['rating_min'] = $v;
            }
        }
    }

    // Minimum discount percentage ("30% off or more", "at least 20% discount").
    if ( preg_match( '/\b(discount|off)\b|%/', $m ) ) {
        if (
            preg_match( '/(\d{1,2})\s*%/', $m, $d )
            || preg_match( '/(?:discount|off)\D{0,12}?(\d{1,2})/', $m, $d )
            || preg_match( '/(\d{1,2})\s*(?:percent|off)/', $m, $d )
        ) {
            $v = (int) $d[1];
            if ( $v > 0 && $v <= 100 ) {
                $out['discount_min'] = $v;
            }
        }
    }

    // In-stock-only (availability) — a hard filter when explicitly requested.
    if (
        preg_match( '/\b(in ?stock|available|availability|purchasable|buy ?now)\b/', $m )
        && ! preg_match( '/\b(out of stock|not available|unavailable|sold out)\b/', $m )
    ) {
        $out['in_stock_only'] = true;
    }

    // Minimum stock quantity ("more than 10 in stock", "at least 5 left/units").
    if (
        preg_match( '/(?:more than|at ?least|atleast|min(?:imum)?|over)\s*(\d{1,5})\s*(?:in stock|available|left|units?|pieces?|pcs|qty|quantity)/', $m, $q )
        || preg_match( '/(\d{1,5})\s*\+?\s*(?:in stock|units? (?:available|left|in stock)|pieces? (?:available|left))/', $m, $q )
    ) {
        $out['qty_min'] = (int) $q[1];
    }

    return $out;
}

/**
 * Searchable taxonomy text for a product (categories, tags, attributes).
 *
 * @param WC_Product $product Product.
 * @return string
 */
function wp_ai_agent_wc_terms( $product ) {
    $out = array();

    foreach ( array( 'product_cat', 'product_tag' ) as $tax ) {
        $names = wp_get_post_terms( $product->get_id(), $tax, array( 'fields' => 'names' ) );
        if ( ! is_wp_error( $names ) ) {
            $out = array_merge( $out, $names );
        }
    }

    // Attribute values (global pa_* taxonomies).
    foreach ( $product->get_attributes() as $taxonomy => $attr ) {
        if ( is_string( $taxonomy ) && taxonomy_exists( $taxonomy ) ) {
            $names = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $names ) ) {
                $out = array_merge( $out, $names );
            }
        }
    }

    return implode( ' ', array_filter( $out ) );
}

/**
 * Format a product as context for the AI (Name, Price, Short description, Link).
 *
 * @param WC_Product $product Product.
 * @return string
 */
function wp_ai_agent_wc_format_product( $product ) {
    $price = html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ) );

    $desc = $product->get_short_description();
    if ( '' === $desc ) {
        $desc = $product->get_description();
    }
    $desc = wp_trim_words( wp_strip_all_tags( $desc ), 30, '' );

    return sprintf(
        "Title: %s\nURL: %s\nContent: Price: %s. %s\n\n",
        $product->get_name(),
        get_permalink( $product->get_id() ),
        ( '' !== $price ) ? $price : 'N/A',
        ( '' !== $desc ) ? $desc : ''
    );
}

/**
 * The meaningful product keywords in a query — tokens minus price/intent filler
 * words and bare numbers. Empty when the query is generic ("suggest products"),
 * non-empty when it names a specific thing ("shoes", "red dress").
 *
 * @param string $message User message.
 * @return string[]
 */
function wp_ai_agent_wc_query_keywords( $message ) {
    $m      = strtolower( $message );
    $tokens = function_exists( 'wp_ai_agent_tokenize_query' )
        ? wp_ai_agent_tokenize_query( $message )
        : preg_split( '/\s+/', $m, -1, PREG_SPLIT_NO_EMPTY );

    $remove = array(
        'under', 'below', 'above', 'over', 'between', 'upto', 'within', 'best', 'bestseller',
        'seller', 'sellers', 'selling', 'popular', 'top', 'trending', 'recommend', 'recommended',
        'suggest', 'suggestion', 'related', 'similar', 'product', 'products', 'buy', 'purchase',
        'price', 'cost', 'budget', 'cheap', 'cheapest', 'expensive', 'rupees', 'rupee', 'inr',
        'student', 'students', 'good', 'better', 'premium', 'item', 'items', 'show', 'find',
        'around', 'about', 'approx', 'approximately', 'worth', 'value', 'priced', 'near', 'nearby',
        // Descriptor / conversational noise that is never a product TYPE — so a
        // query like "red color" reduces to "red", and "online not available"
        // reduces to nothing (rather than matching stray description text).
        'color', 'colour', 'colors', 'colours', 'colored', 'coloured', 'shade', 'shades',
        'not', 'online', 'offline', 'available', 'unavailable', 'avaible', 'availability',
        'size', 'sizes', 'option', 'options', 'type', 'types', 'kind', 'category',
        // Request / filler verbs — never a product type ("provide me X", "list X").
        'provide', 'provides', 'provided', 'give', 'gimme', 'display', 'list', 'listing',
        'looking', 'search', 'searching', 'see', 'view', 'browse', 'please', 'kindly',
        'fetch', 'share', 'want', 'need', 'get', 'show', 'me', 'my', 'the', 'some', 'any',
        // Conversational filler — "tell me MORE about your products", "give me INFO",
        // "any DETAILS" — these are never a product TYPE and must never be searched.
        'more', 'most', 'info', 'information', 'detail', 'details', 'know', 'tell',
        'about', 'anything', 'something', 'everything', 'else', 'us', 'your', 'you',
        'available', 'availability', 'catalogue', 'catalog', 'range', 'lineup',
        // Chat noise — "no i AM ASKING for U to SHOW ME…" — never a product type.
        'asking', 'ask', 'asked', 'u', 'ur', 'yes', 'yeah', 'yep', 'nope', 'okay',
        'hey', 'hii', 'hello', 'actually', 'really', 'sir', 'maam', 'madam', 'for',
        'this', 'that', 'these', 'those', 'here', 'there', 'like', 'liked',
        // Generic apparel filler — "wear" alone is not a product type (unlike the
        // single words footwear/swimwear), so it must not anchor a match.
        'wear', 'wears', 'stuff', 'things', 'thing',
        // Value / deal / vague words — never a product type ("best value for money").
        'money', 'value', 'worth', 'quality', 'great', 'deal', 'deals', 'discount',
        'discounts', 'offer', 'offers', 'list', 'pls', 'plz',
        // Superlatives / quantifiers / preference fillers — never a product type
        // ("biggest discounts", "only men's products", "don't show women's").
        'biggest', 'largest', 'huge', 'massive', 'maximum', 'max', 'only', 'just',
        'simply', 'don', 'dont', 'without', 'except', 'exclude', 'hide', 'none',
        'never', 'avoid', 'remove',
        // "free" must not anchor a match (it wrongly hits a "Free Size" attribute
        // or "free shipping" text); free-product requests are handled separately.
        'free', 'freebie', 'freebies', 'sample', 'samples',
        // Numeric / availability FILTER words (rating, discount, stock, quantity).
        // They drive hard filters, never a product-name match, so they must not
        // survive as keywords (else "products rated 4+" hunts for a "rated" item).
        'rating', 'rated', 'star', 'stars', 'stock', 'instock', 'available',
        'availability', 'unavailable', 'purchasable', 'off', 'percent', 'left',
        'units', 'unit', 'pieces', 'piece', 'pcs', 'qty', 'quantity',
    );
    $keywords = array();
    foreach ( (array) $tokens as $t ) {
        if ( ! in_array( $t, $remove, true ) && ! is_numeric( $t ) ) {
            $keywords[] = $t;
        }
    }
    return $keywords;
}

/**
 * Recognised colour words (used to enforce the colour filter on product search).
 *
 * @return string[]
 */
function wp_ai_agent_wc_color_terms() {
    return apply_filters( 'wp_ai_agent_color_terms', array(
        'black', 'white', 'red', 'blue', 'green', 'yellow', 'pink', 'purple', 'orange',
        'grey', 'gray', 'brown', 'beige', 'navy', 'maroon', 'gold', 'golden', 'silver',
        'cream', 'tan', 'teal', 'olive', 'ivory', 'khaki', 'turquoise', 'magenta',
    ) );
}

/**
 * Gender word map: canonical gender => the words/spellings that indicate it.
 *
 * @return array<string,string[]>
 */
function wp_ai_agent_wc_gender_map() {
    return apply_filters( 'wp_ai_agent_gender_map', array(
        'women' => array( 'women', 'womens', 'woman', 'womans', 'ladies', 'lady', 'female', 'girl', 'girls' ),
        'men'   => array( 'men', 'mens', 'man', 'mans', 'male', 'gents', 'gent', 'gentlemen', 'boy', 'boys' ),
        'kids'  => array( 'kids', 'kid', 'children', 'child', 'toddler', 'baby', 'infant' ),
    ) );
}

/**
 * Needles to look for when a clothing size is requested (so "M" matches both "M"
 * and "Medium" attribute values, etc.).
 *
 * @param string $size Requested size token.
 * @return string[]
 */
function wp_ai_agent_size_needles( $size ) {
    $size = strtolower( trim( $size ) );
    $map  = array(
        's'         => array( 's', 'small' ),
        'small'     => array( 'small', 's' ),
        'm'         => array( 'm', 'medium' ),
        'medium'    => array( 'medium', 'm' ),
        'l'         => array( 'l', 'large' ),
        'large'     => array( 'large', 'l' ),
        'xs'        => array( 'xs', 'x-small', 'extra small' ),
        'xl'        => array( 'xl', 'x-large', 'extra large' ),
        'xxl'       => array( 'xxl', '2xl', 'xx-large' ),
        '2xl'       => array( '2xl', 'xxl' ),
        'xxxl'      => array( 'xxxl', '3xl' ),
        '3xl'       => array( '3xl', 'xxxl' ),
        'free size' => array( 'free size', 'freesize', 'one size' ),
    );
    return isset( $map[ $size ] ) ? $map[ $size ] : array( $size );
}

/**
 * Extract structured product filters (colour, gender, size) from a raw message.
 *
 * Read from the RAW text (not the tokenizer) so short sizes like "XL"/"S" — which
 * the tokenizer drops — are still captured. These enforce the "never ignore
 * colour / size / gender" rules in product search.
 *
 * @param string $message User message.
 * @return array{colors:string[],genders:string[],sizes:string[]}
 */
function wp_ai_agent_extract_product_filters( $message ) {
    $m = ' ' . strtolower( (string) $message ) . ' ';

    $word = function ( $needle ) use ( $m ) {
        return (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . "(?:'s|s)?(?![\p{L}\p{N}])/u", $m );
    };

    // Colours (OR — any requested colour).
    $colors = array();
    foreach ( wp_ai_agent_wc_color_terms() as $c ) {
        if ( $word( $c ) ) {
            $colors[] = $c;
        }
    }

    // Gender (canonical buckets).
    $genders = array();
    foreach ( wp_ai_agent_wc_gender_map() as $canon => $words ) {
        foreach ( $words as $w ) {
            if ( $word( $w ) ) {
                $genders[ $canon ] = true;
                break;
            }
        }
    }
    // "men and women" / "for everyone" → no gender filter (both present).
    if ( isset( $genders['men'], $genders['women'] ) ) {
        unset( $genders['men'], $genders['women'] );
    }

    // Sizes: named sizes + "size N" / "size M".
    $sizes = array();
    if ( preg_match_all( '/\b(xxxl|xxl|3xl|2xl|xs|xl|small|medium|large|free size)\b/i', $m, $sm ) ) {
        foreach ( $sm[1] as $s ) {
            $sizes[] = strtolower( $s );
        }
    }
    if ( preg_match_all( '/\bsize\s*[:\-]?\s*([a-z0-9]{1,4})\b/i', $m, $sn ) ) {
        foreach ( $sn[1] as $s ) {
            $sizes[] = strtolower( $s );
        }
    }

    return array(
        'colors'  => array_values( array_unique( $colors ) ),
        'genders' => array_keys( $genders ),
        'sizes'   => array_values( array_unique( $sizes ) ),
    );
}

/**
 * Enforce colour / gender / size filters on a scored product list.
 *
 * For each requested facet the list is narrowed to products that match it (via
 * name + categories + tags + attributes). The narrowing is RELAXED for a facet
 * only when it would leave zero products — i.e. the store simply does not record
 * that attribute — so a colour/size/gender request is honoured wherever the data
 * exists, without wrongly returning an empty result on stores that lack it.
 *
 * @param array[] $scored  Rows of array( 'product' => WC_Product, ... ).
 * @param string  $message User message.
 * @return array[]
 */
function wp_ai_agent_wc_filter_by_facets( $scored, $message ) {
    $f = wp_ai_agent_extract_product_filters( $message );
    if ( empty( $f['colors'] ) && empty( $f['genders'] ) && empty( $f['sizes'] ) ) {
        return $scored;
    }

    // Searchable haystack per product (name + categories + tags + attributes).
    foreach ( $scored as $i => $row ) {
        $scored[ $i ]['facet_hay'] = ' ' . strtolower( $row['product']->get_name() . ' ' . wp_ai_agent_wc_terms( $row['product'] ) ) . ' ';
    }

    $narrow = function ( $rows, $needles ) {
        $needles = array_values( array_unique( array_filter( $needles ) ) );
        if ( empty( $needles ) ) {
            return $rows;
        }
        $kept = array();
        foreach ( $rows as $row ) {
            foreach ( $needles as $n ) {
                if ( wp_ai_agent_term_match( $row['facet_hay'], $n ) ) {
                    $kept[] = $row;
                    break;
                }
            }
        }
        // Relax when the store does not track this attribute (no product matched).
        return ! empty( $kept ) ? $kept : $rows;
    };

    // Strict narrower for MANDATORY facets (colour, size): keep products matching
    // the requested value. If NONE match, relax ONLY when the store doesn't track
    // this attribute at all (no product mentions ANY value from $universe);
    // otherwise return EMPTY so the caller can honestly say "no exact match"
    // instead of silently swapping in the wrong colour/size.
    $narrow_strict = function ( $rows, $want, $universe ) {
        $want = array_values( array_unique( array_filter( $want ) ) );
        if ( empty( $want ) ) {
            return $rows;
        }
        $kept = array();
        foreach ( $rows as $row ) {
            foreach ( $want as $n ) {
                if ( wp_ai_agent_term_match( $row['facet_hay'], $n ) ) {
                    $kept[] = $row;
                    break;
                }
            }
        }
        if ( ! empty( $kept ) ) {
            return $kept;
        }
        // Requested value not found — is this attribute tracked on ANY candidate?
        $tracked = false;
        foreach ( $rows as $row ) {
            foreach ( (array) $universe as $u ) {
                if ( wp_ai_agent_term_match( $row['facet_hay'], $u ) ) {
                    $tracked = true;
                    break 2;
                }
            }
        }
        return $tracked ? array() : $rows; // tracked → strict empty; untracked → relax.
    };

    // Colour is MANDATORY — never silently swapped for a different colour.
    if ( ! empty( $f['colors'] ) ) {
        $scored = $narrow_strict( $scored, $f['colors'], wp_ai_agent_wc_color_terms() );
        if ( empty( $scored ) ) {
            return array();
        }
    }

    if ( ! empty( $f['genders'] ) ) {
        $map = wp_ai_agent_wc_gender_map();

        // Needles for the REQUESTED gender(s)…
        $want = array();
        foreach ( $f['genders'] as $g ) {
            if ( isset( $map[ $g ] ) ) {
                $want = array_merge( $want, $map[ $g ] );
            }
        }
        $want = array_values( array_unique( $want ) );

        // …and every gender needle, used to detect a product's OWN gender.
        $all_gender = array();
        foreach ( $map as $words ) {
            $all_gender = array_merge( $all_gender, $words );
        }
        $all_gender = array_values( array_unique( $all_gender ) );

        // Gender is a MANDATORY filter (no relaxing): keep products that match the
        // requested gender, and keep unisex / ungendered products, but ALWAYS drop
        // products that clearly belong to a different gender — so a Men's search
        // never leaks a Women's product, and vice-versa.
        $kept = array();
        foreach ( $scored as $row ) {
            $hay          = $row['facet_hay'];
            $matches_want = false;
            foreach ( $want as $n ) {
                if ( wp_ai_agent_term_match( $hay, $n ) ) {
                    $matches_want = true;
                    break;
                }
            }
            if ( $matches_want ) {
                $kept[] = $row;
                continue;
            }
            $other_gender = false;
            foreach ( $all_gender as $n ) {
                if ( in_array( $n, $want, true ) ) {
                    continue;
                }
                if ( wp_ai_agent_term_match( $hay, $n ) ) {
                    $other_gender = true;
                    break;
                }
            }
            if ( ! $other_gender ) {
                $kept[] = $row; // unisex / ungendered — safe to keep.
            }
            // else: belongs to a different gender only → drop it.
        }
        $scored = $kept;
    }

    // Size is MANDATORY too — a requested size is never silently ignored.
    if ( ! empty( $f['sizes'] ) ) {
        $needles = array();
        foreach ( $f['sizes'] as $s ) {
            $needles = array_merge( $needles, wp_ai_agent_size_needles( $s ) );
        }
        $size_universe = array( 'xs', 's', 'small', 'm', 'medium', 'l', 'large', 'xl', 'xxl', 'xxxl', '2xl', '3xl', 'free size', 'one size' );
        $scored = $narrow_strict( $scored, $needles, $size_universe );
        if ( empty( $scored ) ) {
            return array();
        }
    }

    return $scored;
}

/**
 * Rank WooCommerce products for a natural-language query and return the
 * matching product objects (best first). Shared by the text-context search and
 * the structured product-card builder. Returns [] when the message is not a
 * product query or nothing matches.
 *
 * @param string   $message    User message.
 * @param int|null $limit      Max products (defaults to the wc_result_count filter).
 * @param string   $match_type Set by reference to 'exact' (real type matches),
 *                             'similar' (only related types found) or null.
 * @return WC_Product[]
 */
function wp_ai_agent_wc_rank_products( $message, $limit = null, &$match_type = null, &$total = null ) {
    $match_type = null;
    $total      = 0;
    if ( ! wp_ai_agent_wc_active() ) {
        return array();
    }

    $m     = strtolower( $message );
    $flags = wp_ai_agent_wc_parse_intent( $m );

    // Non-price numeric / availability filters (rating, min discount, in-stock,
    // min quantity). These + the price bounds above are HARD filters, applied to
    // the candidate set below BEFORE any relevance ranking.
    $nf = wp_ai_agent_wc_extract_numeric_filters( $message );

    // A rating / quantity / discount number must never double as a price bound
    // (e.g. "rating above 4" would otherwise set a $4 minimum PRICE). If the price
    // parser picked up the same value, drop that price bound.
    $reserved = array();
    if ( null !== $nf['rating_min'] ) {
        $reserved[] = (float) $nf['rating_min'];
    }
    if ( null !== $nf['qty_min'] ) {
        $reserved[] = (float) $nf['qty_min'];
    }
    if ( null !== $nf['discount_min'] ) {
        $reserved[] = (float) $nf['discount_min'];
    }
    foreach ( $reserved as $rn ) {
        if ( null !== $flags['min'] && abs( (float) $flags['min'] - $rn ) < 0.001 ) {
            $flags['min'] = null;
        }
        if ( null !== $flags['max'] && abs( (float) $flags['max'] - $rn ) < 0.001 ) {
            $flags['max'] = null;
        }
    }

    // Meaningful product keywords (minus price/intent filler words and numbers).
    $keywords = wp_ai_agent_wc_query_keywords( $message );

    // Type-anchor: the "type" keywords are the non-generic ones (e.g. "shirt"
    // out of "white shirt"). If the query is only generic words ("white"), every
    // keyword counts as a type.
    $type_keywords = array_values( array_diff( $keywords, wp_ai_agent_generic_terms() ) );
    if ( empty( $type_keywords ) ) {
        $type_keywords = $keywords;
    }

    // Intent present if any explicit signal was found (price bounds, sort flags,
    // or a numeric/availability filter such as rating / discount / in-stock).
    $is_product_query = (
        $flags['best'] || $flags['related'] || $flags['reco']
        || null !== $flags['min'] || null !== $flags['max']
        || null !== $nf['rating_min'] || null !== $nf['discount_min']
        || null !== $nf['qty_min'] || $nf['in_stock_only']
    );

    $products = wc_get_products( array(
        'status' => 'publish',
        'limit'  => (int) apply_filters( 'wp_ai_agent_wc_search_limit', 200 ),
    ) );
    if ( empty( $products ) ) {
        return array();
    }

    // Field weights — exact title / SKU / slug rank highest, then category,
    // attributes, tags, and finally the free-text description (Priority 1→8).
    $subject     = trim( implode( ' ', $keywords ) );
    $subject_sku = str_replace( ' ', '-', $subject );
    $has_strict  = function_exists( 'wp_ai_agent_term_match_strict' );

    // Exact-type matches (real product of that type) and "similar" ones (a
    // related/compound type, e.g. t-shirt for "shirt") are kept apart so exact
    // always wins and similar is only a fallback.
    $exact_rows = array();
    $loose_rows = array();

    foreach ( $products as $product ) {
        $price = (float) $product->get_price();
        // A price-range query ("under $200", "over $50", "around $100") means the
        // customer wants real, priced products. Skip items with no positive price
        // (e.g. a $0 / "choose amount" gift card) so they never top a price search.
        if ( ( null !== $flags['min'] || null !== $flags['max'] ) && $price <= 0 ) {
            continue;
        }
        if ( null !== $flags['min'] && $price < $flags['min'] ) {
            continue;
        }
        if ( null !== $flags['max'] && $price > $flags['max'] ) {
            continue;
        }

        // --- Hard numeric / availability filters ---
        // Applied here, on the raw candidate set, BEFORE any relevance ranking or
        // sorting — so ranking only ever orders products that already satisfy
        // every numeric condition. A product failing any one is dropped outright.
        if ( $nf['in_stock_only'] && ! $product->is_in_stock() ) {
            continue;
        }
        if ( null !== $nf['rating_min'] && (float) $product->get_average_rating() < $nf['rating_min'] ) {
            continue;
        }
        if ( null !== $nf['discount_min'] ) {
            $pct = function_exists( 'wp_ai_agent_discount_percent' ) ? (int) wp_ai_agent_discount_percent( $product ) : 0;
            if ( $pct < $nf['discount_min'] ) {
                continue;
            }
        }
        if ( null !== $nf['qty_min'] ) {
            $qty = $product->get_stock_quantity();
            // Only enforce when the store actually tracks stock quantity; if it
            // is unmanaged (null) we cannot verify, so we don't wrongly drop it.
            if ( null !== $qty && (int) $qty < $nf['qty_min'] ) {
                continue;
            }
        }

        // No specific keywords (e.g. "best sellers", "cheapest"): keep the whole
        // catalog for the flag-based ordering below.
        if ( empty( $keywords ) ) {
            $exact_rows[] = array( 'product' => $product, 'rel' => 0, 'sales' => (int) $product->get_total_sales(), 'price' => $price, 'instock' => $product->is_in_stock() );
            continue;
        }

        // Field-separated text (name / category / brand / tag / attribute / desc).
        $f = function_exists( 'wp_ai_agent_product_field_text' )
            ? wp_ai_agent_product_field_text( $product )
            : array( 'name' => $product->get_name(), 'category' => '', 'brand' => '', 'tag' => '', 'attribute' => '', 'desc' => wp_ai_agent_wc_terms( $product ) );

        $name = ' ' . strtolower( $f['name'] ) . ' ';
        $cat  = ' ' . strtolower( $f['category'] ) . ' ';
        $tag  = ' ' . strtolower( $f['tag'] ) . ' ';
        $attr = ' ' . strtolower( $f['attribute'] . ' ' . $f['brand'] ) . ' ';
        $desc = ' ' . strtolower( $f['desc'] ) . ' ';
        $sku  = strtolower( (string) $product->get_sku() );
        $slug = strtolower( (string) $product->get_slug() );

        $rel   = 0;
        $score = 0;

        // Priority 1–3: exact whole title, SKU, slug.
        if ( '' !== $subject && trim( strtolower( $f['name'] ) ) === $subject ) {
            $rel += 1000;
        }
        if ( '' !== $sku && ( $sku === $subject || in_array( $sku, $keywords, true ) ) ) {
            $rel += 600;
        }
        if ( '' !== $slug && '' !== $subject_sku && false !== strpos( $slug, $subject_sku ) ) {
            $rel += 300;
        }

        // Priority 4–7: category, attribute, tag, description (weighted).
        $weights = array( 'name' => 200, 'cat' => 140, 'attr' => 90, 'tag' => 60, 'desc' => 12 );
        foreach ( $keywords as $kw ) {
            $needles = function_exists( 'wp_ai_agent_expand_token' ) ? wp_ai_agent_expand_token( $kw ) : array( $kw );
            $field   = '';
            foreach ( array( 'name' => $name, 'cat' => $cat, 'attr' => $attr, 'tag' => $tag, 'desc' => $desc ) as $key => $hay ) {
                foreach ( $needles as $needle ) {
                    if ( wp_ai_agent_term_match( $hay, $needle ) ) {
                        $field = $key;
                        break 2;
                    }
                }
            }
            if ( '' !== $field ) {
                $score++;
                $rel += $weights[ $field ];
            }
        }

        // TYPE gate: the product must at least be a related type (matched in
        // name / category / tag / attribute — never the description).
        $strong      = $name . $cat . $tag . $attr;
        $type_strict = false;
        $type_loose  = false;
        foreach ( $type_keywords as $kw ) {
            $needles = function_exists( 'wp_ai_agent_expand_token' ) ? wp_ai_agent_expand_token( $kw ) : array( $kw );
            foreach ( $needles as $needle ) {
                if ( wp_ai_agent_term_match( $strong, $needle ) ) {
                    $type_loose = true;
                    // Strict (hyphen-aware) hit = a true exact type ("shirt" ≠ "t-shirt").
                    if ( $has_strict ? wp_ai_agent_term_match_strict( $strong, $needle ) : true ) {
                        $type_strict = true;
                    }
                }
            }
        }

        if ( 0 === $score || ! $type_loose ) {
            continue; // not even a related product type.
        }
        $is_product_query = true;

        $row = array( 'product' => $product, 'rel' => $rel, 'sales' => (int) $product->get_total_sales(), 'price' => $price, 'instock' => $product->is_in_stock() );
        if ( $type_strict ) {
            $exact_rows[] = $row;
        } else {
            $loose_rows[] = $row;
        }
    }

    if ( ! $is_product_query ) {
        return array();
    }

    // Exact-first: only fall back to similar (related-type) products when NO
    // exact product of the requested type exists.
    $used_exact = ! empty( $exact_rows );
    $scored     = $used_exact ? $exact_rows : $loose_rows;
    if ( empty( $scored ) ) {
        return array();
    }
    // Signal exact vs similar only when the visitor named a real product type.
    $match_type = empty( $keywords ) ? null : ( $used_exact ? 'exact' : 'similar' );

    // Enforce colour / gender / size filters (relaxed only when the store does
    // not track the attribute, so we never wrongly return an empty list).
    $scored = wp_ai_agent_wc_filter_by_facets( $scored, $message );
    if ( empty( $scored ) ) {
        return array();
    }

    // Ordering: explicit best/price flags win; otherwise by relevance tier, then
    // best-selling as a tie-breaker.
    if ( $flags['best'] ) {
        usort( $scored, function ( $a, $b ) {
            return $b['sales'] <=> $a['sales'];
        } );
    } elseif ( 'ASC' === $flags['order'] ) {
        usort( $scored, function ( $a, $b ) {
            return $a['price'] <=> $b['price'];
        } );
    } elseif ( 'DESC' === $flags['order'] ) {
        usort( $scored, function ( $a, $b ) {
            return $b['price'] <=> $a['price'];
        } );
    } else {
        usort( $scored, function ( $a, $b ) {
            if ( $a['rel'] !== $b['rel'] ) {
                return $b['rel'] <=> $a['rel'];
            }
            return $b['sales'] <=> $a['sales'];
        } );
    }

    // In-stock products rank above out-of-stock, preserving order within groups.
    $instock = array();
    $out     = array();
    foreach ( $scored as $row ) {
        if ( ! empty( $row['instock'] ) ) {
            $instock[] = $row;
        } else {
            $out[] = $row;
        }
    }
    $scored = array_merge( $instock, $out );

    // Total matches within the (price / facet) filters, BEFORE the display cap —
    // so the reply can say "I found 18 products under $200" and offer the rest.
    $total = count( $scored );

    $count = ( null !== $limit ) ? (int) $limit : (int) apply_filters( 'wp_ai_agent_wc_result_count', 8 );
    $use   = array_slice( $scored, 0, $count );

    return array_map(
        function ( $row ) {
            return $row['product'];
        },
        $use
    );
}

/**
 * Natural-language WooCommerce product search, formatted as text context for
 * the AI. Returns '' if not a product query.
 *
 * @param string $message User message.
 * @return string
 */
function wp_ai_agent_wc_product_search( $message ) {
    $products = wp_ai_agent_wc_rank_products( $message );
    if ( empty( $products ) ) {
        return '';
    }

    $context = '';
    foreach ( $products as $product ) {
        $context .= wp_ai_agent_wc_format_product( $product );
    }

    return trim( $context );
}
