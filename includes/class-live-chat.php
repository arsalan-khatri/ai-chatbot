<?php
/**
 * Live chat handoff (2.0.0): a person from the team takes over a chat.
 *
 * - The visitor asks for a person ("talk to a human", "insaan se baat", the
 *   "Talk to a person" button) or the bot could not answer: the chat is marked
 *   "waiting" and shows in AI Chatbot > Live Chat, and the team gets an email.
 * - An agent opens the chat, takes it over (or just replies) and types. The
 *   reply appears in the visitor's chat within seconds (the widget checks for
 *   new replies), or is sent on WhatsApp for WhatsApp chats.
 * - While a person handles the chat the bot stays quiet; "Hand back to bot"
 *   lets the bot answer again.
 * - Nobody online (no agent has the Live Chat page open): the visitor is told
 *   when the team will reply, and the bot keeps answering in the meantime.
 *
 * Messages are stored in the normal chat log: agent replies as "[AGENT]:<name>"
 * rows, visitor messages while a person handles the chat with an empty bot reply.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Live_Chat {

	const OPTION    = 'wp_ai_chatbot_live';
	const HEARTBEAT = 'wp_ai_chatbot_live_heartbeat';
	const PAGE      = 'wp-ai-chatbot-live';
	const ONLINE_SECONDS = 75;

	const DEFAULT_REQUEST_WORDS = 'human, person, agent, real person, representative, customer care, customer service, support team, insaan, banda, bande se, admin se baat, kisi se baat, team se baat, call me, talk to someone';
	const DEFAULT_ONLINE_MSG    = 'Sure! I am connecting you with a member of our team. Please stay in this chat; they will reply here in a moment.';
	const DEFAULT_OFFLINE_MSG   = 'Our team is not online right now, but your message has been passed on and we will reply as soon as possible. You can also leave your phone number or email here so we can get back to you.';

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'add_submenu' ), 25 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		foreach ( array( 'list', 'thread', 'send', 'mode' ) as $a ) {
			add_action( 'wp_ajax_wp_ai_live_' . $a, array( $this, 'ajax_' . $a ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function settings() {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array_merge(
			array(
				'enabled'       => '1',
				'words'         => self::DEFAULT_REQUEST_WORDS,
				'online_msg'    => '',
				'offline_msg'   => '',
				'notify'        => '1',
				'offer_button'  => '1',
				'button_label'  => 'Talk to a person',
			),
			$o
		);
	}

	public static function enabled() {
		return '1' === (string) self::settings()['enabled'];
	}

	public function register_settings() {
		register_setting( 'wp_ai_chatbot_live_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = self::settings();
		foreach ( array( 'enabled', 'notify', 'offer_button' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? '0' : '1';
		}
		$out['words']        = implode( ', ', array_filter( array_map( 'trim', preg_split( '/[,\n]+/u', sanitize_textarea_field( (string) ( $in['words'] ?? '' ) ) ) ) ) );
		$out['online_msg']   = sanitize_textarea_field( (string) ( $in['online_msg'] ?? '' ) );
		$out['offline_msg']  = sanitize_textarea_field( (string) ( $in['offline_msg'] ?? '' ) );
		$out['button_label'] = sanitize_text_field( (string) ( $in['button_label'] ?? '' ) );
		if ( '' === $out['button_label'] ) {
			$out['button_label'] = 'Talk to a person';
		}
		return $out;
	}

	public static function online_message() {
		$m = trim( (string) self::settings()['online_msg'] );
		return WP_AI_Chatbot_I18n::t( 'Live chat: connecting message', '' !== $m ? $m : self::DEFAULT_ONLINE_MSG );
	}

	public static function offline_message() {
		$m = trim( (string) self::settings()['offline_msg'] );
		return WP_AI_Chatbot_I18n::t( 'Live chat: offline message', '' !== $m ? $m : self::DEFAULT_OFFLINE_MSG );
	}

	/* ------------------------------------------------------------------
	 * Agents online
	 * ------------------------------------------------------------------ */

	/** Marks the current admin as online (the Live Chat page is open). */
	private static function heartbeat() {
		$beats = get_option( self::HEARTBEAT, array() );
		$beats = is_array( $beats ) ? $beats : array();
		$beats[ get_current_user_id() ] = time();
		foreach ( $beats as $uid => $t ) {
			if ( time() - (int) $t > DAY_IN_SECONDS ) {
				unset( $beats[ $uid ] );
			}
		}
		update_option( self::HEARTBEAT, $beats, false );
	}

	/** True when someone had the Live Chat page open in the last minute or so. */
	public static function agents_online() {
		$beats = get_option( self::HEARTBEAT, array() );
		foreach ( (array) $beats as $t ) {
			if ( time() - (int) $t <= self::ONLINE_SECONDS ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Visitor side
	 * ------------------------------------------------------------------ */

	/** Does the message ask for a person? */
	public static function asks_for_person( $message ) {
		$words = array_filter( array_map( 'trim', explode( ',', (string) self::settings()['words'] ) ) );
		$m     = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $message ) : strtolower( (string) $message );
		foreach ( $words as $w ) {
			$w = function_exists( 'mb_strtolower' ) ? mb_strtolower( $w ) : strtolower( $w );
			if ( '' !== $w && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/u', $m ) ) {
				// One word ("person", "agent") also appears in other questions ("price per person",
				// "travel agent"): it only counts next to words like talk, speak, connect, real.
				if ( false === strpos( $w, ' ' ) && ! preg_match( '/(?<![\p{L}])(talk|speak|chat|connect|transfer|contact|call|baat|bat|real|live|actual|kisi|someone|somebody)(?![\p{L}])/u', $m ) ) {
					continue;
				}
				// "Are you a human?" asks about the bot, not for a person.
				if ( preg_match( '/\b(are|r)\s+(you|u)\s+(a\s+)?(human|person|bot|real)\b/i', $m ) ) {
					return false;
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Runs before the AI. Returns null to let the bot answer, or an array:
	 * reply (text to show; '' = nothing, a person will answer) and mode.
	 */
	public static function before_ai( $session_id, $message ) {
		if ( ! self::enabled() || '' === (string) $session_id ) {
			return null;
		}
		$mode = WP_AI_Chatbot_Sessions::mode( $session_id );
		if ( 'human' === $mode ) {
			return array( 'reply' => '', 'mode' => 'human' );
		}
		if ( 'waiting' === $mode && self::agents_online() ) {
			return array( 'reply' => '', 'mode' => 'waiting' );
		}
		if ( 'bot' === $mode && self::asks_for_person( $message ) ) {
			return self::request( $session_id );
		}
		return null;
	}

	/** The visitor wants a person: mark the chat as waiting and tell the team. */
	public static function request( $session_id ) {
		$online = self::agents_online();
		WP_AI_Chatbot_Sessions::update( $session_id, array( 'mode' => 'waiting', 'requested_at' => current_time( 'mysql' ) ) );
		self::notify( $session_id );
		return array( 'reply' => $online ? self::online_message() : self::offline_message(), 'mode' => 'waiting' );
	}

	/** Email to the lead-alert address(es): a visitor is waiting. At most once per chat every 30 minutes. */
	private static function notify( $session_id ) {
		if ( '1' !== (string) self::settings()['notify'] || ! class_exists( 'WP_AI_Chatbot_Lead_Alerts' ) ) {
			return;
		}
		$key = 'wp_ai_live_notified_' . md5( $session_id );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 30 * MINUTE_IN_SECONDS );
		$o = WP_AI_Chatbot_Lead_Alerts::get();
		if ( '' === trim( (string) $o['alert_to'] ) ) {
			return;
		}
		$who  = self::display_name( $session_id );
		$url  = admin_url( 'admin.php?page=' . self::PAGE . '&chat=' . rawurlencode( $session_id ) );
		$chat = '';
		foreach ( WP_AI_Chatbot_Sessions::history( $session_id, 6 ) as $m ) {
			$chat .= '<p style="margin:0 0 6px;"><strong>' . ( 'user' === $m['sender'] ? 'Visitor' : 'Bot' ) . ':</strong> ' . esc_html( wp_trim_words( $m['text'], 40 ) ) . '</p>';
		}
		$html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111;max-width:600px;">'
			. '<h2 style="margin:0 0 8px;border-top:4px solid #fd9400;padding-top:14px;">A visitor wants to talk to a person</h2>'
			. '<p>' . esc_html( $who ) . ' asked for a team member in the chat.</p>'
			. '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:10px 16px;background:#fd9400;color:#0a0a0a;border-radius:6px;text-decoration:none;font-weight:bold;">Open Live Chat and reply</a></p>'
			. ( '' !== $chat ? '<div style="background:#f7f7f8;border-radius:8px;padding:12px 14px;">' . $chat . '</div>' : '' )
			. '</div>';
		$job = function () use ( $o, $who, $html ) {
			WP_AI_Chatbot_Lead_Alerts::send( $o['alert_to'], 'Live chat: ' . $who . ' is waiting', $html );
		};
		if ( did_action( 'shutdown' ) ) {
			$job();
		} else {
			add_action( 'shutdown', $job );
		}
	}

	/** Name, email or phone of the visitor, or their chat ID. */
	public static function display_name( $session_id ) {
		$contacts = WP_AI_Chatbot_Chat_Contacts::for_sessions( array( $session_id ) );
		if ( isset( $contacts[ $session_id ] ) ) {
			$c = $contacts[ $session_id ];
			foreach ( array( 'name', 'email', 'phone' ) as $k ) {
				if ( '' !== trim( (string) $c->$k ) ) {
					return (string) $c->$k;
				}
			}
		}
		$s = WP_AI_Chatbot_Sessions::get( $session_id );
		if ( $s && '' !== $s->contact ) {
			return '+' . ltrim( $s->contact, '+' );
		}
		return 'User-' . strtoupper( substr( (string) $session_id, 0, 5 ) );
	}

	public function register_routes() {
		register_rest_route(
			'wp-ai-chatbot/v1',
			'/live',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_poll' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_request' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	private static function visitor_session() {
		$sid = isset( $_COOKIE['wp_ai_chat_session'] ) ? strtolower( (string) wp_unslash( $_COOKIE['wp_ai_chat_session'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return WP_AI_Chatbot_Chat_Renderer::valid_session_id( $sid ) ? $sid : '';
	}

	/** GET /live?after=ID: agent replies newer than ID, and the chat's mode. */
	public function rest_poll( WP_REST_Request $request ) {
		$sid   = self::visitor_session();
		$after = max( 0, (int) $request->get_param( 'after' ) );
		$out   = array( 'mode' => 'bot', 'messages' => array(), 'last_id' => $after, 'online' => self::enabled() && self::agents_online() );
		if ( '' !== $sid && self::enabled() ) {
			$out['mode'] = WP_AI_Chatbot_Sessions::mode( $sid );
			if ( 0 === $after ) {
				// First check of this page: only the current position, old replies are already in the chat.
				global $wpdb;
				$out['last_id'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . $wpdb->prefix . 'ai_chat_logs WHERE session_id = %s', $sid ) );
			} else {
				foreach ( WP_AI_Chatbot_Sessions::messages( $sid, 50, $after ) as $row ) {
					$out['last_id'] = max( $out['last_id'], (int) $row->id );
					if ( 0 === strpos( $row->user_message, '[AGENT]:' ) ) {
						$out['messages'][] = array(
							'id'    => (int) $row->id,
							'agent' => substr( $row->user_message, 8 ),
							'text'  => (string) $row->bot_reply,
						);
					} elseif ( 0 === strpos( $row->user_message, '[LIVE]: ' ) && false === strpos( $row->user_message, 'Visitor asked' ) ) {
						// "Sara joined the chat", "The bot is answering again".
						$out['messages'][] = array(
							'id'   => (int) $row->id,
							'type' => 'note',
							'text' => substr( $row->user_message, 8 ),
						);
					}
				}
			}
		}
		$res = rest_ensure_response( $out );
		$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $res;
	}

	/** POST /live: the "Talk to a person" button. */
	public function rest_request( WP_REST_Request $request ) {
		$ip = WP_AI_Chatbot_Rate_Limiter::get_client_ip();
		if ( ! self::enabled() || ! WP_AI_Chatbot_Rate_Limiter::allow( 'live_req_' . $ip, 6, 300 ) ) {
			return new WP_Error( 'not_available', 'Live chat is not available right now.', array( 'status' => 429 ) );
		}
		$sid = self::visitor_session();
		if ( '' === $sid ) {
			return new WP_Error( 'no_session', 'Please send a message first.', array( 'status' => 400 ) );
		}
		$res = self::request( $sid );
		WP_AI_Chatbot_Logger::log_chat( $sid, '[LIVE]: Visitor asked for a person', $res['reply'] );
		WP_AI_Chatbot_Sessions::touch( $sid );
		return rest_ensure_response( array( 'success' => true, 'reply' => $res['reply'], 'mode' => $res['mode'] ) );
	}

	/** Data for the widget. */
	public static function widget_data() {
		$s = self::settings();
		return array(
			'live_enabled' => self::enabled() ? '1' : '0',
			'live_url'     => self::enabled() ? esc_url_raw( rest_url( 'wp-ai-chatbot/v1/live' ) ) : '',
			'live_button'  => self::enabled() && '1' === (string) $s['offer_button'] ? WP_AI_Chatbot_I18n::t( 'Live chat: button', (string) $s['button_label'] ) : '',
		);
	}

	/* ------------------------------------------------------------------
	 * Agent side (admin)
	 * ------------------------------------------------------------------ */

	public static function waiting_count() {
		global $wpdb;
		if ( get_option( 'wp_ai_chat_sessions_schema' ) !== WP_AI_Chatbot_Sessions::SCHEMA ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WP_AI_Chatbot_Sessions::table() . " WHERE mode = 'waiting'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function add_submenu() {
		$n     = self::waiting_count();
		$label = 'Live Chat' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' );
		add_submenu_page( 'wp-ai-chatbot', 'Live Chat', $label, 'manage_options', self::PAGE, array( $this, 'display_page' ) );
	}

	private function guard() {
		check_ajax_referer( 'wp_ai_live', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
	}

	/** Chats of the last 2 days (waiting first), plus a heartbeat for "agents online". */
	public function ajax_list() {
		$this->guard();
		self::heartbeat();
		global $wpdb;
		WP_AI_Chatbot_Sessions::ensure_table();
		$logs  = $wpdb->prefix . 'ai_chat_logs';
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT session_id, MAX(id) AS last_id, MAX(created_at) AS last_at FROM {$logs} WHERE created_at >= %s GROUP BY session_id ORDER BY last_at DESC LIMIT 80", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids   = wp_list_pluck( (array) $rows, 'session_id' );
		$meta  = WP_AI_Chatbot_Sessions::for_sessions( $ids );
		// Waiting chats older than 2 days still need an answer.
		foreach ( (array) $wpdb->get_col( 'SELECT session_id FROM ' . WP_AI_Chatbot_Sessions::table() . " WHERE mode IN ('waiting','human')" ) as $sid ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! in_array( $sid, $ids, true ) ) {
				$last = $wpdb->get_row( $wpdb->prepare( "SELECT MAX(id) AS last_id, MAX(created_at) AS last_at FROM {$logs} WHERE session_id = %s", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows[] = (object) array( 'session_id' => $sid, 'last_id' => $last ? $last->last_id : 0, 'last_at' => $last ? $last->last_at : '' );
				$ids[]  = $sid;
			}
		}
		$meta  = WP_AI_Chatbot_Sessions::for_sessions( $ids );
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$m    = isset( $meta[ $r->session_id ] ) ? $meta[ $r->session_id ] : null;
			$last = $wpdb->get_row( $wpdb->prepare( "SELECT user_message, bot_reply FROM {$logs} WHERE id = %d", (int) $r->last_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$text = '';
			if ( $last ) {
				$text = 0 === strpos( $last->user_message, '[AGENT]:' ) ? 'You: ' . $last->bot_reply : ( WP_AI_Chatbot_Sessions::is_marker( $last->user_message ) ? $last->bot_reply : $last->user_message );
			}
			$out[] = array(
				'id'      => $r->session_id,
				'name'    => self::display_name( $r->session_id ),
				'mode'    => $m ? $m->mode : 'bot',
				'channel' => $m ? $m->channel : 'web',
				'label'   => $m ? $m->label : '',
				'last'    => wp_html_excerpt( wp_strip_all_tags( (string) $text ), 70, '…' ),
				'at'      => $r->last_at,
				'ago'     => $r->last_at ? human_time_diff( strtotime( $r->last_at ), current_time( 'timestamp' ) ) : '',
				'last_id' => (int) $r->last_id,
			);
		}
		$order = array( 'waiting' => 0, 'human' => 1, 'bot' => 2 );
		usort( $out, function ( $a, $b ) use ( $order ) {
			$x = $order[ $a['mode'] ] ?? 2;
			$y = $order[ $b['mode'] ] ?? 2;
			return $x === $y ? strcmp( $b['at'], $a['at'] ) : $x - $y;
		} );
		wp_send_json_success( array( 'chats' => $out, 'waiting' => self::waiting_count() ) );
	}

	/** Messages of one chat. */
	public function ajax_thread() {
		$this->guard();
		$sid = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		if ( '' === $sid ) {
			wp_send_json_error( 'No chat.' );
		}
		$msgs = array();
		foreach ( WP_AI_Chatbot_Sessions::messages( $sid, 200 ) as $row ) {
			$time = mysql2date( 'H:i', $row->created_at );
			if ( 0 === strpos( $row->user_message, '[AGENT]:' ) ) {
				$msgs[] = array( 'id' => (int) $row->id, 'who' => 'agent', 'name' => substr( $row->user_message, 8 ), 'text' => $row->bot_reply, 'time' => $time );
				continue;
			}
			if ( 0 === strpos( $row->user_message, '[FORM_SHOWN]:' ) ) {
				$msgs[] = array( 'id' => (int) $row->id, 'who' => 'system', 'text' => 'Form shown: ' . $row->bot_reply, 'time' => $time );
				continue;
			}
			if ( 0 === strpos( $row->user_message, '[LIVE]:' ) || 0 === strpos( $row->user_message, '[SYSTEM_ACTION]:' ) ) {
				$msgs[] = array( 'id' => (int) $row->id, 'who' => 'system', 'text' => trim( preg_replace( '/^\[[A-Z_]+\]:\s*/', '', $row->user_message ) ), 'time' => $time );
			} elseif ( '' !== trim( $row->user_message ) ) {
				$msgs[] = array( 'id' => (int) $row->id, 'who' => 'visitor', 'text' => $row->user_message, 'time' => $time );
			}
			if ( '' !== trim( $row->bot_reply ) ) {
				$msgs[] = array( 'id' => (int) $row->id, 'who' => 'bot', 'text' => $row->bot_reply, 'time' => $time );
			}
		}
		$s = WP_AI_Chatbot_Sessions::get( $sid );
		wp_send_json_success( array(
			'messages' => $msgs,
			'mode'     => $s ? $s->mode : 'bot',
			'channel'  => $s ? $s->channel : 'web',
			'name'     => self::display_name( $sid ),
			'summary'  => $s && $s->summary ? $s->summary : '',
			'label'    => $s ? $s->label : '',
			'page'     => $s ? $s->page_url : '',
		) );
	}

	/** An agent's reply. Taking over is automatic when the agent writes. */
	public function ajax_send() {
		$this->guard();
		$sid  = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		$text = isset( $_POST['text'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) ) : '';
		if ( '' === $sid || '' === $text ) {
			wp_send_json_error( 'Write a message first.' );
		}
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 4000 ) : substr( $text, 0, 4000 );
		$user = wp_get_current_user();
		$name = $user && $user->display_name ? $user->display_name : 'Team';
		$s    = WP_AI_Chatbot_Sessions::get( $sid );
		if ( $s && 'whatsapp' === $s->channel && class_exists( 'WP_AI_Chatbot_WhatsApp_AI' ) ) {
			$sent = WP_AI_Chatbot_WhatsApp_AI::send_text( $s->contact, $text );
			if ( is_wp_error( $sent ) ) {
				wp_send_json_error( 'WhatsApp did not accept the message: ' . $sent->get_error_message() );
			}
		}
		WP_AI_Chatbot_Logger::log_chat( $sid, '[AGENT]:' . $name, $text );
		WP_AI_Chatbot_Sessions::update( $sid, array( 'mode' => 'human', 'agent_id' => get_current_user_id(), 'last_at' => current_time( 'mysql' ) ) );
		wp_send_json_success();
	}

	/** Take over / hand back to the bot. */
	public function ajax_mode() {
		$this->guard();
		$sid  = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( '' === $sid || ! in_array( $mode, array( 'bot', 'human' ), true ) ) {
			wp_send_json_error( 'Unknown action.' );
		}
		WP_AI_Chatbot_Sessions::update( $sid, array( 'mode' => $mode, 'agent_id' => 'human' === $mode ? get_current_user_id() : 0 ) );
		$note = 'human' === $mode ? '[LIVE]: ' . wp_get_current_user()->display_name . ' joined the chat' : '[LIVE]: The bot is answering again';
		WP_AI_Chatbot_Logger::log_chat( $sid, $note, '' );
		wp_send_json_success( array( 'mode' => $mode ) );
	}

	public function display_page() {
		$s = self::settings();
		$n = self::OPTION;
		?>
		<div class="wrap akdk-wrap akdk-live">
			<h1 class="akdk-page-title">Live Chat</h1>
			<p class="akdk-page-intro">Answer visitors yourself. Chats where a visitor asked for a person are at the top. While this page is open you are shown as <strong>online</strong>; when you write in a chat, the bot stops answering that chat until you hand it back.</p>
			<?php settings_errors(); ?>
			<div class="akdk-live-app" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_ai_live' ) ); ?>" data-open="<?php echo esc_attr( isset( $_GET['chat'] ) ? sanitize_text_field( wp_unslash( $_GET['chat'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
				<div class="akdk-live-list">
					<div class="akdk-live-list-head"><span class="akdk-live-dot"></span> You are online <span class="akdk-live-count"></span></div>
					<div class="akdk-live-items"><p class="akdk-live-empty">Loading chats…</p></div>
				</div>
				<div class="akdk-live-thread">
					<div class="akdk-live-thread-head">
						<div><strong class="akdk-live-name">Select a chat</strong> <span class="akdk-live-badge"></span><div class="akdk-live-summary"></div></div>
						<div class="akdk-live-actions" hidden>
							<button type="button" class="button akdk-live-take">Take over</button>
							<button type="button" class="button akdk-live-back">Hand back to bot</button>
						</div>
					</div>
					<div class="akdk-live-msgs"></div>
					<form class="akdk-live-form" hidden>
						<textarea rows="2" placeholder="Type your reply… (Enter to send, Shift+Enter for a new line)"></textarea>
						<button type="submit" class="button button-primary">Send</button>
					</form>
				</div>
			</div>

			<details class="akdk-live-settings">
				<summary><strong>Live chat settings</strong></summary>
				<form method="post" action="options.php">
					<?php settings_fields( 'wp_ai_chatbot_live_group' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row">Live chat</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( '1', $s['enabled'] ); ?>> Visitors can ask to talk to a person</label></td></tr>
						<tr><th scope="row">Button</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[offer_button]" value="1" <?php checked( '1', $s['offer_button'] ); ?>> Show a button when the bot could not answer</label><br><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[button_label]" value="<?php echo esc_attr( $s['button_label'] ); ?>"></td></tr>
						<tr><th scope="row">Words that ask for a person</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr( $n ); ?>[words]"><?php echo esc_textarea( $s['words'] ); ?></textarea><p class="description">Comma separated. A message with one of these goes to the team.</p></td></tr>
						<tr><th scope="row">Message when someone is online</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr( $n ); ?>[online_msg]" placeholder="<?php echo esc_attr( self::DEFAULT_ONLINE_MSG ); ?>"><?php echo esc_textarea( $s['online_msg'] ); ?></textarea></td></tr>
						<tr><th scope="row">Message when nobody is online</th><td><textarea class="large-text" rows="2" name="<?php echo esc_attr( $n ); ?>[offline_msg]" placeholder="<?php echo esc_attr( self::DEFAULT_OFFLINE_MSG ); ?>"><?php echo esc_textarea( $s['offline_msg'] ); ?></textarea><p class="description">Nobody online = nobody has this page open. The bot keeps answering until someone replies.</p></td></tr>
						<tr><th scope="row">Email</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[notify]" value="1" <?php checked( '1', $s['notify'] ); ?>> Email me when a visitor asks for a person</label><p class="description">Sent to the addresses under <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-email' ) ); ?>">Lead Alerts &amp; Email</a>.</p></td></tr>
					</table>
					<?php submit_button( 'Save live chat settings' ); ?>
				</form>
			</details>
		</div>
		<style>
			.akdk-live-app { display:flex; height:640px; background:#fff; border:1px solid #e7e7ea; border-radius:12px; overflow:hidden; margin-bottom:16px; }
			.akdk-live-list { width:320px; flex-shrink:0; border-right:1px solid #e7e7ea; display:flex; flex-direction:column; background:#fafafa; }
			.akdk-live-list-head { padding:12px 14px; border-bottom:1px solid #e7e7ea; background:#fff; font-weight:600; display:flex; align-items:center; gap:8px; }
			.akdk-live-dot { width:10px; height:10px; border-radius:50%; background:#16a34a; box-shadow:0 0 0 3px #dcfce7; }
			.akdk-live-count { margin-left:auto; font-size:12px; color:#92400e; }
			.akdk-live-items { overflow-y:auto; flex:1; }
			.akdk-live-empty { padding:20px; color:#71717a; text-align:center; }
			.akdk-live-item { padding:11px 14px; border-bottom:1px solid #efeff1; cursor:pointer; border-left:3px solid transparent; }
			.akdk-live-item:hover { background:#f4f4f5; }
			.akdk-live-item.is-active { background:#fff4e5; border-left-color:#fd9400; }
			.akdk-live-item.is-waiting { background:#fffbeb; }
			.akdk-live-item h4 { margin:0; font-size:14px; display:flex; gap:6px; align-items:center; }
			.akdk-live-item p { margin:4px 0 0; font-size:12.5px; color:#52525b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
			.akdk-live-item small { color:#a1a1aa; font-size:11px; margin-left:auto; font-weight:400; }
			.akdk-tag { display:inline-block; padding:1px 7px; border-radius:999px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
			.akdk-tag.waiting { background:#fde68a; color:#92400e; }
			.akdk-tag.human { background:#dbeafe; color:#1e40af; }
			.akdk-tag.whatsapp { background:#dcfce7; color:#166534; }
			.akdk-tag.hot { background:#fee2e2; color:#b91c1c; }
			.akdk-tag.warm { background:#ffedd5; color:#c2410c; }
			.akdk-tag.cold { background:#e0f2fe; color:#0369a1; }
			.akdk-live-thread { flex:1; min-width:0; display:flex; flex-direction:column; background:#f6f6f7; }
			.akdk-live-thread-head { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; padding:12px 16px; background:#fff; border-bottom:1px solid #e7e7ea; }
			.akdk-live-summary { font-size:12.5px; color:#52525b; margin-top:4px; max-width:620px; }
			.akdk-live-actions { display:flex; gap:6px; flex-shrink:0; }
			.akdk-live-msgs { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:8px; }
			.akdk-lm { max-width:72%; padding:8px 12px; border-radius:12px; font-size:13.5px; line-height:1.45; white-space:pre-wrap; word-wrap:break-word; }
			.akdk-lm small { display:block; font-size:10.5px; opacity:.65; margin-top:3px; }
			.akdk-lm.visitor { align-self:flex-start; background:#fff; border:1px solid #e4e4e7; }
			.akdk-lm.bot { align-self:flex-end; background:#f4f4f5; border:1px solid #e4e4e7; color:#3f3f46; }
			.akdk-lm.agent { align-self:flex-end; background:#0a0a0a; color:#fff; }
			.akdk-lm.system { align-self:center; background:transparent; color:#71717a; font-size:12px; padding:2px; }
			.akdk-live-form { display:flex; gap:8px; padding:10px 12px; background:#fff; border-top:1px solid #e7e7ea; }
			.akdk-live-form textarea { flex:1; border-radius:8px; resize:vertical; }
			.akdk-live-settings { background:#fff; border:1px solid #e7e7ea; border-radius:12px; padding:12px 18px; }
			.akdk-live-settings summary { cursor:pointer; }
			@media (max-width: 900px) { .akdk-live-app { flex-direction:column; height:auto; } .akdk-live-list { width:auto; max-height:260px; } .akdk-live-thread { min-height:480px; } }
		</style>
		<script>
		jQuery(function ($) {
			var $app = $('.akdk-live-app'), nonce = $app.data('nonce'), current = String($app.data('open') || ''), lastSig = '', mode = 'bot';
			function esc(t) { return $('<div/>').text(t == null ? '' : String(t)).html(); }
			function post(action, data) { return $.post(ajaxurl, $.extend({ action: 'wp_ai_live_' + action, security: nonce }, data || {})); }
			function tags(c) {
				var h = '';
				if (c.mode === 'waiting') h += '<span class="akdk-tag waiting">Waiting</span>';
				if (c.mode === 'human') h += '<span class="akdk-tag human">You</span>';
				if (c.channel === 'whatsapp') h += '<span class="akdk-tag whatsapp">WhatsApp</span>';
				if (c.label) h += '<span class="akdk-tag ' + esc(c.label) + '">' + esc(c.label) + '</span>';
				return h;
			}
			function loadList() {
				post('list').done(function (r) {
					if (!r || !r.success) return;
					var html = '';
					$.each(r.data.chats, function (_, c) {
						html += '<div class="akdk-live-item' + (c.id === current ? ' is-active' : '') + (c.mode === 'waiting' ? ' is-waiting' : '') + '" data-id="' + esc(c.id) + '">'
							+ '<h4>' + esc(c.name) + ' ' + tags(c) + '<small>' + esc(c.ago) + '</small></h4><p>' + esc(c.last) + '</p></div>';
					});
					$app.find('.akdk-live-items').html(html || '<p class="akdk-live-empty">No chats in the last 2 days.</p>');
					$app.find('.akdk-live-count').text(r.data.waiting ? r.data.waiting + ' waiting' : '');
				});
			}
			function loadThread(scroll) {
				if (!current) return;
				post('thread', { session: current }).done(function (r) {
					if (!r || !r.success) return;
					var d = r.data, sig = d.messages.length + '|' + d.mode + '|' + (d.messages.length ? d.messages[d.messages.length - 1].id : '');
					mode = d.mode;
					$app.find('.akdk-live-name').text(d.name);
					$app.find('.akdk-live-badge').html(tags(d));
					$app.find('.akdk-live-summary').text(d.summary || '');
					$app.find('.akdk-live-actions, .akdk-live-form').prop('hidden', false);
					$app.find('.akdk-live-take').toggle(d.mode !== 'human');
					$app.find('.akdk-live-back').toggle(d.mode !== 'bot');
					if (sig === lastSig) return;
					lastSig = sig;
					var html = '';
					$.each(d.messages, function (_, m) {
						var who = m.who === 'agent' ? esc(m.name) + ' (team)' : (m.who === 'bot' ? 'Bot' : (m.who === 'visitor' ? 'Visitor' : ''));
						html += '<div class="akdk-lm ' + esc(m.who) + '">' + esc(m.text) + '<small>' + who + (who ? ' · ' : '') + esc(m.time) + '</small></div>';
					});
					var $m = $app.find('.akdk-live-msgs').html(html || '<p class="akdk-live-empty">No messages.</p>');
					$m.scrollTop($m[0].scrollHeight);
				});
			}
			$app.on('click', '.akdk-live-item', function () {
				current = String($(this).data('id')); lastSig = '';
				$app.find('.akdk-live-item').removeClass('is-active'); $(this).addClass('is-active');
				loadThread(true);
			});
			$app.on('click', '.akdk-live-take, .akdk-live-back', function () {
				post('mode', { session: current, mode: $(this).hasClass('akdk-live-take') ? 'human' : 'bot' }).always(function () { lastSig = ''; loadThread(); loadList(); });
			});
			$app.find('.akdk-live-form').on('submit', function (e) {
				e.preventDefault();
				var $t = $(this).find('textarea'), text = $.trim($t.val());
				if (!text || !current) return;
				var $b = $(this).find('button').prop('disabled', true);
				post('send', { session: current, text: text }).done(function (r) {
					if (r && r.success) { $t.val(''); lastSig = ''; loadThread(); loadList(); }
					else window.alert((r && r.data) || 'Not sent.');
				}).always(function () { $b.prop('disabled', false); $t.trigger('focus'); });
			});
			$app.find('.akdk-live-form textarea').on('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $(this).closest('form').trigger('submit'); }
			});
			loadList(); if (current) loadThread(true);
			setInterval(function () { if (document.visibilityState !== 'hidden') { loadList(); loadThread(); } }, 4000);
		});
		</script>
		<?php
	}
}
