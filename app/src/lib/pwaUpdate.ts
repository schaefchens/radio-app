import { create } from 'zustand';
import { registerSW } from 'virtual:pwa-register';
import { onAppForeground } from './native';

/**
 * Updates (bible-assistant's pattern): a new version is announced by a banner
 * and applied only when the listener taps it — never a reload in the middle
 * of a song. Checked on start, every 30 minutes and when the app returns to
 * the foreground.
 *
 * Without a service worker — the iOS store app: WKWebView runs none without
 * App-Bound Domains — the check compares the page's entry script with the
 * one the site serves now, and the banner reloads.
 */

interface UpdateState {
  needRefresh: boolean;
  offlineReady: boolean;
}

export const useUpdateStore = create<UpdateState>(() => ({ needRefresh: false, offlineReady: false }));

let started = false;
let updateSW: ((reload?: boolean) => Promise<void>) | null = null;
let registration: ServiceWorkerRegistration | null = null;

export function initPwaUpdate(): void {
  if (started || import.meta.env.DEV) return;
  started = true;
  if ('serviceWorker' in navigator) {
    updateSW = registerSW({
      immediate: true,
      onNeedRefresh: () => useUpdateStore.setState({ needRefresh: true }),
      onOfflineReady: () => useUpdateStore.setState({ offlineReady: true }),
      onRegisteredSW: (_url, reg) => {
        if (reg) registration = reg;
      },
    });
  }
  setInterval(() => void checkForUpdates(), 30 * 60_000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') void checkForUpdates();
  });
  // iOS reports a return through the App plugin more reliably than through visibilitychange.
  onAppForeground(() => void checkForUpdates());
}

export async function checkForUpdates(): Promise<void> {
  if ('serviceWorker' in navigator) {
    try {
      await registration?.update();
    } catch {
      /* offline */
    }
    return;
  }
  try {
    const res = await fetch('/', { cache: 'no-store' });
    if (!res.ok) return;
    const latest = entryOf(await res.text());
    const running = document.querySelector<HTMLScriptElement>('script[type="module"][src]')?.getAttribute('src') ?? null;
    if (latest && running && latest !== running) useUpdateStore.setState({ needRefresh: true });
  } catch {
    /* offline */
  }
}

/**
 * The module entry of a built index.html (`<script type="module" crossorigin
 * src="/assets/index-….js">`), which changes with every build. Not the classic
 * install-event.js before it.
 */
export function entryOf(html: string): string | null {
  for (const tag of html.match(/<script\b[^>]*>/gi) ?? []) {
    if (!/\btype\s*=\s*["']module["']/i.test(tag)) continue;
    const src = /\bsrc\s*=\s*["']([^"']+)["']/i.exec(tag);
    if (src) return src[1] ?? null;
  }
  return null;
}

export async function applyUpdate(): Promise<void> {
  if (updateSW) await updateSW(true);
  else window.location.reload();
}
