<?php
/**
 * Schema for Membership Addon.
 *
 * @package CredoqMembership
 */

namespace CredoqMembership;

defined( 'ABSPATH' ) || exit;

class Schema {

	const DB_OPTION_KEY = 'credoq_membership_db_version';
	const DB_VERSION    = '1.0.2'; // bumped: adds notifications table + created_at

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

		// ── Plans ────────────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$wpdb->prefix}credoq_membership_plans (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name           VARCHAR(255)        NOT NULL DEFAULT '',
			product_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			duration_days  INT                 NOT NULL DEFAULT 30,
			rules          LONGTEXT            NULL,
			created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY product_id (product_id)
		) {$charset};" );

		// ── User Memberships ─────────────────────────────────────────
		dbDelta( "CREATE TABLE {$wpdb->prefix}credoq_user_memberships (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			plan_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			purchase_date   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expiry_date     DATETIME            NOT NULL DEFAULT '0000-00-00 00:00:00',
			order_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			wc_order_status VARCHAR(30)         NOT NULL DEFAULT '',
			status          VARCHAR(20)         NOT NULL DEFAULT 'active',
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY plan_id (plan_id),
			KEY expiry_date (expiry_date)
		) {$charset};" );

		// ── Credit Ledger ───────────────────────────────────────────
		// Unified table for all slot transactions.
		// Amount is positive for grant/refund, negative for use/adjustment.
		dbDelta( "CREATE TABLE {$wpdb->prefix}credoq_credit_ledger (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_email     VARCHAR(190)        NOT NULL DEFAULT '',
			plan_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			amount         INT                 NOT NULL DEFAULT 0,
			type           VARCHAR(30)         NOT NULL DEFAULT 'use',
			ref_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			note           TEXT                NULL,
			created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY user_email (user_email),
			KEY plan_id (plan_id)
		) {$charset};" );

		// Notifications table — used by the user frontend SPA dashboard
		// to surface WC payment confirmations, expiry warnings etc.
		// Also checked by Engine Tools_Page health-checker.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_notifications (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			type       VARCHAR(50)         NOT NULL DEFAULT 'info',
			title      VARCHAR(255)        NOT NULL DEFAULT '',
			message    TEXT,
			is_read    TINYINT(1)          NOT NULL DEFAULT 0,
			created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY is_read (is_read)
		) {$charset};" );

		do_action( 'credoq_membership_schema_installed' );
	}
}
