<?php
/**
 * Audit_Log — append-only activity history.
 *
 * Anything calling Audit_Log::record() shows up on the "Audit log" admin
 * page. Also self-registers listeners for WordPress core login/logout
 * events and for changes to Credoq's own settings options, so the log is
 * useful without any other file having to call it directly.
 *
 * @package CredoqEngine\Log
 */

namespace CredoqEngine\Log;

defined( 'ABSPATH' ) || exit;

class Audit_Log {

	const TABLE = 'credoq_audit_log';

	/** @var bool */
	private static $registered = false;

	/**
	 * Hook WordPress core events we want in the log automatically.
	 * Safe to call once from Plugin::register_hooks().
	 */
	public static function register() : void {
		if ( self::$registered ) return;
		self::$registered = true;

		add_action( 'wp_login',        [ __CLASS__, 'on_login' ], 10, 2 );
		add_action( 'wp_login_failed', [ __CLASS__, 'on_login_failed' ], 10, 1 );
		add_action( 'wp_logout',       [ __CLASS__, 'on_logout' ] );

		// Credoq core "Settings" page (General/Security tabs) saves to this option.
		add_action( 'update_option_credoq_engine_settings', [ __CLASS__, 'on_engine_settings_updated' ], 10, 0 );

		// Credoq SMTP settings option — see Mail\Mailer::OPT.
		add_action( 'update_option_credoq_smtp_settings', [ __CLASS__, 'on_smtp_settings_updated' ], 10, 0 );
	}

	/* ── Core WP event listeners ─────────────────────────────────────── */

	public static function on_login( string $user_login, \WP_User $user ) : void {
		self::record( 'login.success', array(
			'subject'   => $user->user_email ?: $user_login,
			'user_id'   => (int) $user->ID,
			'user_name' => $user->display_name,
		) );
	}

	public static function on_login_failed( string $username ) : void {
		self::record( 'login.failed', array(
			'subject' => $username,
		) );
	}

	public static function on_logout() : void {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) return;
		self::record( 'logout', array(
			'subject'   => $user->user_email ?: $user->user_login,
			'user_id'   => (int) $user->ID,
			'user_name' => $user->display_name,
		) );
	}

	public static function on_engine_settings_updated() : void {
		self::record( 'settings.updated', array( 'subject' => 'general' ) );
	}

	public static function on_smtp_settings_updated() : void {
		self::record( 'settings.updated', array( 'subject' => 'smtp' ) );
	}

	/* ── Writer ───────────────────────────────────────────────────────── */

	/**
	 * Record one audit event.
	 *
	 * @param string $event   Dot-notation event key, e.g. 'mail.send', 'forms.submitted'.
	 * @param array  $args {
	 *   @type string $subject   Short "who/what" shown next to the event, e.g. an email or ID.
	 *   @type int    $user_id   Defaults to the current logged-in user (0 = system/guest).
	 *   @type string $user_name Defaults to the current user's display name, or 'system'.
	 *   @type string $message   Free-text detail line shown under the entry (e.g. "subject=... ok=1").
	 *   @type array  $meta      Structured extra data, stored as JSON.
	 * }
	 */
	public static function record( string $event, array $args = array() ) : void {
		global $wpdb;

		$user_id   = array_key_exists( 'user_id', $args ) ? (int) $args['user_id'] : get_current_user_id();
		$user_name = $args['user_name'] ?? '';
		if ( '' === $user_name ) {
			$user_name = $user_id ? ( get_userdata( $user_id )->display_name ?? '' ) : 'system';
		}

		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'event'      => substr( sanitize_key( $event ), 0, 60 ),
				'subject'    => substr( (string) ( $args['subject'] ?? '' ), 0, 190 ),
				'user_id'    => $user_id,
				'user_name'  => substr( (string) $user_name, 0, 190 ),
				'message'    => isset( $args['message'] ) ? (string) $args['message'] : null,
				'meta'       => isset( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : null,
				'ip_address' => substr( function_exists( 'credoq_client_ip' ) ? credoq_client_ip() : '', 0, 45 ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/* ── Reader ───────────────────────────────────────────────────────── */

	/**
	 * Paginated list of entries, newest first.
	 *
	 * @param array $args { event, search, per_page, paged, days }
	 * @return array{rows: array<int, object>, total: int, pages: int}
	 */
	public static function get_entries( array $args = array() ) : array {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE;
		$per_page = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$params[] = sanitize_key( $args['event'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(subject LIKE %s OR user_name LIKE %s OR message LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['days'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( (int) $args['days'] * DAY_IN_SECONDS ) );
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql   = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
			'pages' => (int) max( 1, ceil( $total / $per_page ) ),
		);
	}

	/**
	 * Volume-by-day counts for the sidebar widget (last $days days, newest first).
	 */
	public static function volume_by_day( int $days = 7 ) : array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY d DESC",
			$since
		) );

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'date' => $r->d, 'count' => (int) $r->c );
		}
		return $out;
	}

	/** Distinct event keys currently present, for the filter dropdown. */
	public static function distinct_events() : array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_col( "SELECT DISTINCT event FROM {$table} ORDER BY event ASC" );
		return $rows ?: array();
	}

	public static function clear() : void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
	}
}
