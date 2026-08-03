<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';
require PLUGINS_DIR . '/credoq-engine-v3/includes/Abstracts/Field_Type.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Plan_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Seat_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Booking_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Fields/Seat_Map_Field.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Booking_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Service.php';

use CredoqSeats\Fields\Seat_Map_Field;
use CredoqEvents\Event_Service;

global $wpdb;
$wpdb = new FakeWPDB();

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected == $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label (expected " . var_export($expected,true) . ", got " . var_export($actual,true) . ")\n";
    if (!$ok) $fails++;
}

// --- Scenario A: capacity ceiling — event.capacity is left "unlimited"
// (0) by the admin, but the connected published seat plan only has 8
// physical seats. Without the fix, has_capacity() would say yes to a
// 20-seat request because 0 = unlimited. With the fix, the seat plan's
// real seat count is the ceiling.
$wpdb->events[10] = ['id' => 10, 'title' => 'Small Room Talk', 'capacity' => 0, 'price' => 0, 'wc_product_id' => 0, 'credit_deduct_enabled' => 0];
$wpdb->seat_plans[5] = ['id' => 5, 'status' => 'published', 'connect_type' => 'event', 'connected_ids' => json_encode([10]), 'total_seats' => 8, 'capacity_limit' => 0];
check('event capacity=0 (unlimited) but seat plan caps it at 8 — 5 requested fits', true, Event_Service::has_capacity(10, 5));
check('same event — 9 requested exceeds the 8-seat plan ceiling', false, Event_Service::has_capacity(10, 9));

// Booked 3 already -> only 5 remain against the seat-plan ceiling of 8.
$wpdb->event_bookings[900] = ['event_id' => 10, 'quantity' => 3, 'status' => 'confirmed'];
check('with 3 already booked, 5 more fits exactly (3+5=8)', true, Event_Service::has_capacity(10, 5));
check('with 3 already booked, 6 more exceeds the ceiling (3+6=9 > 8)', false, Event_Service::has_capacity(10, 6));

// --- Scenario B: event.capacity is explicitly SMALLER than the seat plan
// (e.g. admin intentionally caps a subset of the room) — the smaller of
// the two should win.
$wpdb->events[11] = ['id' => 11, 'title' => 'VIP-only session', 'capacity' => 3, 'price' => 0, 'wc_product_id' => 0, 'credit_deduct_enabled' => 0];
$wpdb->seat_plans[6] = ['id' => 6, 'status' => 'published', 'connect_type' => 'event', 'connected_ids' => json_encode([11]), 'total_seats' => 50, 'capacity_limit' => 0];
check('event capacity (3) smaller than seat plan (50) — the smaller wins: 3 fits', true, Event_Service::has_capacity(11, 3));
check('event capacity (3) smaller than seat plan (50) — 4 does not fit', false, Event_Service::has_capacity(11, 4));

// --- Scenario C: ambiguous event selection — a seat_map field can't tell
// which of two selected events it belongs to. Must fail loudly (WP_Error),
// never silently confirm against the wrong event.
$sanitized_ambiguous = [
    'event_field' => json_encode([
        ['event_id' => 10, 'quantity' => 1, 'price' => 0],
        ['event_id' => 11, 'quantity' => 1, 'price' => 0],
    ]),
    'seat_field' => ['seats' => json_encode([1,2]), 'count' => 2, 'total' => '10.00', 'plan_id' => 5, 'selected' => 'yes'],
];
$seat_field = new Seat_Map_Field();
$verdict = $seat_field->on_submission(777, $sanitized_ambiguous['seat_field'], [], $sanitized_ambiguous);
check('ambiguous multi-event submission with a seat_map returns a WP_Error (not a silent confirm)', true, is_wp_error($verdict));
check('no seat_bookings rows were created for the ambiguous submission', 0, count($wpdb->seat_bookings));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
