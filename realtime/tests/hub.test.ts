import { beforeEach, describe, expect, it } from 'vitest';
import type { RealtimeToken } from '@arche/shared';
import { defaultNodeConfig } from '../src/config.ts';
import { CLOSE, Hub, REACTIONS_PER_MINUTE, normalizeText, type Client } from '../src/hub.ts';
import { FakeConn, makeClock, makeKeys, signToken, tokenPayload } from './helpers.ts';

const keys = makeKeys();

function setup(config = defaultNodeConfig()) {
  const clock = makeClock();
  const hub = new Hub({ tokenKey: keys.publicKey, now: clock.now, config });
  const nowSec = () => Math.floor(clock.now() / 1000);
  const join = (overrides: Partial<RealtimeToken> = {}): { conn: FakeConn; client: Client } => {
    const conn = new FakeConn();
    const client = hub.connect(conn);
    hub.handleRaw(client, JSON.stringify({ t: 'hello', token: signToken(keys.privateKey, tokenPayload(overrides, nowSec())) }));
    return { conn, client };
  };
  const send = (client: Client, msg: unknown) => hub.handleRaw(client, JSON.stringify(msg));
  return { hub, clock, join, send };
}

describe('hello', () => {
  it('welcomes into a room with recent history', () => {
    const { join, send } = setup();
    const a = join({ sub: 'a' });
    expect(a.conn.last('welcome')?.room.id).toBe('main-de-dach-1');
    send(a.client, { t: 'chat', text: 'Guten Morgen', cid: 'c1' });
    const b = join({ sub: 'b' });
    const welcome = b.conn.last('welcome');
    expect(welcome?.history.map((m) => m.text)).toEqual(['Guten Morgen']);
    // The other listener's client id never leaks into history.
    expect(welcome?.history[0]?.cid).toBeUndefined();
    expect(a.conn.last('presence')).toEqual({ t: 'presence', room: 2, channel: 2 });
  });

  it('closes a socket whose first frame is not a valid hello', () => {
    const { hub } = setup();
    const conn = new FakeConn();
    const client = hub.connect(conn);
    hub.handleRaw(client, JSON.stringify({ t: 'chat', text: 'hi', cid: 'x' }));
    expect(conn.errors()).toEqual(['auth']);
    expect(conn.closed?.code).toBe(CLOSE.auth);
    expect(hub.clients.size).toBe(0);

    const conn2 = new FakeConn();
    hub.handleRaw(hub.connect(conn2), JSON.stringify({ t: 'hello', token: 'nope.nope' }));
    expect(conn2.closed?.code).toBe(CLOSE.auth);
  });

  it('tells an expired token apart from a bad one', () => {
    const { hub, clock } = setup();
    const conn = new FakeConn();
    const nowSec = Math.floor(clock.now() / 1000);
    const token = signToken(keys.privateKey, tokenPayload({ iat: nowSec - 1200, exp: nowSec - 600 }, nowSec));
    hub.handleRaw(hub.connect(conn), JSON.stringify({ t: 'hello', token }));
    expect(conn.errors()).toEqual(['expired']);
  });
});

