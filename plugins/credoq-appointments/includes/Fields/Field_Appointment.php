<?php
/**
 * Field_Appointment — Appointment booking field type for the Credoq form builder.
 *
 * When placed in a form this field renders the appointment booking widget:
 *  - Service selector
 *  - Staff / provider selector
 *  - Date + time slot picker
 *  - Validates slot availability on submit
 *  - Creates a booking record on successful submission
 *
 * @package CredoqAppointments\Fields
 */

namespace CredoqAppointments\Fields;

defined( 'ABSPATH' ) || exit;

use CredoqEngine\Abstracts\Field_Type;

class Field_Appointment extends Field_Type {

	/* ── Identity ──────────────────────────────────────────────────── */

	public function get_slug() : string {
		return 'appointment';
	}

	public function get_label() : string {
		return __( 'Appointment Booking', 'credoq-appointments' );
	}

	public function get_icon() : string {
		return 'calendar';
	}

	public function get_category() : string {
		return 'appointments';
	}

	public function get_description() : string {
		return __( 'Service, staff, and time-slot picker. Creates a booking on submit.', 'credoq-appointments' );
	}

	public function get_addon_id() : string {
		return 'credoq-appointments';
	}

	/* ── Builder settings schema ───────────────────────────────────── */

	public function get_settings_schema() : array {
		// Load available services using the correct column names from Schema.php:
		// credoq_appointments has: id, title, location, base_price, duration (NO 'name', NO 'status' column).
		$services = [];
		global $wpdb;
		$tbl = $wpdb->prefix . 'credoq_appointments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
			$rows = $wpdb->get_results(
				// FIX: column is `title` not `name`. No `status` column exists.
				"SELECT id, title FROM {$tbl} ORDER BY title ASC",
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$services[] = [ 'key' => (string) $row['id'], 'label' => $row['title'] ];
			}
		}

