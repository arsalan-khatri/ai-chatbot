<?php
/**
 * Unanswered questions.
 *
 * A visitor question is saved here when the bot could not answer it: the
 * reply says it is not sure / has no information, or the fact check removed
 * a price, phone number or link the bot could not back up. Off-topic
 * questions, greetings and AI errors are not saved. The same question (same
 * words) is counted once with a "times asked" number.
 *
 * Chat History > Unanswered questions lists them. The owner can:
 * - Add answer: saved, and from then on the bot uses it. In Smart mode the
 *   answer is indexed as knowledge (source "owner_answer"); in Simple mode it
 *   is sent with the business details.
 * - Mark done (handled, no answer needed) or Ignore.
 *
 * Tables: wp_ai_unanswered (one row per question) and wp_ai_unanswered_hits
 * (which chat asked it, so the chat can be opened and tagged).
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Unanswered {

	const SCHEMA = '1';
	const SOURCE = 'owner_answer';

	const REASONS = array(
		'not_sure' => 'Bot was not sure',
		'removed'  => 'Unverified details removed',
	);

	public function register_hooks() {
		add_action( 'wp_ajax_wp_ai_chatbot_uq_action', array( $this, 'ajax_action' ) );
		// Answers not in the knowledge index yet (new answer, or the search model changed) are added hourly.
		add_action( WP_AI_Chatbot_Knowledge_Index::MAINTENANCE_HOOK, array( __CLASS__, 'maintenance' ), 20 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_unanswered';
	}

	public static function hits_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_unanswered_hits';
	}

	public static function ensure_tables() {
		if ( get_option( 'wp_ai_unanswered_schema' ) === self::SCHEMA ) {
			return;
		}
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				qhash char(32) NOT NULL,
				question text NOT NULL,
				reason varchar(20) NOT NULL DEFAULT '',
				times int(11) NOT NULL DEFAULT 1,
				status varchar(12) NOT NULL DEFAULT 'new',
				answer text,
				last_session varchar(100) NOT NULL DEFAULT '',
				first_at datetime DEFAULT NULL,
				last_at datetime DEFAULT NULL,
				answered_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY qhash (qhash),
				KEY status (status)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::hits_table() . " (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				uq_id bigint(20) NOT NULL,
				session_id varchar(100) NOT NULL,
				created_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY session_idx (session_id),
				KEY uq_idx (uq_id)
			) {$c};"
		);
		update_option( 'wp_ai_unanswered_schema', self::SCHEMA, false );
	}

	private static function ready() {
		global $wpdb;
		return get_option( 'wp_ai_unanswered_schema' ) === self::SCHEMA && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table();
	}

	/** Lower case, punctuation removed, spaces collapsed: "Price kya hai??" = "price kya hai". */
	public static function normalize( $q ) {
		$q = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $q ) : strtolower( (string) $q );
		$q = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $q );
		return trim( preg_replace( '/\s+/u', ' ', $q ) );
	}

	public static function hash( $q ) {
		return md5( self::normalize( $q ) );
	}

	/** Phrases that mean the bot could not answer (English and Roman Urdu). */
	const NOT_SURE = '/\b(not sure|i\'?m not certain|don\'?t have (that|this|any|the|enough|specific)|do not have (that|this|any|the|enough|specific)|no information|not able to find|couldn\'?t find|could not find|can\'?t find|cannot find|unable to (find|confirm|provide)|not mentioned|isn\'?t (listed|mentioned|available in)|not (listed|available) in (my|our|the)|maloom nahi|maloom nahin|pata nahi|pata nahin|information nahi|maloomat nahi|maazrat|mazrat)\b/i';

	/** Why the reply did not answer the question, or '' when it did. */
	public static function reason( $message, $reply, $handler ) {
		if ( ! empty( $handler->last_off_topic ) || ! empty( $handler->last_request_failed ) ) {
			return '';
		}
		$norm = self::normalize( $message );
		if ( '' === $norm || preg_match( '/^(hi|hello|hey|salam|assalam|aoa|ok|okay|thanks|thank you|shukriya|jazakallah|bye|yes|no|acha|theek|good|great|nice)( [\p{L}]+)?$/u', $norm ) ) {
			return '';
		}
		if ( preg_match( self::NOT_SURE, (string) $reply ) ) {
			return 'not_sure';
		}
		if ( ! empty( $handler->last_flags ) ) {
			return 'removed';
		}
		return '';
	}

	/** Called after every visitor chat message. */
	public static function maybe_record( $session_id, $message, $reply, $handler ) {
		$why = self::reason( $message, $reply, $handler );
		if ( '' === $why ) {
			return '';
		}
		self::ensure_tables();
		global $wpdb;
		$table = self::table();
		$hash  = self::hash( $message );
		$now   = current_time( 'mysql' );
		$q     = function_exists( 'mb_substr' ) ? mb_substr( sanitize_textarea_field( $message ), 0, 1000 ) : substr( sanitize_textarea_field( $message ), 0, 1000 );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (qhash, question, reason, times, status, last_session, first_at, last_at) VALUES (%s, %s, %s, 1, 'new', %s, %s, %s)
				 ON DUPLICATE KEY UPDATE times = times + 1, last_at = VALUES(last_at), last_session = VALUES(last_session), reason = VALUES(reason)",
				$hash, $q, $why, (string) $session_id, $now, $now
			)
		);
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE qhash = %s", $hash ) );
		// phpcs:enable
		if ( $id ) {
			$wpdb->insert( self::hits_table(), array( 'uq_id' => $id, 'session_id' => (string) $session_id, 'created_at' => $now ) );
		}
		return $why; // Why it counts as unanswered (the chat then offers "Talk to a person").
	}

	/** Number of new (not yet handled) questions. */
	public static function new_count() {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** "session|hash" of every unanswered message in these chats (to tag them in Chat History). */
	public static function flagged( $session_ids ) {
		global $wpdb;
		if ( empty( $session_ids ) || ! self::ready() ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT h.session_id, u.qhash, u.status FROM ' . self::hits_table() . ' h JOIN ' . self::table() . " u ON u.id = h.uq_id WHERE h.session_id IN ({$ph})", $session_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->session_id . '|' . $r->qhash ] = $r->status;
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Answers the bot uses
	 * ------------------------------------------------------------------ */

	private static function answered_row( $id ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE id = %d AND status = 'answered' AND answer IS NOT NULL AND answer <> ''", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Knowledge document of one answer (Knowledge_Index source "owner_answer"). */
	public static function document( $id ) {
		$r = self::answered_row( $id );
		if ( ! $r ) {
			return null;
		}
		return array(
			'title'    => 'Question: ' . $r->question,
			'text'     => 'Question: ' . $r->question . "\nAnswer (from the business owner): " . $r->answer,
			'modified' => $r->answered_at ? $r->answered_at : current_time( 'mysql' ),
		);
	}

	/** An answer stays in the knowledge index as long as it exists. */
	public static function is_live( $id ) {
		return null !== self::answered_row( $id );
	}

	/** Simple mode: the owner's answers, sent with the business details. */
	public static function answers_text() {
		global $wpdb;
		if ( ! self::ready() ) {
			return '';
		}
		$rows = $wpdb->get_results( 'SELECT question, answer FROM ' . self::table() . " WHERE status = 'answered' AND answer IS NOT NULL AND answer <> '' ORDER BY answered_at DESC LIMIT 60" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = '';
		foreach ( (array) $rows as $r ) {
			$out .= 'Q: ' . $r->question . "\nA: " . $r->answer . "\n";
		}
		return $out;
	}

	/** Search can run (Smart mode with a search key). */
	private static function can_index() {
		if ( WP_AI_Chatbot_Bot_Mode::is_basic() || ! WP_AI_Chatbot_AI::search_voice_on() ) {
			return false;
		}
		return '' !== WP_AI_Chatbot_AI::api_key( WP_AI_Chatbot_AI::embedding_provider() );
	}

	/** Adds (or updates) one answer in the knowledge index. @return true|string error */
	public static function index_answer( $id ) {
		if ( ! self::can_index() ) {
			return true; // Simple mode, or no search: the answer is used without the index.
		}
		$res = WP_AI_Chatbot_Knowledge_Index::index_source( self::SOURCE, (string) (int) $id );
		return is_wp_error( $res ) ? $res->get_error_message() : true;
	}

	/** Hourly: index answers that are missing from the index or were made with another search model. */
	public static function maintenance() {
		global $wpdb;
		if ( ! self::ready() || ! self::can_index() || ! WP_AI_Chatbot_Knowledge_Index::table_exists() ) {
			return;
		}
		$vt  = WP_AI_Chatbot_Knowledge_Index::table();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT u.id FROM ' . self::table() . " u WHERE u.status = 'answered' AND u.answer <> '' AND NOT EXISTS (SELECT 1 FROM {$vt} v WHERE v.source_type = %s AND v.source_id = u.id AND v.embed_model = %s) LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::SOURCE,
				WP_AI_Chatbot_AI::embedding_signature()
			)
		);
		foreach ( (array) $ids as $id ) {
			if ( true !== self::index_answer( $id ) ) {
				break;
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	public function ajax_action() {
		check_ajax_referer( 'wp_ai_chatbot_uq', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		self::ensure_tables();
		global $wpdb;
		$table = self::table();
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( ! $id || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_send_json_error( 'This question no longer exists.' );
		}
		$now = current_time( 'mysql' );
		switch ( $do ) {
			case 'answer':
				$answer = isset( $_POST['answer'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['answer'] ) ) ) : '';
				if ( '' === $answer ) {
					wp_send_json_error( 'Please write the answer first.' );
				}
				$wpdb->update( $table, array( 'status' => 'answered', 'answer' => $answer, 'answered_at' => $now ), array( 'id' => $id ) );
				$indexed = self::index_answer( $id );
				$msg     = WP_AI_Chatbot_Bot_Mode::is_basic()
					? 'Saved. The bot uses this answer from now on (Simple mode).'
					: ( true === $indexed ? 'Saved. The bot uses this answer from now on.' : 'Saved, but it could not be added to the knowledge yet (' . $indexed . '). It is retried every hour.' );
				wp_send_json_success( array( 'message' => $msg, 'status' => 'answered' ) );
				break;
			case 'done':
				$wpdb->update( $table, array( 'status' => 'answered', 'answer' => '', 'answered_at' => $now ), array( 'id' => $id ) );
				WP_AI_Chatbot_Knowledge_Index::delete_source( self::SOURCE, (string) $id );
				wp_send_json_success( array( 'message' => 'Marked as done.', 'status' => 'answered' ) );
				break;
			case 'ignore':
				$wpdb->update( $table, array( 'status' => 'ignored' ), array( 'id' => $id ) );
				WP_AI_Chatbot_Knowledge_Index::delete_source( self::SOURCE, (string) $id );
				wp_send_json_success( array( 'message' => 'Ignored.', 'status' => 'ignored' ) );
				break;
			case 'reopen':
				$wpdb->update( $table, array( 'status' => 'new', 'answer' => '', 'answered_at' => null ), array( 'id' => $id ) );
				WP_AI_Chatbot_Knowledge_Index::delete_source( self::SOURCE, (string) $id );
				wp_send_json_success( array( 'message' => 'Moved back to New. The bot no longer uses the old answer.', 'status' => 'new' ) );
				break;
			case 'delete':
				$wpdb->delete( $table, array( 'id' => $id ) );
				$wpdb->delete( self::hits_table(), array( 'uq_id' => $id ) );
				WP_AI_Chatbot_Knowledge_Index::delete_source( self::SOURCE, (string) $id );
				wp_send_json_success( array( 'message' => 'Deleted.', 'status' => 'deleted' ) );
				break;
		}
		wp_send_json_error( 'Unknown action.' );
	}

	/** Dashboard: "12 questions the bot could not answer". */
	public static function dashboard_card() {
		$n = self::new_count();
		if ( ! $n ) {
			return;
		}
		$url = admin_url( 'admin.php?page=wp-ai-chatbot-logs&tab=unanswered' );
		?>
		<div class="akdk-uq-card">
			<span class="akdk-uq-card-icon"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></span>
			<div class="akdk-uq-card-text">
				<strong><?php echo esc_html( $n . ( 1 === $n ? ' question' : ' questions' ) ); ?> the bot could not answer</strong>
				<span>Add the answers once and the bot uses them from then on.</span>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( $url ); ?>">Review</a>
		</div>
		<style>
			.akdk-uq-card { display:flex; align-items:center; gap:14px; background:#fff; border:1px solid #ffd59e; border-left:4px solid #fd9400; border-radius:12px; padding:14px 18px; margin:0 0 16px; }
			.akdk-uq-card-icon { display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; border-radius:12px; background:#fff4e5; color:#fd9400; flex-shrink:0; }
			.akdk-uq-card-text { flex:1; min-width:0; }
			.akdk-uq-card-text strong { display:block; font-size:15px; color:#0a0a0a; }
			.akdk-uq-card-text span { color:#50575e; }
		</style>
		<?php
	}

	/** Chat History > Unanswered questions. */
	public static function render_tab() {
		global $wpdb;
		self::ensure_tables();
		$table  = self::table();
		$status = isset( $_GET['uq'] ) ? sanitize_key( wp_unslash( $_GET['uq'] ) ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = in_array( $status, array( 'new', 'answered', 'ignored' ), true ) ? $status : 'new';
		$counts = array( 'new' => 0, 'answered' => 0, 'ignored' => 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status" ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ $c->status ] = (int) $c->n;
		}
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY " . ( 'new' === $status ? 'times DESC, last_at DESC' : 'last_at DESC' ) . ' LIMIT 300', $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sessions = array_values( array_unique( array_filter( wp_list_pluck( (array) $rows, 'last_session' ) ) ) );
		$contacts = WP_AI_Chatbot_Chat_Contacts::for_sessions( $sessions );
		$base     = admin_url( 'admin.php?page=wp-ai-chatbot-logs&tab=unanswered' );
		$labels   = array( 'new' => 'New', 'answered' => 'Answered', 'ignored' => 'Ignored' );
		$basic    = WP_AI_Chatbot_Bot_Mode::is_basic();
		?>
		<div class="akdk-uq">
			<div class="akdk-uq-head">
				<div class="akdk-uq-filters">
					<?php foreach ( $labels as $k => $label ) : ?>
						<a class="akdk-chip<?php echo $k === $status ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'uq', $k, $base ) ); ?>"><?php echo esc_html( $label ); ?> <span><?php echo (int) $counts[ $k ]; ?></span></a>
					<?php endforeach; ?>
				</div>
				<input type="search" class="akdk-uq-search" placeholder="Search questions" />
			</div>
			<p class="akdk-uq-help">Questions your bot could not answer (it was not sure, or it had to remove a price, number or link it could not confirm). <strong>Add answer</strong> once and the bot uses it from then on<?php echo $basic ? ' (Simple mode: sent with your business details)' : ' (added to its knowledge)'; ?>. The same question asked again is counted, not listed twice.</p>

			<?php if ( empty( $rows ) ) : ?>
				<div class="akdk-uq-empty">
					<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
					<p><?php echo 'new' === $status ? 'Nothing to answer. Your bot answered every question.' : 'Nothing here yet.'; ?></p>
				</div>
			<?php else : ?>
				<div class="akdk-uq-list">
				<?php foreach ( $rows as $r ) :
					$c    = isset( $contacts[ $r->last_session ] ) ? $contacts[ $r->last_session ] : null;
					$who  = $c ? ( '' !== $c->name ? $c->name : ( '' !== $c->email ? $c->email : $c->phone ) ) : 'User-' . strtoupper( substr( $r->last_session, 0, 5 ) );
					$chat = admin_url( 'admin.php?page=wp-ai-chatbot-logs&chat=' . rawurlencode( $r->last_session ) );
				?>
					<div class="akdk-uq-item" data-id="<?php echo (int) $r->id; ?>" data-search="<?php echo esc_attr( strtolower( $r->question . ' ' . $r->answer ) ); ?>">
						<div class="akdk-uq-q">
							<span class="akdk-uq-times" title="Times asked"><?php echo (int) $r->times; ?>&times;</span>
							<div class="akdk-uq-qtext">
								<strong><?php echo esc_html( $r->question ); ?></strong>
								<span class="akdk-uq-meta">
									<span class="akdk-uq-reason"><?php echo esc_html( isset( self::REASONS[ $r->reason ] ) ? self::REASONS[ $r->reason ] : 'Not answered' ); ?></span>
									<span>Last asked <?php echo esc_html( human_time_diff( strtotime( $r->last_at ), current_time( 'timestamp' ) ) ); ?> ago by <?php echo esc_html( $who ); ?></span>
									<?php if ( '' !== $r->last_session ) : ?><a href="<?php echo esc_url( $chat ); ?>">View chat &rarr;</a><?php endif; ?>
								</span>
							</div>
						</div>
						<?php if ( 'answered' === $r->status && '' !== (string) $r->answer ) : ?>
							<div class="akdk-uq-answer"><span>Your answer</span><?php echo nl2br( esc_html( $r->answer ) ); ?></div>
						<?php elseif ( 'answered' === $r->status ) : ?>
							<div class="akdk-uq-answer is-done"><span>Marked as done (no answer added)</span></div>
						<?php endif; ?>
						<div class="akdk-uq-form" hidden>
							<textarea rows="3" placeholder="Write the answer the bot should give, e.g. prices, timings, a link or the next step."><?php echo esc_textarea( (string) $r->answer ); ?></textarea>
							<div class="akdk-uq-form-actions">
								<button type="button" class="button button-primary akdk-uq-do" data-do="answer">Save answer</button>
								<button type="button" class="button akdk-uq-cancel">Cancel</button>
							</div>
						</div>
						<div class="akdk-uq-actions">
							<?php if ( 'new' === $r->status ) : ?>
								<button type="button" class="button button-primary akdk-uq-open">Add answer</button>
								<button type="button" class="button akdk-uq-do" data-do="done">Mark done</button>
								<button type="button" class="button akdk-uq-do" data-do="ignore">Ignore</button>
							<?php elseif ( 'answered' === $r->status ) : ?>
								<button type="button" class="button akdk-uq-open"><?php echo '' !== (string) $r->answer ? 'Edit answer' : 'Add answer'; ?></button>
								<button type="button" class="button akdk-uq-do" data-do="reopen">Move back to New</button>
							<?php else : ?>
								<button type="button" class="button akdk-uq-do" data-do="reopen">Move back to New</button>
							<?php endif; ?>
							<button type="button" class="button-link akdk-uq-do akdk-uq-del" data-do="delete">Delete</button>
							<span class="akdk-uq-msg"></span>
						</div>
					</div>
				<?php endforeach; ?>
				<div class="akdk-uq-empty akdk-uq-nomatch" hidden><p>No question matches your search.</p></div>
				</div>
			<?php endif; ?>
		</div>
		<style>
			.akdk-uq { background:#fff; border:1px solid #e7e7ea; border-radius:12px; padding:16px 20px; }
			.akdk-uq-head { display:flex; flex-wrap:wrap; gap:10px; justify-content:space-between; align-items:center; }
			.akdk-uq-filters { display:flex; gap:6px; flex-wrap:wrap; }
			.akdk-uq .akdk-chip { display:inline-flex; align-items:center; gap:4px; text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border:1px solid #e4e4e7; background:#fff; border-radius:999px; color:#3f3f46 !important; box-shadow:none !important; }
			.akdk-uq .akdk-chip span { color:#a1a1aa; }
			.akdk-uq .akdk-chip:hover { border-color:#fd9400; color:#0a0a0a; }
			.akdk-uq .akdk-chip.is-active { background:#0a0a0a; border-color:#0a0a0a; color:#fff !important; }
			.akdk-uq .akdk-chip.is-active span { color:#fd9400; }
			.akdk-uq-search { width:280px; max-width:100%; height:36px; border-radius:8px; border:1px solid #d4d4d8; padding:0 12px; }
			.akdk-uq-help { color:#50575e; margin:12px 0 14px; }
			.akdk-uq-empty { text-align:center; color:#71717a; padding:40px 10px; }
			.akdk-uq-empty svg { color:#16a34a; }
			.akdk-uq-item { border:1px solid #ececef; border-radius:12px; padding:14px 16px; margin-bottom:10px; background:#fff; transition:border-color .15s; }
			.akdk-uq-item:hover { border-color:#ffd59e; }
			.akdk-uq-q { display:flex; gap:12px; align-items:flex-start; }
			.akdk-uq-times { flex-shrink:0; min-width:44px; height:44px; padding:0 6px; border-radius:12px; background:#fff4e5; color:#a35a00; font-weight:800; font-size:16px; display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box; }
			.akdk-uq-qtext { min-width:0; flex:1; }
			.akdk-uq-qtext strong { display:block; font-size:15px; color:#0a0a0a; line-height:1.4; word-wrap:break-word; }
			.akdk-uq-meta { display:flex; flex-wrap:wrap; gap:4px 14px; margin-top:4px; font-size:12.5px; color:#71717a; }
			.akdk-uq-meta a { color:#a35a00; font-weight:600; text-decoration:none; }
			.akdk-uq-reason { color:#b45309; font-weight:600; }
			.akdk-uq-answer { margin:12px 0 0 56px; padding:10px 12px; border-radius:10px; background:#f0fdf4; border:1px solid #bbf7d0; color:#14532d; }
			.akdk-uq-answer span { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#15803d; margin-bottom:3px; }
			.akdk-uq-answer.is-done { background:#f6f7f7; border-color:#e4e4e7; }
			.akdk-uq-answer.is-done span { color:#71717a; margin:0; }
			.akdk-uq-form { margin:12px 0 0 56px; }
			.akdk-uq-form textarea { width:100%; border-radius:8px; }
			.akdk-uq-form-actions { display:flex; gap:8px; margin-top:8px; }
			.akdk-uq-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:12px 0 0 56px; }
			.akdk-uq-del { color:#b91c1c !important; margin-left:auto !important; }
			.akdk-uq-msg { font-weight:600; }
			@media (max-width:600px) { .akdk-uq-answer, .akdk-uq-form, .akdk-uq-actions { margin-left:0; } }
		</style>
		<script>
		jQuery(function ($) {
			var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_ai_chatbot_uq' ) ); ?>';
			$('.akdk-uq-search').on('input', function () {
				var q = $.trim($(this).val()).toLowerCase(), shown = 0;
				$('.akdk-uq-item').each(function () { var ok = !q || String($(this).data('search')).indexOf(q) !== -1; $(this).toggle(ok); if (ok) shown++; });
				$('.akdk-uq-nomatch').prop('hidden', shown > 0);
			});
			$(document).on('click', '.akdk-uq-open', function () {
				var $i = $(this).closest('.akdk-uq-item');
				$i.find('.akdk-uq-form').prop('hidden', false).find('textarea').trigger('focus');
				$i.find('.akdk-uq-actions').prop('hidden', true);
			});
			$(document).on('click', '.akdk-uq-cancel', function () {
				var $i = $(this).closest('.akdk-uq-item');
				$i.find('.akdk-uq-form').prop('hidden', true); $i.find('.akdk-uq-actions').prop('hidden', false);
			});
			$(document).on('click', '.akdk-uq-do', function () {
				var $b = $(this), $i = $b.closest('.akdk-uq-item'), what = $b.data('do');
				if (what === 'delete' && !window.confirm('Delete this question? If it has an answer, the bot stops using it.')) { return; }
				var data = { action: 'wp_ai_chatbot_uq_action', security: nonce, id: $i.data('id'), do: what };
				if (what === 'answer') { data.answer = $i.find('textarea').val(); }
				$i.find('button').prop('disabled', true);
				$.post(ajaxurl, data).done(function (res) {
					if (res && res.success) {
						$i.css('opacity', .55).find('.akdk-uq-msg').text(res.data.message).css('color', '#15803d');
						$i.find('.akdk-uq-form').prop('hidden', true); $i.find('.akdk-uq-actions').prop('hidden', false);
						if (what === 'answer') { $i.find('.akdk-uq-form-actions').after('<p class="akdk-uq-saved" style="color:#15803d;font-weight:600;">' + $('<span>').text(res.data.message).html() + '</p>'); $i.find('.akdk-uq-form').prop('hidden', false).find('textarea, .akdk-uq-form-actions').hide(); }
						setTimeout(function () { $i.slideUp(200); }, 1600);
					} else {
						$i.find('button').prop('disabled', false);
						$i.find('.akdk-uq-msg').text((res && res.data) ? res.data : 'Could not save.').css('color', '#b91c1c');
						$i.find('.akdk-uq-form').prop('hidden', true); $i.find('.akdk-uq-actions').prop('hidden', false);
					}
				}).fail(function () { $i.find('button').prop('disabled', false); $i.find('.akdk-uq-msg').text('Could not save. Please try again.').css('color', '#b91c1c'); });
			});
		});
		</script>
		<?php
	}
}
