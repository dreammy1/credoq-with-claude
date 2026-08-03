<?php
namespace CredoqSeats\Repositories;

defined( 'ABSPATH' ) || exit;

class Booking_Repository {

	/**
	 * Recalculate the true price of a set of seats server-side. Never trust
	 * a client-submitted total for this — mirrors exactly how each seat's
	 * `data-price` was resolved when the map was rendered (Ajax\Seats_Ajax::
	 * credoq_seats_load_map()): seat's own price_override, else the plan's
	 * per-type pricing, else the given fallback base price.
	 *
	 * @return array{total: float, count: int}
	 */
	/**
	 * Same resolution as calc_seats_total(), but also returns each seat's
	 * own resolved price (override > per-type plan price > fallback base)
	 * so callers that need to store per-seat prices (not just the sum)
	 * don't have to duplicate this resolution logic themselves.
	 *
	 * @return array{total:float,count:int,price_map:array<int,float>}
	 */
	public static function calc_seats_breakdown( int $plan_id, array $seat_ids, float $fallback_base_price = 0.0 ) : array {
		$seat_ids = array_filter( array_map( 'absint', $seat_ids ) );
		if ( empty( $seat_ids ) ) return array( 'total' => 0.0, 'count' => 0, 'price_map' => array() );

		$plan = Plan_Repository::find( $plan_id );
		if ( ! $plan ) return array( 'total' => 0.0, 'count' => 0, 'price_map' => array() );

		$layout  = json_decode( $plan->layout_json ?? '{}', true ) ?: array();
		$pricing = $layout['pricing'] ?? array();

		$seats     = Seat_Repository::find_many( $seat_ids );
		$total     = 0.0;
		$price_map = array();
		foreach ( $seats as $seat ) {
			if ( null !== $seat->price_override && '' !== $seat->price_override ) {
				$price = (float) $seat->price_override;
			} elseif ( isset( $pricing[ $seat->seat_type ] ) && null !== $pricing[ $seat->seat_type ] ) {
				$price = (float) $pricing[ $seat->seat_type ];
			} else {
				$price = $fallback_base_price;
			}
			$price_map[ (int) $seat->id ] = $price;
			$total += $price;
		}

		return array( 'total' => $total, 'count' => count( $seats ), 'price_map' => $price_map );
	}

	public static function calc_seats_total( int $plan_id, array $seat_ids, float $fallback_base_price = 0.0 ) : array {
		$breakdown = self::calc_seats_breakdown( $plan_id, $seat_ids, $fallback_base_price );
		return array( 'total' => $breakdown['total'], 'count' => $breakdown['count'] );
	}

