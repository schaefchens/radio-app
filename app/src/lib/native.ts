import { handleBack } from './backStack';

/**
 * The store apps (CLAUDE.md, "Native apps"): Capacitor shells around the live
 * site. This module alone decides whether the page runs in one, and it is the
 * only way to a plugin.
 *
 * The site is deployed whenever we deploy; an installed shell changes when its
 * owner updates it. So the page meets shells without a plugin or a method it
 * knows (an app installed before they existed), and shells without any bridge
 * (an old Android WebView, where Capacitor injects an inline script our CSP
 * blocks). Every native path therefore asks hasPlugin() first, expects the
 * call to fail anyway, and keeps the web behaviour as its fallback. Plugin
 * code is only loaded through dynamic import(), so the website never runs it.
 */

export type Platform = 'ios' | 'android' | 'web';

interface PluginHeader {
  name: string;
  methods: { name: string }[];
}

interface ShellWindow {
  androidBridge?: unknown;
  webkit?: { messageHandlers?: { bridge?: unknown } };
  Capacitor?: { PluginHeaders?: PluginHeader[] };
}

/**
 * The bridge @capacitor/core looks for (getPlatformId), plus the plugin list
 * only our shell injects: an in-app browser with a message handler of the
 * same name is not one of our apps.
 */
export function detectPlatform(win: unknown): Platform {
  const w = win as ShellWindow | undefined;
  if (!w || !Array.isArray(w.Capacitor?.PluginHeaders)) return 'web';
  if (w.androidBridge) return 'android';
  if (w.webkit?.messageHandlers?.bridge) return 'ios';
  return 'web';
}

// Decided once: the shell injects its bridge at document start, before any
// script of ours runs.
const PLATFORM: Platform = typeof window === 'undefined' ? 'web' : detectPlatform(window);

export const platform = (): Platform => PLATFORM;
export const isNative = (): boolean => PLATFORM !== 'web';

/** Whether a shell's plugin list has the plugin (and, if asked, the method). */
export function pluginIn(headers: readonly PluginHeader[], name: string, method?: string): boolean {
  const header = headers.find((h) => h.name === name);
  return !!header && (!method || header.methods.some((m) => m.name === method));
}

export function hasPlugin(name: PluginName, method?: string): boolean {
  if (!isNative()) return false;
  return pluginIn((window as ShellWindow).Capacitor?.PluginHeaders ?? [], name, method);
}

/** Every plugin the page uses, by the name the shell registers it under. */
const LOADERS = {
  App: () => import('@capacitor/app'),
  Haptics: () => import('@capacitor/haptics'),
  KeepAwake: () => import('@capacitor-community/keep-awake'),
  LocalNotifications: () => import('@capacitor/local-notifications'),
  Share: () => import('@capacitor/share'),
  SplashScreen: () => import('@capacitor/splash-screen'),
  // Capacitor 8 ships SystemBars in core.
  SystemBars: () => import('@capacitor/core'),
};

export type PluginName = keyof typeof LOADERS;
type PluginModule<N extends PluginName> = Awaited<ReturnType<(typeof LOADERS)[N]>>;

/**
 * The plugin's module, in a shell that has the plugin; null on the web, in an
 * older shell, or when its chunk cannot load. Never hand out (or await, or
 * return from an async function) the plugin object itself: a Capacitor plugin
 * is a Proxy that answers every property, `then` included, so a promise
 * resolved with it never settles.
 */
export async function plugin<N extends PluginName>(name: N): Promise<PluginModule<N> | null> {
  if (!hasPlugin(name)) return null;
  try {
    return (await LOADERS[name]()) as PluginModule<N>;
  } catch {
    return null;
  }
}

// --- The app going to the background and coming back -----------------------

type Listener = () => void;
const backgrounded = new Set<Listener>();
const foregrounded = new Set<Listener>();
let away = false;

function goAway(): void {
  if (away) return;
  away = true;
  backgrounded.forEach((f) => f());
}

function comeBack(): void {
  if (!away) return;
  away = false;
  foregrounded.forEach((f) => f());
}

