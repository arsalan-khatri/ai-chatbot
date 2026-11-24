<?php
/**
 * Admin notices: AI errors, retired-model upgrades and knowledge base re-index reminders.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Admin_Notices {

	/** Vector count above which the RAG screen shows a performance note. */
	const LARGE_INDEX = 20000;

	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_wp_ai_chatbot_dismiss_notice', array( $this, 'dismiss_notice' ) );
	}

	private function is_plugin_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0 === strpos( $page, 'wp-ai-chatbot' );
	}

	private function dismiss_url( $type ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wp_ai_chatbot_dismiss_notice&notice=' . $type ),
			'wp_ai_chatbot_dismiss_' . $type
		);
	}

	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 1. Upgrade notices (shown on every admin screen until dismissed).
		$upgrade = get_option( 'wp_ai_chatbot_upgrade_notices', array() );
		if ( ! empty( $upgrade ) && is_array( $upgrade ) ) {
			echo '<div class="notice notice-info"><p><strong>AI Chatbot updated:</strong></p><ul style="list-style:disc;margin-left:20px;">';
			foreach ( $upgrade as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul><p><a href="' . esc_url( $this->dismiss_url( 'upgrade' ) ) . '">Dismiss</a></p></div>';
		}

		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		// 2. Latest AI error (last 48 hours).
		$error = get_option( WP_AI_Chatbot_AI::LAST_ERROR_OPTION );
		if ( is_array( $error ) && ! empty( $error['time'] ) && ( time() - (int) $error['time'] ) < 2 * DAY_IN_SECONDS ) {
			printf(
				'<div class="notice notice-error"><p><strong>AI Chatbot: last error</strong> (%1$s ago, %2$s): %3$s</p><p>Visitors were shown a polite fallback message instead of this error. <a href="%4$s">Dismiss</a></p></div>',
				esc_html( human_time_diff( (int) $error['time'] ) ),
				esc_html( isset( $error['context'] ) ? $error['context'] : '' ),
				esc_html( isset( $error['message'] ) ? $error['message'] : '' ),
				esc_url( $this->dismiss_url( 'error' ) )
			);
		}

		// Simple mode: no search, so the search and re-index notices below do not apply.
		if ( WP_AI_Chatbot_Bot_Mode::is_basic() ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'wp-ai-chatbot-rag' === $page ) {
				printf(
					'<div class="notice notice-info"><p><strong>Simple mode is on.</strong> The chatbot answers from the business details in AI Settings and does not use what is trained here. Switch to Smart mode in <a href="%s">AI Settings &rarr; Chatbot mode</a> to use your pages, PDFs and products.</p></div>',
					esc_url( admin_url( 'admin.php?page=wp-ai-chatbot' ) )
				);
			}
			return;
		}

		// 2b. Search & voice off, or on without a key (Claude, DeepSeek, Grok, Mistral).
		$chat = WP_AI_Chatbot_AI::chat_provider();
		if ( ! in_array( $chat, array( 'gemini', 'openai' ), true ) ) {
			$settings_url = admin_url( 'admin.php?page=wp-ai-chatbot' );
			if ( ! WP_AI_Chatbot_AI::search_voice_on() ) {
				printf(
					'<div class="notice notice-warning"><p><strong>Website search and voice are turned off.</strong> The chatbot answers only from your instructions, not from your pages, PDFs or products, and the microphone is hidden. To turn them on, add a free Gemini key (or an OpenAI key) under <a href="%s">AI Settings &rarr; Search &amp; voice</a>.</p></div>',
					esc_url( $settings_url )
				);
			} elseif ( '' === WP_AI_Chatbot_AI::api_key( WP_AI_Chatbot_AI::embedding_provider() ) ) {
				printf(
					'<div class="notice notice-warning"><p><strong>Website search and voice need a key.</strong> %1$s writes the answers, but searching your pages and PDFs needs a %2$s key. Add it under <a href="%3$s">AI Settings &rarr; Search &amp; voice</a>, or turn search &amp; voice off there.</p></div>',
					esc_html( WP_AI_Chatbot_AI::provider_label( $chat ) ),
					esc_html( 'openai' === WP_AI_Chatbot_AI::embedding_provider() ? 'OpenAI' : 'Gemini (free)' ),
					esc_url( $settings_url )
				);
			}
		}

		// 3. Vectors created with a different embedding model than the current one.
		global $wpdb;
		$table = $wpdb->prefix . 'ai_vector_embeddings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );
			if ( is_array( $columns ) && in_array( 'embed_model', $columns, true ) ) {
				$outdated = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE embed_model <> %s", WP_AI_Chatbot_AI::embedding_signature() ) );
				if ( $outdated > 0 ) {
					printf(
						'<div class="notice notice-warning"><p><strong>Knowledge base needs re-indexing:</strong> %1$d item(s) were indexed with a different embedding model and are ignored until refreshed. Open <a href="%2$s">Vector RAG DB</a> and click <em>Process &amp; Index Selected Sources</em>.</p></div>',
						(int) $outdated,
						esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-rag' ) )
					);
				}
			}

			// 4. Items indexed before long content was split into chunks.
			$old_format = WP_AI_Chatbot_Knowledge_Index::outdated_format_count();
			if ( $old_format > 0 ) {
				printf(
					'<div class="notice notice-info"><p><strong>Knowledge base upgrade:</strong> %1$d item(s) were indexed by an older version and will be rebuilt once (into searchable chunks, with a completion check). This happens automatically (about 25 items per hour), or right away when you click <em>Process &amp; Index Selected Sources</em> in <a href="%2$s">Vector RAG DB</a>.</p></div>',
					(int) $old_format,
					esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-rag' ) )
				);
			}

			// 5. Very large index: every new question compares against all vectors.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'wp-ai-chatbot-rag' === $page ) {
				$vectors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
				if ( $vectors > self::LARGE_INDEX ) {
					printf(
						'<div class="notice notice-warning"><p><strong>Large knowledge base:</strong> %s vectors. Every new (uncached) question is compared with all of them in PHP, which can make replies slower on shared hosting. Index only what visitors need (Sync Specific), or limit the scan with <code>define( \'WP_AI_CHATBOT_RAG_SCAN_CAP\', 20000 );</code> in wp-config.php.</p></div>',
						esc_html( number_format_i18n( $vectors ) )
					);
				}
			}
		}
	}

	public function dismiss_notice() {
		$type = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $type, array( 'upgrade', 'error' ), true ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wp_ai_chatbot_dismiss_' . $type );

		if ( 'upgrade' === $type ) {
			delete_option( 'wp_ai_chatbot_upgrade_notices' );
		} else {
			delete_option( WP_AI_Chatbot_AI::LAST_ERROR_OPTION );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wp-ai-chatbot' ) );
		exit;
	}
}
