import { create } from 'zustand';

/**
 * Where the stage is on screen right now. The YouTube player is one fixed
 * layer that floats over the current slot (moving an iframe in the DOM would
 * reload it and stop the music); these values say whether it may be shown.
 */
interface StageState {
  slot: HTMLElement | null;
  /** At least half of the slot is in the viewport. */
  inView: boolean;
  /** Open bottom sheets / dialogs that would cover the player. */
  overlays: number;
  setSlot: (el: HTMLElement | null) => void;
  setInView: (v: boolean) => void;
  pushOverlay: () => void;
  popOverlay: () => void;
}

export const useStage = create<StageState>((set) => ({
  slot: null,
  inView: false,
  overlays: 0,
  setSlot: (slot) => set((s) => (s.slot === slot ? s : { slot, inView: slot ? s.inView : false })),
  setInView: (inView) => set({ inView }),
  pushOverlay: () => set((s) => ({ overlays: s.overlays + 1 })),
  popOverlay: () => set((s) => ({ overlays: Math.max(0, s.overlays - 1) })),
}));

/** The player may be seen (and so may play): a slot, in view, nothing on top. */
export const stageAvailable = (s: StageState): boolean => s.slot !== null && s.inView && s.overlays === 0;
