<?php
/**
 * Conversation database layer.
 *
 * Retrieval, history loading, deletion, and pagination for the
 * wp_ai_conversations log table. Storage (table creation + insert) lives in
 * chat-handler.php; this file is the read/manage + REST history layer that
 * powers per-visitor / per-page history and the admin Conversations screen.
 *
 * Designed to back future analytics & reporting: all queries go through
 * wp_ai_agent_get_conversations() with whitelisted ordering and a (session_id,
 * page_url) composite index for fast lookups.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Bumped whenever the conversations table schema / indexes change. */
if ( ! defined( 'WP_AI_AGENT_CONV_DB_VERSION' ) ) {
    // v4 = admin dashboard columns (user_id, ip_address, user_agent, status, admin_read).
    define( 'WP_AI_AGENT_CONV_DB_VERSION', '4' );
}

/**
 * Add the composite (session_id, page_url) + created_at indexes to existing
 * installs, once per schema version. New installs already get them at create.
 */
add_action( 'admin_init', 'wp_ai_agent_maybe_upgrade_conversations' );
function wp_ai_agent_maybe_upgrade_conversations() {
    if ( get_option( 'wp_ai_agent_conv_db_version' ) === WP_AI_AGENT_CONV_DB_VERSION ) {
        return;
    }
    wp_ai_agent_create_conversations_table(); // dbDelta adds the new indexes in place.
    update_option( 'wp_ai_agent_conv_db_version', WP_AI_AGENT_CONV_DB_VERSION );
}

/**
 * Whether the conversations table exists.
 *
 * @return bool
 */
function wp_ai_agent_conversations_table_ready() {
    global $wpdb;
    $table = wp_ai_agent_conversations_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Query conversations with filtering, search, ordering, and pagination.
 *
 * @param array $args {
 *     @type string $session_id Restrict to one visitor session.
 *     @type string $page_url   Restrict to one page URL (exact match).
 *     @type string $search     Free-text search across message/page/session.
 *     @type string $orderby    id|created_at|session_id|page_url (whitelisted).
 *     @type string $order      ASC|DESC.
 *     @type int    $per_page   Rows per page (default 20).
 *     @type int    $page       1-based page number.
 * }
 * @return array{rows:object[],total:int,per_page:int,page:int,pages:int}
 */
function wp_ai_agent_get_conversations( $args = array() ) {
    global $wpdb;

    $empty = array( 'rows' => array(), 'total' => 0, 'per_page' => 20, 'page' => 1, 'pages' => 0 );
    if ( ! wp_ai_agent_conversations_table_ready() ) {
        return $empty;
    }

    $defaults = array(
        'session_id' => '',
        'page_url'   => '',
        'search'     => '',
        'orderby'    => 'id',
        'order'      => 'DESC',
        'per_page'   => 20,
        'page'       => 1,
    );
    $args  = wp_parse_args( $args, $defaults );
    $table = wp_ai_agent_conversations_table_name();

    $where  = array( '1=1' );
    $params = array();

    if ( '' !== $args['session_id'] ) {
        $where[]  = 'session_id = %s';
        $params[] = $args['session_id'];
    }
    if ( '' !== $args['page_url'] ) {
        $where[]  = 'page_url = %s';
        $params[] = $args['page_url'];
    }
    if ( '' !== $args['search'] ) {
        $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        $where[]  = '(user_message LIKE %s OR bot_message LIKE %s OR page_url LIKE %s OR session_id LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $where_sql = implode( ' AND ', $where );

    // Whitelist ordering to keep the query injection-safe.
    $orderby = in_array( $args['orderby'], array( 'id', 'created_at', 'session_id', 'page_url' ), true ) ? $args['orderby'] : 'id';
    $order   = ( 'ASC' === strtoupper( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

    $per_page = max( 1, (int) $args['per_page'] );
    $page     = max( 1, (int) $args['page'] );
    $offset   = ( $page - 1 ) * $per_page;

    // Total (for pagination).
    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

    // Page of rows.
    $data_sql    = "SELECT id, session_id, page_url, user_message, bot_message, created_at FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
    $data_params = array_merge( $params, array( $per_page, $offset ) );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ) );

    return array(
        'rows'     => $rows ? $rows : array(),
        'total'    => $total,
        'per_page' => $per_page,
        'page'     => $page,
        'pages'    => (int) ceil( $total / $per_page ),
    );
}

/**
 * Load chronological history for ONE visitor session, scoped to ONE page so
 * conversations never leak across pages (requirement: page-specific history).
 *
 * @param string $session_id Visitor session id (required).
 * @param string $page_url   Page URL to scope to ('' = all pages for session).
 * @param int    $limit      Max exchanges to return (most recent kept).
 * @return object[] Rows ordered oldest -> newest.
 */
function wp_ai_agent_get_session_history( $session_id, $page_url = '', $limit = 100, $since = '' ) {
    global $wpdb;

    $session_id = (string) $session_id;
    if ( '' === $session_id || ! wp_ai_agent_conversations_table_ready() ) {
        return array();
    }

    $table = wp_ai_agent_conversations_table_name();
    $limit = max( 1, (int) $limit );

    // Optional page scope (kept for compatibility) and optional "since" cut-off
    // (used to limit a guest restore to the last 24 hours).
    $where  = 'session_id = %s';
    $params = array( $session_id );
    if ( '' !== $page_url ) {
        $where   .= ' AND page_url = %s';
        $params[] = $page_url;
    }
    if ( '' !== $since ) {
        $where   .= ' AND created_at >= %s';
        $params[] = $since;
    }
    $params[] = $limit;

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, session_id, page_url, user_message, bot_message, created_at FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d",
        $params
    ) );

    if ( empty( $rows ) ) {
        return array();
    }

    // Return oldest -> newest for natural chat replay.
    return array_reverse( $rows );
}

