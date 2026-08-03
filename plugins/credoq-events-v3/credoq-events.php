<?php
/**
 * Plugin Name: Credoq Events
 * Plugin URI:  https://credoq.com
 * Description: Events, capacity, WooCommerce ticketing, and attendee management for Credoq Engine.
 * Version:     1.1.1
 * Author:      Credoq
 * Author URI:  https://credoq.com
 * Text Domain: credoq-events
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC tested up to: 9.0
 */
defined( 'ABSPATH' ) || exit;

define( 'CREDOQ_EVENTS_VERSION', '1.1.1' );
define( 'CREDOQ_EVENTS_FILE',    __FILE__ );
define( 'CREDOQ_EVENTS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CREDOQ_EVENTS_URL',     plugin_dir_url( __FILE__ ) );

spl_autoload_register( function ( string $class ) : void {
    if ( strpos( $class, 'CredoqEvents\\' ) !== 0 ) return;
    $relative = str_replace( [ 'CredoqEvents\\', '\\' ], [ '', '/' ], $class );
    $file      = CREDOQ_EVENTS_DIR . 'includes/' . $relative . '.php';
    if ( file_exists( $file ) ) require_once $file;
} );

add_action( 'plugins_loaded', function () : void {
    if ( ! defined( 'CREDOQ_ENGINE_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Credoq Events</strong> requires <strong>Credoq Engine</strong>.</p></div>';
        } );
        return;
    }
    \CredoqEvents\Plugin::boot();
}, 20 ); // FIX: priority 20 — Engine boots at 10

add_action( 'before_woocommerce_init', function () : void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CREDOQ_EVENTS_FILE, true );
    }
} );

register_activation_hook( __FILE__, function () : void {
    \CredoqEvents\Schema::install();
} );
