import type { WallEntry } from '@arche/shared';

/** In silent prayer the stage shows one request at a time, this long each. */
export const SILENT_ROTATE_MS = 12_000;

/**
 * The wall with the requests the host is praying for right now first (a
 * prayer hour's moment lists them by wall id): "Praying now". The same list,
 * not a copy, when none of them is on the wall — it may have been taken down
 * after the moment was published.
 */
export function prayingFirst(wall: WallEntry[], praying: readonly string[]): WallEntry[] {
  if (praying.length === 0) return wall;
  const now = wall.filter((e) => praying.includes(e.id));
  return now.length === 0 ? wall : [...now, ...wall.filter((e) => !praying.includes(e.id))];
}

/**
 * The request the stage shows in silent prayer at server time `t`: picked by
 * the clock, so every listener prays for the same one at the same moment.
 */
export function silentEntry(wall: WallEntry[], t: number): WallEntry | null {
  return wall.length === 0 ? null : wall[Math.floor(t / SILENT_ROTATE_MS) % wall.length]!;
}