/**
 * Delete a single conversation row.
 *
 * @param int $id Row id.
 * @return bool
 */
function wp_ai_agent_delete_conversation( $id ) {
    global $wpdb;
    $id = (int) $id;
    if ( $id <= 0 ) {
        return false;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (bool) $wpdb->delete( wp_ai_agent_conversations_table_name(), array( 'id' => $id ), array( '%d' ) );
}

/**
 * Delete several conversation rows by id.
 *
 * @param int[] $ids Row ids.
 * @return int Number of rows deleted.
 */
function wp_ai_agent_delete_conversations( $ids ) {
    global $wpdb;

    $ids = array_filter( array_map( 'intval', (array) $ids ) );
    if ( empty( $ids ) ) {
        return 0;
    }

    $table = wp_ai_agent_conversations_table_name();
    $place = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($place)", $ids ) );
}

/**
 * Delete an entire visitor session's history.
 *
 * @param string $session_id Session id.
 * @return int Rows deleted.
 */
function wp_ai_agent_delete_session_conversations( $session_id ) {
    global $wpdb;
    $session_id = (string) $session_id;
    if ( '' === $session_id ) {
        return 0;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (int) $wpdb->delete( wp_ai_agent_conversations_table_name(), array( 'session_id' => $session_id ), array( '%s' ) );
}

/* -------------------------------------------------------------------------
 * Admin Conversation Dashboard data layer.
 *
 * A "conversation" = one visitor session (grouped across pages). These queries
 * power the two-panel dashboard: a searchable/filterable/paginated session list
 * on the left and a full chat thread on the right. All ordering is whitelisted
 * and every value is parameterised, so the layer stays injection-safe and scales
 * on the (session_id) / created_at / user_id / status indexes.
 * ---------------------------------------------------------------------- */

/**
 * Resolve WP user IDs whose login / email / name matches a free-text term, so
 * the dashboard search can find "logged-in" conversations by who the person is.
 *
 * @param string $term Search term.
 * @return int[] Matching user IDs (capped).
 */
function wp_ai_agent_search_user_ids( $term ) {
    $term = trim( (string) $term );
    if ( '' === $term || ! function_exists( 'get_users' ) ) {
        return array();
    }
    $users = get_users( array(
        'search'         => '*' . $term . '*',
        'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
        'fields'         => 'ID',
        'number'         => 50,
    ) );
    return array_map( 'intval', (array) $users );
}

/**
 * List conversations (grouped by session) with search, filters, and pagination.
 *
 * @param array $args {
 *     @type string $search    Free text (session id, message, page, user name/email).
 *     @type string $user_type ''|'logged'|'guest'.
 *     @type string $status    ''|'active'|'archived'.
 *     @type bool   $unread     Only sessions with unread messages.
 *     @type string $since      created_at lower bound (mysql datetime, local).
 *     @type string $until      created_at upper bound.
 *     @type int    $per_page   Rows per page (25/50/100).
 *     @type int    $page       1-based page.
 * }
 * @return array{rows:object[],total:int,per_page:int,page:int,pages:int}
 */
function wp_ai_agent_conversation_sessions( $args = array() ) {
    global $wpdb;

    $empty = array( 'rows' => array(), 'total' => 0, 'per_page' => 25, 'page' => 1, 'pages' => 0 );
    if ( ! wp_ai_agent_conversations_table_ready() ) {
        return $empty;
    }

    $defaults = array(
        'search'    => '',
        'user_type' => '',
        'status'    => '',
        'unread'    => false,
        'since'     => '',
        'until'     => '',
        'per_page'  => 25,
        'page'      => 1,
    );
    $args  = wp_parse_args( $args, $defaults );
    $table = wp_ai_agent_conversations_table_name();

    $where  = array( '1=1' );
    $params = array();

    if ( '' !== $args['search'] ) {
        $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        $ors  = array( 'session_id LIKE %s', 'user_message LIKE %s', 'bot_message LIKE %s', 'page_url LIKE %s' );
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $uids = wp_ai_agent_search_user_ids( $args['search'] );
        if ( ! empty( $uids ) ) {
            $place = implode( ',', array_fill( 0, count( $uids ), '%d' ) );
            $ors[] = "user_id IN ($place)";
            foreach ( $uids as $u ) {
                $params[] = (int) $u;
            }
        }
        $where[] = '(' . implode( ' OR ', $ors ) . ')';
    }

    if ( 'logged' === $args['user_type'] ) {
        $where[] = 'user_id > 0';
    } elseif ( 'guest' === $args['user_type'] ) {
        $where[] = 'user_id = 0';
    }

    if ( 'archived' === $args['status'] ) {
        $where[] = "status = 'archived'";
    } elseif ( 'active' === $args['status'] ) {
        $where[] = "status <> 'archived'";
    }

    if ( $args['unread'] ) {
        $where[] = 'admin_read = 0';
    }

    if ( '' !== $args['since'] ) {
        $where[]  = 'created_at >= %s';
        $params[] = $args['since'];
    }
    if ( '' !== $args['until'] ) {
        $where[]  = 'created_at <= %s';
        $params[] = $args['until'];
    }

    $where_sql = implode( ' AND ', $where );

    $count_sql = "SELECT COUNT(DISTINCT session_id) FROM {$table} WHERE {$where_sql}";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

    $per_page = max( 1, (int) $args['per_page'] );
    $page     = max( 1, (int) $args['page'] );
    $offset   = ( $page - 1 ) * $per_page;

    $data_sql = "SELECT session_id,
            MAX(user_id) AS user_id,
            COUNT(*) AS msg_count,
            MIN(created_at) AS started,
            MAX(created_at) AS last_active,
            MAX(id) AS last_id,
            MIN(admin_read) AS min_read,
            MAX(status) AS status
        FROM {$table} WHERE {$where_sql}
        GROUP BY session_id
        ORDER BY last_active DESC
        LIMIT %d OFFSET %d";
    $dp = array_merge( $params, array( $per_page, $offset ) );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $dp ) );

    if ( ! empty( $rows ) ) {
        $last_ids = array();
        foreach ( $rows as $r ) {
            $last_ids[] = (int) $r->last_id;
        }
        $place = implode( ',', array_fill( 0, count( $last_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $lasts = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_message, bot_message, page_url, ip_address, user_agent FROM {$table} WHERE id IN ($place)",
            $last_ids
        ), OBJECT_K );
        foreach ( $rows as $r ) {
            $l              = isset( $lasts[ $r->last_id ] ) ? $lasts[ $r->last_id ] : null;
            $r->last_user   = $l ? $l->user_message : '';
            $r->last_bot    = $l ? $l->bot_message : '';
            $r->page_url    = $l ? $l->page_url : '';
            $r->ip_address  = $l ? $l->ip_address : '';
            $r->user_agent  = $l ? $l->user_agent : '';
            $r->unread      = ( 0 === (int) $r->min_read );
        }
    }

    return array(
        'rows'     => $rows ? $rows : array(),
        'total'    => $total,
        'per_page' => $per_page,
        'page'     => $page,
        'pages'    => (int) ceil( $total / $per_page ),
    );
}

/**
 * Full chronological message thread for ONE session (all pages, no time limit).
 *
 * @param string $session_id Session id.
 * @return object[] Rows oldest -> newest.
 */
function wp_ai_agent_conversation_thread( $session_id ) {
    global $wpdb;
    $session_id = (string) $session_id;
    if ( '' === $session_id || ! wp_ai_agent_conversations_table_ready() ) {
        return array();
    }
    $table = wp_ai_agent_conversations_table_name();
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, user_message, bot_message, page_url, response_ms, created_at FROM {$table} WHERE session_id = %s ORDER BY id ASC",
        $session_id
    ) );
    return $rows ? $rows : array();
}

