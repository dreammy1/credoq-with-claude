import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './App.css';

const container = document.createElement('div');
container.id = 'credoq-assistant-container';
document.body.appendChild(container);

ReactDOM.createRoot(container).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
