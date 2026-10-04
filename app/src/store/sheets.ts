import { create } from 'zustand';

/** `pray`: a listener's own prayer in a prayer hour's prayer time (the stage's Pray button). */
export type Sheet = 'song' | 'preaching' | 'record' | 'prayer' | 'pray';
export type RecordKind = 'story' | 'prayer';

interface SheetsState {
  open: Sheet | null;
  recordKind: RecordKind;
  show: (sheet: Sheet, kind?: RecordKind) => void;
  close: () => void;
}

/**
 * Which submission sheet is open. A store, not page state: the tiles render
 * in the phone dock, in the scene below the page and next to the prayer
 * wall, but each sheet is mounted once (AppShell, on Home) — a closed sheet stays in
 * the DOM, and two copies would share their form ids. Only Home has tiles,
 * so only Home mounts the sheets.
 */
export const useSheets = create<SheetsState>()((set) => ({
  open: null,
  recordKind: 'story',
  show: (sheet, kind) => set((s) => ({ open: sheet, recordKind: kind ?? s.recordKind })),
  close: () => set({ open: null }),
}));
