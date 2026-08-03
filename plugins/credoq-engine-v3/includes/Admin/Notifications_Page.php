<?php
/**
 * Notifications admin page.
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

use CredoqEngine\Mail\Notifications;

defined( 'ABSPATH' ) || exit;

class Notifications_Page {

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		// ── Actions ──────────────────────────────────────────────────────
		if ( isset( $_POST['credoq_notif_action'] ) && check_admin_referer( 'credoq_notifications' ) ) {
			$action = sanitize_key( $_POST['credoq_notif_action'] );
			$ids    = array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) );
			$single = absint( $_POST['id'] ?? 0 );

			switch ( $action ) {
				case 'mark_read':
					if ( $single ) Notifications::mark_read( $single );
					foreach ( $ids as $id ) Notifications::mark_read( $id );
					break;
				case 'mark_unread':
					if ( $single ) Notifications::mark_unread( $single );
					break;
				case 'delete':
					if ( $single ) Notifications::delete( $single );
					if ( $ids ) Notifications::delete_many( $ids );
					break;
				case 'mark_all_read':
					Notifications::mark_all_read();
					break;
				case 'clear_all':
					Notifications::clear_all();
					break;
			}
			wp_safe_redirect( admin_url( 'admin.php?page=credoq-notifications' ) );
			exit;
		}

		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$data  = Notifications::get_list( array( 'paged' => $paged, 'per_page' => 20 ) );
		?>
		<div class="wrap credoq-admin-wrap">

			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-bell" style="font-size:26px;margin-right:8px;color:#4f46e5;"></span>
						<?php esc_html_e( 'Notifications', 'credoq-engine' ); ?>
						<span style="font-size:13px;font-weight:600;color:#94a3b8;margin-left:8px;"><?php echo esc_html( $data['total'] ); ?> total</span>
					</h1>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Clear all notifications? This cannot be undone.', 'credoq-engine' ) ); ?>');">
						<?php wp_nonce_field( 'credoq_notifications' ); ?>
						<button class="button" name="credoq_notif_action" value="clear_all" style="color:#dc2626;border-color:#fca5a5;"><?php esc_html_e( 'Clear all', 'credoq-engine' ); ?></button>
					</form>
				</div>
			</div>

			<?php if ( empty( $data['rows'] ) ) : ?>
				<div class="credoq-card"><p style="color:#94a3b8;"><?php esc_html_e( 'No notifications yet.', 'credoq-engine' ); ?></p></div>
			<?php else : ?>

			<form method="post" id="cq-notif-form">
				<?php wp_nonce_field( 'credoq_notifications' ); ?>
				<div class="credoq-card" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;margin-bottom:12px;">
					<label><input type="checkbox" id="cq-select-all"> <?php esc_html_e( 'Select all', 'credoq-engine' ); ?></label>
					<div>
						<button class="button" name="credoq_notif_action" value="mark_read"><?php esc_html_e( 'Mark read', 'credoq-engine' ); ?></button>
						<button class="button" name="credoq_notif_action" value="delete" style="color:#dc2626;border-color:#fca5a5;" onclick="return confirm('<?php echo esc_js( __( 'Delete selected notifications?', 'credoq-engine' ) ); ?>');"><?php esc_html_e( 'Delete selected', 'credoq-engine' ); ?></button>
					</div>
				</div>

				<?php foreach ( $data['rows'] as $n ) : ?>
				<div class="credoq-card cq-notif-row" style="margin-bottom:8px;padding:14px 16px;<?php echo $n->is_read ? 'opacity:.6;' : ''; ?>">
					<div style="display:flex;align-items:flex-start;gap:12px;">
						<input type="checkbox" name="ids[]" value="<?php echo (int) $n->id; ?>" class="cq-notif-check" style="margin-top:4px;">
						<div style="width:34px;height:34px;border-radius:50%;background:#eef2ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-weight:800;flex-shrink:0;">N</div>
						<div style="flex:1;min-width:0;">
							<div style="font-weight:700;color:#1e293b;"><?php echo esc_html( $n->title ); ?></div>
							<div style="font-size:13px;color:#64748b;margin-top:2px;">
								<?php echo esc_html( $n->message ); ?> ·
								<?php echo esc_html( human_time_diff( strtotime( $n->created_at . ' UTC' ), time() ) . ' ' . __( 'ago', 'credoq-engine' ) ); ?>
							</div>
							<?php if ( $n->link ) : ?>
							<div style="font-size:12px;color:#94a3b8;margin-top:6px;word-break:break-all;">
								<strong><?php esc_html_e( 'Link:', 'credoq-engine' ); ?></strong> <?php echo esc_html( $n->link ); ?>
							</div>
							<?php endif; ?>
						</div>
						<div style="display:flex;gap:6px;flex-shrink:0;">
							<?php if ( $n->link ) : ?>
							<a class="button button-primary button-small" href="<?php echo esc_url( $n->link ); ?>"><?php esc_html_e( 'Open', 'credoq-engine' ); ?></a>
							<?php endif; ?>
							<button class="button button-small" type="submit" name="credoq_notif_action" value="<?php echo $n->is_read ? 'mark_unread' : 'mark_read'; ?>" form="cq-notif-form" onclick="document.getElementById('cq-single-id').value='<?php echo (int) $n->id; ?>';">
								<?php echo $n->is_read ? esc_html__( 'Mark unread', 'credoq-engine' ) : esc_html__( 'Mark read', 'credoq-engine' ); ?>
							</button>
							<button class="button button-small" type="submit" name="credoq_notif_action" value="delete" form="cq-notif-form" style="color:#dc2626;" onclick="document.getElementById('cq-single-id').value='<?php echo (int) $n->id; ?>';return confirm('<?php echo esc_js( __( 'Delete this notification?', 'credoq-engine' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'credoq-engine' ); ?>
							</button>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
				<input type="hidden" name="id" id="cq-single-id" value="0">
			</form>

			<?php if ( $data['pages'] > 1 ) : ?>
			<div style="margin-top:16px;">
				<?php
				echo paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $data['pages'],
				) );
				?>
			</div>
			<?php endif; ?>

			<?php endif; ?>
		</div>

		<script>
		(function () {
			var selectAll = document.getElementById('cq-select-all');
			if (!selectAll) return;
			selectAll.addEventListener('change', function () {
				document.querySelectorAll('.cq-notif-check').forEach(function (cb) { cb.checked = selectAll.checked; });
			});
		})();
		</script>
		<?php
	}
}
