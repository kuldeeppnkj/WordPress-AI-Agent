<?php
/**
 * User Authentication & Personalized AI layer.
 *
 * Adds chat-based login / registration / logout (using WordPress auth APIs),
 * recognizes the logged-in user, personalizes replies by name, and gates
 * account-specific features (order tracking, bookings, account info) behind
 * login so a guest can never see another user's data.
 *
 * Security notes:
 *  - Login uses wp_authenticate(); accounts are created with wp_insert_user().
 *  - The auth cookie is set with wp_set_auth_cookie() (HTTPS strongly advised).
 *  - Passwords are NEVER stored in conversation state or written to the log
 *    (tool responses carry a 'log_user' => '[hidden]' override).
 *  - Account data is read only for the CURRENT user; orders are matched by
 *    customer_id, bookings by the user's email.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * User context.
 * ---------------------------------------------------------------------- */

/**
 * The current visitor's identity.
 *
 * @return array{logged_in:bool,id:int,name:string,email:string,roles:string[],first:string}
 */
function wp_ai_agent_user() {
    $u = wp_get_current_user();
    if ( $u && $u->ID ) {
        $name = $u->display_name ? $u->display_name : $u->user_login;
        return array(
            'logged_in' => true,
            'id'        => (int) $u->ID,
            'name'      => $name,
            'email'     => $u->user_email,
            'roles'     => (array) $u->roles,
            'first'     => $u->first_name ? $u->first_name : strtok( $name, ' ' ),
        );
    }
    return array( 'logged_in' => false, 'id' => 0, 'name' => '', 'email' => '', 'roles' => array(), 'first' => '' );
}

/**
 * First name (or '') of the logged-in user, for natural personalization.
 *
 * @return string
 */
function wp_ai_agent_user_first_name() {
    $u = wp_ai_agent_user();
    return $u['logged_in'] ? $u['first'] : '';
}

/**
 * A "please log in" response for a gated feature.
 *
 * @param string $what What they tried to access (e.g. "your orders").
 * @return array
 */
function wp_ai_agent_login_required_response( $what ) {
    $msg = sprintf( __( '🔒 Please login to access %s.', 'wp-ai-agent' ), $what );
    return wp_ai_agent_tool_response( $msg, array(
        'source'  => 'auth',
        'intent'  => 'login_required',
        'matched' => true,
        'data'    => array(
            'auth_required' => true,
            // Clickable reply buttons shown under the message in the chat.
            'actions'       => array(
                array( 'label' => __( '🔐 Login', 'wp-ai-agent' ), 'query' => 'login' ),
                array( 'label' => __( '📝 Register', 'wp-ai-agent' ), 'query' => 'register' ),
            ),
        ),
    ) );
}

/* -------------------------------------------------------------------------
 * Login flow (email -> password -> authenticate).
 * ---------------------------------------------------------------------- */

/**
 * Where to send the user after login — the WooCommerce My Account page (so
 * their orders/details show) when available, else back to the current page.
 *
 * @param string $page_url Current page URL.
 * @return string
 */
function wp_ai_agent_account_redirect( $page_url ) {
    if ( function_exists( 'wc_get_page_permalink' ) ) {
        $my = wc_get_page_permalink( 'myaccount' );
        if ( $my ) {
            return $my;
        }
    }
    return $page_url ? $page_url : home_url();
}

/**
 * Find a custom CUSTOMER login / account / registration page by common slugs,
 * so visitors are sent to a front-end account page rather than wp-login.php.
 * Universal: no hardcoded URLs — it looks up whatever the site actually has.
 *
 * @param bool $register Look for a registration page first when true.
 * @return string Permalink, or '' if none found.
 */
function wp_ai_agent_find_account_page( $register = false ) {
    $slugs = $register
        ? array( 'register', 'registration', 'sign-up', 'signup', 'create-account', 'my-account', 'account', 'login' )
        : array( 'my-account', 'account', 'login', 'sign-in', 'signin', 'customer-login', 'member-login', 'my-account-2' );

    /** Allow themes/plugins to declare their custom account page slugs. */
    $slugs = (array) apply_filters( 'wp_ai_agent_account_page_slugs', $slugs, $register );

    foreach ( $slugs as $slug ) {
        $page = get_page_by_path( $slug );
        if ( $page && 'publish' === $page->post_status ) {
            return get_permalink( $page->ID );
        }
    }
    return '';
}

/**
 * Whether this website actually has a customer-facing ACCOUNT system, so the
 * assistant only ever offers "Sign in / My account / My orders" where such
 * functionality genuinely exists. A plain brochure / business site (no store, no
 * membership, no registration, no account page) has none — so those buttons are
 * hidden there.
 *
 * @return bool
 */
