<?php
namespace CredoqSeats\Admin;

defined( 'ABSPATH' ) || exit;

class Menu {

	public static function register() : void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenus' ) );
		add_filter( 'credoq_admin_sidebar_items', array( __CLASS__, 'add_sidebar_items' ) );
	}

	public static function add_submenus() : void {
		add_submenu_page(
			'credoq',
			__( 'Seat Plans', 'credoq-seats' ),
			__( 'Seat Plans', 'credoq-seats' ),
			'manage_options',
			'credoq-seat-plans',
			array( Seat_Plans_Page::class, 'render' )
		);

		// Hidden from the WP submenu list (no menu title) — reached from
		// the Seat Plans list's "Edit Builder" action, same pattern the
		// Engine uses for its own Forms builder deep-link page.
		add_submenu_page(
			'credoq',
			__( 'Seat Plan Builder', 'credoq-seats' ),
			'',
			'manage_options',
			'credoq-seat-builder',
			array( Plan_Builder_Page::class, 'render' )
		);

		add_submenu_page(
			'credoq',
			__( 'Seat Bookings', 'credoq-seats' ),
			__( 'Seat Bookings', 'credoq-seats' ),
			'manage_options',
			'credoq-seat-bookings',
			array( Seat_Bookings_Page::class, 'render' )
		);
	}

	public static function add_sidebar_items( array $items ) : array {
		$items[] = array(
			'slug'     => 'credoq-seat-plans',
			'label'    => __( 'Seat Plans', 'credoq-seats' ),
			'icon'     => 'layout-grid',
			'url'      => admin_url( 'admin.php?page=credoq-seat-plans' ),
			'group'    => __( 'Visual Seats', 'credoq-seats' ),
			'priority' => 60,
		);
		$items[] = array(
			'slug'     => 'credoq-seat-bookings',
			'label'    => __( 'Seat Bookings', 'credoq-seats' ),
			'icon'     => 'ticket',
			'url'      => admin_url( 'admin.php?page=credoq-seat-bookings' ),
			'group'    => __( 'Visual Seats', 'credoq-seats' ),
			'priority' => 61,
		);
		return $items;
	}
}
