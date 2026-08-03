<?php
/**
 * Member Slot Credit field type.
 *
 * This field renders a radio list of the user's active membership plans,
 * showing remaining credits for each. On submission, it validates that
 * the chosen plan has enough credits.
 *
 * @package CredoqMembership
 */

namespace CredoqMembership;

use CredoqEngine\Abstracts\Field_Type;

defined( 'ABSPATH' ) || exit;

class Field_Slot_Credit extends Field_Type {

	public function get_slug() : string {
		// 'membership_credit' — matches the Engine Tools page health-checker
		// expectation (Tools_Page.php field_types => ['membership_credit']).
		return 'membership_credit';
	}

	public function get_label() : string {
		return __( 'Member Slot Credit', 'credoq-membership' );
	}

	public function get_icon() : string {
		return 'ticket';
	}

	public function get_category() : string {
		return 'advanced';
	}

	public function get_addon_id() : string {
		return 'credoq-membership';
	}

	/**
	 * AUDIT-FIX (Bug 2 / frontend blank): supply a declarative descriptor
	 * so FormField.jsx's generic <AddonField> path renders this field
	 * properly instead of showing a silent blank box.
	 *
	 * We build a 'select' component whose options are the current user's
	 * active membership plans with their live credit balance.  When no
	 * user is logged in we return a 'display' block explaining login is
	 * needed — the field value is irrelevant in that case.
	 *
	 * The React widget already receives the full member_credits array in
	 * cfg.member_credits (injected by the credoq_widget_config filter in
	 * credoq-membership.php). We mirror that here as the options list so
	 * the label can show "Plan Name — N credits remaining".
	 *
	 * @param array $field_config Saved field config (label, required, etc.)
	 * @return array{component:string, props:array}
	 */
	public function get_frontend_render( array $field_config ) : array {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return [
				'component' => 'display',
				'props'     => [
					'text' => __( 'Please log in to use membership credits.', 'credoq-membership' ),
				],
			];
		}

		// Build plan options from this user's active memberships.
		$service = new Membership_Service();
		$active  = $service->get_active_memberships( $user_id );
		$options = [];

		foreach ( $active as $m ) {
			$repo    = new Plan_Repository();
			$plan    = $repo->find( (int) $m->plan_id );
			if ( ! $plan ) continue;

			$balance = $service->get_balance( $user_id, (int) $plan->id, 0 );
			$label   = sprintf(
				/* translators: 1 = plan name, 2 = credit count */
				__( '%1$s — %2$d credit(s) remaining', 'credoq-membership' ),
				$plan->name,
				$balance
			);

			$options[] = [
				'value' => (string) $plan->id,
				'label' => $label,
			];
		}

		if ( empty( $options ) ) {
			return [
				'component' => 'display',
				'props'     => [
					'text' => __( 'No active membership plans found.', 'credoq-membership' ),
				],
			];
		}

