<?php
namespace CredoqEvents;
defined( 'ABSPATH' ) || exit;

class Event_Service {

    // AUDIT-FIX (Network error on submit): Field_Event::validate() calls
    // Event_Service::has_capacity() to check remaining spots before letting
    // a form submission through, but this method never existed on this
    // class. class_exists('\CredoqEvents\Event_Service') was true (the
    // class is loaded), so PHP attempted the call anyway and hit a fatal
    // "Call to undefined method" error. That fatal aborted the AJAX
    // request before wp_send_json_*() could run, so the browser received
    // a non-JSON (broken/empty) response — which the widget's
    // fetch(...).then(r => r.json()) surfaced as "Network error. Please
    // try again." even though the real problem was a server-side crash,
    // not a network issue.
    //
    // AUDIT-FIX (Events + Seats — capacity ceiling): mirrors the identical
    // fix already made to Appointments' Slot_Generator/Booking_Service —
    // when a published seat plan is connected to this event, the venue's
    // real seat count is the true ceiling, not (only) the event's own
    // `capacity` field, which an admin could easily leave blank/unlimited
    // or set higher than the room actually seats. Effective capacity is
    // the SMALLER of the two when both are set; the seat plan's count
    // applies on its own when the event's own capacity is 0 (unlimited).
    public static function has_capacity( int $event_id, int $qty ) : bool {
        $event = Event_Repository::find( $event_id );
        if ( ! $event ) return false;

        // AUDIT-FIX (Concurrency): check if staff is busy with an Appointment.
        if ( $event->staff_id > 0 && class_exists( '\CredoqAppointments\Booking_Repository' ) ) {
            $appointments = \CredoqAppointments\Booking_Repository::find_for_staff_in_range(
                (int) $event->staff_id, $event->start_datetime, $event->end_datetime
            );
            $ev_start = strtotime( $event->start_datetime );
            $ev_end   = strtotime( $event->end_datetime );
            $date     = substr( $event->start_datetime, 0, 10 );

            foreach ( $appointments as $apt ) {
                $apt_start = strtotime( $date . ' ' . $apt->selected_time );
                $apt_end   = $apt_start + (int) $apt->duration * 60;
                if ( $ev_start < $apt_end && $ev_end > $apt_start ) return false;
            }
        }

        $event_cap = (int) $event->capacity; // 0 = unlimited
        $seat_cap  = self::seat_plan_capacity( $event_id );

        if ( $seat_cap > 0 ) {
            $effective_cap = $event_cap > 0 ? min( $event_cap, $seat_cap ) : $seat_cap;
        } elseif ( $event_cap > 0 ) {
            $effective_cap = $event_cap;
        } else {
            return true; // no seat plan, no event capacity — genuinely unlimited
        }

        $booked = Event_Repository::booked_count( $event_id );
        return ( $booked + max( 1, $qty ) ) <= $effective_cap;
    }

    /** Seat count of the published seat plan connected to this event, or 0 if none/not applicable. */
    private static function seat_plan_capacity( int $event_id ) : int {
        $plan = self::resolve_seat_plan( $event_id );
        return $plan ? (int) ( $plan->capacity_limit ?: $plan->total_seats ) : 0;
    }

    /** Public wrapper for Shortcodes.php — 0 if no resolvable connected published plan. */
    public static function connected_seat_plan_id( int $event_id ) : int {
        $plan = self::resolve_seat_plan( $event_id );
        return $plan ? (int) $plan->id : 0;
    }

    /**
     * The one published seat plan connected to this event, or null if
     * there's none or it's ambiguous (>1 connected plan — see
     * Seat_Map_Field::resolve_plan_id_for_event() in credoq-seats, which
     * applies the identical rule for the Forms Builder path).
     */
    private static function resolve_seat_plan( int $event_id ) : ?object {
        if ( ! class_exists( '\CredoqSeats\Repositories\Plan_Repository' ) ) return null;
        $plans = array_values( array_filter(
            \CredoqSeats\Repositories\Plan_Repository::find_for_connection( 'event', $event_id ),
            function ( $p ) { return 'published' === ( $p->status ?? '' ); }
        ) );
        return 1 === count( $plans ) ? $plans[0] : null;
    }

