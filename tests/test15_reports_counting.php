<?php
/**
 * Audit: Reports Over-counting
 *
 * Verifies if the dashboard reports exclude cancelled/refunded records.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/wp_stubs.php';

// Mock DB
global $wpdb;
$wpdb = new FakeWPDB();
$wpdb->prefix = 'wp_';

// Stubs for Reports Page
if (!function_exists('get_option')) { function get_option($k, $default = false) { return $default; } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script($a) {} }
if (!function_exists('wp_localize_script')) { function wp_localize_script($a,$b,$c) {} }
if (!function_exists('current_user_can')) { function current_user_can($a) { return true; } }

// Load Plugin Admin
require_once PLUGINS_DIR . '/credoq-engine-v3/includes/Admin/Reports_Page.php';

use CredoqEngine\Admin\Reports_Page;

// 1. Setup mock data
// We'll mock the get_var for the reports query
$wpdb->set_mock_var("SELECT COUNT(*) FROM wp_credoq_bookings WHERE created_at BETWEEN '2023-01-01' AND '2023-12-31' AND status NOT IN ('cancelled','refunded','failed','pending_payment')", 5);

// To test my fix, I need to see if the query actually contains the status exclusion
// Since I can't easily capture the query from the static render_overview, 
// I'll update FakeWPDB to log all queries it receives.

class LoggingFakeDB extends FakeWPDB {
    public $queries = [];
    function get_var($q) {
        $this->queries[] = $q;
        return parent::get_var($q);
    }
}

$wpdb = new LoggingFakeDB();
$wpdb->prefix = 'wp_';

// Mock the table existence check
$wpdb->set_mock_var('{"__q":"SHOW TABLES LIKE %s","__args":["wp_credoq_bookings"]}', 'wp_credoq_bookings');
$wpdb->set_mock_var('{"__q":"SHOW TABLES LIKE %s","__args":["wp_credoq_user_memberships"]}', 'wp_credoq_user_memberships');
$wpdb->set_mock_var('{"__q":"SHOW TABLES LIKE %s","__args":["wp_credoq_event_bookings"]}', 'wp_credoq_event_bookings');

// Call render_overview (via reflection or just mock the dependencies)
// render_overview is private, so we need to test via the public render() or use Reflection
$ref = new ReflectionClass('CredoqEngine\Admin\Reports_Page');
$method = $ref->getMethod('render_overview');
$method->setAccessible(true);

ob_start();
$method->invoke(null, '2023-01-01', '2023-12-31');
ob_end_clean();

echo "Debug: Queries Captured:\n";
foreach ($wpdb->queries as $q) echo "  - $q\n";

$found_fix = false;
foreach ($wpdb->queries as $q) {
    if (str_contains($q, 'credoq_bookings') && str_contains($q, "status NOT IN ('cancelled','refunded','failed','pending_payment')")) {
        $found_fix = true;
        break;
    }
}

if ($found_fix) {
    echo "✅ SUCCESS: Reports query now excludes cancelled/refunded records.\n";
} else {
    echo "❌ FAILURE: Reports query still over-counts.\n";
}
