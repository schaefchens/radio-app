import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
  parseChannelsFile,
  parseDayFile,
  parseEvergreenFile,
  parseLiveFile,
  parseSlotFile,
  regionOf,
  slotPath,
} from '../src/index.ts';

/**
 * shared/fixtures is the contract between the PHP generator and every reader.
 * server/tests checks that what PHP writes has the fixtures' shape; this file
 * checks that the readers accept the fixtures without dropping anything. Both
 * sides pass → both sides agree.
 */

const load = (name: string): unknown =>
  JSON.parse(readFileSync(fileURLToPath(new URL(`../fixtures/${name}`, import.meta.url)), 'utf8'));

describe('program file fixtures', () => {
  it('slot.json parses with every item kept', () => {
    const raw = load('slot.json') as { items: unknown[] };
    const slot = parseSlotFile(raw);
    expect(slot).not.toBeNull();
    expect(slot!.items).toHaveLength(raw.items.length);
    expect(new Set(slot!.items.map((i) => i.type))).toEqual(
      new Set(['song', 'host', 'jingle', 'silence', 'contrib', 'stage', 'gap']),
    );
    expect(slot!.submissions).toEqual({ song: 'open', prayer: 'closing' });
    expect(slot!.programs.worship?.stage.mode).toBe('flyins');
  });

  it('day, live, channels and evergreen parse', () => {
    const day = parseDayFile(load('day.json'));
    expect(day?.blocks).toHaveLength(2);
    expect(day?.played).toHaveLength(2);
    expect(day?.programs.prayer?.description.de).not.toBe('');

    const live = parseLiveFile(load('live.json'));
    expect(live?.voices).toHaveLength(2);
    expect(live?.blocked).toEqual(['i7kq2s']);

    const channels = parseChannelsFile(load('channels.json'));
    expect(channels?.channels.filter((c) => c.main)).toHaveLength(1);

    const evergreen = parseEvergreenFile(load('evergreen.json'));
    expect(evergreen?.total).toBe(543000);
  });

  it('rejects unknown versions and drops unknown item types', () => {
    expect(parseSlotFile({ ...(load('slot.json') as object), v: 2 })).toBeNull();
    const slot = parseSlotFile({
      ...(load('slot.json') as object),
      items: [{ id: 'x', type: 'hologram', start: 0, dur: 1000, p: 'worship' }],
    });
    expect(slot?.items).toEqual([]);
  });
});

describe('paths and regions', () => {
  it('names minute files in UTC', () => {
    // 2026-10-25 00:30 UTC is 02:30 CEST, the hour that repeats in Berlin.
    expect(slotPath('main', Date.UTC(2026, 9, 25, 0, 30, 42))).toBe('program/main/slots/20261025/0030.json');
    expect(slotPath('main', Date.UTC(2026, 9, 25, 1, 30))).toBe('program/main/slots/20261025/0130.json');
  });

  it('maps countries to regions, unknown to world', () => {
    expect(regionOf('de')).toBe('dach');
    expect(regionOf('BR')).toBe('americas');
    expect(regionOf('')).toBe('world');
    expect(regionOf('ZZ')).toBe('world');
  });
});
