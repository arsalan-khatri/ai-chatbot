<?php
/**
 * Model catalog: which chat models the site's API key can use, and the
 * cheapest good one, picked automatically.
 *
 * When a key is saved (or "Refresh list" is clicked) the provider's own model
 * list is fetched. The automatic choice is the newest model of the cheapest
 * family that is good enough for a chatbot (Gemini Flash-Lite, GPT mini,
 * Claude Haiku, DeepSeek Flash, Grok fast/mini, Mistral Small). Previews,
 * audio, image, embedding and other special models are left out.
 *
 * The model box empty = automatic. A model the owner picks from the list (or
 * types) is always used as it is.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Models {

	const OPTION  = 'wp_ai_chatbot_model_catalog';
	const MAX_AGE = WEEK_IN_SECONDS;

	/** Model-list address of each provider. */
	const LIST_URLS = array(
		'gemini'    => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200',
		'openai'    => 'https://api.openai.com/v1/models',
		'anthropic' => 'https://api.anthropic.com/v1/models?limit=100',
		'deepseek'  => 'https://api.deepseek.com/models',
		'xai'       => 'https://api.x.ai/v1/models',
		'mistral'   => 'https://api.mistral.ai/v1/models',
	);

	/**
	 * Cheapest good chat families, best first. The first family the key has wins;
	 * inside it the newest version is chosen.
	 */
	const CHEAP_RULES = array(
		'gemini'    => array( '/^gemini-[\d.]+-flash-lite$/', '/^gemini-[\d.]+-flash-lite/', '/^gemini-[\d.]+-flash$/', '/^gemini-[\d.]+-flash/' ),
		'openai'    => array( '/^gpt-[\d.]+o?-mini$/', '/^gpt-[\d.]+o?-mini/', '/^gpt-[\d.]+o?$/' ),
		'anthropic' => array( '/^claude-haiku/', '/^claude-[\d-]+-haiku/', '/^claude-sonnet/', '/^claude-[\d-]+-sonnet/' ),
		'deepseek'  => array( '/^deepseek-flash/', '/^deepseek-chat/', '/^deepseek-v[\d.]+-flash/', '/^deepseek/' ),
		// Newer Grok flagships cost more, so the fast / mini / non-reasoning variants come first,
		// then the known low-price grok-4.3, and only then anything else.
		'xai'       => array( '/^grok-[\d.]+-fast/', '/^grok-[\d.]+-mini/', '/non-reasoning/', '/^grok-4\.3$/', '/^grok/' ),
		'mistral'   => array( '/^mistral-small-latest$/', '/^mistral-small/', '/^mistral-medium-latest$/', '/^mistral/' ),
	);

	/** Models that are not general chat models, or not stable. */
	const EXCLUDE = '/(embed|tts|audio|realtime|transcri|speech|image|imagen|vision-exp|search|moderation|whisper|dall-e|computer-use|-live|preview|-exp|experimental|codex|coder|-code|codestral|devstral|magistral|-vision|build|(?<!non-)reasoning|reasoner|thinking|ocr|guard|instruct|gemma|aqa|learnlm|veo|lyria|robotics|nano-banana|deep-research)/i';

	/** @return array provider => array( models, auto, at, error ) */
	public static function all() {
		$c = get_option( self::OPTION, array() );
		return is_array( $c ) ? $c : array();
	}

	public static function get( $provider ) {
		$all = self::all();
		return isset( $all[ $provider ] ) && is_array( $all[ $provider ] ) ? $all[ $provider ] + array( 'models' => array(), 'auto' => '', 'at' => 0, 'error' => '', 'key' => '' ) : array( 'models' => array(), 'auto' => '', 'at' => 0, 'error' => '', 'key' => '' );
	}

	/** Automatic (cheapest) model for a provider, or '' when the list was never fetched. */
	public static function auto( $provider ) {
		$c = self::get( $provider );
		// A list fetched for another key no longer applies.
		if ( '' === $c['auto'] || $c['key'] !== self::key_hash( WP_AI_Chatbot_AI::api_key( $provider ) ) ) {
			return '';
		}
		return (string) $c['auto'];
	}

	/** True when the saved key was checked with the provider and works (its model list loaded). */
	public static function key_ok( $provider ) {
		$key = WP_AI_Chatbot_AI::api_key( $provider );
		$c   = self::get( $provider );
		return '' !== $key && $c['key'] === self::key_hash( $key ) && '' === $c['error'] && ! empty( $c['models'] );
	}

	private static function key_hash( $key ) {
		return '' === (string) $key ? '' : substr( md5( (string) $key ), 0, 12 );
	}

	/** Numbers in a model id (dates left out), for "newest first". */
	private static function version_of( $id ) {
		$id = preg_replace( '/\d{4}-\d{2}-\d{2}|\d{8}|-\d{4}$/', '', $id );
		preg_match_all( '/\d+/', $id, $m );
		return array_map( 'intval', $m[0] );
	}

	/** Sorts ids newest first; on a tie the short alias (no date) wins. */
	private static function newest_first( $ids ) {
		usort(
			$ids,
			function ( $a, $b ) {
				$va = self::version_of( $a );
				$vb = self::version_of( $b );
				$n  = max( count( $va ), count( $vb ) );
				for ( $i = 0; $i < $n; $i++ ) {
					$x = isset( $va[ $i ] ) ? $va[ $i ] : -1;
					$y = isset( $vb[ $i ] ) ? $vb[ $i ] : -1;
					if ( $x !== $y ) {
						return $y <=> $x;
					}
				}
				return strlen( $a ) <=> strlen( $b );
			}
		);
		return $ids;
	}

	/** Chat models worth listing (special and unstable models left out), newest first. */
	public static function filter_chat_models( $provider, $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$id = WP_AI_Chatbot_AI::clean_model_id( $id );
			if ( '' === $id || preg_match( self::EXCLUDE, $id ) ) {
				continue;
			}
			if ( 'openai' === $provider && ! preg_match( '/^(gpt-|o\d)/', $id ) ) {
				continue;
			}
			if ( 'gemini' === $provider && ( 0 !== strpos( $id, 'gemini-' ) || WP_AI_Chatbot_AI::is_retired_gemini_chat_model( $id ) ) ) {
				continue;
			}
			$out[] = $id;
		}
		return self::newest_first( array_values( array_unique( $out ) ) );
	}

	/** The cheapest good model among the ids, or '' when none matches. */
	public static function pick_cheapest( $provider, $ids ) {
		$ids = self::filter_chat_models( $provider, $ids );
		foreach ( isset( self::CHEAP_RULES[ $provider ] ) ? self::CHEAP_RULES[ $provider ] : array() as $rule ) {
			$found = array_values( preg_grep( $rule, $ids ) );
			if ( $found ) {
				return $found[0]; // Already newest first.
			}
		}
		return '';
	}

	/** Asks the provider which models this key can use. @return string[]|WP_Error */
	public static function fetch( $provider, $key ) {
		if ( ! isset( self::LIST_URLS[ $provider ] ) ) {
			return new WP_Error( 'unknown_provider', 'Unknown provider.' );
		}
		$headers = array( 'Authorization' => 'Bearer ' . $key );
		if ( 'gemini' === $provider ) {
			$headers = array( 'x-goog-api-key' => $key );
		} elseif ( 'anthropic' === $provider ) {
			$headers = array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' );
		}
		$res = wp_remote_get( self::LIST_URLS[ $provider ], array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'http_error', 'Could not reach ' . WP_AI_Chatbot_AI::provider_label( $provider ) . ': ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : 'HTTP ' . $code );
			return new WP_Error( 'list_failed', WP_AI_Chatbot_AI::provider_label( $provider ) . ': ' . $msg, $code );
		}
		$ids = array();
		if ( 'gemini' === $provider ) {
			foreach ( (array) ( $data['models'] ?? array() ) as $m ) {
				if ( isset( $m['name'] ) && in_array( 'generateContent', (array) ( $m['supportedGenerationMethods'] ?? array() ), true ) ) {
					$ids[] = $m['name'];
				}
			}
		} else {
			foreach ( (array) ( $data['data'] ?? array() ) as $m ) {
				if ( ! isset( $m['id'] ) ) {
					continue;
				}
				// Mistral says which models can chat.
				if ( 'mistral' === $provider && isset( $m['capabilities']['completion_chat'] ) && ! $m['capabilities']['completion_chat'] ) {
					continue;
				}
				$ids[] = $m['id'];
			}
		}
		return $ids;
	}

	/** Fetches and stores the list and automatic choice for a provider. @return array catalog entry */
	public static function refresh( $provider ) {
		$all = self::all();
		$key = WP_AI_Chatbot_AI::api_key( $provider );
		if ( '' === $key ) {
			unset( $all[ $provider ] );
			update_option( self::OPTION, $all, false );
			return self::get( $provider );
		}
		$ids   = self::fetch( $provider, $key );
		$entry = self::get( $provider );
		if ( is_wp_error( $ids ) ) {
			if ( $entry['key'] !== self::key_hash( $key ) ) {
				$entry['models'] = array();
				$entry['auto']   = '';
			}
			$entry['error'] = $ids->get_error_message();
			$entry['at']    = time();
			$entry['key']   = self::key_hash( $key );
		} else {
			$entry = array(
				'models' => self::filter_chat_models( $provider, $ids ),
				'auto'   => self::pick_cheapest( $provider, $ids ),
				'at'     => time(),
				'error'  => '',
				'key'    => self::key_hash( $key ),
			);
		}
		$all[ $provider ] = $entry;
		update_option( self::OPTION, $all, false );
		return $entry;
	}

	/**
	 * Hourly job: refreshes the model list of the provider in use when it is older than a
	 * week, so "Automatic" keeps following the cheapest model without a settings save.
	 */
	public static function maybe_refresh_weekly() {
		$p = WP_AI_Chatbot_AI::chat_provider();
		if ( '' === WP_AI_Chatbot_AI::api_key( $p ) ) {
			return;
		}
		$c = self::get( $p );
		if ( time() - (int) $c['at'] > self::MAX_AGE ) {
			self::refresh( $p );
		}
	}

	/** After AI Settings are saved: refresh lists for keys that are new or old. */
	public static function after_settings_saved( $old, $new ) {
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		foreach ( array_keys( WP_AI_Chatbot_AI::PROVIDERS ) as $p ) {
			$k_new = isset( $new[ $p . '_api_key' ] ) ? (string) $new[ $p . '_api_key' ] : '';
			$k_old = isset( $old[ $p . '_api_key' ] ) ? (string) $old[ $p . '_api_key' ] : '';
			$c     = self::get( $p );
			if ( $k_new !== $k_old || ( '' !== $k_new && ( time() - (int) $c['at'] > self::MAX_AGE ) ) ) {
				self::refresh( $p );
			}
		}
	}
}
