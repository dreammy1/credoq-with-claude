<?php
/**
 * Asset enqueue manager.
 *
 * @package CredoqEngine
 */

namespace CredoqEngine;

defined( 'ABSPATH' ) || exit;

class Assets {

	/**
	 * Frontend wp_enqueue_scripts. Intentionally empty — the per-shortcode
	 * Assets::enqueue_widget() / Assets::enqueue_dashboard() methods are
	 * called from the shortcode render functions so we only load assets
	 * on pages that actually use them.
	 */
	public static function enqueue_frontend() : void {
		// Reserved for global frontend tweaks. Per-shortcode loads happen below.
	}

	public static function enqueue_admin( $hook ) : void {
		// Only load on Credoq admin pages.
		if ( false === strpos( (string) $hook, 'credoq' ) ) return;

		wp_enqueue_style(
			'credoq-engine-admin',
			CREDOQ_ENGINE_URL . 'assets/css/admin.css',
			array(),
			CREDOQ_ENGINE_VERSION
		);
		wp_enqueue_script(
			'credoq-engine-admin',
			CREDOQ_ENGINE_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-api-fetch' ),
			CREDOQ_ENGINE_VERSION,
			true
		);
		wp_localize_script( 'credoq-engine-admin', 'CredoqEngineAdmin', array(
			'restUrl'   => esc_url_raw( rest_url( CREDOQ_ENGINE_REST_NS . '/' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'adminUrl'  => admin_url(),
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'i18n'      => array(
				'confirm_delete' => __( 'Are you sure?', 'credoq-engine' ),
				'saved'          => __( 'Saved.', 'credoq-engine' ),
				'error'          => __( 'Something went wrong.', 'credoq-engine' ),
			),
		) );
	}

	/**
	 * Enqueue the React booking widget bundle. Called by the
	 * [credoq_booking_form] shortcode. The widget JS bundle is built
	 * from react-widget/src/ and committed to assets/js/.
	 */
	public static function enqueue_widget() : void {
		if ( wp_script_is( 'credoq-widget', 'enqueued' ) ) return;

		$css_path = CREDOQ_ENGINE_DIR . 'assets/css/booking-widget.min.css';
		$js_path  = CREDOQ_ENGINE_DIR . 'assets/js/booking-widget.min.js';
		// Use file mtime as cache buster so updates are picked up immediately.
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : CREDOQ_ENGINE_VERSION;
		$js_ver   = file_exists( $js_path )  ? (string) filemtime( $js_path )  : CREDOQ_ENGINE_VERSION;

		wp_enqueue_style(
			'credoq-widget',
			CREDOQ_ENGINE_URL . 'assets/css/booking-widget.min.css',
			array(),
			$css_ver
		);
		wp_enqueue_script(
			'credoq-widget',
			CREDOQ_ENGINE_URL . 'assets/js/booking-widget.min.js',
			[],
			$js_ver,
			true
		);
		wp_localize_script( 'credoq-widget', 'credoqWidget', [
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'credoq_nonce' ),
			'rest_url'   => esc_url_raw( rest_url( CREDOQ_ENGINE_REST_NS . '/' ) ),
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
		] );
	}

	/**
	 * Enqueue the dashboard CSS + SPA router JS. Called by the
	 * [credoq_dashboard_app], [credoq_sidebar_nav], [credoq_bottom_nav]
	 * shortcodes.
	 */
	public static function enqueue_dashboard() : void {
		if ( wp_style_is( 'credoq-dashboard', 'enqueued' ) ) return;

		wp_enqueue_style(
			'credoq-dashboard',
			CREDOQ_ENGINE_URL . 'assets/css/dashboard.css',
			array(),
			CREDOQ_ENGINE_VERSION
		);
		wp_enqueue_script(
			'credoq-dashboard-spa',
			CREDOQ_ENGINE_URL . 'assets/js/dashboard-spa.js',
			[],
			CREDOQ_ENGINE_VERSION,
			true
		);
		// Localize the shared nonce used by all addon AJAX handlers.
		// Addons call wp_create_nonce('credoq_nonce') to verify against this.
		wp_localize_script( 'credoq-dashboard-spa', 'credoqShared', [
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'credoq_nonce' ),
			'rest_url'   => esc_url_raw( rest_url( CREDOQ_ENGINE_REST_NS . '/' ) ),
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			'logged_in'  => is_user_logged_in(),
			'user_id'    => get_current_user_id(),
		] );
	}

	/**
	 * Returns true if the current admin screen is a Credoq admin page.
	 * Used by the Admin\Shell class to engage the takeover UI.
	 */
	public static function is_credoq_admin_screen() : bool {
		if ( ! is_admin() ) return false;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page ) return false;
		return ( 'credoq' === $page || 0 === strpos( $page, 'credoq-' ) || 0 === strpos( $page, 'credoq_' ) );
	}
}
