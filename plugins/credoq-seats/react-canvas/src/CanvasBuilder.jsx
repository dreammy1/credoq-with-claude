import React, { useState, useRef, useCallback, useMemo } from 'react';

const SEAT_SIZE = 28;
const SEAT_TYPES = ['standard', 'vip', 'accessible', 'restricted', 'aisle'];
const SEAT_STATUSES = ['available', 'reserved', 'blocked'];
const TYPE_COLORS = {
  standard: '#94a3b8', vip: '#f59e0b', accessible: '#0ea5e9', restricted: '#ef4444', aisle: '#cbd5e1',
};

let uidCounter = 1;
function makeUid(seat) {
  return seat.db_id ? 's' + seat.db_id : 'tmp' + (uidCounter++);
}

function cloneLayout(layout) {
  const copy = JSON.parse(JSON.stringify(layout));
  copy.floors.forEach((f) => {
    f.seats = (f.seats || []).map((s) => ({ ...s, _uid: makeUid(s) }));
  });
  return copy;
}

function colLetter(i) {
  return String.fromCharCode(65 + (i % 26));
}

export default function CanvasBuilder({ initialLayout, planId, onSave }) {
  const [layout, setLayout] = useState(() => cloneLayout(initialLayout));
  const [activeFloor, setActiveFloor] = useState(0);
  const [selected, setSelected] = useState([]); // array of _uid
  const [zoom, setZoom] = useState(1);
  const [snap, setSnap] = useState(true);
  const [genRows, setGenRows] = useState(5);
  const [genCols, setGenCols] = useState(8);
  const [genAisle, setGenAisle] = useState(0);

  const canvasRef = useRef(null);
  const dragRef = useRef(null); // { startX, startY, startPositions: {uid:{x,y}}, moved }

  const floor = layout.floors[activeFloor] || { name: '', color: '#4f46e5', seats: [] };

  const updateFloor = useCallback((updater) => {
    setLayout((prev) => {
      const next = { ...prev, floors: prev.floors.map((f, i) => (i === activeFloor ? updater({ ...f, seats: [...f.seats] }) : f)) };
      return next;
    });
  }, [activeFloor]);

  const snapVal = (v) => (snap ? Math.round(v / 10) * 10 : Math.round(v));

  /* ── Selection & drag ─────────────────────────────────────────────── */

  const onSeatPointerDown = (e, seat) => {
    e.stopPropagation();
    e.preventDefault();
    let newSelection = selected;

    if (e.shiftKey) {
      newSelection = selected.includes(seat._uid) ? selected.filter((u) => u !== seat._uid) : [...selected, seat._uid];
      setSelected(newSelection);
    } else if (!selected.includes(seat._uid)) {
      newSelection = [seat._uid];
      setSelected(newSelection);
    }

    const startPositions = {};
    floor.seats.forEach((s) => {
      if (newSelection.includes(s._uid)) startPositions[s._uid] = { x: s.x || 0, y: s.y || 0 };
    });

    dragRef.current = {
      startX: e.clientX, startY: e.clientY, startPositions, moved: false,
    };

    window.addEventListener('pointermove', onWindowPointerMove);
    window.addEventListener('pointerup', onWindowPointerUp);
  };

  const onWindowPointerMove = (e) => {
    const drag = dragRef.current;
    if (!drag) return;
    const dx = (e.clientX - drag.startX) / zoom;
    const dy = (e.clientY - drag.startY) / zoom;
    if (Math.abs(dx) > 2 || Math.abs(dy) > 2) drag.moved = true;
    if (!drag.moved) return;

    updateFloor((f) => {
      f.seats = f.seats.map((s) => {
        const start = drag.startPositions[s._uid];
        if (!start) return s;
        return { ...s, x: Math.max(0, start.x + dx), y: Math.max(0, start.y + dy) };
      });
      return f;
    });
  };

  const onWindowPointerUp = () => {
    const drag = dragRef.current;
    if (drag && drag.moved && snap) {
      updateFloor((f) => {
        f.seats = f.seats.map((s) => (drag.startPositions[s._uid] ? { ...s, x: snapVal(s.x), y: snapVal(s.y) } : s));
        return f;
      });
    }
    dragRef.current = null;
    window.removeEventListener('pointermove', onWindowPointerMove);
    window.removeEventListener('pointerup', onWindowPointerUp);
  };

  const onCanvasPointerDown = (e) => {
    if (e.target === canvasRef.current) setSelected([]);
  };

  /* ── Toolbar actions ──────────────────────────────────────────────── */

  const generateGrid = () => {
    const rows = Math.max(1, parseInt(genRows, 10) || 1);
    const cols = Math.max(1, parseInt(genCols, 10) || 1);
    const aisleAfter = Math.max(0, parseInt(genAisle, 10) || 0);
    const spacing = 34;
    const seats = [];
    for (let r = 0; r < rows; r++) {
      const rowLabel = colLetter(r);
      let x = 0;
      for (let c = 0; c < cols; c++) {
        seats.push({ _uid: 'tmp' + (uidCounter++), label: rowLabel + (c + 1), type: 'standard', status: 'available', row: r, col: c, x, y: r * spacing, price: null });
        x += spacing;
        if (aisleAfter && c + 1 === aisleAfter) x += spacing * 0.6;
      }
    }
    updateFloor((f) => ({ ...f, seats }));
    setSelected([]);
  };

  const addSeat = () => {
    updateFloor((f) => {
      const n = f.seats.length + 1;
      f.seats.push({ _uid: 'tmp' + (uidCounter++), label: 'NEW' + n, type: 'standard', status: 'available', row: 0, col: n, x: 10, y: 10, price: null });
      return f;
    });
  };

  const addFloor = () => {
    setLayout((prev) => ({ ...prev, floors: [...prev.floors, { name: 'Floor ' + (prev.floors.length + 1), color: '#4f46e5', seats: [] }] }));
    setActiveFloor(layout.floors.length);
    setSelected([]);
  };

  const deleteFloor = () => {
    if (layout.floors.length <= 1) return;
    if (!window.confirm('Delete this floor and all its seats?')) return;
    setLayout((prev) => ({ ...prev, floors: prev.floors.filter((_, i) => i !== activeFloor) }));
    setActiveFloor(0);
    setSelected([]);
  };

  const deleteSelected = () => {
    if (!selected.length) return;
    if (!window.confirm(`Delete ${selected.length} seat(s)?`)) return;
    updateFloor((f) => ({ ...f, seats: f.seats.filter((s) => !selected.includes(s._uid)) }));
    setSelected([]);
  };

  const duplicateSelected = () => {
    if (!selected.length) return;
    updateFloor((f) => {
      const copies = f.seats
        .filter((s) => selected.includes(s._uid))
        .map((s) => ({ ...s, _uid: 'tmp' + (uidCounter++), db_id: undefined, label: s.label + '-copy', x: (s.x || 0) + 20, y: (s.y || 0) + 20 }));
      return { ...f, seats: [...f.seats, ...copies] };
    });
  };

  const applyToSelected = (patch) => {
    updateFloor((f) => ({
      ...f,
      seats: f.seats.map((s) => (selected.includes(s._uid) ? { ...s, ...patch } : s)),
    }));
  };

  const save = () => {
    const stripped = {
      ...layout,
      floors: layout.floors.map((f) => ({
        ...f,
        seats: f.seats.map(({ _uid, ...rest }) => rest),
      })),
    };
    onSave(stripped);
  };

  const selectedSeats = useMemo(() => floor.seats.filter((s) => selected.includes(s._uid)), [floor.seats, selected]);

  /* ── Render ───────────────────────────────────────────────────────── */

  return (
    <div className="cvsp-rb-root">
      <div className="cvsp-rb-toolbar">
        <div className="cvsp-rb-tool-group">
          <label>Rows</label>
          <input type="number" min="1" max="40" value={genRows} onChange={(e) => setGenRows(e.target.value)} />
        </div>
        <div className="cvsp-rb-tool-group">
          <label>Seats/row</label>
          <input type="number" min="1" max="60" value={genCols} onChange={(e) => setGenCols(e.target.value)} />
        </div>
        <div className="cvsp-rb-tool-group">
          <label>Aisle after #</label>
          <input type="number" min="0" max="60" value={genAisle} onChange={(e) => setGenAisle(e.target.value)} />
        </div>
        <button type="button" className="button button-primary" onClick={generateGrid}>Generate grid</button>
        <button type="button" className="button" onClick={addSeat}>+ Seat</button>
        <button type="button" className="button" onClick={addFloor}>+ Floor</button>
        <button type="button" className="button" onClick={duplicateSelected} disabled={!selected.length}>Duplicate</button>
        <button type="button" className="button cvsp-rb-danger" onClick={deleteSelected} disabled={!selected.length}>Delete selected</button>
        <div className="cvsp-rb-tool-group">
          <label>Zoom</label>
          <div className="cvsp-rb-zoom">
            <button type="button" className="button" onClick={() => setZoom((z) => Math.max(0.4, +(z - 0.1).toFixed(2)))}>−</button>
            <span>{Math.round(zoom * 100)}%</span>
            <button type="button" className="button" onClick={() => setZoom((z) => Math.min(2, +(z + 0.1).toFixed(2)))}>+</button>
          </div>
        </div>
        <label className="cvsp-rb-snap">
          <input type="checkbox" checked={snap} onChange={(e) => setSnap(e.target.checked)} /> Snap to grid
        </label>
        <button type="button" className="button button-primary cvsp-rb-save" onClick={save}>Save canvas</button>
      </div>

      <div className="cvsp-rb-body">
        <div className="cvsp-rb-main">
          {layout.floors.length > 1 && (
            <div className="cvsp-rb-floor-tabs">
              {layout.floors.map((f, i) => (
                <div
                  key={i}
                  className={'cvsp-rb-floor-tab' + (i === activeFloor ? ' is-active' : '')}
                  onClick={() => { setActiveFloor(i); setSelected([]); }}
                  style={{ borderColor: i === activeFloor ? (f.color || '#4f46e5') : 'transparent' }}
                >
                  {f.name || 'Floor ' + (i + 1)}
                </div>
              ))}
            </div>
          )}

          <div className="cvsp-rb-canvas-scroll">
            <div
              ref={canvasRef}
              className="cvsp-rb-canvas"
              onPointerDown={onCanvasPointerDown}
              style={{ transform: `scale(${zoom})`, transformOrigin: 'top left' }}
            >
              {floor.seats.map((seat) => (
                <div
                  key={seat._uid}
                  className={'cvsp-rb-seat' + (selected.includes(seat._uid) ? ' is-selected' : '') + (seat.status === 'blocked' ? ' is-blocked' : '')}
                  style={{
                    left: (seat.x || 0) + 'px',
                    top: (seat.y || 0) + 'px',
                    width: SEAT_SIZE, height: SEAT_SIZE,
                    background: TYPE_COLORS[seat.type] || TYPE_COLORS.standard,
                  }}
                  onPointerDown={(e) => onSeatPointerDown(e, seat)}
                  title={`${seat.label} — ${seat.type}`}
                >
                  {seat.label}
                </div>
              ))}
              {!floor.seats.length && (
                <div className="cvsp-rb-empty-hint">No seats yet — use "Generate grid" or "+ Seat" above.</div>
              )}
            </div>
          </div>
        </div>

        <SidePanel
          selectedSeats={selectedSeats}
          floor={floor}
          onApply={applyToSelected}
          onFloorChange={(patch) => updateFloor((f) => ({ ...f, ...patch }))}
          onDeleteFloor={deleteFloor}
          canDeleteFloor={layout.floors.length > 1}
        />
      </div>
    </div>
  );
}

