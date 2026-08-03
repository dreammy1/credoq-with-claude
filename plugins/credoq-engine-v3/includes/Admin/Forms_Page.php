<?php
/**
 * Forms admin page — drag-and-drop visual form builder.
 *
 * Three-column layout: field-type palette | drag-drop canvas | field settings panel.
 * Below the builder: Design customizer + Form-behaviour settings.
 *
 * Save flow: JS base64-encodes fields+settings → PHP decodes → Repository::save().
 * Addons can inject custom field-panel HTML via window.credoqCustomFieldPanels
 * and extra scripts via the 'credoq_form_builder_after_editor_scripts' action.
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

defined( 'ABSPATH' ) || exit;

class Forms_Page {

	/* ════════════════════════════════════════════════════════════════════
	   ENTRY POINT
	════════════════════════════════════════════════════════════════════ */

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$action  = sanitize_key( $_GET['action'] ?? 'list' );
		$form_id = absint( $_GET['id'] ?? 0 );

		// ── Handle save ─────────────────────────────────────────────
		if ( isset( $_POST['_credoq_form_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_credoq_form_nonce'] ) );
			if ( wp_verify_nonce( $nonce, 'credoq_save_form_nonce' ) ) {
				self::handle_save( $form_id );
				return;
			}
		}

		// ── Handle delete ────────────────────────────────────────────
		if ( 'delete' === $action && $form_id && isset( $_GET['_wpnonce'] ) ) {
			$n = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
			if ( wp_verify_nonce( $n, 'credoq_delete_form_' . $form_id ) ) {
				credoq_engine()->forms()->delete( $form_id );
				wp_redirect( admin_url( 'admin.php?page=credoq-forms&msg=deleted' ) );
				exit;
			}
		}

		// ── Flash notices ────────────────────────────────────────────
		if ( isset( $_GET['msg'] ) ) {
			$msg = sanitize_key( $_GET['msg'] );
			if ( 'saved' === $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Form saved.', 'credoq-engine' ) . '</p></div>';
			} elseif ( 'deleted' === $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Form deleted.', 'credoq-engine' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['save_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( urldecode( $_GET['save_error'] ) ) . '</p></div>';
		}

		if ( 'edit' === $action || 'new' === $action ) {
			self::render_editor( $form_id );
		} else {
			self::render_list();
		}
	}

	/* ════════════════════════════════════════════════════════════════════
	   SAVE HANDLER
	════════════════════════════════════════════════════════════════════ */

	private static function handle_save( int $current_id ) : void {
		$raw_fields   = sanitize_text_field( wp_unslash( $_POST['form_fields']   ?? '[]' ) );
		$raw_settings = sanitize_text_field( wp_unslash( $_POST['form_settings'] ?? '{}' ) );

		$dec_f = base64_decode( $raw_fields,   true );
		$dec_s = base64_decode( $raw_settings, true );

		$fields_json   = ( false !== $dec_f ) ? $dec_f : $raw_fields;
		$settings_json = ( false !== $dec_s ) ? $dec_s : $raw_settings;

		$fields   = json_decode( $fields_json,   true );
		$settings = json_decode( $settings_json, true );

		$save_id = absint( $_POST['form_id'] ?? 0 );

		$result = credoq_engine()->forms()->save( array(
			'id'       => $save_id,
			'title'    => sanitize_text_field( $_POST['form_title'] ?? '' ),
			'fields'   => is_array( $fields )   ? $fields   : array(),
			'settings' => is_array( $settings ) ? $settings : array(),
		) );

		if ( is_wp_error( $result ) ) {
			$back_action = $save_id ? 'edit&id=' . $save_id : 'new';
			wp_redirect( admin_url(
				'admin.php?page=credoq-forms&action=' . $back_action
				. '&save_error=' . rawurlencode( $result->get_error_message() )
			) );
			exit;
		}

		wp_redirect( admin_url( 'admin.php?page=credoq-forms&action=edit&id=' . (int) $result . '&msg=saved' ) );
		exit;
	}

	/* ════════════════════════════════════════════════════════════════════
	   LIST VIEW
	════════════════════════════════════════════════════════════════════ */

	private static function render_list() : void {
		$forms = credoq_engine()->forms()->all( 200 );
		?>
		<div class="wrap credoq-forms-list">
		<style>
		.credoq-forms-list{max-width:1100px;}
		.cfl-header{display:flex;align-items:center;justify-content:space-between;margin:0 0 20px;}
		.cfl-header h1{margin:0;font-size:22px;font-weight:700;}
		.cfl-btn-primary{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#4f46e5;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s;}
		.cfl-btn-primary:hover,.cfl-btn-primary:focus{background:#4338ca;color:#fff;}
		.cfl-table-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;}
		.cfl-table{width:100%;border-collapse:collapse;}
		.cfl-table th{background:#f8fafc;padding:10px 16px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e2e8f0;text-align:left;}
		.cfl-table td{padding:13px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle;}
		.cfl-table tr:last-child td{border-bottom:none;}
		.cfl-table tr:hover td{background:#fafafa;}
		.cfl-id-badge{display:inline-block;background:#eef2ff;color:#4f46e5;font-size:11px;font-weight:700;padding:2px 7px;border-radius:5px;}
		.cfl-sc{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:4px;font-size:11px;padding:3px 7px;font-family:monospace;max-width:260px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;}
		.cfl-copy-btn{margin-left:6px;background:none;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:11px;color:#374151;cursor:pointer;transition:all .15s;}
		.cfl-copy-btn:hover{background:#f3f4f6;border-color:#9ca3af;}
		.cfl-actions{display:flex;gap:6px;}
		.cfl-btn-edit{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;font-weight:600;color:#166534;text-decoration:none;transition:all .15s;}
		.cfl-btn-edit:hover{background:#dcfce7;color:#166534;}
		.cfl-btn-del{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;background:#fff1f2;border:1px solid #fecdd3;border-radius:6px;font-size:12px;font-weight:600;color:#be123c;text-decoration:none;transition:all .15s;}
		.cfl-btn-del:hover{background:#ffe4e6;color:#be123c;}
		.cfl-empty{text-align:center;padding:60px 20px;color:#94a3b8;}
		.cfl-empty svg{display:block;margin:0 auto 12px;opacity:.4;}
		</style>

		<div class="cfl-header">
			<h1><?php esc_html_e( 'Forms', 'credoq-engine' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-forms&action=new' ) ); ?>" class="cfl-btn-primary">
				+ <?php esc_html_e( 'New Form', 'credoq-engine' ); ?>
			</a>
		</div>

		<div class="cfl-table-card">
			<?php if ( empty( $forms ) ) : ?>
			<div class="cfl-empty">
				<p style="font-size:15px;font-weight:600;"><?php esc_html_e( 'No forms yet', 'credoq-engine' ); ?></p>
				<p style="font-size:13px;"><?php esc_html_e( 'Create your first booking form to get started.', 'credoq-engine' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-forms&action=new' ) ); ?>" class="cfl-btn-primary" style="display:inline-flex;margin-top:12px;">
					+ <?php esc_html_e( 'Create Form', 'credoq-engine' ); ?>
				</a>
			</div>
			<?php else : ?>
			<table class="cfl-table">
				<thead>
					<tr>
						<th>ID</th>
						<th><?php esc_html_e( 'Form Name', 'credoq-engine' ); ?></th>
						<th><?php esc_html_e( 'Fields', 'credoq-engine' ); ?></th>
						<th><?php esc_html_e( 'Shortcode', 'credoq-engine' ); ?></th>
						<th><?php esc_html_e( 'Last Updated', 'credoq-engine' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'credoq-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $forms as $f ) :
					$sc = '[credoq_booking_form form_id="' . $f->id . '"]';
				?>
					<tr>
						<td><span class="cfl-id-badge">#<?php echo (int) $f->id; ?></span></td>
						<td><strong><?php echo esc_html( $f->title ); ?></strong></td>
						<td><?php echo count( $f->fields ); ?></td>
						<td>
							<code class="cfl-sc" title="<?php echo esc_attr( $sc ); ?>"><?php echo esc_html( $sc ); ?></code>
							<button class="cfl-copy-btn" data-copy="<?php echo esc_attr( $sc ); ?>">Copy</button>
						</td>
						<td style="color:#64748b;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $f->updated_at . ' UTC' ) ) ); ?></td>
						<td>
							<div class="cfl-actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-forms&action=edit&id=' . $f->id ) ); ?>" class="cfl-btn-edit">✏ <?php esc_html_e( 'Edit', 'credoq-engine' ); ?></a>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=credoq-forms&action=delete&id=' . $f->id ), 'credoq_delete_form_' . $f->id ) ); ?>"
								   class="cfl-btn-del"
								   onclick="return confirm('<?php esc_attr_e( 'Delete this form and all its data?', 'credoq-engine' ); ?>');">
									🗑 <?php esc_html_e( 'Delete', 'credoq-engine' ); ?>
								</a>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		</div>
		<script>
		document.querySelectorAll('.cfl-copy-btn').forEach(function(b){
			b.addEventListener('click', function(){
				navigator.clipboard.writeText(this.dataset.copy).then(function(){
					b.textContent = '✓ Copied!';
					setTimeout(function(){ b.textContent = 'Copy'; }, 1800);
				});
			});
		});
		</script>
		<?php
	}

	/* ════════════════════════════════════════════════════════════════════
	   EDITOR VIEW
	════════════════════════════════════════════════════════════════════ */

	private static function render_editor( int $form_id = 0 ) : void {
		wp_enqueue_media();

		$form          = $form_id ? credoq_engine()->forms()->find( $form_id ) : null;
		$form_title    = $form ? $form->title   : '';
		$fields_json   = $form ? wp_json_encode( $form->fields,   JSON_UNESCAPED_UNICODE ) : '[]';
		$settings_json = $form ? wp_json_encode( $form->settings, JSON_UNESCAPED_UNICODE ) : '{}';

		$back_url = esc_url( admin_url( 'admin.php?page=credoq-forms' ) );
		$form_action = esc_url( admin_url(
			'admin.php?page=credoq-forms&action=' . ( $form_id ? 'edit&id=' . $form_id : 'new' )
		) );

		// Build palette from registry.
		$palette_groups = self::get_palette_groups();
		$all_type_labels = array();
		foreach ( $palette_groups as $items ) {
			foreach ( $items as $d ) {
				$all_type_labels[ $d['slug'] ] = $d['label'];
			}
		}

		// Font options for design panel.
		$google_fonts = array(
			'inherit' => 'System / Theme Default',
			'DM Sans' => 'DM Sans', 'Inter' => 'Inter', 'Poppins' => 'Poppins',
			'Nunito'  => 'Nunito',  'Outfit' => 'Outfit', 'Plus Jakarta Sans' => 'Plus Jakarta Sans',
			'Lato'    => 'Lato',    'Raleway' => 'Raleway', 'Space Grotesk' => 'Space Grotesk',
			'Sora'    => 'Sora',    'Figtree' => 'Figtree',
		);

		// Load existing design settings.
		$ds = ( $form && is_array( $form->settings ) ) ? $form->settings : array();

		// Load form behaviour settings.
		$fb = $ds['form_behaviour'] ?? array();
		?>
		<div class="wrap credoq-fb-wrap">
		<?php self::editor_styles(); ?>

		<!-- ── TOP BAR ──────────────────────────────────────────────────── -->
		<div class="cfb-topbar">
			<a href="<?php echo $back_url; ?>" class="cfb-topbar-back">← <?php esc_html_e( 'All Forms', 'credoq-engine' ); ?></a>
			<input type="text" id="cfb-form-title" class="cfb-topbar-title" value="<?php echo esc_attr( $form_title ); ?>" placeholder="<?php esc_attr_e( 'Form name…', 'credoq-engine' ); ?>">
			<div class="cfb-topbar-right">
				<span id="cfb-save-status" style="font-size:12px;color:#64748b;"></span>
				<button type="button" id="cfb-save-btn" class="cfb-btn-primary">
					<span class="cfb-save-label">💾 <?php esc_html_e( 'Save Form', 'credoq-engine' ); ?></span>
				</button>
			</div>
		</div>

		<!-- Hidden submit form -->
		<form method="post" id="cfb-form" action="<?php echo $form_action; ?>" style="display:none;">
			<?php wp_nonce_field( 'credoq_save_form_nonce', '_credoq_form_nonce' ); ?>
			<input type="hidden" name="form_id"       value="<?php echo (int) $form_id; ?>">
			<input type="hidden" name="form_title"    id="cfb-title-hidden">
			<input type="hidden" name="form_fields"   id="cfb-fields-hidden">
			<input type="hidden" name="form_settings" id="cfb-settings-hidden" value="<?php echo esc_attr( $settings_json ); ?>">
		</form>

		<!-- ── THREE-COLUMN BUILDER ─────────────────────────────────────── -->
		<div class="cfb-layout">

			<!-- PALETTE -->
			<aside class="cfb-palette-col">
				<?php foreach ( $palette_groups as $group_label => $descriptors ) : ?>
				<div class="cfb-group-label"><?php echo esc_html( $group_label ); ?></div>
				<?php foreach ( $descriptors as $d ) : ?>
				<div class="cfb-palette-item" data-type="<?php echo esc_attr( $d['slug'] ); ?>" draggable="true" title="<?php echo esc_attr( $d['description'] ?? '' ); ?>">
					<span class="cfb-pi-icon"><?php echo self::type_icon( $d['slug'] ); ?></span>
					<span><?php echo esc_html( $d['label'] ); ?></span>
				</div>
				<?php endforeach; ?>
				<?php endforeach; ?>
			</aside>

			<!-- CANVAS -->
			<main class="cfb-canvas-col">
				<div class="cfb-canvas-header">
					<span style="font-weight:700;font-size:13px;color:#374151;"><?php esc_html_e( 'Form Canvas', 'credoq-engine' ); ?></span>
					<span id="cfb-field-count" style="font-size:12px;color:#94a3b8;"></span>
				</div>
				<div id="cfb-canvas" class="cfb-canvas">
					<div id="cfb-empty-hint" class="cfb-empty-hint">
						<?php esc_html_e( 'Drag fields from the palette or click to add them here', 'credoq-engine' ); ?>
					</div>
				</div>
			</main>

			<!-- SETTINGS PANEL -->
			<aside class="cfb-settings-col" id="cfb-settings-col">
				<div class="cfb-settings-header"><?php esc_html_e( 'Field Settings', 'credoq-engine' ); ?></div>

				<div id="cfb-settings-empty" class="cfb-settings-empty">
					<?php esc_html_e( 'Click a field to edit its settings', 'credoq-engine' ); ?>
				</div>

				<div id="cfb-settings-body" style="display:none;">

					<!-- Field Width -->
					<div class="cfs-group" id="cfs-width-wrap">
						<label class="cfs-label"><?php esc_html_e( 'Field Width', 'credoq-engine' ); ?></label>
						<div class="cfb-width-pills" id="cfs-width-pills">
							<?php foreach ( array( '' => 'Auto', '25%' => '25', '50%' => '50', '75%' => '75', '100%' => '100' ) as $w => $l ) : ?>
							<button type="button" class="cfb-width-pill<?php echo '' === $w ? ' active' : ''; ?>" data-width="<?php echo esc_attr( $w ); ?>"><?php echo esc_html( $l ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>

					<!-- Label -->
					<div class="cfs-group" id="cfs-label-wrap">
						<label class="cfs-label"><?php esc_html_e( 'Label', 'credoq-engine' ); ?></label>
						<input type="text" id="cfs-label" class="cfs-input">
					</div>

					<!-- Field name -->
					<div class="cfs-group" id="cfs-name-wrap">
						<label class="cfs-label"><?php esc_html_e( 'Field Name', 'credoq-engine' ); ?> <span style="color:#aaa;font-size:10px;">(auto)</span></label>
						<input type="text" id="cfs-name" class="cfs-input">
					</div>

					<!-- Required -->
					<div class="cfs-group" id="cfs-required-wrap">
						<label class="cfs-toggle">
							<input type="checkbox" id="cfs-required">
							<span><?php esc_html_e( 'Required field', 'credoq-engine' ); ?></span>
						</label>
					</div>

					<!-- Placeholder -->
					<div class="cfs-group" id="cfs-placeholder-wrap" style="display:none;">
						<label class="cfs-label"><?php esc_html_e( 'Placeholder', 'credoq-engine' ); ?></label>
						<input type="text" id="cfs-placeholder" class="cfs-input">
					</div>

					<!-- Default value -->
					<div class="cfs-group" id="cfs-default-wrap" style="display:none;">
						<label class="cfs-label"><?php esc_html_e( 'Default Value', 'credoq-engine' ); ?></label>
						<input type="text" id="cfs-default" class="cfs-input">
					</div>

					<!-- Options (select / radio / checkbox) -->
					<div id="cfs-options-wrap" style="display:none;">
						<label class="cfs-label"><?php esc_html_e( 'Options', 'credoq-engine' ); ?> <span style="color:#aaa;font-size:10px;">(Label | Value for formula)</span></label>
						<div id="cfs-options-list" style="margin-bottom:6px;"></div>
						<button type="button" id="cfs-add-option" class="cfb-btn-outline" style="width:100%;">+ <?php esc_html_e( 'Add Option', 'credoq-engine' ); ?></button>
					</div>

					<!-- Formula (calculate) -->
					<div id="cfs-formula-wrap" style="display:none;">
						<label class="cfs-label"><?php esc_html_e( 'Formula', 'credoq-engine' ); ?></label>
						<div style="position:relative;margin-bottom:10px;">
							<textarea id="cfs-formula" class="cfs-formula-ta" rows="3"
								placeholder="{qty} * {base_price} + 5" spellcheck="false"></textarea>
							<button type="button" id="cfb-formula-clear" class="cfb-formula-clear-btn">✕</button>
						</div>
						<!-- Calculator pad -->
						<div class="cfb-calc-pad">
							<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:6px;"><?php esc_html_e( 'Form Fields', 'credoq-engine' ); ?></div>
							<div id="cfb-field-btns" style="display:flex;flex-wrap:wrap;gap:4px;min-height:24px;margin-bottom:8px;">
								<span style="font-size:11px;color:#94a3b8;font-style:italic;"><?php esc_html_e( 'No fields yet', 'credoq-engine' ); ?></span>
							</div>
							<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:6px;"><?php esc_html_e( 'Special Tokens', 'credoq-engine' ); ?></div>
							<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px;">
								<button type="button" class="cfb-calc-btn cfb-token-btn" data-insert="{base_price}" style="background:#312e81;color:#a5b4fc;border-color:#4338ca;">{base_price}</button>
								<button type="button" class="cfb-calc-btn cfb-token-btn" data-insert="{date_price}" style="background:#312e81;color:#a5b4fc;border-color:#4338ca;">{date_price}</button>
							</div>
							<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:5px;">
								<?php
								$calc_btns = array(
									array('7','#1e293b','#94a3b8'),array('8','#1e293b','#94a3b8'),array('9','#1e293b','#94a3b8'),array('/','#4f46e5','#c7d2fe'),
									array('4','#1e293b','#94a3b8'),array('5','#1e293b','#94a3b8'),array('6','#1e293b','#94a3b8'),array('*','#4f46e5','#c7d2fe'),
									array('1','#1e293b','#94a3b8'),array('2','#1e293b','#94a3b8'),array('3','#1e293b','#94a3b8'),array('-','#4f46e5','#c7d2fe'),
									array('0','#1e293b','#94a3b8'),array('.','#1e293b','#94a3b8'),array('(','#0f766e','#5eead4'),array('+','#4f46e5','#c7d2fe'),
									array(')','#0f766e','#5eead4'),array(' ','#334155','#64748b'),array('⌫','#7f1d1d','#fca5a5'),
								);
								foreach ( $calc_btns as $b ) :
									$ins = ( '⌫' === $b[0] ) ? '__BACKSPACE__' : ( ' ' === $b[0] ? ' ' : $b[0] );
									$lbl = ( ' ' === $b[0] ) ? 'Sp' : $b[0];
								?>
								<button type="button" class="cfb-calc-btn cfb-op-btn"
									data-insert="<?php echo esc_attr( $ins ); ?>"
									style="background:<?php echo esc_attr( $b[1] ); ?>;color:<?php echo esc_attr( $b[2] ); ?>;border-color:<?php echo esc_attr( $b[1] === '#1e293b' ? '#334155' : $b[1] ); ?>;">
									<?php echo esc_html( $lbl ); ?>
								</button>
								<?php endforeach; ?>
							</div>
						</div>
						<!-- Calc integration -->
						<div style="margin-top:12px;border-top:1px solid #e2e8f0;padding-top:12px;">
							<label class="cfs-toggle">
								<input type="checkbox" id="cfs-add-to-total">
								<span><?php esc_html_e( 'Add result to booking total', 'credoq-engine' ); ?></span>
							</label>
						</div>
					</div>

					<!-- Calc integration for quantity/select/checkbox/radio -->
					<div id="cfs-extra-total-wrap" style="display:none;border-top:1px solid #e2e8f0;padding-top:10px;margin-top:10px;">
						<label class="cfs-toggle">
							<input type="checkbox" id="cfs-field-add-to-total">
							<span><?php esc_html_e( 'Add to booking total', 'credoq-engine' ); ?></span>
						</label>
					</div>

					<!-- Standalone WooCommerce Checkout (select/radio/checkbox/calculate) -->
					<div id="cfs-wc-wrap" style="display:none;border-top:1px solid #e2e8f0;padding-top:10px;margin-top:10px;">
						<label class="cfs-toggle">
							<input type="checkbox" id="cfs-enable-wc">
							<span style="font-weight:600;"><?php esc_html_e( 'Enable WC Checkout', 'credoq-engine' ); ?></span>
						</label>
						<div id="cfs-wc-body" style="display:none;margin-top:8px;">
							<div class="cfs-group">
								<label class="cfs-label"><?php esc_html_e( 'WC Product ID', 'credoq-engine' ); ?></label>
								<input type="number" id="cfs-wc-product-id" class="cfs-input" min="1" step="1" placeholder="123">
							</div>
							<label class="cfs-toggle">
								<input type="checkbox" id="cfs-wc-option-price">
								<span id="cfs-wc-option-price-label"><?php esc_html_e( 'Option value as price → add to WC grand total', 'credoq-engine' ); ?></span>
							</label>
						</div>
					</div>

					<!-- Total price label -->
					<div id="cfs-total-wrap" style="display:none;">
						<div class="cfs-group">
							<label class="cfs-label"><?php esc_html_e( 'Display Label', 'credoq-engine' ); ?></label>
							<input type="text" id="cfs-total-label" class="cfs-input" placeholder="Total">
						</div>
					</div>

					<!-- Quantity config -->
					<div id="cfs-qty-wrap" style="display:none;">
						<div class="cfs-group">
							<label class="cfs-label"><?php esc_html_e( 'Minimum', 'credoq-engine' ); ?></label>
							<input type="number" id="cfs-qty-min" class="cfs-input" value="1" min="1">
						</div>
						<div class="cfs-group">
							<label class="cfs-label"><?php esc_html_e( 'Maximum', 'credoq-engine' ); ?></label>
							<input type="number" id="cfs-qty-max" class="cfs-input" value="99" min="1">
						</div>
						<label class="cfs-toggle">
							<input type="checkbox" id="cfs-qty-multiply">
							<span><?php esc_html_e( 'Multiply appointment price by quantity', 'credoq-engine' ); ?></span>
						</label>
					</div>

					<!-- File upload config -->
					<div id="cfs-file-wrap" style="display:none;">
						<div class="cfs-group">
							<label class="cfs-label"><?php esc_html_e( 'Allowed Types', 'credoq-engine' ); ?></label>
							<input type="text" id="cfs-file-types" class="cfs-input" placeholder="jpg,png,pdf">
						</div>
						<div class="cfs-group">
							<label class="cfs-label"><?php esc_html_e( 'Max Size (MB)', 'credoq-engine' ); ?></label>
							<input type="number" id="cfs-file-size" class="cfs-input" value="5" min="1">
						</div>
					</div>

					<!-- HTML code -->
					<div id="cfs-html-wrap" style="display:none;">
						<div class="cfs-group">
							<label class="cfs-label"><?php esc_html_e( 'HTML / CSS / Shortcode', 'credoq-engine' ); ?></label>
							<textarea id="cfs-html-code" class="cfs-input" rows="6" style="font-family:monospace;font-size:11px;" placeholder="<div>Any HTML or [shortcode]</div>"></textarea>
						</div>
					</div>

					<!-- Step title -->
					<div id="cfs-step-wrap" style="display:none;">
						<div class="cfs-group">
							<label class="cfs-label"><?php esc_html_e( 'Step Title', 'credoq-engine' ); ?></label>
							<input type="text" id="cfs-step-title" class="cfs-input">
						</div>
					</div>

					<!-- Submit label -->
					<div id="cfs-submit-wrap" style="display:none;">
						<div class="cfs-group">
							<label class="cfs-label"><?php esc_html_e( 'Button Label', 'credoq-engine' ); ?></label>
							<input type="text" id="cfs-submit-label" class="cfs-input" placeholder="Submit">
						</div>
					</div>

					<!-- Custom addon panels injected here by addons -->
					<div id="cfs-addon-panel-wrap"></div>

					<!-- Conditional logic -->
					<div class="cfs-group" style="border-top:1px solid #e2e8f0;padding-top:12px;margin-top:12px;">
						<label class="cfs-toggle">
							<input type="checkbox" id="cfs-cond-enabled">
							<span style="font-weight:600;"><?php esc_html_e( 'Conditional Logic', 'credoq-engine' ); ?></span>
						</label>
						<div id="cfs-cond-body" style="display:none;margin-top:10px;background:#f8fafc;border-radius:7px;padding:10px;">
							<div style="font-size:11px;color:#64748b;margin-bottom:6px;"><?php esc_html_e( 'Show this field if:', 'credoq-engine' ); ?></div>
							<select id="cfs-cond-field" class="cfs-input cfs-select" style="margin-bottom:6px;"></select>
							<div style="display:flex;gap:6px;">
								<select id="cfs-cond-op" class="cfs-input cfs-select" style="width:100px;">
									<option value="equals"><?php esc_html_e( 'equals', 'credoq-engine' ); ?></option>
									<option value="not_equals"><?php esc_html_e( 'is not', 'credoq-engine' ); ?></option>
									<option value="contains"><?php esc_html_e( 'contains', 'credoq-engine' ); ?></option>
									<option value="not_empty"><?php esc_html_e( 'is not empty', 'credoq-engine' ); ?></option>
									<option value="empty"><?php esc_html_e( 'is empty', 'credoq-engine' ); ?></option>
								</select>
								<input type="text" id="cfs-cond-value" class="cfs-input" style="flex:1;" placeholder="value">
							</div>
						</div>
					</div>

					<!-- Action buttons -->
					<div style="display:flex;gap:8px;margin-top:14px;">
						<button type="button" id="cfs-apply" class="cfb-btn-primary" style="flex:1;">
							<?php esc_html_e( 'Apply', 'credoq-engine' ); ?>
						</button>
						<button type="button" id="cfs-duplicate" class="cfb-btn-outline" title="<?php esc_attr_e( 'Duplicate field', 'credoq-engine' ); ?>">⎘</button>
						<button type="button" id="cfs-delete" class="cfb-btn-danger" title="<?php esc_attr_e( 'Delete field', 'credoq-engine' ); ?>">🗑</button>
					</div>
				</div><!-- #cfb-settings-body -->
			</aside>
		</div><!-- .cfb-layout -->

		<!-- ── DESIGN PANEL ─────────────────────────────────────────────── -->
		<div class="cfb-panel-card" style="margin-top:20px;">
			<div class="cfb-panel-header" onclick="cfbTogglePanel('design')">
				<div style="display:flex;align-items:center;gap:10px;">
					<span style="font-size:20px;">🎨</span>
					<div>
						<strong style="font-size:14px;"><?php esc_html_e( 'Widget Design & Customizer', 'credoq-engine' ); ?></strong>
						<p style="font-size:12px;color:#64748b;margin:2px 0 0;"><?php esc_html_e( 'Typography, colors, calendar style, button shape. Settings apply to this form only.', 'credoq-engine' ); ?></p>
					</div>
				</div>
				<span id="cfb-design-toggle-icon" style="font-size:18px;color:#94a3b8;transition:transform .2s;">▼</span>
			</div>
			<div id="cfb-design-body" style="display:none;padding:20px 22px;">

				<!-- Tabs -->
				<div class="cfb-dtabs">
					<?php foreach ( array( 'typography' => '✏ Typography', 'colors' => '🎨 Colors', 'layout' => '⬚ Layout', 'calendar' => '📅 Calendar' ) as $tid => $tlabel ) : ?>
					<button type="button" class="cfb-dtab" data-tab="<?php echo esc_attr( $tid ); ?>" onclick="cfbDesignTab('<?php echo esc_attr( $tid ); ?>')"><?php echo esc_html( $tlabel ); ?></button>
					<?php endforeach; ?>
				</div>

				<!-- Typography tab -->
				<div class="cfb-dtab-panel" id="cfb-dt-typography" style="display:none;">
					<div class="cfb-design-grid">
						<div>
							<label class="cfs-label"><?php esc_html_e( 'Brand Name', 'credoq-engine' ); ?></label>
							<input type="text" id="fd-brand-name" class="cfs-input" value="<?php echo esc_attr( $ds['brand_name'] ?? '' ); ?>" placeholder="Your Brand">
						</div>
						<div>
							<label class="cfs-label"><?php esc_html_e( 'Font Family', 'credoq-engine' ); ?></label>
							<select id="fd-font-family" class="cfs-input cfs-select" onchange="cfbPreviewFont(this.value)">
								<?php foreach ( $google_fonts as $fk => $fv ) : ?>
								<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $ds['font_family'] ?? 'inherit', $fk ); ?>><?php echo esc_html( $fv ); ?></option>
								<?php endforeach; ?>
							</select>
							<div id="cfb-font-preview" style="margin-top:6px;padding:8px 10px;background:#f6f7f7;border-radius:5px;font-size:14px;color:#111;"></div>
						</div>
					</div>
					<div class="cfb-design-grid" style="margin-top:14px;">
						<?php foreach ( array( 'font_size_base' => array( __( 'Base Font Size', 'credoq-engine' ), 12, 20, $ds['font_size_base'] ?? 15 ), 'font_size_label' => array( __( 'Label Size', 'credoq-engine' ), 10, 16, $ds['font_size_label'] ?? 12 ), 'font_size_heading' => array( __( 'Heading Size', 'credoq-engine' ), 16, 36, $ds['font_size_heading'] ?? 22 ) ) as $fid => $fc ) : ?>
						<div>
							<label class="cfs-label"><?php echo esc_html( $fc[0] ); ?></label>
							<div style="display:flex;align-items:center;gap:8px;">
								<input type="range" id="fd-<?php echo esc_attr( $fid ); ?>" min="<?php echo (int) $fc[1]; ?>" max="<?php echo (int) $fc[2]; ?>" step="1" value="<?php echo (int) $fc[3]; ?>" style="flex:1;"
									oninput="document.getElementById('fd-<?php echo esc_attr( $fid ); ?>-v').textContent=this.value+'px'">
								<span id="fd-<?php echo esc_attr( $fid ); ?>-v" style="font-size:11px;color:#555;min-width:32px;"><?php echo (int) $fc[3]; ?>px</span>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Colors tab -->
				<div class="cfb-dtab-panel" id="cfb-dt-colors" style="display:none;">
					<p style="font-size:12px;color:#64748b;margin:0 0 14px;"><?php esc_html_e( 'Leave blank to use widget template defaults.', 'credoq-engine' ); ?></p>
					<div class="cfb-design-grid cfb-design-grid-3">
						<?php
						$color_fields = array(
							'color_primary'   => array( __( 'Primary (buttons, accents)', 'credoq-engine' ), '#4f46e5' ),
							'color_secondary' => array( __( 'Secondary / hover',          'credoq-engine' ), '#7c3aed' ),
							'color_accent'    => array( __( 'Accent (slots, badges)',      'credoq-engine' ), '#06b6d4' ),
							'color_text'      => array( __( 'Body text',                  'credoq-engine' ), '#1e293b' ),
							'color_border'    => array( __( 'Borders / dividers',          'credoq-engine' ), '#e2e8f0' ),
							'color_bg'        => array( __( 'Card background',             'credoq-engine' ), '#ffffff' ),
						);
						foreach ( $color_fields as $ck => list( $clabel, $cdef ) ) :
							$cval = $ds[ $ck ] ?? '';
						?>
						<div>
							<label class="cfs-label"><?php echo esc_html( $clabel ); ?></label>
							<div style="display:flex;align-items:center;gap:6px;">
								<input type="color" id="fd-<?php echo esc_attr( $ck ); ?>" value="<?php echo esc_attr( $cval ?: $cdef ); ?>" style="width:34px;height:28px;padding:2px;border:1px solid #dcdcde;border-radius:4px;cursor:pointer;"
									oninput="document.getElementById('fd-<?php echo esc_attr( $ck ); ?>-text').value=this.value">
								<input type="text" id="fd-<?php echo esc_attr( $ck ); ?>-text" value="<?php echo esc_attr( $cval ); ?>" placeholder="<?php echo esc_attr( $cdef ); ?>"
									class="cfs-input" style="flex:1;font-size:11px;"
									oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById('fd-<?php echo esc_attr( $ck ); ?>').value=this.value;}">
								<button type="button" onclick="document.getElementById('fd-<?php echo esc_attr( $ck ); ?>-text').value='';document.getElementById('fd-<?php echo esc_attr( $ck ); ?>').value='<?php echo esc_attr( $cdef ); ?>';" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;padding:0 3px;">×</button>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Layout tab -->
				<div class="cfb-dtab-panel" id="cfb-dt-layout" style="display:none;">
					<div class="cfb-design-grid">
						<?php foreach ( array( 'card_radius' => array( __( 'Card Radius', 'credoq-engine' ), 0, 32, $ds['card_radius'] ?? 16 ), 'card_padding' => array( __( 'Card Padding', 'credoq-engine' ), 12, 40, $ds['card_padding'] ?? 24 ), 'btn_radius' => array( __( 'Button Radius', 'credoq-engine' ), 0, 50, $ds['btn_radius'] ?? 8 ) ) as $rid => $rc ) : ?>
						<div>
							<label class="cfs-label"><?php echo esc_html( $rc[0] ); ?></label>
							<div style="display:flex;align-items:center;gap:8px;">
								<input type="range" id="fd-<?php echo esc_attr( $rid ); ?>" min="<?php echo (int) $rc[1]; ?>" max="<?php echo (int) $rc[2]; ?>" step="2" value="<?php echo (int) $rc[3]; ?>" style="flex:1;"
									oninput="document.getElementById('fd-<?php echo esc_attr( $rid ); ?>-v').textContent=this.value+'px'">
								<span id="fd-<?php echo esc_attr( $rid ); ?>-v" style="font-size:11px;color:#555;min-width:32px;"><?php echo (int) $rc[3]; ?>px</span>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<div class="cfb-design-grid" style="margin-top:14px;">
						<div>
							<label class="cfs-label"><?php esc_html_e( 'Card Shadow', 'credoq-engine' ); ?></label>
							<select id="fd-card-shadow" class="cfs-input cfs-select">
								<?php foreach ( array( 'none' => __( 'None', 'credoq-engine' ), 'sm' => __( 'Subtle', 'credoq-engine' ), 'md' => __( 'Medium (default)', 'credoq-engine' ), 'lg' => __( 'Large', 'credoq-engine' ) ) as $sv => $sl ) : ?>
								<option value="<?php echo esc_attr( $sv ); ?>" <?php selected( $ds['card_shadow'] ?? 'md', $sv ); ?>><?php echo esc_html( $sl ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<label class="cfs-label"><?php esc_html_e( 'Button Style', 'credoq-engine' ); ?></label>
							<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
								<?php foreach ( array( 'filled' => __( 'Filled', 'credoq-engine' ), 'outlined' => __( 'Outlined', 'credoq-engine' ), 'pill' => __( 'Pill', 'credoq-engine' ) ) as $bv => $bl ) : ?>
								<label class="cfb-radio-pill">
									<input type="radio" name="fd-btn-style" value="<?php echo esc_attr( $bv ); ?>" <?php checked( $ds['btn_style'] ?? 'filled', $bv ); ?>>
									<?php echo esc_html( $bl ); ?>
								</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<div style="margin-top:14px;">
						<label class="cfs-label"><?php esc_html_e( 'Time Slot Layout', 'credoq-engine' ); ?></label>
						<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
							<?php foreach ( array( 'list' => __( 'List', 'credoq-engine' ), 'grid' => __( 'Grid', 'credoq-engine' ), 'pills' => __( 'Pills', 'credoq-engine' ) ) as $slv => $sll ) : ?>
							<label class="cfb-radio-pill">
								<input type="radio" name="fd-slot-layout" value="<?php echo esc_attr( $slv ); ?>" <?php checked( $ds['slot_layout'] ?? 'list', $slv ); ?>>
								<?php echo esc_html( $sll ); ?>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<!-- Calendar tab -->
				<div class="cfb-dtab-panel" id="cfb-dt-calendar" style="display:none;">
					<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:18px;">
						<?php
						$cal_styles = array(
							'default' => array( 'name' => 'Default',  'desc' => 'Rounded rect, gradient',    'sel' => 'linear-gradient(135deg,#4f46e5,#7c3aed)' ),
							'rounded' => array( 'name' => 'Rounded',  'desc' => 'Circular days, soft',        'sel' => '#4f46e5' ),
							'flat'    => array( 'name' => 'Flat',     'desc' => 'Minimal, no card',           'sel' => '#0d0d0d' ),
							'outline' => array( 'name' => 'Outline',  'desc' => 'Ring outline on selection',  'sel' => 'transparent' ),
							'card'    => array( 'name' => 'Card',     'desc' => 'Each day its own card',      'sel' => 'linear-gradient(135deg,#4f46e5,#7c3aed)' ),
						);
						$cur_cal = $ds['calendar_style'] ?? 'default';
						foreach ( $cal_styles as $cid => $cs ) :
							$active = ( $cid === $cur_cal );
						?>
						<div class="cfb-cal-card <?php echo $active ? 'cfb-cal-active' : ''; ?>" data-cal="<?php echo esc_attr( $cid ); ?>" onclick="cfbPickCalStyle('<?php echo esc_attr( $cid ); ?>')"
							style="border:2px solid <?php echo $active ? '#4f46e5' : '#e2e8f0'; ?>;border-radius:8px;padding:10px;cursor:pointer;background:#fff;">
							<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:6px;">
								<?php for ( $d = 1; $d <= 21; $d++ ) :
									$is_sel = ( 15 === $d );
									$radius = ( 'rounded' === $cid ) ? '50%' : ( 'card' === $cid ? '3px' : '4px' );
									$bg     = $is_sel ? $cs['sel'] : 'transparent';
									$color  = $is_sel && 'outline' !== $cid ? '#fff' : '#555';
								?>
								<div style="aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:<?php echo esc_attr( $radius ); ?>;background:<?php echo esc_attr( $bg ); ?>;font-size:6px;color:<?php echo esc_attr( $color ); ?>;font-weight:<?php echo $is_sel ? '700' : '400'; ?>;"><?php echo (int) $d; ?></div>
								<?php endfor; ?>
							</div>
							<strong style="font-size:11px;display:block;"><?php echo esc_html( $cs['name'] ); ?></strong>
							<span style="font-size:10px;color:#94a3b8;"><?php echo esc_html( $cs['desc'] ); ?></span>
							<?php if ( $active ) : ?><span style="display:inline-block;margin-top:3px;font-size:9px;font-weight:700;color:#4f46e5;background:#eef2ff;padding:1px 5px;border-radius:4px;">ACTIVE</span><?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>
					<input type="hidden" id="fd-calendar-style" value="<?php echo esc_attr( $cur_cal ); ?>">
					<div>
						<label class="cfs-label"><?php esc_html_e( 'Step Animation', 'credoq-engine' ); ?></label>
						<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
							<?php foreach ( array( 'slide' => __( 'Slide', 'credoq-engine' ), 'fade' => __( 'Fade', 'credoq-engine' ), 'none' => __( 'Instant', 'credoq-engine' ) ) as $av => $al ) : ?>
							<label class="cfb-radio-pill">
								<input type="radio" name="fd-step-animation" value="<?php echo esc_attr( $av ); ?>" <?php checked( $ds['step_animation'] ?? 'slide', $av ); ?>>
								<?php echo esc_html( $al ); ?>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

			</div><!-- #cfb-design-body -->
		</div>

		<!-- ── FORM BEHAVIOUR SETTINGS ─────────────────────────────────── -->
		<div class="cfb-panel-card" style="margin-top:14px;">
			<div class="cfb-panel-header" onclick="cfbTogglePanel('behaviour')">
				<div style="display:flex;align-items:center;gap:10px;">
					<span style="font-size:20px;">⚙</span>
					<div>
						<strong style="font-size:14px;"><?php esc_html_e( 'Form Behaviour & Notifications', 'credoq-engine' ); ?></strong>
						<p style="font-size:12px;color:#64748b;margin:2px 0 0;"><?php esc_html_e( 'Success message, redirect URL, email notifications.', 'credoq-engine' ); ?></p>
					</div>
				</div>
				<span id="cfb-behaviour-toggle-icon" style="font-size:18px;color:#94a3b8;transition:transform .2s;">▼</span>
			</div>
			<div id="cfb-behaviour-body" style="display:none;padding:20px 22px;">
				<div class="cfb-design-grid">
					<div>
						<label class="cfs-label"><?php esc_html_e( 'Success Message', 'credoq-engine' ); ?></label>
						<textarea id="fb-success-message" class="cfs-input" rows="3" placeholder="<?php esc_attr_e( 'Thank you! Your booking has been submitted.', 'credoq-engine' ); ?>"><?php echo esc_textarea( $fb['success_message'] ?? '' ); ?></textarea>
					</div>
					<div>
						<label class="cfs-label"><?php esc_html_e( 'Redirect URL after Submit', 'credoq-engine' ); ?> <span style="color:#94a3b8;font-size:10px;">(overrides message)</span></label>
						<input type="url" id="fb-redirect-url" class="cfs-input" placeholder="https://…" value="<?php echo esc_attr( $fb['redirect_url'] ?? '' ); ?>">
					</div>
				</div>
				<div class="cfb-design-grid" style="margin-top:14px;">
					<div>
						<label class="cfs-label"><?php esc_html_e( 'Notification Email To', 'credoq-engine' ); ?></label>
						<input type="text" id="fb-notify-email" class="cfs-input" placeholder="admin@example.com" value="<?php echo esc_attr( $fb['notify_email'] ?? '' ); ?>">
					</div>
					<div>
						<label class="cfs-label"><?php esc_html_e( 'Notification Email Subject', 'credoq-engine' ); ?></label>
						<input type="text" id="fb-notify-subject" class="cfs-input" placeholder="<?php esc_attr_e( 'New booking submission', 'credoq-engine' ); ?>" value="<?php echo esc_attr( $fb['notify_subject'] ?? '' ); ?>">
					</div>
				</div>
			</div>
		</div>

		</div><!-- .credoq-fb-wrap -->

		<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.6/Sortable.min.js"></script>
		<script>
		(function(){
			'use strict';

			/* ── State ───────────────────────────────────────────────── */
			var FIELDS     = <?php echo wp_json_encode( $form ? $form->fields : array() ); ?>;
			var TYPE_LABELS = <?php echo wp_json_encode( $all_type_labels ); ?>;
			var selectedIdx = null;

			var canvas  = document.getElementById('cfb-canvas');
			var emptyEl = document.getElementById('cfb-empty-hint');
			var countEl = document.getElementById('cfb-field-count');
			var sbEmpty = document.getElementById('cfb-settings-empty');
			var sbBody  = document.getElementById('cfb-settings-body');

			/* ── Icons ───────────────────────────────────────────────── */
			var TYPE_ICONS = {
				text:'Aa', email:'@', phone:'☎', textarea:'≡', number:'#', date:'📅', time:'⏰',
				select:'▾', radio:'◉', checkbox:'☑',
				file:'📎', signature:'✍', hidden:'👁', html:'</>',
				quantity:'🔢', calculate:'∑', total_price:'$',
				step:'—', page_break:'⊟', submit:'▶'
			};
			function icon(t){ return TYPE_ICONS[t] || '⊞'; }
			function slugify(s){ return s.toLowerCase().replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,''); }

			/* ── Expose for design panel ─────────────────────────────── */
			window.CFB_FIELDS        = FIELDS;
			window.CFB_SELECTED_IDX  = null;

			/* ── Render canvas ───────────────────────────────────────── */
			function renderCanvas(){
				canvas.querySelectorAll('.cfb-field-row').forEach(function(r){ r.remove(); });
				emptyEl.style.display = FIELDS.length ? 'none' : '';
				countEl.textContent   = FIELDS.length ? '(' + FIELDS.length + ' field' + (FIELDS.length !== 1 ? 's' : '') + ')' : '';

				FIELDS.forEach(function(f, i){
					var row = document.createElement('div');
					row.className = 'cfb-field-row' + (i === selectedIdx ? ' cfb-selected' : '') + (f.conditional && f.conditional.enabled ? ' cfb-cond-tag' : '');
					row.dataset.idx = i;

					var cond = (f.conditional && f.conditional.enabled) ? '<span class="cfb-cond-badge" title="Has conditional logic">⚡</span>' : '';
					var w = f.field_width ? '<span class="cfb-width-badge">' + f.field_width + '</span>' : '';

					row.innerHTML =
						'<span class="cfb-drag-handle">⠿</span>' +
						'<span class="cfb-field-icon">' + icon(f.type) + '</span>' +
						'<div class="cfb-field-info">' +
							'<strong>' + escHtml(f.label || f.type) + cond + '</strong>' +
							'<small>' + escHtml(TYPE_LABELS[f.type] || f.type) + (f.required ? ' · required' : '') + '</small>' +
						'</div>' +
						w +
						'<span class="cfb-type-badge">' + escHtml(f.type) + '</span>';

					row.addEventListener('click', function(){ selectField(i); });
					canvas.appendChild(row);
				});
			}

			/* ── Select field ────────────────────────────────────────── */
			function selectField(i){
				selectedIdx          = i;
				window.CFB_SELECTED_IDX = i;
				var f = FIELDS[i];
				renderCanvas();
				sbEmpty.style.display = 'none';
				sbBody.style.display  = '';

				// Common fields.
				document.getElementById('cfs-label').value       = f.label         || '';
				document.getElementById('cfs-name').value        = f.name          || '';
				document.getElementById('cfs-required').checked  = !!f.required;
				document.getElementById('cfs-placeholder').value = f.placeholder   || '';
				document.getElementById('cfs-default').value     = f.default_value || '';
				document.getElementById('cfs-formula').value     = f.formula       || '';
				document.getElementById('cfs-step-title').value  = f.step_title    || '';
				document.getElementById('cfs-html-code').value   = f.html_code     || '';
				document.getElementById('cfs-total-label').value = f.total_label   || 'Total';
				document.getElementById('cfs-add-to-total').checked     = !!f.add_to_total;
				document.getElementById('cfs-field-add-to-total').checked = !!f.add_to_total;
				document.getElementById('cfs-enable-wc').checked        = !!f.enable_wc;
				document.getElementById('cfs-wc-product-id').value      = f.wc_product_id || '';
				document.getElementById('cfs-wc-option-price').checked  = !!f.wc_option_price;
				document.getElementById('cfs-wc-body').style.display    = f.enable_wc ? '' : 'none';
				document.getElementById('cfs-qty-min').value     = f.qty_min || 1;
				document.getElementById('cfs-qty-max').value     = f.qty_max || 99;
				document.getElementById('cfs-qty-multiply').checked = !!f.qty_multiply;
				document.getElementById('cfs-file-types').value  = f.file_types    || 'jpg,png,pdf';
				document.getElementById('cfs-file-size').value   = f.file_max_size || 5;
				document.getElementById('cfs-submit-label').value = f.submit_label || '';

				// Field width pills.
				document.querySelectorAll('.cfb-width-pill').forEach(function(p){
					p.classList.toggle('active', p.dataset.width === (f.field_width || ''));
				});

				// Options.
				renderOptionsList(f.options || []);

				// Conditional.
				var cond = f.conditional || {};
				document.getElementById('cfs-cond-enabled').checked = !!cond.enabled;
				document.getElementById('cfs-cond-body').style.display = cond.enabled ? '' : 'none';
				document.getElementById('cfs-cond-op').value    = cond.operator || 'equals';
				document.getElementById('cfs-cond-value').value = cond.value    || '';
				populateCondFieldSelect(cond.field_name || '', i);

				// Show/hide type-specific panels.
				var t = f.type;
				var withPlaceholder = ['text','email','phone','textarea','number','date','time'];
				var withOptions     = ['select','radio','checkbox'];
				var noName          = ['step','page_break','submit','html','total_price'];
				var noReq           = ['step','page_break','submit','html','total_price'];
				var withExtraTotal  = ['quantity','select','radio','checkbox'];
				var withWcCheckout  = ['select','radio','checkbox','calculate'];

				s('cfs-placeholder-wrap',  withPlaceholder.indexOf(t) > -1);
				s('cfs-default-wrap',      ['text','email','phone','textarea','number','date','time','select','radio','hidden'].indexOf(t) > -1);
				s('cfs-options-wrap',      withOptions.indexOf(t) > -1);
				s('cfs-formula-wrap',      t === 'calculate');
				s('cfs-total-wrap',        t === 'total_price');
				s('cfs-qty-wrap',          t === 'quantity');
				s('cfs-file-wrap',         t === 'file');
				s('cfs-html-wrap',         t === 'html');
				s('cfs-step-wrap',         t === 'step' || t === 'page_break');
				s('cfs-submit-wrap',       t === 'submit');
				s('cfs-extra-total-wrap',  withExtraTotal.indexOf(t) > -1);
				s('cfs-wc-wrap',           withWcCheckout.indexOf(t) > -1);
				s('cfs-name-wrap',         noName.indexOf(t) === -1);
				s('cfs-required-wrap',     noReq.indexOf(t) === -1);
				s('cfs-width-wrap',        noName.indexOf(t) === -1);

				// WC option-price label reads differently for the formula field.
				var wcLbl = document.getElementById('cfs-wc-option-price-label');
				if (wcLbl) {
					wcLbl.textContent = (t === 'calculate')
						? '<?php echo esc_js( __( 'Formula result as price → add to WC grand total', 'credoq-engine' ) ); ?>'
						: '<?php echo esc_js( __( 'Option value as price → add to WC grand total', 'credoq-engine' ) ); ?>';
				}

				// Addon custom panel.
				var addonWrap = document.getElementById('cfs-addon-panel-wrap');
				if (addonWrap) {
					var panels = window.credoqCustomFieldPanels || {};
					if (panels[t]) {
						addonWrap.innerHTML = panels[t];
						addonWrap.style.display = '';
						if (typeof window.credoqLoadFieldPanel === 'function') {
							window.credoqLoadFieldPanel(t, f);
						}
					} else {
						addonWrap.innerHTML = '';
						addonWrap.style.display = 'none';
					}
				}
			}

			function s(id, visible){
				var el = document.getElementById(id);
				if (el) el.style.display = visible ? '' : 'none';
			}

			/* ── Options list ────────────────────────────────────────── */
			function renderOptionsList(options){
				var container = document.getElementById('cfs-options-list');
				container.innerHTML = '';
				options.forEach(function(opt, oi){
					var label = typeof opt === 'object' ? (opt.label || opt) : opt;
					var value = typeof opt === 'object' ? (opt.value !== undefined ? opt.value : label) : opt;
					var row   = document.createElement('div');
					row.className = 'cfb-option-row';
					row.innerHTML =
						'<input type="text" class="cfb-opt-label cfs-input" value="' + escAttr(label) + '" placeholder="Option label" style="flex:1.5;">' +
						'<input type="text" class="cfb-opt-value cfs-input" value="' + escAttr(String(value)) + '" placeholder="Value (formula)" style="flex:1;">' +
						'<button type="button" class="cfb-opt-del" onclick="this.closest(\'.cfb-option-row\').remove();">×</button>';
					container.appendChild(row);
				});
			}

			function getOptionsFromUI(){
				var opts = [];
				document.querySelectorAll('#cfs-options-list .cfb-option-row').forEach(function(row){
					var lbl = row.querySelector('.cfb-opt-label').value.trim();
					var val = row.querySelector('.cfb-opt-value').value.trim();
					if (lbl) opts.push({ label: lbl, value: val !== '' ? val : lbl });
				});
				return opts;
			}

			document.getElementById('cfs-add-option').addEventListener('click', function(){
				var existing = getOptionsFromUI();
				existing.push({ label: 'Option ' + (existing.length + 1), value: String(existing.length + 1) });
				renderOptionsList(existing);
			});

			/* ── Conditional field select ────────────────────────────── */
			function populateCondFieldSelect(currentVal, excludeIdx){
				var sel = document.getElementById('cfs-cond-field');
				sel.innerHTML = '<option value="">— ' + (typeof credoqI18n !== 'undefined' ? credoqI18n.select_field : 'select field') + ' —</option>';
				FIELDS.forEach(function(f, i){
					if (i === excludeIdx || !f.name) return;
					var opt = document.createElement('option');
					opt.value = f.name;
					opt.textContent = (f.label || f.name) + ' (' + f.type + ')';
					if (f.name === currentVal) opt.selected = true;
					sel.appendChild(opt);
				});
			}

			document.getElementById('cfs-cond-enabled').addEventListener('change', function(){
				document.getElementById('cfs-cond-body').style.display = this.checked ? '' : 'none';
			});

			document.getElementById('cfs-enable-wc').addEventListener('change', function(){
				document.getElementById('cfs-wc-body').style.display = this.checked ? '' : 'none';
			});

			/* ── Width pills ─────────────────────────────────────────── */
			document.getElementById('cfs-width-pills').addEventListener('click', function(e){
				var pill = e.target.closest('.cfb-width-pill');
				if (!pill) return;
				document.querySelectorAll('.cfb-width-pill').forEach(function(p){ p.classList.remove('active'); });
				pill.classList.add('active');
			});

			/* ── Apply settings ──────────────────────────────────────── */
			document.getElementById('cfs-apply').addEventListener('click', function(){
				if (selectedIdx === null) return;
				var f = FIELDS[selectedIdx];
				f.label          = document.getElementById('cfs-label').value.trim();
				f.name           = document.getElementById('cfs-name').value.trim() || slugify(f.label);
				f.required       = document.getElementById('cfs-required').checked ? 1 : 0;
				f.placeholder    = document.getElementById('cfs-placeholder').value;
				f.default_value  = document.getElementById('cfs-default').value;
				f.formula        = document.getElementById('cfs-formula').value;
				f.step_title     = document.getElementById('cfs-step-title').value;
				f.html_code      = document.getElementById('cfs-html-code').value;
				f.total_label    = document.getElementById('cfs-total-label').value || 'Total';
				f.add_to_total   = document.getElementById('cfs-add-to-total').checked ? 1 : 0;
				f.enable_wc       = document.getElementById('cfs-enable-wc').checked ? 1 : 0;
				f.wc_product_id   = parseInt(document.getElementById('cfs-wc-product-id').value) || 0;
				f.wc_option_price = document.getElementById('cfs-wc-option-price').checked ? 1 : 0;
				f.qty_min        = parseInt(document.getElementById('cfs-qty-min').value) || 1;
				f.qty_max        = parseInt(document.getElementById('cfs-qty-max').value) || 99;
				f.qty_multiply   = document.getElementById('cfs-qty-multiply').checked ? 1 : 0;
				f.file_types     = document.getElementById('cfs-file-types').value;
				f.file_max_size  = parseInt(document.getElementById('cfs-file-size').value) || 5;
				f.submit_label   = document.getElementById('cfs-submit-label').value;
				f.options        = getOptionsFromUI();

				if (['quantity','select','radio','checkbox'].indexOf(f.type) > -1) {
					f.add_to_total = document.getElementById('cfs-field-add-to-total').checked ? 1 : 0;
				}

				// Field width from active pill.
				var activePill = document.querySelector('.cfb-width-pill.active');
				f.field_width  = activePill ? (activePill.dataset.width || '') : '';

				// Addon panel values.
				if (typeof window.credoqSaveFieldPanel === 'function') {
					window.credoqSaveFieldPanel(f.type, f);
				}

				// Conditional.
				f.conditional = {
					enabled:    document.getElementById('cfs-cond-enabled').checked ? 1 : 0,
					field_name: document.getElementById('cfs-cond-field').value,
					operator:   document.getElementById('cfs-cond-op').value,
					value:      document.getElementById('cfs-cond-value').value,
				};

				window.CFB_FIELDS = FIELDS;
				renderCanvas();
			});

			/* ── Delete field ────────────────────────────────────────── */
			document.getElementById('cfs-delete').addEventListener('click', function(){
				if (selectedIdx === null) return;
				if (!confirm('Delete this field?')) return;
				FIELDS.splice(selectedIdx, 1);
				selectedIdx = null;
				window.CFB_SELECTED_IDX = null;
				window.CFB_FIELDS = FIELDS;
				sbBody.style.display  = 'none';
				sbEmpty.style.display = '';
				renderCanvas();
			});

			/* ── Duplicate field ─────────────────────────────────────── */
			document.getElementById('cfs-duplicate').addEventListener('click', function(){
				if (selectedIdx === null) return;
				var orig = FIELDS[selectedIdx];
				var copy = JSON.parse(JSON.stringify(orig));
				var count = FIELDS.filter(function(f){ return f.type === copy.type; }).length + 1;
				copy.name  = copy.type + '_' + count;
				copy.label = (copy.label || copy.type) + ' (copy)';
				delete copy.id;
				FIELDS.splice(selectedIdx + 1, 0, copy);
				window.CFB_FIELDS = FIELDS;
				renderCanvas();
				selectField(selectedIdx + 1);
			});

			/* ── Palette click → add field ───────────────────────────── */
			document.querySelectorAll('.cfb-palette-item').forEach(function(item){
				item.addEventListener('click', function(){
					var t     = this.dataset.type;
					var count = FIELDS.filter(function(f){ return f.type === t; }).length + 1;
					var newF  = {
						type: t, label: (TYPE_LABELS[t] || t) + (count > 1 ? ' ' + count : ''),
						name: t + (count > 1 ? '_' + count : ''), required: 0,
						placeholder: '', default_value: '', options: [],
						formula: '', step_title: '', html_code: '', total_label: 'Total',
						add_to_total: 0, enable_wc: 0, wc_product_id: 0, wc_option_price: 0,
						qty_min: 1, qty_max: 99, qty_multiply: 0,
						file_types: 'jpg,png,pdf', file_max_size: 5,
						field_width: '',
						conditional: { enabled: 0, field_name: '', operator: 'equals', value: '' }
					};
					FIELDS.push(newF);
					window.CFB_FIELDS = FIELDS;
					renderCanvas();
					selectField(FIELDS.length - 1);
					canvas.lastElementChild && canvas.lastElementChild.scrollIntoView({ block: 'nearest' });
				});
			});

			/* ── Drag from palette to canvas ─────────────────────────── */
			document.querySelectorAll('.cfb-palette-item').forEach(function(item){
				item.addEventListener('dragstart', function(e){
					e.dataTransfer.setData('text/plain', this.dataset.type);
				});
			});
			canvas.addEventListener('dragover',  function(e){ e.preventDefault(); canvas.classList.add('cfb-drag-over'); });
			canvas.addEventListener('dragleave', function(){  canvas.classList.remove('cfb-drag-over'); });
			canvas.addEventListener('drop', function(e){
				e.preventDefault();
				canvas.classList.remove('cfb-drag-over');
				var t = e.dataTransfer.getData('text/plain');
				if (!t || !TYPE_LABELS[t]) return;
				var count = FIELDS.filter(function(f){ return f.type === t; }).length + 1;
				FIELDS.push({
					type: t, label: (TYPE_LABELS[t] || t) + (count > 1 ? ' ' + count : ''),
					name: t + (count > 1 ? '_' + count : ''), required: 0,
					placeholder: '', default_value: '', options: [],
					formula: '', step_title: '', html_code: '', total_label: 'Total',
					add_to_total: 0, enable_wc: 0, wc_product_id: 0, wc_option_price: 0,
					qty_min: 1, qty_max: 99, qty_multiply: 0,
					file_types: 'jpg,png,pdf', file_max_size: 5, field_width: '',
					conditional: { enabled: 0, field_name: '', operator: 'equals', value: '' }
				});
				window.CFB_FIELDS = FIELDS;
				renderCanvas();
				selectField(FIELDS.length - 1);
			});

			/* ── SortableJS on canvas ────────────────────────────────── */
			Sortable.create(canvas, {
				handle: '.cfb-drag-handle',
				animation: 180,
				filter: '#cfb-empty-hint',
				ghostClass: 'cfb-sortable-ghost',
				onStart: function(){ canvas.classList.add('cfb-drag-over'); },
				onEnd: function(evt){
					canvas.classList.remove('cfb-drag-over');
					var oldIdx = parseInt(evt.item.dataset.idx);
					var newIdx = evt.newDraggableIndex;
					if (oldIdx === newIdx) return;
					var moved = FIELDS.splice(oldIdx, 1)[0];
					FIELDS.splice(newIdx, 0, moved);
					if (selectedIdx === oldIdx) selectedIdx = newIdx;
					window.CFB_FIELDS  = FIELDS;
					window.CFB_SELECTED_IDX = selectedIdx;
					renderCanvas();
				}
			});

			/* ── Formula calculator ──────────────────────────────────── */
			(function(){
				var ta    = document.getElementById('cfs-formula');
				var clrBt = document.getElementById('cfb-formula-clear');

				function insertAt(val){
					if (!ta) return;
					ta.focus();
					var s = ta.selectionStart, e = ta.selectionEnd, cur = ta.value;
					if (val === '__BACKSPACE__') {
						if (s === e && s > 0) {
							var before = cur.slice(0, s), tm = before.match(/\{[^}]*\}$/);
							if (tm) { ta.value = cur.slice(0, s - tm[0].length) + cur.slice(e); ta.selectionStart = ta.selectionEnd = s - tm[0].length; }
							else    { ta.value = cur.slice(0, s - 1) + cur.slice(e); ta.selectionStart = ta.selectionEnd = s - 1; }
						} else if (s !== e) { ta.value = cur.slice(0, s) + cur.slice(e); ta.selectionStart = ta.selectionEnd = s; }
					} else {
						ta.value = cur.slice(0, s) + val + cur.slice(e);
						ta.selectionStart = ta.selectionEnd = s + val.length;
					}
					if (selectedIdx !== null && FIELDS[selectedIdx]) FIELDS[selectedIdx].formula = ta.value;
				}

				document.querySelectorAll('.cfb-op-btn').forEach(function(b){ b.addEventListener('click', function(e){ e.preventDefault(); insertAt(this.dataset.insert); }); });
				document.querySelectorAll('.cfb-token-btn').forEach(function(b){ b.addEventListener('click', function(e){ e.preventDefault(); insertAt(this.dataset.insert); }); });
				if (clrBt) clrBt.addEventListener('click', function(e){ e.preventDefault(); ta.value = ''; ta.focus(); });

				function rebuildFieldBtns(){
					var fb = document.getElementById('cfb-field-btns');
					if (!fb || selectedIdx === null) return;
					fb.innerHTML = '';
					var added = 0;
					var skip  = ['step','page_break','submit','html','hidden'];
					FIELDS.forEach(function(f, i){
						if (i === selectedIdx || !f.name || skip.indexOf(f.type) > -1) return;
						var token = '{' + f.name + '}';
						var btn   = document.createElement('button');
						btn.type  = 'button';
						btn.className = 'cfb-calc-btn';
						btn.dataset.insert = token;
						btn.textContent    = token;
						btn.style.cssText  = 'background:#1e3a5f;color:#7dd3fc;border-color:#1d4ed8;font-size:.75rem;padding:3px 8px;border-radius:6px;border-width:1px;border-style:solid;cursor:pointer;font-weight:700;font-family:monospace;';
						btn.addEventListener('click', function(e){ e.preventDefault(); insertAt(this.dataset.insert); });
						fb.appendChild(btn);
						added++;
					});
					if (!added) fb.innerHTML = '<span style="font-size:11px;color:#94a3b8;font-style:italic;">No other fields yet</span>';
				}

				var formulaWrap = document.getElementById('cfs-formula-wrap');
				if (formulaWrap) {
					new MutationObserver(function(mutations){
						mutations.forEach(function(m){
							if (m.attributeName === 'style' && formulaWrap.style.display !== 'none') rebuildFieldBtns();
						});
					}).observe(formulaWrap, { attributes: true });
				}
			})();

			/* ── Save ────────────────────────────────────────────────── */
			document.getElementById('cfb-save-btn').addEventListener('click', function(){
				var btn    = this;
				var status = document.getElementById('cfb-save-status');
				btn.disabled = true;
				status.textContent = 'Saving…';

				if (selectedIdx !== null) {
					try { document.getElementById('cfs-apply').click(); } catch(e){}
				}

				document.getElementById('cfb-title-hidden').value = document.getElementById('cfb-form-title').value;

				if (typeof window.cfbCollectAndSaveDesign === 'function') {
					window.cfbCollectAndSaveDesign();
				}

				try {
					var fHid = document.getElementById('cfb-fields-hidden');
					var sHid = document.getElementById('cfb-settings-hidden');
					fHid.value = btoa(unescape(encodeURIComponent(JSON.stringify(FIELDS))));
					sHid.value = btoa(unescape(encodeURIComponent(sHid.value || '{}')));
				} catch(e) {
					document.getElementById('cfb-fields-hidden').value = JSON.stringify(FIELDS);
				}

				document.getElementById('cfb-form').submit();
			});

			/* ── Helpers ─────────────────────────────────────────────── */
			function escAttr(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
			function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

			/* ── Init ────────────────────────────────────────────────── */
			renderCanvas();

		})();
		</script>

		<!-- ── DESIGN PANEL JS ─────────────────────────────────────────── -->
		<script>
		(function(){
			var DS = {};
			try { DS = JSON.parse(document.getElementById('cfb-settings-hidden').value || '{}'); } catch(e){ DS = {}; }

			window.cfbDesignTab = function(id){
				document.querySelectorAll('.cfb-dtab-panel').forEach(function(p){ p.style.display='none'; });
				document.querySelectorAll('.cfb-dtab').forEach(function(b){ b.classList.remove('active'); });
				var p = document.getElementById('cfb-dt-'+id);
				if (p) p.style.display = '';
				var b = document.querySelector('.cfb-dtab[data-tab="'+id+'"]');
				if (b) b.classList.add('active');
			};
			cfbDesignTab('typography');

			window.cfbPickCalStyle = function(s){
				document.querySelectorAll('.cfb-cal-card').forEach(function(c){
					c.style.borderColor = c.dataset.cal === s ? '#4f46e5' : '#e2e8f0';
				});
				document.getElementById('fd-calendar-style').value = s;
			};

			window.cfbPreviewFont = function(font){
				var prev = document.getElementById('cfb-font-preview');
				if (!prev) return;
				if (font && font !== 'inherit') {
					prev.style.fontFamily = "'" + font + "', sans-serif";
					prev.textContent = 'The quick brown fox — booking form preview';
				} else {
					prev.style.fontFamily = '';
					prev.textContent = 'System font — inherits from your theme';
				}
			};
			cfbPreviewFont(document.getElementById('fd-font-family') ? document.getElementById('fd-font-family').value : 'inherit');

			window.cfbTogglePanel = function(name){
				var body   = document.getElementById('cfb-' + name + '-body');
				var icon   = document.getElementById('cfb-' + name + '-toggle-icon');
				var hidden = body.style.display === 'none';
				body.style.display     = hidden ? '' : 'none';
				if (icon) icon.style.transform = hidden ? 'rotate(180deg)' : '';
			};

			window.cfbCollectAndSaveDesign = function(){
				var ds = {};
				// Typography.
				var fontEl = document.getElementById('fd-font-family');
				if (fontEl) ds.font_family = fontEl.value;
				['font_size_base','font_size_label','font_size_heading','card_radius','card_padding','btn_radius'].forEach(function(k){
					var el = document.getElementById('fd-' + k.replace(/_/g,'-'));
					if (el) ds[k] = parseInt(el.value);
				});
				var shadowEl = document.getElementById('fd-card-shadow');
				if (shadowEl) ds.card_shadow = shadowEl.value;
				var brandEl = document.getElementById('fd-brand-name');
				if (brandEl) ds.brand_name = brandEl.value.trim();
				// Colors.
				['color_primary','color_secondary','color_accent','color_text','color_border','color_bg'].forEach(function(ck){
					var el = document.getElementById('fd-' + ck + '-text');
					ds[ck] = el ? el.value.trim() : '';
				});
				// Button/slot/animation style.
				var btnR = document.querySelector('input[name="fd-btn-style"]:checked');
				ds.btn_style = btnR ? btnR.value : 'filled';
				var slotR = document.querySelector('input[name="fd-slot-layout"]:checked');
				ds.slot_layout = slotR ? slotR.value : 'list';
				var animR = document.querySelector('input[name="fd-step-animation"]:checked');
				ds.step_animation = animR ? animR.value : 'slide';
				// Calendar.
				var calEl = document.getElementById('fd-calendar-style');
				if (calEl) ds.calendar_style = calEl.value;
				// Form behaviour.
				ds.form_behaviour = {
					success_message: (document.getElementById('fb-success-message') || {}).value || '',
					redirect_url:    (document.getElementById('fb-redirect-url')    || {}).value || '',
					notify_email:    (document.getElementById('fb-notify-email')    || {}).value || '',
					notify_subject:  (document.getElementById('fb-notify-subject')  || {}).value || '',
				};
				var hiddenEl = document.getElementById('cfb-settings-hidden');
				if (hiddenEl) hiddenEl.value = JSON.stringify(ds);
			};

			// Color picker ↔ text sync.
			['color_primary','color_secondary','color_accent','color_text','color_border','color_bg'].forEach(function(ck){
				var picker = document.getElementById('fd-' + ck);
				var textEl = document.getElementById('fd-' + ck + '-text');
				if (picker && textEl) {
					picker.addEventListener('input', function(){ textEl.value = picker.value; });
				}
			});

			// Radio pill highlight on change.
			document.querySelectorAll('input[name="fd-btn-style"], input[name="fd-slot-layout"], input[name="fd-step-animation"]').forEach(function(r){
				r.addEventListener('change', function(){
					document.querySelectorAll('input[name="'+r.name+'"]').forEach(function(x){
						var lbl = x.closest('label');
						if (lbl) lbl.classList.toggle('active', x.checked);
					});
				});
			});

		})();
		</script>

		<?php do_action( 'credoq_form_builder_after_editor_scripts' ); ?>

		<?php
	}

	/* ════════════════════════════════════════════════════════════════════
	   PALETTE GROUPS
	════════════════════════════════════════════════════════════════════ */

	private static function get_palette_groups() : array {
		$registry   = credoq_engine()->fields()->builder_descriptors();
		$by_slug    = array();
		foreach ( $registry as $d ) {
			$by_slug[ $d['slug'] ] = $d;
		}

		$ordered = array(
			__( 'Basic Fields',       'credoq-engine' ) => array( 'text','email','phone','textarea','number','date','time' ),
			__( 'Choice Fields',      'credoq-engine' ) => array( 'select','radio','checkbox' ),
			__( 'Special Fields',     'credoq-engine' ) => array( 'file','signature','hidden','html' ),
			__( 'Booking & Pricing',  'credoq-engine' ) => array( 'quantity','calculate','total_price' ),
			__( 'Layout / Structure', 'credoq-engine' ) => array( 'step','page_break','submit' ),
		);

		$known  = array();
		$groups = array();
		foreach ( $ordered as $label => $slugs ) {
			$items = array();
			foreach ( $slugs as $slug ) {
				if ( isset( $by_slug[ $slug ] ) ) {
					$items[] = $by_slug[ $slug ];
					$known[] = $slug;
				}
			}
			if ( ! empty( $items ) ) {
				$groups[ $label ] = $items;
			}
		}

		// Addon-provided types not in the fixed groups.
		$addon_items = array();
		foreach ( $registry as $d ) {
			if ( ! in_array( $d['slug'], $known, true ) ) {
				$addon_items[] = $d;
			}
		}
		if ( ! empty( $addon_items ) ) {
			$groups[ __( 'Addon Fields', 'credoq-engine' ) ] = $addon_items;
		}

		return $groups;
	}

	/* ════════════════════════════════════════════════════════════════════
	   TYPE ICON HELPER
	════════════════════════════════════════════════════════════════════ */

	private static function type_icon( string $type ) : string {
		$icons = array(
			'text'        => 'Aa',  'email'      => '@',   'phone'    => '☎',
			'textarea'    => '≡',   'number'     => '#',   'date'     => '📅',
			'time'        => '⏰',  'select'     => '▾',   'radio'    => '◉',
			'checkbox'    => '☑',   'file'       => '📎',  'signature'=> '✍',
			'hidden'      => '👁',  'html'       => '</>',
			'quantity'    => '🔢',  'calculate'  => '∑',   'total_price' => '$',
			'step'        => '—',   'page_break' => '⊟',   'submit'   => '▶',
		);
		return $icons[ $type ] ?? '⊞';
	}

	/* ════════════════════════════════════════════════════════════════════
	   INLINE CSS
	════════════════════════════════════════════════════════════════════ */

	private static function editor_styles() : void {
		?>
		<style>
		/* ── Wrap ─────────────────────────────────────────────────── */
		.credoq-fb-wrap{margin:0 -20px;padding:0;background:#f1f5f9;min-height:calc(100vh - 32px);}
		/* ── Top bar ──────────────────────────────────────────────── */
		.cfb-topbar{display:flex;align-items:center;gap:12px;background:#fff;border-bottom:1px solid #e2e8f0;padding:10px 22px;position:sticky;top:32px;z-index:200;}
		.cfb-topbar-back{font-size:13px;font-weight:600;color:#4f46e5;text-decoration:none;white-space:nowrap;padding:6px 10px;border-radius:6px;transition:background .15s;}
		.cfb-topbar-back:hover{background:#eef2ff;color:#4f46e5;}
		.cfb-topbar-title{flex:1;border:2px solid transparent;border-radius:7px;font-size:16px;font-weight:700;padding:6px 10px;color:#111827;background:transparent;transition:border-color .15s;}
		.cfb-topbar-title:focus{border-color:#4f46e5;outline:none;background:#fff;}
		.cfb-topbar-right{display:flex;align-items:center;gap:10px;margin-left:auto;}
		.cfb-btn-primary{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:#4f46e5;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s;white-space:nowrap;}
		.cfb-btn-primary:hover:not(:disabled){background:#4338ca;}
		.cfb-btn-primary:disabled{opacity:.6;cursor:default;}
		.cfb-btn-outline{padding:7px 12px;background:#fff;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;font-weight:600;color:#374151;cursor:pointer;transition:all .15s;}
		.cfb-btn-outline:hover{border-color:#4f46e5;color:#4f46e5;background:#eef2ff;}
		.cfb-btn-danger{padding:7px 12px;background:#fff;border:1.5px solid #fecdd3;border-radius:7px;font-size:13px;color:#be123c;cursor:pointer;transition:all .15s;}
		.cfb-btn-danger:hover{background:#fff1f2;border-color:#fda4af;}
		/* ── Three-column layout ──────────────────────────────────── */
		.cfb-layout{display:grid;grid-template-columns:192px 1fr 268px;gap:0;padding:0;min-height:600px;}
		/* ── Palette ─────────────────────────────────────────────── */
		.cfb-palette-col{background:#fff;border-right:1px solid #e2e8f0;padding:14px 10px;overflow-y:auto;max-height:calc(100vh - 80px);position:sticky;top:80px;}
		.cfb-group-label{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;padding:10px 4px 5px;margin-top:4px;}
		.cfb-group-label:first-child{margin-top:0;}
		.cfb-palette-item{display:flex;align-items:center;gap:7px;padding:7px 9px;margin-bottom:3px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer;font-size:12px;font-weight:500;color:#374151;user-select:none;transition:all .15s;}
		.cfb-palette-item:hover{background:#eef2ff;border-color:#a5b4fc;color:#4f46e5;transform:translateX(2px);}
		.cfb-pi-icon{font-size:13px;width:18px;text-align:center;flex-shrink:0;}
		/* ── Canvas ──────────────────────────────────────────────── */
		.cfb-canvas-col{padding:16px;background:#f8fafc;}
		.cfb-canvas-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
		.cfb-canvas{min-height:500px;border:2px dashed #cbd5e1;border-radius:10px;padding:10px;background:#fff;transition:all .2s;}
		.cfb-canvas.cfb-drag-over{border-color:#4f46e5;background:#eef2ff;}
		.cfb-empty-hint{text-align:center;color:#cbd5e1;padding:80px 20px;font-size:14px;pointer-events:none;}
		.cfb-field-row{display:flex;align-items:center;gap:8px;padding:11px 14px;margin-bottom:6px;background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:all .15s;box-shadow:0 1px 3px rgba(0,0,0,.04);}
		.cfb-field-row:hover{border-color:#a5b4fc;background:#f5f3ff;}
		.cfb-field-row.cfb-selected{border-color:#4f46e5;background:#eef2ff;box-shadow:0 0 0 3px rgba(79,70,229,.1);}
		.cfb-field-row.cfb-cond-tag{border-left:3px solid #f59e0b;}
		.cfb-sortable-ghost{opacity:.4;background:#ddd6fe;}
		.cfb-drag-handle{cursor:grab;color:#94a3b8;font-size:18px;flex-shrink:0;}
		.cfb-drag-handle:active{cursor:grabbing;}
		.cfb-field-icon{font-size:15px;flex-shrink:0;}
		.cfb-field-info{flex:1;min-width:0;}
		.cfb-field-info strong{font-size:13px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
		.cfb-field-info small{font-size:11px;color:#94a3b8;}
		.cfb-type-badge{font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:4px;background:#f1f5f9;color:#64748b;flex-shrink:0;}
		.cfb-width-badge{font-size:9.5px;font-weight:700;padding:2px 5px;border-radius:4px;background:#fef3c7;color:#92400e;flex-shrink:0;margin-right:2px;}
		.cfb-cond-badge{font-size:11px;margin-left:4px;}
		/* ── Settings panel ──────────────────────────────────────── */
		.cfb-settings-col{background:#fff;border-left:1px solid #e2e8f0;padding:0;overflow-y:auto;max-height:calc(100vh - 80px);position:sticky;top:80px;}
		.cfb-settings-header{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#64748b;padding:14px 14px 10px;border-bottom:1px solid #f1f5f9;}
		.cfb-settings-empty{color:#94a3b8;font-size:12px;padding:30px 14px;text-align:center;}
		#cfb-settings-body{padding:12px 14px;}
		.cfs-group{margin-bottom:12px;}
		.cfs-label{display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;}
		.cfs-input{width:100%;box-sizing:border-box;padding:6px 9px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;color:#111;transition:border-color .15s;}
		.cfs-input:focus{border-color:#4f46e5;outline:none;}
		.cfs-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;padding-right:26px;}
		.cfs-toggle{display:flex;align-items:center;gap:7px;font-size:12px;color:#374151;cursor:pointer;}
		.cfs-toggle input[type=checkbox]{width:14px;height:14px;accent-color:#4f46e5;}
		/* Options list */
		.cfb-option-row{display:flex;gap:5px;align-items:center;margin-bottom:5px;}
		.cfb-opt-del{background:none;border:none;color:#f43f5e;cursor:pointer;font-size:18px;padding:0 3px;line-height:1;flex-shrink:0;}
		/* Width pills */
		.cfb-width-pills{display:flex;gap:4px;flex-wrap:wrap;}
		.cfb-width-pill{padding:4px 8px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:5px;font-size:11px;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s;}
		.cfb-width-pill.active,.cfb-width-pill:hover{background:#eef2ff;border-color:#4f46e5;color:#4f46e5;}
		/* Formula */
		.cfs-formula-ta{width:100%;box-sizing:border-box;font-family:'Courier New',monospace;font-size:13px;font-weight:600;padding:10px 12px;border:2px solid #4f46e5;border-radius:8px;background:#0f172a;color:#a5f3fc;resize:vertical;min-height:72px;line-height:1.5;}
		.cfs-formula-ta:focus{outline:none;box-shadow:0 0 0 3px rgba(79,70,229,.3);}
		.cfb-formula-clear-btn{position:absolute;top:7px;right:7px;background:rgba(244,63,94,.15);border:1px solid rgba(244,63,94,.4);color:#f43f5e;border-radius:5px;padding:2px 7px;font-size:11px;font-weight:700;cursor:pointer;}
		.cfb-calc-pad{background:#1e293b;border-radius:10px;padding:12px;border:1px solid #334155;margin-top:8px;}
		.cfb-calc-btn{padding:8px 5px;border-radius:7px;border-width:1px;border-style:solid;cursor:pointer;font-weight:700;font-size:.85rem;font-family:'Courier New',monospace;transition:filter .12s;text-align:center;}
		.cfb-calc-btn:hover{filter:brightness(1.25);}
		/* ── Panel cards ─────────────────────────────────────────── */
		.cfb-panel-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin:0 16px;}
		.cfb-panel-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;cursor:pointer;border-bottom:1px solid transparent;transition:background .15s;}
		.cfb-panel-header:hover{background:#f8fafc;}
		/* Design tabs */
		.cfb-dtabs{display:flex;gap:2px;border-bottom:2px solid #f1f5f9;margin-bottom:18px;}
		.cfb-dtab{padding:7px 14px;font-size:12px;font-weight:600;border:none;background:none;cursor:pointer;color:#64748b;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;border-radius:6px 6px 0 0;}
		.cfb-dtab:hover{color:#4f46e5;}
		.cfb-dtab.active{color:#4f46e5;border-bottom-color:#4f46e5;background:#eef2ff;}
		.cfb-design-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
		.cfb-design-grid-3{grid-template-columns:1fr 1fr 1fr;}
		/* Radio pills in design */
		.cfb-radio-pill{display:inline-flex;align-items:center;gap:5px;font-size:12px;padding:5px 10px;border:1.5px solid #e2e8f0;border-radius:6px;cursor:pointer;background:#fff;transition:all .15s;}
		.cfb-radio-pill:has(input:checked),.cfb-radio-pill.active{border-color:#4f46e5;background:#eef2ff;color:#4f46e5;}
		.cfb-radio-pill input{accent-color:#4f46e5;width:12px;height:12px;}
		/* Calendar cards */
		.cfb-cal-card{transition:border-color .18s,background .18s;}
		.cfb-cal-card:hover{background:#fafafe!important;}
		.cfb-cal-active{border-color:#4f46e5!important;}
		/* Responsive adjustments */
		@media (max-width:1100px){.cfb-layout{grid-template-columns:175px 1fr 240px;}}
		@media (max-width:900px) {.cfb-layout{grid-template-columns:1fr;}.cfb-palette-col,.cfb-settings-col{max-height:none;position:static;}}
		</style>
		<?php
	}
}
