<?php
/**
 * Mailer — outbound email configuration.
 *
 * Modes:
 *   'php'  — WordPress default wp_mail() / PHP mail(). No configuration needed.
 *   'smtp' — Authenticated SMTP relay. Works with every provider on the
 *            SMTP settings page (Microsoft 365 / Outlook, Gmail / Workspace,
 *            Yahoo, iCloud, Zoho, Fastmail, Amazon SES, SendGrid, Mailgun,
 *            Postmark, Brevo, Mailjet, SMTP2GO, or any "Other SMTP" host)
 *            because every one of those providers offers an SMTP endpoint —
 *            we don't need a separate HTTP-API integration per provider.
 *
 * Note on Microsoft 365 / Google "OAuth, no password" connect flows: that
 * requires registering an Azure/Google Cloud app, storing a client ID +
 * secret, running a redirect/consent flow, and refreshing access tokens on
 * a schedule. That's a distinct project from this settings page and is not
 * included here — Microsoft 365 / Gmail both work today via this page using
 * an SMTP app password, which is fully supported below.
 *
 * @package CredoqEngine\Mail
 */

namespace CredoqEngine\Mail;

defined( 'ABSPATH' ) || exit;

class Mailer {

	const OPT = 'credoq_smtp_settings';

	/** @var bool */
	private static $registered = false;

