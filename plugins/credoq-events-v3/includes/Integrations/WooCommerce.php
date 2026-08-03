<?php
namespace CredoqEvents\Integrations;
defined( 'ABSPATH' ) || exit;
use CredoqEvents\Event_Booking_Repository;
use CredoqEvents\Event_Service;
// AUDIT-FIX B-13: priority 10 for events (membership=5 first)
class WooCommerce {
    public static function register() : void {
        add_action('woocommerce_order_status_completed',  [__CLASS__,'on_complete'],  10, 1);
        add_action('woocommerce_order_status_processing', [__CLASS__,'on_complete'],  10, 1);
        // AUDIT-FIX (Events + Seats — point 7 "WC order cancelled, refunded,
        // or failed → seats release"): only 'cancelled' was registered
        // before, so a failed or refunded payment left held/confirmed
        // seats stuck forever instead of releasing back to available.
        add_action('woocommerce_order_status_cancelled',  [__CLASS__,'on_cancel'],    10, 1);
        add_action('woocommerce_order_status_refunded',   [__CLASS__,'on_cancel'],    10, 1);
        add_action('woocommerce_order_status_failed',     [__CLASS__,'on_cancel'],    10, 1);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__,'save_meta'], 10, 4);
    }
    public static function on_complete(int $order_id) : void {
        $order = wc_get_order($order_id); if (!$order) return;
        foreach ($order->get_items() as $item) {
            // Legacy path: standalone credoq_event_register flow (adds
            // '_credoq_event_booking_id' directly to the cart item).
            $bid = $item->get_meta('_credoq_event_booking_id');
            if ($bid) {
                Event_Booking_Repository::update_status(intval($bid), 'confirmed');
                do_action('credoq_event_booking_confirmed', intval($bid));
                continue;
            }
            // AUDIT-FIX (WC checkout redirect for Event Registration form
            // field): the Form Builder / booking-widget flow instead adds
            // the cart item via the Engine's WooCommerce_Bridge, which
            // tags it with '_credoq_submission_id' — not
            // '_credoq_event_booking_id'. Without this branch, bookings
            // created by Field_Event::on_submission() stayed
            // 'pending_payment' forever even after the customer paid.
            $submission_id = absint( $item->get_meta( '_credoq_submission_id' ) );
            if ( $submission_id ) {
                foreach ( Event_Booking_Repository::find_by_submission( $submission_id ) as $booking ) {
                    if ( 'cancelled' === $booking->status ) continue;
                    Event_Booking_Repository::update_status_by_submission( $submission_id, 'confirmed', $order_id );
                    do_action( 'credoq_event_booking_confirmed', (int) $booking->id );
                }
            }
        }
    }
    public static function on_cancel(int $order_id) : void {
        $order = wc_get_order($order_id); if (!$order) return;
        foreach ($order->get_items() as $item) {
            $bid = $item->get_meta('_credoq_event_booking_id');
            if ($bid) { Event_Service::cancel(intval($bid)); continue; }

            $submission_id = absint( $item->get_meta( '_credoq_submission_id' ) );
            if ( $submission_id ) {
                Event_Booking_Repository::update_status_by_submission( $submission_id, 'cancelled' );
                // AUDIT-FIX (hook-contract mismatch): every OTHER caller of
                // 'credoq_event_booking_cancelled' (Event_Service::cancel()
                // above, and on_complete()'s do_action('...confirmed', ...)
                // sibling) passes an event_booking ROW id — which is also
                // exactly what Seats\Integrations\Events_Bridge::on_cancelled()
                // expects (it looks up credoq_event_bookings.id to find the
                // submission_id). This used to fire with $submission_id
                // instead, so that lookup silently found nothing and seats
                // never released on a WC cancellation/refund/failure. Fire
                // once per booking row, like on_complete() already does.
                foreach ( Event_Booking_Repository::find_by_submission( $submission_id ) as $booking ) {
                    do_action( 'credoq_event_booking_cancelled', (int) $booking->id );
                }
            }
        }
    }
    public static function save_meta($item, $cart_item_key, $cart_item, $order) : void {
        if (!empty($cart_item['_credoq_event_booking_id'])) {
            $item->update_meta_data('_credoq_event_booking_id', $cart_item['_credoq_event_booking_id']);
        }
    }
}
