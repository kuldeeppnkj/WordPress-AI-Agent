<?php
/**
 * Custom Q&A ("Trained Answers").
 *
 * Lets an admin save answers for frequently asked questions. These are matched
 * before the AI search, and also fed into the universal index, so the next user
 * who asks gets the admin-provided answer.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Q&A table name.
 *
 * @return string
 */
function wp_ai_agent_qa_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'ai_qa';
}

/**
 * Create the Q&A table.
 */
function wp_ai_agent_create_qa_table() {
    global $wpdb;
    $table           = wp_ai_agent_qa_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        question text NOT NULL,
        answer longtext NOT NULL,
        hits bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * All saved Q&A rows (newest first).
 *
 * @return object[]
 */
function wp_ai_agent_get_qa_items() {
    global $wpdb;
    $table = wp_ai_agent_qa_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return array();
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
}

/**
 * Insert or update a Q&A pair.
 *
 * @param string $question Question.
 * @param string $answer   Answer.
 * @param int    $id       Existing id to update, or 0 to insert.
 * @return bool
 */
function wp_ai_agent_save_qa( $question, $answer, $id = 0 ) {
    global $wpdb;
    wp_ai_agent_create_qa_table();
    $table = wp_ai_agent_qa_table_name();

    $question = sanitize_textarea_field( $question );
    $answer   = sanitize_textarea_field( $answer );
    if ( '' === $question || '' === $answer ) {
        return false;
    }

    if ( $id > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( $table, array( 'question' => $question, 'answer' => $answer ), array( 'id' => (int) $id ) );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, array( 'question' => $question, 'answer' => $answer, 'created_at' => current_time( 'mysql' ) ) );
    }

    // Refresh the content index so the new answer is searchable too.
    if ( function_exists( 'wp_ai_agent_schedule_reindex' ) ) {
        wp_ai_agent_schedule_reindex();
    }
    return true;
}

/**
 * Delete a Q&A pair.
 *
 * @param int $id Row id.
 */

function wp_ai_agent_delete_qa( $id ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( wp_ai_agent_qa_table_name(), array( 'id' => (int) $id ) );
    if ( function_exists( 'wp_ai_agent_schedule_reindex' ) ) {
        wp_ai_agent_schedule_reindex();
    }
}

/**
 * Find a saved answer that matches the user's message. Returns '' if none is a
 * strong enough match.
 *
 * @param string $message User message.
 * @return string Stored answer, or ''.
 */

function wp_ai_agent_match_custom_qa( $message ) {
    $items = wp_ai_agent_get_qa_items();
    if ( empty( $items ) ) {
        return '';
    }

    $msg_lower = strtolower( trim( $message ) );
    if ( '' === $msg_lower ) {
        return '';
    }

    $best       = null;
    $best_score = 0.0;

    foreach ( $items as $item ) {
        $q_lower = strtolower( trim( $item->question ) );

        // Exact match wins immediately.
        if ( $msg_lower === $q_lower ) {
            wp_ai_agent_bump_qa_hit( $item->id );
            return $item->answer;
        }

        // Otherwise: how many of the stored question's keywords appear in the
        // user's message (synonym/stem aware when available)?

        if ( function_exists( 'wp_ai_agent_tokenize_query' ) ) {
            $q_tokens = wp_ai_agent_tokenize_query( $item->question );
        } else {
            $q_tokens = preg_split( '/\s+/', $q_lower, -1, PREG_SPLIT_NO_EMPTY );
        }
        if ( empty( $q_tokens ) ) {
            continue;
        }

        $matched = 0;
        foreach ( $q_tokens as $qt ) {
            $needles = function_exists( 'wp_ai_agent_expand_token' ) ? wp_ai_agent_expand_token( $qt ) : array( strtolower( $qt ) );
            foreach ( $needles as $needle ) {
                if ( false !== strpos( $msg_lower, $needle ) ) {
                    $matched++;
                    break;
                }
            }
        }

        $score = $matched / count( $q_tokens );
        if ( $score > $best_score ) {
            $best_score = $score;
            $best       = $item;
        }
    }

    $threshold = (float) apply_filters( 'wp_ai_agent_qa_match_threshold', 0.6 );
    if ( $best && $best_score >= $threshold ) {
        wp_ai_agent_bump_qa_hit( $best->id );
        return $best->answer;
    }

    return '';
}

/**
 * Increment the hit counter for a Q&A row.
 *
 * @param int $id Row id.
 */

function wp_ai_agent_bump_qa_hit( $id ) {
    global $wpdb;
    $table = wp_ai_agent_qa_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1 WHERE id = %d", (int) $id ) );
}

/**
 * Feed saved Q&A into the universal content index so they are searchable.
 */

