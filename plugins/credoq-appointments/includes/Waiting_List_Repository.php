<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

class Waiting_List_Repository {

    public static function add( int $apt_id, int $staff_id, string $date, string $time, int $user_id, string $email ) : int {
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}credoq_waiting_list
             WHERE appointment_id=%d AND booking_date=%s AND booking_time=%s AND user_id=%d AND status='waiting'",
            $apt_id, $date, $time, $user_id ) );
        if ( $existing ) return intval( $existing );

        $wpdb->insert( $wpdb->prefix . 'credoq_waiting_list', [
            'appointment_id' => $apt_id,
            'staff_id'       => $staff_id,
            'booking_date'   => $date,
            'booking_time'   => $time,
            'user_id'        => $user_id,
            'guest_email'    => sanitize_email( $email ),
            'status'         => 'waiting',
            'created_at'     => current_time( 'mysql' ),
        ] );
        return intval( $wpdb->insert_id );
    }

    public static function offer_next( int $apt_id, int $staff_id, string $date, string $time ) : void {
        global $wpdb;
        $next = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_waiting_list
             WHERE appointment_id=%d AND booking_date=%s AND booking_time=%s AND status='waiting'
             ORDER BY created_at ASC LIMIT 1",
            $apt_id, $date, $time ) );
        if ( ! $next ) return;

        $expires_at = wp_date( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS );
        $wpdb->update( $wpdb->prefix . 'credoq_waiting_list',
            [ 'status' => 'offered', 'offer_sent_at' => current_time('mysql'), 'expires_at' => $expires_at ],
            [ 'id' => intval( $next->id ) ] );

        // Notify user
        $apt = Appointment_Repository::find( $apt_id );
        if ( $next->user_id && $apt ) {
            $msg = sprintf( 'A slot opened for %s on %s at %s. You have 2 hours to confirm.',
                $apt->title,
                date_i18n( get_option('date_format'), strtotime($date) ),
                date_i18n( 'H:i', strtotime($time) )
            );
            if ( class_exists( '\CredoqEngine\Mail\Notifications' ) ) {
                \CredoqEngine\Mail\Notifications::create( 'waiting_list', 'Slot Available!', $msg, admin_url('admin.php?page=credoq-bookings') );
            }
            if ( $next->guest_email && class_exists( '\CredoqEngine\Mail\Mailer' ) ) {
                \CredoqEngine\Mail\Mailer::send( $next->guest_email, 'Slot Available – ' . ( $apt->title ?? '' ), $msg );
            } elseif ( $next->guest_email ) {
                wp_mail( $next->guest_email, 'Slot Available – ' . ( $apt->title ?? '' ), $msg );
            }
        }
    }

    public static function expire_offers() : void {
        global $wpdb;
        $wpdb->query(
            "UPDATE {$wpdb->prefix}credoq_waiting_list
             SET status='expired' WHERE status='offered' AND expires_at < NOW()"
        );
    }

    public static function get_for_slot( int $apt_id, string $date, string $time ) : array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT w.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}credoq_waiting_list w
             LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID
             WHERE w.appointment_id=%d AND w.booking_date=%s AND w.booking_time=%s
             ORDER BY w.created_at ASC",
            $apt_id, $date, $time ) );
    }
}
