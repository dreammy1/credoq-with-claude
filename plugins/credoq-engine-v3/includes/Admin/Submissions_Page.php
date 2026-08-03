<?php
/**
 * Submissions admin page — universal submission log.
 *
 * Three views, dispatched via $_GET['action']:
 *   (none) / 'list' — filterable, sortable table with bulk actions
 *   'view'          — read-only detail page (payload, IP, browser, country, WC order)
 *   'edit'          — change status / add internal notes
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

use CredoqEngine\Security\Gate;

defined( 'ABSPATH' ) || exit;

class Submissions_Page {

	const STATUSES = array(
		'pending'   => array( 'label' => 'Pending',   'badge' => 'pending'   ),
		'confirmed' => array( 'label' => 'Confirmed', 'badge' => 'confirmed' ),
		'completed' => array( 'label' => 'Completed', 'badge' => 'confirmed' ),
		'cancelled' => array( 'label' => 'Cancelled', 'badge' => 'cancelled' ),
		'rejected'  => array( 'label' => 'Rejected',  'badge' => 'rejected'  ),
	);

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		// Mutating actions redirect when done, so handle them before any output.
		self::handle_mutations();

		$action = sanitize_key( $_GET['action'] ?? 'list' );
		if ( 'view' === $action && ! empty( $_GET['id'] ) ) {
			self::render_view( absint( $_GET['id'] ) );
			return;
		}
		if ( 'edit' === $action && ! empty( $_GET['id'] ) ) {
			self::render_edit( absint( $_GET['id'] ) );
			return;
		}
		self::render_list();
	}

	/* ═══════════════════════════════════════════════════════════════
	   MUTATIONS — delete, bulk actions, status/notes save, CSV export
	═══════════════════════════════════════════════════════════════ */

	private static function handle_mutations() : void {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_submissions';

		// ── CSV export ──────────────────────────────────────────────
		if ( isset( $_GET['action'] ) && 'export_csv' === $_GET['action'] ) {
			check_admin_referer( 'credoq_export_submissions' );
			self::export_csv();
			exit;
		}

		// ── Single delete ───────────────────────────────────────────
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'credoq_delete_submission_' . $id );
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			wp_safe_redirect( add_query_arg( array( 'page' => 'credoq-submissions', 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// ── Bulk actions ─────────────────────────────────────────────
		if ( isset( $_POST['credoq_bulk_action'], $_POST['ids'] ) ) {
			check_admin_referer( 'credoq_submissions_bulk' );
			$bulk = sanitize_key( $_POST['credoq_bulk_action'] );
			$ids  = array_map( 'absint', (array) $_POST['ids'] );
			$ids  = array_filter( $ids );

			if ( $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

				if ( 'delete' === $bulk ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
				} elseif ( isset( self::STATUSES[ str_replace( 'status_', '', $bulk ) ] ) ) {
					$new_status = str_replace( 'status_', '', $bulk );
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$table} SET status = %s, updated_at = %s WHERE id IN ({$placeholders})",
						array_merge( array( $new_status, current_time( 'mysql', true ) ), $ids )
					) );
				}
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'credoq-submissions', 'bulk_done' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// ── Save edit (status + notes) ────────────────────────────────
		if ( isset( $_POST['credoq_save_submission'], $_POST['submission_id'] ) ) {
			$id = absint( $_POST['submission_id'] );
			check_admin_referer( 'credoq_save_submission_' . $id );

			$status = sanitize_key( $_POST['status'] ?? 'pending' );
			if ( ! isset( self::STATUSES[ $status ] ) ) $status = 'pending';
			$notes = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

			$wpdb->update(
				$table,
				array( 'status' => $status, 'notes' => $notes, 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			do_action( 'credoq_submission_status_changed', $id, $status );

			wp_safe_redirect( add_query_arg( array( 'page' => 'credoq-submissions', 'action' => 'view', 'id' => $id, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/* ═══════════════════════════════════════════════════════════════
	   LIST VIEW
	═══════════════════════════════════════════════════════════════ */

	private static function render_list() : void {
		global $wpdb;
		$table = $wpdb->prefix . 'credoq_submissions';

		$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per        = 20;
		$offset     = ( $paged - 1 ) * $per;

		$f_status   = sanitize_key( $_GET['status']  ?? '' );
		$f_form     = absint( $_GET['form_id']       ?? 0 );
		$f_search   = sanitize_text_field( $_GET['s'] ?? '' );
		$f_from     = sanitize_text_field( $_GET['date_from'] ?? '' );
		$f_to       = sanitize_text_field( $_GET['date_to']   ?? '' );

		$where  = array( '1=1' );
		$params = array();

		if ( $f_status && isset( self::STATUSES[ $f_status ] ) ) {
			$where[]  = 's.status = %s';
			$params[] = $f_status;
		}
		if ( $f_form ) {
			$where[]  = 's.form_id = %d';
			$params[] = $f_form;
		}
		if ( $f_search ) {
			$where[]  = '(s.user_email LIKE %s OR s.payload LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $f_search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		if ( $f_from ) {
			$where[]  = 's.created_at >= %s';
			$params[] = $f_from . ' 00:00:00';
		}
		if ( $f_to ) {
			$where[]  = 's.created_at <= %s';
			$params[] = $f_to . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} s WHERE {$where_sql}";
		$total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$list_sql = "SELECT s.*, f.title AS form_title
			FROM {$table} s
			LEFT JOIN {$wpdb->prefix}credoq_forms f ON s.form_id = f.id
			WHERE {$where_sql}
			ORDER BY s.id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per, $offset ) ) ) );

		$forms = $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}credoq_forms ORDER BY title ASC" );

		// Status counts for the filter chips.
		$status_counts = array();
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$table} GROUP BY status" ) as $row ) {
			$status_counts[ $row->status ] = (int) $row->c;
		}

		$settings = get_option( 'credoq_engine_settings', array() );
		$currency = $settings['currency'] ?? 'USD';

		?>
		<div class="wrap credoq-admin-wrap">

			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-email-alt" style="font-size:26px;margin-right:8px;color:#4f46e5;"></span>
						<?php esc_html_e( 'Submissions', 'credoq-engine' ); ?>
					</h1>
					<a href="<?php echo esc_url( wp_nonce_url(
						add_query_arg( array_merge( $_GET, array( 'page' => 'credoq-submissions', 'action' => 'export_csv' ) ), admin_url( 'admin.php' ) ),
						'credoq_export_submissions'
					) ); ?>" class="button">
						<span class="dashicons dashicons-download" style="margin-top:3px;"></span> <?php esc_html_e( 'Export CSV', 'credoq-engine' ); ?>
					</a>
				</div>
			</div>

			<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Submission deleted.', 'credoq-engine' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['bulk_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Bulk action completed.', 'credoq-engine' ); ?></p></div>
			<?php endif; ?>

			<!-- Filter bar -->
			<div class="credoq-card credoq-filter-card">
				<form method="get">
					<input type="hidden" name="page" value="credoq-submissions">
					<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">

						<input type="search" name="s" value="<?php echo esc_attr( $f_search ); ?>"
							placeholder="<?php esc_attr_e( 'Search email or field value…', 'credoq-engine' ); ?>"
							style="padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;min-width:220px;">

						<select name="form_id" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
							<option value=""><?php esc_html_e( 'All forms', 'credoq-engine' ); ?></option>
							<?php foreach ( $forms as $form ) : ?>
								<option value="<?php echo (int) $form->id; ?>" <?php selected( $f_form, $form->id ); ?>>
									<?php echo esc_html( $form->title ?: '#' . $form->id ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<input type="date" name="date_from" value="<?php echo esc_attr( $f_from ); ?>"
							style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
						<span style="color:#94a3b8;">—</span>
						<input type="date" name="date_to" value="<?php echo esc_attr( $f_to ); ?>"
							style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">

						<button type="submit" class="button button-primary" style="border-radius:8px;"><?php esc_html_e( 'Filter', 'credoq-engine' ); ?></button>
						<?php if ( $f_status || $f_form || $f_search || $f_from || $f_to ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'page', 'credoq-submissions', admin_url( 'admin.php' ) ) ); ?>" style="font-size:12px;color:#64748b;">
								<?php esc_html_e( 'Clear', 'credoq-engine' ); ?>
							</a>
						<?php endif; ?>
					</div>

					<!-- Status chips -->
					<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;">
						<?php
						$total_all = array_sum( $status_counts );
						$chip_url  = function( $status ) {
							$args = $_GET;
							$args['page'] = 'credoq-submissions';
							if ( $status ) { $args['status'] = $status; } else { unset( $args['status'] ); }
							return add_query_arg( $args, admin_url( 'admin.php' ) );
						};
						?>
						<a href="<?php echo esc_url( $chip_url( '' ) ); ?>" class="credoq-status-chip <?php echo ! $f_status ? 'is-active' : ''; ?>">
							<?php esc_html_e( 'All', 'credoq-engine' ); ?> <span><?php echo (int) $total_all; ?></span>
						</a>
						<?php foreach ( self::STATUSES as $key => $meta ) :
							$c = $status_counts[ $key ] ?? 0;
							if ( ! $c && $key !== $f_status ) continue;
						?>
						<a href="<?php echo esc_url( $chip_url( $key ) ); ?>" class="credoq-status-chip credoq-status-chip-<?php echo esc_attr( $meta['badge'] ); ?> <?php echo $f_status === $key ? 'is-active' : ''; ?>">
							<?php echo esc_html( $meta['label'] ); ?> <span><?php echo (int) $c; ?></span>
						</a>
						<?php endforeach; ?>
					</div>
				</form>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<div class="credoq-card">
					<p style="color:#94a3b8;margin:0;"><?php esc_html_e( 'No submissions found.', 'credoq-engine' ); ?></p>
				</div>
			<?php else : ?>

				<form method="post">
					<?php wp_nonce_field( 'credoq_submissions_bulk' ); ?>

					<div class="credoq-card" style="padding:0;overflow:hidden;">

						<div style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1.5px solid var(--cq-border);">
							<select name="credoq_bulk_action" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
								<option value=""><?php esc_html_e( 'Bulk actions', 'credoq-engine' ); ?></option>
								<option value="status_confirmed"><?php esc_html_e( 'Mark Confirmed', 'credoq-engine' ); ?></option>
								<option value="status_pending"><?php esc_html_e( 'Mark Pending', 'credoq-engine' ); ?></option>
								<option value="status_cancelled"><?php esc_html_e( 'Mark Cancelled', 'credoq-engine' ); ?></option>
								<option value="status_rejected"><?php esc_html_e( 'Mark Rejected', 'credoq-engine' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete', 'credoq-engine' ); ?></option>
							</select>
							<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Apply this action to the selected submissions?', 'credoq-engine' ) ); ?>');">
								<?php esc_html_e( 'Apply', 'credoq-engine' ); ?>
							</button>
							<span style="margin-left:auto;font-size:12px;color:#94a3b8;">
								<?php printf( esc_html__( '%d total', 'credoq-engine' ), (int) $total ); ?>
							</span>
						</div>

						<table class="credoq-table widefat" style="border:none;">
							<thead>
								<tr>
									<th style="width:30px;"><input type="checkbox" onclick="document.querySelectorAll('.cq-row-cb').forEach(c=>c.checked=this.checked)"></th>
									<th>#</th>
									<th><?php esc_html_e( 'Form', 'credoq-engine' ); ?></th>
									<th><?php esc_html_e( 'User', 'credoq-engine' ); ?></th>
									<th><?php esc_html_e( 'Status', 'credoq-engine' ); ?></th>
									<th><?php esc_html_e( 'Total', 'credoq-engine' ); ?></th>
									<th><?php esc_html_e( 'Source', 'credoq-engine' ); ?></th>
									<th><?php esc_html_e( 'When', 'credoq-engine' ); ?></th>
									<th style="width:140px;"><?php esc_html_e( 'Actions', 'credoq-engine' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $rows as $r ) :
								$badge = self::STATUSES[ $r->status ]['badge'] ?? 'gray';
								$label = self::STATUSES[ $r->status ]['label'] ?? ucfirst( $r->status );
								$view_url = add_query_arg( array( 'page' => 'credoq-submissions', 'action' => 'view', 'id' => $r->id ), admin_url( 'admin.php' ) );
								$edit_url = add_query_arg( array( 'page' => 'credoq-submissions', 'action' => 'edit', 'id' => $r->id ), admin_url( 'admin.php' ) );
								$del_url  = wp_nonce_url( add_query_arg( array( 'page' => 'credoq-submissions', 'action' => 'delete', 'id' => $r->id ), admin_url( 'admin.php' ) ), 'credoq_delete_submission_' . $r->id );
							?>
								<tr>
									<td><input type="checkbox" class="cq-row-cb" name="ids[]" value="<?php echo (int) $r->id; ?>"></td>
									<td><?php echo (int) $r->id; ?></td>
									<td><a href="<?php echo esc_url( $view_url ); ?>" style="font-weight:600;text-decoration:none;"><?php echo esc_html( $r->form_title ?: '#' . $r->form_id ); ?></a></td>
									<td style="color:#64748b;"><?php echo esc_html( $r->user_email ?: '(guest)' ); ?></td>
									<td><span class="credoq-status credoq-status-<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span></td>
									<td><?php echo esc_html( $currency . ' ' . number_format( (float) $r->total_price, 2 ) ); ?></td>
									<td style="color:#94a3b8;font-size:12px;text-transform:capitalize;"><?php echo esc_html( str_replace( '_', ' ', $r->source ) ); ?></td>
									<td style="color:#94a3b8;font-size:12px;"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r->created_at ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( $view_url ); ?>" title="<?php esc_attr_e( 'View', 'credoq-engine' ); ?>" class="credoq-row-action">👁</a>
										<a href="<?php echo esc_url( $edit_url ); ?>" title="<?php esc_attr_e( 'Edit', 'credoq-engine' ); ?>" class="credoq-row-action">✏️</a>
										<a href="<?php echo esc_url( $del_url ); ?>" title="<?php esc_attr_e( 'Delete', 'credoq-engine' ); ?>" class="credoq-row-action"
										   onclick="return confirm('<?php echo esc_js( __( 'Delete this submission permanently?', 'credoq-engine' ) ); ?>');">🗑</a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</form>

				<?php
				$pages = (int) ceil( $total / $per );
				if ( $pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo paginate_links( array(
						'current' => $paged,
						'total'   => $pages,
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
					) );
					echo '</div></div>';
				}
				?>
			<?php endif; ?>
		</div>

		<?php self::shared_styles(); ?>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════
	   DETAIL VIEW
	═══════════════════════════════════════════════════════════════ */

	private static function render_view( int $id ) : void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.*, f.title AS form_title, f.fields AS form_fields
			 FROM {$wpdb->prefix}credoq_submissions s
			 LEFT JOIN {$wpdb->prefix}credoq_forms f ON s.form_id = f.id
			 WHERE s.id = %d", $id
		) );

		if ( ! $row ) {
			echo '<div class="wrap credoq-admin-wrap"><div class="credoq-card"><p>' . esc_html__( 'Submission not found.', 'credoq-engine' ) . '</p></div></div>';
			return;
		}

		$payload     = json_decode( $row->payload ?: '{}', true ) ?: array();
		$form_fields = json_decode( $row->form_fields ?: '[]', true ) ?: array();
		$field_map   = array();
		foreach ( $form_fields as $f ) {
			if ( ! empty( $f['name'] ) ) $field_map[ $f['name'] ] = $f;
		}

		$badge    = self::STATUSES[ $row->status ]['badge'] ?? 'gray';
		$label    = self::STATUSES[ $row->status ]['label'] ?? ucfirst( $row->status );
		$settings = get_option( 'credoq_engine_settings', array() );
		$currency = $settings['currency'] ?? 'USD';

		$ua_info = self::parse_user_agent( $row->user_agent );
		$geo     = $row->ip_address ? Gate::lookup_geo_details( $row->ip_address ) : array( 'country' => '', 'country_code' => '', 'region' => '', 'city' => '' );

		?>
		<div class="wrap credoq-admin-wrap">

			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'credoq-submissions' ), admin_url( 'admin.php' ) ) ); ?>" style="color:#94a3b8;text-decoration:none;margin-right:6px;">&larr;</a>
						<?php printf( esc_html__( 'Submission #%d', 'credoq-engine' ), $id ); ?>
						<span class="credoq-status credoq-status-<?php echo esc_attr( $badge ); ?>" style="margin-left:10px;font-size:13px;"><?php echo esc_html( $label ); ?></span>
					</h1>
					<div style="display:flex;gap:8px;">
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'credoq-submissions', 'action' => 'edit', 'id' => $id ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Edit', 'credoq-engine' ); ?>
						</a>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'credoq-submissions', 'action' => 'delete', 'id' => $id ), admin_url( 'admin.php' ) ), 'credoq_delete_submission_' . $id ) ); ?>"
						   class="button" onclick="return confirm('<?php echo esc_js( __( 'Delete this submission permanently?', 'credoq-engine' ) ); ?>');">
							<?php esc_html_e( 'Delete', 'credoq-engine' ); ?>
						</a>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'credoq-engine' ); ?></p></div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">

				<div>
					<!-- Submitted data -->
					<div class="credoq-card">
						<h2 class="credoq-section-title"><?php esc_html_e( 'Submitted Data', 'credoq-engine' ); ?></h2>
						<?php if ( empty( $payload ) ) : ?>
							<p style="color:#94a3b8;"><?php esc_html_e( 'No field data.', 'credoq-engine' ); ?></p>
						<?php else : ?>
							<table class="credoq-table" style="width:100%;">
								<tbody>
								<?php foreach ( $payload as $key => $value ) :
									if ( '' === $value || null === $value || array() === $value ) continue;
									if ( in_array( $key, array( 'recaptcha_token', 'g-recaptcha-response', 'nonce' ), true ) ) continue;
									$field_def = $field_map[ $key ] ?? null;
									$flabel    = $field_def['label'] ?? ucwords( str_replace( '_', ' ', $key ) );
									$display   = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
									if ( strlen( $display ) > 0 && strpos( $display, 'data:image' ) === 0 ) {
										$display = '(signature image)';
									}
								?>
									<tr>
										<td style="color:#64748b;font-weight:600;width:220px;"><?php echo esc_html( $flabel ); ?></td>
										<td><?php echo esc_html( $display ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $row->notes ) ) : ?>
					<div class="credoq-card">
						<h2 class="credoq-section-title"><?php esc_html_e( 'Internal Notes', 'credoq-engine' ); ?></h2>
						<p style="white-space:pre-wrap;margin:0;color:#475569;"><?php echo esc_html( $row->notes ); ?></p>
					</div>
					<?php endif; ?>
				</div>

				<div>
					<!-- Summary -->
					<div class="credoq-card">
						<h2 class="credoq-section-title"><?php esc_html_e( 'Summary', 'credoq-engine' ); ?></h2>
						<table class="credoq-table" style="width:100%;">
							<tbody>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'Form', 'credoq-engine' ); ?></td><td style="text-align:right;font-weight:600;"><?php echo esc_html( $row->form_title ?: '#' . $row->form_id ); ?></td></tr>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'User', 'credoq-engine' ); ?></td><td style="text-align:right;"><?php echo esc_html( $row->user_email ?: __( '(guest)', 'credoq-engine' ) ); ?></td></tr>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'Total', 'credoq-engine' ); ?></td><td style="text-align:right;font-weight:700;color:#16a34a;"><?php echo esc_html( $currency . ' ' . number_format( (float) $row->total_price, 2 ) ); ?></td></tr>
								<?php if ( $row->total_credits ) : ?>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'Credits used', 'credoq-engine' ); ?></td><td style="text-align:right;"><?php echo (int) $row->total_credits; ?></td></tr>
								<?php endif; ?>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'Source', 'credoq-engine' ); ?></td><td style="text-align:right;text-transform:capitalize;"><?php echo esc_html( str_replace( '_', ' ', $row->source ) ); ?></td></tr>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'Submitted', 'credoq-engine' ); ?></td><td style="text-align:right;"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->created_at ) ); ?></td></tr>
								<?php if ( $row->wc_order_id ) : ?>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'WC Order', 'credoq-engine' ); ?></td><td style="text-align:right;">
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row->wc_order_id . '&action=edit' ) ); ?>">#<?php echo (int) $row->wc_order_id; ?> &rarr;</a>
								</td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<!-- Technical info -->
					<div class="credoq-card">
						<h2 class="credoq-section-title"><?php esc_html_e( 'Technical Info', 'credoq-engine' ); ?></h2>
						<table class="credoq-table" style="width:100%;">
							<tbody>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'IP Address', 'credoq-engine' ); ?></td><td style="text-align:right;font-family:monospace;"><?php echo esc_html( $row->ip_address ?: '—' ); ?></td></tr>
								<tr>
									<td style="color:#64748b;"><?php esc_html_e( 'Country', 'credoq-engine' ); ?></td>
									<td style="text-align:right;">
										<?php if ( $geo['country'] ) : ?>
											<?php echo esc_html( trim( self::flag_emoji( $geo['country_code'] ) . ' ' . $geo['country'] ) ); ?>
											<?php if ( $geo['region'] || $geo['city'] ) : ?>
												<div style="font-size:11px;color:#94a3b8;"><?php echo esc_html( trim( $geo['city'] . ( $geo['city'] && $geo['region'] ? ', ' : '' ) . $geo['region'] ) ); ?></div>
											<?php endif; ?>
										<?php else : ?>
											<span style="color:#94a3b8;">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'Browser', 'credoq-engine' ); ?></td><td style="text-align:right;"><?php echo esc_html( $ua_info['browser'] ); ?></td></tr>
								<tr><td style="color:#64748b;"><?php esc_html_e( 'OS / Device', 'credoq-engine' ); ?></td><td style="text-align:right;"><?php echo esc_html( $ua_info['os'] ); ?></td></tr>
								<tr><td colspan="2" style="color:#94a3b8;font-size:11px;word-break:break-all;padding-top:8px;"><?php echo esc_html( $row->user_agent ?: '—' ); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>

		<?php self::shared_styles(); ?>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════
	   EDIT VIEW
	═══════════════════════════════════════════════════════════════ */

	private static function render_edit( int $id ) : void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.*, f.title AS form_title FROM {$wpdb->prefix}credoq_submissions s
			 LEFT JOIN {$wpdb->prefix}credoq_forms f ON s.form_id = f.id
			 WHERE s.id = %d", $id
		) );
		if ( ! $row ) {
			echo '<div class="wrap credoq-admin-wrap"><div class="credoq-card"><p>' . esc_html__( 'Submission not found.', 'credoq-engine' ) . '</p></div></div>';
			return;
		}
		?>
		<div class="wrap credoq-admin-wrap">
			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'credoq-submissions', 'action' => 'view', 'id' => $id ), admin_url( 'admin.php' ) ) ); ?>" style="color:#94a3b8;text-decoration:none;margin-right:6px;">&larr;</a>
						<?php printf( esc_html__( 'Edit Submission #%d', 'credoq-engine' ), $id ); ?>
					</h1>
				</div>
			</div>

			<form method="post" class="credoq-card" style="max-width:560px;">
				<?php wp_nonce_field( 'credoq_save_submission_' . $id ); ?>
				<input type="hidden" name="submission_id" value="<?php echo (int) $id; ?>">

				<h2 class="credoq-section-title"><?php echo esc_html( $row->form_title ?: '#' . $row->form_id ); ?></h2>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Status', 'credoq-engine' ); ?></th>
						<td>
							<select name="status">
								<?php foreach ( self::STATUSES as $key => $meta ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row->status, $key ); ?>><?php echo esc_html( $meta['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Internal Notes', 'credoq-engine' ); ?></th>
						<td><textarea name="notes" rows="5" class="large-text"><?php echo esc_textarea( $row->notes ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Only visible to admins.', 'credoq-engine' ); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" name="credoq_save_submission" value="1" class="button button-primary"><?php esc_html_e( 'Save Changes', 'credoq-engine' ); ?></button>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'credoq-submissions', 'action' => 'view', 'id' => $id ), admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'credoq-engine' ); ?></a>
				</p>
			</form>
		</div>
		<?php self::shared_styles(); ?>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════
	   HELPERS
	═══════════════════════════════════════════════════════════════ */

	/**
	 * Tiny dependency-free User-Agent parser — good enough to label the
	 * common browsers/OSes in the admin UI without pulling in a library.
	 */
	private static function parse_user_agent( ?string $ua ) : array {
		$ua = (string) $ua;
		if ( '' === $ua ) return array( 'browser' => '—', 'os' => '—' );

		$browser = 'Unknown';
		if ( preg_match( '/Edg\/([\d.]+)/', $ua, $m ) )           $browser = 'Edge ' . $m[1];
		elseif ( preg_match( '/OPR\/([\d.]+)/', $ua, $m ) )       $browser = 'Opera ' . $m[1];
		elseif ( preg_match( '/Chrome\/([\d.]+)/', $ua, $m ) && strpos( $ua, 'Chromium' ) === false ) $browser = 'Chrome ' . $m[1];
		elseif ( preg_match( '/Firefox\/([\d.]+)/', $ua, $m ) )   $browser = 'Firefox ' . $m[1];
		elseif ( preg_match( '/Version\/([\d.]+).*Safari/', $ua, $m ) ) $browser = 'Safari ' . $m[1];
		elseif ( preg_match( '/MSIE ([\d.]+)/', $ua, $m ) )       $browser = 'Internet Explorer ' . $m[1];

		$os = 'Unknown';
		if ( preg_match( '/Windows NT 10\.0/', $ua ) )            $os = 'Windows 10/11';
		elseif ( preg_match( '/Windows NT ([\d.]+)/', $ua, $m ) ) $os = 'Windows (' . $m[1] . ')';
		elseif ( preg_match( '/Mac OS X ([\d_]+)/', $ua, $m ) )   $os = 'macOS ' . str_replace( '_', '.', $m[1] );
		elseif ( preg_match( '/Android ([\d.]+)/', $ua, $m ) )    $os = 'Android ' . $m[1];
		elseif ( preg_match( '/iPhone OS ([\d_]+)/', $ua, $m ) )  $os = 'iOS ' . str_replace( '_', '.', $m[1] ) . ' (iPhone)';
		elseif ( preg_match( '/iPad.*OS ([\d_]+)/', $ua, $m ) )   $os = 'iPadOS ' . str_replace( '_', '.', $m[1] );
		elseif ( preg_match( '/Linux/', $ua ) )                   $os = 'Linux';

		return array( 'browser' => $browser, 'os' => $os );
	}

	/** Convert a 2-letter ISO country code into its flag emoji. */
	private static function flag_emoji( string $code ) : string {
		$code = strtoupper( trim( $code ) );
		if ( strlen( $code ) !== 2 || ! function_exists( 'mb_chr' ) ) return '';
		$chars = array_map( function( $c ) { return mb_chr( ord( $c ) - 65 + 0x1F1E6 ); }, str_split( $code ) );
		return implode( '', $chars );
	}

	private static function export_csv() : void {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT s.*, f.title AS form_title
			 FROM {$wpdb->prefix}credoq_submissions s
			 LEFT JOIN {$wpdb->prefix}credoq_forms f ON s.form_id = f.id
			 ORDER BY s.id DESC"
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="credoq-submissions-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'ID', 'Form', 'Email', 'Status', 'Total', 'Source', 'IP', 'Created At' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array(
				$r->id,
				$r->form_title ?: ( '#' . $r->form_id ),
				$r->user_email,
				$r->status,
				$r->total_price,
				$r->source,
				$r->ip_address,
				$r->created_at,
			) );
		}
		fclose( $out );
	}

	private static function shared_styles() : void {
		?>
		<style>
		.credoq-status-chip {
			display: inline-flex; align-items: center; gap: 4px;
			padding: 4px 12px; border-radius: 99px; font-size: 12px; font-weight: 700;
			background: #f1f5f9; color: #64748b; text-decoration: none; border: 1.5px solid transparent;
		}
		.credoq-status-chip span { opacity: .7; }
		.credoq-status-chip.is-active { border-color: currentColor; }
		.credoq-status-chip-pending.is-active   { background: #fffbeb; color: #d97706; }
		.credoq-status-chip-confirmed.is-active { background: #f0fdf4; color: #16a34a; }
		.credoq-status-chip-cancelled.is-active { background: #fff1f2; color: #dc2626; }
		.credoq-status-chip-rejected.is-active  { background: #f5f5f5; color: #64748b; }
		.credoq-status-chip:not(.is-active):hover { background: #e2e8f0; }
		.credoq-row-action {
			display: inline-block; text-decoration: none; padding: 2px 4px; border-radius: 4px; font-size: 14px;
		}
		.credoq-row-action:hover { background: #f1f5f9; }
		</style>
		<?php
	}
}
