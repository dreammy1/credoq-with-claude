<?php
require __DIR__ . '/bootstrap.php';
define('ABSPATH', '/tmp/'); // satisfy the defined('ABSPATH')||exit guard in the plugin file

// Minimal harness: exercise Seat_Map_Field::resolve_event_id_from_payload()
// with realistic sanitized-payload shapes, without needing full WordPress.

require PLUGINS_DIR . '/credoq-engine-v3/includes/Abstracts/Field_Type.php';
require PLUGINS_DIR . '/credoq-seats/includes/Fields/Seat_Map_Field.php';
// Seat_Map_Field extends CredoqEngine\Abstracts\Field_Type — stub it minimally.

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected === $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label (expected " . var_export($expected,true) . ", got " . var_export($actual,true) . ")\n";
    if (!$ok) $fails++;
}

use CredoqSeats\Fields\Seat_Map_Field;

// Case 1: single event selected (legacy single-object shape)
$payload1 = [
    'event_reg_field' => json_encode(['event_id' => 42, 'quantity' => 2, 'price' => 25]),
    'email' => 'a@b.com',
];
check('single event, legacy object shape', 42, Seat_Map_Field::resolve_event_id_from_payload($payload1));

// Case 2: single event selected, array-of-selections shape
$payload2 = [
    'event_reg_field' => json_encode([['event_id' => 7, 'quantity' => 3, 'price' => 10]]),
];
check('single event, array shape', 7, Seat_Map_Field::resolve_event_id_from_payload($payload2));

// Case 3: MULTIPLE distinct events selected — ambiguous, must return 0
$payload3 = [
    'event_reg_field' => json_encode([
        ['event_id' => 7, 'quantity' => 1, 'price' => 10],
        ['event_id' => 9, 'quantity' => 2, 'price' => 20],
    ]),
];
check('multiple distinct events — ambiguous', 0, Seat_Map_Field::resolve_event_id_from_payload($payload3));

// Case 4: no event_registration-shaped value at all
$payload4 = [
    'seat_map_field' => ['seats' => '[1,2]', 'count' => 2, 'total' => '50.00', 'plan_id' => 3, 'selected' => 'yes'],
    'email' => 'x@y.com',
];
check('no event field present', 0, Seat_Map_Field::resolve_event_id_from_payload($payload4));

// Case 5: same event repeated across two event_registration-shaped fields (dedup, still unambiguous)
$payload5 = [
    'field_a' => json_encode([['event_id' => 5, 'quantity' => 1, 'price' => 10]]),
    'field_b' => json_encode([['event_id' => 5, 'quantity' => 2, 'price' => 10]]),
];
check('same event across two fields — dedup to single id', 5, Seat_Map_Field::resolve_event_id_from_payload($payload5));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
