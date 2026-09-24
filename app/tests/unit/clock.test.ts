import { describe, expect, it } from 'vitest';
import { offsetFrom } from '@/lib/clock';

describe('clock offset', () => {
  it('trusts the sample with the shortest round trip', () => {
    const r = offsetFrom([
      { t0: 1000, t1: 1400, server: 6000 }, // rtt 400 → offset 4800
      { t0: 2000, t1: 2040, server: 7020 }, // rtt 40 → offset 5000
      { t0: 3000, t1: 3300, server: 8200 },
    ]);
    expect(r).toEqual({ offset: 5000, rtt: 40 });
  });
  it('returns null without samples', () => {
    expect(offsetFrom([])).toBeNull();
  });
});