describe('chat', () => {
  let s: ReturnType<typeof setup>;
  beforeEach(() => {
    s = setup({ ...defaultNodeConfig(), maxMessageLength: 10, blocklist: ['casino'], slowModeMs: 3000 });
  });

  it('broadcasts to the room and echoes the client id only to the sender', () => {
    const a = s.join({ sub: 'a' });
    const b = s.join({ sub: 'b' });
    s.send(a.client, { t: 'chat', text: '  Amen \n ', cid: 'c-1' });
    expect(a.conn.last('msg')?.msg).toMatchObject({ text: 'Amen', sub: 'a', cid: 'c-1', likes: 0 });
    expect(b.conn.last('msg')?.msg.text).toBe('Amen');
    expect(b.conn.last('msg')?.msg.cid).toBeUndefined();
  });

  it('refuses too long, blocklisted, empty and too fast messages', () => {
    const a = s.join({ sub: 'a' });
    s.send(a.client, { t: 'chat', text: 'x'.repeat(11), cid: '1' });
    s.send(a.client, { t: 'chat', text: 'CASINO!', cid: '2' });
    s.send(a.client, { t: 'chat', text: '   ', cid: '3' });
    s.send(a.client, { t: 'chat', text: 42, cid: '4' });
    expect(a.conn.errors()).toEqual(['too_long', 'blocked', 'bad', 'bad']);

    s.send(a.client, { t: 'chat', text: 'one', cid: '5' });
    s.send(a.client, { t: 'chat', text: 'two', cid: '6' });
    expect(a.conn.errors().at(-1)).toBe('rate');
    s.clock.advance(3000);
    s.send(a.client, { t: 'chat', text: 'three', cid: '7' });
    expect(a.conn.of('msg').map((m) => m.msg.text)).toEqual(['one', 'three']);
  });

  it('shares slow mode between two tabs of the same listener', () => {
    const tab1 = s.join({ sub: 'a' });
    const tab2 = s.join({ sub: 'a' });
    s.send(tab1.client, { t: 'chat', text: 'hi', cid: '1' });
    s.send(tab2.client, { t: 'chat', text: 'hi again', cid: '2' });
    expect(tab2.conn.errors()).toEqual(['rate']);
  });

  it('strips invisible characters that would slip past the blocklist', () => {
    const zeroWidth = String.fromCharCode(0x200b);
    expect(normalizeText(`ca${zeroWidth}sino`)).toBe('ca sino');
    const a = s.join({ sub: 'a' });
    s.send(a.client, { t: 'chat', text: `cas${String.fromCharCode(0x2028)}ino`, cid: '1' });
    expect(a.conn.last('msg')?.msg.text).toBe('cas ino');
  });
});

describe('likes and highlight candidates', () => {
  it('counts one like per listener and queues a candidate once at the threshold', () => {
    const s = setup({ ...defaultNodeConfig(), highlightLikes: 2, slowModeMs: 0 });
    const author = s.join({ sub: 'author', name: 'Lena', country: 'BR', lang: 'en' });
    const f1 = s.join({ sub: 'f1', country: 'BR', lang: 'en' });
    const f2 = s.join({ sub: 'f2', country: 'BR', lang: 'en' });
    s.send(author.client, { t: 'chat', text: 'God is faithful', cid: 'x' });
    const id = author.conn.last('msg')!.msg.id;

    s.send(author.client, { t: 'like', msg: id }); // own message: ignored
    s.send(f1.client, { t: 'like', msg: id });
    s.send(f1.client, { t: 'like', msg: id }); // twice: ignored
    expect(author.conn.last('likes')).toEqual({ t: 'likes', msg: id, likes: 1 });
    s.send(f2.client, { t: 'like', msg: id });
    expect(f1.conn.last('likes')?.likes).toBe(2);

    const { deltas } = s.hub.buildReport('rt1', 0);
    expect(deltas.candidates).toEqual([
      expect.objectContaining({ id, channel: 'main', lang: 'en', sub: 'author', name: 'Lena', country: 'BR', likes: 2 }),
    ]);
    const third = s.join({ sub: 'f3', country: 'BR', lang: 'en' });
    s.send(third.client, { t: 'like', msg: id });
    expect(s.hub.buildReport('rt1', 0).deltas.candidates).toEqual([]);
  });
});

describe('reactions and reports', () => {
  it('aggregates reactions per item and rate-limits each listener', () => {
    const s = setup();
    const a = s.join({ sub: 'a' });
    const b = s.join({ sub: 'b' });
    for (let i = 0; i < REACTIONS_PER_MINUTE + 3; i++) s.send(a.client, { t: 'react', item: 'i1', kind: 'heart' });
    s.send(b.client, { t: 'react', item: 'i1', kind: 'pray' });
    s.send(b.client, { t: 'voiceReact', voice: 'v9', kind: 'smile' });
    s.send(b.client, { t: 'react', item: 'i1', kind: 'shrug' });
    expect(a.conn.errors().filter((e) => e === 'rate')).toHaveLength(3);
    expect(b.conn.errors()).toEqual(['bad']);

    s.clock.advance(60_000);
    s.send(a.client, { t: 'react', item: 'i2', kind: 'fire' });

    const { body } = s.hub.buildReport('rt1', 123);
    expect(body.reactions).toEqual([
      { item: 'i1', counts: { heart: REACTIONS_PER_MINUTE, pray: 1 } },
      { item: 'i2', counts: { fire: 1 } },
    ]);
    expect(body.voiceReactions).toEqual([{ voice: 'v9', counts: { smile: 1 } }]);
  });

  it('queues one report per reporter and message', () => {
    const s = setup();
    const a = s.join({ sub: 'a' });
    const b = s.join({ sub: 'b' });
    s.send(a.client, { t: 'chat', text: 'buy followers', cid: '1' });
    const id = a.conn.last('msg')!.msg.id;
    s.send(b.client, { t: 'report', msg: id, reason: 'spam' });
    s.send(b.client, { t: 'report', msg: id, reason: 'spam again' });
    s.send(b.client, { t: 'report', msg: 'm-unknown', reason: 'x' });
    const { deltas } = s.hub.buildReport('rt1', 0);
    expect(deltas.reports).toEqual([expect.objectContaining({ msg: id, text: 'buy followers', sub: 'a', by: 'b', reason: 'spam' })]);
  });
});

