<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Agent_Lead_Manager {
    public function add_lead( $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_agent_leads';

        $wpdb->insert(
            $table_name,
            array(
                'name'    => sanitize_text_field( $data['name'] ?? '' ),
                'email'   => sanitize_email( $data['email'] ?? '' ),
                'phone'   => sanitize_text_field( $data['phone'] ?? '' ),
                'message' => sanitize_textarea_field( $data['message'] ?? '' ),
            ),
            array( '%s', '%s', '%s', '%s' )
        );

        return $wpdb->insert_id;
    }
}
