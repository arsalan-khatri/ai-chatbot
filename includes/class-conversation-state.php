<?php
/**
 * Small server-side memory per conversation for the token saver:
 * - the current list (original query, stable order of sources, cursor),
 * - the numbers shown to the visitor (#1, #2, ...) => source,
 * - the item(s) the conversation is about ("its price", "iski warranty").
 *
 * Stored as a transient keyed by the visitor's HttpOnly session cookie, never
 * taken from the browser. It only holds source keys ("wc_product|123"); every
 * source is re-checked for visibility and selection before it is used.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Conversation_State {

    const TTL     = 7200; // Two hours without a message.
    const VERSION = 1;
    const MAX_IDS = 150;

    private $key  = '';
    public $data  = array();

    public function __construct( $session_id ) {
        $session_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $session_id );
        if ( strlen( $session_id ) >= 16 ) {
            $this->key = 'wp_ai_conv_' . md5( $session_id );
        }
        $this->data = $this->load();
    }

    public static function empty_state() {
        return array( 'v' => self::VERSION, 'list' => null, 'numbers' => array(), 'focus' => array(), 'focus_multi' => false, 'at' => time() );
    }

    /** True when there is a session to store state for. */
    public function enabled() {
        return '' !== $this->key;
    }

    private static function valid_key( $k ) {
        return is_string( $k ) && (bool) preg_match( '/^(wc_product|wp_post|wp_page|tutor_course|custom_kb)\|[a-z0-9_\-]{1,100}$/', $k );
    }

    private function load() {
        if ( '' === $this->key ) {
            return self::empty_state();
        }
        $d = get_transient( $this->key );
        if ( ! is_array( $d ) || ! isset( $d['v'] ) || self::VERSION !== (int) $d['v'] ) {
            return self::empty_state();
        }
        // Validate everything; anything odd is dropped.
        $clean            = self::empty_state();
        $clean['focus']   = array_values( array_filter( (array) ( $d['focus'] ?? array() ), array( __CLASS__, 'valid_key' ) ) );
        $clean['focus']   = array_slice( $clean['focus'], 0, 10 );
        $clean['focus_multi'] = ! empty( $d['focus_multi'] );
        foreach ( (array) ( $d['numbers'] ?? array() ) as $n => $k ) {
            if ( (int) $n > 0 && (int) $n <= 500 && self::valid_key( $k ) ) {
                $clean['numbers'][ (int) $n ] = $k;
            }
        }
        $l = $d['list'] ?? null;
        if ( is_array( $l ) && isset( $l['query'], $l['ids'], $l['cursor'], $l['gen'], $l['sig'] ) ) {
            $ids = array_values( array_filter( (array) $l['ids'], array( __CLASS__, 'valid_key' ) ) );
            $clean['list'] = array(
                'query'  => substr( sanitize_text_field( (string) $l['query'] ), 0, 300 ),
                'ids'    => array_slice( $ids, 0, self::MAX_IDS ),
                'cursor' => max( 0, min( count( $ids ), (int) $l['cursor'] ) ),
                'next'   => max( 1, (int) ( $l['next'] ?? 1 ) ),
                'shown'  => array_values( array_filter( (array) ( $l['shown'] ?? array() ), array( __CLASS__, 'valid_key' ) ) ),
                'gen'    => (int) $l['gen'],
                'sig'    => (string) $l['sig'],
                'last'   => array( (int) ( $l['last'][0] ?? 0 ), (int) ( $l['last'][1] ?? 0 ) ),
            );
        }
        return $clean;
    }

    public function save() {
        if ( '' === $this->key ) {
            return;
        }
        $this->data['at'] = time();
        set_transient( $this->key, $this->data, self::TTL );
    }

    /** A new list starts: the cursor and numbering start over. */
    public function start_list( $query, $ids ) {
        $this->data['list'] = array(
            'query'  => (string) $query,
            'ids'    => array_slice( array_values( $ids ), 0, self::MAX_IDS ),
            'cursor' => 0,
            'next'   => 1,
            'shown'  => array(),
            'gen'    => WP_AI_Chatbot_Knowledge_Index::cache_generation(),
            'sig'    => WP_AI_Chatbot_AI::embedding_signature(),
            'last'   => array( 0, 0 ),
        );
        $this->data['numbers'] = array();
    }

    /** Does the stored list still match the index (no content change, same embedding model)? */
    public function list_current() {
        $l = $this->data['list'];
        return $l && WP_AI_Chatbot_Knowledge_Index::cache_generation() === $l['gen'] && WP_AI_Chatbot_AI::embedding_signature() === $l['sig'];
    }

    /** Records which items a list page showed, with their numbers, and moves the cursor past everything looked at. */
    public function list_shown( $shown_keys, $new_cursor ) {
        $l = &$this->data['list'];
        $first = $l['next'];
        foreach ( $shown_keys as $k ) {
            $this->data['numbers'][ $l['next'] ] = $k;
            $l['shown'][]                        = $k;
            $l['next']++;
        }
        $l['cursor'] = $new_cursor;
        $l['last']   = array( $first, $l['next'] - 1 );
        $this->set_focus( $shown_keys, true );
    }

    public function set_focus( $keys, $multi ) {
        $keys = array_values( array_unique( array_filter( (array) $keys, array( __CLASS__, 'valid_key' ) ) ) );
        if ( $keys ) {
            $this->data['focus']       = array_slice( $keys, 0, 10 );
            $this->data['focus_multi'] = $multi || count( $keys ) > 1;
        }
    }

    /**
     * Item number N. A bare ordinal ("the second one") counts within the last
     * page shown; an explicit number ("number 12", "#12") is the overall number.
     */
    public function item( $n, $absolute ) {
        $nums = $this->data['numbers'];
        if ( empty( $nums ) ) {
            return '';
        }
        if ( -1 === $n ) {
            $last = $this->data['list']['last'][1] ?? max( array_keys( $nums ) );
            return $nums[ $last ] ?? '';
        }
        if ( ! $absolute && ! empty( $this->data['list']['last'][0] ) ) {
            $n = $this->data['list']['last'][0] + $n - 1;
        }
        return $nums[ $n ] ?? '';
    }
}
