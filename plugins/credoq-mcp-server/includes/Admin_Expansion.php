<?php
/**
 * Admin Expansion — high-value administrative "Propose & Create" tools.
 *
 * These tools enable the AI to act as a drafting assistant for real
 * business entities without synthetic data restrictions, while maintaining
 * the secure "Proposal-Confirm" safety pattern.
 */

defined( 'ABSPATH' ) || exit;

final class Credoq_MCP_Admin_Expansion {

	/**
	 * Propose creation of a new business resource.
	 */
	public static function propose_creation( string $resource, array $data ) : array {
		if ( ! in_array( $resource, [ 'service', 'staff', 'membership_plan', 'event', 'form' ], true ) ) {
			return [ 'error' => 'Unsupported creation resource.' ];
		}

		$proposal_id = wp_generate_uuid4();
		$confirm_token = wp_generate_password( 32, false, false );

		// Clean and validate the data before proposing
		$clean = self::sanitize_creation_data( $resource, $data );
		if ( isset( $clean['error'] ) ) return $clean;

		set_transient( 'credoq_mcp_confirm_' . $proposal_id, [
			'kind'    => 'create_' . $resource,
			'data'    => $clean,
			'token'   => $confirm_token
		], 600 );

		return [
			'proposal_id'   => $proposal_id,
			'confirm_token' => $confirm_token,
			'status'        => 'awaiting_approval',
			'type'          => 'create_' . $resource,
			'draft'         => $clean,
			'warning'       => 'No database mutation was performed. Human approval required.'
		];
	}

	/**
	 * Apply a creation proposal after confirmation.
	 */
	public static function apply_creation( array $args ) : array {
		if ( empty( $args['confirm'] ) ) return [ 'error' => 'Explicit confirm=true is required.' ];

		$proposal_id = sanitize_text_field( $args['proposal_id'] ?? '' );
		$proposal = get_transient( 'credoq_mcp_confirm_' . $proposal_id );

		if ( ! is_array( $proposal ) || ! hash_equals( (string) $proposal['token'], (string) ( $args['confirm_token'] ?? '' ) ) ) {
			return [ 'error' => 'Invalid or expired confirmation token.' ];
		}

		$kind = $proposal['kind'];
		$data = $proposal['data'];
		$result = [ 'status' => 'error', 'message' => 'Implementation missing.' ];

		if ( 'create_service' === $kind ) {
			$result = self::create_service( $data );
		} elseif ( 'create_staff' === $kind ) {
			$result = self::create_staff( $data );
		} elseif ( 'create_membership_plan' === $kind ) {
			$result = self::create_membership_plan( $data );
		} elseif ( 'create_event' === $kind ) {
			$result = self::create_event( $data );
		} elseif ( 'create_form' === $kind ) {
			$result = self::create_form( $data );
		}

		if ( $result['status'] === 'created' ) {
			delete_transient( 'credoq_mcp_confirm_' . $proposal_id );
		}

		return $result;
	}

	private static function sanitize_creation_data( string $resource, array $data ) : array {
		if ( 'service' === $resource ) {
			return [
				'title'         => sanitize_text_field( $data['title'] ?? 'New Service' ),
				'price'         => (float) ( $data['price'] ?? 0.0 ),
				'duration'      => absint( $data['duration'] ?? 60 ),
				'slot_interval' => absint( $data['slot_interval'] ?? 30 ),
				'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
			];
		}
		if ( 'staff' === $resource ) {
			return [
				'display_name'     => sanitize_text_field( $data['display_name'] ?? 'New Staff' ),
				'email'            => sanitize_email( $data['email'] ?? '' ),
				'price_multiplier' => (float) ( $data['price_multiplier'] ?? 1.0 ),
			];
		}
		if ( 'membership_plan' === $resource ) {
			return [
				'name'          => sanitize_text_field( $data['name'] ?? 'New Plan' ),
				'duration_days' => absint( $data['duration_days'] ?? 30 ),
				'rules'         => [
					'slot_credit' => absint( $data['slot_credit'] ?? 0 ),
				],
			];
		}
		if ( 'event' === $resource ) {
			return [
				'title'          => sanitize_text_field( $data['title'] ?? 'New Event' ),
				'start_datetime' => sanitize_text_field( $data['start_datetime'] ?? '' ),
				'end_datetime'   => sanitize_text_field( $data['end_datetime'] ?? '' ),
				'price'          => (float) ( $data['price'] ?? 0.0 ),
				'capacity'       => absint( $data['capacity'] ?? 0 ),
			];
		}
		if ( 'form' === $resource ) {
			if ( empty( $data['title'] ) || ! is_array( $data['fields'] ?? null ) ) {
				return [ 'error' => 'Title and fields array are required for form creation.' ];
			}
			return $data;
		}
		return [ 'error' => 'Sanitization not implemented.' ];
	}

	private static function create_service( array $data ) : array {
		if ( ! class_exists( '\\CredoqAppointments\\Appointment_Repository' ) ) return [ 'status' => 'error', 'message' => 'Appointments repository missing.' ];
		$id = \CredoqAppointments\Appointment_Repository::save( $data );
		return [ 'status' => 'created', 'id' => $id, 'resource' => 'service' ];
	}

	private static function create_staff( array $data ) : array {
		if ( ! class_exists( '\\CredoqAppointments\\Staff_Repository' ) ) return [ 'status' => 'error', 'message' => 'Staff repository missing.' ];
		$id = \CredoqAppointments\Staff_Repository::save( $data );
		return [ 'status' => 'created', 'id' => $id, 'resource' => 'staff' ];
	}

	private static function create_membership_plan( array $data ) : array {
		if ( ! class_exists( '\\CredoqMembership\\Plan_Repository' ) ) return [ 'status' => 'error', 'message' => 'Membership repository missing.' ];
		$repo = new \CredoqMembership\Plan_Repository();
		$id = $repo->save( $data );
		return [ 'status' => 'created', 'id' => $id, 'resource' => 'membership_plan' ];
	}

	private static function create_event( array $data ) : array {
		if ( ! class_exists( '\\CredoqEvents\\Event_Repository' ) ) return [ 'status' => 'error', 'message' => 'Events repository missing.' ];
		$id = \CredoqEvents\Event_Repository::save( $data );
		return [ 'status' => 'created', 'id' => $id, 'resource' => 'event' ];
	}

	private static function create_form( array $data ) : array {
		if ( ! class_exists( '\\CredoqEngine\\Forms\\Repository' ) ) return [ 'status' => 'error', 'message' => 'Forms repository missing.' ];
		$repo = new \CredoqEngine\Forms\Repository();
		$id = $repo->save( array_merge( $data, [ 'status' => 'published' ] ) );
		if ( is_wp_error( $id ) ) return [ 'status' => 'error', 'message' => $id->get_error_message() ];
		return [ 'status' => 'created', 'id' => (int) $id, 'resource' => 'form' ];
	}
}
