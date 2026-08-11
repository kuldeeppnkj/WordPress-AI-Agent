<?php
/**
 * AI Agent admin screens: Leads, Bookings, Orders, Support Tickets.
 *
 * Conversations, Analytics, Settings, and Training have their own pages; these
 * cover the action data the agent now captures.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared pagination renderer for the agent list screens.
 *
 * @param string $page    Admin page slug.
 * @param int    $pages   Total pages.
 * @param int    $current Current page.
 */
function wp_ai_agent_admin_pagination( $page, $pages, $current ) {
    if ( $pages < 2 ) {
        return;
    }
    $links = paginate_links( array(
        'base'    => admin_url( 'admin.php?page=' . $page ) . '&paged=%#%',
        'format'  => '',
        'total'   => $pages,
        'current' => $current,
        'type'    => 'plain',
    ) );
    if ( $links ) {
        echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
    }
}

/* ----------------------------------------------------------------------- */

/**
 * Handle a lead status update (per-row select).
 */
add_action( 'admin_post_wp_ai_agent_lead_update_status', 'wp_ai_agent_handle_lead_status' );
function wp_ai_agent_handle_lead_status() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    $id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;
    check_admin_referer( 'wp_ai_agent_lead_status_' . $id );

    $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
    wp_ai_agent_update_lead_status( $id, $status );

    $args = array( 'page' => 'wp-ai-agent-leads', 'updated' => 1 );
    foreach ( array( 's', 'status', 'paged' ) as $k ) {
        if ( ! empty( $_POST[ $k ] ) ) {
            $args[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
        }
    }
    wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $args ) ) );
    exit;
}

/**
 * Export leads (CSV), honoring the active search + status filter.
 */
add_action( 'admin_post_wp_ai_agent_export_leads', 'wp_ai_agent_export_leads_csv' );
function wp_ai_agent_export_leads_csv() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    check_admin_referer( 'wp_ai_agent_export_leads' );

    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=ai-leads-' . gmdate( 'Y-m-d' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'id', 'name', 'email', 'phone', 'message', 'lead_source', 'page_url', 'session_id', 'lead_status', 'score', 'created_at' ) );

    $result = wp_ai_agent_get_leads( array( 'search' => $search, 'status' => $status, 'per_page' => 100000, 'page' => 1 ) );
    foreach ( $result['rows'] as $row ) {
        fputcsv( $out, array( $row->id, $row->name, $row->email, $row->phone, $row->message, $row->lead_source, $row->page_url, $row->session_id, $row->lead_status, $row->score, $row->created_at ) );
    }
    fclose( $out ); // phpcs:ignore
    exit;
}

/**
 * AI Leads dashboard: view, search, filter, update status, export.
 */
