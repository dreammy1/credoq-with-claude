<?php
namespace CredoqSeats\Admin;

use CredoqSeats\Repositories\Plan_Repository;
use CredoqSeats\Repositories\Seat_Repository;
use CredoqSeats\Templates\Template_Library;

defined( 'ABSPATH' ) || exit;

class Plan_Builder_Page {

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		$id      = absint( $_GET['id'] ?? 0 );
		$tab_raw = sanitize_key( $_GET['tab'] ?? 'info' );
		$tab     = in_array( $tab_raw, array( 'info', 'templates', 'canvas', 'pricing', 'publish' ), true ) ? $tab_raw : 'info';
		$plan = $id ? Plan_Repository::find( $id ) : null;

		if ( $id && ! $plan ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Plan not found.', 'credoq-seats' ) . '</p></div>';
			return;
		}
		if ( ! $plan ) {
			$plan = (object) array(
				'id' => 0, 'name' => '', 'description' => '', 'template_key' => 'custom',
				'connect_type' => 'none', 'connected_ids' => '[]', 'layout_json' => wp_json_encode( array( 'floors' => array() ) ),
				'total_floors' => 0, 'total_seats' => 0, 'capacity_limit' => 0, 'status' => 'draft',
			);
		}

		$notice = self::handle_post( $plan, $tab );
		if ( $notice && ! empty( $notice['redirect'] ) ) {
			wp_safe_redirect( $notice['redirect'] );
			exit;
		}
		if ( $id && ! $plan ) return;
		// Reload after any save so the form reflects the persisted state.
		if ( $id || ( $notice['new_id'] ?? 0 ) ) {
			$reload_id = $id ?: (int) $notice['new_id'];
			$plan      = Plan_Repository::find( $reload_id ) ?: $plan;
			$id        = $reload_id;
		}

		$layout = json_decode( $plan->layout_json ?? '{}', true ) ?: array( 'floors' => array() );
		?>
		<div class="wrap credoq-admin-wrap">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-seat-plans' ) ); ?>" class="button" style="margin-bottom:14px;">&larr; <?php esc_html_e( 'Back to Seat Plans', 'credoq-seats' ); ?></a>

			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-grid-view" style="font-size:26px;margin-right:8px;color:#4f46e5;"></span>
						<?php echo $id ? esc_html( $plan->name ?: __( '(untitled plan)', 'credoq-seats' ) ) : esc_html__( 'New Seat Plan', 'credoq-seats' ); ?>
					</h1>
				</div>
			</div>

			<?php if ( ! empty( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ?? 'success' ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( self::tabs() as $key => $label ) :
					$disabled = ! $id && 'info' !== $key;
					$url      = admin_url( 'admin.php?page=credoq-seat-builder&id=' . $id . '&tab=' . $key );
				?>
					<?php if ( $disabled ) : ?>
						<span class="nav-tab" style="opacity:.4;cursor:not-allowed;" title="<?php esc_attr_e( 'Save the plan info first', 'credoq-seats' ); ?>"><?php echo esc_html( $label ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $tab ) {
				case 'templates': self::render_templates_tab( $plan ); break;
				case 'canvas':    self::render_canvas_tab( $plan, $layout ); break;
				case 'pricing':   self::render_pricing_tab( $plan, $layout ); break;
				case 'publish':   self::render_publish_tab( $plan, $layout ); break;
				default:          self::render_info_tab( $plan );
			}
			?>
		</div>
		<?php
	}

	private static function tabs() : array {
		return array(
			'info'      => __( '1. Plan Info', 'credoq-seats' ),
			'templates' => __( '2. Templates', 'credoq-seats' ),
			'canvas'    => __( '3. Canvas Builder', 'credoq-seats' ),
			'pricing'   => __( '4. Pricing', 'credoq-seats' ),
			'publish'   => __( '5. Publish', 'credoq-seats' ),
		);
	}

	/** @return array{message?:string,type?:string,redirect?:string,new_id?:int} */
	private static function handle_post( object $plan, string $tab ) : array {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['credoq_seat_builder_action'] ) ) return array();
		if ( ! check_admin_referer( 'credoq_seat_builder' ) ) return array();

