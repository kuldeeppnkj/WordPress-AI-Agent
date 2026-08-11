<?php
/**
 * Analytics dashboard for the conversation log (wp_ai_conversations).
 *
 * Overview metrics, trend charts (daily / weekly / monthly), reports
 * (recent conversations, popular questions, top pages, search analytics,
 * failed questions), filters (date range, page URL, session), and exports
 * (CSV, Excel, search report). All data comes from the conversations DB layer
 * in includes/conversations.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * Exports.
 * ---------------------------------------------------------------------- */

/**
 * Build the filtered conversation export query (shared by CSV + Excel).
 *
 * @param array $filters Filters from wp_ai_agent_analytics_filters_from_request().
 * @return string Prepared SQL.
 */
function wp_ai_agent_export_conversations_sql( $filters ) {
    global $wpdb;

    $table  = wp_ai_agent_conversations_table_name();
    $c      = wp_ai_agent_analytics_conditions( $filters );
    $conds  = $c['conds'];
    $params = $c['params'];

    if ( ! empty( $filters['search'] ) ) {
        $like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
        $conds[] = '(user_message LIKE %s OR bot_message LIKE %s OR page_url LIKE %s OR session_id LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $where = wp_ai_agent_analytics_where( $conds );
    $sql   = "SELECT id, session_id, page_url, user_message, bot_message, response_ms, created_at FROM {$table}{$where} ORDER BY id DESC";

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return $params ? $wpdb->prepare( $sql, $params ) : $sql;
}

/**
 * CSV export of the (filtered) conversation log.
 */
add_action( 'admin_post_wp_ai_agent_export_csv', 'wp_ai_agent_export_conversations_csv' );
function wp_ai_agent_export_conversations_csv() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    check_admin_referer( 'wp_ai_agent_export_csv' );

    global $wpdb;
    $filters = wp_ai_agent_analytics_filters_from_request();

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=ai-conversations-' . gmdate( 'Y-m-d' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'id', 'session_id', 'page_url', 'user_message', 'bot_message', 'response_ms', 'created_at' ) );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( wp_ai_agent_export_conversations_sql( $filters ), ARRAY_A );
    if ( $rows ) {
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
    }
    fclose( $out ); // phpcs:ignore
    exit;
}

/**
 * Excel (.xls) export of the (filtered) conversation log. Emits an
 * Excel-readable HTML table — no external library required.
 */
add_action( 'admin_post_wp_ai_agent_export_xls', 'wp_ai_agent_export_conversations_xls' );
function wp_ai_agent_export_conversations_xls() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    check_admin_referer( 'wp_ai_agent_export_xls' );

    global $wpdb;
    $filters = wp_ai_agent_analytics_filters_from_request();

    nocache_headers();
    header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=ai-conversations-' . gmdate( 'Y-m-d' ) . '.xls' );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( wp_ai_agent_export_conversations_sql( $filters ), ARRAY_A );

    echo "<table border=\"1\">\n";
    echo '<tr><th>ID</th><th>Session</th><th>Page URL</th><th>User Message</th><th>AI Response</th><th>Response (ms)</th><th>Created At</th></tr>' . "\n";
    if ( $rows ) {
        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( array( 'id', 'session_id', 'page_url', 'user_message', 'bot_message', 'response_ms', 'created_at' ) as $col ) {
                echo '<td>' . esc_html( isset( $row[ $col ] ) ? $row[ $col ] : '' ) . '</td>';
            }
            echo "</tr>\n";
        }
    }
    echo "</table>";
    exit;
}

/**
 * CSV "Search Report": popular questions, product searches, and failed
 * questions for the current filter window — a quick actionable insights export.
 */
add_action( 'admin_post_wp_ai_agent_export_search_report', 'wp_ai_agent_export_search_report' );
function wp_ai_agent_export_search_report() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    check_admin_referer( 'wp_ai_agent_export_search_report' );

    $filters = wp_ai_agent_analytics_filters_from_request();

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=ai-search-report-' . gmdate( 'Y-m-d' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'report', 'query', 'count' ) );

    $sections = array(
        'popular_question' => wp_ai_agent_analytics_top_questions( $filters, 50 ),
        'product_search'   => wp_ai_agent_analytics_product_searches( $filters, 50 ),
        'failed_question'  => wp_ai_agent_analytics_failed_questions( $filters, 50 ),
    );
    foreach ( $sections as $name => $rows ) {
        foreach ( $rows as $r ) {
            fputcsv( $out, array( $name, $r->value, $r->c ) );
        }
    }
    fclose( $out ); // phpcs:ignore
    exit;
}