function wp_ai_agent_admin_leads_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $statuses = wp_ai_agent_lead_statuses();

    $result = wp_ai_agent_get_leads( array( 'search' => $search, 'status' => $status, 'per_page' => 20, 'page' => $paged ) );
    $home   = home_url();

    echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'AI Leads', 'wp-ai-agent' ) . '</h1>';

    // Export link (preserves filters).
    $export_args = array( 'action' => 'wp_ai_agent_export_leads' );
    if ( '' !== $search ) {
        $export_args['s'] = $search;
    }
    if ( '' !== $status ) {
        $export_args['status'] = $status;
    }
    echo ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( $export_args ) ), 'wp_ai_agent_export_leads' ) ) . '">' . esc_html__( 'Export CSV', 'wp-ai-agent' ) . '</a>';
    echo '<hr class="wp-header-end" />';

    if ( isset( $_GET['updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Lead status updated.', 'wp-ai-agent' ) . '</p></div>';
    }

    // Search + status filter toolbar.
    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="wp-ai-agent-leads" />';
    echo '<select name="status" onchange="this.form.submit()"><option value="">' . esc_html__( 'All statuses', 'wp-ai-agent' ) . '</option>';
    foreach ( $statuses as $slug => $label ) {
        echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $status, $slug, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select> ';
    echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name, email, phone…', 'wp-ai-agent' ) . '" style="width:260px;" /> ';
    echo '<input type="submit" class="button" value="' . esc_attr__( 'Search', 'wp-ai-agent' ) . '" />';
    if ( '' !== $search || '' !== $status ) {
        echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=wp-ai-agent-leads' ) ) . '">' . esc_html__( 'Reset', 'wp-ai-agent' ) . '</a>';
    }
    echo '</form>';

    echo '<p class="description">' . sprintf( esc_html__( '%s leads.', 'wp-ai-agent' ), esc_html( number_format_i18n( $result['total'] ) ) ) . '</p>';

    if ( empty( $result['rows'] ) ) {
        echo '<p>' . esc_html__( 'No leads found.', 'wp-ai-agent' ) . '</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . esc_html__( 'Name', 'wp-ai-agent' ) . '</th>';
    echo '<th>' . esc_html__( 'Contact', 'wp-ai-agent' ) . '</th>';
    echo '<th>' . esc_html__( 'Requirement', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:70px;">' . esc_html__( 'Score', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:90px;">' . esc_html__( 'Source', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:150px;">' . esc_html__( 'Status', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:140px;">' . esc_html__( 'When', 'wp-ai-agent' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $result['rows'] as $row ) {
        $score = (int) ( isset( $row->score ) ? $row->score : 0 );
        if ( $score >= 75 ) {
            $score_color = '#2e9e5b';
            $score_lbl   = __( 'High', 'wp-ai-agent' );
        } elseif ( $score >= 50 ) {
            $score_color = '#e8740c';
            $score_lbl   = __( 'Medium', 'wp-ai-agent' );
        } else {
            $score_color = '#6b7280';
            $score_lbl   = __( 'Low', 'wp-ai-agent' );
        }

        $cur_status = isset( $row->lead_status ) ? $row->lead_status : 'new';
        $page_label = isset( $row->page_url ) ? str_replace( $home, '', $row->page_url ) : '';

        echo '<tr>';
        echo '<td>' . esc_html( $row->name ) . '</td>';
        echo '<td>';
        if ( $row->email ) {
            echo '<a href="mailto:' . esc_attr( $row->email ) . '">' . esc_html( $row->email ) . '</a><br>';
        }
        echo esc_html( $row->phone );
        echo '</td>';
        echo '<td>' . esc_html( wp_trim_words( $row->message, 18, '…' ) ) . '</td>';
        echo '<td><strong style="color:' . esc_attr( $score_color ) . ';">' . esc_html( $score ) . '</strong><br><small style="color:' . esc_attr( $score_color ) . ';">' . esc_html( $score_lbl ) . '</small></td>';
        echo '<td>' . esc_html( isset( $row->lead_source ) ? $row->lead_source : 'chat' ) . '</td>';

        // Status update form.
        echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
        echo '<input type="hidden" name="action" value="wp_ai_agent_lead_update_status" />';
        echo '<input type="hidden" name="lead_id" value="' . esc_attr( $row->id ) . '" />';
        echo '<input type="hidden" name="s" value="' . esc_attr( $search ) . '" />';
        echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '" />';
        echo '<input type="hidden" name="paged" value="' . esc_attr( $paged ) . '" />';
        wp_nonce_field( 'wp_ai_agent_lead_status_' . (int) $row->id );
        echo '<select name="status" onchange="this.form.submit()">';
        foreach ( $statuses as $slug => $label ) {
            echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $cur_status, $slug, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></form></td>';

        echo '<td>' . esc_html( $row->created_at );
        if ( '' !== $page_label && '/' !== $page_label ) {
            echo '<br><small><a href="' . esc_url( $row->page_url ) . '" target="_blank" rel="noopener">' . esc_html( $page_label ) . '</a></small>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
                                             
    // Pagination preserving filters.
    if ( $result['pages'] > 1 ) {
        $base_args = array( 'page' => 'wp-ai-agent-leads' );
        if ( '' !== $search ) {
            $base_args['s'] = $search;
        }
        if ( '' !== $status ) {
            $base_args['status'] = $status;
        }
        $links = paginate_links( array(
            'base'    => admin_url( 'admin.php?' . http_build_query( $base_args ) ) . '&paged=%#%',
            'format'  => '',
            'total'   => $result['pages'],
            'current' => $paged,
            'type'    => 'plain',
        ) );
        if ( $links ) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
        }
    }

    echo '</div>';
}

/* ----------------------------------------------------------------------- */

/**
 * Handle a booking status update (per-row select).
 */
add_action( 'admin_post_wp_ai_agent_booking_update_status', 'wp_ai_agent_handle_booking_status' );
function wp_ai_agent_handle_booking_status() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    $id = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;
    check_admin_referer( 'wp_ai_agent_booking_status_' . $id );

    $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
    wp_ai_agent_update_booking_status( $id, $status );

    $args = array( 'page' => 'wp-ai-agent-bookings', 'updated' => 1 );
    foreach ( array( 's', 'status', 'paged' ) as $k ) {
        if ( ! empty( $_POST[ $k ] ) ) {
            $args[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
        }
    }
    wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $args ) ) );
    exit;
}

/**
 * Export bookings (CSV), honoring the active search + status filter.
 */
add_action( 'admin_post_wp_ai_agent_export_bookings', 'wp_ai_agent_export_bookings_csv' );
function wp_ai_agent_export_bookings_csv() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'wp-ai-agent' ) );
    }
    check_admin_referer( 'wp_ai_agent_export_bookings' );

    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=ai-bookings-' . gmdate( 'Y-m-d' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'id', 'name', 'email', 'phone', 'booking_date', 'booking_time', 'status', 'session_id', 'created_at' ) );

    $result = wp_ai_agent_get_bookings( array( 'search' => $search, 'status' => $status, 'per_page' => 100000, 'page' => 1 ) );
    foreach ( $result['rows'] as $row ) {
        fputcsv( $out, array( $row->id, $row->name, $row->email, $row->phone, $row->booking_date, $row->booking_time, $row->status, $row->session_id, $row->created_at ) );
    }
    fclose( $out ); // phpcs:ignore
    exit;
}

