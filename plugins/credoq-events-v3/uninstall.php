<?php
// AUDIT-FIX B-4
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
if ( get_option( 'credoq_events_delete_data', 0 ) ) {
    global $wpdb;
    foreach ( ['credoq_events','credoq_event_bookings'] as $t ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" );
    }
    delete_option('credoq_events_db_version');
}
