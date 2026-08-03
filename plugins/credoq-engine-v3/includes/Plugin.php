<?php
/**
 * Engine Plugin class.
 *
 * Owns the boot sequence and exposes accessors to the field-type registry,
 * the form repository, and other shared services.
 *
 * @package CredoqEngine
 */

namespace CredoqEngine;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var \CredoqEngine\Fields\Registry */
	private $field_registry;

	/** @var \CredoqEngine\Forms\Repository */
	private $form_repository;

	/** @var \CredoqEngine\Api\Rest */
	private $rest;

	/** @var \CredoqEngine\Forms\Submission_Handler */
	private $submission_handler;

	/** @var bool */
	private $booted = false;

	public static function instance() : Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Boot the engine. Called once on plugins_loaded.
	 */
	public function boot() : void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Run schema migrations if version changed.
		Install\Schema::maybe_upgrade();

		// Core services.
		$this->field_registry     = new Fields\Registry();
		$this->form_repository    = new Forms\Repository();
		$this->submission_handler = new Forms\Submission_Handler( $this->field_registry, $this->form_repository );
		$this->rest               = new Api\Rest( $this->field_registry, $this->form_repository, $this->submission_handler );

		// Register the built-in field types (text, email, select, etc.).
		// Addons register their own field types by hooking 'credoq_register_field_types'.
		Fields\Builtin_Types::register( $this->field_registry );

		// Allow addons to register field types.
		do_action( 'credoq_register_field_types', $this->field_registry );

		// Fire 'credoq_engine_ready' so addons know all services exist.
		// Addons loaded at plugins_loaded priority 10 (same as Engine) may miss this
		// if WordPress loads them before the Engine file alphabetically.
		// Addons loaded at priority > 10 will always miss this fire.
		// FIX: We use a second fire on 'init' priority 1 via 'credoq_engine_late_init'
		// so late-loading addons (priority 20) can still wire themselves in.
		do_action( 'credoq_engine_ready', $this );

		// FIX: Re-fire on init (before any other init hooks) so addons that loaded
		// at plugins_loaded priority 20 can call on_engine_ready via late init.
		$engine = $this;
		add_action( 'init', static function () use ( $engine ) {
			do_action( 'credoq_engine_late_init', $engine );
		}, 1 );

		// Register WP hooks for everything Engine owns.
		$this->register_hooks();
	}

	private function register_hooks() : void {
		// REST routes.
		add_action( 'rest_api_init', [ $this->rest, 'register_routes' ] );

		// AJAX handlers for the booking widget (runs on init so addons can
		// override the stubs at priority 20 inside credoq_engine_ready).
		Ajax\Booking::register();

		// SMTP mailer, submission notifications (email + bell), audit log.
		Mail\Mailer::register();
		Mail\Submission_Notifier::register();
		Log\Audit_Log::register();

		// Frontend & admin asset enqueue.
		add_action( 'wp_enqueue_scripts',    [ Assets::class, 'enqueue_frontend' ] );
		add_action( 'admin_enqueue_scripts', [ Assets::class, 'enqueue_admin' ] );

		// Shortcodes (booking widget + dashboard SPA).
		// FIX: Priority 1 ensures Engine shortcodes register BEFORE any addon's init
		// callback (default priority 10). This prevents addons from accidentally
		// overwriting [credoq_booking_form] with their own add_shortcode() call.
		add_action( 'init', [ Shortcodes::class, 'register' ], 1 );

		// Admin pages.
		if ( is_admin() ) {
			// FIX: Priority 5 ensures the parent 'credoq' menu page is created
			// BEFORE any addon calls add_submenu_page('credoq',...) at priority 10.
			// Without this, addons registering at the same priority may run first,
			// and WordPress silently drops orphan submenus → "not allowed" on those pages.
			add_action( 'admin_menu', [ Admin\Menu::class, 'register' ], 5 );
			// Admin Shell — the Booknetic-style takeover UI for Credoq pages.
			Admin\Shell::boot();
		}

		// Standalone WooCommerce cart bridge for Checkbox/Select/Radio/Calculate
		// fields with "Enable WC Checkout" turned on. Safe no-op if WC is absent.
		add_action( 'init', [ Integrations\WooCommerce_Bridge::class, 'register' ], 20 );

		// HPOS compatibility declaration (WC 8.2+).
		add_action( 'before_woocommerce_init', function () {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables', CREDOQ_ENGINE_FILE, true
				);
			}
		} );
	}

	// ── Accessors used by addons via credoq_engine() helper ─────────────
	public function fields() : Fields\Registry           { return $this->field_registry; }
	public function forms() : Forms\Repository           { return $this->form_repository; }
	public function submissions() : Forms\Submission_Handler { return $this->submission_handler; }
	public function rest() : Api\Rest                    { return $this->rest; }
}
