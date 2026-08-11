<?php
/**
 * Universal content indexer.
 *
 * Collects content from every available WordPress / WooCommerce / page-builder
 * source into a single database table (wp_ai_content_index) that powers the
 * chatbot's "answer only from website content" search.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Bumped whenever the schema OR the set of indexed sources changes.
 *  v4: deep Elementor extraction (accordion / toggle / tabs / FAQ repeaters).
 *  v5: key-agnostic Elementor extraction (any widget/addon text, any key). */
if ( ! defined( 'WP_AI_AGENT_INDEX_DB_VERSION' ) ) {
    define( 'WP_AI_AGENT_INDEX_DB_VERSION', '5' );
}

/**
 * Fully-qualified name of the centralized content index table.
 *
 * @return string
 */
function wp_ai_agent_index_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'ai_content_index';
}

/**
 * Create (or update) the centralized content index table.
 */
function wp_ai_agent_create_index_table() {
    global $wpdb;

    $table           = wp_ai_agent_index_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        content_type varchar(50) NOT NULL DEFAULT '',
        post_id bigint(20) unsigned NOT NULL DEFAULT 0,
        title text NOT NULL,
        content longtext NOT NULL,
        source varchar(50) NOT NULL DEFAULT '',
        url varchar(255) NOT NULL DEFAULT '',
        embedding longtext NULL,
        last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY content_type (content_type),
        KEY post_id (post_id),
        KEY source (source)
    ) {$charset_collate};";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'wp_ai_agent_index_db_version', WP_AI_AGENT_INDEX_DB_VERSION );
}

/**
 * Run the table migration + initial build once per schema version, in admin.
 */
add_action( 'admin_init', 'wp_ai_agent_maybe_upgrade_index' );
function wp_ai_agent_maybe_upgrade_index() {
    if ( get_option( 'wp_ai_agent_index_db_version' ) === WP_AI_AGENT_INDEX_DB_VERSION ) {
        return;
    }
    wp_ai_agent_create_index_table();
    wp_ai_agent_rebuild_index();
}

/**
 * Whether the index table exists and currently holds rows.
 *
 * @return bool
 */
function wp_ai_agent_index_table_ready() {
    global $wpdb;
    $table = wp_ai_agent_index_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        return false;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0;
}

/* -------------------------------------------------------------------------
 * Collection — gather content from every source into normalized items.
 * Each item: array( content_type, post_id, title, content, source, url ).
 * ---------------------------------------------------------------------- */

/**
 * Collect content from all available sources.
 *
 * @return array[] Normalized content items.
 */
function wp_ai_agent_collect_all_content() {
    $items = array();

    $items = array_merge( $items, wp_ai_agent_collect_posts() );
    $items = array_merge( $items, wp_ai_agent_collect_terms() );
    $items = array_merge( $items, wp_ai_agent_collect_authors() );
    $items = array_merge( $items, wp_ai_agent_collect_menus() );
    $items = array_merge( $items, wp_ai_agent_collect_woocommerce() );
    $items = array_merge( $items, wp_ai_agent_collect_theme_and_widgets() );
    $items = array_merge( $items, wp_ai_agent_collect_attachments() );

    /**
     * Append items from future / external sources (CRM, bookings, support
     * tickets, custom knowledge bases, parsed PDF/DOCX text, etc.).
     *
     * @param array[] $extra Items to add (same shape as core items).
     */
    $extra = apply_filters( 'wp_ai_agent_extra_index_items', array() );
    if ( is_array( $extra ) && ! empty( $extra ) ) {
        $items = array_merge( $items, $extra );
    }

    // Normalize + drop empties.
    $clean = array();
    foreach ( $items as $item ) {
        $title   = isset( $item['title'] ) ? trim( wp_strip_all_tags( (string) $item['title'] ) ) : '';
        $content = isset( $item['content'] ) ? trim( wp_strip_all_tags( (string) $item['content'] ) ) : '';
        if ( '' === $title && '' === $content ) {
            continue;
        }
        $clean[] = array(
            'content_type' => isset( $item['content_type'] ) ? substr( (string) $item['content_type'], 0, 50 ) : 'other',
            'post_id'      => isset( $item['post_id'] ) ? (int) $item['post_id'] : 0,
            'title'        => $title,
            'content'      => $content,
            'source'       => isset( $item['source'] ) ? substr( (string) $item['source'], 0, 50 ) : 'wordpress',
            'url'          => isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '',
        );
    }

    return $clean;
}

