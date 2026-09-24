/**
 * "Keep screen on": phones stop YouTube embeds when the screen locks, so for
 * listening the screen has to stay on. Re-acquired after returning to the tab,
 * because the browser drops the lock whenever the page is hidden.
 */
let sentinel: WakeLockSentinel | null = null;
let wanted = false;

export function wakeLockSupported(): boolean {
  return 'wakeLock' in navigator;
}

async function acquire(): Promise<void> {
  if (!wanted || !wakeLockSupported() || document.visibilityState !== 'visible') return;
  try {
    sentinel = await navigator.wakeLock.request('screen');
    sentinel.addEventListener('release', () => (sentinel = null));
  } catch {
    sentinel = null;
  }
}

export function setKeepAwake(on: boolean): void {
  wanted = on;
  if (on) void acquire();
  else {
    void sentinel?.release();
    sentinel = null;
  }
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && wanted && !sentinel) void acquire();
});