		$action = sanitize_key( $_POST['credoq_seat_builder_action'] );

		if ( 'save_info' === $action ) {
			$connect_type_raw = $_POST['connect_type'] ?? 'none';
			$connect_type     = in_array( $connect_type_raw, array( 'none', 'event', 'appointment' ), true ) ? $connect_type_raw : 'none';
			$connected_ids = array();
			if ( 'event' === $connect_type ) $connected_ids = array_map( 'absint', (array) ( $_POST['connected_event_ids'] ?? array() ) );
			if ( 'appointment' === $connect_type ) $connected_ids = array_map( 'absint', (array) ( $_POST['connected_apt_ids'] ?? array() ) );

			$data = array(
				'id'             => (int) $plan->id,
				'name'           => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'description'    => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
				'connect_type'   => $connect_type,
				'connected_ids'  => $connected_ids,
				'capacity_limit' => absint( $_POST['capacity_limit'] ?? 0 ),
			);
			if ( ! $plan->id ) {
				$data['layout_json']  = wp_json_encode( array( 'floors' => array() ) );
				$data['template_key'] = 'custom';
				$data['status']       = 'draft';
			}
			$new_id = Plan_Repository::save( $data );

			if ( ! $plan->id ) {
				return array( 'redirect' => admin_url( 'admin.php?page=credoq-seat-builder&id=' . $new_id . '&tab=templates' ), 'new_id' => $new_id );
			}

			// AUDIT-FEATURE (P2 housekeeping — surface ambiguity at save
			// time): connecting one plan to several events/services is a
			// valid setup (e.g. swapping layouts between runs of the same
			// recurring show), but a seat_map form field can only ever
			// resolve to ONE of them automatically (see
			// Seat_Map_Field::resolve_plan_id_for_event() in this same
			// plugin, and the read-only warning already shown on the
			// Events edit screen for this same reason) — so an admin
			// connecting a plan to more than one target should know that
			// up front, not discover it later when a registration form's
			// seat map doesn't render.
			if ( count( $connected_ids ) > 1 ) {
				$label = 'event' === $connect_type ? __( 'events', 'credoq-seats' ) : __( 'services', 'credoq-seats' );
				$one   = 'event' === $connect_type ? __( 'event', 'credoq-seats' ) : __( 'service', 'credoq-seats' );
				return array(
					'message' => sprintf(
						/* translators: 1: count, 2: "events" or "services", 3: "event" or "service" */
						__( 'Plan info saved — but it\'s now connected to %1$d %2$s. A seat_map form field can only automatically resolve to a single connected one; forms combining a seat map with more than one may not render a seat map at all. Connect this plan to just one %3$s if it\'s meant for a specific form, or set the field\'s plan explicitly.', 'credoq-seats' ),
						count( $connected_ids ),
						$label,
						$one
					),
					'type'   => 'warning',
					'new_id' => $new_id,
				);
			}

			return array( 'message' => __( 'Plan info saved.', 'credoq-seats' ), 'new_id' => $new_id );
		}

		if ( 'apply_template' === $action && $plan->id ) {
			$key    = sanitize_key( $_POST['template_key'] ?? 'custom' );
			$layout = Template_Library::get( $key );
			$layout = Seat_Repository::sync_from_layout( $plan->id, $layout );
			Plan_Repository::save( array( 'id' => $plan->id, 'template_key' => $key, 'layout_json' => $layout ) );
			return array( 'redirect' => admin_url( 'admin.php?page=credoq-seat-builder&id=' . $plan->id . '&tab=canvas' ) );
		}

		if ( 'save_canvas' === $action && $plan->id ) {
			$layout = json_decode( wp_unslash( $_POST['layout_json'] ?? '{}' ), true );
			if ( ! is_array( $layout ) ) return array( 'message' => __( 'Could not read the canvas data — nothing was saved.', 'credoq-seats' ), 'type' => 'error' );
			$layout = Seat_Repository::sync_from_layout( $plan->id, $layout );
			Plan_Repository::save( array( 'id' => $plan->id, 'layout_json' => $layout ) );
			return array( 'message' => __( 'Canvas saved.', 'credoq-seats' ) );
		}

