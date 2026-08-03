<?php
namespace CredoqSeats\Admin;

use CredoqSeats\Repositories\Booking_Repository;
use CredoqSeats\Repositories\Plan_Repository;

defined( 'ABSPATH' ) || exit;

class Seat_Bookings_Page {

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		Booking_Repository::expire_holds();

		if ( isset( $_POST['credoq_cancel_seat_booking'] ) && check_admin_referer( 'credoq_seat_bookings' ) ) {
			global $wpdb;
			$id = absint( $_POST['booking_id'] ?? 0 );
			$wpdb->update( $wpdb->prefix . 'credoq_seat_bookings', array( 'status' => 'cancelled' ), array( 'id' => $id ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Booking cancelled.', 'credoq-seats' ) . '</p></div>';
		}

		$plan_id = absint( $_GET['plan_id'] ?? 0 );
		$date    = sanitize_text_field( $_GET['date'] ?? '' );
		$status  = sanitize_key( $_GET['status'] ?? '' );
		$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) );

		$data  = Booking_Repository::list_bookings( array_filter( array(
			'plan_id' => $plan_id, 'date' => $date, 'status' => $status,
		) ), 50, $paged );
		$plans = Plan_Repository::all();
		?>
		<div class="wrap credoq-admin-wrap">
			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-tickets-alt" style="font-size:26px;margin-right:8px;color:#4f46e5;"></span>
						<?php esc_html_e( 'Seat Bookings', 'credoq-seats' ); ?>
					</h1>
				</div>
			</div>

			<form method="get" class="credoq-card" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:16px 0;">
				<input type="hidden" name="page" value="credoq-seat-bookings">
				<div>
					<label class="credoq-field-label"><?php esc_html_e( 'Plan', 'credoq-seats' ); ?></label>
					<select name="plan_id">
						<option value="0"><?php esc_html_e( 'All plans', 'credoq-seats' ); ?></option>
						<?php foreach ( $plans as $p ) : ?>
							<option value="<?php echo (int) $p->id; ?>" <?php selected( $plan_id, (int) $p->id ); ?>><?php echo esc_html( $p->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="credoq-field-label"><?php esc_html_e( 'Date', 'credoq-seats' ); ?></label>
					<input type="date" name="date" value="<?php echo esc_attr( $date ); ?>">
				</div>
				<div>
					<label class="credoq-field-label"><?php esc_html_e( 'Status', 'credoq-seats' ); ?></label>
					<select name="status">
						<option value=""><?php esc_html_e( 'Any', 'credoq-seats' ); ?></option>
						<?php foreach ( array( 'held', 'confirmed', 'cancelled' ) as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div><button class="button button-primary"><?php esc_html_e( 'Filter', 'credoq-seats' ); ?></button></div>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Seat', 'credoq-seats' ); ?></th>
						<th><?php esc_html_e( 'Type', 'credoq-seats' ); ?></th>
						<th><?php esc_html_e( 'Booked by', 'credoq-seats' ); ?></th>
						<th><?php esc_html_e( 'Ref type', 'credoq-seats' ); ?></th>
						<th><?php esc_html_e( 'Date / Time', 'credoq-seats' ); ?></th>
						<th><?php esc_html_e( 'Status', 'credoq-seats' ); ?></th>
						<th><?php esc_html_e( 'Price', 'credoq-seats' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'credoq-seats' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="8" style="color:#94a3b8;"><?php esc_html_e( 'No bookings found.', 'credoq-seats' ); ?></td></tr>
				<?php else : foreach ( $data['rows'] as $row ) :
					$who = $row->guest_email ?: ( $row->user_id ? get_userdata( $row->user_id )->user_email ?? ( '#' . $row->user_id ) : '—' );
				?>
					<tr>
						<td><strong><?php echo esc_html( $row->seat_label ); ?></strong></td>
						<td><?php echo esc_html( $row->seat_type ); ?></td>
						<td><?php echo esc_html( $who ); ?></td>
						<td><?php echo esc_html( $row->booking_type ); ?> #<?php echo (int) $row->ref_id; ?></td>
						<td><?php echo esc_html( $row->date_context . ( $row->time_context ? ' ' . $row->time_context : '' ) ); ?></td>
						<td>
							<?php
							$badge = array( 'held' => 'credoq-badge-yellow', 'confirmed' => 'credoq-badge-green', 'cancelled' => 'credoq-badge-red', 'expired' => 'credoq-badge-gray' );
							echo '<span class="credoq-badge ' . esc_attr( $badge[ $row->status ] ?? 'credoq-badge-gray' ) . '">' . esc_html( ucfirst( $row->status ) ) . '</span>';
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (float) $row->price_charged, 2 ) ); ?></td>
						<td>
							<?php if ( 'confirmed' === $row->status ) : ?>
							<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this seat booking?', 'credoq-seats' ) ); ?>');">
								<?php wp_nonce_field( 'credoq_seat_bookings' ); ?>
								<input type="hidden" name="booking_id" value="<?php echo (int) $row->id; ?>">
								<button class="button button-small" name="credoq_cancel_seat_booking" value="1"><?php esc_html_e( 'Cancel', 'credoq-seats' ); ?></button>
							</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php if ( $data['pages'] > 1 ) : ?>
			<div style="margin-top:16px;">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $data['pages'] ) ); ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
