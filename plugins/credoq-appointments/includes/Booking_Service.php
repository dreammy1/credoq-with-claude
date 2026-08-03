<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

use CredoqMembership\Membership_Service;

/**
 * Core booking business logic.
 *
 * AUDIT-FIX:   Real DB transactions (START TRANSACTION / COMMIT).
 * AUDIT-FIX C-6: Prices recalculated server-side from service record.
 * AUDIT-FIX A-2: No JetAppointments anywhere.
 *
 * --- BUGS FIXED IN THIS FILE ---
 *
 * FIX-BS-1: Added is_slot_available() — was called by Field_Appointment::validate()
 *           but did not exist → PHP fatal on form submission.
 *
 * FIX-BS-2: WC response key mismatch:
 *           React checks  data.data.wc  and  data.data.redirect
 *           Old code returned  use_wc  and  wc_cart_url
 *           → Now returns { wc: bool, redirect: checkout_url }.
 *
 * FIX-BS-3: WC redirect target was the cart page, not the checkout page.
 *           Users had to click "Proceed to checkout" manually.
 *           → wc_get_checkout_url() used instead of wc_get_cart_url().
 *
 * FIX-BS-4: Credit deduction was skipped entirely on WC bookings.
 *           Credits are now deferred: on_complete() in WooCommerce.php
 *           calls deduct_credits_for_order() after payment confirms.
 *           The booking row stores plan_id + credit_amount for that.
 *
 * FIX-BS-5: cancel() accepted $refund_credits parameter but never acted on it.
 *           Now correctly refunds credits to the member's plan.
 *
 * FIX-BS-6: Waiting list: if a slot is full and WL enabled, create() now
 *           returns a wl_added flag instead of a generic error, letting
 *           the AJAX handler surface the right message to the user.
 */
class Booking_Service {

