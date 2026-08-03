<?php
/**
 * Security\Gate — IP block, country block, and reCAPTCHA verification
 * for form submissions.
 *
 * Settings live in the same 'credoq_engine_settings' option Settings_Page
 * already manages, under these keys:
 *
 *   ip_block_enabled        (bool)
 *   ip_blocklist             (string, one entry per line — exact IP or
 *                             wildcard prefix like 203.0.113.*)
 *   country_block_enabled   (bool)
 *   country_blocklist        (string, comma/newline separated ISO-3166
 *                             alpha-2 codes, e.g. "RU, KP, CN")
 *   recaptcha_enabled       (bool)
 *   recaptcha_version       ('v2' | 'v3')
 *   recaptcha_site_key      (string)
 *   recaptcha_secret_key    (string)
 *   recaptcha_v3_threshold  (float, default 0.5)
 *
 * @package CredoqEngine\Security
 */

namespace CredoqEngine\Security;

defined( 'ABSPATH' ) || exit;

class Gate {

	const OPT = 'credoq_engine_settings';

	/**
	 * Run every enabled security check. Returns true if the submission
	 * may proceed, or a WP_Error describing why it was blocked.
	 *
	 * @param array $context  Same $context passed to Submission_Handler::process().
	 * @param array $payload  Raw submitted payload (for the reCAPTCHA token).
	 * @return true|\WP_Error
	 */
	public static function check( array $context, array $payload ) {
		$s = get_option( self::OPT, array() );

		// ── 1. IP block ─────────────────────────────────────────────
		if ( ! empty( $s['ip_block_enabled'] ) ) {
			$ip = credoq_client_ip();
			if ( self::ip_is_blocked( $ip, (string) ( $s['ip_blocklist'] ?? '' ) ) ) {
				credoq_log( "Blocked submission from blocklisted IP: {$ip}", 'warning' );
				return new \WP_Error(
					'ip_blocked',
					__( 'Submissions from your network are not allowed.', 'credoq-engine' )
				);
			}
		}

		// ── 2. Country block ────────────────────────────────────────
		if ( ! empty( $s['country_block_enabled'] ) ) {
			$ip      = credoq_client_ip();
			$country = self::lookup_country( $ip );
			$blocked = self::parse_list( (string) ( $s['country_blocklist'] ?? '' ) );
			if ( $country && in_array( strtoupper( $country ), array_map( 'strtoupper', $blocked ), true ) ) {
				credoq_log( "Blocked submission from blocklisted country: {$country} ({$ip})", 'warning' );
				return new \WP_Error(
					'country_blocked',
					__( 'Submissions from your region are not allowed.', 'credoq-engine' )
				);
			}
		}

		// ── 3. reCAPTCHA ─────────────────────────────────────────────
		if ( ! empty( $s['recaptcha_enabled'] ) && ! empty( $s['recaptcha_secret_key'] ) ) {
			$token = (string) ( $payload['recaptcha_token'] ?? $payload['g-recaptcha-response'] ?? '' );
			$verdict = self::verify_recaptcha( $token, $s );
			if ( is_wp_error( $verdict ) ) return $verdict;
		}

		return true;
	}