/**
 * Posts, pages, and every public custom post type. Includes ACF fields and
 * Elementor builder text when those plugins are present.
 *
 * @return array[]
 */
function wp_ai_agent_collect_posts() {
    $items = array();

    $builtin = array( 'post', 'page' );
    $custom  = get_post_types( array( 'public' => true, '_builtin' => false ), 'names' );
    $types   = array_unique( array_merge( $builtin, array_values( $custom ) ) );

    // Elementor templates AND popups live in the (non-public) elementor_library
    // post type, so include it explicitly when Elementor is active.
    if ( post_type_exists( 'elementor_library' ) && ! in_array( 'elementor_library', $types, true ) ) {
        $types[] = 'elementor_library';
    }

    /** Allow adding/removing indexable post types. */
    $types = apply_filters( 'wp_ai_agent_indexable_post_types', $types );

    $per_type = (int) apply_filters( 'wp_ai_agent_index_posts_per_type', -1 );

    foreach ( $types as $post_type ) {
        $posts = get_posts( array(
            'post_type'        => $post_type,
            'posts_per_page'   => $per_type,
            'post_status'      => 'publish',
            'suppress_filters' => true,
        ) );

        foreach ( $posts as $post ) {
            $content = $post->post_content;

            // Excerpt adds useful summary text.
            if ( ! empty( $post->post_excerpt ) ) {
                $content .= "\n" . $post->post_excerpt;
            }

            // ACF fields (if ACF is active).
            $content .= wp_ai_agent_extract_acf_text( $post->ID );

            // Elementor builder text (if present).
            $content .= wp_ai_agent_extract_elementor_text( $post->ID );

            $items[] = array(
                'content_type' => in_array( $post_type, $builtin, true ) ? $post_type : 'custom_post_type',
                'post_id'      => $post->ID,
                'title'        => get_the_title( $post ),
                'content'      => $content,
                'source'       => ( 'elementor_library' === $post_type ) ? 'elementor' : $post_type,
                'url'          => get_permalink( $post ),
            );
        }
    }

    return $items;
}

/**
 * Flatten ACF field values for a post into searchable text.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function wp_ai_agent_extract_acf_text( $post_id ) {
    if ( ! function_exists( 'get_fields' ) ) {
        return '';
    }

    $fields = get_fields( $post_id );
    if ( empty( $fields ) || ! is_array( $fields ) ) {
        return '';
    }

    return "\n" . wp_ai_agent_flatten_scalars( $fields );
}

/**
 * Recursively reduce an array/scalar structure to a space-separated string of
 * its scalar (string/number) values.
 *
 * @param mixed $value Value to flatten.
 * @return string
 */
function wp_ai_agent_flatten_scalars( $value ) {
    if ( is_scalar( $value ) ) {
        return ' ' . (string) $value;
    }
    if ( is_array( $value ) ) {
        $out = '';
        foreach ( $value as $sub ) {
            $out .= wp_ai_agent_flatten_scalars( $sub );
        }
        return $out;
    }
    return '';
}

