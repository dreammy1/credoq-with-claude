<?php
namespace CredoqSeats\Repositories;

defined( 'ABSPATH' ) || exit;

class Seat_Repository {

	/** @return object[] All seats for a plan, ordered by floor then row/col. */
	public static function for_plan( int $plan_id ) : array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}credoq_seats WHERE plan_id = %d ORDER BY floor_id ASC, row_index ASC, col_index ASC",
			$plan_id
		) );
	}

	public static function find( int $id ) : ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}credoq_seats WHERE id = %d", $id
		) ) ?: null;
	}

	/** @param int[] $ids */
	public static function find_many( array $ids ) : array {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) return array();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}credoq_seats WHERE id IN ({$placeholders})", $ids
		) );
	}

	/**
	 * Rebuild the credoq_seats + credoq_seat_plan_floors rows from a plan's
	 * layout_json. Called whenever the builder saves the canvas, so the
	 * flat, queryable seat table always mirrors the JSON source of truth.
	 *
	 * Existing seat IDs are preserved when a seat's client-side temp id
	 * matches a `db_id` in the incoming floor data, so bookings referencing
	 * seat_id keep working after a re-save.
	 *
	 * @return array The same layout with every floor/seat's `db_id` filled
	 *               in (newly inserted rows get their real ID here). The
	 *               caller MUST persist this returned array back onto the
	 *               plan's layout_json — otherwise the next save would see
	 *               no db_id on those rows and re-insert duplicates.
	 */
	public static function sync_from_layout( int $plan_id, array $layout ) : array {
		global $wpdb;
		$floors_table = $wpdb->prefix . 'credoq_seat_plan_floors';
		$seats_table  = $wpdb->prefix . 'credoq_seats';

		$existing_floor_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$floors_table} WHERE plan_id = %d", $plan_id ) );
		$existing_seat_ids  = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$seats_table} WHERE plan_id = %d", $plan_id ) );
		$kept_floor_ids     = array();
		$kept_seat_ids      = array();

		foreach ( (array) ( $layout['floors'] ?? array() ) as $f_index => &$floor ) {
			$floor_id = (int) ( $floor['db_id'] ?? 0 );
			$floor_data = array(
				'plan_id'    => $plan_id,
				'name'       => sanitize_text_field( $floor['name'] ?? ( 'Floor ' . ( $f_index + 1 ) ) ),
				'sort_order' => $f_index,
				'color'      => sanitize_hex_color( $floor['color'] ?? '#4f46e5' ) ?: '#4f46e5',
				'seat_count' => count( $floor['seats'] ?? array() ),
			);

			if ( $floor_id && in_array( $floor_id, array_map( 'intval', $existing_floor_ids ), true ) ) {
				$wpdb->update( $floors_table, $floor_data, array( 'id' => $floor_id ) );
			} else {
				$wpdb->insert( $floors_table, $floor_data );
				$floor_id = (int) $wpdb->insert_id;
			}
			$floor['db_id'] = $floor_id;
			$kept_floor_ids[] = $floor_id;

			foreach ( (array) ( $floor['seats'] ?? array() ) as &$seat ) {
				$seat_id = (int) ( $seat['db_id'] ?? 0 );
				$seat_data = array(
					'plan_id'        => $plan_id,
					'floor_id'       => $floor_id,
					'seat_label'     => sanitize_text_field( $seat['label'] ?? 'A1' ),
					'seat_type'      => in_array( $seat['type'] ?? 'standard', array( 'standard', 'vip', 'accessible', 'restricted', 'aisle' ), true ) ? $seat['type'] : 'standard',
					'row_index'      => (int) ( $seat['row'] ?? 0 ),
					'col_index'      => (int) ( $seat['col'] ?? 0 ),
					'x_pos'          => (float) ( $seat['x'] ?? 0 ),
					'y_pos'          => (float) ( $seat['y'] ?? 0 ),
					'price_override' => ( '' !== ( $seat['price'] ?? '' ) && null !== ( $seat['price'] ?? null ) ) ? (float) $seat['price'] : null,
					'status'         => in_array( $seat['status'] ?? 'available', array( 'available', 'reserved', 'blocked' ), true ) ? $seat['status'] : 'available',
					'color_class'    => sanitize_text_field( $seat['color_class'] ?? '' ),
				);

				if ( $seat_id && in_array( $seat_id, array_map( 'intval', $existing_seat_ids ), true ) ) {
					$wpdb->update( $seats_table, $seat_data, array( 'id' => $seat_id ) );
				} else {
					$wpdb->insert( $seats_table, $seat_data );
					$seat_id = (int) $wpdb->insert_id;
				}
				$seat['db_id'] = $seat_id;
				$kept_seat_ids[] = $seat_id;
			}
			unset( $seat );
		}
		unset( $floor );

		// Remove floors/seats that were deleted in the builder.
		$stale_floors = array_diff( array_map( 'intval', $existing_floor_ids ), $kept_floor_ids );
		foreach ( $stale_floors as $fid ) $wpdb->delete( $floors_table, array( 'id' => $fid ) );

		$stale_seats = array_diff( array_map( 'intval', $existing_seat_ids ), $kept_seat_ids );
		foreach ( $stale_seats as $sid ) $wpdb->delete( $seats_table, array( 'id' => $sid ) );

		return $layout;
	}

	public static function floors_for_plan( int $plan_id ) : array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}credoq_seat_plan_floors WHERE plan_id = %d ORDER BY sort_order ASC",
			$plan_id
		) );
	}
}
