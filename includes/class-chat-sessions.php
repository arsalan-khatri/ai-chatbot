<?php
/**
 * Chat sessions (2.0.0): one row per conversation with what the other
 * features need to know about it.
 *
 * - channel: web | whatsapp, contact: the WhatsApp number
 * - bot_id: which bot answered (Bots), lang: page language (WPML / Polylang)
 * - mode: bot | waiting (visitor asked for a person) | human (an agent took over)
 * - summary, score, label (hot / warm / cold), intent: AI summary and lead score
 *
 * The messages themselves stay in wp_ai_chat_logs, as before.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Sessions {

	const SCHEMA = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_chat_sessions';
	}

	public static function ensure_table() {
		if ( get_option( 'wp_ai_chat_sessions_schema' ) === self::SCHEMA ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				session_id varchar(100) NOT NULL,
				channel varchar(20) NOT NULL DEFAULT 'web',
				contact varchar(60) NOT NULL DEFAULT '',
				bot_id varchar(40) NOT NULL DEFAULT '',
				lang varchar(20) NOT NULL DEFAULT '',
				mode varchar(10) NOT NULL DEFAULT 'bot',
				agent_id bigint(20) NOT NULL DEFAULT 0,
				page_url varchar(255) NOT NULL DEFAULT '',
				msgs int(11) NOT NULL DEFAULT 0,
				last_at datetime DEFAULT NULL,
				requested_at datetime DEFAULT NULL,
				summary text,
				score smallint(6) NOT NULL DEFAULT -1,
				label varchar(10) NOT NULL DEFAULT '',
				intent varchar(30) NOT NULL DEFAULT '',
				summarized_msgs int(11) NOT NULL DEFAULT 0,
				summarized_at datetime DEFAULT NULL,
				PRIMARY KEY  (session_id),
				KEY mode_idx (mode),
				KEY last_idx (last_at),
				KEY channel_idx (channel)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'wp_ai_chat_sessions_schema', self::SCHEMA, false );
	}

	/** @return object|null */
	public static function get( $session_id ) {
		global $wpdb;
		if ( '' === (string) $session_id ) {
			return null;
		}
		self::ensure_table();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE session_id = %s', (string) $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Creates the row if needed and updates the given columns. */
	public static function update( $session_id, $fields ) {
		global $wpdb;
		if ( '' === (string) $session_id ) {
			return;
		}
		self::ensure_table();
		$allowed = array( 'channel', 'contact', 'bot_id', 'lang', 'mode', 'agent_id', 'page_url', 'last_at', 'requested_at', 'summary', 'score', 'label', 'intent', 'summarized_msgs', 'summarized_at' );
		$fields  = array_intersect_key( (array) $fields, array_flip( $allowed ) );
		if ( null === self::get( $session_id ) ) {
			$wpdb->insert( self::table(), array_merge( array( 'session_id' => (string) $session_id, 'last_at' => current_time( 'mysql' ) ), $fields ) );
			return;
		}
		if ( $fields ) {
			$wpdb->update( self::table(), $fields, array( 'session_id' => (string) $session_id ) );
		}
	}

	/** Called for every message: counts it and remembers where it came from. */
	public static function touch( $session_id, $fields = array() ) {
		global $wpdb;
		self::update( $session_id, array_merge( array( 'last_at' => current_time( 'mysql' ) ), $fields ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET msgs = msgs + 1 WHERE session_id = %s', (string) $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function mode( $session_id ) {
		$s = self::get( $session_id );
		return $s && in_array( $s->mode, array( 'bot', 'waiting', 'human' ), true ) ? $s->mode : 'bot';
	}

	/** @return array session_id => row */
	public static function for_sessions( $session_ids ) {
		global $wpdb;
		$session_ids = array_values( array_filter( array_map( 'strval', (array) $session_ids ) ) );
		if ( ! $session_ids ) {
			return array();
		}
		self::ensure_table();
		$ph   = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE session_id IN ({$ph})", $session_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->session_id ] = $r;
		}
		return $out;
	}

	/** Messages of one chat, oldest first (internal markers included). */
	public static function messages( $session_id, $limit = 200, $after_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_chat_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_message, bot_reply, created_at FROM {$table} WHERE session_id = %s AND id > %d ORDER BY id DESC LIMIT %d", (string) $session_id, (int) $after_id, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_reverse( (array) $rows );
	}

	/**
	 * Recent visitor/bot messages of a chat in the widget's history format
	 * (array( sender, text )), for channels that keep no history in the browser (WhatsApp).
	 */
	public static function history( $session_id, $limit = 12 ) {
		$out = array();
		foreach ( self::messages( $session_id, $limit ) as $row ) {
			if ( self::is_marker( $row->user_message ) && 0 !== strpos( $row->user_message, '[AGENT]:' ) ) {
				continue;
			}
			if ( 0 === strpos( $row->user_message, '[AGENT]:' ) ) {
				$out[] = array( 'sender' => 'bot', 'text' => (string) $row->bot_reply );
				continue;
			}
			if ( '' !== trim( $row->user_message ) ) {
				$out[] = array( 'sender' => 'user', 'text' => (string) $row->user_message );
			}
			if ( '' !== trim( $row->bot_reply ) ) {
				$out[] = array( 'sender' => 'bot', 'text' => (string) $row->bot_reply );
			}
		}
		return $out;
	}

	/** Internal log rows that are not visitor messages. */
	public static function is_marker( $user_message ) {
		foreach ( array( '[FORM_SHOWN]:', '[SYSTEM_ACTION]:', '[AGENT]:', '[LIVE]:' ) as $m ) {
			if ( 0 === strpos( (string) $user_message, $m ) ) {
				return true;
			}
		}
		return false;
	}
}
