import { isNative } from './native';

/** iOS (incl. iPadOS pretending to be a Mac): no element volume, no background audio. */
export function isIOS(): boolean {
  const ua = navigator.userAgent;
  return /iPad|iPhone|iPod/.test(ua) || (ua.includes('Macintosh') && navigator.maxTouchPoints > 1);
}

/** The installed PWA, or a store app (whose WebView reports display-mode browser). */
export function isStandalone(): boolean {
  return (
    isNative() ||
    window.matchMedia?.('(display-mode: standalone)').matches ||
    (navigator as unknown as { standalone?: boolean }).standalone === true
  );
}

/**
 * The installed app behaves like an app (<html data-standalone>, index.css)
 * and does not zoom. iOS ignores `user-scalable=no`, so a pinch zoomed the
 * whole page — and with no browser bar to zoom back out with, it stayed
 * zoomed and slid around under the finger. WebKit reports the pinch as
 * gesture events; in the browser pinch zoom stays (some listeners read that
 * way).
 */
export function markInstalled(): void {
  if (!isStandalone()) return;
  document.documentElement.dataset.standalone = '';
  for (const type of ['gesturestart', 'gesturechange', 'gestureend']) {
    document.addEventListener(type, (e) => e.preventDefault(), { passive: false });
  }
}
