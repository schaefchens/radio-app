import { describe, expect, it } from 'vitest';
import type { ChannelInfo, DayFile, DayProgram } from '@arche/shared';
import { addDays } from '@/lib/format';
import {
  dayFacts,
  LEAD_MS,
  MAX_PENDING,
  planReminders,
  reminderId,
  type PendingReminder,
  type PlanInput,
  type Wanted,
} from '@/lib/reminderPlan';

/**
 * Which reminders the phone should hold. The prayer hour runs 08–09 and
 * 20–21 Berlin time; everything else on the channel is music.
 */

const TZ = 'Europe/Berlin';
const MAIN: ChannelInfo = { id: 'main', name: { en: 'Arche Radio', de: 'Arche Radio' }, main: true, tz: TZ, color: '#000', host: { name: 'Noah', avatar: null }, evergreen: null };

/** UTC ms of a Berlin wall-clock time (tries both offsets the zone has). */
function berlin(date: string, hm: string): number {
  const [h, m] = hm.split(':').map(Number) as [number, number];
  const fmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
  for (const offset of [1, 2]) {
    const t = Date.UTC(+date.slice(0, 4), +date.slice(5, 7) - 1, +date.slice(8, 10), h, m) - offset * 3_600_000;
    if (fmt.format(t).replace(',', '') === `${date} ${hm}`) return t;
  }
  throw new Error(`no ${date} ${hm} in ${TZ}`);
}

function program(id: string, de = id, en = id): DayProgram {
  return { id, title: { en, de }, subtitle: { en: '', de: '' }, description: { en: '', de: '' }, color: '#000', stage: {} as DayProgram['stage'], allowed: [], format: 'music' };
}

const PROGRAMS = { musik: program('musik', 'Musik', 'Music'), gebet: program('gebet', 'Gebetsstunde', 'Prayer hour') };

/** A day of [from, to, program] in Berlin time; '24:00' is the next day's midnight. */
function day(date: string, blocks: [string, string, string][], gen: number, programs: Record<string, DayProgram> = PROGRAMS): DayFile {
  const at = (hm: string) => (hm === '24:00' ? berlin(addDays(date, 1), '00:00') : berlin(date, hm));
  return { v: 1, channel: 'main', date, tz: TZ, gen, blocks: blocks.map(([from, to, p]) => ({ start: at(from), end: at(to), p })), programs, played: [] };
}

const PRAYER_DAY: [string, string, string][] = [
  ['00:00', '08:00', 'musik'],
  ['08:00', '09:00', 'gebet'],
  ['09:00', '20:00', 'musik'],
  ['20:00', '21:00', 'gebet'],
  ['21:00', '24:00', 'musik'],
];

/** The week the site publishes around `today`: yesterday to six days ahead. */
function week(today: string, now: number, blocks = PRAYER_DAY): Record<string, DayFile> {
  const out: Record<string, DayFile> = {};
  for (let d = -1; d <= 6; d++) {
    const date = addDays(today, d);
    out[date] = day(date, blocks, now - 60_000);
  }
  return out;
}

const time = (ms: number) => new Intl.DateTimeFormat('de', { hour: '2-digit', minute: '2-digit', timeZone: TZ }).format(ms);

function input(over: Partial<PlanInput> & { now: number }): PlanInput {
  return {
    intents: [{ ch: 'main', p: 'gebet', since: 0 }],
    channels: [MAIN],
    days: {},
    pending: [],
    exact: false,
    refreshAll: false,
    describe: (channel, p, slug, start) => ({ title: p?.title.de ?? slug, body: `Beginnt um ${time(start)} Uhr auf ${channel.name.de}.` }),
    ...over,
  };
}

/** What the phone holds after a plan is carried out. */
function pendingFrom(scheduled: Wanted[]): PendingReminder[] {
  return scheduled.map((w) => ({ id: w.id, title: w.title, body: w.body, extra: w.extra }));
}

const TODAY = '2026-10-07';
const NOW = berlin(TODAY, '10:00');

describe('the prayer hour, every airing of the week', () => {
  const plan = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) } }));

  it('one reminder per airing still to come, five minutes ahead', () => {
    // Today 20:00, then six days at 08:00 and 20:00.
    expect(plan.schedule).toHaveLength(13);
    expect(plan.schedule[0]!.at).toBe(berlin(TODAY, '20:00') - LEAD_MS);
    expect(plan.schedule.every((w) => w.extra.start - w.at === LEAD_MS && w.at > NOW)).toBe(true);
    expect(plan.cancel).toEqual([]);
  });

  it('in the listener’s language, with the start time and the channel', () => {
    expect(plan.schedule[0]!.title).toBe('Gebetsstunde');
    expect(plan.schedule[0]!.body).toBe('Beginnt um 20:00 Uhr auf Arche Radio.');
  });

  it('nothing for a program nobody asked about', () => {
    expect(plan.schedule.every((w) => w.extra.p === 'gebet')).toBe(true);
  });

  it('carried out, the same plan changes nothing', () => {
    const again = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) }, pending: pendingFrom(plan.schedule) }));
    expect(again).toEqual({ cancel: [], schedule: [], stale: false });
  });
});

