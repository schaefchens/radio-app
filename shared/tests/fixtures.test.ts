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
  type GroupNotice,
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
      new Set(['song', 'host', 'jingle', 'silence', 'contrib', 'stage', 'gap', 'bed']),
    );
    const bed = slot!.items.find((i) => i.type === 'bed');
    expect(bed).toMatchObject({ audio: '/media/beds/5e0d7a.mp3', offset: 60000, label: { en: 'What can we pray for?', de: 'Wofür dürfen wir beten?' } });
    expect(slot!.submissions).toEqual({ song: 'open', prayer: 'closing', intercession: 'open' });
    expect(slot!.programs.worship?.stage.mode).toBe('flyins');
    expect(slot!.programs.worship?.format).toBe('music');
    expect(slot!.programs.sermon?.format).toBe('preaching');
    expect(slot!.programs.outreach).toMatchObject({ format: 'mission', allowed: ['mission', 'testimony_video', 'film'] });
    expect(slot!.items.filter((i) => i.type === 'song').map((i) => i.type === 'song' && [i.kind, i.request?.name])).toEqual([
      ['song', 'Jenny'],
      ['preaching', 'Samuel'],
      ['testimony', 'Grace'],
      ['film', undefined],
    ]);
    // A film runs two hours: nothing about an item's length is capped.
    expect(slot!.items.find((i) => i.type === 'song' && i.kind === 'film')?.dur).toBe(7_200_000);
    const host = slot!.items.find((i) => i.type === 'host');
    expect(host?.type === 'host' && host.prayers).toEqual(['pk3v9q2m7x4tb']);
    // A group's notice after the item before: its links https only — the
    // fixture's javascript: one would run in the app when tapped.
    expect(host?.type === 'host' && host.notice).toEqual({
      name: 'Grace Chapel',
      text: { en: 'A church in Accra, Ghana.', de: 'Eine Gemeinde in Accra, Ghana.' },
      links: [
        { kind: 'youtube', url: 'https://www.youtube.com/@gracechapel' },
        { kind: 'website', url: 'https://gracechapel.example' },
      ],
    });
    // The page of a fact the host told, linked with the words on the stage.
    expect(host?.type === 'host' && host.cite).toEqual({ title: 'kirche-im-swr.de', url: 'https://www.kirche-im-swr.de/beitraege?id=18167' });
    // A listener's request read out word for word: one language, the request on the wall it is.
    const reading = slot!.items.find((i) => i.type === 'host' && i.kind === 'reading');
    expect(reading).toMatchObject({ kind: 'reading', audio: { en: '/media/host/20260923/9c20.en.mp3' }, prayers: ['pk3v9q2m7x4tb'] });
  });

  it('day, live, channels and evergreen parse', () => {
    const day = parseDayFile(load('day.json'));
    expect(day?.blocks).toHaveLength(2);
    expect(day?.played).toHaveLength(2);
    // "Was lief" links a song to YouTube; a listener's contribution has nothing there.
    expect(day?.played.map((p) => p.yt)).toEqual(['dQw4w9WgXcQ', '']);
    expect(parseDayFile({ ...(load('day.json') as object), played: [{ start: 1, type: 'song', title: 'Old file' }] })?.played[0]?.yt).toBe('');
    expect(day?.programs.prayer?.description.de).not.toBe('');
    expect(day?.programs.prayer?.format).toBe('prayer');

    const live = parseLiveFile(load('live.json'));
    expect(live?.voices).toHaveLength(2);
    // The author's mark, for a listener who blocked them (Presence::voiceTag).
    expect(live?.voices[0]?.by).toBe('3f9a1c07be52');
    expect(live?.wall).toHaveLength(3);
    // A prayer hour's: shown from its reading on; the station's own says whose it is, translated.
    expect(live?.wall[0]).toEqual({
      id: 'pq7n4x2k9m3sd',
      text: 'Bewaffnete Kämpfer töteten die Frau von Pastor Josiah. Beten wir, dass Jesus ihn tröstet.',
      at: 1790186400000,
      from: 1790189880000,
      source: 'Open Doors · Nigeria',
      texts: { en: 'Armed fighters killed the wife of Pastor Josiah. Let us pray that Jesus comforts him.' },
    });
    // A listener's with the first name and place they gave; one who stayed anonymous has neither.
    expect(live?.wall[1]).toEqual({
      id: 'pk3v9q2m7x4tb',
      text: 'Please pray for my mother, she has surgery on Friday.',
      at: 1790189940000,
      from: 1790190000000,
      name: 'Ruth',
      place: 'Lagos',
    });
    expect(Object.keys(live?.wall[2] ?? {})).toEqual(['id', 'text', 'at']);
    expect(live?.blocked).toEqual(['i7kq2s']);
    expect(live?.collected).toBe(3);

    const channels = parseChannelsFile(load('channels.json'));
    expect(channels?.channels.filter((c) => c.main)).toHaveLength(1);

    const evergreen = parseEvergreenFile(load('evergreen.json'));
    expect(evergreen?.total).toBe(543000);
  });

  it('reads a live.json from before the prayer wall as an empty wall', () => {
    // During a deploy the CDN can still hand out the old generator's file.
    const { wall: _wall, ...old } = load('live.json') as Record<string, unknown>;
    const live = parseLiveFile(old);
    expect(live?.wall).toEqual([]);
    expect(live?.voices).toHaveLength(2);
    // An entry without text is dropped, not shown as an empty card.
    expect(parseLiveFile({ ...old, wall: [{ id: 'p1', at: 1 }, { id: 'p2', text: 'Amen', at: 2 }] })?.wall).toEqual([
      { id: 'p2', text: 'Amen', at: 2 },
    ]);
  });

  it('a piece of prayer music needs its file; without an offset it starts the file', () => {
    const items = (extra: Record<string, unknown>[]) =>
      parseSlotFile({ ...(load('slot.json') as object), items: extra.map((e, i) => ({ id: `b${i}`, type: 'bed', start: i * 1000, dur: 1000, p: 'prayer', ...e })) })?.items;
    expect(items([{ label: { en: 'x', de: 'y' } }, { audio: '' }])).toEqual([]);
    expect(items([{ audio: '/media/beds/a.mp3', offset: -5 }, { audio: '/media/beds/a.mp3' }])?.map((i) => i.type === 'bed' && i.offset)).toEqual([0, 0]);
  });

  it('an older generator\'s host item and program read as before; new host kinds are known, unknown ones a break', () => {
    const raw = load('slot.json') as { items: Record<string, unknown>[]; programs: Record<string, Record<string, unknown>> };
    const host = { ...raw.items[1]!, prayers: undefined };
    const { format: _format, ...worship } = raw.programs.worship!;
    const slot = parseSlotFile({ ...raw, programs: { worship }, items: [host, { ...host, id: 'k2', kind: 'invite' }, { ...host, id: 'k3', kind: 'hymn' }] });
    expect(slot?.programs.worship?.format).toBe('music');
    expect(slot?.items.map((i) => (i.type === 'host' ? [i.kind, i.prayers] : null))).toEqual([['break', []], ['invite', []], ['break', []]]);
    const kinds = ['reading', 'intercession', 'present', 'prayertime', 'encourage', 'preaching', 'testimony', 'mission', 'film'];
    const start = Number(raw.items[1]!.start);
    const newer = parseSlotFile({ ...raw, items: kinds.map((kind, i) => ({ ...host, id: `n${i}`, start: start + i * 30_000, kind })) });
    expect(newer?.items.map((i) => i.type === 'host' && i.kind)).toEqual(kinds);
  });

  it('a host item without a notice has none; a notice needs a name, keeps https links only — an unknown kind as "other" — and at most four', () => {
    const raw = load('slot.json') as { items: Record<string, unknown>[] };
    const { notice: _notice, ...host } = raw.items[1]!;
    const noticeOf = (notice: unknown): GroupNotice | null => {
      const item = parseSlotFile({ ...(raw as object), items: [{ ...host, notice }] })?.items[0];
      if (item?.type !== 'host') throw new Error('not a host item');
      return item.notice;
    };
    expect(noticeOf(undefined)).toBeNull();
    expect(noticeOf({ text: { en: 'x' }, links: [] })).toBeNull();
    const https = (n: number): string => `https://example.org/${n}`;
    expect(noticeOf({ name: 'Hope', links: [{ kind: 'podcast', url: https(1) }, { kind: 'website', url: 'http://example.org' }, { url: 'data:text/html,x' }] })).toEqual({
      name: 'Hope',
      text: { en: '', de: '' },
      links: [{ kind: 'other', url: https(1) }],
    });
    expect(noticeOf({ name: 'Hope', links: [1, 2, 3, 4, 5].map((n) => ({ kind: 'website', url: https(n) })) })?.links).toHaveLength(4);
  });

  it('a host item cites a fact\'s page only over https; without one it cites nothing', () => {
    const raw = load('slot.json') as { items: Record<string, unknown>[] };
    const { cite: _cite, ...host } = raw.items[1]!;
    const citeOf = (cite: unknown) => {
      const item = parseSlotFile({ ...(raw as object), items: [{ ...host, cite }] })?.items[0];
      if (item?.type !== 'host') throw new Error('not a host item');
      return item.cite;
    };
    expect(citeOf(undefined)).toBeNull();
    expect(citeOf({ title: 'x', url: 'javascript:alert(1)' })).toBeNull();
    expect(citeOf({ url: 'https://example.org/p' })).toEqual({ title: '', url: 'https://example.org/p' });
  });

  it('a host moment names its host; the schedule its hosts; channels their first; and who may voice a program', () => {
    const slot = parseSlotFile(load('slot.json'));
    const hosts = slot!.items.flatMap((i) => (i.type === 'host' ? [i.host] : []));
    expect(hosts).toEqual([
      { name: 'David', avatar: null, color: '#e0763a', about: { en: 'Mornings and requests.', de: 'Morgens und eure Wünsche.' }, voice: 'elevenlabs' },
      { name: 'Hope', avatar: '/media/stage/host-1a2b.webp', color: '#2f7bff', about: { en: 'Your companion through the day.', de: 'Deine Begleiterin durch den Tag.' }, voice: 'openai' },
    ]);
    expect(Object.fromEntries(Object.entries(slot!.programs).map(([k, p]) => [k, p.voicedBy]))).toEqual({
      worship: ['openai', 'elevenlabs'],
      sermon: ['openai'],
      outreach: ['elevenlabs'],
    });
    const day = parseDayFile(load('day.json'));
    expect(day?.programs.night?.hosts.map((h) => h.name)).toEqual(['Hope']);
    expect(day?.programs.prayer?.hosts.map((h) => [h.name, h.voice])).toEqual([
      ['Hope', 'openai'],
      ['David', 'elevenlabs'],
    ]);
    const channels = parseChannelsFile(load('channels.json'));
    expect(channels?.channels[0]?.host).toMatchObject({ name: 'Hope', avatar: '/media/stage/host-1a2b.webp', color: '#2f7bff', voice: 'openai' });
  });

  it('files from before several hosts read as none named — the channel\'s then — and nobody voiced', () => {
    const raw = load('slot.json') as { items: Record<string, unknown>[]; programs: Record<string, Record<string, unknown>> };
    const { host: _host, ...older } = raw.items[1]!;
    const { voicedBy: _voicedBy, ...worship } = raw.programs.worship!;
    const slot = parseSlotFile({ ...raw, programs: { worship }, items: [older] });
    expect(slot?.items[0]?.type === 'host' && slot.items[0].host).toBeNull();
    expect(slot?.programs.worship?.voicedBy).toEqual([]);
    const dayRaw = load('day.json') as { programs: Record<string, Record<string, unknown>> };
    const { hosts: _hosts, ...night } = dayRaw.programs.night!;
    expect(parseDayFile({ ...dayRaw, programs: { night } })?.programs.night?.hosts).toEqual([]);
    // channels.json of an older generator: {name, avatar} only.
    const channelsRaw = load('channels.json') as { channels: Record<string, unknown>[] };
    const channels = parseChannelsFile({ ...channelsRaw, channels: [{ ...channelsRaw.channels[0], host: { name: 'Hope', avatar: '/media/stage/a.webp' } }] });
    expect(channels?.channels[0]?.host).toEqual({ name: 'Hope', avatar: '/media/stage/a.webp', color: '#2f7bff', about: { en: '', de: '' }, voice: 'openai' });
  });

  it('a host\'s picture only from /media, a color only as #rrggbb, an unknown voice as OpenAI — no name, no host', () => {
    const raw = load('slot.json') as { items: Record<string, unknown>[] };
    const hostOf = (host: unknown) => {
      const item = parseSlotFile({ ...(raw as object), items: [{ ...raw.items[1], host }] })?.items[0];
      return item?.type === 'host' ? item.host : undefined;
    };
    expect(hostOf({ name: 'Eve', avatar: 'https://evil.example/x.png', color: 'red;background:url(x)', voice: 'acme' })).toEqual({
      name: 'Eve',
      avatar: null,
      color: '#2f7bff',
      about: { en: '', de: '' },
      voice: 'openai',
    });
    expect(hostOf({ name: 'Eve', avatar: '/media/../_arche/.env' })?.avatar).toBeNull();
    expect(hostOf({ avatar: '/media/stage/a.webp' })).toBeNull();
    const dayRaw = load('day.json') as { programs: Record<string, Record<string, unknown>> };
    const many = Array.from({ length: 9 }, (_, i) => ({ name: `Host ${i}` }));
    expect(parseDayFile({ ...dayRaw, programs: { night: { ...dayRaw.programs.night, hosts: many } } })?.programs.night?.hosts).toHaveLength(6);
  });

  it('a host speaking on the station\'s own computer keeps that voice, and a program voiced by it says so', () => {
    const raw = load('slot.json') as { items: Record<string, unknown>[]; programs: Record<string, Record<string, unknown>> };
    const slot = parseSlotFile({
      ...raw,
      programs: { ...raw.programs, worship: { ...raw.programs.worship!, voicedBy: ['worker', 'openai', 'worker'] } },
      items: [{ ...raw.items[1], host: { name: 'Grace', voice: 'worker' } }],
    });
    const item = slot?.items[0];
    expect(item?.type === 'host' ? item.host?.voice : undefined).toBe('worker');
    expect(slot?.programs.worship?.voicedBy).toEqual(['worker', 'openai']);
  });

  it('a song item without a kind is a song; a format or kind it does not know reads as music and a song', () => {
    const raw = load('slot.json') as { items: Record<string, unknown>[]; programs: Record<string, Record<string, unknown>> };
    const { kind: _kind, ...song } = raw.items[0]!;
    const slot = parseSlotFile({
      ...raw,
      programs: { worship: { ...raw.programs.worship!, format: 'concert' } },
      items: [song, { ...song, id: 'k2', kind: 'audiobook' }, ...['preaching', 'testimony', 'mission', 'film'].map((kind, i) => ({ ...song, id: `v${i}`, kind }))],
    });
    expect(slot?.programs.worship?.format).toBe('music');
    expect(slot?.items.map((i) => i.type === 'song' && i.kind)).toEqual(['song', 'song', 'preaching', 'testimony', 'mission', 'film']);
    const formats = ['testimony', 'mission', 'film'].map((format) => parseSlotFile({ ...raw, programs: { worship: { ...raw.programs.worship!, format } } })?.programs.worship?.format);
    expect(formats).toEqual(['testimony', 'mission', 'film']);
  });

  it('a video program\'s suggestion types are kept, in the minute file and in what it allows; unknown ones dropped', () => {
    const raw = load('slot.json') as { programs: Record<string, Record<string, unknown>> };
    const slot = parseSlotFile({
      ...raw,
      current: 'outreach',
      submissions: { mission: 'open', testimony_video: 'closing', film: 'closed', video: 'open' },
      programs: { outreach: { ...raw.programs.outreach!, allowed: ['mission', 'testimony_video', 'film', 'documentary'] } },
    });
    expect(slot?.submissions).toEqual({ mission: 'open', testimony_video: 'closing', film: 'closed' });
    expect(slot?.programs.outreach?.allowed).toEqual(['mission', 'testimony_video', 'film']);
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
