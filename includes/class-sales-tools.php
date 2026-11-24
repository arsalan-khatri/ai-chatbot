<?php
/**
 * AI Chatbot > Sales Tools (2.0.0): one page for the selling features.
 *
 * Tabs: Store (order status, product cards), Proactive messages, Bots,
 * WhatsApp AI, Lead score. Live chat has its own page (Live Chat).
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Sales_Tools {

	const PAGE = 'wp-ai-chatbot-sales';

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_submenu' ), 26 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public static function tabs() {
		return array(
			'store'     => array( 'Store', 'WP_AI_Chatbot_Order_Status' ),
			'proactive' => array( 'Proactive messages', 'WP_AI_Chatbot_Proactive' ),
			'bots'      => array( 'Bots', 'WP_AI_Chatbot_Bots' ),
			'whatsapp'  => array( 'WhatsApp AI', 'WP_AI_Chatbot_WhatsApp_AI' ),
			'insights'  => array( 'Lead score', 'WP_AI_Chatbot_Insights' ),
		);
	}

	public function register_settings() {
		register_setting( 'wp_ai_chatbot_orders_group', WP_AI_Chatbot_Order_Status::OPTION, array( 'sanitize_callback' => array( 'WP_AI_Chatbot_Order_Status', 'sanitize' ) ) );
		register_setting( 'wp_ai_chatbot_proactive_group', WP_AI_Chatbot_Proactive::OPTION, array( 'sanitize_callback' => array( 'WP_AI_Chatbot_Proactive', 'sanitize' ) ) );
		register_setting( 'wp_ai_chatbot_bots_group', WP_AI_Chatbot_Bots::OPTION, array( 'sanitize_callback' => array( 'WP_AI_Chatbot_Bots', 'sanitize' ) ) );
		register_setting( 'wp_ai_chatbot_wa_ai_group', WP_AI_Chatbot_WhatsApp_AI::OPTION, array( 'sanitize_callback' => array( 'WP_AI_Chatbot_WhatsApp_AI', 'sanitize' ) ) );
		register_setting( 'wp_ai_chatbot_insights_group', WP_AI_Chatbot_Insights::OPTION, array( 'sanitize_callback' => array( 'WP_AI_Chatbot_Insights', 'sanitize' ) ) );
		// Multilingual sites: the chat's texts appear in WPML / Polylang string translation.
		WP_AI_Chatbot_I18n::register_all();
	}

	public function add_submenu() {
		add_submenu_page( 'wp-ai-chatbot', 'Sales Tools', 'Sales Tools', 'manage_options', self::PAGE, array( $this, 'display_page' ) );
	}

	public function display_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'store'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'store';
		?>
		<div class="wrap akdk-wrap akdk-st">
			<h1 class="akdk-page-title">Sales Tools</h1>
			<p class="akdk-page-intro">Turn chats into sales: order status, add-to-cart cards, messages that start the conversation, separate bots per page, WhatsApp and lead scores. To answer visitors yourself, open <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WP_AI_Chatbot_Live_Chat::PAGE ) ); ?>">Live Chat</a>.</p>
			<?php settings_errors(); ?>
			<nav class="nav-tab-wrapper akdk-st-tabs">
				<?php foreach ( $tabs as $k => $t ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $k ) ); ?>" class="nav-tab <?php echo $k === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $t[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="akdk-st-body">
				<?php call_user_func( array( $tabs[ $tab ][1], 'render_tab' ) ); ?>
			</div>
		</div>
		<style>
			.akdk-st-body { max-width: 980px; margin: 16px auto 0; text-align: left; }
			.akdk-st-body * { text-align: left; }
			.akdk-st-body .submit { text-align: left; }
			.akdk-st-intro { font-size: 14px; color: #3f3f46; max-width: 860px; }
			.akdk-st-card { background:#fff; border:1px solid #e7e7ea; border-radius:12px; padding:14px 18px; margin:0 0 14px; }
			.akdk-st-card-head { display:flex; align-items:center; gap:10px; margin-bottom:8px; font-size:14px; }
			.akdk-st-card-head code { font-size:12px; }
			.akdk-st-switch { margin-left:auto; font-weight:600; }
			.akdk-st-remove { color:#b32d2e !important; }
			.akdk-st-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px 14px; margin:8px 0; }
			.akdk-st-grid label, .akdk-st-full { display:flex; flex-direction:column; gap:4px; font-weight:600; font-size:13px; }
			.akdk-st-full { margin:8px 0; }
			.akdk-st-full small, .akdk-st-label small { font-weight:400; color:#71717a; }
			.akdk-st-grid input, .akdk-st-grid select, .akdk-st-full textarea { width:100%; max-width:100%; font-weight:400; }
			.akdk-st-inline { display:flex; flex-wrap:wrap; gap:6px 18px; margin:6px 0; }
			.akdk-st-checks { display:flex; flex-wrap:wrap; gap:6px 16px; font-weight:400; padding-top:2px; }
			.akdk-st-checks label { font-weight:400; }
			.akdk-st-ok { background:#dcfce7; color:#166534; border-radius:999px; padding:1px 9px; font-size:11.5px; font-weight:700; }
			.akdk-st-copy { cursor:pointer; user-select:all; display:inline-block; padding:4px 8px; margin-top:4px; word-break:break-all; }
			.akdk-tag { display:inline-block; padding:1px 7px; border-radius:999px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
			.akdk-tag.hot { background:#fee2e2; color:#b91c1c; } .akdk-tag.warm { background:#ffedd5; color:#c2410c; } .akdk-tag.cold { background:#e0f2fe; color:#0369a1; }
		</style>
		<script>
		(function () {
			document.querySelectorAll('.akdk-st-form').forEach(function (form) {
				var rows = form.querySelector('.akdk-st-rows');
				var tpl = form.querySelector('.akdk-st-template');
				var add = form.querySelector('.akdk-st-add');
				function sync(scope) {
					(scope || form).querySelectorAll('.akdk-pro-where').forEach(function (sel) {
						var v = sel.closest('.akdk-st-card').querySelector('.akdk-pro-value');
						if (v) { v.style.display = sel.value === 'url' ? '' : 'none'; }
					});
				}
				if (add && rows && tpl) {
					add.addEventListener('click', function () {
						var i = parseInt(rows.getAttribute('data-next'), 10) || 0;
						rows.setAttribute('data-next', i + 1);
						var box = document.createElement('div');
						box.innerHTML = tpl.innerHTML.replace(/__i__/g, 'n' + i);
						var card = box.firstElementChild;
						rows.appendChild(card);
						sync(card);
						var first = card.querySelector('input[type=text], textarea');
						if (first) { first.focus(); }
					});
				}
				form.addEventListener('click', function (e) {
					if (e.target.classList.contains('akdk-st-remove')) {
						e.preventDefault();
						e.target.closest('.akdk-st-card').remove();
					}
				});
				form.addEventListener('change', function (e) {
					if (e.target.classList.contains('akdk-pro-where')) { sync(); }
				});
				sync();
			});
			document.querySelectorAll('.akdk-st-copy').forEach(function (c) {
				c.title = 'Click to copy';
				c.addEventListener('click', function () {
					if (navigator.clipboard) { navigator.clipboard.writeText(c.textContent.trim()); c.style.background = '#dcfce7'; setTimeout(function () { c.style.background = ''; }, 800); }
				});
			});
		})();
		</script>
		<?php
	}
}
