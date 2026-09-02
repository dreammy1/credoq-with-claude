<?php
namespace CredoqAppointments\Admin;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Appointment_Repository;
use CredoqAppointments\Booking_Repository;
use CredoqAppointments\Booking_Service;
use CredoqAppointments\Staff_Repository;

class Bookings_Page {

    const PER_PAGE = 20;
    const STATUSES = [
        'pending_payment' => ['label' => 'Pending Payment', 'color' => '#7c3aed', 'bg' => '#f5f3ff'],
        'confirmed'       => ['label' => 'Confirmed',       'color' => '#10b981', 'bg' => '#f0fdf4'],
        'cancelled'       => ['label' => 'Cancelled',       'color' => '#dc2626', 'bg' => '#fff1f2'],
        'completed'       => ['label' => 'Completed',       'color' => '#475569', 'bg' => '#f8fafc'],
        'rejected'        => ['label' => 'Rejected',        'color' => '#9f1239', 'bg' => '#fff1f2'],
    ];

    public static function render() : void {
        // SECURITY FIX: Check capability at entry point BEFORE any data operations
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        self::handle_mutations();

        $action = sanitize_key( $_GET['action'] ?? 'list' );
        if ( 'calendar' === $action ) {
            self::render_calendar();
            return;
        }
        if ( 'view' === $action ) {
            self::render_view( absint( $_GET['id'] ?? 0 ) );
            return;
        }
        if ( 'edit' === $action ) {
            self::render_edit( absint( $_GET['id'] ?? 0 ) );
            return;
        }
        if ( 'add' === $action ) {
            self::render_add();
            return;
        }
        self::render_list();
    }

    /* ════════════════════════════════════════════════════════════
       MUTATION HANDLERS
    ════════════════════════════════════════════════════════════ */

    private static function handle_mutations() : void {
        global $wpdb;
        $table = $wpdb->prefix . 'credoq_bookings';

        // ── CSV import ───────────────────────────────────────────
        if ( isset( $_POST['credoq_import_bookings'] ) ) {
            // SECURITY FIX: Check admin referer for POST actions
            check_admin_referer( 'credoq_import_bookings' );
            $msg = self::handle_csv_import();
            add_action( 'admin_notices', function() use ( $msg ) {
                echo "<div class='notice notice-success is-dismissible'><p>" . esc_html( $msg ) . "</p></div>";
            });
        }

        // ── CSV export ───────────────────────────────────────────
        if ( isset( $_GET['action'] ) && 'export_csv' === $_GET['action'] ) {
            // SECURITY FIX: Check admin referer for GET export actions
            if ( ! isset( $_GET['_wpnonce'] ) ) {
                wp_die( 'Missing nonce verification' );
            }
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
            if ( wp_verify_nonce( $nonce, 'credoq_export_bookings' ) ) {
                self::export_csv();
                exit;
            } else {
                wp_die( 'Nonce verification failed' );
            }
        }

        // ── Single quick actions (confirm/cancel/delete) ─────────
        if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) ) {
            $act = sanitize_key( $_GET['action'] );
            $id  = absint( $_GET['id'] );
            // SECURITY FIX: Verify nonce with action + id to prevent reuse
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
            if ( wp_verify_nonce( $nonce, 'credoq_booking_action_' . $act . '_' . $id ) ) {
                if ( 'confirm' === $act ) {
                    Booking_Service::confirm( $id );
                } elseif ( 'cancel' === $act ) {
                    Booking_Service::cancel( $id );
                } elseif ( 'delete' === $act ) {
                    Booking_Repository::delete( $id );
                }
                wp_safe_redirect( add_query_arg( ['page' => 'credoq-bookings', 'done' => $act], admin_url( 'admin.php' ) ) );
                exit;
            } else {
                wp_die( 'Nonce verification failed' );
            }
        }

        // ── Bulk actions ─────────────────────────────────────────
        if ( isset( $_POST['credoq_bulk'], $_POST['ids'] ) ) {
            // SECURITY FIX: Check admin referer for POST bulk actions
            check_admin_referer( 'credoq_bulk_bookings' );
            $bulk = sanitize_key( $_POST['credoq_bulk'] );
            $ids  = array_filter( array_map( 'absint', (array) $_POST['ids'] ) );
            if ( $ids ) {
                $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                if ( 'delete' === $bulk ) {
                    // SECURITY FIX: Use $wpdb->prepare with placeholders
                    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$ph})", $ids ) );
                } elseif ( isset( self::STATUSES[ str_replace( 'status_', '', $bulk ) ] ) ) {
                    $status = str_replace( 'status_', '', $bulk );
                    // SECURITY FIX: Use $wpdb->prepare for all placeholders
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table} SET status=%s, updated_at=%s WHERE id IN ({$ph})",
                        array_merge( [$status, current_time( 'mysql' )], $ids )
                    ));
                }
            }
            wp_safe_redirect( add_query_arg( ['page' => 'credoq-bookings', 'bulk_done' => 1], admin_url( 'admin.php' ) ) );
            exit;
        }

        // ── Save edit / add ──────────────────────────────────────
        if ( isset( $_POST['credoq_save_booking'] ) ) {
            $id = absint( $_POST['booking_id'] ?? 0 );
            // SECURITY FIX: Use form-specific nonce with booking ID
            check_admin_referer( 'credoq_save_booking_' . $id );
            self::save_booking( $id );
            wp_safe_redirect( add_query_arg( ['page' => 'credoq-bookings', 'action' => 'view', 'id' => $id, 'saved' => 1], admin_url( 'admin.php' ) ) );
            exit;
        }
        if ( isset( $_POST['credoq_add_booking'] ) ) {
            // SECURITY FIX: Check admin referer for POST add actions
            check_admin_referer( 'credoq_add_booking' );
            $new_id = self::create_booking();
            wp_safe_redirect( add_query_arg( ['page' => 'credoq-bookings', 'action' => 'view', 'id' => $new_id, 'saved' => 1], admin_url( 'admin.php' ) ) );
            exit;
        }
    }
}
