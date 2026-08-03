<?php
/**
 * Plan Repository — CRUD for membership plans.
 *
 * @package CredoqMembership
 */

namespace CredoqMembership;

defined( 'ABSPATH' ) || exit;

class Plan_Repository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'credoq_membership_plans';
	}

	public function find( int $id ) : ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ) );
		if ( ! $row ) return null;
		$row->rules = json_decode( $row->rules, true ) ?: array();
		return $row;
	}

	public function all() : array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY name ASC" );
		foreach ( $rows as &$row ) {
			$row->rules = json_decode( $row->rules, true ) ?: array();
		}
		return $rows;
	}

	public function get_by_product( int $product_id ) : array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE product_id = %d", $product_id ) );
		foreach ( $rows as &$row ) {
			$row->rules = json_decode( $row->rules, true ) ?: array();
		}
		return $rows;
	}

	public function save( array $data ) : int {
		global $wpdb;
		$id = absint( $data['id'] ?? 0 );

		$fields = array(
			'name'          => sanitize_text_field( $data['name'] ?? '' ),
			'product_id'    => absint( $data['product_id'] ?? 0 ),
			'duration_days' => absint( $data['duration_days'] ?? 30 ),
			'rules'         => wp_json_encode( $data['rules'] ?? array() ),
		);

		if ( $id > 0 ) {
			$wpdb->update( $this->table, $fields, array( 'id' => $id ) );
			return $id;
		}

		$wpdb->insert( $this->table, $fields );
		return (int) $wpdb->insert_id;
	}

	public function delete( int $id ) : bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table, array( 'id' => $id ) );
	}
}
