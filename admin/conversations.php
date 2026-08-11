<?php
/**
 * Admin: Conversations browser.
 *
 * View, search, paginate, delete (single + bulk), and export the
 * wp_ai_conversations log. Built on wp_ai_agent_get_conversations() so it
 * benefits from the (session_id, page_url) index and server-side pagination.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handle a single-row delete (GET link with nonce).
 */
add_action( 'admin_post_wp_ai_agent_delete_conversation', 'wp_ai_agent_handle_delete_conversation' );
function wp_ai_agent_handle_delete_conversation() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
    check_admin_referer( 'wp_ai_agent_delete_conversation_' . $id );

    wp_ai_agent_delete_conversation( $id );

    wp_safe_redirect( wp_ai_agent_conversations_redirect_url( array( 'deleted' => 1 ) ) );
    exit;
}

/**
 * Handle a bulk delete (POST form with ids[]).
 */
add_action( 'admin_post_wp_ai_agent_bulk_delete_conversations', 'wp_ai_agent_handle_bulk_delete_conversations' );
function wp_ai_agent_handle_bulk_delete_conversations() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    check_admin_referer( 'wp_ai_agent_bulk_delete_conversations' );

    $ids     = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
    $deleted = wp_ai_agent_delete_conversations( $ids );

    $redirect = array( 'deleted' => $deleted );
    if ( ! empty( $_POST['s'] ) ) {
        $redirect['s'] = sanitize_text_field( wp_unslash( $_POST['s'] ) );
    }
    if ( ! empty( $_POST['paged'] ) ) {
        $redirect['paged'] = (int) $_POST['paged'];
    }

    wp_safe_redirect( wp_ai_agent_conversations_redirect_url( $redirect ) );
    exit;
}

/**
 * Build a URL back to the Conversations screen with extra query args.
 *
 * @param array $extra Query args to merge in.
 * @return string
 */
function wp_ai_agent_conversations_redirect_url( $extra = array() ) {
    $args = array_merge( array( 'page' => 'wp-ai-agent-conversations' ), $extra );
    return admin_url( 'admin.php?' . http_build_query( $args ) );
}

/**
 * Render the Conversations admin screen.
 */
