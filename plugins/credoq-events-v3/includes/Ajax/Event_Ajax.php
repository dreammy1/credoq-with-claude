<?php
namespace CredoqEvents\Ajax;
defined( 'ABSPATH' ) || exit;
use CredoqEvents\Event_Service;
use CredoqEvents\Event_Repository;

class Event_Ajax {
    public static function register() : void {
        add_action('wp_ajax_credoq_event_register',        [__CLASS__,'register_event']);
        add_action('wp_ajax_nopriv_credoq_event_register', [__CLASS__,'register_event']);
    }
    public static function register_event() : void {
        check_ajax_referer('credoq_nonce','nonce');
        // Rate limit: 5/10min per IP
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR']??'');
        $rk = 'credoq_evt_rate_'.md5($ip);
        $hits = intval(get_transient($rk));
        if ($hits >= 5) { wp_send_json_error('Too many requests.'); }
        set_transient($rk, $hits+1, 10*MINUTE_IN_SECONDS);

        $event_id   = absint($_POST['event_id']??0); // AUDIT-FIX A-6: always absint
        $user_id    = get_current_user_id();
        $qty        = max(1, absint($_POST['quantity']??1));
        $guest_name = sanitize_text_field($_POST['guest_name']??'');
        $guest_email= sanitize_email($_POST['guest_email']??'');
        $plan_id    = absint($_POST['plan_id']??0);

        // AUDIT-FEATURE: seat selections from the modal's seat map (see
        // Shortcodes.php's credoqSubmitEventReg()) — a JSON array of seat
        // ids. Real validation/pricing happens server-side in
        // Event_Service::register(), never trusting these values directly.
        $seat_ids = array();
        if ( ! empty( $_POST['seat_ids'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['seat_ids'] ), true );
            if ( is_array( $decoded ) ) $seat_ids = array_map( 'absint', $decoded );
        }

        if (!$event_id) { wp_send_json_error('Invalid event.'); }

        $result = Event_Service::register($event_id,$user_id,$qty,$guest_name,$guest_email,$plan_id,$seat_ids);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result['error']??'Registration failed.');
    }
}
