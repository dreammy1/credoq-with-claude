<?php
/**
 * Audit log admin page.
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

use CredoqEngine\Log\Audit_Log;

defined( 'ABSPATH' ) || exit;

class Audit_Page {

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		if ( isset( $_POST['credoq_audit_action'] ) && check_admin_referer( 'credoq_audit_log' ) ) {
			if ( 'clear' === sanitize_key( $_POST['credoq_audit_action'] ) ) {
				Audit_Log::clear();
				wp_safe_redirect( admin_url( 'admin.php?page=credoq-audit-log' ) );
				exit;
			}
		}

		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$event  = sanitize_key( $_GET['event'] ?? '' );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

		$data   = Audit_Log::get_entries( array(
			'paged'    => $paged,
			'per_page' => 50,
			'event'    => $event,
			'search'   => $search,
			'days'     => 7,
		) );
		$events   = Audit_Log::distinct_events();
		$volume   = Audit_Log::volume_by_day( 7 );
		?>
		<div class="wrap credoq-admin-wrap">

			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-list-view" style="font-size:26px;margin-right:8px;color:#4f46e5;"></span>
						<?php esc_html_e( 'Audit log', 'credoq-engine' ); ?>
					</h1>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Clear the entire audit log? This cannot be undone.', 'credoq-engine' ) ); ?>');">
						<?php wp_nonce_field( 'credoq_audit_log' ); ?>
						<button class="button" name="credoq_audit_action" value="clear" style="color:#dc2626;border-color:#fca5a5;"><?php esc_html_e( 'Clear log', 'credoq-engine' ); ?></button>
					</form>
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'Everything Credoq has recorded across the last 7 days.', 'credoq-engine' ); ?></p>

			<!-- Filters -->
			<form method="get" class="credoq-card" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:16px 0;">
				<input type="hidden" name="page" value="credoq-audit-log">
				<div>
					<label class="credoq-field-label"><?php esc_html_e( 'Event', 'credoq-engine' ); ?></label>
					<select name="event">
						<option value=""><?php esc_html_e( 'All events', 'credoq-engine' ); ?></option>
						<?php foreach ( $events as $ev ) : ?>
							<option value="<?php echo esc_attr( $ev ); ?>" <?php selected( $event, $ev ); ?>><?php echo esc_html( $ev ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="credoq-field-label"><?php esc_html_e( 'Search', 'credoq-engine' ); ?></label>
					<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'email, user, message…', 'credoq-engine' ); ?>">
				</div>
				<div><button class="button button-primary"><?php esc_html_e( 'Filter', 'credoq-engine' ); ?></button></div>
				<?php if ( $event || $search ) : ?>
				<div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-audit-log' ) ); ?>"><?php esc_html_e( 'Reset', 'credoq-engine' ); ?></a></div>
				<?php endif; ?>
			</form>

			<div style="display:grid;grid-template-columns:2.5fr 1fr;gap:20px;align-items:start;">

				<!-- Entries -->
				<div>
					<div class="credoq-card" style="padding:10px 16px;margin-bottom:12px;font-size:13px;color:#64748b;">
						<?php printf( esc_html__( '%d entries', 'credoq-engine' ), (int) $data['total'] ); ?>
					</div>

					<?php if ( empty( $data['rows'] ) ) : ?>
						<div class="credoq-card"><p style="color:#94a3b8;"><?php esc_html_e( 'No matching entries.', 'credoq-engine' ); ?></p></div>
					<?php else : ?>
						<?php foreach ( $data['rows'] as $row ) : ?>
						<div class="credoq-card" style="padding:12px 16px;margin-bottom:6px;">
							<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
								<code style="background:#eef2ff;color:#4338ca;padding:2px 8px;border-radius:5px;font-size:12px;font-weight:700;"><?php echo esc_html( $row->event ); ?></code>
								<span style="color:#475569;">· <?php echo esc_html( $row->subject ); ?></span>
							</div>
							<div style="font-size:12px;color:#94a3b8;margin-top:4px;">
								<?php echo esc_html( mysql2date( 'j M Y, H:i:s', $row->created_at ) ); ?> · <?php echo esc_html( $row->user_name ?: 'system' ); ?>
								<?php if ( $row->ip_address ) : ?> · <?php echo esc_html( $row->ip_address ); ?><?php endif; ?>
							</div>
							<?php if ( $row->message ) : ?>
							<div style="font-size:12px;color:#64748b;margin-top:6px;font-family:monospace;word-break:break-all;"><?php echo esc_html( $row->message ); ?></div>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>

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

				<!-- Sidebar -->
				<div>
					<div class="credoq-card" style="margin-bottom:16px;">
						<h2 class="credoq-section-title" style="margin-top:0;"><?php esc_html_e( 'Volume by day', 'credoq-engine' ); ?></h2>
						<?php if ( empty( $volume ) ) : ?>
							<p style="color:#94a3b8;font-size:13px;"><?php esc_html_e( 'No activity yet.', 'credoq-engine' ); ?></p>
						<?php else : ?>
							<table class="widefat" style="border:none;">
								<tbody>
								<?php foreach ( $volume as $v ) : ?>
									<tr>
										<td style="border:none;padding:6px 0;color:#475569;"><?php echo esc_html( mysql2date( 'D j M', $v['date'] ) ); ?></td>
										<td style="border:none;padding:6px 0;text-align:right;font-weight:700;"><?php echo esc_html( $v['count'] ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
					<div class="credoq-card">
						<h2 class="credoq-section-title" style="margin-top:0;"><?php esc_html_e( 'About the audit log', 'credoq-engine' ); ?></h2>
						<p style="font-size:13px;color:#64748b;">
							<?php esc_html_e( 'Records are append-only. Anything calling Audit_Log::record() shows up here — core, addons, and customer flows.', 'credoq-engine' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}
