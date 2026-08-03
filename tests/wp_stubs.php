<?php
// Minimal WordPress function/environment stubs sufficient to exercise
// the seats plugin's repository + field logic without a real WP install.

define('ABSPATH', '/tmp/');
define('MINUTE_IN_SECONDS', 60);

function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string)$s); }
function esc_attr($s) { return htmlspecialchars((string)$s); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_url($s) { return $s; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s)); }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_email($s) { return filter_var($s, FILTER_SANITIZE_EMAIL); }
function absint($v) { return abs((int)$v); }
function wp_json_encode($v) { return json_encode($v); }
function get_current_user_id() { return 0; }
function wp_generate_password($len = 12, $special = true) { return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, $len); }
function current_time($fmt, $gmt = false) {
    if ($fmt === 'Y-m-d') return gmdate('Y-m-d');
    return gmdate('Y-m-d H:i:s');
}
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

/**
 * Fake $wpdb — enough of the surface (get_var/get_row/get_col/get_results/
 * insert/update/query/prepare) to run Booking_Repository & friends against
 * an in-memory table set. Not a real SQL engine — prepare() just does
 * sprintf-style substitution, and each method below is hand-matched to the
 * specific queries these classes issue (this is a purpose-built test
 * double, not a general one).
 */
class FakeWPDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $seat_bookings = []; // id => row (array)
    public $seat_plans = [];
    public $seats = [];
    public $submissions = [];
    public $event_bookings = [];
    public $events = [];
    private $next_id = 1;

    function prepare($query, ...$args) {
        // Flatten a single array arg (some callers pass an array of params)
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return json_encode(['__q' => $query, '__args' => $args]); // encode query+args for our fake executor
    }

    private function decode($q) {
        $d = json_decode($q, true);
        $d = $d ?: ['__q' => $q, '__args' => []];
        // Normalize away the interpolated table prefix (real queries contain
        // literal 'wp_credoq_...' once {$wpdb->prefix} is interpolated) so
        // every match below can just look for 'credoq_<table>' regardless
        // of what sits between 'FROM'/'JOIN' and the table name.
        $d['__q'] = str_replace($this->prefix . 'credoq_', 'credoq_', $d['__q']);
        return $d;
    }

    function get_var($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];

        if (str_contains($query, 'FROM credoq_submissions WHERE id')) {
            $row = $this->submissions[$args[0]] ?? null;
            if (!$row) return null;
            if (str_contains($query, 'SELECT form_id')) return $row['form_id'];
            return null;
        }
        if (str_contains($query, 'SELECT payload FROM credoq_submissions')) {
            return $this->submissions[$args[0]]['payload'] ?? null;
        }
        if (str_contains($query, 'SELECT submission_id FROM credoq_event_bookings')) {
            return $this->event_bookings[$args[0]]['submission_id'] ?? null;
        }
        if (str_contains($query, 'DELETE FROM')) { return $this->fake_delete($query, $args); }
        if (str_contains($query, 'SELECT COUNT(*)')) { return $this->count_bookings($query, $args); }
        if (str_contains($query, 'SELECT id FROM') && str_contains($query,'credoq_seat_bookings')) {
            return $this->find_existing_booking_id($query, $args);
        }
        if (str_contains($query, 'SELECT COALESCE(SUM(quantity)')) {
            $event_id = $args[0];
            $sum = 0;
            foreach ($this->event_bookings as $b) {
                if ($b['event_id'] == $event_id && !in_array($b['status'], ['cancelled','refunded'])) $sum += $b['quantity'];
            }
            return $sum;
        }
        return null;
    }

    function get_row($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];
        if (str_contains($query, 'credoq_event_bookings eb WHERE eb.id')) {
            $row = $this->event_bookings[$args[0]] ?? null;
            return $row ? (object) $row : null;
        }
        if (str_contains($query, 'SELECT form_id, payload FROM credoq_submissions')) {
            $row = $this->submissions[$args[0]] ?? null;
            return $row ? (object) $row : null;
        }
        if (str_contains($query, 'FROM credoq_events WHERE id')) {
            $row = $this->events[$args[0]] ?? null;
            return $row ? (object) $row : null;
        }
        if (str_contains($query, 'FROM credoq_event_bookings WHERE id')) {
            $row = $this->event_bookings[$args[0]] ?? null;
            return $row ? (object) $row : null;
        }
        if (str_contains($query, 'FROM credoq_seat_plans WHERE id')) {
            $row = $this->seat_plans[$args[0]] ?? null;
            return $row ? (object) $row : null;
        }
        return null;
    }

    function get_col($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];
        if (str_contains($query, 'SELECT seat_id FROM')) {
            return $this->booked_seat_ids($query, $args);
        }
        if (str_contains($query, 'SELECT seat_label FROM')) {
            $out = [];
            foreach ($this->seats as $s) if (in_array($s['id'], $args)) $out[] = $s['seat_label'];
            return $out;
        }
        return [];
    }

    function get_results($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];
        if (str_contains($query, 'FROM credoq_seats WHERE id IN')) {
            $out = [];
            foreach ($this->seats as $s) if (in_array($s['id'], $args)) $out[] = (object) $s;
            return $out;
        }
        if (str_contains($query, 'FROM credoq_seat_plans WHERE')) {
            $filters = [];
            $ai = 0;
            if (str_contains($query, 'status = %s')) { $filters['status'] = $args[$ai++] ?? null; }
            if (str_contains($query, 'connect_type = %s')) { $filters['connect_type'] = $args[$ai++] ?? null; }
            $out = [];
            foreach ($this->seat_plans as $p) {
                $match = true;
                foreach ($filters as $k => $v) if (($p[$k] ?? null) !== $v) { $match = false; break; }
                if ($match) $out[] = (object) $p;
            }
            return $out;
        }
        if (str_contains($query, 'FROM credoq_event_bookings WHERE submission_id')) {
            $out = [];
            foreach ($this->event_bookings as $id => $b) if (($b['submission_id'] ?? null) == $args[0]) { $b['id'] = $id; $out[] = (object) $b; }
            return $out;
        }
        return [];
    }

    function insert($table, $data, $formats = null) {
        $table = str_replace($this->prefix, '', $table);
        if ($table === 'credoq_seat_bookings') {
            $id = $this->next_id++;
            $data['id'] = $id;
            $this->seat_bookings[$id] = $data;
            $this->insert_id = $id;
            return 1;
        }
        if ($table === 'credoq_event_bookings') {
            $id = $this->next_id++;
            $this->event_bookings[$id] = $data;
            $this->insert_id = $id;
            return 1;
        }
        return false;
    }

    function update($table, $data, $where) {
        $table = str_replace($this->prefix, '', $table);
        if ($table === 'credoq_seat_bookings') {
            if (isset($where['id'])) {
                $this->seat_bookings[$where['id']] = array_merge($this->seat_bookings[$where['id']], $data);
                return 1;
            }
            // cancel_for_ref style update: booking_type + ref_id
            $n = 0;
            foreach ($this->seat_bookings as $id => $row) {
                $match = true;
                foreach ($where as $k => $v) if (($row[$k] ?? null) != $v) { $match = false; break; }
                if ($match) { $this->seat_bookings[$id] = array_merge($row, $data); $n++; }
            }
            return $n;
        }
        if ($table === 'credoq_event_bookings') {
            $n = 0;
            foreach ($this->event_bookings as $id => $row) {
                $match = true;
                foreach ($where as $k => $v) if (($row[$k] ?? null) != $v) { $match = false; break; }
                if ($match) { $this->event_bookings[$id] = array_merge($row, $data); $n++; }
            }
            return $n;
        }
        return 0;
    }

    function query($q) {
        $d = $this->decode($q);
        $query = $d['__q'];
        if (str_contains($query, 'DELETE FROM')) { $this->fake_delete($query, $d['__args']); return 1; }
        return 0;
    }

    // ---- helpers matching the exact queries Booking_Repository issues ----

    private function count_bookings($query, $args) {
        // args order: seat_id, date, time, [event_id, event_id,] now
        $seat_id = $args[0]; $date = $args[1]; $time = $args[2];
        $has_event_scope = str_contains($query, 'event_id = %d');
        $idx = 3;
        $event_id = 0;
        if ($has_event_scope) { $event_id = $args[3]; $idx = 5; }
        $now = $args[$idx] ?? gmdate('Y-m-d H:i:s');
        $n = 0;
        foreach ($this->seat_bookings as $row) {
            if ($row['seat_id'] != $seat_id) continue;
            if ($row['date_context'] !== $date) continue;
            if (($row['time_context'] ?? null) !== $time) continue;
            if ($has_event_scope && $event_id != 0 && ($row['event_id'] ?? 0) != $event_id) continue;
            if ($row['status'] === 'confirmed' || ($row['status'] === 'held' && $row['held_until'] >= $now)) $n++;
        }
        return $n;
    }

    private function fake_delete($query, $args) {
        if (str_contains($query, "status = 'held' AND held_until <")) {
            $seat_id = $args[0]; $date = $args[1]; $time = $args[2];
            $has_event_scope = str_contains($query, 'event_id = %d');
            $event_id = $has_event_scope ? $args[3] : 0;
            $now = $args[count($args)-1];
            foreach ($this->seat_bookings as $id => $row) {
                if ($row['seat_id'] != $seat_id || $row['date_context'] !== $date) continue;
                if (($row['time_context'] ?? null) !== $time) continue;
                if ($has_event_scope && $event_id != 0 && ($row['event_id'] ?? 0) != $event_id) continue;
                if ($row['status'] === 'held' && $row['held_until'] < $now) unset($this->seat_bookings[$id]);
            }
            return true;
        }
        if (str_contains($query, "status = 'held'") && !str_contains($query, 'held_until <')) {
            // release_seat: DELETE ... AND status = 'held' (no held_until condition)
            $seat_id = $args[0]; $date = $args[1]; $time = $args[2];
            $has_event_scope = str_contains($query, 'event_id = %d');
            $event_id = $has_event_scope ? $args[3] : 0;
            foreach ($this->seat_bookings as $id => $row) {
                if ($row['seat_id'] != $seat_id || $row['date_context'] !== $date) continue;
                if (($row['time_context'] ?? null) !== $time) continue;
                if ($has_event_scope && $event_id != 0 && ($row['event_id'] ?? 0) != $event_id) continue;
                if ($row['status'] === 'held') unset($this->seat_bookings[$id]);
            }
            return true;
        }
        return true;
    }

    private function find_existing_booking_id($query, $args) {
        $seat_id = $args[0]; $date = $args[1]; $time = $args[2];
        $has_event_scope = str_contains($query, 'event_id = %d');
        $event_id = $has_event_scope ? $args[3] : 0;
        $candidates = [];
        foreach ($this->seat_bookings as $id => $row) {
            if ($row['seat_id'] != $seat_id || $row['date_context'] !== $date) continue;
            if (($row['time_context'] ?? null) !== $time) continue;
            if ($has_event_scope && $event_id != 0 && ($row['event_id'] ?? 0) != $event_id) continue;
            if (in_array($row['status'], ['held','confirmed'])) $candidates[$id] = $row;
        }
        if (empty($candidates)) return null;
        // ORDER BY status DESC (confirmed > held alphabetically DESC: 'held' < 'confirmed'? 'h'<'c' is false... just mimic "prefer confirmed")
        uasort($candidates, fn($a,$b) => strcmp($b['status'], $a['status']));
        return array_key_first($candidates);
    }

    private function booked_seat_ids($query, $args) {
        $plan_id = $args[0]; $date = $args[1]; $time = $args[2];
        $has_event_scope = str_contains($query, 'event_id = %d');
        $idx = 4; // after plan_id,date,time,time(second)
        $event_id = 0;
        if ($has_event_scope) { $event_id = $args[4]; $idx = 6; }
        $now = $args[$idx] ?? gmdate('Y-m-d H:i:s');
        $out = [];
        foreach ($this->seat_bookings as $row) {
            if ($row['plan_id'] != $plan_id || $row['date_context'] !== $date) continue;
            if ($time !== '' && ($row['time_context'] ?? '') !== $time) continue;
            if ($has_event_scope && $event_id != 0 && ($row['event_id'] ?? 0) != $event_id) continue;
            if ($row['status'] === 'confirmed' || ($row['status'] === 'held' && $row['held_until'] >= $now)) $out[] = $row['seat_id'];
        }
        return $out;
    }
}
