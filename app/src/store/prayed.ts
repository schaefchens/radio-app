import { create } from 'zustand';
import { persist } from 'zustand/middleware';

/**
 * The prayer requests this listener prayed along with — on this device only,
 * so the wall still shows it after a reload; the server counts each device
 * once anyway. "Delete this device's data" on the About page removes it.
 */
interface PrayedState {
  /** Request id → its count when the listener tapped (their tap arrives with the next pulse). */
  at: Record<string, number>;
  add: (id: string, n: number) => void;
}

const KEEP = 200;

export const usePrayed = create<PrayedState>()(
  persist(
    (set) => ({
      at: {},
      add: (id, n) =>
        set((s) => {
          if (id in s.at) return s;
          const entries = [...Object.entries(s.at), [id, n] as const].slice(-KEEP);
          return { at: Object.fromEntries(entries) };
        }),
    }),
    { name: 'arche.prayed', version: 1 },
  ),
);

/** The count to show: the listener's own tap included until the server's count has it. */
export function withMine(n: number, tappedAt: number | undefined): number {
  return tappedAt !== undefined && n <= tappedAt ? n + 1 : n;
}
