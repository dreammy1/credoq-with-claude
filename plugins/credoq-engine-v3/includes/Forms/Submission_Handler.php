<?php
/**
 * Submission Handler — orchestrates form submissions.
 *
 * Flow:
 *   1. Rate-limit check.
 *   2. Per-field validate() — collect all errors before returning.
 *   3. Per-field sanitize() — produce a clean payload.
 *   4. Compute total_price (sum of price_contribution()) and
 *      total_credits (sum of credit_cost()).
 *   5. Atomic insert into credoq_submissions.
 *   6. Fire on_submission() per field — side effects (create
 *      appointment booking, reserve seats, etc.). Wrapped in a
 *      try/catch so one failing field cancels the whole submission.
 *   7. Fire 'credoq_after_submission' so any cross-cutting addon
 *      (notifications, audit log) can react.
 *
 * AUDIT-FIX summary applied in this file:
 *   - B-6: rate limit no longer trusts X-Forwarded-For unless
 *          CREDOQ_TRUST_PROXY_HEADERS is set.
 *   - A-1: no eval() — calculate fields are evaluated by a safe
 *          expression parser (see Forms\Expression).
 *   - C-6: option price matching uses key, not numeric value.
 *
 * @package CredoqEngine\Forms
 */

namespace CredoqEngine\Forms;

use CredoqEngine\Fields\Registry;

defined( 'ABSPATH' ) || exit;

class Submission_Handler {

	private Registry $fields;
	private Repository $forms;

	public function __construct( Registry $fields, Repository $forms ) {
		$this->fields = $fields;
		$this->forms  = $forms;
	}

