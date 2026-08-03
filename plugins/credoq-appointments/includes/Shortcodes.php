<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

/**
 * AUDIT-FIX A-5: All shortcodes registered once via Plugin::register_shortcodes().
 */
class Shortcodes {

    public static function booking_form( array $atts ) : string {
        $atts = shortcode_atts([
            'appointment_id' => 0,
            'staff_id'       => 0,
            'show_title'     => 'yes',
            'accent_color'   => '',
        ], $atts);

        $apt_id = absint($atts['appointment_id']);
        $settings = get_option('credoq_booking_settings', []);

        wp_enqueue_style('credoq-appointments-frontend');
        wp_enqueue_script('credoq-appointments-frontend');
        wp_localize_script('credoq-appointments-frontend', 'credoqAptData', [
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('credoq_nonce'),
            'appointment_id' => $apt_id,
            'staff_id'       => absint($atts['staff_id']),
            'require_login'  => !empty($settings['require_login']) && !is_user_logged_in(),
            'logged_in'      => is_user_logged_in(),
            'currency'       => $settings['currency'] ?? get_option('woocommerce_currency','USD'),
            'show_staff'     => !empty($settings['show_staff_selector']),
            'show_notes'     => !empty($settings['show_notes_field']),
            'enable_waiting' => !empty($settings['enable_waiting_list']),
        ]);

        if (!empty($settings['require_login']) && !is_user_logged_in()) {
            return '<div class="cq-booking-widget cq-login-required"><p>'
                . __('Please log in to book a session.','credoq-appointments')
                . ' <a href="'.esc_url(wp_login_url(get_permalink())).'">'.__('Log in','credoq-appointments').'</a></p></div>';
        }

        ob_start(); ?>
<div class="cq-booking-widget" id="cq-booking-widget-<?php echo $apt_id; ?>"
     data-apt-id="<?php echo $apt_id; ?>"
     data-nonce="<?php echo wp_create_nonce('credoq_nonce'); ?>">

    <!-- Service selector (if no specific apt set) -->
    <?php if (!$apt_id) : ?>
    <div class="cq-step" id="cq-step-service">
        <h3 class="cq-widget-title">Select a Service</h3>
        <div id="cq-service-list" class="cq-service-grid">
            <?php
            $apts = Appointment_Repository::all(50,0);
            foreach ($apts as $a) :
                $accent = sanitize_hex_color($a->accent_color ?: '#4f46e5');
            ?>
            <div class="cq-service-card" data-apt-id="<?php echo intval($a->id); ?>"
                 style="border-top:4px solid <?php echo $accent; ?>;"
                 onclick="credoqSelectService(<?php echo intval($a->id); ?>)">
                <?php if ($a->image_url) : ?>
                <img src="<?php echo esc_url($a->image_url); ?>" alt="" style="width:100%;height:120px;object-fit:cover;border-radius:8px;margin-bottom:10px;">
                <?php endif; ?>
                <div class="cq-service-name"><?php echo esc_html($a->title); ?></div>
                <?php if ($a->location) : ?><div class="cq-service-loc">📍 <?php echo esc_html($a->location); ?></div><?php endif; ?>
                <?php if ($a->description) : ?><div class="cq-service-desc"><?php echo wp_kses_post(wp_trim_words($a->description,20)); ?></div><?php endif; ?>
                <div class="cq-service-meta">
                    <span>⏱ <?php echo intval($a->duration); ?> min</span>
                    <?php if ($a->base_price > 0) : ?><span>💰 <?php echo $settings['currency']??'$'; ?><?php echo number_format($a->base_price,2); ?></span><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Calendar / date picker step -->
    <div class="cq-step" id="cq-step-date" <?php echo $apt_id ? '' : 'style="display:none;"'; ?>>
        <h3 class="cq-widget-title">Choose a Date</h3>
        <div id="cq-calendar-nav" style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <button type="button" class="cq-btn" onclick="credoqPrevMonth()" style="padding:6px 12px;font-size:18px;background:#f1f5f9;border-radius:8px;">‹</button>
            <div id="cq-month-label" style="font-weight:800;font-size:15px;flex:1;text-align:center;"></div>
            <button type="button" class="cq-btn" onclick="credoqNextMonth()" style="padding:6px 12px;font-size:18px;background:#f1f5f9;border-radius:8px;">›</button>
        </div>
        <div id="cq-calendar-grid" class="cq-calendar-grid"></div>
    </div>

    <!-- Time slots -->
    <div class="cq-step" id="cq-step-time" style="display:none;">
        <button type="button" class="cq-back-btn" onclick="credoqGoStep('date')">‹ Back</button>
        <h3 class="cq-widget-title">Choose a Time</h3>
        <div id="cq-slots-grid" class="cq-slots-grid"></div>
    </div>

    <!-- Details form -->
    <div class="cq-step" id="cq-step-form" style="display:none;">
        <button type="button" class="cq-back-btn" onclick="credoqGoStep('time')">‹ Back</button>
        <h3 class="cq-widget-title">Your Details</h3>
        <?php if (!is_user_logged_in()) : ?>
        <div class="cq-form-group">
            <label class="cq-form-label">Name</label>
            <input type="text" id="cq-guest-name" class="cq-input" placeholder="Your name">
        </div>
        <div class="cq-form-group">
            <label class="cq-form-label">Email</label>
            <input type="email" id="cq-guest-email" class="cq-input" placeholder="your@email.com">
        </div>
        <?php endif; ?>
        <?php if (!empty($settings['show_notes_field'])) : ?>
        <div class="cq-form-group">
            <label class="cq-form-label">Notes</label>
            <textarea id="cq-booking-notes" class="cq-input" rows="3" placeholder="Any notes for your trainer..."></textarea>
        </div>
        <?php endif; ?>
        <!-- Booking summary -->
        <div class="cq-booking-summary" id="cq-booking-summary"></div>
        <button type="button" class="cq-btn cq-btn-primary" style="width:100%;margin-top:16px;"
                onclick="credoqSubmitBooking()">Confirm Booking</button>
        <div id="cq-booking-msg" class="cq-msg" style="display:none;margin-top:12px;"></div>
    </div>

    <!-- Success -->
    <div class="cq-step" id="cq-step-success" style="display:none;">
        <div style="text-align:center;padding:48px 24px;">
            <div style="font-size:64px;margin-bottom:16px;">🎉</div>
            <h3 style="font-size:22px;font-weight:900;color:#16a34a;margin:0 0 8px;">Booking Confirmed!</h3>
            <p id="cq-success-msg" style="color:#64748b;"></p>
            <button type="button" class="cq-btn cq-btn-primary" onclick="credoqReset()" style="margin-top:20px;">Book Another</button>
        </div>
    </div>
</div><!-- .cq-booking-widget -->
        <?php
        return ob_get_clean();
    }

