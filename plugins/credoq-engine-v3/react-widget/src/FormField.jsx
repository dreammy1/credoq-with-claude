import React, { useRef, useEffect, useState, useMemo } from 'react';
import EventCalendarField from './EventCalendarField.jsx';

const SLOT_COLORS = ['#4f46e5','#7c3aed','#0891b2','#059669','#d97706','#dc2626'];

/** Signature canvas field */
function SignatureField({ name, formState, setFormState }) {
  const canvasRef = useRef(null);
  const drawing   = useRef(false);

  function getPos(e, canvas) {
    const rect = canvas.getBoundingClientRect();
    const sx = canvas.width / rect.width;
    const sy = canvas.height / rect.height;
    const src = e.touches ? e.touches[0] : e;
    return { x: (src.clientX - rect.left) * sx, y: (src.clientY - rect.top) * sy };
  }

  useEffect(() => {
    const cv = canvasRef.current;
    if (!cv) return;
    const ctx = cv.getContext('2d');
    ctx.strokeStyle = '#1a1a2e';
    ctx.lineWidth   = 2;
    ctx.lineCap     = 'round';

    function start(e) { e.preventDefault(); drawing.current=true; const p=getPos(e,cv); ctx.beginPath(); ctx.moveTo(p.x,p.y); }
    function move(e)  { e.preventDefault(); if(!drawing.current)return; const p=getPos(e,cv); ctx.lineTo(p.x,p.y); ctx.stroke(); }
    function stop()   { drawing.current=false; save(); }

    function save() { setFormState(s => ({...s, [name]: cv.toDataURL('image/png')})); }

    cv.addEventListener('mousedown',  start);
    cv.addEventListener('mousemove',  move);
    cv.addEventListener('mouseup',    stop);
    cv.addEventListener('touchstart', start, {passive:false});
    cv.addEventListener('touchmove',  move,  {passive:false});
    cv.addEventListener('touchend',   stop);
    return () => {
      cv.removeEventListener('mousedown',  start);
      cv.removeEventListener('mousemove',  move);
      cv.removeEventListener('mouseup',    stop);
      cv.removeEventListener('touchstart', start);
      cv.removeEventListener('touchmove',  move);
      cv.removeEventListener('touchend',   stop);
    };
  }, [name]);

  function clear() {
    const cv = canvasRef.current;
    if (!cv) return;
    cv.getContext('2d').clearRect(0,0,cv.width,cv.height);
    setFormState(s => ({...s, [name]: ''}));
  }

  return (
    <div className="cqw-field">
      <div className="cqw-sig-wrap">
        <canvas ref={canvasRef} className="cqw-sig-canvas" width={500} height={120} />
      </div>
      <button type="button" className="cqw-sig-clear" onClick={clear}>Clear</button>
    </div>
  );
}

/** Quantity field */
function QuantityField({ field, formState, setFormState }) {
  const name = field.name || 'qty';
  const val  = parseInt(formState[name]) || parseInt(field.default_value) || 1;
  const min  = parseInt(field.min_qty) || 1;
  const max  = parseInt(field.max_qty) || 999;

  function change(delta) {
    const next = Math.max(min, Math.min(max, val + delta));
    setFormState(s => ({...s, [name]: next}));
  }
  return (
    <div className="cqw-qty">
      <button type="button" className="cqw-qty-btn" onClick={() => change(-1)}>-</button>
      <span className="cqw-qty-val">{val}</span>
      <button type="button" className="cqw-qty-btn" onClick={() => change(1)}>+</button>
    </div>
  );
}

