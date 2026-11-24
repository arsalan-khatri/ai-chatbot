<?php
/**
 * Core plugin class: loads every module and registers its hooks.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot {

	public function __construct() {
		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	private function load_dependencies() {
		$files = array(
			'class-ai-client.php',
			'class-model-catalog.php',
			'class-widget-style.php',
			'class-admin-ui.php',
			'class-rate-limiter.php',
			'class-token-control.php',
			'class-bot-mode.php',
			'class-chat-history.php',
			'class-chat-contacts.php',
			'class-unanswered.php',
			'class-lead-alerts.php',
			'class-admin-settings.php',
			'class-admin-notices.php',
			'class-conversation-intelligence.php',
			'class-api-handler.php',
			'class-chat-renderer.php',
			'class-external-forms.php',
			'class-form-builder.php',
			'class-leads-manager.php',
			'class-quick-actions.php',
			'class-dynamic-db-manager.php',
			'class-memory-manager.php',
			'class-pre-chat-manager.php',
			'class-launcher-icons.php',
			'class-chatbot-customizer.php',
			'class-rag-manager.php',
			'class-knowledge-index.php',
			'class-pdf-knowledge.php',
			'class-grounding.php',
			'class-evidence.php',
			'class-conversation-state.php',
			'class-rag-embeddings.php',
			'class-rag-engine.php',
			'class-chat-logger.php',
			'class-voice.php',
			'class-whatsapp-settings.php',
			'class-guided-flow.php',
			'whatsapp.php',
			'class-dashboard.php',
			// 2.0.0: selling features.
			'class-i18n.php',
			'class-chat-sessions.php',
			'class-bots.php',
			'class-live-chat.php',
			'class-order-status.php',
			'class-product-cards.php',
			'class-proactive.php',
			'class-whatsapp-ai.php',
			'class-insights.php',
			'class-sales-tools.php',
		);
		foreach ( $files as $file ) {
			require_once WP_AI_CHATBOT_PATH . 'includes/' . $file;
		}
	}

	private function define_admin_hooks() {
		$plugin_admin = new WP_AI_Chatbot_Admin_Settings();
		add_action( 'admin_menu', array( $plugin_admin, 'add_plugin_admin_menu' ) );
		add_action( 'admin_init', array( $plugin_admin, 'register_plugin_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_admin_assets' ) );

		$modules = array(
			new WP_AI_Chatbot_Admin_UI(),
			new WP_AI_Chatbot_Admin_Notices(),
			new WP_AI_Chatbot_External_Forms(),
			new WP_AI_Chatbot_Form_Builder(),
			new WP_AI_Chatbot_Leads_Manager(),
			new WP_AI_Chatbot_Quick_Actions(),
			new WP_AI_Chatbot_Dynamic_DB(),
			new WP_AI_Chatbot_Pre_Chat_Manager(),
			new WP_AI_Chatbot_Customizer(),
			new WP_AI_Chatbot_RAG_Manager(),
			new WP_AI_Chatbot_Knowledge_Index(),
			new WP_AI_Chatbot_PDF_Knowledge(),
			new WP_AI_Chatbot_RAG_Embeddings(),
			new WP_AI_Chatbot_Logger(),
			new WP_AI_Chatbot_WhatsApp_Settings(),
			new WP_AI_Chatbot_Lead_Alerts(),
			new WP_AI_Chatbot_Chat_History(),
			new WP_AI_Chatbot_Unanswered(),
			new WP_AI_Chatbot_Guided_Flow(),
			new WP_AI_Chatbot_Dashboard(),
			new WP_AI_Chatbot_Live_Chat(),
			new WP_AI_Chatbot_WhatsApp_AI(),
			new WP_AI_Chatbot_Insights(),
			new WP_AI_Chatbot_Sales_Tools(),
		);
		foreach ( $modules as $module ) {
			$module->register_hooks();
		}
		// "Automatic" model: the provider's list is checked again once a week (hourly job, cheap when fresh).
		add_action( WP_AI_Chatbot_Knowledge_Index::MAINTENANCE_HOOK, array( 'WP_AI_Chatbot_Models', 'maybe_refresh_weekly' ), 30 );
	}

	private function define_public_hooks() {
		$plugin_public = new WP_AI_Chatbot_Chat_Renderer();
		add_action( 'wp_enqueue_scripts', array( $plugin_public, 'enqueue_public_assets' ) );
		add_action( 'wp_footer', array( $plugin_public, 'render_chat_widget_html' ) );
		// Dedicated chat page: [akdk_chatbot] shows the chat inside the page itself.
		add_action( 'init', array( $plugin_public, 'register_shortcodes' ) );
		add_action( 'rest_api_init', array( $plugin_public, 'register_chat_rest_route' ) );
	}

	/** Kept for backwards compatibility; hooks are registered in the constructor. */
	public function run() {}
}