/**
 * Extract readable text from Elementor's stored builder data for a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function wp_ai_agent_extract_elementor_text( $post_id ) {
    $data = get_post_meta( $post_id, '_elementor_data', true );
    if ( empty( $data ) || ! is_string( $data ) ) {
        return '';
    }

    $decoded = json_decode( $data, true );
    if ( ! is_array( $decoded ) ) {
        return '';
    }

    // Known text-bearing keys (kept regardless of length/spaces) — covers core
    // Elementor AND popular addons (Essential/Premium/JetElements accordion,
    // toggle, FAQ, icon-box, tabs …). Field names differ per addon, so this list
    // is only a hint — the heuristic below is what makes extraction universal.
    $text_keys = array(
        'title', 'text', 'editor', 'description', 'html', 'heading', 'content', 'caption', 'excerpt',
        'tab_title', 'tab_content', 'item_title', 'item_content', 'question', 'answer',
        'title_text', 'description_text', 'testimonial_content', 'list_title', 'list_content',
        'inner_content', 'accordion_title', 'accordion_content', 'faq_title', 'faq_content',
        'toggle_title', 'toggle_content', 'box_title', 'box_description', 'sub_title',
        'before_text', 'after_text', 'ea_toggle_title', 'ea_toggle_content',
    );

    // Heuristic: is this string human-readable CONTENT (not a CSS value, URL,
    // colour, id or enum like "flex-start"/"yes")? Content has spaces or HTML, or
    // is a longer phrase. This makes extraction KEY-AGNOSTIC, so any widget's or
    // addon's text is captured no matter what its setting key is called.
    $is_text = function ( $s ) {
        $s = trim( (string) $s );
        if ( strlen( $s ) < 3 ) {
            return false;
        }
        if ( preg_match( '#^https?://#i', $s ) || false !== strpos( $s, '://' ) ) {
            return false; // URLs.
        }
        if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $s ) ) {
            return false; // hex colours.
        }
        return ( false !== strpos( $s, ' ' ) || false !== strpos( $s, '<' ) || strlen( $s ) > 24 );
    };

    // Deep scan: recurse into every nested array (containers, elements, settings,
    // repeaters) and collect every value that is either under a known text key OR
    // looks like real content.
    $collected = '';
    $deep      = function ( $value ) use ( &$deep, $text_keys, $is_text, &$collected ) {
        if ( ! is_array( $value ) ) {
            return;
        }
        foreach ( $value as $key => $val ) {
            if ( is_string( $val ) ) {
                if ( in_array( $key, $text_keys, true ) || $is_text( $val ) ) {
                    $collected .= ' ' . $val;
                }
            } elseif ( is_array( $val ) ) {
                $deep( $val );
            }
        }
    };
    $deep( $decoded );

    $collected = trim( $collected );

    return ( '' !== $collected ) ? "\n" . wp_strip_all_tags( $collected ) : '';
}

/**
 * Categories, tags, and all other public taxonomy terms (including custom
 * taxonomies and WooCommerce product attributes).
 *
 * @return array[]
 */
function wp_ai_agent_collect_terms() {
    $items = array();

    $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );

    foreach ( $taxonomies as $taxonomy ) {
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            continue;
        }

        foreach ( $terms as $term ) {
            if ( 'category' === $taxonomy ) {
                $type = 'category';
            } elseif ( 'post_tag' === $taxonomy ) {
                $type = 'tag';
            } else {
                $type = 'taxonomy';
            }

            $link = get_term_link( $term );

            $items[] = array(
                'content_type' => $type,
                'post_id'      => 0,
                'title'        => $term->name,
                'content'      => $term->description,
                'source'       => $taxonomy,
                'url'          => is_wp_error( $link ) ? '' : $link,
            );
        }
    }

    return $items;
}

/**
 * Authors who have published content, with their bio.
 *
 * @return array[]
 */
function wp_ai_agent_collect_authors() {
    $items = array();

    $authors = get_users( array(
        'who'                 => 'authors',
        'has_published_posts' => true,
        'fields'              => array( 'ID', 'display_name' ),
    ) );

    foreach ( $authors as $author ) {
        $bio = get_the_author_meta( 'description', $author->ID );
        $items[] = array(
            'content_type' => 'author',
            'post_id'      => 0,
            'title'        => $author->display_name,
            'content'      => $bio,
            'source'       => 'author',
            'url'          => get_author_posts_url( $author->ID ),
        );
    }

    return $items;
}