/**
 * Aggregated metadata for ONE session (for the detail header).
 *
 * @param string $session_id Session id.
 * @return object|null
 */
function wp_ai_agent_conversation_meta( $session_id ) {
    global $wpdb;
    $session_id = (string) $session_id;
    if ( '' === $session_id || ! wp_ai_agent_conversations_table_ready() ) {
        return null;
    }
    $table = wp_ai_agent_conversations_table_name();
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT session_id, MAX(user_id) AS user_id, COUNT(*) AS msg_count, MIN(created_at) AS started, MAX(created_at) AS last_active, MAX(status) AS status, MIN(admin_read) AS min_read FROM {$table} WHERE session_id = %s",
        $session_id
    ) );
    if ( ! $row ) {
        return null;
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $ctx = $wpdb->get_row( $wpdb->prepare(
        "SELECT page_url, ip_address, user_agent FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1",
        $session_id
    ) );
    $row->page_url   = $ctx ? $ctx->page_url : '';
    $row->ip_address = $ctx ? $ctx->ip_address : '';
    $row->user_agent = $ctx ? $ctx->user_agent : '';
    $row->unread     = ( 0 === (int) $row->min_read );
    return $row;
}

/**
 * Set the status of every row in a session (used by Archive / Unarchive).
 *
 * @param string $session_id Session id.
 * @param string $status     'active' | 'archived'.
 * @return int Rows affected.
 */
