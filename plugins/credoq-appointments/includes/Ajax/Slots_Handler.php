<?php
/**
 * Slots AJAX Handler
 *
 * Registers every AJAX action the React booking widget calls.
 *
 * FIX SUMMARY:
 *  1. Added credoq_get_date_capacity   — widget calls this for calendar dot-marking
 *  2. Added credoq_get_providers_for_service — widget calls this on service select
 *  3. Added credoq_get_services_for_provider — widget calls this in provider-first flow
 *  4. Added credoq_get_services_for_date     — widget calls this in calendar-first flow
 *  5. get_timeslots: widget sends 'selected_date', handler was reading '$_POST[date]'
 *
 * @package CredoqAppointments\Ajax
 */
namespace CredoqAppointments\Ajax;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Slot_Generator;
use CredoqAppointments\Appointment_Repository;
use CredoqAppointments\Staff_Repository;

class Slots_Handler {

    public static function register() : void {
        // Time slots
        add_action( 'wp_ajax_credoq_get_timeslots',        [ __CLASS__, 'get_timeslots' ] );
        add_action( 'wp_ajax_nopriv_credoq_get_timeslots', [ __CLASS__, 'get_timeslots' ] );

        // FIX: widget calls credoq_get_date_capacity for month calendar dot-marking
        add_action( 'wp_ajax_credoq_get_date_capacity',        [ __CLASS__, 'get_date_capacity' ] );
        add_action( 'wp_ajax_nopriv_credoq_get_date_capacity', [ __CLASS__, 'get_date_capacity' ] );

        // Legacy name — keep for backward compat
        add_action( 'wp_ajax_credoq_get_available_dates',        [ __CLASS__, 'get_date_capacity' ] );
        add_action( 'wp_ajax_nopriv_credoq_get_available_dates', [ __CLASS__, 'get_date_capacity' ] );

        // FIX: widget calls credoq_get_providers_for_service when a service is selected
        add_action( 'wp_ajax_credoq_get_providers_for_service',        [ __CLASS__, 'get_providers_for_service' ] );
        add_action( 'wp_ajax_nopriv_credoq_get_providers_for_service', [ __CLASS__, 'get_providers_for_service' ] );

        // FIX: widget calls credoq_get_services_for_provider in provider-first flow
        add_action( 'wp_ajax_credoq_get_services_for_provider',        [ __CLASS__, 'get_services_for_provider' ] );
        add_action( 'wp_ajax_nopriv_credoq_get_services_for_provider', [ __CLASS__, 'get_services_for_provider' ] );

        // FIX: widget calls credoq_get_services_for_date in calendar-first flow
        add_action( 'wp_ajax_credoq_get_services_for_date',        [ __CLASS__, 'get_services_for_date' ] );
        add_action( 'wp_ajax_nopriv_credoq_get_services_for_date', [ __CLASS__, 'get_services_for_date' ] );
    }

    /* ── Time slots ─────────────────────────────────────────────── */

    public static function get_timeslots() : void {
        check_ajax_referer( 'credoq_nonce', 'nonce' );

        $apt_id   = absint( $_POST['appointment_id'] ?? 0 );
        $staff_id = absint( $_POST['staff_id']       ?? 0 );
        // FIX: widget sends 'selected_date', not 'date'
        $date     = sanitize_text_field( $_POST['selected_date'] ?? $_POST['date'] ?? '' );

        if ( ! $apt_id || ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_send_json_error( [ 'message' => 'Invalid parameters.' ] );
        }

        $apt   = Appointment_Repository::find( $apt_id );
        if ( ! $apt ) { wp_send_json_error( [ 'message' => 'Service not found.' ] ); }

        $slots = Slot_Generator::for_date( $apt_id, $staff_id, $date );
        $staff = Staff_Repository::find_by_appointment( $apt_id );

        wp_send_json_success( [
            'slots'      => $slots,
            'staff'      => array_map( fn( $s ) => [
                'id'    => (int) $s->id,
                'name'  => (string) $s->display_name,
                'image' => (string) ( $s->avatar_url ?? '' ),
                'bio'   => (string) ( $s->bio         ?? '' ),
            ], $staff ),
            'duration'   => (int) ( $apt->duration    ?? 60 ),
            'date_price' => (float) ( $apt->base_price ?? 0 ),
            'currency'   => get_option( 'woocommerce_currency', 'USD' ),
        ] );
    }

