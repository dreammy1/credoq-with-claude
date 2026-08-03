<?php
/**
 * Events_Bridge.
 *
 * Credoq Events registration normally runs through the Engine's generic
 * submission pipeline, where Fields\Seat_Map_Field::on_submission() is the
 * primary place seats get confirmed (keyed by submission_id, which is
 * always available regardless of field order).
 *
 * This bridge is everything else that flow needs from the Events side:
 *
 *   - A defense-in-depth listener on 'credoq_event_booking_confirmed' /
 *     'credoq_event_booking_cancelled' (credoq_event_bookings.submission_id
 *     links back to the same submission), so seats stay correct even if an
 *     admin changes a booking's status directly from the Events admin
 *     screen rather than through the original submission flow.
 *     Booking_Repository::confirm_seats()/cancel_for_ref() are idempotent,
 *     so re-running them for the same submission is harmless.
 *
 *   - 'credoq_widget_config': injects an {event_id: plan_id} map for every
 *     published seat plan connected to exactly one event, so the React
 *     widget's SeatMapField can resolve which plan to render once the
 *     visitor picks a single event in an event_registration field on the
 *     same form — there's no Forms Builder settings UI for addon field
 *     types (see Fields\Seat_Map_Field::on_submission() doc), so this is
 *     the config-time half of that same auto-resolution.
 *
 *   - 'credoq_events_seat_overrides': lets Credoq Events recompute a
 *     registration's real price from the seat plan instead of the event's
 *     flat price, whenever a seat_map field governs that event — see
 *     filter_seat_overrides() below.
 *
 * @package CredoqSeats\Integrations
 */

namespace CredoqSeats\Integrations;

use CredoqSeats\Repositories\Booking_Repository;
use CredoqSeats\Repositories\Plan_Repository;
use CredoqSeats\Fields\Seat_Map_Field;

defined( 'ABSPATH' ) || exit;

class Events_Bridge {

	public static function register() : void {
		if ( ! class_exists( '\CredoqEvents\Event_Service' ) ) return; // addon not active — no-op.

		add_action( 'credoq_event_booking_confirmed', array( __CLASS__, 'on_confirmed' ), 10, 1 );
		add_action( 'credoq_event_booking_cancelled', array( __CLASS__, 'on_cancelled' ), 10, 1 );
		add_filter( 'credoq_widget_config', array( __CLASS__, 'inject_widget_config' ), 10, 1 );
		add_filter( 'credoq_events_seat_overrides', array( __CLASS__, 'filter_seat_overrides' ), 10, 2 );
	}

