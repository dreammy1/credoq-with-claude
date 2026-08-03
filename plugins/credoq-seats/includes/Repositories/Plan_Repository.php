<?php
namespace CredoqSeats\Repositories;

defined( 'ABSPATH' ) || exit;

class Plan_Repository {

	public static function find( int $id ) : ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}credoq_seat_plans WHERE id = %d", $id
		) ) ?: null;
	}

	/** @return object[] */
	public static function all( array $args = array() ) : array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['connect_type'] ) ) {
			$where[]  = 'connect_type = %s';
			$params[] = $args['connect_type'];
		}
		$sql = "SELECT * FROM {$wpdb->prefix}credoq_seat_plans WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC';
		return $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
	}

	/** Published plans — used by the Appointments/Events admin pickers. */
	public static function published() : array {
		return self::all( array( 'status' => 'published' ) );
	}

	public static function count() : int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_seat_plans" );
	}

	/**
	 * Save a plan. When $id is 0, inserts; otherwise updates.
	 * Recomputes total_floors/total_seats/capacity_limit from layout_json
	 * so those cached columns never drift.
	 */
	public static function save( array $data ) : int {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_seat_plans';

		if ( isset( $data['connected_ids'] ) && is_array( $data['connected_ids'] ) ) {
			$data['connected_ids'] = wp_json_encode( $data['connected_ids'] );
		}

		if ( isset( $data['layout_json'] ) ) {
			$layout = is_array( $data['layout_json'] ) ? $data['layout_json'] : json_decode( $data['layout_json'], true );
			if ( is_array( $data['layout_json'] ) ) $data['layout_json'] = wp_json_encode( $data['layout_json'] );
			if ( is_array( $layout ) ) {
				$floors = $layout['floors'] ?? array();
				$seats  = 0;
				foreach ( $floors as $f ) $seats += count( $f['seats'] ?? array() );
				$data['total_floors']   = count( $floors );
				$data['total_seats']    = $seats;
				$data['capacity_limit'] = ! empty( $data['capacity_limit'] ) ? (int) $data['capacity_limit'] : $seats;
			}
		}

		$data['updated_at'] = current_time( 'mysql' );

		$id = (int) ( $data['id'] ?? 0 );
		if ( $id > 0 ) {
			unset( $data['id'], $data['created_at'] );
			$wpdb->update( $table, $data, array( 'id' => $id ) );
			return $id;
		}
		$data['created_at'] = current_time( 'mysql' );
		$data['created_by'] = get_current_user_id();
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	public static function duplicate( int $id ) : int {
		$plan = self::find( $id );
		if ( ! $plan ) return 0;
		return self::save( array(
			'name'           => $plan->name . ' (copy)',
			'description'    => $plan->description,
			'template_key'   => $plan->template_key,
			'connect_type'   => 'none',
			'connected_ids'  => '[]',
			'layout_json'    => $plan->layout_json,
			'capacity_limit' => (int) $plan->capacity_limit,
			'status'         => 'draft',
		) );
	}

	public static function delete( int $id ) : void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'credoq_seat_plans', array( 'id' => $id ) );
		$wpdb->delete( $wpdb->prefix . 'credoq_seat_plan_floors', array( 'plan_id' => $id ) );
		$wpdb->delete( $wpdb->prefix . 'credoq_seats', array( 'plan_id' => $id ) );
		$wpdb->delete( $wpdb->prefix . 'credoq_seat_bookings', array( 'plan_id' => $id ) );
	}

	/** Find plans connected to a given event or appointment ID. */
	public static function find_for_connection( string $type, int $ref_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}credoq_seat_plans WHERE connect_type = %s", $type
		) );
		$out = array();
		foreach ( $rows as $row ) {
			$ids = json_decode( $row->connected_ids ?? '[]', true ) ?: array();
			if ( in_array( $ref_id, array_map( 'intval', $ids ), true ) ) $out[] = $row;
		}
		return $out;
	}
}
