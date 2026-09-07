import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './App.css';

// Create the mounting point dynamically if it doesn't exist
let root = document.getElementById('credoq-ai-assistant-root');
if (!root) {
  root = document.createElement('div');
  root.id = 'credoq-ai-assistant-root';
  document.body.appendChild(root);
}

ReactDOM.createRoot(root).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