	/**
	 * Defense-in-depth: re-run the same confirm that
	 * Seat_Map_Field::on_submission() already did at submission time (see
	 * that method's docblock for why seats are confirmed there rather than
	 * waiting for WooCommerce). Reconstructs the seat selection from the
	 * submission's stored payload (credoq_submissions.payload) rather than
	 * trusting anything passed around in-memory, since this can fire long
	 * after the original request (e.g. a manual admin status change).
	 */
	public static function on_confirmed( int $event_booking_id ) : void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT eb.submission_id FROM {$wpdb->prefix}credoq_event_bookings eb WHERE eb.id = %d", $event_booking_id
		) );
		$submission_id = $row ? (int) $row->submission_id : 0;
		if ( ! $submission_id ) return;

		// AUDIT-FIX (P1 — defensive schema check): on any install where
		// `credoq_submissions.payload` doesn't exist (a schema version
		// predating it, or a partial migration), $wpdb->get_var() fails
		// silently per WordPress convention (no fatal — it populates
		// $wpdb->last_error and returns null), so this already degraded
		// safely before. Made explicit here instead of relying on that
		// implicit null-coercion, which also silences a PHP 8.1+
		// deprecation notice for passing null into json_decode().
		$payload_json = $wpdb->get_var( $wpdb->prepare(
			"SELECT payload FROM {$wpdb->prefix}credoq_submissions WHERE id = %d", $submission_id
		) );
		if ( null === $payload_json ) return; // column/row missing, or query failed — nothing to re-sync from.
		$sanitized = json_decode( $payload_json, true );
		if ( ! is_array( $sanitized ) ) return;

		foreach ( $sanitized as $val ) {
			if ( ! is_array( $val ) || ! array_key_exists( 'seats', $val ) || ! array_key_exists( 'plan_id', $val ) ) continue;

			$seat_ids = self::decode_seats( $val );
			$plan_id  = (int) ( $val['plan_id'] ?? 0 );
			if ( empty( $seat_ids ) || ! $plan_id ) continue;

			$event_id = Seat_Map_Field::resolve_event_id_from_payload( $sanitized );
			if ( ! $event_id ) continue;

			$date  = current_time( 'Y-m-d' );
			$event = null;
			if ( class_exists( '\CredoqEvents\Event_Repository' ) ) {
				$event = \CredoqEvents\Event_Repository::find( $event_id );
				if ( $event && ! empty( $event->start_datetime ) ) $date = substr( $event->start_datetime, 0, 10 );
			}

			$base_price = $event ? (float) $event->price : 0.0;
			$breakdown  = Booking_Repository::calc_seats_breakdown( $plan_id, $seat_ids, $base_price );

			Booking_Repository::confirm_seats( $plan_id, $seat_ids, array(
				'booking_type' => 'event',
				'ref_id'       => $submission_id,
				'event_id'     => $event_id,
				'date'         => $date,
				'time'         => '',
				'guest_email'  => sanitize_email( $sanitized['email'] ?? '' ),
				'price_map'    => $breakdown['price_map'],
			) );
		}
	}

	public static function on_cancelled( int $event_booking_id ) : void {
		global $wpdb;
		$submission_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT submission_id FROM {$wpdb->prefix}credoq_event_bookings WHERE id = %d", $event_booking_id
		) );
		if ( $submission_id ) {
			Booking_Repository::cancel_for_ref( 'event', $submission_id );
		}
	}

	/**
	 * Builds {event_id: plan_id} for every published, event-connected seat
	 * plan that resolves unambiguously to one event. Plans connected to
	 * several events (a valid setup — see
	 * Fields\Seat_Map_Field::resolve_plan_id_for_event()) are intentionally
	 * left out here since there'd be no way for the widget to pick between
	 * them at render time either.
	 */
	public static function inject_widget_config( array $config ) : array {
		$map = array();
		foreach ( Plan_Repository::published() as $plan ) {
			if ( 'event' !== ( $plan->connect_type ?? '' ) ) continue;
			$ids = json_decode( $plan->connected_ids ?? '[]', true ) ?: array();
			if ( 1 === count( $ids ) ) {
				$map[ (int) $ids[0] ] = (int) $plan->id;
			}
		}
		$config['event_seat_plans'] = $map;
		return $config;
	}

	/**
	 * Recomputes the real seat-selection total for every event selection
	 * on a submission, keyed by event_id — never trusts the client
	 * submitted total (see Booking_Repository::calc_seats_breakdown()).
	 * Credoq Events calls this (via the 'credoq_events_seat_overrides'
	 * filter) so a seat_map field's per-seat pricing REPLACES the flat
	 * event price × qty for whichever event it governs, instead of the two
	 * being charged side by side.
	 *
	 * @param array $context {sanitized: array} — the submission's sanitized payload.
	 * @return array<int, array{plan_id:int, seat_ids:int[], total:float, count:int}>
	 */
	public static function filter_seat_overrides( array $overrides, array $context ) : array {
		$sanitized = $context['sanitized'] ?? array();

		foreach ( $sanitized as $val ) {
			if ( ! is_array( $val ) || ! array_key_exists( 'seats', $val ) || ! array_key_exists( 'plan_id', $val ) ) continue;

			$seat_ids = self::decode_seats( $val );
			$plan_id  = (int) ( $val['plan_id'] ?? 0 );
			if ( empty( $seat_ids ) ) continue;

			$event_id = Seat_Map_Field::resolve_event_id_from_payload( $sanitized );
			if ( ! $event_id ) continue; // ambiguous — Seat_Map_Field::on_submission() rejects the whole submission for this same reason.

			if ( ! $plan_id ) {
				// Same auto-resolution fallback as on_submission() — the
				// field's own setting is realistically never configured
				// for Events (see Seat_Map_Field doc), so fall back to
				// whatever the connected plan resolves to.
				$plan_id = Seat_Map_Field::resolve_plan_id_for_event( $event_id );
			}
			if ( ! $plan_id ) continue;

			$base_price = 0.0;
			if ( class_exists( '\CredoqEvents\Event_Repository' ) ) {
				$event = \CredoqEvents\Event_Repository::find( $event_id );
				if ( $event ) $base_price = (float) $event->price;
			}

			$calc = Booking_Repository::calc_seats_total( $plan_id, $seat_ids, $base_price );
			$overrides[ $event_id ] = array(
				'plan_id'  => $plan_id,
				'seat_ids' => $seat_ids,
				'total'    => $calc['total'],
				'count'    => $calc['count'],
			);
		}

		return $overrides;
	}

	private static function decode_seats( array $value ) : array {
		if ( empty( $value['seats'] ) ) return array();
		$seats = $value['seats'];
		if ( is_string( $seats ) ) $seats = json_decode( $seats, true );
		return is_array( $seats ) ? array_map( 'absint', $seats ) : array();
	}
}
