import { hasPlugin, plugin } from './native';

/**
 * "Keep the screen on while listening": phones stop YouTube embeds when the
 * screen locks, and in the store apps the lock sends the app to the
 * background, where the radio leaves. Held only while the setting is on and
 * the listener is in: a radio that is not playing lets the phone sleep.
 *
 * The store apps hold it through the shell (the Web API is missing or
 * unreliable in WebViews); browsers through the Screen Wake Lock API, taken
 * again when the page comes back, because the browser drops it whenever the
 * page is hidden.
 */
let setting = false;
let listening = false;
let sentinel: WakeLockSentinel | null = null;
let shellHolds: boolean | null = null;
let queue: Promise<void> = Promise.resolve();

export function wakeLockSupported(): boolean {
  return hasPlugin('KeepAwake') || (typeof navigator !== 'undefined' && 'wakeLock' in navigator);
}

/** The listener's setting (Profile). */
export function setKeepAwake(on: boolean): void {
  setting = on;
  apply();
}

/** Whether the listener is in (the engine's `joined`). */
export function setListening(on: boolean): void {
  if (listening === on) return;
  listening = on;
  apply();
}

// One change at a time, each acting on the newest wish: a quick join and
// leave must never end with the screen held.
function apply(): void {
  queue = queue.then(sync, sync);
}

async function sync(): Promise<void> {
  const want = setting && listening;
  if (await syncShell(want)) return;
  if (want) await acquire();
  else release();
}

/** True when the store app's shell took care of it. */
async function syncShell(want: boolean): Promise<boolean> {
  const m = await plugin('KeepAwake');
  if (!m) return false;
  try {
    if (want !== shellHolds) {
      if (want) await m.KeepAwake.keepAwake();
      else await m.KeepAwake.allowSleep();
      shellHolds = want;
    }
    return true;
  } catch {
    return false;
  }
}

async function acquire(): Promise<void> {
  if (sentinel || !('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
  try {
    sentinel = await navigator.wakeLock.request('screen');
    sentinel.addEventListener('release', () => (sentinel = null));
  } catch {
    sentinel = null;
  }
}

function release(): void {
  void sentinel?.release();
  sentinel = null;
}

if (typeof document !== 'undefined') {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && setting && listening && !sentinel) apply();
  });
}
