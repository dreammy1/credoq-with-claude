<?php
/**
 * Appointments_Bridge.
 *
 * Credoq Appointments doesn't run submissions through the Engine's generic
 * pipeline — it has its own Booking_Service that writes straight to
 * wp_credoq_bookings (including a `seat_ids` column already reserved for
 * this addon). So instead of a Field_Type::on_submission() hook, this
 * bridge listens to Appointments' own lifecycle hooks:
 *
 *   credoq_widget_config    — inject visual_seats_enabled / seat_plan_id
 *                              into the React widget config so the seat
 *                              map field can turn itself on.
 *   credoq_booking_confirmed — convert held/pending seats into confirmed
 *                              seat_bookings rows tied to this booking.
 *   credoq_booking_cancelled — release those seats.
 *
 * Entirely additive: Credoq Appointments is never modified by this file
 * (aside from the small, self-guarded settings panel added directly to
 * its admin edit screen — see credoq-appointments/includes/Admin/Appointments_Page.php).
 *
 * @package CredoqSeats\Integrations
 */

namespace CredoqSeats\Integrations;

use CredoqSeats\Repositories\Booking_Repository;

defined( 'ABSPATH' ) || exit;

class Appointments_Bridge {

	public static function register() : void {
		if ( ! class_exists( '\CredoqAppointments\Plugin' ) ) return; // addon not active — no-op.

		add_filter( 'credoq_widget_config', array( __CLASS__, 'inject_widget_config' ), 20, 2 );
		add_action( 'credoq_booking_confirmed', array( __CLASS__, 'on_confirmed' ), 10, 1 );
		add_action( 'credoq_booking_cancelled', array( __CLASS__, 'on_cancelled' ), 10, 1 );
	}

	public static function inject_widget_config( array $config, $form ) : array {
		global $wpdb;
		$apt_table = $wpdb->prefix . 'credoq_appointments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $apt_table ) ) !== $apt_table ) return $config;

		if ( ! empty( $config['appointments'] ) && is_array( $config['appointments'] ) ) {
			foreach ( $config['appointments'] as &$apt ) {
				$s = self::seats_settings_for( (int) ( $apt['id'] ?? 0 ) );
				$apt['visual_seats_enabled'] = $s['visual_seats_enabled'];
				$apt['seat_plan_id']         = $s['seat_plan_id'];
			}
			unset( $apt );
		}

		$apt_id = (int) ( $config['appointment_id'] ?? 0 );
		if ( $apt_id ) {
			$s = self::seats_settings_for( $apt_id );
			$config['visual_seats_enabled'] = $s['visual_seats_enabled'];
			$config['seat_plan_id']         = $s['seat_plan_id'];
		}

		return $config;
	}

