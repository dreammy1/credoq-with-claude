import React from 'react';
import { createRoot } from 'react-dom/client';
import BookingWidget from './BookingWidget.jsx';
import './widget.css';

function mount() {
  const el = document.getElementById('credoq-booking-root');
  if (!el) return;

  let config = {};
  try {
    config = JSON.parse(el.getAttribute('data-config') || '{}');
  } catch (e) {
    console.error('CredoQ: failed to parse data-config', e);
  }

  // Merge window.credoq_booking as fallback
  config = Object.assign({}, window.credoq_booking || {}, config);

  const root = createRoot(el);
  root.render(<BookingWidget config={config} />);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
