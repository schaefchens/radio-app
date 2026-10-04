import type { ChannelInfo, DayFile, DayProgram } from '@arche/shared';
import { addDays, stationDate } from './format';

/**
 * Program reminders (store apps only): which local notifications the phone
 * should hold, decided from the day files — the facts — the listener's
 * reminders — the wishes — and what the phone holds now. Pure, so every rule
 * below has a test (tests/unit/reminderPlan.test.ts); lib/reminderRunner.ts
 * carries the plan out.
 */

/** Before the program begins. Inexact Android alarms can be late: the text names the start. */
export const LEAD_MS = 5 * 60_000;
/** iOS keeps only the soonest 64 pending notifications and says nothing about the rest. */
export const MAX_PENDING = 60;
/** A time in the past fires at once on both platforms; leave a minute's margin. */
export const MIN_AHEAD_MS = 60_000;
/** Android shows missed ones after a reboot; one this late is no reminder any more. */
export const OVERDUE_MS = 15 * 60_000;
/** Day files are rewritten every 5 minutes; an older copy (a cache, a stalled generator) is not a fact. */
export const STALE_DAY_MS = 60 * 60_000;
/** The days the site publishes (Publisher::publishDays): yesterday to six days ahead. */
export const DAY_OFFSETS = [-1, 0, 1, 2, 3, 4, 5, 6] as const;

/** "Remind me of this program on this channel": every airing the day files show. */
export interface ReminderIntent {
  ch: string;
  p: string;
  since: number;
}

/** What each of our notifications carries, to recognise it later. */
export interface ReminderExtra {
  arche: 1;
  ch: string;
  p: string;
  /** The program's start, server time. */
  start: number;
  /** When it fires, server time. */
  at: number;
  /** Everything that, changed, needs the notification written again. */
  sig: string;
}

export interface PendingReminder {
  id: number;
  title: string;
  body: string;
  /** null: not one of ours (never touched). */
  extra: ReminderExtra | null;
}

export interface Wanted {
  id: number;
  at: number;
  title: string;
  body: string;
  extra: ReminderExtra;
}

export interface PlanInput {
  intents: readonly ReminderIntent[];
  /** null: channels.json could not be read. */
  channels: readonly ChannelInfo[] | null;
  /** channel → station date → its day file (null or missing: unknown). */
  days: Readonly<Record<string, Readonly<Record<string, DayFile | null | undefined>>>>;
  pending: readonly PendingReminder[];
  /** Server time. */
  now: number;
  /** Android: whether reminders fire on time (a change writes them again). */
  exact: boolean;
  /** At start: write every reminder again (an Android force-stop drops the alarms but not the list). */
  refreshAll: boolean;
  /** Title and text of a reminder, in the listener's language. */
  describe: (channel: ChannelInfo, program: DayProgram | undefined, slug: string, start: number) => { title: string; body: string };
}

export interface Plan {
  cancel: number[];
  schedule: Wanted[];
  /** A day file was too old to trust: try again soon. */
  stale: boolean;
}

interface Start {
  p: string;
  start: number;
  program: DayProgram | undefined;
}

interface Facts {
  /** Station dates whose day file is known. */
  known: Set<string>;
  starts: Start[];
  /** Block starts that may only continue a run from an unknown previous day. */
  unsure: Set<number>;
}

/**
 * Where programs begin. A run across local midnight is split into two blocks
 * in two files (PlanResolver works day by day); its second half is no start.
 */
export function dayFacts(dates: readonly string[], days: Readonly<Record<string, DayFile | null | undefined>>): Facts {
  const known = new Set<string>();
  const starts: Start[] = [];
  const unsure = new Set<number>();
  for (const date of dates) {
    const day = days[date];
    if (!day) continue;
    known.add(date);
    // The calendar day before, not the previous one we happen to have.
    const previousDay = days[addDays(date, -1)];
    day.blocks.forEach((b, j) => {
      const before = j > 0 ? day.blocks[j - 1] : previousDay?.blocks[previousDay.blocks.length - 1];
      if (j === 0 && !previousDay) {
        unsure.add(b.start);
        return;
      }
      if (before && before.p === b.p && before.end === b.start) return;
      starts.push({ p: b.p, start: b.start, program: day.programs[b.p] });
    });
  }
  return { known, starts, unsure };
}

