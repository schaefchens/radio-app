import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { Lang } from '@arche/shared';
import type { ThemeId } from '@/lib/theme';
import { isNative } from '@/lib/native';

/**
 * Per-device preferences. Language defaults to German for German browsers and
 * English for everyone else (the concept's en/de rule); the station itself is
 * the same for everybody.
 */
export function detectLang(): Lang {
  return (navigator.language ?? '').toLowerCase().startsWith('de') ? 'de' : 'en';
}

interface SettingsState {
  lang: Lang;
  channel: string | null;
  volume: number;
  keepAwake: boolean;
  /** The listener agreed to load YouTube (Google) — asked at "tap to join". */
  consent: boolean;
  /** Kids Ark or Storm Ark; null follows the device until the listener picks. */
  theme: ThemeId | null;
  /** The welcome dialog was seen (closed once, however). */
  welcomed: boolean;
  /** The version of the community rules accepted on this device (content/rules.ts); 0 = none. */
  rules: number;
  setLang: (lang: Lang) => void;
  setChannel: (channel: string) => void;
  setVolume: (v: number) => void;
  setKeepAwake: (on: boolean) => void;
  setConsent: (on: boolean) => void;
  setTheme: (theme: ThemeId | null) => void;
  setWelcomed: () => void;
  acceptRules: (version: number) => void;
}

export const useSettings = create<SettingsState>()(
  persist(
    (set) => ({
      lang: detectLang(),
      channel: null,
      volume: 1,
      // On in the store apps: the auto-lock would send the app to the
      // background, where the radio leaves (lib/radio.ts).
      keepAwake: isNative(),
      consent: false,
      theme: null,
      welcomed: false,
      rules: 0,
      setLang: (lang) => set({ lang }),
      setChannel: (channel) => set({ channel }),
      setVolume: (volume) => set({ volume: Math.max(0, Math.min(1, volume)) }),
      setKeepAwake: (keepAwake) => set({ keepAwake }),
      setConsent: (consent) => set({ consent }),
      setTheme: (theme) => set({ theme }),
      setWelcomed: () => set({ welcomed: true }),
      acceptRules: (rules) => set({ rules }),
    }),
    { name: 'arche.settings', version: 1 },
  ),
);
