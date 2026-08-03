<?php
/**
 * Schema — canonical CREATE TABLE statements for the Engine.
 *
 * AUDIT-FIX (v9 had a long ALTER TABLE ladder for booking columns;
 * this version puts every column in the canonical CREATE so reinstalls
 * and dbDelta diffing are correct).
 *
 * Engine owns ONLY two tables:
 *   credoq_forms          — form schemas
 *   credoq_submissions    — every form submission (the universal record)
 *
 * Domain-specific tables (appointments, events, seat plans, memberships,
 * attendance) are owned by their respective addon plugins.
 *
 * @package CredoqEngine\Install
 */

namespace CredoqEngine\Install;

defined( 'ABSPATH' ) || exit;

class Schema {

	const DB_OPTION_KEY = 'credoq_engine_db_version';
	const DB_VERSION    = '1.1.0'; // bumped: adds notifications, audit_log tables + submissions.ref column

	public static function maybe_upgrade() : void {
		if ( get_option( self::DB_OPTION_KEY ) === self::DB_VERSION ) {
			return;
		}
		self::install();
		update_option( self::DB_OPTION_KEY, self::DB_VERSION );
	}

	public static function install() : void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// ── Forms ────────────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$wpdb->prefix}credoq_forms (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title       VARCHAR(255)        NOT NULL DEFAULT '',
			fields      LONGTEXT            NULL,
			settings    LONGTEXT            NULL,
			status      VARCHAR(20)         NOT NULL DEFAULT 'published',
			created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};" );

		// ── Submissions ──────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$wpdb->prefix}credoq_submissions (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_email    VARCHAR(190)        NOT NULL DEFAULT '',
			payload       LONGTEXT            NULL,
			total_price   DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
			total_credits INT                 NOT NULL DEFAULT 0,
			status        VARCHAR(30)         NOT NULL DEFAULT 'pending',
			source        VARCHAR(30)         NOT NULL DEFAULT 'frontend',
			wc_order_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ip_address    VARCHAR(45)         NOT NULL DEFAULT '',
			user_agent    VARCHAR(255)        NOT NULL DEFAULT '',
			notes         LONGTEXT            NULL,
			ref           VARCHAR(20)         NOT NULL DEFAULT '',
			created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY user_id (user_id),
			KEY user_email (user_email),
			KEY status (status),
			KEY wc_order_id (wc_order_id),
			KEY created_at (created_at),
			KEY ref (ref)
		) {$charset};" );

		// ── Notifications (admin bell / Notifications page) ────────────
		dbDelta( "CREATE TABLE {$wpdb->prefix}credoq_notifications (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type        VARCHAR(50)         NOT NULL DEFAULT 'system',
			title       VARCHAR(255)        NOT NULL DEFAULT '',
			message     TEXT                NULL,
			link        VARCHAR(500)        NOT NULL DEFAULT '',
			ref         VARCHAR(40)         NOT NULL DEFAULT '',
			is_read     TINYINT(1)          NOT NULL DEFAULT 0,
			created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_read (is_read),
			KEY created_at (created_at)
		) {$charset};" );

		// ── Audit log (append-only activity history) ────────────────────
		dbDelta( "CREATE TABLE {$wpdb->prefix}credoq_audit_log (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event       VARCHAR(60)         NOT NULL DEFAULT '',
			subject     VARCHAR(190)        NOT NULL DEFAULT '',
			user_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_name   VARCHAR(190)        NOT NULL DEFAULT '',
			message     TEXT                NULL,
			meta        LONGTEXT            NULL,
			ip_address  VARCHAR(45)         NOT NULL DEFAULT '',
			created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event (event),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset};" );

		do_action( 'credoq_engine_schema_installed' );
	}
}
