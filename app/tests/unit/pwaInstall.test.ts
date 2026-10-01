import { describe, expect, it } from 'vitest';
import { installPlatform, urlWithoutInstallParam } from '@/lib/pwaInstall';

/** The two rules of `?install=1` that are pure functions (bible-assistant's table). */

describe('stripping ?install=1', () => {
  // Surgical: a blanket replaceState(pathname) would eat the rest of a link.
  it.each([
    ['?install=1', '', '/', '/'],
    ['?other=x&install=1', '', '/', '/?other=x'],
    ['?install=1&other=x', '', '/schedule', '/schedule?other=x'],
    ['?other=x', '', '/chat', '/chat?other=x'],
    ['', '', '/about', '/about'],
    ['?install=1', '#datenschutz', '/about', '/about#datenschutz'],
  ])('%s + %s at %s → %s', (search, hash, pathname, expected) => {
    expect(urlWithoutInstallParam(search, hash, pathname)).toBe(expected);
  });
});

describe('which written steps to show', () => {
  const IPHONE =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1';
  /** iPadOS reports a desktop Mac; only the touch screen separates it. */
  const MAC_SAFARI =
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15';
  const MAC_CHROME =
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

  it.each([
    ['iPhone', IPHONE, 0, 'ios'],
    ['Firefox on iPhone (Share works there too)', IPHONE.replace('Version/26.0', 'FxiOS/140.0'), 0, 'ios'],
    ['iPadOS, desktop UA + touch', MAC_SAFARI, 5, 'ios'],
    ['a real Mac, same UA, no touch', MAC_SAFARI, 0, 'safari'],
    ['Firefox on Android', 'Mozilla/5.0 (Android 14; Mobile; rv:140.0) Gecko/140.0 Firefox/140.0', 5, 'firefox'],
    // Every Chromium browser carries "Safari" in its UA.
    ['Chrome on macOS', MAC_CHROME, 0, 'other'],
    ['Edge on Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', 0, 'other'],
    ['Chrome on Android', 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', 5, 'other'],
    ['nothing at all', '', 0, 'other'],
  ])('%s → %s', (_name, ua, touchPoints, expected) => {
    expect(installPlatform(ua, touchPoints)).toBe(expected);
  });
});