		if ( 'save_pricing' === $action && $plan->id ) {
			$layout = json_decode( $plan->layout_json ?? '{}', true ) ?: array( 'floors' => array() );
			$pricing = array();
			foreach ( array( 'standard', 'vip', 'accessible', 'restricted', 'aisle' ) as $type ) {
				$pricing[ $type ] = isset( $_POST[ 'price_' . $type ] ) && '' !== $_POST[ 'price_' . $type ] ? (float) $_POST[ 'price_' . $type ] : null;
			}
			$layout['pricing'] = $pricing;

			$touched = 0;
			if ( ! empty( $_POST['apply_to_all_seats'] ) ) {
				foreach ( $layout['floors'] as &$floor ) {
					foreach ( $floor['seats'] as &$seat ) {
						$seat_type = $seat['type'] ?? 'standard';
						// Only overwrite when this type actually has a price
						// entered above — a blank field means "no change",
						// not "clear every seat of this type's override".
						if ( array_key_exists( $seat_type, $pricing ) && null !== $pricing[ $seat_type ] ) {
							$seat['price'] = $pricing[ $seat_type ];
							$touched++;
						}
					}
					unset( $seat );
				}
				unset( $floor );

				// Persisted independent of the checkbox's own (intentionally
				// momentary) state, so "did this actually happen" is
				// answerable just by reloading the page — not something you
				// have to take on faith from a one-time success message.
				$layout['pricing_last_applied'] = array(
					'at'     => current_time( 'mysql' ),
					'seats'  => $touched,
					'prices' => $pricing,
				);
			}

			Plan_Repository::save( array( 'id' => $plan->id, 'layout_json' => Seat_Repository::sync_from_layout( $plan->id, $layout ) ) );

			if ( class_exists( '\CredoqEngine\Log\Audit_Log' ) ) {
				\CredoqEngine\Log\Audit_Log::record( 'seats.pricing_saved', array(
					'subject' => 'plan #' . $plan->id,
					'message' => 'per-type prices set: ' . wp_json_encode( $pricing ) . ' · apply_to_all=' . ( ! empty( $_POST['apply_to_all_seats'] ) ? 1 : 0 ) . ' · seats overwritten=' . $touched,
				) );
			}

			$message = __( 'Pricing saved.', 'credoq-seats' );
			if ( ! empty( $_POST['apply_to_all_seats'] ) ) {
				$message .= ' ' . sprintf( __( '%d individual seat price override(s) were overwritten.', 'credoq-seats' ), $touched );
			}
			return array( 'message' => $message );
		}

		if ( 'set_status' === $action && $plan->id ) {
			$status_raw = $_POST['status'] ?? 'draft';
			$status     = in_array( $status_raw, array( 'draft', 'published', 'archived' ), true ) ? $status_raw : 'draft';
			Plan_Repository::save( array( 'id' => $plan->id, 'status' => $status ) );
			return array( 'message' => sprintf( __( 'Plan marked as %s.', 'credoq-seats' ), $status ) );
		}