/** A stable positive 32-bit id per reminder (Android's ids are ints): FNV-1a. */
export function reminderId(key: string): number {
  let h = 0x811c9dc5;
  for (let i = 0; i < key.length; i++) {
    h ^= key.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return ((h >>> 0) % 0x7ffffffe) + 1;
}

function hash(text: string): string {
  return reminderId(text).toString(36);
}

/** Ours, or null. */
export function parseExtra(extra: unknown): ReminderExtra | null {
  const e = extra as Partial<ReminderExtra> | null | undefined;
  if (!e || e.arche !== 1 || typeof e.ch !== 'string' || typeof e.p !== 'string') return null;
  if (typeof e.start !== 'number' || typeof e.at !== 'number' || typeof e.sig !== 'string') return null;
  return { arche: 1, ch: e.ch, p: e.p, start: e.start, at: e.at, sig: e.sig };
}

const keyOf = (ch: string, p: string, start: number): string => `${ch}|${p}|${start}`;

export function planReminders(x: PlanInput): Plan {
  const wished = new Set(x.intents.map((i) => `${i.ch}|${i.p}`));
  const channels = x.channels ? new Map(x.channels.map((c) => [c.id, c])) : null;
  let stale = false;

  // What the facts say should be held, by key.
  const confirmed = new Map<string, Wanted>();
  const facts = new Map<string, Facts>();
  if (channels) {
    for (const ch of new Set(x.intents.map((i) => i.ch))) {
      const channel = channels.get(ch);
      if (!channel) continue;
      const files = x.days[ch] ?? {};
      const fresh: Record<string, DayFile | null> = {};
      for (const [date, file] of Object.entries(files)) {
        const ok = !!file && file.channel === ch && x.now - file.gen <= STALE_DAY_MS;
        if (file && !ok) stale = true;
        fresh[date] = ok ? file! : null;
      }
      const f = dayFacts(Object.keys(files), fresh);
      facts.set(ch, f);
      for (const s of f.starts) {
        if (!wished.has(`${ch}|${s.p}`)) continue;
        const at = s.start - LEAD_MS;
        const { title, body } = x.describe(channel, s.program, s.p, s.start);
        const key = keyOf(ch, s.p, s.start);
        const sig = hash(`${key}|${at}|${title}|${body}|${x.exact ? 1 : 0}`);
        confirmed.set(key, { id: 0, at, title, body, extra: { arche: 1, ch, p: s.p, start: s.start, at, sig } });
      }
    }
  }

  const cancel: number[] = [];
  const held: Wanted[] = []; // left as they are (due, or nothing to say about them)
  const reused: { wanted: Wanted; sig: string }[] = [];
  // Every pending id is taken, ours or not: a new one must not replace it.
  const claimed = new Set<number>(x.pending.map((n) => n.id));
  const matched = new Set<string>();

  for (const n of x.pending) {
    const e = n.extra;
    if (!e) continue; // not ours
    const keep = (): void => {
      held.push({ id: n.id, at: e.at, title: n.title, body: n.body, extra: e });
    };
    if (!wished.has(`${e.ch}|${e.p}`)) cancel.push(n.id);
    else if (e.at < x.now - OVERDUE_MS) cancel.push(n.id);
    else if (e.at <= x.now + MIN_AHEAD_MS) keep(); // due: it may be firing right now
    else if (!channels) keep();
    else if (!channels.has(e.ch)) cancel.push(n.id);
    else {
      const key = keyOf(e.ch, e.p, e.start);
      const want = confirmed.get(key);
      const f = facts.get(e.ch);
      const date = stationDate(e.start, channels.get(e.ch)!.tz);
      if (want && !matched.has(key)) {
        matched.add(key);
        reused.push({ wanted: { ...want, id: n.id }, sig: e.sig });
      } else if (!f || !f.known.has(date) || f.unsure.has(e.start)) keep();
      else cancel.push(n.id); // moved, or taken off the plan
    }
  }

  // New ones get ids by key, stepping past any taken.
  const drafts = [...confirmed.entries()]
    // Strictly later than a due one is kept (above): never the same airing twice.
    .filter(([key, w]) => !matched.has(key) && w.at > x.now + MIN_AHEAD_MS)
    .sort(([ka, a], [kb, b]) => a.at - b.at || (ka < kb ? -1 : 1))
    .map(([key, w]) => {
      let id = reminderId(key);
      while (claimed.has(id)) id = (id % 0x7ffffffe) + 1;
      claimed.add(id);
      return { ...w, id };
    });

  // The soonest MAX_PENDING; anything later waits for a later run.
  type Entry = { kind: 'held' | 'reused' | 'draft'; w: Wanted; sig?: string };
  const all: Entry[] = [
    ...held.map((w) => ({ kind: 'held' as const, w })),
    ...reused.map((r) => ({ kind: 'reused' as const, w: r.wanted, sig: r.sig })),
    ...drafts.map((w) => ({ kind: 'draft' as const, w })),
  ].sort((a, b) => a.w.at - b.w.at);
  const kept = all.slice(0, MAX_PENDING);
  for (const e of all.slice(MAX_PENDING)) if (e.kind !== 'draft') cancel.push(e.w.id);

  const schedule: Wanted[] = [];
  for (const e of kept) {
    if (e.kind === 'draft') schedule.push(e.w);
    else if (e.kind === 'reused' && (x.refreshAll || e.sig !== e.w.extra.sig)) schedule.push(e.w);
    else if (e.kind === 'held' && x.refreshAll && e.w.at > x.now + MIN_AHEAD_MS) schedule.push(e.w);
  }
  const scheduling = new Set(schedule.map((w) => w.id));
  return { cancel: cancel.filter((id) => !scheduling.has(id)), schedule, stale };
}
