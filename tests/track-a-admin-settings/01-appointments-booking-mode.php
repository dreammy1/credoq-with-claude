<?php
require_once __DIR__ . '/wp-load.php';
$fx = json_decode(file_get_contents(__DIR__ . '/track-a-fixture-ids.json'), true);

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected === $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label (expected " . var_export($expected,true) . ", got " . var_export($actual,true) . ")\n";
    if (!$ok) $fails++;
}

$params = [
    'appointment_id' => $fx['appointment_id'],
    'staff_id' => $fx['staff_id'],
    'dates' => [$fx['test_date']],
    'user_id' => 0,
    'guest_name' => 'Jane Test',
    'guest_email' => 'jane@example.test',
    'form_data' => ['quantity' => 1],
];
$params['dates'] = [['date' => $fx['test_date'], 'time' => $fx['test_time']]];

// Wire up minimal args the real Booking_Service::create() expects — check
// actual arg shape by trying and adjusting from the error if needed.
$params['time'] = $fx['test_time'];

// --- SETTING OFF: booking_mode = 'auto' (the pre-existing default) ---
update_option('credoq_booking_settings', ['booking_mode' => 'auto']);
$r1 = \CredoqAppointments\Booking_Service::create($params);
echo "auto-mode result: " . json_encode($r1) . "\n";
if (!empty($r1['success'])) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}credoq_bookings WHERE id=%d", $r1['booking_id'] ?? ($r1['booking_ids'][0] ?? 0)));
    check('booking_mode=auto: booking is auto-confirmed', 'confirmed', $row->status ?? null);
} else {
    echo "auto-mode booking FAILED: " . ($r1['error'] ?? 'unknown') . "\n";
}

// --- SETTING ON: booking_mode = 'manual' ---
update_option('credoq_booking_settings', ['booking_mode' => 'manual']);
$r2 = \CredoqAppointments\Booking_Service::create($params);
echo "manual-mode result: " . json_encode($r2) . "\n";
if (!empty($r2['success'])) {
    global $wpdb;
    $bid = $r2['booking_id'] ?? ($r2['booking_ids'][0] ?? 0);
    $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}credoq_bookings WHERE id=%d", $bid));
    check('booking_mode=manual: booking is pending (NOT auto-confirmed)', 'pending', $row->status ?? null);

    $notif = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}credoq_notifications WHERE data LIKE %s ORDER BY id DESC LIMIT 1", '%#' . $bid));
    if ($notif) {
        check('admin bell notification says "awaiting approval", not "confirmed"', true, strpos($notif->title, 'awaiting approval') !== false);
    }
} else {
    echo "manual-mode booking FAILED: " . ($r2['error'] ?? 'unknown') . "\n";
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
