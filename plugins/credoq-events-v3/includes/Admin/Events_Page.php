<?php
namespace CredoqEvents\Admin;
defined( 'ABSPATH' ) || exit;
use CredoqEvents\Event_Repository;

class Events_Page {

    public static function render() : void {
        // SECURITY FIX: Check capability at entry point BEFORE any data operations
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        // SECURITY FIX: Handle delete with proper nonce verification
        if ( isset( $_GET['delete'] ) ) {
            if ( ! isset( $_GET['_wpnonce'] ) ) {
                wp_die( 'Missing nonce verification' );
            }
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
            $delete_id = absint( $_GET['delete'] );
            // SECURITY FIX: Use event_id in nonce to prevent reuse
            if ( wp_verify_nonce( $nonce, 'credoq_del_event_' . $delete_id ) ) {
                Event_Repository::delete( $delete_id );
                echo '<div class="notice notice-success is-dismissible"><p>Event deleted.</p></div>';
            } else {
                wp_die( 'Nonce verification failed' );
            }
        }

        // SECURITY FIX: Check admin referer for POST form saves
        if ( isset( $_POST['submit'] ) ) {
            check_admin_referer( 'credoq_save_event' );
            self::handle_save();
        }

        $editing = isset( $_GET['edit'] );
        $event = null;
        if ( $editing ) {
            $id = absint( $_GET['edit'] );
            $event = $id ? Event_Repository::find( $id ) : null;
            if ( ! $event ) {
                $event = (object)[
                    'id' => 0,
                    'title' => '',
                    'description' => '',
                    'start_datetime' => '',
                    'end_datetime' => '',
                    'location' => '',
                    'capacity' => 0,
                    'price' => '0.00',
                    'wc_product_id' => 0,
                    'staff_id' => 0,
                    'accent_color' => '#4f46e5',
                    'image_url' => '',
                    'zoom_link' => '',
                    'google_meet_link' => '',
                    'credit_deduct_enabled' => 0,
                    'credit_deduct_amount' => 1,
                    'status' => 'published'
                ];
            }
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header">
            <div class="credoq-page-header-inner">
                <h1 class="credoq-page-title">
                    <span class="dashicons dashicons-tickets-alt" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>Events
                </h1>
                <?php if ( ! $editing ): ?>
                    <a href="<?php echo esc_url( add_query_arg( 'edit', '0', admin_url( 'admin.php?page=credoq-events' ) ) ); ?>" class="button button-primary">+ Add Event</a>
                <?php endif; ?>
            </div>
        </div>
        <?php $editing ? self::render_form( $event ) : self::render_list(); echo '</div>'; ?>
        <?php
    }

    private static function handle_save() : void {
        // Admin referer already checked at render() entry point
        $data = [
            'id'                   => absint( $_POST['event_id'] ?? 0 ),
            'title'                => sanitize_text_field( $_POST['title'] ?? '' ),
            'description'          => wp_kses_post( $_POST['description'] ?? '' ),
            'start_datetime'       => sanitize_text_field( $_POST['start_datetime'] ?? '' ),
            'end_datetime'         => sanitize_text_field( $_POST['end_datetime'] ?? '' ),
            'location'             => sanitize_text_field( $_POST['location'] ?? '' ),
            'capacity'             => absint( $_POST['capacity'] ?? 0 ),
            'price'                => floatval( $_POST['price'] ?? 0 ),
            'wc_product_id'        => absint( $_POST['wc_product_id'] ?? 0 ),
            'staff_id'             => absint( $_POST['staff_id'] ?? 0 ),
            'accent_color'         => sanitize_hex_color( $_POST['accent_color'] ?? '#4f46e5' ),
            'image_url'            => esc_url_raw( $_POST['image_url'] ?? '' ),
            'zoom_link'            => esc_url_raw( $_POST['zoom_link'] ?? '' ),
            'google_meet_link'     => esc_url_raw( $_POST['google_meet_link'] ?? '' ),
            'credit_deduct_enabled' => absint( $_POST['credit_deduct_enabled'] ?? 0 ),
            'credit_deduct_amount' => absint( $_POST['credit_deduct_amount'] ?? 1 ),
            'status'               => sanitize_key( $_POST['status'] ?? 'published' ),
        ];
        Event_Repository::save( $data );
    }
}