/**
 * Navigation menu items (labels + their target URLs).
 *
 * @return array[]
 */
function wp_ai_agent_collect_menus() {
    $items = array();

    $menus = wp_get_nav_menus();
    if ( empty( $menus ) || is_wp_error( $menus ) ) {
        return $items;
    }

    foreach ( $menus as $menu ) {
        $menu_items = wp_get_nav_menu_items( $menu->term_id );
        if ( empty( $menu_items ) ) {
            continue;
        }
        $labels = array();
        foreach ( $menu_items as $menu_item ) {
            $labels[] = $menu_item->title;
        }
        $items[] = array(
            'content_type' => 'menu',
            'post_id'      => 0,
            'title'        => $menu->name,
            'content'      => implode( ', ', array_filter( $labels ) ),
            'source'       => 'menu',
            'url'          => '',
        );
    }

    return $items;
}

/**
 * Theme options (Customizer theme mods) and widget content.
 *
 * @return array[]
 */
function wp_ai_agent_collect_theme_and_widgets() {
    $items = array();

    // Theme options (Customizer "theme mods").
    $mods = get_theme_mods();
    if ( is_array( $mods ) && ! empty( $mods ) ) {
        $text = trim( wp_ai_agent_flatten_scalars( $mods ) );
        if ( '' !== $text ) {
            $items[] = array(
                'content_type' => 'theme_option',
                'post_id'      => 0,
                'title'        => __( 'Theme Options', 'wp-ai-agent' ),
                'content'      => $text,
                'source'       => 'theme',
                'url'          => '',
            );
        }
    }

    // Site identity options not always stored as theme mods.
    $blog_bits = array( get_bloginfo( 'name' ), get_bloginfo( 'description' ) );
    $items[]   = array(
        'content_type' => 'theme_option',
        'post_id'      => 0,
        'title'        => __( 'Site Identity', 'wp-ai-agent' ),
        'content'      => implode( ' - ', array_filter( $blog_bits ) ),
        'source'       => 'theme',
        'url'          => '',
    );

    // Widgets: scan every option named widget_* for text-bearing instances.
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $widget_option_names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'widget_%'" );

    $text_keys = array( 'text', 'content', 'title' );
    foreach ( (array) $widget_option_names as $option_name ) {
        $instances = get_option( $option_name );
        if ( ! is_array( $instances ) ) {
            continue;
        }
        foreach ( $instances as $instance ) {
            if ( ! is_array( $instance ) ) {
                continue;
            }
            $parts = array();
            foreach ( $text_keys as $key ) {
                if ( ! empty( $instance[ $key ] ) && is_string( $instance[ $key ] ) ) {
                    $parts[] = $instance[ $key ];
                }
            }
            if ( empty( $parts ) ) {
                continue;
            }
            $items[] = array(
                'content_type' => 'widget',
                'post_id'      => 0,
                'title'        => sprintf( '%s widget', str_replace( 'widget_', '', $option_name ) ),
                'content'      => implode( "\n", $parts ),
                'source'       => 'widget',
                'url'          => '',
            );
        }
    }

    return $items;
}

/**
 * WooCommerce products, reviews, coupons, and shipping zones/methods.
 *
 * @return array[]
 */