function wp_ai_agent_set_session_status( $session_id, $status ) {
    global $wpdb;
    $session_id = (string) $session_id;
    $status     = in_array( $status, array( 'active', 'archived' ), true ) ? $status : 'active';
    if ( '' === $session_id ) {
        return 0;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (int) $wpdb->update(
        wp_ai_agent_conversations_table_name(),
        array( 'status' => $status ),
        array( 'session_id' => $session_id ),
        array( '%s' ),
        array( '%s' )
    );
}

/**
 * Mark an entire session as read by the admin (clears the "unread" badge).
 *
 * @param string $session_id Session id.
 * @return int Rows affected.
 */
function wp_ai_agent_mark_session_read( $session_id ) {
    global $wpdb;
    $session_id = (string) $session_id;
    if ( '' === $session_id ) {
        return 0;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (int) $wpdb->update(
        wp_ai_agent_conversations_table_name(),
        array( 'admin_read' => 1 ),
        array( 'session_id' => $session_id ),
        array( '%d' ),
        array( '%s' )
    );
}

/* -------------------------------------------------------------------------
 * REST: load previous conversation for the current visitor + page.
 * ---------------------------------------------------------------------- */

/**
 * REST handler: return chronological chat history for a session, scoped to the
 * requesting page so history is both session-specific and page-specific.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function wp_ai_agent_handle_history_request( $request ) {
    $session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

    if ( '' === $session_id ) {
        return new WP_REST_Response( array( 'messages' => array(), 'count' => 0, 'resume' => '' ), 200 );
    }

    // Restore the last N hours (default 24) so a guest continues where they left
    // off — SESSION-WIDE (across every page) so navigating the site never resets
    // the conversation. Older history simply isn't replayed (it stays for admin
    // analytics but is considered expired for the visitor).
    $hours = (int) $request->get_param( 'hours' );
    if ( $hours <= 0 || $hours > 168 ) {
        $hours = (int) apply_filters( 'wp_ai_agent_guest_history_hours', 24 );
    }
    $since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $hours * HOUR_IN_SECONDS );

    $limit = (int) apply_filters( 'wp_ai_agent_history_limit', 100 );
    $rows  = wp_ai_agent_get_session_history( $session_id, '', $limit, $since );

    $messages = array();
    foreach ( $rows as $row ) {
        $user = (string) $row->user_message;
        $bot  = (string) $row->bot_message;

        // Skip internal markers (e.g. "[image: ...]") so the replayed thread
        // shows only genuine visitor messages.
        if ( '' !== trim( $user ) && '[' !== substr( ltrim( $user ), 0, 1 ) ) {
            $messages[] = array( 'role' => 'human', 'text' => $user );
        }
        if ( '' !== trim( $bot ) ) {
            $messages[] = array( 'role' => 'bot', 'text' => $bot );
        }
    }

    // A short "you were previously discussing…" summary from the saved filters.
    $resume = function_exists( 'wp_ai_agent_shop_context_summary' ) ? wp_ai_agent_shop_context_summary( $session_id ) : '';

    return new WP_REST_Response( array(
        'messages' => $messages,
        'count'    => count( $messages ),
        'resume'   => $resume,
    ), 200 );
}

/**
 * REST handler: "Clear Chat" — delete this guest's stored conversation, pending
 * flow state, and remembered context/filters, so nothing of the old chat
 * remains. The client then starts a brand-new session id.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function wp_ai_agent_handle_clear_history_request( $request ) {
    $params     = $request->get_json_params();
    $session_id = isset( $params['session_id'] ) ? sanitize_text_field( $params['session_id'] ) : '';
    if ( '' === $session_id ) {
        return new WP_REST_Response( array( 'ok' => false ), 200 );
    }

    if ( function_exists( 'wp_ai_agent_delete_session_conversations' ) ) {
        wp_ai_agent_delete_session_conversations( $session_id );
    }
    if ( function_exists( 'wp_ai_agent_clear_state' ) ) {
        wp_ai_agent_clear_state( $session_id );
    }
    if ( function_exists( 'wp_ai_agent_prefs_key' ) ) {
        delete_transient( wp_ai_agent_prefs_key( $session_id ) );
    }

    return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/* -------------------------------------------------------------------------
 * Analytics data layer.
 *
 * Reusable, filter-aware queries that power the Analytics dashboard and its
 * exports. All filtering flows through wp_ai_agent_analytics_conditions() so
 * the (session_id, page_url) + created_at indexes are used and reports stay
 * consistent. Designed so future reports can reuse these directly.
 * ---------------------------------------------------------------------- */

/**
 * Read dashboard/report filters from the current admin request.
 *
 * created_at is stored in site-local time (current_time), so the "since" bound
 * is built from current_time('timestamp') to stay in the same timezone.
 *
 * @return array{range:string,days:int,since:string,page_url:string,session_id:string,search:string}
 */
function wp_ai_agent_analytics_filters_from_request() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '30';
    $days  = in_array( $range, array( '7', '30', '365' ), true ) ? (int) $range : 0;

    $since = '';
    if ( $days > 0 ) {
        $since = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
    }

    $filters = array(
        'range'      => $range,
        'days'       => $days,
        'since'      => $since,
        'page_url'   => isset( $_GET['page_url'] ) ? esc_url_raw( wp_unslash( $_GET['page_url'] ) ) : '',
        'session_id' => isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '',
        'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
    );
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    return $filters;
}

/**
 * Build SQL conditions + params from a filter set.
 *
 * @param array $filters See wp_ai_agent_analytics_filters_from_request().
 * @return array{conds:string[],params:array}
 */
function wp_ai_agent_analytics_conditions( $filters ) {
    $conds  = array();
    $params = array();

    if ( ! empty( $filters['since'] ) ) {
        $conds[]  = 'created_at >= %s';
        $params[] = $filters['since'];
    }
    if ( ! empty( $filters['page_url'] ) ) {
        $conds[]  = 'page_url = %s';
        $params[] = $filters['page_url'];
    }
    if ( ! empty( $filters['session_id'] ) ) {
        $conds[]  = 'session_id = %s';
        $params[] = $filters['session_id'];
    }

    return array( 'conds' => $conds, 'params' => $params );
}

/**
 * Turn a condition list into a " WHERE a AND b" clause ('' when empty).
 *
 * @param string[] $conds Conditions.
 * @return string
 */
function wp_ai_agent_analytics_where( $conds ) {
    return empty( $conds ) ? '' : ( ' WHERE ' . implode( ' AND ', $conds ) );
}

/**
 * Bot messages that count as a "failed"/unanswered reply.
 *
 * @return string[]
 */
function wp_ai_agent_analytics_failure_messages() {
    $msgs = array();
    if ( function_exists( 'wp_ai_agent_not_found_message' ) ) {
        $msgs[] = wp_ai_agent_not_found_message();
    }
    if ( function_exists( 'wp_ai_agent_image_no_match_message' ) ) {
        $msgs[] = wp_ai_agent_image_no_match_message();
    }
    $msgs[] = __( "I couldn't read that image. Please try another one.", 'wp-ai-agent' );

    return array_values( array_unique( array_filter( $msgs ) ) );
}

/**
 * Headline scalar metrics for the dashboard.
 *
 * @param array $filters Filters.
 * @return array{conversations:int,messages:int,users:int,chats:int,active:int,today:int,avg_ms:int,failed:int}
 */
function wp_ai_agent_analytics_summary( $filters ) {
    global $wpdb;

    $out = array(
        'conversations' => 0,
        'messages'      => 0,
        'users'         => 0,
        'chats'         => 0,
        'active'        => 0,
        'today'         => 0,
        'avg_ms'        => 0,
        'failed'        => 0,
    );
    if ( ! wp_ai_agent_conversations_table_ready() ) {
        return $out;
    }

    $table  = wp_ai_agent_conversations_table_name();
    $c      = wp_ai_agent_analytics_conditions( $filters );
    $where  = wp_ai_agent_analytics_where( $c['conds'] );
    $params = $c['params'];

    $get = function ( $sql ) use ( $wpdb, $params ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql );
    };

    $out['conversations'] = (int) $get( "SELECT COUNT(*) FROM {$table}{$where}" );
    $out['messages']      = $out['conversations'] * 2;
    $out['users']         = (int) $get( "SELECT COUNT(DISTINCT session_id) FROM {$table}{$where}" );
    $out['chats']         = (int) $get( "SELECT COUNT(DISTINCT session_id, page_url) FROM {$table}{$where}" );

    // Average response time over rows that recorded one.
    $ms_conds  = array_merge( $c['conds'], array( 'response_ms > 0' ) );
    $ms_where  = wp_ai_agent_analytics_where( $ms_conds );
    $out['avg_ms'] = (int) round( (float) $get( "SELECT AVG(response_ms) FROM {$table}{$ms_where}" ) );

    // Failed/unanswered count.
    $fails = wp_ai_agent_analytics_failure_messages();
    if ( ! empty( $fails ) ) {
        $place      = implode( ',', array_fill( 0, count( $fails ), '%s' ) );
        $fail_conds = array_merge( $c['conds'], array( "bot_message IN ($place)" ) );
        $fail_where = wp_ai_agent_analytics_where( $fail_conds );
        $fail_params = array_merge( $params, $fails );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $out['failed'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$fail_where}", $fail_params ) );
    }

    // Active sessions = distinct sessions seen in the last N minutes (now),
    // honoring page/session filters but not the date range.
    $window  = (int) apply_filters( 'wp_ai_agent_active_window_minutes', 30 );
    $since_a = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $window * MINUTE_IN_SECONDS );
    $a_filters = array(
        'page_url'   => isset( $filters['page_url'] ) ? $filters['page_url'] : '',
        'session_id' => isset( $filters['session_id'] ) ? $filters['session_id'] : '',
    );
    $ac        = wp_ai_agent_analytics_conditions( $a_filters );
    $a_conds   = array_merge( $ac['conds'], array( 'created_at >= %s' ) );
    $a_params  = array_merge( $ac['params'], array( $since_a ) );
    $a_where   = wp_ai_agent_analytics_where( $a_conds );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $out['active'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM {$table}{$a_where}", $a_params ) );

    // Today (local day start), honoring page/session filters.
    $today_start = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
    $t_conds     = array_merge( $ac['conds'], array( 'created_at >= %s' ) );
    $t_params    = array_merge( $ac['params'], array( $today_start ) );
    $t_where     = wp_ai_agent_analytics_where( $t_conds );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $out['today'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$t_where}", $t_params ) );

    return $out;
}

