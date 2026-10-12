import { describe, expect, it } from 'vitest';
import type { DayFile, DayProgram, SubmissionType } from '@arche/shared';
import { addDays } from '@/lib/format';
import { TILE_TYPES, daysAhead, lockedWhy, nextOpening, tileState } from '@/lib/intake';

/**
 * A locked tile says why and when again. The channel's day: music that takes
 * song requests and stories, a preaching that takes preachings only, and an
 * evening program running across midnight that takes songs.
 */

const TZ = 'Europe/Berlin';

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

function program(id: string, allowed: SubmissionType[]): DayProgram {
  return { id, title: { en: id, de: id }, subtitle: { en: '', de: '' }, description: { en: '', de: '' }, color: '#000', stage: {} as DayProgram['stage'], allowed, format: 'music', voicedBy: [], hosts: [] };
}

const PROGRAMS = {
  morgen: program('morgen', ['song', 'story', 'testimony', 'greeting', 'prayer']),
  predigt: program('predigt', ['preaching']),
  abend: program('abend', ['song']),
};

const DAY: [string, string, string][] = [
  ['00:00', '01:00', 'abend'],
  ['01:00', '09:00', 'morgen'],
  ['09:00', '11:00', 'predigt'],
  ['11:00', '22:00', 'morgen'],
  ['22:00', '24:00', 'abend'],
];

function day(date: string, blocks = DAY): DayFile {
  const at = (hm: string) => (hm === '24:00' ? berlin(addDays(date, 1), '00:00') : berlin(date, hm));
  return { v: 1, channel: 'main', date, tz: TZ, gen: 0, blocks: blocks.map(([from, to, p]) => ({ start: at(from), end: at(to), p })), programs: PROGRAMS, played: [] };
}

const TODAY = '2026-10-12';
const DATES = [-1, 0, 1, 2].map((d) => addDays(TODAY, d));
const WEEK = Object.fromEntries(DATES.map((d) => [d, day(d)]));

describe('a tile and the program on air', () => {
  it('is open while any of its types is, closing before it closes, and off when the program takes none', () => {
    expect(tileState(TILE_TYPES.video, { preaching: 'closed', film: 'open' })).toBe('open');
    expect(tileState(TILE_TYPES.story, { story: 'closing', testimony: 'closing' })).toBe('closing');
    expect(tileState(TILE_TYPES.song, { song: 'closed' })).toBe('closed');
    expect(tileState(TILE_TYPES.song, { preaching: 'open' })).toBe('off');
  });

  it('is locked because the program does not take it, or for the reason the minute file gives', () => {
    expect(lockedWhy(TILE_TYPES.song, { preaching: 'open' }, {})).toBe('off');
    expect(lockedWhy(TILE_TYPES.song, { song: 'closed' }, { song: 'late' })).toBe('late');
    expect(lockedWhy(TILE_TYPES.story, { story: 'closed', testimony: 'closed', greeting: 'closed' }, { greeting: 'full' })).toBe('full');
    // A file from before the reasons.
    expect(lockedWhy(TILE_TYPES.song, { song: 'closed' }, {})).toBe('unknown');
  });
});

describe('when a locked tile opens again', () => {
  it('is the next program that takes it: after the preaching, music again', () => {
    const next = nextOpening(TILE_TYPES.song, DATES, WEEK, berlin(TODAY, '09:30'));
    expect(next).toMatchObject({ start: berlin(TODAY, '11:00'), program: { id: 'morgen' } });
  });

  it('never the run on air, which closed: the next one, past programs that do not take it', () => {
    // Music closes late before the preaching; songs come back after it.
    expect(nextOpening(TILE_TYPES.song, DATES, WEEK, berlin(TODAY, '08:50'))?.start).toBe(berlin(TODAY, '11:00'));
    // Stories: the evening takes none, so the next morning.
    expect(nextOpening(TILE_TYPES.story, DATES, WEEK, berlin(TODAY, '21:50'))?.start).toBe(berlin(addDays(TODAY, 1), '01:00'));
  });

  it('a run across midnight begins once: closed late in the evening, songs open again only after it', () => {
    const next = nextOpening(TILE_TYPES.song, DATES, WEEK, berlin(TODAY, '23:50'));
    expect(next).toMatchObject({ start: berlin(addDays(TODAY, 1), '01:00'), program: { id: 'morgen' } });
  });

  it('the video tile with any kind of video; a day without its file skipped; none in the days known', () => {
    expect(nextOpening(TILE_TYPES.video, DATES, WEEK, berlin(TODAY, '12:00'))?.start).toBe(berlin(addDays(TODAY, 1), '09:00'));
    const missing = { ...WEEK, [addDays(TODAY, 1)]: null };
    expect(nextOpening(TILE_TYPES.video, DATES, missing, berlin(TODAY, '12:00'))?.start).toBe(berlin(addDays(TODAY, 2), '09:00'));
    expect(nextOpening(TILE_TYPES.video, DATES, WEEK, berlin(addDays(TODAY, 2), '12:00'))).toBeNull();
  });
});

describe('the days ahead, by the listener\'s own calendar', () => {
  it('counts today, tomorrow and the days after — across a change of the clocks', () => {
    const noon = new Date(2026, 9, 24, 12).getTime();
    expect(daysAhead(new Date(2026, 9, 24, 23, 59).getTime(), noon)).toBe(0);
    expect(daysAhead(new Date(2026, 9, 25, 0, 1).getTime(), noon)).toBe(1);
    expect(daysAhead(new Date(2026, 9, 27, 9).getTime(), noon)).toBe(3);
  });
});