function wp_ai_agent_collect_woocommerce() {
    $items = array();

    if ( ! function_exists( 'wc_get_products' ) ) {
        return $items;
    }

    // Products (descriptions, SKU, price).
    $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) );
    foreach ( $products as $product ) {
        $parts = array(
            $product->get_description(),
            $product->get_short_description(),
            'SKU: ' . $product->get_sku(),
            'Price: ' . wp_strip_all_tags( $product->get_price_html() ),
        );

        $items[] = array(
            'content_type' => 'product',
            'post_id'      => $product->get_id(),
            'title'        => $product->get_name(),
            'content'      => implode( "\n", array_filter( $parts ) ),
            'source'       => 'woocommerce',
            'url'          => get_permalink( $product->get_id() ),
        );

        // Product reviews.
        $reviews = get_comments( array(
            'post_id' => $product->get_id(),
            'status'  => 'approve',
            'type'    => 'review',
            'number'  => 20,
        ) );
        foreach ( $reviews as $review ) {
            $items[] = array(
                'content_type' => 'product_review',
                'post_id'      => $product->get_id(),
                'title'        => sprintf( 'Review: %s', $product->get_name() ),
                'content'      => $review->comment_content,
                'source'       => 'woocommerce',
                'url'          => get_permalink( $product->get_id() ),
            );
        }
    }

    // Coupons.
    $coupons = get_posts( array(
        'post_type'      => 'shop_coupon',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ) );
    $shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
    foreach ( $coupons as $coupon ) {
        $code  = $coupon->post_title;
        $extra = '';
        if ( class_exists( 'WC_Coupon' ) ) {
            $c     = new WC_Coupon( $coupon->ID );
            $extra = sprintf( 'Code: %s. Discount: %s (%s).', $code, $c->get_amount(), $c->get_discount_type() );
        }
        $items[] = array(
            'content_type' => 'coupon',
            'post_id'      => $coupon->ID,
            // Include synonym words so "offer/discount/deal/sale" queries match.
            'title'        => sprintf( 'Coupon / Offer: %s', $code ),
            'content'      => trim( 'Offer discount deal sale promo coupon voucher code. ' . $extra . ' ' . $coupon->post_excerpt . ' ' . $coupon->post_content ),
            'source'       => 'woocommerce',
            'url'          => $shop_url,
        );
    }

    // Shipping zones + methods.
    if ( class_exists( 'WC_Shipping_Zones' ) ) {
        $zones = WC_Shipping_Zones::get_zones();
        foreach ( $zones as $zone ) {
            $methods = array();
            if ( ! empty( $zone['shipping_methods'] ) ) {
                foreach ( $zone['shipping_methods'] as $method ) {
                    $methods[] = $method->get_title();
                }
            }
            $items[] = array(
                'content_type' => 'shipping',
                'post_id'      => 0,
                'title'        => sprintf( 'Shipping: %s', $zone['zone_name'] ),
                'content'      => sprintf( 'Regions: %s. Methods: %s', $zone['formatted_zone_location'], implode( ', ', $methods ) ),
                'source'       => 'woocommerce',
                'url'          => '',
            );
        }
    }

    return $items;
}

/**
 * Uploaded media. Indexes title/caption/description/alt for every attachment
 * and the body text of supported documents (TXT, DOCX, best-effort PDF).
 *
 * @return array[]
 */
function wp_ai_agent_collect_attachments() {
    $items = array();

    if ( ! (bool) apply_filters( 'wp_ai_agent_index_attachments', true ) ) {
        return $items;
    }

    $attachments = get_posts( array(
        'post_type'      => 'attachment',
        'posts_per_page' => (int) apply_filters( 'wp_ai_agent_index_attachments_limit', 200 ),
        'post_status'    => 'inherit',
    ) );

    foreach ( $attachments as $attachment ) {
        $mime = get_post_mime_type( $attachment->ID );

        // Skip images: the chatbot should surface page/post content and their
        // URLs, never raw image file URLs. Only documents/text carry useful
        // answer content. (Filterable in case images are wanted later.)
        $is_image = ( 0 === strpos( (string) $mime, 'image/' ) );
        if ( $is_image && ! (bool) apply_filters( 'wp_ai_agent_index_image_attachments', false ) ) {
            continue;
        }

        $file = get_attached_file( $attachment->ID );

        $parts = array(
            $attachment->post_content,             // description
            $attachment->post_excerpt,             // caption
        );

        // Extract body text from supported uploaded documents.
        $parts[] = wp_ai_agent_extract_document_text( $file, $mime );

        /**
         * Let integrations supply extracted text for file types not handled
         * above (e.g. scanned/OCR PDFs, XLSX, PPTX).
         *
         * @param string $text       Extracted text (default empty).
         * @param int    $attachment Attachment ID.
         * @param string $mime       MIME type.
         * @param string $file       Absolute file path.
         */
        $extra = apply_filters( 'wp_ai_agent_attachment_text', '', $attachment->ID, $mime, $file );
        if ( is_string( $extra ) && '' !== $extra ) {
            $parts[] = $extra;
        }

        $items[] = array(
            'content_type' => 'attachment',
            'post_id'      => $attachment->ID,
            'title'        => get_the_title( $attachment ),
            'content'      => implode( "\n", array_filter( $parts ) ),
            'source'       => 'media',
            'url'          => wp_get_attachment_url( $attachment->ID ),
        );
    }

    return $items;
}

