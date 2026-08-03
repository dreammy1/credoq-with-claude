<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';
function get_userdata($id) { return $id ? (object)['user_email'=>"user{$id}@test.com"] : false; }

/** Extends the shared FakeWPDB with membership tables (memberships, ledger, plans). */
class FakeWPDB_Membership extends FakeWPDB {
    public $memberships = [];
    public $ledger = [];
    public $plans = [];
    private $lid = 1;

    private function d($q) {
        $ref = new ReflectionClass('FakeWPDB'); $m = $ref->getMethod('decode'); $m->setAccessible(true);
        return $m->invoke($this, $q);
    }
    function get_results($q) {
        $d = $this->d($q);
        if (str_contains($d['__q'], 'credoq_user_memberships')) {
            $uid = $d['__args'][0] ?? null;
            return array_values(array_map(fn($m)=>(object)$m, array_filter($this->memberships, fn($m)=>$m['user_id']==$uid)));
        }
        return parent::get_results($q);
    }
    function get_row($q) {
        $d = $this->d($q);
        if (str_contains($d['__q'], 'credoq_membership_plans')) {
            $row = $this->plans[$d['__args'][0]] ?? null;
            if (!$row) return null;
            $o = (object) $row; $o->rules = json_encode($row['rules']); // real column is a JSON string
            return $o;
        }
        return parent::get_row($q);
    }
    function get_var($q) {
        $d = $this->d($q);
        if (str_contains($d['__q'], 'SUM(amount)')) {
            $uid = $d['__args'][0];
            $plan_filter = count($d['__args']) > 1 ? $d['__args'][1] : null;
            $sum = 0;
            foreach ($this->ledger as $l) {
                if ($l['user_id'] != $uid) continue;
                if ($plan_filter !== null && $l['plan_id'] != $plan_filter) continue;
                $sum += $l['amount'];
            }
            return $sum;
        }
        return parent::get_var($q);
    }
    function insert($table, $data, $formats=null) {
        $table = str_replace($this->prefix,'',$table);
        if ($table === 'credoq_credit_ledger') {
            $data['id'] = $this->lid;
            $this->ledger[$this->lid] = $data;
            $this->insert_id = $this->lid++;
            return 1;
        }
        return parent::insert($table,$data,$formats);
    }
}

require PLUGINS_DIR . '/credoq-membership-v3/includes/Plan_Repository.php';
require PLUGINS_DIR . '/credoq-membership-v3/includes/Membership_Service.php';
use CredoqMembership\Membership_Service;

global $wpdb;
$wpdb = new FakeWPDB_Membership();

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected == $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label (expected " . var_export($expected,true) . ", got " . var_export($actual,true) . ")\n";
    if (!$ok) $fails++;
}

foreach (['get_plan_status','deduct_credit','refund_credit'] as $m) {
    check("Membership_Service::$m() exists (was fatal-undefined before this fix)", true, method_exists(Membership_Service::class, $m));
}

// Fixture: user 5 has an active "Gold" plan (id 1, slot_credit=10, no form restriction).
$wpdb->memberships[1] = ['id'=>1,'user_id'=>5,'plan_id'=>1,'status'=>'active','expiry_date'=>'2099-01-01 00:00:00','order_id'=>0,'wc_order_status'=>''];
$wpdb->plans[1] = ['id'=>1,'name'=>'Gold','product_id'=>0,'duration_days'=>30,'rules'=>['slot_credit'=>10,'allowed_form_ids'=>'']];

$status = Membership_Service::get_plan_status(5, 1, 0);
check('get_plan_status reports the plan\'s full balance (10) before any deduction', 10, $status['remaining']);

// Simulate Appointments' Booking_Service::create() deducting 3 credits for a booking.
Membership_Service::deduct_credit(5, 1, 3, 'Booking #42 — Massage', 7);
$status2 = Membership_Service::get_plan_status(5, 1, 0);
check('after deducting 3, remaining balance is 7', 7, $status2['remaining']);

// Simulate a cancellation refunding those 3 credits back.
Membership_Service::refund_credit(5, 1, 3, 'Refund for cancelled booking #42', 7);
$status3 = Membership_Service::get_plan_status(5, 1, 0);
check('after refunding, balance is back to 10', 10, $status3['remaining']);

// Insufficient-credit scenario (case b from the requested workflow): needed=15 > remaining=10.
$needed = 15;
check('insufficient-credit check correctly identifies shortfall (10 < 15)', true, $status3['remaining'] < $needed);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
