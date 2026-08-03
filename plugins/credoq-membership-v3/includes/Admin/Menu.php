<?php
/**
 * CredoqMembership\Admin\Menu — registers Membership pages under the
 * Credoq Engine admin shell and injects sidebar navigation entries.
 *
 * Two hooks are required by the Engine's Tools page health-checker:
 *   add_submenu   → admin_menu hook that calls add_submenu_page()
 *   add_sidebar_items → credoq_admin_sidebar_items filter
 *
 * @package CredoqMembership\Admin
 */

namespace CredoqMembership\Admin;

defined( 'ABSPATH' ) || exit;

class Menu {

	/**
	 * Register all Membership sub-pages under the Credoq parent menu.
	 *
	 * Method name is add_submenus (plural) — this is what the Engine's
	 * Tools page health-checker looks for on the 'admin_menu' hook.
	 */
	public static function add_submenus() : void {
		$parent = 'credoq'; // Engine's registered parent slug.

		add_submenu_page(
			$parent,
			__( 'Membership Plans', 'credoq-membership' ),
			__( 'Membership Plans', 'credoq-membership' ),
			'manage_options',
			'credoq-membership-plans',
			[ Plans_Page::class, 'render' ]
		);

		add_submenu_page(
			$parent,
			__( 'Memberships', 'credoq-membership' ),
			__( 'Memberships', 'credoq-membership' ),
			'manage_options',
			'credoq-memberships',
			[ Users_Page::class, 'render' ]
		);
	}

	/**
	 * Inject Membership entries into the Engine's left-sidebar navigation.
	 * The Engine's Shell reads this filter to build its custom sidebar list.
	 *
	 * Each entry:
	 *   slug  (string) — the ?page= query arg, must match add_submenu_page slug
	 *   label (string) — visible sidebar label
	 *   icon  (string) — dashicons class name without the 'dashicons-' prefix
	 *   url   (string) — full admin URL
	 *
	 * @param array $items  Existing sidebar items from the Engine and other addons.
	 * @return array
	 */
	public static function add_sidebar_items( array $items ) : array {
		$items[] = array(
			'slug'  => 'credoq-membership-plans',
			'label' => __( 'Membership Plans', 'credoq-membership' ),
			'icon'  => 'id-alt',
			'url'   => admin_url( 'admin.php?page=credoq-membership-plans' ),
		);

		$items[] = array(
			'slug'  => 'credoq-memberships',
			'label' => __( 'Memberships', 'credoq-membership' ),
			'icon'  => 'groups',
			'url'   => admin_url( 'admin.php?page=credoq-memberships' ),
		);

		return $items;
	}

	/** Register all hooks. Called from Plugin::boot(). */
	public static function register() : void {
		add_action( 'admin_menu', [ __CLASS__, 'add_submenus' ], 20 );
		add_filter( 'credoq_admin_sidebar_items', [ __CLASS__, 'add_sidebar_items' ], 10, 1 );
	}
}