    /**
     * Legacy standalone registration path — powers the [credoq_event_register]
     * shortcode's own modal/AJAX flow (Ajax\Event_Ajax::register_event()),
     * entirely separate from the Forms Builder + Field_Event pipeline.
     *
     * AUDIT-FEATURE (closes the previously-flagged "no seat-map
     * integration" gap): $seat_ids is optional — when the event has a
     * resolvable connected published seat plan AND seats were actually
     * selected in the modal (see Shortcodes.php's credoqSubmitEventReg()),
     * they're validated against that plan, and — mirroring the identical
     * fix already applied to Field_Event::handle_submission() — the real
     * seat count and seat-plan total REPLACE the flat $qty/$event->price
     * for this registration; they are never added on top of it. If the
     * event has no connected plan, or none were selected, this behaves
     * exactly as before (flat qty × price, no seat rows touched).
     */
    public static function register( int $event_id, int $user_id, int $qty, string $guest_name, string $guest_email, int $plan_id = 0, array $seat_ids = [] ) : array {
        // AUDIT-FIX A-6: event_id always absint
        $event_id = absint($event_id);
        $event    = Event_Repository::find($event_id);
        if ( ! $event ) return ['success'=>false,'error'=>'Event not found.'];

        $seat_ids     = array_values( array_unique( array_filter( array_map( 'absint', $seat_ids ) ) ) );
        $seat_plan    = ! empty( $seat_ids ) ? self::resolve_seat_plan( $event_id ) : null;
        $seat_pricing = null;

        if ( ! empty( $seat_ids ) ) {
            if ( ! $seat_plan ) {
                // Seats were submitted but there's no resolvable plan for
                // this event anymore (removed/unpublished/made ambiguous
                // between page load and submit) — reject rather than
                // silently falling back to flat pricing for a "seat"
                // selection that no longer means anything.
                return ['success'=>false,'error'=>'This event\'s seat map is no longer available. Please refresh and try again.'];
            }
            if ( ! class_exists( '\CredoqSeats\Repositories\Booking_Repository' ) ) {
                return ['success'=>false,'error'=>'Seat booking is unavailable right now.'];
            }
            // AUDIT-FIX (never trust the client — same principle as
            // Seat_Map_Field::on_submission()): recompute the real total
            // server-side from each seat's own price (override → its
            // type's plan price → this event's base price), not anything
            // the browser sent.
            $seat_pricing = \CredoqSeats\Repositories\Booking_Repository::calc_seats_breakdown(
                (int) $seat_plan->id, $seat_ids, (float) $event->price
            );
            if ( 0 === $seat_pricing['count'] ) {
                return ['success'=>false,'error'=>'Selected seats are no longer valid.'];
            }
            // Seat count REPLACES the submitted qty — matches the same
            // "replace, don't add" rule applied to Field_Event.
            $qty = $seat_pricing['count'];
        }

        if ( ! self::has_capacity( $event_id, $qty ) ) {
            return ['success'=>false,'error'=>'Not enough spots remaining.'];
        }

        // Credit deduction check
        if ( $plan_id > 0 && $user_id && $event->credit_deduct_enabled && class_exists('\CredoqMembership\Membership_Service') ) {
            $needed = intval($event->credit_deduct_amount) * $qty;
            $status = \CredoqMembership\Membership_Service::get_plan_status($user_id, $plan_id, 0);
            if ( $status['remaining'] < $needed ) {
                return ['success'=>false,'error'=>'Insufficient slot credits.'];
            }
        }

        $use_wc  = $event->price > 0 && $event->wc_product_id > 0;
        $init_st = $use_wc ? 'pending_payment' : 'confirmed';

        $total_price = $seat_pricing ? (float) $seat_pricing['total'] : ( (float) $event->price * absint($qty) );

        $id = Event_Booking_Repository::insert([
            'event_id'    => $event_id,
            'user_id'     => $user_id,
            'guest_name'  => sanitize_text_field($guest_name),
            'guest_email' => sanitize_email($guest_email),
            'quantity'    => absint($qty),
            'total_price' => $total_price,
            'status'      => $init_st,
            'qr_token'    => wp_generate_password( 32, false ),
        ]);
        if ( ! $id ) return ['success'=>false,'error'=>'Registration failed.'];

        // AUDIT-FEATURE: reserve the actual seats. Uses a distinct
        // booking_type ('event_legacy', not 'event') so ref_id never
        // collides with the Forms Builder path's ref_id, which is a
        // *submission* id from a completely different table/id-space —
        // this flow has no submission at all to key off of. Seats confirm
        // immediately here regardless of $init_st (even for the
        // pending_payment/WC case) — this is the reservation act itself,
        // matching the same design already used for Field_Event (see its
        // handle_submission() docblock): seats are held through checkout,
        // then released via cancel() above if payment fails/is cancelled.
        if ( $seat_plan && ! empty( $seat_ids ) ) {
            $date = ! empty( $event->start_datetime ) ? substr( $event->start_datetime, 0, 10 ) : current_time( 'Y-m-d' );
            \CredoqSeats\Repositories\Booking_Repository::confirm_seats( (int) $seat_plan->id, $seat_ids, [
                'booking_type' => 'event_legacy',
                'ref_id'       => $id,
                'event_id'     => $event_id,
                'date'         => $date,
                'time'         => '',
                'user_id'      => $user_id,
                'guest_email'  => sanitize_email( $guest_email ),
                'price_map'    => $seat_pricing['price_map'],
            ] );
        }

        // Credit deduction
        if ( ! $use_wc && $plan_id > 0 && $user_id && $event->credit_deduct_enabled && class_exists('\CredoqMembership\Membership_Service') ) {
            $deduct_amt = intval($event->credit_deduct_amount) * $qty;
            \CredoqMembership\Membership_Service::deduct_credit(
                $user_id, $plan_id, $deduct_amt,
                'Event: '.$event->title, $id
            );
            // Record how many credits were deducted in the booking row itself.
            Event_Booking_Repository::update_status($id, $init_st, ['credit_deducted' => $deduct_amt]);
        }

        if ( ! $use_wc ) {
            do_action('credoq_event_booking_confirmed', $id);
        }

        $wc_cart_url = '';
        if ( $use_wc && function_exists('WC') ) {
            WC()->cart->add_to_cart($event->wc_product_id, $qty, 0, [], [
                '_credoq_event_booking_id' => $id,
            ]);
            $wc_cart_url = wc_get_cart_url();
        }

        return ['success'=>true,'booking_id'=>$id,'use_wc'=>$use_wc,'wc_cart_url'=>$wc_cart_url];
    }

