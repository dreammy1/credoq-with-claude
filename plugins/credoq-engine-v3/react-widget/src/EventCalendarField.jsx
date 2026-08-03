/**
 * EventCalendarField — full interactive event calendar for the booking widget.
 *
 * Renders:
 *  1. Month-grid calendar — dates with events show a colour badge
 *  2. Date-click panel — checkbox list of events on that day, each with:
 *       price badge, remaining-capacity badge, inline qty stepper
 *  3. Running total price panel below the event list
 *
 * Value pushed to formState / submitted as JSON array:
 *   [{event_id, quantity, price}, …]  (one entry per selected event)
 *
 * @param {object} props
 * @param {object} props.field         field config (from server)
 * @param {string} props.name          form_data key
 * @param {string} props.val           current formState value (JSON string)
 * @param {Function} props.set         setFormState updater
 * @param {Function} props.fieldWrap   wraps output in label + error div
 * @param {string}   props.currency    e.g. 'USD'
 */

import React, { useState, useMemo, useCallback } from 'react';

const DAYS  = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
const MONTHS= ['January','February','March','April','May','June',
               'July','August','September','October','November','December'];

function toISO(y, m, d) {
  return `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
}

export default function EventCalendarField({ field, name, val, set, fieldWrap, currency, seatMappedEventIds }) {
  const seatMapped = seatMappedEventIds || new Set();
  const props      = field._frontend?.props || {};
  const byDate     = props.by_date     || {};
  const allEvents  = props.events      || [];
  const maxTickets = props.max_tickets || 10;
  const currSym    = props.currency_sym || (currency || '$');

  // Parse current selection from formState value
  const selection = useMemo(() => {
    if (!val) return {};
    try {
      const arr = JSON.parse(val);
      if (Array.isArray(arr)) {
        const m = {};
        arr.forEach(s => { m[s.event_id] = { qty: s.quantity || 1, price: s.price || 0 }; });
        return m;
      }
    } catch {}
    return {};
  }, [val]);

  // Calendar navigation
  const today = new Date(); today.setHours(0,0,0,0);
  const [viewYear,  setViewYear]  = useState(() => today.getFullYear());
  const [viewMonth, setViewMonth] = useState(() => today.getMonth());
  const [activeDate, setActiveDate] = useState(null); // Y-m-d

  // All dates that have events
  const eventDates = useMemo(() => new Set(Object.keys(byDate)), [byDate]);

  // Push updated selection back to formState
  const pushSelection = useCallback((newSel) => {
    const arr = Object.entries(newSel)
      .filter(([, s]) => s.qty > 0)
      .map(([eid, s]) => ({ event_id: parseInt(eid), quantity: s.qty, price: s.price }));
    set(arr.length ? JSON.stringify(arr) : '');
  }, [set]);

  const toggleEvent = (evId, price, maxQty) => {
    const cur = { ...selection };
    if (cur[evId]) {
      delete cur[evId];
    } else {
      cur[evId] = { qty: 1, price };
    }
    pushSelection(cur);
  };

  const changeQty = (evId, price, delta, maxQty) => {
    const cur = { ...selection };
    const existing = cur[evId];
    if (!existing) return;
    const newQty = Math.max(1, Math.min(maxQty, existing.qty + delta));
    cur[evId] = { qty: newQty, price };
    pushSelection(cur);
  };

  // Grand total
  const grandTotal = useMemo(() => {
    return Object.values(selection).reduce((t, s) => t + s.price * s.qty, 0);
  }, [selection]);

  const totalQty = useMemo(() => {
    return Object.values(selection).reduce((t, s) => t + s.qty, 0);
  }, [selection]);

  // Build calendar cells
  const cells = useMemo(() => {
    const first    = new Date(viewYear, viewMonth, 1);
    const startDow = ((first.getDay() + 6) % 7); // Mon=0
    const daysInM  = new Date(viewYear, viewMonth + 1, 0).getDate();
    const out = [];
    for (let i = 0; i < startDow; i++) out.push(null);
    for (let d = 1; d <= daysInM; d++) {
      const iso     = toISO(viewYear, viewMonth, d);
      const date    = new Date(viewYear, viewMonth, d);
      const isPast  = date < today;
      const isToday = date.getTime() === today.getTime();
      const evs     = byDate[iso] || [];
      const hasSel  = evs.some(e => selection[e.id]);
      out.push({ d, iso, isPast, isToday, evs, hasSel });
    }
    return out;
  }, [viewYear, viewMonth, byDate, selection]);

  const prevMonth = () => {
    if (viewMonth === 0) { setViewYear(y => y - 1); setViewMonth(11); }
    else setViewMonth(m => m - 1);
    setActiveDate(null);
  };
  const nextMonth = () => {
    if (viewMonth === 11) { setViewYear(y => y + 1); setViewMonth(0); }
    else setViewMonth(m => m + 1);
    setActiveDate(null);
  };

  const activeEvents = activeDate ? (byDate[activeDate] || []) : [];

  return fieldWrap(
    <div className="cqw-event-cal-root">
      {/* ── Calendar header ─────────────────────────────────── */}
      <div className="cqw-event-cal-hdr">
        <button type="button" className="cqw-cal-nav" onClick={prevMonth}>‹</button>
        <span className="cqw-event-cal-title">{MONTHS[viewMonth]} {viewYear}</span>
        <button type="button" className="cqw-cal-nav" onClick={nextMonth}>›</button>
      </div>

      {/* ── Legend ──────────────────────────────────────────── */}
      <div className="cqw-event-cal-legend">
        <span className="cqw-event-cal-dot" style={{background:'#4f46e5'}}/>Events available
        {totalQty > 0 && <span style={{marginLeft:'auto',fontWeight:700,color:'#16a34a'}}>
          {totalQty} ticket{totalQty!==1?'s':''} selected
        </span>}
      </div>

      {/* ── Day-of-week headers ──────────────────────────────── */}
      <div className="cqw-event-cal-dn">
        {DAYS.map(d => <div key={d}>{d}</div>)}
      </div>

      {/* ── Calendar grid ────────────────────────────────────── */}
      <div className="cqw-event-cal-grid">
        {cells.map((cell, i) => {
          if (!cell) return <div key={`e${i}`} className="cqw-event-cal-c empty" />;
          const { d, iso, isPast, isToday, evs, hasSel } = cell;
          const hasEvs = evs.length > 0;
          const active  = iso === activeDate;
          let cls = 'cqw-event-cal-c';
          if (isPast)       cls += ' past';
          else if (isToday) cls += ' today';
          else if (hasEvs)  cls += ' has-events';
          if (active)       cls += ' active';
          if (hasSel)       cls += ' selected';

          return (
            <div key={iso} className={cls}
                 onClick={() => !isPast && hasEvs && setActiveDate(active ? null : iso)}
                 title={hasEvs && !isPast ? `${evs.length} event${evs.length!==1?'s':''} — click to select` : undefined}>
              <span className="cqw-cal-day-num">{d}</span>
              {/* Event dots */}
              {hasEvs && !isPast && (
                <div className="cqw-event-cal-dots">
                  {evs.slice(0,3).map(ev => (
                    <span key={ev.id} className="cqw-event-cal-dot"
                          style={{background: selection[ev.id] ? '#16a34a' : ev.color}} />
                  ))}
                  {evs.length > 3 && <span className="cqw-event-cal-more">+{evs.length-3}</span>}
                </div>
              )}
              {hasSel && <span className="cqw-event-cal-sel-tick">✓</span>}
            </div>
          );
        })}
      </div>

      {/* ── Date event list (shown on date-click) ───────────── */}
      {activeDate && activeEvents.length > 0 && (
        <div className="cqw-event-day-panel">
          <div className="cqw-event-day-title">
            {new Date(activeDate + 'T00:00:00').toLocaleDateString(undefined,
              {weekday:'long', year:'numeric', month:'long', day:'numeric'})}
          </div>
          <div className="cqw-event-list">
            {activeEvents.map(ev => {
              const sel     = selection[ev.id];
              const checked = !!sel;
              const qty     = sel?.qty || 1;
              const maxQty  = ev.max_qty || maxTickets;
              const isFull  = ev.remaining !== null && ev.remaining === 0;

              return (
                <div key={ev.id} className={`cqw-event-row${checked?' checked':''}${isFull?' full':''}`}>
                  {/* Checkbox */}
                  <label className="cqw-event-check-wrap">
                    <input type="checkbox"
                           disabled={isFull && !checked}
                           checked={checked}
                           onChange={() => !isFull && toggleEvent(ev.id, ev.price, maxQty)} />
                    <span className="cqw-event-check-box">{checked ? '✓' : ''}</span>
                  </label>

                  {/* Event info */}
                  <div className="cqw-event-info" onClick={() => !isFull && toggleEvent(ev.id, ev.price, maxQty)}
                       style={{cursor: isFull ? 'not-allowed' : 'pointer'}}>
                    <div className="cqw-event-row-title">{ev.title}</div>
                    <div className="cqw-event-row-meta">
                      🕐 {ev.start}{ev.end ? ` – ${ev.end}` : ''}
                      {ev.location && <> &nbsp;📍 {ev.location}</>}
                    </div>
                  </div>

                  {/* Price badge */}
                  <span className="cqw-event-row-price"
                        style={{background: ev.color+'22', color: ev.color}}>
                    {ev.price > 0 ? `${currSym}${ev.price.toFixed(2)}` : 'Free'}
                  </span>

                  {/* Capacity badge */}
                  {ev.remaining !== null && (
                    <span className={`cqw-event-cap-badge${isFull?' full':ev.remaining<=5?' low':''}`}>
                      {isFull ? 'Full' : `${ev.remaining} left`}
                    </span>
                  )}

                  {/* Qty stepper (only when selected) — locked when a seat
                      map on this same form governs this event, since the
                      real quantity for that event is decided by however
                      many seats get picked in the map below, not here. */}
                  {checked && seatMapped.has(ev.id) && (
                    <div className="cqw-event-qty-stepper cqw-event-qty-locked" title="Quantity is set by your seat selection below">
                      <span>{qty} seat{qty !== 1 ? 's' : ''}</span>
                    </div>
                  )}
                  {checked && !seatMapped.has(ev.id) && (
                    <div className="cqw-event-qty-stepper">
                      <button type="button" onClick={() => changeQty(ev.id, ev.price, -1, maxQty)}
                              disabled={qty <= 1}>−</button>
                      <span>{qty}</span>
                      <button type="button" onClick={() => changeQty(ev.id, ev.price, +1, maxQty)}
                              disabled={qty >= maxQty}>+</button>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* ── Running total ────────────────────────────────────── */}
      {totalQty > 0 && (
        <div className="cqw-event-total-bar">
          <div className="cqw-event-total-details">
            {Object.entries(selection).map(([eid, s]) => {
              const ev = allEvents.find(e => e.id === parseInt(eid));
              if (!ev) return null;
              return (
                <div key={eid} className="cqw-event-total-line">
                  <span>{ev.title} × {s.qty}</span>
                  <span>{currSym}{(s.price * s.qty).toFixed(2)}</span>
                </div>
              );
            })}
          </div>
          <div className="cqw-event-total-sum">
            <span>Total</span>
            <span className="cqw-event-total-amount">{currSym}{grandTotal.toFixed(2)}</span>
          </div>
        </div>
      )}

      {/* Hidden input carries the JSON value for FormData submission */}
      <input type="hidden" name={`form_data[${name}]`} value={val || ''} />
    </div>
  );
}