	/**
	 * Seat IDs currently held or confirmed for a given plan/date/time.
	 * Expired holds are treated as free (lazy expiry — no cron dependency).
	 *
	 * AUDIT-FIX (Events + Seats): a seat plan can be connected to MULTIPLE
	 * events (Plan Builder's "Connect to a service" uses checkboxes, not a
	 * single select — see Admin\Plan_Builder_Page::handle_post()). Without
	 * an $event_id scope, two different events sharing one plan and
	 * happening to land on the same date_context (or both falling back to
	 * "today" when unresolved) would see each other's seats as booked.
	 * $event_id = 0 preserves the previous (plan+date+time only) behaviour
	 * for Appointments, which doesn't have this multi-connection ambiguity.
	 */
	public static function booked_seat_ids( int $plan_id, string $date, string $time = '', int $event_id = 0 ) : array {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_seat_bookings';
		$now   = current_time( 'mysql', true );

		$sql = "SELECT seat_id FROM {$table}
			WHERE plan_id = %d AND date_context = %s
			AND ( ( time_context <=> %s ) OR %s = '' )
			AND ( %d = 0 OR event_id = %d )
			AND ( status = 'confirmed' OR ( status = 'held' AND held_until >= %s ) )";

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $plan_id, $date, $time, $time, $event_id, $event_id, $now ) );
		return array_map( 'intval', $rows );
	}

	/**
	 * Attempt to hold one seat. Returns true on success, false if it's
	 * already held/confirmed by someone else. Uses INSERT ... WHERE NOT
	 * EXISTS so two simultaneous requests can't both win the same seat —
	 * no explicit transaction/lock needed on top of the unique index.
	 *
	 * AUDIT-FIX (Events + Seats): scoped by event_id (from $who['event_id'])
	 * in addition to date/time — see booked_seat_ids() above.
	 */
	public static function hold_seat( int $plan_id, int $seat_id, string $date, string $time, array $who, int $minutes = 5 ) : bool {
		global $wpdb;
		$table    = $wpdb->prefix . 'credoq_seat_bookings';
		$now      = current_time( 'mysql', true );
		$event_id = (int) ( $who['event_id'] ?? 0 );

		// Clear this seat's own expired holds first so a stale row doesn't
		// block a fresh attempt (the UNIQUE key includes status, so an
		// old 'held' row with a past held_until must be reused/removed).
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE seat_id = %d AND date_context = %s AND ( time_context <=> %s )
			 AND ( %d = 0 OR event_id = %d )
			 AND status = 'held' AND held_until < %s",
			$seat_id, $date, ( '' !== $time ? $time : null ), $event_id, $event_id, $now
		) );

		$taken = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE seat_id = %d AND date_context = %s AND ( time_context <=> %s )
			 AND ( %d = 0 OR event_id = %d )
			 AND ( status = 'confirmed' OR ( status = 'held' AND held_until >= %s ) )",
			$seat_id, $date, ( '' !== $time ? $time : null ), $event_id, $event_id, $now
		) );
		if ( $taken > 0 ) return false;

		$inserted = $wpdb->insert( $table, array(
			'plan_id'      => $plan_id,
			'seat_id'      => $seat_id,
			'booking_type' => $who['booking_type'] ?? 'event',
			'ref_id'       => (int) ( $who['ref_id'] ?? 0 ),
			'event_id'     => $event_id,
			'appointment_id' => (int) ( $who['appointment_id'] ?? 0 ),
			'date_context' => $date,
			'time_context' => '' !== $time ? $time : null,
			'user_id'      => get_current_user_id(),
			'guest_email'  => (string) ( $who['guest_email'] ?? '' ),
			'status'       => 'held',
			'held_until'   => gmdate( 'Y-m-d H:i:s', time() + ( $minutes * MINUTE_IN_SECONDS ) ),
			'price_charged'=> (float) ( $who['price'] ?? 0 ),
			'created_at'   => $now,
		) );

		return (bool) $inserted;
	}

	/** AUDIT-FIX (Events + Seats): event-scoped release, see booked_seat_ids() above. */
	public static function release_seat( int $seat_id, string $date, string $time = '', int $event_id = 0 ) : void {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_seat_bookings';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE seat_id = %d AND date_context = %s AND ( time_context <=> %s )
			 AND ( %d = 0 OR event_id = %d ) AND status = 'held'",
			$seat_id, $date, ( '' !== $time ? $time : null ), $event_id, $event_id
		) );
	}

	/** Release every held-by-this-session seat for a plan (used before re-hold on repaint). */
	public static function release_all_for_ref( string $booking_type, int $ref_id ) : void {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_seat_bookings';
		$wpdb->update( $table, array( 'status' => 'expired' ), array(
			'booking_type' => $booking_type,
			'ref_id'       => $ref_id,
			'status'       => 'held',
		) );
	}

	/**
	 * Convert a set of held/pending rows into confirmed bookings tied to a
	 * real ref_id (submission id, appointment booking id, WC order id).
	 * If no held row exists for a seat (e.g. hold expired right before
	 * checkout completed), a confirmed row is created directly — we don't
	 * want a slow checkout to lose a seat the customer already paid for.
	 */
	/**
	 * @param array $who Recognized keys: booking_type, ref_id, event_id,
	 *   appointment_id, date, time, user_id, guest_email, wc_order_id, and
	 *   EITHER 'price_map' => [seat_id => price] (preferred — each seat
	 *   keeps its own resolved price/override) OR the legacy flat
	 *   'price_each' (applied to every seat the same — only accurate when
	 *   every seat in the selection shares one price; kept for backward
	 *   compatibility with any external caller that hasn't been updated).
	 *
	 * AUDIT-FIX (price accuracy): previously every caller passed a single
	 * 'price_each' — for Events this was literally total/count, i.e. an
	 * AVERAGE, which silently overwrites the correct individual price each
	 * seat was actually held at (hold_seat() already stores the real
	 * per-seat price) the moment a submission is confirmed, corrupting the
	 * Admin > Seat Bookings price column whenever seats have mixed
	 * types/overrides. 'price_map' fixes this by keeping each seat's own
	 * value; see Fields\Seat_Map_Field::on_submission() for the caller.
	 */
	public static function confirm_seats( int $plan_id, array $seat_ids, array $who ) : void {
		global $wpdb;
		$table     = $wpdb->prefix . 'credoq_seat_bookings';
		$date      = (string) ( $who['date'] ?? gmdate( 'Y-m-d' ) );
		$time      = (string) ( $who['time'] ?? '' );
		$event_id  = (int) ( $who['event_id'] ?? 0 );
		$price_map = is_array( $who['price_map'] ?? null ) ? $who['price_map'] : array();

		foreach ( array_map( 'absint', $seat_ids ) as $seat_id ) {
			if ( ! $seat_id ) continue;

			$existing_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE seat_id = %d AND date_context = %s AND ( time_context <=> %s )
				 AND ( %d = 0 OR event_id = %d )
				 AND status IN ('held','confirmed') ORDER BY status DESC LIMIT 1",
				$seat_id, $date, ( '' !== $time ? $time : null ), $event_id, $event_id
			) );

			$price = isset( $price_map[ $seat_id ] ) ? (float) $price_map[ $seat_id ] : (float) ( $who['price_each'] ?? 0 );

			$row = array(
				'plan_id'        => $plan_id,
				'seat_id'        => $seat_id,
				'booking_type'   => $who['booking_type'] ?? 'event',
				'ref_id'         => (int) ( $who['ref_id'] ?? 0 ),
				'event_id'       => $event_id,
				'appointment_id' => (int) ( $who['appointment_id'] ?? 0 ),
				'date_context'   => $date,
				'time_context'   => '' !== $time ? $time : null,
				'user_id'        => (int) ( $who['user_id'] ?? get_current_user_id() ),
				'guest_email'    => (string) ( $who['guest_email'] ?? '' ),
				'status'         => 'confirmed',
				'held_until'     => null,
				'price_charged'  => $price,
				'wc_order_id'    => (int) ( $who['wc_order_id'] ?? 0 ),
			);

			if ( $existing_id ) {
				$wpdb->update( $table, $row, array( 'id' => (int) $existing_id ) );
			} else {
				$row['created_at'] = current_time( 'mysql', true );
				$wpdb->insert( $table, $row );
			}
		}
	}


	public static function cancel_for_ref( string $booking_type, int $ref_id ) : void {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_seat_bookings';
		$wpdb->update( $table, array( 'status' => 'cancelled' ), array(
			'booking_type' => $booking_type,
			'ref_id'       => $ref_id,
		) );
	}

	public static function expire_holds() : int {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_seat_bookings';
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'expired' WHERE status = 'held' AND held_until < %s",
			current_time( 'mysql', true )
		) );
	}

	/** @param array{plan_id?:int,event_id?:int,appointment_id?:int,date?:string,status?:string} $args */
	public static function list_bookings( array $args = array(), int $per_page = 50, int $paged = 1 ) : array {
		global $wpdb;
		$table  = $wpdb->prefix . 'credoq_seat_bookings';
		$seats  = $wpdb->prefix . 'credoq_seats';
		$where  = array( "b.status != 'expired'" );
		$params = array();

		foreach ( array( 'plan_id', 'event_id', 'appointment_id' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) { $where[] = "b.$key = %d"; $params[] = (int) $args[ $key ]; }
		}
		if ( ! empty( $args['date'] ) )   { $where[] = 'b.date_context = %s'; $params[] = $args['date']; }
		if ( ! empty( $args['status'] ) ) { $where[] = 'b.status = %s';       $params[] = $args['status']; }

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $paged - 1 ) * $per_page );

		$total_sql = "SELECT COUNT(*) FROM {$table} b WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

		$list_sql = "SELECT b.*, s.seat_label, s.seat_type FROM {$table} b
			LEFT JOIN {$seats} s ON s.id = b.seat_id
			WHERE {$where_sql} ORDER BY b.id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ) );

		return array( 'rows' => $rows ?: array(), 'total' => $total, 'pages' => (int) max( 1, ceil( $total / $per_page ) ) );
	}
}
