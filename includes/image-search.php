<?php
/**
 * Visual product search (WooCommerce only).
 * A visitor uploads a product photo; a vision-capable model (OpenAI gpt-4o,
 * Gemini, or Groq Llama-4 vision) describes it as keywords + attributes
 * (product type, category, brand, color, material, pattern, style). We then
 * match those against this website's WooCommerce catalog using weighted
 * similarity (name, category, brand, tags, attributes, description) and return
 * the best-ranked products. No outside data and no non-product content is ever
 * used — the search is restricted strictly to WooCommerce products.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Exact message shown when no WooCommerce product matches the image. */
function wp_ai_agent_image_no_match_message() {
    return apply_filters(
        'wp_ai_agent_image_no_match_message',
        __( "Sorry, I couldn't find any similar products on this website.", 'wp-ai-agent' )
    );
}

/**
 * Record / read why image analysis failed.
 *
 * The vision API error is otherwise swallowed (every failure just returns ''),
 * which is exactly why "I couldn't read that image" appears with no clue why.
 * Pass a string to append a diagnostic (also written to the error log); call
 * with no args to read the joined diagnostics; pass false to reset.
 *
 * @param string|null|false $message Append (string), read (null), or reset (false).
 * @return string
 * 
 */

function wp_ai_agent_vision_diag( $message = null ) {
    static $diag = array();

    if ( false === $message ) {
        $diag = array();
        return '';
    }

    if ( null !== $message && '' !== $message ) {
        $diag[] = (string) $message;
        // Always log: a misconfigured vision provider is worth surfacing to admins.
        error_log( '[wp-ai-agent] image analysis: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        return '';
    }

    return implode( ' | ', $diag );
}

/**
 * Extract a human-readable error detail from a vision API response.
 *
 * @param WP_Error|array $response wp_remote_post result.
 * @return string
 */
function wp_ai_agent_vision_error_detail( $response ) {
    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = (string) wp_remote_retrieve_body( $response );
    $json = json_decode( $body, true );

    $msg = '';
    if ( is_array( $json ) ) {
        if ( ! empty( $json['error']['message'] ) ) {
            $msg = $json['error']['message'];
        } elseif ( ! empty( $json['error'] ) && is_string( $json['error'] ) ) {
            $msg = $json['error'];
        }
    }
    if ( '' === $msg ) {
        $msg = substr( trim( wp_strip_all_tags( $body ) ), 0, 300 );
    }

    return 'HTTP ' . $code . ': ' . $msg;
}

/**
 * Which provider to use for image (vision) analysis. Supports OpenAI, Gemini,
 * and Groq (Groq exposes OpenAI-compatible vision models). Prefers the active
 * provider, then any provider that has a key.
 *
 * @return string 'openai' | 'gemini' | 'groq' | ''.
 */
function wp_ai_agent_vision_provider() {
    $order = wp_ai_agent_vision_provider_order();
    return ! empty( $order ) ? $order[0] : '';
}

/**
 * Ordered list of vision providers to try: the active provider first, then any
 * other provider that has an API key. Used so image analysis falls back to a
 * second provider when the first one errors out (the cause of the recurring
 * "I couldn't read that image" failure).
 *
 * @return string[]
 */

function wp_ai_agent_vision_provider_order() {
    $options  = wp_ai_agent_get_options();
    $provider = isset( $options['provider'] ) ? $options['provider'] : '';

    $candidates = array( 'openai', 'gemini', 'groq' );

    $ordered = array();
    if ( in_array( $provider, $candidates, true ) ) {
        $ordered[] = $provider; // active provider first.
    }
    foreach ( $candidates as $cand ) {
        if ( ! in_array( $cand, $ordered, true ) ) {
            $ordered[] = $cand;
        }
    }

    // Keep only providers that actually have a key.
    $available = array();
    foreach ( $ordered as $cand ) {
        if ( wp_ai_agent_provider_key( $cand ) ) {
            $available[] = $cand;
        }
    }

    return $available;
}

/**
 * Describe an uploaded product image as search keywords / attributes.
 *
 * Tries each available vision provider in turn (active provider first) so a
 * single provider outage no longer breaks image search.
 *
 * @param string $base64 Base64 image data (no data: prefix).
 * @param string $mime   MIME type.
 * @return string Space-separated keywords describing the product, or ''.
 * 
 */

function wp_ai_agent_describe_image( $base64, $mime ) {
    wp_ai_agent_vision_diag( false ); // reset diagnostics for this attempt.

    $prompt = 'You are a product catalog assistant for an online store. Identify the MAIN product to search for. '
        . 'If a person is present, focus ONLY on the most prominent product they are WEARING or HOLDING '
        . '(e.g. t-shirt, shirt, jacket, dress, jeans, shoes, watch, bag, cap, sunglasses) and IGNORE the person, '
        . 'their face, pose, background and setting. '
        . 'The VERY FIRST word MUST be the product type as a single common noun (e.g. shirt, tshirt, jeans, shoes, watch, bag, saree, kurta, jacket, dress). '
        . 'Then add its colour, material, pattern, style, and the target gender (men/women/kids) if it is visible. '
        . 'Output 6 to 12 lowercase keywords separated by single spaces. No sentences, no punctuation, no numbering, no extra commentary — keywords only.';

    foreach ( wp_ai_agent_vision_provider_order() as $provider ) {
        if ( 'openai' === $provider ) {
            $keywords = wp_ai_agent_vision_openai( $base64, $mime, $prompt );
        } elseif ( 'groq' === $provider ) {
            $keywords = wp_ai_agent_vision_groq( $base64, $mime, $prompt );
        } else {
            $keywords = wp_ai_agent_vision_gemini( $base64, $mime, $prompt );
        }

        $keywords = wp_ai_agent_clean_vision_keywords( $keywords );
        if ( '' !== $keywords ) {
            return $keywords;
        }
    }

    return '';
}

/**
 * Normalize a vision model's reply into clean space-separated keywords. Strips
 * punctuation, list markers, and obvious filler so the matcher gets pure terms.
 *
 * @param string $text Raw model output.
 * @return string
 */
function wp_ai_agent_clean_vision_keywords( $text ) {
    $text = strtolower( wp_strip_all_tags( (string) $text ) );
    if ( '' === trim( $text ) ) {
        return '';
    }

    // Drop common lead-ins some models add despite instructions.
    $text = preg_replace( '/\b(keywords?|tags?|the (main )?product (is|appears to be)|this (image|is)|i see|it (is|looks))\b[:\-]?/', ' ', $text );

    // Commas / newlines / list bullets become spaces; keep letters, numbers, spaces, hyphens.
    $text = preg_replace( '/[^\p{L}\p{N}\s\-]+/u', ' ', $text );
    $text = preg_replace( '/\s+/u', ' ', $text );

    return trim( $text );
}

/**
 * Groq vision call (OpenAI-compatible chat completions with an image).
 *
 * @param string $base64 Base64 image.
 * @param string $mime   MIME.
 * @param string $prompt Prompt.
 * @return string
 */

function wp_ai_agent_vision_groq( $base64, $mime, $prompt ) {
    $key = wp_ai_agent_provider_key( 'groq' );
    if ( '' === $key ) {
        return '';
    }

    $body = array(
        'model'      => apply_filters( 'wp_ai_agent_vision_model', 'meta-llama/llama-4-scout-17b-16e-instruct', 'groq' ),
        'max_tokens' => 120,
        'messages'   => array(
            array(
                'role'    => 'user',
                'content' => array(
                    array( 'type' => 'text', 'text' => $prompt ),
                    array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $mime . ';base64,' . $base64 ) ),
                ),
            ),
        ),
    );

    $model    = $body['model'];
    $response = wp_remote_post( 'https://api.groq.com/openai/v1/chat/completions', array(
        'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ),
        'body'    => wp_json_encode( $body ),
        'timeout' => 45,
    ) );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        wp_ai_agent_vision_diag( 'groq (' . $model . ') ' . wp_ai_agent_vision_error_detail( $response ) );
        return '';
    }
    $b = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset( $b['choices'][0]['message']['content'] ) ) {
        return sanitize_text_field( $b['choices'][0]['message']['content'] );
    }
    wp_ai_agent_vision_diag( 'groq (' . $model . '): response had no content' );
    return '';
}

