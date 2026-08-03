<?php
/**
 * Uninstall handler — runs only when the user deletes the plugin
 * AND the 'credoq_remove_data_on_uninstall' option is truthy.
 *
 * The Engine only owns 2 tables and ~5 options. Addons clean up
 * their own data via 'credoq_engine_uninstall' action.
 *
 * @package CredoqEngine
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'credoq_remove_data_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;

// Engine tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}credoq_submissions" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}credoq_forms" );

// Engine options.
$opts = array(
	'credoq_engine_version',
	'credoq_engine_db_version',
	'credoq_engine_settings',
	'credoq_engine_installed_at',
	'credoq_debug_mode',
	'credoq_remove_data_on_uninstall',
);
foreach ( $opts as $opt ) delete_option( $opt );

// Let addons remove their own data.
do_action( 'credoq_engine_uninstall' );
