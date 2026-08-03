
/* Credoq Booking Widget JS
 * Handles: service select → calendar → slots → form → submit
 * AUDIT-FIX A-2: zero JetAppointments calls
 * AUDIT-FIX B-7: no base64 encoding
 */
(function(){
    'use strict';

    var state = {
        aptId: 0, staffId: 0,
        selectedDate: '', selectedTime: '',
        year: new Date().getFullYear(), month: new Date().getMonth() + 1,
        availableDates: [],
        price: 0, duration: 0, aptTitle: '',
        nonce: (window.credoqAptData && credoqAptData.nonce) || '',
        ajaxUrl: (window.credoqAptData && credoqAptData.ajax_url) || '/wp-admin/admin-ajax.php',
        currency: (window.credoqAptData && credoqAptData.currency) || '$',
    };

    // Init: if appointment_id pre-set via localized data
    document.addEventListener('DOMContentLoaded', function(){
        if (window.credoqAptData && credoqAptData.appointment_id > 0) {
            credoqSelectService(parseInt(credoqAptData.appointment_id));
        }
        renderCalendar();
    });

    window.credoqSelectService = function(aptId) {
        state.aptId = aptId;
        goStep('date');
        loadAvailableDates();
    };

    window.credoqPrevMonth = function() {
        state.month--;
        if (state.month < 1) { state.month = 12; state.year--; }
        loadAvailableDates();
    };

    window.credoqNextMonth = function() {
        state.month++;
        if (state.month > 12) { state.month = 1; state.year++; }
        loadAvailableDates();
    };

    window.credoqGoStep = goStep;
    window.credoqReset  = function() {
        state.selectedDate = ''; state.selectedTime = '';
        goStep(state.aptId ? 'date' : 'service');
        if (state.aptId) loadAvailableDates();
    };

    function goStep(step) {
        ['service','date','time','form','success'].forEach(function(s){
            var el = document.getElementById('cq-step-'+s);
            if (el) el.style.display = (s === step) ? '' : 'none';
        });
    }

    function loadAvailableDates() {
        var monthLabel = document.getElementById('cq-month-label');
        if (monthLabel) {
            var d = new Date(state.year, state.month-1, 1);
            monthLabel.textContent = d.toLocaleString('default',{month:'long',year:'numeric'});
        }
        var fd = new FormData();
        fd.append('action','credoq_get_available_dates');
        fd.append('nonce', state.nonce);
        fd.append('appointment_id', state.aptId);
        fd.append('staff_id', state.staffId);
        fd.append('year', state.year);
        fd.append('month', state.month);
        fetch(state.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(d){
                if (d.success) { state.availableDates = d.data.available_dates || []; }
                else           { state.availableDates = []; }
                renderCalendar();
            });
    }

    function renderCalendar() {
        var grid = document.getElementById('cq-calendar-grid');
        if (!grid) return;
        var today = new Date(); today.setHours(0,0,0,0);
        var firstDay = new Date(state.year, state.month-1, 1).getDay();
        var daysInMonth = new Date(state.year, state.month, 0).getDate();
        var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var html = dayNames.map(function(d){return '<div class="cq-cal-header">'+d+'</div>';}).join('');
        for (var i=0; i<firstDay; i++) html += '<div></div>';
        for (var d=1; d<=daysInMonth; d++) {
            var dateStr = state.year+'-'+String(state.month).padStart(2,'0')+'-'+String(d).padStart(2,'0');
            var dt = new Date(state.year, state.month-1, d);
            var cls = ['cq-cal-day'];
            if (dt < today) cls.push('past');
            else if (state.availableDates.indexOf(dateStr) > -1) cls.push('available');
            if (dt.toDateString() === today.toDateString()) cls.push('today');
            if (dateStr === state.selectedDate) cls.push('selected');
            var onclick = cls.indexOf('available') > -1 ? ' onclick="credoqSelectDate(\''+dateStr+'\')"' : '';
            html += '<div class="'+cls.join(' ')+'"'+onclick+'>'+d+'</div>';
        }
        grid.innerHTML = html;
    }

    window.credoqSelectDate = function(date) {
        state.selectedDate = date;
        renderCalendar();
        loadSlots(date);
        goStep('time');
    };

    function loadSlots(date) {
        var grid = document.getElementById('cq-slots-grid');
        if (!grid) return;
        grid.innerHTML = '<p style="color:#94a3b8;font-size:13px;">Loading slots…</p>';
        var fd = new FormData();
        fd.append('action','credoq_get_timeslots');
        fd.append('nonce', state.nonce);
        fd.append('appointment_id', state.aptId);
        fd.append('staff_id', state.staffId);
        fd.append('date', date);
        fetch(state.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(d){
                if (!d.success || !d.data.slots.length) {
                    grid.innerHTML='<p style="color:#94a3b8;text-align:center;padding:24px;">No slots available for this date.</p>';
                    return;
                }
                state.duration = d.data.duration;
                var html = '';
                d.data.slots.forEach(function(slot){
                    var cls = 'cq-slot-btn' + (slot.available ? '' : ' unavailable');
                    var dis = slot.available ? '' : ' disabled';
                    var capHtml = slot.capacity > 1 ? '<div class="cq-slot-cap">'+slot.remaining+'/'+slot.capacity+' free</div>' : '';
                    html += '<button type="button" class="'+cls+'" data-time="'+slot.time+'"'+dis+
                            ' onclick="credoqSelectSlot(\''+slot.time+'\',this)">'+
                            slot.time+capHtml+'</button>';
                });
                grid.innerHTML = html;
            });
    }

    window.credoqSelectSlot = function(time, btn) {
        state.selectedTime = time;
        document.querySelectorAll('.cq-slot-btn').forEach(function(b){ b.classList.remove('selected'); });
        btn.classList.add('selected');
        setTimeout(function(){ renderSummary(); goStep('form'); }, 200);
    };

    function renderSummary() {
        var el = document.getElementById('cq-booking-summary');
        if (!el) return;
        var dateFmt = new Date(state.selectedDate+'T00:00:00').toLocaleDateString(undefined,{weekday:'short',year:'numeric',month:'long',day:'numeric'});
        el.innerHTML =
            '<div class="cq-summary-row"><span class="cq-summary-label">Date</span><span class="cq-summary-value">'+dateFmt+'</span></div>'+
            '<div class="cq-summary-row"><span class="cq-summary-label">Time</span><span class="cq-summary-value">'+state.selectedTime+'</span></div>'+
            '<div class="cq-summary-row"><span class="cq-summary-label">Duration</span><span class="cq-summary-value">'+(state.duration||60)+' min</span></div>';
    }

    window.credoqSubmitBooking = function() {
        var msgEl = document.getElementById('cq-booking-msg');
        var btn = document.querySelector('#cq-step-form .cq-btn-primary');
        if (!state.selectedDate || !state.selectedTime) {
            showMsg(msgEl,'error','Please select a date and time.'); return;
        }
        btn.disabled = true; btn.textContent = 'Processing…';

        var fd = new FormData();
        fd.append('action','credoq_submit_booking');
        fd.append('nonce', state.nonce);
        fd.append('appointment_id', state.aptId);
        fd.append('staff_id', state.staffId);
        fd.append('date', state.selectedDate);
        fd.append('time', state.selectedTime);

        var guestName  = document.getElementById('cq-guest-name');
        var guestEmail = document.getElementById('cq-guest-email');
        var notes      = document.getElementById('cq-booking-notes');
        if (guestName)  fd.append('guest_name',  guestName.value);
        if (guestEmail) fd.append('guest_email', guestEmail.value);
        if (notes)      fd.append('form_data[notes]', notes.value);

        fetch(state.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(d){
                btn.disabled = false; btn.textContent = 'Confirm Booking';
                if (d.success) {
                    if (d.data.use_wc && d.data.wc_cart_url) {
                        window.location.href = d.data.wc_cart_url; return;
                    }
                    var sucMsg = document.getElementById('cq-success-msg');
                    if (sucMsg) sucMsg.textContent = 'Your session on '+state.selectedDate+' at '+state.selectedTime+' is confirmed.';
                    goStep('success');
                } else {
                    showMsg(msgEl,'error', d.data || 'Booking failed. Please try again.');
                }
            })
            .catch(function(){ btn.disabled=false; btn.textContent='Confirm Booking'; showMsg(msgEl,'error','Network error.'); });
    };

    function showMsg(el, type, msg) {
        if (!el) return;
        el.className = 'cq-msg cq-msg-'+type;
        el.textContent = msg;
        el.style.display = '';
    }
})();
