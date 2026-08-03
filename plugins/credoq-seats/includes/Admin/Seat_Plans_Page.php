<?php
namespace CredoqSeats\Admin;

use CredoqSeats\Repositories\Plan_Repository;

defined( 'ABSPATH' ) || exit;

class Seat_Plans_Page {

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		if ( isset( $_GET['action'], $_GET['id'] ) && check_admin_referer( 'credoq_seat_plan_action' ) ) {
			$id     = absint( $_GET['id'] );
			$action = sanitize_key( $_GET['action'] );
			if ( 'delete' === $action ) {
				Plan_Repository::delete( $id );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plan deleted.', 'credoq-seats' ) . '</p></div>';
			} elseif ( 'duplicate' === $action ) {
				Plan_Repository::duplicate( $id );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plan duplicated.', 'credoq-seats' ) . '</p></div>';
			}
		}

		$status_filter = sanitize_key( $_GET['status'] ?? '' );
		$plans = Plan_Repository::all( $status_filter ? array( 'status' => $status_filter ) : array() );
		?>
		<div class="wrap credoq-admin-wrap">
			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-grid-view" style="font-size:26px;margin-right:8px;color:#4f46e5;"></span>
						<?php esc_html_e( 'Seat Plans', 'credoq-seats' ); ?>
					</h1>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-seat-builder&id=0' ) ); ?>">
						<?php esc_html_e( '+ New Seat Plan', 'credoq-seats' ); ?>
					</a>
				</div>
			</div>

			<div style="margin:14px 0;">
				<?php foreach ( array( '' => 'All', 'draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived' ) as $key => $label ) : ?>
					<a class="button <?php echo $status_filter === $key ? 'button-primary' : ''; ?>"
					   href="<?php echo esc_url( add_query_arg( 'status', $key, admin_url( 'admin.php?page=credoq-seat-plans' ) ) ); ?>"
					   style="margin-right:6px;"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>

			<?php if ( empty( $plans ) ) : ?>
				<div class="credoq-card">
					<p style="color:#94a3b8;"><?php esc_html_e( 'No seat plans yet.', 'credoq-seats' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-seat-builder&id=0' ) ); ?>"><?php esc_html_e( 'Create your first plan', 'credoq-seats' ); ?></a>
				</div>
			<?php else : ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
				<?php foreach ( $plans as $plan ) : ?>
				<div class="credoq-card" style="padding:18px;">
					<div style="display:flex;justify-content:space-between;align-items:flex-start;">
						<div style="font-weight:800;font-size:16px;color:#1e293b;"><?php echo esc_html( $plan->name ); ?></div>
						<?php echo self::status_badge( $plan->status ); ?>
					</div>
					<div style="font-size:13px;color:#64748b;margin:6px 0 12px;"><?php echo esc_html( self::connection_summary( $plan ) ); ?></div>

					<div style="display:flex;gap:14px;font-size:13px;color:#475569;margin-bottom:14px;">
						<div><strong><?php echo (int) $plan->total_floors; ?></strong> <?php esc_html_e( 'floors', 'credoq-seats' ); ?></div>
						<div><strong><?php echo (int) $plan->total_seats; ?></strong> <?php esc_html_e( 'seats', 'credoq-seats' ); ?></div>
						<div><strong><?php echo (int) $plan->capacity_limit; ?></strong> <?php esc_html_e( 'capacity', 'credoq-seats' ); ?></div>
					</div>

					<div style="display:flex;gap:6px;flex-wrap:wrap;">
						<a class="button button-small button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-seat-builder&id=' . (int) $plan->id ) ); ?>"><?php esc_html_e( 'Edit Builder', 'credoq-seats' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=credoq-seat-plans&action=duplicate&id=' . (int) $plan->id ), 'credoq_seat_plan_action' ) ); ?>"><?php esc_html_e( 'Duplicate', 'credoq-seats' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-seat-bookings&plan_id=' . (int) $plan->id ) ); ?>"><?php esc_html_e( 'Bookings', 'credoq-seats' ); ?></a>
						<a class="button button-small" style="color:#dc2626;" onclick="return confirm('<?php echo esc_js( __( 'Delete this plan? Seats and bookings under it will also be removed.', 'credoq-seats' ) ); ?>');"
						   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=credoq-seat-plans&action=delete&id=' . (int) $plan->id ), 'credoq_seat_plan_action' ) ); ?>"><?php esc_html_e( 'Delete', 'credoq-seats' ); ?></a>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function status_badge( string $status ) : string {
		$map = array(
			'draft'     => 'credoq-badge-gray',
			'published' => 'credoq-badge-green',
			'archived'  => 'credoq-badge-yellow',
		);
		$class = $map[ $status ] ?? 'credoq-badge-gray';
		return '<span class="credoq-badge ' . esc_attr( $class ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
	}

	private static function connection_summary( object $plan ) : string {
		if ( 'none' === $plan->connect_type || empty( $plan->connect_type ) ) {
			return __( 'Not connected to any service yet', 'credoq-seats' );
		}
		$ids = json_decode( $plan->connected_ids ?? '[]', true ) ?: array();
		if ( empty( $ids ) ) return __( 'Not connected to any service yet', 'credoq-seats' );

		$names = array();
		foreach ( $ids as $id ) {
			if ( 'event' === $plan->connect_type && class_exists( '\CredoqEvents\Event_Repository' ) ) {
				$e = \CredoqEvents\Event_Repository::find( (int) $id );
				if ( $e ) $names[] = $e->title;
			} elseif ( 'appointment' === $plan->connect_type && class_exists( '\CredoqAppointments\Appointment_Repository' ) ) {
				$a = \CredoqAppointments\Appointment_Repository::find( (int) $id );
				if ( $a ) $names[] = $a->title;
			}
		}
		if ( empty( $names ) ) return __( 'Connected service no longer exists', 'credoq-seats' );
		return ( 'event' === $plan->connect_type ? __( 'Events: ', 'credoq-seats' ) : __( 'Appointments: ', 'credoq-seats' ) ) . implode( ', ', $names );
	}
}
