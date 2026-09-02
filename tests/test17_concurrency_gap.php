<?php
/**
 * Audit: Cross-Plugin Concurrency Gap
 *
 * Verifies if a staff member can be booked for an Appointment
 * while they are already assigned to an Event at the same time.
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
require_once PLUGINS_DIR . '/credoq-events-v3/credoq-events.php';

use CredoqAppointments\Slot_Generator;

// 1. Setup a Staff member
$staff_id = 10;
$wpdb->insert('wp_credoq_staff', [
    'id' => $staff_id,
    'display_name' => 'Expert Trainer'
]);

// 2. Setup an Event assigned to this staff
$event_id = 200;
$date = '2026-10-10';
$wpdb->events[$event_id] = (object)[
    'id' => $event_id,
    'title' => 'Group Workshop',
    'start_datetime' => "$date 10:00:00",
    'end_datetime' => "$date 12:00:00",
    'staff_id' => $staff_id,
    'status' => 'published'
];

// 3. Setup an Appointment service that this staff also provides
$apt_id = 1;
$wpdb->appointments[$apt_id] = (object)[
    'id' => $apt_id,
    'title' => 'Private Session',
    'duration' => 60,
    'slot_interval' => 60,
    'max_bookings' => 1,
    'availability' => json_encode([
        'saturday' => ['closed' => false, 'hours' => [['start' => '08:00', 'end' => '17:00']]]
    ])
];

echo "Checking availability for staff #$staff_id on $date...\n";
echo "Staff has an Event from 10:00 to 12:00.\n";

if (class_exists('\CredoqEvents\Event_Repository')) {
    echo "DEBUG: CredoqEvents\Event_Repository is loaded.\n";
} else {
    echo "DEBUG: CredoqEvents\Event_Repository is NOT loaded.\n";
}

$slots = Slot_Generator::for_date($apt_id, $staff_id, $date);

$found_10am = false;
foreach ($slots as $s) {
    if ($s['time'] === '10:00') {
        $found_10am = true;
        if ($s['available']) {
            echo "❌ GAP DETECTED: Staff is marked AVAILABLE for 10:00 AM Appointment despite being at an Event.\n";
        } else {
            echo "✅ SUCCESS: Staff is correctly blocked for 10:00 AM Appointment.\n";
        }
    }
}

if (!$found_10am) {
    echo "ERROR: 10:00 AM slot not generated at all. Check availability config.\n";
}

// 4. Test Reverse: Event blocked by Appointment
echo "\nTesting reverse: Event blocked by Appointment...\n";

// Clear previous event mock to avoid noise
unset($wpdb->events[$event_id]);

// Add an Appointment for the staff
$booking_id = 888;
$wpdb->bookings[$booking_id] = (object)[
    'id' => $booking_id,
    'appointment_id' => $apt_id,
    'staff_id' => $staff_id,
    'selected_date' => $date,
    'selected_time' => '14:00',
    'duration' => 60,
    'status' => 'confirmed'
];

// Add an Event at the same time
$event_id_2 = 201;
$wpdb->events[$event_id_2] = (object)[
    'id' => $event_id_2,
    'title' => 'Afternoon Session',
    'start_datetime' => "$date 14:15:00",
    'end_datetime' => "$date 15:15:00",
    'staff_id' => $staff_id,
    'status' => 'published',
    'capacity' => 10
];

use CredoqEvents\Event_Service;
$has_cap = Event_Service::has_capacity($event_id_2, 1);

if ($has_cap) {
    echo "❌ GAP DETECTED: Event registration allowed despite staff being in an Appointment.\n";
} else {
    echo "✅ SUCCESS: Event registration correctly blocked by Appointment.\n";
}
