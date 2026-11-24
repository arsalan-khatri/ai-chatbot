<?php
/**
 * Product cards with Add to cart / Buy now (2.0.0).
 *
 * After a reply, the products it mentions (by link or by name, among the
 * products the bot was given) are sent to the widget as cards: photo, name,
 * price and buttons. Simple products: "Add to cart" (WooCommerce's own AJAX
 * add to cart, the mini cart updates) and "Buy now" (straight to checkout).
 * Variable and other products: "Choose options" opens the product page.
 * Out-of-stock products show "Out of stock".
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Product_Cards {

	const MAX = 4;

	public static function enabled() {
		$s = WP_AI_Chatbot_Order_Status::settings();
		return function_exists( 'wc_get_product' ) && ( ! isset( $s['cards'] ) || '1' === (string) $s['cards'] );
	}

	/**
	 * Products named in the reply: product links in it, and products the
	 * knowledge search found whose name the reply mentions.
	 *
	 * @return int[] Product IDs, at most MAX, in the order of the reply.
	 */
	public static function products_in( $reply ) {
		if ( ! self::enabled() || '' === trim( (string) $reply ) ) {
			return array();
		}
		$found = array(); // id => position in the reply.
		if ( preg_match_all( '#https?://[^\s<>()\]"\']+#i', $reply, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$url = rtrim( $hit[0], '.,;:!?*' );
				$id  = 0;
				if ( preg_match( '/[?&]add-to-cart=(\d+)/', $url, $a ) ) {
					$id = (int) $a[1];
				} elseif ( 0 === strpos( $url, home_url() ) ) {
					$id = (int) url_to_postid( $url );
					if ( ! $id ) {
						// Plain permalinks: /?product=slug
						parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
						if ( ! empty( $q['product'] ) && is_string( $q['product'] ) ) {
							$post = get_page_by_path( sanitize_title( $q['product'] ), OBJECT, 'product' );
							$id   = $post ? (int) $post->ID : 0;
						}
					}
				}
				if ( $id && 'product' === get_post_type( $id ) && ! isset( $found[ $id ] ) ) {
					$found[ $id ] = (int) $hit[1];
				}
			}
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $reply ) : strtolower( $reply );
		foreach ( WP_AI_Chatbot_RAG_Engine::$seen_products as $id => $title ) {
			$t = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title ) );
			if ( isset( $found[ $id ] ) || strlen( $t ) < 4 ) {
				continue;
			}
			$pos = strpos( $lower, $t );
			if ( false !== $pos ) {
				$found[ (int) $id ] = (int) $pos;
			}
		}
		asort( $found );
		return array_slice( array_keys( $found ), 0, self::MAX );
	}

	/**
	 * The reply without the image cards of products that get a product card
	 * (the card already shows the photo and the link).
	 */
	public static function strip_cards( $reply, $cards ) {
		foreach ( $cards as $c ) {
			$u = preg_quote( untrailingslashit( $c['url'] ), '/' );
			$reply = preg_replace( '/\[!\[[^\]]*\]\([^)]*\)\]\(' . $u . '\/?\)\s*/', '', $reply );
			$reply = preg_replace( '/\*\*\[Click here to view:[^\]]*\]\(' . $u . '\/?\)\*\*\s*/i', '', $reply );
			$reply = preg_replace( '/!\[[^\]]*\]\(' . $u . '\/?\)\s*/', '', $reply ); // An image card whose photo was removed.
		}
		return trim( preg_replace( "/\n{3,}/", "\n\n", $reply ) );
	}

	/** Card data for the widget. */
	public static function cards( $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$p = wc_get_product( (int) $id );
			if ( ! $p || 'publish' !== get_post_status( $p->get_id() ) || 'hidden' === $p->get_catalog_visibility() || '' !== (string) $p->get_post_password() ) {
				continue;
			}
			$simple = $p->is_type( 'simple' ) && $p->is_purchasable() && $p->is_in_stock();
			$image  = wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' );
			$price  = html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' );
			$out[]  = array(
				'id'       => $p->get_id(),
				'name'     => $p->get_name(),
				'url'      => get_permalink( $p->get_id() ),
				'image'    => $image ? $image : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '' ),
				'price'    => trim( preg_replace( '/\s+/u', ' ', $price ) ),
				'stock'    => $p->is_in_stock() ? 'in' : 'out',
				'can_add'  => $simple,
				'buy_url'  => $simple ? add_query_arg( 'add-to-cart', $p->get_id(), wc_get_checkout_url() ) : '',
			);
		}
		return $out;
	}

	/** Data for the widget (shared strings and addresses). */
	public static function widget_data() {
		if ( ! self::enabled() ) {
			return array( 'cards_enabled' => '0' );
		}
		return array(
			'cards_enabled' => '1',
			'wc_ajax_add'   => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : home_url( '/?wc-ajax=add_to_cart' ),
			'cart_url'      => wc_get_cart_url(),
		);
	}
}
