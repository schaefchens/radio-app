import { describe, expect, it } from 'vitest';
import type { WallEntry } from '@arche/shared';
import { WALL_PAGE_MS, entryBy, entryText, prayingFirst, wallPage } from '@/lib/prayerWall';

const wall: WallEntry[] = [
  { id: 'pa', text: 'For my mother.', at: 3 },
  { id: 'pb', text: 'For peace.', at: 2 },
  { id: 'pc', text: 'For my exams.', at: 1 },
];

describe('the prayer wall', () => {
  it('puts the request on air first, and stays the same list when it is not on the wall', () => {
    expect(prayingFirst(wall, ['pc']).map((e) => e.id)).toEqual(['pc', 'pa', 'pb']);
    expect(prayingFirst(wall, ['pb', 'pc']).map((e) => e.id)).toEqual(['pb', 'pc', 'pa']);
    // Nothing on air, or taken down after its reading was published: no copy (a selector would loop).
    expect(prayingFirst(wall, [])).toBe(wall);
    expect(prayingFirst(wall, ['px'])).toBe(wall);
  });

  it('in the prayer time every listener sees the same few requests, page by page, and every request comes round', () => {
    const t = 1_790_160_000_000;
    expect(wallPage(wall, t, 2)).toEqual(wallPage(wall, t + 1000, 2));
    const pages = Array.from({ length: 4 }, (_, i) => wallPage(wall, t + i * WALL_PAGE_MS, 2).map((e) => e.id));
    // Always a full page, wrapping round the end — never one request alone.
    for (const page of pages) expect(new Set(page).size).toBe(2);
    expect(new Set(pages.flat())).toEqual(new Set(['pa', 'pb', 'pc']));
    // Few enough for one page: all of them, the same list.
    expect(wallPage(wall, t, 3)).toBe(wall);
    expect(wallPage([], t, 3)).toEqual([]);
  });

  it('says whose a request is: the first name and place given, the station\'s source — nothing for one who stayed anonymous', () => {
    expect(entryBy({ id: 'p1', text: 'x', at: 1, name: 'Ruth', place: 'Lagos' })).toBe('Ruth · Lagos');
    expect(entryBy({ id: 'p2', text: 'x', at: 1, name: 'Ruth' })).toBe('Ruth');
    expect(entryBy({ id: 'p3', text: 'x', at: 1, source: 'Open Doors · Nigeria' })).toBe('Open Doors · Nigeria');
    expect(entryBy(wall[0]!)).toBe('');
  });

  it('the station\'s own request comes in the listener\'s language when it was translated', () => {
    const od: WallEntry = { id: 'po', text: 'Beten wir für Nigeria.', at: 1, source: 'Open Doors · Nigeria', texts: { en: 'Let us pray for Nigeria.' } };
    expect([entryText(od, 'en'), entryText(od, 'de'), entryText(wall[0]!, 'de')]).toEqual(['Let us pray for Nigeria.', 'Beten wir für Nigeria.', 'For my mother.']);
  });
});
