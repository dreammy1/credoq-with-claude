import React, { useEffect, useState, useMemo } from 'react';

const DAYS   = ['Mo','Tu','We','Th','Fr','Sa','Su'];
const MONTHS = ['January','February','March','April','May','June','July',
                'August','September','October','November','December'];

function toISO(y, m, d) {
  return `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
}

export default function Calendar({
  config,
  selectedDate,
  onSelect,
  appointmentId = 0,
  staffId = 0,
  ajax = null,
  currency = '',
}) {
  const today = new Date();
  today.setHours(0,0,0,0);

  const minDate = appointmentId ? today : (config.min_date ? new Date(config.min_date + 'T00:00:00') : today);
  const maxDate = appointmentId ? null  : (config.max_date ? new Date(config.max_date + 'T00:00:00') : null);

  const [viewDate,    setViewDate]    = useState(() => {
    const d = new Date(minDate);
    return { year: d.getFullYear(), month: d.getMonth() };
  });
  const [dateCapacity,  setDateCapacity]  = useState({});
  const [specialDates,  setSpecialDates]  = useState(new Set());
  const getDateCapacity = ajax && ajax.getDateCapacity;

  useEffect(() => {
    let active = true;
    if (!appointmentId || !getDateCapacity) {
      setDateCapacity({});
      setSpecialDates(new Set());
      return () => { active = false; };
    }
    getDateCapacity(appointmentId, staffId, viewDate.year, viewDate.month + 1)
      .then(data => {
        if (!active) return;
        // data is now the full REST response: { dates:{}, available_dates:[], special_dates:[] }
        setDateCapacity(data?.dates || data || {});
        const sp = Array.isArray(data?.special_dates) ? data.special_dates : [];
        setSpecialDates(new Set(sp));
      })
      .catch(() => { if (active) { setDateCapacity({}); setSpecialDates(new Set()); } });
    return () => { active = false; };
  }, [appointmentId, staffId, viewDate.year, viewDate.month, getDateCapacity]);

  const offDays     = useMemo(() => new Set(config.off_days || []), [config.off_days]);
  const workingDays = useMemo(() => new Set((config.working_days || [1,2,3,4,5]).map(Number)), [config.working_days]);

  function isDisabled(date) {
    if (date < minDate) return true;
    if (maxDate && date > maxDate) return true;
    const ymd = toISO(date.getFullYear(), date.getMonth(), date.getDate());

    if (appointmentId) {
      if (Object.keys(dateCapacity).length === 0) return false;
      const cap = dateCapacity[ymd];
      if (!cap) return true;
      if (typeof cap === 'object') return !!(cap.no_slots || cap.is_full);
      return false;
    }

    let iso = date.getDay();
    if (iso === 0) iso = 7;
    if (!workingDays.has(iso)) return true;
    if (offDays.has(ymd)) return true;
    return false;
  }

  function buildCells() {
    const { year, month } = viewDate;
    const firstDay = new Date(year, month, 1);
    let startOffset = firstDay.getDay() - 1;
    if (startOffset < 0) startOffset = 6;
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const cells = [];
    for (let i = 0; i < startOffset; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) {
      const date    = new Date(year, month, d);
      const iso     = toISO(year, month, d);
      const disabled  = isDisabled(date);
      const isToday   = date.getTime() === today.getTime();
      const picked    = iso === selectedDate;
      const isSpecial = !disabled && specialDates.has(iso);
      cells.push({ d, iso, disabled, isToday, picked, isSpecial });
    }
    return cells;
  }

  function prevMonth() {
    setViewDate(v => v.month === 0 ? { year: v.year - 1, month: 11 } : { year: v.year, month: v.month - 1 });
  }
  function nextMonth() {
    setViewDate(v => v.month === 11 ? { year: v.year + 1, month: 0 } : { year: v.year, month: v.month + 1 });
  }

  const cells = buildCells();

  return (
    <div className="cqw-cal">
      <div className="cqw-cal-hdr">
        <button type="button" className="cqw-cal-nav" onClick={prevMonth}>‹</button>
        <span className="cqw-cal-month">{MONTHS[viewDate.month]} {viewDate.year}</span>
        <button type="button" className="cqw-cal-nav" onClick={nextMonth}>›</button>
      </div>
      <div className="cqw-cal-dn">
        {DAYS.map(d => <div key={d}>{d}</div>)}
      </div>
      <div className="cqw-cal-grid">
        {cells.map((cell, i) => {
          if (!cell) return <div key={`e-${i}`} className="cqw-cal-c empty" />;
          let cls = 'cqw-cal-c';
          if (cell.disabled)        cls += ' off';
          else if (cell.picked)     cls += ' picked';
          else if (cell.isToday)    cls += ' today';
          else                      cls += ' avail';
          if (cell.isSpecial)       cls += ' special';
          return (
            <div
              key={cell.iso}
              className={cls}
              title={cell.isSpecial ? 'Special pricing applies' : undefined}
              onClick={() => !cell.disabled && onSelect(cell.iso)}
            >
              {cell.d}
              {/* Special date badge — visible star dot */}
              {cell.isSpecial && <span className="cqw-cal-sp-badge" aria-label="Special price">★</span>}
            </div>
          );
        })}
      </div>
      {/* Legend shown only when there are special dates this month */}
      {specialDates.size > 0 && (
        <div className="cqw-cal-legend">
          <span className="cqw-cal-sp-badge-sm">★</span>
          <span>Special pricing applies on highlighted dates</span>
        </div>
      )}
    </div>
  );
}
