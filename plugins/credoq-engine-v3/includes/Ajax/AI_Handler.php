<?php
/**
 * AI_Handler — AJAX handlers for the AI Command Center and Customer Assistant.
 *
 * @package CredoqEngine\Ajax
 */

namespace CredoqEngine\Ajax;

use CredoqEngine\AI\AI_Bridge;

defined( 'ABSPATH' ) || exit;

class AI_Handler {

	public static function register() : void {
		$instance = new self();
		add_action( 'wp_ajax_credoq_ai_chat', [ $instance, 'handle_chat' ] );
		// Future: add_action( 'wp_ajax_nopriv_credoq_ai_chat', ... ) for frontend.
	}

	public function handle_chat() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden', 'credoq-engine' ) ] );
		}

		check_ajax_referer( 'credoq_ai_command' );

		$prompt  = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : [];

		if ( ! is_array( $history ) ) $history = [];

		if ( empty( $prompt ) ) {
			wp_send_json_error( [ 'message' => __( 'Prompt is required.', 'credoq-engine' ) ] );
		}

		$bridge = AI_Bridge::instance();
		$result = $bridge->chat( $prompt, $history, 'admin' );

		wp_send_json_success( $result );
	}
}