/** The app went to the background (home, lock, another app). Never fires on the web. */
export function onAppBackground(f: Listener): () => void {
  backgrounded.add(f);
  return () => backgrounded.delete(f);
}

/** The app is back in front after onAppBackground. Never fires on the web. */
export function onAppForeground(f: Listener): () => void {
  foregrounded.add(f);
  return () => foregrounded.delete(f);
}

// --- System bars --------------------------------------------------------------

export type BarStyle = 'DARK' | 'LIGHT' | 'DEFAULT';

/**
 * Which icons the status bar shows. Over the page (iOS, and Android WebView
 * 140+, which draws under the bars): light icons over Storm Ark's storm, dark
 * ones over Kids Ark's sky. Where Android cannot draw under the bars it pads
 * the page, reports a zero inset, and paints the bars itself in the device's
 * scheme (values-night): then the icons follow the device too.
 */
export function barStyle(theme: 'light' | 'dark', p: Platform, insetTop: number): BarStyle {
  if (p === 'android' && !(insetTop > 0)) return 'DEFAULT';
  return theme === 'dark' ? 'DARK' : 'LIGHT';
}

let barTheme: 'light' | 'dark' | null = null;
let barShown: BarStyle | null = null;

/** The theme on screen changed (theme.ts): the status bar follows. A no-op on the web. */
export function syncSystemBars(theme: 'light' | 'dark'): void {
  barTheme = theme;
  if (!hasPlugin('SystemBars')) return;
  const inset = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--safe-area-inset-top'));
  const style = barStyle(theme, PLATFORM, inset);
  if (style === barShown) return;
  barShown = style;
  void plugin('SystemBars')
    .then((m) => {
      if (!m) return;
      const value = style === 'DARK' ? m.SystemBarsStyle.Dark : style === 'LIGHT' ? m.SystemBarsStyle.Light : m.SystemBarsStyle.Default;
      return m.SystemBars.setStyle({ style: value });
    })
    .catch(() => {
      barShown = null;
    });
}

/** The page has rendered: the splash may go (it also goes by itself after 3 s). */
export function hideSplash(): void {
  void plugin('SplashScreen')
    .then((m) => m?.SplashScreen.hide())
    .catch(() => {});
}

/**
 * Wires the page to the shell: <html data-native>, the plugins' code, the app
 * lifecycle and Android's back button. Runs first in main.tsx; nothing on the web.
 */
export function initNative(): void {
  if (!isNative()) return;
  document.documentElement.dataset.native = PLATFORM;

  // Load every plugin's code now: imported hours later, a chunk could be one a
  // deploy has replaced, and the iOS app keeps no service-worker copy.
  for (const name of Object.keys(LOADERS) as PluginName[]) {
    if (hasPlugin(name)) void LOADERS[name]().catch(() => {});
  }

  // The hidden edge is reliable everywhere; the App plugin backs it up. Not
  // iOS's appStateChange (resign-active: pulling down Control Center must not
  // stop the radio), not Android's pause (the microphone permission dialog
  // pauses the activity).
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') goAway();
    else comeBack();
  });
  void plugin('App').then(async (m) => {
    if (!m) return;
    try {
      if (PLATFORM === 'ios') {
        await m.App.addListener('pause', goAway);
        await m.App.addListener('resume', comeBack);
      } else {
        // false at onStop, true at onResume.
        await m.App.addListener('appStateChange', ({ isActive }) => (isActive ? comeBack() : goAway()));
        // Minimized rather than closed: the radio leaves in the background
        // anyway, and the page is still there when the listener comes back.
        await m.App.addListener('backButton', () => handleBack(() => void m.App.minimizeApp().catch(() => {})));
      }
    } catch {
      /* an older shell: visibilitychange alone, and Capacitor's own back */
    }
  });

  // Android writes the insets into <html style> once it knows them, after the
  // first paint: the status bar's icons are decided again then.
  new MutationObserver(() => {
    if (barTheme) syncSystemBars(barTheme);
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['style'] });
}
