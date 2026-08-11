<?php
/**
 * Website Intelligence Engine.
 *
 * Detects what KIND of website the plugin is running on (WooCommerce store,
 * business, restaurant, medical, education, hotel, real-estate, blog, …) and
 * builds a cached "Website Profile" describing it — type, persona, business
 * details, active modules, content counts. The AI engine reads this profile so
 * the assistant adapts its ROLE and TONE to the site automatically, with no
 * manual configuration.
 *
 * Future-ready by design: a new website type never requires editing this file —
 * it is added by hooking the filters:
 *   - wp_ai_agent_website_type      (override / add detection)
 *   - wp_ai_agent_website_persona   (role + tone per type)
 *   - wp_ai_agent_active_modules    (which modules that type enables)
 *   - wp_ai_agent_website_profile   (final profile array)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Transient key holding the cached website profile.
 */
if ( ! defined( 'WP_AI_AGENT_PROFILE_KEY' ) ) {
    define( 'WP_AI_AGENT_PROFILE_KEY', 'wp_ai_agent_website_profile' );
}

/**
 * Is WooCommerce present on this site?
 *
 * @return bool
 */
function wp_ai_agent_profile_has_woocommerce() {
    return function_exists( 'wc_get_products' ) || class_exists( 'WooCommerce' );
}

/**
 * Whether an appointment / booking capability appears to exist (used by several
 * website types). Detected from common booking plugins and post types — never
 * hardcoded to one vendor.
 *
 * @return bool
 */
function wp_ai_agent_profile_has_bookings() {
    $cpts = array( 'booking', 'bookings', 'appointment', 'appointments', 'wpa_appointment', 'mp-event', 'tribe_events' );
    foreach ( $cpts as $cpt ) {
        if ( post_type_exists( $cpt ) ) {
            return true;
        }
    }
    return (bool) (
        defined( 'AMELIA_VERSION' )
        || class_exists( 'BookingPress' )
        || function_exists( 'bkap_load_class' )
        || class_exists( 'WC_Bookings' )
        || class_exists( 'App_Appointments' )
    );
}

/**
 * Detect the website TYPE from the strongest available signals (specialised
 * plugins / custom post types first, then WooCommerce, then a blog-vs-business
 * heuristic). Returns a lower-case type slug.
 *
 * Filterable via wp_ai_agent_website_type so a site or add-on can force / add a
 * type without changing any code here.
 *
 * @return string
 */
