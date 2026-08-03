<?php
/**
 * Plugin Name:       Credoq Engine
 * Plugin URI:        https://credoq.com
 * Description:       Core engine for the Credoq booking ecosystem. Provides the form builder, React booking widget, dashboard SPA, REST API, and the field-type registry that addons plug into.
 * Version:           1.1.9
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Author:            Credoq
 * Author URI:        https://credoq.com
 * Text Domain:       credoq-engine
 * Domain Path:       /languages
 *
 * @package CredoqEngine
 */

defined( 'ABSPATH' ) || exit;

// ── Plugin constants ────────────────────────────────────────────────
define( 'CREDOQ_ENGINE_VERSION', '1.1.9' );
define( 'CREDOQ_ENGINE_FILE',    __FILE__ );
define( 'CREDOQ_ENGINE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CREDOQ_ENGINE_URL',     plugin_dir_url( __FILE__ ) );
define( 'CREDOQ_ENGINE_BASENAME', plugin_basename( __FILE__ ) );

// Minimum addon API version. Addons declaring an older version are still
// loaded with a deprecation notice; newer versions are refused.
define( 'CREDOQ_ENGINE_ADDON_API_VERSION', '1.0' );

// REST API namespace used by Engine and all addons.
define( 'CREDOQ_ENGINE_REST_NS', 'credoq/v1' );

// ── PSR-4 autoloader for our own classes ────────────────────────────
spl_autoload_register( function ( $class_name ) {
    if ( strpos( $class_name, 'CredoqEngine\\' ) !== 0 ) {
        return;
    }
    $relative = substr( $class_name, strlen( 'CredoqEngine\\' ) );
    $relative = str_replace( '\\', '/', $relative );
    $path     = CREDOQ_ENGINE_DIR . 'includes/' . $relative . '.php';
    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );

// ── Function-style helpers (kept as a thin layer for templates) ─────
require_once CREDOQ_ENGINE_DIR . 'includes/functions.php';

// ── Bootstrap the plugin ────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    // Load translations.
    load_plugin_textdomain( 'credoq-engine', false, dirname( CREDOQ_ENGINE_BASENAME ) . '/languages' );

    // Boot the engine.
    \CredoqEngine\Plugin::instance()->boot();
}, 10 );

// ── Activation / deactivation hooks ─────────────────────────────────
register_activation_hook( __FILE__, [ '\CredoqEngine\Install\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\CredoqEngine\Install\Activator', 'deactivate' ] );

// uninstall.php handles full removal when the user opts in.
