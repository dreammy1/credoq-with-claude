<?php
/**
 * REST API routes for the appointment booking widget.
 *
 * All read endpoints are public (permission_callback: __return_true).
 * The booking submission verifies a nonce internally.
 *
 * Routes registered under credoq/v1:
 *   GET  /providers             ?appointment_id=X
 *   GET  /date-capacity         ?appointment_id=X&year=Y&month=M[&staff_id=S]
 *   GET  /timeslots             ?appointment_id=X&date=YYYY-MM-DD[&staff_id=S]
 *   GET  /services-for-provider ?staff_id=X
 *   GET  /services-for-date     ?date=YYYY-MM-DD
 *   POST /bookings              JSON body
 *
 * @package CredoqAppointments\Api
 */
namespace CredoqAppointments\Api;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Appointment_Repository;
use CredoqAppointments\Staff_Repository;
use CredoqAppointments\Slot_Generator;

class Booking_Rest {

    public static function register() : void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() : void {
        $ns = defined( 'CREDOQ_ENGINE_REST_NS' ) ? \CREDOQ_ENGINE_REST_NS : 'credoq/v1';

        register_rest_route( $ns, '/providers', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_providers' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'appointment_id' => [ 'sanitize_callback' => 'absint', 'default' => 0 ] ],
        ] );