    /* ── Date capacity / available dates ────────────────────────── */

    /**
     * Returns a map of { "YYYY-MM-DD": available_slots_count } for a whole month.
     * The React Calendar component uses this to mark available dates with dots.
     * Widget calls: action=credoq_get_date_capacity, appointment_id, staff_id, year, month
     */
    public static function get_date_capacity() : void {
        check_ajax_referer( 'credoq_nonce', 'nonce' );

        $apt_id   = absint( $_POST['appointment_id'] ?? 0 );
        $staff_id = absint( $_POST['staff_id']       ?? 0 );
        $year     = absint( $_POST['year']            ?? date( 'Y' ) );
        $month    = absint( $_POST['month']           ?? date( 'n' ) );

        if ( ! $apt_id ) { wp_send_json_error( [ 'message' => 'Invalid appointment.' ] ); }

        // Use Slot_Generator if it has the method, else compute inline
        if ( method_exists( Slot_Generator::class, 'available_dates_in_month' ) ) {
            $dates = Slot_Generator::available_dates_in_month( $apt_id, $staff_id, $year, $month );
        } else {
            $dates = self::compute_available_dates( $apt_id, $staff_id, $year, $month );
        }

        // Widget expects { dates: {"YYYY-MM-DD": count, ...} }
        wp_send_json_success( [ 'dates' => $dates, 'available_dates' => array_keys( $dates ) ] );
    }

    /**
     * Fallback: compute available dates manually if Slot_Generator doesn't have the method.
     */
    private static function compute_available_dates( int $apt_id, int $staff_id, int $year, int $month ) : array {
        $apt = Appointment_Repository::find( $apt_id );
        if ( ! $apt ) return [];

        $availability = json_decode( $apt->availability ?? '{}', true );
        if ( ! is_array( $availability ) ) return [];

        $days_in_month = cal_days_in_month( CAL_GREGORIAN, $month, $year );
        $today         = date( 'Y-m-d' );
        $map           = [];

        $day_names = [ 0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
                       4 => 'thursday', 5 => 'friday', 6 => 'saturday' ];

        for ( $d = 1; $d <= $days_in_month; $d++ ) {
            $date_str = sprintf( '%04d-%02d-%02d', $year, $month, $d );
            if ( $date_str < $today ) continue;

            $dow    = (int) date( 'w', mktime( 0, 0, 0, $month, $d, $year ) );
            $day_av = $availability[ $day_names[ $dow ] ] ?? [];

            if ( ! empty( $day_av['closed'] ) || empty( $day_av['hours'] ) ) continue;

            // Count slots roughly
            $duration = max( 1, (int) ( $apt->duration ?? 60 ) );
            $interval = max( 1, (int) ( $apt->slot_interval ?? $duration ) );
            $count    = 0;
            foreach ( $day_av['hours'] as $block ) {
                $start = strtotime( $block['start'] ?? '09:00' );
                $end   = strtotime( $block['end']   ?? '17:00' );
                if ( $end > $start ) $count += floor( ( $end - $start ) / 60 / $interval );
            }
            if ( $count > 0 ) $map[ $date_str ] = $count;
        }

        return $map;
    }

    /* ── Providers for service ───────────────────────────────────── */

