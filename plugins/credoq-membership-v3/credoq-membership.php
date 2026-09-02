<?php
/**
 * Plugin Name: Credoq Membership
 * Plugin URI:  https://credoq.com
 * Description: Membership addon for Credoq Engine. Adds plans, credits, and content restriction.
 * Version:     1.0.3
 * Author:      Credoq
 * Author URI:  https://credoq.com
 * Text Domain: credoq-membership
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package CredoqMembership
 */

namespace CredoqMembership;

defined( 'ABSPATH' ) || exit;

define( 'CREDOQ_MEMBERSHIP_VERSION', '1.0.3' );
define( 'CREDOQ_MEMBERSHIP_FILE',    __FILE__ );
define( 'CREDOQ_MEMBERSHIP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CREDOQ_MEMBERSHIP_URL',     plugin_dir_url( __FILE__ ) );

// ── Manual autoload ────────────────────────────────────────────────
require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Schema.php';
require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Plan_Repository.php';
require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Membership_Service.php';
require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Restriction_Gate.php';
require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Field_Slot_Credit.php';
require_once CREDOQ_MEMBERSHIP_DIR . 'includes/Plugin.php';   // ← \CredoqMembership\Plugin

// ── Boot ───────────────────────────────────────────────────────────
Plugin::boot();

// ── Global helper functions ────────────────────────────────────────

function credoq_add_user_membership( $user_id, $plan_id, $purchase_date, $expiry_date, $order_id, $wc_order_status = '' ) {
	global $wpdb;
	return $wpdb->insert(
		$wpdb->prefix . 'credoq_user_memberships',
		[
			'user_id'         => intval( $user_id ),
			'plan_id'         => intval( $plan_id ),
			'purchase_date'   => $purchase_date,
			'expiry_date'     => $expiry_date,
			'order_id'        => intval( $order_id ),
			'wc_order_status' => sanitize_text_field( $wc_order_status ),
			'status'          => 'active',
		]
	);
}

function credoq_get_user_active_memberships( $user_id ) {
	return ( new Membership_Service() )->get_active_memberships( $user_id );
}