/**
 * Extract readable body text from an uploaded document.
 *
 * Handles plain text (.txt), Word (.docx via the bundled ZipArchive), and a
 * best-effort plain-text extraction for PDFs. Returns '' for unsupported or
 * unreadable files. Capped at ~50k characters per document.
 *              
 * @param string $file Absolute file path.
 * @param string $mime MIME type.
 * @return string
 */
function wp_ai_agent_extract_document_text( $file, $mime ) {
    if ( empty( $file ) || ! file_exists( $file ) ) {
        return '';
    }

    $max_bytes = (int) apply_filters( 'wp_ai_agent_document_max_bytes', 5000000 ); // ~5 MB.
    if ( filesize( $file ) > $max_bytes ) {
        return '';
    }

    $text = '';
    $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

    if ( 'text/plain' === $mime || 'txt' === $ext ) {
        $text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    } elseif ( 'docx' === $ext || false !== strpos( (string) $mime, 'wordprocessingml' ) ) {
        $text = wp_ai_agent_extract_docx_text( $file );
    } elseif ( 'pdf' === $ext || 'application/pdf' === $mime ) {
        $text = wp_ai_agent_extract_pdf_text( $file );
    }

    $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );

    return ( strlen( $text ) > 50000 ) ? substr( $text, 0, 50000 ) : $text;
}

/**
 * Extract text from a .docx file using the bundled ZipArchive extension.
 *
 * @param string $file Absolute path.
 * @return string
*/

function wp_ai_agent_extract_docx_text( $file ) {
    if ( ! class_exists( 'ZipArchive' ) ) {
        return '';
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open( $file ) ) {
        return '';
    }

    $xml = $zip->getFromName( 'word/document.xml' );
    $zip->close();

    if ( false === $xml || '' === $xml ) {
        return '';
    }

    // Turn paragraph/line breaks into spaces, then strip the remaining tags.
    $xml = str_replace( array( '</w:p>', '<w:br/>', '<w:tab/>' ), ' ', $xml );

    return wp_strip_all_tags( $xml );
}


/**
 * Best-effort plain-text extraction from a PDF.
 *
 * @param string $file Absolute path.
 * @return string
 */

function wp_ai_agent_extract_pdf_text( $file ) {
    $data = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    if ( '' === $data ) {
        return '';
    }

    $out = '';  

    // Concatenate decoded content streams (FlateDecode) plus raw streams.
    if ( preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $data, $streams ) ) {
        foreach ( $streams[1] as $stream ) {
            $decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $chunk   = ( false !== $decoded ) ? $decoded : $stream;

            // Text inside ( ) before a Tj/TJ show operator.
            if ( preg_match_all( '/\((?:[^()\\\\]|\\\\.)*\)/s', $chunk, $matches ) ) {
                foreach ( $matches[0] as $token ) {
                    $token = substr( $token, 1, -1 );
                    $token = str_replace( array( '\\(', '\\)', '\\\\' ), array( '(', ')', '\\' ), $token );
                    $out  .= $token . ' ';
                }
            }
        }
    }

    return $out;
}

/* -------------------------------------------------------------------------
 * Persistence — write the collected content into the index table.
 * ---------------------------------------------------------------------- */

/**
 * Rebuild the entire content index table from scratch.
 *
 * @return int Number of rows written.
 */