/**
 * Grouped report of the most frequent values in a column.
 *
 * @param string $column  Whitelisted column (user_message|page_url).
 * @param array  $filters Filters.
 * @param int    $limit   Max rows.
 * @param array  $extra_conds  Extra SQL conditions (no params) to AND in.
 * @return object[] Rows of { value, c }.
 */
function wp_ai_agent_analytics_group_count( $column, $filters, $limit = 10, $extra_conds = array() ) {
    global $wpdb;

    $allowed = array( 'user_message', 'page_url', 'session_id' );
    if ( ! in_array( $column, $allowed, true ) || ! wp_ai_agent_conversations_table_ready() ) {
        return array();
    }

    $table  = wp_ai_agent_conversations_table_name();
    $c      = wp_ai_agent_analytics_conditions( $filters );
    $conds  = array_merge( $c['conds'], array( "{$column} <> ''" ), (array) $extra_conds );
    $params = $c['params'];
    $where  = wp_ai_agent_analytics_where( $conds );

    $sql      = "SELECT {$column} AS value, COUNT(*) AS c FROM {$table}{$where} GROUP BY {$column} ORDER BY c DESC LIMIT %d";
    $params[] = (int) $limit;

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    return $rows ? $rows : array();
}

/**
 * Most asked questions.
 *
 * @param array $filters Filters.
 * @param int   $limit   Max rows.
 * @return object[]
 */
