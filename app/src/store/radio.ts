import { create } from 'zustand';
import type { DayFile } from '@arche/shared';
import { initialState, type EngineState } from '@/lib/engine';

interface RadioState {
  engine: EngineState;
  today: DayFile | null;
  /** The store app went to the background and the radio left (lib/radio.ts). */
  leftInBackground: boolean;
  setEngine: (s: EngineState) => void;
  setToday: (d: DayFile | null) => void;
  setLeftInBackground: (v: boolean) => void;
}

export const useRadio = create<RadioState>((set) => ({
  engine: initialState(),
  today: null,
  leftInBackground: false,
  setEngine: (engine) => set({ engine }),
  setToday: (today) => set({ today }),
  setLeftInBackground: (leftInBackground) => set({ leftInBackground }),
}));
