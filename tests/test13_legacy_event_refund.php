<?php
/**
 * Audit: Legacy Event Refund
 *
 * Verifies if credits are refunded when a legacy Event booking is cancelled.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/wp_stubs.php';

// Mock DB
global $wpdb;
$wpdb = new FakeWPDB();
$wpdb->prefix = 'wp_';

// Consolidate Stubs
if (!function_exists('get_userdata')) { function get_userdata($id) { return (object)['user_email' => 'test@example.com']; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($s) { return $s; } }
if (!function_exists('plugin_dir_path')) { function plugin_dir_path($f) { return dirname($f) . '/'; } }
if (!function_exists('plugin_dir_url')) { function plugin_dir_url($f) { return 'http://test.local/wp-content/plugins/' . basename(dirname($f)) . '/'; } }
if (!function_exists('register_activation_hook')) { function register_activation_hook($f, $cb) {} }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook($f, $cb) {} }
if (!function_exists('plugin_basename')) { function plugin_basename($f) { return basename($f); } }
if (!function_exists('is_admin')) { function is_admin() { return false; } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return $s; } }
if (!function_exists('untrailingslashit')) { function untrailingslashit($s) { return rtrim($s, '/'); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
if (!function_exists('get_bloginfo')) { function get_bloginfo($k) { return 'Test Site'; } }
if (!function_exists('get_option')) { function get_option($k, $default = false) { return $default; } }
if (!function_exists('wp_date')) { function wp_date($f, $t) { return date($f, $t); } }

// Load Plugins
require_once PLUGINS_DIR . '/credoq-engine-v3/credoq-engine.php';
require_once PLUGINS_DIR . '/credoq-events-v3/credoq-events.php';
require_once PLUGINS_DIR . '/credoq-membership-v3/credoq-membership.php';

use CredoqEvents\Event_Service;
use CredoqMembership\Membership_Service;

// 1. Setup a Plan and a User
$user_id = 1;
$plan_id = 101;
$wpdb->user_memberships[1] = (object)[
    'id' => 1,
    'user_id' => $user_id,
    'plan_id' => $plan_id,
    'status' => 'active',
    'expiry_date' => '2099-12-31 23:59:59',
    'purchase_date' => '2023-01-01 00:00:00',
    'order_id' => 0
];

// Give user 10 credits
$membership_svc = new Membership_Service();
$membership_svc->add_ledger_entry($user_id, 10, 'purchase', $plan_id, 0, 'Initial credits');

echo "Initial Balance: " . $membership_svc->get_balance($user_id, $plan_id) . " (Expected: 10)\n";

// 2. Create a Booking that uses a credit
$booking_id = 500;
// Simulate the credit deduction record created by register()
$membership_svc->add_ledger_entry($user_id, -1, 'use', $plan_id, $booking_id, 'Event Booking');

echo "Balance after booking: " . $membership_svc->get_balance($user_id, $plan_id) . " (Expected: 9)\n";

// 3. Cancel the Booking
echo "Cancelling legacy event booking #$booking_id...\n";

// Mock the booking row
$wpdb->event_bookings[$booking_id] = (object)[
    'id' => $booking_id,
    'user_id' => $user_id,
    'status' => 'confirmed',
    'credit_deducted' => 1,
    'guest_name' => 'Test User'
];

Event_Service::cancel($booking_id);

// 4. Check Balance
$final_balance = $membership_svc->get_balance($user_id, $plan_id);
echo "Final Balance: $final_balance\n";

if ($final_balance === 10) {
    echo "✅ SUCCESS: Legacy Event credits were refunded.\n";
} else {
    echo "❌ FAILURE: Legacy Event credits were NOT refunded.\n";
}
