<?php
namespace CredoqEvents;
defined( 'ABSPATH' ) || exit;

class Shortcodes {

    public static function events_list(array $atts) : string {
        $atts = shortcode_atts(['upcoming_only'=>'yes','limit'=>10], $atts);
        wp_enqueue_style('credoq-events-frontend');
        $events = Event_Repository::all(['upcoming_only'=>$atts['upcoming_only']==='yes','per_page'=>absint($atts['limit'])]);
        ob_start(); ?>
<div class="cq-events-list">
    <?php if (empty($events)) : ?>
        <div class="cq-empty-state"><div class="cq-empty-icon">🎟</div><p>No upcoming events.</p></div>
    <?php else: foreach ($events as $ev):
        $booked  = Event_Repository::booked_count(intval($ev->id));
        $spotsLeft = $ev->capacity > 0 ? max(0,$ev->capacity-$booked) : null;
        $start  = date_i18n(get_option('date_format').' H:i',strtotime($ev->start_datetime));
        $accent = sanitize_hex_color($ev->accent_color??'#4f46e5');
    ?>
        <div class="cq-event-card" style="border-top:4px solid <?php echo $accent; ?>;">
            <?php if ($ev->image_url): ?><img src="<?php echo esc_url($ev->image_url); ?>" alt="" style="width:100%;height:160px;object-fit:cover;border-radius:10px;margin-bottom:12px;"><?php endif; ?>
            <div class="cq-event-title"><?php echo esc_html($ev->title); ?></div>
            <div class="cq-event-meta">
                <span>📅 <?php echo esc_html($start); ?></span>
                <?php if($ev->location): ?><span>📍 <?php echo esc_html($ev->location); ?></span><?php endif; ?>
                <?php if($ev->price>0): ?><span>💰 $<?php echo number_format($ev->price,2); ?></span><?php endif; ?>
                <?php if($spotsLeft!==null): ?><span class="<?php echo $spotsLeft<=5?'cq-text-warning':''; ?>">🪑 <?php echo intval($spotsLeft); ?> spots left</span><?php endif; ?>
            </div>
            <?php if($ev->description): ?><div class="cq-event-desc"><?php echo wp_kses_post(wp_trim_words($ev->description,30)); ?></div><?php endif; ?>
            <div style="margin-top:14px;">
                <?php if($ev->zoom_link): ?><a href="<?php echo esc_url($ev->zoom_link); ?>" class="cq-btn cq-btn-primary" target="_blank" style="font-size:12px;padding:8px 14px;">🎥 Join Online</a><?php endif; ?>
                <?php
                // AUDIT-FEATURE (closes the previously-flagged "no
                // seat-map integration in the legacy flow" gap): embed
                // the resolved plan id (0 = none/ambiguous, same rule as
                // the Forms Builder path — see Event_Service::resolve_seat_plan()).
                $seat_plan_id = class_exists( '\CredoqEvents\Event_Service' ) ? \CredoqEvents\Event_Service::connected_seat_plan_id( intval( $ev->id ) ) : 0;
                ?>
                <button class="cq-btn cq-btn-primary" onclick="credoqOpenEventReg(<?php echo intval($ev->id); ?>, <?php echo (int) $seat_plan_id; ?>)" style="font-size:12px;padding:8px 14px;">Register</button>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>
<!-- Event registration modal -->
<div id="cq-event-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:20px;padding:32px;width:460px;max-width:95vw;max-height:85vh;overflow-y:auto;">
        <h3 id="cq-event-modal-title" style="margin:0 0 20px;font-size:20px;font-weight:900;"></h3>

        <!-- Plain quantity path (no connected seat plan) -->
        <div id="cq-ev-qty-wrap" class="cq-form-group"><label class="cq-form-label">Quantity</label><input type="number" id="cq-ev-qty" min="1" value="1" class="cq-input" style="width:80px;"></div>

        <!-- Seat-map path (event has a resolvable connected published plan) —
             reuses the exact same credoq_seats_load_map/hold/release/get_booked
             AJAX + frontend-seat-map.js the React widget already relies on,
             so seat availability is consistent everywhere, not a second
             parallel implementation. -->
        <div id="cq-ev-seatmap-wrap" style="display:none;">
            <label class="cq-form-label">Select your seat(s)</label>
            <div id="cq-ev-seatmap-msg" style="font-size:12.5px;color:#64748b;margin:4px 0 8px;">Loading seat map…</div>
            <div id="cq-ev-seatmap-target"></div>
        </div>

        <?php if(!is_user_logged_in()): ?>
        <div class="cq-form-group"><label class="cq-form-label">Your Name</label><input type="text" id="cq-ev-name" class="cq-input"></div>
        <div class="cq-form-group"><label class="cq-form-label">Email</label><input type="email" id="cq-ev-email" class="cq-input"></div>
        <?php endif; ?>
        <button class="cq-btn cq-btn-primary" style="width:100%;margin-top:8px;" onclick="credoqSubmitEventReg()">Confirm Registration</button>
        <div id="cq-ev-msg" class="cq-msg" style="display:none;margin-top:12px;"></div>
    </div>
</div>
<script>
var _cqEvId=0;
var _cqEvSeatPlanId=0;
function credoqOpenEventReg(id, seatPlanId){
    _cqEvId=id;
    _cqEvSeatPlanId=parseInt(seatPlanId||0,10);
    document.getElementById('cq-event-modal').style.display='flex';
    document.getElementById('cq-ev-qty-wrap').style.display = _cqEvSeatPlanId ? 'none' : '';
    document.getElementById('cq-ev-seatmap-wrap').style.display = _cqEvSeatPlanId ? '' : 'none';
    document.getElementById('cq-ev-msg').style.display='none';
    if (_cqEvSeatPlanId) credoqLoadEventSeatMap();
}
function credoqLoadEventSeatMap(){
    var target = document.getElementById('cq-ev-seatmap-target');
    var msg    = document.getElementById('cq-ev-seatmap-msg');
    target.innerHTML = '';
    msg.style.display = '';
    msg.textContent = 'Loading seat map…';
    var fd = new FormData();
    fd.append('action','credoq_seats_load_map');
    fd.append('nonce','<?php echo wp_create_nonce('credoq_nonce'); ?>');
    fd.append('plan_id', _cqEvSeatPlanId);
    fd.append('event_id', _cqEvId);
    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(d){
            if (!d.success) { msg.textContent = (d.data && d.data.message) || 'Seat map unavailable.'; return; }
            msg.style.display='none';
            target.innerHTML = d.data.html || '';
            var wrap = target.querySelector('.cvsp-map-wrap');
            if (wrap) wrap.setAttribute('data-credoq-event-id', String(_cqEvId));
            if (typeof window.cvspReinitMaps === 'function') window.cvspReinitMaps();
            var map = window.CVSPMaps && window.CVSPMaps[_cqEvSeatPlanId];
            if (map) map.currentEventId = _cqEvId;
        }).catch(function(){ msg.textContent = 'Seat map unavailable.'; });
}
function credoqSubmitEventReg(){
    var fd=new FormData();
    fd.append('action','credoq_event_register');
    fd.append('nonce','<?php echo wp_create_nonce('credoq_nonce'); ?>');
    fd.append('event_id',_cqEvId);

    if (_cqEvSeatPlanId) {
        // AUDIT-FEATURE: quantity is the seat count, never the other way
        // around — mirrors the "seat selection replaces qty" rule applied
        // to Field_Event. The server recomputes/validates this from the
        // actual seat_ids regardless of what's sent here.
        var map = window.CVSPMaps && window.CVSPMaps[_cqEvSeatPlanId];
        var seatIds = map ? map.selectedIds.slice() : [];
        if (!seatIds.length) {
            var msg=document.getElementById('cq-ev-msg');
            msg.style.display=''; msg.className='cq-msg cq-msg-error';
            msg.textContent='Please select at least one seat.';
            return;
        }
        fd.append('seat_ids', JSON.stringify(seatIds));
        fd.append('quantity', seatIds.length);
        fd.append('plan_id', _cqEvSeatPlanId);
    } else {
        fd.append('quantity',document.getElementById('cq-ev-qty').value);
    }

    var n=document.getElementById('cq-ev-name');
    var e=document.getElementById('cq-ev-email');
    if(n) fd.append('guest_name',n.value);
    if(e) fd.append('guest_email',e.value);
    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(d){
            var msg=document.getElementById('cq-ev-msg');
            msg.style.display='';
            if(d.success){
                msg.className='cq-msg cq-msg-success';
                msg.textContent='Registered! '+((d.data&&d.data.use_wc)?'Redirecting to checkout…':'');
                if(d.data&&d.data.wc_cart_url) setTimeout(function(){window.location.href=d.data.wc_cart_url;},800);
            } else {
                msg.className='cq-msg cq-msg-error';
                msg.textContent=d.data||'Registration failed.';
            }
        });
}
</script>
        <?php
        return ob_get_clean();
    }

    public static function register_form(array $atts) : string {
        $atts = shortcode_atts(['event_id'=>0], $atts);
        $event_id = absint($atts['event_id']);
        if (!$event_id) return '<p>Please specify an event_id.</p>';
        ob_start();
        echo self::events_list(['limit'=>1,'upcoming_only'=>'no']);
        return ob_get_clean();
    }
}
