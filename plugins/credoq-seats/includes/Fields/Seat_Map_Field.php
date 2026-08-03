<?php
/**
 * Seat_Map_Field — the 'seat_map' field type.
 *
 * One field type slug is shared by both Credoq Events and Credoq
 * Appointments, because the React widget (FormField.jsx) already has a
 * single hardcoded `type === 'seat_map'` branch rather than per-addon
 * branches — see SeatMapField in react-widget/src/FormField.jsx.
 *
 * Lifecycle differs by context:
 *   - Events: registration goes through the Engine's generic submission
 *     pipeline, so on_submission()/on_cancellation() below do the actual
 *     seat confirm/release.
 *   - Appointments: booking goes through Credoq Appointments' own
 *     Booking_Service (bypassing the generic submission pipeline), so
 *     on_submission() here is never called for that context — seat
 *     confirm/release for appointments happens in
 *     Integrations\Appointments_Bridge, hooked to
 *     'credoq_booking_confirmed' / 'credoq_booking_cancelled'.
 *
 * @package CredoqSeats\Fields
 */

namespace CredoqSeats\Fields;

use CredoqEngine\Abstracts\Field_Type;
use CredoqSeats\Repositories\Booking_Repository;
use CredoqSeats\Repositories\Plan_Repository;

defined( 'ABSPATH' ) || exit;

class Seat_Map_Field extends Field_Type {

	public function get_slug() : string { return 'seat_map'; }
	public function get_label() : string { return __( 'Seat Map', 'credoq-seats' ); }
	public function get_icon() : string { return 'armchair'; }
	public function get_category() : string { return 'addon'; }
	public function get_description() : string { return __( 'Interactive seat/table selection powered by Credoq Visual Seats Pro.', 'credoq-seats' ); }
	public function get_addon_id() : string { return 'credoq-seats'; }

	public function get_settings_schema() : array {
		$plans   = Plan_Repository::published();
		$options = array();
		foreach ( $plans as $p ) $options[] = array( 'label' => $p->name . ' (' . (int) $p->total_seats . ' seats)', 'value' => (string) $p->id );

		$event_options = array();
		if ( class_exists( '\CredoqEvents\Event_Repository' ) ) {
			foreach ( \CredoqEvents\Event_Repository::all( array( 'per_page' => 200 ) ) as $e ) {
				if ( 'published' !== ( $e->status ?? '' ) ) continue;
				$event_options[] = array( 'label' => $e->title, 'value' => (string) $e->id );
			}
		}

		return array(
			array( 'key' => 'label', 'label' => __( 'Field label', 'credoq-seats' ), 'type' => 'text' ),
			array( 'key' => 'required', 'label' => __( 'Require at least one seat', 'credoq-seats' ), 'type' => 'checkbox' ),
			array( 'key' => 'seat_plan_mode', 'label' => __( 'Plan mode', 'credoq-seats' ), 'type' => 'select', 'options' => array(
				array( 'label' => __( 'Single plan', 'credoq-seats' ), 'value' => 'single' ),
				array( 'label' => __( 'Let visitor pick from several plans', 'credoq-seats' ), 'value' => 'multiple' ),
			) ),
			array( 'key' => 'seat_plan_id', 'label' => __( 'Seat plan (single mode)', 'credoq-seats' ), 'type' => 'select', 'options' => $options ),
			array( 'key' => 'seat_plan_ids', 'label' => __( 'Seat plans (multiple mode)', 'credoq-seats' ), 'type' => 'multiselect', 'options' => $options ),
			array( 'key' => 'max_seats', 'label' => __( 'Max seats per booking (0 = unlimited)', 'credoq-seats' ), 'type' => 'number' ),
			// AUDIT-FEATURE: explicit override for the auto-resolution in
			// Seat_Map_Field::on_submission() / FormField.jsx's SeatMapField.
			// Leave blank to keep auto-resolving from a sibling
			// event_registration field's single selection, or from the
			// seat plan's own single-event connection (the common case —
			// see that method's docblock). Only needed when a plan is
			// intentionally connected to more than one event (valid setup)
			// and this specific form should always mean one particular one.
			array( 'key' => 'event_id', 'label' => __( 'Pin to a specific event (optional — overrides auto-detection)', 'credoq-seats' ), 'type' => 'select', 'options' => $event_options ),
		);
	}

	public function get_default_settings() : array {
		return array( 'seat_plan_mode' => 'single', 'seat_plan_id' => 0, 'seat_plan_ids' => array(), 'max_seats' => 0, 'required' => 1, 'event_id' => 0 );
	}

