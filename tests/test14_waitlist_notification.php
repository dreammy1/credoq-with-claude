<?php
/**
 * Audit: Waitlist Notification Fix
 *
 * Verifies if the waitlist offer uses the correct notification system.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/wp_stubs.php';

// Mock DB
global $wpdb;
$wpdb = new FakeWPDB();
$wpdb->prefix = 'wp_';

// Stubs
if (!function_exists('get_option')) { function get_option($k, $default = false) { return $default; } }
if (!function_exists('date_i18n')) { function date_i18n($f, $t) { return date($f, $t); } }
if (!function_exists('wp_date')) { function wp_date($f, $t) { return date($f, $t); } }
if (!function_exists('wp_mail')) { function wp_mail($a,$b,$c) { global $mails; $mails[] = [$a,$b,$c]; } }
if (!function_exists('admin_url')) { function admin_url($p) { return "http://test.local/wp-admin/$p"; } }

// Load Classes
require_once PLUGINS_DIR . '/credoq-engine-v3/includes/Mail/Notifications.php';
require_once PLUGINS_DIR . '/credoq-engine-v3/includes/Mail/Mailer.php';
require_once PLUGINS_DIR . '/credoq-appointments/includes/Waiting_List_Repository.php';
require_once PLUGINS_DIR . '/credoq-appointments/includes/Appointment_Repository.php';

use CredoqAppointments\Waiting_List_Repository;
use CredoqEngine\Mail\Notifications;

// 1. Setup mock data
$apt_id = 1;
$wpdb->insert('wp_credoq_appointments', [
    'id' => $apt_id,
    'title' => 'Test Service'
]);

// Mock the row in waiting_list
$wpdb->set_mock_row("SELECT * FROM credoq_waiting_list WHERE appointment_id=%d AND booking_date=%s AND booking_time=%s AND status='waiting' ORDER BY created_at ASC LIMIT 1", (object)[
    'id' => 77,
    'user_id' => 5,
    'guest_email' => 'wait@example.com',
    'booking_date' => '2023-10-10',
    'booking_time' => '10:00'
]);

$apt = \CredoqAppointments\Appointment_Repository::find($apt_id);
if ($apt) {
    echo "DEBUG: Mock Appointment found: " . $apt->title . "\n";
} else {
    echo "DEBUG: Mock Appointment NOT found via Repository.\n";
}

// 2. Call offer_next
echo "Calling offer_next...\n";
Waiting_List_Repository::offer_next($apt_id, 1, '2023-10-10', '10:00');

echo "Notifications in DB: " . count($wpdb->notifications) . "\n";
foreach ($wpdb->notifications as $n) echo "  - Type: " . ($n['type'] ?? 'unknown') . ", Title: " . ($n['title'] ?? 'none') . "\n";

// 3. Check if notification was created in DB
$found_notif = false;
foreach ($wpdb->notifications as $n) {
    if ($n['type'] === 'waiting_list' && str_contains($n['title'], 'Slot Available!')) {
        $found_notif = true;
        break;
    }
}

if ($found_notif) {
    echo "✅ SUCCESS: Waitlist offer created a system notification.\n";
} else {
    echo "❌ FAILURE: Waitlist offer did NOT create a system notification.\n";
}

// 4. Check if email was sent
global $mails;
if (!empty($mails)) {
    echo "✅ SUCCESS: Waitlist email was sent.\n";
} else {
    echo "❌ FAILURE: Waitlist email was NOT sent.\n";
}
