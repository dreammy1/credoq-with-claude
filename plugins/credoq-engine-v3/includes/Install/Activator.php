<?php
/**
 * Activator — runs on plugin activation/deactivation.
 *
 * @package CredoqEngine\Install
 */

namespace CredoqEngine\Install;

defined( 'ABSPATH' ) || exit;

class Activator {

	public static function activate() : void {
		// Ensure DB schema exists.
		Schema::install();

		// Set a baseline option.
		add_option( 'credoq_engine_version',  CREDOQ_ENGINE_VERSION );
		add_option( 'credoq_engine_installed_at', current_time( 'mysql', true ) );

		// Default settings.
		if ( false === get_option( 'credoq_engine_settings' ) ) {
			add_option( 'credoq_engine_settings', array(
				'currency'    => 'USD',
				'date_format' => 'Y-m-d',
				'time_format' => 'H:i',
				'debug_mode'  => 0,
			) );
		}

		// Fire so addons can run their own activation tasks.
		do_action( 'credoq_engine_activated' );

		// Flush rewrites once (REST namespace is already covered by core).
		flush_rewrite_rules();
	}

	public static function deactivate() : void {
		do_action( 'credoq_engine_deactivated' );
		flush_rewrite_rules();
	}
}