	public static function register() : void {
		if ( self::$registered ) return;
		self::$registered = true;

		// Always hook phpmailer_init; configure_phpmailer() re-reads the
		// setting at send time and no-ops when mode isn't 'smtp', so a
		// mode change takes effect immediately without a fresh page load.
		add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ) );
		add_filter( 'wp_mail_from',      array( __CLASS__, 'filter_from_email' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ) );
	}

	public static function get_settings() : array {
		return wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
	}

	public static function defaults() : array {
		return array(
			'mode'                  => 'php',   // php|smtp
			'provider'              => 'other',
			'host'                  => '',
			'port'                  => 587,
			'encryption'            => 'tls',    // none|tls|ssl
			'username'              => '',
			'password'              => '',
			'from_name'             => get_bloginfo( 'name' ),
			'from_email'            => get_option( 'admin_email' ),
			'notify_admin_email'    => get_option( 'admin_email' ),
			'notify_on_submission'  => 1,
			'confirm_customer'      => 1,
		);
	}

	/** Provider presets: host/port/encryption auto-filled by the picker (still editable). */
	public static function provider_presets() : array {
		return array(
			'microsoft365' => array( 'label' => 'Microsoft 365 / Outlook', 'host' => 'smtp.office365.com',      'port' => 587, 'encryption' => 'tls' ),
			'gmail'        => array( 'label' => 'Gmail / Workspace',       'host' => 'smtp.gmail.com',          'port' => 587, 'encryption' => 'tls' ),
			'yahoo'        => array( 'label' => 'Yahoo Mail',              'host' => 'smtp.mail.yahoo.com',     'port' => 587, 'encryption' => 'tls' ),
			'icloud'       => array( 'label' => 'iCloud Mail',             'host' => 'smtp.mail.me.com',        'port' => 587, 'encryption' => 'tls' ),
			'zoho'         => array( 'label' => 'Zoho Mail',               'host' => 'smtp.zoho.com',           'port' => 587, 'encryption' => 'tls' ),
			'fastmail'     => array( 'label' => 'Fastmail',                'host' => 'smtp.fastmail.com',       'port' => 587, 'encryption' => 'tls' ),
			'ses'          => array( 'label' => 'Amazon SES (SMTP)',       'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'encryption' => 'tls' ),
			'sendgrid'     => array( 'label' => 'SendGrid',                'host' => 'smtp.sendgrid.net',       'port' => 587, 'encryption' => 'tls' ),
			'mailgun'      => array( 'label' => 'Mailgun',                 'host' => 'smtp.mailgun.org',        'port' => 587, 'encryption' => 'tls' ),
			'postmark'     => array( 'label' => 'Postmark',                'host' => 'smtp.postmarkapp.com',    'port' => 587, 'encryption' => 'tls' ),
			'brevo'        => array( 'label' => 'Brevo',                   'host' => 'smtp-relay.brevo.com',    'port' => 587, 'encryption' => 'tls' ),
			'mailjet'      => array( 'label' => 'Mailjet',                 'host' => 'in-v3.mailjet.com',       'port' => 587, 'encryption' => 'tls' ),
			'smtp2go'      => array( 'label' => 'SMTP2GO',                 'host' => 'mail.smtp2go.com',        'port' => 587, 'encryption' => 'tls' ),
			'other'        => array( 'label' => 'Other SMTP',              'host' => '',                        'port' => 587, 'encryption' => 'tls' ),
		);
	}

	/* ── wp_mail wiring ───────────────────────────────────────────────── */

	public static function configure_phpmailer( $phpmailer ) : void {
		$s = self::get_settings();
		if ( 'smtp' !== ( $s['mode'] ?? 'php' ) || empty( $s['host'] ) ) return;

		$phpmailer->isSMTP();
		$phpmailer->Host       = $s['host'];
		$phpmailer->Port       = (int) $s['port'];
		$phpmailer->SMTPAuth   = ! empty( $s['username'] );
		$phpmailer->Username   = $s['username'];
		$phpmailer->Password   = $s['password'];
		$phpmailer->SMTPSecure = in_array( $s['encryption'], array( 'tls', 'ssl' ), true ) ? $s['encryption'] : '';
		if ( 'none' === $s['encryption'] || '' === $s['encryption'] ) {
			$phpmailer->SMTPAutoTLS = false;
		}
	}

	public static function filter_from_email( $email ) {
		$s = self::get_settings();
		return ! empty( $s['from_email'] ) && is_email( $s['from_email'] ) ? $s['from_email'] : $email;
	}

	public static function filter_from_name( $name ) {
		$s = self::get_settings();
		return ! empty( $s['from_name'] ) ? $s['from_name'] : $name;
	}

	/* ── Verify / send-test (used by the SMTP settings page) ─────────── */

	/**
	 * Attempt an SMTP handshake using the given (not-yet-saved) settings.
	 * Sends nothing. Returns array( 'ok' => bool, 'error' => string ).
	 */
	public static function verify_connection( array $s ) : array {
		if ( 'php' === ( $s['mode'] ?? 'php' ) ) {
			return array( 'ok' => true, 'error' => '' );
		}
		if ( empty( $s['host'] ) ) {
			return array( 'ok' => false, 'error' => __( 'Host is required.', 'credoq-engine' ) );
		}

		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		$mail = new \PHPMailer\PHPMailer\PHPMailer( true );
		try {
			$mail->isSMTP();
			$mail->Host       = $s['host'];
			$mail->Port       = (int) $s['port'];
			$mail->SMTPAuth   = ! empty( $s['username'] );
			$mail->Username   = $s['username'];
			$mail->Password   = $s['password'];
			$mail->SMTPSecure = in_array( $s['encryption'], array( 'tls', 'ssl' ), true ) ? $s['encryption'] : '';
			$mail->Timeout    = 12;
			$mail->SMTPDebug  = 0;

			if ( ! $mail->smtpConnect() ) {
				return array( 'ok' => false, 'error' => __( 'Could not connect to the SMTP server.', 'credoq-engine' ) );
			}
			$mail->smtpClose();
			return array( 'ok' => true, 'error' => '' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Send a real test email using the CURRENTLY SAVED settings.
	 */
	public static function send_test( string $to ) : array {
		if ( ! is_email( $to ) ) {
			return array( 'ok' => false, 'error' => __( 'Enter a valid email address.', 'credoq-engine' ) );
		}
		$s        = self::get_settings();
		$subject  = sprintf( '[%s] Credoq SMTP test', get_bloginfo( 'name' ) );
		$body     = sprintf(
			"This is a test message from the Credoq SMTP settings page.\n\nMode: %s\nHost: %s\nSent: %s",
			$s['mode'],
			$s['host'] ?: '(default PHP mail)',
			current_time( 'mysql' )
		);

		$captured_error = '';
		$capture = function ( $wp_error ) use ( &$captured_error ) {
			$captured_error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, $subject, $body );
		remove_action( 'wp_mail_failed', $capture );

		return array( 'ok' => (bool) $sent, 'error' => $sent ? '' : ( $captured_error ?: __( 'wp_mail() returned false.', 'credoq-engine' ) ) );
	}

	/**
	 * Send using currently saved settings — used by Submission_Notifier.
	 * Returns array( 'ok' => bool, 'error' => string ).
	 */
	public static function send( string $to, string $subject, string $body, array $headers = array() ) : array {
		$captured_error = '';
		$capture = function ( $wp_error ) use ( &$captured_error ) {
			$captured_error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $capture );

		return array( 'ok' => (bool) $sent, 'error' => $sent ? '' : $captured_error );
	}
}
