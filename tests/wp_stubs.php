<?php
// Minimal WordPress function/environment stubs sufficient to exercise
// the seats plugin's repository + field logic without a real WP install.

define('ABSPATH', '/tmp/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

function __($s, $d = null) { return $s; }
function _e($s, $d = null) { echo $s; }
function _n($s, $p, $c, $d = null) { return $c > 1 ? $p : $s; }
function esc_html($s) { return htmlspecialchars((string)$s); }
function esc_attr($s) { return htmlspecialchars((string)$s); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_attr_e($s, $d = null) { echo esc_attr($s); }
function esc_url($s) { return $s; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s)); }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_email($s) { return filter_var($s, FILTER_SANITIZE_EMAIL); }
function absint($v) { return abs((int)$v); }
function wp_json_encode($v) { return json_encode($v); }
function get_current_user_id() { return 0; }
function wp_generate_password($len = 12, $special = true) { return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, $len); }
function current_time($fmt, $gmt = false) {
    if ($fmt === 'mysql') return gmdate('Y-m-d H:i:s');
    if ($fmt === 'Y-m-d') return gmdate('Y-m-d');
    if ($fmt === 'timestamp') return time();
    return gmdate('Y-m-d H:i:s');
}
function wp_date($f, $t = null) { 
    if ($t === null) $t = time();
    return date($f, $t); 
}
function date_i18n($f, $t = null) {
    if ($t === null) $t = time();
    return date($f, $t);
}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://test.local/wp-content/plugins/' . basename(dirname($f)) . '/'; }
function plugin_basename($f) { return basename($f); }
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function is_admin() { return false; }
function wp_unslash($s) { return $s; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function get_bloginfo($k) { return 'Test Site'; }
function get_option($k, $default = false) { return $default; }
function get_userdata($id) { return (object)['user_email' => 'test@example.com', 'display_name' => 'Test User']; }
function wp_kses_post($s) { return $s; }
function check_admin_referer($a) { return true; }
function admin_url($p = '') { return "http://test.local/wp-admin/$p"; }
function wp_mail($a,$b,$c) { global $mails; $mails[] = [$a,$b,$c]; return true; }

function apply_filters($tag, $value, ...$args) {
    global $__filters;
    if (!empty($__filters[$tag])) {
        foreach ($__filters[$tag] as $cb) $value = call_user_func($cb, $value, ...$args);
    }
    return $value;
}
function add_filter($tag, $cb, $prio = 10, $args = 1) {
    global $__filters;
    $__filters[$tag][] = $cb;
}
function add_action($tag, $cb, $prio = 10, $args = 1) {
    global $__actions;
    $__actions[$tag][] = $cb;
}
function remove_action($tag, $cb, $prio = 10) { return true; }
function do_action($tag, ...$args) {
    global $__actions;
    if (!empty($__actions[$tag])) foreach ($__actions[$tag] as $cb) call_user_func($cb, ...$args);
}

class WP_Error {
    public $code; public $message; public $data;
    function __construct($code = '', $message = '', $data = null) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    function get_error_message() { return $this->message; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }

class FakeWPDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $seat_bookings = [];
    public $seat_plans = [];
    public $seats = [];
    public $submissions = [];
    public $event_bookings = [];
    public $events = [];
    public $user_memberships = [];
    public $credit_ledger = [];
    public $bookings = [];
    public $notifications = [];
    public $appointments = [];
    public $mock_vars = [];
    public $mock_rows = [];
    public $users = 'wp_users';
    private $next_id = 1;

    function set_mock_var($q, $v) { $this->mock_vars[$q] = $v; }
    function set_mock_row($q, $v) { $this->mock_rows[$q] = $v; }

    function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return json_encode(['__q' => $query, '__args' => $args]);
    }

    function suppress_errors($s = true) {}

    private function decode($q) {
        $d = json_decode($q, true);
        $d = $d ?: ['__q' => $q, '__args' => []];
        $d['__q'] = str_replace($this->prefix . 'credoq_', 'credoq_', $d['__q']);
        $d['__q'] = preg_replace('/\s+/', ' ', trim($d['__q']));
        return $d;
    }

    function get_var($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];

        if (isset($this->mock_vars[$q])) return $this->mock_vars[$q];
        foreach ($this->mock_vars as $mq => $mv) { if (str_contains($query, $mq)) return $mv; }

        if (str_contains($query, 'SUM(amount) FROM credoq_credit_ledger')) {
            $sum = 0;
            foreach ($this->credit_ledger as $row) {
                if ($row['user_id'] == $args[0]) {
                    if (!isset($args[1]) || $row['plan_id'] == $args[1]) $sum += $row['amount'];
                }
            }
            return $sum;
        }
        if (str_contains($query, 'FROM credoq_submissions WHERE id')) {
            $row = $this->submissions[$args[0]] ?? null;
            if (!$row) return null;
            if (str_contains($query, 'SELECT form_id')) return $row['form_id'];
            return null;
        }
        if (str_contains($query, 'SELECT payload FROM credoq_submissions')) return $this->submissions[$args[0]]['payload'] ?? null;
        if (str_contains($query, 'SELECT submission_id FROM credoq_event_bookings')) return $this->event_bookings[$args[0]]['submission_id'] ?? null;
        return null;
    }

    function get_row($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];

        if (isset($this->mock_rows[$q])) return $this->mock_rows[$q];
        foreach ($this->mock_rows as $mq => $mv) { if (str_contains($query, $mq)) return $mv; }

        if (str_contains($query, 'FROM credoq_bookings WHERE id')) return $this->bookings[$args[0]] ?? null;
        if (str_contains($query, 'FROM credoq_event_bookings WHERE id')) return $this->event_bookings[$args[0]] ?? null;
        if (str_contains($query, 'FROM credoq_appointments WHERE id')) return $this->appointments[$args[0]] ?? null;
        if (str_contains($query, 'credoq_credit_ledger WHERE ref_id')) {
            foreach ($this->credit_ledger as $row) if ($row['ref_id'] == $args[0] && $row['type'] == 'use') return (object)$row;
            return null;
        }
        if (str_contains($query, 'FROM credoq_seat_plans WHERE id')) return (object) ($this->seat_plans[$args[0]] ?? []);
        return null;
    }

    function get_results($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];

        if (str_contains($query, 'FROM credoq_user_memberships')) {
            $results = [];
            foreach ($this->user_memberships as $row) {
                if ($row->user_id == $args[0] && $row->status == 'active') $results[] = (object) $row;
            }
            return $results;
        }
        if (str_contains($query, 'FROM credoq_events WHERE staff_id = %d')) {
             $results = [];
             foreach ($this->events as $e) {
                 if ($e->staff_id != $args[0]) continue;
                 // Date check (simplified for mock)
                 if (str_contains($e->start_datetime, $args[1]) || str_contains($e->end_datetime, $args[2])) {
                     $results[] = $e;
                 }
             }
             return $results;
        }
        if (str_contains($query, 'FROM credoq_bookings')) {
             $results = [];
             foreach ($this->bookings as $b) {
                 if (str_contains($query, 'appointment_id = %d') && $b->appointment_id != $args[0]) continue;
                 if (str_contains($query, 'selected_date = %s') && $b->selected_date != $args[1]) continue;
                 // Add more filters as needed for Booking_Repository::get_booked_slots
                 $results[] = (object)$b;
             }
             return $results;
        }
        return [];
    }

    function insert($table, $data, $formats = null) {
        $table = str_replace($this->prefix, '', $table);
        $id = $this->next_id++;
        $this->insert_id = $id;

        if ($table === 'credoq_credit_ledger') { $this->credit_ledger[$id] = $data; return 1; }
        if ($table === 'credoq_bookings') { $this->bookings[$id] = (object) array_merge(['id' => $id], $data); return 1; }
        if ($table === 'credoq_notifications') { $this->notifications[$id] = $data; return 1; }
        if ($table === 'credoq_appointments') { $this->appointments[$id] = (object) array_merge(['id' => $id], $data); return 1; }
        if ($table === 'credoq_event_bookings') { $this->event_bookings[$id] = (object) array_merge(['id' => $id], $data); return 1; }
        return 1;
    }

    function update($table, $data, $where) { return 1; }
    function query($q) { return 1; }
}
