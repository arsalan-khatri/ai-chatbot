<?php
/**
 * Chat contacts: links a chat to the person behind it.
 *
 * When a visitor fills any built-in lead form (or the pre-chat form) inside
 * the chat, the name, email and phone from that form are saved against the
 * chat's session, so Chat History shows "Ali Khan" instead of "User-5A689".
 * A later form in the same chat fills in what is still missing.
 *
 * Table wp_ai_chat_contacts (one row per chat session). Chats without a form
 * keep their ID.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Chat_Contacts {

	const SCHEMA = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_chat_contacts';
	}

	public static function ensure_table() {
		if ( get_option( 'wp_ai_chat_contacts_schema' ) === self::SCHEMA ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
				session_id varchar(100) NOT NULL,
				name varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(60) NOT NULL DEFAULT '',
				form_id varchar(100) NOT NULL DEFAULT '',
				form_title varchar(190) NOT NULL DEFAULT '',
				lead_id bigint(20) NOT NULL DEFAULT 0,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY  (session_id)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'wp_ai_chat_contacts_schema', self::SCHEMA, false );
	}

	/**
	 * Picks name, email and phone out of a submitted form.
	 *
	 * @param array $form_def Form definition (fields with label and type).
	 * @param array $values   Saved values, by column (slug of the label).
	 * @return array name, email, phone
	 */
	public static function extract( $form_def, $values ) {
		$out   = array( 'name' => '', 'email' => '', 'phone' => '' );
		$first = '';
		$last  = '';
		foreach ( (array) $form_def['fields'] as $field ) {
			if ( empty( $field['label'] ) ) {
				continue;
			}
			$col   = sanitize_key( str_replace( ' ', '_', strtolower( $field['label'] ) ) );
			$val   = isset( $values[ $col ] ) ? trim( (string) $values[ $col ] ) : '';
			$label = strtolower( $field['label'] );
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			if ( '' === $val ) {
				continue;
			}
			if ( '' === $out['email'] && ( 'email' === $type || preg_match( '/e-?mail/', $label ) ) && is_email( $val ) ) {
				$out['email'] = $val;
				continue;
			}
			if ( '' === $out['phone'] && ( 'tel' === $type || preg_match( '/phone|mobile|whats\s*app|cell|contact\s*(no|number)|\bnumber\b|\bfon\b/', $label ) ) && preg_match_all( '/\d/', $val ) >= 7 ) {
				$out['phone'] = $val;
				continue;
			}
			if ( in_array( $type, array( 'text', '' ), true ) && preg_match( '/\bname\b|naam|\bnom\b|nombre/', $label ) && ! preg_match( '/company|business|product|shop|store|brand|user\s*name|file|project|website|domain|pet|baby|father|mother|guardian/', $label ) ) {
				if ( preg_match( '/\bfirst\b/', $label ) ) {
					$first = $val;
				} elseif ( preg_match( '/\b(last|sur)\b|surname/', $label ) ) {
					$last = $val;
				} elseif ( '' === $out['name'] ) {
					$out['name'] = $val;
				}
			}
		}
		if ( '' === $out['name'] && ( '' !== $first || '' !== $last ) ) {
			$out['name'] = trim( $first . ' ' . $last );
		}
		$out['name'] = mb_substr( wp_strip_all_tags( $out['name'] ), 0, 120 );
		return $out;
	}

	/** Saves who is behind a chat after one of its forms was submitted. */
	public static function remember( $session_id, $form_def, $values, $lead_id ) {
		if ( '' === (string) $session_id ) {
			return;
		}
		$found = self::extract( $form_def, $values );
		if ( '' === $found['name'] && '' === $found['email'] && '' === $found['phone'] ) {
			return;
		}
		self::ensure_table();
		global $wpdb;
		$table = self::table();
		$old   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s", $session_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = array(
			'session_id' => $session_id,
			'name'       => '' !== $found['name'] ? $found['name'] : ( $old['name'] ?? '' ),
			'email'      => '' !== $found['email'] ? $found['email'] : ( $old['email'] ?? '' ),
			'phone'      => '' !== $found['phone'] ? $found['phone'] : ( $old['phone'] ?? '' ),
			'form_id'    => (string) $form_def['id'],
			'form_title' => isset( $form_def['title'] ) ? mb_substr( (string) $form_def['title'], 0, 180 ) : '',
			'lead_id'    => (int) $lead_id,
			'updated_at' => current_time( 'mysql' ),
		);
		$wpdb->replace( $table, $row );
	}

	/** @return array session_id => row (name, email, phone, form_id, form_title, lead_id) */
	public static function for_sessions( $session_ids ) {
		global $wpdb;
		$table = self::table();
		if ( empty( $session_ids ) || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id IN ({$ph})", $session_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->session_id ] = $r;
		}
		return $out;
	}
}
