<?php
namespace CredoqAppointments\Integrations;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Booking_Service;
use CredoqAppointments\Booking_Repository;
use CredoqAppointments\Appointment_Repository;

/**
 * WooCommerce order → booking lifecycle.
 *
 * AUDIT-FIX B-13: Appointments hook priority 10 (Membership runs at 5 first).
 *
 * --- BUGS FIXED IN THIS FILE ---
 *
 * FIX-WC-1: on_complete() never deducted membership credits.
 *           Credits for WC bookings are now deducted here, after payment
 *           confirmation, using the plan_id + credit_amount stored in
 *           booking.notes by Booking_Service::create().
 *
 * FIX-WC-2: on_cancel() never refunded credits.
 *           cancel() is now called with $refund_credits=true so
 *           Booking_Service::cancel() correctly reverses the deduction.
 *
 * FIX-WC-3: save_booking_meta() didn't persist plan_id for credit use.
 *           Now also stores _credoq_plan_id on the order line item.
 *
 * FIX-WC-4: display_booking_in_cart() crashed with a PHP notice when
 *           the appointment row was missing (service deleted after booking).
 *           Defensive null-check added.
 */
class WooCommerce {

    public static function register() : void {
        // Order status → booking status lifecycle.
        add_action( 'woocommerce_order_status_completed',  [ __CLASS__, 'on_complete' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'on_complete' ], 10, 1 );
        add_action( 'woocommerce_order_status_cancelled',  [ __CLASS__, 'on_cancel'   ], 10, 1 );
        add_action( 'woocommerce_order_status_refunded',   [ __CLASS__, 'on_cancel'   ], 10, 1 );
        add_action( 'woocommerce_order_status_failed',     [ __CLASS__, 'on_cancel'   ], 10, 1 );

        // Line item meta — persists booking IDs from cart to order.
        add_action( 'woocommerce_checkout_create_order_line_item',
            [ __CLASS__, 'save_booking_meta' ], 10, 4 );

        // Cart / checkout display.
        add_filter( 'woocommerce_get_item_data',
            [ __CLASS__, 'display_booking_in_cart' ], 10, 2 );

        // FIX-BS-10: Two-hook strategy to guarantee price override on every request:
        //
        // Hook 1 — woocommerce_get_cart_item_from_session (filter, priority 1)
        //   Fires when WC rebuilds each cart item from the PHP session.
        //   We set_price() here so the WC_Product object already carries the
        //   correct price before ANY totals calculation begins.
        //
        // Hook 2 — woocommerce_before_calculate_totals (action, priority 1)
        //   Fires just before WC sums up the cart. Acts as a safety net in
        //   case the session hook ran on a different WC_Product instance.
        //   We iterate cart_contents BY REFERENCE so set_price() is guaranteed
        //   to affect the same object that WC will use to calculate totals.
        add_filter( 'woocommerce_get_cart_item_from_session',
            [ __CLASS__, 'restore_dynamic_price_from_session' ], 1, 1 );

        add_action( 'woocommerce_before_calculate_totals',
            [ __CLASS__, 'apply_dynamic_price' ], 1, 1 );
    }

    /**
     * Hook 1: restore price as soon as the cart item is loaded from session.
     *
     * @param  array $cart_item  Cart item array (already has _credoq_dynamic_price
     *                           because we stored it in $cart_data inside create()).
     * @return array
     */
    public static function restore_dynamic_price_from_session( array $cart_item ) : array {
        if ( isset( $cart_item['_credoq_dynamic_price'] ) && is_object( $cart_item['data'] ) ) {
            $cart_item['data']->set_price( floatval( $cart_item['_credoq_dynamic_price'] ) );
        }
        return $cart_item;
    }