		return array();
	}

	/* ── Tab 1: Info ──────────────────────────────────────────────────── */

	private static function render_info_tab( object $plan ) : void {
		$connected_ids = json_decode( $plan->connected_ids ?? '[]', true ) ?: array();
		$events = class_exists( '\CredoqEvents\Event_Repository' ) ? \CredoqEvents\Event_Repository::all() : array();
		$apts   = class_exists( '\CredoqAppointments\Appointment_Repository' ) ? \CredoqAppointments\Appointment_Repository::all( 200 ) : array();
		?>
		<form method="post">
			<?php wp_nonce_field( 'credoq_seat_builder' ); ?>
			<input type="hidden" name="credoq_seat_builder_action" value="save_info">

			<div class="credoq-card">
				<h2 class="credoq-section-title"><?php esc_html_e( 'Plan Info', 'credoq-seats' ); ?></h2>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Name', 'credoq-seats' ); ?></th><td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $plan->name ); ?>" required></td></tr>
					<tr><th><?php esc_html_e( 'Description', 'credoq-seats' ); ?></th><td><textarea name="description" class="large-text" rows="3"><?php echo esc_textarea( $plan->description ); ?></textarea></td></tr>
					<tr><th><?php esc_html_e( 'Capacity override', 'credoq-seats' ); ?></th>
						<td>
							<input type="number" name="capacity_limit" min="0" value="<?php echo (int) $plan->capacity_limit; ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Leave 0 to auto-use the seat count from the canvas.', 'credoq-seats' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="credoq-card">
				<h2 class="credoq-section-title"><?php esc_html_e( 'Connect to a service', 'credoq-seats' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Optional. Connecting lets the seat map field type appear when building a form for the selected event(s) or appointment service(s).', 'credoq-seats' ); ?></p>

				<p>
					<label><input type="radio" name="connect_type" value="none" <?php checked( $plan->connect_type, 'none' ); ?>> <?php esc_html_e( 'Not connected', 'credoq-seats' ); ?></label><br>
					<label><input type="radio" name="connect_type" value="event" <?php checked( $plan->connect_type, 'event' ); ?>> <?php esc_html_e( 'Connect to Credoq Events', 'credoq-seats' ); ?></label><br>
					<label><input type="radio" name="connect_type" value="appointment" <?php checked( $plan->connect_type, 'appointment' ); ?>> <?php esc_html_e( 'Connect to Credoq Appointments', 'credoq-seats' ); ?></label>
				</p>

				<?php if ( ! empty( $events ) ) : ?>
				<div style="margin:10px 0;padding:10px;border:1px solid #e2e8f0;border-radius:8px;max-height:220px;overflow:auto;">
					<strong style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Events', 'credoq-seats' ); ?></strong>
					<?php foreach ( $events as $e ) : ?>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" name="connected_event_ids[]" value="<?php echo (int) $e->id; ?>" <?php echo in_array( (int) $e->id, array_map( 'intval', $connected_ids ), true ) ? 'checked' : ''; ?>>
							<?php echo esc_html( $e->title ); ?> (<?php echo esc_html( mysql2date( 'M j, Y', $e->start_datetime ) ); ?>) — <?php printf( esc_html__( 'Capacity: %d', 'credoq-seats' ), (int) $e->capacity ); ?>
						</label>
					<?php endforeach; ?>
				</div>
				<?php elseif ( class_exists( '\CredoqEvents\Event_Repository' ) ) : ?>
					<p style="color:#94a3b8;"><?php esc_html_e( 'No events found yet.', 'credoq-seats' ); ?></p>
				<?php else : ?>
					<p style="color:#94a3b8;"><?php esc_html_e( 'Credoq Events is not active.', 'credoq-seats' ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $apts ) ) : ?>
				<div style="margin:10px 0;padding:10px;border:1px solid #e2e8f0;border-radius:8px;max-height:220px;overflow:auto;">
					<strong style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Appointments', 'credoq-seats' ); ?></strong>
					<?php foreach ( $apts as $a ) : ?>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" name="connected_apt_ids[]" value="<?php echo (int) $a->id; ?>" <?php echo in_array( (int) $a->id, array_map( 'intval', $connected_ids ), true ) ? 'checked' : ''; ?>>
							<?php echo esc_html( $a->title ); ?> — <?php printf( esc_html__( 'Max per slot: %d', 'credoq-seats' ), (int) $a->max_bookings ); ?>
						</label>
					<?php endforeach; ?>
				</div>
				<?php elseif ( class_exists( '\CredoqAppointments\Appointment_Repository' ) ) : ?>
					<p style="color:#94a3b8;"><?php esc_html_e( 'No appointment services found yet.', 'credoq-seats' ); ?></p>
				<?php else : ?>
					<p style="color:#94a3b8;"><?php esc_html_e( 'Credoq Appointments is not active.', 'credoq-seats' ); ?></p>
				<?php endif; ?>

				<p class="description">
					<?php esc_html_e( 'After connecting, open the service in Appointments/Events and turn on "Enable seat map" (Appointments) or add the Seat Map field to the registration form (Events).', 'credoq-seats' ); ?>
				</p>
			</div>

			<p><button class="button button-primary"><?php echo $plan->id ? esc_html__( 'Save', 'credoq-seats' ) : esc_html__( 'Save & Continue', 'credoq-seats' ); ?></button></p>
		</form>
		<?php
	}

	/* ── Tab 2: Templates ─────────────────────────────────────────────── */

	private static function render_templates_tab( object $plan ) : void {
		?>
		<div class="credoq-card">
			<h2 class="credoq-section-title"><?php esc_html_e( 'Choose a starting template', 'credoq-seats' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Applying a template replaces the current canvas. You can still edit every seat afterward.', 'credoq-seats' ); ?></p>

			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-top:14px;">
				<?php foreach ( Template_Library::catalog() as $key => $tpl ) : ?>
				<form method="post" style="border:2px solid #e2e8f0;border-radius:10px;padding:14px;">
					<?php wp_nonce_field( 'credoq_seat_builder' ); ?>
					<input type="hidden" name="credoq_seat_builder_action" value="apply_template">
					<input type="hidden" name="template_key" value="<?php echo esc_attr( $key ); ?>">
					<div style="font-weight:700;color:#1e293b;margin-bottom:4px;"><?php echo esc_html( $tpl['label'] ); ?></div>
					<div style="font-size:12px;color:#64748b;margin-bottom:10px;min-height:32px;"><?php echo esc_html( $tpl['description'] ); ?></div>
					<button class="button <?php echo $plan->template_key === $key ? 'button-primary' : ''; ?>">
						<?php echo $plan->template_key === $key ? esc_html__( 'Current', 'credoq-seats' ) : esc_html__( 'Use this template', 'credoq-seats' ); ?>
					</button>
				</form>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* ── Tab 3: Canvas ────────────────────────────────────────────────── */

	private static function render_canvas_tab( object $plan, array $layout ) : void {
		if ( empty( $layout['floors'] ) ) $layout['floors'] = array( array( 'name' => 'Floor 1', 'color' => '#4f46e5', 'seats' => array() ) );

		wp_enqueue_style( 'credoq-seats-canvas-builder', CREDOQ_SEATS_URL . 'assets/css/seat-canvas-builder.min.css', array(), CREDOQ_SEATS_VERSION );
		wp_enqueue_script( 'credoq-seats-canvas-builder', CREDOQ_SEATS_URL . 'assets/js/seat-canvas-builder.min.js', array(), CREDOQ_SEATS_VERSION, true );
		?>
		<div class="credoq-card">
			<h2 class="credoq-section-title"><?php esc_html_e( 'Canvas Builder', 'credoq-seats' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Generate a grid of seats, then drag to reposition — click to select one, shift-click to select several, drag any selected seat to move the whole group. The panel on the right edits label, type, price, and status for whatever is selected.', 'credoq-seats' ); ?>
			</p>

			<div id="cvsp-builder-root"
			     data-plan-id="<?php echo (int) $plan->id; ?>"
			     data-layout="<?php echo esc_attr( wp_json_encode( $layout ) ); ?>"
			     data-hidden-input-id="cvsp-layout-input"
			     data-form-id="cvsp-canvas-form"></div>
		</div>

		<form method="post" id="cvsp-canvas-form">
			<?php wp_nonce_field( 'credoq_seat_builder' ); ?>
			<input type="hidden" name="credoq_seat_builder_action" value="save_canvas">
			<input type="hidden" name="layout_json" id="cvsp-layout-input">
		</form>
		<?php
	}


	/* ── Tab 4: Pricing ───────────────────────────────────────────────── */

	private static function render_pricing_tab( object $plan, array $layout ) : void {
		$pricing = $layout['pricing'] ?? array();
		?>
		<form method="post">
			<?php wp_nonce_field( 'credoq_seat_builder' ); ?>
			<input type="hidden" name="credoq_seat_builder_action" value="save_pricing">

			<div class="credoq-card">
				<h2 class="credoq-section-title"><?php esc_html_e( 'Price per seat type', 'credoq-seats' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Leave a type blank to inherit the connected event/appointment base price for seats of that type. An individual seat\'s own price override (set in the Canvas tab) always wins over both of these.', 'credoq-seats' ); ?></p>
				<table class="form-table">
					<?php foreach ( array( 'standard', 'vip', 'accessible', 'restricted', 'aisle' ) as $type ) : ?>
					<tr>
						<th><?php echo esc_html( ucfirst( $type ) ); ?></th>
						<td><input type="number" step="0.01" name="price_<?php echo esc_attr( $type ); ?>" value="<?php echo isset( $pricing[ $type ] ) && null !== $pricing[ $type ] ? esc_attr( $pricing[ $type ] ) : ''; ?>" placeholder="<?php esc_attr_e( 'inherit base price', 'credoq-seats' ); ?>" class="regular-text"></td>
					</tr>
					<?php endforeach; ?>
				</table>
				<label><input type="checkbox" name="apply_to_all_seats" value="1"> <?php esc_html_e( 'Also overwrite every existing seat\'s individual price override with these values', 'credoq-seats' ); ?></label>
				<p class="description" style="margin-top:6px;"><?php esc_html_e( 'This checkbox is an action, not a saved setting — it always shows unchecked after saving. To confirm a bulk overwrite actually ran, check the record below or the Audit log (event: seats.pricing_saved).', 'credoq-seats' ); ?></p>

				<?php $last = $layout['pricing_last_applied'] ?? null; if ( $last ) : ?>
				<div style="margin-top:12px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px;color:#166534;">
					<?php printf(
						esc_html__( 'Last bulk overwrite: %1$d seat(s) updated on %2$s.', 'credoq-seats' ),
						(int) ( $last['seats'] ?? 0 ),
						esc_html( mysql2date( 'M j, Y g:ia', $last['at'] ?? '' ) )
					); ?>
				</div>
				<?php endif; ?>
			</div>

			<p><button class="button button-primary"><?php esc_html_e( 'Save pricing', 'credoq-seats' ); ?></button></p>
		</form>
		<?php
	}

	/* ── Tab 5: Publish ───────────────────────────────────────────────── */

	private static function render_publish_tab( object $plan, array $layout ) : void {
		$seat_count = 0;
		foreach ( $layout['floors'] ?? array() as $f ) $seat_count += count( $f['seats'] ?? array() );
		?>
		<div class="credoq-card">
			<h2 class="credoq-section-title"><?php esc_html_e( 'Summary', 'credoq-seats' ); ?></h2>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;">
				<div><div style="font-size:12px;color:#94a3b8;"><?php esc_html_e( 'Floors', 'credoq-seats' ); ?></div><div style="font-size:22px;font-weight:800;"><?php echo count( $layout['floors'] ?? array() ); ?></div></div>
				<div><div style="font-size:12px;color:#94a3b8;"><?php esc_html_e( 'Seats', 'credoq-seats' ); ?></div><div style="font-size:22px;font-weight:800;"><?php echo (int) $seat_count; ?></div></div>
				<div><div style="font-size:12px;color:#94a3b8;"><?php esc_html_e( 'Capacity limit', 'credoq-seats' ); ?></div><div style="font-size:22px;font-weight:800;"><?php echo (int) ( $plan->capacity_limit ?: $seat_count ); ?></div></div>
				<div><div style="font-size:12px;color:#94a3b8;"><?php esc_html_e( 'Status', 'credoq-seats' ); ?></div><div style="font-size:22px;font-weight:800;text-transform:capitalize;"><?php echo esc_html( $plan->status ); ?></div></div>
			</div>
		</div>

		<div class="credoq-card">
			<h2 class="credoq-section-title"><?php esc_html_e( 'Change status', 'credoq-seats' ); ?></h2>
			<form method="post" style="display:flex;gap:8px;">
				<?php wp_nonce_field( 'credoq_seat_builder' ); ?>
				<input type="hidden" name="credoq_seat_builder_action" value="set_status">
				<?php foreach ( array( 'draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived' ) as $key => $label ) : ?>
					<button class="button <?php echo $plan->status === $key ? 'button-primary' : ''; ?>" name="status" value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</form>
			<p class="description" style="margin-top:10px;"><?php esc_html_e( 'Only Published plans appear in the Appointments/Events seat-plan pickers.', 'credoq-seats' ); ?></p>
		</div>
		<?php
	}
}
