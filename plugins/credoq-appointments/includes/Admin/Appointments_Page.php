<?php
namespace CredoqAppointments\Admin;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Appointment_Repository;
use CredoqAppointments\Staff_Repository;

class Appointments_Page {

    public static function render() : void {
        // SECURITY FIX: Check capability at entry point BEFORE any data operations
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        // SECURITY FIX: Handle delete with nonce verification
        if ( isset( $_GET['delete'] ) ) {
            if ( ! isset( $_GET['_wpnonce'] ) ) {
                wp_die( 'Missing nonce verification' );
            }
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
            $delete_id = absint( $_GET['delete'] );
            // SECURITY FIX: Use form_id in nonce action to prevent nonce reuse
            if ( wp_verify_nonce( $nonce, 'credoq_delete_apt_' . $delete_id ) ) {
                Appointment_Repository::delete( $delete_id );
                echo '<div class="notice notice-success is-dismissible"><p>Service deleted.</p></div>';
            } else {
                wp_die( 'Nonce verification failed' );
            }
        }

        // SECURITY FIX: Check admin referer for POST form saves
        if ( isset( $_POST['submit'] ) ) {
            check_admin_referer( 'credoq_save_apt' );
            self::handle_save();
        }

        $editing = isset( $_GET['edit'] );
        $apt     = null;
        if ( $editing ) {
            $id  = absint( $_GET['edit'] );
            $apt = $id ? Appointment_Repository::find( $id ) : null;
            if ( ! $apt ) {
                $apt = (object)[
                    'id' => 0, 'title' => '', 'location' => '', 'description' => '',
                    'duration' => 60, 'slot_interval' => 60, 'max_bookings' => 1,
                    'base_price' => '0.00', 'wc_product_id' => 0, 'staff_ids' => '[]',
                    'availability' => '{}', 'allow_multi_booking' => 0, 'multi_price_mode' => 'per_session',
                    'multi_day_rate' => '0.00', 'capacity_mode' => 'per_staff', 'capacity_value' => 1,
                    'min_schedules' => 1, 'max_schedules' => 1, 'credit_deduct_enabled' => 0,
                    'credit_deduct_amount' => 1, 'booking_settings' => '{}', 'accent_color' => '#4f46e5', 'image_url' => '',
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
                    <span class="dashicons dashicons-calendar-alt" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
                    <?php esc_html_e( 'Services / Appointments', 'credoq-appointments' ); ?>
                </h1>
                <?php if ( ! $editing ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'edit', '0', admin_url( 'admin.php?page=credoq-appointments' ) ) ); ?>"
                   class="button button-primary">+ Add New Service</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        if ( $editing ) {
            self::render_edit_form( $apt );
        } else {
            self::render_list();
        }
        echo '</div>';
    }

    private static function handle_save() : void {
        // Admin referer already checked at render() entry point
        $avail = [];
        foreach ( ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day ) {
            $avail[ $day ] = [
                'closed' => empty( $_POST[ "avail_{$day}_enabled" ] ),
                'hours'  => [],
            ];
            if ( ! empty( $_POST[ "avail_{$day}_enabled" ] ) ) {
                $starts = (array) ( $_POST[ "avail_{$day}_start" ] ?? [] );
                $ends   = (array) ( $_POST[ "avail_{$day}_end" ] ?? [] );
                foreach ( $starts as $i => $start ) {
                    if ( ! empty( $start ) && ! empty( $ends[ $i ] ) ) {
                        $avail[ $day ]['hours'][] = [
                            'start' => sanitize_text_field( $start ),
                            'end'   => sanitize_text_field( $ends[ $i ] ),
                        ];
                    }
                }
            }
        }
    }
}
