<?php
/**
 * WooCommerce_Bridge — standalone WC cart bridging for Engine field types.
 *
 * This lets Checkbox, Select, Radio and Calculate fields push pricing
 * straight into the WooCommerce cart/checkout flow without depending on
 * any addon plugin (Appointments, Membership, Events, etc.).
 *
 * Architecture (mirrors the proven Appointments approach):
 *
 *   - Submission_Handler::process() sums each field's wc_contribution()
 *     into a [ product_id => price ] map and calls add_cart_items().
 *
 *   - add_cart_items() adds/refreshes one cart line item per product_id,
 *     storing the computed price in '_credoq_dynamic_price' inside the
 *     cart item data array so it survives the redirect to checkout.
 *
 *   - Two hooks keep that price applied on every subsequent page load:
 *       woocommerce_get_cart_item_from_session (priority 1)
 *       woocommerce_before_calculate_totals    (priority 1)
 *
 * Addons that already register the same hook + meta key (e.g.
 * Credoq Appointments' Integrations\WooCommerce) are unaffected — both
 * simply call set_price() with the same '_credoq_dynamic_price' value.
 *
 * @package CredoqEngine\Integrations
 */

namespace CredoqEngine\Integrations;

defined( 'ABSPATH' ) || exit;

class WooCommerce_Bridge {

	public static function register() : void {
		if ( ! function_exists( 'WC' ) ) return;

		add_filter( 'woocommerce_get_cart_item_from_session',
			array( __CLASS__, 'restore_dynamic_price_from_session' ), 1, 1 );

		add_action( 'woocommerce_before_calculate_totals',
			array( __CLASS__, 'apply_dynamic_price' ), 1, 1 );

		// Persist the engine submission id + dynamic price onto the order
		// line item so admins can trace which submission generated it.
		add_action( 'woocommerce_checkout_create_order_line_item',
			array( __CLASS__, 'save_line_item_meta' ), 10, 4 );

		// AUDIT-FIX: nothing previously synced credoq_submissions.status
		// after WC checkout completed, so every submission stayed
		// 'pending' forever in the admin Submissions list — even ones
		// that successfully paid. These hooks flip the linked
		// submission(s) to 'confirmed' on payment, 'cancelled' on
		// cancel/failure/refund, and stamp wc_order_id for the admin
		// detail view's "View order" link.
		add_action( 'woocommerce_order_status_completed',  array( __CLASS__, 'on_order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled',  array( __CLASS__, 'on_order_voided' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed',     array( __CLASS__, 'on_order_voided' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded',   array( __CLASS__, 'on_order_voided' ), 20, 1 );
	}

	/**
	 * Walk an order's line items, pull every '_credoq_submission_id'
	 * meta value off them, and update those submissions' status +
	 * wc_order_id. Used by both on_order_paid() and on_order_voided().
	 *
	 * @param int    $order_id
	 * @param string $status  New credoq_submissions.status value.
	 */
	private static function sync_submission_status( int $order_id, string $status ) : void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		global $wpdb;
		foreach ( $order->get_items() as $item ) {
			$submission_id = absint( $item->get_meta( '_credoq_submission_id' ) );
			if ( ! $submission_id ) continue;

			$wpdb->update(
				$wpdb->prefix . 'credoq_submissions',
				array(
					'status'     => $status,
					'wc_order_id' => $order_id,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $submission_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
		}
	}

	/** Order paid (completed/processing) → submission confirmed. */
	public static function on_order_paid( int $order_id ) : void {
		self::sync_submission_status( $order_id, 'confirmed' );
	}

	/** Order cancelled/failed/refunded → submission cancelled. */
	public static function on_order_voided( int $order_id ) : void {
		self::sync_submission_status( $order_id, 'cancelled' );
	}

	/**
	 * Add (or refresh) one cart item per [ product_id => price ] entry.
	 *
	 * @param array $product_prices  [ product_id => price ]
	 * @param int   $submission_id
	 * @return string Checkout URL if at least one item was added, '' otherwise.
	 */
	public static function add_cart_items( array $product_prices, int $submission_id ) : string {
		if ( empty( $product_prices ) || ! function_exists( 'WC' ) ) return '';

		self::ensure_cart();
		if ( ! WC()->cart ) return '';

		$added = false;
		foreach ( $product_prices as $product_id => $price ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) continue;
			if ( ! wc_get_product( $product_id ) ) continue;

			$cart_data = array(
				'_credoq_submission_id' => $submission_id,
				'_credoq_dynamic_price' => round( (float) $price, 2 ),
			);

			$added_key = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_data );
			if ( $added_key ) $added = true;
		}

		if ( ! $added ) return '';

		WC()->cart->calculate_totals();

		return wc_get_checkout_url();
	}

	/**
	 * Make sure WC()->session / customer / cart exist (REST requests may
	 * not have booted them yet).
	 */
	private static function ensure_cart() : void {
		if ( ! WC()->session ) {
			$session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
			WC()->session  = new $session_class();
			WC()->session->init();
		}
		if ( ! WC()->customer ) {
			WC()->customer = new \WC_Customer( get_current_user_id(), true );
		}
		if ( ! WC()->cart ) {
			WC()->cart = new \WC_Cart();
			WC()->cart->get_cart_from_session();
		}
	}

	/**
	 * Hook 1: restore price as soon as the cart item is loaded from session.
	 */
	public static function restore_dynamic_price_from_session( array $cart_item ) : array {
		if ( isset( $cart_item['_credoq_dynamic_price'] ) && is_object( $cart_item['data'] ) ) {
			$cart_item['data']->set_price( floatval( $cart_item['_credoq_dynamic_price'] ) );
		}
		return $cart_item;
	}

	/**
	 * Hook 2: safety net — re-apply the dynamic price right before totals
	 * are calculated, iterating cart_contents by reference.
	 */
	public static function apply_dynamic_price( \WC_Cart $cart ) : void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
		foreach ( $cart->cart_contents as &$item ) {
			if ( isset( $item['_credoq_dynamic_price'] ) && is_object( $item['data'] ) ) {
				$item['data']->set_price( floatval( $item['_credoq_dynamic_price'] ) );
			}
		}
		unset( $item );
	}

	/**
	 * Persist the engine submission id + dynamic price on the order line
	 * item for admin reference.
	 *
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array                  $cart_item
	 * @param \WC_Order              $order
	 */
	public static function save_line_item_meta( $item, $cart_item_key, $cart_item, $order ) : void {
		if ( isset( $cart_item['_credoq_submission_id'] ) ) {
			$item->update_meta_data( '_credoq_submission_id', absint( $cart_item['_credoq_submission_id'] ) );
		}
		if ( isset( $cart_item['_credoq_dynamic_price'] ) ) {
			$item->update_meta_data( '_credoq_dynamic_price', floatval( $cart_item['_credoq_dynamic_price'] ) );
		}
	}
}