function wp_ai_agent_analytics_top_questions( $filters, $limit = 10 ) {
    // Exclude internal markers like "[image: ...]".
    return wp_ai_agent_analytics_group_count( 'user_message', $filters, $limit, array( "LEFT(user_message, 1) <> '['" ) );
}

/**
 * Top visited pages.
 *
 * @param array $filters Filters.
 * @param int   $limit   Max rows.
 * @return object[]
 */
function wp_ai_agent_analytics_top_pages( $filters, $limit = 10 ) {
    return wp_ai_agent_analytics_group_count( 'page_url', $filters, $limit );
}

/**
 * Failed / unanswered questions (got a fallback reply).
 *
 * @param array $filters Filters.
 * @param int   $limit   Max rows.
 * @return object[] Rows of { value, c }.
 * 
 */
function wp_ai_agent_analytics_failed_questions( $filters, $limit = 15 ) {
    global $wpdb;

    $fails = wp_ai_agent_analytics_failure_messages();
    if ( empty( $fails ) || ! wp_ai_agent_conversations_table_ready() ) {
        return array();
    }

    $table  = wp_ai_agent_conversations_table_name();
    $c      = wp_ai_agent_analytics_conditions( $filters );
    $place  = implode( ',', array_fill( 0, count( $fails ), '%s' ) );
    $conds  = array_merge( $c['conds'], array( "bot_message IN ($place)", "user_message <> ''", "LEFT(user_message, 1) <> '['" ) );
    $params = array_merge( $c['params'], $fails );
    $where  = wp_ai_agent_analytics_where( $conds );

    $sql      = "SELECT user_message AS value, COUNT(*) AS c FROM {$table}{$where} GROUP BY user_message ORDER BY c DESC LIMIT %d";
    $params[] = (int) $limit;

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    return $rows ? $rows : array();
}

