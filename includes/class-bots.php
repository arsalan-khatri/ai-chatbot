<?php
/**
 * Multiple bots (2.0.0).
 *
 * Besides the main bot (AI Settings, Appearance, Train Chatbot), extra bots can
 * be made under AI Chatbot > Sales Tools > Bots. Each has its own name, line
 * under the name, header colour, welcome message, instructions and the
 * knowledge it may use (for example only products, or only one PDF / one
 * knowledge base), and is shown on the pages chosen for it:
 *
 *   - Pages: one rule per line, "/shop", "/services/seo" (address contains it), or a page ID.
 *   - Shortcode: [akdk_chatbot bot="sales"] puts that bot in a page.
 *
 * Everything else (API key, forms, quick actions, appearance) is shared.
 * Every bot keeps its own conversation in the visitor's browser, and Chat
 * History shows which bot answered.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Bots {

	const OPTION = 'wp_ai_chatbot_bots';
	const MAX    = 20;

	/** Bot of the current request ('' = main bot). */
	private static $active = '';

	/** Knowledge a bot can be limited to: key => label. */
	public static function knowledge_types() {
		$types = array(
			'wp_page'      => 'Pages',
			'wp_post'      => 'Blog posts',
			'wc_product'   => 'Products',
			'tutor_course' => 'Courses',
			'pdf_doc'      => 'PDF documents',
			'custom_kb'    => 'Knowledge bases',
			'owner_answer' => 'Answers you added in Chat History',
		);
		return $types;
	}

	public static function defaults() {
		return array(
			'id'           => '',
			'enabled'      => '1',
			'name'         => '',
			'subtitle'     => '',
			'welcome'      => '',
			'color'        => '',
			'instructions' => '',
			'prompt_mode'  => 'add', // add = after the main instructions, replace = instead of them.
			'knowledge'    => array(), // Empty = everything the main bot knows.
			'pages'        => '',
		);
	}

	/** @return array id => bot */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $b ) {
			if ( is_array( $b ) && ! empty( $b['id'] ) ) {
				$out[ (string) $b['id'] ] = array_merge( self::defaults(), $b );
			}
		}
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		$id  = sanitize_key( (string) $id );
		return ( '' !== $id && isset( $all[ $id ] ) && '1' === (string) $all[ $id ]['enabled'] ) ? $all[ $id ] : null;
	}

	/** Uses this bot for the rest of the request (unknown or switched-off bots: the main bot). */
	public static function activate( $id ) {
		self::$active = self::get( $id ) ? sanitize_key( (string) $id ) : '';
		return self::$active;
	}

	public static function active() {
		return self::$active;
	}

	/** Bot whose page rules match the page being shown ('' = main bot). */
	public static function for_current_page() {
		$all = self::all();
		if ( ! $all ) {
			return '';
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$pid = (int) get_queried_object_id();
		foreach ( $all as $id => $b ) {
			if ( '1' !== (string) $b['enabled'] ) {
				continue;
			}
			foreach ( preg_split( '/[\r\n,]+/', (string) $b['pages'] ) as $rule ) {
				$rule = strtolower( trim( $rule ) );
				if ( '' === $rule ) {
					continue;
				}
				if ( ctype_digit( $rule ) ) {
					if ( $pid && (int) $rule === $pid ) {
						return $id;
					}
					continue;
				}
				// A full address: only the part after the domain counts.
				if ( preg_match( '#^https?://#', $rule ) ) {
					$rule = preg_replace( '#^https?://[^/?\#]+#', '', $rule );
					$rule = '' === $rule ? '/' : $rule;
				}
				if ( '/' === $rule ) {
					if ( is_front_page() ) {
						return $id;
					}
					continue;
				}
				if ( '' !== $uri && false !== strpos( $uri, $rule ) ) {
					return $id;
				}
			}
		}
		return '';
	}

	/** Appearance values of the active bot over the main ones. */
	public static function style( $o ) {
		$b = self::get( self::$active );
		if ( ! $b ) {
			return $o;
		}
		if ( '' !== trim( $b['name'] ) ) {
			$o['bot_name'] = WP_AI_Chatbot_I18n::t( 'Bot ' . $b['id'] . ': name', $b['name'] );
		}
		if ( '' !== trim( $b['subtitle'] ) ) {
			$o['header_subtitle'] = WP_AI_Chatbot_I18n::t( 'Bot ' . $b['id'] . ': subtitle', $b['subtitle'] );
		}
		$color = sanitize_hex_color( (string) $b['color'] );
		if ( $color ) {
			$o['header_bg']                = $color;
			$o['send_btn_bg']              = $color;
			$o['input_border_focus_color'] = $color;
		}
		return $o;
	}

	public static function welcome() {
		$b = self::get( self::$active );
		return $b && '' !== trim( $b['welcome'] ) ? WP_AI_Chatbot_I18n::t( 'Bot ' . $b['id'] . ': welcome', $b['welcome'] ) : '';
	}

	/** AI settings for the active bot: its instructions added to (or instead of) the main ones. */
	public static function apply_settings( $settings ) {
		$b = self::get( self::$active );
		if ( ! $b || '' === trim( (string) $b['instructions'] ) ) {
			return $settings;
		}
		$main = isset( $settings['system_prompt'] ) ? (string) $settings['system_prompt'] : '';
		$own  = trim( (string) $b['instructions'] );
		$settings['system_prompt'] = 'replace' === $b['prompt_mode'] || '' === trim( $main ) ? $own : trim( $main ) . "\n\n" . $own;
		return $settings;
	}

	/** True when the active bot may use knowledge of this kind. */
	public static function allows( $source_type ) {
		$b = self::get( self::$active );
		if ( ! $b || empty( $b['knowledge'] ) || ! is_array( $b['knowledge'] ) ) {
			return true;
		}
		return in_array( (string) $source_type, $b['knowledge'], true );
	}

	/** Limits the knowledge has? (Then the off-topic check and caches must not be shared with the main bot.) */
	public static function limited() {
		$b = self::get( self::$active );
		return $b && ! empty( $b['knowledge'] );
	}

	/** Data for the widget. */
	public static function widget_data() {
		$id = self::$active;
		return array(
			'bot'          => $id,
			'bot_welcome'  => self::welcome(),
		);
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function sanitize( $in ) {
		$out   = array();
		$types = array_keys( self::knowledge_types() );
		$seen  = array();
		foreach ( is_array( $in ) ? $in : array() as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) ( $b['name'] ?? '' ) );
			$id   = sanitize_key( (string) ( $b['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_key( sanitize_title( $name ) );
			}
			if ( '' === $id && '' === $name && '' === trim( (string) ( $b['instructions'] ?? '' ) ) ) {
				continue; // Empty row.
			}
			if ( '' === $id ) {
				$id = 'bot' . ( count( $out ) + 1 );
			}
			$id = substr( $id, 0, 30 );
			$base = $id;
			$n    = 2;
			while ( isset( $seen[ $id ] ) ) {
				$id = substr( $base, 0, 27 ) . '-' . $n++;
			}
			$seen[ $id ] = true;
			$out[]       = array(
				'id'           => $id,
				'enabled'      => empty( $b['enabled'] ) ? '0' : '1',
				'name'         => $name,
				'subtitle'     => sanitize_text_field( (string) ( $b['subtitle'] ?? '' ) ),
				'welcome'      => sanitize_textarea_field( (string) ( $b['welcome'] ?? '' ) ),
				'color'        => (string) sanitize_hex_color( (string) ( $b['color'] ?? '' ) ),
				'instructions' => sanitize_textarea_field( (string) ( $b['instructions'] ?? '' ) ),
				'prompt_mode'  => 'replace' === ( $b['prompt_mode'] ?? '' ) ? 'replace' : 'add',
				'knowledge'    => array_values( array_intersect( array_map( 'strval', (array) ( $b['knowledge'] ?? array() ) ), $types ) ),
				'pages'        => implode( "\n", array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', sanitize_textarea_field( (string) ( $b['pages'] ?? '' ) ) ) ) ) ),
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	public static function render_tab() {
		$bots  = array_values( self::all() );
		$types = self::knowledge_types();
		$name  = self::OPTION;
		$row   = function ( $i, $b ) use ( $types, $name ) {
			$b = array_merge( self::defaults(), (array) $b );
			$f = function ( $k ) use ( $i, $name ) {
				return esc_attr( $name . '[' . $i . '][' . $k . ']' );
			};
			ob_start();
			?>
			<div class="akdk-st-card akdk-bot-row">
				<div class="akdk-st-card-head">
					<strong class="akdk-bot-title"><?php echo esc_html( '' !== $b['name'] ? $b['name'] : 'New bot' ); ?></strong>
					<?php if ( '' !== $b['id'] ) : ?><code>bot="<?php echo esc_html( $b['id'] ); ?>"</code><?php endif; ?>
					<label class="akdk-st-switch"><input type="checkbox" name="<?php echo $f( 'enabled' ); // phpcs:ignore ?>" value="1" <?php checked( '1', $b['enabled'] ); ?>> On</label>
					<button type="button" class="button-link akdk-st-remove">Remove</button>
				</div>
				<input type="hidden" name="<?php echo $f( 'id' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $b['id'] ); ?>">
				<div class="akdk-st-grid">
					<label>Name in the header<input type="text" name="<?php echo $f( 'name' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $b['name'] ); ?>" placeholder="Sales Assistant"></label>
					<label>Line under the name<input type="text" name="<?php echo $f( 'subtitle' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $b['subtitle'] ); ?>" placeholder="Ask me about our plans"></label>
					<label>Header colour<input type="text" class="akdk-st-color" name="<?php echo $f( 'color' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $b['color'] ); ?>" placeholder="#fd9400 (empty = Appearance colour)"></label>
					<label>Welcome message<input type="text" name="<?php echo $f( 'welcome' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $b['welcome'] ); ?>" placeholder="Hi! Looking for the right plan?"></label>
				</div>
				<label class="akdk-st-full">Instructions for this bot
					<textarea rows="4" name="<?php echo $f( 'instructions' ); // phpcs:ignore ?>" placeholder="You are the sales assistant of ... Help visitors choose a hosting plan. Always mention the free domain."><?php echo esc_textarea( $b['instructions'] ); ?></textarea>
				</label>
				<p class="akdk-st-inline">
					<label><input type="radio" name="<?php echo $f( 'prompt_mode' ); // phpcs:ignore ?>" value="add" <?php checked( 'add', $b['prompt_mode'] ); ?>> Add to the main instructions (AI Settings)</label>
					<label><input type="radio" name="<?php echo $f( 'prompt_mode' ); // phpcs:ignore ?>" value="replace" <?php checked( 'replace', $b['prompt_mode'] ); ?>> Use instead of the main instructions (for example for a different client)</label>
				</p>
				<div class="akdk-st-full"><span class="akdk-st-label">Knowledge this bot may use <small>(nothing ticked = everything trained under Train Chatbot)</small></span>
					<div class="akdk-st-checks">
						<?php foreach ( $types as $k => $label ) : ?>
							<label><input type="checkbox" name="<?php echo $f( 'knowledge' ); // phpcs:ignore ?>[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $b['knowledge'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</div>
				</div>
				<label class="akdk-st-full">Show on these pages <small>(one per line: part of the address such as <code>/shop</code> or <code>/services/seo</code>, a page ID, or <code>/</code> for the home page. Empty = only where you use the shortcode)</small>
					<textarea rows="2" name="<?php echo $f( 'pages' ); // phpcs:ignore ?>" placeholder="/shop&#10;/product/"><?php echo esc_textarea( $b['pages'] ); ?></textarea>
				</label>
			</div>
			<?php
			return ob_get_clean();
		};
		?>
		<p class="akdk-st-intro">Make a separate bot for a part of your website (shop, services, one client's pages) with its own name, colour, instructions and knowledge. Pages that match no bot use the main bot. To put a bot inside a page use <code>[akdk_chatbot bot="the-bot-id"]</code>.</p>
		<form method="post" action="options.php" class="akdk-st-form">
			<?php settings_fields( 'wp_ai_chatbot_bots_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="">
			<div class="akdk-st-rows" data-next="<?php echo (int) count( $bots ); ?>">
				<?php
				foreach ( $bots as $i => $b ) {
					echo $row( $i, $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				?>
			</div>
			<script type="text/template" class="akdk-st-template"><?php echo $row( '__i__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
			<p><button type="button" class="button akdk-st-add">+ Add a bot</button></p>
			<?php submit_button( 'Save bots' ); ?>
		</form>
		<?php
	}
}
