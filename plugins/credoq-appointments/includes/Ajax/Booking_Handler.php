<?php
namespace CredoqAppointments\Ajax;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Booking_Service;
use CredoqAppointments\Booking_Repository;
use CredoqAppointments\Waiting_List_Repository;

/**
 * AJAX booking submission and user booking management.
 *
 * AUDIT-FIX A-2: No JetAppointments.
 * AUDIT-FIX B-7: No base64_decode.
 * Rate-limit: 5 submissions per 10 minutes per IP.
 *
 * --- BUGS FIXED IN THIS FILE ---
 *
 * FIX-BH-1: React widget sends selected_date / selected_time but the PHP
 *           single-slot fallback read $_POST['date'] / $_POST['time'].
 *           Single-slot bookings (no 'dates' array) always produced
 *           "No dates selected".
 *           → Now reads selected_date and selected_time as primary keys,
 *           with date/time as legacy aliases.
 *
 * FIX-BH-2: Waiting-list result (wl_added=true) was returned as a generic
 *           error with no distinction from a hard failure.
 *           → AJAX now returns success=false + wl_added=true with a user-
 *           friendly message so the React widget can display the right copy.
 *
 * FIX-BH-3: plan_id was read from $_POST['plan_id'] but the React widget
 *           sends credoq_selected_plan_id.  Credit bookings were never
 *           routed to the membership plan.
 */
class Booking_Handler {

    public static function register() : void {
        add_action( 'wp_ajax_credoq_submit_booking',        [ __CLASS__, 'submit' ] );
        add_action( 'wp_ajax_nopriv_credoq_submit_booking', [ __CLASS__, 'submit' ] );
        add_action( 'wp_ajax_credoq_cancel_booking_user',   [ __CLASS__, 'cancel_user' ] );
        add_action( 'wp_ajax_credoq_join_waiting_list',     [ __CLASS__, 'join_waiting_list' ] );
        add_action( 'wp_ajax_nopriv_credoq_join_waiting_list', [ __CLASS__, 'join_waiting_list' ] );
        add_action( 'wp_ajax_credoq_get_user_bookings_cal',        [ __CLASS__, 'get_user_bookings_cal' ] );
        add_action( 'wp_ajax_nopriv_credoq_get_user_bookings_cal', [ __CLASS__, 'get_user_bookings_cal' ] );
        // Token-based cancel link from email.
        add_action( 'init', [ __CLASS__, 'handle_cancel_token' ] );
    }