/**
 * Top product searches (heuristic): visitor messages with commerce intent and
 * image (visual) product searches. Provides "Search Analytics" insight.
 *
 * @param array $filters Filters.
 * @param int   $limit   Max rows.
 * @return object[] Rows of { value, c }.
 */

function wp_ai_agent_analytics_product_searches( $filters, $limit = 10 ) {
    global $wpdb;

    if ( ! wp_ai_agent_conversations_table_ready() ) {
        return array();
    }

    $terms = apply_filters( 'wp_ai_agent_product_search_terms', array(
        'price', 'cost', 'buy', 'purchase', 'product', 'cheap', 'cheapest', 'under', 'budget',
        'discount', 'offer', 'sale', 'recommend', 'best', 'order', 'shop',
    ) );

    $table  = wp_ai_agent_conversations_table_name();
    $c      = wp_ai_agent_analytics_conditions( $filters );
    $params = $c['params'];

    // Visual searches are logged as "[image: ...]"; commerce-intent messages match a term.
    $or        = array( 'user_message LIKE %s' );
    $or_params = array( $wpdb->esc_like( '[image:' ) . '%' );
    foreach ( $terms as $t ) {
        $or[]        = 'user_message LIKE %s';
        $or_params[] = '%' . $wpdb->esc_like( $t ) . '%';
    }

    $conds  = array_merge( $c['conds'], array( "user_message <> ''", '(' . implode( ' OR ', $or ) . ')' ) );
    $params = array_merge( $params, $or_params );
    $where  = wp_ai_agent_analytics_where( $conds );

    $sql      = "SELECT user_message AS value, COUNT(*) AS c FROM {$table}{$where} GROUP BY user_message ORDER BY c DESC LIMIT %d";
    $params[] = (int) $limit;

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    return $rows ? $rows : array();
}

/**
 * Recent conversations (newest first).
 *
 * @param array $filters Filters.
 * @param int   $limit   Max rows.
 * @return object[]
 */
function wp_ai_agent_analytics_recent( $filters, $limit = 20 ) {
    global $wpdb;

    if ( ! wp_ai_agent_conversations_table_ready() ) {
        return array();
    }

    $table  = wp_ai_agent_conversations_table_name();
    $c      = wp_ai_agent_analytics_conditions( $filters );
    $where  = wp_ai_agent_analytics_where( $c['conds'] );
    $params = $c['params'];

    $sql      = "SELECT session_id, page_url, user_message, bot_message, response_ms, created_at FROM {$table}{$where} ORDER BY id DESC LIMIT %d";
    $params[] = (int) $limit;

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    return $rows ? $rows : array();
}

/**
 * Distinct page URLs, most active first, for the page filter dropdown.
 *
 * @param int $limit Max pages.
 * @return string[]
 */