        register_rest_route( $ns, '/date-capacity', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_date_capacity' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'appointment_id' => [ 'sanitize_callback' => 'absint',              'default' => 0           ],
                'staff_id'       => [ 'sanitize_callback' => 'absint',              'default' => 0           ],
                'year'           => [ 'sanitize_callback' => 'absint',              'default' => 0           ],
                'month'          => [ 'sanitize_callback' => 'absint',              'default' => 0           ],
            ],
        ] );

        register_rest_route( $ns, '/timeslots', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_timeslots' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'appointment_id' => [ 'sanitize_callback' => 'absint',              'default' => 0  ],
                'staff_id'       => [ 'sanitize_callback' => 'absint',              'default' => 0  ],
                'date'           => [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            ],
        ] );

        register_rest_route( $ns, '/services-for-provider', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_services_for_provider' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'staff_id' => [ 'sanitize_callback' => 'absint', 'default' => 0 ] ],
        ] );

        register_rest_route( $ns, '/services-for-date', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_services_for_date' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'date' => [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] ],
        ] );

        register_rest_route( $ns, '/bookings', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'submit_booking' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ── Providers ───────────────────────────────────────────── */

    public static function get_providers( \WP_REST_Request $req ) : \WP_REST_Response {
        $apt_id = (int) $req->get_param( 'appointment_id' );
        if ( ! $apt_id ) return rest_ensure_response( [] );

        $staff  = Staff_Repository::find_by_appointment( $apt_id );
        $result = array_map( fn( $s ) => [
            'id'    => (int)    $s->id,
            'name'  => (string) $s->display_name,
            'bio'   => (string) ( $s->bio        ?? '' ),
            'image' => (string) ( $s->avatar_url ?? '' ),
        ], $staff );

        if ( empty( $result ) ) {
            $result = [[
                'id'    => 0,
                'name'  => __( 'Any Available', 'credoq-appointments' ),
                'bio'   => __( 'First available specialist will be assigned.', 'credoq-appointments' ),
                'image' => '',
            ]];
        }

        return rest_ensure_response( $result );
    }

    /* ── Date capacity (calendar dots) ──────────────────────── */

    public static function get_date_capacity( \WP_REST_Request $req ) : \WP_REST_Response {
        $apt_id   = (int) $req->get_param( 'appointment_id' );
        $staff_id = (int) $req->get_param( 'staff_id' );
        $year     = (int) $req->get_param( 'year'  ) ?: (int) date( 'Y' );
        $month    = (int) $req->get_param( 'month' ) ?: (int) date( 'n' );

        if ( ! $apt_id ) return rest_ensure_response( [ 'dates' => [], 'available_dates' => [], 'special_dates' => [] ] );

        $apt         = Appointment_Repository::find( $apt_id );
        $bk_settings = $apt ? ( json_decode( $apt->booking_settings ?? '{}', true ) ?: [] ) : [];
        $staff_row   = $staff_id > 0 ? Staff_Repository::find( $staff_id ) : null;
        $dates       = Slot_Generator::available_dates_in_month( $apt_id, $staff_id, $year, $month );

        // Build special_dates set — any date in this month that carries a
        // price override so the Calendar can render a visual badge.
        $special_date_keys = [];
        // From staff special_dates
        if ( $staff_row ) {
            foreach ( Staff_Repository::get_special_dates( $staff_row ) as $sd ) {
                if ( ! empty( $sd['date'] ) && isset( $sd['price'] ) && $sd['price'] !== '' ) {
                    $special_date_keys[] = $sd['date'];
                }
            }
        }
        // From appointment-level special_dates
        foreach ( (array) ( $bk_settings['special_dates'] ?? [] ) as $sd ) {
            if ( ! empty( $sd['date'] ) && isset( $sd['price'] ) ) {
                $special_date_keys[] = $sd['date'];
            }
        }
        // Weekend price — mark all Saturdays/Sundays in the month
        if ( isset( $bk_settings['weekend_price'] ) ) {
            $days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
            for ( $d = 1; $d <= $days_in_month; $d++ ) {
                $dow = (int) date( 'N', mktime( 0, 0, 0, $month, $d, $year ) );
                if ( $dow >= 6 ) {
                    $special_date_keys[] = "$year-" . str_pad( $month, 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $d, 2, '0', STR_PAD_LEFT );
                }
            }
        }

        return rest_ensure_response( [
            'dates'           => $dates,
            'available_dates' => array_keys( $dates ),
            'special_dates'   => array_values( array_unique( $special_date_keys ) ),
        ] );
    }

    /* ── Timeslots ───────────────────────────────────────────── */

    public static function get_timeslots( \WP_REST_Request $req ) : \WP_REST_Response {
        $apt_id   = (int) $req->get_param( 'appointment_id' );
        $staff_id = (int) $req->get_param( 'staff_id' );
        $date     = sanitize_text_field( $req->get_param( 'date' ) );

        if ( ! $apt_id || ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid parameters' ], 400 );
        }

        $apt   = Appointment_Repository::find( $apt_id );
        if ( ! $apt ) return new \WP_REST_Response( [ 'error' => 'Service not found' ], 404 );

        $slots = Slot_Generator::for_date( $apt_id, $staff_id, $date );
        $staff_row = $staff_id > 0 ? Staff_Repository::find( $staff_id ) : null;

        // ── Special-date / base price for this specific date ──────────
        // Mirrors Booking_Service::resolve_special_price() so the React
        // widget shows the correct price immediately when the user clicks
        // a date — including staff-level special-date overrides and
        // appointment-level special_dates/weekend_price entries.
        $bk_settings = json_decode( $apt->booking_settings ?? '{}', true ) ?: [];
        $day_of_week = strtolower( date( 'l', strtotime( $date ) ) );

        // Priority 1: staff special_dates price
        $date_price = null;
        if ( $staff_row ) {
            $date_price = Staff_Repository::get_special_date_price( $staff_row, $date );
        }
        // Priority 2: appointment-level special_dates
        if ( $date_price === null ) {
            foreach ( (array) ( $bk_settings['special_dates'] ?? [] ) as $sd ) {
                if ( is_array( $sd ) && ( $sd['date'] ?? '' ) === $date && isset( $sd['price'] ) ) {
                    $date_price = (float) $sd['price'];
                    break;
                }
            }
        }
        // Priority 3: weekend surcharge
        if ( $date_price === null && in_array( $day_of_week, [ 'saturday', 'sunday' ], true )
             && isset( $bk_settings['weekend_price'] ) ) {
            $date_price = (float) $bk_settings['weekend_price'];
        }
        // Fallback: base price
        if ( $date_price === null ) {
            $date_price = (float) ( $apt->base_price ?? 0 );
        }

        // Apply staff price multiplier if applicable.
        if ( $staff_row && ! Staff_Repository::get_special_date_price( $staff_row, $date ) ) {
            $date_price *= (float) ( $staff_row->price_multiplier ?: 1 );
        }

        $staff_list = Staff_Repository::find_by_appointment( $apt_id );

        return rest_ensure_response( [
            'slots'      => $slots,
            'staff'      => array_map( fn( $s ) => [
                'id'    => (int)    $s->id,
                'name'  => (string) $s->display_name,
                'image' => (string) ( $s->avatar_url ?? '' ),
                'bio'   => (string) ( $s->bio         ?? '' ),
            ], $staff_list ),
            'duration'   => (int)    ( $apt->duration   ?? 60 ),
            'date_price' => round( $date_price, 2 ),
            'is_special' => $date_price !== (float) ( $apt->base_price ?? 0 ),
            'currency'   => get_option( 'woocommerce_currency', 'USD' ),
        ] );
    }

    /* ── Services for provider ───────────────────────────────── */

    public static function get_services_for_provider( \WP_REST_Request $req ) : \WP_REST_Response {
        $staff_id = (int) $req->get_param( 'staff_id' );
        if ( ! $staff_id ) return rest_ensure_response( [] );

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, title, location, base_price, duration, slot_interval, staff_ids
             FROM {$wpdb->prefix}credoq_appointments ORDER BY title ASC", ARRAY_A );

        $services = [];
        foreach ( (array) $rows as $apt ) {
            $ids = json_decode( $apt['staff_ids'] ?? '[]', true );
            if ( is_array( $ids ) && in_array( $staff_id, array_map( 'intval', $ids ), true ) ) {
                $services[] = self::fmt( $apt );
            }
        }
        return rest_ensure_response( $services );
    }

    /* ── Services for date ───────────────────────────────────── */

    public static function get_services_for_date( \WP_REST_Request $req ) : \WP_REST_Response {
        $date = sanitize_text_field( $req->get_param( 'date' ) );
        if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid date' ], 400 );
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, title, location, base_price, duration, slot_interval, availability
             FROM {$wpdb->prefix}credoq_appointments ORDER BY title ASC", ARRAY_A );

        $dow_names = [ 0=>'sunday',1=>'monday',2=>'tuesday',3=>'wednesday',
                       4=>'thursday',5=>'friday',6=>'saturday' ];
        $dow       = (int) date( 'w', strtotime( $date ) );
        $day_name  = $dow_names[ $dow ];

        $services = [];
        foreach ( (array) $rows as $apt ) {
            $av     = json_decode( $apt['availability'] ?? '{}', true );
            $day_av = is_array( $av ) ? ( $av[ $day_name ] ?? [] ) : [];
            if ( empty( $day_av['closed'] ) && ! empty( $day_av['hours'] ) ) {
                $services[] = self::fmt( $apt );
            }
        }
        return rest_ensure_response( $services );
    }

    /* ── Submit booking ──────────────────────────────────────── */

    public static function submit_booking( \WP_REST_Request $req ) : \WP_REST_Response {
        // Verify nonce — accept X-WP-Nonce header (wp_rest) OR body nonce (credoq_nonce)
        $header_nonce = $req->get_header( 'X-WP-Nonce' ) ?: '';
        $body_nonce   = sanitize_text_field( $req->get_param( 'nonce' ) ?: '' );
        $nonce_ok     = ( $header_nonce && wp_verify_nonce( $header_nonce, 'wp_rest' ) )
                     || ( $body_nonce   && wp_verify_nonce( $body_nonce,   'credoq_nonce' ) );
        if ( ! $nonce_ok ) {
            return new \WP_REST_Response( [
                'success' => false,
                'data'    => [ 'message' => 'Security check failed. Please reload and try again.' ],
            ], 403 );
        }

        // Copy REST params into $_POST so Booking_Handler can read them unchanged.
        $params = $req->get_params();
        foreach ( $params as $k => $v ) {
            $_POST[ $k ] = $v;
        }

        // Handle nested form_data object from JSON body
        if ( isset( $params['form_data'] ) && is_array( $params['form_data'] ) ) {
            foreach ( $params['form_data'] as $fk => $fv ) {
                if ( is_array( $fv ) ) {
                    $_POST['form_data'][ $fk ] = $fv;
                } else {
                    $_POST['form_data'][ $fk ] = sanitize_text_field( $fv );
                }
            }
        }

        // Signal to Booking_Handler::submit() that nonce is already verified here.
        if ( ! defined( 'CREDOQ_REST_SUBMISSION' ) ) define( 'CREDOQ_REST_SUBMISSION', true );

        // Run the booking handler and capture its wp_send_json output.
        ob_start();
        try {
            \CredoqAppointments\Ajax\Booking_Handler::submit();
        } catch ( \Throwable $e ) {
            ob_end_clean();
            return new \WP_REST_Response( [
                'success' => false,
                'data'    => [ 'message' => $e->getMessage() ],
            ], 500 );
        }
        $out     = ob_get_clean();
        $decoded = json_decode( $out, true );

        return rest_ensure_response(
            $decoded ?: [ 'success' => false, 'data' => [ 'message' => 'Unknown error.' ] ]
        );
    }

    /* ── Formatter ───────────────────────────────────────────── */

    private static function fmt( array $a ) : array {
        return [
            'id'           => (int)    ( $a['id']           ?? 0  ),
            'title'        => (string) ( $a['title']        ?? '' ),
            'location'     => (string) ( $a['location']     ?? '' ),
            'base_price'   => (float)  ( $a['base_price']   ?? 0  ),
            'duration'     => (int)    ( $a['duration']     ?? 60 ),
            'slot_interval'=> (int)    ( $a['slot_interval']?? 30 ),
        ];
    }
}
