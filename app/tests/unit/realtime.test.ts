import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatMessage, ServerMsg } from '@arche/shared';
import { RealtimeClient } from '@/lib/realtime/client';
import { resolveNode, WakeTimeout, WakeUnavailable, type WakeResponse } from '@/lib/realtime/resolver';
import { initialChat, withMessage, withOptimistic, withoutLastPending, type ChatState } from '@/store/chat';
import { ApiError } from '@/lib/api';

class FakeWS {
  static all: FakeWS[] = [];
  readyState = 0;
  sent: unknown[] = [];
  closed: number | null = null;
  onopen: (() => void) | null = null;
  onmessage: ((e: { data: string }) => void) | null = null;
  onclose: ((e: { code: number }) => void) | null = null;
  onerror: (() => void) | null = null;
  readonly url: string;
  constructor(url: string) {
    this.url = url;
    FakeWS.all.push(this);
  }
  send(data: string): void {
    this.sent.push(JSON.parse(data));
  }
  close(code = 1000): void {
    this.closed = code;
    this.readyState = 3;
  }
  // test helpers
  accept(): void {
    this.readyState = 1;
    this.onopen?.();
  }
  receive(msg: ServerMsg): void {
    this.onmessage?.({ data: JSON.stringify(msg) });
  }
  drop(code: number): void {
    this.readyState = 3;
    this.onclose?.({ code });
  }
}

function harness(wakes: (WakeResponse | Error)[]) {
  let state: ChatState = { ...initialChat };
  let now = 0;
  const calls: string[] = [];
  const client = new RealtimeClient({
    wake: async (channel) => {
      calls.push(channel);
      const next = wakes.length > 1 ? wakes.shift()! : wakes[0]!;
      if (next instanceof Error) throw next;
      return next;
    },
    sleep: async (ms) => {
      now += ms;
    },
    now: () => now,
    socket: (url) => new FakeWS(url) as unknown as WebSocket,
    store: { get: () => state, set: (p) => (state = { ...state, ...p }) },
  });
  return { client, get state() { return state; }, calls, tick: (ms: number) => (now += ms) };
}

const flush = async (): Promise<void> => {
  for (let i = 0; i < 5; i++) await Promise.resolve();
};

const msg = (id: string, extra: Partial<ChatMessage> = {}): ChatMessage => ({ id, sub: 'other', name: 'Maria', country: 'DE', text: 'Praise God', at: 1, likes: 0, ...extra });

beforeEach(() => {
  FakeWS.all = [];
  vi.useFakeTimers();
});
afterEach(() => vi.useRealTimers());

describe('resolveNode', () => {
  const ready = { status: 'ready', url: 'wss://rt1/ws', token: 'tok' };
  it('polls while a node boots, then returns url and token', async () => {
    let now = 0;
    const answers: WakeResponse[] = [{ status: 'starting', retryMs: 3000 }, { status: 'starting', retryMs: 3000 }, ready];
    const waiting = vi.fn();
    const r = await resolveNode('main', { wake: async () => answers.shift()!, sleep: async (ms) => void (now += ms), now: () => now }, new AbortController().signal, waiting);
    expect(r).toEqual({ url: 'wss://rt1/ws', token: 'tok' });
    expect(waiting).toHaveBeenCalledTimes(1);
    expect(now).toBe(6000);
  });
  it('slows down after half a minute and gives up after three', async () => {
    let now = 0;
    let n = 0;
    await expect(
      resolveNode('main', { wake: async () => (n++, { status: 'starting' }), sleep: async (ms) => void (now += ms), now: () => now }, new AbortController().signal),
    ).rejects.toBeInstanceOf(WakeTimeout);
    expect(n).toBeLessThanOrEqual(40); // inside the endpoint's rate limit
  });
  it('unavailable is final; rate limiting is waited out', async () => {
    await expect(resolveNode('main', { wake: async () => ({ status: 'unavailable' }), sleep: async () => {}, now: () => 0 }, new AbortController().signal)).rejects.toBeInstanceOf(WakeUnavailable);
    let first = true;
    let now = 0;
    const r = await resolveNode('main', {
      wake: async () => {
        if (first) {
          first = false;
          throw new ApiError(429, 'rate_limited');
        }
        return ready;
      },
      sleep: async (ms) => void (now += ms),
      now: () => now,
    }, new AbortController().signal);
    expect(r.token).toBe('tok');
  });
});