function wp_ai_agent_analytics_pages_list( $limit = 100 ) {
    global $wpdb;

    if ( ! wp_ai_agent_conversations_table_ready() ) {
        return array();
    }
    $table = wp_ai_agent_conversations_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $cols = $wpdb->get_col( $wpdb->prepare(
        "SELECT page_url FROM {$table} WHERE page_url <> '' GROUP BY page_url ORDER BY COUNT(*) DESC LIMIT %d",
        (int) $limit
    ) );
    return $cols ? $cols : array();
}

/**
 * Chat-volume time series for trend charts. Returns buckets oldest -> newest,
 * each with a label, total conversations, and distinct users.
 *
 * Respects page_url / session_id filters but uses its own fixed lookback
 * window per period so the trend lines stay stable regardless of the date
 * range selector.
 *
 * @param array  $filters Filters (page_url/session_id honored).
 * @param string $period  'day' | 'week' | 'month'.
 * @param int    $buckets Number of buckets.
 * @return array[] List of { label, count, users }.
 */
function wp_ai_agent_analytics_trends( $filters, $period = 'day', $buckets = 30 ) {
    global $wpdb;

    if ( ! wp_ai_agent_conversations_table_ready() ) {
        return array();
    }

    $buckets = max( 1, (int) $buckets );
    $now     = current_time( 'timestamp' );
    $table   = wp_ai_agent_conversations_table_name();

    // Build the ordered bucket map (key => label) and the SQL group expression.
    $list      = array();
    $oldest_ts = $now;

    if ( 'month' === $period ) {
        $group_expr = "DATE_FORMAT(created_at, '%Y-%m')";
        $y = (int) gmdate( 'Y', $now );
        $m = (int) gmdate( 'n', $now );
        for ( $i = $buckets - 1; $i >= 0; $i-- ) {
            $mm = $m - $i;
            $yy = $y;
            while ( $mm <= 0 ) {
                $mm += 12;
                $yy--;
            }
            $ts  = mktime( 0, 0, 0, $mm, 1, $yy );
            $key = sprintf( '%04d-%02d', $yy, $mm );
            $list[ $key ] = array( 'label' => date_i18n( 'M Y', $ts ), 'count' => 0, 'users' => 0 );
            if ( $i === $buckets - 1 ) {
                $oldest_ts = $ts;
            }
        }
    } elseif ( 'week' === $period ) {
        $group_expr = 'YEARWEEK(created_at, 3)';
        $dow        = (int) gmdate( 'N', $now ); // 1 (Mon) .. 7 (Sun).
        $week_start = $now - ( $dow - 1 ) * DAY_IN_SECONDS;
        for ( $i = $buckets - 1; $i >= 0; $i-- ) {
            $ts  = $week_start - $i * 7 * DAY_IN_SECONDS;
            $key = gmdate( 'o', $ts ) . gmdate( 'W', $ts ); // ISO year-week, matches YEARWEEK mode 3.
            $list[ $key ] = array( 'label' => date_i18n( 'M j', $ts ), 'count' => 0, 'users' => 0 );
            if ( $i === $buckets - 1 ) {
                $oldest_ts = $ts;
            }
        }
    } else {
        $group_expr = 'DATE(created_at)';
        for ( $i = $buckets - 1; $i >= 0; $i-- ) {
            $ts  = $now - $i * DAY_IN_SECONDS;
            $key = gmdate( 'Y-m-d', $ts );
            $list[ $key ] = array( 'label' => date_i18n( 'M j', $ts ), 'count' => 0, 'users' => 0 );
            if ( $i === $buckets - 1 ) {
                $oldest_ts = $ts;
            }
        }
    }

    $since = gmdate( 'Y-m-d 00:00:00', $oldest_ts );

    // Conditions: page/session filters + the lookback window.
    $f = array(
        'page_url'   => isset( $filters['page_url'] ) ? $filters['page_url'] : '',
        'session_id' => isset( $filters['session_id'] ) ? $filters['session_id'] : '',
    );
    $c       = wp_ai_agent_analytics_conditions( $f );
    $conds   = array_merge( $c['conds'], array( 'created_at >= %s' ) );
    $params  = array_merge( $c['params'], array( $since ) );
    $where   = wp_ai_agent_analytics_where( $conds );

    $sql = "SELECT {$group_expr} AS k, COUNT(*) AS c, COUNT(DISTINCT session_id) AS u FROM {$table}{$where} GROUP BY {$group_expr}";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

    foreach ( (array) $rows as $r ) {
        $k = (string) $r->k;
        if ( isset( $list[ $k ] ) ) {
            $list[ $k ]['count'] = (int) $r->c;
            $list[ $k ]['users'] = (int) $r->u;
        }
    }

    return array_values( $list );
}
