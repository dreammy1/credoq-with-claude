<?php
/**
 * Field_Event — full interactive Event Calendar field type.
 *
 * Returns an 'event_calendar' frontend descriptor containing all published
 * events grouped by calendar date so the React widget can render:
 *   1. A mini calendar with event-badge highlights per date
 *   2. On date-click: checkbox event list with inline qty + capacity badge
 *   3. Dynamic total price as events/qty are selected
 *   4. On submit: JSON array of {event_id, quantity, price} per selection
 *
 * @package CredoqEvents\Fields
 */

namespace CredoqEvents\Fields;

defined( 'ABSPATH' ) || exit;

use CredoqEngine\Abstracts\Field_Type;

class Field_Event extends Field_Type {

    public function get_slug()     : string { return 'event_registration'; }
    public function get_label()    : string { return __( 'Event Calendar', 'credoq-events' ); }
    public function get_icon()     : string { return 'ticket'; }
    public function get_category() : string { return 'events'; }
    public function get_addon_id() : string { return 'credoq-events'; }

    /* ── Frontend descriptor ─────────────────────────────────────── */

    public function get_frontend_render( array $field_config ) : array {
        global $wpdb;
        $table = $wpdb->prefix . 'credoq_events';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [ 'component' => 'display',
                     'props'     => [ 'text' => __( 'Events table not found.', 'credoq-events' ) ] ];
        }

        $max_tickets = max( 1, (int)( $field_config['max_tickets'] ?? 10 ) );

        // Load all upcoming published events once, grouped by calendar date.
        $rows = $wpdb->get_results(
            "SELECT id, title, description, start_datetime, end_datetime,
                    location, price, capacity, accent_color, image_url,
                    credit_deduct_enabled, credit_deduct_amount
             FROM {$table}
             WHERE status = 'published' AND start_datetime >= NOW()
             ORDER BY start_datetime ASC LIMIT 200"
        );

        if ( empty( $rows ) ) {
            return [ 'component' => 'display',
                     'props'     => [ 'text' => __( 'No upcoming events available.', 'credoq-events' ) ] ];
        }

        // Booked counts — single query for all events.
        $btable   = $wpdb->prefix . 'credoq_event_bookings';
        $ids      = implode( ',', array_map( 'intval', array_column( (array)$rows, 'id' ) ) );
        $booked_q = $wpdb->get_results(
            "SELECT event_id, SUM(quantity) AS booked
             FROM {$btable}
             WHERE event_id IN ({$ids}) AND status NOT IN ('cancelled','refunded')
             GROUP BY event_id"
        );
        $booked_map = [];
        foreach ( (array)$booked_q as $bk ) {
            $booked_map[ (int)$bk->event_id ] = (int)$bk->booked;
        }

        // Group by Y-m-d date for the calendar.
        $by_date = [];
        $events  = [];
        foreach ( $rows as $ev ) {
            $date = date( 'Y-m-d', strtotime( $ev->start_datetime ) );
            $booked     = $booked_map[ (int)$ev->id ] ?? 0;
            $remaining  = (int)$ev->capacity > 0 ? max( 0, (int)$ev->capacity - $booked ) : null;
            $max_qty    = $remaining !== null ? min( $max_tickets, $remaining ) : $max_tickets;

            $ev_data = [
                'id'          => (int)$ev->id,
                'title'       => (string)$ev->title,
                'date'        => $date,
                'start'       => date( 'H:i', strtotime( $ev->start_datetime ) ),
                'end'         => $ev->end_datetime ? date( 'H:i', strtotime( $ev->end_datetime ) ) : '',
                'location'    => (string)$ev->location,
                'price'       => (float)$ev->price,
                'capacity'    => (int)$ev->capacity,
                'remaining'   => $remaining,
                'max_qty'     => $max_qty,
                'color'       => (string)( $ev->accent_color ?: '#4f46e5' ),
                'image_url'   => (string)$ev->image_url,
                'description' => (string)$ev->description,
                // AUDIT-FEATURE (membership credit): so the widget can show
                // "N credit(s) will be used" in the Review step instead of
                // just the raw Member Slot Credit field value (a plan id).
                'credit_deduct_enabled' => (bool) $ev->credit_deduct_enabled,
                'credits_per_ticket'    => max( 1, (int) $ev->credit_deduct_amount ),
            ];
            $by_date[ $date ][] = $ev_data;
            $events[ (int)$ev->id ] = $ev_data;
        }

