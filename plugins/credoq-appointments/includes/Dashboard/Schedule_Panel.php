<?php
namespace CredoqAppointments\Dashboard;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Booking_Repository;

class Schedule_Panel {

    public static function register() : void {
        add_filter('credoq_dashboard_panels', [__CLASS__, 'add_panel'], 30);
    }

    public static function add_panel(array $panels) : array {
        $panels['my_schedule'] = [
            'label_sidebar' => __('My Schedule','credoq-appointments'),
            'label_bottom'  => __('Schedule','credoq-appointments'),
            'icon_key'      => 'schedule',
            'priority'      => 30,
            'render'        => [__CLASS__, 'render'],
        ];
        return $panels;
    }

    public static function render() : string {
        if ( ! is_user_logged_in() ) return '';
        $user_id  = get_current_user_id();
        $upcoming = Booking_Repository::get_user_bookings($user_id, true);
        $past     = Booking_Repository::get_user_bookings($user_id, false);
        $past     = array_filter($past, fn($b) => strtotime($b->selected_date) < strtotime('today'));
        $past     = array_slice($past, 0, 10);

        $nonce = wp_create_nonce('credoq_nonce');
        ob_start(); ?>
<div class="cq-schedule-panel">
    <h2 class="cq-section-title" style="margin-bottom:16px;">My Schedule</h2>

    <!-- Upcoming -->
    <div class="cq-section">
        <h3 class="cq-section-title">Upcoming Sessions</h3>
        <?php if (empty($upcoming)) : ?>
            <div class="cq-empty-state" style="padding:32px;">
                <div class="cq-empty-icon">📅</div>
                <p>No upcoming sessions. <a href="<?php echo esc_url(add_query_arg('cq_panel','home')); ?>">Book one now.</a></p>
            </div>
        <?php else: foreach ($upcoming as $b) :
            $date_fmt = date_i18n(get_option('date_format'),strtotime($b->selected_date));
            $time_fmt = date_i18n('H:i',strtotime($b->selected_time));
            $status_c = $b->status === 'confirmed' ? 'green' : 'blue';
        ?>
            <div class="cq-session-row" style="justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div class="cq-session-dot" style="background:<?php echo $b->status==='confirmed'?'#16a34a':'#4f46e5'; ?>;"></div>
                    <div class="cq-session-info">
                        <div class="cq-session-name"><?php echo esc_html($b->apt_title?:'Appointment'); ?></div>
                        <div class="cq-session-time"><?php echo esc_html("$date_fmt · $time_fmt"); ?><?php echo $b->apt_location ? ' · '.esc_html($b->apt_location) : ''; ?></div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="cq-badge cq-badge-<?php echo esc_attr($status_c); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span>
                    <?php if (in_array($b->status,['confirmed','pending'])) : ?>
                    <button class="cq-btn" style="padding:6px 12px;font-size:12px;background:#fee2e2;color:#dc2626;"
                            onclick="credoqCancelBooking(<?php echo intval($b->id); ?>,this,'<?php echo $nonce; ?>')">Cancel</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Past sessions -->
    <?php if (!empty($past)) : ?>
    <div class="cq-section" style="margin-top:28px;">
        <h3 class="cq-section-title">Past Sessions</h3>
        <?php foreach ($past as $b) :
            $date_fmt = date_i18n(get_option('date_format'),strtotime($b->selected_date));
            $time_fmt = date_i18n('H:i',strtotime($b->selected_time));
        ?>
        <div class="cq-history-row">
            <div>
                <div class="cq-history-plan"><?php echo esc_html($b->apt_title?:'Appointment'); ?></div>
                <div class="cq-history-date"><?php echo esc_html("$date_fmt · $time_fmt"); ?></div>
            </div>
            <span class="cq-badge cq-badge-gray"><?php echo esc_html(ucfirst($b->status)); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function credoqCancelBooking(bookingId, btn, nonce) {
    if (!confirm('Cancel this booking?')) return;
    btn.disabled = true;
    btn.textContent = '...';
    var fd = new FormData();
    fd.append('action','credoq_cancel_booking_user');
    fd.append('nonce', nonce);
    fd.append('booking_id', bookingId);
    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){ btn.closest('.cq-session-row').style.opacity='0.4'; btn.textContent='Cancelled'; }
            else{ btn.disabled=false; btn.textContent='Cancel'; alert(d.data||'Error cancelling.'); }
        });
}
</script>
        <?php
        return ob_get_clean();
    }
}
