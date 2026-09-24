import { createHmac } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { REPORT_HEADERS } from '@arche/shared';
import { AGG_LIMITS, Aggregator } from '../src/aggregate.ts';
import { defaultNodeConfig, parseDotenv, sanitizeNodeConfig } from '../src/config.ts';
import { Hub } from '../src/hub.ts';
import { ReportLoop, parseReportResponse, reportHeaders } from '../src/reporter.ts';
import { FakeConn, makeKeys, signToken, tokenPayload } from './helpers.ts';

describe('Aggregator', () => {
  it('sums, hands over and merges back', () => {
    const agg = new Aggregator();
    agg.addItemReaction('i1', 'heart');
    agg.addItemReaction('i1', 'heart');
    agg.addVoiceReaction('v1', 'pray');
    const first = agg.take();
    expect(first.reactions).toEqual([{ item: 'i1', counts: { heart: 2 } }]);
    expect(agg.isEmpty).toBe(true);

    agg.addItemReaction('i1', 'heart');
    agg.restore(first);
    expect(agg.take().reactions).toEqual([{ item: 'i1', counts: { heart: 3 } }]);
  });

  it('stays bounded during a long outage', () => {
    const agg = new Aggregator();
    for (let i = 0; i < AGG_LIMITS.keys + 50; i++) agg.addItemReaction(`i${i}`, 'heart');
    for (let i = 0; i < AGG_LIMITS.reports + 10; i++) {
      agg.addReport({ msg: `m${i}`, text: '', sub: 's', by: 'b', reason: '', at: i });
    }
    const d = agg.take();
    expect(d.reactions).toHaveLength(AGG_LIMITS.keys);
    expect(d.reports).toHaveLength(AGG_LIMITS.reports);
    expect(d.reports[0]?.msg).toBe('m10'); // oldest dropped first
  });
});

describe('signature and response parsing', () => {
  it('signs `${ts}.${body}` with HMAC-SHA256, hex', () => {
    const headers = reportHeaders('rt1', 's3cret', 1790190000, '{"v":1}');
    const expected = createHmac('sha256', 's3cret').update('1790190000.{"v":1}').digest('hex');
    expect(headers[REPORT_HEADERS.signature]).toBe(expected);
    expect(headers[REPORT_HEADERS.node]).toBe('rt1');
    expect(headers[REPORT_HEADERS.timestamp]).toBe('1790190000');
  });

  it('keeps sane config values and the previous ones otherwise', () => {
    const prev = defaultNodeConfig();
    const parsed = parseReportResponse(
      { ok: true, bans: ['a', 7, ''], removed: ['m1'], config: { maxMessageLength: 'x', slowModeMs: -5, blocklist: [' Spam ', 3] } },
      prev,
    );
    expect(parsed).toEqual({
      ok: true,
      bans: ['a'],
      removed: ['m1'],
      config: { ...prev, slowModeMs: 0, blocklist: ['spam'] },
      drain: false,
    });
    expect(parseReportResponse({ ok: false }, prev)).toBeNull();
    expect(sanitizeNodeConfig(null, prev)).toBe(prev);
  });

  it('reads the dotenv dialect the PHP side reads', () => {
    expect(parseDotenv('A=1\n# c\nB = "two words"\nlower=x\nC=\'q\'\nD=a=b\n')).toEqual({ A: '1', B: 'two words', C: 'q', D: 'a=b' });
  });
});

describe('ReportLoop', () => {
  const keys = makeKeys();

  function hubWithReaction() {
    const hub = new Hub({ tokenKey: keys.publicKey });
    const client = hub.connect(new FakeConn());
    hub.handleRaw(client, JSON.stringify({ t: 'hello', token: signToken(keys.privateKey, tokenPayload()) }));
    hub.handleRaw(client, JSON.stringify({ t: 'react', item: 'i1', kind: 'heart' }));
    return hub;
  }

  it('posts a signed report and applies the answer', async () => {
    const hub = hubWithReaction();
    const calls: { url: string; init: RequestInit }[] = [];
    const fetchImpl = (async (url: string, init: RequestInit) => {
      calls.push({ url, init });
      return new Response(
        JSON.stringify({ ok: true, bans: [], removed: [], config: { ...defaultNodeConfig(), roomCapacity: 7 }, drain: false }),
        { status: 200 },
      );
    }) as unknown as typeof fetch;
    const loop = new ReportLoop({ hub, node: 'rt1', apiBase: 'https://radio.example/', secret: 'k', startedAt: 1, fetchImpl });

    expect(await loop.tick()).toBe(true);
    expect(calls[0]?.url).toBe('https://radio.example/api/realtime/report');
    const headers = calls[0]!.init.headers as Record<string, string>;
    const body = String(calls[0]!.init.body);
    const ts = headers[REPORT_HEADERS.timestamp]!;
    expect(headers[REPORT_HEADERS.signature]).toBe(createHmac('sha256', 'k').update(`${ts}.${body}`).digest('hex'));
    expect(JSON.parse(body)).toMatchObject({ v: 1, node: 'rt1', connections: 1, reactions: [{ item: 'i1', counts: { heart: 1 } }] });
    expect(hub.config.roomCapacity).toBe(7);
    expect(hub.agg.isEmpty).toBe(true);
  });

  it('keeps the deltas for the next report when PHP is unreachable', async () => {
    const hub = hubWithReaction();
    let attempt = 0;
    const bodies: string[] = [];
    const fetchImpl = (async (_url: string, init: RequestInit) => {
      bodies.push(String(init.body));
      attempt++;
      if (attempt === 1) throw new TypeError('fetch failed');
      if (attempt === 2) return new Response('nope', { status: 503 });
      return new Response(JSON.stringify({ ok: true, bans: [], removed: [], config: {}, drain: false }), { status: 200 });
    }) as unknown as typeof fetch;
    const loop = new ReportLoop({ hub, node: 'rt1', apiBase: 'http://web', secret: 'k', startedAt: 1, fetchImpl });

    expect(await loop.tick()).toBe(false);
    expect(await loop.tick()).toBe(false);
    hub.agg.addItemReaction('i1', 'heart');
    expect(await loop.tick()).toBe(true);
    expect(JSON.parse(bodies[2]!).reactions).toEqual([{ item: 'i1', counts: { heart: 2 } }]);
  });

  it('does not post without a secret, and does not lose the deltas either', async () => {
    const hub = hubWithReaction();
    let called = false;
    const fetchImpl = (async () => {
      called = true;
      return new Response('{}');
    }) as unknown as typeof fetch;
    const loop = new ReportLoop({ hub, node: 'rt1', apiBase: 'http://web', secret: '', startedAt: 1, fetchImpl });
    expect(await loop.tick()).toBe(false);
    expect(called).toBe(false);
    expect(hub.agg.isEmpty).toBe(false);
  });
});
