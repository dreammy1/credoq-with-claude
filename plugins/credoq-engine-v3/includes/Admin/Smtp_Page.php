<?php
/**
 * SMTP admin page — outbound email provider + delivery test.
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

use CredoqEngine\Mail\Mailer;
use CredoqEngine\Log\Audit_Log;

defined( 'ABSPATH' ) || exit;

class Smtp_Page {

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		$notice       = '';
		$notice_type  = 'success';
		$verify_result = null;
		$test_result   = null;

		// ── Save (also runs before Verify / Send test so those act on the
		//    values currently in the form, not stale saved ones). ────────
		if ( isset( $_POST['credoq_smtp_action'] ) && check_admin_referer( 'credoq_save_smtp' ) ) {

			$cur = array(
				'mode'                 => in_array( ( $_POST['mode'] ?? 'php' ), array( 'php', 'smtp' ), true ) ? $_POST['mode'] : 'php',
				'provider'             => sanitize_key( $_POST['provider'] ?? 'other' ),
				'host'                 => sanitize_text_field( wp_unslash( $_POST['host'] ?? '' ) ),
				'port'                 => absint( $_POST['port'] ?? 587 ),
				'encryption'           => in_array( ( $_POST['encryption'] ?? 'tls' ), array( 'none', 'tls', 'ssl' ), true ) ? $_POST['encryption'] : 'tls',
				'username'             => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
				'from_name'            => sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ),
				'from_email'           => sanitize_email( $_POST['from_email'] ?? '' ),
				'notify_admin_email'   => sanitize_email( $_POST['notify_admin_email'] ?? '' ),
				'notify_on_submission' => ! empty( $_POST['notify_on_submission'] ) ? 1 : 0,
				'confirm_customer'     => ! empty( $_POST['confirm_customer'] ) ? 1 : 0,
			);

			// Password: leave the saved one alone if the field was left blank.
			$existing = Mailer::get_settings();
			$posted_password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
			$cur['password'] = '' !== $posted_password ? $posted_password : ( $existing['password'] ?? '' );

			update_option( Mailer::OPT, $cur );
			$notice = __( 'SMTP settings saved.', 'credoq-engine' );

			$action = sanitize_key( $_POST['credoq_smtp_action'] );

			if ( 'verify' === $action ) {
				$verify_result = Mailer::verify_connection( $cur );
				Audit_Log::record( 'smtp.verify', array(
					'subject' => $cur['host'] ?: 'php-mail',
					'message' => 'ok=' . ( $verify_result['ok'] ? 1 : 0 ) . ( $verify_result['error'] ? ' error=' . $verify_result['error'] : '' ),
				) );
				if ( ! $verify_result['ok'] ) { $notice = $verify_result['error']; $notice_type = 'error'; }

			} elseif ( 'send_test' === $action ) {
				$send_to      = sanitize_email( $_POST['send_to'] ?? '' );
				$test_result  = Mailer::send_test( $send_to );
				Audit_Log::record( 'mail.send', array(
					'subject' => $send_to,
					'message' => 'subject=Credoq SMTP test · ok=' . ( $test_result['ok'] ? 1 : 0 )
						. ' · driver=' . ( 'smtp' === $cur['mode'] ? 'smtp' : 'php' )
						. ' · error=' . $test_result['error'],
				) );
				if ( ! $test_result['ok'] ) { $notice = $test_result['error'] ?: __( 'Test email failed to send.', 'credoq-engine' ); $notice_type = 'error'; }
				else { $notice = sprintf( __( 'Test email sent to %s.', 'credoq-engine' ), $send_to ); }
			}
		}

		$s        = Mailer::get_settings();
		$presets  = Mailer::provider_presets();
		?>
		<div class="wrap credoq-admin-wrap">

			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-email-alt" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
						<?php esc_html_e( 'SMTP', 'credoq-engine' ); ?>
					</h1>
				</div>
			</div>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<!-- Status strip -->
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px;">
				<div class="credoq-card" style="padding:14px 16px;">
					<div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">Delivery mode</div>
					<div style="font-size:15px;font-weight:800;color:#1e293b;margin-top:4px;">
						<?php echo 'smtp' === $s['mode'] ? esc_html( $presets[ $s['provider'] ]['label'] ?? 'SMTP' ) : esc_html__( 'Default (PHP mail)', 'credoq-engine' ); ?>
					</div>
				</div>
				<div class="credoq-card" style="padding:14px 16px;">
					<div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">From address</div>
					<div style="font-size:15px;font-weight:800;color:#1e293b;margin-top:4px;"><?php echo esc_html( $s['from_email'] ?: '—' ); ?></div>
				</div>
				<div class="credoq-card" style="padding:14px 16px;">
					<div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">Admin notify</div>
					<div style="font-size:15px;font-weight:800;color:#1e293b;margin-top:4px;"><?php echo $s['notify_on_submission'] ? esc_html__( 'On', 'credoq-engine' ) : esc_html__( 'Off', 'credoq-engine' ); ?></div>
				</div>
			</div>

			<form method="post" id="cq-smtp-form">
				<?php wp_nonce_field( 'credoq_save_smtp' ); ?>

				<div class="credoq-card">
					<h2 class="credoq-section-title"><?php esc_html_e( 'Choose your mailer', 'credoq-engine' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Pick how emails are sent. Leave "Default (PHP mail)" if you don\'t have SMTP credentials — it uses your server\'s built-in mail() function.', 'credoq-engine' ); ?></p>

					<div class="cq-provider-grid">
						<div class="cq-provider-card <?php echo 'php' === $s['mode'] ? 'is-active' : ''; ?>" data-mode="php" data-provider="other">
							<div class="cq-provider-name">🐘 <?php esc_html_e( 'Default (PHP mail)', 'credoq-engine' ); ?></div>
						</div>
						<?php foreach ( $presets as $key => $p ) : ?>
						<div class="cq-provider-card <?php echo ( 'smtp' === $s['mode'] && $s['provider'] === $key ) ? 'is-active' : ''; ?>"
						     data-mode="smtp" data-provider="<?php echo esc_attr( $key ); ?>"
						     data-host="<?php echo esc_attr( $p['host'] ); ?>" data-port="<?php echo esc_attr( $p['port'] ); ?>" data-encryption="<?php echo esc_attr( $p['encryption'] ); ?>">
							<div class="cq-provider-name"><?php echo esc_html( $p['label'] ); ?></div>
						</div>
						<?php endforeach; ?>
					</div>

					<input type="hidden" name="mode" id="cq-mode" value="<?php echo esc_attr( $s['mode'] ); ?>">
					<input type="hidden" name="provider" id="cq-provider" value="<?php echo esc_attr( $s['provider'] ); ?>">
				</div>

				<div class="credoq-card" id="cq-smtp-fields" style="<?php echo 'smtp' === $s['mode'] ? '' : 'display:none;'; ?>">
					<h2 class="credoq-section-title"><?php esc_html_e( 'SMTP connection', 'credoq-engine' ); ?></h2>
					<div class="credoq-settings-grid">
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'Host', 'credoq-engine' ); ?></label>
							<input type="text" name="host" id="cq-host" class="regular-text" value="<?php echo esc_attr( $s['host'] ); ?>" placeholder="smtp.example.com">
						</div>
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'Port', 'credoq-engine' ); ?></label>
							<input type="number" name="port" id="cq-port" class="regular-text" value="<?php echo esc_attr( $s['port'] ); ?>">
						</div>
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'Encryption', 'credoq-engine' ); ?></label>
							<select name="encryption" id="cq-encryption" class="regular-text">
								<option value="tls"  <?php selected( $s['encryption'], 'tls' ); ?>>TLS (STARTTLS)</option>
								<option value="ssl"  <?php selected( $s['encryption'], 'ssl' ); ?>>SSL</option>
								<option value="none" <?php selected( $s['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'credoq-engine' ); ?></option>
							</select>
						</div>
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'Username', 'credoq-engine' ); ?></label>
							<input type="text" name="username" class="regular-text" value="<?php echo esc_attr( $s['username'] ); ?>" autocomplete="off">
						</div>
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'Password / App password', 'credoq-engine' ); ?></label>
							<input type="password" name="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep the saved password', 'credoq-engine' ); ?>">
						</div>
					</div>
					<p class="description">
						<?php esc_html_e( 'Microsoft 365 / Gmail: use an app password here, not your normal login password. Full "connect with one click, no password" OAuth is a separate, larger feature — this SMTP method works today for every provider above.', 'credoq-engine' ); ?>
					</p>
				</div>

				<div class="credoq-card">
					<h2 class="credoq-section-title"><?php esc_html_e( 'Sender identity', 'credoq-engine' ); ?></h2>
					<div class="credoq-settings-grid">
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'From name', 'credoq-engine' ); ?></label>
							<input type="text" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>">
						</div>
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'From email', 'credoq-engine' ); ?></label>
							<input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>">
						</div>
					</div>
				</div>

				<div class="credoq-card">
					<h2 class="credoq-section-title"><?php esc_html_e( 'Submission notifications', 'credoq-engine' ); ?></h2>
					<div class="credoq-settings-grid">
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'Notify admin email', 'credoq-engine' ); ?></label>
							<input type="email" name="notify_admin_email" class="regular-text" value="<?php echo esc_attr( $s['notify_admin_email'] ); ?>">
						</div>
						<div>
							<label class="credoq-field-label"><?php esc_html_e( 'On new submission', 'credoq-engine' ); ?></label>
							<label style="display:block;margin-top:6px;"><input type="checkbox" name="notify_on_submission" value="1" <?php checked( ! empty( $s['notify_on_submission'] ) ); ?>> <?php esc_html_e( 'Email the admin above', 'credoq-engine' ); ?></label>
							<label style="display:block;margin-top:4px;"><input type="checkbox" name="confirm_customer" value="1" <?php checked( ! empty( $s['confirm_customer'] ) ); ?>> <?php esc_html_e( 'Email the customer a confirmation', 'credoq-engine' ); ?></label>
						</div>
					</div>
				</div>

				<p>
					<button class="button button-primary" name="credoq_smtp_action" value="save"><?php esc_html_e( 'Save changes', 'credoq-engine' ); ?></button>
				</p>

				<div class="credoq-card">
					<h2 class="credoq-section-title"><?php esc_html_e( 'Test delivery', 'credoq-engine' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Saves your changes above, then runs the check. Verify tries the SMTP handshake without sending anything; Send test dispatches a real message.', 'credoq-engine' ); ?></p>
					<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:12px;">
						<div>
							<div style="font-weight:700;margin-bottom:8px;"><?php esc_html_e( '1. Verify connection', 'credoq-engine' ); ?></div>
							<button class="button" name="credoq_smtp_action" value="verify"><?php esc_html_e( 'Verify', 'credoq-engine' ); ?></button>
							<?php if ( $verify_result ) : ?>
								<p style="margin-top:8px;color:<?php echo $verify_result['ok'] ? '#16a34a' : '#dc2626'; ?>;font-weight:700;">
									<?php echo $verify_result['ok'] ? esc_html__( '✓ Connected successfully.', 'credoq-engine' ) : esc_html( '✗ ' . $verify_result['error'] ); ?>
								</p>
							<?php endif; ?>
						</div>
						<div>
							<div style="font-weight:700;margin-bottom:8px;"><?php esc_html_e( '2. Send test email', 'credoq-engine' ); ?></div>
							<input type="email" name="send_to" class="regular-text" placeholder="<?php esc_attr_e( 'you@example.com', 'credoq-engine' ); ?>" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" style="margin-bottom:8px;display:block;">
							<button class="button" name="credoq_smtp_action" value="send_test"><?php esc_html_e( 'Send test', 'credoq-engine' ); ?></button>
							<?php if ( $test_result ) : ?>
								<p style="margin-top:8px;color:<?php echo $test_result['ok'] ? '#16a34a' : '#dc2626'; ?>;font-weight:700;">
									<?php echo $test_result['ok'] ? esc_html__( '✓ Sent.', 'credoq-engine' ) : esc_html( '✗ ' . $test_result['error'] ); ?>
								</p>
							<?php endif; ?>
						</div>
					</div>
				</div>

			</form>
		</div>

		<style>
		.cq-provider-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin-top: 14px; }
		.cq-provider-card { border: 2px solid #e2e8f0; border-radius: 10px; padding: 16px 10px; text-align: center; cursor: pointer; background: #fff; transition: border-color .15s, box-shadow .15s; }
		.cq-provider-card:hover { border-color: #94a3b8; }
		.cq-provider-card.is-active { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.12); }
		.cq-provider-name { font-size: 13px; font-weight: 700; color: #1e293b; }
		.credoq-field-label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 6px; }
		.credoq-settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap: 16px; margin-top: 14px; }
		</style>

		<script>
		(function () {
			var cards      = document.querySelectorAll('.cq-provider-card');
			var modeInput  = document.getElementById('cq-mode');
			var provInput  = document.getElementById('cq-provider');
			var fieldsWrap = document.getElementById('cq-smtp-fields');
			var hostEl     = document.getElementById('cq-host');
			var portEl     = document.getElementById('cq-port');
			var encEl      = document.getElementById('cq-encryption');

			cards.forEach(function (card) {
				card.addEventListener('click', function () {
					cards.forEach(function (c) { c.classList.remove('is-active'); });
					card.classList.add('is-active');

					var mode = card.getAttribute('data-mode');
					modeInput.value = mode;
					provInput.value = card.getAttribute('data-provider');
					fieldsWrap.style.display = (mode === 'smtp') ? '' : 'none';

					if (mode === 'smtp') {
						var host = card.getAttribute('data-host');
						if (host) hostEl.value = host;
						var port = card.getAttribute('data-port');
						if (port) portEl.value = port;
						var enc = card.getAttribute('data-encryption');
						if (enc) encEl.value = enc;
					}
				});
			});
		})();
		</script>
		<?php
	}
}