describe('RealtimeClient', () => {
  const ready = { status: 'ready', url: 'wss://rt1/ws', token: 'tok-1' };

  it('says hello with the token, then is connected with the welcome history', async () => {
    const h = harness([ready]);
    await h.client.connect('main');
    const ws = FakeWS.all[0]!;
    ws.accept();
    expect(ws.sent[0]).toEqual({ t: 'hello', token: 'tok-1' });
    ws.receive({ t: 'welcome', you: { sub: 'me', name: 'Jo' }, room: { id: 'main-en-dach-1', channel: 'main', lang: 'en', region: 'dach', count: 3 }, history: [msg('m1')] });
    expect(h.state.status).toBe('connected');
    expect(h.state.messages.map((m) => m.id)).toEqual(['m1']);
    expect(h.client.isConnected()).toBe(true);
  });

  it('sends optimistically and reconciles the echo by client id', async () => {
    const h = harness([ready]);
    await h.client.connect('main');
    const ws = FakeWS.all[0]!;
    ws.accept();
    ws.receive({ t: 'welcome', you: { sub: 'me', name: 'Jo' }, room: { id: 'r', channel: 'main', lang: 'en', region: 'dach', count: 1 }, history: [] });
    h.client.sendChat('  Hello all  ');
    const sent = ws.sent[1] as { t: string; text: string; cid: string };
    expect(sent).toMatchObject({ t: 'chat', text: 'Hello all' });
    expect(h.state.messages).toHaveLength(1);
    ws.receive({ t: 'msg', msg: msg('m9', { sub: 'me', name: 'Jo', text: 'Hello all', cid: sent.cid }) });
    expect(h.state.messages.map((m) => m.id)).toEqual(['m9']);
    expect(h.state.pending).toEqual([]);
  });

  it('drops the optimistic copy when the node refuses it', async () => {
    const h = harness([ready]);
    await h.client.connect('main');
    const ws = FakeWS.all[0]!;
    ws.accept();
    ws.receive({ t: 'welcome', you: { sub: 'me', name: 'Jo' }, room: { id: 'r', channel: 'main', lang: 'en', region: 'dach', count: 1 }, history: [] });
    h.client.sendChat('too fast');
    ws.receive({ t: 'error', code: 'rate' });
    expect(h.state.messages).toEqual([]);
    expect(h.state.error).toBe('rate');
  });

  it('likes once, reports once, updates counts, removes on request', async () => {
    const h = harness([ready]);
    await h.client.connect('main');
    const ws = FakeWS.all[0]!;
    ws.accept();
    ws.receive({ t: 'welcome', you: { sub: 'me', name: 'Jo' }, room: { id: 'r', channel: 'main', lang: 'en', region: 'dach', count: 1 }, history: [msg('m1')] });
    expect(h.client.like('m1')).toBe(true);
    expect(h.client.like('m1')).toBe(false);
    ws.receive({ t: 'likes', msg: 'm1', likes: 4 });
    expect(h.state.messages[0]!.likes).toBe(4);
    expect(h.client.report('m1', 'inappropriate')).toBe(true);
    expect(h.client.report('m1', 'inappropriate')).toBe(false);
    ws.receive({ t: 'removed', msg: 'm1' });
    expect(h.state.messages).toEqual([]);
    ws.receive({ t: 'presence', room: 7, channel: 40 });
    expect(h.state.channelCount).toBe(40);
  });

  it('on draining or 1013 it goes through wake again; 4001 fetches a fresh token; 4003 stops', async () => {
    const h = harness([ready, { status: 'ready', url: 'wss://rt2/ws', token: 'tok-2' }]);
    await h.client.connect('main');
    FakeWS.all[0]!.accept();
    FakeWS.all[0]!.receive({ t: 'draining' });
    await vi.runAllTimersAsync();
    await flush();
    expect(h.calls).toHaveLength(2);
    const second = FakeWS.all[1]!;
    expect(second.url).toBe('wss://rt2/ws');
    second.accept();
    expect(second.sent[0]).toEqual({ t: 'hello', token: 'tok-2' });

    second.drop(4001);
    await vi.runAllTimersAsync();
    await flush();
    expect(h.calls).toHaveLength(3);

    FakeWS.all[2]!.accept();
    FakeWS.all[2]!.drop(4003);
    expect(h.state.status).toBe('error');
    expect(h.state.error).toBe('banned');
  });

  it('leave closes the socket and resets the room', async () => {
    const h = harness([ready]);
    await h.client.connect('main');
    FakeWS.all[0]!.accept();
    h.client.leave();
    expect(FakeWS.all[0]!.closed).toBe(1000);
    expect(h.state.status).toBe('idle');
  });
});

describe('chat reducers', () => {
  it('cap and de-duplicate', () => {
    let s: ChatState = { ...initialChat, me: { sub: 'me', name: 'Jo' } };
    for (let i = 0; i < 160; i++) s = { ...s, ...withMessage(s, msg(`m${i}`)) };
    expect(s.messages).toHaveLength(150);
    s = { ...s, ...withMessage(s, msg('m159')) };
    expect(s.messages).toHaveLength(150);
    s = { ...s, ...withOptimistic(s, 'c1', 'hi', 5) };
    expect(s.pending).toEqual(['c1']);
    s = { ...s, ...withoutLastPending(s) };
    expect(s.pending).toEqual([]);
  });
});

describe('RealtimeClient failure limits', () => {
  it('backs off after repeated 4001 and gives up after too many failures', async () => {
    const h = harness([{ status: 'ready', url: 'wss://rt1/ws', token: 't' }]);
    await h.client.connect('main');
    for (let i = 0; i < 10 && h.state.status !== 'error'; i++) {
      const ws = FakeWS.all[FakeWS.all.length - 1]!;
      ws.accept();
      ws.drop(4001);
      await vi.runAllTimersAsync();
      await flush();
    }
    expect(h.state.status).toBe('error');
    expect(h.state.error).toBe('auth');
    expect(FakeWS.all.length).toBeLessThanOrEqual(8);
  });
});