/**
 * Clear all logged conversations.
 */
add_action( 'admin_post_wp_ai_agent_clear_logs', 'wp_ai_agent_clear_conversations' );
function wp_ai_agent_clear_conversations() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    check_admin_referer( 'wp_ai_agent_clear_logs' );

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( 'TRUNCATE TABLE ' . wp_ai_agent_conversations_table_name() );

    wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-agent-analytics&cleared=1' ) );
    exit;
}

/* -------------------------------------------------------------------------
 * Rendering helpers.
 * ---------------------------------------------------------------------- */

/**
 * Human-readable response time from milliseconds.
 *
 * @param int $ms Milliseconds.
 * @return string
 */
function wp_ai_agent_format_ms( $ms ) {
    $ms = (int) $ms;
    if ( $ms <= 0 ) {
        return '—';
    }
    if ( $ms >= 1000 ) {
        return number_format_i18n( $ms / 1000, 2 ) . ' s';
    }
    return number_format_i18n( $ms ) . ' ms';
}

/**
 * Display label for a logged user message (turns "[image: …]" markers into a
 * friendly visual-search label).
 *
 * @param string $message Raw user message.
 * @return string
 */
function wp_ai_agent_query_label( $message ) {
    $message = (string) $message;
    if ( 0 === strpos( $message, '[image:' ) ) {
        $kw = trim( rtrim( substr( $message, strlen( '[image:' ) ), ']' ) );
        return '🖼 ' . ( '' !== $kw ? sprintf( __( 'Image search: %s', 'wp-ai-agent' ), $kw ) : __( 'Image search', 'wp-ai-agent' ) );
    }
    if ( 0 === strpos( $message, '[image search]' ) ) {
        return '🖼 ' . __( 'Image search', 'wp-ai-agent' );
    }
    return $message;
}

/**
 * Render a dependency-free CSS bar chart from a trend series.
 *
 * @param array  $series Buckets of { label, count, users }.
 * @param string $accent Bar colour.
 */