function wp_ai_agent_detect_website_type() {
    // 0) Explicit override (a site owner or an adapter can force the type).
    $forced = apply_filters( 'wp_ai_agent_website_type', '', 'pre' );
    if ( is_string( $forced ) && '' !== $forced ) {
        return sanitize_key( $forced );
    }

    $type = '';

    // 1) Education / LMS.
    if (
        defined( 'LEARNDASH_VERSION' ) || class_exists( 'LifterLMS' ) || function_exists( 'tutor' )
        || post_type_exists( 'sfwd-courses' ) || post_type_exists( 'llms_course' )
        || post_type_exists( 'lp_course' ) || post_type_exists( 'course' ) || post_type_exists( 'courses' )
        || post_type_exists( 'stm-courses' )
    ) {
        $type = 'education';
    }

    // 2) Restaurant (food-menu post types / restaurant plugins).
    if ( '' === $type && (
        post_type_exists( 'food_menu' ) || post_type_exists( 'fdm-menu' ) || post_type_exists( 'mtmenu' )
        || post_type_exists( 'restaurant_menu' ) || post_type_exists( 'rpress_menuitem' ) || post_type_exists( 'fc_menu' )
    ) ) {
        $type = 'restaurant';
    }

    // 3) Hotel (room booking plugins / room post types).
    if ( '' === $type && (
        post_type_exists( 'hb_room' ) || post_type_exists( 'mphb_room_type' ) || post_type_exists( 'room' )
        || post_type_exists( 'lp_hotel_room' ) || class_exists( 'HB_Hotel' )
    ) ) {
        $type = 'hotel';
    }

    // 4) Real estate.
    if ( '' === $type && (
        post_type_exists( 'property' ) || post_type_exists( 'properties' ) || post_type_exists( 'estate_property' )
        || post_type_exists( 'rem_property' ) || post_type_exists( 'houzez_property' ) || post_type_exists( 'listing' )
    ) ) {
        $type = 'real_estate';
    }

    // 5) Medical / healthcare.
    if ( '' === $type && (
        post_type_exists( 'doctor' ) || post_type_exists( 'doctors' ) || post_type_exists( 'kc_doctor' )
        || post_type_exists( 'department' ) && post_type_exists( 'doctor' ) || class_exists( 'KiviCare' )
    ) ) {
        $type = 'medical';
    }

    // 6) Travel / tourism.
    if ( '' === $type && (
        post_type_exists( 'itineraries' ) || post_type_exists( 'itinerary' ) || post_type_exists( 'trip' )
        || post_type_exists( 'tour' ) || post_type_exists( 'tours' ) || post_type_exists( 'travel_package' )
    ) ) {
        $type = 'travel';
    }

    // 7) NGO / charity.
    if ( '' === $type && (
        class_exists( 'Give' ) || function_exists( 'give_get_option' ) || defined( 'CHARITABLE_VERSION' )
        || post_type_exists( 'donation' ) || post_type_exists( 'campaign' ) || post_type_exists( 'give_forms' )
    ) ) {
        $type = 'ngo';
    }

    // 8) Portfolio.
    if ( '' === $type && (
        post_type_exists( 'portfolio' ) || post_type_exists( 'project' ) || post_type_exists( 'projects' )
        || post_type_exists( 'jetpack-portfolio' )
    ) ) {
        $type = 'portfolio';
    }

    // 9) WooCommerce store (checked AFTER the specialised verticals above, so a
    //    restaurant/hotel that also runs WooCommerce keeps its richer identity).
    if ( '' === $type && wp_ai_agent_profile_has_woocommerce() ) {
        $type = 'woocommerce';
    }

    // 10) Explicit services CPT → business / agency.
    if ( '' === $type && ( post_type_exists( 'service' ) || post_type_exists( 'services' ) || post_type_exists( 'practice_area' ) ) ) {
        $type = ( post_type_exists( 'practice_area' ) ) ? 'law' : 'business';
    }

    // 11) Blog vs business, by content shape + tell-tale pages.
    if ( '' === $type ) {
        $counts     = wp_count_posts( 'post' );
        $pub_posts  = ( $counts && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
        $page_c     = wp_count_posts( 'page' );
        $pub_pages  = ( $page_c && isset( $page_c->publish ) ) ? (int) $page_c->publish : 0;

        $has_biz_pages = false;
        foreach ( array( 'services', 'about', 'about-us', 'contact', 'contact-us', 'our-team', 'team' ) as $slug ) {
            $p = get_page_by_path( $slug );
            if ( $p && 'publish' === $p->post_status ) {
                $has_biz_pages = true;
                break;
            }
        }

        if ( $has_biz_pages && $pub_posts <= max( 3, $pub_pages ) ) {
            $type = 'business';
        } elseif ( $pub_posts >= 5 && $pub_posts > $pub_pages ) {
            $type = 'blog';
        } else {
            $type = 'business'; // safe, neutral default (generic support persona).
        }
    }

    /**
     * Final say on the detected website type.
     *
     * @param string $type Detected type slug.
     */
    return sanitize_key( (string) apply_filters( 'wp_ai_agent_website_type', $type, 'post' ) );
}

/**
 * The AI persona (role + tone description) for a website type. This is what makes
 * the assistant "sound like it belongs to that business".
 *
 * @param string $type Website type slug.
 * @return array{role:string,desc:string}
 */
function wp_ai_agent_website_persona( $type ) {
    $map = array(
        'woocommerce' => array(
            'role' => __( 'Sales Assistant', 'wp-ai-agent' ),
            'desc' => __( 'an experienced, friendly retail sales assistant and shopping consultant', 'wp-ai-agent' ),
        ),
        'business'    => array(
            'role' => __( 'Customer Support Executive', 'wp-ai-agent' ),
            'desc' => __( 'a professional, warm customer support executive', 'wp-ai-agent' ),
        ),
        'agency'      => array(
            'role' => __( 'Business Consultant', 'wp-ai-agent' ),
            'desc' => __( 'a knowledgeable business consultant who helps prospects understand the services', 'wp-ai-agent' ),
        ),
        'law'         => array(
            'role' => __( 'Legal Office Assistant', 'wp-ai-agent' ),
            'desc' => __( 'a courteous legal-office assistant who explains practice areas and helps visitors get in touch (never giving legal advice)', 'wp-ai-agent' ),
        ),
        'restaurant'  => array(
            'role' => __( 'Restaurant Host', 'wp-ai-agent' ),
            'desc' => __( 'a warm, welcoming restaurant host and waiter who helps with the menu, timings and reservations', 'wp-ai-agent' ),
        ),
        'hotel'       => array(
            'role' => __( 'Hotel Receptionist', 'wp-ai-agent' ),
            'desc' => __( 'a polished hotel receptionist who helps with rooms, amenities, bookings and directions', 'wp-ai-agent' ),
        ),
        'medical'     => array(
            'role' => __( 'Reception Executive', 'wp-ai-agent' ),
            'desc' => __( 'a caring clinic reception executive who helps with doctors, departments and appointments (never giving medical advice)', 'wp-ai-agent' ),
        ),
        'education'   => array(
            'role' => __( 'Admission Counsellor', 'wp-ai-agent' ),
            'desc' => __( 'a helpful admission counsellor who explains courses, faculty and the admission process', 'wp-ai-agent' ),
        ),
        'real_estate' => array(
            'role' => __( 'Property Consultant', 'wp-ai-agent' ),
            'desc' => __( 'a helpful property consultant who assists with listings, locations and enquiries', 'wp-ai-agent' ),
        ),
        'travel'      => array(
            'role' => __( 'Travel Consultant', 'wp-ai-agent' ),
            'desc' => __( 'an enthusiastic travel consultant who helps with tours, packages and trip planning', 'wp-ai-agent' ),
        ),
        'ngo'         => array(
            'role' => __( 'Volunteer Coordinator', 'wp-ai-agent' ),
            'desc' => __( 'a warm volunteer coordinator who explains the cause, programmes and how to help or donate', 'wp-ai-agent' ),
        ),
        'portfolio'   => array(
            'role' => __( 'Personal Assistant', 'wp-ai-agent' ),
            'desc' => __( 'a friendly personal assistant who showcases the work and helps visitors get in touch', 'wp-ai-agent' ),
        ),
        'blog'        => array(
            'role' => __( 'Content Guide', 'wp-ai-agent' ),
            'desc' => __( 'a friendly content guide who helps readers find articles and topics', 'wp-ai-agent' ),
        ),
    );

    $persona = isset( $map[ $type ] ) ? $map[ $type ] : $map['business'];

    /**
     * Filter the AI persona for a website type (add or tweak a personality
     * without editing this file).
     *
     * @param array  $persona role + desc.
     * @param string $type    Website type slug.
     */
    return apply_filters( 'wp_ai_agent_website_persona', $persona, $type );
}

/**
 * The set of agent modules a website type enables. WooCommerce commerce modules
 * are added only when WooCommerce is present, so a non-store site never advertises
 * Products / Cart / Orders / Checkout, etc.
 *
 * @param string $type   Website type slug.
 * @param bool   $has_wc Whether WooCommerce is active.
 * @return string[]
 */
function wp_ai_agent_active_modules( $type, $has_wc ) {
    // Always available (any website).
    $modules = array( 'navigation', 'contact', 'faq', 'website_info' );

    if ( $has_wc ) {
        $modules = array_merge( $modules, array(
            'product_search', 'image_search', 'orders', 'coupons',
            'shipping', 'payments', 'cart', 'shopping_assistant',
        ) );
    }

    switch ( $type ) {
        case 'business':
        case 'agency':
        case 'law':
            $modules = array_merge( $modules, array( 'services', 'company_info', 'lead_generation', 'appointment', 'team', 'portfolio' ) );
            break;
        case 'restaurant':
            $modules = array_merge( $modules, array( 'menu', 'reservations', 'opening_hours', 'location', 'gallery' ) );
            break;
        case 'hotel':
            $modules = array_merge( $modules, array( 'rooms', 'reservations', 'amenities', 'location', 'opening_hours' ) );
            break;
        case 'medical':
            $modules = array_merge( $modules, array( 'doctors', 'appointments', 'departments', 'emergency_contact', 'services' ) );
            break;
        case 'education':
            $modules = array_merge( $modules, array( 'courses', 'teachers', 'admissions', 'downloads', 'contact' ) );
            break;
        case 'real_estate':
            $modules = array_merge( $modules, array( 'listings', 'enquiry', 'locations', 'appointment' ) );
            break;
        case 'travel':
            $modules = array_merge( $modules, array( 'packages', 'itineraries', 'enquiry', 'lead_generation' ) );
            break;
        case 'ngo':
            $modules = array_merge( $modules, array( 'donations', 'programmes', 'volunteer', 'contact' ) );
            break;
        case 'portfolio':
            $modules = array_merge( $modules, array( 'projects', 'about', 'lead_generation' ) );
            break;
        case 'blog':
            $modules = array_merge( $modules, array( 'article_search', 'categories', 'authors', 'latest_posts' ) );
            break;
    }

    $modules = array_values( array_unique( $modules ) );

    /**
     * Filter the enabled modules for a website type.
     *
     * @param string[] $modules Module slugs.
     * @param string   $type    Website type slug.
     * @param bool     $has_wc  Whether WooCommerce is active.
     */
    return apply_filters( 'wp_ai_agent_active_modules', $modules, $type, $has_wc );
}

/**
 * Human-readable label for a website type (for the admin display).
 *
 * @param string $type Type slug.
 * @return string
 */
function wp_ai_agent_website_type_label( $type ) {
    $labels = array(
        'woocommerce' => __( 'WooCommerce Store', 'wp-ai-agent' ),
        'business'    => __( 'Business / Corporate', 'wp-ai-agent' ),
        'agency'      => __( 'Agency', 'wp-ai-agent' ),
        'law'         => __( 'Law Firm', 'wp-ai-agent' ),
        'restaurant'  => __( 'Restaurant', 'wp-ai-agent' ),
        'hotel'       => __( 'Hotel', 'wp-ai-agent' ),
        'medical'     => __( 'Medical / Healthcare', 'wp-ai-agent' ),
        'education'   => __( 'Educational', 'wp-ai-agent' ),
        'real_estate' => __( 'Real Estate', 'wp-ai-agent' ),
        'travel'      => __( 'Travel / Tourism', 'wp-ai-agent' ),
        'ngo'         => __( 'NGO / Charity', 'wp-ai-agent' ),
        'portfolio'   => __( 'Portfolio', 'wp-ai-agent' ),
        'blog'        => __( 'Blog', 'wp-ai-agent' ),
    );
    return isset( $labels[ $type ] ) ? $labels[ $type ] : ucwords( str_replace( array( '-', '_' ), ' ', $type ) );
}

/**
 * Build the full Website Profile. Cached for a day; rebuilt on demand (and when
 * the content index is rebuilt).
 *
 * @param bool $refresh Force a rebuild.
 * @return array
 */
function wp_ai_agent_get_website_profile( $refresh = false ) {
    if ( ! $refresh ) {
        $cached = get_transient( WP_AI_AGENT_PROFILE_KEY );
        if ( is_array( $cached ) && ! empty( $cached['type'] ) ) {
            return $cached;
        }
    }

    $type    = wp_ai_agent_detect_website_type();
    $persona = wp_ai_agent_website_persona( $type );
    $has_wc  = wp_ai_agent_profile_has_woocommerce();

    $business = function_exists( 'wp_ai_agent_option' )
        ? wp_ai_agent_option( 'business_name', get_bloginfo( 'name' ) )
        : get_bloginfo( 'name' );

    $post_c = wp_count_posts( 'post' );
    $page_c = wp_count_posts( 'page' );

    $categories = array();
    if ( $has_wc && function_exists( 'wp_ai_agent_store_categories' ) ) {
        $categories = wp_list_pluck( wp_ai_agent_store_categories( 8 ), 'name' );
    }

    $profile = array(
        'type'            => $type,
        'type_label'      => wp_ai_agent_website_type_label( $type ),
        'persona_role'    => isset( $persona['role'] ) ? $persona['role'] : '',
        'persona_desc'    => isset( $persona['desc'] ) ? $persona['desc'] : '',
        'business_name'   => $business,
        'tagline'         => get_bloginfo( 'description' ),
        'has_woocommerce' => $has_wc,
        'has_bookings'    => wp_ai_agent_profile_has_bookings(),
        'product_count'   => ( $has_wc && function_exists( 'wp_ai_agent_db_count_posts' ) ) ? wp_ai_agent_db_count_posts( 'product' ) : 0,
        'post_count'      => ( $post_c && isset( $post_c->publish ) ) ? (int) $post_c->publish : 0,
        'page_count'      => ( $page_c && isset( $page_c->publish ) ) ? (int) $page_c->publish : 0,
        'categories'      => array_values( (array) $categories ),
        'custom_types'    => array_values( get_post_types( array( 'public' => true, '_builtin' => false ), 'names' ) ),
        'modules'         => wp_ai_agent_active_modules( $type, $has_wc ),
        'languages'       => array( get_bloginfo( 'language' ) ),
        'generated_at'    => current_time( 'mysql' ),
    );

    /**
     * Filter the finished website profile (an adapter can enrich it).
     *
     * @param array $profile The profile.
     */
    $profile = apply_filters( 'wp_ai_agent_website_profile', $profile );

    set_transient( WP_AI_AGENT_PROFILE_KEY, $profile, DAY_IN_SECONDS );
    return $profile;
}

/**
 * Whether a given agent module is enabled for this website (per the detected
 * profile). Non-store sites, for example, never enable product_search / cart /
 * orders, so commerce features stay hidden. Falls back to "enabled" when the
 * profile is unavailable, so nothing breaks.
 *
 * @param string $module Module slug (see wp_ai_agent_active_modules()).
 * @return bool
 */
function wp_ai_agent_module_enabled( $module ) {
    $profile = wp_ai_agent_get_website_profile();
    $modules = ( isset( $profile['modules'] ) && is_array( $profile['modules'] ) ) ? $profile['modules'] : array();
    $enabled = empty( $modules ) ? true : in_array( $module, $modules, true );

    /**
     * Filter whether a module is enabled (turn a feature on/off regardless of
     * detection).
     *
     * @param bool   $enabled Whether the module is enabled.
     * @param string $module  Module slug.
     */
    return (bool) apply_filters( 'wp_ai_agent_module_enabled', $enabled, $module );
}

/**
 * Whether WooCommerce commerce features (products, cart, orders, coupons,
 * checkout, image product search, shopping assistant) should be offered. True
 * only when WooCommerce is active AND the commerce module is enabled.
 *
 * @return bool
 */
function wp_ai_agent_commerce_enabled() {
    $enabled = wp_ai_agent_profile_has_woocommerce() && wp_ai_agent_module_enabled( 'product_search' );

    /**
     * Filter the commerce master switch.
     *
     * @param bool $enabled Whether commerce features are offered.
     */
    return (bool) apply_filters( 'wp_ai_agent_commerce_enabled', $enabled );
}

/**
 * Curated, website-type-specific quick actions (starter questions) so the
 * assistant feels built for THIS kind of site — a restaurant offers Menu /
 * Reserve, a clinic offers Doctors / Appointment, a school offers Courses /
 * Admissions, and so on. Returns [] for a plain store/blog (those are covered by
 * the content-driven suggestions). Each item: { label, query }.
 *
 * @return array[]
 */
function wp_ai_agent_profile_quick_actions() {
    $profile = wp_ai_agent_get_website_profile();
    $type    = isset( $profile['type'] ) ? $profile['type'] : '';
    $a       = array();

    switch ( $type ) {
        case 'restaurant':
            $a[] = array( 'label' => __( '🍽️ Menu', 'wp-ai-agent' ), 'query' => 'show me your menu' );
            $a[] = array( 'label' => __( '📅 Reserve a table', 'wp-ai-agent' ), 'query' => 'I want to book a table' );
            $a[] = array( 'label' => __( '🕒 Opening hours', 'wp-ai-agent' ), 'query' => 'what are your opening hours' );
            break;
        case 'medical':
            $a[] = array( 'label' => __( '🩺 Our doctors', 'wp-ai-agent' ), 'query' => 'tell me about your doctors' );
            $a[] = array( 'label' => __( '📅 Book an appointment', 'wp-ai-agent' ), 'query' => 'I want to book an appointment' );
            $a[] = array( 'label' => __( '🏥 Departments', 'wp-ai-agent' ), 'query' => 'what departments do you have' );
            break;
        case 'education':
            $a[] = array( 'label' => __( '📚 Courses', 'wp-ai-agent' ), 'query' => 'what courses do you offer' );
            $a[] = array( 'label' => __( '📝 Admissions', 'wp-ai-agent' ), 'query' => 'tell me about the admission process' );
            break;
        case 'hotel':
            $a[] = array( 'label' => __( '🛏️ Rooms', 'wp-ai-agent' ), 'query' => 'show me your rooms' );
            $a[] = array( 'label' => __( '📅 Book a stay', 'wp-ai-agent' ), 'query' => 'I want to book a room' );
            $a[] = array( 'label' => __( '✨ Amenities', 'wp-ai-agent' ), 'query' => 'what amenities do you offer' );
            break;
        case 'real_estate':
            $a[] = array( 'label' => __( '🏠 Listings', 'wp-ai-agent' ), 'query' => 'show me your property listings' );
            $a[] = array( 'label' => __( '📩 Enquire', 'wp-ai-agent' ), 'query' => 'I want to enquire about a property' );
            break;
        case 'travel':
            $a[] = array( 'label' => __( '✈️ Packages', 'wp-ai-agent' ), 'query' => 'show me your travel packages' );
            $a[] = array( 'label' => __( '🧭 Plan a trip', 'wp-ai-agent' ), 'query' => 'help me plan a trip' );
            break;
        case 'ngo':
            $a[] = array( 'label' => __( '❤️ Donate', 'wp-ai-agent' ), 'query' => 'how can I donate' );
            $a[] = array( 'label' => __( '🙌 Volunteer', 'wp-ai-agent' ), 'query' => 'how can I volunteer' );
            $a[] = array( 'label' => __( '📋 Programmes', 'wp-ai-agent' ), 'query' => 'tell me about your programmes' );
            break;
        case 'portfolio':
            $a[] = array( 'label' => __( '💼 My work', 'wp-ai-agent' ), 'query' => 'show me your work' );
            $a[] = array( 'label' => __( '📩 Get in touch', 'wp-ai-agent' ), 'query' => 'I want to get in touch' );
            break;
        case 'blog':
            $a[] = array( 'label' => __( '📰 Latest articles', 'wp-ai-agent' ), 'query' => 'show me your latest articles' );
            break;
        case 'business':
        case 'agency':
        case 'law':
            $a[] = array( 'label' => __( '🧰 Services', 'wp-ai-agent' ), 'query' => 'what services do you offer' );
            $a[] = array( 'label' => __( '📩 Get a quote', 'wp-ai-agent' ), 'query' => 'I would like to get a quote' );
            break;
    }

    /**
     * Filter the website-type quick actions.
     *
     * @param array[] $a    Actions.
     * @param string  $type Website type slug.
     */
    return apply_filters( 'wp_ai_agent_profile_quick_actions', $a, $type );
}

/**
 * The "directory" adapter for a website type — which custom post type(s) hold
 * its main listings, what triggers a request for them, and how to present them.
 * This is what lets ONE generic listing tool serve every vertical (restaurant
 * menu, clinic doctors, school courses, hotel rooms, property listings, travel
 * packages, portfolio projects, NGO programmes, blog articles, business
 * services) with no per-type code. Returns null for types with no listing
 * (e.g. a plain WooCommerce store, whose products are handled elsewhere).
 *
 * A new vertical is added purely by filtering wp_ai_agent_type_directory — no
 * core code changes.
 *
 * @param string $type Website type slug.
 * @return array|null { cpts:string[], intro:string, triggers:string(regex), price:bool, orderby:string }
 */
function wp_ai_agent_type_directory( $type ) {
    $map = array(
        'restaurant'  => array(
            'cpts'     => array( 'food_menu', 'fdm-menu', 'mtmenu', 'restaurant_menu', 'rpress_menuitem', 'fc_menu' ),
            'intro'    => __( 'Here’s our menu:', 'wp-ai-agent' ),
            'triggers' => '/\b(menu|dish|dishes|food|cuisine|meals?|what.{0,12}(eat|serve|order))\b/i',
            'price'    => true,
            'orderby'  => 'menu_order',
        ),
        'medical'     => array(
            'cpts'     => array( 'doctor', 'doctors', 'kc_doctor' ),
            'intro'    => __( 'Here are our doctors:', 'wp-ai-agent' ),
            'triggers' => '/\b(doctors?|physicians?|specialists?|consultants?|surgeons?)\b/i',
            'price'    => false,
            'orderby'  => 'title',
        ),
        'education'   => array(
            'cpts'     => array( 'course', 'courses', 'sfwd-courses', 'llms_course', 'lp_course', 'stm-courses' ),
            'intro'    => __( 'Here are our courses:', 'wp-ai-agent' ),
            'triggers' => '/\b(courses?|classes?|programs?|programmes?|trainings?|diplomas?|degrees?)\b/i',
            'price'    => true,
            'orderby'  => 'title',
        ),
        'hotel'       => array(
            'cpts'     => array( 'hb_room', 'mphb_room_type', 'room', 'lp_hotel_room' ),
            'intro'    => __( 'Here are our rooms:', 'wp-ai-agent' ),
            'triggers' => '/\b(rooms?|suites?|accomm?odation|stay)\b/i',
            'price'    => true,
            'orderby'  => 'menu_order',
        ),
        'real_estate' => array(
            'cpts'     => array( 'property', 'properties', 'estate_property', 'rem_property', 'houzez_property', 'listing' ),
            'intro'    => __( 'Here are our listings:', 'wp-ai-agent' ),
            'triggers' => '/\b(propert|listings?|houses?|apartments?|flats?|villas?|plots?|for sale|for rent)\b/i',
            'price'    => true,
            'orderby'  => 'date',
        ),
        'travel'      => array(
            'cpts'     => array( 'itineraries', 'itinerary', 'trip', 'tour', 'tours', 'travel_package' ),
            'intro'    => __( 'Here are our packages:', 'wp-ai-agent' ),
            'triggers' => '/\b(packages?|tours?|trips?|itiner|destinations?|holidays?)\b/i',
            'price'    => true,
            'orderby'  => 'date',
        ),
        'portfolio'   => array(
            'cpts'     => array( 'portfolio', 'project', 'projects', 'jetpack-portfolio' ),
            'intro'    => __( 'Here’s some of our work:', 'wp-ai-agent' ),
            'triggers' => '/\b(portfolio|projects?|works?|case stud(y|ies))\b/i',
            'price'    => false,
            'orderby'  => 'date',
        ),
        'ngo'         => array(
            'cpts'     => array( 'campaign', 'donation', 'give_forms', 'program', 'programme' ),
            'intro'    => __( 'Here are our programmes:', 'wp-ai-agent' ),
            'triggers' => '/\b(programmes?|programs?|campaigns?|causes?|initiatives?)\b/i',
            'price'    => false,
            'orderby'  => 'date',
        ),
        'blog'        => array(
            'cpts'     => array( 'post' ),
            'intro'    => __( 'Here are our latest articles:', 'wp-ai-agent' ),
            'triggers' => '/\b(articles?|posts?|blogs?|news|latest|stories|read)\b/i',
            'price'    => false,
            'orderby'  => 'date',
        ),
        'business'    => array(
            'cpts'     => array( 'service', 'services' ),
            'intro'    => __( 'Here are our services:', 'wp-ai-agent' ),
            'triggers' => '/\b(services?|what.{0,12}(offer|do|provide))\b/i',
            'price'    => false,
            'orderby'  => 'menu_order',
        ),
    );

    $map['agency'] = $map['business'];
    $map['law']    = array(
        'cpts'     => array( 'practice_area', 'service', 'services' ),
        'intro'    => __( 'Here are our practice areas:', 'wp-ai-agent' ),
        'triggers' => '/\b(practice areas?|services?|what.{0,12}(offer|do|handle))\b/i',
        'price'    => false,
        'orderby'  => 'menu_order',
    );

    $cfg = isset( $map[ $type ] ) ? $map[ $type ] : null;

    /**
     * Filter the directory adapter for a website type (add a new vertical here).
     *
     * @param array|null $cfg  Adapter config.
     * @param string     $type Website type slug.
     */
    return apply_filters( 'wp_ai_agent_type_directory', $cfg, $type );
}

/**
 * Clear the cached profile so the next request rebuilds it.
 */
function wp_ai_agent_clear_website_profile() {
    delete_transient( WP_AI_AGENT_PROFILE_KEY );
}

// Rebuild the profile when the site changes in a way that affects detection.
add_action( 'activated_plugin', 'wp_ai_agent_clear_website_profile' );
add_action( 'deactivated_plugin', 'wp_ai_agent_clear_website_profile' );
add_action( 'switch_theme', 'wp_ai_agent_clear_website_profile' );
