import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './index.css';
import './i18n';
import { App } from './App';
import { initPwaUpdate } from './lib/pwaUpdate';
import { applyTheme } from './lib/theme';

applyTheme();
initPwaUpdate();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
