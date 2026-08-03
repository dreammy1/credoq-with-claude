<?php
/**
 * Plugin Name: Credoq Visual Seats Pro
 * Plugin URI:  https://credoq.com
 * Description: Visual seat-map builder and real-time seat selection for Credoq Events and Credoq Appointments — floors, zones, templates, holds, and per-seat pricing.
 * Version:     1.2.1
 * Author:      Credoq
 * Author URI:  https://credoq.com
 * Text Domain: credoq-seats
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */
defined( 'ABSPATH' ) || exit;

define( 'CREDOQ_SEATS_VERSION', '1.2.1' );
define( 'CREDOQ_SEATS_FILE',    __FILE__ );
define( 'CREDOQ_SEATS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CREDOQ_SEATS_URL',     plugin_dir_url( __FILE__ ) );

spl_autoload_register( function ( string $class ) : void {
	if ( strpos( $class, 'CredoqSeats\\' ) !== 0 ) return;
	$relative = str_replace( [ 'CredoqSeats\\', '\\' ], [ '', '/' ], $class );
	$file     = CREDOQ_SEATS_DIR . 'includes/' . $relative . '.php';
	if ( file_exists( $file ) ) require_once $file;
} );

add_action( 'plugins_loaded', function () : void {
	if ( ! defined( 'CREDOQ_ENGINE_VERSION' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Credoq Visual Seats Pro</strong> requires <strong>Credoq Engine</strong> to be installed and active.</p></div>';
		} );
		return;
	}
	\CredoqSeats\Plugin::boot();
}, 20 ); // Engine boots at 10; Appointments/Events also boot at 20 — order between
         // addons at the same priority doesn't matter because every bridge below
         // is written to tolerate the other addon not being active yet.

register_activation_hook( __FILE__, function () : void {
	\CredoqSeats\Schema::install();
	\CredoqSeats\Cron\Seats_Cron::schedule();
} );
register_deactivation_hook( __FILE__, function () : void {
	\CredoqSeats\Cron\Seats_Cron::clear();
} );