    public static function cancel( int $booking_id ) : bool {
        $b = Event_Booking_Repository::find($booking_id);
        if ( ! $b ) return false;
        Event_Booking_Repository::update_status($booking_id, 'cancelled');

        // Refund membership credits if any were deducted.
        if ( (int) $b->credit_deducted > 0 && class_exists( '\CredoqMembership\Membership_Service' ) ) {
            global $wpdb;
            $ledger_table = $wpdb->prefix . 'credoq_credit_ledger';
            $entry = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$ledger_table} WHERE ref_id = %d AND type = 'use' LIMIT 1",
                $booking_id
            ) );
            if ( $entry ) {
                \CredoqMembership\Membership_Service::refund_credit(
                    (int) $b->user_id,
                    (int) $entry->plan_id,
                    (int) $b->credit_deducted,
                    'Refund: ' . $b->guest_name,
                    $booking_id
                );
            }
        }

        // AUDIT-FEATURE: release any seats reserved through register()'s
        // seat-map integration above — see the 'event_legacy' booking_type
        // note there for why this uses a distinct ref_id space from the
        // Forms Builder path's Events_Bridge::on_cancelled().
        if ( class_exists( '\CredoqSeats\Repositories\Booking_Repository' ) ) {
            \CredoqSeats\Repositories\Booking_Repository::cancel_for_ref( 'event_legacy', $booking_id );
        }

        do_action('credoq_event_booking_cancelled', $booking_id);
        return true;
    }
}