/**
 * OpenAI vision call.
 *
 * @param string $base64 Base64 image.
 * @param string $mime   MIME.
 * @param string $prompt Prompt.
 * @return string
 */
function wp_ai_agent_vision_openai( $base64, $mime, $prompt ) {
    $key = wp_ai_agent_provider_key( 'openai' );
    if ( '' === $key ) {
        return '';
    }

    $body = array(
        'model'      => apply_filters( 'wp_ai_agent_vision_model', 'gpt-4o-mini', 'openai' ),
        'max_tokens' => 120,
        'messages'   => array(
            array(
                'role'    => 'user',
                'content' => array(
                    array( 'type' => 'text', 'text' => $prompt ),
                    array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $mime . ';base64,' . $base64 ) ),
                ),
            ),
        ),
    );

    $model    = $body['model'];
    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
        'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ),
        'body'    => wp_json_encode( $body ),
        'timeout' => 45,
    ) );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        wp_ai_agent_vision_diag( 'openai (' . $model . ') ' . wp_ai_agent_vision_error_detail( $response ) );
        return '';
    }
    $b = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset( $b['choices'][0]['message']['content'] ) ) {
        return sanitize_text_field( $b['choices'][0]['message']['content'] );
    }
    wp_ai_agent_vision_diag( 'openai (' . $model . '): response had no content' );
    return '';
}

