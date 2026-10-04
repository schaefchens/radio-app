import type { Lang, WallEntry } from '@arche/shared';

/** In the prayer time the stage shows a few requests at a time, this long each page. */
export const WALL_PAGE_MS = 15_000;

/**
 * The wall with the request on air right now first (a request read out lists
 * it by wall id): "On air now". The same list, not a copy, when it is not on
 * the wall — it may have been taken down after its reading was published.
 */
export function prayingFirst(wall: WallEntry[], praying: readonly string[]): WallEntry[] {
  if (praying.length === 0) return wall;
  const now = wall.filter((e) => praying.includes(e.id));
  return now.length === 0 ? wall : [...now, ...wall.filter((e) => !praying.includes(e.id))];
}

/**
 * The requests the stage shows in the prayer time at server time `t`: a page
 * of `size`, turned by the clock, so every listener sees the same ones —
 * what there is to pray for, not one request picked for them.
 */
export function wallPage(wall: WallEntry[], t: number, size: number): WallEntry[] {
  if (wall.length <= size) return wall;
  const pages = Math.ceil(wall.length / size);
  const page = Math.floor(t / WALL_PAGE_MS) % pages;
  return wall.slice(page * size, page * size + size);
}

/** A request's text in the listener's language: the station's own (Open Doors') comes translated. */
export function entryText(entry: WallEntry, lang: Lang): string {
  return entry.texts?.[lang] ?? entry.text;
}
