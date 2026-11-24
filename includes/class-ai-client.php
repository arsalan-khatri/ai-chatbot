<?php
/**
 * Central AI client.
 *
 * One place for model defaults, retired-model handling, chat completions,
 * embeddings and error reporting, so every feature of the plugin talks to
 * every provider the same way.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_AI {

	const DEFAULT_GEMINI_CHAT  = 'gemini-3.8-flash';
	const DEFAULT_GEMINI_EMBED = 'gemini-embedding-2';
	const DEFAULT_OPENAI_CHAT  = 'gpt-4o-mini';
	const DEFAULT_OPENAI_EMBED = 'text-embedding-3-small';

	/**
	 * Chat providers. Gemini and OpenAI also do embeddings (knowledge search) and Gemini does
	 * voice; the others only write the answers. DeepSeek, xAI and Mistral speak the OpenAI
	 * chat format at their own address; Anthropic has its own format.
	 */
	const PROVIDERS = array(
		'gemini'    => array( 'label' => 'Google Gemini', 'short' => 'Gemini', 'default' => '', 'url' => '', 'keys' => 'https://aistudio.google.com/app/apikey' ),
		'openai'    => array( 'label' => 'OpenAI (ChatGPT)', 'short' => 'OpenAI', 'default' => '', 'url' => 'https://api.openai.com/v1/chat/completions', 'keys' => 'https://platform.openai.com/api-keys' ),
		'anthropic' => array( 'label' => 'Anthropic (Claude)', 'short' => 'Claude', 'default' => 'claude-haiku-5-5', 'url' => 'https://api.anthropic.com/v1/messages', 'keys' => 'https://console.anthropic.com/settings/keys' ),
		'deepseek'  => array( 'label' => 'DeepSeek', 'short' => 'DeepSeek', 'default' => 'deepseek-flash', 'url' => 'https://api.deepseek.com/chat/completions', 'keys' => 'https://platform.deepseek.com/api_keys' ),
		'xai'       => array( 'label' => 'xAI (Grok)', 'short' => 'Grok', 'default' => 'grok-4.3', 'url' => 'https://api.x.ai/v1/chat/completions', 'keys' => 'https://console.x.ai' ),
		'mistral'   => array( 'label' => 'Mistral AI', 'short' => 'Mistral', 'default' => 'mistral-small-latest', 'url' => 'https://api.mistral.ai/v1/chat/completions', 'keys' => 'https://console.mistral.ai/api-keys' ),
	);

	/** "Claude", "Gemini"... for messages. */
	public static function provider_label( $provider, $long = false ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return ucfirst( (string) $provider );
		}
		return $long ? self::PROVIDERS[ $provider ]['label'] : self::PROVIDERS[ $provider ]['short'];
	}

	/** Recommended model of a provider. */
	public static function default_chat_model( $provider ) {
		if ( 'gemini' === $provider ) {
			return self::DEFAULT_GEMINI_CHAT;
		}
		if ( 'openai' === $provider ) {
			return self::DEFAULT_OPENAI_CHAT;
		}
		return isset( self::PROVIDERS[ $provider ] ) ? self::PROVIDERS[ $provider ]['default'] : '';
	}

	/** Vector size requested from Gemini embedding models (smaller = faster search, less storage). */
	const GEMINI_EMBED_DIMENSIONS = 768;

	const LAST_ERROR_OPTION = 'wp_ai_chatbot_last_error';

	/** Token usage of the current request (filled from the provider's own numbers when available). */
	private static $usage = array( 'prompt' => 0, 'completion' => 0, 'embedding' => 0, 'exact' => true );

	public static function reset_usage() {
		self::$usage = array( 'prompt' => 0, 'completion' => 0, 'embedding' => 0, 'exact' => true );
		self::$calls = array( 'chat' => 0, 'embed' => 0 );
	}

	/** @return array prompt, completion, embedding token counts and whether all of them came from the provider. */
	public static function usage() {
		return self::$usage;
	}

	private static function add_usage( $key, $tokens, $exact ) {
		self::$usage[ $key ] += max( 0, (int) $tokens );
		if ( ! $exact ) {
			self::$usage['exact'] = false;
		}
	}

	/** Rough token count used only when a provider does not report usage. */
	public static function estimate_tokens( $text ) {
		return (int) ceil( ( function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text ) ) / 4 );
	}

	/* ------------------------------------------------------------------
	 * Settings & models
	 * ------------------------------------------------------------------ */

	public static function settings() {
		$settings = get_option( 'wp_ai_chatbot_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	public static function rag_settings() {
		$settings = get_option( 'wp_ai_rag_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	public static function chat_provider() {
		$settings = self::settings();
		$p        = isset( $settings['api_provider'] ) ? (string) $settings['api_provider'] : 'gemini';
		return isset( self::PROVIDERS[ $p ] ) ? $p : 'gemini';
	}

	/**
	 * Website search (embeddings) and voice. Always on with Gemini or OpenAI. With Claude,
	 * DeepSeek, Grok or Mistral they need a separate Gemini or OpenAI key, and the owner may
	 * turn them off (AI Settings > Search & voice) instead of adding one.
	 */
	public static function search_voice_on() {
		if ( in_array( self::chat_provider(), array( 'gemini', 'openai' ), true ) ) {
			return true;
		}
		$s = self::settings();
		return ! isset( $s['aux_enabled'] ) || '0' !== (string) $s['aux_enabled'];
	}

	public static function embedding_provider() {
		// With Claude, DeepSeek, Grok or Mistral, the key chosen under Search & voice does the search.
		if ( ! in_array( self::chat_provider(), array( 'gemini', 'openai' ), true ) ) {
			$s = self::settings();
			if ( isset( $s['aux_provider'] ) && in_array( $s['aux_provider'], array( 'gemini', 'openai' ), true ) ) {
				return $s['aux_provider'];
			}
		}
		$settings = self::rag_settings();
		return ( isset( $settings['embedding_provider'] ) && 'openai' === $settings['embedding_provider'] ) ? 'openai' : 'gemini';
	}

	public static function api_key( $provider ) {
		$settings = self::settings();
		$key      = $provider . '_api_key';
		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/** Removes an accidental "models/" prefix and whitespace from a model id. */
	public static function clean_model_id( $model ) {
		$model = trim( (string) $model );
		if ( 0 === strpos( $model, 'models/' ) ) {
			$model = substr( $model, 7 );
		}
		return preg_replace( '/[^A-Za-z0-9._\-:]/', '', $model );
	}

	/** Gemini chat models that Google has shut down (or that are about to be). */
	public static function is_retired_gemini_chat_model( $model ) {
		$model    = self::clean_model_id( $model );
		$patterns = array(
			'/^gemini-1\./',
			'/^gemini-pro($|-)/',
			'/^gemini-2\.0(-|$)/',
			'/^gemini-2\.5-.*preview/',
			'/^gemini-3-pro-preview$/',
			'/^gemini-3\.1-flash-lite-preview/',
		);
		foreach ( (array) apply_filters( 'wp_ai_chatbot_retired_gemini_chat_patterns', $patterns ) as $pattern ) {
			if ( preg_match( $pattern, $model ) ) {
				return true;
			}
		}
		return false;
	}

	/** Gemini embedding models that Google has shut down (or that are about to be). */
	public static function is_retired_gemini_embedding_model( $model ) {
		$retired = array(
			'text-embedding-004',
			'embedding-001',
			'embedding-gecko-001',
			'gemini-embedding-exp',
			'gemini-embedding-exp-03-07',
			'embedding-2-preview',
		);
		$retired = (array) apply_filters( 'wp_ai_chatbot_retired_gemini_embedding_models', $retired );
		return in_array( self::clean_model_id( $model ), $retired, true );
	}

	public static function chat_model( $provider = null ) {
		$provider = $provider ? $provider : self::chat_provider();
		$settings = self::settings();

		if ( 'openai' === $provider ) {
			$model = isset( $settings['openai_model'] ) ? self::clean_model_id( $settings['openai_model'] ) : '';
			if ( '' === $model ) {
				$model = class_exists( 'WP_AI_Chatbot_Models' ) ? WP_AI_Chatbot_Models::auto( 'openai' ) : '';
			}
			return '' !== $model ? $model : apply_filters( 'wp_ai_chatbot_default_openai_chat_model', self::DEFAULT_OPENAI_CHAT );
		}
		if ( 'gemini' !== $provider && isset( self::PROVIDERS[ $provider ] ) ) {
			$model = isset( $settings[ $provider . '_model' ] ) ? self::clean_model_id( $settings[ $provider . '_model' ] ) : '';
			// Empty = automatic: the cheapest good model found in this key's model list.
			if ( '' === $model ) {
				$model = class_exists( 'WP_AI_Chatbot_Models' ) ? WP_AI_Chatbot_Models::auto( $provider ) : '';
			}
			return '' !== $model ? $model : (string) apply_filters( 'wp_ai_chatbot_default_' . $provider . '_chat_model', self::default_chat_model( $provider ) );
		}

		$model = isset( $settings['gemini_model'] ) ? self::clean_model_id( $settings['gemini_model'] ) : '';
		if ( '' === $model || self::is_retired_gemini_chat_model( $model ) ) {
			$auto  = class_exists( 'WP_AI_Chatbot_Models' ) ? WP_AI_Chatbot_Models::auto( 'gemini' ) : '';
			$model = '' !== $auto ? $auto : apply_filters( 'wp_ai_chatbot_default_gemini_chat_model', self::DEFAULT_GEMINI_CHAT );
		}
		return $model;
	}

	public static function embedding_model( $provider = null ) {
		$provider = $provider ? $provider : self::embedding_provider();
		$settings = self::rag_settings();

		if ( 'openai' === $provider ) {
			$model = isset( $settings['openai_embedding_model'] ) ? self::clean_model_id( $settings['openai_embedding_model'] ) : '';
			return '' !== $model ? $model : apply_filters( 'wp_ai_chatbot_default_openai_embedding_model', self::DEFAULT_OPENAI_EMBED );
		}

		$model = isset( $settings['gemini_embedding_model'] ) ? self::clean_model_id( $settings['gemini_embedding_model'] ) : '';
		if ( '' === $model || self::is_retired_gemini_embedding_model( $model ) ) {
			$model = apply_filters( 'wp_ai_chatbot_default_gemini_embedding_model', self::DEFAULT_GEMINI_EMBED );
		}
		return $model;
	}

	/** Identifies which provider/model produced a stored vector, e.g. "gemini:gemini-embedding-2". */
	public static function embedding_signature() {
		$provider = self::embedding_provider();
		return $provider . ':' . self::embedding_model( $provider );
	}

	public static function temperature() {
		return (float) apply_filters( 'wp_ai_chatbot_temperature', defined( 'WP_AI_CHATBOT_TEMPERATURE' ) ? WP_AI_CHATBOT_TEMPERATURE : 0.3 );
	}

	/** Token saver (AI Settings): on unless the admin turned it off. */
	public static function token_saver() {
		$s = self::settings();
		return ! isset( $s['token_saver'] ) || '0' !== (string) $s['token_saver'];
	}

	/**
	 * Visible-answer token limit per request type when the token saver is on.
	 * Thinking models get extra room on top (see chat_gemini / chat_openai), so
	 * the limit never eats the answer itself.
	 */
	public static function answer_tokens( $mode ) {
		$limits = array( 'simple' => 700, 'multi' => 1400, 'list' => 1400 );
		$limit  = isset( $limits[ $mode ] ) ? $limits[ $mode ] : $limits['simple'];
		// AI Settings > Token control > Answer length.
		$limit  = (int) round( $limit * WP_AI_Chatbot_Token_Control::answer_factor() );
		return (int) apply_filters( 'wp_ai_chatbot_answer_tokens', $limit, $mode );
	}

	/** Number of AI calls in the current request (chat retries included). */
	public static function calls() {
		return self::$calls;
	}

	private static $calls = array( 'chat' => 0, 'embed' => 0 );

	/** Cuts a reply that hit the output limit back to its last complete line or sentence, and closes open Markdown. */
	public static function finish_cut_reply( $text ) {
		$text = rtrim( (string) $text );
		$pos  = max( (int) strrpos( $text, "\n" ), (int) max( strrpos( $text, '. ' ), strrpos( $text, '! ' ), strrpos( $text, '? ' ) ) );
		if ( $pos > strlen( $text ) * 0.5 ) {
			$text = rtrim( substr( $text, 0, $pos + 1 ) );
		}
		// An unfinished link or bold marker would show as raw Markdown.
		$text = preg_replace( '/\[[^\]]*$|\]\([^)]*$/', '', $text );
		if ( substr_count( $text, '**' ) % 2 ) {
			$text .= '**';
		}
		return rtrim( $text );
	}

	public static function max_output_tokens() {
		return (int) ( defined( 'WP_AI_CHATBOT_MAX_OUTPUT_TOKENS' ) ? WP_AI_CHATBOT_MAX_OUTPUT_TOKENS : 1024 );
	}

	/* ------------------------------------------------------------------
	 * Chat
	 * ------------------------------------------------------------------ */

	/**
	 * Sends a conversation to the configured chat provider.
	 *
	 * @param string $system_prompt Instructions for the model.
	 * @param array  $messages      List of array( 'role' => 'user'|'assistant', 'content' => string ). Last item should be the user's message.
	 * @param array  $args          Optional: provider, model, temperature, max_tokens, timeout.
	 * @return string|WP_Error Reply text, or WP_Error with a technical message (never show it to visitors).
	 */
	public static function chat( $system_prompt, $messages, $args = array() ) {
		$provider = ! empty( $args['provider'] ) ? $args['provider'] : self::chat_provider();
		$api_key  = self::api_key( $provider );

		if ( '' === $api_key ) {
			return new WP_Error( 'missing_api_key', sprintf( 'The %s API key is missing. Add it under AI Chatbot, AI Settings.', self::provider_label( $provider ) ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'model'       => self::chat_model( $provider ),
				'temperature' => self::temperature(),
				'max_tokens'  => self::max_output_tokens(),
				'timeout'     => 45,
			)
		);

		$messages = self::normalize_messages( $messages );
		if ( empty( $messages ) ) {
			return new WP_Error( 'empty_conversation', 'No message was sent to the AI provider.' );
		}

		$system_prompt = trim( (string) $system_prompt ) . "\n\nStyle: never use the long dash characters (\u{2014} or \u{2013}). Use a comma, colon or full stop instead.";

		if ( 'gemini' === $provider ) {
			$reply = self::chat_gemini( $api_key, $system_prompt, $messages, $args );
		} elseif ( 'anthropic' === $provider ) {
			$reply = self::chat_anthropic( $api_key, $system_prompt, $messages, $args );
		} elseif ( 'openai' === $provider ) {
			$reply = self::chat_openai( $api_key, $system_prompt, $messages, $args );
		} else {
			$reply = self::chat_openai( $api_key, $system_prompt, $messages, $args, $provider );
		}

		return is_wp_error( $reply ) ? $reply : self::remove_long_dashes( $reply );
	}

	/** Replaces em/en dashes the model may still write with a comma (or "to" between numbers). */
	public static function remove_long_dashes( $text ) {
		$text = preg_replace( '/\s*[\x{2014}\x{2015}]\s*/u', ', ', (string) $text );
		$text = preg_replace( '/(\d)\s*\x{2013}\s*(\d)/u', '$1 to $2', $text );
		$text = preg_replace( '/\s*\x{2013}\s*/u', ', ', $text );
		return str_replace( array( ',,', ' ,' ), array( ',', ',' ), $text );
	}

	/** Cleans roles/content and merges consecutive messages from the same speaker. */
	private static function normalize_messages( $messages ) {
		$clean = array();
		foreach ( (array) $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['content'] ) ) {
				continue;
			}
			$content = trim( (string) $message['content'] );
			if ( '' === $content ) {
				continue;
			}
			$role = ( isset( $message['role'] ) && 'user' === $message['role'] ) ? 'user' : 'assistant';

			$last = count( $clean ) - 1;
			if ( $last >= 0 && $clean[ $last ]['role'] === $role ) {
				$clean[ $last ]['content'] .= "\n\n" . $content;
			} else {
				$clean[] = array( 'role' => $role, 'content' => $content );
			}
		}

		// Conversations must start with the user (drop leading greetings from the bot).
		while ( ! empty( $clean ) && 'user' !== $clean[0]['role'] ) {
			array_shift( $clean );
		}
		return $clean;
	}

	private static function openai_supports_temperature( $model ) {
		return ! preg_match( '/^(o\d|gpt-5|gpt-6)/', $model );
	}

	/**
	 * OpenAI chat format. $provider other than 'openai' (DeepSeek, xAI, Mistral) sends the same
	 * request to that provider's address, with the classic "max_tokens" limit.
	 */
	private static function chat_openai( $api_key, $system_prompt, $messages, $args, $provider = 'openai' ) {
		$endpoint = isset( self::PROVIDERS[ $provider ]['url'] ) && '' !== self::PROVIDERS[ $provider ]['url'] ? self::PROVIDERS[ $provider ]['url'] : 'https://api.openai.com/v1/chat/completions';
		$compat   = 'openai' !== $provider;
		$label    = self::provider_label( $provider );
		$payload_messages = array();
		if ( '' !== $system_prompt ) {
			$payload_messages[] = array( 'role' => 'system', 'content' => $system_prompt );
		}
		foreach ( $messages as $message ) {
			$payload_messages[] = array( 'role' => $message['role'], 'content' => $message['content'] );
		}

		if ( $compat ) {
			// DeepSeek models think by default; keep it light and leave room for it in the limit.
			$reasoning = 'deepseek' === $provider || preg_match( '/reason|think/i', $args['model'] );
			$body      = array(
				'model'       => $args['model'],
				'messages'    => $payload_messages,
				'max_tokens'  => (int) $args['max_tokens'] + ( $reasoning ? 1024 : 0 ),
				'temperature' => (float) $args['temperature'],
			);
		} else {
			$reasoning = ! self::openai_supports_temperature( $args['model'] ); // o-series / gpt-5: reasoning models.
			$body      = array(
				'model'                 => $args['model'],
				'messages'              => $payload_messages,
				// Reasoning tokens count against this limit too, so they get their own room.
				'max_completion_tokens' => (int) $args['max_tokens'] + ( $reasoning && ! empty( $args['saver'] ) ? 2048 : 0 ),
			);
			if ( self::openai_supports_temperature( $args['model'] ) ) {
				$body['temperature'] = (float) $args['temperature'];
			}
		}
		if ( $reasoning && ! empty( $args['saver'] ) ) {
			$body['reasoning_effort'] = 'low';
		}

		self::$calls['chat']++;
		$result = self::post_json(
			$endpoint,
			$body,
			array( 'Authorization' => 'Bearer ' . $api_key ),
			$args['timeout']
		);

		// Some newer models reject custom temperature values; retry once without it.
		if ( is_wp_error( $result ) && isset( $body['temperature'] ) && false !== stripos( $result->get_error_message(), 'temperature' ) ) {
			unset( $body['temperature'] );
			self::$calls['chat']++;
			$result = self::post_json( $endpoint, $body, array( 'Authorization' => 'Bearer ' . $api_key ), $args['timeout'] );
		}
		// Models without the reasoning_effort option reject it (no tokens are billed for a rejected request): retry once without it.
		if ( is_wp_error( $result ) && isset( $body['reasoning_effort'] ) && false !== stripos( $result->get_error_message(), 'reasoning' ) ) {
			unset( $body['reasoning_effort'] );
			self::$calls['chat']++;
			$result = self::post_json( $endpoint, $body, array( 'Authorization' => 'Bearer ' . $api_key ), $args['timeout'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = isset( $result['choices'][0]['message']['content'] ) ? trim( (string) $result['choices'][0]['message']['content'] ) : '';
		if ( isset( $result['usage']['prompt_tokens'] ) ) {
			self::add_usage( 'prompt', $result['usage']['prompt_tokens'], true );
			self::add_usage( 'completion', isset( $result['usage']['completion_tokens'] ) ? $result['usage']['completion_tokens'] : 0, true );
		} else {
			self::add_usage( 'prompt', self::estimate_tokens( wp_json_encode( $payload_messages ) ), false );
			self::add_usage( 'completion', self::estimate_tokens( $text ), false );
		}
		$reason = isset( $result['choices'][0]['finish_reason'] ) ? $result['choices'][0]['finish_reason'] : '';
		if ( '' === $text ) {
			return new WP_Error( 'empty_reply', $label . ' returned an empty reply (finish reason: ' . ( $reason ? $reason : 'unknown' ) . ').' );
		}
		return 'length' === $reason ? self::finish_cut_reply( $text ) : $text;
	}

	/** Anthropic Messages API (Claude). */
	private static function chat_anthropic( $api_key, $system_prompt, $messages, $args ) {
		$endpoint = self::PROVIDERS['anthropic']['url'];
		$headers  = array( 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01' );
		$body     = array(
			'model'       => $args['model'],
			'max_tokens'  => max( 256, (int) $args['max_tokens'] ),
			'messages'    => array_map( function ( $m ) { return array( 'role' => $m['role'], 'content' => $m['content'] ); }, $messages ),
			'temperature' => (float) $args['temperature'],
		);
		if ( '' !== $system_prompt ) {
			$body['system'] = $system_prompt;
		}

		self::$calls['chat']++;
		$result = self::post_json( $endpoint, $body, $headers, $args['timeout'] );
		// A model that rejects an option (not billed): retry once without temperature.
		if ( is_wp_error( $result ) && 400 === (int) $result->get_error_data() && false !== stripos( $result->get_error_message(), 'temperature' ) ) {
			unset( $body['temperature'] );
			self::$calls['chat']++;
			$result = self::post_json( $endpoint, $body, $headers, $args['timeout'] );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = '';
		foreach ( (array) ( $result['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		$text = trim( $text );
		if ( isset( $result['usage']['input_tokens'] ) ) {
			$cached = (int) ( $result['usage']['cache_read_input_tokens'] ?? 0 ) + (int) ( $result['usage']['cache_creation_input_tokens'] ?? 0 );
			self::add_usage( 'prompt', (int) $result['usage']['input_tokens'] + $cached, true );
			self::add_usage( 'completion', (int) ( $result['usage']['output_tokens'] ?? 0 ), true );
		} else {
			self::add_usage( 'prompt', self::estimate_tokens( $system_prompt . wp_json_encode( $messages ) ), false );
			self::add_usage( 'completion', self::estimate_tokens( $text ), false );
		}
		$reason = isset( $result['stop_reason'] ) ? (string) $result['stop_reason'] : '';
		if ( '' === $text ) {
			return new WP_Error( 'empty_reply', 'Claude returned an empty reply (stop reason: ' . ( $reason ? $reason : 'unknown' ) . ').' );
		}
		return 'max_tokens' === $reason ? self::finish_cut_reply( $text ) : $text;
	}

	private static function chat_gemini( $api_key, $system_prompt, $messages, $args ) {
		$contents = array();
		foreach ( $messages as $message ) {
			$contents[] = array(
				'role'  => 'user' === $message['role'] ? 'user' : 'model',
				'parts' => array( array( 'text' => $message['content'] ) ),
			);
		}

		$saver = ! empty( $args['saver'] );
		$body  = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => (float) $args['temperature'],
				// Newer Gemini models "think" before answering and that uses output tokens too.
				// Token saver: the answer limit plus 1024 tokens of thinking room. Without it: at least 2048 (1.6 behaviour).
				'maxOutputTokens' => $saver ? (int) $args['max_tokens'] + 1024 : max( 2048, (int) $args['max_tokens'] ),
			),
		);
		if ( $saver ) {
			// Ask thinking models for light thinking. Gemini 2.5 takes a token budget, Gemini 3 and later a level.
			if ( preg_match( '/gemini-2\.5/', $args['model'] ) ) {
				$body['generationConfig']['thinkingConfig'] = array( 'thinkingBudget' => false !== strpos( $args['model'], 'pro' ) ? 512 : 256 );
			} elseif ( preg_match( '/gemini-([3-9]|\d{2})/', $args['model'] ) ) {
				$body['generationConfig']['thinkingConfig'] = array( 'thinkingLevel' => 'low' );
			}
		}
		if ( '' !== $system_prompt ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system_prompt ) ) );
		}

		$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $args['model'] ) . ':generateContent';
		self::$calls['chat']++;
		$result = self::post_json( $endpoint, $body, array( 'x-goog-api-key' => $api_key ), $args['timeout'] );

		// A model that does not accept the thinking option rejects the request (not billed): retry once without it.
		if ( is_wp_error( $result ) && isset( $body['generationConfig']['thinkingConfig'] ) && 400 === (int) $result->get_error_data() ) {
			unset( $body['generationConfig']['thinkingConfig'] );
			self::$calls['chat']++;
			$result = self::post_json( $endpoint, $body, array( 'x-goog-api-key' => $api_key ), $args['timeout'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = '';
		if ( isset( $result['candidates'][0]['content']['parts'] ) && is_array( $result['candidates'][0]['content']['parts'] ) ) {
			foreach ( $result['candidates'][0]['content']['parts'] as $part ) {
				if ( ! empty( $part['thought'] ) || ! isset( $part['text'] ) ) {
					continue;
				}
				$text .= $part['text'];
			}
		}
		$text = trim( $text );

		if ( isset( $result['usageMetadata']['promptTokenCount'] ) ) {
			$meta = $result['usageMetadata'];
			self::add_usage( 'prompt', $meta['promptTokenCount'], true );
			// Billed output = visible answer + the model's thinking tokens.
			self::add_usage( 'completion', ( isset( $meta['candidatesTokenCount'] ) ? $meta['candidatesTokenCount'] : 0 ) + ( isset( $meta['thoughtsTokenCount'] ) ? $meta['thoughtsTokenCount'] : 0 ), true );
		} else {
			self::add_usage( 'prompt', self::estimate_tokens( $system_prompt . wp_json_encode( $contents ) ), false );
			self::add_usage( 'completion', self::estimate_tokens( $text ), false );
		}

		$reason = isset( $result['candidates'][0]['finishReason'] ) ? $result['candidates'][0]['finishReason'] : '';
		// Thinking used the whole limit before any answer: one retry with double room (counted in usage), never more.
		if ( '' === $text && 'MAX_TOKENS' === $reason && $saver && empty( $args['retried'] ) ) {
			$args['retried']    = true;
			$args['max_tokens'] = (int) $args['max_tokens'] * 2 + 1024;
			return self::chat_gemini( $api_key, $system_prompt, $messages, $args );
		}
		if ( '' === $text ) {
			if ( ! empty( $result['promptFeedback']['blockReason'] ) ) {
				return new WP_Error( 'blocked', 'Gemini blocked this request (' . $result['promptFeedback']['blockReason'] . ').' );
			}
			return new WP_Error( 'empty_reply', 'Gemini returned an empty reply (finish reason: ' . ( $reason ? $reason : 'unknown' ) . ').' );
		}
		return 'MAX_TOKENS' === $reason ? self::finish_cut_reply( $text ) : $text;
	}

	/* ------------------------------------------------------------------
	 * Embeddings
	 * ------------------------------------------------------------------ */

	/**
	 * Creates an embedding vector for a piece of text.
	 *
	 * @param string $text Text to embed.
	 * @param string $task 'query' for a visitor question, 'document' for knowledge-base content.
	 * @return array|WP_Error List of floats, or WP_Error.
	 */
	public static function embed( $text, $task = 'query' ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return new WP_Error( 'empty_text', 'There is no text to embed.' );
		}

		if ( ! self::search_voice_on() ) {
			return new WP_Error( 'search_off', 'Website search is turned off (AI Settings > Search & voice).' );
		}
		$provider = self::embedding_provider();
		$model    = self::embedding_model( $provider );
		$api_key  = self::api_key( $provider );
		$max_len  = ( 'query' === $task ) ? 1000 : 8000;
		$text     = mb_substr( $text, 0, $max_len );

		if ( '' === $api_key ) {
			return new WP_Error( 'missing_api_key', sprintf( 'The %s API key is missing. Add it under AI Chatbot, AI Settings.', 'openai' === $provider ? 'OpenAI' : 'Gemini' ) );
		}
		self::$calls['embed']++;

		if ( 'openai' === $provider ) {
			$result = self::post_json(
				'https://api.openai.com/v1/embeddings',
				array( 'model' => $model, 'input' => $text ),
				array( 'Authorization' => 'Bearer ' . $api_key ),
				20
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$vector = isset( $result['data'][0]['embedding'] ) ? $result['data'][0]['embedding'] : null;
			if ( isset( $result['usage']['prompt_tokens'] ) ) {
				self::add_usage( 'embedding', $result['usage']['prompt_tokens'], true );
			} else {
				self::add_usage( 'embedding', self::estimate_tokens( $text ), false );
			}
		} else {
			$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':embedContent';
			$body     = array(
				'model'                => 'models/' . $model,
				'content'              => array( 'parts' => array( array( 'text' => $text ) ) ),
				'taskType'             => ( 'query' === $task ) ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT',
				'outputDimensionality' => self::GEMINI_EMBED_DIMENSIONS,
			);
			$result = self::post_json( $endpoint, $body, array( 'x-goog-api-key' => $api_key ), 20 );

			// If a model doesn't accept the optional tuning fields, retry with the plain request.
			if ( is_wp_error( $result ) && 400 === (int) $result->get_error_data() ) {
				unset( $body['taskType'], $body['outputDimensionality'] );
				$result = self::post_json( $endpoint, $body, array( 'x-goog-api-key' => $api_key ), 20 );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$vector = isset( $result['embedding']['values'] ) ? $result['embedding']['values'] : null;
			// The Gemini embedding API does not report token usage.
			self::add_usage( 'embedding', self::estimate_tokens( $text ), false );
		}

		if ( ! is_array( $vector ) || empty( $vector ) ) {
			return new WP_Error( 'empty_vector', 'The embedding API returned an empty vector. Please check your API plan limits.' );
		}
		return array_map( 'floatval', $vector );
	}

	/** Cosine similarity; returns 0 when vectors come from different models (different sizes). */
	/**
	 * Stored vector format. 1.7.6 stores vectors as packed 32-bit floats
	 * ("f32:" + base64, about 4 KB for 768 numbers instead of about 16 KB of
	 * JSON) because reading and decoding JSON was most of the search time.
	 * Older JSON rows keep working and are converted by the maintenance job.
	 */
	public static function pack_vector( $vector ) {
		$vector = array_map( 'floatval', array_values( (array) $vector ) );
		if ( empty( $vector ) ) {
			return '[]';
		}
		// 1.8.0: stored at unit length ("n32:"), so search needs only one dot product per row.
		// Cosine similarity does not depend on length, so results are the same as before.
		$unit = self::normalize( $vector );
		if ( $unit ) {
			return 'n32:' . base64_encode( pack( 'g*', ...$unit ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return 'f32:' . base64_encode( pack( 'g*', ...$vector ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Similarity of a stored vector with a query vector that is unit length and
	 * 1-indexed (array keys 1..n, as unpack() returns them). Fast path for
	 * "n32:" rows; other formats fall back to the full cosine.
	 *
	 * @return float|null Null when the stored value is not a vector of the same size.
	 */
	public static function similarity_packed( $q1, $stored ) {
		if ( 0 === strncmp( $stored, 'n32:', 4 ) ) {
			$bin = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $bin || strlen( $bin ) !== 4 * count( $q1 ) ) {
				return null;
			}
			$dot = 0.0;
			foreach ( unpack( 'g*', $bin ) as $i => $y ) {
				$dot += $q1[ $i ] * $y;
			}
			return $dot;
		}
		$b = self::unpack_vector( $stored );
		if ( ! $b || count( $b ) !== count( $q1 ) ) {
			return null;
		}
		return self::cosine_normalized( array_values( $q1 ), $b );
	}

	/** @return array|null List of floats, or null when the stored value is not a vector. */
	public static function unpack_vector( $stored ) {
		$stored = (string) $stored;
		if ( 0 === strncmp( $stored, 'f32:', 4 ) || 0 === strncmp( $stored, 'n32:', 4 ) ) {
			$bin = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $bin || 0 === strlen( $bin ) || 0 !== strlen( $bin ) % 4 ) {
				return null;
			}
			return array_values( unpack( 'g*', $bin ) );
		}
		$v = json_decode( $stored, true );
		return is_array( $v ) && $v ? array_values( $v ) : null;
	}

	/** Unit-length copy of a vector (so search only needs one dot product per row). */
	public static function normalize( $v ) {
		$n = 0.0;
		foreach ( $v as $x ) {
			$n += $x * $x;
		}
		if ( $n <= 0 ) {
			return array();
		}
		$n = sqrt( $n );
		return array_map( function ( $x ) use ( $n ) { return (float) $x / $n; }, array_values( $v ) );
	}

	/** Cosine similarity with an already normalized query vector. */
	public static function cosine_normalized( $q, $b ) {
		if ( count( $q ) !== count( $b ) || ! $q ) {
			return 0.0;
		}
		$dot = 0.0;
		$mb  = 0.0;
		foreach ( $b as $i => $y ) {
			$dot += $q[ $i ] * $y;
			$mb  += $y * $y;
		}
		return $mb > 0 ? $dot / sqrt( $mb ) : 0.0;
	}

	public static function cosine_similarity( $a, $b ) {
		$count = count( $a );
		if ( 0 === $count || count( $b ) !== $count ) {
			return 0.0;
		}
		$dot = 0.0;
		$ma  = 0.0;
		$mb  = 0.0;
		for ( $i = 0; $i < $count; $i++ ) {
			$x    = (float) $a[ $i ];
			$y    = (float) $b[ $i ];
			$dot += $x * $y;
			$ma  += $x * $x;
			$mb  += $y * $y;
		}
		if ( $ma <= 0 || $mb <= 0 ) {
			return 0.0;
		}
		return $dot / ( sqrt( $ma ) * sqrt( $mb ) );
	}

	/* ------------------------------------------------------------------
	 * Voice: speech to text with Gemini (any language)
	 * ------------------------------------------------------------------ */

	/** Gemini model used to turn visitor speech into text. */
	public static function voice_model() {
		$model = 'gemini' === self::chat_provider() ? self::chat_model( 'gemini' ) : self::DEFAULT_GEMINI_CHAT;
		return self::clean_model_id( apply_filters( 'wp_ai_chatbot_voice_model', $model ) );
	}

	/** The instruction sent with the audio. English stays English, Urdu and Hindi come back in Roman Urdu. */
	public static function voice_instruction() {
		$text = "You turn a short voice message from a website visitor into the chat message they meant to type.\n"
			. "- English speech: write it in English.\n"
			. "- Urdu or Hindi speech (also mixed with English): write it in Roman Urdu with Latin letters only, the way people type on WhatsApp, for example \"mujhe is ki price batao\". Never use Urdu, Hindi or Arabic script for Urdu or Hindi.\n"
			. "- Any other language: write it in that language's usual script.\n"
			. "- Keep product names, brand names, numbers and emails as spoken.\n"
			. "- Write only the words. No timestamps, no time ranges, no speaker labels, no notes, no descriptions of sounds.\n"
			. "- Do not translate, answer or add anything.\n"
			. "- If there is no clear human speech (silence, noise, music), set heard_speech to false and text to an empty string.";
		return (string) apply_filters( 'wp_ai_chatbot_voice_instruction', $text );
	}

	/**
	 * Transcribes a short WAV recording. Returns the text (empty when nothing
	 * clear was said) or WP_Error. Token usage is added to usage().
	 */
	public static function transcribe( $wav_binary ) {
		$api_key = self::api_key( 'gemini' );
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', 'Voice needs a Gemini API key.' );
		}
		$model = self::voice_model();
		$body  = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => self::voice_instruction() ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'inline_data' => array( 'mime_type' => 'audio/wav', 'data' => base64_encode( $wav_binary ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						array( 'text' => 'Write down what the visitor said in this voice message.' ),
					),
				),
			),
			'generationConfig'  => array(
				'temperature'      => 0,
				// 60 seconds of speech is about 200 words; a low cap also stops a runaway (slow) answer.
				'maxOutputTokens'  => 400,
				// A fixed JSON shape stops the model from writing subtitles with timestamps.
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'heard_speech' => array( 'type' => 'BOOLEAN' ),
						'text'         => array( 'type' => 'STRING' ),
					),
					'required'   => array( 'heard_speech', 'text' ),
				),
			),
		);
		// Transcription needs no thinking, and thinking is what makes a voice reply slow.
		// Try the lowest setting the model accepts; a rejected option costs nothing.
		$thinking = array();
		if ( preg_match( '/gemini-2\.5-flash/', $model ) ) {
			$thinking = array( array( 'thinkingBudget' => 0 ) );
		} elseif ( preg_match( '/gemini-2\.5-pro/', $model ) ) {
			$thinking = array( array( 'thinkingBudget' => 128 ) );
		} elseif ( preg_match( '/gemini-([3-9]|\d{2})/', $model ) ) {
			$thinking = array( array( 'thinkingLevel' => 'minimal' ), array( 'thinkingLevel' => 'low' ) );
		}

		$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
		$headers  = array( 'x-goog-api-key' => $api_key );
		$result   = null;
		foreach ( $thinking as $config ) {
			$body['generationConfig']['thinkingConfig'] = $config;
			self::$calls['chat']++;
			$result = self::post_json( $endpoint, $body, $headers, 20 );
			if ( ! ( is_wp_error( $result ) && 400 === (int) $result->get_error_data() ) ) {
				break;
			}
		}
		// No thinking options for this model, or it rejected them: plain settings.
		if ( null === $result || ( is_wp_error( $result ) && 400 === (int) $result->get_error_data() ) ) {
			unset( $body['generationConfig']['thinkingConfig'] );
			if ( null !== $result ) {
				unset( $body['generationConfig']['responseMimeType'], $body['generationConfig']['responseSchema'] );
			}
			self::$calls['chat']++;
			$result = self::post_json( $endpoint, $body, $headers, 20 );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$raw = '';
		foreach ( (array) ( $result['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( empty( $part['thought'] ) && isset( $part['text'] ) ) {
				$raw .= $part['text'];
			}
		}
		if ( isset( $result['usageMetadata']['promptTokenCount'] ) ) {
			$meta = $result['usageMetadata'];
			self::add_usage( 'prompt', $meta['promptTokenCount'], true );
			self::add_usage( 'completion', ( $meta['candidatesTokenCount'] ?? 0 ) + ( $meta['thoughtsTokenCount'] ?? 0 ), true );
		} else {
			// About 32 tokens per second of audio (16 kHz, 16-bit mono WAV = 32000 bytes per second).
			self::add_usage( 'prompt', (int) ceil( strlen( $wav_binary ) / 1000 ) + 250, false );
			self::add_usage( 'completion', self::estimate_tokens( $raw ), false );
		}

		// A runaway answer (cut at the limit) is never a real short voice message.
		if ( 'MAX_TOKENS' === ( $result['candidates'][0]['finishReason'] ?? '' ) ) {
			return '';
		}
		$json = json_decode( trim( preg_replace( '/^```(?:json)?|```$/m', '', trim( $raw ) ) ), true );
		if ( is_array( $json ) && array_key_exists( 'text', $json ) ) {
			if ( isset( $json['heard_speech'] ) && false === $json['heard_speech'] ) {
				return '';
			}
			$raw = is_string( $json['text'] ) ? $json['text'] : '';
		}
		return self::clean_transcript( $raw );
	}

	/**
	 * Cleans a transcript: removes timestamps ("00:00:02 - 00:00:04"), speaker
	 * labels, quotes and labels a model sometimes adds, and drops output that
	 * is only repetition (a model "hearing" silence).
	 */
	public static function clean_transcript( $text ) {
		$text = (string) $text;
		$t    = '\d{1,2}:\d{2}(?::\d{2})?(?:[.,]\d{1,3})?';
		// Time ranges ("00:00 - 00:02"), clock stamps with seconds ("00:00:02") and bracketed stamps ("[00:04]").
		// A plain time someone says ("5:30 baje") is kept.
		$text = preg_replace( '/[\[\(]?\b' . $t . '\b[\]\)]?\s*(?:-|--|\x{2013}|\x{2014}|->|to)\s*[\[\(]?\b' . $t . '\b[\]\)]?/u', ' ', $text );
		$text = preg_replace( '/\b\d{1,2}:\d{2}:\d{2}(?:[.,]\d{1,3})?\b|[\[\(]\s*' . $t . '\s*[\]\)]/u', ' ', $text );
		$text = preg_replace( '/\b(?:speaker|visitor|user)\s*\d*\s*:\s*/i', ' ', $text );
		$text = preg_replace( '/[\[\(](?:music|noise|silence|inaudible|background[^\]\)]*|laughs?)[\]\)]/i', ' ', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		$text = preg_replace( '/^(transcript(ion)?|text)\s*:\s*/i', '', $text );
		$text = trim( preg_replace( '/^[\s"\'`\x{201C}\x{201D}\-:,.]+|["\'`\x{201C}\x{201D}\s\-:,]+$/u', '', $text ) );
		$text = trim( preg_replace( '/^(transcript(ion)?|text)\s*:\s*/i', '', $text ) );

		// Only punctuation or digits left, or the same few words over and over: nothing was really said.
		if ( ! preg_match( '/\p{L}/u', $text ) ) {
			return '';
		}
		$words = preg_split( '/\s+/u', function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text ) );
		if ( count( $words ) >= 12 && count( array_unique( $words ) ) / count( $words ) < 0.3 ) {
			return '';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, (int) WP_AI_CHATBOT_MAX_MSG_LEN ) : substr( $text, 0, (int) WP_AI_CHATBOT_MAX_MSG_LEN );
	}

	/* ------------------------------------------------------------------
	 * HTTP + errors
	 * ------------------------------------------------------------------ */

	/**
	 * POSTs JSON and returns the decoded body, or WP_Error (error data = HTTP status).
	 */
	private static function post_json( $url, $body, $headers, $timeout ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => (int) $timeout,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'http_error', 'Could not reach the AI provider: ' . $response->get_error_message(), 0 );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = 'HTTP ' . $status;
			if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
				$error   = $decoded['error'];
				$message = is_array( $error ) && isset( $error['message'] ) ? $error['message'] : ( is_string( $error ) ? $error : $message );
			} elseif ( is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				$message = $decoded['message']; // Mistral style.
			} elseif ( is_array( $decoded ) && isset( $decoded['detail'] ) ) {
				$message = is_string( $decoded['detail'] ) ? $decoded['detail'] : wp_json_encode( $decoded['detail'] );
			}
			return new WP_Error( 'api_error', 'AI provider error (' . $status . '): ' . $message, $status );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'bad_response', 'The AI provider returned an unreadable response.', $status );
		}
		return $decoded;
	}

	/** Stores the latest technical error for the admin (never shown to visitors). */
	public static function record_error( $context, $error ) {
		$message = self::redact( is_wp_error( $error ) ? $error->get_error_message() : (string) $error );
		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'time'    => time(),
				'context' => sanitize_text_field( $context ),
				'message' => sanitize_text_field( $message ),
			),
			false
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[AI Chatbot] ' . $context . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Removes API keys (OpenAI sk-..., Google AIza...) and ?key= values from text before it is stored or shown. */
	public static function redact( $text ) {
		$text = preg_replace( '/\bsk-[A-Za-z0-9_\-*]{6,}/', 'sk-***', (string) $text );
		$text = preg_replace( '/\bAIza[0-9A-Za-z_\-]{10,}/', 'AIza***', $text );
		return preg_replace( '/([?&]key=)[^&\s]+/i', '$1***', $text );
	}

	/** Polite message shown to website visitors when the AI can't answer. */
	public static function friendly_error_message() {
		return (string) apply_filters(
			'wp_ai_chatbot_friendly_error_message',
			"Sorry, I'm having trouble answering right now. Please try again in a moment, or contact us directly and our team will help you."
		);
	}
}