function wp_ai_agent_rebuild_index() {
    global $wpdb;

    $table = wp_ai_agent_index_table_name();

    // Ensure the table exists before writing.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        wp_ai_agent_create_index_table();
    }

    $items = wp_ai_agent_collect_all_content();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "TRUNCATE TABLE {$table}" );

    $now   = current_time( 'mysql' );
    $count = 0;
    foreach ( $items as $item ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            array(
                'content_type' => $item['content_type'],
                'post_id'      => $item['post_id'],
                'title'        => $item['title'],
                'content'      => $item['content'],
                'source'       => $item['source'],
                'url'          => $item['url'],
                'last_updated' => $now,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
        $count++;
    }

    // Keep the legacy transient in sync as a fallback search source, and clear
    // the dynamic quick-action suggestions so they reflect fresh content.
    delete_transient( 'wp_ai_agent_content_index' );
    delete_transient( 'wp_ai_agent_quick_actions' );

    // Content changed enough to re-run website-type detection next time.
    if ( function_exists( 'wp_ai_agent_clear_website_profile' ) ) {
        wp_ai_agent_clear_website_profile();
    }
    // Re-discover social links + business hours + newsletter + email + phone.
    delete_transient( 'wp_ai_agent_social_links' );
    delete_transient( 'wp_ai_agent_business_hours' );
    delete_transient( 'wp_ai_agent_newsletter' );
    delete_transient( 'wp_ai_agent_business_email' );
    delete_transient( 'wp_ai_agent_phone_number' );

    // Generate semantic embeddings for the freshly indexed rows (cached by
    // content hash, so unchanged content is not re-embedded). Best-effort.
    if ( function_exists( 'wp_ai_agent_generate_index_embeddings' ) ) {
        wp_ai_agent_generate_index_embeddings();
    }

    return $count;
}

/**
 * Schedule a (debounced) index rebuild when content changes.
 */
function wp_ai_agent_schedule_reindex() {
    if ( ! wp_next_scheduled( 'wp_ai_agent_reindex_event' ) ) {
        wp_schedule_single_event( time() + 30, 'wp_ai_agent_reindex_event' );
    }
}
add_action( 'wp_ai_agent_reindex_event', 'wp_ai_agent_rebuild_index' );

/**
 * Trigger a reindex on relevant content changes (skip autosaves/revisions).
 *
 * @param int $post_id Post ID.
 */
function wp_ai_agent_reindex_on_save( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    wp_ai_agent_schedule_reindex();
}
add_action( 'save_post', 'wp_ai_agent_reindex_on_save' );
add_action( 'deleted_post', 'wp_ai_agent_schedule_reindex' );
add_action( 'edited_term', 'wp_ai_agent_schedule_reindex' );
add_action( 'created_term', 'wp_ai_agent_schedule_reindex' );

/* -------------------------------------------------------------------------
 * Query — candidate retrieval used by the search/relevance layer.
 * ---------------------------------------------------------------------- */

/**
 * Map a DB index row to the item shape used by the scorer.
 *
 * @param object $row DB row.
 * @return array
 */
function wp_ai_agent_map_index_row( $row ) {
    return array(
        'type'    => $row->content_type,
        'title'   => $row->title,
        'content' => $row->content,
        'url'     => $row->url,
        'source'  => $row->source,
    );
}

/**
 * Fetch candidate items for the given query tokens.
 *
 * Prefers a LIKE prefilter against the index table; falls back to the legacy
 * in-memory (transient) index when the table is not ready.
 *
 * @param string[] $tokens Query tokens.
 * @return array[] Candidate items (type/title/content/url).
 */
