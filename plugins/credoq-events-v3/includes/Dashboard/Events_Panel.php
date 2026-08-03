<?php
namespace CredoqEvents\Dashboard;
defined( 'ABSPATH' ) || exit;
use CredoqEvents\Event_Booking_Repository;
use CredoqEvents\Event_Repository;

class Events_Panel {
    public static function register() : void {
        add_filter('credoq_dashboard_panels', [__CLASS__,'add_panel'], 50);
    }
    public static function add_panel(array $panels) : array {
        $panels['my_events'] = [
            'label_sidebar' => __('My Events','credoq-events'),
            'label_bottom'  => __('Events','credoq-events'),
            'icon_key'      => 'events',
            'priority'      => 50,
            'render'        => [__CLASS__,'render'],
        ];
        return $panels;
    }
    public static function render() : string {
        if (!is_user_logged_in()) return '';
        $bookings = Event_Booking_Repository::get_for_user(get_current_user_id());
        ob_start(); ?>
<div class="cq-events-panel">
    <h2 class="cq-section-title" style="margin-bottom:16px;">My Events</h2>
    <?php if (empty($bookings)) : ?>
        <div class="cq-empty-state"><div class="cq-empty-icon">🎟</div><p>No event registrations yet.</p></div>
    <?php else: foreach ($bookings as $b):
        $start = date_i18n(get_option('date_format').' H:i', strtotime($b->start_datetime));
        $cls   = $b->status==='confirmed'?'green':($b->status==='cancelled'?'red':'blue');
    ?>
        <div class="cq-session-row">
            <div class="cq-session-info">
                <div class="cq-session-name"><?php echo esc_html($b->event_title??'Event'); ?></div>
                <div class="cq-session-time"><?php echo esc_html($start); ?><?php echo $b->location?' · '.esc_html($b->location):''; ?></div>
            </div>
            <span class="cq-badge cq-badge-<?php echo esc_attr($cls); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span>
        </div>
    <?php endforeach; endif; ?>
</div>
        <?php
        return ob_get_clean();
    }
}
