<?php
/**
 * Notifications — in-app admin notifications (the bell + Notifications page).
 *
 * @package CredoqEngine\Mail
 */

namespace CredoqEngine\Mail;

defined( 'ABSPATH' ) || exit;

class Notifications {

	const TABLE = 'credoq_notifications';

	/**
	 * Create a notification row.
	 *
	 * @param string $type    e.g. 'submission', 'system'
	 * @param string $title
	 * @param string $message
	 * @param string $link    Absolute URL (usually an admin_url()).
	 * @param string $ref     Short reference code shown in the title, e.g. SUB-B9862DAB.
	 * @return int Inserted ID, 0 on failure.
	 */
	public static function create( string $type, string $title, string $message = '', string $link = '', string $ref = '' ) : int {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'type'       => substr( sanitize_key( $type ), 0, 50 ),
				'title'      => substr( $title, 0, 255 ),
				'message'    => $message,
				'link'       => substr( $link, 0, 500 ),
				'ref'        => substr( $ref, 0, 40 ),
				'is_read'    => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array $args { paged, per_page }
	 * @return array{rows: array<int,object>, total: int, pages: int}
	 */
	public static function get_list( array $args = array() ) : array {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE;
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
			$per_page, $offset
		) );

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
			'pages' => (int) max( 1, ceil( $total / $per_page ) ),
		);
	}

	public static function count_unread() : int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0" );
	}

	public static function mark_read( int $id ) : void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . self::TABLE, array( 'is_read' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	public static function mark_unread( int $id ) : void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . self::TABLE, array( 'is_read' => 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	public static function mark_all_read() : void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( "UPDATE {$table} SET is_read = 1 WHERE is_read = 0" );
	}

	public static function delete( int $id ) : void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'id' => $id ), array( '%d' ) );
	}

	public static function delete_many( array $ids ) : void {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) return;
		$table        = $wpdb->prefix . self::TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	public static function clear_all() : void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
	}
}