	/**
	 * Process a submission.
	 *
	 * @param int   $form_id
	 * @param array $payload  Raw user input (already unslashed).
	 * @param array $context  Additional context: user_id, ip, user_agent, source.
	 *
	 * @return array|\WP_Error  ['submission_id' => int, 'total_price' => float, 'total_credits' => int, 'addon_payload' => array]
	 */
	public function process( int $form_id, array $payload, array $context = array() ) {
		// AUDIT-FIX (Bug 1 frontend pipeline): the React widget posts field
		// values nested under payload['form_data'][field_name] (FormData
		// keys like form_data[email]) alongside top-level housekeeping keys
		// (nonce, form_id, __addon_total, etc). Flatten form_data into the
		// top level (without clobbering explicit top-level keys) so the
		// $payload[$name] lookups below work for both shapes — i.e. a
		// caller can pass either a flat array or { form_data: {...}, ... }.
		if ( isset( $payload['form_data'] ) && is_array( $payload['form_data'] ) ) {
			$payload = array_merge( $payload['form_data'], $payload );
		}

		// 1. Rate limit.
		$rl = $this->check_rate_limit( $context );
		if ( is_wp_error( $rl ) ) return $rl;

		// 1.5. Security gate — IP block, country block, reCAPTCHA.
		// Runs before form lookup so blocked traffic never even reaches
		// field validation/DB work.
		$security = \CredoqEngine\Security\Gate::check( $context, $payload );
		if ( is_wp_error( $security ) ) return $security;

		// 2. Load form.
		$form = $this->forms->find( $form_id );
		if ( ! $form ) {
			return new \WP_Error( 'unknown_form', __( 'Form not found.', 'credoq-engine' ) );
		}

		// 3. Run pre-submit filter so addons (e.g. membership) can short-circuit
		//    BEFORE we do any expensive work.
		$gate = apply_filters( 'credoq_pre_submit', true, $form, $payload, $context );
		if ( is_wp_error( $gate ) ) return $gate;

		// 4. Validate every field; collect all errors.
		$errors    = array();
		$sanitized = array();
		foreach ( $form->fields as $field ) {
			$type     = $this->fields->get( $field['type'] );
			if ( ! $type ) continue;
			$name     = $field['name'] ?? '';
			$value    = $payload[ $name ] ?? null;

			$verdict = $type->validate( $value, $field, $payload );
			if ( is_wp_error( $verdict ) ) {
				$errors[ $name ] = $verdict->get_error_message();
				continue;
			}
			$sanitized[ $name ] = $type->sanitize( $value, $field );
		}
		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'validation_failed', __( 'Some fields are invalid.', 'credoq-engine' ), array( 'fields' => $errors ) );
		}

		// 5. Compute totals.
		$total_price   = 0.0;
		$total_credits = 0;
		$wc_items      = array(); // product_id => price (summed across fields)
		foreach ( $form->fields as $field ) {
			$type  = $this->fields->get( $field['type'] );
			if ( ! $type ) continue;
			$name  = $field['name'] ?? '';
			$value = $sanitized[ $name ] ?? null;
			$total_price   += (float) $type->price_contribution( $value, $field, $sanitized );
			$total_credits += (int)   $type->credit_cost(         $value, $field, $sanitized );

			// AUDIT: standalone WC checkout bridging (3-setting architecture).
			foreach ( $type->wc_contribution( $value, $field, $sanitized ) as $contribution ) {
				$pid = absint( $contribution['product_id'] ?? 0 );
				if ( ! $pid ) continue;
				$wc_items[ $pid ] = ( $wc_items[ $pid ] ?? 0.0 ) + (float) ( $contribution['price'] ?? 0 );
			}
		}

		// Let addons modify the WC cart contributions too (e.g. Appointments
		// may want to fold these into its own line item instead of creating
		// separate ones).
		$wc_items = (array) apply_filters( 'credoq_submission_wc_items', $wc_items, $form, $sanitized, $context );
		$wc_total = array_sum( $wc_items );

		// Let addons modify totals (e.g. coupon addon discounts).
		$total_price   = (float) apply_filters( 'credoq_submission_total_price',   $total_price,   $form, $sanitized, $context );
		$total_credits = (int)   apply_filters( 'credoq_submission_total_credits', $total_credits, $form, $sanitized, $context );

		// 6. Insert the submission row (in a transaction).
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		$current_user_email = is_user_logged_in() ? (string) wp_get_current_user()->user_email : '';
		$user_email         = sanitize_email( ! empty( $sanitized['email'] ) ? $sanitized['email'] : $current_user_email );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'credoq_submissions',
			array(
				'form_id'       => $form_id,
				'user_id'       => (int) ( $context['user_id'] ?? get_current_user_id() ),
				'user_email'    => $user_email,
				'payload'       => wp_json_encode( $sanitized ),
				'total_price'   => $total_price + $wc_total, // AUDIT-FIX: wc_total (wc_option_price fields) was computed but never stored in the DB row
				'total_credits' => $total_credits,
				'status'        => 'pending',
				'source'        => sanitize_key( $context['source'] ?? 'frontend' ),
				'ip_address'    => substr( credoq_client_ip(), 0, 45 ),
				'user_agent'    => substr( $context['user_agent'] ?? '', 0, 255 ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d','%d','%s','%s','%f','%d','%s','%s','%s','%s','%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'db_error', $wpdb->last_error ?: 'Insert failed.' );
		}
		$submission_id = (int) $wpdb->insert_id;

		// 7. Fire per-field on_submission(). Each may produce data the
		//    response should include (e.g. cart URL from Appointments).
		$addon_payload = array();
		foreach ( $form->fields as $field ) {
			$type = $this->fields->get( $field['type'] );
			if ( ! $type ) continue;
			$name = $field['name'] ?? '';
			$value = $sanitized[ $name ] ?? null;
			$verdict = $type->on_submission( $submission_id, $value, $field, $sanitized );
			if ( is_wp_error( $verdict ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $verdict;
			}
			if ( is_array( $verdict ) ) {
				$addon_payload = array_merge( $addon_payload, $verdict );
			}
		}

		$wpdb->query( 'COMMIT' );

		// 8. Cross-cutting hook for notifications, integrations, etc.
		do_action( 'credoq_after_submission', $submission_id, $form, $sanitized, $addon_payload, $context );

		// 9. AUDIT: standalone WC checkout — add/refresh cart item(s) for any
		//    field with "Enable WC Checkout" turned on, so the engine's own
		//    field types work without depending on an addon plugin. Addons
		//    (e.g. Appointments) that already manage their own WC cart item
		//    can opt out per-submission via 'credoq_submission_wc_items'
		//    returning an empty array.
		$wc_redirect = '';
		$wc_enabled  = ! empty( $wc_items ) && function_exists( 'WC' );
		if ( $wc_enabled ) {
			$wc_redirect = \CredoqEngine\Integrations\WooCommerce_Bridge::add_cart_items( $wc_items, $submission_id );
		}

		return array(
			'submission_id' => $submission_id,
			'total_price'   => $total_price,
			'total_credits' => $total_credits,
			'addon_payload' => $addon_payload,
			'wc'            => $wc_enabled && '' !== $wc_redirect,
			'wc_items'      => $wc_items,
			'wc_total'      => $wc_total,
			'redirect'      => $wc_redirect,
		);
	}

	/**
	 * Per-IP and per-user rate limit (10 submissions / 10 minutes).
	 * Returns true|WP_Error.
	 */
	private function check_rate_limit( array $context ) {
		$ip   = credoq_client_ip();
		$uid  = (int) ( $context['user_id'] ?? get_current_user_id() );
		$keys = array( 'credoq_rl_ip_' . md5( $ip ) );
		if ( $uid > 0 ) {
			$keys[] = 'credoq_rl_user_' . $uid;
		}
		foreach ( $keys as $k ) {
			$count = (int) get_transient( $k );
			if ( $count >= 10 ) {
				return new \WP_Error( 'rate_limited', __( 'Too many submissions. Please wait a few minutes.', 'credoq-engine' ) );
			}
			set_transient( $k, $count + 1, 10 * MINUTE_IN_SECONDS );
		}
		return true;
	}

	/**
	 * Mark a submission cancelled and notify all fields so they can
	 * roll back side effects (release seats, refund credits, etc.).
	 */
	public function cancel( int $submission_id, array $context = array() ) : bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}credoq_submissions WHERE id = %d",
			$submission_id
		) );
		if ( ! $row ) return false;
		if ( 'cancelled' === $row->status ) return true;

		$wpdb->update(
			$wpdb->prefix . 'credoq_submissions',
			array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $submission_id ),
			array( '%s', '%s' ), array( '%d' )
		);

		$form = $this->forms->find( (int) $row->form_id );
		if ( $form ) {
			foreach ( $form->fields as $field ) {
				$type = $this->fields->get( $field['type'] );
				if ( ! $type ) continue;
				$type->on_cancellation( $submission_id, $context );
			}
		}

		do_action( 'credoq_after_cancellation', $submission_id, $row, $context );
		return true;
	}
}
