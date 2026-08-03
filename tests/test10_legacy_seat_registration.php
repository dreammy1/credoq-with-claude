<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';
require PLUGINS_DIR . '/credoq-engine-v3/includes/Abstracts/Field_Type.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Plan_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Seat_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Booking_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Booking_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Service.php';

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

// Fixture: a free event (no WC), connected to a published plan with mixed pricing.
$wpdb->events[5] = ['id'=>5,'title'=>'Community Meetup','price'=>0,'wc_product_id'=>0,'capacity'=>0,'credit_deduct_enabled'=>0,'credit_deduct_amount'=>0,'start_datetime'=>'2026-11-01 18:00:00'];
$wpdb->seat_plans[11] = ['id'=>11,'status'=>'published','connect_type'=>'event','connected_ids'=>json_encode([5]),'total_seats'=>20,'capacity_limit'=>0,'layout_json'=>json_encode(['pricing'=>['vip'=>25.00]])];
$wpdb->seats[] = ['id'=>100,'plan_id'=>11,'seat_type'=>'vip','price_override'=>null,'seat_label'=>'C1'];
$wpdb->seats[] = ['id'=>101,'plan_id'=>11,'seat_type'=>'standard','price_override'=>9.00,'seat_label'=>'C2'];

// connected_seat_plan_id() correctly resolves for the modal's Register button.
check('connected_seat_plan_id() resolves the plan for the Register button', 11, Event_Service::connected_seat_plan_id(5));

// Submitting with a mismatched/bogus qty (2) but only picking these 2 seats —
// and a spoofed high qty to prove qty gets overridden by real seat count.
$result = Event_Service::register(5, 0, 999 /* bogus qty, should be ignored */, 'Guest', 'guest@test.com', 0, [100, 101]);
check('registration succeeds', true, $result['success']);

$booking_id = $result['booking_id'];
$booking = $wpdb->event_bookings[$booking_id];
check('quantity is the real seat count (2), not the bogus submitted qty (999)', 2, $booking['quantity']);
check('total_price is the real seat total (25.00 VIP + 9.00 override = 34.00), not 0 (event price) x anything', 34.00, (float) $booking['total_price']);
check('booking status is confirmed (free event, no WC)', 'confirmed', $booking['status']);

$row100 = null; $row101 = null;
foreach ($wpdb->seat_bookings as $r) { if ($r['seat_id']==100) $row100=$r; if ($r['seat_id']==101) $row101=$r; }
check('seat 100 (VIP) confirmed at its own type price', 25.00, $row100['price_charged'] ?? null);
check('seat 101 confirmed at its own override price', 9.00, $row101['price_charged'] ?? null);
check('seats use the event_legacy booking_type (distinct ref_id space from Forms Builder submissions)', 'event_legacy', $row100['booking_type'] ?? null);
check('seats correctly linked to the event', 5, $row100['event_id'] ?? null);

// Cancellation releases the seats.
Event_Service::cancel($booking_id);
$booked_after_cancel = \CredoqSeats\Repositories\Booking_Repository::booked_seat_ids(11, '2026-11-01', '', 5);
check('after cancel(), both seats are released (booked list is empty)', [], $booked_after_cancel);

// Rejecting stale/invalid seat_ids: seats that don't belong to any plan.
$result2 = Event_Service::register(5, 0, 1, 'Guest2', 'g2@test.com', 0, [9999]);
check('registering with nonexistent seat ids fails cleanly (no phantom booking)', false, $result2['success']);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
