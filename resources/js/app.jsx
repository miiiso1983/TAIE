import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './teami/App';
import '../css/app.css';
import '../css/identity.css';
import '../css/public-site.css';

createRoot(document.getElementById('teami-root')).render(<App />);
