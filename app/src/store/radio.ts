import { create } from 'zustand';
import type { DayFile } from '@arche/shared';
import { initialState, type EngineState } from '@/lib/engine';

interface RadioState {
  engine: EngineState;
  today: DayFile | null;
  setEngine: (s: EngineState) => void;
  setToday: (d: DayFile | null) => void;
}

export const useRadio = create<RadioState>((set) => ({
  engine: initialState(),
  today: null,
  setEngine: (engine) => set({ engine }),
  setToday: (today) => set({ today }),
}));
