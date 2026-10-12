import {
  VIDEO_SUBMISSION_TYPES,
  type ClosedReason,
  type DayFile,
  type DayProgram,
  type SubmissionState,
  type SubmissionType,
} from '@arche/shared';
import { dayFacts } from './reminderPlan';

/**
 * When the four tiles take something, and — locked — why not and when again
 * (listeners tapped a locked tile and thought it broken). Pure: the rules
 * have their tests (tests/unit/intake.test.ts).
 */

export type Tile = 'song' | 'story' | 'prayer' | 'video';

/** What each tile sends: the story tile records a story, a testimony or a greeting; the video tile any kind of video. */
export const TILE_TYPES: Readonly<Record<Tile, readonly SubmissionType[]>> = {
  song: ['song'],
  story: ['story', 'testimony', 'greeting'],
  prayer: ['prayer'],
  video: VIDEO_SUBMISSION_TYPES,
};

/** Each tile's color (shell.css `.action.<tone>`). */
export const TILE_TONE: Readonly<Record<Tile, 'purple' | 'green' | 'blue' | 'gold'>> = {
  song: 'purple',
  story: 'green',
  prayer: 'blue',
  video: 'gold',
};

export const TILES = Object.keys(TILE_TYPES) as Tile[];

/** `off`: the program on air does not take it at all. */
export type TileState = SubmissionState | 'off';

/** A tile is open while any of its types is (the video sheet offers the ones that are). */
export function tileState(types: readonly SubmissionType[], submissions: Partial<Record<SubmissionType, SubmissionState>>): TileState {
  const states = types.map((ty) => submissions[ty]).filter((s): s is SubmissionState => !!s);
  if (states.includes('open')) return 'open';
  if (states.includes('closing')) return 'closing';
  return states.length ? 'closed' : 'off';
}

/** The tile takes something now (its last chance included). */
export function tileOpen(tile: Tile, submissions: Partial<Record<SubmissionType, SubmissionState>>): boolean {
  const state = tileState(TILE_TYPES[tile], submissions);
  return state === 'open' || state === 'closing';
}

/** Why a locked tile is locked; `unknown` when the minute file does not say (an older generator). */
export type LockedWhy = 'off' | ClosedReason | 'unknown';

export function lockedWhy(
  types: readonly SubmissionType[],
  submissions: Partial<Record<SubmissionType, SubmissionState>>,
  why: Partial<Record<SubmissionType, ClosedReason>>,
): LockedWhy {
  if (tileState(types, submissions) === 'off') return 'off';
  return types.map((ty) => why[ty]).find((r): r is ClosedReason => !!r) ?? 'unknown';
}

export interface Opening {
  start: number;
  program: DayProgram;
}

/**
 * The next program that takes one of the types: the start of its run after
 * `now` — the run on air now never counts (it closed, or never took them),
 * and a run across midnight starts once. From the station-local days given,
 * in order and with the day before the first one wanted, so a day's first
 * block is known to start a run or continue one.
 */
export function nextOpening(
  types: readonly SubmissionType[],
  dates: readonly string[],
  days: Readonly<Record<string, DayFile | null | undefined>>,
  now: number,
): Opening | null {
  for (const s of dayFacts(dates, days).starts) {
    if (s.start > now && s.program?.allowed.some((a) => types.includes(a))) return { start: s.start, program: s.program };
  }
  return null;
}

/** How many of the listener's own calendar days `ms` lies ahead of `now` (0: today). */
export function daysAhead(ms: number, now: number): number {
  const day = (t: number) => {
    const d = new Date(t);
    return Date.UTC(d.getFullYear(), d.getMonth(), d.getDate());
  };
  // In UTC: a local day with a DST change is 23 or 25 hours long.
  return Math.round((day(ms) - day(now)) / 86_400_000);
}
