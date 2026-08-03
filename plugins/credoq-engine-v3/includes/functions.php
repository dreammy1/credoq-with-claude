<?php
/**
 * Function-style helpers for templates and addons.
 *
 * Thin wrappers around the OO core. Use these from templates; use the OO
 * classes directly from addon code where possible.
 *
 * @package CredoqEngine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the Engine instance.
 *
 * @return \CredoqEngine\Plugin
 */
function credoq_engine() : \CredoqEngine\Plugin {
	return \CredoqEngine\Plugin::instance();
}

/**
 * Structured logger. Writes to debug.log only when WP_DEBUG_LOG is on
 * AND the 'credoq_debug_mode' option is enabled.
 *
 * @param string $message
 * @param string $level info|warning|error
 * @param array  $context
 */
function credoq_log( string $message, string $level = 'info', array $context = array() ) : void {
	if ( ! get_option( 'credoq_debug_mode', 0 ) ) {
		return;
	}
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}
	$line = sprintf( '[credoq:%s] %s', $level, $message );
	if ( ! empty( $context ) ) {
		$line .= ' ' . wp_json_encode( $context );
	}
	error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Verify an AJAX/REST nonce in a uniform way.
 * Bails with wp_send_json_error if invalid.
 *
 * @param string $action Nonce action.
 * @param string $field  Request key (default '_wpnonce').
 */
function credoq_verify_nonce( string $action, string $field = '_wpnonce' ) : void {
	$nonce = isset( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		wp_send_json_error(
			array( 'code' => 'invalid_nonce', 'message' => __( 'Security check failed. Please refresh the page.', 'credoq-engine' ) ),
			403
		);
	}
}

/**
 * Get the client IP. We DO NOT trust X-Forwarded-For unless the site
 * has explicitly opted in by setting CREDOQ_TRUST_PROXY_HEADERS.
 *
 * AUDIT-FIX B-6: previously blindly trusted CF-Connecting-IP / X-Forwarded-For.
 *
 * @return string
 */
function credoq_client_ip() : string {
	if ( defined( 'CREDOQ_TRUST_PROXY_HEADERS' ) && CREDOQ_TRUST_PROXY_HEADERS ) {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = trim( explode( ',', wp_unslash( $_SERVER[ $h ] ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ?: '0.0.0.0';
}
