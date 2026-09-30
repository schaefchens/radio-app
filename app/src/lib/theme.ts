import { useSyncExternalStore } from 'react';
import { useSettings } from '@/store/settings';
import lightTop from '@/assets/theme/light-top.webp';
import lightBottom from '@/assets/theme/light-bottom.webp';
import lightBottomMobile from '@/assets/theme/light-bottom-mobile.webp';
import darkTop from '@/assets/theme/dark-top.webp';
import darkBottom from '@/assets/theme/dark-bottom.webp';
import darkBottomMobile from '@/assets/theme/dark-bottom-mobile.webp';

/**
 * The station's looks. Each theme is one entry here plus one block of tokens
 * in styles/tokens.css (selected by <html data-theme>); the page texture is
 * part of the tokens, the scenery above and below the page is here.
 */
export const THEMES = ['light', 'dark'] as const;
export type ThemeId = (typeof THEMES)[number];

export interface Theme {
  /** i18n key of the theme's name ("Kids Ark", "Storm Ark"). */
  label: string;
  art: { top: string; bottom: string; bottomMobile: string };
  /** The browser bar: the page's base color. */
  color: string;
}

export const THEME: Record<ThemeId, Theme> = {
  light: { label: 'theme.light', art: { top: lightTop, bottom: lightBottom, bottomMobile: lightBottomMobile }, color: '#f6fbff' },
  dark: { label: 'theme.dark', art: { top: darkTop, bottom: darkBottom, bottomMobile: darkBottomMobile }, color: '#03234a' },
};

export function isThemeId(v: unknown): v is ThemeId {
  return THEMES.includes(v as ThemeId);
}

/** No choice yet (null) follows the device. */
export function resolveTheme(setting: ThemeId | null, prefersDark: boolean): ThemeId {
  return setting ?? (prefersDark ? 'dark' : 'light');
}

const DARK_QUERY = '(prefers-color-scheme: dark)';

function prefersDark(): boolean {
  return typeof matchMedia === 'function' && matchMedia(DARK_QUERY).matches;
}

function subscribeScheme(onChange: () => void): () => void {
  if (typeof matchMedia !== 'function') return () => {};
  const query = matchMedia(DARK_QUERY);
  query.addEventListener('change', onChange);
  return () => query.removeEventListener('change', onChange);
}

/** The theme on screen, for components that pick art. */
export function useTheme(): ThemeId {
  const setting = useSettings((s) => s.theme);
  const dark = useSyncExternalStore(subscribeScheme, prefersDark, () => false);
  return resolveTheme(setting, dark);
}

/**
 * Keeps <html data-theme> and the browser bar in step with the setting and,
 * while there is no choice, with the device. Runs before the first render;
 * the CSP allows no inline script in index.html to do it earlier, so the
 * tokens also follow prefers-color-scheme while data-theme is missing.
 */
export function applyTheme(): void {
  const apply = () => {
    const id = resolveTheme(useSettings.getState().theme, prefersDark());
    const root = document.documentElement;
    if (root.dataset.theme !== id) root.dataset.theme = id;
    // index.html carries one tag per scheme for the first paint; from here
    // on one tag, without media, follows the theme actually shown.
    const metas = [...document.querySelectorAll<HTMLMetaElement>('meta[name="theme-color"]')];
    const [meta, ...extra] = metas;
    extra.forEach((m) => m.remove());
    if (meta) {
      meta.removeAttribute('media');
      meta.content = THEME[id].color;
    }
  };
  apply();
  useSettings.subscribe((s, prev) => {
    if (s.theme !== prev.theme) apply();
  });
  subscribeScheme(apply);
}
