import { useCallback } from 'react';

/**
 * useAjax — all HTTP calls for the booking widget.
 *
 * PRIMARY:  WordPress REST API  (GET /credoq/v1/*)
 *           — avoids admin-ajax.php which shared hosts (kesug, 000webhost, etc.)
 *             and security plugins (Wordfence) block with 403 Forbidden.
 * FALLBACK: admin-ajax.php POST — kept for submit_booking and legacy paths.
 */
export function useAjax(config) {
  const ajaxUrl   = config.ajax_url   || '';
  const nonce     = config.nonce      || '';
  const restBase  = (config.rest_url  || '').replace(/\/?$/, '');   // strip trailing slash
  const restNonce = config.rest_nonce || '';

  // ── REST GET ────────────────────────────────────────────────
  const restGet = useCallback(async (endpoint, params = {}) => {
    const url = new URL(restBase + '/' + endpoint);
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    });
    const headers = {};
    if (restNonce) headers['X-WP-Nonce'] = restNonce;
    const r = await fetch(url.toString(), { method: 'GET', headers });
    if (!r.ok) throw new Error(`REST ${endpoint} ${r.status}`);
    return r.json();
  }, [restBase, restNonce]);

  // ── REST POST ───────────────────────────────────────────────
  const restPost = useCallback(async (endpoint, body) => {
    const headers = { 'Content-Type': 'application/json' };
    if (restNonce) headers['X-WP-Nonce'] = restNonce;
    const r = await fetch(restBase + '/' + endpoint, {
      method: 'POST', headers, body: JSON.stringify(body),
    });
    // AUDIT-FIX: mirror restGet's r.ok check. Without this, a missing
    // route (e.g. /bookings when Credoq Appointments isn't installed)
    // returns a 404 WP-REST error body that still parses as valid JSON
    // — silently swallowed as if it were a normal response, surfacing
    // to the user as a generic "An error occurred." with no fallback.
    if (!r.ok) throw new Error(`REST ${endpoint} ${r.status}`);
    return r.json();
  }, [restBase, restNonce]);

  // ── admin-ajax POST (fallback) ───────────────────────────────
  const ajaxPost = useCallback(async (fd) => {
    const r = await fetch(ajaxUrl, { method: 'POST', body: fd });
    return r.json();
  }, [ajaxUrl]);

  // ═══════════════════════════════════════════════════════════
  // GET PROVIDERS  →  GET /credoq/v1/providers?appointment_id=X
  // ═══════════════════════════════════════════════════════════
  const getProviders = useCallback(async (appointmentId) => {
    try {
      const data = await restGet('providers', { appointment_id: appointmentId });
      return Array.isArray(data) ? data : [];
    } catch {
      const fd = new FormData();
      fd.append('action', 'credoq_get_providers_for_service');
      fd.append('nonce', nonce);
      fd.append('appointment_id', appointmentId);
      const d = await ajaxPost(fd);
      return (d.success && d.data && d.data.providers) ? d.data.providers : [];
    }
  }, [restGet, ajaxPost, nonce]);

  // ═══════════════════════════════════════════════════════════
  // GET DATE CAPACITY  →  GET /credoq/v1/date-capacity
  // ═══════════════════════════════════════════════════════════
  const getDateCapacity = useCallback(async (appointmentId, staffId = 0, year, month) => {
    try {
      const data = await restGet('date-capacity', {
        appointment_id: appointmentId,
        staff_id: staffId || undefined,
        year, month,
      });
      // Return the full response so Calendar can read both .dates and .special_dates
      return data || {};
    } catch {
      const fd = new FormData();
      fd.append('action', 'credoq_get_date_capacity');
      fd.append('nonce', nonce);
      fd.append('appointment_id', appointmentId);
      if (staffId) fd.append('staff_id', staffId);
      if (year)  fd.append('year',  year);
      if (month) fd.append('month', month);
      const d = await ajaxPost(fd);
      return (d.success && d.data) ? d.data : {};
    }
  }, [restGet, ajaxPost, nonce]);

  // ═══════════════════════════════════════════════════════════
  // GET TIMESLOTS  →  GET /credoq/v1/timeslots
  // ═══════════════════════════════════════════════════════════
  const getTimeslots = useCallback(async (appointmentId, date, staffId = 0) => {
    try {
      const data = await restGet('timeslots', {
        appointment_id: appointmentId,
        date,
        staff_id: staffId || undefined,
      });
      // Normalize to { success: true, data: { slots, staff, duration, date_price } }
      return { success: true, data };
    } catch {
      const fd = new FormData();
      fd.append('action', 'credoq_get_timeslots');
      fd.append('nonce', nonce);
      fd.append('appointment_id', appointmentId);
      fd.append('selected_date', date);
      if (staffId) fd.append('staff_id', staffId);
      return ajaxPost(fd);
    }
  }, [restGet, ajaxPost, nonce]);

  // ═══════════════════════════════════════════════════════════
  // GET SERVICES FOR PROVIDER  →  GET /credoq/v1/services-for-provider
  // ═══════════════════════════════════════════════════════════
  const getServicesForProvider = useCallback(async (staffId) => {
    try {
      const data = await restGet('services-for-provider', { staff_id: staffId });
      return Array.isArray(data) ? data : [];
    } catch {
      const fd = new FormData();
      fd.append('action', 'credoq_get_services_for_provider');
      fd.append('nonce', nonce);
      fd.append('staff_id', staffId);
      const d = await ajaxPost(fd);
      return (d.success && d.data && d.data.services) ? d.data.services : [];
    }
  }, [restGet, ajaxPost, nonce]);

  // ═══════════════════════════════════════════════════════════
  // GET SERVICES FOR DATE  →  GET /credoq/v1/services-for-date
  // ═══════════════════════════════════════════════════════════
  const getServicesForDate = useCallback(async (date) => {
    try {
      const data = await restGet('services-for-date', { date });
      return Array.isArray(data) ? data : [];
    } catch {
      const fd = new FormData();
      fd.append('action', 'credoq_get_services_for_date');
      fd.append('nonce', nonce);
      fd.append('date', date);
      const d = await ajaxPost(fd);
      return (d.success && d.data && d.data.services) ? d.data.services : [];
    }
  }, [restGet, ajaxPost, nonce]);

  // ═══════════════════════════════════════════════════════════
  // MEMBER SLOT CREDITS  →  admin-ajax (membership plugin)
  // ═══════════════════════════════════════════════════════════
  const getMemberSlotCredits = useCallback(async (formId, appointmentId) => {
    const fd = new FormData();
    fd.append('action', 'credoq_get_member_slot_credits');
    fd.append('nonce', nonce);
    fd.append('form_id', formId || 0);
    fd.append('appointment_id', appointmentId || 0);
    const d = await ajaxPost(fd);
    return (d.success && d.data) ? d.data : { credit_required: false, plans: [] };
  }, [ajaxPost, nonce]);

  // ═══════════════════════════════════════════════════════════
  // GET EVENTS  →  admin-ajax (events plugin)
  // ═══════════════════════════════════════════════════════════
  const getEvents = useCallback(async () => {
    const fd = new FormData();
    const now  = new Date();
    const from = new Date(now.getFullYear(), now.getMonth(), 1);
    const to   = new Date(now.getFullYear() + 1, now.getMonth(), 0);
    const ymd  = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    fd.append('action', 'credoq_get_events_feed');
    fd.append('nonce',  config.event_nonce || nonce);
    fd.append('from',   ymd(from));
    fd.append('to',     ymd(to));
    const d = await ajaxPost(fd);
    return (d.success && d.data && d.data.events) ? d.data.events : [];
  }, [ajaxPost, nonce, config.event_nonce]);

  // ═══════════════════════════════════════════════════════════
  // SUBMIT EVENT BOOKING  →  admin-ajax
  // ═══════════════════════════════════════════════════════════
  const submitEventBooking = useCallback(async (eventId, qty, details = {}) => {
    const fd = new FormData();
    fd.append('action',   'credoq_submit_event_booking');
    fd.append('nonce',    config.event_nonce || nonce);
    fd.append('event_id', eventId);
    fd.append('qty',      qty || 1);
    if (details.name)  fd.append('guest_name',  details.name);
    if (details.email) fd.append('guest_email', details.email);
    if (details.phone) fd.append('form_data[phone]', details.phone);
    return ajaxPost(fd);
  }, [ajaxPost, nonce, config.event_nonce]);

  // ═══════════════════════════════════════════════════════════
  // SUBMIT BOOKING  →  routes based on flow type
  //
  //   isAppointmentFlow = true   (form has an appointment/provider field)
  //     → REST POST /credoq/v1/bookings  (registered ONLY by Credoq
  //       Appointments). Falls back to admin-ajax credoq_submit_booking
  //       if the REST call fails outright (network/blocked REST API).
  //
  //   isAppointmentFlow = false  (Contact Form, Survey, Cost Estimator,
  //   any standalone form — the Engine's 100%-standalone use case)
  //     → goes STRAIGHT to admin-ajax action=credoq_submit_booking.
  //       This action is registered by the Engine itself
  //       (Ajax/Booking.php) and is guaranteed to exist with zero
  //       addons installed. We deliberately skip REST /bookings here
  //       because that route doesn't exist unless Credoq Appointments
  //       is active — hitting it would 404 and previously surfaced as
  //       a generic "An error occurred." message.
  // ═══════════════════════════════════════════════════════════
  const submitBooking = useCallback(async (formData, isAppointmentFlow = false) => {
    if (!isAppointmentFlow) {
      formData.set('action', 'credoq_submit_booking');
      formData.set('nonce', nonce);
      return ajaxPost(formData);
    }

    // Convert FormData's bracket-notation keys into a nested JSON body.
    //
    // BUG FIX: the previous version used a single-bracket regex
    // (/^form_data\[(.+?)\](\[\])?$/) that only understood one level of
    // nesting, e.g. form_data[email]. Any field whose value is itself an
    // object — like seat_map's {seats, count, total, plan_id, selected},
    // sent as form_data[seat_map][seats], form_data[seat_map][count],
    // etc. — has TWO bracket pairs, which that regex was never designed
    // for. It silently produced a mangled flat key like
    // "seat_map][seats" instead of a nested { seat_map: { seats: ... } }
    // object. The backend's seat_ids extraction (Credoq Appointments'
    // Booking_Handler::submit()) looks for form_data[fieldname].seats —
    // which never existed on this path, so seat_ids was always empty
    // for every REST-routed appointment booking with a seat map, which
    // cascaded into wrong prices and seats never confirming. Fields
    // without nested values (the vast majority) were never affected,
    // which is why this went unnoticed until seat_map's shape hit it.
    function parseKeyPath(key) {
      const topMatch = key.match(/^([^\[\]]+)/);
      if (!topMatch) return null;
      const parts = [topMatch[1]];
      const bracketRe = /\[([^\[\]]*)\]/g;
      let bm;
      const rest = key.slice(topMatch[1].length);
      while ((bm = bracketRe.exec(rest))) parts.push(bm[1]);
      return parts;
    }
    function setPath(root, parts, value) {
      let cursor = root;
      for (let i = 1; i < parts.length; i++) {
        const key = parts[i];
        const isLast = i === parts.length - 1;
        const nextIsArrayAppend = !isLast && parts[i + 1] === '';
        if (isLast) {
          if (key === '') {
            if (Array.isArray(cursor)) cursor.push(value);
          } else {
            cursor[key] = value;
          }
        } else {
          if (!(key in cursor)) cursor[key] = nextIsArrayAppend ? [] : {};
          cursor = cursor[key];
        }
      }
    }

    const body = {};
    formData.forEach((v, k) => {
      const parts = parseKeyPath(k);
      if (!parts || parts[0] !== 'form_data' || parts.length < 2) {
        body[k] = v;
        return;
      }
      if (!body.form_data) body.form_data = {};
      setPath(body.form_data, parts, v);
    });
    body.nonce = nonce;

    try {
      return await restPost('bookings', body);
    } catch {
      // Fallback: re-post as admin-ajax
      formData.set('action', 'credoq_submit_booking');
      formData.set('nonce', nonce);
      return ajaxPost(formData);
    }
  }, [restPost, ajaxPost, nonce]);

  // ═══════════════════════════════════════════════════════════
  // JOIN WAITLIST  →  admin-ajax
  // ═══════════════════════════════════════════════════════════
  const joinWaitlist = useCallback(async (appointmentId, date, time) => {
    const fd = new FormData();
    fd.append('action',         'credoq_join_waiting_list');
    fd.append('nonce',          nonce);
    fd.append('appointment_id', appointmentId);
    fd.append('selected_date',  date);
    fd.append('selected_time',  time);
    return ajaxPost(fd);
  }, [ajaxPost, nonce]);

  return {
    getTimeslots, getDateCapacity, getProviders,
    getServicesForProvider, getServicesForDate,
    getMemberSlotCredits, getEvents, submitEventBooking,
    submitBooking, joinWaitlist,
  };
}
