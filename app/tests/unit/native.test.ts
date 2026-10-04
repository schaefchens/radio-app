import { afterEach, describe, expect, it, vi } from 'vitest';
import { barStyle, detectPlatform, pluginIn, shellTurns } from '@/lib/native';

/** What a store app's shell injects before any script of ours: its bridge and its plugin list. */
const HEADERS = [
  { name: 'App', methods: [{ name: 'addListener' }, { name: 'minimizeApp' }] },
  { name: 'Share', methods: [{ name: 'share' }] },
];
const ANDROID = { androidBridge: {}, Capacitor: { PluginHeaders: HEADERS } };
const IOS = { webkit: { messageHandlers: { bridge: {} } }, Capacitor: { PluginHeaders: HEADERS } };

afterEach(() => {
  vi.unstubAllGlobals();
  vi.doUnmock('@capacitor/share');
  vi.resetModules();
});

describe('which shell the page runs in', () => {
  it.each([
    ['a browser', {}, 'web'],
    ['the Android app', ANDROID, 'android'],
    ['the iOS app', IOS, 'ios'],
    // A plugin chunk loaded on the website sets window.Capacitor too.
    ['the website after @capacitor/core loaded', { Capacitor: { getPlatform: () => 'web' } }, 'web'],
    // Another app's WKWebView may name a message handler "bridge".
    ['an in-app browser with a handler of the same name', { webkit: { messageHandlers: { bridge: {} } } }, 'web'],
  ])('%s → %s', (_name, win, expected) => {
    expect(detectPlatform(win)).toBe(expected);
  });
});

describe('what an installed shell offers', () => {
  it('a plugin and its methods', () => {
    expect(pluginIn(HEADERS, 'Share')).toBe(true);
    expect(pluginIn(HEADERS, 'Share', 'share')).toBe(true);
  });

  // The website is deployed ahead of every installed shell.
  it('nothing an older shell lacks', () => {
    expect(pluginIn(HEADERS, 'LocalNotifications')).toBe(false);
    expect(pluginIn(HEADERS, 'Share', 'canShare')).toBe(false);
  });
});

describe('agrees with @capacitor/core', () => {
  // A Capacitor upgrade that detected shells differently would switch every
  // native feature off (or on) without a word.
  it.each([
    ['android', ANDROID],
    ['ios', IOS],
  ])('%s', async (_name, shell) => {
    for (const [key, value] of Object.entries(shell)) vi.stubGlobal(key, value);
    const { Capacitor } = await import('@capacitor/core');
    expect(Capacitor.getPlatform()).toBe(detectPlatform(globalThis));
    for (const name of ['App', 'Share', 'LocalNotifications']) {
      expect(Capacitor.isPluginAvailable(name)).toBe(pluginIn(HEADERS, name));
    }
  });
});

describe('a plugin whose code cannot load', () => {
  it('is null, never an error', async () => {
    vi.stubGlobal('window', ANDROID);
    vi.doMock('@capacitor/share', () => {
      throw new Error('a deploy replaced the chunk');
    });
    const native = await import('@/lib/native');
    expect(native.platform()).toBe('android');
    expect(native.hasPlugin('Share')).toBe(true);
    expect(await native.plugin('Share')).toBeNull();
    // Not in this shell at all.
    expect(await native.plugin('LocalNotifications')).toBeNull();
  });
});

describe('the status bar icons', () => {
  it.each([
    ['dark', 'ios', 0, 'DARK'],
    ['light', 'ios', 0, 'LIGHT'],
    ['dark', 'android', 24, 'DARK'],
    ['light', 'android', 24, 'LIGHT'],
    // Android pads the page itself (WebView < 140) and paints the bars in the device's scheme.
    ['light', 'android', 0, 'DEFAULT'],
    ['dark', 'android', Number.NaN, 'DEFAULT'],
  ] as const)('%s theme on %s, inset %s → %s', (theme, p, inset, expected) => {
    expect(barStyle(theme, p, inset)).toBe(expected);
  });
});

// The big stage's button only where the shell turns sideways with the phone.
describe('a shell that turns sideways', () => {
  it.each([
    ['android', '2', true],
    ['android', '17', true],
    // The first closed test stayed upright; a shell that cannot tell its build too.
    ['android', '1', false],
    ['android', undefined, false],
    ['android', '', false],
    // The iOS app stays upright (Info.plist), whatever its build.
    ['ios', '5', false],
    ['web', '5', false],
  ] as const)('%s, build %s → %s', (p, build, expected) => {
    expect(shellTurns(p, build)).toBe(expected);
  });
});