function wp_ai_agent_admin_conversations_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $session  = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $per_page = (int) apply_filters( 'wp_ai_agent_conversations_per_page', 20 );

    $result = wp_ai_agent_get_conversations( array(
        'search'     => $search,
        'session_id' => $session,
        'per_page'   => $per_page,
        'page'       => $paged,
        'orderby'    => 'id',
        'order'      => 'DESC',
    ) );

    $rows  = $result['rows'];
    $total = $result['total'];
    $pages = $result['pages'];
    $home  = home_url();

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Conversations', 'wp-ai-agent' ) . '</h1>';

    // Export (honors the current search filter; range=0 = all time so the
    // analytics date-range default never silently truncates this export).
    $export_args = array( 'action' => 'wp_ai_agent_export_csv', 'range' => '0' );
    if ( '' !== $search ) {
        $export_args['s'] = $search;
    }
    if ( '' !== $session ) {
        $export_args['session_id'] = $session;
    }
    $export_url = wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( $export_args ) ), 'wp_ai_agent_export_csv' );
    echo ' <a class="page-title-action" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'wp-ai-agent' ) . '</a>';
    echo '<hr class="wp-header-end" />';

    // Notices.
    if ( isset( $_GET['deleted'] ) ) {
        $n = (int) $_GET['deleted'];
        echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
            /* translators: %s: number of deleted rows. */
            esc_html( _n( '%s conversation deleted.', '%s conversations deleted.', $n, 'wp-ai-agent' ) ),
            esc_html( number_format_i18n( $n ) )
        ) . '</p></div>';
    }

    if ( '' !== $session ) {
        echo '<div class="notice notice-info"><p>' . sprintf(
            /* translators: %s: session id. */
            esc_html__( 'Filtering by session: %s', 'wp-ai-agent' ),
            '<code>' . esc_html( $session ) . '</code>'
        ) . ' <a href="' . esc_url( wp_ai_agent_conversations_redirect_url() ) . '">' . esc_html__( 'Clear', 'wp-ai-agent' ) . '</a></p></div>';
    }

    // Search box.
    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="wp-ai-agent-conversations" />';
    if ( '' !== $session ) {
        echo '<input type="hidden" name="session_id" value="' . esc_attr( $session ) . '" />';
    }
    echo '<p class="search-box">';
    echo '<label class="screen-reader-text" for="wpaia-conv-search">' . esc_html__( 'Search conversations', 'wp-ai-agent' ) . '</label>';
    echo '<input type="search" id="wpaia-conv-search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search message, page, or session…', 'wp-ai-agent' ) . '" style="width:320px;" />';
    echo '<input type="submit" class="button" value="' . esc_attr__( 'Search', 'wp-ai-agent' ) . '" />';
    if ( '' !== $search ) {
        echo ' <a class="button" href="' . esc_url( wp_ai_agent_conversations_redirect_url( '' !== $session ? array( 'session_id' => $session ) : array() ) ) . '">' . esc_html__( 'Reset', 'wp-ai-agent' ) . '</a>';
    }
    echo '</p>';
    echo '</form>';

    // Result count + top pagination.
    echo '<div class="tablenav top"><div class="tablenav-pages">';
    echo '<span class="displaying-num">' . sprintf(
        /* translators: %s: item count. */
        esc_html( _n( '%s item', '%s items', $total, 'wp-ai-agent' ) ),
        esc_html( number_format_i18n( $total ) )
    ) . '</span>';
    wp_ai_agent_conversations_pagination( $pages, $paged, $search, $session );
    echo '</div></div>';

    if ( empty( $rows ) ) {
        echo '<p>' . esc_html__( 'No conversations found.', 'wp-ai-agent' ) . '</p></div>';
        return;
    }

    // Bulk-delete form wrapping the table.
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Delete the selected conversations?', 'wp-ai-agent' ) ) . '\');">';
    echo '<input type="hidden" name="action" value="wp_ai_agent_bulk_delete_conversations" />';
    echo '<input type="hidden" name="s" value="' . esc_attr( $search ) . '" />';
    echo '<input type="hidden" name="paged" value="' . esc_attr( $paged ) . '" />';
    wp_nonce_field( 'wp_ai_agent_bulk_delete_conversations' );

    echo '<div class="tablenav top"><div class="alignleft actions"><input type="submit" class="button action" value="' . esc_attr__( 'Delete selected', 'wp-ai-agent' ) . '" /></div></div>';

    echo '<table class="widefat striped fixed">';
    echo '<thead><tr>';
    echo '<td class="check-column"><input type="checkbox" onclick="var c=this.checked,b=document.querySelectorAll(\'.wpaia-conv-cb\');for(var i=0;i<b.length;i++)b[i].checked=c;" /></td>';
    echo '<th style="width:140px;">' . esc_html__( 'When', 'wp-ai-agent' ) . '</th>';
    echo '<th>' . esc_html__( 'User message', 'wp-ai-agent' ) . '</th>';
    echo '<th>' . esc_html__( 'AI response', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:130px;">' . esc_html__( 'Page', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:110px;">' . esc_html__( 'Session', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:70px;">' . esc_html__( 'Action', 'wp-ai-agent' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $rows as $row ) {
        $label = str_replace( $home, '', $row->page_url );
        $label = ( '' === $label ) ? '/' : $label;

        $del_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wp_ai_agent_delete_conversation&id=' . (int) $row->id ),
            'wp_ai_agent_delete_conversation_' . (int) $row->id
        );
        $session_filter = wp_ai_agent_conversations_redirect_url( array( 'session_id' => $row->session_id ) );

        echo '<tr>';
        echo '<th scope="row" class="check-column"><input type="checkbox" class="wpaia-conv-cb" name="ids[]" value="' . esc_attr( $row->id ) . '" /></th>';
        echo '<td>' . esc_html( $row->created_at ) . '</td>';
        echo '<td>' . esc_html( $row->user_message ) . '</td>';
        echo '<td>' . esc_html( wp_trim_words( $row->bot_message, 45, '…' ) ) . '</td>';
        echo '<td><a href="' . esc_url( $row->page_url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></td>';
        echo '<td><a href="' . esc_url( $session_filter ) . '" title="' . esc_attr__( 'View this session', 'wp-ai-agent' ) . '"><code>' . esc_html( substr( (string) $row->session_id, 0, 12 ) ) . '…</code></a></td>';
        echo '<td><a class="button button-small" style="color:#b32d2e;" onclick="return confirm(\'' . esc_js( __( 'Delete this conversation?', 'wp-ai-agent' ) ) . '\');" href="' . esc_url( $del_url ) . '">' . esc_html__( 'Delete', 'wp-ai-agent' ) . '</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</form>';

    // Bottom pagination.
    echo '<div class="tablenav bottom"><div class="tablenav-pages">';
    wp_ai_agent_conversations_pagination( $pages, $paged, $search, $session );
    echo '</div></div>';

    echo '</div>'; // .wrap
}

/**
 * Render pagination links for the Conversations screen.
 *
 * @param int    $pages   Total pages.
 * @param int    $current Current page.
 * @param string $search  Active search term.
 * @param string $session Active session filter.
 */
function wp_ai_agent_conversations_pagination( $pages, $current, $search = '', $session = '' ) {
    if ( $pages < 2 ) {
        return;
    }

    $base_args = array( 'page' => 'wp-ai-agent-conversations' );
    if ( '' !== $search ) {
        $base_args['s'] = $search;
    }
    if ( '' !== $session ) {
        $base_args['session_id'] = $session;
    }
    $base = admin_url( 'admin.php?' . http_build_query( $base_args ) ) . '&paged=%#%';

    $links = paginate_links( array(
        'base'      => $base,
        'format'    => '',
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
        'total'     => $pages,
        'current'   => $current,
        'type'      => 'plain',
    ) );

    if ( $links ) {
        // paginate_links() output is already escaped markup from core.
        echo '<span class="pagination-links">' . wp_kses_post( $links ) . '</span>';
    }
}