/**
 * Gemini vision call.
 *
 * @param string $base64 Base64 image.
 * @param string $mime   MIME.
 * @param string $prompt Prompt.
 * @return string
 */
function wp_ai_agent_vision_gemini( $base64, $mime, $prompt ) {
    $key = wp_ai_agent_provider_key( 'gemini' );
    if ( '' === $key ) {
        return '';
    }

    // Current Gemini vision models, newest first. The old gemini-1.5-flash is
    // being retired and now 404s for many keys, which is a common cause of the
    // "I couldn't read that image" error — so we try several and fall back.
    $models = apply_filters( 'wp_ai_agent_vision_gemini_models', array(
        'gemini-2.0-flash',
        'gemini-2.5-flash',
        'gemini-1.5-flash',
    ) );
    
    // Honor an explicit single-model override (legacy filter) by trying it first.
    $override = apply_filters( 'wp_ai_agent_vision_model', '', 'gemini' );
    if ( is_string( $override ) && '' !== $override ) {
        array_unshift( $models, $override );
    }

    $models = array_values( array_unique( array_filter( (array) $models ) ) );
    
    $body = array(
        'contents' => array(
            array(
                'parts' => array(
                    array( 'text' => $prompt ),
                    array( 'inline_data' => array( 'mime_type' => $mime, 'data' => $base64 ) ),
                ),
            ),
        ),
    );

    foreach ( $models as $model ) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
         
        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            wp_ai_agent_vision_diag( 'gemini (' . $model . ') ' . wp_ai_agent_vision_error_detail( $response ) );
            continue; // Try the next model (e.g. on 404 model-not-found).
        }   

        $b = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $b['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return sanitize_text_field( $b['candidates'][0]['content']['parts'][0]['text'] );
        }

        // 200 but no text usually means a safety block / empty candidate.
        $reason = isset( $b['candidates'][0]['finishReason'] ) ? $b['candidates'][0]['finishReason'] : 'no content';
        wp_ai_agent_vision_diag( 'gemini (' . $model . '): ' . $reason );
    }

    return '';
}

/* -------------------------------------------------------------------------
 * WooCommerce-only product matching.
 * ---------------------------------------------------------------------- */

/**
 * Brand taxonomies / attribute names commonly used by WooCommerce brand
 * plugins. Used to give brand matches their own weight.
 *
 * @return string[]
*/

function wp_ai_agent_brand_taxonomies() {
    return apply_filters( 'wp_ai_agent_brand_taxonomies', array(
        'product_brand', 'pa_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'brand',
    ) );
}

/**
 * Collect a product's searchable text, grouped by field so each field can be
 * scored with its own weight.
 *
 * @param WC_Product $product Product.
 * @return array{name:string,category:string,brand:string,tag:string,attribute:string,desc:string}
*/