		return [
			'component' => 'select',
			'props'     => [
				'options'     => $options,
				'placeholder' => __( '— Select a membership plan —', 'credoq-membership' ),
			],
		];
	}

	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'required', 'label' => __( 'Required', 'credoq-membership' ), 'type' => 'checkbox' ),
		);
	}

	/**
	 * Validate that the user picked a plan and it has credits.
	 */
	public function validate( $value, array $field_config, array $submission ) {
		// AUDIT-FIX (Network error on submit): an uncaught \Throwable here
		// would abort the AJAX request mid-response — the browser gets a
		// broken/non-JSON body, surfaced client-side as a generic
		// "Network error. Please try again." with no detail at all.
		// Converting it into a proper WP_Error means a real bug shows up
		// as a readable message instead of looking like a network problem.
		try {
			return $this->validate_plan_selection( $value, $field_config, $submission );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'membership_credit_exception', sprintf(
				/* translators: %s = exception message */
				__( 'Member Slot Credit error: %s', 'credoq-membership' ), $e->getMessage()
			) );
		}
	}

	private function validate_plan_selection( $value, array $field_config, array $submission ) {
		// If not required and empty, OK.
		if ( empty( $field_config['required'] ) && empty( $value ) ) {
			return true;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new \WP_Error( 'login_required', __( 'You must be logged in to use membership credits.', 'credoq-membership' ) );
		}

		if ( empty( $value ) ) {
			return new \WP_Error( 'required', __( 'Please select a membership plan for this booking.', 'credoq-membership' ) );
		}

		$plan_id = absint( $value );
		$service = new Membership_Service();
		$balance = $service->get_balance( $user_id, $plan_id, (int) ( $submission['form_id'] ?? 0 ) );

		// AUDIT-FIX (3-way membership credit vs WC decision, requested
		// workflow): this field used to hard-block the whole submission
		// with "insufficient_credits" whenever balance < 1 — but that
		// makes case (b) impossible. When this field is paired with an
		// Events "Event Registration" field, insufficient credit isn't
		// fatal: Events falls back to a WooCommerce checkout for the
		// shortfall (see Field_Event::decide_payment() /
		// resolve_selection_payment()), or raises its own clear error if
		// no WC product is configured either (case c). Only hard-block
		// here when there's no such companion field to provide that
		// fallback — e.g. a plain credit-gated form with no WC option.
		if ( $balance < 1 && ! $this->submission_has_wc_fallback_field( $submission ) ) {
			return new \WP_Error( 'insufficient_credits', __( 'The selected plan has no remaining credits.', 'credoq-membership' ) );
		}

		return true;
	}

	/**
	 * Heuristic: does this submission also contain an Event Registration
	 * (or similarly-shaped) field value — i.e. is there a companion field
	 * that can fall back to WooCommerce if credit is insufficient? We
	 * can't see field *types* here (only $submission's flat name=>value
	 * payload), so this matches on the JSON shape Field_Event's value
	 * takes: an array of {event_id, quantity, ...}.
	 */
	private function submission_has_wc_fallback_field( array $submission ) : bool {
		foreach ( $submission as $val ) {
			if ( ! is_string( $val ) || '' === $val ) continue;
			$decoded = json_decode( $val, true );
			if ( is_array( $decoded ) && ( isset( $decoded['event_id'] )
				|| ( isset( $decoded[0] ) && is_array( $decoded[0] ) && isset( $decoded[0]['event_id'] ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compute how many credits this field costs.
	 */
	public function credit_cost( $value, array $field_config, array $submission ) : int {
		// By default, this field type marks that it will consume credits.
		// Other fields (like Appointments) can also return a credit cost.
		// The total cost is summed in Submission_Handler.
		return 0; // The Appointment field usually owns the cost.
	}

	/**
	 * Deduct the credits after a successful submission.
	 */
	public function on_submission( int $submission_id, $value, array $field_config, array $submission_payload ) {
		try {
			if ( empty( $value ) ) return true;

			$user_id = get_current_user_id();
			$plan_id = absint( $value );
			$service = new Membership_Service();

			// How many credits were actually used? Summed by Submission_Handler.
			// AUDIT-FIX (double deduction): when an Events "Event
			// Registration" field is in the same submission, Events
			// already deducts the precise qty-scaled amount itself (see
			// Field_Event::on_submission()) and hooks this filter to
			// return 0 here so this field doesn't ALSO deduct its own
			// flat default of 1.
			$cost = (int) apply_filters( 'credoq_membership_credit_cost', 1, $submission_id, $submission_payload );

			if ( $cost > 0 ) {
				$service->add_ledger_entry( $user_id, -$cost, 'use', $plan_id, $submission_id, __( 'Form submission', 'credoq-membership' ) );
			}

			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'membership_credit_exception', sprintf(
				/* translators: %s = exception message */
				__( 'Member Slot Credit error: %s', 'credoq-membership' ), $e->getMessage()
			) );
		}
	}

	/**
	 * Refund credits if submission is cancelled.
	 */
	public function on_cancellation( int $submission_id, array $context ) : void {
		global $wpdb;
		$ledger_table = $wpdb->prefix . 'credoq_credit_ledger';
		$entry = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $ledger_table WHERE ref_id = %d AND type = 'use' LIMIT 1",
			$submission_id
		) );

		if ( $entry && ! empty( $context['refund_credits'] ) ) {
			$service = new Membership_Service();
			$service->add_ledger_entry(
				(int) $entry->user_id,
				abs( (int) $entry->amount ),
				'refund',
				(int) $entry->plan_id,
				$submission_id,
				__( 'Submission cancelled', 'credoq-membership' )
			);
		}
	}
}