add_filter( 'wp_ai_agent_extra_index_items', 'wp_ai_agent_qa_index_items' );
function wp_ai_agent_qa_index_items( $extra ) {
    foreach ( wp_ai_agent_get_qa_items() as $item ) {
        $extra[] = array(
            'content_type' => 'qa',
            'post_id'      => 0,
            'title'        => $item->question,
            'content'      => $item->answer,
            'source'       => 'qa',
            'url'          => '',
        );
    }
    return $extra;
}

/**
 * Admin page: manage Q&A (Trained Answers).
 */

function wp_ai_agent_admin_qa_page() {
    // Handle save.
    if ( isset( $_POST['wp_ai_agent_qa_save'] ) && check_admin_referer( 'wp_ai_agent_qa_action' ) ) {
        $q  = isset( $_POST['qa_question'] ) ? wp_unslash( $_POST['qa_question'] ) : '';
        $a  = isset( $_POST['qa_answer'] ) ? wp_unslash( $_POST['qa_answer'] ) : '';
        $id = isset( $_POST['qa_id'] ) ? (int) $_POST['qa_id'] : 0;
        if ( wp_ai_agent_save_qa( $q, $a, $id ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Answer saved.', 'wp-ai-agent' ) . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Question and answer are both required.', 'wp-ai-agent' ) . '</p></div>';
        }
    }

    // Handle delete.
    if ( isset( $_GET['delete'] ) && check_admin_referer( 'wp_ai_agent_qa_delete' ) ) {
        wp_ai_agent_delete_qa( (int) $_GET['delete'] );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Answer deleted.', 'wp-ai-agent' ) . '</p></div>';
    }

    // Prefill (from analytics "Add Answer" links or edit).
    $edit_id  = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
    $edit_q   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    $edit_a   = '';
    if ( $edit_id > 0 ) {
        foreach ( wp_ai_agent_get_qa_items() as $row ) {
            if ( (int) $row->id === $edit_id ) {
                $edit_q = $row->question;
                $edit_a = $row->answer;
                break;
            }
        }
    }

    $items = wp_ai_agent_get_qa_items();
    ?>
    
    <div class="wrap">
        <h1><?php esc_html_e( 'Trained Answers (Q&A)', 'wp-ai-agent' ); ?></h1>
        <p><?php esc_html_e( 'Add answers for common questions. When a visitor asks something similar, the assistant replies with your answer.', 'wp-ai-agent' ); ?></p>

        <h2><?php echo $edit_id ? esc_html__( 'Edit Answer', 'wp-ai-agent' ) : esc_html__( 'Add Answer', 'wp-ai-agent' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'wp_ai_agent_qa_action' ); ?>
            <input type="hidden" name="qa_id" value="<?php echo esc_attr( $edit_id ); ?>" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="qa_question"><?php esc_html_e( 'Question', 'wp-ai-agent' ); ?></label></th>
                    <td><input name="qa_question" id="qa_question" type="text" class="large-text" value="<?php echo esc_attr( $edit_q ); ?>" placeholder="<?php esc_attr_e( 'e.g. Do you offer any discount?', 'wp-ai-agent' ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="qa_answer"><?php esc_html_e( 'Answer', 'wp-ai-agent' ); ?></label></th>
                    <td><textarea name="qa_answer" id="qa_answer" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'The answer the assistant should give.', 'wp-ai-agent' ); ?>"><?php echo esc_textarea( $edit_a ); ?></textarea></td>
                </tr>
            </table>
            <?php submit_button( $edit_id ? __( 'Update Answer', 'wp-ai-agent' ) : __( 'Save Answer', 'wp-ai-agent' ), 'primary', 'wp_ai_agent_qa_save' ); ?>
        </form>

        <h2><?php esc_html_e( 'Saved Answers', 'wp-ai-agent' ); ?></h2>
        <?php if ( $items ) : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Question', 'wp-ai-agent' ); ?></th>
                    <th><?php esc_html_e( 'Answer', 'wp-ai-agent' ); ?></th>
                    <th style="width:70px;"><?php esc_html_e( 'Used', 'wp-ai-agent' ); ?></th>
                    <th style="width:120px;"><?php esc_html_e( 'Actions', 'wp-ai-agent' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $items as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row->question ); ?></td>
                        <td><?php echo esc_html( wp_trim_words( $row->answer, 30, '…' ) ); ?></td>
                        <td><?php echo esc_html( $row->hits ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-agent-qa&edit=' . $row->id ) ); ?>"><?php esc_html_e( 'Edit', 'wp-ai-agent' ); ?></a> |
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wp-ai-agent-qa&delete=' . $row->id ), 'wp_ai_agent_qa_delete' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this answer?', 'wp-ai-agent' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'wp-ai-agent' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php esc_html_e( 'No saved answers yet.', 'wp-ai-agent' ); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
