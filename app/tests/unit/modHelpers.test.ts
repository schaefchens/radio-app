import { describe, expect, it } from 'vitest';
import { findOverlap, hmToMin, minToHm } from '@/components/mod/planMath';
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
