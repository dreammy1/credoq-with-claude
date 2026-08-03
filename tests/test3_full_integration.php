<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';

require PLUGINS_DIR . '/credoq-engine-v3/includes/Abstracts/Field_Type.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Plan_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Seat_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Booking_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Fields/Seat_Map_Field.php';
require PLUGINS_DIR . '/credoq-seats/includes/Integrations/Events_Bridge.php';

require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Booking_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Service.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Fields/Field_Event.php';

global $wpdb;
$wpdb = new FakeWPDB();

// --- Fixture data -----------------------------------------------------
// Event #1: flat price $20, has a WC product, NOT credit-enabled.
$wpdb->events[1] = [
    'id' => 1, 'title' => 'Jazz Night', 'price' => 20.00, 'wc_product_id' => 99,
    'capacity' => 100, 'credit_deduct_enabled' => 0, 'credit_deduct_amount' => 1,
    'start_datetime' => '2026-09-01 19:00:00',
];

// Seat plan #1: connected to event #1 only, published, VIP seats priced at $30.
$wpdb->seat_plans[1] = [
    'id' => 1, 'name' => 'Main Hall', 'status' => 'published', 'connect_type' => 'event',
    'connected_ids' => json_encode([1]), 'total_seats' => 10, 'capacity_limit' => 0,
    'layout_json' => json_encode(['pricing' => ['vip' => 30.00, 'standard' => 12.00]]),
];

// Seat #10: VIP, no override -> resolves to plan's VIP price (30.00).
$wpdb->seats[] = ['id' => 10, 'plan_id' => 1, 'seat_type' => 'vip', 'price_override' => null, 'seat_label' => 'A1'];
// Seat #11: standard type, but with an explicit $15 override -> overrides both type price and base.
$wpdb->seats[] = ['id' => 11, 'plan_id' => 1, 'seat_type' => 'standard', 'price_override' => 15.00, 'seat_label' => 'A2'];

use CredoqSeats\Fields\Seat_Map_Field;
use CredoqSeats\Integrations\Events_Bridge;
use CredoqEvents\Fields\Field_Event;

Events_Bridge::register(); // wires 'credoq_events_seat_overrides' + confirm/cancel hooks

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected == $actual; // loose compare for float/int convenience
    echo ($ok ? "PASS" : "FAIL") . " — $label (expected " . var_export($expected,true) . ", got " . var_export($actual,true) . ")\n";
    if (!$ok) $fails++;
}

// --- Simulate the sanitized submission payload -------------------------
// Calendar (event_registration field) says qty=5 @ $20 (WRONG on purpose —
// this must be overridden by the seat plan, not added to it).
$sanitized = [
    'event_field' => json_encode([[ 'event_id' => 1, 'quantity' => 5, 'price' => 20.00 ]]),
    'seat_field'  => [
        'seats' => json_encode([10, 11]),
        'count' => 2,
        'total' => '999.00', // bogus client-submitted total — must be IGNORED (never trust client)
        'plan_id' => 1,
        'selected' => 'yes',
    ],
    'email' => 'guest@example.com',
];

// --- 1. resolve_event_id_from_payload works across the whole payload ---
check('resolve_event_id_from_payload finds event 1 unambiguously', 1, Seat_Map_Field::resolve_event_id_from_payload($sanitized));

// --- 2. Events_Bridge::filter_seat_overrides recomputes the true total --
$overrides = apply_filters('credoq_events_seat_overrides', [], ['sanitized' => $sanitized]);
check('seat override total = 30 (seat10, VIP) + 15 (seat11, override) = 45, NOT 999 or 100', 45.00, $overrides[1]['total'] ?? null);
check('seat override count = 2 (real seat count, not calendar qty=5)', 2, $overrides[1]['count'] ?? null);

// --- 3. Field_Event::wc_contribution() uses the seat total, not qty*price
$field_event = new Field_Event();
$wc = $field_event->wc_contribution($sanitized['event_field'], [], $sanitized);
check('wc_contribution has exactly one line item', 1, count($wc));
check('wc_contribution price is the seat total (45), not flat qty*price (100)', 45.00, $wc[0]['price'] ?? null);
check('wc_contribution product_id matches the event\'s wc_product_id', 99, $wc[0]['product_id'] ?? null);

// --- 4. credit_cost is 0 (this event doesn't use credit deduction) -----
check('credit_cost is 0 (event not credit-enabled)', 0, $field_event->credit_cost($sanitized['event_field'], [], $sanitized));

// --- 5. on_submission() stores the REAL seat total/count on the booking row
$verdict = $field_event->on_submission(555, $sanitized['event_field'], [], $sanitized);
check('on_submission does not return a WP_Error', false, is_wp_error($verdict));
$booking = $wpdb->event_bookings[1] ?? null;
check('event_bookings row created', true, $booking !== null);
check('booking quantity = real seat count (2), not calendar qty (5)', 2, $booking['quantity'] ?? null);
check('booking total_price = seat total (45.00), not flat qty*price (100.00)', 45.00, $booking['total_price'] ?? null);
check('booking status = pending_payment (has a WC product, priced)', 'pending_payment', $booking['status'] ?? null);

// --- 6. Seat_Map_Field::on_submission() confirms the ACTUAL seats with
//        their own individual prices (never an averaged total).
$seat_field = new Seat_Map_Field();
$verdict2 = $seat_field->on_submission(555, $sanitized['seat_field'], [], $sanitized);
check('Seat_Map_Field::on_submission does not return a WP_Error', false, is_wp_error($verdict2));

$row10 = null; $row11 = null;
foreach ($wpdb->seat_bookings as $r) {
    if ($r['seat_id'] == 10) $row10 = $r;
    if ($r['seat_id'] == 11) $row11 = $r;
}
check('seat 10 confirmed with its own VIP price (30.00), not an average (22.50)', 30.00, $row10['price_charged'] ?? null);
check('seat 11 confirmed with its own override price (15.00), not an average', 15.00, $row11['price_charged'] ?? null);
check('seat 10 status confirmed', 'confirmed', $row10['status'] ?? null);
check('seat 10 event_id correctly resolved to 1', 1, $row10['event_id'] ?? null);
check('seat date resolved from the event\'s own start_datetime', '2026-09-01', $row10['date_context'] ?? null);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
