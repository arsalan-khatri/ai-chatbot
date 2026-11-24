<?php
/**
 * AI summary and lead score (2.0.0).
 *
 * Every hour (and with "Summarize now" in Chat History) chats that ended at
 * least 15 minutes ago get a 2-line summary, what the visitor wanted (intent)
 * and a lead score from 0 to 100: hot (70+), warm (40-69), cold. A submitted
 * form adds to the score. Chat History shows hot leads first when you choose
 * "Hot leads first", and Live Chat shows the summary.
 *
 * One short AI call per chat (about 300-900 tokens), at most 8 chats per run.
 * Chats with fewer than 2 visitor messages are skipped.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Insights {

	const OPTION   = 'wp_ai_chatbot_insights';
	const PER_RUN  = 8;
	const IDLE_MIN = 15;

	public function register_hooks() {
		add_action( WP_AI_Chatbot_Knowledge_Index::MAINTENANCE_HOOK, array( __CLASS__, 'run' ), 40 );
		add_action( 'wp_ajax_wp_ai_insights_now', array( $this, 'ajax_now' ) );
	}

	public static function settings() {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array_merge( array( 'enabled' => '1', 'hot_email' => '0' ), $o );
	}

	public static function enabled() {
		return '1' === (string) self::settings()['enabled'] && '' !== WP_AI_Chatbot_AI::api_key( WP_AI_Chatbot_AI::chat_provider() );
	}

	public static function label_for( $score ) {
		$score = (int) $score;
		if ( $score < 0 ) {
			return '';
		}
		return $score >= 70 ? 'hot' : ( $score >= 40 ? 'warm' : 'cold' );
	}

	/** Chats that need a (new) summary. @return string[] session IDs */
	public static function due( $limit = self::PER_RUN, $only = array() ) {
		global $wpdb;
		$logs = $wpdb->prefix . 'ai_chat_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs ) ) !== $logs ) {
			return array();
		}
		WP_AI_Chatbot_Sessions::ensure_table();
		$idle  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::IDLE_MIN * MINUTE_IN_SECONDS );
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 14 * DAY_IN_SECONDS );
		$where = '';
		$args  = array( $since );
		if ( $only ) {
			$where = ' AND session_id IN (' . implode( ',', array_fill( 0, count( $only ), '%s' ) ) . ')';
			$args  = array_merge( $args, array_values( $only ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT session_id, COUNT(*) AS n, MAX(created_at) AS last_at, SUM(CASE WHEN user_message NOT LIKE '[%%' AND user_message <> '' THEN 1 ELSE 0 END) AS visitor FROM {$logs} WHERE created_at >= %s{$where} GROUP BY session_id ORDER BY last_at DESC LIMIT 300", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$meta = WP_AI_Chatbot_Sessions::for_sessions( wp_list_pluck( (array) $rows, 'session_id' ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( (int) $r->visitor < 2 ) {
				continue;
			}
			if ( ! $only && $r->last_at > $idle ) {
				continue; // Still chatting.
			}
			$m = isset( $meta[ $r->session_id ] ) ? $meta[ $r->session_id ] : null;
			if ( $m && (int) $m->summarized_msgs >= (int) $r->n ) {
				continue; // Nothing new since the last summary.
			}
			$out[] = (string) $r->session_id;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Hourly job. */
	public static function run() {
		if ( ! self::enabled() ) {
			return 0;
		}
		$done = 0;
		foreach ( self::due() as $sid ) {
			if ( self::summarize( $sid ) ) {
				$done++;
			}
		}
		return $done;
	}

	/** Summary and score of one chat. @return bool */
	public static function summarize( $sid ) {
		$rows  = WP_AI_Chatbot_Sessions::messages( $sid, 60 );
		$lines = array();
		$forms = 0;
		$count = 0;
		foreach ( $rows as $row ) {
			$count++;
			$u = (string) $row->user_message;
			if ( 0 === strpos( $u, '[FORM_SHOWN]:' ) ) {
				continue;
			}
			if ( 0 === strpos( $u, '[SYSTEM_ACTION]:' ) ) {
				if ( false !== stripos( $u, 'submit' ) ) {
					$forms++;
					$lines[] = 'EVENT: the visitor submitted a form (' . wp_trim_words( $row->bot_reply, 8 ) . ')';
				}
				continue;
			}
			if ( 0 === strpos( $u, '[AGENT]:' ) ) {
				$lines[] = 'TEAM: ' . wp_trim_words( $row->bot_reply, 60 );
				continue;
			}
			if ( 0 === strpos( $u, '[LIVE]:' ) ) {
				$lines[] = 'EVENT: ' . trim( substr( $u, 7 ) );
				continue;
			}
			if ( '' !== trim( $u ) && '[' !== substr( $u, 0, 1 ) ) {
				$lines[] = 'VISITOR: ' . wp_trim_words( $u, 80 );
			}
			if ( '' !== trim( (string) $row->bot_reply ) ) {
				$lines[] = 'BOT: ' . wp_trim_words( preg_replace( '/\(https?:[^)]*\)/', '', (string) $row->bot_reply ), 40 );
			}
		}
		$contacts = WP_AI_Chatbot_Chat_Contacts::for_sessions( array( $sid ) );
		// A form filled in the chat (not just the WhatsApp number we always have).
		$has_contact = isset( $contacts[ $sid ] ) && 'whatsapp' !== $contacts[ $sid ]->form_id && ( (int) $contacts[ $sid ]->lead_id > 0 || '' !== $contacts[ $sid ]->email || '' !== $contacts[ $sid ]->phone );
		if ( $has_contact ) {
			$lines[] = 'EVENT: the visitor submitted a form with their contact details (' . $contacts[ $sid ]->form_title . ')';
		}
		if ( ! $lines ) {
			return false;
		}
		$transcript = implode( "\n", $lines );
		if ( strlen( $transcript ) > 6000 ) {
			$transcript = '...' . substr( $transcript, -6000 );
		}
		$system = "You review website chats for a business and judge how likely the visitor is to buy or become a customer.\n"
			. "Reply with JSON only, no other text: {\"summary\": \"...\", \"intent\": \"...\", \"score\": 0}\n"
			. "- summary: at most 2 short sentences in English: who the visitor is (if known), what they wanted, and what happened or what to do next.\n"
			. "- intent: 1 to 3 words, one of: buying, pricing, booking, order status, support, complaint, information, job, spam, other.\n"
			. "- score 0-100: 80+ = ready to buy or asked for a quote/call/booking and left contact details; 60-79 = clear interest in a product or service, asked about price or availability; 40-59 = researching; 10-39 = general questions or support; 0-9 = spam, testing or no interest.";
		$reply = WP_AI_Chatbot_AI::chat( $system, array( array( 'role' => 'user', 'content' => "CHAT:\n" . $transcript ) ), array( 'max_tokens' => 220, 'temperature' => 0.1, 'timeout' => 30 ) );
		if ( is_wp_error( $reply ) ) {
			WP_AI_Chatbot_AI::record_error( 'Chat summary', $reply );
			return false;
		}
		$data = self::parse( $reply );
		if ( ! $data ) {
			return false;
		}
		$score = max( 0, min( 100, (int) $data['score'] ) );
		if ( $forms > 0 || $has_contact ) {
			$score = min( 100, $score + 15 ); // Left their details: a real lead.
		}
		$label = self::label_for( $score );
		$old   = WP_AI_Chatbot_Sessions::get( $sid );
		WP_AI_Chatbot_Sessions::update(
			$sid,
			array(
				'summary'         => sanitize_textarea_field( wp_html_excerpt( (string) $data['summary'], 400, '…' ) ),
				'intent'          => sanitize_text_field( substr( strtolower( (string) $data['intent'] ), 0, 30 ) ),
				'score'           => $score,
				'label'           => $label,
				'summarized_msgs' => $count,
				'summarized_at'   => current_time( 'mysql' ),
			)
		);
		if ( 'hot' === $label && ( ! $old || 'hot' !== $old->label ) && '1' === (string) self::settings()['hot_email'] ) {
			self::email_hot( $sid, $data, $score );
		}
		return true;
	}

	/** JSON from the reply (models sometimes wrap it in ``` or text). */
	public static function parse( $reply ) {
		$reply = trim( (string) $reply );
		if ( preg_match( '/\{.*\}/s', $reply, $m ) ) {
			$reply = $m[0];
		}
		$data = json_decode( $reply, true );
		if ( ! is_array( $data ) || ! isset( $data['summary'] ) || ! isset( $data['score'] ) || ! is_numeric( $data['score'] ) ) {
			return null;
		}
		$data['intent'] = isset( $data['intent'] ) ? (string) $data['intent'] : '';
		return $data;
	}

	private static function email_hot( $sid, $data, $score ) {
		$o = WP_AI_Chatbot_Lead_Alerts::get();
		if ( '' === trim( (string) $o['alert_to'] ) ) {
			return;
		}
		$who  = WP_AI_Chatbot_Live_Chat::display_name( $sid );
		$url  = admin_url( 'admin.php?page=wp-ai-chatbot-logs&chat=' . rawurlencode( $sid ) );
		$html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111;max-width:600px;">'
			. '<h2 style="margin:0 0 8px;border-top:4px solid #dc2626;padding-top:14px;">Hot lead: ' . esc_html( $who ) . ' (' . (int) $score . '/100)</h2>'
			. '<p>' . esc_html( (string) $data['summary'] ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:10px 16px;background:#fd9400;color:#0a0a0a;border-radius:6px;text-decoration:none;font-weight:bold;">Open the chat</a></p></div>';
		WP_AI_Chatbot_Lead_Alerts::send( $o['alert_to'], 'Hot lead: ' . $who, $html );
	}

	/** "Summarize now" in Chat History: up to 8 chats (or the given one) right away. */
	public function ajax_now() {
		check_ajax_referer( 'wp_ai_insights', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		if ( '' === WP_AI_Chatbot_AI::api_key( WP_AI_Chatbot_AI::chat_provider() ) ) {
			wp_send_json_error( 'Add your API key under AI Settings first.' );
		}
		$sid  = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		$list = '' !== $sid ? array( $sid ) : array();
		$done = 0;
		$ids  = '' !== $sid ? $list : self::due( self::PER_RUN, array() );
		if ( '' === $sid && ! $ids ) {
			// Also chats still in progress when asked by hand.
			$ids = self::due( self::PER_RUN, self::recent_sessions() );
		}
		foreach ( $ids as $one ) {
			if ( self::summarize( $one ) ) {
				$done++;
			}
		}
		wp_send_json_success( array( 'done' => $done, 'message' => $done ? sprintf( '%d chat(s) summarized.', $done ) : 'Nothing new to summarize (chats need at least 2 visitor messages).' ) );
	}

	private static function recent_sessions() {
		global $wpdb;
		$logs = $wpdb->prefix . 'ai_chat_logs';
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT session_id FROM {$logs} GROUP BY session_id ORDER BY MAX(id) DESC LIMIT 40" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'enabled'   => empty( $in['enabled'] ) ? '0' : '1',
			'hot_email' => empty( $in['hot_email'] ) ? '0' : '1',
		);
	}

	public static function render_tab() {
		$s = self::settings();
		$n = self::OPTION;
		?>
		<p class="akdk-st-intro">After a chat ends, the AI writes a 2-line summary and gives the visitor a lead score from 0 to 100, so you can call the best leads first. Chat History shows <span class="akdk-tag hot">Hot</span> <span class="akdk-tag warm">Warm</span> <span class="akdk-tag cold">Cold</span> and can sort by score.</p>
		<form method="post" action="options.php" class="akdk-st-form">
			<?php settings_fields( 'wp_ai_chatbot_insights_group' ); ?>
			<div class="akdk-st-card">
				<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( '1', $s['enabled'] ); ?>> Summarize and score chats automatically (every hour, chats idle for 15 minutes)</label></p>
				<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[hot_email]" value="1" <?php checked( '1', $s['hot_email'] ); ?>> Email me when a chat becomes a hot lead</label></p>
				<p class="description">One short AI call per chat (a few hundred tokens), at most <?php echo (int) self::PER_RUN; ?> chats per hour, only chats with at least 2 visitor messages and only again when there are new messages. Hot = 70 or more, Warm = 40 to 69, Cold = below 40. A submitted form or a left email/phone adds 15 points.</p>
			</div>
			<?php submit_button( 'Save' ); ?>
		</form>
		<?php
	}
}
