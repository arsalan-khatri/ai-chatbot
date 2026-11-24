<?php
/**
 * Plugin Name:       AK Deep Knowledge AI Chatbot
 * Plugin URI:        https://akdeepknowledge.com
 * Description:       AI chatbot for WordPress & WooCommerce that answers visitors from your own content (RAG), captures leads, and works with OpenAI or Google Gemini.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            AK Deep Knowledge
 * Author URI:        https://akdeepknowledge.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-chatbot
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_AI_CHATBOT_VERSION', '2.0.0' );
define( 'WP_AI_CHATBOT_DB_VERSION', '1.1.0' );
define( 'WP_AI_CHATBOT_FILE', __FILE__ );
define( 'WP_AI_CHATBOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_CHATBOT_URL', plugin_dir_url( __FILE__ ) );

/*
 * Tunable limits. Any of these can be overridden in wp-config.php.
 */
if ( ! defined( 'WP_AI_CHATBOT_MAX_MSG_LEN' ) )             define( 'WP_AI_CHATBOT_MAX_MSG_LEN', 1500 );    // Characters per visitor message.
if ( ! defined( 'WP_AI_CHATBOT_MAX_HISTORY_MSG_LEN' ) )     define( 'WP_AI_CHATBOT_MAX_HISTORY_MSG_LEN', 1500 );
if ( ! defined( 'WP_AI_CHATBOT_RATE_LIMIT_CHAT_MAX' ) )     define( 'WP_AI_CHATBOT_RATE_LIMIT_CHAT_MAX', 20 );      // Chat requests...
if ( ! defined( 'WP_AI_CHATBOT_RATE_LIMIT_CHAT_WINDOW' ) )  define( 'WP_AI_CHATBOT_RATE_LIMIT_CHAT_WINDOW', 60 );   // ...per this many seconds, per IP.
if ( ! defined( 'WP_AI_CHATBOT_RATE_LIMIT_LEAD_MAX' ) )     define( 'WP_AI_CHATBOT_RATE_LIMIT_LEAD_MAX', 10 );
if ( ! defined( 'WP_AI_CHATBOT_RATE_LIMIT_LEAD_WINDOW' ) )  define( 'WP_AI_CHATBOT_RATE_LIMIT_LEAD_WINDOW', 60 );
if ( ! defined( 'WP_AI_CHATBOT_MAX_OUTPUT_TOKENS' ) )       define( 'WP_AI_CHATBOT_MAX_OUTPUT_TOKENS', 1024 );
if ( ! defined( 'WP_AI_CHATBOT_TEMPERATURE' ) )             define( 'WP_AI_CHATBOT_TEMPERATURE', 0.3 );     // Lower = more factual answers.
if ( ! defined( 'WP_AI_CHATBOT_RAG_SCAN_CAP' ) )            define( 'WP_AI_CHATBOT_RAG_SCAN_CAP', 0 );      // Max vectors scanned per question (0 = all).
if ( ! defined( 'WP_AI_CHATBOT_RAG_CACHE_TTL' ) )           define( 'WP_AI_CHATBOT_RAG_CACHE_TTL', 300 );   // Seconds.
if ( ! defined( 'WP_AI_CHATBOT_EMBED_CACHE_TTL' ) )         define( 'WP_AI_CHATBOT_EMBED_CACHE_TTL', 3600 ); // Seconds.

require_once WP_AI_CHATBOT_PATH . 'includes/class-ai-client.php';

/**
 * Activation: default settings + database tables.
 */
function activate_wp_ai_chatbot() {
	$default_settings = array(
		'api_provider'  => 'gemini',
		'gemini_model'  => WP_AI_Chatbot_AI::DEFAULT_GEMINI_CHAT,
		'openai_model'  => WP_AI_Chatbot_AI::DEFAULT_OPENAI_CHAT,
		'system_prompt' => 'You are a friendly and helpful customer support assistant for this website. Answer clearly and briefly.',
		'primary_color' => '#0071a1',
		'bot_name'      => 'AI Assistant',
		'bot_icon'      => '',
	);

	if ( false === get_option( 'wp_ai_chatbot_settings' ) ) {
		add_option( 'wp_ai_chatbot_settings', $default_settings );
	}

	wp_ai_chatbot_create_table();
	wp_ai_chatbot_maybe_upgrade();
	flush_rewrite_rules();
}
register_activation_hook( WP_AI_CHATBOT_FILE, 'activate_wp_ai_chatbot' );

