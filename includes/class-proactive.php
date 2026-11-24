<?php
/**
 * Proactive messages (2.0.0): the bot starts the conversation.
 *
 * Rules under AI Chatbot > Sales Tools > Proactive messages, for example:
 *   - 30 seconds on a page whose address contains "/pricing": "Need help choosing a plan?"
 *   - Leaving the page while the cart has products: "Any questions before you order?"
 *   - Half way down a product page: "Want to know if this fits you?"
 *
 * The message appears as a bubble next to the chat button (or opens the chat).
 * Each rule is shown once per visit (or once per visitor / once a day), never
 * while the chat is open, and at most one per page. No AI call, no tokens.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Proactive {

	const OPTION = 'wp_ai_chatbot_proactive';
	const MAX    = 20;

	public static function where_options() {
		return array(
			'all'        => 'Any page',
			'url'        => 'Address contains…',
			'home'       => 'Home page',
			'product'    => 'Product pages',
			'shop'       => 'Shop / category pages',
			'cart'       => 'Cart page',
			'checkout'   => 'Checkout page',
			'cart_items' => 'Any page while the cart has products',
		);
	}

	public static function trigger_options() {
		return array(
			'time'   => 'After some seconds on the page',
			'scroll' => 'After scrolling down the page',
			'exit'   => 'When the visitor is about to leave (mouse leaves the page; on phones: after the seconds below)',
		);
	}

	public static function defaults() {
		return array(
			'id'      => '',
			'enabled' => '1',
			'where'   => 'all',
			'value'   => '',
			'trigger' => 'time',
			'seconds' => 20,
			'scroll'  => 50,
			'message' => '',
			'open'    => '0', // 1 = open the chat with the message, 0 = bubble next to the button.
			'freq'    => 'session', // session | visitor | day.
		);
	}

	/** @return array[] */
	public static function rules() {
		$o = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $o ) ? $o : array() as $r ) {
			if ( is_array( $r ) && ! empty( $r['id'] ) && '' !== trim( (string) ( $r['message'] ?? '' ) ) ) {
				$out[] = array_merge( self::defaults(), $r );
			}
		}
		return $out;
	}

	/** Type of the page being shown, for the rules. */
	public static function page_type() {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			return 'shop';
		}
		if ( is_front_page() ) {
			return 'home';
		}
		return 'other';
	}

	/** Rules that can apply to this page (the browser checks the address, cart and timing). */
	public static function widget_data() {
		$type  = self::page_type();
		$rules = array();
		foreach ( self::rules() as $r ) {
			if ( '1' !== (string) $r['enabled'] ) {
				continue;
			}
			if ( in_array( $r['where'], array( 'home', 'product', 'shop', 'cart', 'checkout' ), true ) && $r['where'] !== $type ) {
				continue;
			}
			if ( 'checkout' === $type && 'checkout' !== $r['where'] ) {
				continue; // Never interrupt a checkout unless asked to.
			}
			$rules[] = array(
				'id'      => $r['id'],
				'where'   => $r['where'],
				'value'   => strtolower( (string) $r['value'] ),
				'trigger' => $r['trigger'],
				'seconds' => max( 0, min( 600, (int) $r['seconds'] ) ),
				'scroll'  => max( 5, min( 100, (int) $r['scroll'] ) ),
				'message' => WP_AI_Chatbot_I18n::t( 'Proactive message ' . $r['id'], $r['message'] ),
				'open'    => '1' === (string) $r['open'] ? 1 : 0,
				'freq'    => $r['freq'],
			);
		}
		return array( 'proactive' => $rules );
	}

	public static function sanitize( $in ) {
		$out   = array();
		$seen  = array();
		$where = array_keys( self::where_options() );
		$trig  = array_keys( self::trigger_options() );
		foreach ( is_array( $in ) ? $in : array() as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$msg = sanitize_textarea_field( (string) ( $r['message'] ?? '' ) );
			if ( '' === trim( $msg ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $r['id'] ?? '' ) );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = 'p' . substr( md5( $msg . wp_rand() ), 0, 8 );
			}
			$seen[ $id ] = true;
			$out[]       = array(
				'id'      => $id,
				'enabled' => empty( $r['enabled'] ) ? '0' : '1',
				'where'   => in_array( $r['where'] ?? '', $where, true ) ? $r['where'] : 'all',
				'value'   => sanitize_text_field( (string) ( $r['value'] ?? '' ) ),
				'trigger' => in_array( $r['trigger'] ?? '', $trig, true ) ? $r['trigger'] : 'time',
				'seconds' => max( 0, min( 600, (int) ( $r['seconds'] ?? 20 ) ) ),
				'scroll'  => max( 5, min( 100, (int) ( $r['scroll'] ?? 50 ) ) ),
				'message' => function_exists( 'mb_substr' ) ? mb_substr( $msg, 0, 300 ) : substr( $msg, 0, 300 ),
				'open'    => empty( $r['open'] ) ? '0' : '1',
				'freq'    => in_array( $r['freq'] ?? '', array( 'session', 'visitor', 'day' ), true ) ? $r['freq'] : 'session',
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	public static function render_tab() {
		$rules = self::rules();
		$name  = self::OPTION;
		$wc    = function_exists( 'WC' );
		if ( ! $rules && ! get_option( self::OPTION ) ) {
			// Examples to start from (switched off until saved with "On").
			$rules = array(
				array_merge( self::defaults(), array( 'id' => 'pricing', 'enabled' => '0', 'where' => 'url', 'value' => '/pricing', 'seconds' => 30, 'message' => 'Need help choosing the right plan? Ask me anything.' ) ),
			);
			if ( $wc ) {
				$rules[] = array_merge( self::defaults(), array( 'id' => 'cart', 'enabled' => '0', 'where' => 'cart_items', 'trigger' => 'exit', 'seconds' => 40, 'message' => 'Any questions before you complete your order? I can help with delivery, payment or sizes.' ) );
			}
		}
		$row = function ( $i, $r ) use ( $name ) {
			$r = array_merge( self::defaults(), (array) $r );
			$f = function ( $k ) use ( $i, $name ) {
				return esc_attr( $name . '[' . $i . '][' . $k . ']' );
			};
			ob_start();
			?>
			<div class="akdk-st-card akdk-pro-row">
				<div class="akdk-st-card-head">
					<strong>Message</strong>
					<label class="akdk-st-switch"><input type="checkbox" name="<?php echo $f( 'enabled' ); // phpcs:ignore ?>" value="1" <?php checked( '1', $r['enabled'] ); ?>> On</label>
					<button type="button" class="button-link akdk-st-remove">Remove</button>
				</div>
				<input type="hidden" name="<?php echo $f( 'id' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $r['id'] ); ?>">
				<label class="akdk-st-full">What the bot says<textarea rows="2" name="<?php echo $f( 'message' ); // phpcs:ignore ?>" placeholder="Need help choosing a plan?"><?php echo esc_textarea( $r['message'] ); ?></textarea></label>
				<div class="akdk-st-grid">
					<label>Where
						<select name="<?php echo $f( 'where' ); // phpcs:ignore ?>" class="akdk-pro-where">
							<?php foreach ( self::where_options() as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $r['where'] ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="akdk-pro-value">Address contains<input type="text" name="<?php echo $f( 'value' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $r['value'] ); ?>" placeholder="/pricing"></label>
					<label>When
						<select name="<?php echo $f( 'trigger' ); // phpcs:ignore ?>">
							<?php foreach ( self::trigger_options() as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $r['trigger'] ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>Seconds<input type="number" min="0" max="600" name="<?php echo $f( 'seconds' ); // phpcs:ignore ?>" value="<?php echo (int) $r['seconds']; ?>"></label>
					<label>Scrolled (% of the page)<input type="number" min="5" max="100" name="<?php echo $f( 'scroll' ); // phpcs:ignore ?>" value="<?php echo (int) $r['scroll']; ?>"></label>
					<label>How often
						<select name="<?php echo $f( 'freq' ); // phpcs:ignore ?>">
							<option value="session" <?php selected( 'session', $r['freq'] ); ?>>Once per visit</option>
							<option value="day" <?php selected( 'day', $r['freq'] ); ?>>Once a day</option>
							<option value="visitor" <?php selected( 'visitor', $r['freq'] ); ?>>Only once per visitor</option>
						</select>
					</label>
				</div>
				<p class="akdk-st-inline"><label><input type="checkbox" name="<?php echo $f( 'open' ); // phpcs:ignore ?>" value="1" <?php checked( '1', $r['open'] ); ?>> Open the chat with this message (otherwise a small bubble appears next to the chat button)</label></p>
			</div>
			<?php
			return ob_get_clean();
		};
		?>
		<p class="akdk-st-intro">Let the bot start the conversation at the right moment. A message is shown at most once per page, never while the chat is open and never on the checkout page unless you choose "Checkout page". It costs no tokens.</p>
		<form method="post" action="options.php" class="akdk-st-form">
			<?php settings_fields( 'wp_ai_chatbot_proactive_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="">
			<div class="akdk-st-rows" data-next="<?php echo (int) count( $rules ); ?>">
				<?php
				foreach ( $rules as $i => $r ) {
					echo $row( $i, $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
			<script type="text/template" class="akdk-st-template"><?php echo $row( '__i__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
			<p><button type="button" class="button akdk-st-add">+ Add a message</button></p>
			<?php submit_button( 'Save proactive messages' ); ?>
		</form>
		<?php
	}
}
