<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp_stubs.php';
require PLUGINS_DIR . '/credoq-engine-v3/includes/Abstracts/Field_Type.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Plan_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Seat_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Repositories/Booking_Repository.php';
require PLUGINS_DIR . '/credoq-seats/includes/Integrations/Appointments_Bridge.php';

eval('namespace CredoqAppointments; class Plugin {} class Appointment_Repository { public static $row; public static function find($id){ return self::$row; } }');

global $wpdb;
$wpdb = new FakeWPDB();
$wpdb->seat_plans[9] = ['id'=>9,'status'=>'published','connect_type'=>'appointment','connected_ids'=>json_encode([3]),'total_seats'=>5,'capacity_limit'=>0,'layout_json'=>json_encode(['pricing'=>['vip'=>40.00]])];
$wpdb->seats[] = ['id'=>20,'plan_id'=>9,'seat_type'=>'vip','price_override'=>null,'seat_label'=>'B1'];
$wpdb->seats[] = ['id'=>21,'plan_id'=>9,'seat_type'=>'standard','price_override'=>18.00,'seat_label'=>'B2'];

// bookings row: total_price is irrelevant now (used to drive the buggy average) — set it to something that would give a WRONG average to prove it's no longer used.
$wpdb->get_row_override = null;
class FakeWPDB_Bk extends FakeWPDB {
    public $bookings = [];
    function get_row($q) {
        $d = null; $ref = new ReflectionClass('FakeWPDB'); $m=$ref->getMethod('decode'); $m->setAccessible(true); $d=$m->invoke($this,$q);
        if (str_contains($d['__q'], 'credoq_bookings WHERE id')) {
            $row = $this->bookings[$d['__args'][0]] ?? null;
            return $row ? (object)$row : null;
        }
        return parent::get_row($q);
    }
    function get_var($q) {
        $d = null; $ref = new ReflectionClass('FakeWPDB'); $m=$ref->getMethod('decode'); $m->setAccessible(true); $d=$m->invoke($this,$q);
        if (str_contains($d['__q'], 'booking_settings FROM')) return json_encode(['visual_seats_enabled'=>1,'seat_plan_id'=>9]);
        if (str_contains($d['__q'], 'SHOW TABLES')) return $d['__args'][0]; // pretend table exists
        return parent::get_var($q);
    }
}
$wpdb = new FakeWPDB_Bk();
$wpdb->seat_plans[9] = ['id'=>9,'status'=>'published','connect_type'=>'appointment','connected_ids'=>json_encode([3]),'total_seats'=>5,'capacity_limit'=>0,'layout_json'=>json_encode(['pricing'=>['vip'=>40.00]])];
$wpdb->seats[] = ['id'=>20,'plan_id'=>9,'seat_type'=>'vip','price_override'=>null,'seat_label'=>'B1'];
$wpdb->seats[] = ['id'=>21,'plan_id'=>9,'seat_type'=>'standard','price_override'=>18.00,'seat_label'=>'B2'];
$wpdb->bookings[42] = ['id'=>42,'appointment_id'=>3,'seat_ids'=>json_encode([20,21]),'selected_date'=>'2026-09-10','selected_time'=>'10:00','user_id'=>0,'guest_email'=>'g@test.com','total_price'=>999.00,'wc_order_id'=>0];

\CredoqAppointments\Appointment_Repository::$row = (object)['id'=>3,'base_price'=>25.00];

use CredoqSeats\Integrations\Appointments_Bridge;
Appointments_Bridge::register();

$fails=0;
function check($l,$e,$a){global $fails; $ok=$e==$a; echo ($ok?"PASS":"FAIL")." — $l (expected ".var_export($e,true).", got ".var_export($a,true).")\n"; if(!$ok)$fails++;}

Appointments_Bridge::on_confirmed(42);

$row20=null;$row21=null;
foreach($wpdb->seat_bookings as $r){ if($r['seat_id']==20) $row20=$r; if($r['seat_id']==21) $row21=$r; }
check('seat 20 (VIP, no override) gets its own type price (40.00), not an average of 999/2', 40.00, $row20['price_charged'] ?? null);
check('seat 21 (explicit override) gets its own override price (18.00), not an average', 18.00, $row21['price_charged'] ?? null);
check('both seats confirmed', 'confirmed', $row20['status'] ?? null);

echo $fails===0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails===0?0:1);
