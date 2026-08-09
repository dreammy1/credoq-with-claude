<?php
/**
 * Plugin Name: Credoq Appointments
 * Plugin URI:  https://credoq.com
 * Description: Full booking engine — services, staff, slot generation, WooCommerce checkout, waiting list, email reminders.
 * Version:     1.2.5
 * Author:      Credoq
 * Author URI:  https://credoq.com
 * Text Domain: credoq-appointments
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */
defined( 'ABSPATH' ) || exit;

define( 'CREDOQ_APT_VERSION', '1.2.5' );
define( 'CREDOQ_APT_FILE',    __FILE__ );
define( 'CREDOQ_APT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CREDOQ_APT_URL',     plugin_dir_url( __FILE__ ) );

spl_autoload_register( function ( string $class ) : void {
    if ( strpos( $class, 'CredoqAppointments\\' ) !== 0 ) return;
    $relative = str_replace( [ 'CredoqAppointments\\', '\\' ], [ '', '/' ], $class );
    $file      = CREDOQ_APT_DIR . 'includes/' . $relative . '.php';
    if ( file_exists( $file ) ) require_once $file;
} );

add_action( 'plugins_loaded', function () : void {
    if ( ! defined( 'CREDOQ_ENGINE_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Credoq Appointments</strong> requires <strong>Credoq Engine</strong>.</p></div>';
        } );
        return;
    }
    \CredoqAppointments\Plugin::boot();
}, 20 ); // FIX: priority 20 — Engine boots at 10, so we must be > 10 to guarantee Engine is ready

add_action( 'before_woocommerce_init', function () : void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CREDOQ_APT_FILE, true );
    }
} );

register_activation_hook( __FILE__, function () : void {
    \CredoqAppointments\Schema::install();
    \CredoqAppointments\Cron\Apt_Cron::schedule();
} );
register_deactivation_hook( __FILE__, function () : void {
    \CredoqAppointments\Cron\Apt_Cron::clear();
} );
