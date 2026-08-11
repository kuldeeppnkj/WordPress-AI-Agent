<?php
/**
 * Embeddings-based semantic search.
 *
 * Generates vector embeddings for indexed content (OpenAI or Gemini), stores
 * them in the content index, and ranks content by cosine similarity to the
 * query. Falls back silently to keyword search when embeddings are unavailable.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Name of the embeddings cache table (hash -> vector) so unchanged content is
 * never re-embedded across rebuilds.
 *
 * @return string
 */
function wp_ai_agent_embeddings_cache_table() {
    global $wpdb;
    return $wpdb->prefix . 'ai_embeddings_cache';
}

/**
 * Create the embeddings cache table.
 */
function wp_ai_agent_create_embeddings_cache_table() {
    global $wpdb;

    $table           = wp_ai_agent_embeddings_cache_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        hash char(32) NOT NULL,
        vector longtext NOT NULL,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (hash)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * The API key for a given embeddings provider (per-provider key, with legacy
 * single key honored only for the active provider).
 *
 * @param string $provider 'openai' | 'gemini'.
 * @return string
 */
function wp_ai_agent_provider_key( $provider ) {
    $options = wp_ai_agent_get_options();
    $field   = 'api_key_' . $provider;

    if ( ! empty( $options[ $field ] ) ) {
        return $options[ $field ];
    }
    if ( isset( $options['provider'] ) && $provider === $options['provider'] && ! empty( $options['api_key'] ) ) {
        return $options['api_key'];
    }
    return '';
}

/**
 * The effective chat/vision provider to use.
 *
 * Returns the saved provider when it actually has a key; otherwise the first
 * provider (openai → gemini → groq) that does. This makes the whole agent work
 * with ANY single API key, regardless of which provider is selected in
 * settings.
 *
 * @return string 'openai' | 'gemini' | 'groq' | '' (none configured).
 */
function wp_ai_agent_effective_provider() {
    $options  = wp_ai_agent_get_options();
    $provider = isset( $options['provider'] ) ? $options['provider'] : '';

    $candidates = array( 'openai', 'gemini', 'groq' );

    // Saved provider wins when it has a key.
    if ( in_array( $provider, $candidates, true ) && '' !== wp_ai_agent_provider_key( $provider ) ) {
        return $provider;
    }

    // Otherwise the first provider that has a key.
    foreach ( $candidates as $cand ) {
        if ( '' !== wp_ai_agent_provider_key( $cand ) ) {
            return $cand;
        }
    }

    return $provider; // none configured.
}

/**
 * Which provider to use for embeddings. Prefers the active provider when it
 * supports embeddings, otherwise any provider with a key. Groq has no
 * embeddings API, so it is never chosen here.
 *
 * @return string 'openai' | 'gemini' | '' (none available).
 */
function wp_ai_agent_embedding_provider() {
    $options  = wp_ai_agent_get_options();
    $provider = isset( $options['provider'] ) ? $options['provider'] : '';

    if ( 'openai' === $provider && wp_ai_agent_provider_key( 'openai' ) ) {
        return 'openai';
    }
    if ( 'gemini' === $provider && wp_ai_agent_provider_key( 'gemini' ) ) {
        return 'gemini';
    }
    if ( wp_ai_agent_provider_key( 'openai' ) ) {
        return 'openai';
    }
    if ( wp_ai_agent_provider_key( 'gemini' ) ) {
        return 'gemini';
    }
    return '';
}

/**
 * Whether semantic search is enabled (setting on + an embeddings provider with
 * a key is available).
 *
 * @return bool
 */
function wp_ai_agent_semantic_enabled() {
    $options = wp_ai_agent_get_options();
    if ( isset( $options['enable_semantic'] ) && '0' === $options['enable_semantic'] ) {
        return false;
    }
    return '' !== wp_ai_agent_embedding_provider();
}

/**
 * Embedding model id for a provider.
 *
 * @param string $provider Provider.
 * @return string
 */
function wp_ai_agent_embedding_model( $provider ) {
    $models = array(
        'openai' => 'text-embedding-3-small',
        'gemini' => 'text-embedding-004',
    );
    $model = isset( $models[ $provider ] ) ? $models[ $provider ] : '';

    return apply_filters( 'wp_ai_agent_embedding_model', $model, $provider );
}

/**
 * Embed a batch of texts. Returns a list of vectors (one per input, in order);
 * empty array on failure.
 *
 * @param string[] $texts Texts to embed.
 * @return array[]
 */
function wp_ai_agent_embed_texts( $texts ) {
    $provider = wp_ai_agent_embedding_provider();
    if ( '' === $provider || empty( $texts ) ) {
        return array();
    }
    if ( 'openai' === $provider ) {
        return wp_ai_agent_embed_openai( array_values( $texts ) );
    }
    if ( 'gemini' === $provider ) {
        return wp_ai_agent_embed_gemini( array_values( $texts ) );
    }
    return array();
}

/**
 * Embed a single query string. Returns the vector or an empty array.
 *
 * @param string $text Query text.
 * @return array
 */
function wp_ai_agent_embed_query( $text ) {
    $vectors = wp_ai_agent_embed_texts( array( $text ) );
    return ( isset( $vectors[0] ) && is_array( $vectors[0] ) ) ? $vectors[0] : array();
}

/**
 * OpenAI embeddings (batch).
 *
 * @param string[] $texts Texts.
 * @return array[]
 */

function wp_ai_agent_embed_openai( $texts ) {
    $key = wp_ai_agent_provider_key( 'openai' );
    if ( '' === $key ) {
        return array();
    }

    $response = wp_remote_post( 'https://api.openai.com/v1/embeddings', array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $key,
        ),
        'body'    => wp_json_encode( array(
            'model' => wp_ai_agent_embedding_model( 'openai' ),
            'input' => $texts,
        ) ),
        'timeout' => 45,
    ) );

    if ( is_wp_error( $response ) ) {
        return array();
    }
    if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return array();
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
        return array();
    }

    // Order by the returned index to match the input order.
    $out = array();
    foreach ( $body['data'] as $item ) {
        $idx = isset( $item['index'] ) ? (int) $item['index'] : count( $out );
        $out[ $idx ] = isset( $item['embedding'] ) ? $item['embedding'] : array();
    }
    ksort( $out );

    return array_values( $out );
}