function wp_ai_agent_get_candidate_items( $tokens ) {
    global $wpdb;

    if ( wp_ai_agent_index_table_ready() && ! empty( $tokens ) ) {
        $table   = wp_ai_agent_index_table_name();
        $clauses = array();
        $params  = array();
        foreach ( $tokens as $token ) {
            // Expand each token to its stem + synonyms so related content is
            // fetched even without embeddings (e.g. "offer" -> coupon/discount).
            $needles = function_exists( 'wp_ai_agent_expand_token' )
                ? wp_ai_agent_expand_token( $token )
                : array( $token );
            foreach ( $needles as $needle ) {
                $like      = '%' . $wpdb->esc_like( $needle ) . '%';
                $clauses[] = '(title LIKE %s OR content LIKE %s)';
                $params[]  = $like;
                $params[]  = $like;
            }
        }
        $where = implode( ' OR ', $clauses );
        $limit = (int) apply_filters( 'wp_ai_agent_candidate_limit', 200 );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql  = $wpdb->prepare( "SELECT content_type, title, content, url, source FROM {$table} WHERE {$where} LIMIT %d", array_merge( $params, array( $limit ) ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $sql );

        if ( ! empty( $rows ) ) {
            return array_map( 'wp_ai_agent_map_index_row', $rows );
        }
        return array();
    }

    // Fallback: legacy transient index (basic source set).
    return wp_ai_agent_index_content();
}
            
/* -------------------------------------------------------------------------
 * Categorized output for the /search-content REST endpoint.
 * ---------------------------------------------------------------------- */
            
/**
 * Build the categorized content response for a query (or the whole index when
 * the query is empty).
 *
 * @param string $query Optional search query.
 * @return array Buckets keyed by content category.
 */

function wp_ai_agent_get_categorized_content( $query = '' ) {
    $buckets = array(
        'posts'             => array(),
        'pages'             => array(),
        'products'          => array(),
        'faqs'              => array(),
        'acf_fields'        => array(),
        'elementor_content' => array(),
        'categories'        => array(),
        'tags'              => array(),
        'policies'          => array(),
        'custom_post_types' => array(),
        'menus'             => array(),
    );

    if ( '' !== trim( (string) $query ) ) {
        $tokens = wp_ai_agent_tokenize_query( $query );
        $rows   = wp_ai_agent_get_candidate_items( $tokens );
    } else {
        $rows = wp_ai_agent_get_candidate_items( array() );
        if ( wp_ai_agent_index_table_ready() ) {
            global $wpdb;
            $table = wp_ai_agent_index_table_name();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $db   = $wpdb->get_results( "SELECT content_type, title, content, url, source FROM {$table} LIMIT 500" );
            $rows = array_map( 'wp_ai_agent_map_index_row', $db );
        }
    }

    foreach ( $rows as $row ) {
        $entry = array(
            'title'   => isset( $row['title'] ) ? $row['title'] : '',
            'content' => isset( $row['content'] ) ? wp_trim_words( $row['content'], 60, '…' ) : '',
            'url'     => isset( $row['url'] ) ? $row['url'] : '',
        );

        $type  = isset( $row['type'] ) ? $row['type'] : 'other';
        $title = strtolower( $entry['title'] );

        // Policy detection by common page titles.
        if ( preg_match( '/(privacy|terms|refund|policy|conditions|shipping)/', $title ) ) {
            $buckets['policies'][] = $entry;
        }

        switch ( $type ) {
            case 'post':
                $buckets['posts'][] = $entry;
                break;
            case 'page':
                $buckets['pages'][] = $entry;
                break;
            case 'product':
            case 'product_review':
                $buckets['products'][] = $entry;
                break;
            case 'category':
                $buckets['categories'][] = $entry;
                break;
            case 'tag':
                $buckets['tags'][] = $entry;
                break;
            case 'menu':
                $buckets['menus'][] = $entry;
                break;
            case 'custom_post_type':
                if ( false !== strpos( $title, 'faq' ) ) {
                    $buckets['faqs'][] = $entry;
                }
                $buckets['custom_post_types'][] = $entry;
                break;
        }

        if ( isset( $row['source'] ) && 'elementor' === $row['source'] ) {
            $buckets['elementor_content'][] = $entry;
        }
    }

    return $buckets;
}
      









