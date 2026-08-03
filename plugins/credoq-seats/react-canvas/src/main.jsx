import React from 'react';
import { createRoot } from 'react-dom/client';
import CanvasBuilder from './CanvasBuilder.jsx';
import './style.css';

function mount() {
  const root = document.getElementById('cvsp-builder-root');
  if (!root) return;

  let layout = { floors: [] };
  try {
    layout = JSON.parse(root.getAttribute('data-layout') || '{"floors":[]}');
  } catch (e) {
    layout = { floors: [] };
  }
  if (!layout.floors || !layout.floors.length) {
    layout.floors = [{ name: 'Floor 1', color: '#4f46e5', seats: [] }];
  }

  const planId = parseInt(root.getAttribute('data-plan-id'), 10) || 0;
  const hiddenInputId = root.getAttribute('data-hidden-input-id');
  const formId = root.getAttribute('data-form-id');

  createRoot(root).render(
    <CanvasBuilder
      initialLayout={layout}
      planId={planId}
      onSave={(finalLayout) => {
        const input = document.getElementById(hiddenInputId);
        const form = document.getElementById(formId);
        if (input) input.value = JSON.stringify(finalLayout);
        if (form) form.submit();
      }}
    />
  );
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