/**
 * Gemini embeddings (batch via batchEmbedContents).
 *
 * @param string[] $texts Texts.
 * @return array[]
 */

function wp_ai_agent_embed_gemini( $texts ) {
    $key = wp_ai_agent_provider_key( 'gemini' );
    if ( '' === $key ) {
        return array();
    }

    $model = wp_ai_agent_embedding_model( 'gemini' );
    $url   = sprintf(
        'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents?key=%s',
        rawurlencode( $model ),
        rawurlencode( $key )
    );

    $requests = array();
    foreach ( $texts as $text ) {
        $requests[] = array(
            'model'   => 'models/' . $model,
            'content' => array(
                'parts' => array( array( 'text' => $text ) ),
            ),
        );
    }

    $response = wp_remote_post( $url, array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array( 'requests' => $requests ) ),
        'timeout' => 45,
    ) );

    if ( is_wp_error( $response ) ) {
        return array();
    }
    if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return array();
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['embeddings'] ) || ! is_array( $body['embeddings'] ) ) {
        return array();
    }

    $out = array();
    foreach ( $body['embeddings'] as $emb ) {
        $out[] = isset( $emb['values'] ) ? $emb['values'] : array();
    }

    return $out;
}

/**
 * Cosine similarity of two vectors.
 *
 * @param array $a Vector A.
 * @param array $b Vector B.
 * @return float
 */
function wp_ai_agent_cosine_similarity( $a, $b ) {
    $n = min( count( $a ), count( $b ) );
    if ( 0 === $n ) {
        return 0.0;
    }

    $dot = 0.0;
    $na  = 0.0;
    $nb  = 0.0;
    for ( $i = 0; $i < $n; $i++ ) {
        $x    = (float) $a[ $i ];
        $y    = (float) $b[ $i ];
        $dot += $x * $y;
        $na  += $x * $x;
        $nb  += $y * $y;
    }

    if ( $na <= 0 || $nb <= 0 ) {
        return 0.0;
    }

    return $dot / ( sqrt( $na ) * sqrt( $nb ) );
}

/**
 * The text used to embed an index row (title + a slice of content).
 *
 * @param string $title   Title.
 * @param string $content Content.
 * @return string
 */
function wp_ai_agent_embedding_text( $title, $content ) {
    $text = trim( $title . "\n" . wp_trim_words( wp_strip_all_tags( (string) $content ), 400, '' ) );
    if ( '' === $text ) {
        $text = trim( (string) $title );
    }
    return $text;
}

/**
 * Generate (or reuse cached) embeddings for every row in the content index and
 * store them in the index table's `embedding` column.
 *
 * @return int Number of rows that now have an embedding.
 */
