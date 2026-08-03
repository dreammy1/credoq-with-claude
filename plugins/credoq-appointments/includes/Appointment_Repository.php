<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

class Appointment_Repository {

    /** @var array<int,object> AUDIT-FIX C-8: static memoize */
    private static array $cache = [];

    public static function find( int $id ) : ?object {
        if ( isset( self::$cache[$id] ) ) return self::$cache[$id]; // AUDIT-FIX C-8
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_appointments WHERE id = %d", $id ) );
        if ( $row ) self::$cache[$id] = $row;
        return $row ?: null;
    }

    public static function all( int $per_page = 100, int $offset = 0 ) : array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_appointments ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page, $offset ) );
    }

    public static function find_by_product( int $product_id ) : ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_appointments WHERE wc_product_id = %d LIMIT 1",
            $product_id ) ) ?: null;
    }

    public static function count() : int {
        global $wpdb;
        return intval( $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_appointments" ) );
    }

    public static function save( array $data ) : int {
        global $wpdb;
        $table = $wpdb->prefix . 'credoq_appointments';

        foreach ( ['staff_ids','availability','booking_settings'] as $json_field ) {
            if ( isset( $data[$json_field] ) && is_array( $data[$json_field] ) ) {
                $data[$json_field] = wp_json_encode( $data[$json_field] );
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
        $wpdb->delete( $wpdb->prefix . 'credoq_appointments', ['id' => $id] );
        unset( self::$cache[$id] );
    }
}