/** Calculate field */
function CalculateField({ field, formState, setFormState, basePrice, currency }) {
  const formula = field.formula || '';
  function getVal(fn) {
    if (fn === 'base_price') return basePrice;
    const v = formState[fn];
    return parseFloat(v) || 0;
  }
  let result = NaN;
  try {
    const expr = formula.replace(/\{([^}]+)\}/g, (_,f) => getVal(f));
    if (/^[\d\s+\-*/.()]+$/.test(expr)) {
      // eslint-disable-next-line no-new-func
      result = (new Function('return (' + expr + ')'))();
    }
  } catch(_) {}

  // Bug 3 fix: persist the calculated result into formState so BookingWidget
  // can include it in the grand total (when add_to_total is enabled) and/or
  // in the standalone WC checkout total (when wc_option_price is enabled).
  const storeKey = '__calc_' + field.name;
  const persistResult = !!field.add_to_total || !!field.wc_option_price;
  useEffect(() => {
    if (!persistResult) {
      setFormState(s => {
        if (s[storeKey] === undefined) return s;
        const next = { ...s };
        delete next[storeKey];
        return next;
      });
      return;
    }
    const val = isNaN(result) ? 0 : result;
    setFormState(s => {
      if (s[storeKey] === val) return s;
      return { ...s, [storeKey]: val };
    });
  }, [result, persistResult]);

  const display = isNaN(result) ? '\u2014' : result.toFixed(2);
  return (
    <div className="cqw-field">
      <div className="cqw-calc-out" data-formula={formula} data-name={field.name}>
        {currency} {display}
        {field.add_to_total && !isNaN(result) && result !== 0 && (
          <span className="cqw-calc-addon"> (+{currency} {result.toFixed(2)} add-on)</span>
        )}
        {field.enable_wc && field.wc_option_price && !isNaN(result) && result !== 0 && (
          <span className="cqw-calc-addon"> (+{currency} {result.toFixed(2)} WC)</span>
        )}
      </div>
    </div>
  );
}
function SeatMapField({ field, name, req, selectedDate, selectedTime, staffId, currentApt, config, formState, setFormState, currency }) {
  const wrapRef = useRef(null);
  const [html, setHtml] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [selectedDetails, setSelectedDetails] = useState([]);
  const [planName, setPlanName] = useState('');
  const planNameRef = useRef('');
  // Appointments gate on the service's "Enable seat map" toggle. Forms with
  // no `currentApt` (e.g. an Events registration form) have no such toggle —
  // dropping this field type onto the form *is* the enable signal there.
  // Event-only forms receive a fallback currentApt object from BookingWidget,
  // but it has no real appointment ID. Only a real appointment service should
  // gate seat-map rendering on visual_seats_enabled; event forms are enabled by
  // the presence of the seat_map field and their resolved event seat plan.
  const hasAppointmentContext = currentApt && parseInt(currentApt?.id || 0, 10) > 0;
  const enabled = hasAppointmentContext
    ? (parseInt(currentApt?.visual_seats_enabled || 0, 10) === 1)
    : true;
  const mode = field.seat_plan_mode || 'single';

  // AUDIT-FEATURE (Events + Seats — event auto-resolution): an
  // event_registration field's calendar can select MULTIPLE events at
  // once, so there's no single static "current event" the way Appointments
  // has a single connected service. Instead, resolve it live from whatever
  // the visitor has actually picked so far on a sibling field: if exactly
  // one event is currently selected, that's unambiguously the seat map's
  // event. (Seat_Map_Field::on_submission() applies the identical rule
  // server-side — see its docblock — so what renders here always matches
  // what gets confirmed at submit time.)
  const eventFieldName = useMemo(() => {
    const fields = Array.isArray(config.fields) ? config.fields : [];
    const f = fields.find(f => f.type === 'event_registration');
    return f ? f.name : '';
  }, [config.fields]);

  const resolvedEventId = useMemo(() => {
    // AUDIT-FEATURE: explicit override, now settable via the Forms
    // Builder's seat_map settings panel (credoq-seats/assets/js/
    // forms-builder-panel.js) — mirrors the same priority order
    // Seat_Map_Field::on_submission() applies server-side
    // ($field_config['event_id'] checked before auto-resolution).
    const explicit = parseInt(field.event_id || 0, 10);
    if (explicit) return explicit;

    if (!eventFieldName) return 0;
    const raw = formState[eventFieldName];
    if (!raw) return 0;
    let decoded;
    try { decoded = typeof raw === 'string' ? JSON.parse(raw) : raw; } catch (e) { return 0; }
    if (!decoded) return 0;
    const selections = Array.isArray(decoded) ? decoded : (decoded.event_id ? [decoded] : []);
    const ids = Array.from(new Set(selections.map(s => parseInt(s.event_id, 10) || 0).filter(Boolean)));
    return ids.length === 1 ? ids[0] : 0;
  }, [field.event_id, eventFieldName, formState[eventFieldName]]);

  // Config-time half of the same auto-resolution — see
  // Integrations\Events_Bridge::inject_widget_config() (credoq-seats).
  const eventPlanId = resolvedEventId ? parseInt((config.event_seat_plans || {})[resolvedEventId] || 0, 10) : 0;

  // The Forms Builder's field-settings panel is hardcoded per built-in
  // field type and has no generic mechanism for addon field types to add
  // their own controls, so there's currently no UI to pick a seat plan on
  // this field directly. Appointments forms don't need one anyway — the
  // service itself is already connected to a plan (Appointments admin →
  // Visual Seats panel), and Appointments_Bridge injects that plan's ID
  // onto `currentApt.seat_plan_id`. Events forms resolve it via
  // eventPlanId above instead. Prefer an explicit per-field plan if one is
  // ever set (e.g. by a future builder update or direct API call).
  const fallbackPlanId = parseInt(currentApt?.seat_plan_id || 0, 10) || eventPlanId;
  const planIds = mode === 'multiple'
    ? (Array.isArray(field.seat_plan_ids) ? field.seat_plan_ids : []).map(v => parseInt(v, 10)).filter(Boolean)
    : [parseInt(field.seat_plan_id || 0, 10) || fallbackPlanId].filter(Boolean);
  const [planId, setPlanId] = useState(planIds[0] || 0);

  useEffect(() => {
    setPlanId(planIds[0] || 0);
  }, [field.seat_plan_id, JSON.stringify(field.seat_plan_ids || []), fallbackPlanId]);

  useEffect(() => {
    setFormState(s => {
      const copy = { ...s };
      delete copy[name];
      delete copy[`__seat_total_${name}`];
      return copy;
    });
    setSelectedDetails([]);
  }, [selectedDate, selectedTime, staffId, currentApt?.id, planId, resolvedEventId]);

  useEffect(() => {
    // Appointments: availability depends on the picked date/time/staff, so
    // wait until those are chosen. Events: there's no picker — the event's
    // own date is resolved server-side by credoq_seats_load_map instead.
    const waitingOnSlotPicker = hasAppointmentContext && ( !selectedDate || !selectedTime );
    if (!enabled || !planId || waitingOnSlotPicker) {
      setHtml('');
      return;
    }

    let cancelled = false;
    const fd = new FormData();
    fd.append('action', 'credoq_seats_load_map');
    fd.append('nonce', config.nonce || '');
    fd.append('plan_id', planId);
    fd.append('event_id', resolvedEventId || 0);

    setLoading(true);
    setMessage('');
    fetch(config.ajax_url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (cancelled) return;
        if (!res.success) {
          setHtml('');
          setMessage((res.data && res.data.message) || 'Seat map unavailable.');
          return;
        }
        setHtml(res.data.html || '');
      })
      .catch(() => {
        if (!cancelled) setMessage('Seat map unavailable.');
      })
      .finally(() => { if (!cancelled) setLoading(false); });

    return () => { cancelled = true; };
  }, [enabled, planId, selectedDate, selectedTime, staffId, resolvedEventId, config.ajax_url, config.nonce]);

  useEffect(() => {
    if (!html || !wrapRef.current) return;
    const mapWrap = wrapRef.current.querySelector('.cvsp-map-wrap');
    if (!mapWrap) return;
    mapWrap.setAttribute('data-credoq-staff-id', String(staffId || 0));
    mapWrap.setAttribute('data-credoq-date', selectedDate || '');
    mapWrap.setAttribute('data-credoq-slot', selectedTime || '');
    mapWrap.setAttribute('data-credoq-event-id', String(resolvedEventId || 0));
    setPlanName(mapWrap.getAttribute('data-plan-name') || '');
    planNameRef.current = mapWrap.getAttribute('data-plan-name') || '';

    const boot = () => {
      if (typeof window.cvspReinitMaps === 'function') window.cvspReinitMaps();
      const map = window.CVSPMaps && window.CVSPMaps[planId];
      if (!map) return false;
      map.currentDate = selectedDate || '';
      map.currentSlot = selectedTime || '';
      map.currentStaffId = parseInt(staffId || 0, 10);
      map.currentEventId = parseInt(resolvedEventId || 0, 10);
      map.loadBooked = function() {
        const fd = new FormData();
        fd.append('action', 'credoq_seats_get_booked');
        fd.append('nonce', config.nonce || '');
        fd.append('plan_id', planId);
        fd.append('date', selectedDate || '');
        fd.append('slot', selectedTime || '');
        fd.append('staff_id', staffId || 0);
        fd.append('event_id', resolvedEventId || 0);
        fetch(config.ajax_url, { method: 'POST', body: fd })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              map.bookedIds = (res.data.booked_seat_ids || []).map(Number);
              if (typeof map.repaint === 'function') map.repaint();
            }
          })
          .catch(() => {});
      };
      if (typeof map.loadBooked === 'function') map.loadBooked();
      bindMap(map);
      return true;
    };

    let tries = 0;
    const timer = setInterval(() => {
      tries += 1;
      if (boot() || tries > 80) clearInterval(timer);
    }, 100);
    return () => clearInterval(timer);
  }, [html, planId, selectedDate, selectedTime, staffId, resolvedEventId]);

  function bindMap(map) {
    // Always keep the callback pointed at *this* render's syncFromMap
    // (closes over current name/setFormState), even if bindMap itself
    // only runs once per field-name per map instance.
    map.onSelectionChange = function () { syncFromMap(map); };
    if (map._credoqReactField === name) return;
    map._credoqReactField = name;
    syncFromMap(map);
  }

  function syncFromMap(map) {
    const ids = Array.isArray(map.selectedIds) ? map.selectedIds.slice() : [];
    const total = typeof map.calcPrice === 'function' ? parseFloat(map.calcPrice() || 0) : 0;
    const details = typeof map.getSelectedDetails === 'function' ? map.getSelectedDetails() : [];
    setFormState(s => ({
      ...s,
      [name]: {
        seats: JSON.stringify(ids),
        count: ids.length,
        total: total.toFixed(2),
        plan_id: planId,
        selected: ids.length ? 'yes' : '',
      },
      [`__seat_total_${name}`]: total,
      // Not submitted to the backend (stripped by the '__' skip filter at
      // submit time, same as __grand_total etc.) — kept only so the
      // Review step can render the same seat breakdown after this
      // component itself has unmounted (its own selectedDetails/planName
      // state would otherwise be lost).
      [`__seat_details_${name}`]: details,
      [`__seat_plan_name_${name}`]: planNameRef.current,
    }));
    setSelectedDetails(details);
  }

  function removeSeat(seatId) {
    const map = window.CVSPMaps && window.CVSPMaps[planId];
    if (map && typeof map.handleClick === 'function') map.handleClick(seatId);
  }

  if (!enabled) {
    return (
      <div className="cqw-seat-note">Seat selection is not enabled for this service.</div>
    );
  }
  if (!planId) {
    if (eventFieldName && !resolvedEventId) {
      return <div className="cqw-seat-note">Select a single event above to choose your seats.</div>;
    }
    return <div className="cqw-seat-note">No seat plan is configured for this field.</div>;
  }

  return (
    <div className="cqw-seat-field">
      {mode === 'multiple' && planIds.length > 1 && (
        <select className="cqw-seat-plan-select" value={planId} onChange={e => setPlanId(parseInt(e.target.value, 10) || 0)}>
          {planIds.map(pid => <option key={pid} value={pid}>Seat plan #{pid}</option>)}
        </select>
      )}
      {hasAppointmentContext && (!selectedDate || !selectedTime) && <div className="cqw-seat-note">Select a date and time slot first.</div>}
      {loading && <div className="cqw-seat-note">Loading seat map...</div>}
      {message && <div className="cqw-seat-note">{message}</div>}
      <div ref={wrapRef} dangerouslySetInnerHTML={{ __html: html }} />

      {selectedDetails.length > 0 && (
        <div className="cqw-seat-selection-summary">
          <div className="cqw-seat-selection-header">
            <span>{planName || 'Seat Map'}</span>
            <span>{selectedDetails.length} seat{selectedDetails.length === 1 ? '' : 's'} selected</span>
          </div>
          {selectedDetails.map(d => (
            <div className="cqw-seat-selection-row" key={d.id}>
              <span className="cqw-seat-selection-label">{d.label}</span>
              <span className="cqw-seat-selection-price">{currency} {d.price.toFixed(2)}</span>
              <button
                type="button"
                className="cqw-seat-selection-remove"
                onClick={() => removeSeat(d.id)}
                aria-label={`Remove seat ${d.label}`}
                title="Remove"
              >×</button>
            </div>
          ))}
          <div className="cqw-seat-selection-total">
            <span>Total</span>
            <span>{currency} {selectedDetails.reduce((t, d) => t + d.price, 0).toFixed(2)}</span>
          </div>
        </div>
      )}

      <input type="hidden" name={`form_data[${name}][selected]`} value={formState[name]?.selected || ''} readOnly required={req} />
    </div>
  );
}

/** EventPickerField — pre-selected event card with qty stepper */
function EventPickerField({ field, name, val, req, props, set, fieldWrap }) {
  const epRef = React.useRef(null);
  React.useEffect(() => {
    if (!epRef.current) return;
    const qtyInput = epRef.current.querySelector('.cqw-event-qty');
    const idInput  = epRef.current.querySelector('.cqw-event-id');
    if (!qtyInput || !idInput) return;
    const sync = () => {
      set(JSON.stringify({
        event_id: parseInt(idInput.value) || (props.event_id || 0),
        quantity: Math.max(1, parseInt(qtyInput.value) || 1),
      }));
    };
    sync();
    qtyInput.addEventListener('change', sync);
    qtyInput.addEventListener('input',  sync);
    return () => {
      qtyInput.removeEventListener('change', sync);
      qtyInput.removeEventListener('input',  sync);
    };
  }, []);
  return fieldWrap(
    <>
      <div ref={epRef} dangerouslySetInnerHTML={{ __html: props.html || '' }} />
      <input type="hidden" name={`form_data[${name}]`} value={val} />
    </>
  );
}

/** The main field renderer */
export default function FormField({ field, index, formState, setFormState, basePrice, currency, errors, selectedDate, selectedTime, staffId, currentApt, config, fileRef }) {
  const type  = field.type          || 'text';
  const label = field.label         || '';
  const name  = field.name          || `field_${index}`;
  const req   = !!field.required;
  const ph    = field.placeholder   || '';
  const def   = field.default_value || '';
  const opts  = Array.isArray(field.options) ? field.options : [];
  const err   = errors && errors[name];

  // Conditional logic check
  const cond = field.conditional || {};
  if (cond.enabled && cond.field_name) {
    const refVal = String(formState[cond.field_name] || '');
    const cv     = String(cond.value || '');
    const op     = cond.operator || 'equals';
    let show = false;
    if (op === 'equals')     show = refVal === cv;
    if (op === 'not_equals') show = refVal !== cv;
    if (op === 'contains')   show = refVal.indexOf(cv) !== -1;
    if (op === 'not_empty')  show = refVal.trim() !== '';
    if (op === 'empty')      show = refVal.trim() === '';
    if (!show) return null;
  }

  function set(val) { setFormState(s => ({...s, [name]: val})); }
  const val = formState[name] !== undefined ? formState[name] : def;

  // When "Enable WC Checkout" + "Option value as price → add to WC grand
  // total" are on, show the per-option price next to its label so the
  // user can see how their selection affects the total before submitting.
  function wcPriceSuffix(optionValue) {
    if (!field.enable_wc || !field.wc_option_price) return '';
    const n = parseFloat(optionValue);
    if (isNaN(n) || n === 0) return '';
    return ` (+${currency} ${n.toFixed(2)})`;
  }

  if (type === 'step' || type === 'page_break' || type === 'submit') return null;
  // appointment / provider / date / time pickers are handled by BookingWidget's
  // own appointment flow — skip rendering them as generic form fields.
  // NOTE: member_slot_credit and event_registration are NOT in this list —
  // they render via the generic _frontend AddonField path below.
  if (type === 'appointment' || type === 'provider_picker' ||
      type === 'service_picker' || type === 'date_picker' ||
      type === 'time_slot_picker') return null;

  const fieldWrap = (children) => (
    <div className={`cqw-field${err ? ' err' : ''}`}>
      {label && (
        <label>
          {label}{req && <span className="req"> *</span>}
        </label>
      )}
      {children}
      {err && <div className="cqw-field-err">{err}</div>}
    </div>
  );

  // ── Generic AddonField renderer (Field Registry frontend bridge) ──
  // Addon plugins (Membership, Events, etc.) register their field types
  // into the Engine's Field Registry via 'credoq_register_field_types'.
  // For any type FormField.jsx doesn't have a hardcoded case for, the
  // backend attaches a small declarative '_frontend' descriptor
  // (Field_Type::get_frontend_render()) so it still renders something
  // useful here instead of going blank.
  if (field._frontend && field._frontend.component) {
    const fr    = field._frontend;
    const props = fr.props || {};

    switch (fr.component) {
      case 'event_calendar': {
        // AUDIT-FIX (P1 — UX: qty stepper misleading when a seat map
        // governs the event): server-side, a seat_map field's own seat
        // count always REPLACES this calendar's qty for pricing/credit/
        // capacity (see Field_Event::handle_submission() / decide_payment()
        // in credoq-events — the fix that closed the "seat total never
        // reached WooCommerce" bug). But until now the qty stepper stayed
        // fully interactive regardless, so a visitor could set qty=5, pick
        // 2 seats, and be charged for 2 with no on-screen explanation of
        // why their qty choice didn't count. Compute which events have an
        // active, resolvable seat map (same event_seat_plans config the
        // seat_map field itself uses — see Events_Bridge::inject_widget_config()
        // in credoq-seats) so EventCalendarField can lock qty to the real
        // seat count and say why, instead of silently overriding it later.
        const hasSeatMapField = Array.isArray(config.fields) && config.fields.some(f => f.type === 'seat_map');
        const seatMappedEventIds = hasSeatMapField
          ? new Set(Object.keys(config.event_seat_plans || {}).map(Number))
          : new Set();

        return <EventCalendarField
          field={field} name={name} val={val}
          set={set} fieldWrap={fieldWrap} currency={currency}
          seatMappedEventIds={seatMappedEventIds} />;
      }

      case 'event_picker':
        return <EventPickerField field={field} name={name} val={val} req={req}
                 props={props} set={set} fieldWrap={fieldWrap} />;

      case 'display': {
        // Read-only info box.
        const value = (props.value_key && formState[props.value_key] !== undefined)
          ? formState[props.value_key]
          : (formState[name] !== undefined ? formState[name] : props.value);
        return fieldWrap(
          <div className="cqw-addon-display">
            {props.text && <div className="cqw-addon-display-text">{props.text}</div>}
            {value !== undefined && value !== '' && value !== null && (
              <div className="cqw-addon-display-value">{String(value)}</div>
            )}
          </div>
        );
      }

      case 'select': {
        const options = Array.isArray(props.options) && props.options.length ? props.options : opts;
        return fieldWrap(
          <select
            name={`form_data[${name}]`}
            value={val}
            required={req}
            onChange={e => set(e.target.value)}
          >
            <option value="">{props.placeholder || '— Select —'}</option>
            {options.map((o, i) => (
              <option key={i} value={o.value ?? o.label ?? o}>{o.label ?? o.value ?? o}</option>
            ))}
          </select>
        );
      }

      case 'number':
        return fieldWrap(
          <input
            type="number"
            name={`form_data[${name}]`}
            value={val}
            placeholder={props.placeholder || ph}
            min={props.min} max={props.max} step={props.step || 'any'}
            required={req}
            onChange={e => set(e.target.value)}
          />
        );

      case 'html':
        return fieldWrap(
          <div className="cqw-addon-html" dangerouslySetInnerHTML={{ __html: props.html || '' }} />
        );

      default:
        break;
    }
  }

  if (type === 'hidden') {
    return <input type="hidden" name={`form_data[${name}]`} value={val} />;
  }

  if (type === 'html') {
    // AUDIT-FIX (Bug 2): the admin form builder saves raw HTML/shortcode
    // content under field.html_code (see Forms_Page.php's #cfs-html-code
    // textarea → f.html_code). This was reading field.html_content,
    // which never existed, so the field silently fell back to {label}
    // (usually empty for HTML blocks) and rendered as a blank box.
    return <div className="cqw-html-field" dangerouslySetInnerHTML={{__html: field.html_code || field.html_content || label || ''}} />;
  }

  if (type === 'signature') {
    return fieldWrap(<SignatureField name={name} formState={formState} setFormState={setFormState} />);
  }

  if (type === 'quantity') {
    return fieldWrap(<QuantityField field={field} formState={formState} setFormState={setFormState} />);
  }

  if (type === 'calculate') {
    return fieldWrap(<CalculateField field={field} formState={formState} setFormState={setFormState} basePrice={basePrice} currency={currency} />);
  }

  if (type === 'seat_map') {
    return fieldWrap(
      <SeatMapField
        field={field}
        name={name}
        req={req}
        selectedDate={selectedDate}
        selectedTime={selectedTime}
        staffId={staffId}
        currentApt={currentApt}
        config={config || {}}
        formState={formState}
        setFormState={setFormState}
        currency={currency}
      />
    );
  }

  if (type === 'total_price') {
    // __grand_total is always kept in sync by BookingWidget's useEffect,
    // so we just display it directly (includes base + addons + seats + qty).
    const displayTotal = formState.__grand_total !== undefined
      ? formState.__grand_total
      : basePrice * (parseInt(formState.__qty_multiplier) || 1);
    return (
      <div className="cqw-total-price">
        <span className="cqw-total-lbl">{label || 'Total'}</span>
        <span className="cqw-total-val">{currency} {displayTotal.toFixed(2)}</span>
      </div>
    );
  }

  // AUDIT-FIX (field type audit): backend's Phone field type slug is
  // 'phone' (see Builtin_Types.php Field_Phone::get_slug()), not 'tel'.
  // This case only checked for 'tel', so Phone fields silently fell
  // through to the generic text-input fallback at the bottom of this
  // file — functional, but missing the tel input type (no numeric/phone
  // keyboard on mobile) and any phone-specific styling/icon.
  if (type === 'text' || type === 'email' || type === 'phone' || type === 'tel' || type === 'number') {
    return fieldWrap(
      <input
        type={type === 'phone' ? 'tel' : type}
        name={`form_data[${name}]`}
        value={val}
        placeholder={ph}
        required={req}
        onChange={e => set(e.target.value)}
      />
    );
  }

  // AUDIT-FIX (Bug 2 / default field types): 'date' and 'time' had no
  // case at all here, so they fell through to the generic text-input
  // fallback at the bottom of this file and rendered as plain text
  // boxes with a "Date"/"Time" placeholder instead of native pickers.
  if (type === 'date') {
    return fieldWrap(
      <input
        type="date"
        name={`form_data[${name}]`}
        value={val}
        min={field.min || undefined}
        max={field.max || undefined}
        required={req}
        onChange={e => set(e.target.value)}
      />
    );
  }

  if (type === 'time') {
    return fieldWrap(
      <input
        type="time"
        name={`form_data[${name}]`}
        value={val}
        required={req}
        onChange={e => set(e.target.value)}
      />
    );
  }

  if (type === 'textarea') {
    return fieldWrap(
      <textarea
        name={`form_data[${name}]`}
        value={val}
        placeholder={ph}
        required={req}
        onChange={e => set(e.target.value)}
      />
    );
  }

  if (type === 'select') {
    return fieldWrap(
      <select
        name={`form_data[${name}]`}
        value={val}
        required={req}
        onChange={e => set(e.target.value)}
      >
        <option value="">— Select —</option>
        {opts.map((o, i) => (
          <option key={i} value={o.value || o.label || o}>
            {(o.label || o.value || o)}{wcPriceSuffix(o.value || o.label || o)}
          </option>
        ))}
      </select>
    );
  }

  if (type === 'radio') {
    return fieldWrap(
      <div>
        {opts.map((o, i) => {
          const ov = o.value || o.label || o;
          const ol = o.label || o.value || o;
          const sel = val === ov;
          return (
            <label key={i} className={`cqw-radio-opt${sel ? ' sel' : ''}`}>
              <input type="radio" name={`form_data[${name}]`} value={ov}
                     checked={sel} required={req}
                     onChange={() => set(ov)} />
              <span className="cqw-radio-dot" />
              <span className="cqw-opt-lbl">{ol}{wcPriceSuffix(ov)}</span>
            </label>
          );
        })}
      </div>
    );
  }

  if (type === 'checkbox') {
    const arrVal = Array.isArray(val) ? val : (val ? [val] : []);
    return fieldWrap(
      <div>
        {opts.map((o, i) => {
          const ov  = o.value || o.label || o;
          const ol  = o.label || o.value || o;
          const sel = arrVal.includes(ov);
          return (
            <label key={i} className={`cqw-check-opt${sel ? ' sel' : ''}`}>
              <input type="checkbox" name={`form_data[${name}][]`} value={ov}
                     checked={sel} onChange={() => {
                       const next = sel
                         ? arrVal.filter(v => v !== ov)
                         : [...arrVal, ov];
                       set(next);
                     }} />
              <span className="cqw-check-box">{sel ? '✓' : ''}</span>
              <span className="cqw-opt-lbl">{ol}{wcPriceSuffix(ov)}</span>
            </label>
          );
        })}
      </div>
    );
  }

  if (type === 'file') {
    // AUDIT-FIX (field type audit — file uploads in multi-step forms):
    // Multi-step forms only keep the CURRENT step's fields mounted in
    // the DOM. The submit handler builds FormData from formState (not
    // from the live DOM) so values survive step navigation — but this
    // field used to store only the filename STRING in formState
    // (`set(e.target.files[0]?.name)`), discarding the actual File
    // blob the moment the step unmounted. Submissions silently lost
    // the uploaded file whenever the File field wasn't on the last step.
    //
    // Fix: also stash the real File object in `fileRef.current[name]`
    // (a ref that lives for the whole widget session, independent of
    // step mounting). BookingWidget's submit handler reads this map
    // and overwrites the FormData entry with the actual File right
    // before sending, regardless of which step is currently rendered.
    const fileName = val || '';
    return fieldWrap(
      <label className="cqw-file-btn">
        <span>📎</span>
        <span>{fileName || 'Choose file'}</span>
        <input
          type="file"
          name={`form_data[${name}]`}
          required={req}
          accept={field.accept || undefined}
          style={{display:'none'}}
          onChange={e => {
            const f = e.target.files[0] || null;
            if (fileRef) fileRef.current[name] = f;
            set(f ? f.name : '');
          }}
        />
      </label>
    );
  }

  // Fallback: unmatched field type with no '_frontend' descriptor.
  // Rather than rendering a visually empty box (Bug 2), show a plain text
  // input that at least carries the field's own label as a placeholder,
  // plus a small hint if we know which addon owns this type but it isn't
  // contributing a render descriptor.
  return fieldWrap(
    <>
      <input
        type="text"
        name={`form_data[${name}]`}
        value={val}
        placeholder={ph || label}
        required={req}
        onChange={e => set(e.target.value)}
      />
      {field._addon && (
        <div className="cqw-addon-hint">
          {`Provided by the ${field._addon} addon`}
        </div>
      )}
    </>
  );
}

export { SLOT_COLORS };
