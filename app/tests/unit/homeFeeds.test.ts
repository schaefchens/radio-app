import { describe, expect, it } from 'vitest';
import { fitMessages } from '@/components/home/carouselFit';
import { dayKey } from '@/lib/format';

describe('phone carousel', () => {
  it('shows as many messages as fit, at most three', () => {
    expect(fitMessages(500, 80, 120)).toBe(3);
    expect(fitMessages(330, 80, 120)).toBe(2);
    expect(fitMessages(1000, 80, 120)).toBe(3);
  });
  it('always shows one, even on a very small screen', () => {
    expect(fitMessages(50, 80, 120)).toBe(1);
  });
});

describe('prayer wall day', () => {
  const now = new Date(2026, 8, 30, 9, 0).getTime();
  it('says today and yesterday by the local calendar', () => {
    expect(dayKey(new Date(2026, 8, 30, 0, 5).getTime(), now)).toBe('today');
    expect(dayKey(new Date(2026, 8, 29, 23, 55).getTime(), now)).toBe('yesterday');
    expect(dayKey(new Date(2026, 8, 28, 12, 0).getTime(), now)).toBeNull();
  });
  it('counts a DST night as one day', () => {
    // Europe changes to winter time on 25 October 2026: that day has 25 hours.
    const after = new Date(2026, 9, 26, 0, 30).getTime();
    expect(dayKey(new Date(2026, 9, 25, 1, 0).getTime(), after)).toBe('yesterday');
  });
});
