<?php
/**
 * Visitor chat history: how long a chat stays in the visitor's browser, and
 * "Clear all visitors' chat history".
 *
 * Only the copy in the visitor's browser is affected. Chats saved for the
 * admin (Chat Logs, Dashboard, Leads) are never deleted here.
 *
 * Settings live on the Chat History page.
 * - wp_ai_chatbot_history['keep']: minutes of no activity after which the chat
 *   is cleared in the browser (presets or any custom time up to 365 days).
 *   0 = keep, -1 = clear when the browser tab is closed.
 * - wp_ai_chatbot_history_epoch: time (ms) of the last "Clear all". Every chat
 *   older than this is cleared in the browser. The widget asks for it when the
 *   page loads, when the chat is opened, and every 20 seconds while it is open,
 *   so open chats are cleared within seconds (also on cached pages).
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Chat_History {

	const EPOCH_OPTION = 'wp_ai_chatbot_history_epoch';

	/** Minutes => label. */
	const KEEP = array(
		0     => 'Keep it (until the visitor clears it)',
		-1    => 'When the visitor closes the browser tab',
		5     => '5 minutes',
		15    => '15 minutes',
		30    => '30 minutes',
		60    => '1 hour',
		360   => '6 hours',
		1440  => '1 day',
		10080 => '7 days',
		43200 => '30 days',
	);

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_clear_all_history', array( $this, 'ajax_clear_all' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_save_history_keep', array( $this, 'ajax_save_keep' ) );
	}

	const KEEP_OPTION = 'wp_ai_chatbot_history';
	const MAX_MINUTES = 525600; // 1 year.

	public static function keep() {
		$o = get_option( self::KEEP_OPTION, array() );
		if ( is_array( $o ) && isset( $o['keep'] ) ) {
			$k = (int) $o['keep'];
		} else {
			$s = WP_AI_Chatbot_AI::settings(); // Saved by 1.9.1 under AI Settings.
			$k = isset( $s['visitor_history_keep'] ) ? (int) $s['visitor_history_keep'] : 0;
		}
		return self::valid( $k ) ? $k : 0;
	}

	private static function valid( $k ) {
		return -1 === $k || ( $k >= 0 && $k <= self::MAX_MINUTES );
	}

	/** "90 days", "2 hours", "45 minutes". */
	public static function label( $min ) {
		if ( isset( self::KEEP[ $min ] ) ) {
			return self::KEEP[ $min ];
		}
		if ( 0 === $min % 1440 ) {
			$n = $min / 1440;
			return $n . ( 1 === $n ? ' day' : ' days' );
		}
		if ( 0 === $min % 60 ) {
			$n = $min / 60;
			return $n . ( 1 === $n ? ' hour' : ' hours' );
		}
		return $min . ( 1 === $min ? ' minute' : ' minutes' );
	}

	public static function epoch() {
		return (int) get_option( self::EPOCH_OPTION, 0 );
	}

	/** Added to the widget's data. */
	public static function widget_data() {
		return array(
			'history_keep'      => self::keep(),
			'history_epoch'     => self::epoch(),
			'history_state_url' => esc_url_raw( rest_url( 'wp-ai-chatbot/v1/history-state' ) ),
		);
	}

	public function register_route() {
		register_rest_route(
			'wp-ai-chatbot/v1',
			'/history-state',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$res = rest_ensure_response( array( 'keep' => self::keep(), 'epoch' => self::epoch() ) );
					$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
					return $res;
				},
			)
		);
	}

	/** "Clear all visitors' chat history": browsers only, admin chat logs are kept. */
	public function ajax_clear_all() {
		check_ajax_referer( 'wp_ai_chatbot_clear_all_history', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		$now = (int) round( microtime( true ) * 1000 );
		update_option( self::EPOCH_OPTION, $now, false );
		wp_send_json_success( array( 'epoch' => $now, 'when' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) );
	}

	/** Chat History page: save how long visitors keep their chat. */
	public function ajax_save_keep() {
		check_ajax_referer( 'wp_ai_chatbot_clear_all_history', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		$k = isset( $_POST['keep'] ) ? (int) $_POST['keep'] : 0;
		if ( ! self::valid( $k ) ) {
			wp_send_json_error( 'Please choose a time between 1 minute and 365 days.' );
		}
		update_option( self::KEEP_OPTION, array( 'keep' => $k ), false );
		wp_send_json_success( array( 'label' => self::label( $k ) ) );
	}

	/** Chat History page > "Visitor chat history settings". */
	public static function render_panel() {
		$keep   = self::keep();
		$epoch  = self::epoch();
		$custom = ! isset( self::KEEP[ $keep ] );
		$unit   = 'days';
		$num    = 60;
		if ( $custom ) {
			if ( 0 === $keep % 1440 ) {
				$num = $keep / 1440;
			} elseif ( 0 === $keep % 60 ) {
				$num  = $keep / 60;
				$unit = 'hours';
			} else {
				$num  = $keep;
				$unit = 'minutes';
			}
		}
		?>
		<details class="akdk-history-panel">
			<summary>
				<span class="akdk-hp-title"><span class="akdk-hp-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg></span>Visitor chat history settings</span>
				<span class="akdk-hp-now">Visitors' chats are cleared: <strong class="akdk-hp-current"><?php echo esc_html( 0 === $keep ? 'never (kept)' : ( -1 === $keep ? 'when the tab closes' : 'after ' . self::label( $keep ) . ' of no activity' ) ); ?></strong></span>
				<span class="akdk-hp-chev"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg></span>
			</summary>
			<div class="akdk-hp-body">
				<p class="akdk-hp-safe">These settings only change what visitors see in their own browser. <strong>The conversations on this page are never deleted by them.</strong></p>

				<div class="akdk-hp-row">
					<label for="akdk-hp-keep"><strong>Clear a visitor's chat after</strong></label>
					<div class="akdk-hp-controls">
						<select id="akdk-hp-keep">
							<?php foreach ( self::KEEP as $min => $label ) : ?>
								<option value="<?php echo esc_attr( $min ); ?>" <?php selected( ! $custom && $keep === $min ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<option value="custom" <?php selected( $custom ); ?>>Custom…</option>
						</select>
						<span class="akdk-hp-custom"<?php echo $custom ? '' : ' hidden'; ?>>
							<input type="number" id="akdk-hp-num" min="1" max="525600" value="<?php echo esc_attr( $num ); ?>" />
							<select id="akdk-hp-unit">
								<option value="1" <?php selected( 'minutes', $unit ); ?>>minutes</option>
								<option value="60" <?php selected( 'hours', $unit ); ?>>hours</option>
								<option value="1440" <?php selected( 'days', $unit ); ?>>days</option>
							</select>
						</span>
						<button type="button" class="button button-primary akdk-hp-save">Save</button>
						<span class="akdk-hp-msg"></span>
					</div>
					<p class="description">Counted from the visitor's last message. When the time is up, their chat starts fresh. Custom: any time up to 365 days.</p>
				</div>

				<div class="akdk-hp-row akdk-hp-clear">
					<button type="button" class="button akdk-clear-all-btn">Clear all visitors' chat history</button>
					<span class="akdk-clear-all-status description"><?php echo $epoch ? esc_html( 'Last cleared: ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) floor( $epoch / 1000 ) ) ) : ''; ?></span>
					<p class="description">Clears the chat on every visitor's screen within seconds (open chats too). The conversations on this page stay. (To delete these admin logs, use <em>Clear All Logs</em> above.)</p>
				</div>
			</div>
		</details>
		<style>
			.akdk-history-panel { background:#fff; border:1px solid #ccd0d4; border-radius:8px; margin:0 0 15px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
			.akdk-history-panel > summary { display:flex; flex-wrap:wrap; gap:6px 16px; align-items:center; justify-content:space-between; padding:14px 20px; cursor:pointer; list-style:none; }
			.akdk-history-panel > summary::-webkit-details-marker { display:none; }
			.akdk-hp-chev { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border:1px solid #dcdcde; border-radius:10px; color:#0a0a0a; transition:transform .15s, border-color .15s, color .15s; }
			.akdk-hp-chev svg { width:22px; height:22px; display:block; }
			.akdk-history-panel > summary:hover .akdk-hp-chev { border-color:#fd9400; color:#fd9400; }
			.akdk-history-panel[open] .akdk-hp-chev { transform:rotate(180deg); }
			.akdk-hp-title { display:inline-flex; align-items:center; gap:10px; font-weight:600; font-size:15px; color:#1d2327; }
			.akdk-hp-icon { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:10px; background:#fff4e5; color:#fd9400; }
			.akdk-hp-icon svg { width:22px; height:22px; display:block; }
			.akdk-hp-now { margin-left:auto; color:#50575e; }
			.akdk-hp-body { padding:4px 20px 18px; border-top:1px solid #f0f0f1; }
			.akdk-hp-safe { padding:8px 12px; border-left:4px solid #16a34a; background:#f0fdf4; }
			.akdk-hp-row { margin-top:14px; }
			.akdk-hp-controls { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:6px; }
			.akdk-hp-custom { display:inline-flex; gap:6px; align-items:center; }
			.akdk-hp-custom[hidden] { display:none; }
			.akdk-hp-custom input { width:90px; }
			.akdk-hp-clear { padding-top:14px; border-top:1px solid #f0f0f1; }
			.akdk-clear-all-status { display:block; margin-top:6px; }
		</style>
		<script>
		jQuery(function ($) {
			var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_ai_chatbot_clear_all_history' ) ); ?>';
			$('#akdk-hp-keep').on('change', function () { $('.akdk-hp-custom').prop('hidden', $(this).val() !== 'custom'); });
			$('.akdk-hp-save').on('click', function () {
				var v = $('#akdk-hp-keep').val(), keep;
				if (v === 'custom') {
					var n = parseInt($('#akdk-hp-num').val(), 10);
					if (!n || n < 1) { $('.akdk-hp-msg').text('Enter a number.').css('color', '#b91c1c'); return; }
					keep = n * parseInt($('#akdk-hp-unit').val(), 10);
				} else { keep = parseInt(v, 10); }
				var $b = $(this).prop('disabled', true);
				$.post(ajaxurl, { action: 'wp_ai_chatbot_save_history_keep', security: nonce, keep: keep })
					.done(function (res) {
						if (res && res.success) {
							$('.akdk-hp-msg').text('Saved.').css('color', '#15803d');
							$('.akdk-hp-current').text(keep === 0 ? 'never (kept)' : (keep === -1 ? 'when the tab closes' : 'after ' + res.data.label + ' of no activity'));
						} else { $('.akdk-hp-msg').text((res && res.data) ? res.data : 'Could not save.').css('color', '#b91c1c'); }
					})
					.fail(function () { $('.akdk-hp-msg').text('Could not save.').css('color', '#b91c1c'); })
					.always(function () { $b.prop('disabled', false); });
			});
			$('.akdk-clear-all-btn').on('click', function () {
				if (!window.confirm("Clear the chat history on every visitor's screen?\n\nThe conversations on this page are kept.")) { return; }
				var $b = $(this).prop('disabled', true).text('Clearing...');
				$.post(ajaxurl, { action: 'wp_ai_chatbot_clear_all_history', security: nonce })
					.done(function (res) {
						if (res && res.success) {
							$('.akdk-clear-all-status').text('Done. Visitors\' chats cleared (' + res.data.when + '). The conversations on this page are kept.').css('color', '#15803d');
						} else {
							$('.akdk-clear-all-status').text((res && res.data) ? res.data : 'Could not clear.').css('color', '#b91c1c');
						}
					})
					.fail(function () { $('.akdk-clear-all-status').text('Could not clear. Please try again.').css('color', '#b91c1c'); })
					.always(function () { $b.prop('disabled', false).text("Clear all visitors' chat history"); });
			});
		});
		</script>
		<?php
	}
}