describe('applying PHP decisions', () => {
  it('disconnects and refuses banned listeners, and lets them back when unbanned', () => {
    const s = setup();
    const a = s.join({ sub: 'bad' });
    const b = s.join({ sub: 'good' });
    s.hub.applyResponse({ ok: true, bans: ['bad'], removed: [], config: defaultNodeConfig(), drain: false });
    expect(a.conn.errors()).toEqual(['banned']);
    expect(a.conn.closed?.code).toBe(CLOSE.banned);
    expect(b.conn.closed).toBeNull();
    expect(s.join({ sub: 'bad' }).conn.errors()).toEqual(['banned']);

    s.hub.applyResponse({ ok: true, bans: [], removed: [], config: defaultNodeConfig(), drain: false });
    expect(s.join({ sub: 'bad' }).conn.last('welcome')).toBeDefined();
  });

  it('removes moderated messages from history and tells the room', () => {
    const s = setup();
    const a = s.join({ sub: 'a' });
    s.send(a.client, { t: 'chat', text: 'oops', cid: '1' });
    const id = a.conn.last('msg')!.msg.id;
    s.hub.applyResponse({ ok: true, bans: [], removed: [id], config: defaultNodeConfig(), drain: false });
    expect(a.conn.last('removed')).toEqual({ t: 'removed', msg: id });
    expect(s.join({ sub: 'b' }).conn.last('welcome')?.history).toEqual([]);
  });

  it('applies config and drains on request; PHP may cancel its own drain', () => {
    const s = setup();
    const a = s.join({ sub: 'a' });
    s.hub.applyResponse({
      ok: true,
      bans: [],
      removed: [],
      config: { ...defaultNodeConfig(), maxMessageLength: 3 },
      drain: true,
    });
    expect(s.hub.config.maxMessageLength).toBe(3);
    expect(a.conn.last('draining')).toEqual({ t: 'draining' });
    const late = s.join({ sub: 'late' });
    expect(late.conn.errors()).toEqual(['draining']);
    expect(late.conn.closed?.code).toBe(CLOSE.tryLater);
    expect(s.hub.buildReport('rt1', 0).body.draining).toBe(true);

    s.hub.applyResponse({ ok: true, bans: [], removed: [], config: defaultNodeConfig(), drain: false });
    expect(s.hub.draining).toBe(false);
    expect(s.join({ sub: 'later' }).conn.last('welcome')).toBeDefined();
  });

  it('never un-drains a node that reached its lifetime', () => {
    const s = setup();
    s.hub.startDrain('lifetime');
    s.hub.applyResponse({ ok: true, bans: [], removed: [], config: defaultNodeConfig(), drain: false });
    expect(s.hub.draining).toBe(true);
  });
});

describe('report body and health', () => {
  it('describes rooms, presence and connections', () => {
    const s = setup();
    s.join({ sub: 'a' });
    s.join({ sub: 'b', lang: 'en', country: 'US' });
    const lurker = new FakeConn();
    s.hub.connect(lurker); // no hello yet: not a listener
    const { body } = s.hub.buildReport('rt1', 42);
    expect(body).toMatchObject({ v: 1, node: 'rt1', startedAt: 42, connections: 2, draining: false, presence: { main: 2 } });
    expect(body.rooms.map((r) => r.id).sort()).toEqual(['main-de-dach-1', 'main-en-americas-1']);
    expect(s.hub.health('rt1', 42)).toEqual({ ok: true, node: 'rt1', connections: 2, rooms: 2, draining: false, startedAt: 42 });
  });
});
