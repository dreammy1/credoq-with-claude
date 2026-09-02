<?php
/**
 * Audit: Timezone Boundary Stress Test
 *
 * Verifies if Slot_Generator correctly identifies "today" and applies 
 * lead-time rules when the server time (UTC) differs from the site timezone.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/wp_stubs.php';

// Mock DB
global $wpdb;
$wpdb = new FakeWPDB();
$wpdb->prefix = 'wp_';

// Load Plugins
require_once PLUGINS_DIR . '/credoq-engine-v3/credoq-engine.php';
require_once PLUGINS_DIR . '/credoq-appointments/credoq-appointments.php';

use CredoqAppointments\Slot_Generator;

// Setup a mock appointment
$apt_id = 1;
$wpdb->appointments[$apt_id] = (object)[
    'id' => $apt_id,
    'title' => 'Timezone Test Service',
    'duration' => 60,
    'slot_interval' => 60,
    'max_bookings' => 1,
    'booking_settings' => json_encode([
        'min_lead_time' => 60, // 1 hour lead time
    ]),
    'availability' => json_encode([
        'monday'    => ['closed' => false, 'hours' => [['start' => '08:00', 'end' => '17:00']]],
        'tuesday'   => ['closed' => false, 'hours' => [['start' => '08:00', 'end' => '17:00']]],
        'wednesday' => ['closed' => false, 'hours' => [['start' => '08:00', 'end' => '17:00']]],
        'thursday'  => ['closed' => false, 'hours' => [['start' => '08:00', 'end' => '17:00']]],
        'friday'    => ['closed' => false, 'hours' => [['start' => '08:00', 'end' => '17:00']]],
        'saturday'  => ['closed' => false, 'hours' => [['start' => '08:00', 'end' => '17:00']]],
        'sunday'    => ['closed' => false, 'hours' => [['start' => '08:00', 'end' => '17:00']]],
    ])
];

/**
 * Scenario:
 * Server is in UTC.
 * It is Oct 10th, 00:30 AM UTC.
 * Site is in New York (UTC-4).
 * In New York, it is still Oct 9th, 08:30 PM.
 * 
 * A user in New York wants to book for Oct 9th (which is "today" for them).
 * The server thinks today is Oct 10th.
 */

// Force PHP timezone to UTC
date_default_timezone_set('UTC');

// Today is Sep 2, 2026 (based on session context)
echo "Current Server Date (UTC): " . date('Y-m-d') . " " . date('H:i') . "\n";

// Test 1: Yesterday's date
$yesterday = date('Y-m-d', strtotime('-1 day'));
echo "Testing slots for yesterday: $yesterday\n";

// Debug: check day of week
$day_of_week = strtolower(date('l', strtotime($yesterday)));
echo "Day of week for $yesterday: $day_of_week\n";

$slots = Slot_Generator::for_date($apt_id, 0, $yesterday);
echo "Slot count for yesterday: " . count($slots) . "\n";

if (!empty($slots)) {
    echo "❌ VULNERABILITY DETECTED: Slots returned for a past date ($yesterday).\n";
    echo "Example slot: " . $slots[0]['time'] . "\n";
} else {
    echo "✅ SUCCESS: No slots returned for yesterday.\n";
}

// Test 2: Today's date with lead-time violation
$today = date('Y-m-d');
echo "\nTesting slots for today: $today\n";
$slots = Slot_Generator::for_date($apt_id, 0, $today);

// If it's early morning UTC, but we want to book a slot that is theoretically "past" in local time
// but Slot_Generator uses time() (UTC).
// Let's see if any slots are returned that are actually in the past.
$past_slots = 0;
$now_ts = time();
foreach ($slots as $s) {
    $slot_ts = strtotime($today . ' ' . $s['time']);
    if ($slot_ts < $now_ts) {
        $past_slots++;
    }
}

if ($past_slots > 0) {
    echo "❌ VULNERABILITY DETECTED: $past_slots slots returned that are in the past (UTC).\n";
} else {
    echo "✅ SUCCESS: No past slots returned for today.\n";
}