function wp_ai_agent_product_field_text( $product ) {
    $id          = $product->get_id();
    $brand_taxes = wp_ai_agent_brand_taxonomies();

    $categories = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
    $tags       = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'names' ) );

    $brand_terms = array();
    foreach ( $brand_taxes as $tax ) {
        if ( taxonomy_exists( $tax ) ) {
            $names = wp_get_post_terms( $id, $tax, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $names ) ) {
                $brand_terms = array_merge( $brand_terms, $names );
            }
        }
    }

    // Product attributes (global pa_* taxonomies and local/custom attributes).
    $attr_values = array();
    foreach ( $product->get_attributes() as $taxonomy => $attr ) {
        // Route brand-like attributes into the brand bucket instead.
        $is_brand = false;
        foreach ( $brand_taxes as $bt ) {
            if ( false !== stripos( (string) $taxonomy, 'brand' ) || $bt === $taxonomy ) {
                $is_brand = true;
                break;
            }
        }

        $values = array();
        if ( is_string( $taxonomy ) && taxonomy_exists( $taxonomy ) ) {
            $names = wp_get_post_terms( $id, $taxonomy, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $names ) ) {
                $values = $names;
            }
        } elseif ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
            $values = (array) $attr->get_options();
        }

        if ( $is_brand ) {
            $brand_terms = array_merge( $brand_terms, $values );
        } else {
            $attr_values = array_merge( $attr_values, $values );
        }
    }

    return array(
        'name'      => (string) $product->get_name(),
        'category'  => is_wp_error( $categories ) ? '' : implode( ' ', $categories ),
        'brand'     => implode( ' ', array_filter( $brand_terms ) ),
        'tag'       => is_wp_error( $tags ) ? '' : implode( ' ', $tags ),
        'attribute' => implode( ' ', array_filter( $attr_values ) ),
        'desc'      => wp_strip_all_tags( $product->get_short_description() . ' ' . $product->get_description() ),
    );
}

/**
 * Synonyms for a detected product TYPE, so the type gate is robust (a "shirt"
 * photo still matches products named "tee"/"t-shirt"/"top", "shoes" matches
 * "footwear"/"sneakers", etc.). Filterable so any store can extend it.
 *
 * @param string $type Detected type token.
 * @return string[]
 */
function wp_ai_agent_image_type_synonyms( $type ) {
    $map = apply_filters( 'wp_ai_agent_image_type_synonyms', array(
        'shirt'    => array( 'shirt', 'tshirt', 't-shirt', 'tee', 'top' ),
        'tshirt'   => array( 'tshirt', 't-shirt', 'tee', 'shirt', 'top' ),
        'tee'      => array( 'tee', 'tshirt', 't-shirt', 'shirt', 'top' ),
        'top'      => array( 'top', 'tshirt', 'tee', 'shirt', 'blouse' ),
        'blouse'   => array( 'blouse', 'top', 'shirt' ),
        'kurta'    => array( 'kurta', 'kurti', 'tunic' ),
        'shoe'     => array( 'shoe', 'shoes', 'footwear', 'sneaker', 'sneakers', 'trainers' ),
        'shoes'    => array( 'shoes', 'shoe', 'footwear', 'sneaker', 'sneakers', 'trainers' ),
        'sneaker'  => array( 'sneaker', 'sneakers', 'shoes', 'shoe', 'footwear' ),
        'jean'     => array( 'jean', 'jeans', 'denim', 'pant', 'pants', 'trouser', 'trousers' ),
        'jeans'    => array( 'jeans', 'jean', 'denim', 'pant', 'pants', 'trouser', 'trousers' ),
        'pant'     => array( 'pant', 'pants', 'trouser', 'trousers', 'jeans' ),
        'trouser'  => array( 'trouser', 'trousers', 'pant', 'pants' ),
        'short'    => array( 'short', 'shorts' ),
        'dress'    => array( 'dress', 'dresses', 'gown', 'frock' ),
        'saree'    => array( 'saree', 'sari', 'sarees' ),
        'watch'    => array( 'watch', 'watches', 'timepiece' ),
        'bag'      => array( 'bag', 'bags', 'handbag', 'backpack', 'purse', 'tote' ),
        'sock'     => array( 'sock', 'socks' ),
        'jacket'   => array( 'jacket', 'jackets', 'coat', 'blazer' ),
        'hoodie'   => array( 'hoodie', 'hoodies', 'sweatshirt' ),
        'ring'     => array( 'ring', 'rings' ),
        'necklace' => array( 'necklace', 'necklaces', 'chain', 'pendant' ),
        'earring'  => array( 'earring', 'earrings', 'stud', 'studs', 'jhumka' ),
        'bracelet' => array( 'bracelet', 'bracelets', 'bangle', 'bangles' ),
    ) );
    $type = strtolower( $type );
    return isset( $map[ $type ] ) ? $map[ $type ] : array();
}