	private static function seats_settings_for( int $apt_id ) : array {
		global $wpdb;
		if ( ! $apt_id ) return array( 'visual_seats_enabled' => 0, 'seat_plan_id' => 0 );

		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT booking_settings FROM {$wpdb->prefix}credoq_appointments WHERE id = %d", $apt_id
		) );
		$decoded = json_decode( (string) $raw, true ) ?: array();
		return array(
			'visual_seats_enabled' => (int) ( $decoded['visual_seats_enabled'] ?? 0 ),
			'seat_plan_id'         => (int) ( $decoded['seat_plan_id'] ?? 0 ),
		);
	}

	public static function on_confirmed( int $booking_id ) : void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}credoq_bookings WHERE id = %d", $booking_id
		) );
		if ( ! $row ) {
			self::log( 'skip: booking #' . $booking_id . ' row not found', $booking_id );
			return;
		}

		// Bell notification + admin email — fires for every confirmed
		// appointment booking (seat-map or not). Appointments' own booking
		// flow bypasses the Engine's generic submission pipeline (its own
		// Booking_Service, not credoq_after_submission), which is exactly
		// what the Engine's Mail\Submission_Notifier listens for — so
		// without this, appointment bookings never notified anyone at all.
		self::notify( $row, $booking_id );

		$seat_ids = json_decode( $row->seat_ids ?? '[]', true ) ?: array();
		if ( empty( $seat_ids ) ) {
			self::log( 'skip: credoq_bookings.seat_ids is empty for booking #' . $booking_id, $booking_id );
			return;
		}

		$s = self::seats_settings_for( (int) $row->appointment_id );
		if ( ! $s['seat_plan_id'] ) {
			self::log( 'skip: appointment #' . $row->appointment_id . ' has no seat_plan_id configured (visual_seats_enabled=' . $s['visual_seats_enabled'] . ')', $booking_id );
			return;
		}

		// AUDIT-FIX (P1 — same averaging bug fixed for Events, applied
		// here): this used to pass a single flat 'price_each' computed as
		// total_price / count(seats) — an AVERAGE that silently overwrote
		// each seat's own correct price (already recorded individually at
		// hold time by CredoqSeats\Ajax\Seats_Ajax::credoq_seats_hold())
		// the moment a booking was confirmed, corrupting the Admin > Seat
		// Bookings price column whenever seats have mixed types/overrides.
		// calc_seats_breakdown() resolves each seat's own price (override
		// → its type's plan price → this appointment's base price
		// fallback) instead of one averaged number for the whole batch.
		$base_price = 0.0;
		if ( class_exists( '\CredoqAppointments\Appointment_Repository' ) ) {
			$apt = \CredoqAppointments\Appointment_Repository::find( (int) $row->appointment_id );
			if ( $apt ) $base_price = (float) ( $apt->base_price ?? 0 );
		}
		$breakdown = Booking_Repository::calc_seats_breakdown( $s['seat_plan_id'], array_map( 'absint', $seat_ids ), $base_price );

		Booking_Repository::confirm_seats( $s['seat_plan_id'], array_map( 'absint', $seat_ids ), array(
			'booking_type'   => 'appointment',
			'ref_id'         => $booking_id,
			'appointment_id' => (int) $row->appointment_id,
			'date'           => $row->selected_date ?? current_time( 'Y-m-d' ),
			'time'           => $row->selected_time ?? '',
			'user_id'        => (int) $row->user_id,
			'guest_email'    => (string) ( $row->guest_email ?? '' ),
			'price_map'      => $breakdown['price_map'],
			'wc_order_id'    => (int) ( $row->wc_order_id ?? 0 ),
		) );
		self::log( 'confirmed ' . count( $seat_ids ) . ' seat(s) [' . implode( ',', $seat_ids ) . '] in plan #' . $s['seat_plan_id'] . ' for booking #' . $booking_id, $booking_id );

		$wpdb->update( $wpdb->prefix . 'credoq_bookings', array( 'cvsp_booking_id' => $s['seat_plan_id'] ), array( 'id' => $booking_id ) );
	}

	/** Writes an 'appointments.seat_confirm' audit entry when Credoq Engine's Audit_Log is available. */
	private static function log( string $message, int $booking_id ) : void {
		if ( ! class_exists( '\CredoqEngine\Log\Audit_Log' ) ) return;
		\CredoqEngine\Log\Audit_Log::record( 'appointments.seat_confirm', array(
			'subject' => 'booking #' . $booking_id,
			'message' => $message,
		) );
	}

	private static function notify( object $row, int $booking_id ) : void {
		$apt_title = (string) $row->appointment_id;
		if ( class_exists( '\CredoqAppointments\Appointment_Repository' ) ) {
			$apt = \CredoqAppointments\Appointment_Repository::find( (int) $row->appointment_id );
			if ( $apt ) $apt_title = $apt->title;
		}
		$who  = $row->guest_email ?: ( $row->user_id ? ( get_userdata( $row->user_id )->user_email ?? '' ) : __( 'Guest', 'credoq-seats' ) );
		$when = trim( ( $row->selected_date ?? '' ) . ' ' . ( $row->selected_time ?? '' ) );

		if ( class_exists( '\CredoqEngine\Mail\Notifications' ) ) {
			\CredoqEngine\Mail\Notifications::create(
				'appointment',
				sprintf( __( 'Booking confirmed · #%d', 'credoq-seats' ), $booking_id ),
				sprintf( __( '%s booked "%s" for %s', 'credoq-seats' ), $who, $apt_title, $when ),
				admin_url( 'admin.php?page=credoq-bookings&id=' . $booking_id )
			);
		}

		if ( class_exists( '\CredoqEngine\Mail\Mailer' ) ) {
			$settings = \CredoqEngine\Mail\Mailer::get_settings();
			if ( ! empty( $settings['notify_on_submission'] ) && ! empty( $settings['notify_admin_email'] ) ) {
				\CredoqEngine\Mail\Mailer::send(
					$settings['notify_admin_email'],
					sprintf( '[%s] Booking confirmed · %s', get_bloginfo( 'name' ), $apt_title ),
					sprintf( "Booking #%d confirmed.\n\nService: %s\nWhen: %s\nBy: %s\n\nView: %s",
						$booking_id, $apt_title, $when, $who, admin_url( 'admin.php?page=credoq-bookings&id=' . $booking_id ) )
				);
			}
		}
	}

	public static function on_cancelled( int $booking_id ) : void {
		Booking_Repository::cancel_for_ref( 'appointment', $booking_id );
	}
}
