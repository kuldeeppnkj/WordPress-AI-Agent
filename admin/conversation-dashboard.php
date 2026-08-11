<?php
/**
 * Admin: Conversation Dashboard (professional two-panel live-chat manager).
 *
 * A conversation = one visitor session (grouped across pages). The left panel
 * lists sessions with search, filters and pagination; clicking one loads the
 * full chat thread on the right (lazy-loaded via AJAX). Admins can delete,
 * archive, export (JSON / CSV) and print (PDF) each conversation.
 *
 * Built on the dashboard data layer in includes/conversations.php. Everything is
 * capability-gated (manage_options), nonce-protected, sanitised and escaped.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ helpers */

/**
 * Identify the person behind a conversation.
 *
 * @param int    $user_id    WP user id (0 for guests).
 * @param string $session_id Session id.
 * @return array{type:string,name:string,email:string,id:int,initials:string}
 */
function wp_ai_agent_conv_identity( $user_id, $session_id ) {
    $user_id = (int) $user_id;
    if ( $user_id > 0 && function_exists( 'get_userdata' ) ) {
        $u = get_userdata( $user_id );
        if ( $u ) {
            $name = $u->display_name ? $u->display_name : $u->user_login;
            return array(
                'type'     => 'logged',
                'name'     => $name,
                'email'    => $u->user_email,
                'id'       => $user_id,
                'initials' => wp_ai_agent_conv_initials( $name ),
            );
        }
    }
    // Guest: derive a short, stable label from the session id.
    $tail = strtoupper( substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $session_id ), -4 ) );
    return array(
        'type'     => 'guest',
        'name'     => __( 'Guest', 'wp-ai-agent' ) . ( '' !== $tail ? ' ' . $tail : '' ),
        'email'    => '',
        'id'       => 0,
        'initials' => 'G',
    );
}

/**
 * First-letters avatar text for a name.
 *
 * @param string $name Name.
 * @return string
 */
function wp_ai_agent_conv_initials( $name ) {
    $name  = trim( wp_strip_all_tags( (string) $name ) );
    if ( '' === $name ) {
        return '?';
    }
    $parts = preg_split( '/\s+/', $name );
    $a     = mb_substr( $parts[0], 0, 1 );
    $b     = ( count( $parts ) > 1 ) ? mb_substr( end( $parts ), 0, 1 ) : '';
    return strtoupper( $a . $b );
}

/**
 * Device type from a user-agent string.
 *
 * @param string $ua User agent.
 * @return string
 */
function wp_ai_agent_conv_device( $ua ) {
    $ua = strtolower( (string) $ua );
    if ( '' === $ua ) {
        return __( 'Unknown', 'wp-ai-agent' );
    }
    if ( preg_match( '/ipad|tablet|kindle|playbook|silk|(android(?!.*mobile))/i', $ua ) ) {
        return __( 'Tablet', 'wp-ai-agent' );
    }
    if ( preg_match( '/mobi|iphone|ipod|android.*mobile|windows phone|blackberry/i', $ua ) ) {
        return __( 'Mobile', 'wp-ai-agent' );
    }
    return __( 'Desktop', 'wp-ai-agent' );
}

/**
 * Browser name from a user-agent string.
 *
 * @param string $ua User agent.
 * @return string
 */
function wp_ai_agent_conv_browser( $ua ) {
    if ( '' === (string) $ua ) {
        return __( 'Unknown', 'wp-ai-agent' );
    }
    if ( preg_match( '/edg/i', $ua ) ) {
        return 'Edge';
    }
    if ( preg_match( '/opr|opera/i', $ua ) ) {
        return 'Opera';
    }
    if ( preg_match( '/chrome|crios/i', $ua ) ) {
        return 'Chrome';
    }
    if ( preg_match( '/firefox|fxios/i', $ua ) ) {
        return 'Firefox';
    }
    if ( preg_match( '/safari/i', $ua ) ) {
        return 'Safari';
    }
    return __( 'Unknown', 'wp-ai-agent' );
}

/**
 * Local timestamp for a stored (local) mysql datetime.
 *
 * @param string $mysql Datetime.
 * @return int
 */
function wp_ai_agent_conv_ts( $mysql ) {
    return (int) strtotime( (string) $mysql );
}

/**
 * "2 hours ago"-style relative label for a stored datetime.
 *
 * @param string $mysql Datetime.
 * @return string
 */
function wp_ai_agent_conv_ago( $mysql ) {
    $ts = wp_ai_agent_conv_ts( $mysql );
    if ( ! $ts ) {
        return '';
    }
    $now = (int) current_time( 'timestamp' );
    if ( $ts > $now ) {
        $ts = $now;
    }
    /* translators: %s: human time difference. */
    return sprintf( __( '%s ago', 'wp-ai-agent' ), human_time_diff( $ts, $now ) );
}

/**
 * Absolute date+time label for a stored datetime.
 *
 * @param string $mysql Datetime.
 * @return string
 */
function wp_ai_agent_conv_datetime( $mysql ) {
    $ts = wp_ai_agent_conv_ts( $mysql );
    if ( ! $ts ) {
        return '';
    }
    return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
}

/**
 * A visitor message may be an internal marker (e.g. "[image: …]"). Return a
 * human-friendly version for display.
 *
 * @param string $text Stored user message.
 * @return string
 */
function wp_ai_agent_conv_user_display( $text ) {
    $text = (string) $text;
    if ( 0 === strpos( ltrim( $text ), '[image:' ) ) {
        return '📷 ' . __( 'Image / product photo search', 'wp-ai-agent' );
    }
    return $text;
}

