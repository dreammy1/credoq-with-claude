<?php
/**
 * Events admin menu — registers submenus under the Credoq parent AND
 * registers sidebar entries for the Engine's custom Shell UI.
 *
 * FIX: Previously only called add_action('admin_menu', ...) — never populated
 * the Shell sidebar (credoq_admin_sidebar_items filter). Both now handled here.
 *
 * @package CredoqEvents\Admin
 */

namespace CredoqEvents\Admin;

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
			__( 'Events', 'credoq-events' ),
			__( 'Events', 'credoq-events' ),
			'manage_options',
			'credoq-events',
			[ Events_Page::class, 'render' ]
		);

		add_submenu_page(
			'credoq',
			__( 'Event Bookings', 'credoq-events' ),
			__( 'Event Bookings', 'credoq-events' ),
			'manage_options',
			'credoq-event-bookings',
			[ Event_Bookings_Page::class, 'render' ]
		);
	}

	/**
	 * Adds event sidebar items to the Engine's Shell nav.
	 *
	 * @param array $items Existing sidebar items.
	 * @return array
	 */
	public static function add_sidebar_items( array $items ) : array {
		$items[] = [
			'slug'     => 'credoq-events',
			'label'    => __( 'Events Management', 'credoq-events' ),
			'icon'     => 'ticket',
			'url'      => admin_url( 'admin.php?page=credoq-events' ),
			'group'    => __( 'Events Engine Pro', 'credoq-events' ),
			'priority' => 60,
		];
		$items[] = [
			'slug'     => 'credoq-event-bookings',
			'label'    => __( 'Event Bookings', 'credoq-events' ),
			'icon'     => 'inbox',
			'url'      => admin_url( 'admin.php?page=credoq-event-bookings' ),
			'group'    => __( 'Events Engine Pro', 'credoq-events' ),
			'priority' => 61,
		];
		return $items;
	}
}
