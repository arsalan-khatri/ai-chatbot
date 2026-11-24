<?php
/**
 * Chatbot mode.
 *
 * - Smart mode (default): the bot searches the website, PDFs and products
 *   (Train Chatbot) and answers from what it finds.
 * - Simple mode: no search. The bot answers only from the business details
 *   the owner types in AI Settings (and the instructions). Nothing to train,
 *   no search key needed; voice still works with a Gemini key.
 *
 * Stored in wp_ai_chatbot_settings: bot_mode ('smart' | 'basic') and the
 * business details biz_name, biz_about, biz_services, biz_hours, biz_contact,
 * biz_faq.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Bot_Mode {

	/** Business detail boxes of Simple mode: key => array( heading, label, placeholder, rows ). */
	const FIELDS = array(
		'biz_name'     => array( 'BUSINESS', 'Business name', 'e.g. Glow Beauty Salon', 1 ),
		'biz_about'    => array( 'ABOUT', 'What you do', 'e.g. A ladies beauty salon in Gulshan, Karachi, since 2015. Bridal makeup is our speciality.', 3 ),
		'biz_services' => array( 'SERVICES AND PRICES', 'Services and prices', "One per line, e.g.\nHaircut - Rs 1,500\nBridal makeup - from Rs 35,000\nFacial - Rs 3,000", 6 ),
		'biz_hours'    => array( 'TIMINGS', 'Timings', 'e.g. Mon to Sat 11am to 9pm, Sunday closed', 2 ),
		'biz_contact'  => array( 'CONTACT AND LOCATION', 'Contact and location', "e.g.\nPhone / WhatsApp: 0300 1234567\nEmail: info@example.com\nAddress: Shop 4, Block 13, Gulshan, Karachi\nBooking: https://example.com/book", 4 ),
		'biz_faq'      => array( 'FREQUENTLY ASKED QUESTIONS', 'Common questions and answers', "e.g.\nQ: Do you take walk-ins?\nA: Yes, but booking is better on weekends.\nQ: Is parking available?\nA: Yes, in front of the shop.", 6 ),
	);

	public static function mode() {
		$s = WP_AI_Chatbot_AI::settings();
		return isset( $s['bot_mode'] ) && 'basic' === $s['bot_mode'] ? 'basic' : 'smart';
	}

	public static function is_basic() {
		return 'basic' === self::mode();
	}

	/** The owner's business details as one block for the prompt ('' when empty). */
	public static function business_info() {
		$s   = WP_AI_Chatbot_AI::settings();
		$out = '';
		foreach ( self::FIELDS as $key => $f ) {
			$v = isset( $s[ $key ] ) ? trim( (string) $s[ $key ] ) : '';
			if ( '' !== $v ) {
				$out .= $f[0] . ":\n" . $v . "\n\n";
			}
		}
		return $out;
	}

	/**
	 * What the AI is given in Simple mode in place of search results: the business
	 * details, plus the reply for unrelated questions when the off-topic block is on
	 * (in Simple mode the AI itself declines them; no search, no extra call).
	 */
	public static function knowledge() {
		$info = self::business_info();
		if ( '' === $info ) {
			$info = "(No business details were added. Use only the instructions above.)\n\n";
		}
		$answers = WP_AI_Chatbot_Unanswered::answers_text();
		if ( '' !== $answers ) {
			$info .= "MORE QUESTIONS AND ANSWERS (from the owner):\n" . $answers . "\n";
		}
		if ( WP_AI_Chatbot_Token_Control::off_topic_on() ) {
			$info .= "UNRELATED QUESTIONS: if the visitor asks something that has nothing to do with this business (jokes, news, sports, homework, general knowledge, coding and so on), reply with exactly this and nothing else:\n" . WP_AI_Chatbot_Token_Control::off_topic_message() . "\n";
		}
		return $info;
	}

	/** True when a Simple-mode reply is the fixed off-topic reply. */
	public static function is_off_topic_reply( $reply ) {
		if ( ! WP_AI_Chatbot_Token_Control::off_topic_on() ) {
			return false;
		}
		$norm = function ( $t ) {
			return strtolower( preg_replace( '/[^a-z0-9]+/i', '', (string) $t ) );
		};
		return $norm( $reply ) === $norm( WP_AI_Chatbot_Token_Control::off_topic_message() );
	}

	/** Sanitizes the mode and business details into $sanitized. */
	public static function sanitize( $input, $sanitized ) {
		if ( isset( $input['bot_mode'] ) ) {
			$sanitized['bot_mode'] = 'basic' === $input['bot_mode'] ? 'basic' : 'smart';
		}
		foreach ( array_keys( self::FIELDS ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$sanitized[ $key ] = 'biz_name' === $key ? sanitize_text_field( $input[ $key ] ) : sanitize_textarea_field( $input[ $key ] );
			}
		}
		if ( isset( $sanitized['bot_mode'] ) && 'basic' === $sanitized['bot_mode'] ) {
			$empty = true;
			foreach ( array_keys( self::FIELDS ) as $key ) {
				if ( ! empty( $sanitized[ $key ] ) && trim( $sanitized[ $key ] ) !== '' && 'biz_name' !== $key ) {
					$empty = false;
				}
			}
			if ( $empty ) {
				add_settings_error( 'wp_ai_chatbot_settings', 'basic_empty', 'Simple mode is on, but no business details were added. Fill in at least your services and contact details, or the bot can only say it is not sure.', 'warning' );
			}
		}
		return $sanitized;
	}

	/** AI Settings > Chatbot mode: the two mode cards and, for Simple mode, the business detail boxes. */
	public static function render_field( $options ) {
		$mode = isset( $options['bot_mode'] ) && 'basic' === $options['bot_mode'] ? 'basic' : 'smart';
		?>
		<div class="akdk-mode">
			<div class="akdk-mode-cards">
				<label class="akdk-mode-card">
					<input type="radio" name="wp_ai_chatbot_settings[bot_mode]" value="smart" <?php checked( 'smart', $mode ); ?> />
					<strong>Smart mode</strong>
					<span>Learns your website: pages, PDFs and products (Train Chatbot), and answers from them. Best for shops and sites with a lot of content.</span>
				</label>
				<label class="akdk-mode-card">
					<input type="radio" name="wp_ai_chatbot_settings[bot_mode]" value="basic" <?php checked( 'basic', $mode ); ?> />
					<strong>Simple mode</strong>
					<span>Answers only from the business details you type below. No training, no search key. Best for a salon, clinic, restaurant or local service.</span>
				</label>
			</div>

			<div class="akdk-basic-info"<?php echo 'basic' === $mode ? '' : ' hidden'; ?>>
				<p class="description">Write what a visitor may ask about. The bot answers <strong>only</strong> from this and your instructions, so if something is not here it says it is not sure and offers your contact details or a form.</p>
				<?php foreach ( self::FIELDS as $key => $f ) : ?>
					<p class="akdk-basic-field">
						<label for="akdk-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $f[1] ); ?></strong></label><br />
						<?php if ( 1 === $f[3] ) : ?>
							<input type="text" id="akdk-<?php echo esc_attr( $key ); ?>" name="wp_ai_chatbot_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $options[ $key ] ) ? $options[ $key ] : '' ); ?>" class="regular-text akdk-basic-input" placeholder="<?php echo esc_attr( $f[2] ); ?>" />
						<?php else : ?>
							<textarea id="akdk-<?php echo esc_attr( $key ); ?>" name="wp_ai_chatbot_settings[<?php echo esc_attr( $key ); ?>]" rows="<?php echo (int) $f[3]; ?>" class="large-text akdk-basic-input" placeholder="<?php echo esc_attr( $f[2] ); ?>"><?php echo esc_textarea( isset( $options[ $key ] ) ? $options[ $key ] : '' ); ?></textarea>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
				<?php
				$answers = WP_AI_Chatbot_Unanswered::answers_text();
				$n_ans   = '' === $answers ? 0 : substr_count( $answers, "\nA: " );
				?>
				<?php if ( $n_ans ) : ?>
					<p class="description">Also sent: <strong><?php echo (int) $n_ans; ?> answer<?php echo 1 === $n_ans ? '' : 's'; ?></strong> you added under <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-logs&tab=unanswered&uq=answered' ) ); ?>">Chat History &rarr; Unanswered questions</a>.</p>
				<?php endif; ?>
				<p class="akdk-basic-size" data-extra="<?php echo (int) ( function_exists( 'mb_strlen' ) ? mb_strlen( $answers ) : strlen( $answers ) ); ?>">Sent with every message: about <strong class="akdk-basic-tokens">0</strong> tokens. <span class="akdk-basic-advice"></span></p>
			</div>
			<p class="akdk-smart-info description"<?php echo 'basic' === $mode ? ' hidden' : ''; ?>>After saving, go to <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-rag' ) ); ?>">Train Chatbot</a> so the bot learns your content.</p>
		</div>
		<?php
	}
}
