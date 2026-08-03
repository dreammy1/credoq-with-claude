<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';
require PLUGINS_DIR . '/credoq-engine-v3/includes/Abstracts/Field_Type.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Plan_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Seat_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Booking_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Fields/Seat_Map_Field.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Repository.php';

use CredoqSeats\Fields\Seat_Map_Field;

global $wpdb;
$wpdb = new FakeWPDB();

// Plan #7 is intentionally connected to TWO events (a valid multi-event
// setup per the audit — the exact case the new builder panel exists for).
$wpdb->seat_plans[7] = ['id'=>7,'status'=>'published','connect_type'=>'event','connected_ids'=>json_encode([10,20]),'total_seats'=>6,'capacity_limit'=>0,'layout_json'=>json_encode(['pricing'=>[]])];
$wpdb->seats[] = ['id'=>1,'plan_id'=>7,'seat_type'=>'standard','price_override'=>12.0,'seat_label'=>'A1'];
$wpdb->events[20] = ['id'=>20,'title'=>'Second Run','price'=>12.0,'start_datetime'=>'2026-10-05 20:00:00'];

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected == $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label (expected " . var_export($expected,true) . ", got " . var_export($actual,true) . ")\n";
    if (!$ok) $fails++;
}

// Without ANY explicit override, this plan is ambiguous (2 connected
// events) and there's no event_registration field on this submission at
// all — resolve_event_id_from_payload() correctly can't determine one.
$sanitized_no_override = ['seat_field' => ['seats'=>json_encode([1]),'count'=>1,'total'=>'999','plan_id'=>7,'selected'=>'yes']];
check('without an explicit override, an ambiguous plan resolves to no event', 0, Seat_Map_Field::resolve_event_id_from_payload($sanitized_no_override));

// With the admin's explicit field_config['event_id'] = 20 (set via the new
// Forms Builder panel), on_submission() must use it directly — bypassing
// the (here, impossible) auto-detection entirely.
$seat_field = new Seat_Map_Field();
$verdict = $seat_field->on_submission(900, $sanitized_no_override['seat_field'], ['event_id' => 20], $sanitized_no_override);
check('on_submission with an explicit field_config event_id=20 does NOT error', false, is_wp_error($verdict));

$row = null;
foreach ($wpdb->seat_bookings as $r) if ($r['seat_id'] == 1) $row = $r;
check('seat is confirmed against the explicitly pinned event (20), not left unresolved', 20, $row['event_id'] ?? null);
check('date resolved from the explicitly pinned event\'s own start_datetime', '2026-10-05', $row['date_context'] ?? null);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
