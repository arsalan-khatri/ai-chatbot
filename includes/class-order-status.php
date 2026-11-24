<?php
/**
 * WooCommerce order status in the chat (2.0.0).
 *
 * "Mera order kahan hai?", "track order 1234", "order status":
 * - The visitor gives the order number and the email or phone number used for
 *   the order (asked for when missing). Only when both match is anything
 *   shown, so nobody can look up other people's orders. Logged-in customers
 *   only need the order number (or get their latest order).
 * - On WhatsApp the sender's number is checked against the order's phone.
 * - The reply is written by the plugin from the order itself (status, date,
 *   total, payment, items, tracking number and link from common tracking
 *   plugins): always correct and it costs no AI tokens.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Order_Status {

	const OPTION   = 'wp_ai_chatbot_orders';
	const MAX_FAIL = 5; // Wrong order/email pairs per chat per hour.

	const INTENT_RE = '/\b(order|orders|track|tracking|trace|parcel|shipment|shipping\s+status|delivery\s+status|consignment|courier|dispatch(ed)?|aarder|arder)\b/iu';
	const URDU_RE   = '/(mera|meri|mere|my)\s+(order|parcel)|order\s+(kahan|kab|kidhar|status)|parcel\s+(kahan|kab)/iu';

	public static function settings() {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array_merge( array( 'enabled' => '1', 'show_items' => '1', 'cards' => '1' ), $o );
	}

	public static function available() {
		return function_exists( 'wc_get_order' ) && '1' === (string) self::settings()['enabled'];
	}

	private static function state_key( $session_id ) {
		return 'wp_ai_order_ask_' . md5( (string) $session_id );
	}

	/**
	 * Order number in a message: "#1234", "order 1234", "order no. 1234", or a bare number.
	 *
	 * @param int $loose 0 = only with "#" / "order", 1 = also a bare 3-10 digit number, 2 = also a short one (we asked for it).
	 */
	public static function order_number( $message, $loose = 0 ) {
		// Emails and phone numbers are not order numbers.
		$message = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', ' ', (string) $message );
		$message = preg_replace( '/\+?\d[\d\s\-()]{8,}\d/', ' ', $message );
		if ( preg_match( '/(?:#\s*|order\s*(?:no\.?|number|num|id)\s*[:#]?\s*)(\d{2,12})\b/iu', $message, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/\border\s+(\d{3,12})\b/iu', $message, $m ) ) {
			return $m[1];
		}
		// A bare number only right after we asked for it (any length), or next to tracking words (3+ digits).
		$min = 2 === $loose ? 1 : 3;
		if ( $loose && preg_match( '/(?<![\d+])(\d{' . $min . ',10})(?![\d])/', $message, $m ) ) {
			return $m[1];
		}
		return '';
	}

	public static function email_in( $message ) {
		return preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $m ) ? strtolower( $m[0] ) : '';
	}

	public static function phone_in( $message ) {
		if ( preg_match_all( '/\+?\d[\d\s\-()]{8,}\d/', $message, $m ) ) {
			foreach ( $m[0] as $cand ) {
				$digits = preg_replace( '/\D/', '', $cand );
				if ( strlen( $digits ) >= 10 ) {
					return $digits;
				}
			}
		}
		return '';
	}

	/** Same phone number? Compares the last 9 digits, so 0300…, +92300… and 92300… match. */
	public static function same_phone( $a, $b ) {
		$a = preg_replace( '/\D/', '', (string) $a );
		$b = preg_replace( '/\D/', '', (string) $b );
		return strlen( $a ) >= 9 && strlen( $b ) >= 9 && substr( $a, -9 ) === substr( $b, -9 );
	}

	/**
	 * Answers order questions. Returns the reply, or null when the message is not about an order.
	 *
	 * @param array $ctx channel ('web'|'whatsapp'), contact (WhatsApp number), user_id.
	 */
	public static function maybe_answer( $session_id, $message, $ctx = array() ) {
		if ( ! self::available() || '' === (string) $session_id ) {
			return null;
		}
		$pending = get_transient( self::state_key( $session_id ) );
		$intent  = preg_match( self::INTENT_RE, $message ) || preg_match( self::URDU_RE, $message );
		$status  = (bool) preg_match( '/\b(status|track|tracking|trace|where|kahan|kab|kidhar|parcel|shipment|placed|arrive|arrived|pohanch|abhi\s+tak|not\s+received|nahi\s+mila|delayed|late)\b/iu', $message ) || preg_match( self::URDU_RE, $message );
		$number  = self::order_number( $message, is_array( $pending ) ? 2 : ( $status ? 1 : 0 ) );
		$email   = self::email_in( $message );
		$phone   = self::phone_in( $message );

		// A follow-up with only the email/phone (or only the number) after we asked for it.
		if ( ! $intent && ! ( is_array( $pending ) && ( '' !== $email || '' !== $phone || '' !== $number ) ) ) {
			return null;
		}
		// "I want to order 2 pizzas", "how do I order?": about ordering, not about an existing order.
		if ( ! is_array( $pending ) && '' === $number && ! $status ) {
			return null;
		}

		$pending = is_array( $pending ) ? $pending : array();
		if ( '' === $number && ! empty( $pending['number'] ) ) {
			$number = $pending['number'];
		}
		$user_id = isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : get_current_user_id();

		// Logged-in customer without a number: their latest order.
		if ( '' === $number && $user_id ) {
			$orders = wc_get_orders( array( 'customer_id' => $user_id, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
			if ( $orders ) {
				delete_transient( self::state_key( $session_id ) );
				return self::describe( $orders[0] );
			}
		}
		if ( '' === $number ) {
			set_transient( self::state_key( $session_id ), array( 'number' => '' ), 30 * MINUTE_IN_SECONDS );
			return 'I can check that for you. Please send your order number (you will find it in your order confirmation, for example #1234)' . ( 'whatsapp' === ( $ctx['channel'] ?? '' ) ? '.' : ' and the email or phone number you used for the order.' );
		}

		$order = self::find_order( $number );
		$fails = (int) get_transient( 'wp_ai_order_fail_' . md5( (string) $session_id ) );
		if ( $fails >= self::MAX_FAIL ) {
			return 'For your security I cannot look up more orders in this chat right now. Please contact our team and they will help you with your order.';
		}

		// Who is asking? The order's email or phone must match.
		$verified = false;
		if ( $order ) {
			if ( $user_id && (int) $order->get_customer_id() === $user_id ) {
				$verified = true;
			} elseif ( 'whatsapp' === ( $ctx['channel'] ?? '' ) && self::same_phone( $ctx['contact'] ?? '', $order->get_billing_phone() ) ) {
				$verified = true;
			} elseif ( '' !== $email && strtolower( (string) $order->get_billing_email() ) === $email ) {
				$verified = true;
			} elseif ( '' !== $phone && ( self::same_phone( $phone, $order->get_billing_phone() ) || self::same_phone( $phone, $order->get_shipping_phone() ) ) ) {
				$verified = true;
			}
		}

		if ( ! $verified && '' === $email && '' === $phone ) {
			// Not checked yet: ask for the email or phone (same answer whether the order exists or not).
			set_transient( self::state_key( $session_id ), array( 'number' => $number ), 30 * MINUTE_IN_SECONDS );
			return 'Thanks! To keep your order private, please send the email address or phone number used for order #' . $number . '.';
		}
		if ( ! $verified ) {
			set_transient( 'wp_ai_order_fail_' . md5( (string) $session_id ), $fails + 1, HOUR_IN_SECONDS );
			delete_transient( self::state_key( $session_id ) );
			return 'Sorry, I could not find an order #' . $number . ' with those details. Please check the order number and the email or phone number you used, or contact our team.';
		}
		delete_transient( self::state_key( $session_id ) );
		return self::describe( $order );
	}

	/** Order by number (also custom order numbers from Sequential Order Numbers plugins). */
	private static function find_order( $number ) {
		$order = wc_get_order( absint( $number ) );
		if ( $order && ! is_a( $order, 'WC_Order_Refund' ) ) {
			return $order;
		}
		foreach ( array( '_order_number', '_order_number_formatted', '_alg_wc_full_custom_order_number' ) as $key ) {
			$found = wc_get_orders( array( 'limit' => 1, 'meta_key' => $key, 'meta_value' => (string) $number ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $found ) {
				return $found[0];
			}
		}
		return null;
	}

	/** What each status means for the customer. */
	private static function status_text( $status ) {
		$map = array(
			'pending'    => 'Pending payment: we are waiting for the payment.',
			'on-hold'    => 'On hold: we are checking the payment or details and will confirm soon.',
			'processing' => 'Processing: your order is confirmed and being prepared for dispatch.',
			'completed'  => 'Completed: your order has been dispatched or delivered.',
			'cancelled'  => 'Cancelled.',
			'refunded'   => 'Refunded.',
			'failed'     => 'Payment failed. You can try again or choose another payment method.',
			'shipped'    => 'Shipped: your order is on the way.',
			'delivered'  => 'Delivered.',
		);
		if ( isset( $map[ $status ] ) ) {
			return $map[ $status ];
		}
		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) . '.' : ucfirst( $status ) . '.';
	}

	/** Tracking numbers from common tracking plugins. @return array of array( provider, number, url ) */
	public static function tracking( $order ) {
		$out   = array();
		$items = $order->get_meta( '_wc_shipment_tracking_items' ); // WooCommerce Shipment Tracking.
		foreach ( (array) $items as $t ) {
			if ( ! empty( $t['tracking_number'] ) ) {
				$out[] = array( (string) ( $t['tracking_provider'] ?? $t['custom_tracking_provider'] ?? '' ), (string) $t['tracking_number'], (string) ( $t['custom_tracking_link'] ?? '' ) );
			}
		}
		foreach ( array( '_aftership_tracking_number', '_tracking_number', 'tracking_number', '_wcst_order_trackno', 'ast_tracking_number' ) as $key ) {
			$n = (string) $order->get_meta( $key );
			if ( '' !== $n ) {
				$out[] = array( (string) $order->get_meta( '_aftership_tracking_provider_name' ), $n, (string) $order->get_meta( '_tracking_url' ) );
				break;
			}
		}
		return apply_filters( 'wp_ai_chatbot_order_tracking', $out, $order );
	}

	/** The reply: status, date, total, payment, items, tracking. */
	public static function describe( $order ) {
		$num   = $order->get_order_number();
		$date  = $order->get_date_created() ? wp_date( 'j M Y', $order->get_date_created()->getTimestamp() ) : '';
		$total = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
		$lines = array( '**Order #' . $num . '**' . ( $date ? ' (placed ' . $date . ')' : '' ) );
		$lines[] = '- Status: ' . self::status_text( $order->get_status() );
		$lines[] = '- Total: ' . $total . ( $order->get_payment_method_title() ? ' (' . $order->get_payment_method_title() . ')' : '' );
		if ( '1' === (string) self::settings()['show_items'] ) {
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name() . ' × ' . $item->get_quantity();
			}
			if ( $items ) {
				$lines[] = '- Items: ' . implode( ', ', array_slice( $items, 0, 6 ) ) . ( count( $items ) > 6 ? ' and more' : '' );
			}
		}
		foreach ( self::tracking( $order ) as $t ) {
			$lines[] = '- Tracking: ' . trim( $t[0] . ' ' . $t[1] ) . ( '' !== $t[2] ? ' ' . $t[2] : '' );
		}
		if ( $order->get_customer_note() ) {
			$lines[] = '- Your note: ' . wp_trim_words( $order->get_customer_note(), 20 );
		}
		$lines[] = '';
		$lines[] = 'Anything else about this order? You can also ask to talk to our team.';
		return implode( "\n", $lines );
	}

	public static function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'enabled'    => empty( $in['enabled'] ) ? '0' : '1',
			'show_items' => empty( $in['show_items'] ) ? '0' : '1',
			'cards'      => empty( $in['cards'] ) ? '0' : '1',
		);
	}

	public static function render_tab() {
		$s  = self::settings();
		$n  = self::OPTION;
		$wc = function_exists( 'wc_get_order' );
		?>
		<?php if ( ! $wc ) : ?>
			<div class="notice notice-info inline"><p>These features need <strong>WooCommerce</strong>. They switch on by themselves when WooCommerce is active.</p></div>
		<?php endif; ?>
		<form method="post" action="options.php" class="akdk-st-form">
			<?php settings_fields( 'wp_ai_chatbot_orders_group' ); ?>
			<div class="akdk-st-card">
				<div class="akdk-st-card-head"><strong>Order status in the chat</strong></div>
				<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( '1', $s['enabled'] ); ?>> Customers can ask "where is my order?" and get the status, total, items and tracking number</label></p>
				<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_items]" value="1" <?php checked( '1', $s['show_items'] ); ?>> Show the products of the order</label></p>
				<p class="description">The bot asks for the order number and the email or phone number used for the order, and shows the order only when both match (logged-in customers only need the number; on WhatsApp the sender's number is checked). After 5 wrong tries the chat is blocked for an hour. The reply comes straight from WooCommerce, so it is always correct and uses no AI tokens. Tracking numbers are read from WooCommerce Shipment Tracking, AfterShip and similar plugins.</p>
			</div>
			<div class="akdk-st-card">
				<div class="akdk-st-card-head"><strong>Product cards with "Add to cart"</strong></div>
				<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[cards]" value="1" <?php checked( '1', $s['cards'] ); ?>> When the bot recommends products, show them as cards with photo, price, <em>Add to cart</em> and <em>Buy now</em></label></p>
				<p class="description">Simple products go into the cart without leaving the chat (the cart in your theme updates), "Buy now" goes straight to checkout. Products with options (size, colour) get a "Choose options" button that opens the product. At most 4 cards per reply.</p>
			</div>
			<?php submit_button( 'Save store settings' ); ?>
		</form>
		<?php
	}
}
