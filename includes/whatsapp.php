<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wp_ai_agent_send_whatsapp( $to, $message ) {
    $phone = preg_replace( '/[^0-9+]/', '', $to );
    if ( empty( $phone ) ) {
        return false;
    }

    $url = esc_url_raw( 'https://wa.me/' . ltrim( $phone, '+' ) . '?text=' . rawurlencode( $message ) );
    return array(
        'url'     => $url,
        'success' => true,
    );
}
