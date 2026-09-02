<?php
namespace CredoqEvents;
defined( 'ABSPATH' ) || exit;

class Event_Repository {
    private static array $cache = [];

    public static function find( int $id ) : ?object {
        if ( isset( self::$cache[$id] ) ) return self::$cache[$id];
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_events WHERE id = %d", $id ) );
        if ( $row ) self::$cache[$id] = $row;
        return $row ?: null;
    }

    public static function all( array $args = [] ) : array {
        global $wpdb;
        $per    = absint( $args['per_page'] ?? 50 );
        $offset = absint( $args['offset']   ?? 0 );
        $future = ! empty( $args['upcoming_only'] );
        $where  = $future ? "WHERE start_datetime >= NOW()" : "WHERE 1=1";
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_events {$where}
             ORDER BY start_datetime ASC LIMIT %d OFFSET %d", $per, $offset ) );
    }

    public static function count( bool $upcoming_only = false ) : int {
        global $wpdb;
        $where = $upcoming_only ? "WHERE start_datetime >= NOW()" : "";
        return intval( $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_events {$where}" ) );
    }

    public static function find_by_product( int $product_id ) : ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_events WHERE wc_product_id = %d LIMIT 1",
            $product_id ) ) ?: null;
    }

    public static function save( array $data ) : int {
        global $wpdb;
        $table = $wpdb->prefix . 'credoq_events';
        $id    = intval( $data['id'] ?? 0 );
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
        $wpdb->delete( $wpdb->prefix . 'credoq_events', ['id' => $id] );
        unset( self::$cache[$id] );
    }

    public static function booked_count( int $event_id ) : int {
        global $wpdb;
        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}credoq_event_bookings
             WHERE event_id = %d AND status NOT IN ('cancelled','refunded')",
            $event_id ) ) );
    }

    /**
     * AUDIT-FEATURE (Concurrency): find all events a staff member is
     * assigned to on a specific date. Used by Appointments to block
     * slots where the staff member is already leading an event.
     */
    public static function find_for_staff_on_date( int $staff_id, string $date ) : array {
        global $wpdb;
        if ( ! $staff_id ) return [];
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT title, start_datetime, end_datetime
             FROM {$wpdb->prefix}credoq_events
             WHERE staff_id = %d
               AND ( DATE(start_datetime) = %s OR DATE(end_datetime) = %s )
               AND status = 'published'
             ORDER BY start_datetime ASC",
            $staff_id, $date, $date ) );
    }
}