function wp_ai_agent_render_bar_chart( $series, $accent = '#1e73be' ) {
    if ( empty( $series ) ) {
        echo '<p class="wpaia-muted">' . esc_html__( 'No data for this period yet.', 'wp-ai-agent' ) . '</p>';
        return;
    }

    $max = 1;
    foreach ( $series as $b ) {
        $max = max( $max, (int) $b['count'] );
    }

    echo '<div class="wpaia-chart">';
    foreach ( $series as $b ) {
        $count = (int) $b['count'];
        $px    = $count > 0 ? max( 4, (int) round( $count / $max * 150 ) ) : 0;
        $title = sprintf(
            /* translators: 1: period label, 2: chats, 3: users. */
            __( '%1$s — %2$d chats, %3$d users', 'wp-ai-agent' ),
            $b['label'],
            $count,
            (int) $b['users']
        );
        echo '<div class="wpaia-bar-wrap" title="' . esc_attr( $title ) . '">';
        echo '<span class="wpaia-bar-val">' . esc_html( $count ? number_format_i18n( $count ) : '' ) . '</span>';
        echo '<span class="wpaia-bar" style="height:' . esc_attr( $px ) . 'px;background:' . esc_attr( $accent ) . ';"></span>';
        echo '<span class="wpaia-bar-lbl">' . esc_html( $b['label'] ) . '</span>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Render a simple "label + count" report table.
 *
 * @param object[] $rows      Rows of { value, c }.
 * @param string   $col_label Column header for the value.
 * @param bool     $as_query  Whether to render values via wp_ai_agent_query_label + offer "Add answer".
 * @param bool     $is_page   Whether values are page URLs (render as links).
 */
function wp_ai_agent_render_count_table( $rows, $col_label, $as_query = false, $is_page = false ) {
    if ( empty( $rows ) ) {
        echo '<p class="wpaia-muted">' . esc_html__( 'No data yet.', 'wp-ai-agent' ) . '</p>';
        return;
    }

    $home    = home_url();
    $qa_base = admin_url( 'admin.php?page=wp-ai-agent-qa' );

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . esc_html( $col_label ) . '</th>';
    echo '<th style="width:64px;">' . esc_html__( 'Count', 'wp-ai-agent' ) . '</th>';
    if ( $as_query ) {
        echo '<th style="width:90px;"></th>';
    }
    echo '</tr></thead><tbody>';

    foreach ( $rows as $r ) {
        echo '<tr>';
        if ( $is_page ) {
            $label = str_replace( $home, '', $r->value );
            $label = ( '' === $label ) ? '/' : $label;
            echo '<td><a href="' . esc_url( $r->value ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></td>';
        } elseif ( $as_query ) {
            echo '<td>' . esc_html( wp_ai_agent_query_label( $r->value ) ) . '</td>';
        } else {
            echo '<td>' . esc_html( $r->value ) . '</td>';
        }
        echo '<td>' . esc_html( number_format_i18n( $r->c ) ) . '</td>';
        if ( $as_query ) {
            // Only offer "Add answer" for real text questions (not image markers).
            if ( 0 === strpos( (string) $r->value, '[' ) ) {
                echo '<td></td>';
            } else {
                $add = $qa_base . '&q=' . rawurlencode( $r->value );
                echo '<td><a class="button button-small" href="' . esc_url( $add ) . '">' . esc_html__( 'Add answer', 'wp-ai-agent' ) . '</a></td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/* -------------------------------------------------------------------------
 * Dashboard page.
 * ---------------------------------------------------------------------- */

function wp_ai_agent_admin_analytics_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    echo '<div class="wrap"><h1>' . esc_html__( 'AI Agent Analytics', 'wp-ai-agent' ) . '</h1>';

    if ( ! wp_ai_agent_conversations_table_ready() ) {
        echo '<p>' . esc_html__( 'No conversations have been logged yet. Once visitors chat with the assistant, analytics will appear here.', 'wp-ai-agent' ) . '</p></div>';
        return;
    }

    if ( isset( $_GET['cleared'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Conversation logs cleared.', 'wp-ai-agent' ) . '</p></div>';
    }

    $filters = wp_ai_agent_analytics_filters_from_request();

    // Gather all report data.
    $summary       = wp_ai_agent_analytics_summary( $filters );
    $top_questions = wp_ai_agent_analytics_top_questions( $filters, 10 );
    $top_pages     = wp_ai_agent_analytics_top_pages( $filters, 10 );
    $searches      = wp_ai_agent_analytics_product_searches( $filters, 10 );
    $failed        = wp_ai_agent_analytics_failed_questions( $filters, 15 );
    $recent        = wp_ai_agent_analytics_recent( $filters, 20 );
    $daily         = wp_ai_agent_analytics_trends( $filters, 'day', 30 );
    $weekly        = wp_ai_agent_analytics_trends( $filters, 'week', 12 );
    $monthly       = wp_ai_agent_analytics_trends( $filters, 'month', 12 );
    $handoff       = function_exists( 'wp_ai_agent_handoff_stats' ) ? wp_ai_agent_handoff_stats( $filters ) : array( 'shown' => 0, 'clicks' => 0 );
    $bookings_n    = function_exists( 'wp_ai_agent_bookings_count' ) ? wp_ai_agent_bookings_count( $filters ) : 0;

    $pages_list = wp_ai_agent_analytics_pages_list( 100 );
    $home       = home_url();

    // Query string carrying the active filters (for export links).
    $filter_qs = array_filter( array(
        'range'      => $filters['range'],
        'page_url'   => $filters['page_url'],
        'session_id' => $filters['session_id'],
        's'          => $filters['search'],
    ), function ( $v ) {
        return '' !== $v && null !== $v;
    } );

    $export_url = function ( $action, $nonce ) use ( $filter_qs ) {
        $args = array_merge( array( 'action' => $action ), $filter_qs );
        return wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( $args ) ), $nonce );
    };
    ?>
    <style>
        .wpaia-dash { max-width: 1280px; }
        .wpaia-muted { color:#6b7280; }
        .wpaia-toolbar { display:flex; align-items:flex-end; flex-wrap:wrap; gap:12px; margin:14px 0; padding:14px 16px; background:#fff; border:1px solid #e6eaef; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .wpaia-toolbar .field { display:flex; flex-direction:column; gap:4px; }
        .wpaia-toolbar label { font-size:12px; color:#6b7280; }
        .wpaia-toolbar .spacer { flex:1 1 auto; }
        .wpaia-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:16px; margin:18px 0; }
        .wpaia-card { background:#fff; border:1px solid #e6eaef; border-left:4px solid #1e73be; border-radius:12px; padding:16px 18px; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
        .wpaia-card.accent-green { border-left-color:#2e9e5b; }
        .wpaia-card.accent-purple { border-left-color:#7048e8; }
        .wpaia-card.accent-orange { border-left-color:#e8740c; }
        .wpaia-card.accent-red { border-left-color:#d63638; }
        .wpaia-card .lbl { font-size:13px; color:#6b7280; margin-bottom:6px; }
        .wpaia-card .val { font-size:28px; font-weight:700; color:#16314a; line-height:1.1; }
        .wpaia-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; margin-top:8px; }
        @media (max-width:1000px){ .wpaia-grid { grid-template-columns:1fr; } }
        .wpaia-panel { background:#fff; border:1px solid #e6eaef; border-radius:12px; padding:6px 16px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.06); margin-bottom:20px; }
        .wpaia-panel h2 { font-size:15px; margin:14px 4px 10px; }
        .wpaia-panel table.widefat { border:0; box-shadow:none; }
        .wpaia-panel table.widefat thead th { background:#f6f8fb; }
        .wpaia-chart { display:flex; align-items:flex-end; gap:6px; height:200px; padding:10px 4px 0; overflow-x:auto; }
        .wpaia-bar-wrap { flex:1 0 22px; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; min-width:22px; }
        .wpaia-bar-val { font-size:11px; color:#16314a; margin-bottom:3px; min-height:14px; }
        .wpaia-bar { width:70%; min-height:0; border-radius:5px 5px 0 0; transition:opacity .15s; }
        .wpaia-bar-wrap:hover .wpaia-bar { opacity:.78; }
        .wpaia-bar-lbl { font-size:10px; color:#6b7280; margin-top:6px; white-space:nowrap; transform:rotate(-40deg); transform-origin:center; height:26px; }
        .wpaia-charts-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        @media (max-width:1000px){ .wpaia-charts-2 { grid-template-columns:1fr; } }
        .wpaia-recent td { vertical-align:top; }
    </style>
    <div class="wpaia-dash">
    <?php
    // ---- Filters toolbar ----
    echo '<form method="get" class="wpaia-toolbar">';
    echo '<input type="hidden" name="page" value="wp-ai-agent-analytics" />';

    // Date range.
    echo '<div class="field"><label for="wpaia-range">' . esc_html__( 'Date range', 'wp-ai-agent' ) . '</label>';
    echo '<select id="wpaia-range" name="range">';
    foreach ( array( '7' => __( 'Last 7 days', 'wp-ai-agent' ), '30' => __( 'Last 30 days', 'wp-ai-agent' ), '365' => __( 'Last year', 'wp-ai-agent' ), '0' => __( 'All time', 'wp-ai-agent' ) ) as $val => $lbl ) {
        echo '<option value="' . esc_attr( $val ) . '" ' . selected( (string) $filters['range'], $val, false ) . '>' . esc_html( $lbl ) . '</option>';
    }
    echo '</select></div>';

    // Page URL.
    echo '<div class="field"><label for="wpaia-page">' . esc_html__( 'Page URL', 'wp-ai-agent' ) . '</label>';
    echo '<select id="wpaia-page" name="page_url"><option value="">' . esc_html__( 'All pages', 'wp-ai-agent' ) . '</option>';
    foreach ( $pages_list as $purl ) {
        $plabel = str_replace( $home, '', $purl );
        $plabel = ( '' === $plabel ) ? '/' : $plabel;
        echo '<option value="' . esc_attr( $purl ) . '" ' . selected( $filters['page_url'], $purl, false ) . '>' . esc_html( $plabel ) . '</option>';
    }
    echo '</select></div>';

    // Session ID.
    echo '<div class="field"><label for="wpaia-session">' . esc_html__( 'Session ID', 'wp-ai-agent' ) . '</label>';
    echo '<input type="text" id="wpaia-session" name="session_id" value="' . esc_attr( $filters['session_id'] ) . '" placeholder="' . esc_attr__( 'Any session', 'wp-ai-agent' ) . '" style="width:180px;" /></div>';

    echo '<div class="field"><label>&nbsp;</label><button type="submit" class="button button-primary">' . esc_html__( 'Apply', 'wp-ai-agent' ) . '</button></div>';
    if ( ! empty( $filter_qs ) ) {
        echo '<div class="field"><label>&nbsp;</label><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wp-ai-agent-analytics' ) ) . '">' . esc_html__( 'Reset', 'wp-ai-agent' ) . '</a></div>';
    }

    echo '<div class="spacer"></div>';

    // Exports.
    echo '<div class="field"><label>' . esc_html__( 'Export / Reports', 'wp-ai-agent' ) . '</label><div>';
    echo '<a class="button" href="' . esc_url( $export_url( 'wp_ai_agent_export_csv', 'wp_ai_agent_export_csv' ) ) . '">' . esc_html__( 'CSV', 'wp-ai-agent' ) . '</a> ';
    echo '<a class="button" href="' . esc_url( $export_url( 'wp_ai_agent_export_xls', 'wp_ai_agent_export_xls' ) ) . '">' . esc_html__( 'Excel', 'wp-ai-agent' ) . '</a> ';
    echo '<a class="button" href="' . esc_url( $export_url( 'wp_ai_agent_export_search_report', 'wp_ai_agent_export_search_report' ) ) . '">' . esc_html__( 'Search Report', 'wp-ai-agent' ) . '</a> ';
    echo '<a class="button" onclick="return confirm(\'' . esc_js( __( 'Delete ALL conversation logs?', 'wp-ai-agent' ) ) . '\');" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_ai_agent_clear_logs' ), 'wp_ai_agent_clear_logs' ) ) . '">' . esc_html__( 'Clear Logs', 'wp-ai-agent' ) . '</a>';
    echo '</div></div>';
    echo '</form>';

    // ---- Overview cards ----
    $cards = array(
        array( __( 'Total Chats', 'wp-ai-agent' ), $summary['chats'], '' ),
        array( __( 'Total Users', 'wp-ai-agent' ), $summary['users'], 'accent-green' ),
        array( __( 'Total Conversations', 'wp-ai-agent' ), $summary['conversations'], 'accent-purple' ),
        array( __( 'Total Messages', 'wp-ai-agent' ), $summary['messages'], '' ),
        array( __( 'Active Sessions', 'wp-ai-agent' ), $summary['active'], 'accent-orange' ),
        array( __( 'Today', 'wp-ai-agent' ), $summary['today'], '' ),
        array( __( 'Avg Response Time', 'wp-ai-agent' ), wp_ai_agent_format_ms( $summary['avg_ms'] ), 'accent-green', true ),
        array( __( 'Failed Questions', 'wp-ai-agent' ), $summary['failed'], 'accent-red' ),
        array( __( 'WhatsApp Handoffs', 'wp-ai-agent' ), $handoff['shown'], 'accent-green' ),
        array( __( 'WhatsApp Clicks', 'wp-ai-agent' ), $handoff['clicks'], 'accent-green' ),
        array( __( 'Bookings', 'wp-ai-agent' ), $bookings_n, 'accent-purple' ),
    );
    echo '<div class="wpaia-cards">';
    foreach ( $cards as $card ) {
        $accent   = isset( $card[2] ) ? $card[2] : '';
        $is_text  = ! empty( $card[3] );
        $value    = $is_text ? $card[1] : number_format_i18n( $card[1] );
        echo '<div class="wpaia-card ' . esc_attr( $accent ) . '">';
        echo '<div class="lbl">' . esc_html( $card[0] ) . '</div>';
        echo '<div class="val">' . esc_html( $value ) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // ---- Charts ----
    echo '<div class="wpaia-panel">';
    echo '<h2>' . esc_html__( 'Daily Chats (last 30 days)', 'wp-ai-agent' ) . '</h2>';
    wp_ai_agent_render_bar_chart( $daily, '#1e73be' );
    echo '</div>';

    echo '<div class="wpaia-charts-2">';
    echo '<div class="wpaia-panel">';
    echo '<h2>' . esc_html__( 'Weekly Chats (last 12 weeks)', 'wp-ai-agent' ) . '</h2>';
    wp_ai_agent_render_bar_chart( $weekly, '#2e9e5b' );
    echo '</div>';
    echo '<div class="wpaia-panel">';
    echo '<h2>' . esc_html__( 'Monthly Chats (last 12 months)', 'wp-ai-agent' ) . '</h2>';
    wp_ai_agent_render_bar_chart( $monthly, '#7048e8' );
    echo '</div>';
    echo '</div>';

    // ---- Reports row 1: questions + pages ----
    echo '<div class="wpaia-grid">';
    echo '<div class="wpaia-panel"><h2>' . esc_html__( 'Most Asked Questions', 'wp-ai-agent' ) . '</h2>';
    wp_ai_agent_render_count_table( $top_questions, __( 'Question', 'wp-ai-agent' ), true, false );
    echo '</div>';
    echo '<div class="wpaia-panel"><h2>' . esc_html__( 'Top Visited Pages', 'wp-ai-agent' ) . '</h2>';
    wp_ai_agent_render_count_table( $top_pages, __( 'Page', 'wp-ai-agent' ), false, true );
    echo '</div>';
    echo '</div>';

    // ---- Reports row 2: search analytics + failed ----
    echo '<div class="wpaia-grid">';
    echo '<div class="wpaia-panel"><h2>' . esc_html__( 'Top Product Searches', 'wp-ai-agent' ) . '</h2>';
    echo '<p class="description wpaia-muted" style="margin:0 4px 10px;">' . esc_html__( 'Commerce-intent and visual (image) searches visitors made.', 'wp-ai-agent' ) . '</p>';
    wp_ai_agent_render_count_table( $searches, __( 'Search', 'wp-ai-agent' ), true, false );
    echo '</div>';
    echo '<div class="wpaia-panel"><h2>' . esc_html__( 'Failed Questions', 'wp-ai-agent' ) . '</h2>';
    echo '<p class="description wpaia-muted" style="margin:0 4px 10px;">' . esc_html__( 'Questions that got the "not found" reply. Add an answer so the assistant can respond next time.', 'wp-ai-agent' ) . '</p>';
    wp_ai_agent_render_count_table( $failed, __( 'Question', 'wp-ai-agent' ), true, false );
    echo '</div>';
    echo '</div>';

    // ---- Recent conversations ----
    echo '<div class="wpaia-panel wpaia-recent">';
    echo '<h2>' . esc_html__( 'Recent Conversations', 'wp-ai-agent' ) . '</h2>';
    if ( $recent ) {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:140px;">' . esc_html__( 'When', 'wp-ai-agent' ) . '</th>';
        echo '<th>' . esc_html__( 'User', 'wp-ai-agent' ) . '</th>';
        echo '<th>' . esc_html__( 'Assistant', 'wp-ai-agent' ) . '</th>';
        echo '<th style="width:110px;">' . esc_html__( 'Page', 'wp-ai-agent' ) . '</th>';
        echo '<th style="width:80px;">' . esc_html__( 'Time', 'wp-ai-agent' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $recent as $row ) {
            $label = str_replace( $home, '', $row->page_url );
            $label = ( '' === $label ) ? '/' : $label;
            echo '<tr>';
            echo '<td>' . esc_html( $row->created_at ) . '</td>';
            echo '<td>' . esc_html( wp_ai_agent_query_label( $row->user_message ) ) . '</td>';
            echo '<td>' . esc_html( wp_trim_words( $row->bot_message, 30, '…' ) ) . '</td>';
            echo '<td><a href="' . esc_url( $row->page_url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></td>';
            echo '<td>' . esc_html( wp_ai_agent_format_ms( $row->response_ms ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:10px;"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wp-ai-agent-conversations' ) ) . '">' . esc_html__( 'View all conversations', 'wp-ai-agent' ) . '</a></p>';
    } else {
        echo '<p class="wpaia-muted">' . esc_html__( 'No conversations yet.', 'wp-ai-agent' ) . '</p>';
    }
    echo '</div>';

    echo '</div>'; // .wpaia-dash
    echo '</div>'; // .wrap
}
