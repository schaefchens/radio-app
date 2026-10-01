import { describe, expect, it } from 'vitest';
import type { WallEntry } from '@arche/shared';
import { SILENT_ROTATE_MS, prayingFirst, silentEntry } from '@/lib/prayerWall';

const wall: WallEntry[] = [
  { id: 'pa', text: 'For my mother.', at: 3 },
  { id: 'pb', text: 'For peace.', at: 2 },
  { id: 'pc', text: 'For my exams.', at: 1 },
];

describe('the prayer wall', () => {
  it('puts the requests the host is praying for first, and stays the same list when none of them is on it', () => {
    expect(prayingFirst(wall, ['pc']).map((e) => e.id)).toEqual(['pc', 'pa', 'pb']);
    expect(prayingFirst(wall, ['pb', 'pc']).map((e) => e.id)).toEqual(['pb', 'pc', 'pa']);
    // Nothing prayed for, or taken down after the moment was published: no copy (a selector would loop).
    expect(prayingFirst(wall, [])).toBe(wall);
    expect(prayingFirst(wall, ['px'])).toBe(wall);
  });

  it('in silent prayer every listener sees the same request at the same moment', () => {
    const t = 1_790_160_000_000;
    expect(silentEntry(wall, t)).toBe(silentEntry(wall, t + 1000));
    const ids = new Set(Array.from({ length: wall.length }, (_, i) => silentEntry(wall, t + i * SILENT_ROTATE_MS)?.id));
    expect(ids.size).toBe(wall.length);
    expect(silentEntry([], t)).toBeNull();
  });
});
