<?php
/**
 * Conversation memory: cleans the history sent by the widget and keeps only
 * the last N messages (N = "Conversation Memory" setting).
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Memory_Manager {
    private $limit;

    public function __construct() {
        $settings = get_option( 'wp_ai_chatbot_settings', array() );
        // Admin-defined limit, default 6 messages.
        $this->limit = isset( $settings['history_limit'] ) ? max( 0, intval( $settings['history_limit'] ) ) : 6;
    }

    public function process_history( $raw_history ) {
        if ( is_string( $raw_history ) ) {
            $raw_history = json_decode( $raw_history, true );
        }
        if ( ! is_array( $raw_history ) ) {
            return array();
        }

        // Only the most recent entries can be used, so a huge history sent by a script
        // is cut before any work is done on it.
        $raw_history = array_slice( array_values( $raw_history ), -max( 2, min( 100, $this->limit * 2 + 2 ) ) );

        // 1. Sanitize and cap each entry's length.
        $clean_history = array();
        $max_len = defined( 'WP_AI_CHATBOT_MAX_HISTORY_MSG_LEN' ) ? WP_AI_CHATBOT_MAX_HISTORY_MSG_LEN : 1500;
        foreach ( $raw_history as $msg ) {
            if ( is_array( $msg ) && isset( $msg['text'], $msg['sender'] ) && is_string( $msg['text'] ) && is_string( $msg['sender'] ) ) {
                // Token saver keeps line breaks (lists in bot replies stay readable for follow-ups).
                $text = WP_AI_Chatbot_AI::token_saver() ? sanitize_textarea_field( $msg['text'] ) : sanitize_text_field( $msg['text'] );
                $text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_len ) : substr( $text, 0, $max_len );
                $clean_history[] = array(
                    'text'   => $text,
                    'sender' => sanitize_text_field( $msg['sender'] )
                );
            }
        }

        // 2. Sliding window. A limit of 0 disables memory.
        if ( 0 === $this->limit ) {
            return array();
        }
        if ( count( $clean_history ) > $this->limit ) {
            $clean_history = array_slice( $clean_history, -$this->limit );
        }

        return $clean_history;
    }
}