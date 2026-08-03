<?php
namespace CredoqSeats;

use CredoqSeats\Admin\Menu;
use CredoqSeats\Ajax\Seats_Ajax;
use CredoqSeats\Fields\Seat_Map_Field;
use CredoqSeats\Integrations\Appointments_Bridge;
use CredoqSeats\Integrations\Events_Bridge;
use CredoqSeats\Cron\Seats_Cron;
use CredoqSeats\Repositories\Plan_Repository;

defined( 'ABSPATH' ) || exit;

class Plugin {

	public static function boot() : void {
		Schema::maybe_upgrade();

		// Field type registration: the Engine fires 'credoq_register_field_types'
		// (registry as arg) during its own boot, and 'credoq_engine_ready' /
		// 'credoq_engine_late_init' (engine instance as arg) afterward as a
		// safety net for addons that boot at the same or later priority.
		// Hooking all three (same pattern Credoq Appointments/Events use)
		// means the field type registers no matter the load order.
		add_action( 'credoq_register_field_types', array( __CLASS__, 'on_register_field_types' ), 10, 1 );
		add_action( 'credoq_engine_ready',         array( __CLASS__, 'on_engine_ready' ), 10, 1 );
		add_action( 'credoq_engine_late_init',     array( __CLASS__, 'on_engine_ready' ), 10, 1 );

		Menu::register();
		Seats_Ajax::register();
		Seats_Cron::register_hooks();

		// Addon-to-addon bridges no-op internally if the other addon isn't
		// active, so it's always safe to call these regardless of load order.
		Appointments_Bridge::register();
		Events_Bridge::register();

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_builder_panel' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
	}

	/**
	 * AUDIT-FEATURE: wires the Forms Builder addon settings panel for
	 * seat_map (see assets/js/forms-builder-panel.js) — only loaded on
	 * the Credoq Forms Builder screen itself, not admin-wide.
	 */
	public static function enqueue_builder_panel() : void {
		if ( ( $_GET['page'] ?? '' ) !== 'credoq-forms' ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gate.

		wp_enqueue_script( 'credoq-seats-builder-panel', CREDOQ_SEATS_URL . 'assets/js/forms-builder-panel.js', array(), CREDOQ_SEATS_VERSION, true );

		$plans = array();
		foreach ( Plan_Repository::published() as $p ) {
			$plans[] = array( 'value' => (string) $p->id, 'label' => $p->name . ' (' . (int) $p->total_seats . ' seats)' );
		}
		$events = array();
		if ( class_exists( '\CredoqEvents\Event_Repository' ) ) {
			foreach ( \CredoqEvents\Event_Repository::all( array( 'per_page' => 200 ) ) as $e ) {
				if ( 'published' !== ( $e->status ?? '' ) ) continue;
				$events[] = array( 'value' => (string) $e->id, 'label' => $e->title );
			}
		}
		wp_localize_script( 'credoq-seats-builder-panel', 'credoqSeatsBuilderOptions', array(
			'plans'  => $plans,
			'events' => $events,
		) );
		wp_localize_script( 'credoq-seats-builder-panel', 'credoqSeatsI18n', array(
			'panelTitle' => __( 'Seat Map', 'credoq-seats' ),
			'panelSub'   => __( 'Optional overrides — leave on Auto-detect unless a plan serves more than one event/service.', 'credoq-seats' ),
			'planLabel'  => __( 'Seat plan', 'credoq-seats' ),
			'eventLabel' => __( 'Pin to event', 'credoq-seats' ),
			'autoOption' => __( 'Auto-detect (recommended)', 'credoq-seats' ),
			'planHint'   => __( 'Auto-detect resolves this from a sibling Event Registration field\'s selection, or from the plan\'s own single-event connection. Only set this if the plan is intentionally connected to more than one event/service.', 'credoq-seats' ),
			'eventHint'  => __( 'Only needed together with a Seat plan override above, for the same reason.', 'credoq-seats' ),
		) );
	}

	public static function on_register_field_types( $registry ) : void {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
			$registry->register( new Seat_Map_Field() );
		}
	}

	public static function on_engine_ready( $engine ) : void {
		static $done = false;
		if ( $done ) return;
		$done = true;

		if ( is_object( $engine ) && method_exists( $engine, 'fields' ) && method_exists( $engine->fields(), 'register' ) ) {
			$engine->fields()->register( new Seat_Map_Field() );
		}
	}

	public static function enqueue_frontend() : void {
		// Loaded unconditionally on the frontend (cheap, small files) since
		// the seat map can appear inside a shortcode-rendered React widget
		// on any page, and we can't reliably detect that in advance.
		wp_enqueue_style( 'credoq-seats-frontend', CREDOQ_SEATS_URL . 'assets/css/frontend-seat-map.css', array(), CREDOQ_SEATS_VERSION );
		wp_enqueue_script( 'credoq-seats-frontend', CREDOQ_SEATS_URL . 'assets/js/frontend-seat-map.js', array(), CREDOQ_SEATS_VERSION, true );
		wp_localize_script( 'credoq-seats-frontend', 'credoqSeatsCfg', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'credoq_nonce' ),
		) );
	}

	public static function load_textdomain() : void {
		load_plugin_textdomain( 'credoq-seats', false, dirname( plugin_basename( CREDOQ_SEATS_FILE ) ) . '/languages' );
	}
}