/* ------------------------------------------------------- URL + list helpers */

/**
 * Read the dashboard's list filters from the current request.
 *
 * @return array
 */
function wp_ai_agent_conv_dashboard_filters() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'all';
    $valid = array( 'today', 'yesterday', '7', '30', 'all' );
    if ( ! in_array( $range, $valid, true ) ) {
        $range = 'all';
    }

    $since = '';
    $until = '';
    $now   = (int) current_time( 'timestamp' );
    if ( 'today' === $range ) {
        $since = gmdate( 'Y-m-d 00:00:00', $now );
    } elseif ( 'yesterday' === $range ) {
        $since = gmdate( 'Y-m-d 00:00:00', $now - DAY_IN_SECONDS );
        $until = gmdate( 'Y-m-d 00:00:00', $now );
    } elseif ( '7' === $range ) {
        $since = gmdate( 'Y-m-d 00:00:00', $now - 7 * DAY_IN_SECONDS );
    } elseif ( '30' === $range ) {
        $since = gmdate( 'Y-m-d 00:00:00', $now - 30 * DAY_IN_SECONDS );
    }

    $user_type = isset( $_GET['utype'] ) ? sanitize_key( wp_unslash( $_GET['utype'] ) ) : '';
    if ( ! in_array( $user_type, array( 'logged', 'guest' ), true ) ) {
        $user_type = '';
    }
    $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
    if ( ! in_array( $status, array( 'active', 'archived' ), true ) ) {
        $status = '';
    }
    $per_page = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : 25;
    if ( ! in_array( $per_page, array( 25, 50, 100 ), true ) ) {
        $per_page = 25;
    }

    return array(
        'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
        'user_type' => $user_type,
        'status'    => $status,
        'unread'    => ! empty( $_GET['unread'] ),
        'range'     => $range,
        'since'     => $since,
        'until'     => $until,
        'per_page'  => $per_page,
        'page'      => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1,
    );
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Build a dashboard URL with merged query args.
 *
 * @param array $extra Extra args.
 * @return string
 */
function wp_ai_agent_conv_dashboard_url( $extra = array() ) {
    $args = array_merge( array( 'page' => 'wp-ai-agent-conversations' ), $extra );
    return admin_url( 'admin.php?' . http_build_query( $args ) );
}

/* ---------------------------------------------------------------- the page */

/**
 * Render the Conversation Dashboard screen.
 */
