<?php
namespace CredoqSeats\Ajax;

use CredoqSeats\Repositories\Plan_Repository;
use CredoqSeats\Repositories\Seat_Repository;
use CredoqSeats\Repositories\Booking_Repository;

defined( 'ABSPATH' ) || exit;

class Seats_Ajax {

	public static function register() : void {
		foreach ( array( 'credoq_seats_load_map', 'credoq_seats_get_booked', 'credoq_seats_hold', 'credoq_seats_release' ) as $action ) {
			add_action( 'wp_ajax_' . $action,        array( __CLASS__, $action ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, $action ) );
		}
	}

	private static function verify_nonce() : bool {
		return isset( $_POST['nonce'] ) && wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ), 'credoq_nonce' );
	}

	/**
	 * AUDIT-FIX (Events + Seats): a seat plan can be connected to more than
	 * one event (Plan Builder's "Connect to a service" is checkboxes, not
	 * a single select), so blindly using connected_ids[0] silently picks
	 * the WRONG event's date whenever a plan serves several. $event_id
	 * (sent by the widget once it has resolved which event the visitor
	 * picked — see FormField.jsx SeatMapField) takes priority when given
	 * and actually belongs to this plan's connections; connected_ids[0]
	 * remains the fallback for the common single-event-per-plan case.
	 */
	private static function resolve_event_id_for_plan( object $plan, int $posted_event_id ) : int {
		if ( 'event' !== $plan->connect_type ) return 0;
		$ids = array_map( 'intval', json_decode( $plan->connected_ids ?? '[]', true ) ?: array() );
		if ( $posted_event_id && in_array( $posted_event_id, $ids, true ) ) return $posted_event_id;
		return $ids[0] ?? 0;
	}

	/** Resolve a usable date_context when the widget didn't send one (Events have no date/time picker). */
	private static function resolve_date( object $plan, string $posted_date, int $event_id = 0 ) : string {
		if ( '' !== $posted_date ) return $posted_date;
		if ( 'event' === $plan->connect_type && class_exists( '\CredoqEvents\Event_Repository' ) && $event_id ) {
			$event = \CredoqEvents\Event_Repository::find( $event_id );
			if ( $event && ! empty( $event->start_datetime ) ) return substr( $event->start_datetime, 0, 10 );
		}
		return current_time( 'Y-m-d' );
	}

	public static function credoq_seats_load_map() : void {
		if ( ! self::verify_nonce() ) wp_send_json_error( array( 'message' => __( 'Security check failed.', 'credoq-seats' ) ) );

		$plan_id  = absint( $_POST['plan_id'] ?? 0 );
		$plan     = $plan_id ? Plan_Repository::find( $plan_id ) : null;
		if ( ! $plan || 'published' !== $plan->status ) {
			wp_send_json_error( array( 'message' => __( 'This seat plan is not available.', 'credoq-seats' ) ) );
		}

		$event_id = self::resolve_event_id_for_plan( $plan, absint( $_POST['event_id'] ?? 0 ) );

		$floors = Seat_Repository::floors_for_plan( $plan_id );
		$seats  = Seat_Repository::for_plan( $plan_id );
		$layout = json_decode( $plan->layout_json ?? '{}', true ) ?: array();
		$pricing = $layout['pricing'] ?? array();

		$by_floor = array();
		foreach ( $seats as $seat ) $by_floor[ $seat->floor_id ][] = $seat;

		$base_price = self::resolve_base_price( $plan, $event_id );

		ob_start();
		?>
		<div class="cvsp-map-wrap" data-plan-id="<?php echo (int) $plan_id; ?>" data-plan-name="<?php echo esc_attr( $plan->name ); ?>" data-credoq-event-id="<?php echo (int) $event_id; ?>">
			<div class="cvsp-legend">
				<span class="cvsp-legend-item"><i class="cvsp-dot type-standard"></i> <?php esc_html_e( 'Standard', 'credoq-seats' ); ?></span>
				<span class="cvsp-legend-item"><i class="cvsp-dot type-vip"></i> <?php esc_html_e( 'VIP', 'credoq-seats' ); ?></span>
				<span class="cvsp-legend-item"><i class="cvsp-dot type-accessible"></i> <?php esc_html_e( 'Accessible', 'credoq-seats' ); ?></span>
				<span class="cvsp-legend-item"><i class="cvsp-dot is-booked"></i> <?php esc_html_e( 'Taken', 'credoq-seats' ); ?></span>
				<span class="cvsp-legend-item"><i class="cvsp-dot is-selected"></i> <?php esc_html_e( 'Your selection', 'credoq-seats' ); ?></span>
			</div>

			<?php if ( count( $floors ) > 1 ) : ?>
			<div class="cvsp-floor-tabs">
				<?php foreach ( $floors as $i => $floor ) : ?>
					<button type="button" class="cvsp-floor-tab-btn <?php echo 0 === $i ? 'is-active' : ''; ?>" data-floor="<?php echo (int) $floor->id; ?>"><?php echo esc_html( $floor->name ); ?></button>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php foreach ( $floors as $i => $floor ) :
				$floor_seats = $by_floor[ $floor->id ] ?? array();
				$max_x = 0; $max_y = 0;
				foreach ( $floor_seats as $s ) { $max_x = max( $max_x, (float) $s->x_pos ); $max_y = max( $max_y, (float) $s->y_pos ); }
			?>
			<div class="cvsp-floor-canvas" data-floor-id="<?php echo (int) $floor->id; ?>" style="<?php echo 0 === $i ? '' : 'display:none;'; ?>min-width:<?php echo (int) $max_x + 60; ?>px;min-height:<?php echo (int) $max_y + 60; ?>px;">
				<?php foreach ( $floor_seats as $seat ) :
					if ( 'blocked' === $seat->status ) continue;
					$price = null !== $seat->price_override ? (float) $seat->price_override : ( $pricing[ $seat->seat_type ] ?? $base_price );
				?>
					<div class="cvsp-seat type-<?php echo esc_attr( $seat->seat_type ); ?>"
					     data-seat-id="<?php echo (int) $seat->id; ?>"
					     data-price="<?php echo esc_attr( number_format( (float) $price, 2, '.', '' ) ); ?>"
					     data-label="<?php echo esc_attr( $seat->seat_label ); ?>"
					     style="left:<?php echo esc_attr( (float) $seat->x_pos ); ?>px;top:<?php echo esc_attr( (float) $seat->y_pos ); ?>px;"
					     title="<?php echo esc_attr( $seat->seat_label . ' — ' . number_format_i18n( (float) $price, 2 ) ); ?>">
						<?php echo esc_html( $seat->seat_label ); ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endforeach; ?>

			<div class="cvsp-summary">
				<?php esc_html_e( 'Selected:', 'credoq-seats' ); ?> <span class="cvsp-sel-count">0</span> ·
				<?php esc_html_e( 'Total:', 'credoq-seats' ); ?> <span class="cvsp-sel-total">0.00</span>
			</div>
			<div class="cvsp-hold-msg" role="status"></div>
		</div>
		<?php
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	public static function credoq_seats_get_booked() : void {
		if ( ! self::verify_nonce() ) wp_send_json_error( array( 'message' => __( 'Security check failed.', 'credoq-seats' ) ) );

		$plan_id = absint( $_POST['plan_id'] ?? 0 );
		$plan    = $plan_id ? Plan_Repository::find( $plan_id ) : null;
		if ( ! $plan ) wp_send_json_error( array( 'message' => __( 'Plan not found.', 'credoq-seats' ) ) );

		$event_id = self::resolve_event_id_for_plan( $plan, absint( $_POST['event_id'] ?? 0 ) );
		$date     = self::resolve_date( $plan, sanitize_text_field( $_POST['date'] ?? '' ), $event_id );
		$time     = sanitize_text_field( $_POST['slot'] ?? '' );

		$ids = Booking_Repository::booked_seat_ids( $plan_id, $date, $time, $event_id );
		wp_send_json_success( array( 'booked_seat_ids' => $ids ) );
	}

	public static function credoq_seats_hold() : void {
		if ( ! self::verify_nonce() ) wp_send_json_error( array( 'message' => __( 'Security check failed.', 'credoq-seats' ) ) );

		$plan_id = absint( $_POST['plan_id'] ?? 0 );
		$seat_id = absint( $_POST['seat_id'] ?? 0 );
		$plan    = $plan_id ? Plan_Repository::find( $plan_id ) : null;
		if ( ! $plan || ! $seat_id ) wp_send_json_error( array( 'message' => __( 'Invalid request.', 'credoq-seats' ) ) );

		$event_id = self::resolve_event_id_for_plan( $plan, absint( $_POST['event_id'] ?? 0 ) );
		$date     = self::resolve_date( $plan, sanitize_text_field( $_POST['date'] ?? '' ), $event_id );
		$time     = sanitize_text_field( $_POST['slot'] ?? '' );

		// So the Admin > Seat Bookings price column is accurate even while
		// a seat is only 'held' (not yet confirmed at submission time).
		$base_price = self::resolve_base_price( $plan, $event_id );
		$breakdown  = Booking_Repository::calc_seats_breakdown( $plan_id, array( $seat_id ), $base_price );
		$price      = $breakdown['price_map'][ $seat_id ] ?? 0.0;

		$ok = Booking_Repository::hold_seat( $plan_id, $seat_id, $date, $time, array(
			'booking_type' => 'event' === $plan->connect_type ? 'event' : 'appointment',
			'event_id'     => $event_id,
			'guest_email'  => sanitize_email( $_POST['guest_email'] ?? '' ),
			'price'        => $price,
		) );

		if ( ! $ok ) wp_send_json_error( array( 'message' => __( 'That seat was just taken — pick another.', 'credoq-seats' ) ) );
		wp_send_json_success( array( 'held' => true ) );
	}

	public static function credoq_seats_release() : void {
		if ( ! self::verify_nonce() ) wp_send_json_error( array( 'message' => __( 'Security check failed.', 'credoq-seats' ) ) );

		$plan_id = absint( $_POST['plan_id'] ?? 0 );
		$seat_id = absint( $_POST['seat_id'] ?? 0 );
		$plan    = $plan_id ? Plan_Repository::find( $plan_id ) : null;
		if ( ! $plan || ! $seat_id ) wp_send_json_error();

		$event_id = self::resolve_event_id_for_plan( $plan, absint( $_POST['event_id'] ?? 0 ) );
		$date     = self::resolve_date( $plan, sanitize_text_field( $_POST['date'] ?? '' ), $event_id );
		$time     = sanitize_text_field( $_POST['slot'] ?? '' );

		Booking_Repository::release_seat( $seat_id, $date, $time, $event_id );
		wp_send_json_success();
	}

	/** Base per-seat price fallback: the connected event/appointment's own base price. */
	private static function resolve_base_price( object $plan, int $event_id = 0 ) : float {
		$ids = json_decode( $plan->connected_ids ?? '[]', true ) ?: array();
		if ( empty( $ids ) ) return 0.0;

		if ( 'event' === $plan->connect_type && class_exists( '\CredoqEvents\Event_Repository' ) ) {
			$id = $event_id ?: (int) $ids[0];
			$e  = \CredoqEvents\Event_Repository::find( $id );
			return $e ? (float) ( $e->price ?? 0 ) : 0.0;
		}
		if ( 'appointment' === $plan->connect_type && class_exists( '\CredoqAppointments\Appointment_Repository' ) ) {
			$a = \CredoqAppointments\Appointment_Repository::find( (int) $ids[0] );
			return $a ? (float) ( $a->base_price ?? 0 ) : 0.0;
		}
		return 0.0;
	}
}
