<?php
namespace CredoqAppointments\Cron;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Booking_Repository;
use CredoqAppointments\Waiting_List_Repository;
use CredoqAppointments\Notifications\Booking_Mailer;

class Apt_Cron {

    public static function schedule() : void {
        if ( ! wp_next_scheduled('credoq_apt_pending_expiry') ) {
            wp_schedule_event( time(), 'hourly', 'credoq_apt_pending_expiry' );
        }
        if ( ! wp_next_scheduled('credoq_apt_waiting_list_check') ) {
            wp_schedule_event( time(), 'credoq_15min', 'credoq_apt_waiting_list_check' );
        }
        if ( ! wp_next_scheduled('credoq_apt_reminders') ) {
            wp_schedule_event( strtotime('tomorrow 08:00:00'), 'daily', 'credoq_apt_reminders' );
        }
    }

    public static function clear() : void {
        foreach ( ['credoq_apt_pending_expiry','credoq_apt_waiting_list_check','credoq_apt_reminders'] as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
    }

    public static function register_hooks() : void {
        // Register custom 15-min interval
        add_filter( 'cron_schedules', function( $schedules ) {
            if ( ! isset($schedules['credoq_15min']) ) {
                $schedules['credoq_15min'] = [ 'interval' => 900, 'display' => 'Every 15 minutes' ];
            }
            return $schedules;
        });
        self::schedule();
        add_action( 'credoq_apt_pending_expiry',       [ __CLASS__, 'expire_pending_bookings' ] );
        add_action( 'credoq_apt_waiting_list_check',   [ __CLASS__, 'check_waiting_list' ] );
        add_action( 'credoq_apt_reminders',            [ __CLASS__, 'send_reminders' ] );
    }

    public static function expire_pending_bookings() : void {
        global $wpdb;
        $cutoff = wp_date('Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS);
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}credoq_bookings
             SET status='cancelled' WHERE status='pending_payment' AND created_at < %s",
            $cutoff
        ) );
    }

    public static function check_waiting_list() : void {
        Waiting_List_Repository::expire_offers();
    }

    /** AUDIT-FIX C-12: reminder_sent flag prevents duplicates even if cron runs twice. */
    public static function send_reminders() : void {
        global $wpdb;
        $tomorrow = wp_date('Y-m-d', strtotime('+1 day'));
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}credoq_bookings
             WHERE selected_date = %s AND status = 'confirmed' AND reminder_sent = 0",
            $tomorrow
        ) );
        foreach ( $bookings as $b ) {
            // AUDIT-FIX C-12: mark sent BEFORE sending — safe against double-fire
            $updated = $wpdb->update(
                $wpdb->prefix . 'credoq_bookings',
                ['reminder_sent' => 1],
                ['id' => intval($b->id)],
                ['%d'], ['%d']
            );
            if ( false === $updated ) continue;
            Booking_Mailer::send( intval($b->id), 'reminder' );
        }
    }
}
