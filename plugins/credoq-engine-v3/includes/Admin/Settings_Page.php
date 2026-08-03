<?php
/**
 * Settings admin page — General + Security tabs.
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

defined( 'ABSPATH' ) || exit;

class Settings_Page {

	const OPT = 'credoq_engine_settings';

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		$saved = false;
		if ( isset( $_POST['credoq_save_settings'] ) && check_admin_referer( 'credoq_save_settings' ) ) {
			$cur = get_option( self::OPT, array() );

			// ── General ──────────────────────────────────────────────
			$cur['currency']    = sanitize_text_field( $_POST['currency']    ?? 'USD' );
			$cur['date_format'] = sanitize_text_field( $_POST['date_format'] ?? 'Y-m-d' );
			$cur['time_format'] = sanitize_text_field( $_POST['time_format'] ?? 'H:i' );
			$cur['debug_mode']  = ! empty( $_POST['debug_mode'] ) ? 1 : 0;

			// ── Security: IP block ──────────────────────────────────
			$cur['ip_block_enabled'] = ! empty( $_POST['ip_block_enabled'] ) ? 1 : 0;
			$cur['ip_blocklist']     = sanitize_textarea_field( wp_unslash( $_POST['ip_blocklist'] ?? '' ) );

			// ── Security: Country block ─────────────────────────────
			$cur['country_block_enabled'] = ! empty( $_POST['country_block_enabled'] ) ? 1 : 0;
			$cur['country_blocklist']     = strtoupper( sanitize_text_field( wp_unslash( $_POST['country_blocklist'] ?? '' ) ) );

			// ── Security: reCAPTCHA ─────────────────────────────────
			$cur['recaptcha_enabled']      = ! empty( $_POST['recaptcha_enabled'] ) ? 1 : 0;
			$cur['recaptcha_version']      = ( $_POST['recaptcha_version'] ?? 'v2' ) === 'v3' ? 'v3' : 'v2';
			$cur['recaptcha_site_key']     = sanitize_text_field( $_POST['recaptcha_site_key']   ?? '' );
			$cur['recaptcha_secret_key']   = sanitize_text_field( $_POST['recaptcha_secret_key'] ?? '' );
			$cur['recaptcha_v3_threshold'] = max( 0, min( 1, (float) ( $_POST['recaptcha_v3_threshold'] ?? 0.5 ) ) );

			update_option( self::OPT, $cur );
			// Sync the debug mode option separately so credoq_log() can read it cheaply.
			update_option( 'credoq_debug_mode', $cur['debug_mode'] );
			$saved = true;
		}
		$s = get_option( self::OPT, array() );

		$tab = sanitize_key( $_GET['tab'] ?? 'general' );
		if ( ! in_array( $tab, array( 'general', 'security' ), true ) ) $tab = 'general';
		?>
		<div class="wrap credoq-admin-wrap">

			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-admin-settings" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
						<?php esc_html_e( 'Settings', 'credoq-engine' ); ?>
					</h1>
				</div>
			</div>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'credoq-engine' ); ?></p></div>
			<?php endif; ?>

			<!-- Tab nav -->
			<div class="credoq-reports-tabs">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'credoq-settings', 'tab' => 'general' ), admin_url( 'admin.php' ) ) ); ?>"
				   class="<?php echo $tab === 'general' ? 'active' : ''; ?>">
					<span class="dashicons dashicons-admin-generic" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
					<?php esc_html_e( 'General', 'credoq-engine' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'credoq-settings', 'tab' => 'security' ), admin_url( 'admin.php' ) ) ); ?>"
				   class="<?php echo $tab === 'security' ? 'active' : ''; ?>">
					<span class="dashicons dashicons-shield" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
					<?php esc_html_e( 'Security', 'credoq-engine' ); ?>
				</a>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'credoq_save_settings' ); ?>
				<input type="hidden" name="tab_return" value="<?php echo esc_attr( $tab ); ?>">

				<?php if ( 'general' === $tab ) : ?>
					<div class="credoq-card">
						<h2 class="credoq-section-title"><?php esc_html_e( 'General', 'credoq-engine' ); ?></h2>
						<table class="form-table">
							<tr>
								<th><?php esc_html_e( 'Currency', 'credoq-engine' ); ?></th>
								<td><input type="text" name="currency" maxlength="3" value="<?php echo esc_attr( $s['currency'] ?? 'USD' ); ?>" class="regular-text"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Date format', 'credoq-engine' ); ?></th>
								<td><input type="text" name="date_format" value="<?php echo esc_attr( $s['date_format'] ?? 'Y-m-d' ); ?>" class="regular-text"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Time format', 'credoq-engine' ); ?></th>
								<td><input type="text" name="time_format" value="<?php echo esc_attr( $s['time_format'] ?? 'H:i' ); ?>" class="regular-text"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Debug mode', 'credoq-engine' ); ?></th>
								<td>
									<label><input type="checkbox" name="debug_mode" value="1" <?php checked( ! empty( $s['debug_mode'] ) ); ?>>
										<?php esc_html_e( 'Write extra info to debug.log (requires WP_DEBUG_LOG)', 'credoq-engine' ); ?></label>
								</td>
							</tr>
						</table>
					</div>

				<?php else : // security tab ?>

					<div class="credoq-card">
						<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
							<div style="background:#dc2626;border-radius:10px;width:40px;height:40px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
								<span class="dashicons dashicons-dismiss" style="color:#fff;font-size:18px;width:18px;height:18px;"></span>
							</div>
							<div>
								<h2 class="credoq-section-title" style="margin:0 0 2px;border:none;padding:0;"><?php esc_html_e( 'IP Block', 'credoq-engine' ); ?></h2>
								<div style="font-size:12px;color:#64748b;"><?php esc_html_e( 'Block specific IP addresses from submitting any form.', 'credoq-engine' ); ?></div>
							</div>
							<label class="credoq-switch" style="margin-left:auto;">
								<input type="checkbox" name="ip_block_enabled" value="1" <?php checked( ! empty( $s['ip_block_enabled'] ) ); ?>>
								<span class="credoq-switch-track"><span class="credoq-switch-thumb"></span></span>
							</label>
						</div>
						<label class="credoq-field-label"><?php esc_html_e( 'Blocked IP addresses', 'credoq-engine' ); ?></label>
						<textarea name="ip_blocklist" rows="5" class="large-text code"
							placeholder="203.0.113.5&#10;198.51.100.*&#10;192.0.2.10"><?php echo esc_textarea( $s['ip_blocklist'] ?? '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line. Use a trailing * to block a whole range, e.g. 203.0.113.* blocks 203.0.113.0–255.', 'credoq-engine' ); ?></p>
					</div>

					<div class="credoq-card">
						<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
							<div style="background:#7c3aed;border-radius:10px;width:40px;height:40px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
								<span class="dashicons dashicons-admin-site-alt3" style="color:#fff;font-size:18px;width:18px;height:18px;"></span>
							</div>
							<div>
								<h2 class="credoq-section-title" style="margin:0 0 2px;border:none;padding:0;"><?php esc_html_e( 'Country Block', 'credoq-engine' ); ?></h2>
								<div style="font-size:12px;color:#64748b;"><?php esc_html_e( 'Block submissions originating from specific countries (via IP geolocation).', 'credoq-engine' ); ?></div>
							</div>
							<label class="credoq-switch" style="margin-left:auto;">
								<input type="checkbox" name="country_block_enabled" value="1" <?php checked( ! empty( $s['country_block_enabled'] ) ); ?>>
								<span class="credoq-switch-track"><span class="credoq-switch-thumb"></span></span>
							</label>
						</div>
						<label class="credoq-field-label"><?php esc_html_e( 'Blocked countries (ISO codes)', 'credoq-engine' ); ?></label>
						<input type="text" name="country_blocklist" class="large-text"
							value="<?php echo esc_attr( $s['country_blocklist'] ?? '' ); ?>" placeholder="RU, KP, CN">
						<p class="description">
							<?php esc_html_e( 'Comma-separated two-letter country codes (ISO 3166-1 alpha-2). Country is resolved from the submitter\'s IP via a free lookup, cached for 7 days per IP.', 'credoq-engine' ); ?>
						</p>
					</div>

					<div class="credoq-card">
						<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
							<div style="background:#0891b2;border-radius:10px;width:40px;height:40px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
								<span class="dashicons dashicons-lock" style="color:#fff;font-size:18px;width:18px;height:18px;"></span>
							</div>
							<div>
								<h2 class="credoq-section-title" style="margin:0 0 2px;border:none;padding:0;"><?php esc_html_e( 'Google reCAPTCHA', 'credoq-engine' ); ?></h2>
								<div style="font-size:12px;color:#64748b;"><?php esc_html_e( 'Stop bots from submitting your forms.', 'credoq-engine' ); ?></div>
							</div>
							<label class="credoq-switch" style="margin-left:auto;">
								<input type="checkbox" name="recaptcha_enabled" value="1" id="cq-recaptcha-enabled" <?php checked( ! empty( $s['recaptcha_enabled'] ) ); ?>>
								<span class="credoq-switch-track"><span class="credoq-switch-thumb"></span></span>
							</label>
						</div>

						<div id="cq-recaptcha-body" style="<?php echo empty( $s['recaptcha_enabled'] ) ? 'display:none;' : ''; ?>">
							<div class="credoq-settings-grid" style="margin-bottom:0;">
								<div>
									<label class="credoq-field-label"><?php esc_html_e( 'Version', 'credoq-engine' ); ?></label>
									<select name="recaptcha_version" class="regular-text">
										<option value="v2" <?php selected( ( $s['recaptcha_version'] ?? 'v2' ), 'v2' ); ?>><?php esc_html_e( 'v2 — “I\'m not a robot” checkbox', 'credoq-engine' ); ?></option>
										<option value="v3" <?php selected( ( $s['recaptcha_version'] ?? 'v2' ), 'v3' ); ?>><?php esc_html_e( 'v3 — Invisible, score-based', 'credoq-engine' ); ?></option>
									</select>
								</div>
								<div>
									<label class="credoq-field-label"><?php esc_html_e( 'Score threshold (v3 only)', 'credoq-engine' ); ?></label>
									<input type="number" name="recaptcha_v3_threshold" min="0" max="1" step="0.1" class="regular-text"
										value="<?php echo esc_attr( $s['recaptcha_v3_threshold'] ?? 0.5 ); ?>">
									<p class="description"><?php esc_html_e( '0 = very lenient, 1 = very strict. Google recommends 0.5.', 'credoq-engine' ); ?></p>
								</div>
								<div>
									<label class="credoq-field-label"><?php esc_html_e( 'Site Key', 'credoq-engine' ); ?></label>
									<input type="text" name="recaptcha_site_key" class="regular-text"
										value="<?php echo esc_attr( $s['recaptcha_site_key'] ?? '' ); ?>">
								</div>
								<div>
									<label class="credoq-field-label"><?php esc_html_e( 'Secret Key', 'credoq-engine' ); ?></label>
									<input type="password" name="recaptcha_secret_key" class="regular-text"
										value="<?php echo esc_attr( $s['recaptcha_secret_key'] ?? '' ); ?>" autocomplete="new-password">
								</div>
							</div>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to Google reCAPTCHA admin console */
									esc_html__( 'Get your keys from %s', 'credoq-engine' ),
									'<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">google.com/recaptcha/admin</a>'
								);
								?>
							</p>
						</div>
					</div>

					<script>
					document.getElementById('cq-recaptcha-enabled').addEventListener('change', function () {
						document.getElementById('cq-recaptcha-body').style.display = this.checked ? '' : 'none';
					});
					</script>

				<?php endif; ?>

				<p>
					<button class="button button-primary" name="credoq_save_settings" value="1">
						<?php esc_html_e( 'Save Settings', 'credoq-engine' ); ?>
					</button>
				</p>
			</form>
		</div>

		<style>
		.credoq-field-label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 6px; }
		.credoq-switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
		.credoq-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
		.credoq-switch-track {
			position: absolute; inset: 0; background: #cbd5e1; border-radius: 11px; cursor: pointer; transition: background .2s;
		}
		.credoq-switch-thumb {
			position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; background: #fff; border-radius: 50%;
			box-shadow: 0 1px 3px rgba(0,0,0,.2); transition: left .2s;
		}
		.credoq-switch input:checked + .credoq-switch-track { background: #16a34a; }
		.credoq-switch input:checked + .credoq-switch-track .credoq-switch-thumb { left: 21px; }
		</style>
		<?php
	}
}
