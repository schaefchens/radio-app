import { create } from 'zustand';
import type { Tile } from '@/lib/intake';

/** `pray`: a listener's own prayer in a prayer hour's prayer time (the stage's
 *  Pray button). `locked`: why a locked tile takes nothing now, and when again. */
export type Sheet = 'song' | 'video' | 'record' | 'prayer' | 'pray' | 'locked';
export type RecordKind = 'story' | 'prayer';

interface SheetsState {
  open: Sheet | null;
  recordKind: RecordKind;
  /** The tile the `locked` sheet explains. */
  locked: Tile;
  show: (sheet: Sheet, kind?: RecordKind) => void;
  /** A tile's own sheet: the story tile records a story. */
  openTile: (tile: Tile) => void;
  explain: (tile: Tile) => void;
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
  locked: 'song',
  show: (sheet, kind) => set((s) => ({ open: sheet, recordKind: kind ?? s.recordKind })),
  openTile: (tile) => set(tile === 'story' ? { open: 'record', recordKind: 'story' } : { open: tile }),
  explain: (tile) => set({ open: 'locked', locked: tile }),
  close: () => set({ open: null }),
}));
