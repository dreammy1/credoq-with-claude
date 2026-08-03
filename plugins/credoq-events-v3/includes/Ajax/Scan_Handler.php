<?php
/**
 * Scan_Handler — REST endpoints for QR attendee verification.
 *
 * POST /credoq/v1/events/scan
 *   Accepts { qr_token, action: 'valid'|'invalid'|'rejected'|'expired' }
 *   Returns attendee info + appends a timestamped entry to scan_logs.
 *
 * GET  /credoq/v1/events/my-bookings
 *   Returns all event bookings for the current logged-in user.
 *
 * POST /credoq/v1/events/notify-paid
 *   Internal: called by WooCommerce hook to push notification to user.
 *
 * @package CredoqEvents\Ajax
 */

namespace CredoqEvents\Ajax;

defined( 'ABSPATH' ) || exit;

class Scan_Handler {

    public static function register() : void {
        add_action( 'credoq_rest_routes', [ __CLASS__, 'register_routes' ], 10, 1 );
        // WC order paid → user notification + confirm booking
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'on_order_paid' ], 20, 1 );
        add_action( 'woocommerce_order_status_completed',  [ __CLASS__, 'on_order_paid' ], 20, 1 );
    }

    public static function register_routes( $rest_api ) : void {
        $ns = CREDOQ_ENGINE_REST_NS;

        register_rest_route( $ns, '/events/scan', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'handle_scan' ],
            'permission_callback' => [ __CLASS__, 'staff_permission' ],
        ] );

        register_rest_route( $ns, '/events/my-bookings', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'my_bookings' ],
            'permission_callback' => 'is_user_logged_in',
        ] );

        register_rest_route( $ns, '/events/my-notifications', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'my_notifications' ],
            'permission_callback' => 'is_user_logged_in',
        ] );

        register_rest_route( $ns, '/events/mark-read', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'mark_notification_read' ],
            'permission_callback' => 'is_user_logged_in',
        ] );
    }

    /** Allow admins AND any user with the custom 'credoq_scan_attendees' capability */
    public static function staff_permission() : bool {
        return current_user_can( 'manage_options' ) || current_user_can( 'credoq_scan_attendees' );
    }

    /** POST /events/scan — verify a QR token and log the scan action */
    public static function handle_scan( \WP_REST_Request $req ) : \WP_REST_Response {
        global $wpdb;
        $token  = sanitize_text_field( $req->get_param( 'qr_token' ) ?? '' );
        $action = sanitize_key( $req->get_param( 'action' ) ?? 'valid' );

        if ( ! in_array( $action, [ 'valid', 'invalid', 'rejected', 'expired' ], true ) ) {
            $action = 'valid';
        }

        $table   = $wpdb->prefix . 'credoq_event_bookings';
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT eb.*, e.title AS event_title, e.start_datetime, e.location
             FROM {$table} eb
             LEFT JOIN {$wpdb->prefix}credoq_events e ON eb.event_id = e.id
             WHERE eb.qr_token = %s LIMIT 1", $token
        ) );

        if ( ! $booking ) {
            return rest_ensure_response( [ 'success' => false, 'error' => 'QR token not found.' ] );
        }

        // Append scan log entry
        $scan_logs = json_decode( $booking->scan_logs ?: '[]', true ) ?: [];
        $scan_logs[] = [
            'action'    => $action,
            'staff_id'  => get_current_user_id(),
            'staff_name'=> wp_get_current_user()->display_name,
            'timestamp' => current_time( 'mysql' ),
        ];

        $wpdb->update( $table, [ 'scan_logs' => wp_json_encode( $scan_logs ) ], [ 'id' => $booking->id ] );

        $attendee_name  = $booking->user_id
            ? get_userdata( $booking->user_id )->display_name ?? $booking->guest_name
            : $booking->guest_name;

        return rest_ensure_response( [
            'success'       => true,
            'action'        => $action,
            'booking_id'    => (int)$booking->id,
            'event_title'   => $booking->event_title,
            'event_date'    => $booking->start_datetime,
            'location'      => $booking->location,
            'attendee_name' => $attendee_name,
            'attendee_email'=> $booking->guest_email ?: ( $booking->user_id ? get_userdata($booking->user_id)->user_email ?? '' : '' ),
            'quantity'       => (int)$booking->quantity,
            'booking_status' => $booking->status,
            'scan_logs'      => $scan_logs,
        ] );
    }

    /** GET /events/my-bookings — all event bookings for the logged-in user */
    public static function my_bookings( \WP_REST_Request $req ) : \WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        if ( ! $user_id ) return rest_ensure_response( [] );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT eb.*, e.title AS event_title, e.start_datetime, e.end_datetime,
                    e.location, e.price, e.image_url, e.accent_color
             FROM {$wpdb->prefix}credoq_event_bookings eb
             LEFT JOIN {$wpdb->prefix}credoq_events e ON eb.event_id = e.id
             WHERE eb.user_id = %d
             ORDER BY e.start_datetime DESC", $user_id
        ) );

        $result = [];
        foreach ( (array)$rows as $b ) {
            $result[] = [
                'id'          => (int)$b->id,
                'event_id'    => (int)$b->event_id,
                'event_title' => (string)$b->event_title,
                'start'       => (string)$b->start_datetime,
                'end'         => (string)$b->end_datetime,
                'location'    => (string)$b->location,
                'quantity'    => (int)$b->quantity,
                'total_price' => (float)$b->total_price,
                'status'      => (string)$b->status,
                'qr_token'    => (string)$b->qr_token,
                'qr_url'      => self::qr_url( $b->qr_token ),
                'image_url'   => (string)$b->image_url,
                'color'       => (string)$b->accent_color,
                'wc_order_id' => (int)$b->wc_order_id,
                'created_at'  => (string)$b->created_at,
            ];
        }
        return rest_ensure_response( $result );
    }

    /** GET /events/my-notifications */
    public static function my_notifications( \WP_REST_Request $req ) : \WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}credoq_notifications
             WHERE user_id = %d ORDER BY created_at DESC LIMIT 50", $user_id
        ) );
        return rest_ensure_response( array_map( fn($r) => [
            'id'         => (int)$r->id,
            'type'       => (string)$r->type,
            'title'      => (string)$r->title,
            'message'    => (string)$r->message,
            'is_read'    => (bool)$r->is_read,
            'created_at' => (string)$r->created_at,
        ], (array)$rows ) );
    }

    /** POST /events/mark-read */
    public static function mark_notification_read( \WP_REST_Request $req ) : \WP_REST_Response {
        global $wpdb;
        $id = absint( $req->get_param( 'id' ) ?? 0 );
        $wpdb->update( $wpdb->prefix . 'credoq_notifications',
            [ 'is_read' => 1 ],
            [ 'id' => $id, 'user_id' => get_current_user_id() ]
        );
        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * Generate (or load from DB) a unique QR token for an event booking,
     * and return the Google Charts QR code image URL.
     */
    public static function qr_url( string $token ) : string {
        if ( ! $token ) return '';
        $data = site_url( '/credoq-event-qr/' . rawurlencode( $token ) );
        return 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . rawurlencode( $data ) . '&choe=UTF-8';
    }

    /**
     * Ensure the event booking has a QR token; generate one if missing.
     * Called when a new event booking row is created.
     */
    public static function ensure_qr_token( int $booking_id ) : string {
        global $wpdb;
        $table   = $wpdb->prefix . 'credoq_event_bookings';
        $current = $wpdb->get_var( $wpdb->prepare( "SELECT qr_token FROM {$table} WHERE id=%d", $booking_id ) );
        if ( $current ) return $current;

        $token = wp_generate_password( 32, false );
        $wpdb->update( $table, [ 'qr_token' => $token ], [ 'id' => $booking_id ] );
        return $token;
    }

    /** WC order paid → confirm event bookings + push notification */
    public static function on_order_paid( int $order_id ) : void {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $table = $wpdb->prefix . 'credoq_event_bookings';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE wc_order_id = %d", $order_id
        ) );

        foreach ( (array)$rows as $b ) {
            // Confirm booking
            $wpdb->update( $table, [ 'status' => 'confirmed' ], [ 'id' => $b->id ] );
            // Ensure QR token
            $token = self::ensure_qr_token( (int)$b->id );

            // Push user notification (into credoq_notifications if table exists)
            $uid = (int)$b->user_id;
            if ( $uid ) {
                $event = $wpdb->get_row( $wpdb->prepare(
                    "SELECT title, start_datetime FROM {$wpdb->prefix}credoq_events WHERE id=%d", (int)$b->event_id
                ) );
                $ev_title = $event ? $event->title : 'Event #' . $b->event_id;
                $ev_date  = $event ? date_i18n( get_option('date_format'), strtotime($event->start_datetime) ) : '';

                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix.'credoq_notifications' ) ) === $wpdb->prefix.'credoq_notifications' ) {
                    $wpdb->insert( $wpdb->prefix . 'credoq_notifications', [
                        'user_id'    => $uid,
                        'type'       => 'event_confirmed',
                        'title'      => __( 'Event Booking Confirmed!', 'credoq-events' ),
                        'message'    => sprintf(
                            __( 'Your booking for "%1$s" on %2$s (×%3$d ticket(s)) is confirmed. Your QR code is ready in My Events.', 'credoq-events' ),
                            $ev_title, $ev_date, (int)$b->quantity
                        ),
                        'is_read'    => 0,
                        'created_at' => current_time( 'mysql' ),
                    ] );
                }
            }
        }
    }
}
