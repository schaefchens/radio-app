import { create } from 'zustand';
import type { DayFile } from '@arche/shared';
import { initialState, type EngineState } from '@/lib/engine';

interface RadioState {
  engine: EngineState;
  today: DayFile | null;
  /** Voices the listener already reacted to (they leave the main screen). */
  answered: Record<string, true>;
  setEngine: (s: EngineState) => void;
  setToday: (d: DayFile | null) => void;
  answer: (voiceId: string) => void;
}

export const useRadio = create<RadioState>((set) => ({
  engine: initialState(),
  today: null,
  answered: {},
  setEngine: (engine) => set({ engine }),
  setToday: (today) => set({ today }),
  answer: (id) => set((s) => ({ answered: { ...s.answered, [id]: true } })),
}));
