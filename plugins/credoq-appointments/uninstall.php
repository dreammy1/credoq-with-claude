<?php
// AUDIT-FIX B-4: drop ALL plugin tables on uninstall
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
if ( get_option( 'credoq_apt_delete_data', 0 ) ) {
    global $wpdb;
    foreach ( [
        'credoq_appointments',
        'credoq_staff',
        'credoq_bookings',
        'credoq_waiting_list',
    ] as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
    }
    delete_option('credoq_booking_settings');
    delete_option('credoq_apt_db_version');
}
