<?php
/**
 * Submission_Notifier — reacts to 'credoq_after_submission'.
 *
 * For every new submission it:
 *   1. Generates a short reference code (SUB-XXXXXXXX) and stores it on the row.
 *   2. Emails the site admin (if enabled in SMTP settings).
 *   3. Emails the submitter a confirmation (if enabled and an email was collected).
 *   4. Creates a bell notification row.
 *   5. Writes 'forms.submitted' and 'mail.send' entries to the audit log.
 *
 * Entirely additive — does not modify Forms\Submission_Handler.
 *
 * @package CredoqEngine\Mail
 */

namespace CredoqEngine\Mail;

use CredoqEngine\Forms\Form;
use CredoqEngine\Log\Audit_Log;

defined( 'ABSPATH' ) || exit;

class Submission_Notifier {

	/** @var bool */
	private static $registered = false;

	public static function register() : void {
		if ( self::$registered ) return;
		self::$registered = true;
		add_action( 'credoq_after_submission', array( __CLASS__, 'handle' ), 10, 5 );
	}

	/**
	 * @param int    $submission_id
	 * @param Form   $form
	 * @param array  $sanitized
	 * @param array  $addon_payload
	 * @param array  $context
	 */
	public static function handle( int $submission_id, $form, array $sanitized, array $addon_payload, array $context ) : void {
		global $wpdb;

		$s = Mailer::get_settings();

		// 1. Reference code — stable, short, matches the SUB-XXXXXXXX style.
		$ref = 'SUB-' . strtoupper( substr( md5( $submission_id . '|' . get_option( 'auth_key', 'credoq' ) ), 0, 8 ) );
		$wpdb->update(
			$wpdb->prefix . 'credoq_submissions',
			array( 'ref' => $ref ),
			array( 'id' => $submission_id ),
			array( '%s' ),
			array( '%d' )
		);

		$form_title    = is_object( $form ) && isset( $form->title ) ? $form->title : ( '#' . $submission_id );
		$customer_email = sanitize_email( $sanitized['email'] ?? ( $context['user_email'] ?? '' ) );

		Audit_Log::record( 'forms.submitted', array(
			'subject' => (string) $submission_id,
			'message' => 'form_id=' . ( is_object( $form ) ? (int) $form->id : 0 ) . ' ref=' . $ref,
		) );

		$driver = 'php' === ( $s['mode'] ?? 'php' ) ? 'php' : 'smtp';

		// 2. Admin notification email.
		if ( ! empty( $s['notify_on_submission'] ) && ! empty( $s['notify_admin_email'] ) ) {
			$admin_to      = $s['notify_admin_email'];
			$admin_subject = sprintf( 'New submission · %s (%s)', $form_title, $ref );
			$admin_body    = self::build_admin_body( $form_title, $ref, $sanitized, $submission_id );

			$result = Mailer::send( $admin_to, $admin_subject, $admin_body );

			Audit_Log::record( 'mail.send', array(
				'subject' => $admin_to,
				'message' => 'subject=' . $admin_subject . ' · ok=' . ( $result['ok'] ? 1 : 0 )
					. ' · driver=' . $driver . ' · error=' . $result['error'],
			) );
		}

		// 3. Customer confirmation email.
		if ( ! empty( $s['confirm_customer'] ) && $customer_email ) {
			$cust_subject = 'Thank you for your submission';
			$cust_body    = self::build_customer_body( $form_title, $ref );

			$result = Mailer::send( $customer_email, $cust_subject, $cust_body );

			Audit_Log::record( 'mail.send', array(
				'subject' => $customer_email,
				'message' => 'subject=' . $cust_subject . ' · ok=' . ( $result['ok'] ? 1 : 0 )
					. ' · driver=' . $driver . ' · error=' . $result['error'],
			) );
		}

		// 4. Bell notification.
		Notifications::create(
			'submission',
			sprintf( 'New submission · %s', $ref ),
			sprintf( '%s submitted "%s"', $customer_email ?: __( 'Guest', 'credoq-engine' ), $form_title ),
			admin_url( 'admin.php?page=credoq-submissions&id=' . $submission_id ),
			$ref
		);
	}

	private static function build_admin_body( string $form_title, string $ref, array $sanitized, int $submission_id ) : string {
		$lines = array(
			sprintf( 'A new submission (%s) was received for "%s".', $ref, $form_title ),
			'',
			'View it here: ' . admin_url( 'admin.php?page=credoq-submissions&id=' . $submission_id ),
			'',
			'Submitted data:',
		);
		foreach ( $sanitized as $key => $value ) {
			if ( is_array( $value ) ) $value = implode( ', ', $value );
			$lines[] = '- ' . $key . ': ' . $value;
		}
		return implode( "\n", $lines );
	}

	private static function build_customer_body( string $form_title, string $ref ) : string {
		return sprintf(
			"Thank you for your submission.\n\nReference: %s\nForm: %s\n\nWe'll be in touch shortly.",
			$ref,
			$form_title
		);
	}
}
