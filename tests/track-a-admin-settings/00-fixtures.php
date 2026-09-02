<?php
require_once __DIR__ . '/wp-load.php';

// Pick a date 14 days out and make sure the fixture's availability JSON
// declares that specific weekday open, regardless of what day "today" is.
$test_date = date('Y-m-d', strtotime('+14 days'));
$dow = strtolower(date('l', strtotime($test_date)));

$hours = ['start' => '09:00', 'end' => '18:00'];
$avail = [];
foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $d) {
    $avail[$d] = ['closed' => false, 'hours' => [$hours]];
}

$staff_id = \CredoqAppointments\Staff_Repository::save([
    'display_name' => 'Test Practitioner',
    'email' => 'staff@example.test',
    'availability' => $avail,
    'price_multiplier' => 1.0,
]);

$apt_id = \CredoqAppointments\Appointment_Repository::save([
    'title' => 'Test Consultation',
    'duration' => 60,
    'slot_interval' => 60,
    'max_bookings' => 5,
    'base_price' => 50.00,
    'wc_product_id' => 0,
    'staff_ids' => [$staff_id],
    'availability' => $avail,
    'capacity_mode' => 'per_staff',
    'capacity_value' => 5,
    'credit_deduct_enabled' => 0,
    'credit_deduct_amount' => 1,
]);

file_put_contents(__DIR__ . '/track-a-fixture-ids.json', json_encode([
    'staff_id' => $staff_id,
    'appointment_id' => $apt_id,
    'test_date' => $test_date,
    'test_time' => '10:00',
]));

echo "staff_id=$staff_id apt_id=$apt_id date=$test_date ($dow)\n";