/**
 * Visual-search ranking engine. Scores EVERY WooCommerce product against the
 * image keywords across all product data (title, description, short desc,
 * categories, tags, attributes, SKU) plus a semantic/embedding boost, ranks
 * them, and returns the top matches. The product TYPE is a strong weight + bonus
 * (so a shirt ranks above shoes) but NOT a hard filter — so an existing product
 * is never wrongly dropped. Only returns empty when NOTHING overlaps at all.
 *
 * @param string $keywords Space-separated keywords from image analysis.
 * @param int    $limit    Max products to return.
 * @return array{products:WC_Product[],confident:bool,debug:string}
 */
function wp_ai_agent_image_match_products( $keywords, $limit = 5 ) {
    $empty = array( 'products' => array(), 'confident' => false, 'debug' => 'no woocommerce' );
    if ( ! function_exists( 'wc_get_products' ) ) {
        return $empty;
    }

    $tokens = function_exists( 'wp_ai_agent_tokenize_query' )
        ? wp_ai_agent_tokenize_query( $keywords )
        : array_filter( preg_split( '/\s+/', strtolower( $keywords ), -1, PREG_SPLIT_NO_EMPTY ) );
    $tokens = array_values( array_unique( $tokens ) );
    if ( empty( $tokens ) ) {
        return array( 'products' => array(), 'confident' => false, 'debug' => 'no keywords' );
    }

    $token_needles = array();
    foreach ( $tokens as $token ) {
        $token_needles[ $token ] = function_exists( 'wp_ai_agent_expand_token' ) ? wp_ai_agent_expand_token( $token ) : array( $token );
    }

    // Field weights (Step 4): title / attributes / category are strongest.
    $weights = apply_filters( 'wp_ai_agent_image_match_weights', array(
        'name' => 30, 'category' => 20, 'attribute' => 25, 'tag' => 15, 'brand' => 15, 'sku' => 20, 'desc' => 10,
    ) );

    // Primary product TYPE (first non-generic keyword) — a strong ranking signal,
    // NOT a hard filter.
    $generic    = function_exists( 'wp_ai_agent_generic_terms' ) ? wp_ai_agent_generic_terms() : array();
    $type_token = '';
    foreach ( $tokens as $t ) {
        if ( ! in_array( $t, $generic, true ) ) {
            $type_token = $t;
            break;
        }
    }
    if ( '' === $type_token ) {
        $type_token = $tokens[0];
    }
    $type_needles = isset( $token_needles[ $type_token ] ) ? $token_needles[ $type_token ] : array( $type_token );
    // Expand the type with synonyms (shirt ↔ tee ↔ top, shoes ↔ footwear …) so a
    // legit product is still matched, while unrelated types stay out.
    foreach ( wp_ai_agent_image_type_synonyms( $type_token ) as $syn ) {
        $type_needles[] = $syn;
    }
    $type_needles = array_values( array_unique( array_filter( $type_needles ) ) );
    $type_bonus   = (int) apply_filters( 'wp_ai_agent_image_type_bonus', 40 );

    // Semantic / embedding layer (Step 3): product URL -> relevance.
    $sem = array();
    if ( function_exists( 'wp_ai_agent_semantic_enabled' ) && wp_ai_agent_semantic_enabled() && function_exists( 'wp_ai_agent_semantic_search' ) ) {
        foreach ( (array) wp_ai_agent_semantic_search( $keywords, 30 ) as $row ) {
            if ( isset( $row['type'], $row['url'] ) && 'product' === $row['type'] && '' !== $row['url'] ) {
                $sem[ $row['url'] ] = (float) ( isset( $row['relevance'] ) ? $row['relevance'] : 0 );
            }
        }
    }

    $products = wc_get_products( array(
        'status' => 'publish',
        'limit'  => (int) apply_filters( 'wp_ai_agent_image_search_limit', 300 ),
    ) );
    if ( empty( $products ) ) {
        return array( 'products' => array(), 'confident' => false, 'debug' => 'no published products' );
    }

    $scored   = array(); // Same-type matches (preferred).
    $fallback = array(); // Overlap without a type match (closest / relaxed).
    foreach ( $products as $product ) {
        $fields        = wp_ai_agent_product_field_text( $product );
        $fields['sku'] = (string) $product->get_sku();

        $hay = array();
        foreach ( $fields as $field => $text ) {
            $hay[ $field ] = ' ' . strtolower( $text ) . ' ';
        }

        // Weighted keyword scoring across ALL fields. Also track whether any
        // DISTINCTIVE (non-generic) keyword matched — a shared colour/gender
        // alone (e.g. socks that are also "black"/"mens" like a shoe photo) is
        // NOT a meaningful visual match.
        $score          = 0;
        $matched        = 0;
        $nongeneric_hit = false;
        foreach ( $token_needles as $token => $needles ) {
            $hit_any = false;
            foreach ( $weights as $field => $weight ) {
                if ( ! isset( $hay[ $field ] ) ) {
                    continue;
                }
                foreach ( $needles as $needle ) {
                    if ( wp_ai_agent_term_match( $hay[ $field ], $needle ) ) {
                        $score += $weight;
                        $hit_any = true;
                        break;
                    }
                }
            }
            if ( $hit_any ) {
                $matched++;
                if ( ! in_array( $token, $generic, true ) ) {
                    $nongeneric_hit = true;
                }
            }
        }

        // Semantic / embedding boost (applies to both the preferred and the
        // relaxed pass).
        $url       = get_permalink( $product->get_id() );
        $sem_boost = ( isset( $sem[ $url ] ) && $sem[ $url ] > 0 ) ? (int) round( $sem[ $url ] * 30 ) : 0;

        // Same product TYPE (matched in name / category / tag / attribute)?
        $type_field = $hay['name'] . $hay['category'] . $hay['tag'] . $hay['attribute'];
        $type_hit   = false;
        foreach ( $type_needles as $needle ) {
            if ( wp_ai_agent_term_match( $type_field, $needle ) ) {
                $type_hit = true;
                break;
            }
        }

        $total = $score + $sem_boost + ( $type_hit ? $type_bonus : 0 );
        if ( $total <= 0 ) {
            continue;
        }

        $row = array(
            'product'  => $product,
            'score'    => $total,
            'type_hit' => $type_hit,
            'matched'  => $matched,
            'sales'    => (int) $product->get_total_sales(),
        );  

        if ( $type_hit ) {
            $scored[] = $row; // same product TYPE — the only results we trust for an image.
        }
        // Products that merely share a keyword/colour but are a DIFFERENT type
        // (e.g. socks sharing "black"/"mens" with a shoe or ring photo) are
        // intentionally NOT collected — image results must be the same type.
    }

    // Image search returns ONLY same-type matches. We never fall back to loose
    // keyword/colour overlap, so a ring photo can never return socks. If the
    // store carries no product of the detected type, we say so honestly.
    $use     = $scored;
    $relaxed = false;

    if ( empty( $use ) ) {
        return array( 'products' => array(), 'confident' => false, 'debug' => 'keywords=[' . implode( ',', $tokens ) . '] no same-type match among ' . count( $products ) . ' products' );
    }

    usort( $use, function ( $a, $b ) {
        if ( $a['score'] !== $b['score'] ) {
            return ( $a['score'] < $b['score'] ) ? 1 : -1;
        }
        if ( $a['type_hit'] !== $b['type_hit'] ) {
            return $a['type_hit'] ? -1 : 1;
        }
        if ( $a['sales'] !== $b['sales'] ) {
            return ( $a['sales'] < $b['sales'] ) ? 1 : -1;
        }
        return strcasecmp( $a['product']->get_name(), $b['product']->get_name() );
    } );

    $top = array_slice( $use, 0, (int) $limit );

    // "Confident" (exact-ish) only for a same-type match with 2+ attribute hits;
    // relaxed matches are always framed as "closest matching".
    $confident = ( ! $relaxed && $top[0]['type_hit'] && $top[0]['matched'] >= 2 );

    $debug = sprintf(
        'keywords=[%s]; type=%s; type_matches=%d; relaxed=%s; top_score=%d; matched_attrs=%d; semantic=%d',
        implode( ',', $tokens ),
        $type_token,
        count( $scored ),
        $relaxed ? 'yes' : 'no',
        $top[0]['score'],
        $top[0]['matched'],
        count( $sem )
    );

    return array(
        'products'  => array_map( function ( $r ) {
            return $r['product'];
        }, $top ),
        'confident' => (bool) $confident,
        'debug'     => $debug,
    );
}

