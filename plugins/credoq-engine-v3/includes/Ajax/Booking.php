<?php
/**
 * AJAX handlers for the booking widget.
 *
 * The Engine owns exactly one real handler: credoq_submit_booking, which
 * routes form submissions through the field-type registry and stores them
 * in credoq_submissions.
 *
 * The remaining nine actions belong to future addons (Appointments, Events,
 * Membership, Seats). Each is registered here as a graceful stub so the
 * React widget always gets a valid JSON response instead of a PHP-0 or 403.
 * Addons override these by calling remove_action() + add_action() at
 * priority 20 inside their own credoq_engine_ready callback.
 *
 * Response shape the widget expects (from useAjax.js):
 *   { success: true,  data: { ... } }
 *   { success: false, data: { code: '...', message: '...' } }
 *
 * @package CredoqEngine\Ajax
 */

namespace CredoqEngine\Ajax;

defined( 'ABSPATH' ) || exit;

class Booking {

	public static function register() : void {
		// ── Core submit — handled by Engine ─────────────────────────────
		add_action( 'wp_ajax_credoq_submit_booking',        [ __CLASS__, 'submit_booking' ] );
		add_action( 'wp_ajax_nopriv_credoq_submit_booking', [ __CLASS__, 'submit_booking' ] );

		// ── Appointment stubs — overridden by credoq-appointments ────────
		foreach ( [
			'credoq_get_timeslots',
			'credoq_get_date_capacity',
			'credoq_get_providers_for_service',
			'credoq_get_services_for_provider',
			'credoq_get_services_for_date',
			'credoq_join_waiting_list',
		] as $action ) {
			add_action( 'wp_ajax_' . $action,        [ __CLASS__, 'appointments_stub' ] );
			add_action( 'wp_ajax_nopriv_' . $action, [ __CLASS__, 'appointments_stub' ] );
		}

		// ── Membership stub — overridden by credoq-membership ───────────
		add_action( 'wp_ajax_credoq_get_member_slot_credits',        [ __CLASS__, 'member_credits_stub' ] );
		add_action( 'wp_ajax_nopriv_credoq_get_member_slot_credits', [ __CLASS__, 'member_credits_stub' ] );

		// ── Events stubs — overridden by credoq-events ──────────────────
		add_action( 'wp_ajax_credoq_get_events_feed',              [ __CLASS__, 'events_stub' ] );
		add_action( 'wp_ajax_nopriv_credoq_get_events_feed',       [ __CLASS__, 'events_stub' ] );
		add_action( 'wp_ajax_credoq_submit_event_booking',         [ __CLASS__, 'events_stub' ] );
		add_action( 'wp_ajax_nopriv_credoq_submit_event_booking',  [ __CLASS__, 'events_stub' ] );
	}

	/* ═══════════════════════════════════════════════════════════════════
	   REAL HANDLER — credoq_submit_booking
	═══════════════════════════════════════════════════════════════════ */

	public static function submit_booking() : void {
		// Nonce check.
		// AUDIT-FIX: this previously verified against action 'credoq_booking',
		// but EVERY nonce generator in both Engine (Shortcodes.php,
		// Assets.php) and Appointments (Shortcodes.php, Slots_Handler.php,
		// Booking_Handler.php) creates/sends the nonce for action
		// 'credoq_nonce'. The mismatch meant this check could never
		// succeed, so EVERY submission failed with "Security check failed."
		// regardless of a fresh page load. Aligned to 'credoq_nonce' to
		// match the single nonce convention used across the whole suite.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'credoq_nonce' ) ) {
			wp_send_json_error( array(
				'code'    => 'invalid_nonce',
				'message' => __( 'Security check failed. Please refresh the page.', 'credoq-engine' ),
			), 403 );
		}

		$form_id = absint( $_POST['form_id'] ?? 0 );
		if ( ! $form_id ) {
			wp_send_json_error( array(
				'code'    => 'missing_form_id',
				'message' => __( 'No form specified.', 'credoq-engine' ),
			), 400 );
		}