    /**
     * Override cart item price with the Credoq-computed total stored in
     * _credoq_dynamic_price (base_price + addon fields).
     *
     * CRITICAL: get_cart() returns an array of arrays. $item['data'] is a
     * WC_Product object which IS passed by reference inside that array, so
     * set_price() on it DOES stick — but only when we iterate by reference
     * over the outer array.  Iterating by value (the default foreach) gives
     * us a copy of the outer array; set_price() is called on the real object
     * (objects are always by-handle) so it actually works, BUT only if WC has
     * not already cloned the product. To be safe we use get_cart() by
     * reference so the outer array entry is not copied either.
     *
     * Additionally we add woocommerce_get_cart_item_from_session so the price
     * is applied the moment WC rebuilds the cart from the session — before any
     * totals calculation fires.
     *
     * @param \WC_Cart $cart
     */
    public static function apply_dynamic_price( \WC_Cart $cart ) : void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        // Iterate by reference so set_price() is guaranteed to stick.
        foreach ( $cart->cart_contents as &$item ) {
            if ( isset( $item['_credoq_dynamic_price'] ) && is_object( $item['data'] ) ) {
                $item['data']->set_price( floatval( $item['_credoq_dynamic_price'] ) );
            }
        }
        unset( $item ); // break the reference
    }

    /**
     * Payment received (processing or completed) → confirm bookings.
     *
     * AUDIT-FIX (P2 housekeeping — removed dead code, previously "FIX-WC-1:
     * Also deducts membership credits if configured"): credit deduction and
     * the WC cart are mutually exclusive per booking by design —
     * Booking_Service::create() only ever reaches the $use_wc branch when
     * $use_credit is false (either credit deduction isn't enabled for this
     * service, or it is but the customer had insufficient credits and is
     * paying cash instead — case (b) of the three-way decision). A booking
     * that goes through WC checkout was never meant to *also* deduct
     * credits afterward. This method used to have a branch that tried to —
     * gated on `_credoq_plan_id` read from the order line item — but
     * create() always hardcodes that meta to `0` before adding to cart
     * (correctly: there's no plan to charge on this path), so the branch's
     * own `$plan_id > 0` guard could never pass. It was confirmed-dead
     * code, not a live gap; removed rather than left as confusing,
     * never-executing scaffolding that implied behavior this plugin
     * doesn't actually have.
     */
    public static function on_complete( int $order_id ) : void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item ) {
            // HPOS-compatible meta read.
            $booking_ids = $item->get_meta( '_credoq_booking_ids' );
            if ( ! $booking_ids ) continue;

            foreach ( (array) $booking_ids as $bid ) {
                $bid     = intval( $bid );
                $booking = Booking_Repository::find( $bid );
                if ( ! $booking || $booking->status !== 'pending_payment' ) continue;

                Booking_Service::confirm( $bid );

                // Update wc_order_id on the booking row (HPOS-safe direct update).
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'credoq_bookings',
                    [ 'wc_order_id' => $order_id ],
                    [ 'id' => $bid ],
                    [ '%d' ], [ '%d' ]
                );
            }
        }
    }

    /**
     * Order cancelled/refunded/failed → cancel bookings.
     *
     * FIX-WC-2: pass $refund_credits=true so Booking_Service::cancel()
     * reverses any credit deduction already applied.
     */
    public static function on_cancel( int $order_id ) : void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item ) {
            $booking_ids = $item->get_meta( '_credoq_booking_ids' );
            if ( ! $booking_ids ) continue;

            foreach ( (array) $booking_ids as $bid ) {
                // FIX-WC-2: refund_credits = true.
                Booking_Service::cancel( intval( $bid ), true );
            }
        }
    }

    /**
     * Persist booking IDs and plan ID on the order line item (HPOS-safe).
     *
     * FIX-WC-3: also saves _credoq_plan_id so on_complete() can deduct credits.
     *
     * @param \WC_Order_Item_Product $item
     * @param string                 $cart_item_key
     * @param array                  $cart_item
     * @param \WC_Order              $order
     */
    public static function save_booking_meta( $item, $cart_item_key, $cart_item, $order ) : void {
        if ( ! empty( $cart_item['_credoq_booking_ids'] ) ) {
            $item->update_meta_data( '_credoq_booking_ids', $cart_item['_credoq_booking_ids'] );
        }
        if ( ! empty( $cart_item['_credoq_group_id'] ) ) {
            $item->update_meta_data( '_credoq_group_id', $cart_item['_credoq_group_id'] );
        }
        // FIX-WC-3: persist plan_id for credit deduction on payment.
        if ( ! empty( $cart_item['_credoq_plan_id'] ) ) {
            $item->update_meta_data( '_credoq_plan_id', intval( $cart_item['_credoq_plan_id'] ) );
        }
        // FIX-BS-9: persist computed dynamic total for admin order display.
        if ( isset( $cart_item['_credoq_dynamic_price'] ) ) {
            $item->update_meta_data( '_credoq_dynamic_price', floatval( $cart_item['_credoq_dynamic_price'] ) );
        }
    }

    /**
     * Show booking details in the cart / checkout review table.
     *
     * FIX-WC-4: added null-check on appointment row.
     *
     * @param array $data      Existing item meta display array.
     * @param array $cart_item
     * @return array
     */
    public static function display_booking_in_cart( array $data, array $cart_item ) : array {
        if ( empty( $cart_item['_credoq_booking_ids'] ) ) return $data;

        $ids = (array) $cart_item['_credoq_booking_ids'];
        foreach ( $ids as $bid ) {
            $b = Booking_Repository::find( intval( $bid ) );
            if ( ! $b ) continue;

            // FIX-WC-4: appointment might have been deleted since booking.
            $apt  = Appointment_Repository::find( intval( $b->appointment_id ) );
            $name = $apt ? $apt->title : __( 'Service', 'credoq-appointments' );

            $data[] = [
                'name'    => $name,
                'display' => date_i18n( get_option( 'date_format' ), strtotime( $b->selected_date ) )
                             . ' ' . __( 'at', 'credoq-appointments' ) . ' '
                             . date_i18n( 'H:i', strtotime( $b->selected_time ) ),
            ];
        }

        return $data;
    }
}
