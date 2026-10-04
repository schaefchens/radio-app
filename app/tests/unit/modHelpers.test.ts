import { describe, expect, it } from 'vitest';
import { findOverlap, hmToMin, minToHm } from '@/components/mod/planMath';
import { allowedForFormat } from '@/components/mod/programFormat';
import { chatErrorKey } from '@/lib/realtime/errors';

describe('plan editor helpers', () => {
  it('converts between HH:MM and minutes, including the end of the day', () => {
    expect(hmToMin('07:30')).toBe(450);
    expect(hmToMin('7:05')).toBe(425);
    expect(hmToMin('24:00')).toBe(1440);
    expect(hmToMin('24:01')).toBeNull();
    expect(hmToMin('12:60')).toBeNull();
    expect(hmToMin('noon')).toBeNull();
    expect(minToHm(450)).toBe('07:30');
    expect(minToHm(1440)).toBe('24:00');
  });

  it('finds overlapping blocks regardless of order; touching blocks are fine', () => {
    expect(findOverlap([{ start_min: 60, end_min: 120, program_id: 1 }, { start_min: 0, end_min: 60, program_id: 2 }])).toBe(-1);
    expect(findOverlap([{ start_min: 0, end_min: 90, program_id: 1 }, { start_min: 60, end_min: 120, program_id: 2 }])).toBe(1);
  });
});

// The server keeps suggested videos only in a video program (Catalog::saveProgram);
// the form mirrors that, so what a moderator sees ticked is what is saved.
describe('a program\'s format and the suggestions it takes', () => {
  it('a video format takes its own kind from the start', () => {
    expect(allowedForFormat(['song'], 'music', 'mission')).toEqual(['song', 'mission']);
    expect(allowedForFormat([], 'music', 'testimony')).toEqual(['testimony_video']);
    expect(allowedForFormat(['song'], 'music', 'film')).toEqual(['song', 'film']);
  });

  it('switching between video formats swaps the own kind and keeps the others a moderator ticked', () => {
    expect(allowedForFormat(['mission', 'testimony_video', 'song'], 'mission', 'testimony')).toEqual(['testimony_video', 'song']);
    expect(allowedForFormat(['testimony_video'], 'testimony', 'mission')).toEqual(['mission']);
    expect(allowedForFormat(['preaching', 'film'], 'preaching', 'film')).toEqual(['film']);
  });

  it('a format without videos takes none of them', () => {
    expect(allowedForFormat(['song', 'preaching', 'mission', 'story'], 'preaching', 'music')).toEqual(['song', 'story']);
    expect(allowedForFormat(['mission', 'testimony_video', 'film'], 'mission', 'prayer')).toEqual([]);
  });

  it('the same format again changes nothing, and the own kind is never listed twice', () => {
    expect(allowedForFormat(['testimony_video', 'song'], 'testimony', 'testimony')).toEqual(['testimony_video', 'song']);
    expect(allowedForFormat(['song'], 'mission', 'mission')).toEqual(['song']);
    expect(allowedForFormat(['song', 'film'], 'music', 'film')).toEqual(['song', 'film']);
  });
});

describe('chat error messages', () => {
  it('maps node error codes to sentences', () => {
    expect(chatErrorKey(null)).toBeNull();
    expect(chatErrorKey('rate')).toBe('chat.rate');
    expect(chatErrorKey('too_long')).toBe('chat.tooLong');
    expect(chatErrorKey('banned')).toBe('chat.banned');
    expect(chatErrorKey('expired')).toBe('chat.connecting');
    expect(chatErrorKey('something_new')).toBe('chat.error');
  });
});