function wp_ai_agent_conversation_dashboard_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $f      = wp_ai_agent_conv_dashboard_filters();
    $result = wp_ai_agent_conversation_sessions( array(
        'search'    => $f['search'],
        'user_type' => $f['user_type'],
        'status'    => $f['status'],
        'unread'    => $f['unread'],
        'since'     => $f['since'],
        'until'     => $f['until'],
        'per_page'  => $f['per_page'],
        'page'      => $f['page'],
    ) );

    $rows  = $result['rows'];
    $total = $result['total'];
    $pages = $result['pages'];
    $home  = home_url();
    $nonce = wp_create_nonce( 'wpaia_conv' );

    echo '<div class="wrap wpaia-cv-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Conversations', 'wp-ai-agent' ) . '</h1>';
    echo '<hr class="wp-header-end" />';

    // ---- Filter / search toolbar (GET) ----
    echo '<form method="get" class="wpaia-cv-toolbar">';
    echo '<input type="hidden" name="page" value="wp-ai-agent-conversations" />';

    echo '<input type="search" name="s" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'Search name, email, session, message…', 'wp-ai-agent' ) . '" class="wpaia-cv-search" />';

    echo '<select name="range">';
    foreach ( array(
        'all'       => __( 'All time', 'wp-ai-agent' ),
        'today'     => __( 'Today', 'wp-ai-agent' ),
        'yesterday' => __( 'Yesterday', 'wp-ai-agent' ),
        '7'         => __( 'Last 7 days', 'wp-ai-agent' ),
        '30'        => __( 'Last 30 days', 'wp-ai-agent' ),
    ) as $k => $label ) {
        echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['range'], $k, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';

    echo '<select name="utype">';
    foreach ( array(
        ''       => __( 'All users', 'wp-ai-agent' ),
        'logged' => __( 'Logged-in', 'wp-ai-agent' ),
        'guest'  => __( 'Guests', 'wp-ai-agent' ),
    ) as $k => $label ) {
        echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['user_type'], $k, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';

    echo '<select name="status">';
    foreach ( array(
        ''         => __( 'All status', 'wp-ai-agent' ),
        'active'   => __( 'Active', 'wp-ai-agent' ),
        'archived' => __( 'Archived', 'wp-ai-agent' ),
    ) as $k => $label ) {
        echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['status'], $k, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';

    echo '<select name="per_page">';
    foreach ( array( 25, 50, 100 ) as $pp ) {
        echo '<option value="' . esc_attr( $pp ) . '"' . selected( $f['per_page'], $pp, false ) . '>' . sprintf(
            /* translators: %d: per-page count. */
            esc_html__( '%d / page', 'wp-ai-agent' ),
            (int) $pp
        ) . '</option>';
    }
    echo '</select>';

    echo '<label class="wpaia-cv-unread-lbl"><input type="checkbox" name="unread" value="1"' . checked( $f['unread'], true, false ) . ' /> ' . esc_html__( 'Unread only', 'wp-ai-agent' ) . '</label>';

    echo '<button type="submit" class="button button-primary">' . esc_html__( 'Filter', 'wp-ai-agent' ) . '</button>';
    if ( '' !== $f['search'] || '' !== $f['user_type'] || '' !== $f['status'] || 'all' !== $f['range'] || $f['unread'] ) {
        echo ' <a class="button" href="' . esc_url( wp_ai_agent_conv_dashboard_url() ) . '">' . esc_html__( 'Reset', 'wp-ai-agent' ) . '</a>';
    }
    echo '</form>';

    // ---- Two-panel layout ----
    echo '<div class="wpaia-cv-app" id="wpaia-cv-app">';

    // LEFT: conversation list.
    echo '<div class="wpaia-cv-side">';
    echo '<div class="wpaia-cv-count">' . sprintf(
        /* translators: %s: number of conversations. */
        esc_html( _n( '%s conversation', '%s conversations', $total, 'wp-ai-agent' ) ),
        esc_html( number_format_i18n( $total ) )
    ) . '</div>';

    echo '<div class="wpaia-cv-list">';
    if ( empty( $rows ) ) {
        echo '<div class="wpaia-cv-empty">' . esc_html__( 'No conversations found.', 'wp-ai-agent' ) . '</div>';
    } else {
        foreach ( $rows as $row ) {
            $id      = wp_ai_agent_conv_identity( $row->user_id, $row->session_id );
            $preview = $row->last_user ? wp_ai_agent_conv_user_display( $row->last_user ) : $row->last_bot;
            $preview = wp_trim_words( wp_strip_all_tags( (string) $preview ), 12, '…' );

            $classes = 'wpaia-cv-item';
            if ( $row->unread ) {
                $classes .= ' is-unread';
            }
            if ( 'archived' === $row->status ) {
                $classes .= ' is-archived';
            }

            // Short, readable conversation ID (full session id kept in the title).
            $sid      = (string) $row->session_id;
            $short_id = ( strlen( $sid ) > 18 ) ? ( substr( $sid, 0, 11 ) . '…' . substr( $sid, -4 ) ) : $sid;

            echo '<div class="' . esc_attr( $classes ) . '" data-session="' . esc_attr( $sid ) . '" role="button" tabindex="0">';
            echo '<span class="wpaia-cv-avatar' . ( 'guest' === $id['type'] ? ' is-guest' : '' ) . '">' . esc_html( $id['initials'] ) . '</span>';
            echo '<span class="wpaia-cv-item-main">';
            echo '<span class="wpaia-cv-item-top"><span class="wpaia-cv-name">' . esc_html( $id['name'] ) . '</span><span class="wpaia-cv-when">' . esc_html( wp_ai_agent_conv_ago( $row->last_active ) ) . '</span></span>';
            echo '<span class="wpaia-cv-id" title="' . esc_attr( $sid ) . '">' . esc_html__( 'ID:', 'wp-ai-agent' ) . ' <code>' . esc_html( $short_id ) . '</code></span>';
            echo '<span class="wpaia-cv-preview">' . esc_html( $preview ) . '</span>';
            echo '<span class="wpaia-cv-item-meta">';
            echo '<span class="wpaia-cv-badge wpaia-cv-badge-' . esc_attr( $id['type'] ) . '">' . esc_html( 'logged' === $id['type'] ? __( 'Logged-in', 'wp-ai-agent' ) : __( 'Guest', 'wp-ai-agent' ) ) . '</span>';
            echo '<span class="wpaia-cv-msgcount">' . sprintf(
                /* translators: %s: message count. */
                esc_html( _n( '%s msg', '%s msgs', (int) $row->msg_count, 'wp-ai-agent' ) ),
                esc_html( number_format_i18n( (int) $row->msg_count ) )
            ) . '</span>';
            if ( 'archived' === $row->status ) {
                echo '<span class="wpaia-cv-badge wpaia-cv-badge-arch">' . esc_html__( 'Archived', 'wp-ai-agent' ) . '</span>';
            }
            echo '</span>'; // meta
            echo '</span>'; // main
            // Right-side per-row actions.
            echo '<span class="wpaia-cv-item-actions">';
            echo '<button type="button" class="button button-small wpaia-cv-view" data-session="' . esc_attr( $sid ) . '">' . esc_html__( 'View', 'wp-ai-agent' ) . '</button>';
            echo '<button type="button" class="button button-small wpaia-cv-del" data-session="' . esc_attr( $sid ) . '">' . esc_html__( 'Delete', 'wp-ai-agent' ) . '</button>';
            echo '</span>';
            if ( $row->unread ) {
                echo '<span class="wpaia-cv-dot" aria-label="' . esc_attr__( 'Unread', 'wp-ai-agent' ) . '"></span>';
            }
            echo '</div>';
        }
    }
    echo '</div>'; // list

    // Pagination.
    if ( $pages > 1 ) {
        $base = wp_ai_agent_conv_dashboard_url( array_filter( array(
            's'        => $f['search'],
            'range'    => 'all' !== $f['range'] ? $f['range'] : '',
            'utype'    => $f['user_type'],
            'status'   => $f['status'],
            'unread'   => $f['unread'] ? '1' : '',
            'per_page' => 25 !== $f['per_page'] ? $f['per_page'] : '',
        ) ) ) . '&paged=%#%';
        $links = paginate_links( array(
            'base'      => $base,
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $pages,
            'current'   => $f['page'],
            'type'      => 'plain',
        ) );
        if ( $links ) {
            echo '<div class="wpaia-cv-pagination">' . wp_kses_post( $links ) . '</div>';
        }
    }
    echo '</div>'; // side

    // RIGHT: thread view (filled by AJAX).
    echo '<div class="wpaia-cv-main" id="wpaia-cv-main">';
    echo '<div class="wpaia-cv-placeholder" id="wpaia-cv-placeholder">';
    echo '<span class="dashicons dashicons-format-chat"></span>';
    echo '<p>' . esc_html__( 'Select a conversation to read the full chat.', 'wp-ai-agent' ) . '</p>';
    echo '</div>';
    echo '<div class="wpaia-cv-thread" id="wpaia-cv-thread" style="display:none;"></div>';
    echo '</div>'; // main

    echo '</div>'; // app

    wp_ai_agent_conv_dashboard_assets( $nonce, $home );

    echo '</div>'; // wrap
}

/**
 * Inline CSS + JS for the dashboard. Kept self-contained so the page has no
 * external asset dependencies.
 *
 * @param string $nonce AJAX nonce.
 * @param string $home  Site home URL (for trimming page links).
 */
function wp_ai_agent_conv_dashboard_assets( $nonce, $home ) {
    ?>
    <style>
        .wpaia-cv-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:14px 0; }
        .wpaia-cv-toolbar .wpaia-cv-search { min-width:280px; flex:1 1 280px; max-width:420px; }
        .wpaia-cv-toolbar select { max-width:160px; }
        .wpaia-cv-unread-lbl { display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
        .wpaia-cv-app { display:flex; gap:16px; align-items:stretch; height:calc(100vh - 230px); min-height:460px; }
        .wpaia-cv-side { flex:0 0 360px; max-width:360px; display:flex; flex-direction:column; background:#fff; border:1px solid #dcdfe4; border-radius:10px; overflow:hidden; }
        .wpaia-cv-count { padding:10px 14px; font-weight:600; color:#50575e; border-bottom:1px solid #eef0f3; background:#fbfcfd; }
        .wpaia-cv-list { flex:1 1 auto; overflow-y:auto; }
        .wpaia-cv-empty { padding:28px 16px; color:#787c82; text-align:center; }
        .wpaia-cv-item { display:flex; gap:11px; width:100%; text-align:left; background:none; border:0; border-bottom:1px solid #f0f2f5; padding:12px 14px; cursor:pointer; position:relative; align-items:flex-start; }
        .wpaia-cv-item:hover { background:#f6f9fc; }
        .wpaia-cv-item.is-active { background:#eef5fc; box-shadow:inset 3px 0 0 #2271b1; }
        .wpaia-cv-item.is-unread .wpaia-cv-name { font-weight:700; }
        .wpaia-cv-item.is-archived { opacity:.72; }
        .wpaia-cv-avatar { flex:0 0 40px; width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#2271b1,#135e96); color:#fff; font-weight:700; font-size:14px; display:flex; align-items:center; justify-content:center; }
        .wpaia-cv-avatar.is-guest { background:linear-gradient(135deg,#9aa5b1,#6b7480); }
        .wpaia-cv-item-main { flex:1 1 auto; min-width:0; display:flex; flex-direction:column; gap:3px; }
        .wpaia-cv-item-top { display:flex; justify-content:space-between; gap:8px; align-items:baseline; }
        .wpaia-cv-name { font-size:13.5px; color:#1d2327; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .wpaia-cv-when { flex:0 0 auto; font-size:11px; color:#8c9196; }
        .wpaia-cv-preview { font-size:12.5px; color:#646970; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .wpaia-cv-item-meta { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:2px; }
        .wpaia-cv-badge { font-size:10.5px; font-weight:600; padding:1px 7px; border-radius:10px; }
        .wpaia-cv-badge-logged { background:#e6f4ea; color:#1a7f37; }
        .wpaia-cv-badge-guest { background:#eef0f3; color:#646970; }
        .wpaia-cv-badge-arch { background:#fbeaea; color:#b32d2e; }
        .wpaia-cv-msgcount { font-size:11px; color:#8c9196; }
        .wpaia-cv-id { font-size:11px; color:#8c9196; margin:1px 0; }
        .wpaia-cv-id code { font-size:11px; background:#eef1f5; color:#50575e; padding:1px 6px; border-radius:4px; }
        .wpaia-cv-item-actions { display:flex; flex-direction:column; gap:5px; flex:0 0 auto; align-self:center; }
        .wpaia-cv-item-actions .button { min-width:64px; text-align:center; justify-content:center; }
        .wpaia-cv-view { color:#2271b1; }
        .wpaia-cv-del { color:#b32d2e; }
        .wpaia-cv-dot { position:absolute; top:8px; left:7px; width:8px; height:8px; border-radius:50%; background:#2271b1; box-shadow:0 0 0 2px #fff; }
        .wpaia-cv-pagination { padding:10px 12px; border-top:1px solid #eef0f3; text-align:center; }
        .wpaia-cv-pagination .page-numbers { display:inline-block; padding:2px 8px; margin:0 1px; border-radius:5px; text-decoration:none; }
        .wpaia-cv-pagination .page-numbers.current { background:#2271b1; color:#fff; }

        .wpaia-cv-main { flex:1 1 auto; min-width:0; background:#fff; border:1px solid #dcdfe4; border-radius:10px; display:flex; flex-direction:column; overflow:hidden; }
        .wpaia-cv-placeholder { flex:1 1 auto; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#a7aaad; gap:8px; }
        .wpaia-cv-placeholder .dashicons { font-size:52px; width:52px; height:52px; }
        .wpaia-cv-thread { flex:1 1 auto; display:flex; flex-direction:column; min-height:0; }

        .wpaia-cv-head { padding:12px 16px; border-bottom:1px solid #eef0f3; background:#fbfcfd; display:flex; gap:12px; align-items:flex-start; }
        .wpaia-cv-head .wpaia-cv-avatar { flex:0 0 44px; width:44px; height:44px; }
        .wpaia-cv-head-info { flex:1 1 auto; min-width:0; }
        .wpaia-cv-head-name { font-size:15px; font-weight:700; color:#1d2327; }
        .wpaia-cv-head-sub { font-size:12px; color:#646970; margin-top:2px; word-break:break-word; }
        .wpaia-cv-head-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
        .wpaia-cv-back { display:none; }
        .wpaia-cv-details { padding:9px 16px; border-bottom:1px solid #eef0f3; display:flex; flex-wrap:wrap; gap:6px 18px; font-size:12px; color:#50575e; background:#fff; }
        .wpaia-cv-details b { color:#1d2327; font-weight:600; }

        .wpaia-cv-messages { flex:1 1 auto; overflow-y:auto; padding:18px 16px; background:#f0f2f5; min-height:0; }
        .wpaia-cv-daysep { text-align:center; margin:14px 0; }
        .wpaia-cv-daysep span { background:#dfe3e8; color:#50575e; font-size:11px; font-weight:600; padding:3px 12px; border-radius:12px; }
        .wpaia-cv-msg { display:flex; flex-direction:column; margin:10px 0; max-width:76%; }
        .wpaia-cv-user { margin-left:auto; align-items:flex-end; }
        .wpaia-cv-bot { margin-right:auto; align-items:flex-start; }
        .wpaia-cv-bubble { padding:9px 13px; border-radius:14px; font-size:13.5px; line-height:1.5; word-wrap:break-word; white-space:normal; box-shadow:0 1px 1px rgba(0,0,0,.05); }
        .wpaia-cv-user .wpaia-cv-bubble { background:#2271b1; color:#fff; border-bottom-right-radius:4px; }
        .wpaia-cv-bot .wpaia-cv-bubble { background:#fff; color:#1d2327; border:1px solid #e3e6ea; border-bottom-left-radius:4px; }
        .wpaia-cv-time { font-size:10.5px; color:#8c9196; margin-top:3px; }
        .wpaia-cv-loading { padding:30px; text-align:center; color:#787c82; }

        @media screen and (max-width:960px) {
            .wpaia-cv-app { height:auto; flex-direction:column; }
            .wpaia-cv-side { flex-basis:auto; max-width:none; height:60vh; }
            .wpaia-cv-main { min-height:60vh; }
            .wpaia-cv-app.is-viewing .wpaia-cv-side { display:none; }
            .wpaia-cv-app.is-viewing .wpaia-cv-main { display:flex; }
            .wpaia-cv-app:not(.is-viewing) .wpaia-cv-main { display:none; }
            .wpaia-cv-back { display:inline-flex !important; }
        }
    </style>
    <script>
    (function () {
        var CFG = {
            ajax: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
            nonce: <?php echo wp_json_encode( $nonce ); ?>,
            confirmDelete: <?php echo wp_json_encode( __( 'Are you sure you want to permanently delete this conversation? This cannot be undone.', 'wp-ai-agent' ) ); ?>,
            copied: <?php echo wp_json_encode( __( 'Conversation copied to clipboard.', 'wp-ai-agent' ) ); ?>
        };
        var app = document.getElementById('wpaia-cv-app');
        var list = app ? app.querySelector('.wpaia-cv-list') : null;
        var main = document.getElementById('wpaia-cv-main');
        var thread = document.getElementById('wpaia-cv-thread');
        var placeholder = document.getElementById('wpaia-cv-placeholder');
        if (!app || !list) { return; }
        var current = null;

        function post(action, data) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('_ajax_nonce', CFG.nonce);
            Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
            return fetch(CFG.ajax, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); });
        }

        function openThread(session, itemEl) {
            current = session;
            placeholder.style.display = 'none';
            thread.style.display = 'flex';
            thread.innerHTML = '<div class="wpaia-cv-loading">' + '…' + '</div>';
            app.classList.add('is-viewing');
            list.querySelectorAll('.wpaia-cv-item.is-active').forEach(function (n) { n.classList.remove('is-active'); });
            if (itemEl) {
                itemEl.classList.add('is-active');
                itemEl.classList.remove('is-unread');
                var dot = itemEl.querySelector('.wpaia-cv-dot');
                if (dot) { dot.parentNode.removeChild(dot); }
            }
            post('wpaia_conv_thread', { session_id: session }).then(function (res) {
                if (res && res.success && res.data && res.data.html) {
                    thread.innerHTML = res.data.html;
                    wireThread();
                    var box = thread.querySelector('.wpaia-cv-messages');
                    if (box) { box.scrollTop = box.scrollHeight; }
                } else {
                    thread.innerHTML = '<div class="wpaia-cv-loading">' + (res && res.data && res.data.message ? res.data.message : 'Error') + '</div>';
                }
            }).catch(function () {
                thread.innerHTML = '<div class="wpaia-cv-loading">Network error.</div>';
            });
        }

        function wireThread() {
            var back = thread.querySelector('.wpaia-cv-back');
            if (back) { back.addEventListener('click', function () { app.classList.remove('is-viewing'); }); }

            var del = thread.querySelector('[data-action="delete"]');
            if (del) {
                del.addEventListener('click', function () {
                    if (!window.confirm(CFG.confirmDelete)) { return; }
                    post('wpaia_conv_delete', { session_id: current }).then(function (res) {
                        if (res && res.success) { window.location.reload(); }
                    });
                });
            }
            var arch = thread.querySelector('[data-action="archive"]');
            if (arch) {
                arch.addEventListener('click', function () {
                    post('wpaia_conv_archive', { session_id: current, status: arch.getAttribute('data-status') }).then(function (res) {
                        if (res && res.success) { window.location.reload(); }
                    });
                });
            }
            var copy = thread.querySelector('[data-action="copy"]');
            if (copy) {
                copy.addEventListener('click', function () {
                    var msgs = thread.querySelectorAll('.wpaia-cv-messages .wpaia-cv-bubble');
                    var text = '';
                    thread.querySelectorAll('.wpaia-cv-messages .wpaia-cv-msg').forEach(function (m) {
                        var who = m.classList.contains('wpaia-cv-user') ? 'User' : 'AI';
                        var b = m.querySelector('.wpaia-cv-bubble');
                        if (b) { text += who + ': ' + b.innerText + '\n'; }
                    });
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(text).then(function () { window.alert(CFG.copied); });
                    }
                });
            }
        }

        list.querySelectorAll('.wpaia-cv-item').forEach(function (item) {
            var session = item.getAttribute('data-session');
            item.addEventListener('click', function (e) {
                // Clicks on the row (but not on the action buttons) open the thread.
                if (e.target.closest && e.target.closest('.wpaia-cv-item-actions')) { return; }
                openThread(session, item);
            });
            var viewBtn = item.querySelector('.wpaia-cv-view');
            if (viewBtn) {
                viewBtn.addEventListener('click', function (e) { e.stopPropagation(); openThread(session, item); });
            }
            var delBtn = item.querySelector('.wpaia-cv-del');
            if (delBtn) {
                delBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (!window.confirm(CFG.confirmDelete)) { return; }
                    post('wpaia_conv_delete', { session_id: session }).then(function (res) {
                        if (res && res.success) { window.location.reload(); }
                    });
                });
            }
        });
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------- AJAX + export */

/**
 * Render the full thread HTML for a session (used by the AJAX loader).
 *
 * @param string $session_id Session id.
 * @return string HTML, or '' when the session has no messages.
 */
function wp_ai_agent_conv_render_thread( $session_id ) {
    $rows = wp_ai_agent_conversation_thread( $session_id );
    if ( empty( $rows ) ) {
        return '';
    }
    $meta = wp_ai_agent_conversation_meta( $session_id );
    $id   = wp_ai_agent_conv_identity( $meta ? $meta->user_id : 0, $session_id );
    $home = home_url();

    // Mark this session read now that the admin is viewing it.
    wp_ai_agent_mark_session_read( $session_id );

    $is_archived = ( $meta && 'archived' === $meta->status );

    $export_json = wp_nonce_url( admin_url( 'admin-post.php?action=wpaia_conv_export&format=json&session_id=' . rawurlencode( $session_id ) ), 'wpaia_conv_export_' . $session_id );
    $export_csv  = wp_nonce_url( admin_url( 'admin-post.php?action=wpaia_conv_export&format=csv&session_id=' . rawurlencode( $session_id ) ), 'wpaia_conv_export_' . $session_id );
    $print_url   = wp_nonce_url( admin_url( 'admin-post.php?action=wpaia_conv_print&session_id=' . rawurlencode( $session_id ) ), 'wpaia_conv_print_' . $session_id );

    ob_start();

    // Header.
    echo '<div class="wpaia-cv-head">';
    echo '<button type="button" class="button button-small wpaia-cv-back">&larr;</button>';
    echo '<span class="wpaia-cv-avatar' . ( 'guest' === $id['type'] ? ' is-guest' : '' ) . '">' . esc_html( $id['initials'] ) . '</span>';
    echo '<span class="wpaia-cv-head-info">';
    echo '<div class="wpaia-cv-head-name">' . esc_html( $id['name'] ) . '</div>';
    echo '<div class="wpaia-cv-head-sub">';
    if ( '' !== $id['email'] ) {
        echo esc_html( $id['email'] ) . ' &middot; ';
    }
    echo esc_html( 'logged' === $id['type'] ? __( 'Logged-in user', 'wp-ai-agent' ) : __( 'Guest', 'wp-ai-agent' ) );
    echo '</div>';
    echo '</span>';
    echo '<span class="wpaia-cv-head-actions">';
    echo '<a class="button button-small" href="' . esc_url( $export_json ) . '">' . esc_html__( 'JSON', 'wp-ai-agent' ) . '</a>';
    echo '<a class="button button-small" href="' . esc_url( $export_csv ) . '">' . esc_html__( 'CSV', 'wp-ai-agent' ) . '</a>';
    echo '<a class="button button-small" href="' . esc_url( $print_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Print / PDF', 'wp-ai-agent' ) . '</a>';
    echo '<button type="button" class="button button-small" data-action="copy">' . esc_html__( 'Copy', 'wp-ai-agent' ) . '</button>';
    echo '<button type="button" class="button button-small" data-action="archive" data-status="' . ( $is_archived ? 'active' : 'archived' ) . '">' . esc_html( $is_archived ? __( 'Unarchive', 'wp-ai-agent' ) : __( 'Archive', 'wp-ai-agent' ) ) . '</button>';
    echo '<button type="button" class="button button-small button-link-delete" data-action="delete" style="color:#b32d2e;">' . esc_html__( 'Delete', 'wp-ai-agent' ) . '</button>';
    echo '</span>';
    echo '</div>';

    // Details row.
    if ( $meta ) {
        $device  = wp_ai_agent_conv_device( $meta->user_agent );
        $browser = wp_ai_agent_conv_browser( $meta->user_agent );
        $page    = $meta->page_url ? str_replace( $home, '', $meta->page_url ) : '';
        $page    = ( '' === $page ) ? '/' : $page;

        echo '<div class="wpaia-cv-details">';
        echo '<span><b>' . esc_html__( 'Session', 'wp-ai-agent' ) . ':</b> <code>' . esc_html( $session_id ) . '</code></span>';
        if ( $meta->user_id > 0 ) {
            echo '<span><b>' . esc_html__( 'User ID', 'wp-ai-agent' ) . ':</b> ' . esc_html( (int) $meta->user_id ) . '</span>';
        }
        echo '<span><b>' . esc_html__( 'Messages', 'wp-ai-agent' ) . ':</b> ' . esc_html( number_format_i18n( (int) $meta->msg_count ) ) . '</span>';
        echo '<span><b>' . esc_html__( 'Started', 'wp-ai-agent' ) . ':</b> ' . esc_html( wp_ai_agent_conv_datetime( $meta->started ) ) . '</span>';
        echo '<span><b>' . esc_html__( 'Last active', 'wp-ai-agent' ) . ':</b> ' . esc_html( wp_ai_agent_conv_ago( $meta->last_active ) ) . '</span>';
        echo '<span><b>' . esc_html__( 'Device', 'wp-ai-agent' ) . ':</b> ' . esc_html( $device . ' · ' . $browser ) . '</span>';
        if ( '' !== $meta->ip_address ) {
            echo '<span><b>' . esc_html__( 'IP', 'wp-ai-agent' ) . ':</b> ' . esc_html( $meta->ip_address ) . '</span>';
        }
        if ( $meta->page_url ) {
            echo '<span><b>' . esc_html__( 'Page', 'wp-ai-agent' ) . ':</b> <a href="' . esc_url( $meta->page_url ) . '" target="_blank" rel="noopener">' . esc_html( $page ) . '</a></span>';
        }
        echo '</div>';
    }

    // Messages.
    echo '<div class="wpaia-cv-messages">';
    $last_date = '';
    foreach ( $rows as $r ) {
        $ts   = wp_ai_agent_conv_ts( $r->created_at );
        $date = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
        $time = $ts ? date_i18n( get_option( 'time_format' ), $ts ) : '';
        if ( $date !== $last_date ) {
            echo '<div class="wpaia-cv-daysep"><span>' . esc_html( $date ) . '</span></div>';
            $last_date = $date;
        }
        $user = wp_ai_agent_conv_user_display( $r->user_message );
        if ( '' !== trim( (string) $user ) ) {
            echo '<div class="wpaia-cv-msg wpaia-cv-user"><div class="wpaia-cv-bubble">' . nl2br( esc_html( $user ) ) . '</div><div class="wpaia-cv-time">' . esc_html( $time ) . '</div></div>';
        }
        if ( '' !== trim( (string) $r->bot_message ) ) {
            echo '<div class="wpaia-cv-msg wpaia-cv-bot"><div class="wpaia-cv-bubble">' . nl2br( esc_html( $r->bot_message ) ) . '</div><div class="wpaia-cv-time">' . esc_html( $time ) . '</div></div>';
        }
    }
    echo '</div>';

    return ob_get_clean();
}

/**
 * AJAX: load a conversation thread.
 */
add_action( 'wp_ajax_wpaia_conv_thread', 'wp_ai_agent_ajax_conv_thread' );
function wp_ai_agent_ajax_conv_thread() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-agent' ) ), 403 );
    }
    check_ajax_referer( 'wpaia_conv' );
    $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
    $html       = wp_ai_agent_conv_render_thread( $session_id );
    if ( '' === $html ) {
        wp_send_json_error( array( 'message' => __( 'Conversation not found.', 'wp-ai-agent' ) ) );
    }
    wp_send_json_success( array( 'html' => $html ) );
}

/**
 * AJAX: delete a conversation (all rows for the session).
 */
add_action( 'wp_ajax_wpaia_conv_delete', 'wp_ai_agent_ajax_conv_delete' );
function wp_ai_agent_ajax_conv_delete() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-agent' ) ), 403 );
    }
    check_ajax_referer( 'wpaia_conv' );
    $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
    if ( '' === $session_id ) {
        wp_send_json_error( array( 'message' => __( 'Missing session.', 'wp-ai-agent' ) ) );
    }
    $deleted = wp_ai_agent_delete_session_conversations( $session_id );
    if ( function_exists( 'wp_ai_agent_clear_state' ) ) {
        wp_ai_agent_clear_state( $session_id );
    }
    wp_send_json_success( array( 'deleted' => (int) $deleted ) );
}

/**
 * AJAX: archive / unarchive a conversation.
 */
add_action( 'wp_ajax_wpaia_conv_archive', 'wp_ai_agent_ajax_conv_archive' );
function wp_ai_agent_ajax_conv_archive() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-agent' ) ), 403 );
    }
    check_ajax_referer( 'wpaia_conv' );
    $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
    $status     = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'archived';
    if ( '' === $session_id ) {
        wp_send_json_error( array( 'message' => __( 'Missing session.', 'wp-ai-agent' ) ) );
    }
    wp_ai_agent_set_session_status( $session_id, $status );
    wp_send_json_success( array( 'status' => $status ) );
}

/**
 * admin-post: export one conversation as JSON or CSV (file download).
 */
add_action( 'admin_post_wpaia_conv_export', 'wp_ai_agent_conv_export' );
function wp_ai_agent_conv_export() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    $session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
    check_admin_referer( 'wpaia_conv_export_' . $session_id );
    $format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'json';

    $rows = wp_ai_agent_conversation_thread( $session_id );
    $meta = wp_ai_agent_conversation_meta( $session_id );
    $id   = wp_ai_agent_conv_identity( $meta ? $meta->user_id : 0, $session_id );
    $slug = sanitize_file_name( 'conversation-' . substr( $session_id, 0, 16 ) . '-' . gmdate( 'Y-m-d' ) );

    if ( 'csv' === $format ) {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $slug . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Time', 'User message', 'AI response', 'Page URL', 'Response ms' ) );
        foreach ( $rows as $r ) {
            fputcsv( $out, array( $r->created_at, $r->user_message, $r->bot_message, $r->page_url, $r->response_ms ) );
        }
        fclose( $out );
        exit;
    }

    // JSON.
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $slug . '.json' );
    $payload = array(
        'session_id' => $session_id,
        'user'       => array( 'type' => $id['type'], 'name' => $id['name'], 'email' => $id['email'], 'id' => $id['id'] ),
        'meta'       => $meta ? array(
            'message_count' => (int) $meta->msg_count,
            'started'       => $meta->started,
            'last_active'   => $meta->last_active,
            'status'        => $meta->status,
            'ip_address'    => $meta->ip_address,
            'user_agent'    => $meta->user_agent,
            'page_url'      => $meta->page_url,
        ) : array(),
        'messages'   => array_map( function ( $r ) {
            return array(
                'time' => $r->created_at,
                'user' => $r->user_message,
                'bot'  => $r->bot_message,
                'page' => $r->page_url,
            );
        }, $rows ),
    );
    echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    exit;
}

/**
 * admin-post: printable (PDF-ready) view of one conversation.
 */
add_action( 'admin_post_wpaia_conv_print', 'wp_ai_agent_conv_print' );
function wp_ai_agent_conv_print() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    $session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
    check_admin_referer( 'wpaia_conv_print_' . $session_id );

    $rows = wp_ai_agent_conversation_thread( $session_id );
    $meta = wp_ai_agent_conversation_meta( $session_id );
    $id   = wp_ai_agent_conv_identity( $meta ? $meta->user_id : 0, $session_id );

    header( 'Content-Type: text/html; charset=utf-8' );
    ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title><?php echo esc_html( sprintf( /* translators: %s: user name. */ __( 'Conversation with %s', 'wp-ai-agent' ), $id['name'] ) ); ?></title>
<style>
    body { font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color:#1d2327; max-width:720px; margin:24px auto; padding:0 16px; }
    h1 { font-size:20px; margin:0 0 4px; }
    .meta { color:#646970; font-size:12px; margin-bottom:18px; border-bottom:1px solid #e3e6ea; padding-bottom:12px; }
    .msg { margin:10px 0; }
    .who { font-size:11px; font-weight:700; text-transform:uppercase; color:#8c9196; }
    .user .bubble { background:#2271b1; color:#fff; }
    .bot .bubble { background:#f0f2f5; color:#1d2327; }
    .bubble { display:inline-block; padding:8px 12px; border-radius:12px; font-size:13px; line-height:1.5; margin-top:2px; max-width:100%; white-space:pre-wrap; word-wrap:break-word; }
    .user { text-align:right; }
    .time { font-size:10px; color:#a7aaad; margin-top:2px; }
    @media print { .noprint { display:none; } }
</style>
</head>
<body onload="window.print()">
    <div class="noprint" style="text-align:right;margin-bottom:10px;">
        <button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'wp-ai-agent' ); ?></button>
    </div>
    <h1><?php echo esc_html( $id['name'] ); ?></h1>
    <div class="meta">
        <?php
        if ( '' !== $id['email'] ) {
            echo esc_html( $id['email'] ) . ' &middot; ';
        }
        echo esc_html( 'logged' === $id['type'] ? __( 'Logged-in user', 'wp-ai-agent' ) : __( 'Guest', 'wp-ai-agent' ) );
        if ( $meta ) {
            echo ' &middot; ' . esc_html( sprintf( /* translators: %s: count. */ _n( '%s message', '%s messages', (int) $meta->msg_count, 'wp-ai-agent' ), number_format_i18n( (int) $meta->msg_count ) ) );
            echo ' &middot; ' . esc_html( wp_ai_agent_conv_datetime( $meta->started ) );
        }
        ?>
    </div>
    <?php
    foreach ( $rows as $r ) {
        $time = wp_ai_agent_conv_datetime( $r->created_at );
        $user = wp_ai_agent_conv_user_display( $r->user_message );
        if ( '' !== trim( (string) $user ) ) {
            echo '<div class="msg user"><div class="who">' . esc_html__( 'User', 'wp-ai-agent' ) . '</div><div class="bubble">' . esc_html( $user ) . '</div><div class="time">' . esc_html( $time ) . '</div></div>';
        }
        if ( '' !== trim( (string) $r->bot_message ) ) {
            echo '<div class="msg bot"><div class="who">' . esc_html__( 'AI', 'wp-ai-agent' ) . '</div><div class="bubble">' . esc_html( $r->bot_message ) . '</div><div class="time">' . esc_html( $time ) . '</div></div>';
        }
    }
    ?>
</body>
</html>
    <?php
    exit;
}
