<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Data is removed ONLY if "Delete all chatbot data when the plugin is deleted"
 * is checked under AI Chatbot → Settings. Otherwise everything is kept, so
 * updating the plugin by delete + re-upload never loses leads or chat logs.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wp_ai_chatbot_settings = get_option( 'wp_ai_chatbot_settings', array() );
if ( ! is_array( $wp_ai_chatbot_settings ) || empty( $wp_ai_chatbot_settings['delete_data_on_uninstall'] ) || '1' !== $wp_ai_chatbot_settings['delete_data_on_uninstall'] ) {
	return;
}

global $wpdb;

// 1. Tables.
$wp_ai_chatbot_tables = array(
	$wpdb->prefix . 'ai_chat_analytics',
	$wpdb->prefix . 'ai_chat_logs',
	$wpdb->prefix . 'ai_chat_contacts',
	$wpdb->prefix . 'ai_unanswered',
	$wpdb->prefix . 'ai_unanswered_hits',
	$wpdb->prefix . 'ai_chatbot_leads',
	$wpdb->prefix . 'ai_vector_embeddings',
	$wpdb->prefix . 'ai_form_events',
	$wpdb->prefix . 'ai_rate_limits',
	$wpdb->prefix . 'ai_chat_sessions',
);
foreach ( array( 'ai_leads_', 'ai_kb_', 'ai_leadbak_' ) as $wp_ai_chatbot_prefix ) {
	$wp_ai_chatbot_like  = $wpdb->esc_like( $wpdb->prefix . $wp_ai_chatbot_prefix ) . '%';
	$wp_ai_chatbot_found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wp_ai_chatbot_like ) );
	if ( is_array( $wp_ai_chatbot_found ) ) {
		$wp_ai_chatbot_tables = array_merge( $wp_ai_chatbot_tables, $wp_ai_chatbot_found );
	}
}
foreach ( array_unique( $wp_ai_chatbot_tables ) as $wp_ai_chatbot_table ) {
	$wp_ai_chatbot_table = preg_replace( '/[^A-Za-z0-9_]/', '', $wp_ai_chatbot_table );
	$wpdb->query( "DROP TABLE IF EXISTS `{$wp_ai_chatbot_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// 2. Options.
$wp_ai_chatbot_options = array(
	'wp_ai_chatbot_settings',
	'wp_ai_chatbot_email',
	'wp_ai_chatbot_model_catalog',
	'wp_ai_chatbot_history_epoch',
	'wp_ai_chatbot_history',
	'wp_ai_chat_contacts_schema',
	'wp_ai_unanswered_schema',
	'wp_ai_chatbot_email_last',
	'wp_ai_chatbot_offtopic_base',
	'wp_ai_chatbot_custom_style',
	'wp_ai_chatbot_multi_forms',
	'wp_ai_chatbot_quick_actions',
	'wp_ai_chatbot_knowledge_bases',
	'wp_ai_chatbot_db_version',
	'wp_ai_chatbot_upgrade_notices',
	'wp_ai_chatbot_last_error',
	'wp_ai_rag_settings',
	'wp_ai_pre_chat_form_id',
	'wp_ai_guided_queries',
	'wp_ai_guided_flow_enabled',
	'wp_ai_form_events_db',
	'wp_ai_chatbot_rate_table',
	'wp_ai_chatbot_vectors_packed',
	'wp_ai_chatbot_voice_usage',
	'wp_ai_chatbot_dashboard_design',
	'wp_ai_rag_cache_gen',
	'wp_ai_chatbot_schema',
	'wp_ai_chatbot_webhook_secret',
	'wp_ai_chatbot_index_usage',
	'wp_ai_chatbot_pdf_docs',
	'wp_ai_chatbot_vectors_normalized',
	'wp_ai_chatbot_chat_page',
	'akdk_lead_column_backups',
	// 2.0.0
	'wp_ai_chat_sessions_schema',
	'wp_ai_chatbot_live',
	'wp_ai_chatbot_live_heartbeat',
	'wp_ai_chatbot_orders',
	'wp_ai_chatbot_proactive',
	'wp_ai_chatbot_bots',
	'wp_ai_chatbot_wa_ai',
	'wp_ai_chatbot_wa_last',
	'wp_ai_chatbot_insights',
);
foreach ( array( 'wp_ai_chatbot_rag_maintenance', 'wp_ai_chatbot_reindex_source', 'wp_ai_chatbot_kb_sync' ) as $wp_ai_chatbot_hook ) {
	wp_unschedule_hook( $wp_ai_chatbot_hook );
}
foreach ( $wp_ai_chatbot_options as $wp_ai_chatbot_option ) {
	delete_option( $wp_ai_chatbot_option );
}

// Text and status cached on PDF attachments (the PDF files themselves stay in the Media Library).
delete_post_meta_by_key( '_wp_ai_chatbot_pdf_text' );
delete_post_meta_by_key( '_wp_ai_chatbot_pdf_status' );

// Prefixed options (WhatsApp settings, knowledge base field maps, log schema versions).
foreach ( array( 'wp_ai_wa_', 'wp_ai_kb_fields_', 'wp_ai_logs_schema_v' ) as $wp_ai_chatbot_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $wp_ai_chatbot_prefix ) . '%' ) );
}

// 3. Cached data (transients).
foreach ( array( 'wp_ai_rag_', 'wp_ai_qemb_', 'wp_ai_rl_', 'wp_ai_conv_', 'wp_ai_offtopic_base_', 'akdk_form_view_', 'wp_ai_live_notified_', 'wp_ai_order_', 'wp_ai_wa_seen_' ) as $wp_ai_chatbot_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' . $wp_ai_chatbot_prefix ) . '%', $wpdb->esc_like( '_transient_timeout_' . $wp_ai_chatbot_prefix ) . '%' ) );
}