/**
 * 
 * Other published products that share a category with the given product — used
 * to round out image-search results with genuinely "similar" items.
 * 
 * @param WC_Product $product Reference (best-matched) product.
 * @param int        $limit   How many similar products to return.
 * @param int[]      $exclude Product IDs already shown.
 * @return WC_Product[]
 * 
 */
function wp_ai_agent_similar_by_category( $product, $limit, $exclude = array() ) {
    if ( $limit < 1 || ! function_exists( 'wc_get_products' ) ) {
        return array();
    }

    $slugs = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );
    if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
        return array();
    }

    $exclude   = array_map( 'intval', (array) $exclude );
    $exclude[] = (int) $product->get_id();

    $products = wc_get_products( array(
        'status'   => 'publish',
        'limit'    => (int) $limit,
        'category' => $slugs,
        'exclude'  => array_values( array_unique( $exclude ) ),
        'orderby'  => 'popularity',
    ) );

    return is_array( $products ) ? $products : array();
}

/**
 * Render matched WooCommerce products in the required response format:
 * Product Name / Price / Short Description / Product URL.
 *
 * @param WC_Product[] $products Ranked products.
 * @return string
 */
function wp_ai_agent_format_image_results( $products ) {
    $lines = array();
    $lines[] = __( 'Here are the most similar products from our store:', 'wp-ai-agent' );
    $lines[] = '';

    $n = 0;
    foreach ( $products as $product ) {
        $n++;

        // Price (handles variable products via the price range when present).
        $price = '';
        if ( '' !== (string) $product->get_price() ) {
            $price = wp_strip_all_tags( wc_price( $product->get_price() ) );
        }
        if ( '' === $price ) {
            $price = wp_strip_all_tags( $product->get_price_html() );
        }
        $price = html_entity_decode( $price, ENT_QUOTES );

        // Short description (fall back to the long description).
        $desc = $product->get_short_description();
        if ( '' === trim( (string) $desc ) ) {
            $desc = $product->get_description();
        }
        $desc = wp_trim_words( wp_strip_all_tags( $desc ), 30, '…' );

        $lines[] = sprintf( '%d. %s', $n, $product->get_name() );
        $lines[] = sprintf( /* translators: %s: product price. */ __( 'Price: %s', 'wp-ai-agent' ), ( '' !== $price ) ? $price : __( 'N/A', 'wp-ai-agent' ) );
        if ( '' !== $desc ) {
            $lines[] = $desc;
        }
        $lines[] = get_permalink( $product->get_id() );
        $lines[] = '';
    }

    return trim( implode( "\n", $lines ) );
}