function wp_ai_agent_generate_index_embeddings() {
    if ( ! wp_ai_agent_semantic_enabled() ) {
        return 0;
    }

    global $wpdb;
    $table       = wp_ai_agent_index_table_name();
    $cache_table = wp_ai_agent_embeddings_cache_table();
    wp_ai_agent_create_embeddings_cache_table();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( "SELECT id, title, content FROM {$table}" );
    if ( empty( $rows ) ) {
        return 0;
    }

    // Build embed text + hash per row.
    $row_hash = array();
    $needed   = array();
    foreach ( $rows as $row ) {
        $text = wp_ai_agent_embedding_text( $row->title, $row->content );
        if ( '' === $text ) {
            continue;
        }
        $hash               = md5( $text );
        $row_hash[ $row->id ] = $hash;
        $needed[ $hash ]      = $text;
    }

    if ( empty( $needed ) ) {
        return 0;
    }

    // Which hashes are already cached?
    $hashes = array_keys( $needed );
    $cached = array();
    $chunks = array_chunk( $hashes, 200 );
    foreach ( $chunks as $chunk ) {
        $place = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare( "SELECT hash, vector FROM {$cache_table} WHERE hash IN ($place)", $chunk );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        foreach ( $wpdb->get_results( $sql ) as $c ) {
            $cached[ $c->hash ] = $c->vector;
        }
    }

    // Embed the missing texts in batches.
    $missing = array();
    foreach ( $needed as $hash => $text ) {
        if ( ! isset( $cached[ $hash ] ) ) {
            $missing[ $hash ] = $text;
        }
    }

    $batch_size     = (int) apply_filters( 'wp_ai_agent_embed_batch_size', 50 );
    $missing_hashes = array_keys( $missing );
    $missing_texts  = array_values( $missing );
    $total          = count( $missing_texts );

    for ( $i = 0; $i < $total; $i += $batch_size ) {
        $chunk_texts  = array_slice( $missing_texts, $i, $batch_size );
        $chunk_hashes = array_slice( $missing_hashes, $i, $batch_size );

        $vectors = wp_ai_agent_embed_texts( $chunk_texts );
        if ( count( $vectors ) !== count( $chunk_texts ) ) {
            // Provider error / partial response — skip this chunk safely.
            continue;
        }

        foreach ( $vectors as $k => $vector ) {
            if ( ! is_array( $vector ) || empty( $vector ) ) {
                continue;
            }
            $json                       = wp_json_encode( $vector );
            $cached[ $chunk_hashes[ $k ] ] = $json;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->replace(
                $cache_table,
                array(
                    'hash'       => $chunk_hashes[ $k ],
                    'vector'     => $json,
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s' )
            );
        }
    }

    // Write the vectors back to the index rows.
    $count = 0;
    foreach ( $row_hash as $id => $hash ) {
        if ( isset( $cached[ $hash ] ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $table, array( 'embedding' => $cached[ $hash ] ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
            $count++;
        }
    }

    return $count;
}

/**
 * Semantic search: rank indexed content by cosine similarity to the query.
 *
 * @param string $query Query.
 * @param int    $limit Max results.
 * @return array[] Items shaped like keyword results (type/title/content/url/source/relevance).
 */
function wp_ai_agent_semantic_search( $query, $limit = 8 ) {
    if ( ! wp_ai_agent_semantic_enabled() ) {
        return array();
    }

    $qvec = wp_ai_agent_embed_query( $query );
    if ( empty( $qvec ) ) {
        return array();
    }

    global $wpdb;
    $table = wp_ai_agent_index_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( "SELECT content_type, title, content, url, source, embedding FROM {$table} WHERE embedding <> ''" );
    if ( empty( $rows ) ) {
        return array();
    }

    $threshold = (float) apply_filters( 'wp_ai_agent_semantic_threshold', 0.25 );
    $scored    = array();

    foreach ( $rows as $row ) {
        $vec = json_decode( $row->embedding, true );
        if ( ! is_array( $vec ) || empty( $vec ) ) {
            continue;
        }
        $sim = wp_ai_agent_cosine_similarity( $qvec, $vec );
        if ( $sim < $threshold ) {
            continue;
        }
        $scored[] = array(
            'type'      => $row->content_type,
            'title'     => $row->title,
            'content'   => $row->content,
            'url'       => $row->url,
            'source'    => $row->source,
            'relevance' => $sim,
            'score'     => $sim,
        );
    }

    usort( $scored, function ( $a, $b ) {
        if ( $a['relevance'] === $b['relevance'] ) {
            return 0;
        }
        return ( $a['relevance'] < $b['relevance'] ) ? 1 : -1;
    } );

    return array_slice( $scored, 0, $limit );
}
