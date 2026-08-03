<?php
// AUDIT-FIX (production-readiness pass): every other Credoq addon
// (Appointments' credoq_apt_delete_data, Events' credoq_events_delete_data,
// and the Engine's own credoq_remove_data_on_uninstall) gates its table
// drop behind an explicit opt-in option, so deleting the plugin from the
// Plugins screen doesn't silently destroy data unless the site owner asked
// for that. This file used to drop every seats table unconditionally on
// ANY plugin deletion — including a routine "deactivate, delete, reinstall
// a fresh copy" — with no way to opt out. Brought in line with the other
// three addons.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'credoq_seats_delete_data', 0 ) ) {
	return;
}

global $wpdb;

foreach ( array( 'credoq_seat_bookings', 'credoq_seats', 'credoq_seat_plan_floors', 'credoq_seat_plans' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
}

delete_option( 'credoq_seats_db_version' );
delete_option( 'credoq_seats_delete_data' );
wp_clear_scheduled_hook( 'credoq_seats_expire_holds' );
