<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Booking_Repository.php';
require PLUGINS_DIR . '/credoq-events-v3/includes/Event_Service.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Plan_Repository.php';

// Fake Membership_Service to isolate this test from the real ledger implementation —
// we're testing the ARGUMENTS Event_Service::register() passes, not the ledger itself.
class CallLog { public static $calls = []; }
eval('namespace CredoqMembership; class Membership_Service {
    public static function get_plan_status($user_id, $plan_id, $form_id) {
        \CallLog::$calls[] = ["get_plan_status", $user_id, $plan_id, $form_id];
        return ["remaining" => 100];
    }
    public static function deduct_credit($user_id, $plan_id, $amount, $note, $ref_id) {
        \CallLog::$calls[] = ["deduct_credit", $user_id, $plan_id, $amount, $ref_id];
        return 1;
    }
}');

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

// --- Bug #1 check: form_id passed to get_plan_status must be 0, not event_id ---
$wpdb->events[1] = ['id'=>1,'title'=>'Wine Tasting','price'=>0,'wc_product_id'=>0,'capacity'=>50,'credit_deduct_enabled'=>1,'credit_deduct_amount'=>2];
$result = Event_Service::register(1, 9, 3, 'Guest', 'g@test.com', 77);
$call = CallLog::$calls[0] ?? null;
check('register() called', true, $result['success'] ?? false);
check('get_plan_status is called with form_id=0, NOT event_id (1)', 0, $call[3] ?? 'MISSING');

// --- Bug #2 check: has_capacity() (seat-plan-aware) is used, not the old direct check ---
// Event #2: capacity=0 (unlimited from its own field), but a published seat plan caps it at 4.
$wpdb->events[2] = ['id'=>2,'title'=>'Small Workshop','price'=>0,'wc_product_id'=>0,'capacity'=>0,'credit_deduct_enabled'=>0,'credit_deduct_amount'=>0];
$wpdb->seat_plans[3] = ['id'=>3,'status'=>'published','connect_type'=>'event','connected_ids'=>json_encode([2]),'total_seats'=>4,'capacity_limit'=>0];

$r1 = Event_Service::register(2, 0, 4, 'A', 'a@test.com', 0);
check('registering exactly 4 (the seat-plan ceiling) succeeds even though event.capacity=0/unlimited', true, $r1['success']);

$r2 = Event_Service::register(2, 0, 1, 'B', 'b@test.com', 0);
check('one more registration is rejected — seat-plan ceiling (4) is enforced via has_capacity(), not bypassed', false, $r2['success']);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
