<?php
/**
 * Membership Service — core business logic for user memberships and credits.
 *
 * @package CredoqMembership
 */

namespace CredoqMembership;

defined( 'ABSPATH' ) || exit;

class Membership_Service {

	private string $mem_table;
	private string $ledger_table;

	public function __construct() {
		global $wpdb;
		$this->mem_table    = $wpdb->prefix . 'credoq_user_memberships';
		$this->ledger_table = $wpdb->prefix . 'credoq_credit_ledger';
	}

	/**
	 * Get all active memberships for a user.
	 * Active = status='active' AND expiry > NOW() AND (order not revoked).
	 */
	public function get_active_memberships( int $user_id ) : array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->mem_table}
			 WHERE user_id = %d
			   AND status = 'active'
			   AND expiry_date > %s
			   AND ( order_id = 0 OR wc_order_status IN ('processing','completed') )
			 ORDER BY purchase_date DESC",
			$user_id,
			current_time( 'mysql', true )
		) );
	}

	/**
	 * Get the current slot credit balance for a user.
	 * Can be scoped to a specific plan or form.
	 */
	public function get_balance( int $user_id, int $plan_id = 0, int $form_id = 0 ) : int {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user ) return 0;

		$total_credit = 0;
		$active = $this->get_active_memberships( $user_id );

		foreach ( $active as $m ) {
			if ( $plan_id > 0 && (int) $m->plan_id !== $plan_id ) continue;

			$repo = new Plan_Repository();
			$plan = $repo->find( (int) $m->plan_id );
			if ( ! $plan ) continue;

			// Check if this plan is valid for the requested form.
			if ( $form_id > 0 ) {
				$allowed = $plan->rules['allowed_form_ids'] ?? '';
				if ( ! empty( $allowed ) ) {
					$allowed_ids = array_map( 'intval', explode( ',', $allowed ) );
					if ( ! in_array( $form_id, $allowed_ids, true ) ) continue;
				}
			}

			$total_credit += (int) ( $plan->rules['slot_credit'] ?? 0 );
		}

		// Sum up all ledger entries (adjustments + uses).
		$where = "user_id = %d";
		$args  = [ $user_id ];
		if ( $plan_id > 0 ) {
			$where .= " AND plan_id = %d";
			$args[] = $plan_id;
		}
		$adjustment = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(amount) FROM {$this->ledger_table} WHERE $where",
			$args
		) );

		return max( 0, $total_credit + $adjustment );
	}

	/**
	 * Add an entry to the credit ledger.
	 * Amount should be negative for use, positive for refund/grant.
	 */
	public function add_ledger_entry( int $user_id, int $amount, string $type = 'use', int $plan_id = 0, int $ref_id = 0, string $note = '' ) : int {
		global $wpdb;
		$user = get_userdata( $user_id );
		$wpdb->insert( $this->ledger_table, array(
			'user_id'    => $user_id,
			'user_email' => $user ? $user->user_email : '',
			'plan_id'    => $plan_id,
			'amount'     => $amount,
			'type'       => sanitize_key( $type ),
			'ref_id'     => $ref_id,
			'note'       => sanitize_text_field( $note ),
			'created_at' => current_time( 'mysql', true ),
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Grant a membership plan to a user.
	 */
	public function grant_membership( int $user_id, int $plan_id, int $order_id = 0, string $wc_status = '' ) : int {
		$repo = new Plan_Repository();
		$plan = $repo->find( $plan_id );
		if ( ! $plan ) return 0;

		$purchase_date = current_time( 'mysql', true );
		$expiry_date   = wp_date( 'Y-m-d H:i:s', strtotime( "+{$plan->duration_days} days" ) );

		return (int) credoq_add_user_membership( $user_id, $plan_id, $purchase_date, $expiry_date, $order_id, $wc_status );
	}

	/**
	 * AUDIT-FIX (P0 — fatal error on every credit-enabled booking):
	 * `Membership_Service::get_plan_status()`, `::deduct_credit()`, and
	 * `::refund_credit()` are called STATICALLY from six call sites across
	 * two other plugins —
	 *   - credoq-appointments/includes/Booking_Service.php (create(), cancel())
	 *   - credoq-appointments/includes/Integrations/WooCommerce.php (on_complete())
	 *   - credoq-events/includes/Event_Service.php (register()) — the legacy
	 *     standalone registration flow
	 * — but none of these three methods existed anywhere on this class.
	 * Every one of those call sites is only reached once credit deduction
	 * is actually enabled and relevant (i.e. exactly the path a real
	 * customer hits), so this was a guaranteed fatal "Call to undefined
	 * method" — surfacing to the customer as a broken/blank AJAX response
	 * (the same "Network error. Please try again." symptom documented
	 * elsewhere in this codebase for the analogous has_capacity() bug).
	 * These three wrap the existing get_balance()/add_ledger_entry()
	 * instance methods so the ledger/balance semantics stay identical to
	 * what Field_Slot_Credit and Field_Event's own credit checks already
	 * use — this is not a second, divergent accounting path.
	 */

	/**
	 * @return array{remaining:int, plan_id:int, user_id:int}
	 */
	public static function get_plan_status( int $user_id, int $plan_id, int $form_id = 0 ) : array {
		$svc = new self();
		return array(
			'remaining' => $svc->get_balance( $user_id, $plan_id, $form_id ),
			'plan_id'   => $plan_id,
			'user_id'   => $user_id,
		);
	}

	/**
	 * Deduct credits from a specific plan's ledger. Returns the new
	 * ledger row id (matches add_ledger_entry()'s own return), or 0 on
	 * failure. $ref_id is caller-defined (Appointments passes the
	 * appointment/service id; Events passes the event id) — it's purely
	 * an audit trail column, not a foreign key constraint.
	 */
	public static function deduct_credit( int $user_id, int $plan_id, int $amount, string $note = '', int $ref_id = 0 ) : int {
		if ( $amount <= 0 ) return 0;
		$svc = new self();
		return $svc->add_ledger_entry( $user_id, -abs( $amount ), 'use', $plan_id, $ref_id, $note );
	}

	/** Reverse a prior deduct_credit() — e.g. on booking/registration cancellation. */
	public static function refund_credit( int $user_id, int $plan_id, int $amount, string $note = '', int $ref_id = 0 ) : int {
		if ( $amount <= 0 ) return 0;
		$svc = new self();
		return $svc->add_ledger_entry( $user_id, abs( $amount ), 'refund', $plan_id, $ref_id, $note );
	}
}
