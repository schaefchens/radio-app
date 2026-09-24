import type { ChatMessage, ReactionKind, ServerMsg } from '@arche/shared';
import { api } from '@/lib/api';
import {
  initialChat,
  useChat,
  withLikes,
  withMessage,
  withOptimistic,
  withoutLastPending,
  withoutMessage,
  type ChatState,
} from '@/store/chat';
import { Socket, type SocketFactory } from './socket';
import { resolveNode, WakeTimeout, WakeUnavailable, type ResolverDeps, type WakeResponse } from './resolver';

/**
 * The listener's connection to a chat room.
 *
 *   connect(channel) → wake (maybe waiting for a node to boot) → WebSocket →
 *   {t:'hello', token} → welcome → connected
 *
 * Every reconnect goes through wake again: after `draining` or close 1013 the
 * node is being scaled in (wake routes elsewhere), after 4001 the token has
 * expired (wake issues a fresh one). 4003 (banned) is final. A dropped
 * connection retries with a capped backoff; the room stays joined while the
 * app is open, so Home keeps its live voices.
 */

export interface ClientDeps extends ResolverDeps {
  socket: SocketFactory;
  store: {
    get: () => ChatState;
    set: (patch: Partial<ChatState>) => void;
  };
}

const MAX_BACKOFF_MS = 15_000;
/** Consecutive failed connects before the client stops and shows "try again". */
const MAX_FAILURES = 7;
/** Immediate retries allowed for 4001/1013 before those back off too — a
 *  misconfigured key or a node that stays full must not become a tight loop. */
const MAX_QUICK_RETRIES = 2;

export class RealtimeClient {
  private readonly deps: ClientDeps;
  private socket: Socket | null = null;
  private channel = '';
  private abort: AbortController | null = null;
  private retry = 0;
  private quickRetries = 0;
  private timer: ReturnType<typeof setTimeout> | null = null;
  private seq = 0;
  private wanted = false;

  constructor(deps: ClientDeps) {
    this.deps = deps;
  }

  get channelId(): string {
    return this.channel;
  }

  isConnected(): boolean {
    return this.deps.store.get().status === 'connected' && !!this.socket?.connected;
  }

  hasMessage(id: string): boolean {
    return this.deps.store.get().messages.some((m) => m.id === id);
  }

  /** Join the room for `channel` (again). Safe to call repeatedly. */
  async connect(channel: string): Promise<void> {
    if (this.wanted && this.channel === channel && this.deps.store.get().status !== 'error' && this.deps.store.get().status !== 'unavailable') return;
    this.teardown();
    this.wanted = true;
    this.channel = channel;
    this.retry = 0;
    this.quickRetries = 0;
    this.deps.store.set({ ...initialChat, status: 'connecting' });
    await this.open();
  }

  leave(): void {
    this.wanted = false;
    this.teardown();
    this.deps.store.set({ ...initialChat });
  }

  private teardown(): void {
    this.abort?.abort();
    this.abort = null;
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
    this.socket?.close();
    this.socket = null;
  }

  private async open(): Promise<void> {
    const ctl = new AbortController();
    this.abort = ctl;
    let node: { url: string; token: string };
    try {
      node = await resolveNode(this.channel, this.deps, ctl.signal, () => {
        if (this.abort === ctl) this.deps.store.set({ status: 'waking' });
      });
    } catch (e) {
      if (ctl.signal.aborted || (e as Error).name === 'AbortError') return;
      if (e instanceof WakeUnavailable || e instanceof WakeTimeout) {
        this.deps.store.set({ status: 'unavailable', error: 'unavailable' });
      } else {
        this.deps.store.set({ status: 'error', error: (e as { code?: string }).code ?? 'generic' });
      }
      return;
    }
    if (ctl.signal.aborted || !this.wanted) return;
    this.deps.store.set({ status: 'connecting' });
    const socket = new Socket(node.url, {
      onOpen: () => socket.send({ t: 'hello', token: node.token }),
      onMessage: (m) => this.onMessage(m),
      onClose: (code) => this.onClose(code),
    }, this.deps.socket);
    this.socket = socket;
    socket.open();
  }

