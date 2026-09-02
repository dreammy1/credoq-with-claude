<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

class Booking_Repository {

    public static function find( int $id ) : ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_bookings WHERE id = %d", $id ) ) ?: null;
    }

    public static function find_by_group( string $group_id ) : array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_bookings WHERE group_id = %s ORDER BY group_index ASC",
            $group_id ) );
    }

    public static function count_slot( int $appointment_id, int $staff_id, string $date, string $time ) : int {
        global $wpdb;
        $staff_clause = $staff_id > 0 ? $wpdb->prepare( " AND staff_id = %d", $staff_id ) : '';
        // AUDIT-FIX (Bug: slot count never deducts):
        // WC-path bookings sit in 'pending_payment' until the order is paid.
        // They still occupy the slot (the seat is reserved). Only exclude
        // truly dead statuses — 'cancelled' and 'failed'.
        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_bookings
             WHERE appointment_id = %d AND selected_date = %s AND selected_time = %s
               AND status NOT IN ('cancelled','failed') {$staff_clause}",
            $appointment_id, $date, $time ) ) );
    }

    public static function get_booked_slots( int $appointment_id, int $staff_id, string $date ) : array {
        global $wpdb;
        $staff_clause = $staff_id > 0 ? $wpdb->prepare( " AND staff_id = %d", $staff_id ) : '';
        // AUDIT-FIX (Bug: slot count never deducts):
        // Include pending_payment in the booked count — these are reserved
        // slots that are awaiting WooCommerce payment. Only 'cancelled' and
        // 'failed' free the slot back up. 'refunded' is treated the same
        // way as cancelled (freed) by on_cancel(), so it's excluded too.
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT selected_time, COUNT(*) as count
             FROM {$wpdb->prefix}credoq_bookings
             WHERE appointment_id = %d AND selected_date = %s
               AND status NOT IN ('cancelled','failed','refunded') {$staff_clause}
             GROUP BY selected_time",
            $appointment_id, $date ) );
    }

    public static function get_user_bookings( int $user_id, bool $upcoming_only = false ) : array {
        global $wpdb;
        $clause = $upcoming_only ? "AND b.selected_date >= CURDATE()" : '';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, a.title AS apt_title, a.location AS apt_location
             FROM {$wpdb->prefix}credoq_bookings b
             LEFT JOIN {$wpdb->prefix}credoq_appointments a ON b.appointment_id = a.id
             WHERE b.user_id = %d {$clause}
             ORDER BY b.selected_date DESC, b.selected_time DESC",
            $user_id ) );
    }

    /** AUDIT-FIX B-2: paginated list query */
    public static function paginated( array $filters = [], int $per_page = 20, int $offset = 0 ) : array {
        global $wpdb;
        $where   = 'WHERE 1=1';
        $args    = [];
        if ( ! empty( $filters['status'] ) ) {
            $where .= ' AND b.status = %s'; $args[] = $filters['status'];
        }
        if ( ! empty( $filters['appointment_id'] ) ) {
            $where .= ' AND b.appointment_id = %d'; $args[] = intval($filters['appointment_id']);
        }
        if ( ! empty( $filters['staff_id'] ) ) {
            $where .= ' AND b.staff_id = %d'; $args[] = intval($filters['staff_id']);
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where .= ' AND b.selected_date >= %s'; $args[] = $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where .= ' AND b.selected_date <= %s'; $args[] = $filters['date_to'];
        }
        if ( ! empty( $filters['s'] ) ) {
            $like   = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
            $where .= ' AND (b.guest_email LIKE %s OR b.guest_name LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $args[] = $like; $args[] = $like; $args[] = $like; $args[] = $like;
        }
        $args[] = $per_page;
        $args[] = $offset;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, a.title AS apt_title, s.display_name AS staff_name,
                    u.display_name AS user_name, u.user_email
             FROM {$wpdb->prefix}credoq_bookings b
             LEFT JOIN {$wpdb->prefix}credoq_appointments a ON b.appointment_id = a.id
             LEFT JOIN {$wpdb->prefix}credoq_staff s ON b.staff_id = s.id
             LEFT JOIN {$wpdb->users} u ON b.user_id = u.ID
             {$where}
             ORDER BY b.selected_date DESC, b.selected_time DESC
             LIMIT %d OFFSET %d", ...$args ) );
    }

    public static function count_with_filters( array $filters = [] ) : int {
        global $wpdb;
        $where = 'WHERE 1=1'; $args = [];
        if ( ! empty($filters['status']) )         { $where .= ' AND status = %s';             $args[] = $filters['status']; }
        if ( ! empty($filters['appointment_id']) ) { $where .= ' AND appointment_id = %d';     $args[] = intval($filters['appointment_id']); }
        if ( ! empty($filters['staff_id']) )       { $where .= ' AND staff_id = %d';           $args[] = intval($filters['staff_id']); }
        if ( ! empty($filters['date_from']) )      { $where .= ' AND selected_date >= %s';     $args[] = $filters['date_from']; }
        if ( ! empty($filters['date_to']) )        { $where .= ' AND selected_date <= %s';     $args[] = $filters['date_to']; }
        if ( ! empty($filters['s']) ) {
            $like   = '%' . $wpdb->esc_like($filters['s']) . '%';
            $where .= ' AND (guest_email LIKE %s OR guest_name LIKE %s)';
            $args[] = $like; $args[] = $like;
        }
        if ( empty($args) ) {
            return intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_bookings" ) );
        }
        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_bookings b {$where}", ...$args ) ) );
    }

    public static function update_status( int $id, string $status ) : bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'credoq_bookings',
            ['status' => sanitize_text_field($status)],
            ['id' => $id], ['%s'], ['%d'] );
    }

    public static function insert( array $data ) : int {
        global $wpdb;
        if ( isset($data['form_data']) && is_array($data['form_data']) ) {
            $data['form_data'] = wp_json_encode($data['form_data']);
        }
        if ( isset($data['seat_ids']) && is_array($data['seat_ids']) ) {
            $data['seat_ids'] = wp_json_encode($data['seat_ids']);
        }
        $data['created_at'] = current_time('mysql');
        $wpdb->insert( $wpdb->prefix . 'credoq_bookings', $data );
        return intval( $wpdb->insert_id );
    }

    public static function delete( int $id ) : void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'credoq_bookings', ['id' => $id] );
    }

    /**
     * AUDIT-FEATURE (Concurrency): find all appointments for a staff member
     * within a specific datetime range. Used by Events to block
     * registration if the staff member is already in an appointment.
     */
    public static function find_for_staff_in_range( int $staff_id, string $start_dt, string $end_dt ) : array {
        global $wpdb;
        if ( ! $staff_id ) return [];

        $date = substr( $start_dt, 0, 10 );
        // We look for any booking on the same date where the times overlap.
        // Appointment busy window = [selected_time, selected_time + duration].
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, selected_time, duration
             FROM {$wpdb->prefix}credoq_bookings
             WHERE staff_id = %d
               AND selected_date = %s
               AND status NOT IN ('cancelled','failed','refunded')",
            $staff_id, $date ) );
    }
}