describe('a program across midnight', () => {
  const NIGHT: [string, string, string][] = [
    ['00:00', '01:00', 'nacht'],
    ['01:00', '23:00', 'musik'],
    ['23:00', '24:00', 'nacht'],
  ];
  const programs = { ...PROGRAMS, nacht: program('nacht') };

  it('is one start, not two', () => {
    const days = { [TODAY]: day(TODAY, NIGHT, NOW, programs), [addDays(TODAY, 1)]: day(addDays(TODAY, 1), NIGHT, NOW, programs) };
    const facts = dayFacts(Object.keys(days), days);
    expect(facts.starts.filter((s) => s.p === 'nacht').map((s) => s.start)).toEqual([berlin(TODAY, '23:00'), berlin(addDays(TODAY, 1), '23:00')]);
  });

  it('after a day nobody could read, its midnight half is no start — and a reminder there is kept', () => {
    const tomorrow = addDays(TODAY, 1);
    const days = { [TODAY]: null, [tomorrow]: day(tomorrow, NIGHT, NOW, programs) };
    const start = berlin(tomorrow, '00:00');
    const held: PendingReminder = { id: 7, title: 'nacht', body: '', extra: { arche: 1, ch: 'main', p: 'nacht', start, at: start - LEAD_MS, sig: 'x' } };
    const plan = planReminders(input({ now: NOW, intents: [{ ch: 'main', p: 'nacht', since: 0 }], days: { main: days }, pending: [held] }));
    expect(plan.cancel).toEqual([]);
    expect(plan.schedule.map((w) => w.extra.start)).toEqual([berlin(tomorrow, '23:00')]);
  });
});

describe('summer and winter time', () => {
  it.each([
    ['2026-10-24', 25], // CEST → CET in the night to the 25th
    ['2027-03-27', 23], // CET → CEST in the night to the 28th
  ])('around %s the evening hour stays at 19:55 (a %i-hour day)', (date, hours) => {
    const now = berlin(date, '12:00');
    const plan = planReminders(input({ now, days: { main: week(date, now) } }));
    const evenings = plan.schedule.filter((w) => time(w.extra.start) === '20:00');
    expect(evenings.slice(0, 3).map((w) => time(w.at))).toEqual(['19:55', '19:55', '19:55']);
    expect((evenings[1]!.at - evenings[0]!.at) / 3_600_000).toBe(hours);
  });
});

describe('what changed on the plan', () => {
  const first = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) } }));
  const pending = pendingFrom(first.schedule);

  it('a program taken off the plan: its reminders go', () => {
    const noPrayer: [string, string, string][] = [['00:00', '24:00', 'musik']];
    const plan = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW, noPrayer) }, pending }));
    expect(plan.schedule).toEqual([]);
    expect(plan.cancel.sort()).toEqual(pending.map((n) => n.id).sort());
  });

  it('a program moved: the old time goes, the new one comes', () => {
    const moved = PRAYER_DAY.map(([a, b, p]): [string, string, string] => (a === '20:00' ? ['20:00', '20:30', p] : [a, b, p]));
    moved.splice(3, 2, ['20:00', '20:30', 'musik'], ['20:30', '21:30', 'gebet'], ['21:30', '24:00', 'musik']);
    const plan = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW, moved) }, pending }));
    expect(plan.schedule.filter((w) => time(w.extra.start) === '20:30')).toHaveLength(7);
    expect(plan.cancel).toHaveLength(7); // the seven evenings at 20:00
  });

  it('the language changed: the same ids, written again, nothing cancelled', () => {
    const plan = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) }, pending, describe: (_c, p, slug) => ({ title: p?.title.en ?? slug, body: 'Begins soon.' }) }));
    expect(plan.cancel).toEqual([]);
    expect(plan.schedule.map((w) => w.id).sort()).toEqual(pending.map((n) => n.id).sort());
    expect(plan.schedule[0]!.title).toBe('Prayer hour');
  });

  it('exact alarms allowed now: written again', () => {
    const plan = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) }, pending, exact: true }));
    expect(plan.schedule).toHaveLength(pending.length);
  });

  it('turned off: they go, even when nothing can be read', () => {
    const plan = planReminders(input({ now: NOW, intents: [], channels: null, pending }));
    expect(plan.cancel.sort()).toEqual(pending.map((n) => n.id).sort());
  });
});

