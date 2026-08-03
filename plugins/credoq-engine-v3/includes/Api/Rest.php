<?php
/**
 * REST API — credoq/v1.
 *
 * The React widget talks to these routes instead of admin-ajax.php.
 * Addons can register their own routes by hooking 'credoq_rest_routes'
 * during 'rest_api_init'.
 *
 * @package CredoqEngine\Api
 */

namespace CredoqEngine\Api;

use CredoqEngine\Fields\Registry;
use CredoqEngine\Forms\Repository;
use CredoqEngine\Forms\Submission_Handler;

defined( 'ABSPATH' ) || exit;

class Rest {

	private Registry $fields;
	private Repository $forms;
	private Submission_Handler $submissions;

	public function __construct( Registry $fields, Repository $forms, Submission_Handler $submissions ) {
		$this->fields      = $fields;
		$this->forms       = $forms;
		$this->submissions = $submissions;
	}

	public function register_routes() : void {
		$ns = CREDOQ_ENGINE_REST_NS;

		// GET /forms/{id} — public, returns the form schema for the widget.
		register_rest_route( $ns, '/forms/(?P<id>\d+)', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_form' ),
			'permission_callback' => '__return_true', // AUDIT-FIX B-9: form schema is public read-only (no user data exposed)
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		) );

		// POST /submissions — create one (rate-limited inside the handler).
		register_rest_route( $ns, '/submissions', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_submission' ),
			'permission_callback' => array( $this, 'nonce_or_logged_in' ),
		) );

		// GET /field-types — for the form-builder UI (admin only).
		register_rest_route( $ns, '/field-types', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'list_field_types' ),
			'permission_callback' => array( $this, 'admin_only' ),
		) );

		// Allow addons to add routes.
		do_action( 'credoq_rest_routes', $this );
	}

	/* ── Public endpoints ─────────────────────────────────────────── */

	public function get_form( \WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return new \WP_Error( 'not_found', __( 'Form not found.', 'credoq-engine' ), array( 'status' => 404 ) );
		}
		// Strip server-only settings before sending to the client.
		$client_view = $form->to_array();
		$client_view['settings'] = $this->sanitize_settings_for_client( $client_view['settings'] );
		return rest_ensure_response( $client_view );
	}

	public function create_submission( \WP_REST_Request $req ) {
		$body    = $req->get_json_params() ?: array();
		$form_id = absint( $body['form_id'] ?? 0 );
		$payload = is_array( $body['payload'] ?? null ) ? $body['payload'] : array();

		$result = $this->submissions->process( $form_id, $payload, array(
			'user_id'    => get_current_user_id(),
			'user_agent' => $req->get_header( 'user_agent' ) ?? '',
			'source'     => 'rest',
		) );
		if ( is_wp_error( $result ) ) {
			$status = 'rate_limited' === $result->get_error_code() ? 429 :
			          ( 'validation_failed' === $result->get_error_code() ? 422 : 400 );
			return new \WP_REST_Response( array(
				'success' => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $result->get_error_data(),
			), $status );
		}
		return rest_ensure_response( array( 'success' => true ) + $result );
	}

	public function list_field_types( \WP_REST_Request $req ) {
		return rest_ensure_response( $this->fields->builder_descriptors() );
	}

	/* ── Permission callbacks ─────────────────────────────────────── */

	public function admin_only() : bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Accept either a logged-in user (WP REST nonce) or an
	 * unauthenticated request bearing a one-time public token (for
	 * guest bookings, scoped to a specific form_id).
	 *
	 * Guests submit a form by first GETting /forms/{id}, which sets
	 * a short-lived signed token in a cookie. The token is required
	 * on the POST.
	 */
	public function nonce_or_logged_in( \WP_REST_Request $req ) {
		// Logged-in path: WP automatically checks the X-WP-Nonce header.
		if ( is_user_logged_in() && wp_verify_nonce( $req->get_header( 'x_wp_nonce' ), 'wp_rest' ) ) {
			return true;
		}
		// Guest path: require a fresh nonce we mint ourselves.
		$nonce = $req->get_header( 'x_credoq_guest_nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'credoq_guest_submit' ) ) {
			return true;
		}
		return false;
	}

	/* ── Helpers ──────────────────────────────────────────────────── */

	/**
	 * Strip any settings that should never be exposed to the client
	 * (private keys, server-side webhooks, etc.). Addons can hook
	 * 'credoq_client_form_settings' to add more.
	 */
	private function sanitize_settings_for_client( array $settings ) : array {
		$strip_keys = apply_filters( 'credoq_client_form_settings_strip', array(
			'admin_email_template', 'webhook_url', 'integration_keys',
		) );
		foreach ( $strip_keys as $k ) unset( $settings[ $k ] );
		return $settings;
	}
}