/**
 * Creates the legacy leads table (kept for backwards compatibility).
 */
function wp_ai_chatbot_create_table() {
	global $wpdb;
	$table_name      = $wpdb->prefix . 'ai_chatbot_leads';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		form_name varchar(255) NOT NULL DEFAULT '',
		form_data text NOT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Migration for very old installs: move "Form Purpose" out of the JSON into its own column.
	$columns = $wpdb->get_col( "DESCRIBE {$table_name}", 0 );
	if ( is_array( $columns ) && ! in_array( 'form_name', $columns, true ) ) {
		$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN form_name VARCHAR(255) NOT NULL DEFAULT '' AFTER id" );
		$rows = $wpdb->get_results( "SELECT id, form_data FROM {$table_name}" );
		foreach ( (array) $rows as $row ) {
			$data = json_decode( $row->form_data, true );
			if ( is_array( $data ) && isset( $data['Form Purpose'] ) ) {
				$form_name = $data['Form Purpose'];
				unset( $data['Form Purpose'] );
				$wpdb->update(
					$table_name,
					array( 'form_name' => $form_name, 'form_data' => wp_json_encode( $data ) ),
					array( 'id' => $row->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		}
	}
}

/**
 * Safety net: recreate the leads table if it was removed manually.
 */
add_action(
	'admin_init',
	function () {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ai_chatbot_leads';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			wp_ai_chatbot_create_table();
		}
	}
);

/**
 * One-time upgrade routine (runs once per plugin version).
 *
 * - Replaces AI models that Google has shut down with current ones.
 * - Tags every stored vector with the model that created it, so vectors from
 *   an old model are never compared with vectors from a new one.
 */
function wp_ai_chatbot_maybe_upgrade() {
	if ( get_option( 'wp_ai_chatbot_db_version' ) === WP_AI_CHATBOT_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$notices = array();

	// 1. Chat model.
	$settings = get_option( 'wp_ai_chatbot_settings', array() );
	if ( is_array( $settings ) ) {
		$old_chat = isset( $settings['gemini_model'] ) ? WP_AI_Chatbot_AI::clean_model_id( $settings['gemini_model'] ) : '';
		if ( '' === $old_chat || WP_AI_Chatbot_AI::is_retired_gemini_chat_model( $old_chat ) ) {
			$settings['gemini_model'] = WP_AI_Chatbot_AI::DEFAULT_GEMINI_CHAT;
			update_option( 'wp_ai_chatbot_settings', $settings );
			if ( '' !== $old_chat ) {
				$notices[] = sprintf( 'Gemini chat model "%s" has been retired by Google and was switched to "%s".', $old_chat, WP_AI_Chatbot_AI::DEFAULT_GEMINI_CHAT );
			}
		}
	}

	// 2. Embedding model (remember the old signature for existing vectors).
	$rag          = get_option( 'wp_ai_rag_settings', array() );
	$rag          = is_array( $rag ) ? $rag : array();
	$old_provider = ( isset( $rag['embedding_provider'] ) && 'openai' === $rag['embedding_provider'] ) ? 'openai' : 'gemini';
	if ( 'openai' === $old_provider ) {
		$old_model = ! empty( $rag['openai_embedding_model'] ) ? WP_AI_Chatbot_AI::clean_model_id( $rag['openai_embedding_model'] ) : WP_AI_Chatbot_AI::DEFAULT_OPENAI_EMBED;
	} else {
		$old_model = ! empty( $rag['gemini_embedding_model'] ) ? WP_AI_Chatbot_AI::clean_model_id( $rag['gemini_embedding_model'] ) : 'text-embedding-004';
		if ( WP_AI_Chatbot_AI::is_retired_gemini_embedding_model( $old_model ) ) {
			$rag['gemini_embedding_model'] = WP_AI_Chatbot_AI::DEFAULT_GEMINI_EMBED;
			update_option( 'wp_ai_rag_settings', $rag );
		}
	}
	$old_signature = $old_provider . ':' . $old_model;

	// 3. Vector table: add the embed_model column and tag existing rows.
	$vector_table = $wpdb->prefix . 'ai_vector_embeddings';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vector_table ) ) === $vector_table ) {
		$columns = $wpdb->get_col( "DESCRIBE {$vector_table}", 0 );
		if ( is_array( $columns ) && ! in_array( 'embed_model', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$vector_table} ADD COLUMN embed_model VARCHAR(150) NOT NULL DEFAULT '' AFTER vector_data" );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$vector_table} SET embed_model = %s WHERE embed_model = ''", $old_signature ) );

		if ( $old_signature !== WP_AI_Chatbot_AI::embedding_signature() ) {
			$outdated = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$vector_table} WHERE embed_model <> %s", WP_AI_Chatbot_AI::embedding_signature() ) );
			if ( $outdated > 0 ) {
				$notices[] = sprintf( 'Embedding model "%s" has been retired by Google and was switched to "%s". Please re-index your knowledge base (AI Chatbot → Vector RAG DB → Process & Index).', $old_model, WP_AI_Chatbot_AI::embedding_model() );
			}
		}
	}

	if ( ! empty( $notices ) ) {
		update_option( 'wp_ai_chatbot_upgrade_notices', $notices, false );
	}
	update_option( 'wp_ai_chatbot_db_version', WP_AI_CHATBOT_DB_VERSION );
}
add_action( 'plugins_loaded', 'wp_ai_chatbot_maybe_upgrade', 5 );