    public static function submit() : void {
        // Nonce check: skip when called from REST context (REST handler verifies its own nonce).
        // When called via admin-ajax.php, verify normally.
        if ( ! defined( 'CREDOQ_REST_SUBMISSION' ) ) {
            check_ajax_referer( 'credoq_nonce', 'nonce' );
        }

        // Rate limit: 5 per 10 min per IP.
        $ip       = self::client_ip();
        $rate_key = 'credoq_book_rate_' . md5( $ip );
        $hits     = intval( get_transient( $rate_key ) );
        if ( $hits >= 5 ) {
            wp_send_json_error( [ 'message' => 'Too many requests. Please wait a few minutes.' ] );
        }
        set_transient( $rate_key, $hits + 1, 10 * MINUTE_IN_SECONDS );

        $apt_id   = absint( $_POST['appointment_id'] ?? 0 );
        $staff_id = absint( $_POST['staff_id']       ?? 0 );
        $user_id  = get_current_user_id();

        // ── AUDIT-FIX (Bug 1: "Invalid service."): decouple submission
        //    pipeline for forms without an appointment field. ──────────
        //
        // The React widget always POSTs to the Appointments-owned
        // /credoq/v1/bookings REST route (and its admin-ajax fallback),
        // because that is the only route registered for
        // 'credoq_submit_booking'. When the form being submitted has no
        // appointment field, appointment_id arrives as 0/empty, and
        // Booking_Service::create() would call
        // Appointment_Repository::find(0) → null → "Invalid service.",
        // even though nothing about this submission needs Appointments
        // at all.
        //
        // Fix: if appointment_id is empty, hand the submission straight
        // to the Engine's generic, addon-agnostic Submission_Handler and
        // return its result. The Engine itself never needs to know
        // Appointments exists, and the widget gets a normal
        // success/redirect response for plain Contact Forms, Surveys,
        // Cost Estimators, etc.
        if ( ! $apt_id ) {
            $form_id = absint( $_POST['form_id'] ?? 0 );

            if ( ! $form_id ) {
                wp_send_json_error( [ 'message' => __( 'No form specified.', 'credoq-appointments' ) ], 400 );
            }

            if ( ! function_exists( 'credoq_engine' ) ) {
                wp_send_json_error( [ 'message' => __( 'Form engine unavailable.', 'credoq-appointments' ) ], 500 );
            }

            $form_data = self::sanitize_form_data( $_POST['form_data'] ?? [] );

            $result = credoq_engine()->submissions()->process( $form_id, $form_data, [
                'user_id'    => $user_id,
                'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
                    ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
                    : '',
                'source'     => 'rest_bookings_fallback',
            ] );

            if ( is_wp_error( $result ) ) {
                $code = $result->get_error_code();
                wp_send_json_error( [
                    'code'    => $code,
                    'message' => $result->get_error_message(),
                    'data'    => $result->get_error_data() ?: [],
                ], 'rate_limited' === $code ? 429 : 400 );
            }

            wp_send_json_success( $result );
        }

        // From here on, $apt_id > 0: a genuine appointment booking.

        // FIX-BH-3: React sends credoq_selected_plan_id; fall back to plan_id.
        $plan_id = absint(
            $_POST['credoq_selected_plan_id'] ?? $_POST['plan_id'] ?? 0
        );

        // ── Dates: multi-booking (schedules_json) or single slot ──────
        $dates = [];

        $is_multi = ! empty( $_POST['is_multi_booking'] );
        if ( $is_multi && ! empty( $_POST['schedules_json'] ) ) {
            // React widget sends JSON-encoded array for multi-booking.
            $raw_schedules = json_decode(
                wp_unslash( $_POST['schedules_json'] ), true
            );
            if ( is_array( $raw_schedules ) ) {
                foreach ( $raw_schedules as $s ) {
                    $d = sanitize_text_field( $s['date'] ?? '' );
                    $t = sanitize_text_field( $s['time'] ?? '' );
                    if ( $d && $t ) $dates[] = [ 'date' => $d, 'time' => $t ];
                }
            }
        } elseif ( ! empty( $_POST['dates'] ) && is_array( $_POST['dates'] ) ) {
            // Classic array format: dates[0][date], dates[0][time].
            foreach ( $_POST['dates'] as $d ) {
                $date = sanitize_text_field( $d['date'] ?? '' );
                $time = sanitize_text_field( $d['time'] ?? '' );
                if ( $date && $time ) $dates[] = [ 'date' => $date, 'time' => $time ];
            }
        } else {
            // FIX-BH-1: single-slot fallback.
            // React sends selected_date / selected_time; accept date/time as aliases.
            $date = sanitize_text_field(
                $_POST['selected_date'] ?? $_POST['date'] ?? ''
            );
            $time = sanitize_text_field(
                $_POST['selected_time'] ?? $_POST['time'] ?? ''
            );
            if ( $date && $time ) $dates[] = [ 'date' => $date, 'time' => $time ];
        }

        // Form data — sanitize all values recursively.
        $form_data = self::sanitize_form_data( $_POST['form_data'] ?? [] );

        // FIX: the React widget never sends a top-level `seat_ids` param —
        // a seat_map field's selection only ever travels inside
        // form_data[fieldname] as {seats:"[...]", count, total, plan_id,
        // selected}. Without this, `seat_ids` here (and therefore the
        // credoq_bookings.seat_ids column, and everything that reads it —
        // Appointments_Bridge confirming/releasing seats) was always
        // empty. Detect it structurally so this works regardless of the
        // field's name.
        $seat_ids = array_map( 'absint', (array) ( $_POST['seat_ids'] ?? [] ) );
        if ( empty( $seat_ids ) ) {
            foreach ( $form_data as $field_value ) {
                if ( is_array( $field_value ) && isset( $field_value['seats'], $field_value['plan_id'] ) ) {
                    $raw_seats = $field_value['seats'];
                    $decoded   = is_array( $raw_seats ) ? $raw_seats : json_decode( (string) $raw_seats, true );
                    if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                        $seat_ids = array_map( 'absint', $decoded );
                        break;
                    }
                }
            }
        }

