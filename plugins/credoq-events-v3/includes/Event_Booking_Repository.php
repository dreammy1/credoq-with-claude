<?php
namespace CredoqEvents;
defined( 'ABSPATH' ) || exit;

class Event_Booking_Repository {

    public static function find( int $id ) : ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_event_bookings WHERE id = %d", $id ) ) ?: null;
    }

    public static function get_for_event( int $event_id, int $per = 50, int $offset = 0 ) : array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT eb.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}credoq_event_bookings eb
             LEFT JOIN {$wpdb->users} u ON eb.user_id = u.ID
             WHERE eb.event_id = %d
             ORDER BY eb.created_at DESC LIMIT %d OFFSET %d",
            $event_id, $per, $offset ) );
    }

    public static function get_for_user( int $user_id ) : array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT eb.*, e.title AS event_title, e.start_datetime, e.location
             FROM {$wpdb->prefix}credoq_event_bookings eb
             LEFT JOIN {$wpdb->prefix}credoq_events e ON eb.event_id = e.id
             WHERE eb.user_id = %d ORDER BY e.start_datetime ASC",
            $user_id ) );
    }

    public static function insert( array $data ) : int {
        global $wpdb;
        $data['created_at'] = current_time('mysql');
        $wpdb->insert( $wpdb->prefix . 'credoq_event_bookings', $data );
        return intval( $wpdb->insert_id );
    }

    public static function update_status( int $id, string $status ) : void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'credoq_event_bookings',
            ['status' => sanitize_text_field($status)],
            ['id'     => $id], ['%s'], ['%d'] );
    }

    /**
     * AUDIT-FIX (WC checkout redirect for Event Registration form field):
     * find every booking row created from a given Engine form submission
     * — a submission can contain multiple selected events, so this can
     * return more than one row.
     */
    public static function find_by_submission( int $submission_id ) : array {
        global $wpdb;
        if ( ! $submission_id ) return [];
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_event_bookings WHERE submission_id = %d",
            $submission_id ) );
    }

    /** Update the status of every booking tied to a given submission. */
    public static function update_status_by_submission( int $submission_id, string $status, int $wc_order_id = 0 ) : void {
        global $wpdb;
        if ( ! $submission_id ) return;
        $data   = [ 'status' => sanitize_text_field( $status ) ];
        $format = [ '%s' ];
        if ( $wc_order_id ) {
            $data['wc_order_id'] = $wc_order_id;
            $format[] = '%d';
        }
        $wpdb->update( $wpdb->prefix . 'credoq_event_bookings',
            $data, [ 'submission_id' => $submission_id ], $format, [ '%d' ] );
    }

    public static function paginated( int $per, int $offset, array $filters = [] ) : array {
        global $wpdb;
        $where = 'WHERE 1=1'; $args = [];
        if ( ! empty($filters['event_id']) ) { $where .= ' AND eb.event_id=%d'; $args[] = intval($filters['event_id']); }
        if ( ! empty($filters['status'])   ) { $where .= ' AND eb.status=%s';    $args[] = $filters['status']; }
        $args[] = $per; $args[] = $offset;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT eb.*, e.title AS event_title, u.display_name, u.user_email
             FROM {$wpdb->prefix}credoq_event_bookings eb
             LEFT JOIN {$wpdb->prefix}credoq_events e ON eb.event_id=e.id
             LEFT JOIN {$wpdb->users} u ON eb.user_id=u.ID
             {$where} ORDER BY eb.created_at DESC LIMIT %d OFFSET %d",
            ...$args ) );
    }
}
