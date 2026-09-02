<?php
require_once __DIR__ . '/wp-load.php';

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected === $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label\n";
    if (!$ok) { echo "   expected: " . var_export($expected,true) . " got: " . var_export($actual,true) . "\n"; $fails++; }
}

// ---------- Engine: ip_block_enabled ----------
$_SERVER['REMOTE_ADDR'] = '203.0.113.55';

update_option('credoq_engine_settings', ['ip_block_enabled' => 0, 'ip_blocklist' => "203.0.113.55\n"]);
$r1 = \CredoqEngine\Security\Gate::check(['form_id' => 1], []);
check('ip_block_enabled=OFF: blocklisted IP is allowed through', true, true === $r1);

update_option('credoq_engine_settings', ['ip_block_enabled' => 1, 'ip_blocklist' => "203.0.113.55\n"]);
$r2 = \CredoqEngine\Security\Gate::check(['form_id' => 1], []);
check('ip_block_enabled=ON: blocklisted IP is rejected', true, is_wp_error($r2));

update_option('credoq_engine_settings', ['ip_block_enabled' => 1, 'ip_blocklist' => "198.51.100.1\n"]);
$r3 = \CredoqEngine\Security\Gate::check(['form_id' => 1], []);
check('ip_block_enabled=ON but IP not on the list: allowed through', true, true === $r3);

// ---------- Events: wc_product_id (0 = free, >0 = WC-paid) ----------
$event_free_id = \CredoqEvents\Event_Repository::save([
    'title' => 'Free Meetup', 'price' => 0, 'wc_product_id' => 0,
    'capacity' => 0, 'credit_deduct_enabled' => 0, 'status' => 'published',
    'start_datetime' => date('Y-m-d H:i:s', strtotime('+10 days')),
]);
$r4 = \CredoqEvents\Event_Service::register($event_free_id, 0, 1, 'Guest', 'g@test.test');
check('wc_product_id=0: registration confirms immediately, no WC', true, ($r4['success'] ?? false) && !($r4['use_wc'] ?? true));

$event_paid_id = \CredoqEvents\Event_Repository::save([
    'title' => 'Paid Concert', 'price' => 20, 'wc_product_id' => 999,
    'capacity' => 0, 'credit_deduct_enabled' => 0, 'status' => 'published',
    'start_datetime' => date('Y-m-d H:i:s', strtotime('+10 days')),
]);
$r5 = \CredoqEvents\Event_Service::register($event_paid_id, 0, 1, 'Guest2', 'g2@test.test');
check('wc_product_id set + price>0: use_wc is true', true, ($r5['success'] ?? false) && ($r5['use_wc'] ?? false));

// ---------- Events: capacity ceiling ----------
$event_cap_id = \CredoqEvents\Event_Repository::save([
    'title' => 'Small Room', 'price' => 0, 'wc_product_id' => 0,
    'capacity' => 2, 'credit_deduct_enabled' => 0, 'status' => 'published',
    'start_datetime' => date('Y-m-d H:i:s', strtotime('+10 days')),
]);
$r6 = \CredoqEvents\Event_Service::register($event_cap_id, 0, 2, 'A', 'a@test.test');
check('capacity=2: registering exactly 2 succeeds', true, $r6['success'] ?? false);
$r7 = \CredoqEvents\Event_Service::register($event_cap_id, 0, 1, 'B', 'b@test.test');
check('capacity=2 (already full): registering 1 more is rejected', false, $r7['success'] ?? true);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
