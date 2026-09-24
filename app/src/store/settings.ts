import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { Lang } from '@arche/shared';

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
  setLang: (lang: Lang) => void;
  setChannel: (channel: string) => void;
  setVolume: (v: number) => void;
  setKeepAwake: (on: boolean) => void;
  setConsent: (on: boolean) => void;
}

export const useSettings = create<SettingsState>()(
  persist(
    (set) => ({
      lang: detectLang(),
      channel: null,
      volume: 1,
      keepAwake: false,
      consent: false,
      setLang: (lang) => set({ lang }),
      setChannel: (channel) => set({ channel }),
      setVolume: (volume) => set({ volume: Math.max(0, Math.min(1, volume)) }),
      setKeepAwake: (keepAwake) => set({ keepAwake }),
      setConsent: (consent) => set({ consent }),
    }),
    { name: 'arche.settings', version: 1 },
  ),
);
