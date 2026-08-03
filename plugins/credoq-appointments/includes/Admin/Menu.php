<?php
/**
 * Appointments admin menu — registers submenus under the Credoq parent AND
 * registers sidebar entries for the Engine's custom Shell UI.
 *
 * FIX: Previously only called add_action('admin_menu', ...) — never populated
 * the Shell sidebar (credoq_admin_sidebar_items filter). Both now handled here.
 *
 * @package CredoqAppointments\Admin
 */

namespace CredoqAppointments\Admin;

defined( 'ABSPATH' ) || exit;

class Menu {

	/**
	 * Called from Plugin::on_engine_ready().
	 * Registers both the WP admin_menu subpages AND the Shell sidebar items.
	 */
	public static function register() : void {
		// 1. WP native admin menu subpages.
		add_action( 'admin_menu', [ __CLASS__, 'add_submenus' ] );

		// 2. Shell sidebar items.
		add_filter( 'credoq_admin_sidebar_items', [ __CLASS__, 'add_sidebar_items' ] );
	}

	/** Registers WP submenu pages under the 'credoq' parent slug. */
	public static function add_submenus() : void {
		add_submenu_page(
			'credoq',
			__( 'Appointments', 'credoq-appointments' ),
			__( 'Appointments', 'credoq-appointments' ),
			'manage_options',
			'credoq-appointments',
			[ Appointments_Page::class, 'render' ]
		);

		add_submenu_page(
			'credoq',
			__( 'Staff', 'credoq-appointments' ),
			__( 'Staff', 'credoq-appointments' ),
			'manage_options',
			'credoq-staff',
			[ Staff_Page::class, 'render' ]
		);

		add_submenu_page(
			'credoq',
			__( 'Bookings', 'credoq-appointments' ),
			__( 'Bookings', 'credoq-appointments' ),
			'manage_options',
			'credoq-bookings',
			[ Bookings_Page::class, 'render' ]
		);

		add_submenu_page(
			'credoq',
			__( 'Booking Settings', 'credoq-appointments' ),
			__( 'Booking Settings', 'credoq-appointments' ),
			'manage_options',
			'credoq-booking-settings',
			[ Booking_Settings_Page::class, 'render' ]
		);
	}

	/**
	 * Adds appointment sidebar items to the Engine's Shell nav.
	 *
	 * @param array $items Existing sidebar items.
	 * @return array
	 */
	public static function add_sidebar_items( array $items ) : array {
		$items[] = [
			'slug'     => 'credoq-appointments',
			'label'    => __( 'Appointments', 'credoq-appointments' ),
			'icon'     => 'calendar',
			'url'      => admin_url( 'admin.php?page=credoq-appointments' ),
			'group'    => __( 'Appointments', 'credoq-appointments' ),
			'priority' => 50,
		];
		$items[] = [
			'slug'     => 'credoq-staff',
			'label'    => __( 'Staff', 'credoq-appointments' ),
			'icon'     => 'users',
			'url'      => admin_url( 'admin.php?page=credoq-staff' ),
			'group'    => __( 'Appointments', 'credoq-appointments' ),
			'priority' => 51,
		];
		$items[] = [
			'slug'     => 'credoq-bookings',
			'label'    => __( 'Bookings', 'credoq-appointments' ),
			'icon'     => 'inbox',
			'url'      => admin_url( 'admin.php?page=credoq-bookings' ),
			'group'    => __( 'Appointments', 'credoq-appointments' ),
			'priority' => 52,
		];
		$items[] = [
			'slug'     => 'credoq-booking-settings',
			'label'    => __( 'Booking Settings', 'credoq-appointments' ),
			'icon'     => 'settings',
			'url'      => admin_url( 'admin.php?page=credoq-booking-settings' ),
			'group'    => __( 'Appointments', 'credoq-appointments' ),
			'priority' => 53,
		];
		return $items;
	}
}
