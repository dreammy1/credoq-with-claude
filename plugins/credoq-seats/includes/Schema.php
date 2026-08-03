<?php
namespace CredoqSeats;
defined( 'ABSPATH' ) || exit;

class Schema {

	const DB_VERSION_KEY = 'credoq_seats_db_version';
	const DB_VERSION     = '1.0.0';

	public static function install() : void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ── Seat plans ───────────────────────────────────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_seat_plans (
			id              bigint unsigned NOT NULL AUTO_INCREMENT,
			name            varchar(255)    NOT NULL DEFAULT '',
			description     text,
			template_key    varchar(50)     NOT NULL DEFAULT 'custom',
			connect_type    varchar(20)     NOT NULL DEFAULT 'none',
			connected_ids   longtext,
			layout_json     longtext        NOT NULL,
			total_floors    int unsigned    NOT NULL DEFAULT 0,
			total_seats     int unsigned    NOT NULL DEFAULT 0,
			capacity_limit  int unsigned    NOT NULL DEFAULT 0,
			status          varchar(20)     NOT NULL DEFAULT 'draft',
			created_by      bigint unsigned NOT NULL DEFAULT 0,
			created_at      datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY connect_type (connect_type)
		) {$charset};" );

		// ── Floors ───────────────────────────────────────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_seat_plan_floors (
			id          bigint unsigned NOT NULL AUTO_INCREMENT,
			plan_id     bigint unsigned NOT NULL,
			name        varchar(100)    NOT NULL DEFAULT 'Floor 1',
			sort_order  int unsigned    NOT NULL DEFAULT 0,
			color       varchar(10)     NOT NULL DEFAULT '#4f46e5',
			seat_count  int unsigned    NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY plan_id (plan_id)
		) {$charset};" );

		// ── Seats ────────────────────────────────────────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_seats (
			id             bigint unsigned NOT NULL AUTO_INCREMENT,
			plan_id        bigint unsigned NOT NULL,
			floor_id       bigint unsigned NOT NULL,
			seat_label     varchar(20)     NOT NULL DEFAULT 'A1',
			seat_type      varchar(20)     NOT NULL DEFAULT 'standard',
			row_index      smallint unsigned NOT NULL DEFAULT 0,
			col_index      smallint unsigned NOT NULL DEFAULT 0,
			x_pos          float           NOT NULL DEFAULT 0,
			y_pos          float           NOT NULL DEFAULT 0,
			price_override decimal(10,2)   DEFAULT NULL,
			status         varchar(20)     NOT NULL DEFAULT 'available',
			color_class    varchar(50)     NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY plan_id (plan_id),
			KEY floor_id (floor_id),
			KEY seat_label (seat_label)
		) {$charset};" );

		// ── Seat bookings (holds + confirmed) ───────────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_seat_bookings (
			id              bigint unsigned NOT NULL AUTO_INCREMENT,
			plan_id         bigint unsigned NOT NULL,
			seat_id         bigint unsigned NOT NULL,
			booking_type    varchar(20)     NOT NULL DEFAULT 'event',
			ref_id          bigint unsigned NOT NULL DEFAULT 0,
			event_id        bigint unsigned NOT NULL DEFAULT 0,
			appointment_id  bigint unsigned NOT NULL DEFAULT 0,
			date_context    date            NOT NULL DEFAULT '1970-01-01',
			time_context    time            DEFAULT NULL,
			user_id         bigint unsigned NOT NULL DEFAULT 0,
			guest_email     varchar(200)    NOT NULL DEFAULT '',
			status          varchar(20)     NOT NULL DEFAULT 'held',
			held_until      datetime        DEFAULT NULL,
			price_charged   decimal(10,2)   NOT NULL DEFAULT 0.00,
			wc_order_id     bigint unsigned NOT NULL DEFAULT 0,
			created_at      datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY plan_id_date (plan_id, date_context),
			KEY seat_id (seat_id),
			KEY ref_id (ref_id),
			KEY status (status),
			KEY held_until (held_until),
			UNIQUE KEY seat_date_time_status (seat_id, date_context, time_context, status)
		) {$charset};" );

		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	public static function maybe_upgrade() : void {
		if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) self::install();
	}
}