describe('what cannot be known', () => {
  const first = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) } }));
  const pending = pendingFrom(first.schedule);

  it('unreadable days keep their reminders and add none', () => {
    const days = Object.fromEntries(Object.keys(week(TODAY, NOW)).map((d) => [d, null]));
    const plan = planReminders(input({ now: NOW, days: { main: days }, pending }));
    expect(plan).toEqual({ cancel: [], schedule: [], stale: false });
  });

  it('a day file older than an hour is no fact (a cached copy): kept, and tried again', () => {
    const old = Object.fromEntries(Object.entries(week(TODAY, NOW)).map(([d, f]) => [d, { ...f, gen: NOW - 2 * 3_600_000 }]));
    const plan = planReminders(input({ now: NOW, days: { main: old }, pending }));
    expect(plan.cancel).toEqual([]);
    expect(plan.schedule).toEqual([]);
    expect(plan.stale).toBe(true);
  });

  it('channels.json unreadable: kept; a channel that is gone: cancelled', () => {
    expect(planReminders(input({ now: NOW, channels: null, pending })).cancel).toEqual([]);
    expect(planReminders(input({ now: NOW, channels: [], pending })).cancel).toHaveLength(pending.length);
  });
});

describe('times', () => {
  const start = berlin(TODAY, '20:00');
  const reminder = (at: number, id = 1): PendingReminder => ({ id, title: 't', body: 'b', extra: { arche: 1, ch: 'main', p: 'gebet', start, at, sig: 's' } });

  it('never one in the past or under a minute ahead: it would fire at once', () => {
    const plan = planReminders(input({ now: start - LEAD_MS - 30_000, days: { main: week(TODAY, NOW) } }));
    expect(plan.schedule.some((w) => w.extra.start === start)).toBe(false);
  });

  it('one that is due is left alone; one long overdue goes', () => {
    expect(planReminders(input({ now: start - LEAD_MS + 5_000, channels: null, pending: [reminder(start - LEAD_MS)] })).cancel).toEqual([]);
    expect(planReminders(input({ now: start + 20 * 60_000, channels: null, pending: [reminder(start - LEAD_MS)] })).cancel).toEqual([1]);
  });

  it('one exactly a minute ahead is kept, and not written a second time', () => {
    const at = start - LEAD_MS;
    const plan = planReminders(input({ now: at - 60_000, days: { main: week(TODAY, at - 60_000) }, pending: [reminder(at)] }));
    expect(plan.cancel).toEqual([]);
    expect(plan.schedule.filter((w) => w.extra.start === start)).toEqual([]);
  });

  it('a notification that is not ours is never touched', () => {
    const foreign: PendingReminder = { id: 99, title: 'x', body: 'y', extra: null };
    const plan = planReminders(input({ now: NOW, intents: [], pending: [foreign] }));
    expect(plan.cancel).toEqual([]);
  });
});

describe('ids and the 64 iOS keeps', () => {
  it('positive 32-bit ints, the same for the same airing', () => {
    const id = reminderId('main|gebet|1790000000000');
    expect(id).toBeGreaterThan(0);
    expect(id).toBeLessThanOrEqual(0x7fffffff);
    expect(reminderId('main|gebet|1790000000000')).toBe(id);
  });

  it('an id another reminder still holds is stepped past', () => {
    const plan = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) } }));
    const first = plan.schedule[0]!;
    // About to fire, so it stays — under the id the new one would get.
    const due: PendingReminder = { id: first.id, title: '', body: '', extra: { arche: 1, ch: 'main', p: 'gebet', start: NOW + 30_000 + LEAD_MS, at: NOW + 30_000, sig: 'x' } };
    const known = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) }, pending: [due] }));
    const ids = known.schedule.map((w) => w.id);
    expect(known.cancel).toEqual([]);
    expect(new Set(ids).size).toBe(ids.length);
    expect(ids).not.toContain(first.id);
    expect(ids).toHaveLength(13);
  });

  it(`only the soonest ${MAX_PENDING}; later ones wait`, () => {
    const many = Object.fromEntries(Array.from({ length: 10 }, (_, i) => [`p${i}`, program(`p${i}`)]));
    const blocks: [string, string, string][] = Array.from({ length: 20 }, (_, i) => {
      const from = `${String(i).padStart(2, '0')}:00`;
      const to = i === 19 ? '24:00' : `${String(i + 1).padStart(2, '0')}:00`;
      return [from, to, `p${i % 10}`];
    });
    const days: Record<string, DayFile> = {};
    for (let d = -1; d <= 6; d++) days[addDays(TODAY, d)] = day(addDays(TODAY, d), blocks, NOW, many);
    const intents = Object.keys(many).map((p) => ({ ch: 'main', p, since: 0 }));
    const plan = planReminders(input({ now: NOW, intents, days: { main: days } }));
    expect(plan.schedule).toHaveLength(MAX_PENDING);
    const latest = Math.max(...plan.schedule.map((w) => w.at));
    expect(plan.schedule.every((w) => w.at <= latest)).toBe(true);
    expect(Math.min(...plan.schedule.map((w) => w.at))).toBeGreaterThan(NOW);
  });
});

describe('at start', () => {
  it('everything is written again (a force-stop on Android drops the alarms, not the list)', () => {
    const first = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) } }));
    const plan = planReminders(input({ now: NOW, days: { main: week(TODAY, NOW) }, pending: pendingFrom(first.schedule), refreshAll: true }));
    expect(plan.schedule).toHaveLength(first.schedule.length);
    expect(plan.cancel).toEqual([]);
  });
});
