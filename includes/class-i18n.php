<?php
/**
 * Multilingual sites (2.0.0): WPML and Polylang.
 *
 * - The widget sends the page's language; the bot is told to answer in it
 *   (unless the visitor clearly writes in another language).
 * - Knowledge search prefers pages, posts and products in that language.
 * - The chat's own texts (welcome, placeholder, name, messages) are registered
 *   as strings, so they can be translated in WPML > String Translation or
 *   Polylang > Languages > Translations (group "AK Deep Knowledge AI Chatbot").
 *
 * Without WPML or Polylang nothing changes.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_I18n {

	const GROUP = 'AK Deep Knowledge AI Chatbot';

	/** Language of the current request (set from the widget for REST chat requests). */
	private static $lang = null;

	public static function active() {
		return function_exists( 'pll_current_language' ) || defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_current_language' );
	}

	public static function set_lang( $code ) {
		$code       = strtolower( preg_replace( '/[^a-zA-Z_-]/', '', (string) $code ) );
		self::$lang = '' !== $code ? substr( $code, 0, 20 ) : null;
		if ( null !== self::$lang && has_action( 'wpml_switch_language' ) ) {
			do_action( 'wpml_switch_language', self::$lang ); // So WPML string translations follow.
		}
	}

	/** Current language code ('' without a multilingual plugin). */
	public static function current() {
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$l = pll_current_language( 'slug' );
			return is_string( $l ) ? $l : '';
		}
		$l = apply_filters( 'wpml_current_language', null );
		return is_string( $l ) && 'all' !== $l ? $l : '';
	}

	/** Language name for the AI ("Urdu", "Arabic"); falls back to the code. */
	public static function name( $code ) {
		$code = (string) $code;
		if ( '' === $code ) {
			return '';
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$names = (array) pll_languages_list( array( 'fields' => 'name' ) );
			$i     = array_search( $code, $slugs, true );
			if ( false !== $i && isset( $names[ $i ] ) ) {
				return (string) $names[ $i ];
			}
		}
		$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $langs ) && isset( $langs[ $code ] ) ) {
			$l = $langs[ $code ];
			return (string) ( $l['translated_name'] ?? $l['native_name'] ?? $code );
		}
		$known = array( 'en' => 'English', 'ur' => 'Urdu', 'ar' => 'Arabic', 'hi' => 'Hindi', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'tr' => 'Turkish', 'fa' => 'Persian', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'ru' => 'Russian', 'zh' => 'Chinese', 'ja' => 'Japanese', 'bn' => 'Bengali', 'pa' => 'Punjabi', 'ms' => 'Malay', 'id' => 'Indonesian' );
		$base  = strtolower( substr( $code, 0, 2 ) );
		return isset( $known[ $base ] ) ? $known[ $base ] : $code;
	}

	/** Line for the system prompt ('' when the site is not multilingual). */
	public static function prompt_line() {
		$code = self::current();
		if ( '' === $code || ! self::active() ) {
			return '';
		}
		$name = self::name( $code );
		return "\nLanguage: the visitor is on the " . $name . ' version of this website. Reply in ' . $name . ' unless the visitor clearly writes in another language; then reply in their language.' . "\n";
	}

	/** Language of a post ('' when unknown). */
	public static function post_lang( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$l = pll_get_post_language( (int) $post_id, 'slug' );
			return is_string( $l ) ? $l : '';
		}
		$d = apply_filters( 'wpml_post_language_details', null, (int) $post_id );
		return is_array( $d ) && ! empty( $d['language_code'] ) ? (string) $d['language_code'] : '';
	}

	/**
	 * Keeps knowledge rows in the visitor's language when there are any: rows of
	 * posts in another language are dropped, rows without a language (PDFs,
	 * knowledge bases) always stay.
	 *
	 * @param object[] $rows Rows with source_type and source_id.
	 */
	public static function filter_rows( $rows, $post_sources ) {
		$code = self::current();
		if ( '' === $code || ! self::active() || ! $rows ) {
			return $rows;
		}
		$same  = array();
		$other = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row->source_type, $post_sources, true ) ) {
				$l = self::post_lang( (int) $row->source_id );
				if ( '' !== $l && $l !== $code ) {
					$other++;
					continue;
				}
			}
			$same[] = $row;
		}
		// Nothing in this language: better an answer from another language than none.
		return $same ? $same : $rows;
	}

	/**
	 * Translates one of the chat's texts with WPML / Polylang string translation.
	 * The string is registered the first time it is seen (admin) so it appears
	 * in the translation screens.
	 */
	public static function t( $name, $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}
		if ( function_exists( 'pll_register_string' ) && is_admin() ) {
			pll_register_string( $name, $value, self::GROUP, strlen( $value ) > 80 );
		}
		if ( is_admin() && has_action( 'wpml_register_single_string' ) ) {
			do_action( 'wpml_register_single_string', self::GROUP, $name, $value );
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value; // Admin screens show the original.
		}
		if ( function_exists( 'pll__' ) ) {
			$code = self::current();
			if ( '' !== $code && function_exists( 'pll_translate_string' ) ) {
				return (string) pll_translate_string( $value, $code );
			}
			return (string) pll__( $value );
		}
		if ( has_filter( 'wpml_translate_single_string' ) ) {
			return (string) apply_filters( 'wpml_translate_single_string', $value, self::GROUP, $name, self::current() ?: null );
		}
		return $value;
	}

	/** Registers the chat's texts so they show in the translation screens even before they are used. */
	public static function register_all() {
		if ( ! self::active() ) {
			return;
		}
		$o = WP_AI_Chatbot_Widget_Style::get();
		self::t( 'Chatbot name', $o['bot_name'] );
		self::t( 'Subtitle', $o['header_subtitle'] );
		self::t( 'Input placeholder', $o['input_placeholder'] );
		self::t( 'Launcher greeting', $o['launcher_greeting'] );
		self::t( 'Welcome message', 'Hi there! How can I help you today?' );
		self::t( 'Off-topic message', WP_AI_Chatbot_Token_Control::off_topic_message() );
		self::t( 'Daily limit message', WP_AI_Chatbot_Token_Control::limit_message() );
		if ( class_exists( 'WP_AI_Chatbot_Live_Chat' ) ) {
			self::t( 'Live chat: connecting message', WP_AI_Chatbot_Live_Chat::online_message() );
			self::t( 'Live chat: offline message', WP_AI_Chatbot_Live_Chat::offline_message() );
			self::t( 'Live chat: button', (string) WP_AI_Chatbot_Live_Chat::settings()['button_label'] );
		}
		if ( class_exists( 'WP_AI_Chatbot_Proactive' ) ) {
			foreach ( WP_AI_Chatbot_Proactive::rules() as $r ) {
				self::t( 'Proactive message ' . $r['id'], $r['message'] );
			}
		}
	}
}
