<?php
/**
 * Minimal WordPress environment stubs for unit testing.
 * Provides a FakeWPDB and common WP functions.
 */

if (!defined('ABSPATH')) define('ABSPATH', '/tmp/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

if (!function_exists('__')) { function __($s, $d = null) { return $s; } }
if (!function_exists('_e')) { function _e($s, $d = null) { echo $s; } }
if (!function_exists('_x')) { function _x($s, $c, $d = null) { return $s; } }
if (!function_exists('_n')) { function _n($s, $p, $c, $d = null) { return $c > 1 ? $p : $s; } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string)$s); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string)$s); } }
if (!function_exists('esc_html_e')) { function esc_html_e($s, $d = null) { echo esc_html($s); } }
if (!function_exists('esc_attr_e')) { function esc_attr_e($s, $d = null) { echo esc_attr($s); } }
if (!function_exists('esc_url')) { function esc_url($s) { return $s; } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string)$s)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string)$s)); } }
if (!function_exists('sanitize_email')) { function sanitize_email($s) { return filter_var($s, FILTER_SANITIZE_EMAIL); } }
if (!function_exists('absint')) { function absint($v) { return abs((int)$v); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }
if (!function_exists('wp_generate_password')) { function wp_generate_password($len = 12, $special = true) { return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, $len); } }
if (!function_exists('current_time')) { 
    function current_time($fmt, $gmt = false) {
        if ($fmt === 'mysql') return gmdate('Y-m-d H:i:s');
        if ($fmt === 'Y-m-d') return gmdate('Y-m-d');
        if ($fmt === 'timestamp') return time();
        return gmdate('Y-m-d H:i:s');
    }
}
if (!function_exists('wp_date')) { 
    function wp_date($f, $t = null) { 
        if ($t === null) $t = time();
        return date($f, $t); 
    }
}
if (!function_exists('date_i18n')) {
    function date_i18n($f, $t = null) {
        if ($t === null) $t = time();
        return date($f, $t);
    }
}
if (!function_exists('plugin_dir_path')) { function plugin_dir_path($f) { return dirname($f) . '/'; } }
if (!function_exists('plugin_dir_url')) { function plugin_dir_url($f) { return 'http://test.local/wp-content/plugins/' . basename(dirname($f)) . '/'; } }
if (!function_exists('plugin_basename')) { function plugin_basename($f) { return basename($f); } }
if (!function_exists('register_activation_hook')) { function register_activation_hook($f, $cb) {} }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook($f, $cb) {} }
if (!function_exists('is_admin')) { function is_admin() { return false; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return false; } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return $s; } }
if (!function_exists('untrailingslashit')) { function untrailingslashit($s) { return rtrim($s, '/'); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
if (!function_exists('get_bloginfo')) { function get_bloginfo($k) { return 'Test Site'; } }
if (!function_exists('get_option')) { function get_option($k, $default = false) { return $default; } }
if (!function_exists('update_option')) { function update_option($k, $v) { return true; } }
if (!function_exists('get_userdata')) { function get_userdata($id) { return (object)['user_email' => 'test@example.com', 'display_name' => 'Test User']; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($s) { return $s; } }
if (!function_exists('check_admin_referer')) { function check_admin_referer($a) { return true; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return "http://test.local/wp-admin/$p"; } }
if (!function_exists('wp_mail')) { function wp_mail($a,$b,$c) { global $mails; $mails[] = [$a,$b,$c]; return true; } }
if (!function_exists('wp_verify_nonce')) { function wp_verify_nonce($n, $a) { return true; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a) { return 'mock-nonce'; } }
if (!function_exists('wp_die')) { function wp_die($m) { die($m); } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        global $__filters;
        if (!empty($__filters[$tag])) {
            foreach ($__filters[$tag] as $cb) $value = call_user_func($cb, $value, ...$args);
        }
        return $value;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $cb, $prio = 10, $args = 1) {
        global $__filters;
        $__filters[$tag][] = $cb;
    }
}
if (!function_exists('add_action')) {
    function add_action($tag, $cb, $prio = 10, $args = 1) {
        global $__actions;
        $__actions[$tag][] = $cb;
    }
}
if (!function_exists('remove_action')) { function remove_action($tag, $cb, $prio = 10) { return true; } }
if (!function_exists('do_action')) {
    function do_action($tag, ...$args) {
        global $__actions;
        if (!empty($__actions[$tag])) foreach ($__actions[$tag] as $cb) call_user_func($cb, ...$args);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        function __construct($code = '', $message = '', $data = null) {
            $this->code = $code; $this->message = $message; $this->data = $data;
        }
        function get_error_message() { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($v) { return $v instanceof WP_Error; } }

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
        
        if (str_contains($query, 'SELECT COUNT(*)')) { return $this->count_bookings($query, $args); }
        if (str_contains($query, 'SELECT id FROM') && str_contains($query,'credoq_seat_bookings')) {
            return $this->find_existing_booking_id($query, $args);
        }
        if (str_contains($query, 'SELECT COALESCE(SUM(quantity)')) {
            $event_id = $args[0];
            $sum = 0;
            foreach ($this->event_bookings as $b) {
                $b = (array)$b;
                if ($b['event_id'] == $event_id && !in_array($b['status'], ['cancelled','refunded'])) $sum += $b['quantity'];
            }
            return $sum;
        }
        if (str_contains($query, 'SHOW TABLES LIKE')) return $args[0];

        return null;
    }

    function get_row($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];

        $res = null;
        if (isset($this->mock_rows[$q])) $res = $this->mock_rows[$q];
        else {
            foreach ($this->mock_rows as $mq => $mv) { if (str_contains($query, $mq)) { $res = $mv; break; } }
        }

        if (!$res) {
            if (str_contains($query, 'FROM credoq_bookings WHERE id')) $res = $this->bookings[$args[0]] ?? null;
            elseif (str_contains($query, 'FROM credoq_event_bookings WHERE id')) $res = $this->event_bookings[$args[0]] ?? null;
            elseif (str_contains($query, 'eb WHERE eb.id')) $res = $this->event_bookings[$args[0]] ?? null;
            elseif (str_contains($query, 'FROM credoq_appointments WHERE id')) $res = $this->appointments[$args[0]] ?? null;
            elseif (str_contains($query, 'credoq_credit_ledger WHERE ref_id')) {
                foreach ($this->credit_ledger as $row) if ($row['ref_id'] == $args[0] && $row['type'] == 'use') { $res = $row; break; }
            }
            elseif (str_contains($query, 'FROM credoq_seat_plans WHERE id')) $res = $this->seat_plans[$args[0]] ?? null;
            elseif (str_contains($query, 'FROM credoq_events WHERE id')) $res = $this->events[$args[0]] ?? null;
            elseif (str_contains($query, 'SELECT form_id, payload FROM credoq_submissions')) $res = $this->submissions[$args[0]] ?? null;
        }

        return $res ? (object)$res : null;
    }

    function get_results($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];

        if (str_contains($query, 'FROM credoq_user_memberships')) {
            $results = [];
            foreach ($this->user_memberships as $row) {
                $row = (array)$row;
                if ($row['user_id'] == $args[0] && $row['status'] == 'active') $results[] = (object) $row;
            }
            return $results;
        }
        if (str_contains($query, 'FROM credoq_bookings')) {
             $results = [];
             foreach ($this->bookings as $b) {
                 $b = (array)$b;
                 if (str_contains($query, 'appointment_id = %d') && $b['appointment_id'] != $args[0]) continue;
                 if (str_contains($query, 'selected_date = %s') && $b['selected_date'] != $args[1]) continue;
                 $results[] = (object)$b;
             }
             return $results;
        }
        if (str_contains($query, 'FROM credoq_events WHERE staff_id = %d')) {
             $results = [];
             foreach ($this->events as $e) {
                 $e = (array)$e;
                 if ($e['staff_id'] != $args[0]) continue;
                 if (str_contains($e['start_datetime'], $args[1]) || str_contains($e['end_datetime'], $args[2])) $results[] = (object)$e;
             }
             return $results;
        }
        if (str_contains($query, 'FROM credoq_events')) {
            $results = [];
            foreach ($this->events as $e) {
                $e = (array)$e;
                if (str_contains($query, "status = 'published'") && $e['status'] !== 'published') continue;
                $results[] = (object)$e;
            }
            return $results;
        }
        if (str_contains($query, 'FROM credoq_seats WHERE id IN')) {
            $out = [];
            foreach ($this->seats as $s) {
                $s = (array)$s;
                if (in_array($s['id'], $args)) $out[] = (object) $s;
            }
            return $out;
        }
        if (str_contains($query, 'FROM credoq_seat_plans WHERE')) {
            $filters = [];
            $ai = 0;
            if (str_contains($query, 'status = %s')) { $filters['status'] = $args[$ai++] ?? null; }
            if (str_contains($query, 'connect_type = %s')) { $filters['connect_type'] = $args[$ai++] ?? null; }
            $out = [];
            foreach ($this->seat_plans as $p) {
                $p = (array)$p;
                $match = true;
                foreach ($filters as $k => $v) if (($p[$k] ?? null) !== $v) { $match = false; break; }
                if ($match) $out[] = (object) $p;
            }
            return $out;
        }
        if (str_contains($query, 'FROM credoq_event_bookings WHERE submission_id')) {
            $out = [];
            foreach ($this->event_bookings as $id => $b) {
                $b = (array)$b;
                if (($b['submission_id'] ?? null) == $args[0]) { $b['id'] = $id; $out[] = (object) $b; }
            }
            return $out;
        }
        return [];
    }

    function get_col($q) {
        $d = $this->decode($q);
        $query = $d['__q']; $args = $d['__args'];
        if (str_contains($query, 'SELECT id FROM credoq_bookings WHERE submission_id')) {
            $out = [];
            foreach ($this->bookings as $id => $b) {
                $b = (array)$b;
                if (($b['submission_id'] ?? 0) == $args[0]) $out[] = $id;
            }
            return $out;
        }
        if (str_contains($query, 'SELECT seat_id FROM credoq_seat_bookings')) {
            return $this->booked_seat_ids($query, $args);
        }
        if (str_contains($query, 'SELECT seat_label FROM credoq_seats')) {
            $out = [];
            foreach ($this->seats as $s) {
                $s = (array)$s;
                if (in_array($s['id'], $args)) $out[] = $s['seat_label'];
            }
            return $out;
        }
        return [];
    }

    function insert($table, $data, $formats = null) {
        $table = str_replace($this->prefix, '', $table);
        $id = $this->next_id++;
        $this->insert_id = $id;

        if ($table === 'credoq_credit_ledger') { $this->credit_ledger[$id] = $data; return 1; }
        if ($table === 'credoq_bookings') { $this->bookings[$id] = array_merge(['id' => $id], $data); return 1; }
        if ($table === 'credoq_notifications') { $this->notifications[$id] = $data; return 1; }
        if ($table === 'credoq_appointments') { $this->appointments[$id] = array_merge(['id' => $id], $data); return 1; }
        if ($table === 'credoq_event_bookings') { $this->event_bookings[$id] = array_merge(['id' => $id], $data); return 1; }
        if ($table === 'credoq_seat_bookings') { $this->seat_bookings[$id] = $data; return 1; }
        if ($table === 'credoq_events') { $this->events[$id] = array_merge(['id' => $id], $data); return 1; }
        return 1;
    }

    function update($table, $data, $where) {
        $table = str_replace($this->prefix, '', $table);
        if ($table === 'credoq_seat_bookings') {
            if (isset($where['id'])) {
                $this->seat_bookings[$where['id']] = array_merge((array)$this->seat_bookings[$where['id']], $data);
                return 1;
            }
            $n = 0;
            foreach ($this->seat_bookings as $id => $row) {
                $row = (array)$row;
                $match = true;
                foreach ($where as $k => $v) if (($row[$k] ?? null) != $v) { $match = false; break; }
                if ($match) { $this->seat_bookings[$id] = array_merge($row, $data); $n++; }
            }
            return $n;
        }
        if ($table === 'credoq_event_bookings') {
            $n = 0;
            foreach ($this->event_bookings as $id => $row) {
                $row = (array)$row;
                $match = true;
                foreach ($where as $k => $v) if (($row[$k] ?? null) != $v) { $match = false; break; }
                if ($match) { $this->event_bookings[$id] = array_merge($row, $data); $n++; }
            }
            return $n;
        }
        return 1;
    }

    function query($q) {
        $d = $this->decode($q);
        $query = $d['__q'];
        if (str_contains($query, 'DELETE FROM')) { $this->fake_delete($query, $d['__args']); return 1; }
        return 1;
    }

    function delete($table, $where) { return 1; }
    function get_charset_collate() { return 'DEFAULT CHARSET utf8'; }

    private function count_bookings($query, $args) {
        $seat_id = $args[0]; $date = $args[1]; $time = $args[2];
        $has_event_scope = str_contains($query, 'event_id = %d');
        $idx = 3;
        $event_id = 0;
        if ($has_event_scope) { $event_id = $args[3]; $idx = 5; }
        $now = $args[$idx] ?? gmdate('Y-m-d H:i:s');
        $n = 0;
        foreach ($this->seat_bookings as $row) {
            $row = (array)$row;
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
                $row = (array)$row;
                if ($row['seat_id'] != $seat_id || $row['date_context'] !== $date) continue;
                if (($row['time_context'] ?? null) !== $time) continue;
                if ($has_event_scope && $event_id != 0 && ($row['event_id'] ?? 0) != $event_id) continue;
                if ($row['status'] === 'held' && $row['held_until'] < $now) unset($this->seat_bookings[$id]);
            }
            return true;
        }
        if (str_contains($query, "status = 'held'") && !str_contains($query, 'held_until <')) {
            $seat_id = $args[0]; $date = $args[1]; $time = $args[2];
            $has_event_scope = str_contains($query, 'event_id = %d');
            $event_id = $has_event_scope ? $args[3] : 0;
            foreach ($this->seat_bookings as $id => $row) {
                $row = (array)$row;
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
            $row = (array)$row;
            if ($row['seat_id'] != $seat_id || $row['date_context'] !== $date) continue;
            if (($row['time_context'] ?? null) !== $time) continue;
            if ($has_event_scope && $event_id != 0 && ($row['event_id'] ?? 0) != $event_id) continue;
            if (in_array($row['status'], ['held','confirmed'])) $candidates[$id] = $row;
        }
        if (empty($candidates)) return null;
        uasort($candidates, fn($a,$b) => strcmp($b['status'], $a['status']));
        return array_key_first($candidates);
    }

    private function booked_seat_ids($query, $args) {
        $plan_id = $args[0]; $date = $args[1]; $time = $args[2];
        $has_event_scope = str_contains($query, 'event_id = %d');
        $idx = 4;
        $event_id = 0;
        if ($has_event_scope) { $event_id = $args[4]; $idx = 6; }
        $now = $args[$idx] ?? gmdate('Y-m-d H:i:s');
        $out = [];
        foreach ($this->seat_bookings as $row) {
            $row = (array)$row;
            if ($row['plan_id'] != $plan_id || $row['date_context'] !== $date) continue;
            if ($time !== '' && ($row['time_context'] ?? '') !== $time) continue;
            if ($has_event_scope && $event_id != 0 && ($row['event_id'] ?? 0) != $event_id) continue;
            if ($row['status'] === 'confirmed' || ($row['status'] === 'held' && $row['held_until'] >= $now)) $out[] = $row['seat_id'];
        }
        return $out;
    }
}