	/**
	 * Exact match or simple wildcard prefix match (e.g. "203.0.113.*"
	 * blocks the whole 203.0.113.0/24 range without requiring CIDR math).
	 */
	private static function ip_is_blocked( string $ip, string $list_raw ) : bool {
		foreach ( self::parse_list( $list_raw ) as $entry ) {
			if ( '' === $entry ) continue;
			if ( $entry === $ip ) return true;
			if ( false !== strpos( $entry, '*' ) ) {
				$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $entry, '/' ) ) . '$/';
				if ( preg_match( $pattern, $ip ) ) return true;
			}
		}
		return false;
	}

	/** Split a textarea/comma list into a clean array of entries. */
	private static function parse_list( string $raw ) : array {
		$raw   = str_replace( ',', "\n", $raw );
		$lines = preg_split( '/[\r\n]+/', $raw );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}

	/**
	 * Resolve an IP to an ISO-3166 alpha-2 country code, cached for 7
	 * days per IP (transient) so we don't hit the external API on every
	 * submission/view from the same visitor.
	 *
	 * Uses ipapi.co's free, keyless endpoint. Falls back to '' (unknown)
	 * on any network failure — fails OPEN (never blocks a submission just
	 * because the lookup itself failed).
	 *
	 * @param string $ip
	 * @return string  Two-letter country code, or '' if unknown.
	 */
	public static function lookup_country( string $ip ) : string {
		if ( '' === $ip || '0.0.0.0' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		// Private/reserved ranges (localhost, LAN) will never resolve — skip the call.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '';
		}

		$cache_key = 'credoq_geoip_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached; // may be '' (cached "unknown" to avoid hammering the API)
		}

		$response = wp_remote_get( 'https://ipapi.co/' . rawurlencode( $ip ) . '/country/', array(
			'timeout' => 3,
		) );

		$country = '';
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = trim( wp_remote_retrieve_body( $response ) );
			// Endpoint returns a bare 2-letter code, e.g. "US". Anything
			// else (error text, rate-limit message) is rejected.
			if ( preg_match( '/^[A-Za-z]{2}$/', $body ) ) {
				$country = strtoupper( $body );
			}
		}

		set_transient( $cache_key, $country, 7 * DAY_IN_SECONDS );
		return $country;
	}

	/**
	 * Full GeoIP detail lookup (country name, region, city) for the
	 * Submissions detail view — a richer, separately-cached call so the
	 * lightweight country-code-only lookup() above stays cheap for the
	 * per-submission block check.
	 *
	 * @return array{country:string,country_code:string,region:string,city:string}
	 */
	public static function lookup_geo_details( string $ip ) : array {
		$empty = array( 'country' => '', 'country_code' => '', 'region' => '', 'city' => '' );
		if ( '' === $ip || '0.0.0.0' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return $empty;
		}

		$cache_key = 'credoq_geoip_full_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) return $cached;

		$response = wp_remote_get( 'https://ipapi.co/' . rawurlencode( $ip ) . '/json/', array(
			'timeout' => 3,
		) );

		$result = $empty;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && empty( $data['error'] ) ) {
				$result = array(
					'country'      => (string) ( $data['country_name'] ?? '' ),
					'country_code' => (string) ( $data['country_code'] ?? '' ),
					'region'       => (string) ( $data['region']       ?? '' ),
					'city'         => (string) ( $data['city']         ?? '' ),
				);
			}
		}

		set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Verify a reCAPTCHA token against Google's siteverify endpoint.
	 * Handles both v2 (checkbox, pass/fail) and v3 (score-based).
	 */
	private static function verify_recaptcha( string $token, array $settings ) {
		if ( '' === $token ) {
			return new \WP_Error( 'recaptcha_missing', __( 'reCAPTCHA verification failed. Please try again.', 'credoq-engine' ) );
		}

		$response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
			'timeout' => 5,
			'body'    => array(
				'secret'   => (string) $settings['recaptcha_secret_key'],
				'response' => $token,
				'remoteip' => credoq_client_ip(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			credoq_log( 'reCAPTCHA verify request failed: ' . $response->get_error_message(), 'error' );
			// Fail OPEN on a network error so a Google outage doesn't take the form down.
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['success'] ) ) {
			return new \WP_Error( 'recaptcha_failed', __( 'reCAPTCHA verification failed. Please try again.', 'credoq-engine' ) );
		}

		if ( 'v3' === ( $settings['recaptcha_version'] ?? 'v2' ) ) {
			$score     = isset( $body['score'] ) ? (float) $body['score'] : 0.0;
			$threshold = isset( $settings['recaptcha_v3_threshold'] ) && '' !== $settings['recaptcha_v3_threshold']
				? (float) $settings['recaptcha_v3_threshold']
				: 0.5;
			if ( $score < $threshold ) {
				credoq_log( "reCAPTCHA v3 score {$score} below threshold {$threshold}", 'warning' );
				return new \WP_Error( 'recaptcha_low_score', __( 'Submission flagged as suspicious. Please try again.', 'credoq-engine' ) );
			}
		}

		return true;
	}
}