/**
 * AI Bookings dashboard: view, search, filter, update status, export.
 */
function wp_ai_agent_admin_bookings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $statuses = wp_ai_agent_booking_statuses();

    $result = wp_ai_agent_get_bookings( array( 'search' => $search, 'status' => $status, 'per_page' => 20, 'page' => $paged ) );

    echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'AI Bookings', 'wp-ai-agent' ) . '</h1>';

    $export_args = array( 'action' => 'wp_ai_agent_export_bookings' );
    if ( '' !== $search ) {
        $export_args['s'] = $search;
    }
    if ( '' !== $status ) {
        $export_args['status'] = $status;
    }
    echo ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( $export_args ) ), 'wp_ai_agent_export_bookings' ) ) . '">' . esc_html__( 'Export CSV', 'wp-ai-agent' ) . '</a>';
    echo '<hr class="wp-header-end" />';

    if ( isset( $_GET['updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Booking status updated.', 'wp-ai-agent' ) . '</p></div>';
    }

    // Search + status filter toolbar.
    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="wp-ai-agent-bookings" />';
    echo '<select name="status" onchange="this.form.submit()"><option value="">' . esc_html__( 'All statuses', 'wp-ai-agent' ) . '</option>';
    foreach ( $statuses as $slug => $label ) {
        echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $status, $slug, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select> ';
    echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name, email, phone…', 'wp-ai-agent' ) . '" style="width:260px;" /> ';
    echo '<input type="submit" class="button" value="' . esc_attr__( 'Search', 'wp-ai-agent' ) . '" />';
    if ( '' !== $search || '' !== $status ) {
        echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=wp-ai-agent-bookings' ) ) . '">' . esc_html__( 'Reset', 'wp-ai-agent' ) . '</a>';
    }
    echo '</form>';

    echo '<p class="description">' . sprintf( esc_html__( '%s bookings.', 'wp-ai-agent' ), esc_html( number_format_i18n( $result['total'] ) ) ) . '</p>';

    if ( empty( $result['rows'] ) ) {
        echo '<p>' . esc_html__( 'No bookings found.', 'wp-ai-agent' ) . '</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . esc_html__( 'Name', 'wp-ai-agent' ) . '</th>';
    echo '<th>' . esc_html__( 'Contact', 'wp-ai-agent' ) . '</th>';
    echo '<th>' . esc_html__( 'Date', 'wp-ai-agent' ) . '</th>';
    echo '<th>' . esc_html__( 'Time', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:150px;">' . esc_html__( 'Status', 'wp-ai-agent' ) . '</th>';
    echo '<th style="width:140px;">' . esc_html__( 'Booked', 'wp-ai-agent' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $result['rows'] as $row ) {
        $cur_status = isset( $row->status ) ? $row->status : 'pending';
        echo '<tr>';
        echo '<td>' . esc_html( $row->name ) . '</td>';
        echo '<td>';
        if ( $row->email ) {
            echo '<a href="mailto:' . esc_attr( $row->email ) . '">' . esc_html( $row->email ) . '</a><br>';
        }
        echo esc_html( $row->phone );
        echo '</td>';
        echo '<td>' . esc_html( $row->booking_date ) . '</td>';
        echo '<td>' . esc_html( $row->booking_time ) . '</td>';

        // Status update form.
        echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
        echo '<input type="hidden" name="action" value="wp_ai_agent_booking_update_status" />';
        echo '<input type="hidden" name="booking_id" value="' . esc_attr( $row->id ) . '" />';
        echo '<input type="hidden" name="s" value="' . esc_attr( $search ) . '" />';
        echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '" />';
        echo '<input type="hidden" name="paged" value="' . esc_attr( $paged ) . '" />';
        wp_nonce_field( 'wp_ai_agent_booking_status_' . (int) $row->id );
        echo '<select name="status" onchange="this.form.submit()">';
        foreach ( $statuses as $slug => $label ) {
            echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $cur_status, $slug, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></form></td>';

        echo '<td>' . esc_html( $row->created_at ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    if ( $result['pages'] > 1 ) {
        $base_args = array( 'page' => 'wp-ai-agent-bookings' );
        if ( '' !== $search ) {
            $base_args['s'] = $search;
        }
        if ( '' !== $status ) {
            $base_args['status'] = $status;
        }
        $links = paginate_links( array(
            'base'    => admin_url( 'admin.php?' . http_build_query( $base_args ) ) . '&paged=%#%',
            'format'  => '',
            'total'   => $result['pages'],
            'current' => $paged,
            'type'    => 'plain',
        ) );
        if ( $links ) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
        }
    }

    echo '</div>';
}

/* ----------------------------------------------------------------------- */

/**
 * Support tickets screen.
 */
function wp_ai_agent_admin_tickets_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $result = wp_ai_agent_fetch_rows( wp_ai_agent_tickets_table(), 20, $paged );

    echo '<div class="wrap"><h1>' . esc_html__( 'Support Tickets', 'wp-ai-agent' ) . '</h1>';
    echo '<p class="description">' . esc_html__( 'Tickets raised by visitors through the AI agent.', 'wp-ai-agent' ) . '</p>';

    if ( empty( $result['rows'] ) ) {
        echo '<p>' . esc_html__( 'No tickets yet.', 'wp-ai-agent' ) . '</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th style="width:120px;">' . esc_html__( 'Ticket #', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Email', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Subject', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Message', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Status', 'wp-ai-agent' ) . '</th><th style="width:150px;">' . esc_html__( 'When', 'wp-ai-agent' ) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ( $result['rows'] as $row ) {
        echo '<tr>';
        echo '<td><code>' . esc_html( $row->ticket_number ) . '</code></td>';
        echo '<td>' . ( $row->email ? '<a href="mailto:' . esc_attr( $row->email ) . '">' . esc_html( $row->email ) . '</a>' : '' ) . '</td>';
        echo '<td>' . esc_html( $row->subject ) . '</td>';
        echo '<td>' . esc_html( wp_trim_words( $row->message, 25, '…' ) ) . '</td>';
        echo '<td>' . esc_html( ucfirst( $row->status ) ) . '</td>';
        echo '<td>' . esc_html( $row->created_at ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    wp_ai_agent_admin_pagination( 'wp-ai-agent-tickets', $result['pages'], $paged );
    echo '</div>';
}

/* ----------------------------------------------------------------------- */

/**
 * Orders screen — recent WooCommerce orders, with a quick lookup box.
 */
function wp_ai_agent_admin_orders_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    echo '<div class="wrap"><h1>' . esc_html__( 'Orders', 'wp-ai-agent' ) . '</h1>';

    // Order-tracking request log (works even without WooCommerce orders shown).
    wp_ai_agent_render_order_tracking_log();

    if ( ! function_exists( 'wc_get_orders' ) ) {
        echo '<p>' . esc_html__( 'WooCommerce is not active, so there are no orders to track.', 'wp-ai-agent' ) . '</p></div>';
        return;
    }

    echo '<h2>' . esc_html__( 'Recent Orders', 'wp-ai-agent' ) . '</h2>';
    echo '<p class="description">' . esc_html__( 'Recent WooCommerce orders. The AI agent answers visitor "where is my order" questions from this data.', 'wp-ai-agent' ) . '</p>';

    $orders = wc_get_orders( array( 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );
    if ( empty( $orders ) ) {
        echo '<p>' . esc_html__( 'No orders found.', 'wp-ai-agent' ) . '</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th style="width:90px;">' . esc_html__( 'Order', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Customer', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Status', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Total', 'wp-ai-agent' ) . '</th><th style="width:160px;">' . esc_html__( 'Date', 'wp-ai-agent' ) . '</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ( $orders as $order ) {
        $edit = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : '';
        echo '<tr>';
        echo '<td>#' . esc_html( $order->get_order_number() ) . '</td>';
        echo '<td>' . esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) . '</td>';
        echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
        echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
        echo '<td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td>';
        echo '<td>' . ( $edit ? '<a class="button button-small" href="' . esc_url( $edit ) . '">' . esc_html__( 'View', 'wp-ai-agent' ) . '</a>' : '' ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * Render the AI agent's order-tracking request log (order #, session, result,
 * timestamp) so admins can see what visitors looked up.
 */
function wp_ai_agent_render_order_tracking_log() {
    if ( ! function_exists( 'wp_ai_agent_order_logs_table' ) ) {
        return;
    }
    $paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $result = wp_ai_agent_fetch_rows( wp_ai_agent_order_logs_table(), 20, $paged );

    echo '<h2>' . esc_html__( 'Order Tracking Requests', 'wp-ai-agent' ) . '</h2>';
    echo '<p class="description">' . esc_html__( 'Order lookups made by visitors through the AI agent.', 'wp-ai-agent' ) . '</p>';

    if ( empty( $result['rows'] ) ) {
        echo '<p>' . esc_html__( 'No tracking requests logged yet.', 'wp-ai-agent' ) . '</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . esc_html__( 'Order #', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Result', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Status', 'wp-ai-agent' ) . '</th><th>' . esc_html__( 'Session', 'wp-ai-agent' ) . '</th><th style="width:150px;">' . esc_html__( 'When', 'wp-ai-agent' ) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ( $result['rows'] as $row ) {
        echo '<tr>';
        echo '<td>' . esc_html( $row->order_number ) . '</td>';
        echo '<td>' . ( $row->found ? esc_html__( 'Found', 'wp-ai-agent' ) : '<span style="color:#b32d2e;">' . esc_html__( 'Not found', 'wp-ai-agent' ) . '</span>' ) . '</td>';
        echo '<td>' . esc_html( $row->status ) . '</td>';
        echo '<td><code>' . esc_html( substr( (string) $row->session_id, 0, 12 ) ) . '…</code></td>';
        echo '<td>' . esc_html( $row->created_at ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
