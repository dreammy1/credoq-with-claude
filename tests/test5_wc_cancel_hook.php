<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';
require PLUGINS_DIR . '/credoq-engine-v3/includes/Abstracts/Field_Type.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Plan_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Seat_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Booking_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Fields/Seat_Map_Field.php';
require PLUGINS_DIR . '/credoq-seats/includes/Integrations/Events_Bridge.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Booking_Repository.php';

use CredoqSeats\Repositories\Booking_Repository;
use CredoqSeats\Integrations\Events_Bridge;
use CredoqEvents\Event_Booking_Repository;

// Fake Event_Service just enough for Events_Bridge::register()'s class_exists check.
eval('namespace CredoqEvents; class Event_Service {}');

global $wpdb;
$wpdb = new FakeWPDB();
Events_Bridge::register();

// Set up: a confirmed seat (event_id=1, ref_id/submission_id=42) plus the
// event_bookings row WooCommerce.php's on_cancel() would look up.
Booking_Repository::confirm_seats(1, [7], [
    'booking_type' => 'event', 'ref_id' => 42, 'event_id' => 1,
    'date' => '2026-09-01', 'time' => '', 'price_map' => [7 => 20.00],
]);
$wpdb->event_bookings[900] = ['id' => 900, 'event_id' => 1, 'submission_id' => 42, 'status' => 'pending_payment', 'quantity' => 1];

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected == $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label (expected " . var_export($expected,true) . ", got " . var_export($actual,true) . ")\n";
    if (!$ok) $fails++;
}

// Sanity: seat is confirmed before cancellation.
$before = array_values(array_filter($wpdb->seat_bookings, fn($r) => $r['seat_id'] == 7))[0];
check('seat 7 starts confirmed', 'confirmed', $before['status']);

// --- Simulate the FIXED WooCommerce::on_cancel() behavior: fire the hook
// once per booking ROW id (900), the way find_by_submission()+the fix now
// does — NOT the raw submission_id (42), which was the actual bug found.
foreach (Event_Booking_Repository::find_by_submission(42) as $booking) {
    do_action('credoq_event_booking_cancelled', (int) $booking->id);
}

$after = array_values(array_filter($wpdb->seat_bookings, fn($r) => $r['seat_id'] == 7))[0];
check('seat 7 is cancelled after the hook fires with the booking id', 'cancelled', $after['status']);

// --- Now demonstrate the ORIGINAL bug for contrast: firing with the
// submission_id (42) instead of the booking id (900) — Events_Bridge looks
// up credoq_event_bookings.id = 42, which doesn't exist, so nothing happens.
Booking_Repository::confirm_seats(1, [8], [
    'booking_type' => 'event', 'ref_id' => 42, 'event_id' => 1,
    'date' => '2026-09-01', 'time' => '', 'price_map' => [8 => 20.00],
]);
do_action('credoq_event_booking_cancelled', 42); // the OLD (buggy) call shape
$seat8 = array_values(array_filter($wpdb->seat_bookings, fn($r) => $r['seat_id'] == 8))[0];
check('(regression check) firing with submission_id instead of booking id fails silently — confirms the bug this fix addresses', 'confirmed', $seat8['status']);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