        $result = Booking_Service::create( [
            'appointment_id' => $apt_id,
            'staff_id'       => $staff_id,
            'user_id'        => $user_id,
            'guest_name'     => sanitize_text_field( $_POST['guest_name']  ?? '' ),
            'guest_email'    => sanitize_email( $_POST['guest_email'] ?? '' ),
            'dates'          => $dates,
            'form_data'      => $form_data,
            'form_id'        => absint( $_POST['form_id'] ?? 0 ),
            'plan_id'        => $plan_id,
            'seat_ids'       => $seat_ids,
        ] );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            // FIX-BH-2: surface waiting-list outcome as a distinct flag.
            $response = [
                'message'  => $result['error'] ?? 'Booking failed.',
                'wl_added' => ! empty( $result['wl_added'] ),
            ];
            wp_send_json_error( $response );
        }
    }

    public static function cancel_user() : void {
        check_ajax_referer( 'credoq_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Not logged in.' ] ); }

        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        $booking    = Booking_Repository::find( $booking_id );

        if ( ! $booking || intval( $booking->user_id ) !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        // User-initiated cancel: refund credits.
        $ok = Booking_Service::cancel( $booking_id, true );
        $ok
            ? wp_send_json_success()
            : wp_send_json_error( [ 'message' => 'Could not cancel booking.' ] );
    }

    public static function join_waiting_list() : void {
        check_ajax_referer( 'credoq_nonce', 'nonce' );

        $apt_id   = absint( $_POST['appointment_id'] ?? 0 );
        $staff_id = absint( $_POST['staff_id']       ?? 0 );
        $date     = sanitize_text_field( $_POST['date'] ?? '' );
        $time     = sanitize_text_field( $_POST['time'] ?? '' );
        $user_id  = get_current_user_id();
        $email    = sanitize_email(
            $_POST['email'] ?? ( $user_id ? wp_get_current_user()->user_email : '' )
        );

        if ( ! $apt_id || ! $date || ! $time || ! $email ) {
            wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
        }

        $id = Waiting_List_Repository::add( $apt_id, $staff_id, $date, $time, $user_id, $email );
        $id
            ? wp_send_json_success( [ 'queue_id' => $id ] )
            : wp_send_json_error( [ 'message' => 'Could not join waiting list.' ] );
    }

    public static function get_user_bookings_cal() : void {
        check_ajax_referer( 'credoq_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Not logged in.' ] ); }

        $user_id  = get_current_user_id();
        $upcoming = filter_var( $_POST['upcoming'] ?? 0, FILTER_VALIDATE_BOOLEAN );
        $bookings = Booking_Repository::get_user_bookings( $user_id, $upcoming );

        $out = array_map( fn( $b ) => [
            'id'       => intval( $b->id ),
            'title'    => $b->apt_title ?: 'Appointment',
            'date'     => $b->selected_date,
            'time'     => $b->selected_time,
            'status'   => $b->status,
            'location' => $b->apt_location ?? '',
        ], $bookings );

        wp_send_json_success( [ 'bookings' => $out ] );
    }

    /** Handle token-based cancel link from email. AUDIT-FIX B-12 */
    public static function handle_cancel_token() : void {
        if ( empty( $_GET['credoq_action'] ) || $_GET['credoq_action'] !== 'cancel_booking' ) return;
        $token = sanitize_text_field( $_GET['token'] ?? '' );
        if ( ! $token ) return;

        $booking_id = get_transient( 'credoq_cancel_token_' . $token );
        if ( ! $booking_id ) {
            wp_die(
                'This cancellation link has expired. Please log in to cancel your booking.',
                'Link Expired',
                [ 'response' => 403 ]
            );
        }
        delete_transient( 'credoq_cancel_token_' . $token );
        Booking_Service::cancel( intval( $booking_id ), true );
        wp_redirect( add_query_arg( 'credoq_cancelled', '1', home_url( '/' ) ) );
        exit;
    }

    private static function sanitize_form_data( $data ) : array {
        if ( ! is_array( $data ) ) return [];
        $clean = [];
        foreach ( $data as $k => $v ) {
            $key         = sanitize_key( $k );
            $clean[$key] = self::sanitize_form_value( $v );
        }
        return $clean;
    }

    /**
     * Recursively sanitizes a form value. Needed because a field's value
     * can arrive shaped two different ways depending on transport:
     *   - multipart FormData (the AJAX fallback): nested values are
     *     already flattened to strings by the browser, e.g. seat_map's
     *     "seats" arrives as the STRING '[198,200,206]'.
     *   - JSON REST body (Booking_Rest::submit_booking, the widget's
     *     primary path): nested JS objects/arrays decode to real PHP
     *     arrays, e.g. seat_map's "seats" arrives as an actual array
     *     [198, 200, 206].
     * The old, non-recursive version called sanitize_text_field()
     * directly on whatever array-map hit it one level down, which
     * silently corrupts a real PHP array into the literal string
     * "Array" — which is exactly why seat_ids was always empty
     * downstream (json_decode('Array', true) is null). Recursing keeps
     * arrays as arrays and only string-sanitizes actual scalars.
     */
    private static function sanitize_form_value( $v ) {
        if ( is_array( $v ) ) {
            $out = [];
            foreach ( $v as $k2 => $v2 ) {
                $out[ is_string( $k2 ) ? sanitize_key( $k2 ) : $k2 ] = self::sanitize_form_value( $v2 );
            }
            return $out;
        }
        return sanitize_text_field( wp_unslash( (string) $v ) );
    }

    /** AUDIT-FIX B-6: client IP without trusting proxy headers by default. */
    private static function client_ip() : string {
        return isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] )
            : '0.0.0.0';
    }
}
