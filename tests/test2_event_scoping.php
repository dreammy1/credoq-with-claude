<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Booking_Repository.php';
// calc_seats_breakdown() needs these — not exercised in this test, but the class references them.
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Plan_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Seat_Repository.php';

use CredoqSeats\Repositories\Booking_Repository;

global $wpdb;
$wpdb = new FakeWPDB();

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected === $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label (expected " . var_export($expected,true) . ", got " . var_export($actual,true) . ")\n";
    if (!$ok) $fails++;
}

// Scenario: ONE seat plan (id=1) connected to TWO different events (100 and
// 200) that happen to land on the SAME date (2026-08-01) — the exact
// collision case Plan_Builder_Page's checkbox "connect to several events"
// UI allows, and the original (unfixed) queries had no event_id filter
// to keep them apart.

$date = '2026-08-01';

// Event 100 holds seat #5.
$ok1 = Booking_Repository::hold_seat(1, 5, $date, '', ['event_id' => 100, 'ref_id' => 0], 5);
check('event 100 holds seat 5 — succeeds', true, $ok1);

// Event 200 tries to hold the SAME physical seat id #5 for ITS OWN
// showing on the same date. Without event scoping this would incorrectly
// be blocked ("already taken") even though it's a different event/showing.
$ok2 = Booking_Repository::hold_seat(1, 5, $date, '', ['event_id' => 200, 'ref_id' => 0], 5);
check('event 200 can independently hold its own seat 5 (not blocked by event 100)', true, $ok2);

// booked_seat_ids for event 100 should show seat 5 booked...
$booked100 = Booking_Repository::booked_seat_ids(1, $date, '', 100);
check('booked_seat_ids(event 100) includes seat 5', [5], $booked100);

// ...and booked_seat_ids for event 200 should ALSO show seat 5 booked
// (its own independent hold), not "double-booked against event 100's row".
$booked200 = Booking_Repository::booked_seat_ids(1, $date, '', 200);
check('booked_seat_ids(event 200) includes seat 5 (its own hold)', [5], $booked200);

// A THIRD attempt to hold seat 5 for event 100 again should fail (it's
// genuinely taken within event 100's own scope).
$ok3 = Booking_Repository::hold_seat(1, 5, $date, '', ['event_id' => 100, 'ref_id' => 0], 5);
check('re-holding seat 5 for event 100 again fails (already held within its own scope)', false, $ok3);

// Release seat 5 for event 100 only — event 200's hold on the same
// physical seat id must survive.
Booking_Repository::release_seat(5, $date, '', 100);
check('after releasing event 100\'s hold, event 100 booked list is empty', [], Booking_Repository::booked_seat_ids(1, $date, '', 100));
check('event 200\'s independent hold on seat 5 is untouched', [5], Booking_Repository::booked_seat_ids(1, $date, '', 200));

// Now confirm event 200's seat and verify confirm_seats also respects
// event scoping when looking for an existing held row to upgrade (rather
// than accidentally upgrading event 100's — already-released — row).
Booking_Repository::confirm_seats(1, [5], [
    'booking_type' => 'event', 'ref_id' => 555, 'event_id' => 200,
    'date' => $date, 'time' => '', 'price_map' => [5 => 42.50],
]);
$wpdb_row = null;
foreach ($wpdb->seat_bookings as $row) if ($row['seat_id'] == 5 && $row['event_id'] == 200) $wpdb_row = $row;
check('event 200 seat 5 confirmed with correct per-seat price (not an average)', 42.50, $wpdb_row['price_charged'] ?? null);
check('event 200 seat 5 status is confirmed', 'confirmed', $wpdb_row['status'] ?? null);
check('exactly one seat_bookings row exists for seat 5 (upgraded in place, not duplicated)', 1, count(array_filter($wpdb->seat_bookings, fn($r) => $r['seat_id']==5)));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