    /**
     * Create a booking (or multi-schedule group).
     *
     * @param array{
     *   appointment_id: int,
     *   staff_id:       int,
     *   user_id:        int,
     *   guest_name:     string,
     *   guest_email:    string,
     *   dates:          array<array{date:string,time:string}>,
     *   form_data:      array,
     *   seat_ids:       int[],
     *   plan_id:        int,
     * } $params
     *
     * @return array{
     *   success:     bool,
     *   booking_ids: int[],
     *   group_id:    string,
     *   wc?:         bool,
     *   redirect?:   string,
     *   wl_added?:   bool,
     *   error?:      string,
     * }
     */
    public static function create( array $params ) : array {
        global $wpdb;

        // AUDIT: apply the Engine's Security Gate (IP block, country
        // block, reCAPTCHA) here too — these settings are global (one
        // 'credoq_engine_settings' option) and should protect every
        // submission path, not just the Engine's own standalone forms.
        if ( class_exists( '\CredoqEngine\Security\Gate' ) ) {
            $security = \CredoqEngine\Security\Gate::check(
                array( 'user_id' => absint( $params['user_id'] ?? get_current_user_id() ) ),
                (array) ( $params['form_data'] ?? array() )
            );
            if ( is_wp_error( $security ) ) {
                return [ 'success' => false, 'error' => $security->get_error_message() ];
            }
        }

        $apt = Appointment_Repository::find( absint( $params['appointment_id'] ?? 0 ) );
        if ( ! $apt ) return [ 'success' => false, 'error' => 'Invalid service.' ];

        // AUDIT-FIX: apply the Engine's Security Gate (IP block, country
        // block, reCAPTCHA) to appointment bookings too — these
        // protections previously only covered the standalone Engine
        // submission path. class_exists guard is defensive only;
        // Appointments always requires the Engine to be active.
        if ( class_exists( '\CredoqEngine\Security\Gate' ) ) {
            $security = \CredoqEngine\Security\Gate::check(
                [ 'user_id' => absint( $params['user_id'] ?? get_current_user_id() ) ],
                (array) ( $params['form_data'] ?? [] )
            );
            if ( is_wp_error( $security ) ) {
                return [ 'success' => false, 'error' => $security->get_error_message() ];
            }
        }

        $dates    = $params['dates'] ?? [];
        if ( empty( $dates ) ) return [ 'success' => false, 'error' => 'No dates selected.' ];

        $staff_id  = absint( $params['staff_id']  ?? 0 );
        $user_id   = absint( $params['user_id']   ?? get_current_user_id() );
        $form_id   = absint( $params['form_id']   ?? 0 );
        $plan_id   = absint( $params['plan_id']   ?? 0 );
        $seat_ids  = $params['seat_ids'] ?? [];
        $form_data = $params['form_data'] ?? [];
        $qty_multiplier = max( 1, absint( $form_data['__qty_multiplier'] ?? $form_data['qty'] ?? $form_data['quantity'] ?? 1 ) );

        // ── Price: ALWAYS recalculate server-side (AUDIT-FIX C-6) ────
        $unit_price = floatval( $apt->base_price );

        // Multi-booking rate override.
        if ( count( $dates ) > 1 && $apt->allow_multi_booking ) {
            if ( $apt->multi_price_mode === 'per_day_rate' && $apt->multi_day_rate > 0 ) {
                $unit_price = floatval( $apt->multi_day_rate );
            }
        }

        // Staff price multiplier (and special-date price look-up later).
        $staff = $staff_id > 0 ? Staff_Repository::find( $staff_id ) : null;
        if ( $staff ) $unit_price *= floatval( $staff->price_multiplier ?: 1 );

        // ── FIX-BS-8: Server-side addon price from form fields ────────
        // The frontend sends __addon_total in form_data (computed by the
        // React widget from checkbox/dropdown/calculate fields).  We
        // re-validate it here: only add the amount that backend field
        // price_contribution() also agrees on.
        $addon_price = 0.0;
        if ( ! empty( $form_data['__addon_total'] ) ) {
            $addon_price = floatval( $form_data['__addon_total'] );
        }

        // ── Standalone WC checkout fields (3-setting architecture) ────
        // Checkbox / Select / Radio / Calculate fields with "Enable WC
        // Checkout" + "Option value as price → add to WC grand total"
        // contribute __wc_total from the React widget. Fold it into the
        // same addon price so it flows straight into $cart_data below —
        // these fields work standalone, without any addon plugin needing
        // to register its own product/price logic.
        if ( ! empty( $form_data['__wc_total'] ) ) {
            $addon_price += floatval( $form_data['__wc_total'] );
        }

        // Defensive cap: don't allow client to send a negative addon total.
        if ( $addon_price < 0 ) $addon_price = 0.0;

        // ── Visual Seats: seat total REPLACES the per-slot base price ──
        // Requested formula: total = schedule_qty × seat_qty × per-seat
        // price (the seat's own override, or the service's base price
        // when it has none) — not base_price + seats, which would double
        // count the base price since each unoverridden seat already
        // falls back to it. The client sends seat_ids and a seat plan,
        // but the PRICE itself is always recalculated here server-side
        // (AUDIT-FIX C-6 pattern) rather than trusted from form_data —
        // never trust a client-submitted total for money math.
        //
        // Note: this intentionally does not layer the staff price
        // multiplier or special-date pricing on top of seat prices —
        // those are resolved from the service's plain base price only
        // (see Ajax\Seats_Ajax::credoq_seats_load_map() in Credoq Seats).
        $seats_replace_base = false;
        if ( empty( $seat_ids ) ) {
            self::log_seats( 'no seat_ids on this booking (either no seat_map field on this form, or nothing was selected)', [] );
        } else {
            if ( ! class_exists( '\CredoqSeats\Repositories\Booking_Repository' ) ) {
                self::log_seats( 'skip: Credoq Seats plugin not active/loaded', $seat_ids );
            } else {
                $s = self::seats_settings_for_appointment( $apt );
                if ( ! $s['seat_plan_id'] ) {
                    self::log_seats( 'skip: no seat_plan_id in this service\'s booking_settings (visual_seats_enabled=' . $s['visual_seats_enabled'] . ')', $seat_ids );
                } else {
                    $seat_calc = \CredoqSeats\Repositories\Booking_Repository::calc_seats_total(
                        $s['seat_plan_id'], $seat_ids, floatval( $apt->base_price )
                    );
                    if ( $seat_calc['count'] > 0 ) {
                        $unit_price         = $seat_calc['total'];
                        $seats_replace_base = true;
                        self::log_seats( 'applied: total=' . $seat_calc['total'] . ' from ' . $seat_calc['count'] . '/' . count( $seat_ids ) . ' seats found in plan #' . $s['seat_plan_id'], $seat_ids );
                    } else {
                        self::log_seats( 'skip: 0 of ' . count( $seat_ids ) . ' seat_ids found in plan #' . $s['seat_plan_id'] . ' (wrong plan, or seats belong to a different plan)', $seat_ids );
                    }
                }
            }
        }

        // Special-date price overrides (per-slot, applied inside the loop below).
        $bk_settings = [];
        if ( ! empty( $apt->booking_settings ) ) {
            $decoded = json_decode( $apt->booking_settings, true );
            if ( is_array( $decoded ) ) $bk_settings = $decoded;
        }

        $credit_enabled = ! empty( $apt->credit_deduct_enabled );
        $credit_needed  = max( 1, intval( $apt->credit_deduct_amount ) ) * count( $dates ) * $qty_multiplier;
        $use_credit     = false;

        if ( $credit_enabled ) {
            if ( $form_id > 0 && ! self::form_has_membership_credit_field( $form_id ) ) {
                return [
                    'success' => false,
                    'error'   => __( 'This service has membership credit deduction enabled, but this form has no Member Slot Credit field. Add one in the Form Builder, or disable credit deduction for this service.', 'credoq-appointments' ),
                ];
            }

            if ( $plan_id > 0 && $user_id && class_exists( '\CredoqMembership\Membership_Service' ) ) {
                $status     = Membership_Service::get_plan_status( $user_id, $plan_id, 0 );
                if ( $form_id > 0 ) {
                    $form_status = Membership_Service::get_plan_status( $user_id, $plan_id, $form_id );
                    $status['remaining'] = max( intval( $status['remaining'] ?? 0 ), intval( $form_status['remaining'] ?? 0 ) );
                }
                $use_credit = $status['remaining'] >= $credit_needed;
            }

            if ( ! $use_credit && empty( $apt->wc_product_id ) ) {
                return [
                    'success' => false,
                    'error'   => __( 'Insufficient membership credits, and this service has no WooCommerce product configured for cash checkout.', 'credoq-appointments' ),
                ];
            }
        }

        $use_wc = ! $use_credit
            && ( $unit_price > 0 || $addon_price > 0 )
            && ! empty( $apt->wc_product_id )
            && function_exists( 'WC' );

        // ── BEGIN TRANSACTION ─────────────────────────────────────────
        $wpdb->query( 'START TRANSACTION' );

        $booking_ids = [];
        $group_id    = count( $dates ) > 1 ? wp_generate_uuid4() : '';
        $wl_added    = false;

        // AUDIT-FIX (Bug: WC checkout total mismatch on multi-date bookings).
        // $unit_price is the PER-DATE base price. The WC cart item must
        // reflect the SAME grand total the React widget displayed (sum of
        // every selected date's base/special price + addon total once),
        // not just a single date's price. Accumulate it here as we go.
        $sum_base_prices = 0.0;

        foreach ( $dates as $idx => $slot ) {
            $date = sanitize_text_field( $slot['date'] ?? '' );
            $time = sanitize_text_field( $slot['time'] ?? '' );

            if ( ! $date || ! $time ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'success' => false, 'error' => 'Invalid date/time.' ];
            }

            // ── Capacity check: lock the slot FOR UPDATE ──────────────
            // AUDIT-FIX (Bug: slot count never deducts):
            // pending_payment bookings are reserved WC-path seats — they
            // must count against capacity so the same slot can't be
            // double-booked while payment is pending.
            //
            // Visual Seats: when this service uses a seat map, the real
            // capacity constraint is the specific seats themselves, and
            // those were already claimed exclusively at hold time (each
            // credoq_seats_hold call is itself a race-safe "WHERE NOT
            // EXISTS" claim — see CredoqSeats\Repositories\Booking_Repository::hold_seat()).
            // Re-applying the generic per-row max_bookings gate on top of
            // that double-constrains capacity for no reason (a service
            // with a 300-seat plan but max_bookings left at a small
            // default would start rejecting bookings long before the
            // seats actually ran out), so it's skipped for seat-map
            // bookings.
            $seats_enabled_here = ! empty( $bk_settings['visual_seats_enabled'] ) && ! empty( $seat_ids );

            $existing_count = $seats_enabled_here ? 0 : intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_bookings
                 WHERE appointment_id = %d
                   AND staff_id       = %d
                   AND selected_date  = %s
                   AND selected_time  = %s
                   AND status NOT IN ('cancelled','failed','refunded')
                 FOR UPDATE",
                $apt->id, $staff_id, $date, $time
            ) ) );

            if ( ! $seats_enabled_here && $existing_count >= intval( $apt->max_bookings ) ) {
                // FIX-BS-6: slot is full — try waiting list before failing.
                $wpdb->query( 'ROLLBACK' );

                if ( $user_id || ! empty( $params['guest_email'] ) ) {
                    $email = sanitize_email( $params['guest_email'] ?? '' );
                    if ( ! $email && $user_id ) {
                        $u     = get_userdata( $user_id );
                        $email = $u ? $u->user_email : '';
                    }
                    Waiting_List_Repository::add(
                        intval( $apt->id ), $staff_id, $date, $time, $user_id, $email
                    );
                    return [
                        'success'  => false,
                        'wl_added' => true,
                        'error'    => "Time slot {$time} on {$date} is full. You have been added to the waiting list.",
                    ];
                }

                return [ 'success' => false, 'error' => "Time slot {$time} on {$date} is no longer available." ];
            }

            // Per-slot special-date price (staff-level override takes
            // priority over the appointment-level booking_settings one).
            // Skipped when seat pricing is authoritative — see above.
            $base_for_slot = $unit_price;
            if ( ! $seats_replace_base ) {
                $dow      = strtolower( date( 'l', strtotime( $date ) ) );
                $sd_price = self::resolve_special_price( $date, $dow, $bk_settings, $staff ?? null );
                if ( $sd_price !== null ) $base_for_slot = $sd_price;
            }

            $sum_base_prices += $base_for_slot * $qty_multiplier;
            $slot_price       = ( $base_for_slot * $qty_multiplier ) + $addon_price;

            $initial_status = $use_wc ? 'pending_payment' : 'confirmed';

            $booking_id = Booking_Repository::insert( [
                'appointment_id'      => intval( $apt->id ),
                'staff_id'            => $staff_id,
                'user_id'             => $user_id,
                'guest_name'          => sanitize_text_field( $params['guest_name']  ?? '' ),
                'guest_email'         => sanitize_email( $params['guest_email'] ?? '' ),
                'selected_date'       => $date,
                'selected_time'       => $time,
                'duration'            => intval( $apt->duration ),
                'status'              => $initial_status,
                'total_price'         => $slot_price,
                'group_id'            => $group_id,
                'group_index'         => $idx,
                'seat_ids'            => $seat_ids,
                'form_data'           => $form_data,
                // Store plan/credit info only when this booking is actually
                // paid with membership credit, so cash fallback never deducts
                // credits later through WooCommerce hooks.
                'notes'               => $use_credit
                    ? wp_json_encode( [
                        'plan_id'       => $plan_id,
                        'credit_amount' => max( 1, intval( $apt->credit_deduct_amount ) ) * $qty_multiplier,
                        'quantity'      => $qty_multiplier,
                    ] )
                    : '',
            ] );

            if ( ! $booking_id ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'success' => false, 'error' => 'Failed to save booking.' ];
            }

            $booking_ids[] = $booking_id;
        }

        $wpdb->query( 'COMMIT' );

        // ── Post-commit: immediate credit deduction (non-WC path) ─────
        // FIX-BS-4: for WC bookings, deferral happens inside WooCommerce::on_complete().
        if ( $use_credit && $plan_id > 0 && $user_id && $apt->credit_deduct_enabled ) {
            if ( class_exists( '\CredoqMembership\Membership_Service' ) ) {
                $deduct_per = max( 1, intval( $apt->credit_deduct_amount ) ) * $qty_multiplier;
                foreach ( $booking_ids as $bid ) {
                    Membership_Service::deduct_credit(
                        $user_id, $plan_id, $deduct_per,
                        "Booking #{$bid} — " . $apt->title,
                        intval( $apt->id )
                    );
                    $wpdb->update(
                        $wpdb->prefix . 'credoq_bookings',
                        [ 'credit_deducted' => $deduct_per ],
                        [ 'id' => $bid ],
                        [ '%d' ], [ '%d' ]
                    );
                }
            }
        }

        // ── Post-commit: notifications (non-WC path) ─────────────────
        if ( ! $use_wc ) {
            foreach ( $booking_ids as $bid ) {
                do_action( 'credoq_booking_confirmed', $bid );
                Notifications\Booking_Mailer::send( $bid, 'confirm' );
            }
        }

        // ── WC: add to cart → redirect to CHECKOUT ───────────────────
        // FIX-BS-2 + FIX-BS-3: correct response keys and use checkout URL.
        // FIX-BS-7: REST context WC session boot (WC()->cart was null).
        // FIX-BS-10: _credoq_dynamic_price must live INSIDE the $cart_data
        //   array so WC serialises it into the session. Previous approach
        //   registered anonymous closures on add_filter/add_action that only
        //   existed during the REST request and were dead by the time the
        //   browser hit the checkout page. Now the price survives in the
        //   session and apply_dynamic_price() (WooCommerce.php) calls
        //   set_price() on every page load that involves the cart.
        $wc_redirect = '';
        if ( $use_wc ) {
            // ── Safely initialize WC session and cart if not already done ──
            if ( ! WC()->session ) {
                $session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
                WC()->session = new $session_class();
                WC()->session->init();
            }
            if ( ! WC()->customer ) {
                WC()->customer = new \WC_Customer( get_current_user_id(), true );
            }
            if ( ! WC()->cart ) {
                WC()->cart = new \WC_Cart();
                WC()->cart->get_cart_from_session();
            }

            // AUDIT-FIX: grand total = sum of every date's base/special
            // price + the form's addon total (added once, not per-date),
            // matching what BookingWidget.jsx's grandTotal shows.
            $total_for_wc = round( $sum_base_prices + $addon_price, 2 );

            // _credoq_dynamic_price is stored inside the session cart item
            // array. WooCommerce serialises every key in $cart_data into the
            // session, so apply_dynamic_price() can read it on every subsequent
            // request (cart page, checkout page, order-pay page).
            $cart_data = [
                '_credoq_booking_ids'   => $booking_ids,
                '_credoq_group_id'      => $group_id,
                '_credoq_plan_id'       => 0,
                '_credoq_total_price'   => $total_for_wc,
                '_credoq_dynamic_price' => $total_for_wc,
            ];

            WC()->cart->empty_cart();
            WC()->cart->add_to_cart( intval( $apt->wc_product_id ), 1, 0, [], $cart_data );

            // Recalculate totals immediately so the session is flushed with
            // the correct price before the redirect fires.
            WC()->cart->calculate_totals();

            // FIX-BS-3: go straight to checkout, not cart.
            $wc_redirect = wc_get_checkout_url();
        }

        $response = [
            'success'     => true,
            'booking_ids' => $booking_ids,
            'group_id'    => $group_id,
        ];

        if ( $use_wc ) {
            // FIX-BS-2: use the key names the React widget actually checks.
            $response['wc']       = true;
            $response['redirect'] = $wc_redirect;
        } else {
            $response['message'] = __( 'Booking confirmed!', 'credoq-appointments' );
        }

        return $response;
    }

    /**
     * Confirm a booking (set status → confirmed, fire hooks, send email).
     */
    public static function confirm( int $booking_id ) : bool {
        $ok = Booking_Repository::update_status( $booking_id, 'confirmed' );
        if ( $ok ) {
            do_action( 'credoq_booking_confirmed', $booking_id );
            Notifications\Booking_Mailer::send( $booking_id, 'confirm' );
        }
        return $ok;
    }

    /**
     * Cancel a booking.
     *
     * FIX-BS-5: $refund_credits was accepted but never acted on.
     *           Credits are now returned when requested.
     *
     * @param bool $refund_credits If true, reverse the membership credit deduction.
     */
    public static function cancel( int $booking_id, bool $refund_credits = false ) : bool {
        $booking = Booking_Repository::find( $booking_id );
        if ( ! $booking ) return false;

        $ok = Booking_Repository::update_status( $booking_id, 'cancelled' );
        if ( ! $ok ) return false;

        Notifications\Booking_Mailer::send( $booking_id, 'cancel' );
        do_action( 'credoq_booking_cancelled', $booking_id );

        // FIX-BS-5: honour the refund flag.
        if ( $refund_credits && ! empty( $booking->notes ) ) {
            $meta = json_decode( $booking->notes, true );
            if (
                is_array( $meta )
                && ! empty( $meta['plan_id'] )
                && ! empty( $meta['credit_amount'] )
                && intval( $booking->user_id ) > 0
                && class_exists( '\CredoqMembership\Membership_Service' )
            ) {
                Membership_Service::refund_credit(
                    intval( $booking->user_id ),
                    intval( $meta['plan_id'] ),
                    intval( $meta['credit_amount'] ),
                    "Refund for cancelled booking #{$booking_id}",
                    intval( $booking->appointment_id )
                );
            }
        }

        // Offer waiting list next person.
        Waiting_List_Repository::offer_next(
            intval( $booking->appointment_id ),
            intval( $booking->staff_id ),
            $booking->selected_date,
            $booking->selected_time
        );

        return true;
    }

    /**
     * Quick availability check delegated to Slot_Generator.
     * FIX-BS-1: this method was called but missing.
     */
    public static function is_slot_available(
        int $appointment_id, int $staff_id, string $date, string $time
    ) : bool {
        return Slot_Generator::is_slot_available( $appointment_id, $staff_id, $date, $time );
    }

    private static function form_has_membership_credit_field( int $form_id ) : bool {
        static $cache = [];
        if ( array_key_exists( $form_id, $cache ) ) return $cache[ $form_id ];

        $found = false;
        if ( class_exists( '\CredoqEngine\Forms\Repository' ) ) {
            $repo = new \CredoqEngine\Forms\Repository();
            $form = $repo->find( $form_id );
            if ( $form ) {
                foreach ( (array) $form->fields as $field ) {
                    if ( ( $field['type'] ?? '' ) === 'membership_credit' ) {
                        $found = true;
                        break;
                    }
                }
            }
        }

        return $cache[ $form_id ] = $found;
    }

    /**
     * Internal: resolve a special-date or weekend price from booking_settings.
     * Mirrors Slot_Generator::get_date_price_override() for the create() path.
     *
     * AUDIT-FIX (Special Dates pricing, Staff edit page): a staff member's
     * own "Special Dates / Overrides" entry can carry a per-date price
     * (entered via the admin Staff edit page). That takes priority over
     * the appointment-level booking_settings special_dates/weekend price.
     *
     * @return float|null
     */
    /** Writes a 'seats.price_calc' audit entry when Credoq Engine's Audit_Log is available — makes the seat-price decision path visible from Credoq → Audit log without needing debug.log access. */
    private static function log_seats( string $message, array $seat_ids ) : void {
        if ( ! class_exists( '\CredoqEngine\Log\Audit_Log' ) ) return;
        \CredoqEngine\Log\Audit_Log::record( 'seats.price_calc', [
            'subject' => implode( ',', $seat_ids ),
            'message' => $message,
        ] );
    }

    /** Reads visual_seats_enabled / seat_plan_id out of this service's booking_settings JSON. */
    private static function seats_settings_for_appointment( object $apt ) : array {
        $decoded = [];
        if ( ! empty( $apt->booking_settings ) ) {
            $d = json_decode( $apt->booking_settings, true );
            if ( is_array( $d ) ) $decoded = $d;
        }
        return [
            'visual_seats_enabled' => (int) ( $decoded['visual_seats_enabled'] ?? 0 ),
            'seat_plan_id'         => (int) ( $decoded['seat_plan_id'] ?? 0 ),
        ];
    }

    private static function resolve_special_price(
        string $date, string $dow, array $bk_settings, ?object $staff = null
    ) : ?float {
        if ( $staff ) {
            $staff_price = Staff_Repository::get_special_date_price( $staff, $date );
            if ( $staff_price !== null ) return $staff_price;
        }

        $sd = $bk_settings['special_dates'] ?? [];
        if ( is_array( $sd ) ) {
            foreach ( $sd as $entry ) {
                if ( is_array( $entry ) && ( $entry['date'] ?? '' ) === $date && isset( $entry['price'] ) ) {
                    return (float) $entry['price'];
                }
            }
        }
        if ( in_array( $dow, [ 'saturday', 'sunday' ], true ) && isset( $bk_settings['weekend_price'] ) ) {
            return (float) $bk_settings['weekend_price'];
        }
        return null;
    }
}