    public static function my_schedule( array $atts ) : string {
        if (!is_user_logged_in()) return '<p>'.__('Please log in to view your schedule.','credoq-appointments').'</p>';
        $bookings = Booking_Repository::get_user_bookings(get_current_user_id(), false);
        ob_start(); ?>
<div class="cq-my-schedule">
    <?php if (empty($bookings)) : ?>
        <p style="color:#64748b;">No bookings yet.</p>
    <?php else: foreach ($bookings as $b) :
        $date_fmt = date_i18n(get_option('date_format'),strtotime($b->selected_date));
        $time_fmt = date_i18n('H:i',strtotime($b->selected_time));
        $cls = $b->status==='confirmed'?'green':($b->status==='cancelled'?'red':'gray');
    ?>
        <div class="cq-session-row">
            <div class="cq-session-dot"></div>
            <div class="cq-session-info">
                <div class="cq-session-name"><?php echo esc_html($b->apt_title?:'Appointment'); ?></div>
                <div class="cq-session-time"><?php echo esc_html("$date_fmt · $time_fmt"); ?></div>
            </div>
            <span class="cq-badge cq-badge-<?php echo esc_attr($cls); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span>
        </div>
    <?php endforeach; endif; ?>
</div>
        <?php
        return ob_get_clean();
    }
}