/**
 * Schema changes for 1.5: knowledge chunks, exact token usage, verified leads.
 * 1.7.1: index format version (old single-vector rows are rebuilt into chunks)
 * and submission IDs for de-duplicating verified leads.
 * Runs once, on init (after all plugin classes are loaded).
 */
function wp_ai_chatbot_schema_upgrade() {
	$schema = (string) get_option( 'wp_ai_chatbot_schema' );
	if ( '4' === $schema ) {
		return;
	}
	if ( '3' !== $schema ) {
		wp_ai_chatbot_schema_upgrade_3();
	}
	// 1.7.6: atomic rate-limit counters. If the table cannot be created (no CREATE privilege),
	// the old limiter keeps working and creation is retried once a day.
	if ( class_exists( 'WP_AI_Chatbot_Rate_Limiter' ) ) {
		if ( false !== get_transient( 'wp_ai_rate_table_retry' ) ) {
			update_option( 'wp_ai_chatbot_schema', '3' );
			return;
		}
		WP_AI_Chatbot_Rate_Limiter::ensure_table();
		if ( '1' !== get_option( 'wp_ai_chatbot_rate_table' ) ) {
			set_transient( 'wp_ai_rate_table_retry', 1, DAY_IN_SECONDS );
			update_option( 'wp_ai_chatbot_schema', '3' );
			return;
		}
	}
	update_option( 'wp_ai_chatbot_schema', '4' );
}

function wp_ai_chatbot_schema_upgrade_3() {
	global $wpdb;
	if ( class_exists( 'WP_AI_Chatbot_Knowledge_Index' ) ) {
		WP_AI_Chatbot_Knowledge_Index::migrate();
	}
	$analytics = $wpdb->prefix . 'ai_chat_analytics';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $analytics ) ) === $analytics ) {
		$columns = (array) $wpdb->get_col( "DESCRIBE {$analytics}", 0 );
		if ( ! in_array( 'token_source', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$analytics} ADD COLUMN token_source VARCHAR(10) NOT NULL DEFAULT 'estimate'" );
		}
		if ( ! in_array( 'unverified', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$analytics} ADD COLUMN unverified TINYINT(1) NOT NULL DEFAULT 0" );
		}
	}
	delete_option( 'wp_ai_form_events_db' ); // Re-runs the events table update (adds the data column).
	if ( class_exists( 'WP_AI_Chatbot_External_Forms' ) ) {
		WP_AI_Chatbot_External_Forms::ensure_table();
	}
	update_option( 'wp_ai_chatbot_schema', '3' );
}
add_action( 'init', 'wp_ai_chatbot_schema_upgrade', 5 );

function deactivate_wp_ai_chatbot() {
	foreach ( array( 'wp_ai_chatbot_rag_maintenance', 'wp_ai_chatbot_reindex_source', 'wp_ai_chatbot_kb_sync' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
	flush_rewrite_rules();
}
register_deactivation_hook( WP_AI_CHATBOT_FILE, 'deactivate_wp_ai_chatbot' );

/**
 * "Settings" link on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( WP_AI_CHATBOT_FILE ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wp-ai-chatbot' ) ) . '">Settings</a>' );
		return $links;
	}
);

function run_wp_ai_chatbot() {
	require_once WP_AI_CHATBOT_PATH . 'includes/class-wp-ai-chatbot.php';
	$plugin = new WP_AI_Chatbot();
	$plugin->run();
}
add_action( 'plugins_loaded', 'run_wp_ai_chatbot' );
