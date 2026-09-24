import { create } from 'zustand';
import { registerSW } from 'virtual:pwa-register';

/**
 * Service worker updates (bible-assistant's pattern): a new version is
 * announced by a banner and applied only when the listener taps it — never a
 * reload in the middle of a song. Checked on start, every 30 minutes and when
 * the app returns to the foreground.
 */

interface UpdateState {
  needRefresh: boolean;
  offlineReady: boolean;
}

export const useUpdateStore = create<UpdateState>(() => ({ needRefresh: false, offlineReady: false }));

let updateSW: ((reload?: boolean) => Promise<void>) | null = null;
let registration: ServiceWorkerRegistration | null = null;

export function initPwaUpdate(): void {
  if (updateSW || import.meta.env.DEV) return;
  updateSW = registerSW({
    immediate: true,
    onNeedRefresh: () => useUpdateStore.setState({ needRefresh: true }),
    onOfflineReady: () => useUpdateStore.setState({ offlineReady: true }),
    onRegisteredSW: (_url, reg) => {
      if (reg) registration = reg;
    },
  });
  setInterval(() => void checkForUpdates(), 30 * 60_000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') void checkForUpdates();
  });
}

export async function checkForUpdates(): Promise<void> {
  try {
    await registration?.update();
  } catch {
    /* offline */
  }
}

export async function applyUpdate(): Promise<void> {
  if (updateSW) await updateSW(true);
  else window.location.reload();
}