function wp_ai_agent_has_accounts() {
    $has = false;

    // WooCommerce provides a My Account area.
    if ( function_exists( 'wp_ai_agent_commerce_enabled' ) && wp_ai_agent_commerce_enabled() ) {
        $has = true;
    } elseif ( function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'myaccount' ) > 0 ) {
        $has = true;
    } elseif ( get_option( 'users_can_register' ) ) {
        // Registration is open to visitors.
        $has = true;
    } elseif ( '' !== wp_ai_agent_find_account_page( false ) || '' !== wp_ai_agent_find_account_page( true ) ) {
        // A dedicated login / account / registration page exists.
        $has = true;
    } elseif (
        class_exists( 'LifterLMS' ) || defined( 'LEARNDASH_VERSION' ) || function_exists( 'tutor' )
        || class_exists( 'MeprCtrlFactory' ) || class_exists( 'Paid_Memberships_Pro' )
        || class_exists( 'WC_Memberships' ) || class_exists( 'RCP_Member' )
    ) {
        // A membership / LMS plugin gives members an account area.
        $has = true;
    }

    /**
     * Filter whether the site exposes customer accounts.
     *
     * @param bool $has Whether an account system exists.
     */
    return (bool) apply_filters( 'wp_ai_agent_has_accounts', $has );
}

/**
 * The CUSTOMER login URL. Always a front-end account page — never the WordPress
 * admin login — unless nothing else exists.
 *
 * Priority: WooCommerce My Account → a custom customer login/account page →
 * (last resort) core login redirecting back to the current page, NOT wp-admin.
 *
 * @param string $page_url Current page URL.
 * @return string
 */
function wp_ai_agent_login_link( $page_url = '' ) {
    if ( function_exists( 'wc_get_page_permalink' ) ) {
        $my = wc_get_page_permalink( 'myaccount' );
        if ( $my ) {
            return $my;
        }
    }
    $custom = wp_ai_agent_find_account_page( false );
    if ( '' !== $custom ) {
        return $custom;
    }
    // Last resort: core login, redirecting back to the page they were on (never
    // to the admin dashboard).
    return wp_login_url( $page_url ? $page_url : home_url() );
}

/**
 * The CUSTOMER registration URL (WooCommerce My Account form when available,
 * else a custom registration page, else core registration). Never the admin.
 *
 * @param string $page_url Current page URL.
 * @return string
 */
function wp_ai_agent_register_link( $page_url = '' ) {
    if ( function_exists( 'wc_get_page_permalink' ) ) {
        $my = wc_get_page_permalink( 'myaccount' );
        if ( $my ) {
            return $my;
        }
    }
    $custom = wp_ai_agent_find_account_page( true );
    if ( '' !== $custom ) {
        return $custom;
    }
    if ( get_option( 'users_can_register' ) ) {
        return wp_registration_url();
    }
    return wp_ai_agent_login_link( $page_url );
}


/**
 * Admin login tool — used ONLY when a visitor explicitly asks for administrator
 * / wp-admin / dashboard access. Customers never reach this.
 *
 * @return array
 */

function wp_ai_agent_tool_admin_login() {
    $url = function_exists( 'wp_login_url' ) ? wp_login_url( admin_url() ) : admin_url();
    return wp_ai_agent_tool_response(
        __( 'If you are the site administrator, you can sign in to the WordPress dashboard here:', 'wp-ai-agent' ),
        array(
            'source' => 'auth',
            'intent' => 'admin_login',
            'data'   => array(
                'actions' => array(
                    array( 'label' => __( '🔑 Admin Login', 'wp-ai-agent' ), 'url' => $url, 'same_tab' => true ),
                ),
            ),
        )
    );
}

/**
 * Login tool: send the visitor to the website's real login page (with a
 * redirect back), instead of asking for credentials inside the chat. After
 * they log in there, their account details are available.
 *
 * @param string $page_url Current page URL (for redirect-back).
 * @return array
 */
function wp_ai_agent_tool_login( $page_url = '' ) {
    if ( is_user_logged_in() ) {
        return wp_ai_agent_tool_response( sprintf( __( "You're already logged in as %s. 😊 How can I help?", 'wp-ai-agent' ), wp_ai_agent_user_first_name() ), array( 'intent' => 'login' ) );
    }

    return wp_ai_agent_tool_response(
        __( "Sure! You can sign in to your customer account using the button below. Once you're logged in, you'll be able to view and track your orders, save addresses, and manage your profile — right here.", 'wp-ai-agent' ),
        array(
            'source' => 'auth',
            'intent' => 'login',
            'data'   => array(
                'actions' => array(
                    array( 'label' => __( '🔐 Login to My Account', 'wp-ai-agent' ), 'url' => wp_ai_agent_login_link( $page_url ), 'same_tab' => true ),
                    array( 'label' => __( '📝 Create account', 'wp-ai-agent' ), 'query' => 'register' ),
                ),
            ),
        )
    );
}