        return [
            'component' => 'event_calendar',
            'props'     => [
                'events'       => array_values( $events ),
                'by_date'      => $by_date,
                'max_tickets'  => $max_tickets,
                'currency_sym' => function_exists('get_woocommerce_currency_symbol')
                                    ? get_woocommerce_currency_symbol() : '$',
            ],
        ];
    }

    /* ── Builder settings schema ─────────────────────────────────── */

    public function get_settings_schema() : array {
        return [
            [ 'key'=>'label',        'type'=>'text',     'label'=>__('Field label','credoq-events'),                   'default'=>__('Event Registration','credoq-events') ],
            [ 'key'=>'max_tickets',  'type'=>'number',   'label'=>__('Max tickets per booking (0 = unlimited)','credoq-events'), 'default'=>10 ],
            [ 'key'=>'show_capacity','type'=>'checkbox', 'label'=>__('Show remaining capacity to customers','credoq-events'), 'default'=>true ],
            [ 'key'=>'required',     'type'=>'checkbox', 'label'=>__('Required','credoq-events'), 'default'=>true ],
        ];
    }

    /* ── Value handling ──────────────────────────────────────────── */

    /**
     * Value is now a JSON array: [{event_id, quantity, price}, …]
     * (one entry per event selected in the calendar).
     */
    public function sanitize( $value, array $field_config ) {
        if ( ! is_string( $value ) ) return '';
        $decoded = json_decode( wp_unslash( $value ), true );

        // Legacy: single-event {event_id, quantity}
        if ( is_array( $decoded ) && isset( $decoded['event_id'] ) ) {
            return wp_json_encode( [ [
                'event_id' => absint( $decoded['event_id'] ),
                'quantity' => max( 1, absint( $decoded['quantity'] ?? 1 ) ),
                'price'    => (float)( $decoded['price'] ?? 0 ),
            ] ] );
        }

        // New: array of selections
        if ( ! is_array( $decoded ) || ! array_key_exists( 0, $decoded ) ) return '';
        $clean = [];
        foreach ( $decoded as $sel ) {
            if ( empty( $sel['event_id'] ) ) continue;
            $clean[] = [
                'event_id' => absint( $sel['event_id'] ),
                'quantity' => max( 1, absint( $sel['quantity'] ?? 1 ) ),
                'price'    => (float)( $sel['price'] ?? 0 ),
            ];
        }
        return $clean ? wp_json_encode( $clean ) : '';
    }

    public function validate( $value, array $field_config, array $submission ) {
        $parent = parent::validate( $value, $field_config, $submission );
        if ( is_wp_error( $parent ) ) return $parent;
        if ( '' === $value ) return true;

        // AUDIT-FIX (Network error still occurring): any uncaught
        // \Throwable here (fatal PHP Error, not just a WP_Error) would
        // previously abort the AJAX request mid-response, so the browser
        // received a broken/non-JSON body — surfaced client-side as the
        // generic "Network error. Please try again." with zero detail.
        // Catching everything here converts even a genuine bug into a
        // proper, readable WP_Error so it never looks like a network
        // problem again, and the message tells us exactly what broke.
        try {
            return $this->validate_selections( $value, $field_config, $submission );
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'event_validate_exception', sprintf(
                /* translators: %s = exception message */
                __( 'Event Registration validation error: %s', 'credoq-events' ), $e->getMessage()
            ) );
        }
    }

    private function validate_selections( $value, array $field_config, array $submission ) {
        $selections = json_decode( $value, true );
        if ( ! is_array( $selections ) ) return new \WP_Error( 'invalid_event', __( 'Please select at least one event.', 'credoq-events' ) );

        // Normalise legacy single-event
        if ( isset( $selections['event_id'] ) ) $selections = [ $selections ];

        $max = (int)( $field_config['max_tickets'] ?? 0 );
        foreach ( $selections as $sel ) {
            $qty = max( 1, (int)( $sel['quantity'] ?? 1 ) );
            if ( $max > 0 && $qty > $max ) {
                return new \WP_Error( 'too_many_tickets', sprintf( __( 'Maximum %d ticket(s) per event.', 'credoq-events' ), $max ) );
            }
            if ( class_exists( '\\CredoqEvents\\Event_Service' ) ) {
                if ( ! \CredoqEvents\Event_Service::has_capacity( (int)$sel['event_id'], $qty ) ) {
                    return new \WP_Error( 'no_capacity', __( 'Not enough spots available for one or more selected events.', 'credoq-events' ) );
                }
            }

            // AUDIT-FEATURE (membership credit case c): "Enable membership
            // credit deduction" is configured per EVENT (Events → edit
            // event → Capacity & Pricing), not per form field. If a
            // selected event has it turned on, the form this field lives
            // in must also contain a Member Slot Credit field (Credoq
            // Membership addon) — that's what identifies the customer's
            // plan/credit context. Catch the misconfiguration here with a
            // clear message instead of quietly deducting nothing or
            // silently falling back to WC with no explanation.
            if ( class_exists( '\\CredoqEvents\\Event_Repository' ) ) {
                $event = \CredoqEvents\Event_Repository::find( (int)$sel['event_id'] );
                if ( $event && ! empty( $event->credit_deduct_enabled ) ) {
                    $form_id = absint( $submission['form_id'] ?? 0 );
                    if ( $form_id && ! $this->form_has_membership_credit_field( $form_id ) ) {
                        return new \WP_Error(
                            'membership_credit_field_missing',
                            sprintf(
                                /* translators: %s = event title */
                                __( '"%s" has membership credit deduction enabled, but this form has no Member Slot Credit field. Add one in the Form Builder, or disable credit deduction on that event.', 'credoq-events' ),
                                $event->title
                            )
                        );
                    }
                }
            }
        }
        return true;
    }

    /**
     * Does the given form contain a Member Slot Credit field
     * ('membership_credit', owned by the Credoq Membership addon)?
     * Memoized per-request since it's checked on every keystroke-triggered
     * validate() call in a multi-step form.
     */
    private function form_has_membership_credit_field( int $form_id ) : bool {
        static $cache = [];
        if ( array_key_exists( $form_id, $cache ) ) return $cache[ $form_id ];

        $found = false;
        if ( class_exists( '\\CredoqEngine\\Forms\\Repository' ) ) {
            $repo = new \CredoqEngine\Forms\Repository();
            $form = $repo->find( $form_id );
            if ( $form ) {
                foreach ( $form->fields as $f ) {
                    if ( ( $f['type'] ?? '' ) === 'membership_credit' ) { $found = true; break; }
                }
            }
        }
        return $cache[ $form_id ] = $found;
    }

    /**
     * How many membership credits this field will consume — summed only
     * across selections whose EVENT has "Enable membership credit
     * deduction" turned on (Events → edit event → Capacity & Pricing),
     * at that event's own "Credits per Ticket" rate. Purely informational
     * (feeds total_credits in Submission_Handler for admin visibility) —
     * the actual pay-with-credit-or-WC decision is made once per
     * submission by decide_payment() below, and only takes effect if the
     * customer actually has enough credit at submit time.
     */
    public function credit_cost( $value, array $field_config, array $submission ) : int {
        try {
            if ( '' === $value || ! class_exists( '\\CredoqEvents\\Event_Repository' ) ) return 0;
            $selections = json_decode( $value, true );
            if ( ! is_array( $selections ) ) return 0;
            if ( isset( $selections['event_id'] ) ) $selections = [ $selections ];

            $overrides = $this->seat_overrides( $submission );
            $needed = 0;
            foreach ( $selections as $sel ) {
                $event_id = absint( $sel['event_id'] ?? 0 );
                if ( ! $event_id ) continue;
                $event = \CredoqEvents\Event_Repository::find( $event_id );
                if ( ! $event || empty( $event->credit_deduct_enabled ) ) continue;

                $override = $overrides[ $event_id ] ?? null;
                $qty      = $override ? max( 1, (int) $override['count'] ) : max( 1, (int) ( $sel['quantity'] ?? 1 ) );
                $total    = $override ? (float) $override['total'] : ( (float) ( $sel['price'] ?? $event->price ) * $qty );
                if ( $total <= 0 ) continue; // nothing to pay for, credit not needed

                $needed += $qty * max( 1, (int) $event->credit_deduct_amount );
            }
            return $needed;
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    /**
     * Current user's membership-credit standing, without deducting
     * anything. Safe to call even when Credoq Membership isn't active.
     */
    private function membership_credit_status( int $needed_credits ) : array {
        $status = [ 'active_plugin' => false, 'user_id' => 0, 'balance' => 0, 'sufficient' => false ];
        if ( ! class_exists( '\\CredoqMembership\\Membership_Service' ) ) return $status;

        $status['active_plugin'] = true;
        $user_id = get_current_user_id();
        if ( ! $user_id ) return $status; // guests have no membership credit

        $status['user_id'] = $user_id;
        $service           = new \CredoqMembership\Membership_Service();
        $status['balance'] = $service->get_balance( $user_id, 0, 0 );
        $status['sufficient'] = $status['balance'] >= max( 1, $needed_credits );
        return $status;
    }

    /** @var array|null Memoized per-request payment decision — see decide_payment(). */
    private $credit_decision = null;

    /**
     * AUDIT-FEATURE (membership credit vs WC checkout — three-way
     * decision): resolves once per request (memoized) and reused by both
     * wc_contribution() (decides whether to add anything to the WC cart)
     * and on_submission() (decides whether to deduct credit, wait for WC
     * payment, or confirm free) so the two never disagree.
     *
     * "Enable membership credit deduction" and "Credits per Ticket" are
     * configured per EVENT, not per field, so a single Event Registration
     * field can mix credit-eligible and always-WC events in one
     * submission. This resolves ONE pooled decision — sufficient balance
     * to cover every credit-eligible, priced selection, or not — which
     * resolve_selection_payment() then applies per selection:
     *
     *   (a) event has credit deduction on + pooled balance sufficient
     *       → that event's booking is paid via credit, confirmed now.
     *   (b) event has credit deduction on + pooled balance insufficient
     *       → falls through to that event's own WC product, if any.
     *   (c) event has credit deduction on + no Member Slot Credit field
     *       in the form → already rejected in validate(), never reaches
     *       here.
     */
    private function decide_payment( array $selections, array $submission = array() ) : array {
        if ( isset( $this->credit_decision ) ) return $this->credit_decision;

        $overrides      = $this->seat_overrides( $submission );
        $needed_credits = 0;
        foreach ( $selections as $sel ) {
            $event_id = absint( $sel['event_id'] ?? 0 );
            if ( ! $event_id || ! class_exists( '\\CredoqEvents\\Event_Repository' ) ) continue;
            $event = \CredoqEvents\Event_Repository::find( $event_id );
            if ( ! $event || empty( $event->credit_deduct_enabled ) ) continue;

            $override = $overrides[ $event_id ] ?? null;
            $qty      = $override ? max( 1, (int) $override['count'] ) : max( 1, (int) ( $sel['quantity'] ?? 1 ) );
            $total    = $override ? (float) $override['total'] : ( (float) ( $sel['price'] ?? $event->price ) * $qty );
            if ( $total <= 0 ) continue;

            $needed_credits += $qty * max( 1, (int) $event->credit_deduct_amount );
        }

        $decision = [ 'use_credit' => false, 'needed_credits' => $needed_credits, 'user_id' => 0 ];
        if ( $needed_credits > 0 ) {
            $status = $this->membership_credit_status( $needed_credits );
            $decision['user_id']    = $status['user_id'];
            $decision['use_credit'] = $status['sufficient']; // (a) vs (b)
        }
        return $this->credit_decision = $decision;
    }

    /**
     * AUDIT-FEATURE (Events + Seats — price replacement, not addition): a
     * seat_map field sharing the same form as this one governs an event's
     * REAL price whenever it resolves to that event (see
     * Fields\Seat_Map_Field::resolve_event_id_from_payload() in
     * credoq-seats — the two must agree on which event a seat selection
     * belongs to, since Seat_Map_Field::on_submission() rejects the
     * submission outright if it can't resolve one unambiguously). When
     * present, the seat plan's own per-seat total (each seat's own
     * override, else its type price, else this event's base price)
     * REPLACES event->price × qty for that selection everywhere money is
     * computed — WC cart, credit deduction, and the stored booking row —
     * it is never added on top of the flat price.
     *
     * Memoized per-request/per-field-instance; 'sanitized' is only needed
     * the first call (Submission_Handler always calls with the same
     * payload across price_contribution()/wc_contribution()/credit_cost()/
     * on_submission() for a single field instance).
     */
    private $seat_overrides_cache = null;
    private function seat_overrides( array $submission ) : array {
        if ( null !== $this->seat_overrides_cache ) return $this->seat_overrides_cache;
        return $this->seat_overrides_cache = apply_filters( 'credoq_events_seat_overrides', array(), array( 'sanitized' => $submission ) );
    }

    /**
     * Resolve how a single selection gets paid for, given the pooled
     * credit decision: 'free' (nothing to pay), 'credit', 'wc', or
     * 'unpayable' (priced, but neither credit nor a WC product apply).
     */
    private function resolve_selection_payment( object $event, float $total_price, array $decision ) : string {
        if ( $total_price <= 0 ) return 'free';
        if ( ! empty( $event->credit_deduct_enabled ) && $decision['use_credit'] ) return 'credit';
        if ( (int) $event->wc_product_id > 0 ) return 'wc';
        return 'unpayable';
    }

    /**
     * AUDIT-FIX (Total showed "Free" / no WC checkout redirect): Field_Event
     * previously had no wc_contribution() override, so the abstract
     * default (empty array) was used. Submission_Handler only adds items
     * to the WooCommerce cart / redirects to checkout for fields that
     * return a wc_contribution() — so an Event Registration field, no
     * matter its price, never reached checkout. Selections with a
     * configured WC product now bridge into the cart via the Engine's
     * generic WooCommerce_Bridge (same mechanism Checkbox/Select/Calculate
     * fields use), so the "Total" the customer already saw in the review
     * step is exactly what WooCommerce charges.
     *
     * Events without a wc_product_id configured are still recorded (see
     * on_submission()) but simply skip the WC step and confirm immediately
     * — there's no product to check out with.
     *
     * AUDIT-FEATURE (membership credit): any selection resolved as 'credit'
     * (see resolve_selection_payment()) contributes nothing to the WC
     * cart — payment already happened via credit deduction in
     * on_submission(). Only 'wc'-resolved selections add a cart item.
     */
    public function wc_contribution( $value, array $field_config, array $submission ) : array {
        try {
            return $this->build_wc_contribution( $value, $submission );
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    private function build_wc_contribution( $value, array $submission = array() ) : array {
        if ( '' === $value ) return [];
        $selections = json_decode( $value, true );
        if ( ! is_array( $selections ) ) return [];
        if ( isset( $selections['event_id'] ) ) $selections = [ $selections ];
        if ( ! class_exists( '\\CredoqEvents\\Event_Repository' ) ) return [];

        $decision      = $this->decide_payment( $selections, $submission );
        $overrides     = $this->seat_overrides( $submission );
        $contributions = [];
        foreach ( $selections as $sel ) {
            $event_id = absint( $sel['event_id'] ?? 0 );
            if ( ! $event_id ) continue;
            $event = \CredoqEvents\Event_Repository::find( $event_id );
            if ( ! $event ) continue;

            $override = $overrides[ $event_id ] ?? null;
            $qty      = $override ? max( 1, (int) $override['count'] ) : max( 1, (int) ( $sel['quantity'] ?? 1 ) );
            $total    = $override ? (float) $override['total'] : ( (float) ( $sel['price'] ?? $event->price ) * $qty );

            if ( 'wc' !== $this->resolve_selection_payment( $event, $total, $decision ) ) continue;

            $contributions[] = [
                'product_id' => (int) $event->wc_product_id,
                'price'      => $total,
            ];
        }
        return $contributions;
    }

    /**
     * AUDIT-FIX (Event Registration field submitted through the React
     * booking widget / Form Builder never created a real booking): the
     * default on_submission() is a no-op, so nothing was ever written to
     * credoq_event_bookings and capacity never decremented for this flow
     * (only the older, separate credoq_event_register AJAX action did).
     * Mirrors Event_Service::register()'s booking-creation logic, but for
     * every event selected in the calendar and tagged with the Engine
     * submission id so WooCommerce order-status hooks (see
     * Integrations/WooCommerce.php) can confirm or cancel the right rows
     * after checkout.
     *
     * AUDIT-FEATURE (membership credit vs WC — three-way decision, per
     * selection via resolve_selection_payment()):
     *   (a) event's credit deduction on + pooled balance sufficient →
     *       confirm immediately, credit deducted once at the end.
     *   (b) event's credit deduction on + pooled balance insufficient,
     *       but event has a WC product → pending_payment, same as a
     *       plain paid event (WooCommerce.php confirms it later).
     *   (c) priced event with neither credit nor a WC product available →
     *       hard error instead of silently confirming an unpaid booking.
     */
    public function on_submission( int $submission_id, $value, array $field_config, array $submission_payload ) {
        // AUDIT-FIX (Network error still occurring on submit): same
        // rationale as validate_selections() above — this method does the
        // most work of anything in this class (capacity re-checks, DB
        // inserts, membership-credit lookups), so it's the most likely
        // place for an unexpected \Throwable to slip through and corrupt
        // the AJAX JSON response. Catch everything and turn it into a
        // readable WP_Error instead.
        try {
            return $this->handle_submission( $submission_id, $value, $field_config, $submission_payload );
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'event_submission_exception', sprintf(
                /* translators: %s = exception message */
                __( 'Event Registration submission error: %s', 'credoq-events' ), $e->getMessage()
            ) );
        }
    }

    private function handle_submission( int $submission_id, $value, array $field_config, array $submission_payload ) {
        if ( '' === $value ) return true;
        $selections = json_decode( $value, true );
        if ( ! is_array( $selections ) ) return true;
        if ( isset( $selections['event_id'] ) ) $selections = [ $selections ];
        if ( empty( $selections ) ) return true;

        if ( ! class_exists( '\\CredoqEvents\\Event_Repository' )
            || ! class_exists( '\\CredoqEvents\\Event_Booking_Repository' )
            || ! class_exists( '\\CredoqEvents\\Event_Service' ) ) {
            return new \WP_Error( 'events_addon_missing', __( 'Credoq Events addon is required for event registration.', 'credoq-events' ) );
        }

        $decision  = $this->decide_payment( $selections, $submission_payload );
        $overrides = $this->seat_overrides( $submission_payload );

        $user_id     = get_current_user_id();
        $guest_name  = (string) ( $submission_payload['name'] ?? $submission_payload['full_name'] ?? '' );
        $guest_email = (string) ( $submission_payload['email'] ?? '' );

        $booking_ids   = [];
        $credits_spent = 0;

        foreach ( $selections as $sel ) {
            $event_id = absint( $sel['event_id'] ?? 0 );
            if ( ! $event_id ) continue;

            $override = $overrides[ $event_id ] ?? null;
            // AUDIT-FIX (Events + Seats — replace, don't add): when a
            // seat_map field on this form resolves to this event, the
            // seat plan's own recomputed total (and seat count) is the
            // real quantity/price for this registration — NOT the
            // calendar's own qty stepper × the event's flat price. See
            // seat_overrides() docblock above.
            $qty = $override ? max( 1, (int) $override['count'] ) : max( 1, absint( $sel['quantity'] ?? 1 ) );

            // Re-check capacity at write time (it was already checked in
            // validate(), but another submission may have landed first).
            if ( ! \CredoqEvents\Event_Service::has_capacity( $event_id, $qty ) ) {
                return new \WP_Error( 'no_capacity', __( 'Not enough spots available for one or more selected events.', 'credoq-events' ) );
            }

            $event = \CredoqEvents\Event_Repository::find( $event_id );
            if ( ! $event ) continue;

            $price       = (float) ( $sel['price'] ?? $event->price );
            $total_price = $override ? (float) $override['total'] : ( $price * $qty );
            $method      = $this->resolve_selection_payment( $event, $total_price, $decision );

            // Priced event, but neither credit nor a WC product applies —
            // refuse rather than silently give it away for free.
            if ( 'unpayable' === $method ) {
                return new \WP_Error(
                    'no_payment_method',
                    sprintf(
                        /* translators: %s = event title */
                        __( '"%s" requires payment, but no payment method is available (insufficient membership credit and no WooCommerce product configured for this event). Please contact the site admin.', 'credoq-events' ),
                        $event->title
                    )
                );
            }

            $status = ( 'wc' === $method ) ? 'pending_payment' : 'confirmed';

            $booking_id = \CredoqEvents\Event_Booking_Repository::insert( [
                'event_id'      => $event_id,
                'user_id'       => $user_id,
                'guest_name'    => sanitize_text_field( $guest_name ),
                'guest_email'   => sanitize_email( $guest_email ),
                'quantity'      => $qty,
                'total_price'   => $total_price,
                'status'        => $status,
                'qr_token'      => wp_generate_password( 32, false ),
                'submission_id' => $submission_id,
            ] );

            if ( ! $booking_id ) {
                return new \WP_Error( 'booking_failed', __( 'Could not register for the selected event(s). Please try again.', 'credoq-events' ) );
            }

            if ( 'confirmed' === $status ) {
                do_action( 'credoq_event_booking_confirmed', $booking_id );
            }
            if ( 'credit' === $method ) {
                $credits_spent += $qty * max( 1, (int) $event->credit_deduct_amount );
            }

            $booking_ids[] = $booking_id;
        }

        // Deduct the credit once for the whole field, now that every
        // credit-paid selection has a confirmed booking row.
        if ( $credits_spent > 0 && $decision['user_id'] && class_exists( '\\CredoqMembership\\Membership_Service' ) ) {
            $service = new \CredoqMembership\Membership_Service();
            $service->add_ledger_entry(
                $decision['user_id'],
                -$credits_spent,
                'use',
                0,
                $submission_id,
                __( 'Event registration', 'credoq-events' )
            );
        }

        return $booking_ids ? [ 'event_booking_ids' => $booking_ids, 'credits_used' => $credits_spent ] : true;
    }

    /**
     * If the submission is cancelled before payment completes (or by an
     * admin), release the reserved spots and refund any membership credit
     * that was deducted for it.
     */
    public function on_cancellation( int $submission_id, array $context ) : void {
        if ( class_exists( '\\CredoqEvents\\Event_Booking_Repository' ) ) {
            \CredoqEvents\Event_Booking_Repository::update_status_by_submission( $submission_id, 'cancelled' );
        }

        if ( class_exists( '\\CredoqMembership\\Membership_Service' ) ) {
            global $wpdb;
            $ledger_table = $wpdb->prefix . 'credoq_credit_ledger';
            $entry = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$ledger_table} WHERE ref_id = %d AND type = 'use' LIMIT 1",
                $submission_id
            ) );
            if ( $entry && ! empty( $context['refund_credits'] ) ) {
                $service = new \CredoqMembership\Membership_Service();
                $service->add_ledger_entry(
                    (int) $entry->user_id,
                    abs( (int) $entry->amount ),
                    'refund',
                    (int) $entry->plan_id,
                    $submission_id,
                    __( 'Event registration cancelled', 'credoq-events' )
                );
            }
        }
    }

    public function render_value( $value, array $field_config ) : string {
        if ( '' === $value ) return '';
        $selections = json_decode( $value, true );
        if ( ! is_array( $selections ) ) return esc_html( $value );
        if ( isset( $selections['event_id'] ) ) $selections = [ $selections ];

        $lines = [];
        foreach ( $selections as $sel ) {
            $label = 'Event #' . (int)$sel['event_id'];
            if ( class_exists( '\\CredoqEvents\\Event_Repository' ) ) {
                $ev = \CredoqEvents\Event_Repository::find( (int)$sel['event_id'] );
                if ( $ev ) $label = $ev->title;
            }
            $lines[] = esc_html( $label . ' × ' . (int)$sel['quantity'] );
        }
        return implode( '<br>', $lines );
    }
}