/* -------------------------------------------------------------------------
 * REST handler.
 * ---------------------------------------------------------------------- */

/**
 * REST handler: visual product search from an uploaded image. Restricted to
 * WooCommerce products only.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
*/

function wp_ai_agent_handle_image_search_request( $request ) {
    $t0         = microtime( true );
    $params     = $request->get_json_params();
    $image      = isset( $params['image'] ) ? (string) $params['image'] : '';
    $session_id = isset( $params['session_id'] ) ? sanitize_text_field( $params['session_id'] ) : '';
    $page_url   = isset( $params['page_url'] ) ? esc_url_raw( $params['page_url'] ) : '';
    // Optional note the visitor typed alongside the image ("in red color",
    // "similar shoes"). It refines the visual search.
    $text       = isset( $params['message'] ) ? sanitize_text_field( $params['message'] ) : '';

    $elapsed = function () use ( $t0 ) {
        return (int) round( ( microtime( true ) - $t0 ) * 1000 );
    };

    if ( ! preg_match( '#^data:(image/[a-z0-9.+-]+);base64,(.+)$#i', $image, $mm ) ) {
         return new WP_REST_Response( array( 'message' => __( 'Please upload a valid image.', 'wp-ai-agent' ) ), 200 );
    }
    $mime   = strtolower( $mm[1] );
    $base64 = $mm[2];

    if ( strlen( $base64 ) > (int) apply_filters( 'wp_ai_agent_image_max_b64', 8000000 ) ) {
        return new WP_REST_Response( array( 'message' => __( 'Image is too large. Please upload a smaller image.', 'wp-ai-agent' ) ), 200 );
    }

    // Image search is WooCommerce-only by design.
    if ( ! function_exists( 'wc_get_products' ) ) {
        $msg = wp_ai_agent_image_no_match_message();
        wp_ai_agent_log_conversation( $session_id, $page_url, '[image search]', $msg );
        return new WP_REST_Response( array( 'message' => $msg, 'matched' => false ), 200 );
    }

    if ( '' === wp_ai_agent_vision_provider() ) {
        return new WP_REST_Response( array( 'message' => __( 'Image search needs an OpenAI, Gemini, or Groq API key set in the plugin settings.', 'wp-ai-agent' ) ), 200 );
    }

    // 1) Analyze the image -> keywords / attributes.
    $keywords = wp_ai_agent_describe_image( $base64, $mime );

    // Combine the vision keywords with the visitor's note so BOTH steer the
    // WooCommerce search (e.g. photo of a t-shirt + "in red" → red t-shirts).
    $search_terms = trim( $keywords . ' ' . $text );

    // Only a true failure — vision could not read the image AND no note was
    // typed — falls back to the "couldn't read that image" message.
    if ( '' === $search_terms ) {
        $detail   = wp_ai_agent_vision_diag();
        $is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
        $msg      = __( "I couldn't read that image. Please try another one.", 'wp-ai-agent' );

        // Show the real reason to a logged-in admin (or in WP_DEBUG) right in the
        // chat bubble, so a key/model/network problem can be fixed in one look
        // instead of digging through logs.
        if ( '' !== $detail && ( $is_admin || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) ) {
            /* translators: %s: technical error detail. */
            $msg .= "\n\n" . sprintf( __( '[Admin only — image AI error: %s]', 'wp-ai-agent' ), $detail );
        }

        $response = array( 'message' => $msg, 'matched' => false );
        if ( '' !== $detail ) {
            $response['debug'] = $detail;
        }

        wp_ai_agent_log_conversation( $session_id, $page_url, '[image search]', $msg, $elapsed() );
        return new WP_REST_Response( $response, 200 );
    }

    // From here on, search using the combined terms.
    $keywords = $search_terms;

    // 2) Rank against the FULL WooCommerce catalog (all fields + semantic).
    $limit    = (int) apply_filters( 'wp_ai_agent_image_result_count', 5 );
    $match    = wp_ai_agent_image_match_products( $keywords, $limit );
    $products = $match['products'];
    $is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    $is_debug = $is_admin || ( defined( 'WP_DEBUG' ) && WP_DEBUG );

    // Step 10: log every step (detected keywords + ranking outcome).
    error_log( '[wp-ai-agent] image search: ' . $match['debug'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
                                        
    // Only "no products" after the full pipeline finds zero overlap.
    if ( empty( $products ) ) {
        $msg = wp_ai_agent_image_no_match_message();
        if ( $is_debug ) {
            $msg .= "\n\n" . sprintf( __( '[Admin — detected: %s]', 'wp-ai-agent' ), $keywords );
        }
        wp_ai_agent_log_conversation( $session_id, $page_url, '[image: ' . $keywords . ']', $msg, $elapsed() );
        $resp = array( 'message' => $msg, 'keywords' => $keywords, 'matched' => false );
        if ( $is_debug ) {
            $resp['debug'] = $match['debug'];
        }
        return new WP_REST_Response( $resp, 200 );
    }

    // 3) Rich product cards (Step 9: image, name, price, short desc, category,
    //    View + Add to Cart).
    $cards = array();
    foreach ( $products as $product ) {
        $cards[] = function_exists( 'wp_ai_agent_product_card' )
            ? wp_ai_agent_product_card( $product )
            : array( 'name' => $product->get_name(), 'url' => get_permalink( $product->get_id() ) );
    }   
    
    // Step 8: high confidence → "matching"; otherwise → "closest matching".
    $intro = $match['confident']
        ? __( 'I found these matching products for your image:', 'wp-ai-agent' )
        : __( 'These are the closest matching products I could find:', 'wp-ai-agent' );
    
    wp_ai_agent_log_conversation( $session_id, $page_url, '[image: ' . $keywords . ']', $intro, $elapsed() );

    $resp = array(
        'message'  => $intro,
        'source'   => 'image',
        'keywords' => $keywords,
        'matched'  => true,
        'count'    => count( $cards ),
        'data'     => array( 'products' => $cards ),
    );
    if ( $is_debug ) {
        $resp['debug'] = $match['debug'];
    }
    return new WP_REST_Response( $resp, 200 );
}



