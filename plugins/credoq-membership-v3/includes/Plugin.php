<?php
/**
 * CredoqMembership\Plugin — proper singleton to satisfy the Engine's
 * Tools page health-checker (which looks for this class + on_engine_ready).
 *
 * @package CredoqMembership
 */
namespace CredoqMembership;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static ?Plugin $instance = null;

	public static function boot() : void {
		// Hook both engine-ready events (defensive).
		add_action( 'credoq_engine_ready',     [ __CLASS__, 'on_engine_ready' ], 10, 1 );
		add_action( 'credoq_engine_late_init', [ __CLASS__, 'on_engine_ready' ], 10, 1 );

		// credoq_register_field_types — fires during Engine boot.
		add_action( 'credoq_register_field_types', function( $registry ) {
			if ( ! $registry->get( 'membership_credit' ) ) {
				$registry->register( new Field_Slot_Credit() );
			}
		}, 10, 1 );

		// Inject credit info into React widget config.
		add_filter( 'credoq_widget_config', [ __CLASS__, 'inject_widget_config' ], 10, 2 );

		// Admin: require page files + register menu (submenu + sidebar items).
		if ( is_admin() ) {
			require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Admin/Plans_Page.php';
			require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Admin/Users_Page.php';
			require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Admin/Menu.php';
			Admin\Menu::register();
		}
	}

	/**
	 * Called by credoq_engine_ready and credoq_engine_late_init.
	 * Guard with static flag so it only runs once.
	 */
	public static function on_engine_ready( $engine ) : void {
		static $done = false;
		if ( $done ) return;
		$done = true;

		// Schema.
		Schema::maybe_upgrade();

		// Register field type.
		if ( method_exists( $engine->fields(), 'register' ) ) {
			if ( ! $engine->fields()->get( 'membership_credit' ) ) {
				$engine->fields()->register( new Field_Slot_Credit() );
			}
		}

		// Dashboard panel (safe on both frontend and admin).
		require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Dashboard/Panel.php';
		Dashboard\Panel::init();
	}

	/**
	 * Inject member_credits into widget config (for React widget access).
	 */
	public static function inject_widget_config( array $config, $form ) : array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) return $config;

		$service = new Membership_Service();
		$plans   = $service->get_active_memberships( $user_id );

		$config['member_credits'] = [];
		foreach ( $plans as $m ) {
			$repo = new Plan_Repository();
			$plan = $repo->find( (int) $m->plan_id );
			if ( ! $plan ) continue;

			$config['member_credits'][] = [
				'plan_id'   => $plan->id,
				'plan_name' => $plan->name,
				'balance'   => $service->get_balance( $user_id, (int) $plan->id, (int) ( $config['form_id'] ?? 0 ) ),
			];
		}

		return $config;
	}

	public static function instance() : self {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}
}
