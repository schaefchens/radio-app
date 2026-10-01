import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './index.css';
// After index.css, so the design's rules come after Tailwind's reset.
import './styles/shell.css';
import './styles/home.css';
import './styles/welcome.css';
import './i18n';
import { App } from './App';
import { initPwaInstall } from './lib/pwaInstall';
import { initPwaUpdate } from './lib/pwaUpdate';
import { lockZoomWhenInstalled } from './lib/platform';
import { applyTheme } from './lib/theme';

applyTheme();
// Before the service worker registers: registering can make Chrome fire
// `beforeinstallprompt`, and a listener added after it has missed it.
initPwaInstall();
initPwaUpdate();
lockZoomWhenInstalled();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