	public function validate( $value, array $field_config, array $submission ) {
		$selected = is_array( $value ) ? ( $value['selected'] ?? '' ) : '';
		if ( ! empty( $field_config['required'] ) && 'yes' !== $selected ) {
			return new \WP_Error( 'required', sprintf( __( '"%s" — please select at least one seat.', 'credoq-seats' ), $field_config['label'] ?? $this->get_label() ) );
		}

		$max = (int) ( $field_config['max_seats'] ?? 0 );
		if ( $max > 0 && is_array( $value ) ) {
			$count = (int) ( $value['count'] ?? count( $this->decode_seats( $value ) ) );
			if ( $count > $max ) {
				return new \WP_Error( 'too_many_seats', sprintf( __( 'You can select at most %d seats.', 'credoq-seats' ), $max ) );
			}
		}
		return true;
	}

	public function sanitize( $value, array $field_config ) {
		if ( ! is_array( $value ) ) return array();
		return array(
			'seats'    => wp_json_encode( $this->decode_seats( $value ) ),
			'count'    => (int) ( $value['count'] ?? 0 ),
			'total'    => (float) ( $value['total'] ?? 0 ),
			'plan_id'  => (int) ( $value['plan_id'] ?? 0 ),
			'selected' => ( 'yes' === ( $value['selected'] ?? '' ) ) ? 'yes' : '',
		);
	}

	public function price_contribution( $value, array $field_config, array $submission ) : float {
		return is_array( $value ) ? (float) ( $value['total'] ?? 0 ) : 0.0;
	}

	public function on_submission( int $submission_id, $value, array $field_config, array $submission_payload ) {
		$seat_ids = $this->decode_seats( $value );
		$plan_id  = (int) ( is_array( $value ) ? ( $value['plan_id'] ?? 0 ) : 0 );

		// AUDIT-FIX (Events + Seats — event_id resolution): the Forms
		// Builder's field-settings panel is hardcoded per built-in field
		// type (see credoq-engine Admin\Forms_Page.php — its unused
		// #cfs-addon-panel-wrap/credoqCustomFieldPanels hook is never
		// populated by any addon), so $field_config['event_id'] is never
		// actually set through the UI for a seat_map field. Previously
		// this fell back to $submission_payload['event_id'], which also
		// never existed (an event_registration field's answer lives under
		// its OWN field name, not a top-level 'event_id' key) — so this
		// was silently 0 for every real submission. Resolve it instead by
		// looking at what the visitor actually selected.
		$event_id = (int) ( $field_config['event_id'] ?? 0 );
		if ( ! $event_id ) {
			$event_id = self::resolve_event_id_from_payload( $submission_payload );
		}

		if ( ! $plan_id && $event_id ) {
			$plan_id = self::resolve_plan_id_for_event( $event_id );
		}

		if ( empty( $seat_ids ) || ! $plan_id ) {
			if ( ! empty( $seat_ids ) ) {
				// Seats were picked but we still couldn't resolve a plan —
				// that's a real problem worth surfacing, not a silent no-op.
				$this->log( 'skip: seats selected but no plan_id resolved (event_id=' . $event_id . ')', $submission_id );
			}
			return true;
		}

		if ( ! $event_id ) {
			// Ambiguous (e.g. an event_registration field on the same form
			// had more than one event selected — the seat map can't know
			// which one it belongs to) or genuinely absent. Confirming
			// seats against the wrong event would be worse than not
			// confirming at all, so this fails loudly instead.
			$this->log( 'ambiguous or missing event_id for plan #' . $plan_id . ' — seats NOT confirmed. A form with a seat_map field must resolve to exactly one selected event.', $submission_id );
			return new \WP_Error(
				'credoq_seats_ambiguous_event',
				__( 'Your seat selection could not be matched to a single event. Please select only one event when reserving seats.', 'credoq-seats' )
			);
		}

		// Resolve a date_context: the connected event's own date (Events
		// have no date/time picker on the form itself).
		$date  = '';
		$event = null;
		if ( class_exists( '\CredoqEvents\Event_Repository' ) ) {
			$event = \CredoqEvents\Event_Repository::find( $event_id );
			if ( $event && ! empty( $event->start_datetime ) ) $date = substr( $event->start_datetime, 0, 10 );
		}
		if ( '' === $date ) $date = current_time( 'Y-m-d' );

		// AUDIT-FIX (never trust the client-submitted total — see
		// Booking_Repository::calc_seats_breakdown()): recompute the real
		// per-seat prices server-side rather than using $value['total'],
		// and store each seat's OWN price instead of an average.
		$base_price = $event ? (float) $event->price : 0.0;
		$breakdown  = Booking_Repository::calc_seats_breakdown( $plan_id, $seat_ids, $base_price );

		$who = array(
			'booking_type' => 'event',
			'ref_id'       => $submission_id,
			'event_id'     => $event_id,
			'date'         => $date,
			'time'         => '',
			'user_id'      => get_current_user_id(),
			'guest_email'  => sanitize_email( $submission_payload['email'] ?? '' ),
			'price_map'    => $breakdown['price_map'],
		);

		Booking_Repository::confirm_seats( $plan_id, $seat_ids, $who );
		$this->log( 'confirmed ' . count( $seat_ids ) . ' seat(s) [' . implode( ',', $seat_ids ) . '] in plan #' . $plan_id . ' for event #' . $event_id . ', total=' . $breakdown['total'], $submission_id );
		return true;
	}

