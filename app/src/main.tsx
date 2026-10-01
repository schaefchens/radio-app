import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './index.css';
// After index.css, so the design's rules come after Tailwind's reset.
import './styles/shell.css';
import './styles/home.css';
import './styles/welcome.css';
import './i18n';
import { App } from './App';
import { initPwaUpdate } from './lib/pwaUpdate';
import { lockZoomWhenInstalled } from './lib/platform';
import { applyTheme } from './lib/theme';

applyTheme();
initPwaUpdate();
lockZoomWhenInstalled();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
