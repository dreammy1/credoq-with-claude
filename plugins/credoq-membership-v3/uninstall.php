<?php
/**
 * AUDIT-FIX (production-readiness pass): every other Credoq plugin
 * (Engine's credoq_remove_data_on_uninstall, Appointments' credoq_apt_
 * delete_data, Events' credoq_events_delete_data, Seats' credoq_seats_
 * delete_data) ships an uninstall.php gated behind an explicit opt-in
 * option, so deleting the plugin from the Plugins screen doesn't
 * silently destroy data. Credoq Membership had no uninstall.php at all —
 * meaning its tables and options were simply orphaned forever on
 * deletion (safe, but untidy) with no way to clean them up even
 * intentionally. Brought in line with the rest of the suite.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'credoq_membership_delete_data', 0 ) ) {
	return;
}

global $wpdb;

foreach ( array( 'credoq_credit_ledger', 'credoq_user_memberships', 'credoq_membership_plans' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
}

delete_option( 'credoq_membership_db_version' );
delete_option( 'credoq_membership_delete_data' );
