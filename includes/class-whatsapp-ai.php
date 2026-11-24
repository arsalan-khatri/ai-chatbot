<?php
/**
 * The same AI on WhatsApp (2.0.0).
 *
 * Customers write to your WhatsApp number; the bot answers with the same
 * knowledge, instructions, order status and live chat as on the website.
 * Chats show in Chat History and Live Chat with a WhatsApp badge, and a team
 * member can reply from Live Chat (the reply is sent on WhatsApp).
 *
 * Connections (AI Chatbot > Sales Tools > WhatsApp AI):
 *   - cloud:     Meta's official WhatsApp Business Cloud API.
 *   - waha:      WAHA (WhatsApp HTTP API, self-hosted, normal WhatsApp by QR code).
 *   - evolution: Evolution API (self-hosted, normal WhatsApp by QR code).
 *   - custom:    any other gateway (wppconnect, UltraMsg, Green API, n8n ...):
 *                it posts {"from","text","id","name"} to the webhook and the
 *                plugin posts {"to","text"} to your send URL.
 *
 * Gateways call the webhook with a secret key in the address (?key=...), so
 * nobody else can make the bot send messages.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_WhatsApp_AI {

	const OPTION  = 'wp_ai_chatbot_wa_ai';
	const ROUTE   = '/whatsapp';
	const GRAPH   = 'https://graph.facebook.com/';
	const MAX_LEN = 4000; // WhatsApp allows 4096 characters per text message.

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_ajax_wp_ai_wa_test', array( $this, 'ajax_test' ) );
	}

	public static function providers() {
		return array(
			'cloud'     => 'WhatsApp Business Cloud API (Meta, official)',
			'waha'      => 'WAHA - WhatsApp HTTP API (your own server, normal WhatsApp by QR code)',
			'evolution' => 'Evolution API (your own server, normal WhatsApp by QR code)',
			'custom'    => 'Other gateway (custom URL: wppconnect, UltraMsg, Green API, n8n ...)',
		);
	}

	public static function settings() {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array_merge(
			array(
				'enabled'       => '0',
				'provider'      => 'cloud',
				// Meta Cloud API.
				'phone_id'      => '',
				'token'         => '',
				'app_secret'    => '',
				'verify_token'  => '',
				'version'       => 'v21.0',
				// WAHA / Evolution / custom.
				'base_url'      => '',
				'api_key'       => '',
				'session'       => 'default', // WAHA session or Evolution instance.
				'send_url'      => '',        // Custom gateway.
				'send_header'   => '',        // Custom gateway: "Header-Name: value".
				'hook_key'      => '',
				'groups'        => '0',
				// Answers.
				'bot'           => '',
				'other_types'   => 'Sorry, I can only read text messages. Please type your question.',
			),
			$o
		);
	}

	public static function provider() {
		$p = (string) self::settings()['provider'];
		return isset( self::providers()[ $p ] ) ? $p : 'cloud';
	}

	/** Verify token for Meta's webhook (made once, kept). */
	public static function verify_token() {
		return self::secret( 'verify_token' );
	}

	/** Key in the webhook address for WAHA / Evolution / custom gateways (made once, kept). */
	public static function hook_key() {
		return self::secret( 'hook_key' );
	}

	private static function secret( $k ) {
		$o = self::settings();
		if ( '' === (string) $o[ $k ] ) {
			$o[ $k ] = ( 'verify_token' === $k ? 'akdk_' : '' ) . wp_generate_password( 24, false, false );
			update_option( self::OPTION, $o, false );
		}
		return (string) $o[ $k ];
	}

	public static function ready() {
		$o = self::settings();
		if ( '1' !== (string) $o['enabled'] ) {
			return false;
		}
		switch ( self::provider() ) {
			case 'waha':
			case 'evolution':
				return '' !== $o['base_url'] && '' !== $o['session'];
			case 'custom':
				return '' !== $o['send_url'];
			default:
				return '' !== $o['phone_id'] && '' !== $o['token'];
		}
	}

	public static function webhook_url( $with_key = null ) {
		$url = rest_url( 'wp-ai-chatbot/v1' . self::ROUTE );
		if ( null === $with_key ) {
			$with_key = 'cloud' !== self::provider();
		}
		// The key is part of the path (…/whatsapp/KEY), so gateways that drop "?key=" or "&key=" still send it.
		return $with_key ? rest_url( 'wp-ai-chatbot/v1' . self::ROUTE . '/' . self::hook_key() ) : $url;
	}

	public function register_routes() {
		register_rest_route(
			'wp-ai-chatbot/v1',
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'verify' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => '__return_true',
				),
			)
		);
		// Same webhook with the secret key in the path.
		register_rest_route(
			'wp-ai-chatbot/v1',
			self::ROUTE . '/(?P<hook>[A-Za-z0-9]{8,64})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'verify' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/** The secret key a gateway sent: in the path, as ?key=, as a header, or as WAHA's HMAC signature. */
	private static function key_check( WP_REST_Request $request, $body ) {
		$want = self::hook_key();
		foreach ( array( (string) $request->get_param( 'hook' ), (string) $request->get_param( 'key' ), (string) $request->get_header( 'x_webhook_key' ) ) as $k ) {
			if ( '' !== $k ) {
				return hash_equals( $want, $k ) ? 'ok' : 'wrong';
			}
		}
		// WAHA: webhook "HMAC key" set to the plugin's key.
		$hmac = strtolower( (string) $request->get_header( 'x_webhook_hmac' ) );
		if ( '' !== $hmac ) {
			return hash_equals( hash_hmac( 'sha512', $body, $want ), $hmac ) ? 'ok' : 'wrong';
		}
		return 'missing';
	}

	/** Meta checks the callback URL: hub.mode=subscribe&hub.verify_token=...&hub.challenge=... */
	public function verify( WP_REST_Request $request ) {
		$mode      = (string) ( $request->get_param( 'hub_mode' ) ?? $request->get_param( 'hub.mode' ) );
		$token     = (string) ( $request->get_param( 'hub_verify_token' ) ?? $request->get_param( 'hub.verify_token' ) );
		$challenge = (string) ( $request->get_param( 'hub_challenge' ) ?? $request->get_param( 'hub.challenge' ) );
		if ( 'subscribe' === $mode && '' !== $token && hash_equals( self::verify_token(), $token ) && preg_match( '/^[A-Za-z0-9_\-.]{1,200}$/', $challenge ) ) {
			add_filter(
				'rest_pre_serve_request',
				function ( $served ) use ( $challenge ) {
					if ( ! headers_sent() ) {
						header( 'Content-Type: text/plain; charset=utf-8' );
					}
					echo $challenge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked above: letters, digits, - _ . only.
					return true;
				}
			);
			return new WP_REST_Response( $challenge, 200 );
		}
		// Gateways sometimes test the address with GET: say it works when the key is right.
		if ( 'ok' === self::key_check( $request, '' ) ) {
			return rest_ensure_response( array( 'ok' => true, 'message' => 'AK Deep Knowledge AI Chatbot: WhatsApp webhook is ready.' ) );
		}
		return new WP_Error( 'forbidden', 'Verification failed.', array( 'status' => 403 ) );
	}

	/** A message (or a status update) from WhatsApp. */
	public function receive( WP_REST_Request $request ) {
		$o    = self::settings();
		$body = (string) $request->get_body();
		$prov = self::provider();

		if ( 'cloud' === $prov ) {
			if ( '' === (string) $o['app_secret'] ) {
				// Without the App secret nobody can check who sent this: refuse.
				self::note( 'error', 'A message arrived, but the App secret is not saved, so it was refused.' );
				return new WP_Error( 'not_configured', 'Add the App secret in the plugin settings.', array( 'status' => 401 ) );
			}
			$sig = (string) $request->get_header( 'x_hub_signature_256' );
			$exp = 'sha256=' . hash_hmac( 'sha256', $body, (string) $o['app_secret'] );
			if ( '' === $sig || ! hash_equals( $exp, $sig ) ) {
				self::note( 'error', 'A message arrived with a wrong signature (is the App secret right?), so it was refused.' );
				return new WP_Error( 'bad_signature', 'Invalid signature.', array( 'status' => 401 ) );
			}
		} else {
			$check = self::key_check( $request, $body );
			if ( 'ok' !== $check ) {
				self::note(
					'error',
					'missing' === $check
						? 'A call arrived without the secret key: your gateway is using an address without it. Copy the whole Webhook URL from this page (it ends with the key) into the webhook of your gateway and save it there.'
						: 'A call arrived with a different key: your gateway still has an old Webhook URL (a new key was made, or the URL was copied incompletely). Copy the Webhook URL from this page again into your gateway.'
				);
				return new WP_Error( 'bad_key', 'Invalid webhook key.', array( 'status' => 401 ) );
			}
		}
		if ( ! self::ready() ) {
			self::note( 'error', 'A message arrived, but "The AI answers WhatsApp messages" is switched off (or the connection details are missing), so it was not answered.' );
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'disabled' ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$data = $request->get_body_params(); // Some gateways post form fields.
		}
		$messages = array();
		if ( 'waha' === $prov ) {
			$messages = self::parse_waha( (array) $data );
		} elseif ( 'evolution' === $prov ) {
			$messages = self::parse_evolution( (array) $data );
		} elseif ( 'custom' === $prov ) {
			$messages = self::parse_custom( (array) $data );
		} else {
			$messages = self::parse_cloud( (array) $data, (string) $o['phone_id'] );
		}

		$jobs = array();
		foreach ( $messages as $m ) {
			if ( '' === $m['from'] || '' === $m['id'] ) {
				continue;
			}
			// Groups, status updates and broadcast lists are not answered (unless groups are allowed).
			if ( false !== strpos( $m['from'], 'status@' ) || false !== strpos( $m['from'], '@broadcast' ) || false !== strpos( $m['from'], '@newsletter' ) ) {
				continue;
			}
			if ( false !== strpos( $m['from'], '@g.us' ) && '1' !== (string) $o['groups'] ) {
				continue;
			}
			// WhatsApp and gateways send a message again when they get no quick answer: answer each one once.
			$seen = 'wp_ai_wa_seen_' . md5( $m['id'] );
			if ( get_transient( $seen ) ) {
				continue;
			}
			set_transient( $seen, 1, DAY_IN_SECONDS );
			$jobs[] = $m;
		}
		if ( $jobs ) {
			self::note( 'ok', count( $jobs ) . ' message(s) received from ' . implode( ', ', array_unique( array_map( function ( $m ) { return self::digits( $m['from'] ); }, $jobs ) ) ) . ' and answered.' );
		} else {
			$ev = is_array( $data ) ? (string) ( $data['event'] ?? ( isset( $data['entry'] ) ? 'status update' : '' ) ) : '';
			self::note( 'info', 'A call arrived' . ( '' !== $ev ? ' (event "' . sanitize_text_field( $ev ) . '")' : '' ) . ' with no new customer message in it (own messages, groups, status updates and repeats are skipped).' );
		}
		if ( $jobs ) {
			$run = function () use ( $jobs ) {
				foreach ( $jobs as $m ) {
					self::handle( $m['from'], $m['text'], $m['type'], $m['name'] );
				}
			};
			// Answer the webhook at once, then let the AI work (WhatsApp waits only a few seconds).
			add_action(
				'shutdown',
				function () use ( $run ) {
					if ( function_exists( 'fastcgi_finish_request' ) ) {
						fastcgi_finish_request();
					}
					ignore_user_abort( true );
					if ( function_exists( 'set_time_limit' ) ) {
						@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
					$run();
				}
			);
		}
		return rest_ensure_response( array( 'ok' => true, 'received' => count( $jobs ) ) );
	}

	/** Last webhook call, shown on the settings tab ("Did my gateway reach the website?"). */
	public static function note( $status, $text ) {
		update_option( 'wp_ai_chatbot_wa_last', array( 'status' => $status, 'text' => wp_html_excerpt( (string) $text, 300, '…' ), 'time' => time() ), false );
	}

	/* ------------------------------------------------------------------
	 * Incoming messages of each connection -> from, text, type, id, name
	 * ------------------------------------------------------------------ */

	private static function msg( $from, $text, $type, $id, $name ) {
		return array(
			'from' => trim( (string) $from ),
			'text' => (string) $text,
			'type' => '' !== (string) $type ? (string) $type : 'text',
			'id'   => (string) $id,
			'name' => (string) $name,
		);
	}

	public static function parse_cloud( $data, $phone_id ) {
		$out = array();
		foreach ( (array) ( $data['entry'] ?? array() ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? array() ) as $change ) {
				$value = (array) ( $change['value'] ?? array() );
				if ( ! empty( $value['metadata']['phone_number_id'] ) && (string) $value['metadata']['phone_number_id'] !== (string) $phone_id ) {
					continue; // Another number of the same app.
				}
				$names = array();
				foreach ( (array) ( $value['contacts'] ?? array() ) as $c ) {
					if ( ! empty( $c['wa_id'] ) ) {
						$names[ (string) $c['wa_id'] ] = (string) ( $c['profile']['name'] ?? '' );
					}
				}
				foreach ( (array) ( $value['messages'] ?? array() ) as $m ) {
					$from = preg_replace( '/\D/', '', (string) ( $m['from'] ?? '' ) );
					$type = (string) ( $m['type'] ?? '' );
					$text = '';
					if ( 'text' === $type ) {
						$text = (string) ( $m['text']['body'] ?? '' );
					} elseif ( 'button' === $type ) {
						$text = (string) ( $m['button']['text'] ?? '' );
					} elseif ( 'interactive' === $type ) {
						$text = (string) ( $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '' );
					}
					$out[] = self::msg( $from, $text, $type, $m['id'] ?? '', $names[ $from ] ?? '' );
				}
			}
		}
		return $out;
	}

	/** WAHA: {"event":"message","session":"default","payload":{"id","from","fromMe","body","hasMedia",...}} */
	public static function parse_waha( $data ) {
		$event = (string) ( $data['event'] ?? '' );
		if ( ! in_array( $event, array( 'message', 'message.any' ), true ) ) {
			return array();
		}
		$session = (string) self::settings()['session'];
		if ( '' !== $session && isset( $data['session'] ) && (string) $data['session'] !== $session ) {
			return array(); // Another WhatsApp number on the same WAHA server.
		}
		$p = (array) ( $data['payload'] ?? array() );
		if ( ! empty( $p['fromMe'] ) ) {
			return array(); // Our own replies.
		}
		$name = (string) ( $p['_data']['notifyName'] ?? $p['_data']['pushName'] ?? $p['notifyName'] ?? '' );
		$type = ! empty( $p['hasMedia'] ) ? (string) ( $p['media']['mimetype'] ?? 'media' ) : 'text';
		$text = ! empty( $p['hasMedia'] ) ? (string) ( $p['caption'] ?? '' ) : (string) ( $p['body'] ?? '' );
		if ( empty( $p['hasMedia'] ) && '' === trim( $text ) && isset( $p['_data']['type'] ) && 'chat' !== $p['_data']['type'] ) {
			$type = (string) $p['_data']['type'];
		}
		return array( self::msg( $p['from'] ?? '', $text, $type, $p['id'] ?? '', $name ) );
	}

	/** Evolution API: {"event":"messages.upsert","instance":"x","data":{"key":{"remoteJid","fromMe","id"},"pushName","message":{...}}} */
	public static function parse_evolution( $data ) {
		$event = strtolower( str_replace( '_', '.', (string) ( $data['event'] ?? '' ) ) );
		if ( 'messages.upsert' !== $event ) {
			return array();
		}
		$instance = (string) self::settings()['session'];
		if ( '' !== $instance && isset( $data['instance'] ) && is_string( $data['instance'] ) && $data['instance'] !== $instance ) {
			return array();
		}
		$list = isset( $data['data']['key'] ) ? array( $data['data'] ) : (array) ( $data['data']['messages'] ?? $data['data'] ?? array() );
		$out  = array();
		foreach ( $list as $d ) {
			if ( ! is_array( $d ) || ! empty( $d['key']['fromMe'] ) ) {
				continue;
			}
			$msg  = (array) ( $d['message'] ?? array() );
			$text = (string) ( $msg['conversation'] ?? $msg['extendedTextMessage']['text'] ?? $msg['buttonsResponseMessage']['selectedDisplayText'] ?? $msg['listResponseMessage']['title'] ?? $msg['imageMessage']['caption'] ?? '' );
			$type = (string) ( $d['messageType'] ?? ( '' !== $text ? 'text' : 'media' ) );
			if ( in_array( $type, array( 'conversation', 'extendedTextMessage', 'buttonsResponseMessage', 'listResponseMessage' ), true ) ) {
				$type = 'text';
			}
			$jid  = (string) ( $d['key']['remoteJid'] ?? '' );
			// Newer WhatsApp hides numbers (@lid); Evolution then gives the real number separately.
			if ( false !== strpos( $jid, '@lid' ) && ! empty( $d['key']['senderPn'] ) ) {
				$jid = (string) $d['key']['senderPn'];
			}
			$out[] = self::msg( $jid, $text, $type, $d['key']['id'] ?? '', $d['pushName'] ?? '' );
		}
		return $out;
	}

	/** Custom gateway: {"from":"923001112233","text":"...","id":"...","name":"..."} (also "message"/"body", "phone"/"sender"). */
	public static function parse_custom( $data ) {
		$from = $data['from'] ?? $data['phone'] ?? $data['sender'] ?? $data['chatId'] ?? '';
		$text = $data['text'] ?? $data['message'] ?? $data['body'] ?? '';
		if ( is_array( $text ) ) {
			$text = $text['body'] ?? $text['text'] ?? '';
		}
		if ( ! empty( $data['fromMe'] ) || ! is_scalar( $from ) || ! is_scalar( $text ) ) {
			return array();
		}
		$id = $data['id'] ?? $data['message_id'] ?? md5( $from . '|' . $text . '|' . gmdate( 'YmdHi' ) );
		return array( self::msg( (string) $from, (string) $text, (string) ( $data['type'] ?? 'text' ), is_scalar( $id ) ? (string) $id : '', is_scalar( $data['name'] ?? '' ) ? (string) ( $data['name'] ?? '' ) : '' ) );
	}

	/** Phone digits of a WhatsApp address ("923001112233@c.us" -> "923001112233"). */
	public static function digits( $address ) {
		$a = (string) $address;
		$a = false !== strpos( $a, '@' ) ? substr( $a, 0, strpos( $a, '@' ) ) : $a;
		$a = false !== strpos( $a, ':' ) ? substr( $a, 0, strpos( $a, ':' ) ) : $a; // Device suffix "92300...:12".
		return preg_replace( '/\D/', '', $a );
	}

	public static function session_for( $from ) {
		return md5( 'wa|' . self::digits( $from ) );
	}

	/** One incoming message. */
	public static function handle( $from, $text, $type = 'text', $profile_name = '' ) {
		$o      = self::settings();
		$sid    = self::session_for( $from );
		$digits = self::digits( $from );
		// The address replies go to: the gateway's chat ID (it may not be a phone number), or the number.
		$contact = 'cloud' === self::provider() ? $digits : (string) $from;
		WP_AI_Chatbot_Sessions::touch( $sid, array( 'channel' => 'whatsapp', 'contact' => substr( $contact, 0, 60 ), 'bot_id' => (string) $o['bot'] ) );
		// The WhatsApp profile name and number, so Chat History and Live Chat show who it is.
		$known = WP_AI_Chatbot_Chat_Contacts::for_sessions( array( $sid ) );
		if ( ! isset( $known[ $sid ] ) ) {
			WP_AI_Chatbot_Chat_Contacts::remember(
				$sid,
				array( 'id' => 'whatsapp', 'title' => 'WhatsApp', 'fields' => array( array( 'label' => 'Name', 'type' => 'text' ), array( 'label' => 'Phone', 'type' => 'tel' ) ) ),
				array( 'name' => sanitize_text_field( $profile_name ), 'phone' => strlen( $digits ) >= 7 && false === strpos( (string) $from, '@lid' ) ? '+' . $digits : '' ),
				0
			);
		}

		$text = sanitize_text_field( (string) $text );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, WP_AI_CHATBOT_MAX_MSG_LEN ) : substr( $text, 0, WP_AI_CHATBOT_MAX_MSG_LEN );
		if ( '' === trim( $text ) ) {
			$label = '[' . sanitize_key( '' !== $type ? $type : 'message' ) . ']';
			// A person is handling this chat: they see it in Live Chat, the bot stays quiet.
			if ( 'human' === WP_AI_Chatbot_Sessions::mode( $sid ) ) {
				WP_AI_Chatbot_Logger::log_chat( $sid, $label, '' );
				return;
			}
			$reply = (string) $o['other_types'];
			WP_AI_Chatbot_Logger::log_chat( $sid, $label, $reply );
			self::send_text( $contact, $reply );
			return;
		}

		$reply = self::answer( $sid, $text, $contact );
		if ( '' !== $reply ) {
			self::send_text( $contact, self::format( $reply ) );
		}
	}

	/** The reply to a text message ('' = nothing to send, a person handles the chat). */
	public static function answer( $sid, $text, $from ) {
		$o = self::settings();
		WP_AI_Chatbot_Bots::activate( (string) $o['bot'] );

		$live = WP_AI_Chatbot_Live_Chat::before_ai( $sid, $text );
		if ( is_array( $live ) && '' === $live['reply'] ) {
			WP_AI_Chatbot_Logger::log_chat( $sid, $text, '' );
			return '';
		}
		if ( is_array( $live ) ) {
			WP_AI_Chatbot_Logger::log_chat( $sid, $text, $live['reply'] );
			return $live['reply'];
		}
		$order = WP_AI_Chatbot_Order_Status::maybe_answer( $sid, $text, array( 'channel' => 'whatsapp', 'contact' => self::digits( $from ), 'user_id' => 0 ) );
		if ( null !== $order ) {
			WP_AI_Chatbot_Logger::log_chat( $sid, $text, $order );
			return $order;
		}
		$gate = WP_AI_Chatbot_Token_Control::chat_gate( 'wa_' . $from );
		if ( null !== $gate ) {
			WP_AI_Chatbot_Logger::log_chat( $sid, $text, $gate );
			return $gate;
		}
		$api     = new WP_AI_Chatbot_API_Handler();
		$history = WP_AI_Chatbot_Sessions::history( $sid, max( 2, (int) ( WP_AI_Chatbot_AI::settings()['history_limit'] ?? 6 ) ) );
		$name    = '';
		if ( class_exists( 'WP_AI_Chatbot_Chat_Contacts' ) ) {
			$c = WP_AI_Chatbot_Chat_Contacts::for_sessions( array( $sid ) );
			if ( isset( $c[ $sid ] ) ) {
				$name = (string) $c[ $sid ]->name;
			}
		}
		$reply = $api->process_chat_request( $text, $history, $sid, $name );
		WP_AI_Chatbot_Logger::log_chat( $sid, $text, $reply );
		WP_AI_Chatbot_Unanswered::maybe_record( $sid, $text, $reply, $api );
		return $reply;
	}

	/** Chat Markdown to WhatsApp text: links as plain addresses, *bold*, forms as a list of what to send. */
	public static function format( $text ) {
		$text = (string) $text;
		// Forms: WhatsApp has no forms, so ask for the fields in the chat (or give the link).
		$text = preg_replace_callback(
			'/\[SHOW_FORM_([a-zA-Z0-9_\-]+)\]/',
			function ( $m ) {
				foreach ( (array) get_option( 'wp_ai_chatbot_multi_forms', array() ) as $f ) {
					if ( ! is_array( $f ) || ! isset( $f['id'] ) || (string) $f['id'] !== $m[1] ) {
						continue;
					}
					$pub = WP_AI_Chatbot_External_Forms::public_form( $f );
					if ( ! empty( $pub['url'] ) && 'builtin' !== $pub['source'] ) {
						return ( '' !== $pub['title'] ? $pub['title'] . ': ' : '' ) . $pub['url'];
					}
					$labels = array();
					foreach ( (array) $pub['fields'] as $field ) {
						if ( ! empty( $field['label'] ) ) {
							$labels[] = (string) $field['label'];
						}
					}
					if ( $labels ) {
						return 'Please reply with your ' . implode( ', ', $labels ) . ' and our team will get back to you.';
					}
				}
				return '';
			},
			$text
		);
		$text = preg_replace( '/\[!\[([^\]]*)\]\([^)]*\)\]\(([^)\s]+)\)/', '$1: $2', $text );     // Image card.
		$text = preg_replace( '/!\[[^\]]*\]\(([^)\s]+)\)/', '$1', $text );                         // Image.
		$text = preg_replace( '/\*\*\[Click here to view:[^\]]*\]\([^)]*\)\*\*/i', '', $text );   // Card button line.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function ( $m ) {
				return trim( $m[1] ) === trim( $m[2] ) ? $m[2] : $m[1] . ': ' . $m[2];
			},
			$text
		);
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '*$1*', $text );
		$text = preg_replace( '/^#{1,6}\s*(.+)$/m', '*$1*', $text );
		$text = preg_replace( '/^\s*[-*]\s+/m', '• ', $text );
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
		if ( strlen( $text ) > self::MAX_LEN ) {
			$text = rtrim( function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_LEN - 3, 'UTF-8' ) : substr( $text, 0, self::MAX_LEN - 3 ) ) . '...';
		}
		return $text;
	}


	/** Sends a text message through the chosen connection. @return true|WP_Error */
	public static function send_text( $to, $text ) {
		$o    = self::settings();
		$prov = self::provider();
		$to   = (string) $to;
		$text = (string) $text;
		$json = function ( $url, $body, $headers = array() ) {
			return wp_remote_post(
				$url,
				array(
					'timeout' => 25,
					'headers' => array_merge( array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), $headers ),
					'body'    => wp_json_encode( $body ),
				)
			);
		};
		$base = untrailingslashit( (string) $o['base_url'] );

		if ( 'waha' === $prov ) {
			if ( '' === $base ) {
				return self::fail( 'Add the WAHA server address first.' );
			}
			$chat = false !== strpos( $to, '@' ) ? $to : self::digits( $to ) . '@c.us';
			$res  = $json( $base . '/api/sendText', array( 'session' => '' !== $o['session'] ? $o['session'] : 'default', 'chatId' => $chat, 'text' => $text ), '' !== $o['api_key'] ? array( 'X-Api-Key' => $o['api_key'] ) : array() );
			return self::result( $res, 'WAHA' );
		}
		if ( 'evolution' === $prov ) {
			if ( '' === $base || '' === $o['session'] ) {
				return self::fail( 'Add the Evolution API address and instance name first.' );
			}
			$number = ( false !== strpos( $to, '@' ) && false === strpos( $to, '@s.whatsapp.net' ) ) ? $to : self::digits( $to );
			$url    = $base . '/message/sendText/' . rawurlencode( (string) $o['session'] );
			$head   = '' !== $o['api_key'] ? array( 'apikey' => $o['api_key'] ) : array();
			$res    = $json( $url, array( 'number' => $number, 'text' => $text ), $head );
			$code   = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( 400 === $code || 422 === $code ) {
				// Evolution API v1 format.
				$res = $json( $url, array( 'number' => $number, 'options' => array( 'delay' => 0 ), 'textMessage' => array( 'text' => $text ) ), $head );
			}
			return self::result( $res, 'Evolution API' );
		}
		if ( 'custom' === $prov ) {
			if ( '' === (string) $o['send_url'] ) {
				return self::fail( 'Add the send URL of your gateway first.' );
			}
			$head = array();
			if ( preg_match( '/^\s*([A-Za-z0-9\-_]+)\s*:\s*(.+)$/', (string) $o['send_header'], $h ) ) {
				$head[ $h[1] ] = trim( $h[2] );
			}
			$res = $json( (string) $o['send_url'], array( 'to' => $to, 'phone' => self::digits( $to ), 'text' => $text, 'message' => $text ), $head );
			return self::result( $res, 'Gateway' );
		}

		if ( '' === $o['phone_id'] || '' === $o['token'] ) {
			return self::fail( 'WhatsApp is not set up.' );
		}
		$version = preg_match( '/^v\d{1,2}\.\d$/', (string) $o['version'] ) ? $o['version'] : 'v21.0';
		$res     = wp_remote_post(
			self::GRAPH . $version . '/' . rawurlencode( (string) $o['phone_id'] ) . '/messages',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $o['token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'recipient_type'    => 'individual',
						'to'                => self::digits( $to ),
						'type'              => 'text',
						'text'              => array( 'preview_url' => true, 'body' => $text ),
					)
				),
			)
		);
		if ( ! is_wp_error( $res ) ) {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( ( $code < 200 || $code >= 300 ) && isset( $body['error']['code'] ) && 131047 === (int) $body['error']['code'] ) {
				return self::fail( 'More than 24 hours have passed since the customer\'s last message, so WhatsApp only allows template messages. Ask the customer to write again.' );
			}
		}
		return self::result( $res, 'WhatsApp' );
	}

	private static function fail( $msg ) {
		$err = new WP_Error( 'whatsapp_error', $msg );
		WP_AI_Chatbot_AI::record_error( 'WhatsApp', $err );
		return $err;
	}

	/** HTTP answer of a send call -> true or a readable error. */
	private static function result( $res, $who ) {
		if ( is_wp_error( $res ) ) {
			return self::fail( $who . ' could not be reached: ' . $res->get_error_message() . ' (is the server address right, and can this website reach it?)' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$msg  = '';
		if ( is_array( $body ) ) {
			$msg = $body['error']['message'] ?? $body['message'] ?? $body['error'] ?? $body['response']['message'] ?? '';
			$msg = is_array( $msg ) ? wp_json_encode( $msg ) : (string) $msg;
		}
		if ( 401 === $code || 403 === $code ) {
			$msg = 'The API key was refused. ' . $msg;
		}
		return self::fail( $who . ' answered HTTP ' . $code . ( '' !== $msg ? ': ' . wp_html_excerpt( $msg, 220, '…' ) : '' ) );
	}

	/** "Send test message" on the settings tab. */
	public function ajax_test() {
		check_ajax_referer( 'wp_ai_wa_test', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		$to = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		if ( strlen( self::digits( $to ) ) < 8 ) {
			wp_send_json_error( 'Enter the number with country code, for example 923001234567.' );
		}
		$r = self::send_text( self::digits( $to ), 'Test message from ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ': your WhatsApp AI chatbot is connected.' );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( $r->get_error_message() );
		}
		$warn = self::ready() ? '' : ' But "The AI answers WhatsApp messages" is off: tick it and save, or customer messages will not be answered.';
		wp_send_json_success( 'Sent! Check WhatsApp on ' . self::digits( $to ) . '.' . $warn );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = self::settings();
		$out['enabled']  = empty( $in['enabled'] ) ? '0' : '1';
		$out['provider'] = isset( self::providers()[ $in['provider'] ?? '' ] ) ? $in['provider'] : 'cloud';
		$out['phone_id'] = preg_replace( '/\D/', '', (string) ( $in['phone_id'] ?? '' ) );
		$out['version']  = preg_match( '/^v\d{1,2}\.\d$/', trim( (string) ( $in['version'] ?? '' ) ) ) ? trim( (string) $in['version'] ) : 'v21.0';
		$out['base_url'] = untrailingslashit( esc_url_raw( trim( (string) ( $in['base_url'] ?? '' ) ), array( 'http', 'https' ) ) );
		$out['session']  = substr( preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) ( $in['session'] ?? '' ) ), 0, 80 );
		if ( '' === $out['session'] && 'waha' === $out['provider'] ) {
			$out['session'] = 'default';
		}
		$out['send_url']    = esc_url_raw( trim( (string) ( $in['send_url'] ?? '' ) ), array( 'http', 'https' ) );
		$out['send_header'] = sanitize_text_field( (string) ( $in['send_header'] ?? '' ) );
		$out['groups']      = empty( $in['groups'] ) ? '0' : '1';
		$out['bot']         = sanitize_key( (string) ( $in['bot'] ?? '' ) );
		$other              = sanitize_textarea_field( (string) ( $in['other_types'] ?? '' ) );
		$out['other_types'] = '' !== trim( $other ) ? $other : 'Sorry, I can only read text messages. Please type your question.';
		// Secrets: an empty box keeps the saved value (they are never shown again).
		foreach ( array( 'token', 'app_secret', 'api_key' ) as $k ) {
			$v = trim( (string) ( $in[ $k ] ?? '' ) );
			if ( '' !== $v ) {
				$out[ $k ] = preg_replace( '/[^A-Za-z0-9_\-.|:+\/=]/', '', $v );
			}
		}
		if ( ! empty( $in['clear_secrets'] ) ) {
			$out['token']      = '';
			$out['app_secret'] = '';
			$out['api_key']    = '';
		}
		if ( ! empty( $in['new_hook_key'] ) ) {
			$out['hook_key'] = '';
		}
		foreach ( array( 'verify_token', 'hook_key' ) as $k ) {
			if ( '' === (string) $out[ $k ] ) {
				$out[ $k ] = ( 'verify_token' === $k ? 'akdk_' : '' ) . wp_generate_password( 24, false, false );
			}
		}
		return $out;
	}

	public static function render_tab() {
		$o    = self::settings();
		$n    = self::OPTION;
		$prov = self::provider();
		$bots = WP_AI_Chatbot_Bots::all();
		$saved = function ( $k ) use ( $o ) {
			return '' !== (string) $o[ $k ] ? ' <small>(saved, leave empty to keep)</small>' : '';
		};
		$ph = function ( $k, $empty ) use ( $o ) {
			return esc_attr( '' !== (string) $o[ $k ] ? '••••••••' : $empty );
		};
		?>
		<p class="akdk-st-intro">Answer customers on WhatsApp with the same AI: same knowledge, instructions, order status and live chat. Connect Meta's official Business API, or the WhatsApp you already use through <strong>WAHA</strong>, <strong>Evolution API</strong> or another gateway. This is separate from the WhatsApp <em>button</em> under "WhatsApp".</p>
		<form method="post" action="options.php" class="akdk-st-form akdk-wa-form" autocomplete="off">
			<?php settings_fields( 'wp_ai_chatbot_wa_ai_group' ); ?>
			<div class="akdk-st-card">
				<div class="akdk-st-card-head"><strong>1. Connection</strong> <?php echo self::ready() ? '<span class="akdk-st-ok">Ready</span>' : ''; ?></div>
				<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( '1', $o['enabled'] ); ?>> The AI answers WhatsApp messages</label></p>
				<div class="akdk-st-providers">
					<?php foreach ( self::providers() as $k => $label ) : ?>
						<label><input type="radio" class="akdk-wa-provider" name="<?php echo esc_attr( $n ); ?>[provider]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $k, $prov ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</div>

				<div class="akdk-wa-box" data-for="cloud">
					<div class="akdk-st-grid">
						<label>Phone number ID<input type="text" name="<?php echo esc_attr( $n ); ?>[phone_id]" value="<?php echo esc_attr( $o['phone_id'] ); ?>" placeholder="1234567890123456"></label>
						<label>Access token<?php echo $saved( 'token' ); // phpcs:ignore ?><input type="password" name="<?php echo esc_attr( $n ); ?>[token]" value="" placeholder="<?php echo $ph( 'token', 'EAAG...' ); // phpcs:ignore ?>"></label>
						<label>App secret<?php echo $saved( 'app_secret' ); // phpcs:ignore ?><input type="password" name="<?php echo esc_attr( $n ); ?>[app_secret]" value="" placeholder="<?php echo $ph( 'app_secret', 'from App settings > Basic' ); // phpcs:ignore ?>"></label>
						<label>Graph API version<input type="text" name="<?php echo esc_attr( $n ); ?>[version]" value="<?php echo esc_attr( $o['version'] ); ?>"></label>
					</div>
					<p class="description">developers.facebook.com: create a Business app, add WhatsApp, copy the Phone number ID, a permanent token (System user) and the App secret. Meta charges per conversation after the free tier.</p>
				</div>

				<div class="akdk-wa-box" data-for="waha evolution">
					<div class="akdk-st-grid">
						<label><span><span class="akdk-wa-only" data-for="waha">WAHA</span><span class="akdk-wa-only" data-for="evolution">Evolution API</span> server address</span><input type="url" name="<?php echo esc_attr( $n ); ?>[base_url]" value="<?php echo esc_attr( $o['base_url'] ); ?>" placeholder="https://wa.yourdomain.com"></label>
						<label>API key<?php echo $saved( 'api_key' ); // phpcs:ignore ?><input type="password" name="<?php echo esc_attr( $n ); ?>[api_key]" value="" placeholder="<?php echo $ph( 'api_key', 'WAHA_API_KEY / Evolution apikey' ); // phpcs:ignore ?>"></label>
						<label><span><span class="akdk-wa-only" data-for="waha">Session name</span><span class="akdk-wa-only" data-for="evolution">Instance name</span></span><input type="text" name="<?php echo esc_attr( $n ); ?>[session]" value="<?php echo esc_attr( $o['session'] ); ?>" placeholder="default"></label>
					</div>
					<p class="description akdk-wa-only" data-for="waha">In WAHA: start the session and scan the QR code with your WhatsApp, then add a webhook with the URL below and the event <strong>message</strong>.</p>
					<p class="description akdk-wa-only" data-for="evolution">In Evolution API: create the instance and scan the QR code, then under Webhook turn it on with the URL below, event <strong>MESSAGES_UPSERT</strong> ("Webhook by events" off).</p>
				</div>

				<div class="akdk-wa-box" data-for="custom">
					<div class="akdk-st-grid">
						<label>Send URL (the plugin posts {"to","phone","text"} here)<input type="url" name="<?php echo esc_attr( $n ); ?>[send_url]" value="<?php echo esc_attr( $o['send_url'] ); ?>" placeholder="https://your-gateway/send"></label>
						<label>Extra header (optional)<input type="text" name="<?php echo esc_attr( $n ); ?>[send_header]" value="<?php echo esc_attr( $o['send_header'] ); ?>" placeholder="Authorization: Bearer abc123"></label>
					</div>
					<p class="description">Your gateway (or an n8n / Make flow) posts each incoming message to the webhook URL below as JSON: <code>{"from":"923001234567","text":"Hi","id":"unique-id","name":"Ali"}</code>.</p>
				</div>

				<div class="akdk-wa-box" data-for="waha evolution custom">
					<p class="description"><strong>Your WhatsApp gateway and this website must be able to reach each other.</strong> A gateway running only on your own computer (localhost) cannot be reached by a hosted website: run it on a server or VPS, or open it with a tunnel (for example Cloudflare Tunnel). Gateways that use normal WhatsApp are unofficial; WhatsApp can block numbers that send spam, so use it for replies to customers who write to you.</p>
					<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[groups]" value="1" <?php checked( '1', $o['groups'] ); ?>> Also answer in WhatsApp groups (normally off)</label></p>
				</div>
				<?php if ( '' !== $o['token'] || '' !== $o['app_secret'] || '' !== $o['api_key'] ) : ?>
					<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[clear_secrets]" value="1"> Remove the saved token, secret and API key</label></p>
				<?php endif; ?>
			</div>

			<div class="akdk-st-card">
				<div class="akdk-st-card-head"><strong>2. Webhook</strong></div>
				<div class="akdk-wa-box" data-for="cloud">
					<p>Callback URL (Meta: WhatsApp &gt; Configuration)<br><code class="akdk-st-copy"><?php echo esc_html( self::webhook_url( false ) ); ?></code></p>
					<p>Verify token<br><code class="akdk-st-copy"><?php echo esc_html( self::verify_token() ); ?></code></p>
					<p class="description">Then subscribe to the <strong>messages</strong> field. Your site must be reachable over HTTPS.</p>
				</div>
				<?php
				$last = get_option( 'wp_ai_chatbot_wa_last' );
				if ( is_array( $last ) && ! empty( $last['time'] ) ) :
					$color = 'ok' === $last['status'] ? '#166534' : ( 'error' === $last['status'] ? '#b91c1c' : '#92400e' );
					?>
					<p class="akdk-wa-last" style="color:<?php echo esc_attr( $color ); ?>;"><strong>Last call from WhatsApp, <?php echo esc_html( human_time_diff( (int) $last['time'] ) ); ?> ago:</strong> <?php echo esc_html( $last['text'] ); ?></p>
				<?php else : ?>
					<p class="akdk-wa-last" style="color:#92400e;"><strong>No message has reached this website yet.</strong> Send a message <em>to</em> your connected number from another phone. If nothing shows here after a minute, the gateway is not calling the Webhook URL (check the URL and the event in your gateway, and that it can reach this website).</p>
				<?php endif; ?>
				<div class="akdk-wa-box" data-for="waha evolution custom">
					<p>Webhook URL (paste the <strong>whole</strong> address in your gateway; the last part is a secret key, keep it private)<br><code class="akdk-st-copy"><?php echo esc_html( self::webhook_url( true ) ); ?></code></p>
					<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[new_hook_key]" value="1"> Make a new secret key (the old webhook URL stops working)</label></p>
				</div>
			</div>

			<div class="akdk-st-card">
				<div class="akdk-st-card-head"><strong>3. Answers</strong></div>
				<div class="akdk-st-grid">
					<label>Bot
						<select name="<?php echo esc_attr( $n ); ?>[bot]">
							<option value="">Main bot</option>
							<?php foreach ( $bots as $id => $b ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $o['bot'] ); ?>><?php echo esc_html( '' !== $b['name'] ? $b['name'] : $id ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>Reply to photos, voice notes and files<input type="text" name="<?php echo esc_attr( $n ); ?>[other_types]" value="<?php echo esc_attr( $o['other_types'] ); ?>"></label>
				</div>
				<p class="description">Forms become a question for the details ("Please reply with your Name, Phone…"), links are sent as addresses. When a customer asks for a person, the chat appears in Live Chat and your reply is sent on WhatsApp.</p>
			</div>
			<?php submit_button( 'Save WhatsApp AI settings' ); ?>
		</form>

		<div class="akdk-st-card akdk-wa-test" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_ai_wa_test' ) ); ?>">
			<div class="akdk-st-card-head"><strong>Test the connection</strong></div>
			<p class="description">Save first, then send a test message to your own WhatsApp number.</p>
			<p><input type="text" class="regular-text akdk-wa-to" placeholder="923001234567"> <button type="button" class="button akdk-wa-send">Send test message</button> <span class="akdk-wa-result"></span></p>
		</div>
		<style>
			.akdk-st-providers { display:flex; flex-direction:column; gap:6px; margin:6px 0 10px; }
			.akdk-st-providers label { font-weight:600; }
			.akdk-wa-box { border-top:1px dashed #e4e4e7; padding-top:10px; margin-top:8px; }
		</style>
		<script>
		(function () {
			var form = document.querySelector('.akdk-wa-form');
			if (!form) return;
			function sync() {
				var r = form.querySelector('.akdk-wa-provider:checked'), p = r ? r.value : 'cloud';
				form.querySelectorAll('.akdk-wa-box, .akdk-wa-only').forEach(function (b) {
					b.style.display = (' ' + b.getAttribute('data-for') + ' ').indexOf(' ' + p + ' ') !== -1 ? '' : 'none';
				});
			}
			form.addEventListener('change', function (e) { if (e.target.classList.contains('akdk-wa-provider')) sync(); });
			sync();
			var t = document.querySelector('.akdk-wa-test');
			t.querySelector('.akdk-wa-send').addEventListener('click', function () {
				var btn = this, out = t.querySelector('.akdk-wa-result');
				btn.disabled = true; out.textContent = 'Sending…';
				var fd = new FormData();
				fd.append('action', 'wp_ai_wa_test'); fd.append('security', t.getAttribute('data-nonce')); fd.append('to', t.querySelector('.akdk-wa-to').value);
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (r) {
					out.textContent = (r.success ? '✓ ' : '✗ ') + (r.data || ''); out.style.color = r.success ? '#166534' : '#b91c1c';
				}).catch(function () { out.textContent = 'Request failed.'; }).then(function () { btn.disabled = false; });
			});
		})();
		</script>
		<?php
	}
}
