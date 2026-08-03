<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

class Staff_Repository {

    private static array $cache = [];

    public static function find( int $id ) : ?object {
        if ( isset( self::$cache[$id] ) ) return self::$cache[$id];
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_staff WHERE id = %d", $id ) );
        if ( $row ) self::$cache[$id] = $row;
        return $row ?: null;
    }

    public static function all( int $per_page = 100, int $offset = 0 ) : array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_staff ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page, $offset ) );
    }

    public static function find_by_appointment( int $appointment_id ) : array {
        global $wpdb;
        $apt = Appointment_Repository::find( $appointment_id );
        if ( ! $apt ) return [];
        $staff_ids = json_decode( $apt->staff_ids ?? '[]', true );
        if ( empty( $staff_ids ) ) return self::all();
        $ids_str   = implode( ',', array_map( 'intval', $staff_ids ) );
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}credoq_staff WHERE id IN ($ids_str)" );
    }

    public static function get_availability( object $staff ) : array {
        $a = json_decode( $staff->availability ?? '{}', true );
        return is_array( $a ) ? $a : [];
    }

    public static function get_special_dates( object $staff ) : array {
        $d = json_decode( $staff->special_dates ?? '[]', true );
        return is_array( $d ) ? $d : [];
    }

    /**
     * AUDIT-FIX (Special Dates pricing): look up the per-date price entered
     * on the Staff edit page's "Special Dates / Overrides" grid for the
     * given date. Returns null if no entry / no price set for that date.
     */
    public static function get_special_date_price( object $staff, string $date ) : ?float {
        foreach ( self::get_special_dates( $staff ) as $sd ) {
            if ( ! is_array( $sd ) || ( $sd['date'] ?? '' ) !== $date ) continue;
            if ( isset( $sd['price'] ) && $sd['price'] !== '' && $sd['price'] !== null ) {
                return (float) $sd['price'];
            }
            return null;
        }
        return null;
    }

    public static function save( array $data ) : int {
        global $wpdb;
        $table = $wpdb->prefix . 'credoq_staff';
        foreach ( ['availability','special_dates'] as $f ) {
            if ( isset( $data[$f] ) && is_array( $data[$f] ) ) {
                $data[$f] = wp_json_encode( $data[$f] );
            }
        }
        $id = intval( $data['id'] ?? 0 );
        if ( $id > 0 ) {
            unset( $data['id'], $data['created_at'] );
            $wpdb->update( $table, $data, ['id' => $id] );
            unset( self::$cache[$id] );
            return $id;
        }
        $data['created_at'] = current_time('mysql');
        $wpdb->insert( $table, $data );
        return intval( $wpdb->insert_id );
    }

    public static function delete( int $id ) : void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'credoq_staff', ['id' => $id] );
        unset( self::$cache[$id] );
    }
}