    /**
     * Returns staff/providers for a given appointment_id.
     * Widget calls: action=credoq_get_providers_for_service, appointment_id, nonce
     */
    public static function get_providers_for_service() : void {
        check_ajax_referer( 'credoq_nonce', 'nonce' );

        $apt_id = absint( $_POST['appointment_id'] ?? 0 );
        if ( ! $apt_id ) { wp_send_json_error( [ 'message' => 'Invalid appointment.' ] ); }

        $staff  = Staff_Repository::find_by_appointment( $apt_id );
        $result = array_map( fn( $s ) => [
            'id'    => (int) $s->id,
            'name'  => (string) $s->display_name,
            'bio'   => (string) ( $s->bio       ?? '' ),
            'image' => (string) ( $s->avatar_url ?? '' ),
        ], $staff );

        // FIX: If no staff exist at all, return a sentinel "Any Available" provider
        // so the widget provider step shows something meaningful instead of
        // staying stuck on "Preparing..." forever.
        // The widget will send staff_id=0 when "Any" is selected, which the
        // booking handler already handles as "assign any available staff".
        if ( empty( $result ) ) {
            $result = [
                [
                    'id'    => 0,
                    'name'  => __( 'Any Available', 'credoq-appointments' ),
                    'bio'   => __( 'First available specialist will be assigned.', 'credoq-appointments' ),
                    'image' => '',
                ],
            ];
        }

        wp_send_json_success( [ 'providers' => $result ] );
    }

    /* ── Services for provider ───────────────────────────────────── */

    /**
     * Returns services/appointments assigned to a staff member.
     * Widget calls: action=credoq_get_services_for_provider, staff_id, nonce
     */
    public static function get_services_for_provider() : void {
        check_ajax_referer( 'credoq_nonce', 'nonce' );

        $staff_id = absint( $_POST['staff_id'] ?? 0 );
        if ( ! $staff_id ) { wp_send_json_error( [ 'message' => 'Invalid staff.' ] ); }

        global $wpdb;
        $tbl = $wpdb->prefix . 'credoq_appointments';

        // Find appointments whose staff_ids JSON array contains this staff_id
        $all_apts = $wpdb->get_results(
            "SELECT id, title, location, base_price, duration, slot_interval FROM {$tbl} ORDER BY title ASC",
            ARRAY_A
        );

        $services = [];
        foreach ( (array) $all_apts as $apt ) {
            $ids = json_decode( $apt['staff_ids'] ?? '[]', true );
            if ( is_array( $ids ) && in_array( $staff_id, array_map( 'intval', $ids ), true ) ) {
                $services[] = [
                    'id'         => (int)    $apt['id'],
                    'title'      => (string) $apt['title'],
                    'location'   => (string) $apt['location'],
                    'base_price' => (float)  $apt['base_price'],
                    'duration'   => (int)    $apt['duration'],
                ];
            }
        }

        wp_send_json_success( [ 'services' => $services ] );
    }

    /* ── Services for date ───────────────────────────────────────── */

    /**
     * Returns services available on a given date (calendar-first flow).
     * Widget calls: action=credoq_get_services_for_date, date, nonce
     */
    public static function get_services_for_date() : void {
        check_ajax_referer( 'credoq_nonce', 'nonce' );

        $date = sanitize_text_field( $_POST['date'] ?? '' );
        if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_send_json_error( [ 'message' => 'Invalid date.' ] );
        }

        global $wpdb;
        $tbl     = $wpdb->prefix . 'credoq_appointments';
        $all_apts = $wpdb->get_results(
            "SELECT id, title, location, base_price, duration, slot_interval, availability FROM {$tbl} ORDER BY title ASC",
            ARRAY_A
        );

        $dow_names = [ 0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
                       4 => 'thursday', 5 => 'friday', 6 => 'saturday' ];
        $dow        = (int) date( 'w', strtotime( $date ) );
        $day_name   = $dow_names[ $dow ];

        $services = [];
        foreach ( (array) $all_apts as $apt ) {
            $av      = json_decode( $apt['availability'] ?? '{}', true );
            $day_av  = is_array( $av ) ? ( $av[ $day_name ] ?? [] ) : [];
            if ( ! empty( $day_av['closed'] ) || empty( $day_av['hours'] ) ) continue;
            $services[] = [
                'id'         => (int)    $apt['id'],
                'title'      => (string) $apt['title'],
                'location'   => (string) $apt['location'],
                'base_price' => (float)  $apt['base_price'],
                'duration'   => (int)    $apt['duration'],
            ];
        }

        wp_send_json_success( [ 'services' => $services ] );
    }
}