		return [
			[
				'key'     => 'label',
				'type'    => 'text',
				'label'   => __( 'Field label', 'credoq-appointments' ),
				'default' => __( 'Book Appointment', 'credoq-appointments' ),
			],
			[
				'key'     => 'service_id',
				'type'    => 'select',
				'label'   => __( 'Pre-select service (optional)', 'credoq-appointments' ),
				'options' => array_merge(
					[ [ 'key' => '', 'label' => __( '— Let customer choose —', 'credoq-appointments' ) ] ],
					$services
				),
			],
			[
				'key'     => 'show_staff',
				'type'    => 'checkbox',
				'label'   => __( 'Show staff selector', 'credoq-appointments' ),
				'default' => true,
			],
			[
				'key'     => 'slot_duration',
				'type'    => 'number',
				'label'   => __( 'Slot duration override (minutes, 0 = use service default)', 'credoq-appointments' ),
				'default' => 0,
			],
			[
				'key'     => 'required',
				'type'    => 'checkbox',
				'label'   => __( 'Required', 'credoq-appointments' ),
				'default' => true,
			],
		];
	}

	/* ── Value handling ────────────────────────────────────────────── */

	/**
	 * Sanitize the submitted booking payload.
	 * Value is a JSON string: { service_id, staff_id, date, time_slot }.
	 */
	public function sanitize( $value, array $field_config ) {
		if ( ! is_string( $value ) ) return '';
		$decoded = json_decode( wp_unslash( $value ), true );
		if ( ! is_array( $decoded ) ) return '';
		return wp_json_encode( [
			'service_id' => absint( $decoded['service_id'] ?? 0 ),
			'staff_id'   => absint( $decoded['staff_id'] ?? 0 ),
			'date'       => sanitize_text_field( $decoded['date'] ?? '' ),
			'time_slot'  => sanitize_text_field( $decoded['time_slot'] ?? '' ),
		] );
	}

	/**
	 * Validate: slot must be chosen and must be available.
	 */
	public function validate( $value, array $field_config, array $submission ) {
		$parent = parent::validate( $value, $field_config, $submission );
		if ( is_wp_error( $parent ) ) return $parent;
		if ( '' === $value ) return true; // required handled by parent

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded )
			|| empty( $decoded['service_id'] )
			|| empty( $decoded['date'] )
			|| empty( $decoded['time_slot'] ) ) {
			return new \WP_Error( 'invalid_slot', __( 'Please choose a valid appointment slot.', 'credoq-appointments' ) );
		}

		// Availability check delegated to Booking_Service if available.
		if ( class_exists( '\\CredoqAppointments\\Booking_Service' ) ) {
			$available = \CredoqAppointments\Booking_Service::is_slot_available(
				(int) $decoded['service_id'],
				(int) ( $decoded['staff_id'] ?? 0 ),
				sanitize_text_field( $decoded['date'] ),
				sanitize_text_field( $decoded['time_slot'] )
			);
			if ( ! $available ) {
				return new \WP_Error( 'slot_taken', __( 'The selected slot is no longer available. Please choose another time.', 'credoq-appointments' ) );
			}
		}

		return true;
	}

	/**
	 * Price contribution: returns the service price if available.
	 */
	public function price_contribution( $value, array $field_config, array $submission ) : float {
		if ( '' === $value ) return 0.0;
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) || empty( $decoded['service_id'] ) ) return 0.0;

		if ( class_exists( '\\CredoqAppointments\\Appointment_Repository' ) ) {
			$service = \CredoqAppointments\Appointment_Repository::find( (int) $decoded['service_id'] );
			if ( $service ) {
				return (float) ( $service->price ?? 0 );
			}
		}
		return 0.0;
	}

	/**
	 * Create the booking record in wp_credoq_bookings after submission.
	 * AUDIT-FIX (Gap in Forms Builder): without this, generic forms with
	 * an appointment field never actually reserved the slot.
	 */
	public function on_submission( int $submission_id, $value, array $field_config, array $submission_payload ) {
		if ( '' === $value ) return true;
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) || empty( $decoded['service_id'] ) ) return true;

		if ( ! class_exists( '\\CredoqAppointments\\Booking_Service' ) ) {
			return new \WP_Error( 'appointments_missing', __( 'Appointments plugin is not active.', 'credoq-appointments' ) );
		}

		$user_id     = get_current_user_id();
		$guest_name  = (string) ( $submission_payload['name']  ?? $submission_payload['full_name'] ?? '' );
		$guest_email = (string) ( $submission_payload['email'] ?? '' );

		// Decide status: if the form has a WC product or payment field,
		// it might stay pending. For now, mimic direct booking logic.
		$status = 'confirmed'; 

		$booking_id = \CredoqAppointments\Booking_Repository::insert( [
			'appointment_id' => absint( $decoded['service_id'] ),
			'staff_id'       => absint( $decoded['staff_id'] ?? 0 ),
			'user_id'        => $user_id,
			'guest_name'     => sanitize_text_field( $guest_name ),
			'guest_email'    => sanitize_email( $guest_email ),
			'selected_date'  => sanitize_text_field( $decoded['date'] ),
			'selected_time'  => sanitize_text_field( $decoded['time_slot'] ),
			'status'         => $status,
			'submission_id'  => $submission_id,
			'total_price'    => $this->price_contribution( $value, $field_config, $submission_payload ),
		] );

		if ( ! $booking_id ) {
			return new \WP_Error( 'booking_failed', __( 'Could not create appointment record.', 'credoq-appointments' ) );
		}

		if ( 'confirmed' === $status ) {
			do_action( 'credoq_booking_confirmed', $booking_id );
		}

		return [ 'appointment_booking_id' => $booking_id ];
	}

	/**
	 * Cancel the appointment booking if the submission is cancelled.
	 */
	public function on_cancellation( int $submission_id, array $context ) : void {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_bookings';
		$ids   = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE submission_id = %d AND status != 'cancelled'",
			$submission_id
		) );

		if ( ! empty( $ids ) && class_exists( '\\CredoqAppointments\\Booking_Service' ) ) {
			$refund_credits = ! empty( $context['refund_credits'] );
			foreach ( $ids as $id ) {
				\CredoqAppointments\Booking_Service::cancel( (int) $id, $refund_credits );
			}
		}
	}
}
