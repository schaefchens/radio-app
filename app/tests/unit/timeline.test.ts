import { describe, expect, it } from 'vitest';
import type { SlotFile, TimelineItem } from '@arche/shared';
import { Timeline } from '@/lib/timeline';
import { evergreenAt } from '@/lib/evergreen';

const song = (id: string, start: number, dur: number): TimelineItem => ({
  id, type: 'song', start, dur, p: 'live', yt: 'dQw4w9WgXcQ', title: id, artist: 'a', thumb: null, request: null, fallback: null,
});
const slot = (t: number, items: TimelineItem[]): SlotFile => ({
  v: 1, channel: 'main', t, gen: 0, current: 'live', next: null, submissions: { song: 'open' }, programs: {}, items,
});

describe('Timeline', () => {
  it('merges overlapping minute files and finds the item on air', () => {
    const tl = new Timeline();
    tl.add(slot(0, [song('a', 0, 240_000), song('b', 240_000, 200_000)]));
    tl.add(slot(60_000, [song('b', 240_000, 200_000), song('c', 440_000, 180_000)]));
    expect(tl.at(10_000)?.id).toBe('a');
    expect(tl.at(240_000)?.id).toBe('b');
    expect(tl.at(500_000)?.id).toBe('c');
    expect(tl.at(700_000)).toBeNull();
    expect(tl.coveredUntil()).toBe(620_000);
    expect(tl.next(10_000)?.id).toBe('b');
  });

  it('skips items a moderator pulled from air', () => {
    const tl = new Timeline();
    tl.add(slot(0, [song('a', 0, 60_000)]));
    expect(tl.at(1000, new Set(['a']))).toBeNull();
  });

  it('keeps per-minute submission state', () => {
    const tl = new Timeline();
    tl.add(slot(0, [song('a', 0, 60_000)]));
    tl.add({ ...slot(60_000, []), submissions: { song: 'closing' } });
    expect(tl.submissions(30_000)).toEqual({ song: 'open' });
    expect(tl.submissions(90_000)).toEqual({ song: 'closing' });
  });
});

describe('evergreen', () => {
  const file = { v: 1 as const, channel: 'main', epoch: 1_000, total: 300, items: [
    { yt: 'aaaaaaaaaaa', title: 'A', artist: '', dur: 100, thumb: null },
    { yt: 'bbbbbbbbbbb', title: 'B', artist: '', dur: 200, thumb: null },
  ] };
  it('is the same second for everyone, looping', () => {
    expect(evergreenAt(file, 1_050)).toMatchObject({ index: 0, offset: 50, start: 1_000 });
    expect(evergreenAt(file, 1_150)).toMatchObject({ index: 1, offset: 50, start: 1_100 });
    expect(evergreenAt(file, 1_000 + 300 * 7 + 10)).toMatchObject({ index: 0, offset: 10 });
    expect(evergreenAt(file, 990)).toMatchObject({ index: 1, offset: 190 }); // before the epoch
  });
});
