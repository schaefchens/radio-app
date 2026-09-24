import type { DayPlanBlock } from './modApi';

/** Pure helpers of the plan editor (no i18n, no DOM: unit-tested in node). */

/** "07:30" ↔ 450 */
export function minToHm(min: number): string {
  if (min >= 1440) return '24:00';
  return `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
}

export function hmToMin(hm: string): number | null {
  const m = /^(\d{1,2}):(\d{2})$/.exec(hm.trim());
  if (!m) return null;
  const h = Number(m[1]);
  const mi = Number(m[2]);
  if (h === 24 && mi === 0) return 1440;
  if (h > 23 || mi > 59) return null;
  return h * 60 + mi;
}

/** Index of the first block that overlaps its predecessor (after sorting), or -1. */
export function findOverlap(blocks: DayPlanBlock[]): number {
  const sorted = [...blocks].sort((a, b) => a.start_min - b.start_min);
  for (let i = 1; i < sorted.length; i++) {
    if (sorted[i]!.start_min < sorted[i - 1]!.end_min) return i;
  }
  return -1;
}