  private onMessage(m: ServerMsg): void {
    const { store } = this.deps;
    switch (m.t) {
      case 'welcome':
        this.retry = 0;
        this.quickRetries = 0;
        store.set({ status: 'connected', error: null, room: m.room, me: m.you, messages: m.history.slice(-150), pending: [] });
        return;
      case 'msg':
        store.set(withMessage(store.get(), m.msg));
        return;
      case 'likes':
        store.set(withLikes(store.get(), m.msg, m.likes));
        return;
      case 'removed':
        store.set(withoutMessage(store.get(), m.msg));
        return;
      case 'presence': {
        const room = store.get().room;
        store.set({ room: room ? { ...room, count: m.room } : room, channelCount: m.channel });
        return;
      }
      case 'draining':
        store.set({ error: 'draining' });
        this.reconnect(0);
        return;
      case 'error':
        if (m.code === 'rate' || m.code === 'too_long' || m.code === 'blocked' || m.code === 'bad') {
          store.set({ ...withoutLastPending(store.get()), error: m.code });
        } else {
          store.set({ error: m.code });
        }
        return;
    }
  }

  private onClose(code: number): void {
    this.socket = null;
    if (!this.wanted) return;
    if (code === 4003) {
      this.wanted = false;
      this.deps.store.set({ status: 'error', error: 'banned' });
      return;
    }
    if (this.retry >= MAX_FAILURES) {
      this.wanted = false;
      this.deps.store.set({ status: 'error', error: code === 4001 ? 'auth' : 'error' });
      return;
    }
    // 4001 (token expired) and 1013 (draining/full) reconnect at once through
    // wake, which issues a fresh token or points elsewhere; anything else —
    // and a repeat of those — backs off.
    const quick = (code === 4001 || code === 1013) && this.quickRetries++ < MAX_QUICK_RETRIES;
    const delay = quick ? 0 : Math.min(MAX_BACKOFF_MS, 1000 * 2 ** this.retry);
    this.retry++;
    this.deps.store.set({ status: 'connecting' });
    this.reconnect(delay);
  }

  private reconnect(delay: number): void {
    if (this.timer) clearTimeout(this.timer);
    this.abort?.abort();
    const old = this.socket;
    this.socket = null;
    old?.close();
    this.timer = setTimeout(() => {
      this.timer = null;
      if (this.wanted) void this.open();
    }, delay);
  }

  // --- sending -------------------------------------------------------------------------

  sendChat(text: string): boolean {
    const clean = text.trim();
    if (!clean || !this.socket) return false;
    const cid = `c${Date.now().toString(36)}${(this.seq++).toString(36)}`;
    const ok = this.socket.send({ t: 'chat', text: clean, cid });
    if (ok) this.deps.store.set({ ...withOptimistic(this.deps.store.get(), cid, clean, this.deps.now()), error: null });
    return ok;
  }

  like(msg: string): boolean {
    const state = this.deps.store.get();
    if (state.liked[msg] || !this.socket?.send({ t: 'like', msg })) return false;
    this.deps.store.set({ liked: { ...state.liked, [msg]: true } });
    return true;
  }

  react(item: string, kind: ReactionKind): boolean {
    return this.socket?.send({ t: 'react', item, kind }) ?? false;
  }

  voiceReact(voice: string, kind: ReactionKind): boolean {
    return this.socket?.send({ t: 'voiceReact', voice, kind }) ?? false;
  }

  report(msg: ChatMessage['id'], reason: string): boolean {
    const state = this.deps.store.get();
    if (state.reported[msg] || !this.socket?.send({ t: 'report', msg, reason })) return false;
    this.deps.store.set({ reported: { ...state.reported, [msg]: true } });
    return true;
  }
}

const sleep = (ms: number, signal: AbortSignal): Promise<void> =>
  new Promise((resolve, reject) => {
    const t = setTimeout(resolve, ms);
    signal.addEventListener(
      'abort',
      () => {
        clearTimeout(t);
        reject(new DOMException('aborted', 'AbortError'));
      },
      { once: true },
    );
  });

/** The app's one realtime connection. */
export const realtime = new RealtimeClient({
  wake: (channel, signal) => api<WakeResponse>('/realtime/wake', { body: { channel }, signal }),
  sleep,
  now: () => Date.now(),
  socket: (url) => new WebSocket(url),
  store: { get: () => useChat.getState(), set: (patch) => useChat.setState(patch) },
});