	public function on_cancellation( int $submission_id, array $context ) : void {
		Booking_Repository::cancel_for_ref( 'event', $submission_id );
	}

	/**
	 * Resolve which single event a seat_map field's selection belongs to,
	 * given the submission's sanitized payload. Rather than requiring a
	 * form-definition lookup (unavailable at every call site — e.g.
	 * Field_Type::wc_contribution()/credit_cost() only ever receive the
	 * sanitized payload, never the form or a submission_id), this scans
	 * every value in the payload for the exact shape
	 * Fields\Field_Event::sanitize() produces (a JSON-encoded array of
	 * `{event_id, quantity, price}`) — regardless of which field name it
	 * lives under. If exactly one distinct event was selected across all
	 * such values (the common case — a seat map only makes sense for one
	 * venue/event at a time), returns it. Returns 0 when nothing matches,
	 * or more than one distinct event was selected (ambiguous).
	 */
	public static function resolve_event_id_from_payload( array $sanitized ) : int {
		$event_ids = array();
		foreach ( $sanitized as $raw ) {
			if ( ! is_string( $raw ) && ! is_array( $raw ) ) continue;
			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			if ( ! is_array( $decoded ) ) continue;
			if ( isset( $decoded['event_id'] ) ) $decoded = array( $decoded );

			foreach ( $decoded as $sel ) {
				if ( is_array( $sel ) && array_key_exists( 'event_id', $sel ) && array_key_exists( 'quantity', $sel ) ) {
					$event_ids[] = (int) $sel['event_id'];
				}
			}
		}
		$event_ids = array_values( array_unique( array_filter( $event_ids ) ) );
		return 1 === count( $event_ids ) ? $event_ids[0] : 0;
	}

	/**
	 * Fallback plan resolution for when a seat_map field's own
	 * `seat_plan_id` setting was never configured (see on_submission()
	 * doc above) — uses the seat plan connected to this one event via
	 * the Seat Plan Builder's "Connect to a service" flow. Only resolves
	 * when exactly one published plan is connected to this event;
	 * connecting several plans to the same event is a valid setup (e.g.
	 * swapping layouts between runs) but this method can't guess which
	 * one applies, so it deliberately returns 0 rather than guessing.
	 */
	public static function resolve_plan_id_for_event( int $event_id ) : int {
		$plans = array_values( array_filter(
			Plan_Repository::find_for_connection( 'event', $event_id ),
			function ( $p ) { return 'published' === ( $p->status ?? '' ); }
		) );
		return 1 === count( $plans ) ? (int) $plans[0]->id : 0;
	}

	/** Writes a 'seats.event_confirm' audit entry when Credoq Engine's Audit_Log is available. */
	private function log( string $message, int $submission_id ) : void {
		if ( ! class_exists( '\CredoqEngine\Log\Audit_Log' ) ) return;
		\CredoqEngine\Log\Audit_Log::record( 'seats.event_confirm', array(
			'subject' => 'submission #' . $submission_id,
			'message' => $message,
		) );
	}

	public function render_value( $value, array $field_config ) : string {
		if ( ! is_array( $value ) ) return '';
		$seat_ids = $this->decode_seats( $value );
		if ( empty( $seat_ids ) ) return __( 'No seats selected', 'credoq-seats' );

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $seat_ids ), '%d' ) );
		$labels = $wpdb->get_col( $wpdb->prepare( "SELECT seat_label FROM {$wpdb->prefix}credoq_seats WHERE id IN ({$placeholders})", $seat_ids ) );
		return esc_html( implode( ', ', $labels ) ) . ' (' . count( $seat_ids ) . ')';
	}

	public function get_frontend_render( array $field_config ) : array {
		// IMPORTANT: this must stay empty. The Engine only attaches
		// field._frontend when this returns a non-empty array (see
		// Shortcodes.php), and FormField.jsx checks `field._frontend &&
		// field._frontend.component` BEFORE it reaches its hardcoded
		// `type === 'seat_map'` branch — so a populated descriptor here
		// silently hijacks the render into the generic <AddonField>
		// "display" box instead of the real interactive seat map. This
		// field type is one of the ones FormField.jsx has a native branch
		// for, so it must never populate _frontend.
		return array();
	}

	private function decode_seats( $value ) : array {
		if ( ! is_array( $value ) || empty( $value['seats'] ) ) return array();
		$seats = $value['seats'];
		if ( is_string( $seats ) ) $seats = json_decode( $seats, true );
		return is_array( $seats ) ? array_map( 'absint', $seats ) : array();
	}
}
