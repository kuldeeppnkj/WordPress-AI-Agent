<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Admin training page for WP AI Agent plugin.

function wp_ai_agent_admin_training_page() {
    $notice = '';

    if ( isset( $_POST['wp_ai_agent_reindex'] ) && check_admin_referer( 'wp_ai_agent_reindex_action' ) ) {
        $count  = wp_ai_agent_rebuild_index();
        $notice = sprintf(
            /* translators: %d: number of indexed items. */
            esc_html__( 'Content index rebuilt. %d items indexed.', 'wp-ai-agent' ),
            (int) $count
        );
    }

    if ( isset( $_POST['wp_ai_agent_redetect'] ) && check_admin_referer( 'wp_ai_agent_redetect_action' ) ) {
        if ( function_exists( 'wp_ai_agent_get_website_profile' ) ) {
            wp_ai_agent_get_website_profile( true );
        }
        $notice = esc_html__( 'Website type re-detected.', 'wp-ai-agent' );
    }

    global $wpdb;
    $table = wp_ai_agent_index_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $embedded = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE embedding <> ''" );
    $semantic_on = function_exists( 'wp_ai_agent_semantic_enabled' ) && wp_ai_agent_semantic_enabled();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $by_type = $wpdb->get_results( "SELECT content_type, COUNT(*) AS n FROM {$table} GROUP BY content_type ORDER BY n DESC" );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Training', 'wp-ai-agent' ); ?></h1>

        <?php if ( $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif; ?>

        <?php if ( function_exists( 'wp_ai_agent_get_website_profile' ) ) :
            $profile = wp_ai_agent_get_website_profile();
            ?>
            <h2><?php esc_html_e( 'Website Intelligence', 'wp-ai-agent' ); ?></h2>
            <p><?php esc_html_e( 'The AI Agent detected the type of this website and adapts its personality accordingly — no manual setup needed.', 'wp-ai-agent' ); ?></p>
            <table class="widefat striped" style="max-width:640px;">
                <tbody>
                    <tr>
                        <th style="width:180px;"><?php esc_html_e( 'Website type', 'wp-ai-agent' ); ?></th>
                        <td><strong><?php echo esc_html( isset( $profile['type_label'] ) ? $profile['type_label'] : $profile['type'] ); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Assistant role', 'wp-ai-agent' ); ?></th>
                        <td><?php echo esc_html( $profile['persona_role'] ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'WooCommerce', 'wp-ai-agent' ); ?></th>
                        <td><?php echo $profile['has_woocommerce'] ? esc_html__( 'Active — commerce features enabled', 'wp-ai-agent' ) : esc_html__( 'Not detected — commerce features hidden', 'wp-ai-agent' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Content', 'wp-ai-agent' ); ?></th>
                        <td><?php
                            /* translators: 1: products, 2: posts, 3: pages. */
                            echo esc_html( sprintf( __( '%1$d products · %2$d posts · %3$d pages', 'wp-ai-agent' ), (int) $profile['product_count'], (int) $profile['post_count'], (int) $profile['page_count'] ) );
                        ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Active modules', 'wp-ai-agent' ); ?></th>
                        <td><?php echo esc_html( implode( ', ', array_map( function ( $m ) { return str_replace( '_', ' ', $m ); }, (array) $profile['modules'] ) ) ); ?></td>
                    </tr>
                </tbody>
            </table>
            <form method="post" style="margin:12px 0 24px;">
                <?php wp_nonce_field( 'wp_ai_agent_redetect_action' ); ?>
                <?php submit_button( __( 'Re-detect Website Type', 'wp-ai-agent' ), 'secondary', 'wp_ai_agent_redetect', false ); ?>
            </form>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Content Index', 'wp-ai-agent' ); ?></h2>
        <p>
            <?php
            printf(
                /* translators: %d: total indexed items. */
                esc_html__( 'The AI answers only from indexed website content. Currently %d items are indexed.', 'wp-ai-agent' ),
                $total
            );
            ?>
        </p>
        <p>
            <?php
            if ( $semantic_on ) {
                printf(
                    /* translators: %d: number of items with semantic embeddings. */
                    esc_html__( 'Semantic search: ON — %d items have AI embeddings.', 'wp-ai-agent' ),
                    $embedded
                );
            } else {
                esc_html_e( 'Semantic search: OFF (enable it in Settings and add an OpenAI/Gemini API key, then rebuild).', 'wp-ai-agent' );
            }
            ?>
        </p>

        <?php if ( ! empty( $by_type ) ) : ?>
            <table class="widefat striped" style="max-width:480px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Content type', 'wp-ai-agent' ); ?></th>
                        <th><?php esc_html_e( 'Items', 'wp-ai-agent' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $by_type as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row->content_type ); ?></td>
                            <td><?php echo esc_html( $row->n ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" style="margin-top:16px;">
            <?php wp_nonce_field( 'wp_ai_agent_reindex_action' ); ?>
            <?php submit_button( __( 'Rebuild Content Index', 'wp-ai-agent' ), 'primary', 'wp_ai_agent_reindex' ); ?>
        </form>
    </div>
    <?php
}