function SidePanel({ selectedSeats, floor, onApply, onFloorChange, onDeleteFloor, canDeleteFloor }) {
  if (selectedSeats.length === 0) {
    return (
      <div className="cvsp-rb-panel">
        <h3>Floor</h3>
        <label>Floor name</label>
        <input type="text" value={floor.name || ''} onChange={(e) => onFloorChange({ name: e.target.value })} />
        <label>Floor color</label>
        <input type="color" value={floor.color || '#4f46e5'} onChange={(e) => onFloorChange({ color: e.target.value })} />
        {canDeleteFloor && (
          <p><button type="button" className="button cvsp-rb-danger" onClick={onDeleteFloor}>Delete this floor</button></p>
        )}
        <p className="cvsp-rb-hint">Click a seat to edit it. Shift-click (or drag) to select several. Drag any selected seat to move the whole group.</p>
      </div>
    );
  }

  if (selectedSeats.length === 1) {
    const s = selectedSeats[0];
    return (
      <div className="cvsp-rb-panel">
        <h3>Seat ({s.label})</h3>
        <label>Label</label>
        <input type="text" value={s.label || ''} onChange={(e) => onApply({ label: e.target.value })} />
        <label>Type</label>
        <select value={s.type || 'standard'} onChange={(e) => onApply({ type: e.target.value })}>
          {SEAT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <label>Status</label>
        <select value={s.status || 'available'} onChange={(e) => onApply({ status: e.target.value })}>
          {SEAT_STATUSES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <label>Price override</label>
        <input
          type="number" step="0.01" placeholder="inherit base price"
          value={s.price === null || s.price === undefined ? '' : s.price}
          onChange={(e) => onApply({ price: e.target.value === '' ? null : parseFloat(e.target.value) })}
        />
        <div className="cvsp-rb-xy">
          <div>
            <label>X</label>
            <input type="number" value={Math.round(s.x || 0)} onChange={(e) => onApply({ x: parseFloat(e.target.value) || 0 })} />
          </div>
          <div>
            <label>Y</label>
            <input type="number" value={Math.round(s.y || 0)} onChange={(e) => onApply({ y: parseFloat(e.target.value) || 0 })} />
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="cvsp-rb-panel">
      <h3>{selectedSeats.length} seats selected</h3>
      <label>Set type</label>
      <select defaultValue="" onChange={(e) => e.target.value && onApply({ type: e.target.value })}>
        <option value="">— no change —</option>
        {SEAT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
      </select>
      <label>Set status</label>
      <select defaultValue="" onChange={(e) => e.target.value && onApply({ status: e.target.value })}>
        <option value="">— no change —</option>
        {SEAT_STATUSES.map((t) => <option key={t} value={t}>{t}</option>)}
      </select>
      <label>Set price override</label>
      <input
        type="number" step="0.01" placeholder="leave blank = no change"
        onBlur={(e) => e.target.value !== '' && onApply({ price: parseFloat(e.target.value) })}
      />
      <p className="cvsp-rb-hint">Drag any of the selected seats to move the whole group together.</p>
    </div>
  );
}