		// Build payload: every POST key except housekeeping fields.
		//
		// AUDIT-FIX (WC checkout total mismatch — e.g. widget shows
		// $40.00 but WooCommerce checkout shows $35.00): 'form_data' is a
		// 2D array — most fields are flat strings, but checkbox fields
		// submit as form_data[name][] meaning $_POST['form_data'][name]
		// is itself an ARRAY of selected values. The old code only did
		// ONE level of array_map(sanitize_text_field, ...) on
		// $_POST['form_data'] — fine for flat string fields, but for a
		// checkbox field $v was itself an array, and passing an array
		// into sanitize_text_field() (which expects a string) silently
		// mangled/dropped that field's value. The checkbox's
		// wc_contribution() then summed against an empty/broken value,
		// so its price never made it into the WC cart total even though
		// it was correctly counted in the widget's own grand-total math.
		// recursive_sanitize() now handles arbitrary nesting depth.
		$skip    = array( 'action', 'nonce', '_wpnonce', 'form_id' );
		$payload = array();
		foreach ( $_POST as $key => $value ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, $skip, true ) || '' === $key ) {
				continue;
			}
			$payload[ $key ] = self::recursive_sanitize( $value );
		}

		// Allow addons (e.g. Appointments) to merge cleaned file-upload
		// references into the payload before processing.
		$payload = apply_filters( 'credoq_ajax_booking_payload', $payload, $form_id );

		$result = credoq_engine()->submissions()->process(
			$form_id,
			$payload,
			array(
				'user_id'    => get_current_user_id(),
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
					? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
					: '',
				'source'     => 'ajax',
			)
		);

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			wp_send_json_error( array(
				'code'    => $code,
				'message' => $result->get_error_message(),
				'data'    => $result->get_error_data() ?: array(),
			), 'rate_limited' === $code ? 429 : 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Recursively sanitize a $_POST value of arbitrary nesting depth.
	 *
	 * form_data arrives as a mixed-depth structure: most fields are flat
	 * strings, but checkbox / multi-select fields submit as
	 * form_data[name][] and arrive as a nested array. A flat
	 * array_map( 'sanitize_text_field', $arr ) breaks the moment any
	 * element is itself an array (sanitize_text_field() expects a
	 * string). This walks the full structure safely instead.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function recursive_sanitize( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'recursive_sanitize' ), $value );
		}
		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/* ═══════════════════════════════════════════════════════════════════
	   STUBS — graceful responses until the relevant addon is installed
	═══════════════════════════════════════════════════════════════════ */

	/**
	 * Appointments stub.
	 * Returns empty slot/provider/service lists so the widget renders
	 * without errors. credoq-appointments replaces these at priority 20.
	 */
	public static function appointments_stub() : void {
		// AUDIT-FIX: widget sends the shared 'credoq_nonce' nonce (see
		// Shortcodes.php cfg.nonce) — this used to check a different,
		// never-matching action string ('credoq_booking').
		if ( ! check_ajax_referer( 'credoq_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 );
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		switch ( $action ) {
			case 'credoq_get_timeslots':
				wp_send_json_success( array( 'slots' => array() ) );
				break;
			case 'credoq_get_date_capacity':
				// Empty map = no days marked full, calendar stays open.
				wp_send_json_success( array( 'dates' => array() ) );
				break;
			case 'credoq_get_providers_for_service':
				wp_send_json_success( array( 'providers' => array() ) );
				break;
			case 'credoq_get_services_for_provider':
				wp_send_json_success( array( 'services' => array() ) );
				break;
			case 'credoq_get_services_for_date':
				wp_send_json_success( array( 'services' => array() ) );
				break;
			case 'credoq_join_waiting_list':
				wp_send_json_error( array(
					'code'    => 'addon_required',
					'message' => __( 'Waiting list requires the Credoq Appointments addon.', 'credoq-engine' ),
				), 503 );
				break;
			default:
				wp_send_json_error( array( 'code' => 'unknown_action' ), 400 );
		}
	}

	/**
	 * Membership stub.
	 * Returns "no credit required" so the widget doesn't block the form.
	 * credoq-membership replaces this at priority 20.
	 */
	public static function member_credits_stub() : void {
		// AUDIT-FIX: see submit_booking() above — align to 'credoq_nonce'.
		if ( ! check_ajax_referer( 'credoq_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 );
		}
		wp_send_json_success( array(
			'credit_required' => false,
			'plans'           => array(),
		) );
	}

	/**
	 * Events stub.
	 * Returns empty events list / error for submit.
	 * credoq-events replaces these at priority 20.
	 */
	public static function events_stub() : void {
		// Events use event_nonce, not booking nonce.
		if ( ! check_ajax_referer( 'credoq_events', 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 );
		}
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'credoq_get_events_feed' === $action ) {
			wp_send_json_success( array( 'events' => array() ) );
		} else {
			wp_send_json_error( array(
				'code'    => 'addon_required',
				'message' => __( 'Events require the Credoq Events addon.', 'credoq-engine' ),
			), 503 );
		}
	}
}