/* -------------------------------------------------------------------------
 * Registration flow (name -> email -> password -> create + auto-login).
 * ---------------------------------------------------------------------- */

/**
 * Register tool: send the visitor to the website's real registration page
 * (WooCommerce My Account when available), instead of collecting credentials in
 * the chat.
 *
 * @param string $page_url Current page URL (for redirect-back).
 * @return array
 */
function wp_ai_agent_tool_register( $page_url = '' ) {
    if ( is_user_logged_in() ) {
        return wp_ai_agent_tool_response( sprintf( __( "You're already logged in as %s. 😊", 'wp-ai-agent' ), wp_ai_agent_user_first_name() ), array( 'intent' => 'register' ) );
    }

    return wp_ai_agent_tool_response(
        __( 'Great! Tap below to create your account. Once registered, you can track orders and bookings right here.', 'wp-ai-agent' ),
        array(
            'source' => 'auth',
            'intent' => 'register',
            'data'   => array(
                'actions' => array(
                    array( 'label' => __( '📝 Register', 'wp-ai-agent' ), 'url' => wp_ai_agent_register_link( $page_url ), 'same_tab' => true ),
                ),
            ),
        )
    );
}

/* -------------------------------------------------------------------------
 * Logout / account / my-bookings.
 * ---------------------------------------------------------------------- */

/**
 * Log the visitor out.
 *
 * @return array
 */
function wp_ai_agent_tool_logout() {
    if ( ! is_user_logged_in() ) {
        return wp_ai_agent_tool_response( __( "You're not logged in.", 'wp-ai-agent' ), array( 'intent' => 'logout' ) );
    }
    wp_logout();
    return wp_ai_agent_tool_response( __( "You've been logged out. 👋 See you again!", 'wp-ai-agent' ), array( 'intent' => 'logout', 'source' => 'auth' ) );
}

/**
 * Show the logged-in user's account information.
 *
 * @return array
 */
function wp_ai_agent_tool_account() {
    $user = wp_ai_agent_user();
    if ( ! $user['logged_in'] ) {
        return wp_ai_agent_login_required_response( __( 'your account information', 'wp-ai-agent' ) );
    }

    $roles   = wp_roles();
    $labels  = array();
    foreach ( $user['roles'] as $r ) {
        $labels[] = isset( $roles->roles[ $r ]['name'] ) ? translate_user_role( $roles->roles[ $r ]['name'] ) : ucfirst( $r );
    }

    $lines   = array();
    $lines[] = sprintf( __( 'Here are your account details, %s:', 'wp-ai-agent' ), $user['first'] );
    $lines[] = sprintf( __( 'Name: %s', 'wp-ai-agent' ), $user['name'] );
    $lines[] = sprintf( __( 'Email: %s', 'wp-ai-agent' ), $user['email'] );
    $lines[] = sprintf( __( 'Role: %s', 'wp-ai-agent' ), implode( ', ', array_filter( $labels ) ) );

    return wp_ai_agent_tool_response( implode( "\n", $lines ), array( 'intent' => 'account', 'source' => 'account' ) );
}

/**
 * Show the logged-in user's bookings (matched by their email).
 *
 * @return array
 */
function wp_ai_agent_tool_my_bookings() {
    $user = wp_ai_agent_user();
    if ( ! $user['logged_in'] ) {
        return wp_ai_agent_login_required_response( __( 'your bookings', 'wp-ai-agent' ) );
    }

    global $wpdb;
    $name  = $user['first'];
    $table = function_exists( 'wp_ai_agent_bookings_table' ) ? wp_ai_agent_bookings_table() : '';

    $rows = array();
    if ( $table ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id DESC LIMIT 10", $user['email'] ) );
        }
    }

    if ( empty( $rows ) ) {
        return wp_ai_agent_tool_response( sprintf( __( "%s, you don't have any bookings yet.", 'wp-ai-agent' ), $name ), array( 'intent' => 'my_bookings', 'matched' => true ) );
    }

    $lines = array( sprintf( __( 'Your bookings, %s:', 'wp-ai-agent' ), $name ), '' );
    foreach ( $rows as $b ) {
        $lines[] = sprintf(
            __( '%1$s at %2$s — %3$s (%4$s)', 'wp-ai-agent' ),
            $b->booking_date ? $b->booking_date : '—',
            $b->booking_time ? $b->booking_time : '—',
            $b->service ? $b->service : __( 'Appointment', 'wp-ai-agent' ),
            ucfirst( $b->status )
        );
    }

    return wp_ai_agent_tool_response( implode( "\n", $lines ), array( 'intent' => 'my_bookings', 'source' => 'booking' ) );
}
