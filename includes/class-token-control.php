<?php
/**
 * Token control: the owner's own limits on how many tokens the chatbot uses.
 *
 * Settings (AI Settings > Token control, stored in wp_ai_chatbot_settings):
 * - token_knowledge: how much of the knowledge base is sent with a question
 *   (economy / balanced / detailed). Scales the token saver's input budget.
 * - token_answer: how long answers may be (short / normal / detailed).
 * - daily_token_limit: tokens per day for the whole site (visitor chat and
 *   voice). When it is reached the bot shows token_limit_message and stops
 *   calling the AI until midnight. 0 = no limit.
 * - visitor_daily_messages: messages one visitor may send per day. 0 = no limit.
 * - voice_max_seconds: longest voice message.
 *
 * Filters from earlier versions (wp_ai_chatbot_input_budget,
 * wp_ai_chatbot_answer_tokens, ...) still run after these settings.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Token_Control {

	const LEVELS = array(
		'economy'  => 0.6,
		'balanced' => 1.0,
		'detailed' => 1.5,
	);

	const ANSWERS = array(
		'short'    => 0.6,
		'normal'   => 1.0,
		'detailed' => 1.5,
	);

	const DEFAULT_OFF_TOPIC_MESSAGE = "Sorry, I can only help with questions about our business, such as our products, services, prices or how to contact us. What would you like to know?";

	/** Words that always reach the AI, even when the knowledge base has no match (buying and contact questions). */
	const DEFAULT_ALLOW_WORDS = 'price, prices, pricing, cost, rate, rates, charges, fee, fees, package, packages, kitne, kitna, kitni, qeemat, keemat, kharcha, kharch, book, booking, appointment, order, buy, khareed, contact, call, whatsapp, number, phone, email, address, location, timing, timings, open, service, services, quote, discount, delivery, form';

	const DEFAULT_LIMIT_MESSAGE = 'Our chat assistant is taking a short break today. Please leave your question through the contact form or WhatsApp and we will reply soon.';

	private static function setting( $key, $default ) {
		$s = WP_AI_Chatbot_AI::settings();
		return isset( $s[ $key ] ) && '' !== $s[ $key ] ? $s[ $key ] : $default;
	}

	public static function knowledge_level() {
		$v = (string) self::setting( 'token_knowledge', 'balanced' );
		return isset( self::LEVELS[ $v ] ) ? $v : 'balanced';
	}

	public static function answer_level() {
		$v = (string) self::setting( 'token_answer', 'normal' );
		return isset( self::ANSWERS[ $v ] ) ? $v : 'normal';
	}

	/** Multiplier for the knowledge sent with each question. */
	public static function knowledge_factor() {
		return self::LEVELS[ self::knowledge_level() ];
	}

	/** Multiplier for the answer length limit. */
	public static function answer_factor() {
		return self::ANSWERS[ self::answer_level() ];
	}

	public static function daily_limit() {
		return max( 0, (int) self::setting( 'daily_token_limit', 0 ) );
	}

	public static function visitor_daily_messages() {
		return max( 0, (int) self::setting( 'visitor_daily_messages', 0 ) );
	}

	public static function voice_max_seconds() {
		return max( 5, min( 60, (int) self::setting( 'voice_max_seconds', 30 ) ) );
	}

	public static function limit_message() {
		$msg = trim( (string) self::setting( 'token_limit_message', '' ) );
		return '' !== $msg ? $msg : self::DEFAULT_LIMIT_MESSAGE;
	}

	/**
	 * Tokens used today by visitors: chat (input + output, as on the dashboard
	 * chart) plus voice. The admin's dashboard assistant is not counted.
	 */
	public static function used_today() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$start = current_time( 'Y-m-d' ) . ' 00:00:00';
		$table = $wpdb->prefix . 'ai_chat_analytics';
		$chat  = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$chat = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(prompt_tokens + completion_tokens), 0) FROM {$table} WHERE created_at >= %s AND session_id != 'admin_dashboard_bot'", $start ) );
		}
		$voice  = class_exists( 'WP_AI_Chatbot_Voice' ) ? WP_AI_Chatbot_Voice::usage( current_time( 'Y-m-d' ), current_time( 'Y-m-d' ) ) : array( 'in' => 0, 'out' => 0 );
		$cached = $chat + (int) $voice['in'] + (int) $voice['out'];
		return $cached;
	}

	/** Off-topic questions (nothing in the knowledge base matches) get a fixed reply instead of an AI answer. */
	public static function off_topic_on() {
		return '1' === (string) self::setting( 'off_topic_block', '0' );
	}

	public static function off_topic_message() {
		$msg = trim( (string) self::setting( 'off_topic_message', '' ) );
		return '' !== $msg ? $msg : self::DEFAULT_OFF_TOPIC_MESSAGE;
	}

	/** Typical questions that have nothing to do with a business website, in English and Roman Urdu. */
	const OFF_TOPIC_EXAMPLES = array(
		'tell me a joke',
		'ek joke sunao',
		'what is the cricket score today',
		'who is the president of America',
		'write a poem about the moon',
		'aaj mausam kaisa hai',
		'solve my maths homework',
		'tum kis ne banaye ho',
	);

	/** How far above the off-topic examples a question must match to reach the AI. */
	const STRICTNESS = array(
		'relaxed' => 0.02,
		'normal'  => 0.05,
		'strict'  => 0.09,
	);

	public static function off_topic_strictness() {
		$v = (string) self::setting( 'off_topic_strictness', 'normal' );
		return isset( self::STRICTNESS[ $v ] ) ? $v : 'normal';
	}

	/**
	 * Best match score of a question in the knowledge base (search is cached),
	 * or null when the search could not run.
	 */
	public static function top_score( $engine, $query ) {
		$engine->embed_failed = false;
		$engine->retrieve( $query );
		if ( $engine->embed_failed ) {
			return null;
		}
		return $engine->last_scores ? (float) max( $engine->last_scores ) : 0.0;
	}

	/**
	 * How well typical off-topic questions match THIS knowledge base with THIS
	 * embedding model (median of the examples). Measured once and remembered
	 * until the content or the model changes. Null when it cannot be measured.
	 */
	public static function off_topic_baseline( $engine ) {
		$key  = 'wp_ai_offtopic_base_' . md5( WP_AI_Chatbot_AI::embedding_signature() . '|' . WP_AI_Chatbot_Knowledge_Index::cache_generation() );
		$base = get_transient( $key );
		if ( is_numeric( $base ) ) {
			return (float) $base;
		}
		$scores = array();
		foreach ( self::OFF_TOPIC_EXAMPLES as $q ) {
			$s = self::top_score( $engine, $q );
			if ( null === $s ) {
				return null;
			}
			$scores[] = $s;
		}
		sort( $scores );
		$n    = count( $scores );
		$base = $n % 2 ? $scores[ intdiv( $n, 2 ) ] : ( $scores[ $n / 2 - 1 ] + $scores[ $n / 2 ] ) / 2;
		set_transient( $key, $base, WEEK_IN_SECONDS );
		update_option( 'wp_ai_chatbot_offtopic_base', array( 'base' => $base, 'at' => current_time( 'mysql' ) ), false );
		return (float) $base;
	}

	/** Questions whose best match is at or below this score get the fixed reply. */
	public static function off_topic_cutoff( $engine ) {
		$base = self::off_topic_baseline( $engine );
		return null === $base ? null : $base + self::STRICTNESS[ self::off_topic_strictness() ];
	}

	/** @return string[] Lower-case words and phrases that always reach the AI. */
	public static function allow_words() {
		$s     = WP_AI_Chatbot_AI::settings();
		// An emptied box means "no extra words" (not "use the defaults").
		$raw   = isset( $s['off_topic_allow'] ) ? (string) $s['off_topic_allow'] : self::DEFAULT_ALLOW_WORDS;
		$words = array();
		foreach ( preg_split( '/[,\n]+/u', $raw ) as $w ) {
			$w = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $w ) : strtolower( $w ) );
			if ( '' !== $w ) {
				$words[] = $w;
			}
		}
		return array_values( array_unique( $words ) );
	}

	/** True when the message contains one of the words (whole words, any case). */
	public static function mentions_any( $message, $words ) {
		$m = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $message ) : strtolower( (string) $message );
		foreach ( $words as $w ) {
			if ( '' !== $w && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/u', $m ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when the site's daily token limit has been reached. */
	public static function limit_reached() {
		$limit = self::daily_limit();
		return $limit > 0 && self::used_today() >= $limit;
	}

	/**
	 * Checks before a visitor's chat message is sent to the AI.
	 * Returns null to go ahead, or the reply to show instead (no AI call, no tokens).
	 */
	public static function chat_gate( $ip ) {
		if ( self::limit_reached() ) {
			return self::limit_message();
		}
		$per_visitor = self::visitor_daily_messages();
		if ( $per_visitor > 0 && ! WP_AI_Chatbot_Rate_Limiter::allow( 'chat_day_' . $ip, $per_visitor, DAY_IN_SECONDS ) ) {
			return "You have reached today's message limit for this chat. Please use the contact form or WhatsApp and our team will help you.";
		}
		return null;
	}
}
